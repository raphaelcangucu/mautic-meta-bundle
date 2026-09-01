<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class ConnectionDiagnostic
{
    public function __construct(
        private MetaGraphClientInterface $graph,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function test(MetaConnection $connection): array
    {
        $started = microtime(true);
        try {
            $profile = $this->graph->get($connection, 'me', ['fields' => 'id,name']);
            $connection->setStatus('active');
            $result = ['ok' => true, 'connectionId' => $connection->getId(), 'graphVersion' => $connection->getGraphVersion(), 'metaUser' => ['id' => $profile['id'] ?? null, 'name' => $profile['name'] ?? null], 'latencyMs' => (int) round((microtime(true) - $started) * 1000)];
        } catch (\Throwable $exception) {
            $connection->setStatus('error');
            $result = ['ok' => false, 'connectionId' => $connection->getId(), 'graphVersion' => $connection->getGraphVersion(), 'error' => $exception->getMessage(), 'latencyMs' => (int) round((microtime(true) - $started) * 1000)];
        }
        $settings = $connection->getSettings();
        $settings['last_diagnostic'] = $result + ['testedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
        $connection->setSettings($settings);
        $this->entityManager->flush();

        return $result;
    }
}
