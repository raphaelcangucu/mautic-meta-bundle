<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Webhook;
use MauticPlugin\MauticMetaBundle\Application\Webhook\FacebookWebhookParser;
use PHPUnit\Framework\TestCase;

final class FacebookWebhookParserTest extends TestCase
{
    public function testIgnoresOtherObjectsEchoesAndUnrelatedRecipients(): void
    {
        $parser = new FacebookWebhookParser();
        self::assertSame([], $parser->parse(['object' => 'instagram']));
        $event = ['sender' => ['id' => 'u1'], 'recipient' => ['id' => 'p1'], 'message' => ['mid' => 'm1', 'text' => 'Oi', 'is_echo' => true]];
        self::assertSame([], $parser->parse(['object' => 'page', 'entry' => [['id' => 'p1', 'messaging' => [$event]]]]));
        $event['message']['is_echo'] = false; $event['recipient']['id'] = 'p2';
        self::assertSame([], $parser->parse(['object' => 'page', 'entry' => [['id' => 'p1', 'messaging' => [$event]]]]));
    }
    public function testHandlesCommentWithoutAuthorAndMessengerAttachment(): void
    {
        $parsed = (new FacebookWebhookParser())->parse(['object' => 'page', 'entry' => [['id' => 'p1', 'time' => 1700000000, 'changes' => [['field' => 'feed', 'value' => ['item' => 'comment', 'verb' => 'add', 'comment_id' => 'c1', 'post_id' => 'v1', 'message' => 'Oi']]], 'messaging' => [['sender' => ['id' => 'u1'], 'recipient' => ['id' => 'p1'], 'timestamp' => 1700000000123, 'message' => ['mid' => 'm1', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://example.com/p.jpg']]]]]]]]]);
        self::assertCount(2, $parsed);
        self::assertSame('', $parsed[0]['commenterId']);
        self::assertSame('v1', $parsed[0]['mediaId']);
        self::assertSame(1700000000, $parsed[1]['timestamp']);
        self::assertCount(1, $parsed[1]['attachments']);
    }
}
