<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConsentJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaConsentJobRepository;

final class ConsentJobQueue
{
    public function __construct(
        private MetaConsentJobRepository $jobs,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private WhatsAppConsentRegistrationService $registration,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(MetaAsset $asset, array $payload): MetaConsentJob
    {
        $submissionId = trim((string) ($payload['externalSubmissionId'] ?? ''));
        if ('' === $submissionId) {
            throw new \InvalidArgumentException('externalSubmissionId is required.');
        }

        $existing = $this->jobs->findOneBy([
            'asset'                => $asset,
            'externalSubmissionId' => $submissionId,
        ]);
        if ($existing instanceof MetaConsentJob) {
            return $existing;
        }

        $payload['assetId'] = $asset->getId();
        $job = (new MetaConsentJob())
            ->setAsset($asset)
            ->setExternalSubmissionId($submissionId)
            ->setPayload($payload);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * @return array{processed: int, completed: int, rejected: int, retried: int, failed: int}
     */
    public function work(int $limit = 100): array
    {
        if (1 !== (int) $this->connection->fetchOne("SELECT GET_LOCK('mautic_meta_consent_queue', 0)")) {
            return ['processed' => 0, 'completed' => 0, 'rejected' => 0, 'retried' => 0, 'failed' => 0];
        }

        try {
            return $this->processDue($limit);
        } finally {
            $this->connection->executeQuery("SELECT RELEASE_LOCK('mautic_meta_consent_queue')");
        }
    }

    /**
     * @return array{processed: int, completed: int, rejected: int, retried: int, failed: int}
     */
    private function processDue(int $limit): array
    {
        $processed = $completed = $rejected = $retried = $failed = 0;
        foreach ($this->jobs->findDue($limit, new \DateTimeImmutable()) as $job) {
            ++$processed;
            $job->setStatus('processing')->setAttempts($job->getAttempts() + 1);
            $this->entityManager->persist($job);
            $this->entityManager->flush();

            try {
                $result = $this->registration->register($job->getPayload());
                $job->setResult($result)->setLastError(null);
                if (in_array($result['status'], ['rejected', 'conflict'], true)) {
                    $job->setStatus($result['status']);
                    ++$rejected;
                } else {
                    $job->setStatus('completed');
                    ++$completed;
                }
            } catch (\Throwable $exception) {
                $job->setLastError($exception->getMessage());
                if ($job->getAttempts() >= $job->getMaxAttempts()) {
                    $job->setStatus('failed');
                    ++$failed;
                } else {
                    $delay = min(3600, 30 * (2 ** max(0, $job->getAttempts() - 1)));
                    $job->setStatus('retry')->setAvailableAt((new \DateTimeImmutable())->modify('+'.$delay.' seconds'));
                    ++$retried;
                }
            }

            $this->entityManager->persist($job);
            $this->entityManager->flush();
        }

        return compact('processed', 'completed', 'rejected', 'retried', 'failed');
    }
}
