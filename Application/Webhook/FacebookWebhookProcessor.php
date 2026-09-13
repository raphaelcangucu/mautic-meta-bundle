<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

final class FacebookWebhookProcessor
{
    public function __construct(private FacebookWebhookParser $parser, private EntityManagerInterface $entityManager,
        private IdentityManager $identities, private ConversationManager $conversations, private InboxIntegrationInterface $inbox, private \MauticPlugin\MauticMetaBundle\Application\Facebook\FacebookOriginResolver $origins, private \MauticPlugin\MauticMetaBundle\Application\Facebook\FacebookParticipantProfile $profiles) {}

    public function process(array $payload, MetaConnection $connection): array
    {
        $created = $updated = $ignored = 0;
        foreach ($this->parser->parse($payload) as $item) {
            $asset = $this->entityManager->getRepository(MetaAsset::class)->findOneBy(['connection' => $connection, 'externalId' => $item['accountId'], 'type' => AssetType::FacebookPage->value, 'isPublished' => true, 'status' => 'active']);
            if (!$asset instanceof MetaAsset) { ++$ignored; continue; }
            $id = (string) ($item['commentId'] ?? $item['messageId']);
            $db = $this->entityManager->getConnection(); $lock = 'meta_fb_'.hash('sha1', $asset->getId().':'.$id);
            if (1 !== (int) $db->fetchOne('SELECT GET_LOCK(:name, 5)', ['name' => $lock])) { throw new \RuntimeException('Facebook event is already being processed.'); }
            try {
                $message = $this->entityManager->getRepository(MetaMessage::class)->findOneBy(['asset' => $asset, 'channel' => 'facebook', 'externalId' => $id]);
                if ($message instanceof MetaMessage) {
                    if ('add' !== $item['verb']) {
                        $old = $message->getPayload();
                        if (($old['event_timestamp'] ?? 0) > $item['timestamp']) { ++$ignored; continue; }
                        $next = array_replace($old, $item);
                        $next['event_timestamp'] = $item['timestamp'];
                        if ('remove' === $item['verb']) { $next['text'] = 'Comentário removido no Facebook.'; $next['removed'] = true; }
                        $message->setPayload($next)->setDateModified(new \DateTimeImmutable());
                        $this->entityManager->persist($message);
                        // Signal SSE even though an edit does not create a new message.
                        if ($message->getConversation()) { $message->getConversation()->setLastMessageAt(new \DateTimeImmutable()); $this->entityManager->persist($message->getConversation()); }
                        $this->entityManager->flush(); ++$updated;
                    } else { ++$ignored; }
                    if (!$message->getConversation()) { $this->conversations->record($message); }
                    $this->inbox->messagePersisted($message);
                    continue;
                }
                if ('add' !== $item['verb']) { ++$ignored; continue; }
                $item = $this->origins->enrich($asset, $item);
                $recipient = (string) ($item['commenterId'] ?? $item['senderId'] ?? '');
                if ('comment' !== $item['type']) { $item['contact']['profile'] = $this->profiles->resolve($asset, $recipient); }
                $identity = '' !== $recipient ? $this->identities->registerInteraction($asset, $recipient, $item['commenterName'] ?? null) : null;
                $message = (new MetaMessage())->setAsset($asset)->setContact($identity?->getContact())->setExternalId($id)->setChannel('facebook')->setDirection('inbound')->setMessageType($item['type'])->setRecipient($recipient ?: 'unknown:'.$id)->setPayload($item)->setStatus('received');
                $message->setDateAdded(new \DateTimeImmutable('@'.min(time(), max(1, $item['timestamp']))));
                $this->entityManager->persist($message); $this->entityManager->flush();
                $conversation = $this->conversations->record($message);
                $conversation->setLastMessageAt(\DateTimeImmutable::createFromInterface($message->getDateAdded()));
                $this->entityManager->persist($conversation); $this->entityManager->flush();
                $this->inbox->messagePersisted($message); ++$created;
            } finally { $db->fetchOne('SELECT RELEASE_LOCK(:name)', ['name' => $lock]); }
        }
        return compact('created', 'updated', 'ignored');
    }
}
