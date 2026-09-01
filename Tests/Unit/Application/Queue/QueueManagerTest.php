<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Queue;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Queue\QueueManager;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use PHPUnit\Framework\TestCase;

final class QueueManagerTest extends TestCase
{
    public function testRetriesFailedJobImmediatelyAndResetsAttemptState(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $job = (new MetaOutboundJob())->setStatus('failed')->setAttempts(5)->setLastError('timeout');

        (new QueueManager($entityManager))->retry($job);

        self::assertSame('pending', $job->getStatus());
        self::assertSame(0, $job->getAttempts());
        self::assertNull($job->getLastError());
        self::assertNotNull($job->getAvailableAt());
    }

    public function testCancelsOnlyPendingJob(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $job = (new MetaOutboundJob())->setStatus('retry');

        (new QueueManager($entityManager))->cancel($job);

        self::assertSame('cancelled', $job->getStatus());
        self::assertNull($job->getAvailableAt());
    }

    public function testCompletedJobCannotBeCancelled(): void
    {
        $manager = new QueueManager($this->createMock(EntityManagerInterface::class));

        $this->expectException(\DomainException::class);
        $manager->cancel((new MetaOutboundJob())->setStatus('completed'));
    }
}
