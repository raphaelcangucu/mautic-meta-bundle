<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Connection;

use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\MauticMetaBundle\Application\Connection\ConnectionCredentialProvider;
use MauticPlugin\MauticMetaBundle\Application\Connection\EmbeddedSignupApi;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class EmbeddedSignupApiTest extends TestCase
{
    private function api(array $debug): EmbeddedSignupApi
    {
        $encryption = $this->createMock(EncryptionHelper::class);
        $encryption->method('decrypt')->willReturn('test-secret');

        return new EmbeddedSignupApi(new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'test-customer-token'])),
            new MockResponse(json_encode(['data' => $debug])),
        ]), new ConnectionCredentialProvider(new CredentialVault($encryption)));
    }

    private function valid(): array
    {
        return ['is_valid' => true, 'app_id' => '12345', 'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'], 'expires_at' => time() + 3600];
    }

    public function testValidatesCorrectApplicationAndPermissions(): void
    {
        $result = $this->api($this->valid())->exchange((new MetaConnection())->setAppId('12345'), 'test-code', '');
        self::assertSame('test-customer-token', $result['token']);
        self::assertGreaterThan(time(), $result['expires']);
    }

    public function testWrongAppCannotAuthorizeCustomer(): void
    {
        $debug = $this->valid();
        $debug['app_id'] = 'different';
        $this->expectException(\DomainException::class);
        $this->api($debug)->exchange((new MetaConnection())->setAppId('12345'), 'test-code', '');
    }

    public function testMissingMessagingScopeIsRejected(): void
    {
        $debug = $this->valid();
        $debug['scopes'] = ['whatsapp_business_management'];
        $this->expectException(\DomainException::class);
        $this->api($debug)->exchange((new MetaConnection())->setAppId('12345'), 'test-code', '');
    }

    public function testExpiredTokenIsRejectedWithoutLeakingSecrets(): void
    {
        $debug = $this->valid();
        $debug['expires_at'] = 1;
        try {
            $this->api($debug)->exchange((new MetaConnection())->setAppId('12345'), 'test-code', '');
            self::fail('Expired token accepted');
        } catch (\DomainException $error) {
            self::assertStringNotContainsString('test-customer-token', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
