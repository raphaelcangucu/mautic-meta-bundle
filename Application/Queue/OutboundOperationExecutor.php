<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramService;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSender;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSendResult;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

class OutboundOperationExecutor
{
    public function __construct(
        private WhatsAppSender $whatsApp,
        private InstagramService $instagram,
        private ?\MauticPlugin\MauticMetaBundle\Application\Facebook\FacebookService $facebook = null,
        private ?\MauticPlugin\MauticMetaBundle\Application\Support\InboxIntegrationInterface $inboxIntegration = null,
    ) {}

    public function execute(MetaOutboundJob $job): MetaMessage|WhatsAppSendResult
    {
        if (($job->getPayload()['_origin']??'')==='inbox_ai') {
            if (!$this->inboxIntegration || !method_exists($this->inboxIntegration,'runAiGuarded')) throw new \DomainException('Automation paused: AI guard unavailable.');
            return $this->inboxIntegration->runAiGuarded($job,fn()=>$this->executeOperation($job));
        }
        return $this->executeOperation($job);
    }
    private function executeOperation(MetaOutboundJob $job): MetaMessage|WhatsAppSendResult
    {
        $payload = $job->getPayload();
        $recipient = (string) ($payload['recipient'] ?? '');
        $origin = $payload['_origin'] ?? null;
        $human = 'inbox_human' === $origin;
        $alreadyGuarded = $human || 'inbox_ai' === $origin;
        $contact = $job->getContact();
        $result = match ($job->getOperation()) {
            'whatsapp_text' => $this->whatsApp->sendText($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), (bool) ($payload['preview_url'] ?? false), $contact, $human, 'inbox_ai' === $origin),
            'whatsapp_template' => $this->whatsApp->sendTemplate($job->getAsset(), $recipient, (string) ($payload['name'] ?? ''), (string) ($payload['language'] ?? 'pt_BR'), is_array($payload['components'] ?? null) ? $payload['components'] : [], $contact, $alreadyGuarded, isset($payload['_template_id']) ? (int) $payload['_template_id'] : null),
            'whatsapp_media' => $this->whatsApp->sendMedia($job->getAsset(), $recipient, (string) ($payload['media_type'] ?? ''), is_array($payload['media'] ?? null) ? $payload['media'] : [], $contact, $alreadyGuarded),
            'whatsapp_interactive' => $this->whatsApp->sendInteractive($job->getAsset(), $recipient, is_array($payload['interactive'] ?? null) ? $payload['interactive'] : [], $contact, $alreadyGuarded),
            'instagram_private_reply' => $this->instagram->privateReply($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), $contact, $alreadyGuarded),
            'instagram_public_reply' => $this->instagram->publicReply($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), $contact, $alreadyGuarded),
            'instagram_direct_message' => $this->instagram->directMessage($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), $contact, $alreadyGuarded),
            'facebook_public_reply', 'facebook_direct_message' => ($this->facebook ?? throw new \LogicException('Facebook service unavailable.'))->send($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), 'facebook_public_reply' === $job->getOperation(), $contact, $alreadyGuarded),
            default => throw new \InvalidArgumentException('Unsupported Meta queue operation.'),
        };

        return $result;
    }
}
