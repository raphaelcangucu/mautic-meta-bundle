<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\WhatsApp;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Safety\OutboundPolicy;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSender;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Infrastructure\GraphTransport;
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
        $entityManager->expects(self::exactly(2))->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');
        $identities = $this->createMock(IdentityManager::class);
        $identities->expects(self::once())->method('assertCanSend');

        $result = (new WhatsAppSender(new GraphTransport($graph), $entityManager, new PhoneNormalizer(), $identities))->sendText($this->asset(), '(11) 99999-9999', 'Hello');

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

    public function testHumanRepliesInsideServiceWindowBypassCooldown(): void
    {
        $asset = $this->asset();
        $inbound = (new MetaMessage())
            ->setAsset($asset)
            ->setChannel('whatsapp')
            ->setDirection('inbound')
            ->setRecipient('5511999999999');
        $messages = $this->createMock(ObjectRepository::class);
        $messages->expects(self::exactly(2))->method('findOneBy')->willReturn($inbound);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('getRepository')->with(MetaMessage::class)->willReturn($messages);
        $identities = $this->createMock(IdentityManager::class);
        $identities->expects(self::exactly(2))->method('assertCanSend')->with($asset, '5511999999999', null, true);
        $database = $this->createMock(\Doctrine\DBAL\Connection::class);
        $database->expects(self::never())->method('fetchOne');
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(2))->method('post')->willReturnOnConsecutiveCalls(
            ['messages' => [['id' => 'wamid.first']]],
            ['messages' => [['id' => 'wamid.second']]],
        );
        $sender = new WhatsAppSender(new GraphTransport($graph), $entityManager, new PhoneNormalizer(), $identities, new OutboundPolicy($database));

        self::assertSame('wamid.first', $sender->sendText($asset, '5511999999999', 'Primeira', human: true)->messageId);
        self::assertSame('wamid.second', $sender->sendText($asset, '5511999999999', 'Segunda', human: true)->messageId);
    }

    public function testGuardedInboxAiUsesCanonicalWaIdInsideServiceWindow(): void
    {
        $asset = $this->asset();
        $canonicalWaId = '553184326486';
        $inbound = (new MetaMessage())
            ->setAsset($asset)
            ->setChannel('whatsapp')
            ->setDirection('inbound')
            ->setRecipient($canonicalWaId);
        $messages = $this->createMock(ObjectRepository::class);
        $messages->expects(self::once())->method('findOneBy')->willReturn($inbound);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getRepository')->with(MetaMessage::class)->willReturn($messages);
        $identities = $this->createMock(IdentityManager::class);
        $identities->expects(self::once())->method('assertCanSend')->with($asset, $canonicalWaId, null, true);
        $database = $this->createMock(\Doctrine\DBAL\Connection::class);
        $database->expects(self::never())->method('fetchOne');
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::anything(),
            'phone-123/messages',
            self::callback(static fn (array $payload): bool => $canonicalWaId === $payload['to']),
        )->willReturn(['messages' => [['id' => 'wamid.ai']]]);
        $sender = new WhatsAppSender(new GraphTransport($graph), $entityManager, new PhoneNormalizer(), $identities, new OutboundPolicy($database));

        $result = $sender->sendText($asset, $canonicalWaId, 'Resposta da IA', alreadyAutomationGuarded: true);

        self::assertSame('wamid.ai', $result->messageId);
        self::assertSame($canonicalWaId, $result->recipient);
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
        )->willReturn(['messages' => [['id' => 'wamid.media']]]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $identities = $this->createMock(IdentityManager::class);
        $identities->expects(self::once())->method('assertCanSend');

        $result = (new WhatsAppSender(new GraphTransport($graph), $entityManager, new PhoneNormalizer(), $identities))->sendMedia(
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

        $result = (new WhatsAppSender(new GraphTransport($graph), $this->createMock(EntityManagerInterface::class), new PhoneNormalizer(), $this->createMock(IdentityManager::class)))->sendInteractive(
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

    public function testHumanTakeoverStopsAtSendBoundaryWithoutCallingMeta(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method('post');
        $integration = new class() implements InboxIntegrationInterface {
            public function ownsSupportInbox(): bool
            {
                return true;
            }

            public function messagePersisted(MetaMessage $message): void
            {
            }

            public function automationAllowed(MetaAsset $asset, string $recipient): bool
            {
                return false;
            }

            public function runAutomationGuarded(MetaAsset $asset, string $recipient, callable $operation): mixed
            {
                throw new \DomainException('Automation paused');
            }

            public function outboundJobChanged(MetaOutboundJob $job): void
            {
            }
        };
        $sender = new WhatsAppSender(new GraphTransport($graph), $this->createMock(EntityManagerInterface::class), new PhoneNormalizer(), $this->createMock(IdentityManager::class), inboxIntegration: $integration);

        $this->expectException(\DomainException::class);
        $sender->sendText($this->asset(), '5511999999999', 'Não enviar');
    }

    private function asset(): MetaAsset
    {
        return (new MetaAsset())
            ->setConnection((new MetaConnection())->setName('Primary')->setStatus('active')->setIsPublished(true))
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
            new GraphTransport($this->createMock(MetaGraphClientInterface::class)),
            $this->createMock(EntityManagerInterface::class),
            new PhoneNormalizer(),
            $this->createMock(IdentityManager::class),
        );
    }
}
