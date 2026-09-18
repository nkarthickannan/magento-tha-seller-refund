<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\Total\RefundFigureLine;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTaxGroup;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PR #1 gave the receipt its own tax recalculation instead of reusing RefundTotalCalculator's
 * output, which both duplicated the arithmetic and dropped the seller-only line filter. This
 * asserts RefundReceipt::buildData() exposes each section's per-rate breakdown straight from
 * RefundFigures - the single source of money - rather than deriving either one a second time,
 * and that the pre-refund breakdown (computed once, inside preRefund()) is kept distinct from
 * the refund breakdown (computed from the refunded lines).
 */
class RefundReceiptTest extends TestCase
{
    public function testTaxGroupsComeStraightFromTheCalculatorFiguresForBothSections(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(42);
        $refund->method('getOrderId')->willReturn(7);
        $refund->method('getRefundNo')->willReturn('SR-0042');
        $refund->method('getCreatedAt')->willReturn('2026-01-15 03:00:00');

        $refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $refundRepository->method('getItems')->with(42)->willReturn([]);

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->with(7)->willReturn($this->createMock(OrderInterface::class));

        // Pre-refund: the order was placed with only a 10 percent line.
        $preRefundGroups = [new RefundTaxGroup('0.1000', '010', '5000.0000', '500.0000')];

        // Refund: a partial refund of two lines at two different rates.
        $lines = [
            new RefundFigureLine(1, 'SELLER-RED-01', 'Seller Red Widget', '2.0000', '1200.0000', '2400.0000', '0.0000', '0.1000', '010', '240.0000', '2640.0000'),
            new RefundFigureLine(2, 'SELLER-BLU-02', 'Seller Blue Gadget', '2.0000', '800.0000', '1600.0000', '0.0000', '0.0800', '008', '128.0000', '1728.0000'),
        ];
        $figures = new RefundFigures(
            '5000.0000',
            '0.0000',
            '500.0000',
            '5500.0000',
            $preRefundGroups,
            '4000.0000',
            '0.0000',
            '368.0000',
            '4368.0000',
            $lines,
            'JPY',
            true
        );

        $calculator = $this->createMock(RefundTotalCalculator::class);
        $calculator->method('fromSnapshot')->willReturn($figures);

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-01-15 03:00:00'));

        $receipt = new RefundReceipt(
            $refundRepository,
            $orderRepository,
            $calculator,
            $timezone
        );

        $data = $receipt->buildData($refund);

        // 'pre_refund' and 'refund' keep the exact four-key shape every other surface agrees
        // on (SurfacesAgreeOnPureSellerOrderTest compares this receipt's 'refund' against it).
        self::assertSame(['subtotal', 'shipping', 'tax', 'grand_total'], array_keys($data['pre_refund']));
        self::assertSame(['subtotal', 'shipping', 'tax', 'grand_total'], array_keys($data['refund']));

        // Pre-refund keeps the single group preRefund() computed - untouched by the refund lines.
        self::assertSame([
            ['tax_rate' => '0.1000', 'tax_code' => '010', 'taxable_amount' => '5000.0000', 'tax_amount' => '500.0000'],
        ], $data['pre_refund_tax_groups']);

        // Refund reports both rates separately - never blended into one figure.
        self::assertCount(2, $data['refund_tax_groups']);
        self::assertSame('0.0800', $data['refund_tax_groups'][0]['tax_rate']);
        self::assertSame('1600.0000', $data['refund_tax_groups'][0]['taxable_amount']);
        self::assertSame('128.0000', $data['refund_tax_groups'][0]['tax_amount']);
        self::assertSame('0.1000', $data['refund_tax_groups'][1]['tax_rate']);
        self::assertSame('2400.0000', $data['refund_tax_groups'][1]['taxable_amount']);
        self::assertSame('240.0000', $data['refund_tax_groups'][1]['tax_amount']);
    }
}
