<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class InstagramAccountResolver
{
    /** @var array<int, string> */
    private array $resolvedIds = [];

    public function __construct(private MetaGraphClientInterface $graph)
    {
    }

    public function resolve(MetaAsset $account): string
    {
        $assetId = (int) $account->getId();
        if (isset($this->resolvedIds[$assetId])) {
            return $this->resolvedIds[$assetId];
        }

        $connection = $account->getConnection();
        $username = ltrim(strtolower((string) $account->getUsername()), '@');

        foreach ($connection->getAssets() as $asset) {
            if (AssetType::FacebookPage !== $asset->getType()) {
                continue;
            }

            $canonicalId = $this->fromPage($account, $asset->getExternalId(), $username);
            if (null !== $canonicalId) {
                return $this->resolvedIds[$assetId] = $canonicalId;
            }
        }

        $pages = $this->graph->get($connection, 'me/accounts', [
            'fields' => 'id,instagram_business_account{id,username},connected_instagram_account{id,username}',
            'limit'  => 100,
        ]);
        foreach ((array) ($pages['data'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }

            $canonicalId = $this->fromRelationships($account, $page, $username);
            if (null !== $canonicalId) {
                return $this->resolvedIds[$assetId] = $canonicalId;
            }
        }

        // Business Manager Instagram assets expose their canonical Graph ID as
        // ig_user_id when queried with metadata=1.
        $metadata = $this->graph->get($connection, $account->getExternalId(), ['metadata' => 1]);
        $canonicalId = trim((string) ($metadata['ig_user_id'] ?? ''));
        if ('' !== $canonicalId) {
            return $this->resolvedIds[$assetId] = $canonicalId;
        }

        throw new \RuntimeException(sprintf(
            'Could not resolve a canonical Instagram Graph ID for asset %d (%s). Assign its linked Facebook Page to the System User.',
            $assetId,
            $account->getExternalId(),
        ));
    }

    private function fromPage(MetaAsset $account, string $pageId, string $username): ?string
    {
        try {
            $page = $this->graph->get($account->getConnection(), $pageId, [
                'fields' => 'instagram_business_account{id,username},connected_instagram_account{id,username}',
            ]);
        } catch (MetaGraphApiException) {
            return null;
        }

        return $this->fromRelationships($account, $page, $username);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function fromRelationships(MetaAsset $account, array $page, string $username): ?string
    {
        foreach (['instagram_business_account', 'connected_instagram_account'] as $relationship) {
            $instagram = $page[$relationship] ?? null;
            if (!is_array($instagram)) {
                continue;
            }

            $id = trim((string) ($instagram['id'] ?? ''));
            $candidateUsername = ltrim(strtolower((string) ($instagram['username'] ?? '')), '@');
            if (
                '' !== $id
                && ($id === $account->getExternalId() || ('' !== $username && $candidateUsername === $username))
            ) {
                return $id;
            }
        }

        return null;
    }
}
