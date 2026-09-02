<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Safety;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use PHPUnit\Framework\TestCase;

final class OutboundPolicyTest extends TestCase
{
    public function testBlocksAtConservativeWhatsAppDailyLimit(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('fetchOne')->willReturn(250);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('daily limit');
        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppPhoneNumber), 'whatsapp', '5511999999999', 'template');
    }

    public function testBlocksWhatsAppFreeFormOutsideCustomerServiceWindow(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(5))->method('fetchOne')->willReturnOnConsecutiveCalls(0, 0, 0, 0, 0);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('last 24 hours');
        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppPhoneNumber), 'whatsapp', '5511999999999', 'text');
    }

    public function testAllowsApprovedWhatsAppTemplateWithinLocalLimits(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(4))->method('fetchOne')->willReturn(0);

        (new OutboundPolicy($db))->assertAllowed($this->asset(AssetType::WhatsAppPhoneNumber), 'whatsapp', '5511999999999', 'template');
        self::assertTrue(true);
    }

    private function asset(AssetType $type): MetaAsset
    {
        return (new MetaAsset(7))->setType($type)->setSettings([]);
    }
}
