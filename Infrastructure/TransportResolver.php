<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;

final class TransportResolver
{
    public const TAG = 'mautic_meta.whatsapp_transport';

    /** @var array<string, WhatsAppTransportInterface> */
    private array $transports = [];

    /**
     * Os transportes chegam etiquetados no container e indexados pelo valor do
     * AssetType: um canal novo se registra pela tag, sem editar esta classe.
     *
     * @param iterable<string, WhatsAppTransportInterface> $transports
     */
    public function __construct(iterable $transports)
    {
        foreach ($transports as $assetType => $transport) {
            $this->transports[(string) $assetType] = $transport;
        }
    }

    public function forAsset(MetaAsset $asset): WhatsAppTransportInterface
    {
        // Devolver null aqui adiaria a falha por tres camadas e chegaria como
        // "call on null"; nomear o tipo aponta direto para a tag que faltou.
        return $this->transports[$asset->getType()->value]
            ?? throw new \RuntimeException(sprintf('No WhatsApp transport is registered for asset type "%s".', $asset->getType()->value));
    }
}
