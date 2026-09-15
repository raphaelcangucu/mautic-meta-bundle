<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Controller\Api;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramAccountResolver;
use MauticPlugin\MauticMetaBundle\Application\Instagram\InstagramMediaResolver;
use MauticPlugin\MauticMetaBundle\Controller\Api\InstagramMediaApiController;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InstagramMediaApiControllerTest extends TestCase
{
    public function testReturnsOnlyWhitelistedMediaFields(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnCallback(static fn (MetaConnection $connection, string $path): array => 'me/accounts' === $path
            ? ['data' => [['access_token' => 'page-secret', 'instagram_business_account' => ['id' => 'ig-native', 'username' => 'macro.markets.br']]]]
            : ['data' => [[
                'id' => '18131598137507562', 'permalink' => 'https://www.instagram.com/p/DdRsy0CgJk7/',
                'media_type' => 'CAROUSEL_ALBUM', 'timestamp' => '2026-09-01T12:00:00+0000',
                'owner' => ['id' => 'ig-native'], 'access_token' => 'media-secret', 'page_token' => 'another-secret',
            ]]]);

        $response = $this->controller($graph, $this->account())->resolve(4, $this->request('https://www.instagram.com/p/DdRsy0CgJk7/'));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('18131598137507562', $payload['media_id']);
        self::assertSame(['success', 'asset_id', 'account', 'media_id', 'permalink', 'media_type', 'timestamp'], array_keys($payload));
        self::assertStringNotContainsString('secret', (string) $response->getContent());
        self::assertStringNotContainsString('token', (string) $response->getContent());
    }

    public function testReturnsNotFoundForUnknownAsset(): void
    {
        $response = $this->controller($this->createMock(MetaGraphClientInterface::class), null)->resolve(404, $this->request('https://www.instagram.com/p/DdRsy0CgJk7/'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('instagram_asset_not_found', $this->payload($response)['code']);
    }

    public function testMapsExpiredMetaTokenWithoutExposingIt(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willThrowException(new MetaGraphApiException(
            'GET',
            'https://graph.facebook.com/v26.0/me/accounts',
            Response::HTTP_BAD_REQUEST,
            ['message' => 'Expired OAuth token private-secret', 'type' => 'OAuthException', 'code' => 190],
        ));

        $response = $this->controller($graph, $this->account())->resolve(4, $this->request('https://www.instagram.com/p/DdRsy0CgJk7/'));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('meta_token_expired', $this->payload($response)['code']);
        self::assertStringNotContainsString('private-secret', (string) $response->getContent());
    }

    public function testMapsGraphRateLimitToRetryableServiceUnavailable(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willThrowException(new MetaGraphApiException(
            'GET',
            'https://graph.facebook.com/v26.0/me/accounts',
            Response::HTTP_TOO_MANY_REQUESTS,
            ['message' => 'Rate limited', 'type' => 'OAuthException', 'code' => 4],
        ));

        $response = $this->controller($graph, $this->account())->resolve(4, $this->request('https://www.instagram.com/p/DdRsy0CgJk7/'));
        $payload = $this->payload($response);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('meta_rate_limited', $payload['code']);
        self::assertTrue($payload['retryable']);
    }

    public function testMapsPermanentGraphErrorToBadGateway(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willThrowException(new MetaGraphApiException(
            'GET',
            'https://graph.facebook.com/v26.0/me/accounts',
            Response::HTTP_BAD_REQUEST,
            ['message' => 'Unsupported request', 'type' => 'GraphMethodException', 'code' => 100],
        ));

        $response = $this->controller($graph, $this->account())->resolve(4, $this->request('https://www.instagram.com/p/DdRsy0CgJk7/'));

        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertSame('meta_graph_error', $this->payload($response)['code']);
    }

    public function testMediaNotFoundResponseIsSafeToRetry(): void
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnCallback(static fn (MetaConnection $connection, string $path): array => 'me/accounts' === $path
            ? ['data' => [['instagram_business_account' => ['id' => 'ig-native', 'username' => 'macro.markets.br']]]]
            : ['data' => []]);

        $response = $this->controller($graph, $this->account())->resolve(4, $this->request('https://www.instagram.com/p/MissingCode/'));
        $payload = $this->payload($response);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('instagram_media_not_found', $payload['code']);
        self::assertTrue($payload['retryable']);
    }

    public function testRejectsCallerWithoutMetaViewPermission(): void
    {
        $permissions = $this->createMock(CorePermissions::class);
        $permissions->method('isGranted')->willReturn(false);
        $assets = $this->createMock(MetaAssetRepository::class);
        $assets->expects(self::never())->method('find');
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $controller = new InstagramMediaApiController($permissions, $assets, new InstagramMediaResolver($graph, new InstagramAccountResolver($graph)));

        $response = $controller->resolve(4, $this->request('https://www.instagram.com/p/DdRsy0CgJk7/'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('access_denied', $this->payload($response)['code']);
    }

    private function controller(MetaGraphClientInterface $graph, ?MetaAsset $asset): InstagramMediaApiController
    {
        $permissions = $this->createMock(CorePermissions::class);
        $permissions->method('isGranted')->with('meta:connections:view')->willReturn(true);
        $assets = $this->createMock(MetaAssetRepository::class);
        $assets->method('find')->willReturn($asset);

        return new InstagramMediaApiController($permissions, $assets, new InstagramMediaResolver($graph, new InstagramAccountResolver($graph)));
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

    private function request(string $permalink): Request
    {
        return Request::create('/api/meta/instagram/assets/4/media/resolve', 'GET', ['permalink' => $permalink]);
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
