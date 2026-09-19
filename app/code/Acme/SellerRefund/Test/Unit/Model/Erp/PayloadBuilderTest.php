<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Erp;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Model\Erp\PayloadBuilder;
use Acme\SellerRefund\Model\Erp\RequestKey;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage of the ERP Create Refund payload shape (FRD 9.1). Snapshot refund-item
 * mocks carry their own tax code (010 / 008); the builder never resolves a product or EAV.
 */
class PayloadBuilderTest extends TestCase
{
    private PayloadBuilder $builder;

    protected function setUp(): void
    {
        $taxCodeResolver = $this->createMock(TaxCodeResolver::class);
        $taxCodeResolver->method('toOptionId')->willReturnMap([['010', 7], ['008', 6]]);
        $this->builder = new PayloadBuilder($taxCodeResolver);
    }

    public function testBuildProducesTheFrdShape(): void
    {
        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getRefundNo')->willReturn('SR-20260907-000123');
        $refund->method('getSellerOrderId')->willReturn('SO-1002');
        $refund->method('getCurrencyCode')->willReturn('JPY');
        $refund->method('getShippingAmount')->willReturn('0.0000');
        $refund->method('getGrandTotal')->willReturn('3048.0000');

        $items = [
            $this->item('SELLER-RED-01', '1', '1200.0000', '010', '120.0000'),
            $this->item('SELLER-BLU-02', '2', '1600.0000', '008', '128.0000'),
        ];

        $payload = $this->builder->build(
            $refund,
            $items,
            $this->createMock(OrderInterface::class),
            RequestKey::forAttempt($refund, 1)
        );

        self::assertSame('SR-20260907-000123', $payload['refund_no']);
        self::assertSame('SO-1002', $payload['seller_order_id']);
        self::assertSame('TAX_EXCLUDED', $payload['tax_mode']);
        self::assertSame('JPY', $payload['currency']);
        self::assertSame('0.0000', $payload['shipping_amount']);
        self::assertSame('3048.0000', $payload['grand_total']);

        self::assertCount(2, $payload['lines']);
        self::assertSame([
            'sku' => 'SELLER-RED-01',
            'quantity' => 1,
            'amount' => '1200.0000',
            'taxes' => [['code' => '7', 'amount' => '120.0000']],
        ], $payload['lines'][0]);
        self::assertSame([
            'sku' => 'SELLER-BLU-02',
            'quantity' => 2,
            'amount' => '1600.0000',
            'taxes' => [['code' => '6', 'amount' => '128.0000']],
        ], $payload['lines'][1]);
    }

    public function testBuildTaxesReturnsOneCodeAmountEntry(): void
    {
        $item = $this->item('SELLER-RED-01', '1', '1200.0000', '010', '120.0000');

        $taxes = $this->builder->buildTaxes($item);

        self::assertSame([['code' => '7', 'amount' => '120.0000']], $taxes);
    }

    private function item(
        string $sku,
        string $qtyRefund,
        string $rowAmount,
        string $taxCode,
        string $taxAmount
    ): RefundItemInterface&MockObject {
        $item = $this->createMock(RefundItemInterface::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQtyRefund')->willReturn($qtyRefund);
        $item->method('getRowAmount')->willReturn($rowAmount);
        $item->method('getTaxCode')->willReturn($taxCode);
        $item->method('getTaxAmount')->willReturn($taxAmount);

        return $item;
    }
}
