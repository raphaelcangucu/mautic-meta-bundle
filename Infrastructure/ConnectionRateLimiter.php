<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\Policy\TokenBucketLimiter;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class ConnectionRateLimiter
{
    private CacheStorage $storage;

    public function __construct(CacheItemPoolInterface $cache) { $this->storage = new CacheStorage($cache); }

    public function reserve(MetaConnection $connection): void
    {
        $rate = max(1, min(100, (int) ($connection->getSettings()['requests_per_second'] ?? 10)));
        $key = (string) ($connection->getId() ?? $connection->getAppId());
        $reservation = (new TokenBucketLimiter('mautic_meta_graph_'.$key, $rate * 2, new Rate(new \DateInterval('PT1S'), $rate), $this->storage))->reserve();
        if ($reservation->getWaitDuration() > 2.0) { throw new \RuntimeException('Meta Graph API local rate limit exceeded; retry later.'); }
        $reservation->wait();
    }
}
