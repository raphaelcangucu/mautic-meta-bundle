<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Adapter\WebhookAdapterDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Automation\CampaignMessageDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Contact\ContactMatcher;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\ConsentKeywordMatcher;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessageRepository;

final class WhatsAppWebhookProcessor
{
    public function __construct(
        private WhatsAppWebhookParser $parser,
        private MetaAssetRepository $assets,
        private MetaMessageRepository $messages,
        private EntityManagerInterface $entityManager,
        private IdentityManager $identities,
        private ContactMatcher $contactMatcher,
        private ConsentKeywordMatcher $keywords,
        private CampaignMessageDispatcher $campaigns,
        private WebhookAdapterDispatcher $adapters,
        private ConversationManager $conversations,
    ) {
    }

    public function process(array $payload): array
    {
        $events = $this->parser->parse($payload);
        $received = 0;
        $updated = 0;
        $ignored = 0;
        $campaignMessages = [];
        foreach ($events['messages'] as $item) {
            $asset = $this->phoneAsset($item['phoneNumberId']);
            $message = $item['message'];
            $externalId = (string) ($message['id'] ?? '');
            if (!$asset instanceof MetaAsset || '' === $externalId || $this->messages->findOneBy(['externalId' => $externalId]) instanceof MetaMessage) {
                ++$ignored;
                continue;
            }
            $type = (string) ($message['type'] ?? 'unknown');
            $sender = (string) ($message['from'] ?? '');
            $profileName = is_array($item['contact'] ?? null) ? (string) ($item['contact']['profile']['name'] ?? '') : '';
            $identity = $this->identities->registerInteraction($asset, $sender, $profileName ?: null, $this->contactMatcher->match($asset, $sender));
            if ('text' === $type) {
                $keyword = $this->keywords->match((string) ($message['text']['body'] ?? ''));
                if ('opt_in' === $keyword) {
                    $this->identities->optIn($identity, 'whatsapp_keyword');
                }
                if ('opt_out' === $keyword) {
                    $this->identities->optOut($identity, 'whatsapp_keyword');
                }
            }
            $log = (new MetaMessage())
                ->setAsset($asset)
                ->setExternalId($externalId)
                ->setChannel('whatsapp')
                ->setDirection('inbound')
                ->setMessageType($type)
                ->setContact($identity->getContact())
                ->setRecipient($sender)
                ->setPayload($item)
                ->setStatus('received');
            $this->entityManager->persist($log);
            $campaignMessages[] = $log;
            ++$received;
        }
        foreach ($events['statuses'] as $item) {
            $status = $item['status'];
            $externalId = (string) ($status['id'] ?? '');
            $log = '' === $externalId ? null : $this->messages->findOneBy(['externalId' => $externalId]);
            if (!$log instanceof MetaMessage) {
                ++$ignored;
                continue;
            }
            $log->setStatus((string) ($status['status'] ?? 'unknown'))->setResponse($status);
            if (!empty($status['errors'])) {
                $log->setError((string) ($status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'Meta delivery error.'));
            }
            $campaignMessages[] = $log;
            ++$updated;
        }
        if ($received + $updated > 0) {
            $this->entityManager->flush();
        }
        foreach ($campaignMessages as $message) {
            $this->conversations->record($message);
            $this->campaigns->dispatch($message);
            $this->adapters->dispatch($message, 'inbound' === $message->getDirection() ? 'message.received' : match ($message->getStatus()) {
                'delivered' => 'message.delivered', 'read' => 'message.read', 'failed' => 'message.failed', default => 'message.sent'
            });
        }

        return compact('received', 'updated', 'ignored');
    }

    private function phoneAsset(string $externalId): ?MetaAsset
    {
        $asset = $this->assets->findOneBy(['externalId' => $externalId, 'type' => AssetType::WhatsAppPhoneNumber->value]);

        return $asset instanceof MetaAsset ? $asset : null;
    }
}
