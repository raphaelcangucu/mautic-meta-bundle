<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/**
 * Entrega um job da fila de um canal que nao fala com o Graph nem com o WhatsApp.
 *
 * Quem implementa entra pela tag {@see ChannelDispatchers::TAG}, indexada pelo asset_type, e
 * devolve a MetaMessage de saida ja gravada. A taxonomia de falha e a da fila:
 * DomainException e InvalidArgumentException falham de vez, ChannelTemporarilyUnavailable
 * tenta de novo, e qualquer outra excecao deixa o desfecho incerto.
 */
interface ChannelDispatcherInterface
{
    public function dispatch(MetaOutboundJob $job): MetaMessage;
}
