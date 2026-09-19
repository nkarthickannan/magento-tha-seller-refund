<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Block\Adminhtml\Refund;

use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\ResourceModel\Refund\Collection;
use Acme\SellerRefund\Model\ResourceModel\Refund\CollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Worklist grid backing block. Each row is enriched with its order summary, seller code and
 * line counts so the template can show them alongside the refund.
 */
class Worklist extends Template
{
    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundRepositoryInterface $refundRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRefunds(): Collection
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->setOrder('main_table.entity_id', Collection::SORT_ORDER_DESC);

        foreach ($collection as $refund) {
            $order = $this->orderRepository->get((int) $refund->getOrderId());
            $items = $this->refundRepository->getItems((int) $refund->getEntityId());

            $qty = '0';
            foreach ($items as $item) {
                $qty = bcadd($qty, (string) $item->getQtyRefund(), 4);
            }

            $refund->setData('order_increment_id', $order->getIncrementId());
            $refund->setData('seller_code', (string) $refund->getSellerOrderId());
            $refund->setData('line_count', count($items));
            $refund->setData('qty_refund_total', $qty);
            $refund->setData('order_grand_total', $order->getGrandTotal());
        }

        return $collection;
    }

    public function getViewUrl(int $refundId): string
    {
        return $this->getUrl('acme_refund/refund/view', ['refund_id' => $refundId]);
    }

    public function formatMoney(?string $amount, string $currency = 'JPY'): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }
}
