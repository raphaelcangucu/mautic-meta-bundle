<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class InstagramParticipantProfile
{
    public function __construct(
        private MetaGraphClientInterface $graph,
        private InstagramPageConnectionResolver $pages,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {}

    /** @return array<string,string> */
    public function resolve(MetaAsset $asset, string $recipient): array
    {
        if (!ctype_digit($recipient)) {
            return [];
        }
        $key = 'meta_ig_profile_'.hash('sha256', $asset->getConnection()->getId().':'.$asset->getId().':'.$recipient);
        return $this->cache->get($key, function (ItemInterface $item) use ($asset, $recipient): array {
            $item->expiresAfter(3600);
            try {
                $profile = $this->graph->get($this->pages->resolve($asset), $recipient, ['fields' => 'name,username,profile_pic']);
                return array_filter(array_intersect_key($profile, array_flip(['name', 'username', 'profile_pic'])), static fn ($value): bool => is_string($value) && '' !== $value);
            } catch (\Throwable) {
                // Profile access is optional; it must never prevent an inbound message being recorded.
                $item->expiresAfter(300);
                return [];
            }
        });
    }
}
