<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramPermalink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InstagramPermalinkTest extends TestCase
{
    #[DataProvider('validPermalinks')]
    public function testNormalizesSupportedPermalinks(string $value, string $normalized, string $shortcode, string $canonical): void
    {
        $permalink = InstagramPermalink::fromString($value);

        self::assertSame($normalized, $permalink->normalized);
        self::assertSame($shortcode, $permalink->shortcode);
        self::assertSame($canonical, $permalink->canonical);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function validPermalinks(): iterable
    {
        yield 'post with query and fragment' => [
            'https://www.instagram.com/p/DdRsy0CgJk7/?utm_source=copy#ignored',
            'instagram.com/p/DdRsy0CgJk7',
            'DdRsy0CgJk7',
            'https://www.instagram.com/p/DdRsy0CgJk7/',
        ];
        yield 'reel without www or trailing slash' => [
            'https://instagram.com/reel/AbC_123-xYz',
            'instagram.com/reel/AbC_123-xYz',
            'AbC_123-xYz',
            'https://www.instagram.com/reel/AbC_123-xYz/',
        ];
    }

    #[DataProvider('invalidPermalinks')]
    public function testRejectsInvalidOrExternalPermalinks(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InstagramPermalink::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPermalinks(): iterable
    {
        yield 'missing' => [''];
        yield 'external host' => ['https://example.com/p/DdRsy0CgJk7/'];
        yield 'lookalike host' => ['https://www.instagram.com.example.org/p/DdRsy0CgJk7/'];
        yield 'unsupported path' => ['https://www.instagram.com/stories/DdRsy0CgJk7/'];
        yield 'extra path' => ['https://www.instagram.com/p/DdRsy0CgJk7/comments/'];
    }
}
