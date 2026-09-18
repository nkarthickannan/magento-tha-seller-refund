<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Total\RefundFigureLine;
use Acme\SellerRefund\Model\Total\RefundTaxGroup;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Reissued receipt / qualified tax invoice for a refund. Every figure is taken from the
 * stored refund snapshot through the calculator, so the receipt agrees with the other
 * presentation surfaces and the downstream export. Labels are ASCII only.
 */
class RefundReceipt
{
    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * View data for the receipt. This is the harness inspection point.
     *
     * 'pre_refund' and 'refund' are deliberately kept identical in shape to
     * RefundFigures::toArray() - every other presentation and export surface builds those same
     * four keys, and SurfacesAgreeOnPureSellerOrderTest asserts this receipt's 'refund' matches
     * them exactly. The tax-rate breakdown is therefore its own top-level key, not nested
     * inside either total.
     *
     * @return array<string, mixed>
     */
    public function buildData(RefundInterface $refund): array
    {
        $items = $this->refundRepository->getItems((int) $refund->getEntityId());
        $order = $this->orderRepository->get($refund->getOrderId());
        $figures = $this->calculator->fromSnapshot($refund, $items, $order);

        return [
            'refund_no' => $refund->getRefundNo(),
            'currency' => $figures->currency,
            'is_partial' => $figures->isPartial(),
            'refund_date' => $this->refundDate($refund),
            'pre_refund' => [
                'subtotal' => $figures->preRefundSubtotal,
                'shipping' => $figures->preRefundShipping,
                'tax' => $figures->preRefundTax,
                'grand_total' => $figures->preRefundGrandTotal,
            ],
            'pre_refund_tax_groups' => $this->groupsToArray($figures->preRefundTaxGroups),
            'refund' => [
                'subtotal' => $figures->refundSubtotal,
                'shipping' => $figures->refundShipping,
                'tax' => $figures->refundTax,
                'grand_total' => $figures->refundGrandTotal,
            ],
            'refund_tax_groups' => $this->groupsToArray($figures->taxGroups()),
            'lines' => array_map(
                static fn (RefundFigureLine $line): array => $line->toArray(),
                $figures->lines
            ),
        ];
    }

    /**
     * Render the receipt as PDF bytes.
     */
    public function render(RefundInterface $refund): string
    {
        $data = $this->buildData($refund);
        $currency = (string) $data['currency'];

        $pdf = new \Zend_Pdf();
        $page = new \Zend_Pdf_Page(\Zend_Pdf_Page::SIZE_A4);
        $pdf->pages[] = $page;
        $font = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA);
        $bold = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD);

        $y = 800;
        $page->setFont($bold, 14);
        $y = $this->line($page, 'Refund Receipt / Qualified Invoice', $y, 18);

        $page->setFont($font, 10);
        $y = $this->line($page, 'Refund No: ' . $this->ascii((string) $data['refund_no']), $y);
        $y = $this->line($page, 'Refund Date: ' . $this->ascii((string) $data['refund_date']), $y);
        $y = $this->line($page, 'Refund Type: ' . ($data['is_partial'] ? 'Partial Refund' : 'Full Refund'), $y, 18);

        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refunded Lines', $y, 14);
        $page->setFont($font, 9);
        foreach ((array) $data['lines'] as $lineData) {
            $text = sprintf(
                '%s  x%s  rate %s  ex-tax %s  tax %s  total %s',
                $this->ascii((string) $lineData['sku']),
                $this->ascii((string) $lineData['qty_refund']),
                $this->ascii((string) $lineData['tax_rate']),
                $this->money($lineData['row_amount'], $currency),
                $this->money($lineData['tax_amount'], $currency),
                $this->money($lineData['grand_total'], $currency)
            );
            $y = $this->line($page, $text, $y);
        }

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Pre-refund Total', $y, 14);
        $page->setFont($font, 10);
        $y = $this->totals($page, $data['pre_refund'], $currency, $y);
        $page->setFont($font, 9);
        $y = $this->taxGroupLines($page, $data['pre_refund_tax_groups'], $currency, $y);

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refund', $y, 14);
        $page->setFont($font, 10);
        $y = $this->totals($page, $data['refund'], $currency, $y);
        $page->setFont($font, 9);
        $this->taxGroupLines($page, $data['refund_tax_groups'], $currency, $y);

        return $pdf->render();
    }

    /**
     * @param array<string, string> $totals
     */
    private function totals(\Zend_Pdf_Page $page, array $totals, string $currency, float $y): float
    {
        $y = $this->line($page, 'Product Subtotal: ' . $this->money($totals['subtotal'], $currency), $y);
        $y = $this->line($page, 'Shipping: ' . $this->money($totals['shipping'], $currency), $y);
        $y = $this->line($page, 'Consumption Tax: ' . $this->money($totals['tax'], $currency), $y);
        $y = $this->line($page, 'Total: ' . $this->money($totals['grand_total'], $currency), $y);

        return $y;
    }

    /**
     * The qualified-invoice breakdown of one section's consumption tax by rate.
     *
     * @param array<int, array<string, string>> $groups
     */
    private function taxGroupLines(\Zend_Pdf_Page $page, array $groups, string $currency, float $y): float
    {
        foreach ($groups as $group) {
            $ratePercent = number_format(((float) $group['tax_rate']) * 100, 0);
            $text = sprintf(
                '  of which %s%%: taxable %s, tax %s',
                $ratePercent,
                $this->money($group['taxable_amount'], $currency),
                $this->money($group['tax_amount'], $currency)
            );
            $y = $this->line($page, $text, $y);
        }

        return $y;
    }

    private function line(\Zend_Pdf_Page $page, string $text, float $y, int $step = 14): float
    {
        $page->drawText($text, 50, $y, 'UTF-8');

        return $y - $step;
    }

    private function refundDate(RefundInterface $refund): string
    {
        $createdAt = $refund->getCreatedAt();
        if ($createdAt === null || $createdAt === '') {
            return $this->timezone->date()->format('Y-m-d');
        }

        return $this->timezone->date(new \DateTime($createdAt))->format('Y-m-d');
    }

    private function money(mixed $amount, string $currency): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($converted === false) {
            $converted = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
        }

        return (string) preg_replace('/[^\x20-\x7E]/', '?', $converted);
    }

    /**
     * @param RefundTaxGroup[] $groups
     *
     * @return array<int, array<string, string>>
     */
    private function groupsToArray(array $groups): array
    {
        return array_map(static fn (RefundTaxGroup $group): array => $group->toArray(), $groups);
    }
}
