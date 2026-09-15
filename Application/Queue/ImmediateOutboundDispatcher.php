<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/**
 * Synchronously dispatches human WhatsApp service replies after local state commits.
 */
final class ImmediateOutboundDispatcher
{
    public function __construct(private OutboundQueue $queue)
    {
    }

    public function supports(MetaOutboundJob $job): bool
    {
        return 'whatsapp_text' === $job->getOperation()
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
