<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

final class MetaGraphApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $error
     */
    public function __construct(
        private readonly string $requestMethod,
        private readonly string $requestEndpoint,
        private readonly int $httpStatus,
        private readonly array $error,
    ) {
        parent::__construct((string) ($error['message'] ?? 'Meta Graph API request failed.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [
            'message'       => $this->getMessage(),
            'type'          => $this->error['type'] ?? null,
            'code'          => $this->error['code'] ?? null,
            'error_subcode' => $this->error['error_subcode'] ?? null,
            'fbtrace_id'    => $this->error['fbtrace_id'] ?? null,
            'http_status'   => $this->httpStatus,
            'method'        => $this->requestMethod,
            'endpoint'      => $this->requestEndpoint,
        ];
    }

    public function isRetryable(): bool
    {
        return 429 === $this->httpStatus || $this->httpStatus >= 500;
    }
}
