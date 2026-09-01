<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Automation\CampaignMessageDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Contact\ContactMatcher;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
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
    ) {}

    public function process(array $payload): array
    {
        $events = $this->parser->parse($payload);
        $created = 0;
        $ignored = 0;
        $createdMessages = [];
        foreach (['comments' => 'comment', 'messages' => 'direct_message', 'postbacks' => 'postback'] as $group => $type) {
            foreach ($events[$group] as $item) {
                $asset = $this->account((string) $item['accountId']);
                $externalId = (string) ($item['commentId'] ?? $item['messageId'] ?? hash('sha256', json_encode($item, JSON_THROW_ON_ERROR)));
                if (!$asset instanceof MetaAsset || $this->messages->findOneBy(['externalId' => $externalId]) instanceof MetaMessage) {
                    ++$ignored;
                    continue;
                }
                $recipient = (string) ($item['commenterId'] ?? $item['senderId'] ?? '');
                $username = isset($item['commenterName']) ? (string) $item['commenterName'] : null;
                $identity = $this->identities->registerInteraction($asset, $recipient, $username, $this->contactMatcher->match($asset, $recipient));
                $log = (new MetaMessage())->setAsset($asset)->setContact($identity->getContact())->setExternalId($externalId)->setChannel('instagram')->setDirection('inbound')->setMessageType($type)->setRecipient($recipient)->setPayload($item)->setStatus('received');
                $this->entityManager->persist($log);
                $createdMessages[] = $log;
                ++$created;
            }
        }
        if ($created > 0) {
            $this->entityManager->flush();
        }
        foreach ($createdMessages as $message) { $this->campaigns->dispatch($message); }

        return compact('created', 'ignored');
    }

    private function account(string $externalId): ?MetaAsset
    {
        $asset = $this->assets->findOneBy(['externalId' => $externalId, 'type' => AssetType::InstagramAccount->value]);

        return $asset instanceof MetaAsset ? $asset : null;
    }
}
