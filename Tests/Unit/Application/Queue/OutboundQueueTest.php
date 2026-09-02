<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundOperationExecutor;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSendResult;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use PHPUnit\Framework\TestCase;

final class OutboundQueueTest extends TestCase
{
    public function testCompletesDueJobAndStoresMessageLogId(): void
    {
        $job = (new MetaOutboundJob())->setOperation('whatsapp_text');
        [$queue, $executor] = $this->queue([$job]);
        $executor->expects(self::once())->method('execute')->with($job)->willReturn(new WhatsAppSendResult(91, 'wamid.test', 'accepted', '5511999999999', ['wamid' => 'wamid.test']));

        $result = $queue->work();

        self::assertSame(['processed' => 1, 'succeeded' => 1, 'retried' => 0, 'failed' => 0, 'recovered' => 0], $result);
        self::assertSame('completed', $job->getStatus());
        self::assertSame(91, $job->getMessageLogId());
        self::assertSame(1, $job->getAttempts());
    }

    public function testRetriesTransientFailureWithBackoff(): void
    {
        $job = (new MetaOutboundJob())->setOperation('whatsapp_text')->setMaxAttempts(3);
        [$queue, $executor] = $this->queue([$job]);
        $executor->method('execute')->willThrowException(new \RuntimeException('Meta temporarily unavailable'));

        $result = $queue->work();

        self::assertSame(1, $result['retried']);
        self::assertSame('retry', $job->getStatus());
        self::assertSame('{"message":"Meta temporarily unavailable"}', $job->getLastError());
        self::assertGreaterThan(new \DateTimeImmutable(), $job->getAvailableAt());
    }

    public function testWhatsAppJobCannotCompleteWithoutWamid(): void
    {
        $job = (new MetaOutboundJob())->setOperation('whatsapp_template')->setMaxAttempts(1);
        [$queue, $executor] = $this->queue([$job]);
        $executor->method('execute')->willReturn(new WhatsAppSendResult(92, '', 'accepted', '5511999999999'));

        $result = $queue->work();

        self::assertSame(1, $result['failed']);
        self::assertSame('failed', $job->getStatus());
        self::assertNull($job->getMessageLogId());
        self::assertStringContainsString('messages[0].id', (string) $job->getLastError());
    }

    public function testDoesNotRetryPermanentValidationFailure(): void
    {
        $job = (new MetaOutboundJob())->setOperation('whatsapp_text')->setMaxAttempts(5);
        [$queue, $executor] = $this->queue([$job]);
        $executor->method('execute')->willThrowException(new \DomainException('Contact opted out'));

        $result = $queue->work();

        self::assertSame(1, $result['failed']);
        self::assertSame('failed', $job->getStatus());
    }

    /** @param list<MetaOutboundJob> $dueJobs
     *  @return array{OutboundQueue, OutboundOperationExecutor&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function queue(array $dueJobs): array
    {
        $repository = $this->createMock(MetaOutboundJobRepository::class);
        $repository->method('findStalled')->willReturn([]);
        $repository->method('findDue')->willReturn($dueJobs);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $executor = $this->createMock(OutboundOperationExecutor::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchAssociative')->willReturn(['external_id' => 'wamid.test', 'status' => 'accepted']);
        $connection->method('executeQuery')->willReturn($this->createMock(Result::class));

        return [new OutboundQueue($repository, $entityManager, $executor, $connection), $executor];
    }
}
