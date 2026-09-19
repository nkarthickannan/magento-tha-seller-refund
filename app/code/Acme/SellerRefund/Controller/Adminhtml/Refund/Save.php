<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Controller\Adminhtml\Refund;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Erp\ErpRefundClient;
use Acme\SellerRefund\Model\Erp\Exception\ErpException;
use Acme\SellerRefund\Model\Erp\PayloadBuilder;
use Acme\SellerRefund\Model\Erp\RequestKey;
use Acme\SellerRefund\Model\RefundStateMachine;
use Acme\SellerRefund\Model\Submission\RefundSubmission;
use Acme\SellerRefund\Service\RefundProcessor;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Acme_SellerRefund::create';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly RefundProcessor $processor,
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly ErpRefundClient $client,
        private readonly RefundStateMachine $stateMachine,
        private readonly ResourceConnection $resource
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();

        $refund = null;
        try {
            $orderId = (int) $this->getRequest()->getParam('order_id');
            $data = (array) $this->getRequest()->getParams();
            $data['actor_id'] = (int) ($this->_auth->getUser() ? $this->_auth->getUser()->getId() : 0);

            $refund = $this->processor->submit($orderId, RefundSubmission::fromRequest($data));
            $refundId = (int) $refund->getEntityId();

            $items = $this->refundRepository->getItems($refundId);
            $order = $this->orderRepository->get($orderId);
            $key = RequestKey::forAttempt($refund, 1);
            $payload = $this->payloadBuilder->build($refund, $items, $order, $key);
            $created = $this->client->create($payload, $key);

            $this->stateMachine->transition(
                $refund,
                RefundInterface::STATUS_CASH_REFUND_PENDING,
                RefundEventInterface::TYPE_ERP_CREATE,
                [
                    RefundInterface::CREATE_STATUS => RefundInterface::SUB_SUCCEEDED,
                    RefundInterface::ERP_REFUND_ID => $created->getErpRefundId(),
                ]
            );
            $connection->commit();

            $fresh = $this->refundRepository->getById($refundId);

            return $result->setData([
                'ok' => true,
                'refund_id' => (int) $fresh->getEntityId(),
                'refund_no' => $fresh->getRefundNo(),
                'status' => $fresh->getStatus(),
                'create_status' => $fresh->getCreateStatus(),
                'erp_refund_id' => $fresh->getErpRefundId(),
                'message' => (string) __('Refund %1 saved.', $fresh->getRefundNo()),
                'redirect_url' => $this->getUrl('acme_refund/refund/view', ['refund_id' => $fresh->getEntityId()]),
            ]);
        } catch (ErpException $e) {
            if ($refund !== null) {
                $this->stateMachine->transition(
                    $refund,
                    RefundInterface::STATUS_FAILED,
                    RefundEventInterface::TYPE_ERP_CREATE,
                    [RefundInterface::CREATE_STATUS => RefundInterface::SUB_BUSINESS_REJECTED]
                );
            }
            $connection->commit();

            return $result->setHttpResponseCode(502)->setData([
                'ok' => false,
                'message' => (string) __('The refund was saved but the ERP call failed.'),
            ]);
        } catch (LocalizedException $e) {
            $connection->rollBack();

            return $result->setData(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $connection->rollBack();

            return $result->setData(['ok' => false, 'message' => (string) __('The refund could not be saved.')]);
        }
    }
}
