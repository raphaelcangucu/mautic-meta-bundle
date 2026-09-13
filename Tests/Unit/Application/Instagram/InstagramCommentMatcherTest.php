<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramCommentMatcher;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use PHPUnit\Framework\TestCase;

final class InstagramCommentMatcherTest extends TestCase
{
    public function testMatchesWholeWordIgnoringCaseAndPortugueseAccent(): void
    {
        $matcher = new InstagramCommentMatcher();
        foreach (['RELATÓRIO', 'Quero relatorio!', 'O relatório, por favor'] as $text) {
            self::assertTrue($matcher->matches($this->comment($text), $this->rule()), $text);
        }
    }

    public function testRejectsOtherMediaAccountsAndSubstrings(): void
    {
        $matcher = new InstagramCommentMatcher();
        foreach (['prerelatorio', 'relatorios', 'relatorio_extra'] as $text) {
            self::assertFalse($matcher->matches($this->comment($text), $this->rule()), $text);
        }
        self::assertFalse($matcher->matches($this->comment('relatorio'), $this->rule(['asset_id' => 9])));
        self::assertFalse($matcher->matches($this->comment('relatorio'), $this->rule(['media_id' => '987654321'])));
        self::assertFalse($matcher->matches($this->comment('relatorio'), $this->rule(['media_id' => ''])));
        self::assertFalse($matcher->matches($this->comment('relatorio')->setMessageType('direct_message'), $this->rule()));
    }

    public function testOwnCommentIsRecognizedByCanonicalIdEvenWhenAssetUsesAnotherId(): void
    {
        self::assertTrue(InstagramCommentMatcher::isOwnComment('canonical-owner', 'business-asset', 'business-asset', 'canonical-owner'));
        self::assertFalse(InstagramCommentMatcher::isOwnComment('external-user', 'business-asset', 'business-asset', 'canonical-owner'));
        self::assertFalse(InstagramCommentMatcher::isOwnComment('', 'business-asset', 'business-asset', ''));
    }

    /** @param array<string, mixed> $overrides */
    private function rule(array $overrides = []): array
    {
        return array_replace(['asset_id' => 4, 'media_id' => '123456789', 'keyword' => 'relatorio'], $overrides);
    }

    private function comment(string $text): MetaMessage
    {
        return (new MetaMessage())->setAsset(new MetaAsset(4))->setChannel('instagram')->setDirection('inbound')->setMessageType('comment')->setPayload(['commentId' => 'comment-1', 'mediaId' => '123456789', 'text' => $text]);
    }
}
