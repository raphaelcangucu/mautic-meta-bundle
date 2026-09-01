<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Queue;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

final class QueueManager
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function retry(MetaOutboundJob $job): void
    {
        if (!in_array($job->getStatus(), ['failed', 'cancelled'], true)) {
            throw new \DomainException('Only failed or cancelled jobs can be retried manually.');
        }
        $job->setStatus('pending')->setAttempts(0)->setAvailableAt(new \DateTimeImmutable())->setLockedAt(null)->setCompletedAt(null)->setLastError(null);
        $this->entityManager->flush();
    }

    public function cancel(MetaOutboundJob $job): void
    {
        if (!in_array($job->getStatus(), ['pending', 'retry'], true)) {
            throw new \DomainException('Only pending jobs can be cancelled.');
        }
        $job->setStatus('cancelled')->setAvailableAt(null)->setLockedAt(null);
        $this->entityManager->flush();
    }
}
