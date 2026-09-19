<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Service;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Exception\IllegalTransitionException;
use Acme\SellerRefund\Model\Erp\ErpRefundClient;
use Acme\SellerRefund\Model\Erp\Exception\ErpException;
use Acme\SellerRefund\Model\Erp\Exception\ErpTransientException;
use Acme\SellerRefund\Model\Erp\PayloadBuilder;
use Acme\SellerRefund\Model\Erp\RequestKey;
use Acme\SellerRefund\Model\Event\EventRecorder;
use Acme\SellerRefund\Model\Outbox\Outbox;
use Acme\SellerRefund\Model\RefundFactory;
use Acme\SellerRefund\Model\RefundItemFactory;
use Acme\SellerRefund\Model\RefundNumberGenerator;
use Acme\SellerRefund\Model\RefundStateMachine;
use Acme\SellerRefund\Model\RefundValidator;
use Acme\SellerRefund\Model\ResourceModel\OrderLock;
use Acme\SellerRefund\Model\ResourceModel\PriorRefundQuantity;
use Acme\SellerRefund\Model\ResourceModel\Refund as RefundResource;
use Acme\SellerRefund\Model\ResourceModel\RefundItem as RefundItemResource;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Submission\RefundSubmission;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Orchestrates the refund lifecycle: local commit first, then the ERP calls driven off the
 * durable outbox. No HTTP is issued inside the submit transaction (BR-06).
 */
class RefundProcessor
{
    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly ResourceConnection $resource,
        private readonly OrderLock $orderLock,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PriorRefundQuantity $priorRefundQuantity,
        private readonly RefundValidator $validator,
        private readonly RefundTotalCalculator $calculator,
        private readonly RefundNumberGenerator $refundNumberGenerator,
        private readonly RefundFactory $refundFactory,
        private readonly RefundItemFactory $refundItemFactory,
        private readonly RefundResource $refundResource,
        private readonly RefundItemResource $refundItemResource,
        private readonly SellerLineResolver $sellerLineResolver,
        private readonly EventRecorder $eventRecorder,
        private readonly Outbox $outbox,
        private readonly RefundStateMachine $stateMachine,
        private readonly ErpRefundClient $client,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly EventManagerInterface $eventManager,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function submit(int $orderId, RefundSubmission $submission): RefundInterface
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->orderLock->lock($orderId);
            $order = $this->orderRepository->get($orderId);
            $prior = $this->priorRefundQuantity->sumRefundedByOrderItem($orderId);
            $this->validator->validate($order, $submission, $prior);

            $now = $this->timezone->date();
            $figures = $this->calculator->fromSelection($order, $submission->qtyByItem, $prior, $now);
            $refundType = $figures->isPartial() ? RefundInterface::TYPE_PARTIAL : RefundInterface::TYPE_FULL;

            /** @var \Acme\SellerRefund\Model\Refund $refund */
            $refund = $this->refundFactory->create();
            $refund->setRefundNo($this->refundNumberGenerator->next($now))
                ->setOrderId($orderId)
                ->setSellerOrderId($this->stringOrNull($order->getData('mp_seller_order_id')))
                ->setRefundType($refundType)
                ->setReasonCode($submission->reasonCode)
                ->setStatus(RefundInterface::STATUS_CALCULATED)
                ->setSubtotalAmount($figures->refundSubtotal)
                ->setShippingAmount($figures->refundShipping)
                ->setTaxAmount($figures->refundTax)
                ->setGrandTotal($figures->refundGrandTotal)
                ->setCurrencyCode($figures->currency)
                ->setCreateStatus(RefundInterface::SUB_NOT_STARTED)
                ->setStatusCheckStatus(RefundInterface::SUB_NOT_STARTED)
                ->setConfirmStatus(RefundInterface::SUB_NOT_STARTED)
                ->setCashRefundStatus(RefundInterface::SUB_NOT_STARTED)
                ->setVersion(1)
                ->setCreatedBy($submission->actorId)
                ->setUpdatedBy($submission->actorId);
            $this->refundResource->save($refund);
            $refundId = (int) $refund->getEntityId();

            $this->saveItems($refundId, $figures, $order, $prior);

            $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_CREATED, RefundInterface::SUB_SUCCEEDED, [
                'status_to' => RefundInterface::STATUS_CALCULATED,
                'created_by' => $submission->actorId,
                'request_payload' => $figures->toArray(),
            ]);

            $this->outbox->enqueue($refundId, Outbox::OP_CREATE);

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $refund;
    }

    public function process(int $refundId, int $attemptNo = 1): void
    {
        $refund = $this->refundRepository->getById($refundId);
        if (!in_array($refund->getStatus(), [RefundInterface::STATUS_CALCULATED, RefundInterface::STATUS_FAILED], true)) {
            return;
        }

        $items = $this->refundRepository->getItems($refundId);
        $order = $this->orderRepository->get($refund->getOrderId());
        $requestKey = RequestKey::forAttempt($refund, $attemptNo);
        $payload = $this->payloadBuilder->build($refund, $items, $order, $requestKey);

        $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_ERP_CREATE, RefundInterface::SUB_PENDING, [
            'api_code' => 'create',
            'attempt_no' => $attemptNo,
            'request_key' => $requestKey->getValue(),
            'request_payload' => $payload,
        ]);

        try {
            $result = $this->client->create($payload, $requestKey);
        } catch (ErpException $e) {
            $this->stateMachine->transition(
                $refund,
                RefundInterface::STATUS_FAILED,
                RefundEventInterface::TYPE_ERP_CREATE,
                [RefundInterface::CREATE_STATUS => RefundInterface::SUB_BUSINESS_REJECTED]
            );
            $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_ERP_CREATE, RefundInterface::SUB_BUSINESS_REJECTED, [
                'api_code' => 'create',
                'attempt_no' => $attemptNo,
                'request_key' => $requestKey->getValue(),
                'error_code' => $e->getMessage(),
            ]);
            return;
        }

        $this->stateMachine->transition(
            $refund,
            RefundInterface::STATUS_CASH_REFUND_PENDING,
            RefundEventInterface::TYPE_ERP_CREATE,
            [
                RefundInterface::CREATE_STATUS => RefundInterface::SUB_SUCCEEDED,
                RefundInterface::ERP_REFUND_ID => $result->getErpRefundId(),
            ]
        );
        $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_ERP_CREATE, RefundInterface::SUB_SUCCEEDED, [
            'api_code' => 'create',
            'attempt_no' => $attemptNo,
            'request_key' => $requestKey->getValue(),
            'response_payload' => ['erp_refund_id' => $result->getErpRefundId(), 'status' => $result->getStatus()],
        ]);
    }

    public function registerCashRefund(int $refundId, string $txnNumber, string $txnDate, ?int $actorId): void
    {
        $refund = $this->refundRepository->getById($refundId);
        $this->stateMachine->transition(
            $refund,
            RefundInterface::STATUS_CASH_REFUNDED,
            RefundEventInterface::TYPE_CASH_REGISTERED,
            [
                RefundInterface::CASH_REFUND_STATUS => RefundInterface::SUB_SUCCEEDED,
                RefundInterface::TRANSACTION_NUMBER => $txnNumber,
                RefundInterface::TRANSACTION_DATE => $txnDate,
            ],
            $actorId
        );
        $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_CASH_REGISTERED, RefundInterface::SUB_SUCCEEDED, [
            'created_by' => $actorId,
        ]);
        $this->outbox->enqueue($refundId, Outbox::OP_STATUS_CHECK);
    }

    public function checkStatus(int $refundId): void
    {
        $refund = $this->refundRepository->getById($refundId);
        if ($refund->getStatus() !== RefundInterface::STATUS_CASH_REFUNDED) {
            return;
        }

        $erpId = (string) $refund->getErpRefundId();
        $this->stateMachine->setSubStatus($refund, RefundInterface::STATUS_CHECK_STATUS, RefundInterface::SUB_PENDING);

        try {
            $response = $this->client->readStatus($erpId);
        } catch (ErpTransientException $e) {
            $this->stateMachine->setSubStatus($refund, RefundInterface::STATUS_CHECK_STATUS, RefundInterface::SUB_RETRYABLE_ERROR);
            $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_ERP_STATUS, RefundInterface::SUB_RETRYABLE_ERROR, [
                'api_code' => 'status',
                'error_code' => (string) $e->getHttpCode(),
            ]);
            throw $e;
        }

        $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_ERP_STATUS, RefundInterface::SUB_SUCCEEDED, [
            'api_code' => 'status',
            'response_payload' => $response,
        ]);
        $this->stateMachine->transition(
            $refund,
            RefundInterface::STATUS_ERP_CONFIRM_PENDING,
            RefundEventInterface::TYPE_ERP_STATUS,
            [RefundInterface::STATUS_CHECK_STATUS => RefundInterface::SUB_SUCCEEDED]
        );
        $this->outbox->enqueue($refundId, Outbox::OP_CONFIRM);
    }

    public function confirm(int $refundId): void
    {
        $refund = $this->refundRepository->getById($refundId);
        if ($refund->getStatus() !== RefundInterface::STATUS_ERP_CONFIRM_PENDING) {
            return;
        }

        $erpId = (string) $refund->getErpRefundId();
        $payload = $this->payloadBuilder->buildConfirm($refund);
        $this->stateMachine->setSubStatus($refund, RefundInterface::CONFIRM_STATUS, RefundInterface::SUB_PENDING);

        try {
            $response = $this->client->confirm($erpId, $payload);
        } catch (ErpTransientException $e) {
            $this->stateMachine->setSubStatus($refund, RefundInterface::CONFIRM_STATUS, RefundInterface::SUB_RETRYABLE_ERROR);
            $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_ERP_CONFIRM, RefundInterface::SUB_RETRYABLE_ERROR, [
                'api_code' => 'confirm',
                'error_code' => (string) $e->getHttpCode(),
            ]);
            throw $e;
        }

        $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_ERP_CONFIRM, RefundInterface::SUB_SUCCEEDED, [
            'api_code' => 'confirm',
            'response_payload' => $response,
        ]);
        $this->stateMachine->transition(
            $refund,
            RefundInterface::STATUS_ERP_CONFIRMED,
            RefundEventInterface::TYPE_ERP_CONFIRM,
            [RefundInterface::CONFIRM_STATUS => RefundInterface::SUB_SUCCEEDED]
        );

        $this->eventManager->dispatch('acme_seller_refund_confirmed', ['refund' => $refund]);
    }

    public function cancel(int $refundId, ?int $actorId): void
    {
        $refund = $this->refundRepository->getById($refundId);
        if (!$this->stateMachine->canCancel($refund)) {
            throw new IllegalTransitionException(
                __('This refund can no longer be cancelled.')
            );
        }

        $this->stateMachine->transition(
            $refund,
            RefundInterface::STATUS_CANCELLED,
            RefundEventInterface::TYPE_CANCELLED,
            [],
            $actorId
        );
        $this->outbox->cancelPendingFor($refundId);
        $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_CANCELLED, RefundInterface::SUB_SUCCEEDED, [
            'created_by' => $actorId,
        ]);
    }

    public function replay(int $refundId, ?int $actorId): void
    {
        $refund = $this->refundRepository->getById($refundId);
        $operation = match ($refund->getStatus()) {
            RefundInterface::STATUS_CALCULATED => Outbox::OP_CREATE,
            RefundInterface::STATUS_FAILED => Outbox::OP_CREATE,
            RefundInterface::STATUS_CASH_REFUNDED => Outbox::OP_STATUS_CHECK,
            RefundInterface::STATUS_ERP_CONFIRM_PENDING => Outbox::OP_CONFIRM,
            default => null,
        };

        if ($operation === null) {
            throw new IllegalTransitionException(
                __('There is no retriable operation for a refund in state "%1".', $refund->getStatus())
            );
        }

        $this->eventRecorder->record($refundId, RefundEventInterface::TYPE_REPLAY, RefundInterface::SUB_PENDING, [
            'created_by' => $actorId,
        ]);

        if (!$this->outbox->hasPending($refundId)) {
            $this->outbox->enqueue($refundId, $operation);
        }
    }

    private function saveItems(int $refundId, \Acme\SellerRefund\Model\Total\RefundFigures $figures, \Magento\Sales\Api\Data\OrderInterface $order, array $prior): void
    {
        $sellerLines = $this->sellerLineResolver->sellerLines($order);

        foreach ($figures->lines as $line) {
            $orderItemId = $line->orderItemId;
            $priorQty = sprintf('%.4F', (float) ($prior[$orderItemId] ?? '0'));
            $qtyOrdered = isset($sellerLines[$orderItemId])
                ? sprintf('%.4F', (float) $sellerLines[$orderItemId]->getQtyOrdered())
                : '0.0000';
            $qtyRefundableAfter = bcsub(bcsub($qtyOrdered, $priorQty, 4), $line->qtyRefund, 4);

            /** @var \Acme\SellerRefund\Model\RefundItem $item */
            $item = $this->refundItemFactory->create();
            $item->setRefundId($refundId)
                ->setOrderItemId($orderItemId)
                ->setSku($line->sku)
                ->setProductName($line->productName)
                ->setQtyOrdered($qtyOrdered)
                ->setQtyRefundedBefore($priorQty)
                ->setQtyRefund($line->qtyRefund)
                ->setQtyRefundableAfter($qtyRefundableAfter)
                ->setUnitPrice($line->unitPrice)
                ->setRowAmount($line->rowAmount)
                ->setShippingAmount($line->shippingAmount)
                ->setTaxRate($line->taxRate)
                ->setTaxCode($line->taxCode)
                ->setTaxAmount($line->taxAmount)
                ->setGrandTotal($line->grandTotal);
            $this->refundItemResource->save($item);
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
