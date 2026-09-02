<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Webhook\WebhookIngestor;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEvent;
use MauticPlugin\MauticMetaBundle\Entity\MetaWebhookEventRepository;
use PHPUnit\Framework\TestCase;

final class WebhookIngestorTest extends TestCase
{
    public function testReceivedDuplicateIsReleasedAfterInterruptedProcessing(): void
    {
        $event = (new MetaWebhookEvent(11))->setStatus('received');
        $repository = $this->createMock(MetaWebhookEventRepository::class);
        $repository->method('findOneBy')->willReturn($event);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $result = (new WebhookIngestor($entityManager, $repository))->ingest(new MetaConnection(), ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-1']]]);

        self::assertFalse($result['duplicate']);
        self::assertTrue($result['retry']);
    }

    public function testFailedDuplicateIsReleasedForRetry(): void
    {
        $event = (new MetaWebhookEvent(12))->setStatus('failed')->setLastError('temporary');
        $repository = $this->createMock(MetaWebhookEventRepository::class);
        $repository->method('findOneBy')->willReturn($event);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $result = (new WebhookIngestor($entityManager, $repository))->ingest(new MetaConnection(), ['object' => 'instagram', 'entry' => [['id' => 'ig-1']]]);

        self::assertFalse($result['duplicate']);
        self::assertTrue($result['retry']);
        self::assertSame('received', $event->getStatus());
        self::assertNull($event->getLastError());
    }

    public function testCompleteRecordsFailureAndAttempt(): void
    {
        $event = new MetaWebhookEvent(13);
        $repository = $this->createMock(MetaWebhookEventRepository::class);
        $repository->method('find')->with(13)->willReturn($event);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        (new WebhookIngestor($entityManager, $repository))->complete(13, new \RuntimeException('processor unavailable'));

        self::assertSame('failed', $event->getStatus());
        self::assertSame(1, $event->getAttempts());
        self::assertSame('processor unavailable', $event->getLastError());
        self::assertNotNull($event->getProcessedAt());
    }
}
