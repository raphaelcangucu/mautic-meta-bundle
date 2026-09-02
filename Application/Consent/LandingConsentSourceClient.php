<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Consent;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LandingConsentSourceClient
{
    public function __construct(
        private HttpClientInterface $http,
        private CredentialVault $vault,
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, nextCheckpoint: int, hasMore: bool}
     */
    public function fetch(MetaAsset $asset, string $source, string $version, int $afterId, int $limit): array
    {
        $settings = $asset->getConnection()->getSettings();
        $url = trim((string) ($settings['consent_source_url'] ?? ''));
        $sealedSecret = (string) ($settings['consent_source_secret'] ?? '');
        if ('' === $url || '' === $sealedSecret) {
            throw new \RuntimeException('The landing consent evidence source is not configured on this Meta connection.');
        }

        $parameters = [
            'afterId' => max(0, $afterId),
            'limit' => min(500, max(1, $limit)),
            'source' => $source,
            'consentVersion' => $version,
        ];
        ksort($parameters);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp."\n".$query, $this->vault->open($sealedSecret));

        $response = $this->http->request('GET', rtrim($url, '?').'?' . $query, [
            'headers' => [
                'X-Mautic-Meta-Timestamp' => $timestamp,
                'X-Mautic-Meta-Signature' => $signature,
            ],
            'timeout' => 15,
        ]);
        $data = $response->toArray();

        return [
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
            'nextCheckpoint' => (int) ($data['nextCheckpoint'] ?? $afterId),
            'hasMore' => (bool) ($data['hasMore'] ?? false),
        ];
    }
}
