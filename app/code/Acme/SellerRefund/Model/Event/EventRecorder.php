<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Event;

use Acme\SellerRefund\Api\Data\RefundEventInterface;
use Acme\SellerRefund\Model\RefundEventFactory;
use Acme\SellerRefund\Model\ResourceModel\RefundEvent as RefundEventResource;

/**
 * Writes append-only audit events, redacting request and response payloads first.
 */
class EventRecorder
{
    public function __construct(
        private readonly RefundEventFactory $eventFactory,
        private readonly RefundEventResource $eventResource,
        private readonly PayloadRedactor $redactor
    ) {
    }

    /**
     * @param array<string, mixed> $ctx
     */
    public function record(int $refundId, string $type, string $eventStatus, array $ctx = []): void
    {
        /** @var \Acme\SellerRefund\Model\RefundEvent $event */
        $event = $this->eventFactory->create();
        $event->setRefundId($refundId)
            ->setEventType($type)
            ->setEventStatus($eventStatus)
            ->setApiCode(isset($ctx['api_code']) ? (string) $ctx['api_code'] : null)
            ->setStatusFrom(isset($ctx['status_from']) ? (string) $ctx['status_from'] : null)
            ->setStatusTo(isset($ctx['status_to']) ? (string) $ctx['status_to'] : null)
            ->setErrorCode(isset($ctx['error_code']) ? (string) $ctx['error_code'] : null)
            ->setAttemptNo(isset($ctx['attempt_no']) ? (int) $ctx['attempt_no'] : 1)
            ->setCreatedBy(isset($ctx['created_by']) && $ctx['created_by'] !== null ? (int) $ctx['created_by'] : null);

        $event->setData('request_key', isset($ctx['request_key']) ? (string) $ctx['request_key'] : null);

        if (isset($ctx['request_payload']) && is_array($ctx['request_payload'])) {
            $event->setRequestPayload($this->encode($this->redactor->redact($ctx['request_payload'])));
        }
        if (isset($ctx['response_payload']) && is_array($ctx['response_payload'])) {
            $event->setResponsePayload($this->encode($this->redactor->redact($ctx['response_payload'])));
        }

        $this->eventResource->save($event);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }
}
