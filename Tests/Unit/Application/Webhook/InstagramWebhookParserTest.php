<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Webhook;

use MauticPlugin\MauticMetaBundle\Application\Webhook\InstagramWebhookParser;
use PHPUnit\Framework\TestCase;

final class InstagramWebhookParserTest extends TestCase
{
    public function testParsesCommentDmAndPostback(): void
    {
        $payload = ['object' => 'instagram', 'entry' => [[
            'id' => 'ig-1',
            'changes' => [['field' => 'comments', 'value' => ['id' => 'comment-1', 'text' => 'LINK', 'media' => ['id' => 'media-1'], 'from' => ['id' => 'user-1', 'username' => 'visitor']]]],
            'messaging' => [
                ['sender' => ['id' => 'user-2'], 'message' => ['mid' => 'mid-1', 'text' => 'INFO']],
                ['sender' => ['id' => 'user-3'], 'postback' => ['mid' => 'mid-2', 'payload' => 'SHOW_LINK']],
            ],
        ]]];

        $events = (new InstagramWebhookParser())->parse($payload);

        self::assertSame('comment-1', $events['comments'][0]['commentId']);
        self::assertSame('INFO', $events['messages'][0]['text']);
        self::assertSame('SHOW_LINK', $events['postbacks'][0]['payload']);
    }

    public function testDropsEchoesAndSelfComments(): void
    {
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'ig-1',
                'changes' => [[
                    'field' => 'comments',
                    'value' => ['id' => 'c', 'media' => ['id' => 'm'], 'from' => ['id' => 'ig-1']],
                ]],
                'messaging' => [[
                    'sender' => ['id' => 'user'],
                    'message' => ['mid' => 'mid', 'text' => 'x', 'is_echo' => true],
                ]],
            ]],
        ];
        $events = (new InstagramWebhookParser())->parse($payload);
        self::assertSame([], $events['comments']);
        self::assertSame([], $events['messages']);
    }
}
