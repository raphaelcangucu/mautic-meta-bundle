<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentity;
use MauticPlugin\MauticMetaBundle\Entity\MetaContactIdentityRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaTrustedContactImport;
use MauticPlugin\MauticMetaBundle\Entity\MetaTrustedContactImportRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaWhatsAppConsent;
use MauticPlugin\MauticMetaBundle\Entity\MetaWhatsAppConsentRepository;

final class TrustedApiWaitlistConsentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private LeadModel $leads,
        private MetaAssetRepository $assets,
        private MetaContactIdentityRepository $identities,
        private MetaWhatsAppConsentRepository $consents,
        private MetaTrustedContactImportRepository $imports,
        private PhoneNormalizer $phones,
    ) {
    }

    public function markApiImport(Lead $contact, ?string $externalSubmissionId = null): MetaTrustedContactImport
    {
        $marker = $this->imports->findOneBy(['contact' => $contact]);
        if (!$marker instanceof MetaTrustedContactImport) {
            $marker = (new MetaTrustedContactImport())->setContact($contact);
        }
        if (null !== $externalSubmissionId && '' !== trim($externalSubmissionId)) {
            $marker->setExternalSubmissionId(trim($externalSubmissionId));
        }
        $this->entityManager->persist($marker);
        $this->entityManager->flush();

        return $marker;
    }

    public function isWaitlist(Lead $contact, string $classification = 'Waitlist'): bool
    {
        return 1 === (int) $this->connection->fetchOne(
            'SELECT EXISTS(SELECT 1 FROM leads l LEFT JOIN stages s ON s.id=l.stage_id LEFT JOIN lead_lists_leads lll ON lll.lead_id=l.id AND lll.manually_removed=0 LEFT JOIN lead_lists ll ON ll.id=lll.leadlist_id AND ll.deleted=0 WHERE l.id=:id AND (LOWER(s.name)=LOWER(:name) OR LOWER(ll.name)=LOWER(:name)))',
            ['id' => $contact->getId(), 'name' => $classification],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function register(
        Lead $contact,
        MetaAsset $asset,
        string $basis,
        \DateTimeInterface $attestedAt,
        ?User $attestedBy = null,
        ?int $syncJobId = null,
        ?string $externalSubmissionId = null,
        bool $dryRun = false,
    ): array {
        $candidates = array_filter(['phone' => trim((string) $contact->getPhone()), 'mobile' => trim((string) $contact->getMobile())]);
        if ([] === $candidates) {
            return $this->result($contact, 'rejected', 'Contact has no phone or mobile.', null, 'none', $dryRun);
        }
        $digits = null;
        $phoneField = (string) array_key_first($candidates);
        $lastError = null;
        foreach ($candidates as $field => $candidate) {
            try {
                $settings = $asset->getSettings();
                $digits = $this->phones->normalizeImported(
                    $candidate,
                    (string) ($settings['trusted_import_default_region'] ?? $settings['default_region'] ?? 'BR'),
                    (bool) ($settings['trusted_import_convert_legacy_br_mobile'] ?? true),
                );
                $phoneField = $field;
                break;
            } catch (\Throwable $exception) {
                $lastError = $exception;
            }
        }
        if (null === $digits) {
            return $this->result($contact, 'rejected', 'Phone and mobile cannot be normalized to E.164: '.$lastError?->getMessage(), null, $phoneField, $dryRun);
        }

        if ($this->hasWhatsAppDnc($contact)) {
            return $this->result($contact, 'opted_out', 'Existing WhatsApp DNC was preserved.', '+'.$digits, $phoneField, $dryRun);
        }
        if ($this->phoneBelongsToAnotherContact($contact, $digits)) {
            return $this->result($contact, 'conflict', 'Normalized phone is shared by multiple Mautic contacts.', '+'.$digits, $phoneField, $dryRun);
        }

        $identity = $this->identities->findForAssetAndExternalId($asset, $digits);
        if ($identity instanceof MetaContactIdentity && $identity->getContact()?->getId() !== $contact->getId()) {
            return $this->result($contact, 'conflict', 'Phone is already associated with another contact.', '+'.$digits, $phoneField, $dryRun);
        }
        if ($identity?->getConsentStatus() === ConsentStatus::OptedOut || $identity?->getOptedOutAt() instanceof \DateTimeInterface) {
            return $this->result($contact, 'opted_out', 'Existing WhatsApp opt-out was preserved.', '+'.$digits, $phoneField, $dryRun);
        }

        $submissionId = $externalSubmissionId ?: 'mautic-contact-'.$contact->getId();
        $existingAudit = $this->consents->findSubmission($asset, $submissionId);
        if ($existingAudit instanceof MetaWhatsAppConsent) {
            if ($existingAudit->getContact()->getId() !== $contact->getId()) {
                return $this->result($contact, 'conflict', 'external_submission_id belongs to another contact.', '+'.$digits, $phoneField, $dryRun, $identity, $existingAudit);
            }
            if ($existingAudit->getPhoneNumber() === '+'.$digits) {
                return $this->result($contact, 'already_registered', null, '+'.$digits, $phoneField, $dryRun, $identity, $existingAudit);
            }
            if (!$identity instanceof MetaContactIdentity) {
                $identity = $existingAudit->getIdentity();
            }
        }
        if ($dryRun) {
            return $this->result($contact, $identity instanceof MetaContactIdentity ? 'updated' : 'created', null, '+'.$digits, $phoneField, true, $identity);
        }

        $lock = 'meta_trusted_waitlist_'.hash('sha256', $asset->getId().':'.$contact->getId());
        if (1 !== (int) $this->connection->fetchOne('SELECT GET_LOCK(:lockName, 5)', ['lockName' => $lock])) {
            return $this->result($contact, 'conflict', 'Contact synchronization is already running.', '+'.$digits, $phoneField);
        }
        try {
            return $this->entityManager->wrapInTransaction(function () use ($contact, $asset, $basis, $attestedAt, $attestedBy, $syncJobId, $submissionId, $digits, $phoneField, $identity): array {
                $storedIdentity = $identity ?? (new MetaContactIdentity())->setAsset($asset)->setExternalId($digits);
                $storedIdentity->setExternalId($digits)
                    ->setArchivedAt(null)
                    ->setContact($contact)
                    ->setPhoneNumber('+'.$digits)
                    ->setConsentStatus(ConsentStatus::OptedIn)
                    ->setConsentSource('mautic_api_waitlist')
                    ->setConsentedAt($attestedAt);
                $this->entityManager->persist($storedIdentity);

                $audit = $this->consents->findSubmission($asset, $submissionId) ?? (new MetaWhatsAppConsent())
                    ->setAsset($asset)
                    ->setIdentity($storedIdentity)
                    ->setContact($contact)
                    ->setExternalSubmissionId($submissionId);
                $audit
                    ->setIdentity($storedIdentity)
                    ->setPhoneNumber('+'.$digits)
                    ->setConsentAt($attestedAt)
                    ->setEvidenceHash(hash('sha256', $asset->getId().':'.$contact->getId().':'.$submissionId.':'.$basis))
                    ->setStatus('accepted')
                    ->setTrustedAttestation('mautic_api_waitlist', $basis)
                    ->setAttestedBy($attestedBy)
                    ->setAttestedAt($attestedAt)
                    ->setContactCreatedAt($contact->getDateAdded() instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($contact->getDateAdded()) : null)
                    ->setSyncJobId($syncJobId)
                    ->setScope('stage_or_segment_waitlist');
                $this->entityManager->persist($audit);
                $this->entityManager->flush();

                return $this->result($contact, $identity instanceof MetaContactIdentity ? 'updated' : 'created', null, '+'.$digits, $phoneField, false, $storedIdentity, $audit);
            });
        } finally {
            $this->connection->executeQuery('SELECT RELEASE_LOCK(:lockName)', ['lockName' => $lock]);
        }
    }

    public function defaultAsset(): ?MetaAsset
    {
        $asset = $this->assets->findOneBy(['type' => AssetType::WhatsAppPhoneNumber->value, 'isDefault' => true, 'status' => 'active']);

        return $asset instanceof MetaAsset ? $asset : null;
    }

    public function wasApiImported(Lead $contact): bool
    {
        return $this->apiImport($contact) instanceof MetaTrustedContactImport;
    }

    public function apiImport(Lead $contact): ?MetaTrustedContactImport
    {
        $marker = $this->imports->findOneBy(['contact' => $contact]);

        return $marker instanceof MetaTrustedContactImport ? $marker : null;
    }

    /**
     * @return array{items: array<int, array{contact: Lead, apiImported: bool}>, nextCheckpoint: int, hasMore: bool}
     */
    public function findWaitlistPage(string $classification, int $afterId, int $limit): array
    {
        $limit = min(500, max(1, $limit));
        $sql = 'SELECT l.id FROM leads l LEFT JOIN stages s ON s.id=l.stage_id LEFT JOIN lead_lists_leads lll ON lll.lead_id=l.id AND lll.manually_removed=0 LEFT JOIN lead_lists ll ON ll.id=lll.leadlist_id AND ll.deleted=0 WHERE l.id>:afterId AND (LOWER(s.name)=LOWER(:name) OR LOWER(ll.name)=LOWER(:name)) GROUP BY l.id ORDER BY l.id ASC LIMIT '.$limit;
        $ids = array_map('intval', $this->connection->fetchFirstColumn($sql, ['afterId' => $afterId, 'name' => $classification]));
        $items = [];
        foreach ($ids as $id) {
            $contact = $this->leads->getEntity($id);
            if ($contact instanceof Lead) {
                $items[] = ['contact' => $contact, 'apiImported' => $this->wasApiImported($contact)];
            }
        }

        return [
            'items' => $items,
            'nextCheckpoint' => [] === $ids ? $afterId : max($ids),
            'hasMore' => count($ids) === $limit,
        ];
    }

    private function hasWhatsAppDnc(Lead $contact): bool
    {
        return 1 === (int) $this->connection->fetchOne("SELECT EXISTS(SELECT 1 FROM lead_donotcontact WHERE lead_id=:id AND channel='whatsapp')", ['id' => $contact->getId()]);
    }

    private function phoneBelongsToAnotherContact(Lead $contact, string $digits): bool
    {
        $normalize = static fn (string $column): string => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')";
        $sql = sprintf('SELECT EXISTS(SELECT 1 FROM leads WHERE id<>:id AND (%s=:phone OR %s=:phone))', $normalize('phone'), $normalize('mobile'));

        return 1 === (int) $this->connection->fetchOne($sql, ['id' => $contact->getId(), 'phone' => $digits]);
    }

    private function result(Lead $contact, string $status, ?string $reason, ?string $phone, string $phoneField, bool $dryRun = false, ?MetaContactIdentity $identity = null, ?MetaWhatsAppConsent $audit = null): array
    {
        return ['contactId' => $contact->getId(), 'eligibilitySource' => 'mautic_api_waitlist', 'consentBasis' => 'admin_attested_trusted_api_import', 'phoneField' => $phoneField, 'phone' => $phone, 'status' => $status, 'reason' => $reason, 'dryRun' => $dryRun, 'identityId' => $identity?->getId(), 'consentId' => $audit?->getId()];
    }
}
