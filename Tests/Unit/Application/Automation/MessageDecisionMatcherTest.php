<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Automation;

use MauticPlugin\MauticMetaBundle\Application\Automation\MessageDecisionMatcher;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use PHPUnit\Framework\TestCase;

final class MessageDecisionMatcherTest extends TestCase
{
    public function testMatchesMessageFieldsAndCaseInsensitiveText(): void
    {
        $message = (new MetaMessage())->setChannel('whatsapp')->setDirection('inbound')->setStatus('received')->setMessageType('text')->setPayload(['message' => ['text' => ['body' => 'Quero o CATÁLOGO']]]);

        self::assertTrue((new MessageDecisionMatcher())->matches($message, ['channel' => 'whatsapp', 'direction' => 'inbound', 'status' => 'received', 'message_type' => 'text', 'pattern' => 'catálogo']));
    }

    public function testRejectsDifferentStatus(): void
    {
        $message = (new MetaMessage())->setChannel('whatsapp')->setDirection('outbound')->setStatus('failed')->setMessageType('template');

        self::assertFalse((new MessageDecisionMatcher())->matches($message, ['status' => 'delivered']));
    }
}
