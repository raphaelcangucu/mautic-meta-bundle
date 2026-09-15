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

    public function testMetaSenderUsesCanonicalBrazilianMobileIdentity(): void
    {
        self::assertSame('5531984326486', (new PhoneNormalizer())->normalizeMetaSender('553184326486', 'BR'));
    }

    public function testInvalidMetaSenderFallsBackToTrimmedOriginal(): void
    {
        self::assertSame('not-a-phone', (new PhoneNormalizer())->normalizeMetaSender('  not-a-phone  ', 'BR'));
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

    public function testBrazilianMetaRecipientAliasesContainCanonicalAndLegacyForms(): void
    {
        $normalizer = new PhoneNormalizer();

        self::assertSame(
            ['5531984326486', '553184326486'],
            $normalizer->equivalentRecipients('553184326486', 'BR'),
        );
        self::assertSame(
            ['5531984326486', '553184326486'],
            $normalizer->equivalentRecipients('+55 31 98432-6486', 'BR'),
        );
    }
}
