<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;

final class OutboundQueue
{
    public function __construct(
        private MetaOutboundJobRepository $jobs,
        private EntityManagerInterface $entityManager,
        private OutboundOperationExecutor $executor,
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(MetaAsset $asset, string $operation, array $payload, ?Lead $contact = null, int $maxAttempts = 5, ?string $idempotencyKey = null): MetaOutboundJob
    {
        if (!in_array($operation, ['whatsapp_text', 'whatsapp_template', 'whatsapp_media', 'whatsapp_interactive', 'instagram_private_reply', 'instagram_public_reply', 'instagram_direct_message'], true)) {
            throw new \InvalidArgumentException('Unsupported Meta queue operation.');
        }
        if (null !== $idempotencyKey && $this->jobs->findOneBy(['idempotencyKey' => $idempotencyKey]) instanceof MetaOutboundJob) {
            return $this->jobs->findOneBy(['idempotencyKey' => $idempotencyKey]);
        }
        $job = (new MetaOutboundJob())->setAsset($asset)->setContact($contact)->setOperation($operation)->setPayload($payload)->setMaxAttempts($maxAttempts)->setIdempotencyKey($idempotencyKey);
        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    /**
     * @return array{processed:int,succeeded:int,retried:int,failed:int,recovered:int}
     */
    public function work(int $limit = 100): array
    {
        if (1 !== (int) $this->connection->fetchOne("SELECT GET_LOCK('mautic_meta_outbound_queue', 0)")) {
            return ['processed' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0, 'recovered' => 0];
        }
        try {
            return $this->processDue($limit);
        } finally {
            $this->connection->executeQuery("SELECT RELEASE_LOCK('mautic_meta_outbound_queue')");
        }
    }

    /**
     * @return array{processed:int,succeeded:int,retried:int,failed:int,recovered:int}
     */
    private function processDue(int $limit): array
    {
        $now = new \DateTimeImmutable();
        $recovered = $this->recoverStalled($now->modify('-15 minutes'));
        $processed = $succeeded = $retried = $failed = 0;
        foreach ($this->jobs->findDue($limit, $now) as $job) {
            ++$processed;
            $job->setStatus('processing')->setLockedAt($now)->setAttempts($job->getAttempts() + 1);
            $this->entityManager->persist($job);
            $this->entityManager->flush();
            try {
                $messageLogId = $this->executor->execute($job);
                $job->setStatus('completed')->setCompletedAt(new \DateTimeImmutable())->setLockedAt(null)->setLastError(null)->setMessageLogId($messageLogId);
                ++$succeeded;
            } catch (\Throwable $exception) {
                $job->setLastError($exception->getMessage())->setLockedAt(null);
                $permanentGraphFailure = $exception instanceof MetaGraphApiException
                    && !$exception->isRetryable();
                if (
                    $exception instanceof \InvalidArgumentException
                    || $exception instanceof \DomainException
                    || $permanentGraphFailure
                    || $job->getAttempts() >= $job->getMaxAttempts()
                ) {
                    $job->setStatus('failed');
                    ++$failed;
                } else {
                    $delay = min(3600, 2 ** max(0, $job->getAttempts() - 1) * 30);
                    $job->setStatus('retry')->setAvailableAt((new \DateTimeImmutable())->modify(sprintf('+%d seconds', $delay)));
                    ++$retried;
                }
            }
            $this->entityManager->persist($job);
            $this->entityManager->flush();
        }

        return compact('processed', 'succeeded', 'retried', 'failed', 'recovered');
    }

    private function recoverStalled(\DateTimeInterface $before): int
    {
        $stalled = $this->jobs->findStalled($before);
        foreach ($stalled as $job) {
            $job->setStatus('retry')->setLockedAt(null)->setAvailableAt(new \DateTimeImmutable())->setLastError('Recovered after worker timeout.');
            $this->entityManager->persist($job);
        }
        if ([] !== $stalled) {
            $this->entityManager->flush();
        }

        return count($stalled);
    }
}
