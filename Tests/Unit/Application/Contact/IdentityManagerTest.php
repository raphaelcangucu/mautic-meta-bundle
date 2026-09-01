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
        $manager->assertCanSend($this->asset(false), '5511999999999', new Lead());
    }

    public function testRequiresExplicitOptInByDefault(): void
    {
        [$manager, $repository] = $this->manager();
        $repository->method('findForAssetAndExternalId')->willReturn(null);

        $this->expectExceptionMessage('Explicit WhatsApp opt-in');
        $manager->assertCanSend($this->asset(), '5511999999999', null);
    }

    public function testAllowsOptedInIdentity(): void
    {
        [$manager, $repository] = $this->manager();
        $identity = (new MetaContactIdentity())->setConsentStatus(ConsentStatus::OptedIn);
        $repository->method('findForAssetAndExternalId')->willReturn($identity);

        $manager->assertCanSend($this->asset(), '5511999999999', null);
        self::assertTrue(true);
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

        return [new IdentityManager($repository, $entityManager, $dnc), $repository, $dnc, $entityManager];
    }

    private function asset(bool $requireOptIn = true): MetaAsset
    {
        return (new MetaAsset())
            ->setConnection(new MetaConnection())
            ->setType(AssetType::WhatsAppPhoneNumber)
            ->setSettings(['require_opt_in' => $requireOptIn]);
    }
}
