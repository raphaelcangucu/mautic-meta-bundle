<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Conversation;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversationRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use PHPUnit\Framework\TestCase;

final class ConversationManagerTest extends TestCase
{
    public function testReusesBrazilianConversationCreatedWithLegacyWaId(): void
    {
        $asset = (new MetaAsset())
            ->setConnection((new MetaConnection())->setName('Primary'))
            ->setExternalId('phone-123')
            ->setName('Support')
            ->setType(AssetType::WhatsAppPhoneNumber)
            ->setStatus('active')
            ->setIsPublished(true)
            ->setSettings(['default_region' => 'BR']);
        $contact = new Lead();
        $legacy = (new MetaConversation())
            ->setAsset($asset)
            ->setContact($contact)
            ->setChannel('whatsapp')
            ->setRecipient('553184326486');
        $message = (new MetaMessage())
            ->setAsset($asset)
            ->setContact($contact)
            ->setChannel('whatsapp')
            ->setDirection('inbound')
            ->setMessageType('text')
            ->setRecipient('5531984326486')
            ->setPayload(['text' => ['body' => 'Oi']]);

        $repository = $this->createMock(MetaConversationRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?MetaConversation => '553184326486' === $criteria['recipient'] ? $legacy : null,
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $recorded = (new ConversationManager($repository, $entityManager, new PhoneNormalizer()))->record($message);

        self::assertSame($legacy, $recorded);
        self::assertSame($legacy, $message->getConversation());
        self::assertSame('5531984326486', $recorded->getRecipient());
    }

    public function testAPhoneRecipientIsStillCanonicalized(): void
    {
        // Os tres canais oficiais dependem desta linha: o numero chega formatado como o
        // Meta entrega e a conversa guarda a forma canonica, senao o mesmo cliente abre
        // uma conversa por formato de numero.
        $asset = $this->asset(AssetType::WhatsAppPhoneNumber);
        $message = (new MetaMessage())
            ->setAsset($asset)
            ->setChannel('whatsapp')
            ->setDirection('inbound')
            ->setMessageType('text')
            ->setRecipient('+55 (31) 98432-6486')
            ->setPayload(['text' => ['body' => 'Oi']]);

        $repository = $this->createMock(MetaConversationRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $recorded = (new ConversationManager($repository, $entityManager, new PhoneNormalizer()))->record($message);

        self::assertSame('5531984326486', $recorded->getRecipient());
    }

    public function testAMarkedRecipientCrossesRecordUntouched(): void
    {
        // O WhatsApp por QR as vezes so entrega um identificador opaco, e quem o recebe o
        // marca com prefixo justamente para que ninguem o confunda com telefone. Canonizar
        // aqui deixaria os digitos que sobram passando por numero -- um destinatario
        // inventado, que aceita resposta e nunca entrega. Nem a busca por equivalentes pode
        // acontecer: ela procuraria a conversa de um telefone que nao e deste remetente.
        $asset = $this->asset(AssetType::WhatsAppQrSession);
        $message = (new MetaMessage())
            ->setAsset($asset)
            ->setChannel('whatsapp')
            ->setDirection('inbound')
            ->setMessageType('text')
            ->setRecipient('jid:220518514233310@lid')
            ->setPayload(['text' => ['body' => 'Oi']]);

        $repository = $this->createMock(MetaConversationRepository::class);
        $repository->expects(self::once())->method('findOneBy')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $recorded = (new ConversationManager($repository, $entityManager, new PhoneNormalizer()))->record($message);

        self::assertSame('jid:220518514233310@lid', $recorded->getRecipient());
    }

    private function asset(AssetType $type): MetaAsset
    {
        return (new MetaAsset())
            ->setConnection((new MetaConnection())->setName('Primary'))
            ->setExternalId('phone-123')
            ->setName('Support')
            ->setType($type)
            ->setStatus('active')
            ->setIsPublished(true)
            ->setSettings(['default_region' => 'BR']);
    }
}
