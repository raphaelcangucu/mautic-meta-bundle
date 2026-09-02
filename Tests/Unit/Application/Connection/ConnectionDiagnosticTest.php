<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionDiagnostic;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class ConnectionDiagnosticTest extends TestCase
{
    public function testHealthyGraphRequestActivatesConnectionAndStoresDiagnostic(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(2))
            ->method('get')
            ->willReturnCallback(static function (MetaConnection $connection, string $path): array {
                if ('me' === $path) {
                    return ['id' => 'user-1', 'name' => 'Meta User'];
                }

                self::assertSame('me/permissions', $path);

                return ['data' => array_map(
                    static fn (string $permission): array => [
                        'permission' => $permission,
                        'status'     => 'granted',
                    ],
                    [
                        'instagram_basic',
                        'instagram_manage_messages',
                        'instagram_manage_comments',
                        'pages_show_list',
                    ],
                )];
            });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $connection = (new MetaConnection(4))->setAppId('app-1')->setStatus('pending');

        $result = (new ConnectionDiagnostic($graph, $entityManager))->test($connection);

        self::assertTrue($result['ok']);
        self::assertSame('active', $connection->getStatus());
        self::assertSame('user-1', $connection->getSettings()['last_diagnostic']['metaUser']['id']);
        self::assertSame([], $result['permissions']['missing']);
    }

    public function testFailureMarksConnectionAsErrorWithoutLeakingCredential(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willThrowException(new \RuntimeException('Invalid OAuth access token'));
        $connection = (new MetaConnection(4))->setAppId('app-1')->setStatus('pending');

        $result = (new ConnectionDiagnostic($graph, $this->createMock(EntityManagerInterface::class)))->test($connection);

        self::assertFalse($result['ok']);
        self::assertSame('error', $connection->getStatus());
        self::assertSame('Invalid OAuth access token', $result['error']);
    }
}
