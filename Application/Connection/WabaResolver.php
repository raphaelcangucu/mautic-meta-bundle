<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class WabaResolver
{
    public function __construct(private MetaAssetRepository $assets, private MetaGraphClientInterface $graph)
    {
    }

    public function forPhone(MetaAsset $phone): MetaAsset
    {
        $candidates = $this->assets->findBy(['connection' => $phone->getConnection(), 'type' => AssetType::WhatsAppBusinessAccount->value]);
        $expected = (string) ($phone->getSettings()['waba_id'] ?? '');
        foreach ($candidates as $waba) {
            if ('' !== $expected && $waba->getExternalId() !== $expected) {
                continue;
            }
            $after = null;
            for ($page = 0; $page < 100; ++$page) {
                $result = $this->graph->get($phone->getConnection(), $waba->getExternalId().'/phone_numbers', ['fields' => 'id', 'limit' => 100] + ($after ? ['after' => $after] : []));
                foreach ($result['data'] ?? [] as $row) {
                    if (($row['id'] ?? null) === $phone->getExternalId()) {
                        return $waba;
                    }
                }
                $next = $result['paging']['cursors']['after'] ?? null;
                if (empty($result['paging']['next']) || !$next || $next === $after) {
                    break;
                }
                $after = $next;
            }
        }
        throw new \DomainException('O número não foi encontrado no WABA desta conexão. Corrija o vínculo antes de enviar.');
    }
}
