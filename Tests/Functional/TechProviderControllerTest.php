<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;

final class TechProviderControllerTest extends MauticMysqlTestCase
{
    public function testAdminCanRenderProviderAndLegacyConnections(): void
    {
        $connection = (new MetaConnection())->setName('Legacy preserved')->setAppId('123456789')->setIsPublished(true);
        $this->em->persist($connection);
        $this->em->flush();
        $this->client->request('GET', '/s/meta/tech-provider');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Legacy preserved', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Empresas associadas', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Conexões anteriores', $this->client->getResponse()->getContent());
    }

    public function testPostWithoutCsrfIsDenied(): void
    {
        $this->client->request('POST', '/s/meta/tech-provider', ['action' => 'connect', 'code' => 'not-real']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testConfigurationIsPersistedBeforeLaunchingSignup(): void
    {
        $connection = (new MetaConnection())->setName('Provider test')->setAppId('1437146078305405')->setIsPublished(true);
        $this->em->persist($connection);
        $this->em->flush();
        $id = $connection->getId();

        $this->client->request('GET', '/s/meta/tech-provider');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/name="_token" value="([^"]+)"/', (string) $this->client->getResponse()->getContent());
        preg_match('/name="_token" value="([^"]+)"/', (string) $this->client->getResponse()->getContent(), $match);

        $this->client->request('POST', '/s/meta/tech-provider', [
            '_token' => $match[1],
            'action' => 'configure',
            'provider' => $id,
            'config_id' => '1110492338184018',
        ]);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $saved = $this->em->getRepository(MetaConnection::class)->find($id);
        self::assertSame('1110492338184018', $saved?->getSettings()['embedded_signup_config_id'] ?? null);
    }

    public function testDifferentBusinessesCanShareAppWithoutChangingLegacyRecord(): void
    {
        foreach (['', 'customerA', 'customerB'] as $business) {
            $this->em->persist((new MetaConnection())->setName('Tenant '.$business)->setAppId('987654321')->setBusinessId($business));
        }
        $this->em->flush();
        self::assertCount(3, $this->em->getRepository(MetaConnection::class)->findBy(['appId' => '987654321']));
    }

    public function testWhatsAppAssetShowsDedicatedDiagnostic(): void
    {
        $connection = (new MetaConnection())->setName('Provider for phone')->setAppId('2468013579')->setIsPublished(true);
        $phone = (new MetaAsset())->setConnection($connection)->setType(AssetType::WhatsAppPhoneNumber)
            ->setExternalId('1236834432856918')->setName('Codificar test')->setPhoneNumber('+55 31 7544-1171')
            ->setIsPublished(true);
        $this->em->persist($connection);
        $this->em->persist($phone);
        $this->em->flush();

        $this->client->request('GET', '/s/meta/assets/'.$phone->getId().'/edit');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Diagnóstico do número', $body);
        self::assertStringContainsString('Diagnosticar sem enviar', $body);
        self::assertStringContainsString('name="return_asset" value="'.$phone->getId().'"', $body);
    }

    public function testAssociatedCompanyAndItsPhoneAreListedAndPausePersists(): void
    {
        $provider = (new MetaConnection())->setName('Provider')->setAppId('1234509876')->setIsPublished(true);
        $customer = (new MetaConnection())->setName('Codificar')->setAppId('1234509876')
            ->setBusinessId('846359018709148')->setStatus('active')->setIsPublished(true);
        $phone = (new MetaAsset())->setConnection($customer)->setType(AssetType::WhatsAppPhoneNumber)
            ->setExternalId('1236834432856918')->setName('Codificar WhatsApp')
            ->setPhoneNumber('+55 31 7544-1171')->setIsPublished(true);
        $this->em->persist($provider);
        $this->em->persist($customer);
        $this->em->persist($phone);
        $this->em->flush();

        $this->client->request('GET', '/s/meta/tech-provider');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Codificar', $body);
        self::assertStringContainsString('+55 31 7544-1171', $body);
        self::assertStringContainsString('/s/meta/assets/'.$phone->getId().'/edit', $body);
        preg_match('/name="_token" value="([^"]+)"/', $body, $match);

        $this->client->request('POST', '/s/meta/tech-provider', [
            '_token' => $match[1], 'action' => 'pause', 'provider' => $customer->getId(),
        ]);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame('paused', $this->em->getRepository(MetaConnection::class)->find($customer->getId())?->getStatus());
    }
}
