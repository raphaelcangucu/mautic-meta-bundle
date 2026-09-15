<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticMetaBundle\Application\Connection\EmbeddedSignupApi;
use MauticPlugin\MauticMetaBundle\Application\Connection\TechProviderManager;
use MauticPlugin\MauticMetaBundle\Application\Connection\WabaResolver;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Security\CredentialVault;

final class TechProviderAuthorizationTest extends MauticMysqlTestCase
{
    public function testPersistsEncryptedCustomerAndReauthorizesWithoutDuplicatingAssets(): void
    {
        $vault = self::getContainer()->get(CredentialVault::class);
        $root = (new MetaConnection())->setName('Provider')->setAppId('9988776655')->setIsPublished(true)->setEncryptedAccessToken($vault->seal('root-token'));
        $this->em->persist($root);
        $this->em->flush();
        $signup = $this->createMock(EmbeddedSignupApi::class);
        $signup->method('exchange')->willReturn(['token' => 'customer-token', 'expires' => time() + 3600]);
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturnCallback(static fn ($connection, $path) => match ($path) {
            '123456' => ['id' => '123456', 'name' => 'Customer WABA', 'owner_business_info' => ['id' => '234567']],
            '345678' => ['id' => '345678', 'display_phone_number' => '+5511999999999', 'verified_name' => 'Customer'],
            '123456/phone_numbers' => ['data' => [['id' => '345678']]],
            default => throw new \LogicException('Unexpected Graph request'),
        });
        $assets = $this->em->getRepository(MetaAsset::class);
        $manager = new TechProviderManager($this->em, $this->em->getRepository(MetaConnection::class), $assets, $signup, $vault, $graph, new WabaResolver($assets, $graph));
        $customer = $manager->connect($root, 'code1', '123456', '345678', '', 'Customer');
        self::assertSame('234567', $customer->getBusinessId());
        self::assertSame($root->getId(), $customer->getSettings()['provider_connection_id']);
        self::assertNotSame('customer-token', $customer->getEncryptedAccessToken());
        self::assertSame('customer-token', $vault->open($customer->getEncryptedAccessToken()));
        self::assertSame('root-token', $vault->open($root->getEncryptedAccessToken()));
        $again = $manager->connect($root, 'code2', '123456', '345678', '', 'Customer');
        self::assertSame($customer->getId(), $again->getId());
        self::assertCount(2, $assets->findBy(['connection' => $customer]));
    }
}
