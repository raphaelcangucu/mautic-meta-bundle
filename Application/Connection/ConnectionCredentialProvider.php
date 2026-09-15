<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class ConnectionCredentialProvider
{
    public function __construct(
        private CredentialVault $vault,
        private ?MetaConnectionRepository $connections = null,
    ) {
    }

    public function for(MetaConnection $connection): ConnectionCredentials
    {
        $app = $connection;
        if ($connection->isCustomer()) {
            $app = $this->connections?->find((int) ($connection->getSettings()['provider_connection_id'] ?? 0));
            if (!$app instanceof MetaConnection || $app->isCustomer() || $app->getAppId() !== $connection->getAppId() || !$app->isPublished() || 'paused' === $app->getStatus()) {
                throw new \DomainException('Tech Provider configuration unavailable.');
            }
        }

        return new ConnectionCredentials(
            $connection->getAppId(),
            $this->vault->open($app->getEncryptedAppSecret()),
            $this->vault->open($connection->getEncryptedAccessToken()),
            $this->vault->open($app->getEncryptedVerifyToken()),
            $connection->getGraphVersion(),
        );
    }
}
