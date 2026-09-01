<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

final readonly class ConnectionCredentials
{
    public function __construct(
        public string $appId,
        public string $appSecret,
        public string $accessToken,
        public string $verifyToken,
        public string $graphVersion,
    ) {}
}
