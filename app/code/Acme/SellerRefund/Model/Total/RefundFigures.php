<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Total;

/**
 * The full set of figures a refund renders on every surface: the original pre-refund seller
 * totals and the refund totals, plus the per-line refund breakdown.
 */
final class RefundFigures
{
    /**
     * @param RefundTaxGroup[] $preRefundTaxGroups the pre-refund seller tax, grouped by rate;
     *                                              computed once by RefundTotalCalculator::preRefund()
     * @param RefundFigureLine[] $lines
     */
    public function __construct(
        public readonly string $preRefundSubtotal,
        public readonly string $preRefundShipping,
        public readonly string $preRefundTax,
        public readonly string $preRefundGrandTotal,
        public readonly array $preRefundTaxGroups,
        public readonly string $refundSubtotal,
        public readonly string $refundShipping,
        public readonly string $refundTax,
        public readonly string $refundGrandTotal,
        public readonly array $lines,
        public readonly string $currency,
        public readonly bool $partial
    ) {
    }

    public function isPartial(): bool
    {
        return $this->partial;
    }

    /**
     * The refund tax broken down by tax rate: the taxable (ex-tax) amount and the tax amount
     * for every refunded line sharing a rate, summed straight from the already seller-filtered
     * lines via the same grouping RefundTotalCalculator::preRefund() uses for the original
     * totals. No figure here is derived by dividing another one, so it cannot drift from the
     * per-line amounts stored on the refund.
     *
     * @return RefundTaxGroup[] ordered by ascending rate
     */
    public function taxGroups(): array
    {
        return RefundTaxGroup::group(array_map(
            static fn (RefundFigureLine $line): array => [
                'rate' => $line->taxRate,
                'code' => $line->taxCode,
                'taxable' => bcadd($line->rowAmount, $line->shippingAmount, 4),
                'tax' => $line->taxAmount,
            ],
            $this->lines
        ));
    }

    /**
     * The 'pre_refund' and 'refund' shapes here are relied on verbatim by every other
     * presentation and export surface (see SurfacesAgreeOnPureSellerOrderTest), so they stay
     * exactly the four totals those surfaces already agree on. The tax-rate breakdown is
     * intentionally not part of this generic shape - callers that want it read
     * preRefundTaxGroups or taxGroups() directly, as RefundReceipt does.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'is_partial' => $this->partial,
            'pre_refund' => [
                'subtotal' => $this->preRefundSubtotal,
                'shipping' => $this->preRefundShipping,
                'tax' => $this->preRefundTax,
                'grand_total' => $this->preRefundGrandTotal,
            ],
            'refund' => [
                'subtotal' => $this->refundSubtotal,
                'shipping' => $this->refundShipping,
                'tax' => $this->refundTax,
                'grand_total' => $this->refundGrandTotal,
            ],
            'lines' => array_map(static fn (RefundFigureLine $line): array => $line->toArray(), $this->lines),
        ];
    }
}
