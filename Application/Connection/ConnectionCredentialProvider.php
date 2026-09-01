<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class ConnectionCredentialProvider
{
    public function __construct(
        private CredentialVault $vault
    ) {}

    public function for(MetaConnection $connection): ConnectionCredentials
    {
        return new ConnectionCredentials(
            $connection->getAppId(),
            $this->vault->open($connection->getEncryptedAppSecret()),
            $this->vault->open($connection->getEncryptedAccessToken()),
            $this->vault->open($connection->getEncryptedVerifyToken()),
            $connection->getGraphVersion(),
        );
    }
}
