<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class ConnectionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CredentialVault $vault,
    ) {}

    public function create(string $name, string $appId, string $appSecret, string $accessToken, string $verifyToken, string $graphVersion = 'v26.0'): MetaConnection
    {
        foreach (['name' => $name, 'appId' => $appId, 'appSecret' => $appSecret, 'accessToken' => $accessToken, 'verifyToken' => $verifyToken] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException($field.' cannot be empty.');
            }
        }
        if (1 !== preg_match('/^v\d+\.\d+$/', $graphVersion)) {
            throw new \InvalidArgumentException('Invalid Meta Graph API version.');
        }

        $connection = (new MetaConnection())
            ->setName($name)
            ->setAppId($appId)
            ->setEncryptedAppSecret($this->vault->seal($appSecret))
            ->setEncryptedAccessToken($this->vault->seal($accessToken))
            ->setEncryptedVerifyToken($this->vault->seal($verifyToken))
            ->setGraphVersion($graphVersion)
            ->setStatus('pending');
        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $connection;
    }

    public function update(MetaConnection $connection, array $data): MetaConnection
    {
        $name = trim((string) ($data['name'] ?? ''));
        $appId = trim((string) ($data['app_id'] ?? ''));
        $graphVersion = trim((string) ($data['graph_version'] ?? ''));
        if ('' === $name || '' === $appId) {
            throw new \InvalidArgumentException('Connection name and App ID cannot be empty.');
        }
        if (1 !== preg_match('/^v\d+\.\d+$/', $graphVersion)) {
            throw new \InvalidArgumentException('Invalid Meta Graph API version.');
        }

        $connection->setName($name)->setAppId($appId)->setGraphVersion($graphVersion)->setStatus('pending');
        foreach (['app_secret' => 'setEncryptedAppSecret', 'access_token' => 'setEncryptedAccessToken', 'verify_token' => 'setEncryptedVerifyToken'] as $field => $setter) {
            $value = trim((string) ($data[$field] ?? ''));
            if ('' !== $value) {
                $connection->{$setter}($this->vault->seal($value));
            }
        }
        $this->entityManager->flush();

        return $connection;
    }

    public function remove(MetaConnection $connection): void
    {
        $this->entityManager->remove($connection);
        $this->entityManager->flush();
    }
}
