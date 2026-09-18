<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Total;

use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use DateTimeImmutable;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage of the money engine. The seller-line resolver and tax-code resolver are
 * mocked; every assertion is an exact 4-decimal string. Option ids used here are arbitrary
 * (101/102/103) and mapped to business codes by the mocked resolver, exactly as production
 * would map through the EAV.
 */
class RefundTotalCalculatorTest extends TestCase
{
    private SellerLineResolver&MockObject $sellerLineResolver;

    private TaxCodeResolver&MockObject $taxCodeResolver;

    protected function setUp(): void
    {
        $this->sellerLineResolver = $this->createMock(SellerLineResolver::class);
        $this->taxCodeResolver = $this->createMock(TaxCodeResolver::class);
        $this->taxCodeResolver->method('toBusinessCode')->willReturnMap([
            [101, '010'],
            [102, '008'],
            [103, '999'],
        ]);
        $this->taxCodeResolver->method('rateFor')->willReturnMap([
            ['010', '0.1000'],
            ['008', '0.0800'],
            ['999', '0.0000'],
        ]);
    }

    /**
     * 10 percent rate, partial refund (2 of 5 ordered): row 1200 * 2 = 2400, tax 2400 * 0.10
     * = 240, grand 2640, and no shipping on a partial.
     */
    public function testTenPercentPartialArithmetic(): void
    {
        $lines = [1 => $this->item(1200.0, 5.0, 101, 'SELLER-RED-01', 'Seller Red Widget')];
        $figures = $this->calculator($lines)->fromSelection(
            $this->order('0.0000'),
            [1 => '2'],
            [],
            new DateTimeImmutable()
        );

        self::assertTrue($figures->isPartial());
        self::assertSame('2400.0000', $figures->refundSubtotal);
        self::assertSame('0.0000', $figures->refundShipping);
        self::assertSame('240.0000', $figures->refundTax);
        self::assertSame('2640.0000', $figures->refundGrandTotal);

        self::assertCount(1, $figures->lines);
        $line = $figures->lines[0];
        self::assertSame('010', $line->taxCode);
        self::assertSame('2400.0000', $line->rowAmount);
        self::assertSame('0.0000', $line->shippingAmount);
        self::assertSame('240.0000', $line->taxAmount);
        self::assertSame('2640.0000', $line->grandTotal);
    }

    /**
     * 8 percent rate, partial refund: row 800 * 2 = 1600, tax 1600 * 0.08 = 128, grand 1728.
     */
    public function testEightPercentPartialArithmetic(): void
    {
        $lines = [1 => $this->item(800.0, 5.0, 102, 'SELLER-BLU-02', 'Seller Blue Gadget')];
        $figures = $this->calculator($lines)->fromSelection(
            $this->order('0.0000'),
            [1 => '2'],
            [],
            new DateTimeImmutable()
        );

        self::assertTrue($figures->isPartial());
        self::assertSame('1600.0000', $figures->refundSubtotal);
        self::assertSame('0.0000', $figures->refundShipping);
        self::assertSame('128.0000', $figures->refundTax);
        self::assertSame('1728.0000', $figures->refundGrandTotal);
    }

    /**
     * A full refund (2 of 2 ordered) refunds the remaining seller shipping. With shipping 500
     * allocated to the single line: taxable 2400 + 500 = 2900, tax 290, grand 3190.
     */
    public function testFullRefundIncludesRemainingSellerShipping(): void
    {
        $lines = [1 => $this->item(1200.0, 2.0, 101, 'SELLER-RED-01', 'Seller Red Widget')];
        $figures = $this->calculator($lines)->fromSelection(
            $this->order('500.0000'),
            [1 => '2'],
            [],
            new DateTimeImmutable()
        );

        self::assertFalse($figures->isPartial());
        self::assertSame('2400.0000', $figures->refundSubtotal);
        self::assertSame('500.0000', $figures->refundShipping);
        self::assertSame('290.0000', $figures->refundTax);
        self::assertSame('3190.0000', $figures->refundGrandTotal);
    }

    /**
     * A partial refund never refunds shipping, even when the order carries a shipping amount.
     */
    public function testPartialRefundShippingIsZero(): void
    {
        $lines = [1 => $this->item(1200.0, 5.0, 101, 'SELLER-RED-01', 'Seller Red Widget')];
        $figures = $this->calculator($lines)->fromSelection(
            $this->order('500.0000'),
            [1 => '2'],
            [],
            new DateTimeImmutable()
        );

        self::assertTrue($figures->isPartial());
        self::assertSame('0.0000', $figures->refundShipping);
    }

    /**
     * A mixed-order calculation sees only seller lines. The resolver returns the two seller
     * lines and never the core line; a core item id offered in the selection is ignored.
     * Seller subtotal 1200 + 1600 = 2800, tax 120 + 128 = 248, grand 3048 (no shipping).
     */
    public function testMixedOrderExcludesCoreLine(): void
    {
        $lines = [
            1 => $this->item(1200.0, 1.0, 101, 'SELLER-RED-01', 'Seller Red Widget'),
            2 => $this->item(800.0, 2.0, 102, 'SELLER-BLU-02', 'Seller Blue Gadget'),
        ];

        // Item id 99 (CORE-STD-01) is offered but is not a seller line; it must be excluded.
        $figures = $this->calculator($lines)->fromSelection(
            $this->order('0.0000'),
            [1 => '1', 2 => '2', 99 => '1'],
            [],
            new DateTimeImmutable()
        );

        self::assertCount(2, $figures->lines);
        foreach ($figures->lines as $line) {
            self::assertNotSame('CORE-STD-01', $line->sku);
        }
        self::assertSame('2800.0000', $figures->refundSubtotal);
        self::assertSame('0.0000', $figures->refundShipping);
        self::assertSame('248.0000', $figures->refundTax);
        self::assertSame('3048.0000', $figures->refundGrandTotal);
    }

    /**
     * The review of PR #1 asked for preRefund() itself to return the tax grouped by rate,
     * rather than have the receipt re-derive it. This exercises exactly that: two seller lines
     * at their full ordered quantity (not the refunded quantity) and two different rates must
     * come back as two separate pre-refund groups, computed once inside preRefund().
     */
    public function testPreRefundTaxGroupsByRateAtOrderedQuantity(): void
    {
        $lines = [
            1 => $this->item(1200.0, 5.0, 101, 'SELLER-RED-01', 'Seller Red Widget'),
            2 => $this->item(800.0, 5.0, 102, 'SELLER-BLU-02', 'Seller Blue Gadget'),
        ];

        // A partial refund selection: only 2 of each line's 5 ordered units.
        $figures = $this->calculator($lines)->fromSelection(
            $this->order('0.0000'),
            [1 => '2', 2 => '2'],
            [],
            new DateTimeImmutable()
        );

        $groups = $figures->preRefundTaxGroups;
        self::assertCount(2, $groups);

        // At the full ordered quantity (5, not the refunded 2): row 1200*5=6000, tax 600.
        self::assertSame('0.0800', $groups[0]->taxRate);
        self::assertSame('4000.0000', $groups[0]->taxableAmount);
        self::assertSame('320.0000', $groups[0]->taxAmount);

        self::assertSame('0.1000', $groups[1]->taxRate);
        self::assertSame('6000.0000', $groups[1]->taxableAmount);
        self::assertSame('600.0000', $groups[1]->taxAmount);
    }

    /**
     * @param array<int, OrderItem&MockObject> $sellerLines
     */
    private function calculator(array $sellerLines): RefundTotalCalculator
    {
        $this->sellerLineResolver->method('sellerLines')->willReturn($sellerLines);

        return new RefundTotalCalculator($this->sellerLineResolver, $this->taxCodeResolver);
    }

    private function item(
        float $price,
        float $qtyOrdered,
        int $taxClassOptionId,
        string $sku,
        string $name
    ): OrderItem&MockObject {
        $item = $this->createMock(OrderItem::class);
        $item->method('getPrice')->willReturn($price);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getData')->willReturn($taxClassOptionId);
        $item->method('getSku')->willReturn($sku);
        $item->method('getName')->willReturn($name);

        return $item;
    }

    private function order(string $shippingAmount): OrderInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getShippingAmount')->willReturn((float) $shippingAmount);
        $order->method('getOrderCurrencyCode')->willReturn('JPY');

        return $order;
    }
}
