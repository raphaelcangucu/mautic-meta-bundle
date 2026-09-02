<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Contact;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\DoNotContact as Dnc;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;

class IdentityManager
{
    public function __construct(
        private MetaContactIdentityRepository $identities,
        private EntityManagerInterface $entityManager,
        private DoNotContact $dnc,
    ) {}

    public function registerInteraction(MetaAsset $asset, string $externalId, ?string $username = null, ?Lead $contact = null): MetaContactIdentity
    {
        $identity = $this->identities->findForAssetAndExternalId($asset, $externalId) ?? (new MetaContactIdentity())->setAsset($asset)->setExternalId($externalId);
        if (null !== $contact) { $identity->setContact($contact); }
        if (null !== $username && '' !== trim($username)) { $identity->setUsername($username); }
        if (AssetType::WhatsAppPhoneNumber === $asset->getType()) { $identity->setPhoneNumber($externalId); }
        $identity->setLastInteractionAt(new \DateTimeImmutable());
        $this->entityManager->persist($identity);

        return $identity;
    }

    public function optIn(MetaContactIdentity $identity, string $source): void
    {
        $identity->setConsentStatus(ConsentStatus::OptedIn)->setConsentSource($source)->setConsentedAt(new \DateTimeImmutable())->setOptedOutAt(null);
        $contact = $identity->getContact();
        if ($contact instanceof Lead) { $this->dnc->removeDncForContact($contact, 'whatsapp', false, Dnc::UNSUBSCRIBED); }
        $this->entityManager->persist($identity);
    }

    public function optOut(MetaContactIdentity $identity, string $source): void
    {
        $identity->setConsentStatus(ConsentStatus::OptedOut)->setConsentSource($source)->setOptedOutAt(new \DateTimeImmutable());
        $contact = $identity->getContact();
        if ($contact instanceof Lead) { $this->dnc->addDncForContact($contact, 'whatsapp', Dnc::UNSUBSCRIBED, $source, false); }
        $this->entityManager->persist($identity);
    }

    public function assertCanSend(MetaAsset $asset, string $externalId, ?Lead $contact): void
    {
        $this->assertChannelContactable($contact, 'whatsapp');
        $identity = $this->identities->findForAssetAndExternalId($asset, $externalId);
        if ($identity instanceof MetaContactIdentity && $contact instanceof Lead && $identity->getContact()?->getId() !== $contact->getId()) {
            throw new \DomainException('WhatsApp identity is linked to a different Mautic contact.');
        }
        if ($identity?->getConsentStatus() === ConsentStatus::OptedOut) {
            throw new \DomainException('Contact opted out of WhatsApp messages.');
        }
        if (
            $identity?->getOptedOutAt() instanceof \DateTimeInterface
            && (!$identity->getConsentedAt() instanceof \DateTimeInterface || $identity->getOptedOutAt() >= $identity->getConsentedAt())
        ) {
            throw new \DomainException('A later WhatsApp opt-out remains in force.');
        }
        $requiresOptIn = (bool) ($asset->getSettings()['require_opt_in'] ?? true);
        if ($requiresOptIn && $identity?->getConsentStatus() !== ConsentStatus::OptedIn) {
            throw new \DomainException('Explicit WhatsApp opt-in is required for this phone number.');
        }
    }

    public function assertChannelContactable(?Lead $contact, string $channel): void
    {
        if ($contact instanceof Lead && Dnc::IS_CONTACTABLE !== $this->dnc->isContactable($contact, $channel)) {
            throw new \DomainException(sprintf('Contact is marked Do Not Contact for %s.', ucfirst($channel)));
        }
    }

    public function changeConsent(MetaContactIdentity $identity, ConsentStatus $status, string $source): void
    {
        match ($status) {
            ConsentStatus::OptedIn => $this->optIn($identity, $source),
            ConsentStatus::OptedOut => $this->optOut($identity, $source),
            ConsentStatus::Unknown => $identity->setConsentStatus($status)->setConsentSource($source)->setConsentedAt(null)->setOptedOutAt(null),
        };
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }

    public function associate(MetaContactIdentity $identity, ?Lead $contact): void
    {
        $identity->setContact($contact);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }
}
