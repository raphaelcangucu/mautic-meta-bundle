<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Facebook;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class FacebookParticipantProfile
{
    public function __construct(
        private MetaGraphClientInterface $graph,
        private PageConnectionResolver $pages,
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
    ) {}

    /** @return array<string,string> */
    public function resolve(MetaAsset $asset, string $recipient): array
    {
        if (!ctype_digit($recipient)) { return []; }
        return $this->cache->get('meta_fb_profile_'.hash('sha256', $asset->getConnection()->getId().':'.$asset->getId().':'.$recipient), function (ItemInterface $item) use ($asset, $recipient): array {
            $item->expiresAfter(3600);
            try {
                $page = $this->pages->resolve($asset);
                try {
                    $result = $this->graph->get($page, $recipient, ['fields' => 'first_name,last_name,profile_pic']);
                    $profile = array_filter(['name' => trim(($result['first_name'] ?? '').' '.($result['last_name'] ?? '')), 'profile_pic' => $result['profile_pic'] ?? null], static fn ($value): bool => is_string($value) && '' !== $value);
                    if (!empty($profile['name'])) { return $profile; }
                } catch (\Throwable) {
                    // Some Pages can read conversation participants before profile-photo access is granted.
                }
                $threads = $this->graph->get($page, $asset->getExternalId().'/conversations', ['user_id' => $recipient, 'fields' => 'participants', 'limit' => 25]);
                foreach ($threads['data'] ?? [] as $thread) {
                    foreach ($thread['participants']['data'] ?? [] as $participant) {
                        if (($participant['id'] ?? '') === $recipient && is_string($participant['name'] ?? null) && '' !== $participant['name']) {
                            return ['name' => $participant['name']];
                        }
                    }
                }
            } catch (\Throwable) {
                // Profile lookup must not block inbound delivery.
            }
            $item->expiresAfter(300);
            return [];
        });
    }
}
