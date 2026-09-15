<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Connection;

use MauticPlugin\MauticMetaBundle\Application\Connection\WhatsAppOperationalHealth;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;
use PHPUnit\Framework\TestCase;

final class WhatsAppOperationalHealthTest extends TestCase
{
    public function testRecentInboundAndDeliveredOutboundAreOperationalProof(): void
    {
        $asset = (new MetaAsset(13))
            ->setType(AssetType::WhatsAppPhoneNumber)
            ->setPhoneNumber('+55 31 7544-1171');
        $inbound = $this->message($asset, 'inbound', 'received', '2026-09-14 09:30:00');
        $outbound = $this->message($asset, 'outbound', 'delivered', '2026-09-14 09:33:00');
        $repository = $this->createMock(MetaMessageRepository::class);
        $repository->expects(self::exactly(2))->method('findLatestForAssetDirection')
            ->willReturnCallback(static fn (MetaAsset $candidate, string $direction): ?MetaMessage => match ($direction) {
                'inbound' => $inbound,
                'outbound' => $outbound,
            });

        $health = (new WhatsAppOperationalHealth($repository))->forAssets([$asset], new \DateTimeImmutable('2026-09-14 12:00:00'))[13];

        self::assertSame('healthy', $health['state']);
        self::assertTrue($health['hasOperationalProof']);
        self::assertSame('1171', $health['phoneSuffix']);
        self::assertSame('WhatsApp •••• 1171', $health['displayNumber']);
        self::assertStringNotContainsString('7544', $health['displayNumber']);
        self::assertTrue($health['outbound']['deliveryConfirmed']);
    }

    public function testMetaAcceptanceDoesNotClaimRecipientDelivery(): void
    {
        $asset = (new MetaAsset(7))->setType(AssetType::WhatsAppPhoneNumber)->setPhoneNumber('551199998888');
        $inbound = $this->message($asset, 'inbound', 'received', '2026-09-14 09:30:00');
        $accepted = $this->message($asset, 'outbound', 'accepted', '2026-09-14 09:31:00');
        $repository = $this->createMock(MetaMessageRepository::class);
        $repository->method('findLatestForAssetDirection')
            ->willReturnCallback(static fn (MetaAsset $candidate, string $direction): ?MetaMessage => 'inbound' === $direction ? $inbound : $accepted);

        $health = (new WhatsAppOperationalHealth($repository))->forAssets([$asset], new \DateTimeImmutable('2026-09-14 12:00:00'))[7];

        self::assertSame('awaiting_delivery', $health['state']);
        self::assertFalse($health['hasOperationalProof']);
        self::assertTrue($health['outbound']['acceptedByMeta']);
        self::assertFalse($health['outbound']['deliveryConfirmed']);
    }

    public function testOldEvidenceIsShownAsHistoryInsteadOfCurrentHealth(): void
    {
        $asset = (new MetaAsset(9))->setType(AssetType::WhatsAppPhoneNumber)->setPhoneNumber('551188887777');
        $oldInbound = $this->message($asset, 'inbound', 'received', '2026-08-01 09:30:00');
        $repository = $this->createMock(MetaMessageRepository::class);
        $repository->method('findLatestForAssetDirection')
            ->willReturnCallback(static fn (MetaAsset $candidate, string $direction): ?MetaMessage => 'inbound' === $direction ? $oldInbound : null);

        $health = (new WhatsAppOperationalHealth($repository))->forAssets([$asset], new \DateTimeImmutable('2026-09-14 12:00:00'))[9];

        self::assertSame('stale', $health['state']);
        self::assertFalse($health['hasOperationalProof']);
    }

    public function testRecentOutboundFailureIsNeverReportedAsHealthy(): void
    {
        $asset = (new MetaAsset(11))->setType(AssetType::WhatsAppPhoneNumber)->setPhoneNumber('551177771171');
        $inbound = $this->message($asset, 'inbound', 'received', '2026-09-14 09:30:00');
        $failed = $this->message($asset, 'outbound', 'failed', '2026-09-14 09:31:00');
        $repository = $this->createMock(MetaMessageRepository::class);
        $repository->method('findLatestForAssetDirection')
            ->willReturnCallback(static fn (MetaAsset $candidate, string $direction): ?MetaMessage => 'inbound' === $direction ? $inbound : $failed);

        $health = (new WhatsAppOperationalHealth($repository))->forAssets([$asset], new \DateTimeImmutable('2026-09-14 12:00:00'))[11];

        self::assertSame('degraded', $health['state']);
        self::assertFalse($health['hasOperationalProof']);
        self::assertSame('failed', $health['outbound']['status']);
    }

    private function message(MetaAsset $asset, string $direction, string $status, string $date): MetaMessage
    {
        $occurredAt = new \DateTimeImmutable($date);

        return (new MetaMessage())
            ->setAsset($asset)
            ->setChannel('whatsapp')
            ->setDirection($direction)
            ->setStatus($status)
            ->setDateAdded($occurredAt)
            ->setDateModified($occurredAt);
    }
}
