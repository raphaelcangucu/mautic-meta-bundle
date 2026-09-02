<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class InstagramAccountResolverTest extends TestCase
{
    public function testResolvesCanonicalIdFromLinkedPage(): void
    {
        $account = $this->account();
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())
            ->method('get')
            ->with($account->getConnection(), 'me/accounts', self::isType('array'))
            ->willReturn([
                'data' => [[
                    'id' => 'page-id',
                    'instagram_business_account' => [
                        'id'       => 'canonical-id',
                        'username' => 'macro.markets',
                    ],
                ]],
            ]);

        self::assertSame('canonical-id', (new InstagramAccountResolver($graph))->resolve($account));
    }

    public function testResolvesBusinessManagerAssetThroughMetadata(): void
    {
        $account = $this->account();
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                ['data' => []],
                ['id' => 'business-asset-id', 'ig_user_id' => 'canonical-id'],
            );

        self::assertSame('canonical-id', (new InstagramAccountResolver($graph))->resolve($account));
    }

    private function account(): MetaAsset
    {
        $connection = (new MetaConnection(5))->setStatus('active');

        return (new MetaAsset(3))
            ->setConnection($connection)
            ->setType(AssetType::InstagramAccount)
            ->setExternalId('business-asset-id')
            ->setUsername('macro.markets')
            ->setStatus('active')
            ->setIsPublished(true);
    }
}
