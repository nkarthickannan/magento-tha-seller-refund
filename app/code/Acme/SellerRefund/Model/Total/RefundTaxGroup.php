<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Total;

/**
 * One tax-rate group of a refund: the taxable (ex-tax) amount and the tax amount for every
 * line that shares a tax rate. This is what a qualified invoice under the JCT reduced-rate
 * regime must disclose per rate (FRD 9.2), so it is grouped by rate, not by tax code.
 */
final class RefundTaxGroup
{
    public function __construct(
        public readonly string $taxRate,
        public readonly string $taxCode,
        public readonly string $taxableAmount,
        public readonly string $taxAmount
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'tax_rate' => $this->taxRate,
            'tax_code' => $this->taxCode,
            'taxable_amount' => $this->taxableAmount,
            'tax_amount' => $this->taxAmount,
        ];
    }

    /**
     * Folds per-line (rate, code, taxable, tax) rows into one group per rate. The single
     * grouping implementation shared by both the pre-refund seller totals and the refund
     * totals, so neither has to re-derive the other's breakdown.
     *
     * @param iterable<array{rate: string, code: string, taxable: string, tax: string}> $rows
     *
     * @return self[] ordered by ascending rate
     */
    public static function group(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $rate = $row['rate'];
            if (!isset($groups[$rate])) {
                $groups[$rate] = ['code' => $row['code'], 'taxable' => '0.0000', 'tax' => '0.0000'];
            }
            $groups[$rate]['taxable'] = bcadd($groups[$rate]['taxable'], $row['taxable'], 4);
            $groups[$rate]['tax'] = bcadd($groups[$rate]['tax'], $row['tax'], 4);
        }

        ksort($groups);

        $result = [];
        foreach ($groups as $rate => $group) {
            $result[] = new self($rate, $group['code'], $group['taxable'], $group['tax']);
        }

        return $result;
    }
}
