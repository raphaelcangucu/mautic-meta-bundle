<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionDiagnostic;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
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

    public function testQrSessionAssetLeavesTheOfficialDiagnosticUntouched(): void
    {
        // Uma sessao por QR nao e asset do Graph: nao tem escopo de permissao da Meta nem
        // no para consultar. Acrescentar uma a uma conexao existente nao pode mudar uma
        // virgula do que a tela ja dizia sobre os assets oficiais dela.
        $officialOnly  = $this->diagnose($this->connection(AssetType::FacebookPage));
        $withQrSession = $this->diagnose($this->connection(AssetType::FacebookPage, AssetType::WhatsAppQrSession));

        self::assertTrue($withQrSession['ok']);
        self::assertArrayNotHasKey('error', $withQrSession);
        self::assertSame($officialOnly['permissions'], $withQrSession['permissions']);
        self::assertSame($officialOnly['assets'], $withQrSession['assets']);
    }

    public function testConnectionWithOnlyQrSessionsHasNothingToDiagnose(): void
    {
        $result = $this->diagnose($this->connection(AssetType::WhatsAppQrSession));

        self::assertTrue($result['ok']);
        self::assertSame([], $result['permissions']['required']);
        self::assertSame(0, $result['assets']['configuredCount']);
        self::assertSame([], $result['assets']['accessible']);
        self::assertSame([], $result['assets']['missing']);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnose(MetaConnection $connection): array
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnCallback(static fn (MetaConnection $target, string $path): array => match ($path) {
            'me'             => ['id' => 'user-1', 'name' => 'Meta User'],
            'me/permissions' => ['data' => array_map(
                static fn (string $permission): array => ['permission' => $permission, 'status' => 'granted'],
                ['instagram_basic', 'instagram_manage_messages', 'instagram_manage_comments', 'pages_show_list'],
            )],
            default          => ['id' => $path, 'name' => 'Loja Macro'],
        });

        return (new ConnectionDiagnostic($graph, $this->createMock(EntityManagerInterface::class)))->test($connection);
    }

    private function connection(AssetType ...$types): MetaConnection
    {
        $connection = (new MetaConnection(4))->setAppId('app-1')->setStatus('pending');
        $assetId    = 10;
        foreach ($types as $type) {
            $connection->addAsset(
                (new MetaAsset(++$assetId))->setType($type)->setExternalId('asset-'.$type->value)->setName($type->value),
            );
        }

        return $connection;
    }
}
