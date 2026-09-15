<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use Symfony\Component\HttpFoundation\Response;

final class InstagramMediaResolver
{
    private const MAX_PAGES = 10;
    private const PAGE_SIZE = 100;

    public function __construct(
        private MetaGraphClientInterface $graph,
        private InstagramAccountResolver $accounts,
    ) {
    }

    /**
     * @return array{account:string,media_id:string,permalink:string,media_type:string,timestamp:string}
     */
    public function resolve(MetaAsset $asset, string $permalink): array
    {
        $target = InstagramPermalink::fromString($permalink);
        $this->assertAvailable($asset);

        try {
            $accountId = trim($this->accounts->resolve($asset));
        } catch (MetaGraphApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InstagramMediaResolveException('instagram_asset_unavailable', Response::HTTP_UNPROCESSABLE_ENTITY, false, $exception);
        }
        if ('' === $accountId) {
            throw new InstagramMediaResolveException('instagram_asset_unavailable', Response::HTTP_UNPROCESSABLE_ENTITY, false);
        }

        $after = null;
        $seenCursors = [];
        for ($pageNumber = 0; $pageNumber < self::MAX_PAGES; ++$pageNumber) {
            $query = [
                'fields' => 'id,permalink,media_type,timestamp,owner{id}',
                'limit'  => self::PAGE_SIZE,
            ];
            if (null !== $after) {
                $query['after'] = $after;
            }

            try {
                $page = $this->graph->get($asset->getConnection(), $accountId.'/media', $query);
            } catch (MetaGraphApiException $exception) {
                throw $exception;
            } catch (\DomainException $exception) {
                throw new InstagramMediaResolveException('instagram_asset_unavailable', Response::HTTP_UNPROCESSABLE_ENTITY, false, $exception);
            } catch (\RuntimeException $exception) {
                throw new InstagramMediaResolveException('meta_graph_unavailable', Response::HTTP_SERVICE_UNAVAILABLE, true, $exception);
            }

            foreach ((array) ($page['data'] ?? []) as $media) {
                if (!is_array($media)) {
                    continue;
                }
                $candidate = $this->candidatePermalink((string) ($media['permalink'] ?? ''));
                if (null === $candidate || ($candidate->normalized !== $target->normalized && $candidate->shortcode !== $target->shortcode)) {
                    continue;
                }

                $ownerId = trim((string) (($media['owner']['id'] ?? '')));
                if ('' !== $ownerId && $ownerId !== $accountId) {
                    throw new InstagramMediaResolveException('instagram_media_owner_mismatch', Response::HTTP_CONFLICT, false);
                }
                $mediaId = trim((string) ($media['id'] ?? ''));
                if ('' === $mediaId) {
                    continue;
                }

                // The account-scoped /media edge is itself authoritative when Meta
                // omits owner{id}; an explicit owner is checked above when present.
                return [
                    'account'    => ltrim((string) ($asset->getUsername() ?: $asset->getName()), '@'),
                    'media_id'   => $mediaId,
                    'permalink'  => $candidate->canonical,
                    'media_type' => (string) ($media['media_type'] ?? ''),
                    'timestamp'  => (string) ($media['timestamp'] ?? ''),
                ];
            }

            $after = $this->nextCursor($page);
            if (null === $after || isset($seenCursors[$after])) {
                break;
            }
            $seenCursors[$after] = true;
        }

        throw new InstagramMediaResolveException('instagram_media_not_found', Response::HTTP_NOT_FOUND, true);
    }

    private function assertAvailable(MetaAsset $asset): void
    {
        $connection = $asset->getConnection();
        if (
            AssetType::InstagramAccount !== $asset->getType()
            || !$asset->isPublished()
            || 'active' !== $asset->getStatus()
            || !$connection->isPublished()
            || 'active' !== $connection->getStatus()
        ) {
            throw new InstagramMediaResolveException('instagram_asset_unavailable', Response::HTTP_UNPROCESSABLE_ENTITY, false);
        }
    }

    private function candidatePermalink(string $permalink): ?InstagramPermalink
    {
        try {
            return InstagramPermalink::fromString($permalink);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $page */
    private function nextCursor(array $page): ?string
    {
        $cursor = trim((string) ($page['paging']['cursors']['after'] ?? ''));
        if ('' === $cursor && is_string($page['paging']['next'] ?? null)) {
            parse_str((string) parse_url($page['paging']['next'], PHP_URL_QUERY), $query);
            $cursor = trim((string) ($query['after'] ?? ''));
        }

        return '' !== $cursor && strlen($cursor) <= 512 ? $cursor : null;
    }
}
