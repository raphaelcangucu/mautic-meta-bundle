<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Webhook;

use MauticPlugin\MauticMetaBundle\Application\Webhook\WhatsAppWebhookParser;
use PHPUnit\Framework\TestCase;

final class WhatsAppWebhookParserTest extends TestCase
{
    public function testExtractsMessagesAndStatusesWithPhoneAsset(): void
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'phone-1'],
            'contacts' => [['wa_id' => '5511999999999']],
            'messages' => [['id' => 'wamid.in', 'from' => '5511999999999', 'type' => 'text', 'text' => ['body' => 'Oi']]],
            'statuses' => [['id' => 'wamid.out', 'status' => 'read']],
        ]]]]]];

        $parsed = (new WhatsAppWebhookParser())->parse($payload);

        self::assertSame('phone-1', $parsed['messages'][0]['phoneNumberId']);
        self::assertSame('wamid.in', $parsed['messages'][0]['message']['id']);
        self::assertSame('read', $parsed['statuses'][0]['status']['status']);
    }

    public function testIgnoresOtherMetaObjects(): void
    {
        self::assertSame(['messages' => [], 'statuses' => []], (new WhatsAppWebhookParser())->parse(['object' => 'instagram']));
    }
}
