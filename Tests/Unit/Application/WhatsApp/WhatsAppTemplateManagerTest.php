<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\WhatsApp;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppTemplateManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplateRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class WhatsAppTemplateManagerTest extends TestCase
{
    public function testCreatesTemplateInMetaAndLocally(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::isInstanceOf(MetaConnection::class),
            'waba-1/message_templates',
            self::callback(static fn (array $payload): bool => 'order_update' === $payload['name'] && 'UTILITY' === $payload['category']),
        )->willReturn(['id' => 'template-1', 'status' => 'PENDING']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $manager = new WhatsAppTemplateManager($graph, $this->createMock(WhatsAppTemplateRepository::class), $entityManager);

        $template = $manager->create($this->asset(), 'order_update', 'pt_BR', 'UTILITY', [['type' => 'BODY', 'text' => 'Olá {{1}}']]);

        self::assertSame('template-1', $template->getExternalId());
        self::assertSame('PENDING', $template->getStatus());
    }

    public function testRejectsInvalidTemplateNameBeforeCallingMeta(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method('post');
        $manager = new WhatsAppTemplateManager($graph, $this->createMock(WhatsAppTemplateRepository::class), $this->createMock(EntityManagerInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $manager->create($this->asset(), 'Invalid Name', 'pt_BR', 'UTILITY', [['type' => 'BODY', 'text' => 'Olá']]);
    }

    private function asset(): MetaAsset
    {
        return (new MetaAsset())
            ->setConnection((new MetaConnection())->setName('Primary'))
            ->setExternalId('waba-1')
            ->setName('WABA')
            ->setType(AssetType::WhatsAppBusinessAccount)
            ->setStatus('active')
            ->setIsPublished(true);
    }
}
