<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Tests\Unit\Application\Contact;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\DoNotContact as Dnc;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;
use PHPUnit\Framework\TestCase;

final class IdentityManagerTest extends TestCase
{
    public function testRejectsContactOnMauticDnc(): void
    {
        [$manager, $repository, $dnc] = $this->manager();
        $repository->method('findForAssetAndExternalId')->willReturn(null);
        $dnc->method('isContactable')->willReturn(Dnc::UNSUBSCRIBED);

        $this->expectExceptionMessage('Do Not Contact');
        $manager->assertCanSend($this->asset(), '5511999999999', new Lead());
    }

    public function testAllowsUnknownIdentityWhenNotOnDnc(): void
    {
        [$manager, $repository] = $this->manager();
        $repository->method('findForAssetAndExternalId')->willReturn(null);

        $manager->assertCanSend($this->asset(), '5511999999999', null);
        self::assertTrue(true);
    }

    public function testRejectsOptedOutIdentity(): void
    {
        [$manager, $repository] = $this->manager();
        $identity = (new MetaContactIdentity())->setConsentStatus(ConsentStatus::OptedOut);
        $repository->method('findForAssetAndExternalId')->willReturn($identity);

        $this->expectExceptionMessage('opted out');
        $manager->assertCanSend($this->asset(), '5511999999999', null);
    }

    public function testOptOutWritesIdentityAndDnc(): void
    {
        [$manager, , $dnc, $entityManager] = $this->manager();
        $contact = new Lead();
        $identity = (new MetaContactIdentity())->setContact($contact);
        $dnc->expects(self::once())->method('addDncForContact')->with($contact, 'whatsapp', Dnc::UNSUBSCRIBED, 'whatsapp_keyword', false);
        $entityManager->expects(self::once())->method('persist')->with($identity);

        $manager->optOut($identity, 'whatsapp_keyword');

        self::assertSame(ConsentStatus::OptedOut, $identity->getConsentStatus());
        self::assertNotNull($identity->getOptedOutAt());
    }

    /**
     * @return array{IdentityManager, MetaContactIdentityRepository&\PHPUnit\Framework\MockObject\MockObject, DoNotContact&\PHPUnit\Framework\MockObject\MockObject, EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function manager(): array
    {
        $repository = $this->createMock(MetaContactIdentityRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $dnc = $this->createMock(DoNotContact::class);
        $dnc->method('isContactable')->willReturn(Dnc::IS_CONTACTABLE);

        return [new IdentityManager($repository, $entityManager, $dnc), $repository, $dnc, $entityManager];
    }

    private function asset(): MetaAsset
    {
        return (new MetaAsset())
            ->setConnection(new MetaConnection())
            ->setType(AssetType::WhatsAppPhoneNumber);
    }
}
