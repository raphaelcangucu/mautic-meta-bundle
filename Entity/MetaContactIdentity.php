<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticMetaBundle\Domain\ConsentStatus;

class MetaContactIdentity extends CommonEntity
{
    /**
     * @var int|null
     */
    private $id;
    private MetaAsset $asset;
    private ?Lead $contact = null;
    private string $externalId = '';
    private ?string $username = null;
    private ?string $phoneNumber = null;
    private string $consentStatus = 'unknown';
    private ?string $consentSource = null;
    private ?\DateTimeInterface $consentedAt = null;
    private ?\DateTimeInterface $optedOutAt = null;
    private ?\DateTimeInterface $lastInteractionAt = null;
    private \DateTimeInterface $dateAdded;
    private ?\DateTimeInterface $dateModified = null;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_contact_identities')
            ->setCustomRepositoryClass(MetaContactIdentityRepository::class)
            ->addUniqueConstraint(['asset_id', 'external_id'], 'meta_identity_asset_external')
            ->addIndex(['contact_id', 'asset_id'], 'meta_identity_contact_asset')
            ->addIndex(['consent_status'], 'meta_identity_consent');
        $builder->addId();
        $builder->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $builder->createManyToOne('contact', Lead::class)->addJoinColumn('contact_id', 'id', true, false, 'SET NULL')->build();
        $builder->addField('externalId', Types::STRING, ['columnName' => 'external_id', 'length' => 191]);
        $builder->addNullableField('username', Types::STRING);
        $builder->addNullableField('phoneNumber', Types::STRING, 'phone_number');
        $builder->addField('consentStatus', Types::STRING, ['columnName' => 'consent_status', 'length' => 24]);
        $builder->addNullableField('consentSource', Types::STRING, 'consent_source');
        $builder->addNullableField('consentedAt', Types::DATETIME_IMMUTABLE, 'consented_at');
        $builder->addNullableField('optedOutAt', Types::DATETIME_IMMUTABLE, 'opted_out_at');
        $builder->addNullableField('lastInteractionAt', Types::DATETIME_IMMUTABLE, 'last_interaction_at');
        $builder->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $builder->addNullableField('dateModified', Types::DATETIME_MUTABLE, 'date_modified');
    }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): MetaAsset { return $this->asset; }
    public function setAsset(MetaAsset $value): self { $this->asset = $value; return $this->touch(); }
    public function getContact(): ?Lead { return $this->contact; }
    public function setContact(?Lead $value): self { $this->contact = $value; return $this->touch(); }
    public function getExternalId(): string { return $this->externalId; }
    public function setExternalId(string $value): self { $this->externalId = trim($value); return $this->touch(); }
    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $value): self { $this->username = null === $value ? null : trim($value); return $this->touch(); }
    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(?string $value): self { $this->phoneNumber = null === $value ? null : trim($value); return $this->touch(); }
    public function getConsentStatus(): ConsentStatus { return ConsentStatus::from($this->consentStatus); }
    public function setConsentStatus(ConsentStatus $value): self { $this->consentStatus = $value->value; return $this->touch(); }
    public function getConsentSource(): ?string { return $this->consentSource; }
    public function setConsentSource(?string $value): self { $this->consentSource = $value; return $this->touch(); }
    public function getConsentedAt(): ?\DateTimeInterface { return $this->consentedAt; }
    public function setConsentedAt(?\DateTimeInterface $value): self { $this->consentedAt = $value; return $this->touch(); }
    public function getOptedOutAt(): ?\DateTimeInterface { return $this->optedOutAt; }
    public function setOptedOutAt(?\DateTimeInterface $value): self { $this->optedOutAt = $value; return $this->touch(); }
    public function getLastInteractionAt(): ?\DateTimeInterface { return $this->lastInteractionAt; }
    public function setLastInteractionAt(?\DateTimeInterface $value): self { $this->lastInteractionAt = $value; return $this->touch(); }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): ?\DateTimeInterface { return $this->dateModified; }

    private function touch(): self { $this->dateModified = new \DateTime(); return $this; }
}
