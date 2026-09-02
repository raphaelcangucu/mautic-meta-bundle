<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class ConnectionDiagnostic
{
    private const REQUIRED_PERMISSIONS = [
        'instagram_basic',
        'instagram_manage_messages',
        'instagram_manage_comments',
        'pages_show_list',
    ];

    public function __construct(
        private MetaGraphClientInterface $graph,
        private EntityManagerInterface $entityManager,
        private ?InstagramAccountResolver $instagramResolver = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function test(MetaConnection $connection): array
    {
        $started = microtime(true);

        try {
            $profile = $this->graph->get($connection, 'me', ['fields' => 'id,name']);
            $permissionResult = $this->permissions($connection);
            $assetResult = $this->assets($connection);
            $ok = [] === $permissionResult['missing'] && [] === $assetResult['missing'];

            $connection->setStatus($ok ? 'active' : 'error');
            $result = [
                'ok'            => $ok,
                'connectionId'  => $connection->getId(),
                'graphVersion'  => $connection->getGraphVersion(),
                'metaUser'      => [
                    'id'   => $profile['id'] ?? null,
                    'name' => $profile['name'] ?? null,
                ],
                'permissions'   => $permissionResult,
                'assets'        => $assetResult,
                'latencyMs'     => (int) round((microtime(true) - $started) * 1000),
            ];

            if (!$ok) {
                $result['error'] = 'System User is missing required permissions or assigned Meta assets.';
            }
        } catch (\Throwable $exception) {
            $connection->setStatus('error');
            $result = [
                'ok'           => false,
                'connectionId' => $connection->getId(),
                'graphVersion' => $connection->getGraphVersion(),
                'error'        => $exception->getMessage(),
                'latencyMs'    => (int) round((microtime(true) - $started) * 1000),
            ];
            if ($exception instanceof MetaGraphApiException) {
                $result['graphError'] = $exception->details();
            }
        }

        $settings = $connection->getSettings();
        $settings['last_diagnostic'] = $result + ['testedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        $connection->setSettings($settings);
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $result;
    }

    /**
     * @return array{required: list<string>, granted: list<string>, missing: list<string>}
     */
    private function permissions(MetaConnection $connection): array
    {
        $response = $this->graph->get($connection, 'me/permissions');
        $granted = [];

        foreach ((array) ($response['data'] ?? []) as $permission) {
            if (is_array($permission) && 'granted' === ($permission['status'] ?? null)) {
                $granted[] = (string) ($permission['permission'] ?? '');
            }
        }

        $granted = array_values(array_unique(array_filter($granted)));

        return [
            'required' => self::REQUIRED_PERMISSIONS,
            'granted'  => $granted,
            'missing'  => array_values(array_diff(self::REQUIRED_PERMISSIONS, $granted)),
        ];
    }

    /**
     * @return array{configuredCount: int, accessible: list<array<string, mixed>>, missing: list<array<string, mixed>>}
     */
    private function assets(MetaConnection $connection): array
    {
        $accessible = [];
        $missing = [];
        $configuredCount = 0;

        foreach ($connection->getAssets() as $asset) {
            if (AssetType::InstagramAccount !== $asset->getType()) {
                continue;
            }

            ++$configuredCount;

            try {
                $canonicalId = $this->instagramResolver?->resolve($asset) ?? $asset->getExternalId();
                $profile = $this->graph->get($connection, $canonicalId, ['fields' => 'id']);
                $accessible[] = [
                    'id'         => $asset->getId(),
                    'externalId' => $asset->getExternalId(),
                    'type'       => $asset->getType()->value,
                    'canonicalId' => $canonicalId,
                    'verifiedId' => $profile['id'] ?? null,
                ];
            } catch (\Throwable $exception) {
                $missing[] = [
                    'id'         => $asset->getId(),
                    'externalId' => $asset->getExternalId(),
                    'type'       => $asset->getType()->value,
                    'error'      => $exception instanceof MetaGraphApiException
                        ? $exception->details()
                        : ['message' => $exception->getMessage()],
                ];
            }
        }

        return [
            'configuredCount' => $configuredCount,
            'accessible'      => $accessible,
            'missing'         => $missing,
        ];
    }
}
