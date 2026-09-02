<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Conversation;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversationRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class ConversationManager
{
    public function __construct(
        private MetaConversationRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function record(MetaMessage $message): MetaConversation
    {
        $conversation = $this->repository->findOneBy([
            'asset'     => $message->getAsset(),
            'channel'   => $message->getChannel(),
            'recipient' => $message->getRecipient(),
        ]);
        if (!$conversation instanceof MetaConversation) {
            $conversation = (new MetaConversation())
                ->setAsset($message->getAsset())
                ->setChannel($message->getChannel())
                ->setRecipient($message->getRecipient());
            $this->entityManager->persist($conversation);
        }

        if (null !== $message->getContact()) {
            $conversation->setContact($message->getContact());
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
        $this->entityManager->flush();

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
