<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Application\Facebook;

use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class FacebookOriginResolver
{
    private array $cache = [];
    public function __construct(private MetaGraphClientInterface $graph, private PageConnectionResolver $connections) {}
    public function enrich(MetaAsset $page, array $item): array
    {
        if ('comment' !== $item['type'] || empty($item['mediaId']) || false === ($page->getSettings()['facebook_read_enabled'] ?? true)) { return $item; }
        $key = $page->getId().':'.$item['mediaId'];
        if (!array_key_exists($key, $this->cache)) {
            try {
                $result = $this->graph->get($this->connections->resolve($page), $item['mediaId'], ['fields' => 'id,message,permalink_url,full_picture']);
                $this->cache[$key] = $result;
            } catch (\Throwable) { $this->cache[$key] = []; }
        }
        $result = $this->cache[$key];
        if (!empty($result['permalink_url'])) {
            $item['permalink'] = $result['permalink_url'];
            $item['origin_media'] = ['kind' => str_contains($result['permalink_url'], '/reel/') ? 'reel' : 'post', 'caption' => mb_substr((string) ($result['message'] ?? ''), 0, 300), 'image' => $result['full_picture'] ?? null];
        }
        return $item;
    }
}
