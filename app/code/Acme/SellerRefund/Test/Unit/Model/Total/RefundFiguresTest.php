<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Total;

use Acme\SellerRefund\Model\Total\RefundFigureLine;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTaxGroup;
use PHPUnit\Framework\TestCase;

/**
 * Covers the per-tax-rate breakdown (RefundFigures::taxGroups()). PR #1 tried to add this
 * breakdown to the receipt by recomputing it independently of the calculator - which both
 * duplicated the tax logic and dropped the seller-only line filter. This asserts the grouping
 * is instead read straight off the already-filtered, already-computed lines: two lines at the
 * same rate must fold into one group, two different rates must stay separate, and every
 * amount must equal a plain sum of the stored per-line figures (never a derived division).
 */
class RefundFiguresTest extends TestCase
{
    public function testTwoLinesAtTheSameRateFoldIntoOneGroup(): void
    {
        $figures = $this->figures([
            $this->line('010', '0.1000', '1200.0000', '0.0000', '120.0000'),
            $this->line('010', '0.1000', '800.0000', '0.0000', '80.0000'),
        ]);

        $groups = $figures->taxGroups();

        self::assertCount(1, $groups);
        self::assertSame('0.1000', $groups[0]->taxRate);
        self::assertSame('010', $groups[0]->taxCode);
        self::assertSame('2000.0000', $groups[0]->taxableAmount);
        self::assertSame('200.0000', $groups[0]->taxAmount);
    }

    /**
     * Mirrors a mixed cart under the JCT reduced-rate regime: a standard-rate line and a
     * reduced-rate line must be reported separately, not blended into a single tax total.
     */
    public function testDifferentRatesStaySeparateAndOrderedByRate(): void
    {
        $figures = $this->figures([
            $this->line('010', '0.1000', '1200.0000', '0.0000', '120.0000'),
            $this->line('008', '0.0800', '1600.0000', '0.0000', '128.0000'),
        ]);

        $groups = $figures->taxGroups();

        self::assertCount(2, $groups);
        // Ascending by rate: 8% before 10%.
        self::assertSame('0.0800', $groups[0]->taxRate);
        self::assertSame('1600.0000', $groups[0]->taxableAmount);
        self::assertSame('128.0000', $groups[0]->taxAmount);

        self::assertSame('0.1000', $groups[1]->taxRate);
        self::assertSame('1200.0000', $groups[1]->taxableAmount);
        self::assertSame('120.0000', $groups[1]->taxAmount);
    }

    public function testShippingAllocatedToALineIsIncludedInItsTaxableAmount(): void
    {
        $figures = $this->figures([
            $this->line('010', '0.1000', '2400.0000', '500.0000', '290.0000'),
        ]);

        $groups = $figures->taxGroups();

        self::assertCount(1, $groups);
        self::assertSame('2900.0000', $groups[0]->taxableAmount);
    }

    public function testNoLinesProducesNoGroups(): void
    {
        self::assertSame([], $this->figures([])->taxGroups());
    }

    /**
     * preRefundTaxGroups (computed once by RefundTotalCalculator::preRefund()) and taxGroups()
     * (computed from the refunded lines) are independent: one refund line's rate must not leak
     * into the pre-refund breakdown the order was originally taxed at.
     */
    public function testPreRefundTaxGroupsAreIndependentOfTheRefundLines(): void
    {
        $preRefundGroups = [new RefundTaxGroup('0.1000', '010', '5000.0000', '500.0000')];
        $figures = new RefundFigures(
            '5000.0000',
            '0.0000',
            '500.0000',
            '5500.0000',
            $preRefundGroups,
            '0.0000',
            '0.0000',
            '0.0000',
            '0.0000',
            [$this->line('008', '0.0800', '1600.0000', '0.0000', '128.0000')],
            'JPY',
            true
        );

        self::assertSame($preRefundGroups, $figures->preRefundTaxGroups);
        self::assertSame('0.0800', $figures->taxGroups()[0]->taxRate);
    }

    /**
     * toArray() feeds the cross-surface agreement test (every other presentation and export
     * surface builds its 'refund' totals from the same four keys), so it must not gain a
     * tax_groups key that only this surface knows to populate. Callers that want the
     * breakdown read preRefundTaxGroups / taxGroups() directly, as RefundReceipt does.
     */
    public function testToArrayDoesNotAddATaxGroupsKeyToEitherSection(): void
    {
        $array = $this->figures([$this->line('010', '0.1000', '1200.0000', '0.0000', '120.0000')])->toArray();

        self::assertSame(['subtotal', 'shipping', 'tax', 'grand_total'], array_keys($array['pre_refund']));
        self::assertSame(['subtotal', 'shipping', 'tax', 'grand_total'], array_keys($array['refund']));
    }

    /**
     * @param RefundFigureLine[] $lines
     */
    private function figures(array $lines): RefundFigures
    {
        return new RefundFigures(
            '0.0000',
            '0.0000',
            '0.0000',
            '0.0000',
            [],
            '0.0000',
            '0.0000',
            '0.0000',
            '0.0000',
            $lines,
            'JPY',
            true
        );
    }

    private function line(
        string $taxCode,
        string $taxRate,
        string $rowAmount,
        string $shippingAmount,
        string $taxAmount
    ): RefundFigureLine {
        return new RefundFigureLine(
            1,
            'SKU-1',
            'Product',
            '1.0000',
            $rowAmount,
            $rowAmount,
            $shippingAmount,
            $taxRate,
            $taxCode,
            $taxAmount,
            bcadd(bcadd($rowAmount, $shippingAmount, 4), $taxAmount, 4)
        );
    }
}
