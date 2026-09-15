<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;

final class ProviderWebhookRouter
{
    public function __construct(private MetaAssetRepository $assets)
    {
    }

    /** @return array<int, array{connection: MetaConnection, payload: array}> */
    public function route(MetaConnection $provider, array $payload): array
    {
        $routed = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            $matches = [];
            foreach ($this->assets->findBy(['externalId' => (string) ($entry['id'] ?? ''), 'type' => AssetType::WhatsAppBusinessAccount->value]) as $asset) {
                $connection = $asset->getConnection();
                // Pausing outbound must not discard delivery receipts for messages already sent.
                if ($connection->getAppId() !== $provider->getAppId() || !$connection->isPublished()) {
                    continue;
                }
                if ($connection->getId() === $provider->getId() || (int) ($connection->getSettings()['provider_connection_id'] ?? 0) === $provider->getId()) {
                    $matches[$connection->getId()] = $connection;
                }
            }
            if (1 !== count($matches)) {
                continue;
            }
            $connection = reset($matches);
            $id = (int) $connection->getId();
            $routed[$id] ??= ['connection' => $connection, 'payload' => ['object' => 'whatsapp_business_account', 'entry' => []]];
            $routed[$id]['payload']['entry'][] = $entry;
        }

        return $routed;
    }
}
