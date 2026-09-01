<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Security;

use MauticPlugin\MauticMetaBundle\Security\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase
{
    public function testAcceptsValidSignature(): void
    {
        $payload = '{"object":"whatsapp_business_account"}';
        $secret = 'app-secret';
        self::assertTrue((new WebhookSignatureVerifier())->verify($payload, 'sha256='.hash_hmac('sha256', $payload, $secret), $secret));
    }

    public function testRejectsModifiedPayloadAndMalformedSignature(): void
    {
        $verifier = new WebhookSignatureVerifier();
        self::assertFalse($verifier->verify('modified', 'sha256='.hash_hmac('sha256', 'original', 'secret'), 'secret'));
        self::assertFalse($verifier->verify('payload', 'invalid', 'secret'));
        self::assertFalse($verifier->verify('payload', '', 'secret'));
    }

    public function testVerifiesSubscriptionChallenge(): void
    {
        $verifier = new WebhookSignatureVerifier();
        self::assertTrue($verifier->verifyChallenge('subscribe', 'token', 'token'));
        self::assertFalse($verifier->verifyChallenge('subscribe', 'wrong', 'token'));
        self::assertFalse($verifier->verifyChallenge('unsubscribe', 'token', 'token'));
    }
}
