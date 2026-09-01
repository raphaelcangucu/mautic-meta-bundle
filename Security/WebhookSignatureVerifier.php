<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Security;

final class WebhookSignatureVerifier
{
    public function verify(string $payload, string $signature, string $appSecret): bool
    {
        if ('' === $signature || '' === $appSecret || !str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $appSecret), $signature);
    }

    public function verifyChallenge(string $mode, string $providedToken, string $expectedToken): bool
    {
        return 'subscribe' === $mode && '' !== $expectedToken && hash_equals($expectedToken, $providedToken);
    }
}
