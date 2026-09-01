<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\DecisionEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticMetaBundle\Application\Automation\ContactTokenResolver;
use MauticPlugin\MauticMetaBundle\Application\Automation\MessageDecisionMatcher;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramService;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSender;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Form\Type\InstagramCampaignActionType;
use MauticPlugin\MauticMetaBundle\Form\Type\MetaMessageDecisionType;
use MauticPlugin\MauticMetaBundle\Form\Type\WhatsAppCampaignActionType;
use MauticPlugin\MauticMetaBundle\MetaEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CampaignSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MetaAssetRepository $assets,
        private WhatsAppSender $whatsApp,
        private InstagramService $instagram,
        private ContactTokenResolver $tokens,
        private MessageDecisionMatcher $decisionMatcher,
        private OutboundQueue $queue,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [CampaignEvents::CAMPAIGN_ON_BUILD => ['onBuild', 0], MetaEvents::CAMPAIGN_WHATSAPP_SEND => ['onWhatsAppSend', 0], MetaEvents::CAMPAIGN_INSTAGRAM_SEND => ['onInstagramSend', 0], MetaEvents::CAMPAIGN_MESSAGE_DECISION => ['onMessageDecision', 0]];
    }

    public function onBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction('meta.whatsapp.send', ['label' => 'Send Meta WhatsApp message', 'description' => 'Send text or an approved template from a selected WhatsApp number.', 'batchEventName' => MetaEvents::CAMPAIGN_WHATSAPP_SEND, 'formType' => WhatsAppCampaignActionType::class, 'channel' => 'whatsapp']);
        $event->addAction('meta.instagram.send', ['label' => 'Send Meta Instagram reply', 'description' => 'Send a private reply, public reply, or DM from a selected Instagram account.', 'batchEventName' => MetaEvents::CAMPAIGN_INSTAGRAM_SEND, 'formType' => InstagramCampaignActionType::class, 'channel' => 'instagram']);
        $event->addDecision(MetaEvents::CAMPAIGN_MESSAGE_TYPE, ['label' => 'Meta message received or updated', 'description' => 'Continue when a matching inbound message or delivery status is received.', 'eventName' => MetaEvents::CAMPAIGN_MESSAGE_DECISION, 'formType' => MetaMessageDecisionType::class]);
    }

    public function onMessageDecision(DecisionEvent $event): void
    {
        $message = $event->getPassthrough();
        if (!$message instanceof MetaMessage) { return; }
        if (!$this->decisionMatcher->matches($message, $event->getLog()->getEvent()->getProperties())) { return; }
        $event->setAsApplicable();
    }

    public function onWhatsAppSend(PendingEvent $event): void
    {
        if (!$event->checkContext('meta.whatsapp.send')) { return; }
        $properties = $event->getEvent()->getProperties();
        $event->setChannel('whatsapp');
        foreach ($event->getPending() as $log) {
            try {
                $asset = $this->asset((int) ($properties['asset_id'] ?? 0));
                $lead = $log->getLead();
                $fields = $lead->getProfileFields();
                $phone = (string) ($fields[(string) ($properties['phone_field'] ?? 'mobile')] ?? '');
                if ('template' === ($properties['mode'] ?? null)) {
                    $parameters = array_map(static fn (string $value): array => ['type' => 'text', 'text' => $value], $this->tokens->lines((string) ($properties['body_parameters'] ?? ''), $fields));
                    $components = [] === $parameters ? [] : [['type' => 'body', 'parameters' => $parameters]];
                    if ($properties['queue'] ?? true) {
                        $job = $this->queue->enqueue($asset, 'whatsapp_template', ['recipient' => $phone, 'name' => (string) ($properties['template_name'] ?? ''), 'language' => (string) ($properties['language'] ?? 'pt_BR'), 'components' => $components], $lead, (int) ($properties['max_attempts'] ?? 5));
                        $log->appendToMetadata(['meta_job_id' => $job->getId(), 'meta_asset_id' => $asset->getId(), 'queued' => true]);
                        $event->pass($log);
                        continue;
                    }
                    $result = $this->whatsApp->sendTemplate($asset, $phone, (string) ($properties['template_name'] ?? ''), (string) ($properties['language'] ?? 'pt_BR'), $components, $lead);
                } else {
                    $text = $this->tokens->resolve((string) ($properties['message'] ?? ''), $fields);
                    if ($properties['queue'] ?? true) {
                        $job = $this->queue->enqueue($asset, 'whatsapp_text', ['recipient' => $phone, 'text' => $text], $lead, (int) ($properties['max_attempts'] ?? 5));
                        $log->appendToMetadata(['meta_job_id' => $job->getId(), 'meta_asset_id' => $asset->getId(), 'queued' => true]);
                        $event->pass($log);
                        continue;
                    }
                    $result = $this->whatsApp->sendText($asset, $phone, $text, false, $lead);
                }
                $log->appendToMetadata(['meta_message_id' => $result->messageId, 'meta_log_id' => $result->logId, 'meta_asset_id' => $asset->getId()]);
                $event->pass($log);
            } catch (\Throwable $exception) {
                $this->fail($event, $log, $exception);
            }
        }
    }

    public function onInstagramSend(PendingEvent $event): void
    {
        if (!$event->checkContext('meta.instagram.send')) { return; }
        $properties = $event->getEvent()->getProperties();
        $event->setChannel('instagram');
        foreach ($event->getPending() as $log) {
            try {
                $asset = $this->asset((int) ($properties['asset_id'] ?? 0));
                $lead = $log->getLead();
                $fields = $lead->getProfileFields();
                $recipient = (string) ($fields[(string) ($properties['recipient_field'] ?? '')] ?? '');
                $message = $this->tokens->resolve((string) ($properties['message'] ?? ''), $fields);
                if ($properties['queue'] ?? true) {
                    $operation = match ($properties['action'] ?? '') { 'private_reply' => 'instagram_private_reply', 'public_reply' => 'instagram_public_reply', 'direct_message' => 'instagram_direct_message', default => throw new \InvalidArgumentException('Unsupported Instagram campaign action.') };
                    $job = $this->queue->enqueue($asset, $operation, ['recipient' => $recipient, 'text' => $message], $lead, (int) ($properties['max_attempts'] ?? 5));
                    $log->appendToMetadata(['meta_job_id' => $job->getId(), 'meta_asset_id' => $asset->getId(), 'queued' => true]);
                    $event->pass($log);
                    continue;
                }
                $sent = match ($properties['action'] ?? '') {
                    'private_reply' => $this->instagram->privateReply($asset, $recipient, $message, $lead),
                    'public_reply' => $this->instagram->publicReply($asset, $recipient, $message, $lead),
                    'direct_message' => $this->instagram->directMessage($asset, $recipient, $message, $lead),
                    default => throw new \InvalidArgumentException('Unsupported Instagram campaign action.'),
                };
                $log->appendToMetadata(['meta_message_id' => $sent->getExternalId(), 'meta_log_id' => $sent->getId(), 'meta_asset_id' => $asset->getId()]);
                $event->pass($log);
            } catch (\Throwable $exception) {
                $this->fail($event, $log, $exception);
            }
        }
    }

    private function asset(int $id): MetaAsset
    {
        $asset = $this->assets->find($id);
        if (!$asset instanceof MetaAsset) { throw new \InvalidArgumentException('Configured Meta asset was not found.'); }
        return $asset;
    }

    private function fail(PendingEvent $event, mixed $log, \Throwable $exception): void
    {
        $this->logger->error('Meta campaign action failed.', ['error' => $exception->getMessage()]);
        $event->fail($log, $exception->getMessage());
    }
}
