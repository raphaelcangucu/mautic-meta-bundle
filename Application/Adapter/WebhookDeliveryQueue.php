<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Adapter;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaAdapterDeliveryRepository;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookDeliveryQueue
{
    public function __construct(
        private MetaAdapterDeliveryRepository $repository,
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $http,
        private CredentialVault $vault,
        private Connection $connection,
    ) {
    }

    public function work(int $limit = 100): array
    {
        if (1 !== (int) $this->connection->fetchOne("SELECT GET_LOCK('mautic_meta_adapter_queue', 0)")) {
            return ['processed' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0];
        }

        try {
            return $this->processDue($limit);
        } finally {
            $this->connection->executeQuery("SELECT RELEASE_LOCK('mautic_meta_adapter_queue')");
        }
    }

    /**
     * @return array{processed: int, succeeded: int, retried: int, failed: int}
     */
    private function processDue(int $limit): array
    {
        $processed = $succeeded = $retried = $failed = 0;
        foreach ($this->repository->findDue($limit, new \DateTimeImmutable()) as $delivery) {
            ++$processed;
            $delivery->setStatus('processing')->setAttempts($delivery->getAttempts() + 1);
            $this->entityManager->persist($delivery);
            $this->entityManager->flush();
            try {
                $body = json_encode($delivery->getPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $timestamp = (string) time();
                $response = $this->http->request('POST', $delivery->getUrl(), [
                    'body'    => $body,
                    'timeout' => $delivery->getTimeout(),
                    'headers' => [
                        'Content-Type'            => 'application/json',
                        'X-Mautic-Meta-Event'     => $delivery->getEventName(),
                        'X-Mautic-Meta-Event-Id'  => $delivery->getEventId(),
                        'X-Mautic-Meta-Timestamp' => $timestamp,
                        'X-Mautic-Meta-Signature' => 'sha256='.hash_hmac(
                            'sha256',
                            $timestamp.'.'.$body,
                            $this->vault->open($delivery->getSealedSecret()),
                        ),
                    ],
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException('HTTP '.$status);
                }

                $delivery
                    ->setStatus('completed')
                    ->setCompletedAt(new \DateTimeImmutable())
                    ->setLastError(null);
                ++$succeeded;
            } catch (\Throwable $e) {
                $delivery->setLastError($e->getMessage());
                if ($delivery->getAttempts() >= $delivery->getMaxAttempts()) {
                    $delivery->setStatus('failed');
                    ++$failed;
                } else {
                    $delay = min(3600, 30 * (2 ** ($delivery->getAttempts() - 1)));
                    $delivery->setStatus('retry')->setAvailableAt((new \DateTimeImmutable())->modify('+'.$delay.' seconds'));
                    ++$retried;
                }
            }

            $this->entityManager->persist($delivery);
            $this->entityManager->flush();
        }

        return compact('processed', 'succeeded', 'retried', 'failed');
    }
}
