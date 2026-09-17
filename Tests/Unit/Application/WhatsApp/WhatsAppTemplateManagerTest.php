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
            self::callback(static fn (array $payload): bool => 'order_update' === $payload['name'] && 'UTILITY' === $payload['category'] && isset($payload['components'][0]['example']['body_text'][0][0])),
        )->willReturn(['id' => 'template-1', 'status' => 'PENDING']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $manager = new WhatsAppTemplateManager($graph, $this->createMock(WhatsAppTemplateRepository::class), $entityManager);

        $template = $manager->create($this->asset(), 'order_update', 'pt_BR', 'UTILITY', [['type' => 'BODY', 'text' => 'Olá {{1}}, sua atualização está pronta.']]);

        self::assertSame('template-1', $template->getExternalId());
        self::assertSame('PENDING', $template->getStatus());
    }

    public function testNormalizesNumberedBodyMenuBeforePostingToMeta(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::isInstanceOf(MetaConnection::class),
            'waba-1/message_templates',
            self::callback(static function (array $payload): bool {
                $body = $payload['components'][0] ?? [];
                $buttons = $payload['components'][1]['buttons'] ?? [];

                return 'MARKETING' === $payload['category']
                    && 'BODY' === ($body['type'] ?? null)
                    && isset($body['example']['body_text'][0][0])
                    && !str_contains((string) ($body['text'] ?? ''), 'Responda com um número')
                    && isset($buttons[0]['type'], $buttons[2]['type'])
                    && 'QUICK_REPLY' === $buttons[0]['type']
                    && 3 === count($buttons);
            }),
        )->willReturn(['id' => 'template-2', 'status' => 'PENDING']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $manager = new WhatsAppTemplateManager($graph, $this->createMock(WhatsAppTemplateRepository::class), $entityManager);

        $template = $manager->create($this->asset(), 'atendimento_contato_opcoes', 'pt_BR', 'MARKETING', [[
            'type' => 'BODY',
            'text' => "Olá, {{1}}! Aqui é João Marcelo.\n\nResponda com um número:\n1. Tenho uma dúvida.\n2. Gostaria de receber atendimento.\n3. Não preciso de ajuda no momento.",
        ]]);

        self::assertSame('template-2', $template->getExternalId());
        self::assertSame('QUICK_REPLY', $template->getComponents()[1]['buttons'][0]['type']);
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
