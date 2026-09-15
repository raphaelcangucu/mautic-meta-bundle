<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Infrastructure;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionCredentialProvider;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\ConnectionRateLimiter;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClient;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MetaGraphClientUploadTest extends TestCase
{
    public function testUsesAppUploadEndpointAndOAuthHeaderForBothOfficialUploadSteps(): void
    {
        $requests = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            self::assertSame('POST', $method);
            self::assertSame('Authorization: OAuth access-token', $options['normalized_headers']['authorization'][0] ?? null);

            if (1 === $requests) {
                self::assertStringStartsWith('https://graph.facebook.com/v26.0/1437146078305405/uploads?', $url);
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                self::assertSame('profile.png', $query['file_name'] ?? null);
                self::assertSame('7', (string) ($query['file_length'] ?? ''));
                self::assertSame('image/png', $query['file_type'] ?? null);

                return new MockResponse('{"id":"upload:session?sig=signature"}', ['http_code' => 200, 'response_headers' => ['content-type: application/json']]);
            }

            self::assertSame('https://graph.facebook.com/v26.0/upload:session?sig=signature', $url);
            self::assertSame('file_offset: 0', $options['normalized_headers']['file_offset'][0] ?? null);
            self::assertSame('Content-Type: image/png', $options['normalized_headers']['content-type'][0] ?? null);
            self::assertSame('PNGDATA', $options['body']);

            return new MockResponse('{"h":"profile-picture-handle"}', ['http_code' => 200, 'response_headers' => ['content-type: application/json']]);
        });

        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->method('decrypt')->willReturnCallback(static fn (string $value): string => match ($value) {
            'sealed-secret' => 'app-secret',
            'sealed-access' => 'access-token',
            'sealed-verify' => 'verify-token',
        });
        $connection = (new MetaConnection(9))
            ->setAppId('1437146078305405')
            ->setEncryptedAppSecret('sealed-secret')
            ->setEncryptedAccessToken('sealed-access')
            ->setEncryptedVerifyToken('sealed-verify')
            ->setGraphVersion('v26.0');
        $client = new MetaGraphClient(
            $http,
            new ConnectionCredentialProvider(new CredentialVault($encryption)),
            new ConnectionRateLimiter(new ArrayAdapter()),
        );

        self::assertSame('profile-picture-handle', $client->upload($connection, 'profile.png', 'PNGDATA', 'image/png'));
        self::assertSame(2, $requests);
    }

    public function testRedactsConfiguredSecretsFromGraphErrorsAndLogs(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'error' => [
                'message' => 'Expired credential access-token for app-secret',
                'type' => 'OAuthException',
                'code' => 190,
                'access_token' => 'access-token',
                'debug' => ['token' => 'verify-token'],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 400, 'response_headers' => ['content-type: application/json']]));
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->method('decrypt')->willReturnCallback(static fn (string $value): string => match ($value) {
            'sealed-secret' => 'app-secret',
            'sealed-access' => 'access-token',
            'sealed-verify' => 'verify-token',
        });
        $connection = (new MetaConnection(9))
            ->setAppId('1437146078305405')
            ->setEncryptedAppSecret('sealed-secret')
            ->setEncryptedAccessToken('sealed-access')
            ->setEncryptedVerifyToken('sealed-verify')
            ->setGraphVersion('v26.0');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Meta Graph API request failed.',
            self::callback(static function (array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return !str_contains($encoded, 'access-token')
                    && !str_contains($encoded, 'app-secret')
                    && !str_contains($encoded, 'verify-token');
            }),
        );
        $client = new MetaGraphClient(
            $http,
            new ConnectionCredentialProvider(new CredentialVault($encryption)),
            new ConnectionRateLimiter(new ArrayAdapter()),
            $logger,
        );

        try {
            $client->get($connection, 'me/accounts');
            self::fail('Expected Graph exception.');
        } catch (MetaGraphApiException $exception) {
            $details = json_encode($exception->details(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('access-token', $details);
            self::assertStringNotContainsString('app-secret', $details);
            self::assertStringNotContainsString('verify-token', $details);
            self::assertStringContainsString('[REDACTED]', $details);
        }
    }
}
