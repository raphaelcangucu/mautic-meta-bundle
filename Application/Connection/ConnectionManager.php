<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnectionRepository;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class ConnectionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CredentialVault $vault,
        private MetaConnectionRepository $repository,
    ) {
    }

    public function create(
        string $name,
        string $appId,
        string $appSecret,
        string $accessToken,
        string $verifyToken,
        string $graphVersion = 'v26.0',
        string $webhookAdaptersJson = '',
    ): MetaConnection {
        foreach (['name' => $name, 'appId' => $appId, 'appSecret' => $appSecret, 'accessToken' => $accessToken, 'verifyToken' => $verifyToken] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException($field.' cannot be empty.');
            }
        }
        if (1 !== preg_match('/^v\d+\.\d+$/', $graphVersion)) {
            throw new \InvalidArgumentException('Invalid Meta Graph API version.');
        }
        if ($this->repository->findOneBy(['appId' => trim($appId)]) instanceof MetaConnection) {
            throw new \InvalidArgumentException('A Meta connection with this App ID already exists. Edit the existing connection instead.');
        }

        $connection = (new MetaConnection())
            ->setName($name)
            ->setAppId($appId)
            ->setEncryptedAppSecret($this->vault->seal($appSecret))
            ->setEncryptedAccessToken($this->vault->seal($accessToken))
            ->setEncryptedVerifyToken($this->vault->seal($verifyToken))
            ->setGraphVersion($graphVersion)
            ->setSettings(['webhook_adapters' => $this->adapters($webhookAdaptersJson, [])])
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

        $duplicate = $this->repository->findOneBy(['appId' => $appId]);
        if ($duplicate instanceof MetaConnection && $duplicate->getId() !== $connection->getId()) {
            throw new \InvalidArgumentException('A Meta connection with this App ID already exists.');
        }

        $connection->setName($name)->setAppId($appId)->setGraphVersion($graphVersion)->setStatus('pending');
        foreach (['app_secret' => 'setEncryptedAppSecret', 'access_token' => 'setEncryptedAccessToken', 'verify_token' => 'setEncryptedVerifyToken'] as $field => $setter) {
            $value = trim((string) ($data[$field] ?? ''));
            if ('' !== $value) {
                $connection->{$setter}($this->vault->seal($value));
            }
        }
        if (array_key_exists('webhook_adapters_json', $data)) {
            $connection->setSettings($connection->getSettings() + ['webhook_adapters' => []]);
            $settings = $connection->getSettings();
            $settings['webhook_adapters'] = $this->adapters((string) $data['webhook_adapters_json'], $settings['webhook_adapters']);
            $connection->setSettings($settings);
        }

        $this->entityManager->persist($connection);
        $this->entityManager->flush();

        return $connection;
    }

    private function adapters(string $json, array $existing): array
    {
        if ('' === trim($json)) {
            return [];
        }
        $items = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($items) || !array_is_list($items)) {
            throw new \InvalidArgumentException('Webhook adapters must be a JSON array.');
        }
        $result = [];
        $names = [];
        foreach ($items as $item) {
            if (!is_array($item) || '' === trim((string) ($item['name'] ?? '')) || !filter_var($item['url'] ?? null, FILTER_VALIDATE_URL) || 'https' !== parse_url((string) $item['url'], PHP_URL_SCHEME)) {
                throw new \InvalidArgumentException('Every webhook adapter requires a name and HTTPS URL.');
            }
            $normalizedName = trim((string) $item['name']);
            if (isset($names[$normalizedName])) {
                throw new \InvalidArgumentException('Webhook adapter names must be unique within a connection.');
            }
            $names[$normalizedName] = true;
            $old = current(array_filter($existing, static fn ($row) => is_array($row) && ($row['name'] ?? null) === $item['name'])) ?: [];
            $secret = (string) ($item['secret'] ?? '');
            $sealed = in_array($secret, ['', '***'], true) ? ($old['sealed_secret'] ?? '') : $this->vault->seal($secret);
            if ('' === $sealed) {
                throw new \InvalidArgumentException('Every new webhook adapter requires a secret.');
            }
            $events = array_values(array_intersect((array) ($item['events'] ?? []), ['message.received', 'message.sent', 'message.delivered', 'message.read', 'message.failed']));
            $channels = array_values(array_intersect((array) ($item['channels'] ?? []), ['whatsapp', 'instagram']));
            if ([] === $events || [] === $channels) {
                throw new \InvalidArgumentException('Every webhook adapter requires at least one supported event and channel.');
            }
            $result[] = [
                'name'          => trim((string) $item['name']),
                'url'           => $item['url'],
                'sealed_secret' => $sealed,
                'enabled'       => (bool) ($item['enabled'] ?? true),
                'allow_replies' => (bool) ($item['allowReplies'] ?? $item['allow_replies'] ?? false),
                'events'        => $events,
                'channels'      => $channels,
                'timeout'       => min(15, max(2, (int) ($item['timeout'] ?? 5))),
                'maxAttempts'   => min(10, max(1, (int) ($item['maxAttempts'] ?? 5))),
            ];
        }

        return $result;
    }

    public function remove(MetaConnection $connection): void
    {
        $this->entityManager->remove($connection);
        $this->entityManager->flush();
    }
}
