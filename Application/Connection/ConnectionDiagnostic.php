<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
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
        'whatsapp_business_management',
        'whatsapp_business_messaging',
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

        $required = [];
        foreach ($connection->getAssets() as $asset) {
            $required = array_merge($required, match ($asset->getType()) {
                AssetType::InstagramAccount, AssetType::FacebookPage => array_slice(self::REQUIRED_PERMISSIONS, 0, 4),
                AssetType::WhatsAppBusinessAccount, AssetType::WhatsAppPhoneNumber => array_slice(self::REQUIRED_PERMISSIONS, 4),
            });
        }
        $required = array_values(array_unique($required));

        return [
            'required' => $required,
            'granted'  => $granted,
            'missing'  => array_values(array_diff($required, $granted)),
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
            ++$configuredCount;

            try {
                $verification = match ($asset->getType()) {
                    AssetType::InstagramAccount => $this->verifyInstagram($connection, $asset),
                    AssetType::WhatsAppBusinessAccount => $this->verifyWaba($connection, $asset),
                    AssetType::WhatsAppPhoneNumber => $this->verifyPhoneNumber($connection, $asset),
                    AssetType::FacebookPage => $this->graph->get($connection, $asset->getExternalId(), ['fields' => 'id,name']),
                };
                $accessible[] = [
                    'id'         => $asset->getId(),
                    'externalId' => $asset->getExternalId(),
                    'type'       => $asset->getType()->value,
                    'verification' => $verification,
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

    private function verifyInstagram(MetaConnection $connection, MetaAsset $asset): array
    {
        $canonicalId = $this->instagramResolver?->resolve($asset) ?? $asset->getExternalId();
        $profile = $this->graph->get($connection, $canonicalId, ['fields' => 'id']);

        return ['canonicalId' => $canonicalId, 'verifiedId' => $profile['id'] ?? null];
    }

    private function verifyWaba(MetaConnection $connection, MetaAsset $asset): array
    {
        $profile = $this->graph->get($connection, $asset->getExternalId(), ['fields' => 'id,name']);
        $templates = $this->graph->get($connection, $asset->getExternalId().'/message_templates', ['name' => 'boas_vindas_reports', 'fields' => 'id,name,status,language', 'limit' => 20]);
        $welcome = array_values(array_filter((array) ($templates['data'] ?? []), static fn ($row): bool => is_array($row) && 'boas_vindas_reports' === ($row['name'] ?? null) && 'APPROVED' === ($row['status'] ?? null)));
        $subscribed = $this->graph->get($connection, $asset->getExternalId().'/subscribed_apps', ['fields' => 'id,name']);
        $apps = array_values(array_filter((array) ($subscribed['data'] ?? []), 'is_array'));
        $appSubscribed = [] !== array_filter($apps, static fn (array $row): bool => (string) ($row['id'] ?? '') === $connection->getAppId());
        if ([] === $welcome || !$appSubscribed) {
            throw new \DomainException([] === $welcome ? 'Approved boas_vindas_reports template was not found in this WABA.' : 'The Meta app is not listed in WABA subscribed_apps.');
        }

        $subscriptions = $this->graph->get($connection, $connection->getAppId().'/subscriptions', ['fields' => 'object,callback_url,active_fields']);
        $whatsAppWebhook = array_values(array_filter((array) ($subscriptions['data'] ?? []), static fn ($row): bool => is_array($row) && 'whatsapp_business_account' === ($row['object'] ?? null)));
        if ([] === $whatsAppWebhook) {
            throw new \DomainException('The app has no whatsapp_business_account webhook subscription.');
        }

        return ['verifiedId' => $profile['id'] ?? null, 'name' => $profile['name'] ?? null, 'welcomeTemplate' => $welcome[0], 'subscribedApp' => true, 'webhookSubscription' => $whatsAppWebhook[0]];
    }

    private function verifyPhoneNumber(MetaConnection $connection, MetaAsset $asset): array
    {
        $profile = $this->graph->get($connection, $asset->getExternalId(), ['fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type']);
        if ('' === trim((string) ($profile['display_phone_number'] ?? ''))) {
            throw new \DomainException('WhatsApp phone-number asset did not return display_phone_number.');
        }

        return [
            'verifiedId' => $profile['id'] ?? null,
            'configuredNumber' => $asset->getPhoneNumber(),
            'displayPhoneNumber' => $profile['display_phone_number'],
            'registrationStatus' => $profile['code_verification_status'] ?? null,
            'qualityRating' => $profile['quality_rating'] ?? null,
            'platformType' => $profile['platform_type'] ?? null,
        ];
    }
}
