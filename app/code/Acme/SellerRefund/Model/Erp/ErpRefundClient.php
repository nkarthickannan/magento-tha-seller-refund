<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Erp;

use Acme\SellerRefund\Model\Config;
use Acme\SellerRefund\Model\Erp\Exception\ErpBusinessException;
use Acme\SellerRefund\Model\Erp\Exception\ErpConflictException;
use Acme\SellerRefund\Model\Erp\Exception\ErpTransientException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Thin transport over the three ERP Refund API operations. Classifies every outcome as
 * success, transient (retry), conflict, or a terminal business rejection.
 */
class ErpRefundClient
{
    private const CREATE_PATH = '/erp-api/v1/refunds';

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, RequestKey $key): CreateResult
    {
        $curl = $this->newCurl($key->getValue());
        $curl->addHeader('X-Request-Id', $key->getValue());
        $uri = $this->config->erpBaseUrl() . self::CREATE_PATH;

        $this->send($curl, $uri, $payload);
        $this->classify($curl, false);

        $body = $this->decode($curl->getBody());

        return new CreateResult(
            (string) ($body['erp_refund_id'] ?? ''),
            (string) ($body['status'] ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function readStatus(string $erpId): array
    {
        $curl = $this->newCurl($erpId . ':status');
        $uri = $this->config->erpBaseUrl() . self::CREATE_PATH . '/' . rawurlencode($erpId);

        try {
            $curl->get($uri);
        } catch (\Exception $e) {
            throw new ErpTransientException('The ERP status read failed at the transport level.', 0, null, $e);
        }

        $this->classify($curl, true);

        return $this->decode($curl->getBody());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function confirm(string $erpId, array $payload): array
    {
        $curl = $this->newCurl($erpId . ':confirm');
        $uri = $this->config->erpBaseUrl() . self::CREATE_PATH . '/' . rawurlencode($erpId) . '/confirm';

        $this->send($curl, $uri, $payload);
        $this->classify($curl, false);

        return $this->decode($curl->getBody());
    }

    private function newCurl(string $correlationId): Curl
    {
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, $this->config->connectTimeout());
        $curl->setTimeout($this->config->timeout());
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('X-Correlation-Id', $correlationId);

        return $curl;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(Curl $curl, string $uri, array $payload): void
    {
        try {
            $curl->post($uri, $this->encode($payload));
        } catch (\Exception $e) {
            throw new ErpTransientException('The ERP call failed at the transport level.', 0, null, $e);
        }
    }

    private function classify(Curl $curl, bool $isStatusRead): void
    {
        $status = $curl->getStatus();

        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 409) {
            throw new ErpConflictException('The ERP reported a conflicting refund state.');
        }

        if ($status === 408 || $status === 429 || $status >= 500 || ($isStatusRead && $status === 404)) {
            throw new ErpTransientException(
                sprintf('The ERP returned a transient error (HTTP %d).', $status),
                $status,
                $this->retryAfter($curl)
            );
        }

        $body = $this->decode($curl->getBody());
        throw new ErpBusinessException(
            sprintf('The ERP rejected the refund (HTTP %d).', $status),
            (string) ($body['error_code'] ?? '')
        );
    }

    private function retryAfter(Curl $curl): ?int
    {
        $headers = $curl->getHeaders();
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after' && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
