<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Webhook;

use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEvent;

final class WebhookReplay
{
    public function __construct(
        private WhatsAppWebhookProcessor $whatsApp,
        private InstagramWebhookProcessor $instagram,
        private WebhookIngestor $ingestor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function replay(MetaWebhookEvent $event): array
    {
        if ('failed' !== $event->getStatus()) {
            throw new \DomainException('Only failed webhook events can be replayed.');
        }
        try {
            $result = match ($event->getObjectType()) {
                'whatsapp_business_account' => $this->whatsApp->process($event->getPayload()),
                'instagram' => $this->instagram->process($event->getPayload()),
                default => ['ignored' => true],
            };
            $this->ingestor->complete((int) $event->getId());

            return $result;
        } catch (\Throwable $exception) {
            $this->ingestor->complete((int) $event->getId(), $exception);
            throw $exception;
        }
    }
}
