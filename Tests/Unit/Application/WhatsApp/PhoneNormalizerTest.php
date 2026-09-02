<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\WhatsApp;

use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizerTest extends TestCase
{
    public function testImportedBrazilianMobileIsConvertedFromLegacyEightDigitFormat(): void
    {
        self::assertSame('5531998417391', (new PhoneNormalizer())->normalizeImported('31 9841-7391', 'BR'));
    }

    public function testLegacyConversionCanBeDisabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PhoneNormalizer())->normalizeImported('31 9841-7391', 'BR', false);
    }

    public function testItDoesNotInventDigitsForShortNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PhoneNormalizer())->normalizeImported('98417391', 'BR');
    }
}
