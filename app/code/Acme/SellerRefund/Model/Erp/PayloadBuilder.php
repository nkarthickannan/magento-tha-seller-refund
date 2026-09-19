<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Builds the ERP Refund API request bodies from the stored refund snapshot (FRD 9.1).
 */
class PayloadBuilder
{
    private const TAX_MODE = 'TAX_EXCLUDED';

    public function __construct(
        private readonly TaxCodeResolver $taxCodeResolver
    ) {
    }

    /**
     * @param RefundItemInterface[] $items
     *
     * @return array<string, mixed>
     */
    public function build(RefundInterface $refund, array $items, OrderInterface $order, RequestKey $key): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'sku' => $item->getSku(),
                'quantity' => (int) $item->getQtyRefund(),
                'amount' => $item->getRowAmount(),
                'taxes' => $this->buildTaxes($item),
            ];
        }

        return [
            'refund_no' => $key->getValue(),
            'seller_order_id' => $refund->getSellerOrderId(),
            'tax_mode' => self::TAX_MODE,
            'currency' => $refund->getCurrencyCode(),
            'lines' => $lines,
            'shipping_amount' => $refund->getShippingAmount(),
            'grand_total' => $refund->getGrandTotal(),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function buildTaxes(RefundItemInterface $item): array
    {
        return [
            [
                'code' => (string) $this->taxCodeResolver->toOptionId($item->getTaxCode()),
                'amount' => $item->getTaxAmount(),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function buildConfirm(RefundInterface $refund): array
    {
        return [
            'transaction_number' => $refund->getTransactionNumber(),
            'transaction_date' => $refund->getTransactionDate(),
        ];
    }
}
