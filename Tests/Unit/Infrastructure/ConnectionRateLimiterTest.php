<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Infrastructure;

use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\ConnectionRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ConnectionRateLimiterTest extends TestCase
{
    public function testReservesWithinConfiguredConnectionRate(): void
    {
        $connection = (new MetaConnection(3))->setSettings(['requests_per_second' => 100]);
        $limiter = new ConnectionRateLimiter(new ArrayAdapter());

        $limiter->reserve($connection);

        self::assertTrue(true);
    }
}
