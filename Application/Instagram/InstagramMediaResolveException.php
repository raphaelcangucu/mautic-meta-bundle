<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

final class InstagramMediaResolveException extends \RuntimeException
{
    public function __construct(
        private readonly string $publicCode,
        private readonly int $httpStatus,
        private readonly bool $retryable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($publicCode, 0, $previous);
    }

    public function publicCode(): string
    {
        return $this->publicCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
