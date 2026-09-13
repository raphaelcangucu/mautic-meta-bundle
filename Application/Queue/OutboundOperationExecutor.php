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
    ) {}

    public function execute(MetaOutboundJob $job): MetaMessage|WhatsAppSendResult
    {
        $payload = $job->getPayload();
        $recipient = (string) ($payload['recipient'] ?? '');
        $human = 'inbox_human' === ($payload['_origin'] ?? null);
        $contact = $job->getContact();
        $result = match ($job->getOperation()) {
            'whatsapp_text' => $this->whatsApp->sendText($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), (bool) ($payload['preview_url'] ?? false), $contact, $human),
            'whatsapp_template' => $this->whatsApp->sendTemplate($job->getAsset(), $recipient, (string) ($payload['name'] ?? ''), (string) ($payload['language'] ?? 'pt_BR'), is_array($payload['components'] ?? null) ? $payload['components'] : [], $contact, $human),
            'whatsapp_media' => $this->whatsApp->sendMedia($job->getAsset(), $recipient, (string) ($payload['media_type'] ?? ''), is_array($payload['media'] ?? null) ? $payload['media'] : [], $contact, $human),
            'whatsapp_interactive' => $this->whatsApp->sendInteractive($job->getAsset(), $recipient, is_array($payload['interactive'] ?? null) ? $payload['interactive'] : [], $contact, $human),
            'instagram_private_reply' => $this->instagram->privateReply($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), $contact, $human),
            'instagram_public_reply' => $this->instagram->publicReply($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), $contact, $human),
            'instagram_direct_message' => $this->instagram->directMessage($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), $contact, $human),
            'facebook_public_reply', 'facebook_direct_message' => ($this->facebook ?? throw new \LogicException('Facebook service unavailable.'))->send($job->getAsset(), $recipient, (string) ($payload['text'] ?? ''), 'facebook_public_reply' === $job->getOperation(), $contact, $human),
            default => throw new \InvalidArgumentException('Unsupported Meta queue operation.'),
        };

        return $result;
    }
}
