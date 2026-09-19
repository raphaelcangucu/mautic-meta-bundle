<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Conversation;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversationRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Domain\UnresolvedRecipient;

final class ConversationManager
{
    public function __construct(
        private MetaConversationRepository $repository,
        private EntityManagerInterface $entityManager,
        private PhoneNormalizer $phones,
        private ?InboxIntegrationInterface $inboxIntegration = null,
    ) {
    }

    public function record(MetaMessage $message): MetaConversation
    {
        $conversationRecipient = in_array($message->getChannel(), ['instagram', 'facebook'], true) && 'comment' === $message->getMessageType()
            ? 'comment:'.(string) ($message->getPayload()['commentId'] ?? $message->getExternalId())
            : $message->getRecipient();
        if (in_array($message->getChannel(), ['instagram', 'facebook'], true) && in_array($message->getMessageType(), ['private_reply', 'comment_reply'], true)) { $conversationRecipient = 'comment:'.$message->getRecipient(); }
        $conversation = $this->repository->findOneBy([
            'asset'     => $message->getAsset(),
            'channel'   => $message->getChannel(),
            'recipient' => $conversationRecipient,
        ]);
        // Destinatario marcado nao e telefone: tudo o que segue trata destinatario de
        // whatsapp como numero, e um identificador opaco so sobreviveria a isso por acaso.
        $carriesPhone = !UnresolvedRecipient::marks($conversationRecipient);
        if (!$conversation instanceof MetaConversation && 'whatsapp' === $message->getChannel() && $carriesPhone) {
            $region = (string) ($message->getAsset()->getSettings()['default_region'] ?? 'BR');
            foreach ($this->phones->equivalentRecipients($conversationRecipient, $region) as $equivalentRecipient) {
                if ($equivalentRecipient === $conversationRecipient) {
                    continue;
                }
                $candidate = $this->repository->findOneBy([
                    'asset'     => $message->getAsset(),
                    'channel'   => $message->getChannel(),
                    'recipient' => $equivalentRecipient,
                ]);
                if (!$candidate instanceof MetaConversation) {
                    continue;
                }
                $candidateContact = $candidate->getContact();
                $messageContact = $message->getContact();
                if (
                    null !== $candidateContact
                    && null !== $messageContact
                    && $candidateContact !== $messageContact
                    && (null === $candidateContact->getId() || null === $messageContact->getId() || $candidateContact->getId() !== $messageContact->getId())
                ) {
                    continue;
                }
                $conversation = $candidate;
                break;
            }
        }
        if (!$conversation instanceof MetaConversation) {
            $conversation = (new MetaConversation())
                ->setAsset($message->getAsset())
                ->setChannel($message->getChannel())
                ->setRecipient($conversationRecipient);
            $this->entityManager->persist($conversation);
        }

        if (null !== $message->getContact()) {
            $conversation->setContact($message->getContact());
        }
        if ('whatsapp' === $message->getChannel() && $carriesPhone) {
            $region = (string) ($message->getAsset()->getSettings()['default_region'] ?? 'BR');
            $canonicalRecipient = $this->phones->equivalentRecipients($conversationRecipient, $region)[0] ?? $conversationRecipient;
            if ($conversation->getRecipient() !== $canonicalRecipient) {
                $conversation->setRecipient($canonicalRecipient);
            }
        }

        $now = new \DateTimeImmutable();
        $conversation->setLastMessageAt($now);
        if ('inbound' === $message->getDirection()) {
            $conversation
                ->setLastInboundAt($now)
                ->setUnreadCount($conversation->getUnreadCount() + 1)
                ->setStatus('open');
        }

        $message->setConversation($conversation);
        $this->entityManager->persist($conversation);
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        if ('outbound' === $message->getDirection()) { $this->inboxIntegration?->messagePersisted($message); }
        return $conversation;
    }

    public function markRead(MetaConversation $conversation): void
    {
        $conversation->setUnreadCount(0);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
    }

    public function setStatus(MetaConversation $conversation, string $status): void
    {
        if (!in_array($status, ['open', 'pending', 'resolved', 'archived'], true)) {
            throw new \InvalidArgumentException('Invalid conversation status.');
        }

        $conversation->setStatus($status);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();
    }
}
