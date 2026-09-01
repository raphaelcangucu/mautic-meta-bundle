<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\WhatsApp;

use MauticPlugin\MauticMetaBundle\Application\WhatsApp\ConsentKeywordMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentKeywordMatcherTest extends TestCase
{
    #[DataProvider('keywords')]
    public function testMatchesExactLocalizedKeywords(string $message, ?string $expected): void
    {
        self::assertSame($expected, (new ConsentKeywordMatcher())->match($message));
    }

    public static function keywords(): iterable
    {
        yield [' parar ', 'opt_out'];
        yield ['STOP', 'opt_out'];
        yield ['sim', 'opt_in'];
        yield ['Quero parar depois', null];
        yield ['', null];
    }
}
