<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\LeadBundle\Entity\Lead;

class MetaOutboundJob extends CommonEntity
{
    /**
     * @var int|null
     */
    private $id;
    private MetaAsset $asset;
    private ?Lead $contact = null;
    private string $operation = '';
    private ?string $idempotencyKey = null;
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];
    private string $status = 'pending';
    private int $attempts = 0;
    private int $maxAttempts = 5;
    private ?\DateTimeInterface $availableAt = null;
    private ?\DateTimeInterface $lockedAt = null;
    private ?\DateTimeInterface $completedAt = null;
    private ?string $lastError = null;
    private ?int $messageLogId = null;
    private \DateTimeInterface $dateAdded;
    private ?\DateTimeInterface $dateModified = null;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
        $this->dateAdded = new \DateTimeImmutable();
        $this->availableAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_outbound_jobs')->setCustomRepositoryClass(MetaOutboundJobRepository::class)
            ->addUniqueConstraint(['idempotency_key'], 'meta_job_idempotency')
            ->addIndex(['status', 'available_at'], 'meta_job_due')
            ->addIndex(['contact_id', 'date_added'], 'meta_job_contact');
        $builder->addId();
        $builder->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $builder->createManyToOne('contact', Lead::class)->addJoinColumn('contact_id', 'id', true, false, 'SET NULL')->build();
        $builder->addField('operation', Types::STRING, ['length' => 64]);
        $builder->addNullableField('idempotencyKey', Types::STRING, 'idempotency_key');
        $builder->addField('payload', Types::JSON);
        $builder->addField('status', Types::STRING, ['length' => 24]);
        $builder->addField('attempts', Types::INTEGER);
        $builder->addField('maxAttempts', Types::INTEGER, ['columnName' => 'max_attempts']);
        $builder->addNullableField('availableAt', Types::DATETIME_IMMUTABLE, 'available_at');
        $builder->addNullableField('lockedAt', Types::DATETIME_IMMUTABLE, 'locked_at');
        $builder->addNullableField('completedAt', Types::DATETIME_IMMUTABLE, 'completed_at');
        $builder->addNullableField('lastError', Types::TEXT, 'last_error');
        $builder->addNullableField('messageLogId', Types::INTEGER, 'message_log_id');
        $builder->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $builder->addNullableField('dateModified', Types::DATETIME_MUTABLE, 'date_modified');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): MetaAsset
    {
        return $this->asset;
    }

    public function setAsset(MetaAsset $value): self
    {
        $this->asset = $value;

        return $this->touch();
    }

    public function getContact(): ?Lead
    {
        return $this->contact;
    }

    public function setContact(?Lead $value): self
    {
        $this->contact = $value;

        return $this->touch();
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function setOperation(string $value): self
    {
        $this->operation = $value;

        return $this->touch();
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $value): self
    {
        $this->idempotencyKey = $value;

        return $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function setPayload(array $value): self
    {
        $this->payload = $value;

        return $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $value): self
    {
        $this->status = $value;

        return $this->touch();
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $value): self
    {
        $this->attempts = $value;

        return $this->touch();
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $value): self
    {
        $this->maxAttempts = max(1, $value);

        return $this->touch();
    }

    public function getAvailableAt(): ?\DateTimeInterface
    {
        return $this->availableAt;
    }

    public function setAvailableAt(?\DateTimeInterface $value): self
    {
        $this->availableAt = $value;

        return $this->touch();
    }

    public function getLockedAt(): ?\DateTimeInterface
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeInterface $value): self
    {
        $this->lockedAt = $value;

        return $this->touch();
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $value): self
    {
        $this->completedAt = $value;

        return $this->touch();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $value): self
    {
        $this->lastError = $value;

        return $this->touch();
    }

    public function getMessageLogId(): ?int
    {
        return $this->messageLogId;
    }

    public function setMessageLogId(?int $value): self
    {
        $this->messageLogId = $value;

        return $this->touch();
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getDateModified(): ?\DateTimeInterface
    {
        return $this->dateModified;
    }

    private function touch(): self
    {
        $this->dateModified = new \DateTime();

        return $this;
    }
}
