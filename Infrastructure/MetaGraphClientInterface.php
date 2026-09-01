<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Infrastructure;

use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;

interface MetaGraphClientInterface
{
    public function get(MetaConnection $connection, string $path, array $query = []): array;
    public function post(MetaConnection $connection, string $path, array $payload): array;
    public function delete(MetaConnection $connection, string $path, array $query = []): array;
}
