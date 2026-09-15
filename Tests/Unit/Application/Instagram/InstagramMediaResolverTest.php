<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Instagram;

use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramMediaResolveException;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramMediaResolver;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class InstagramMediaResolverTest extends TestCase
{
    public function testResolvesAcceptanceCarouselToExactNativeMediaId(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(2))->method('get')->willReturnCallback(
            static fn (MetaConnection $connection, string $path, array $query): array => match ($path) {
                'me/accounts' => ['data' => [['instagram_business_account' => ['id' => '1166568483209053', 'username' => 'macro.markets.br']]]],
                '1166568483209053/media' => ['data' => [[
                    'id'         => '18131598137507562',
                    'permalink'  => 'https://www.instagram.com/p/DdRsy0CgJk7/',
                    'media_type' => 'CAROUSEL_ALBUM',
                    'timestamp'  => '2026-09-01T12:00:00+0000',
                    'owner'      => ['id' => '1166568483209053'],
                ]]],
                default => throw new \LogicException('Unexpected Graph path '.$path),
            },
        );

        $result = $this->resolver($graph)->resolve($this->account(), 'https://www.instagram.com/p/DdRsy0CgJk7/');

        self::assertSame('18131598137507562', $result['media_id']);
        self::assertSame('CAROUSEL_ALBUM', $result['media_type']);
        self::assertSame('macro.markets.br', $result['account']);
    }

    public function testResolvesReelByShortcodeFallback(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnCallback(static fn (MetaConnection $connection, string $path): array => match ($path) {
            'me/accounts' => ['data' => [['instagram_business_account' => ['id' => 'ig-native', 'username' => 'macro.markets.br']]]],
            'ig-native/media' => ['data' => [[
                'id' => 'reel-media-id', 'permalink' => 'https://www.instagram.com/reel/ReelCode123/',
                'media_type' => 'REELS', 'timestamp' => '2026-09-02T12:00:00+0000', 'owner' => ['id' => 'ig-native'],
            ]]],
            default => throw new \LogicException('Unexpected Graph path '.$path),
        });

        $result = $this->resolver($graph)->resolve($this->account(), 'https://instagram.com/p/ReelCode123?source=dm');

        self::assertSame('reel-media-id', $result['media_id']);
        self::assertSame('https://www.instagram.com/reel/ReelCode123/', $result['permalink']);
    }

    public function testPaginatesUsingOnlyTheOpaqueAfterCursor(): void
    {
        $mediaPages = 0;
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::exactly(3))->method('get')->willReturnCallback(
            static function (MetaConnection $connection, string $path, array $query) use (&$mediaPages): array {
                if ('me/accounts' === $path) {
                    return ['data' => [['instagram_business_account' => ['id' => 'ig-native', 'username' => 'macro.markets.br']]]];
                }
                self::assertSame('ig-native/media', $path);
                ++$mediaPages;
                if (1 === $mediaPages) {
                    self::assertArrayNotHasKey('after', $query);

                    return ['data' => [], 'paging' => ['next' => 'https://graph.facebook.com/v26.0/ig-native/media?after=cursor-2&access_token=must-not-be-forwarded']];
                }
                self::assertSame('cursor-2', $query['after']);
                self::assertArrayNotHasKey('access_token', $query);

                return ['data' => [[
                    'id' => 'page-two-id', 'permalink' => 'https://www.instagram.com/p/PageTwoCode/',
                    'media_type' => 'IMAGE', 'timestamp' => '2026-09-03T12:00:00+0000', 'owner' => ['id' => 'ig-native'],
                ]]];
            },
        );

        self::assertSame('page-two-id', $this->resolver($graph)->resolve($this->account(), 'https://www.instagram.com/p/PageTwoCode/')['media_id']);
    }

    public function testReturnsRetryableNotFoundAfterAvailablePagesAreExhausted(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnCallback(static fn (MetaConnection $connection, string $path): array => 'me/accounts' === $path
            ? ['data' => [['instagram_business_account' => ['id' => 'ig-native', 'username' => 'macro.markets.br']]]]
            : ['data' => []]);

        try {
            $this->resolver($graph)->resolve($this->account(), 'https://www.instagram.com/p/MissingCode/');
            self::fail('Expected not-found exception.');
        } catch (InstagramMediaResolveException $exception) {
            self::assertSame('instagram_media_not_found', $exception->publicCode());
            self::assertSame(Response::HTTP_NOT_FOUND, $exception->httpStatus());
            self::assertTrue($exception->isRetryable());
        }
    }

    public function testRejectsMediaOwnedByAnotherInstagramAccount(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnCallback(static fn (MetaConnection $connection, string $path): array => 'me/accounts' === $path
            ? ['data' => [['instagram_business_account' => ['id' => 'ig-native', 'username' => 'macro.markets.br']]]]
            : ['data' => [[
                'id' => 'foreign-id', 'permalink' => 'https://www.instagram.com/p/ForeignCode/',
                'media_type' => 'IMAGE', 'timestamp' => '2026-09-04T12:00:00+0000', 'owner' => ['id' => 'another-account'],
            ]]]);

        try {
            $this->resolver($graph)->resolve($this->account(), 'https://www.instagram.com/p/ForeignCode/');
            self::fail('Expected ownership exception.');
        } catch (InstagramMediaResolveException $exception) {
            self::assertSame('instagram_media_owner_mismatch', $exception->publicCode());
            self::assertSame(Response::HTTP_CONFLICT, $exception->httpStatus());
            self::assertFalse($exception->isRetryable());
        }
    }

    public function testRejectsInactiveInstagramAssetBeforeCallingGraph(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method('get');
        $asset = $this->account()->setStatus('disabled');

        try {
            $this->resolver($graph)->resolve($asset, 'https://www.instagram.com/p/DdRsy0CgJk7/');
            self::fail('Expected unavailable-asset exception.');
        } catch (InstagramMediaResolveException $exception) {
            self::assertSame('instagram_asset_unavailable', $exception->publicCode());
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->httpStatus());
            self::assertFalse($exception->isRetryable());
        }
    }

    private function resolver(MetaGraphClientInterface $graph): InstagramMediaResolver
    {
        return new InstagramMediaResolver($graph, new InstagramAccountResolver($graph));
    }

    private function account(): MetaAsset
    {
        $connection = (new MetaConnection(5))->setName('Meta')->setStatus('active')->setIsPublished(true);

        return (new MetaAsset(4))
            ->setConnection($connection)
            ->setExternalId('business-asset-id')
            ->setType(AssetType::InstagramAccount)
            ->setName('Instagram Macro Markets Brasil')
            ->setUsername('macro.markets.br')
            ->setStatus('active')
            ->setIsPublished(true);
    }
}
