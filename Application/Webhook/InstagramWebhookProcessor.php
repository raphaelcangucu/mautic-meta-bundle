<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Adapter\WebhookAdapterDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Automation\CampaignMessageDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Contact\ContactMatcher;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramCommentAutomation;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramCommentMatcher;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;

final class InstagramWebhookProcessor
{
    public function __construct(
        private InstagramWebhookParser $parser,
        private MetaAssetRepository $assets,
        private MetaMessageRepository $messages,
        private EntityManagerInterface $entityManager,
        private IdentityManager $identities,
        private ContactMatcher $contactMatcher,
        private CampaignMessageDispatcher $campaigns,
        private WebhookAdapterDispatcher $adapters,
        private ConversationManager $conversations,
        private InstagramAccountResolver $accountResolver,
        private InstagramCommentAutomation $commentAutomation,
    ) {
    }

    public function process(array $payload, MetaConnection $connection): array
    {
        $events = $this->parser->parse($payload);
        $created = 0;
        $ignored = 0;
        foreach (['comments' => 'comment', 'messages' => 'direct_message', 'postbacks' => 'postback'] as $group => $type) {
            foreach ($events[$group] as $item) {
                $asset = $this->account((string) $item['accountId'], $connection);
                $externalId = (string) ($item['commentId'] ?? $item['messageId'] ?? hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)));
                if (!$asset instanceof MetaAsset) {
                    ++$ignored;
                    continue;
                }
                $recipient = (string) ($item['commenterId'] ?? $item['senderId'] ?? '');
                if ('comment' === $type) {
                    $ownComment = InstagramCommentMatcher::isOwnComment($recipient, (string) $item['accountId'], $asset->getExternalId(), '');
                    if (!$ownComment) {
                        $ownComment = InstagramCommentMatcher::isOwnComment($recipient, (string) $item['accountId'], $asset->getExternalId(), $this->accountResolver->resolve($asset));
                    }
                    if ($ownComment) {
                        ++$ignored;
                        continue;
                    }
                }
                $lock = 'comment' === $type ? 'meta_ig_'.hash('sha1', $asset->getId().':'.$recipient) : null;
                if (null !== $lock && 1 !== (int) $this->entityManager->getConnection()->fetchOne('SELECT GET_LOCK(:lock_name, 5)', ['lock_name' => $lock])) {
                    throw new \RuntimeException('Instagram comment is already being processed.');
                }
                try {
                    $existing = $this->messages->findOneBy(['externalId' => $externalId]);
                    if ($existing instanceof MetaMessage) {
                        if ('comment' === $type && $existing->getAsset()->getId() === $asset->getId()) {
                            $identity = $this->identities->registerInteraction($asset, $recipient);
                            $this->entityManager->flush();
                            $this->commentAutomation->handle($existing, $identity);
                        }
                        ++$ignored;
                        continue;
                    }
                    $username = isset($item['commenterName']) ? (string) $item['commenterName'] : null;
                    $identity = $this->identities->registerInteraction($asset, $recipient, $username, $this->contactMatcher->match($asset, $recipient));
                    $log = (new MetaMessage())->setAsset($asset)->setContact($identity->getContact())->setExternalId($externalId)->setChannel('instagram')->setDirection('inbound')->setMessageType($type)->setRecipient($recipient)->setPayload($item)->setStatus('received');
                    $this->entityManager->persist($log);
                    $this->entityManager->flush();
                    $this->conversations->record($log);
                    if ('comment' === $type) {
                        $this->commentAutomation->handle($log, $identity);
                    }
                    $this->campaigns->dispatch($log);
                    $this->adapters->dispatch($log, 'message.received');
                    ++$created;
                } finally {
                    if (null !== $lock) {
                        $this->entityManager->getConnection()->fetchOne('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $lock]);
                    }
                }
            }
        }

        return compact('created', 'ignored');
    }

    private function account(string $externalId, MetaConnection $connection): ?MetaAsset
    {
        foreach ($this->assets->findEnabledByType(AssetType::InstagramAccount) as $asset) {
            if ($asset->getConnection()->getId() !== $connection->getId()) {
                continue;
            }
            if ($asset->getExternalId() === $externalId) {
                return $asset;
            }
            try {
                if ($this->accountResolver->resolve($asset) === $externalId) {
                    return $asset;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
