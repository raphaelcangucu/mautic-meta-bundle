<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;

class WhatsAppTemplate extends CommonEntity
{
    /**
     * @var int|null
     */
    private $id;
    private MetaAsset $businessAccount;
    private ?string $externalId = null;
    private string $name = '';
    private string $language = '';
    private string $category = '';
    private string $status = 'PENDING';
    private ?string $qualityScore = null;
    /**
     * @var list<array<string, mixed>>
     */
    private array $components = [];
    private ?string $rejectedReason = null;
    private \DateTimeInterface $lastSyncedAt;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
        $this->lastSyncedAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_whatsapp_templates')
            ->setCustomRepositoryClass(WhatsAppTemplateRepository::class)
            ->addUniqueConstraint(['business_account_id', 'template_name', 'language'], 'meta_whatsapp_template_identity')
            ->addIndex(['status', 'category'], 'meta_whatsapp_template_status');
        $builder->addId();
        $builder->createManyToOne('businessAccount', MetaAsset::class)->addJoinColumn('business_account_id', 'id', false, false, 'CASCADE')->build();
        $builder->addNullableField('externalId', Types::STRING, 'external_id');
        $builder->addNamedField('name', Types::STRING, 'template_name');
        $builder->addField('language', Types::STRING);
        $builder->addField('category', Types::STRING);
        $builder->addField('status', Types::STRING);
        $builder->addNullableField('qualityScore', Types::STRING, 'quality_score');
        $builder->addField('components', Types::JSON);
        $builder->addNullableField('rejectedReason', Types::TEXT, 'rejected_reason');
        $builder->addField('lastSyncedAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'last_synced_at']);
    }

    public function getId(): ?int { return $this->id; }
    public function getBusinessAccount(): MetaAsset { return $this->businessAccount; }
    public function setBusinessAccount(MetaAsset $value): self { $this->businessAccount = $value; return $this; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $value): self { $this->externalId = $value; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $value): self { $this->name = $value; return $this; }
    public function getLanguage(): string { return $this->language; }
    public function setLanguage(string $value): self { $this->language = $value; return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $value): self { $this->category = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): self { $this->status = $value; return $this; }
    public function getQualityScore(): ?string { return $this->qualityScore; }
    public function setQualityScore(?string $value): self { $this->qualityScore = $value; return $this; }
    /**
     * @return list<array<string, mixed>>
     */
    public function getComponents(): array { return $this->components; }
    /**
     * @param list<array<string, mixed>> $value
     */
    public function setComponents(array $value): self { $this->components = $value; return $this; }
    public function getRejectedReason(): ?string { return $this->rejectedReason; }
    public function setRejectedReason(?string $value): self { $this->rejectedReason = $value; return $this; }
    public function getLastSyncedAt(): \DateTimeInterface { return $this->lastSyncedAt; }
    public function touch(): self { $this->lastSyncedAt = new \DateTimeImmutable(); return $this; }
}
