<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\WhatsApp;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSender;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class WhatsAppSenderTest extends TestCase
{
    public function testSendsTextThroughSelectedPhoneAsset(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::isInstanceOf(MetaConnection::class),
            'phone-123/messages',
            self::callback(static fn (array $payload): bool => '5511999999999' === $payload['to'] && 'Hello' === $payload['text']['body']),
        )->willReturn(['messages' => [['id' => 'wamid.123']]]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');
        $identities = $this->createMock(IdentityManager::class);
        $identities->expects(self::once())->method('assertCanSend');

        $result = (new WhatsAppSender($graph, $entityManager, new PhoneNormalizer(), $identities))->sendText($this->asset(), '(11) 99999-9999', 'Hello');

        self::assertSame('wamid.123', $result->messageId);
        self::assertSame('accepted', $result->status);
    }

    public function testRejectsInactiveOrWrongAsset(): void
    {
        $asset = $this->asset()->setStatus('disabled');
        $sender = $this->sender();
        $this->expectException(\InvalidArgumentException::class);
        $sender->sendText($asset, '5511999999999', 'Hello');
    }

    public function testRejectsInvalidMediaType(): void
    {
        $sender = $this->sender();
        $this->expectException(\InvalidArgumentException::class);
        $sender->sendMedia($this->asset(), '5511999999999', 'executable', ['link' => 'https://example.test/file']);
    }

    public function testSendsMediaThroughOfficialPayload(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::isInstanceOf(MetaConnection::class),
            'phone-123/messages',
            self::callback(static fn (array $payload): bool => 'image' === $payload['type'] && 'https://cdn.example.test/image.jpg' === $payload['image']['link']),
        )->willReturn(['messages' => [['id' => 'wamid.media']] ]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $identities = $this->createMock(IdentityManager::class);
        $identities->expects(self::once())->method('assertCanSend');

        $result = (new WhatsAppSender($graph, $entityManager, new PhoneNormalizer(), $identities))->sendMedia(
            $this->asset(),
            '5511999999999',
            'image',
            ['link' => 'https://cdn.example.test/image.jpg', 'caption' => 'Product'],
        );

        self::assertSame('wamid.media', $result->messageId);
    }

    public function testSendsInteractiveButtonPayload(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::anything(),
            'phone-123/messages',
            self::callback(static fn (array $payload): bool => 'interactive' === $payload['type'] && 'button' === $payload['interactive']['type']),
        )->willReturn(['messages' => [['id' => 'wamid.interactive']]]);

        $result = (new WhatsAppSender($graph, $this->createMock(EntityManagerInterface::class), new PhoneNormalizer(), $this->createMock(IdentityManager::class)))->sendInteractive(
            $this->asset(),
            '5511999999999',
            ['type' => 'button', 'body' => ['text' => 'Choose'], 'action' => ['buttons' => []]],
        );

        self::assertSame('wamid.interactive', $result->messageId);
    }

    public function testRejectsInvalidInteractivePayload(): void
    {
        $sender = $this->sender();
        $this->expectException(\InvalidArgumentException::class);
        $sender->sendInteractive($this->asset(), '5511999999999', ['type' => 'unknown']);
    }

    private function asset(): MetaAsset
    {
        return (new MetaAsset())
            ->setConnection((new MetaConnection())->setName('Primary'))
            ->setExternalId('phone-123')
            ->setName('Sales')
            ->setType(AssetType::WhatsAppPhoneNumber)
            ->setStatus('active')
            ->setIsPublished(true)
            ->setSettings(['default_region' => 'BR']);
    }

    private function sender(): WhatsAppSender
    {
        return new WhatsAppSender(
            $this->createMock(MetaGraphClientInterface::class),
            $this->createMock(EntityManagerInterface::class),
            new PhoneNormalizer(),
            $this->createMock(IdentityManager::class),
        );
    }
}
