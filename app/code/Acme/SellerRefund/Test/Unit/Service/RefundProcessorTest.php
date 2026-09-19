<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Service;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Erp\CreateResult;
use Acme\SellerRefund\Model\Erp\ErpRefundClient;
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
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Acme\SellerRefund\Service\RefundProcessor;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage of the Create dispatch in RefundProcessor::process(). All collaborators
 * are mocked; no database, HTTP or application bootstrap is touched.
 */
class RefundProcessorTest extends TestCase
{
    private RefundRepositoryInterface&MockObject $refundRepository;

    private OrderRepositoryInterface&MockObject $orderRepository;

    private PayloadBuilder&MockObject $payloadBuilder;

    private ErpRefundClient&MockObject $client;

    private RefundStateMachine&MockObject $stateMachine;

    private EventRecorder&MockObject $eventRecorder;

    private RefundProcessor $processor;

    protected function setUp(): void
    {
        $this->refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->payloadBuilder = $this->createMock(PayloadBuilder::class);
        $this->client = $this->createMock(ErpRefundClient::class);
        $this->stateMachine = $this->createMock(RefundStateMachine::class);
        $this->eventRecorder = $this->createMock(EventRecorder::class);

        $this->processor = new RefundProcessor(
            $this->refundRepository,
            $this->createMock(ResourceConnection::class),
            $this->createMock(OrderLock::class),
            $this->orderRepository,
            $this->createMock(PriorRefundQuantity::class),
            $this->createMock(RefundValidator::class),
            $this->createMock(RefundTotalCalculator::class),
            $this->createMock(RefundNumberGenerator::class),
            $this->createMock(RefundFactory::class),
            $this->createMock(RefundItemFactory::class),
            $this->createMock(RefundResource::class),
            $this->createMock(RefundItemResource::class),
            $this->createMock(SellerLineResolver::class),
            $this->eventRecorder,
            $this->createMock(Outbox::class),
            $this->stateMachine,
            $this->client,
            $this->payloadBuilder,
            $this->createMock(EventManagerInterface::class),
            $this->createMock(TimezoneInterface::class)
        );
    }

    public function testProcessHappyPathCreatesAndTransitionsToCashRefundPending(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getOrderId')->willReturn(100);

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->refundRepository->method('getItems')->with(7)->willReturn([]);
        $this->orderRepository->method('get')->with(100)->willReturn(
            $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class)
        );
        $payload = ['refund_no' => 'SR-20260907-000123'];
        $this->payloadBuilder->method('build')->willReturn($payload);
        $this->client->expects(self::once())
            ->method('create')
            ->with($payload, self::callback(static fn (RequestKey $k): bool => $k->getValue() === 'SR-20260907-000123'))
            ->willReturn(new CreateResult('ERP-9001', 'refund-pending'));

        $this->stateMachine->expects(self::once())
            ->method('transition')
            ->with(
                self::identicalTo($refund),
                RefundInterface::STATUS_CASH_REFUND_PENDING,
                \Acme\SellerRefund\Api\Data\RefundEventInterface::TYPE_ERP_CREATE,
                [
                    RefundInterface::CREATE_STATUS => RefundInterface::SUB_SUCCEEDED,
                    RefundInterface::ERP_REFUND_ID => 'ERP-9001',
                ]
            );

        $this->processor->process(7);
    }

    public function testErpRequestFailureMarksRefundFailed(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getStatus')->willReturn(RefundInterface::STATUS_CALCULATED);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getOrderId')->willReturn(100);

        $this->refundRepository->method('getById')->with(7)->willReturn($refund);
        $this->refundRepository->method('getItems')->with(7)->willReturn([]);
        $this->orderRepository->method('get')->with(100)->willReturn(
            $this->createMock(\Magento\Sales\Api\Data\OrderInterface::class)
        );
        $this->payloadBuilder->method('build')->willReturn(['refund_no' => 'SR-20260907-000123']);
        $this->client->method('create')->willThrowException(
            new ErpTransientException('The ERP returned a transient error (HTTP 503).', 503)
        );

        $this->stateMachine->expects(self::once())
            ->method('transition')
            ->with(
                self::identicalTo($refund),
                RefundInterface::STATUS_FAILED,
                \Acme\SellerRefund\Api\Data\RefundEventInterface::TYPE_ERP_CREATE,
                [RefundInterface::CREATE_STATUS => RefundInterface::SUB_BUSINESS_REJECTED]
            );

        $this->processor->process(7);
    }
}
