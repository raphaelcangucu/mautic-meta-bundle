<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Queue\ImmediateOutboundDispatcher;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundOperationExecutor;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Application\Support\NoopInboxIntegration;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\WhatsAppSendResult;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJobRepository;
use PHPUnit\Framework\TestCase;

final class OutboundQueueTest extends TestCase
{
    public function testCompletesDueJobAndStoresMessageLogId(): void
    {
        $job = (new MetaOutboundJob(1))->setOperation('whatsapp_text');
        [$queue, $executor] = $this->queue([$job]);
        $executor->expects(self::once())->method('execute')->with($job)->willReturn(new WhatsAppSendResult(91, 'wamid.test', 'accepted', '5511999999999', ['wamid' => 'wamid.test']));

        $result = $queue->work();

        self::assertSame(['processed' => 1, 'succeeded' => 1, 'retried' => 0, 'failed' => 0, 'recovered' => 0], $result);
        self::assertSame('completed', $job->getStatus());
        self::assertSame(91, $job->getMessageLogId());
        self::assertSame(1, $job->getAttempts());
    }

    public function testHoldsUnknownTransportFailureForReview(): void
    {
        $job = (new MetaOutboundJob(2))->setOperation('whatsapp_text')->setMaxAttempts(3);
        [$queue, $executor] = $this->queue([$job]);
        $executor->method('execute')->willThrowException(new \RuntimeException('Meta temporarily unavailable'));

        $result = $queue->work();

        self::assertSame(1, $result['failed']);
        self::assertSame('uncertain', $job->getStatus());
        self::assertSame('{"message":"Meta temporarily unavailable"}', $job->getLastError());
    }

    public function testWhatsAppJobCannotCompleteWithoutWamid(): void
    {
        $job = (new MetaOutboundJob(3))->setOperation('whatsapp_template')->setMaxAttempts(1);
        [$queue, $executor] = $this->queue([$job]);
        $executor->method('execute')->willReturn(new WhatsAppSendResult(92, '', 'accepted', '5511999999999'));

        $result = $queue->work();

        self::assertSame(1, $result['failed']);
        self::assertSame('uncertain', $job->getStatus());
        self::assertNull($job->getMessageLogId());
        self::assertStringContainsString('messages[0].id', (string) $job->getLastError());
    }

    public function testDoesNotRetryPermanentValidationFailure(): void
    {
        $job = (new MetaOutboundJob(4))->setOperation('whatsapp_text')->setMaxAttempts(5);
        [$queue, $executor] = $this->queue([$job]);
        $executor->method('execute')->willThrowException(new \DomainException('Contact opted out'));

        $result = $queue->work();

        self::assertSame(1, $result['failed']);
        self::assertSame('failed', $job->getStatus());
    }

    public function testStalledCommentPrivateReplyIsHeldForReviewWithoutResending(): void
    {
        $job = (new MetaOutboundJob())->setOperation('instagram_private_reply')->setIdempotencyKey('igc:example')->setStatus('processing')->setLockedAt(new \DateTimeImmutable('-20 minutes'));
        [$queue, $executor] = $this->queue([], [$job]);
        $executor->expects(self::never())->method('execute');

        $result = $queue->work();

        self::assertSame(1, $result['recovered']);
        self::assertSame('uncertain', $job->getStatus());
        self::assertStringContainsString('uncertain', (string) $job->getLastError());
    }

    public function testRepeatedCommentActionUsesExistingUniqueJob(): void
    {
        $existing = (new MetaOutboundJob(91))->setOperation('instagram_private_reply')->setIdempotencyKey('igc:example');
        $repository = $this->createMock(MetaOutboundJobRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')->with(['idempotencyKey' => 'igc:example'])->willReturn($existing);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $executor = $this->createMock(OutboundOperationExecutor::class);
        $executor->expects(self::never())->method('execute');
        $queue = new OutboundQueue($repository, $entityManager, $executor, $this->createMock(Connection::class), new NoopInboxIntegration());

        self::assertSame($existing, $queue->enqueue(new MetaAsset(4), 'instagram_private_reply', ['recipient' => 'comment-1', 'text' => 'Report'], null, 1, 'igc:example'));
    }

    public function testHumanWhatsAppTextIsDispatchedImmediately(): void
    {
        $job = (new MetaOutboundJob(5))
            ->setOperation('whatsapp_text')
            ->setPayload(['_origin' => 'inbox_human']);
        [$queue, $executor] = $this->queue([]);
        $executor->expects(self::once())->method('execute')->with($job)->willReturn(
            new WhatsAppSendResult(95, 'wamid.immediate', 'accepted', '5511999999999', ['wamid' => 'wamid.immediate']),
        );

        self::assertTrue((new ImmediateOutboundDispatcher($queue))->dispatch($job));
        self::assertSame('completed', $job->getStatus());
        self::assertSame(95, $job->getMessageLogId());
        self::assertSame(1, $job->getAttempts());
    }

    public function testImmediateDispatcherLeavesTemplatesAndAutomationOnQueue(): void
    {
        $template = (new MetaOutboundJob(6))
            ->setOperation('whatsapp_template')
            ->setPayload(['_origin' => 'inbox_human']);
        $automation = (new MetaOutboundJob(7))
            ->setOperation('whatsapp_text')
            ->setPayload(['_origin' => 'inbox_ai']);
        [$queue, $executor] = $this->queue([]);
        $executor->expects(self::never())->method('execute');
        $dispatcher = new ImmediateOutboundDispatcher($queue);

        self::assertFalse($dispatcher->dispatch($template));
        self::assertFalse($dispatcher->dispatch($automation));
        self::assertSame('pending', $template->getStatus());
        self::assertSame('pending', $automation->getStatus());
    }

    /** @param list<MetaOutboundJob> $dueJobs
     *  @param list<MetaOutboundJob> $stalledJobs
     *  @return array{OutboundQueue, OutboundOperationExecutor&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function queue(array $dueJobs, array $stalledJobs = []): array
    {
        $repository = $this->createMock(MetaOutboundJobRepository::class);
        $repository->method('findStalled')->willReturn($stalledJobs);
        $repository->method('findDue')->willReturn($dueJobs);
        $repository->method('claim')->willReturnCallback(static function (MetaOutboundJob $job, \DateTimeImmutable $now): bool {
            if (!in_array($job->getStatus(), ['pending', 'retry'], true)) {
                return false;
            }
            $job->setStatus('processing')->setLockedAt($now)->setAttempts($job->getAttempts() + 1);

            return true;
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $executor = $this->createMock(OutboundOperationExecutor::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        $connection->method('fetchAssociative')->willReturn(['external_id' => 'wamid.test', 'status' => 'accepted']);
        $connection->method('executeQuery')->willReturn($this->createMock(Result::class));

        return [new OutboundQueue($repository, $entityManager, $executor, $connection, new NoopInboxIntegration()), $executor];
    }
}
