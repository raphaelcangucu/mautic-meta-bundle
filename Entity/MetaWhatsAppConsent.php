<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\LeadBundle\Entity\Lead;

class MetaWhatsAppConsent extends CommonEntity
{
    private $id;
    private MetaAsset $asset;
    private MetaContactIdentity $identity;
    private Lead $contact;
    private string $externalSubmissionId = '';
    private string $phoneNumber = '';
    private \DateTimeInterface $consentAt;
    private string $business = '';
    private ?string $locale = null;
    private string $purpose = '';
    private string $source = '';
    private string $consentText = '';
    private string $consentVersion = '';
    private ?string $pageUrl = null;
    private string $evidenceHash = '';
    private string $status = 'accepted';
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->consentAt = $this->dateAdded = $this->dateModified = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_whatsapp_consents')
            ->setCustomRepositoryClass(MetaWhatsAppConsentRepository::class)
            ->addUniqueConstraint(['asset_id', 'external_submission_id'], 'meta_consent_submission_identity')
            ->addIndex(['contact_id', 'consent_at'], 'meta_consent_contact_date');
        $builder->addId();
        $builder->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $builder->createManyToOne('identity', MetaContactIdentity::class)->addJoinColumn('identity_id', 'id', false, false, 'CASCADE')->build();
        $builder->createManyToOne('contact', Lead::class)->addJoinColumn('contact_id', 'id', false, false, 'CASCADE')->build();
        $builder->addField('externalSubmissionId', Types::STRING, ['columnName' => 'external_submission_id', 'length' => 191]);
        $builder->addField('phoneNumber', Types::STRING, ['columnName' => 'phone_number', 'length' => 32]);
        $builder->addField('consentAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'consent_at']);
        $builder->addField('business', Types::STRING, ['length' => 191]);
        $builder->addNullableField('locale', Types::STRING);
        $builder->addField('purpose', Types::STRING, ['length' => 191]);
        $builder->addField('source', Types::STRING, ['length' => 191]);
        $builder->addField('consentText', Types::TEXT, ['columnName' => 'consent_text']);
        $builder->addField('consentVersion', Types::STRING, ['columnName' => 'consent_version', 'length' => 191]);
        $builder->addNullableField('pageUrl', Types::TEXT, 'page_url');
        $builder->addField('evidenceHash', Types::STRING, ['columnName' => 'evidence_hash', 'length' => 64]);
        $builder->addField('status', Types::STRING, ['length' => 32]);
        $builder->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $builder->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
    }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): MetaAsset { return $this->asset; }
    public function setAsset(MetaAsset $value): self { $this->asset = $value; return $this; }
    public function getIdentity(): MetaContactIdentity { return $this->identity; }
    public function setIdentity(MetaContactIdentity $value): self { $this->identity = $value; return $this; }
    public function getContact(): Lead { return $this->contact; }
    public function setContact(Lead $value): self { $this->contact = $value; return $this; }
    public function getExternalSubmissionId(): string { return $this->externalSubmissionId; }
    public function setExternalSubmissionId(string $value): self { $this->externalSubmissionId = $value; return $this; }
    public function getPhoneNumber(): string { return $this->phoneNumber; }
    public function setPhoneNumber(string $value): self { $this->phoneNumber = $value; return $this; }
    public function getConsentAt(): \DateTimeInterface { return $this->consentAt; }
    public function setConsentAt(\DateTimeInterface $value): self { $this->consentAt = $value; return $this; }
    public function getEvidenceHash(): string { return $this->evidenceHash; }
    public function setEvidenceHash(string $value): self { $this->evidenceHash = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): self { $this->status = $value; return $this; }

    /**
     * @param array<string, mixed> $evidence
     */
    public function setEvidence(array $evidence): self
    {
        $this->business = (string) $evidence['business'];
        $this->locale = null === ($evidence['locale'] ?? null) ? null : (string) $evidence['locale'];
        $this->purpose = (string) $evidence['purpose'];
        $this->source = (string) $evidence['source'];
        $this->consentText = (string) $evidence['consentText'];
        $this->consentVersion = (string) $evidence['consentVersion'];
        $this->pageUrl = null === ($evidence['pageUrl'] ?? null) ? null : (string) $evidence['pageUrl'];
        $this->dateModified = new \DateTimeImmutable();

        return $this;
    }
}
