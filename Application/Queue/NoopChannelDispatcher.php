<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/**
 * Sem o plugin do canal instalado, o job falha de vez. DomainException, e nao RuntimeException:
 * a fila le RuntimeException como desfecho incerto, e incerto nao se tenta de novo.
 */
final class NoopChannelDispatcher implements ChannelDispatcherInterface
{
    public function dispatch(MetaOutboundJob $job): MetaMessage
    {
        throw new \DomainException(sprintf('No channel dispatcher is installed for asset type "%s".', $job->getAsset()->getType()->value));
    }
}
