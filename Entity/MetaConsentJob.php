<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;

class MetaConsentJob extends CommonEntity
{
    private $id;
    private MetaAsset $asset;
    private string $externalSubmissionId = '';
    private array $payload = [];
    private string $status = 'pending';
    private int $attempts = 0;
    private int $maxAttempts = 5;
    private \DateTimeInterface $availableAt;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;
    private ?string $lastError = null;
    private ?array $result = null;

    public function __construct()
    {
        $this->availableAt = $this->dateAdded = $this->dateModified = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_consent_jobs')
            ->setCustomRepositoryClass(MetaConsentJobRepository::class)
            ->addUniqueConstraint(['asset_id', 'external_submission_id'], 'meta_consent_job_submission')
            ->addIndex(['status', 'available_at'], 'meta_consent_job_due');
        $builder->addId();
        $builder->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $builder->addField('externalSubmissionId', Types::STRING, ['columnName' => 'external_submission_id', 'length' => 191]);
        $builder->addField('payload', Types::JSON);
        $builder->addField('status', Types::STRING, ['length' => 32]);
        $builder->addField('attempts', Types::INTEGER);
        $builder->addField('maxAttempts', Types::INTEGER, ['columnName' => 'max_attempts']);
        $builder->addField('availableAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'available_at']);
        $builder->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $builder->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
        $builder->addNullableField('lastError', Types::TEXT, 'last_error');
        $builder->addNullableField('result', Types::JSON);
    }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): MetaAsset { return $this->asset; }
    public function setAsset(MetaAsset $value): self { $this->asset = $value; return $this; }
    public function getExternalSubmissionId(): string { return $this->externalSubmissionId; }
    public function setExternalSubmissionId(string $value): self { $this->externalSubmissionId = $value; return $this; }
    public function getPayload(): array { return $this->payload; }
    public function setPayload(array $value): self { $this->payload = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): self { $this->status = $value; return $this->touch(); }
    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $value): self { $this->attempts = $value; return $this->touch(); }
    public function getMaxAttempts(): int { return $this->maxAttempts; }
    public function setMaxAttempts(int $value): self { $this->maxAttempts = $value; return $this; }
    public function getAvailableAt(): \DateTimeInterface { return $this->availableAt; }
    public function setAvailableAt(\DateTimeInterface $value): self { $this->availableAt = $value; return $this->touch(); }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $value): self { $this->lastError = $value; return $this->touch(); }
    public function getResult(): ?array { return $this->result; }
    public function setResult(?array $value): self { $this->result = $value; return $this->touch(); }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }

    private function touch(): self
    {
        $this->dateModified = new \DateTimeImmutable();

        return $this;
    }
}
