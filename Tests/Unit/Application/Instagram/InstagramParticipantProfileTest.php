<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramPageConnectionResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramParticipantProfile;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class InstagramParticipantProfileTest extends TestCase
{
    public function testProfileIsCachedAndUnrelatedFieldsAreDiscarded(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(3))->method('get')->willReturnOnConsecutiveCalls(
            ['data' => [['instagram_business_account' => ['id' => 'ig-id']]]],
            ['data' => [['id' => 'page-id', 'access_token' => 'page-token', 'instagram_business_account' => ['id' => 'ig-id']]]],
            ['name' => 'Raphael', 'username' => 'raphael', 'profile_pic' => 'https://example.fbcdn.net/photo.jpg', 'unrelated' => 'discard'],
        );
        $service = $this->service($graph);
        $asset = (new MetaAsset(4))->setConnection(new MetaConnection(5))->setExternalId('ig-id');
        $first = $service->resolve($asset, '123');
        self::assertSame('Raphael', $first['name']);
        self::assertArrayNotHasKey('unrelated', $first);
        self::assertSame($first, $service->resolve($asset, '123'));
    }

    public function testApiFailureDoesNotBlockMessagesAndIsCached(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('get')->willThrowException(new \RuntimeException('Permission denied'));
        $service = $this->service($graph);
        $asset = (new MetaAsset(4))->setConnection(new MetaConnection(5))->setExternalId('ig-id');
        self::assertSame([], $service->resolve($asset, '123'));
        self::assertSame([], $service->resolve($asset, '123'));
        self::assertSame([], $service->resolve($asset, '../invalid'));
    }

    private function service(MetaGraphClientInterface $graph): InstagramParticipantProfile
    {
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->method('encrypt')->willReturn('sealed');
        $pages = new InstagramPageConnectionResolver($graph, new InstagramAccountResolver($graph), new CredentialVault($encryption));
        return new InstagramParticipantProfile($graph, $pages, new ArrayAdapter());
    }
}
