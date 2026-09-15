<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Connection;

use MauticPlugin\MauticMetaBundle\Application\Connection\ProviderWebhookRouter;
use MauticPlugin\MauticMetaBundle\Application\Connection\WabaResolver;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class ProviderIsolationTest extends TestCase
{
    public function testRoutesEachCustomerSeparately(): void
    {
        $root = (new MetaConnection(1))->setAppId('app')->setIsPublished(true);
        $customer = (new MetaConnection(2))->setAppId('app')->setBusinessId('business')->setSettings(['provider_connection_id' => 1])->setIsPublished(true);
        $other = (new MetaConnection(3))->setAppId('other')->setIsPublished(true);
        $repo = $this->createMock(MetaAssetRepository::class);
        $repo->method('findBy')->willReturnCallback(fn ($criteria) => [(new MetaAsset())->setConnection('client' === $criteria['externalId'] ? $customer : $other)]);
        $result = (new ProviderWebhookRouter($repo))->route($root, ['entry' => [['id' => 'client'], ['id' => 'unrelated']]]);
        self::assertSame([2], array_keys($result));
        self::assertSame([['id' => 'client']], $result[2]['payload']['entry']);
    }

    public function testAmbiguousWabaIsNotRouted(): void
    {
        $root = (new MetaConnection(1))->setAppId('app')->setIsPublished(true);
        $child = (new MetaConnection(2))->setAppId('app')->setBusinessId('client')->setSettings(['provider_connection_id' => 1])->setIsPublished(true);
        $repo = $this->createMock(MetaAssetRepository::class);
        $repo->method('findBy')->willReturn([(new MetaAsset())->setConnection($root), (new MetaAsset())->setConnection($child)]);
        self::assertSame([], (new ProviderWebhookRouter($repo))->route($root, ['entry' => [['id' => 'waba']]]));
    }

    public function testPhoneBindingUsesPaginationAndOnlyConfiguredWaba(): void
    {
        $connection = new MetaConnection(1);
        $phone = (new MetaAsset())->setConnection($connection)->setExternalId('phone')->setSettings(['waba_id' => 'correct']);
        $wrong = (new MetaAsset())->setExternalId('wrong');
        $correct = (new MetaAsset())->setExternalId('correct');
        $repo = $this->createMock(MetaAssetRepository::class);
        $repo->expects(self::once())->method('findBy')->with(self::callback(fn ($criteria) => $criteria['connection'] === $connection))->willReturn([$wrong, $correct]);
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(2))->method('get')->with($connection, 'correct/phone_numbers', self::anything())->willReturnOnConsecutiveCalls(['data' => [], 'paging' => ['next' => 'untrusted-url', 'cursors' => ['after' => 'cursor']]], ['data' => [['id' => 'phone']]]);
        self::assertSame($correct, (new WabaResolver($repo, $graph))->forPhone($phone));
    }

    public function testDoesNotGuessMissingBinding(): void
    {
        $repo = $this->createMock(MetaAssetRepository::class);
        $repo->method('findBy')->willReturn([]);
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method('get');
        $this->expectException(\DomainException::class);
        (new WabaResolver($repo, $graph))->forPhone((new MetaAsset())->setConnection(new MetaConnection()));
    }
}
