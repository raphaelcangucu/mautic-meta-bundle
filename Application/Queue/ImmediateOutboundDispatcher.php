<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/**
 * Synchronously dispatches human WhatsApp and email replies after local state commits.
 */
final class ImmediateOutboundDispatcher
{
    public function __construct(private OutboundQueue $queue)
    {
    }

    public function supports(MetaOutboundJob $job): bool
    {
        return in_array($job->getOperation(), ['whatsapp_text', 'email_message'], true)
            && 'inbox_human' === ($job->getPayload()['_origin'] ?? null);
    }

    public function dispatch(MetaOutboundJob $job): bool
    {
        if (!$this->supports($job)) {
            return false;
        }

        return $this->queue->dispatchNow($job);
    }
}
