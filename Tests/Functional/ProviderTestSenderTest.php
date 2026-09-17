<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticMetaBundle\Application\Connection\ProviderTestSender;
use MauticPlugin\MauticMetaBundle\Application\Connection\WabaResolver;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\Queue\OutboundQueue;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\WhatsAppTemplate;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;

final class ProviderTestSenderTest extends MauticMysqlTestCase
{
    private function fixtures(ConsentStatus $consent): array
    {
        $connection = (new MetaConnection())->setName('Test provider')->setAppId('1122334455')->setStatus('active')->setIsPublished(true);
        $waba = (new MetaAsset())->setName('WABA')->setExternalId('waba')->setConnection($connection)->setType(AssetType::WhatsAppBusinessAccount);
        $phone = (new MetaAsset())->setName('Phone')->setExternalId('phone')->setConnection($connection)->setType(AssetType::WhatsAppPhoneNumber)->setStatus('active')->setIsPublished(true)->setSettings(['waba_id' => 'waba']);
        $template = (new WhatsAppTemplate())->setBusinessAccount($waba)->setName('welcome')->setLanguage('pt_BR')->setStatus('APPROVED');
        $identity = (new MetaContactIdentity())->setAsset($phone)->setExternalId('5511999999999')->setConsentStatus($consent);
        foreach ([$connection, $waba, $phone, $template, $identity] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->method('get')->willReturn(['data' => [['id' => 'phone']]]);
        $graph->expects(self::never())->method('post');
        $sender = new ProviderTestSender(new WabaResolver($this->em->getRepository(MetaAsset::class), $graph), self::getContainer()->get(IdentityManager::class), self::getContainer()->get(OutboundQueue::class), new PhoneNormalizer(), $this->em->getRepository(MetaContactIdentity::class));

        return [$sender, $phone, $template];
    }

    public function testOneAttemptIsIdempotentAndDoesNotSendSynchronously(): void
    {
        [$sender, $phone, $template] = $this->fixtures(ConsentStatus::OptedIn);
        $key = str_repeat('a', 64);
        $first = $sender->enqueue($phone, $template, '+5511999999999', [], $key);
        $second = $sender->enqueue($phone, $template, '+5511999999999', [], $key);
        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $first->getMaxAttempts());
        self::assertSame('pending', $first->getStatus());
    }

    public function testOptOutIsNotOverriddenForTesting(): void
    {
        [$sender, $phone, $template] = $this->fixtures(ConsentStatus::OptedOut);
        $this->expectException(\DomainException::class);
        $sender->enqueue($phone, $template, '+5511999999999', [], str_repeat('b', 64));
    }

    public function testPendingTemplateIsNotEnqueued(): void
    {
        [$sender, $phone, $template] = $this->fixtures(ConsentStatus::OptedIn);
        $template->setStatus('PENDING');
        $this->expectException(\DomainException::class);
        $sender->enqueue($phone, $template, '+5511999999999', [], str_repeat('c', 64));
    }
}
