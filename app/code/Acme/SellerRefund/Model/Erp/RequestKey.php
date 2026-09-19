<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp;

use Acme\SellerRefund\Api\Data\RefundInterface;

/**
 * Per-attempt ERP request key. The first attempt sends the plain refund number; a later
 * attempt sends a suffixed key so ERP-side audit entries for each attempt can be told apart.
 */
final class RequestKey
{
    private function __construct(
        private readonly string $value
    ) {
    }

    public static function forAttempt(RefundInterface $refund, int $attemptNo): self
    {
        $refundNo = $refund->getRefundNo();
        if ($attemptNo <= 1) {
            return new self($refundNo);
        }

        return new self(sprintf('%s-%02d', $refundNo, $attemptNo));
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
