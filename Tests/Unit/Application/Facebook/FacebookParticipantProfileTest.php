<?php

declare(strict_types=1);
namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Facebook;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticMetaBundle\Application\Facebook\FacebookParticipantProfile;
use MauticPlugin\MauticMetaBundle\Application\Facebook\PageConnectionResolver;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FacebookParticipantProfileTest extends TestCase
{
    public function testFallsBackToExactConversationParticipantAndCachesResult(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(3))->method('get')->willReturnCallback(static function ($connection, $path, $query) {
            return match ($path) {
                'me/accounts' => ['data' => [['id' => 'page', 'access_token' => 'token']]],
                '123' => throw new \RuntimeException('Profile permission unavailable'),
                'page/conversations' => ['data' => [['participants' => ['data' => [['id' => 'page', 'name' => 'Business'], ['id' => '123', 'name' => 'Raphael']]]]]],
            };
        });
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->method('encrypt')->willReturn('sealed');
        $service = new FacebookParticipantProfile($graph, new PageConnectionResolver($graph, new CredentialVault($encryption)), new ArrayAdapter());
        $asset = (new MetaAsset(11))->setConnection(new MetaConnection(5))->setType(AssetType::FacebookPage)->setExternalId('page')->setStatus('active');
        $asset->setIsPublished(true);
        self::assertSame(['name' => 'Raphael'], $service->resolve($asset, '123'));
        self::assertSame(['name' => 'Raphael'], $service->resolve($asset, '123'));
        self::assertSame([], $service->resolve($asset, '../invalid'));
    }
}
