<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramPageConnectionResolver;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class InstagramPageConnectionResolverTest extends TestCase
{
    public function testSelectsMatchingPageAcrossPaginationWithoutChangingSharedCredential(): void
    {
        $connection = (new MetaConnection(5))->setEncryptedAccessToken('shared-system-credential');
        $account = (new MetaAsset(4))->setConnection($connection)->setExternalId('ig-id');
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(3))->method('get')->willReturnOnConsecutiveCalls(
            ['data' => [['instagram_business_account' => ['id' => 'ig-id']]]],
            ['data' => [['access_token' => 'wrong-page', 'instagram_business_account' => ['id' => 'other-ig']]], 'paging' => ['next' => 'opaque-next-url', 'cursors' => ['after' => 'cursor-2']]],
            ['data' => [['id' => 'linked-page-id', 'access_token' => 'matching-page', 'instagram_business_account' => ['id' => 'ig-id']]]],
        );
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->expects(self::once())->method('encrypt')->with('matching-page')->willReturn('sealed-page');
        $resolver = new InstagramPageConnectionResolver($graph, new InstagramAccountResolver($graph), new CredentialVault($encryption));

        $result = $resolver->resolve($account);

        self::assertNotSame($connection, $result);
        self::assertSame('sealed-page', $result->getEncryptedAccessToken());
        self::assertSame('linked-page-id', $resolver->pageId($account));
        self::assertSame('shared-system-credential', $connection->getEncryptedAccessToken());
    }

    public function testRejectsMissingLinkedPage(): void
    {
        $account = (new MetaAsset(4))->setConnection(new MetaConnection(5))->setExternalId('ig-id');
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnOnConsecutiveCalls(
            ['data' => [['instagram_business_account' => ['id' => 'ig-id']]]],
            ['data' => [['access_token' => 'wrong-page', 'instagram_business_account' => ['id' => 'other-ig']]]],
        );
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->expects(self::never())->method('encrypt');

        $this->expectException(\RuntimeException::class);
        (new InstagramPageConnectionResolver($graph, new InstagramAccountResolver($graph), new CredentialVault($encryption)))->resolve($account);
    }
}
