<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/**
 * Escolhe o despachante pelo asset_type do job, como o TransportResolver faz com o WhatsApp.
 * Um canal novo entra pela tag, sem mudar este arquivo nem a fila.
 */
final class ChannelDispatchers
{
    public const TAG = 'mautic_meta.channel_dispatcher';

    /** @var array<string, ChannelDispatcherInterface> */
    private array $dispatchers = [];

    /**
     * @param iterable<string, ChannelDispatcherInterface> $dispatchers indexados pelo asset_type
     */
    public function __construct(iterable $dispatchers = [], private ChannelDispatcherInterface $fallback = new NoopChannelDispatcher())
    {
        foreach ($dispatchers as $assetType => $dispatcher) {
            $this->dispatchers[(string) $assetType] = $dispatcher;
        }
    }

    public function dispatch(MetaOutboundJob $job): MetaMessage
    {
        return ($this->dispatchers[$job->getAsset()->getType()->value] ?? $this->fallback)->dispatch($job);
    }
}
