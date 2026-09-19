<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\ResourceModel;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Refund extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mp_refund', 'entity_id');
    }

    /**
     * Optimistic compare-and-set. Bumps version by one only when the stored version still
     * matches the expected value. Returns true when exactly one row was updated.
     *
     * @param array<string, mixed> $data
     */
    public function updateWithVersion(int $id, int $expectedVersion, array $data): bool
    {
        $connection = $this->getConnection();

        $set = [];
        $bind = [];
        foreach ($data as $column => $value) {
            $set[] = $connection->quoteIdentifier($column) . ' = ?';
            $bind[] = $value;
        }
        $set[] = $connection->quoteIdentifier('version') . ' = ' . $connection->quoteIdentifier('version') . ' + 1';

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ? AND %s = ?',
            $connection->quoteIdentifier($this->getMainTable()),
            implode(', ', $set),
            $connection->quoteIdentifier('entity_id'),
            $connection->quoteIdentifier('version')
        );
        $bind[] = $id;
        $bind[] = $expectedVersion;

        return $connection->query($sql, $bind)->rowCount() === 1;
    }

    protected function _beforeSave(AbstractModel $object): AbstractDb
    {
        if (!$object->isObjectNew()
            && ($object->dataHasChangedFor(RefundInterface::STATUS)
                || $object->dataHasChangedFor(RefundInterface::VERSION))
        ) {
            throw new \RuntimeException(
                'Refund status and version are updated only through the optimistic-locking path.'
            );
        }

        return parent::_beforeSave($object);
    }
}
