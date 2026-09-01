<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramService;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;

final class InstagramServiceTest extends TestCase
{
    public function testSendsCommentTriggeredPrivateReply(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::isInstanceOf(MetaConnection::class),
            'ig-1/messages',
            ['recipient' => ['comment_id' => 'comment-1'], 'message' => ['text' => 'Aqui está o link']],
        )->willReturn(['recipient_id' => 'user-1', 'message_id' => 'mid-1']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::exactly(2))->method('flush');
        $identities = $this->createMock(IdentityManager::class);
        $identities->expects(self::once())->method('assertChannelContactable')->with(null, 'instagram');

        $log = (new InstagramService($graph, $entityManager, $identities))->privateReply($this->account(), 'comment-1', 'Aqui está o link');

        self::assertSame('mid-1', $log->getExternalId());
        self::assertSame('accepted', $log->getStatus());
    }

    public function testListsConversationsWithInstagramPlatform(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('get')->with(
            self::isInstanceOf(MetaConnection::class),
            'ig-1/conversations',
            self::callback(static fn (array $query): bool => 'instagram' === $query['platform'] && 20 === $query['limit']),
        )->willReturn(['data' => [['id' => 'conversation-1']]]);

        $result = (new InstagramService($graph, $this->createMock(EntityManagerInterface::class), $this->createMock(IdentityManager::class)))->conversations($this->account(), 20);
        self::assertSame('conversation-1', $result['data'][0]['id']);
    }

    private function account(): MetaAsset
    {
        return (new MetaAsset())->setConnection((new MetaConnection())->setName('Primary'))->setExternalId('ig-1')->setName('@brand')->setType(AssetType::InstagramAccount)->setStatus('active')->setIsPublished(true);
    }
}
