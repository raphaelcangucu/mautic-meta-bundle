<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Infrastructure;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\TransportResolver;
use MauticPlugin\MauticMetaBundle\Infrastructure\WhatsAppTransportInterface;
use PHPUnit\Framework\TestCase;

final class TransportResolverTest extends TestCase
{
    public function testItPicksTheTransportByAssetType(): void
    {
        $graph = $this->createMock(WhatsAppTransportInterface::class);
        $qr = $this->createMock(WhatsAppTransportInterface::class);
        $resolver = new TransportResolver([
            AssetType::WhatsAppPhoneNumber->value => $graph,
            AssetType::WhatsAppQrSession->value   => $qr,
        ]);

        self::assertSame($graph, $resolver->forAsset($this->asset(AssetType::WhatsAppPhoneNumber)));
        self::assertSame($qr, $resolver->forAsset($this->asset(AssetType::WhatsAppQrSession)));
    }

    public function testAnAssetWithNoRegisteredTransportFails(): void
    {
        // Sem esta guarda o tipo sem transporte devolveria null e so estouraria
        // camadas adiante, sem dizer que faltou etiquetar um transporte.
        $resolver = new TransportResolver([
            AssetType::WhatsAppPhoneNumber->value => $this->createMock(WhatsAppTransportInterface::class),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(AssetType::WhatsAppQrSession->value);
        $resolver->forAsset($this->asset(AssetType::WhatsAppQrSession));
    }

    private function asset(AssetType $type): MetaAsset
    {
        return (new MetaAsset())
            ->setConnection((new MetaConnection())->setName('Primary')->setStatus('active')->setIsPublished(true))
            ->setExternalId('asset-123')
            ->setName('Asset')
            ->setType($type)
            ->setStatus('active')
            ->setIsPublished(true);
    }
}
