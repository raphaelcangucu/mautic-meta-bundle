<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;

final class GraphTransport implements WhatsAppTransportInterface
{
    public function __construct(private MetaGraphClientInterface $graph)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function post(MetaAsset $asset, array $payload): array
    {
        // O caminho da aresta pertence ao transporte: o Graph enderecar por
        // externalId e detalhe da API oficial, nao do envio de WhatsApp.
        return $this->graph->post($asset->getConnection(), $asset->getExternalId().'/messages', $payload);
    }
}
