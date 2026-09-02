<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Consent;

use MauticPlugin\MauticMetaBundle\Application\Consent\WhatsAppConsentRegistrationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppConsentRegistrationServiceTest extends TestCase
{
    #[DataProvider('invalidEvidence')]
    public function testConsentIsRejectedBeforeAnyWrite(array $input, string $expected): void
    {
        $service = (new \ReflectionClass(WhatsAppConsentRegistrationService::class))->newInstanceWithoutConstructor();
        $result = $service->register($input);

        self::assertSame('rejected', $result['status']);
        self::assertStringContainsString($expected, (string) $result['error']);
    }

    public static function invalidEvidence(): iterable
    {
        yield 'false is never consent' => [['consent' => false], 'boolean true'];
        yield 'missing is never consent' => [[], 'boolean true'];
        yield 'incomplete evidence' => [['consent' => true], 'assetId is required'];
    }
}
