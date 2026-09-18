<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Infrastructure;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\GraphTransport;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class GraphTransportTest extends TestCase
{
    public function testItPostsToTheAssetMessagesEdge(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $asset = (new MetaAsset())
            ->setConnection((new MetaConnection())->setName('Primary')->setStatus('active')->setIsPublished(true))
            ->setExternalId('55123')
            ->setName('Sales')
            ->setType(AssetType::WhatsAppPhoneNumber)
            ->setStatus('active')
            ->setIsPublished(true);
        $graph->expects($this->once())->method('post')
            ->with($asset->getConnection(), '55123/messages', ['type' => 'text'])
            ->willReturn(['messages' => [['id' => 'wamid.1']]]);

        $resposta = (new GraphTransport($graph))->post($asset, ['type' => 'text']);

        self::assertSame('wamid.1', $resposta['messages'][0]['id']);
    }
}
