<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramPageConnectionResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramService;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class InstagramServiceTest extends TestCase
{
    public function testFacebookLoginSendsThroughLinkedPageWithItsCredential(): void
    {
        $account = $this->account();
        $account->getConnection()->setEncryptedAccessToken('system-credential');
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturn(['data' => [['id' => 'page-1', 'access_token' => 'page-token', 'instagram_business_account' => ['id' => 'ig-1']]]]);
        $graph->expects(self::once())->method('post')->with(
            self::callback(static fn (MetaConnection $connection): bool => 'sealed-page-token' === $connection->getEncryptedAccessToken()),
            'page-1/messages',
            ['recipient' => ['comment_id' => 'comment-1'], 'message' => ['text' => 'Report']],
        )->willReturn(['message_id' => 'mid-1']);
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->method('encrypt')->with('page-token')->willReturn('sealed-page-token');
        $accounts = new InstagramAccountResolver($graph);
        $pages = new InstagramPageConnectionResolver($graph, $accounts, new CredentialVault($encryption));
        $service = new InstagramService($graph, $this->createMock(EntityManagerInterface::class), $this->createMock(IdentityManager::class), accountResolver: $accounts, pageConnections: $pages);

        self::assertSame('accepted', $service->privateReply($account, 'comment-1', 'Report')->getStatus());
        self::assertSame('system-credential', $account->getConnection()->getEncryptedAccessToken());
    }

    public function testSendsCommentTriggeredPrivateReply(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::once())->method('post')->with(
            self::isInstanceOf(MetaConnection::class),
            'ig-1/messages',
            ['recipient' => ['comment_id' => 'comment-1'], 'message' => ['text' => 'Aqui está o link']],
        )->willReturn(['recipient_id' => 'user-1', 'message_id' => 'mid-1']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist');
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
