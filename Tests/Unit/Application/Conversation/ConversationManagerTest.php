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
}
