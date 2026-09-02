<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\UserBundle\Entity\User;

class MetaConsentSyncRun extends CommonEntity
{
    private $id;
    private MetaAsset $asset;
    private ?User $operator = null;
    private string $status = 'waiting';
    private array $criteria = [];
    private array $counts = [];
    private array $rejections = [];
    private int $checkpoint = 0;
    private int $batchSize = 100;
    private ?string $idempotencyKey = null;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $dateModified;
    private ?\DateTimeInterface $completedAt = null;

    public function __construct()
    {
        $this->dateAdded = $this->dateModified = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_consent_sync_runs')
            ->setCustomRepositoryClass(MetaConsentSyncRunRepository::class)
            ->addUniqueConstraint(['idempotency_key'], 'meta_consent_sync_idempotency')
            ->addIndex(['status', 'date_added'], 'meta_consent_sync_status');
        $builder->addId();
        $builder->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $builder->createManyToOne('operator', User::class)->addJoinColumn('operator_id', 'id', true, false, 'SET NULL')->build();
        $builder->addField('status', Types::STRING, ['length' => 32]);
        $builder->addField('criteria', Types::JSON);
        $builder->addField('counts', Types::JSON);
        $builder->addField('rejections', Types::JSON);
        $builder->addField('checkpoint', Types::INTEGER);
        $builder->addField('batchSize', Types::INTEGER, ['columnName' => 'batch_size']);
        $builder->addNullableField('idempotencyKey', Types::STRING, 'idempotency_key');
        $builder->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $builder->addField('dateModified', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_modified']);
        $builder->addNullableField('completedAt', Types::DATETIME_IMMUTABLE, 'completed_at');
    }

    public function getId(): ?int { return $this->id; }
    public function getAsset(): MetaAsset { return $this->asset; }
    public function setAsset(MetaAsset $value): self { $this->asset = $value; return $this; }
    public function getOperator(): ?User { return $this->operator; }
    public function setOperator(?User $value): self { $this->operator = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): self { $this->status = $value; return $this->touch(); }
    public function getCriteria(): array { return $this->criteria; }
    public function setCriteria(array $value): self { $this->criteria = $value; return $this; }
    public function getCounts(): array { return $this->counts; }
    public function setCounts(array $value): self { $this->counts = $value; return $this->touch(); }
    public function getRejections(): array { return $this->rejections; }
    public function setRejections(array $value): self { $this->rejections = $value; return $this->touch(); }
    public function getCheckpoint(): int { return $this->checkpoint; }
    public function setCheckpoint(int $value): self { $this->checkpoint = $value; return $this->touch(); }
    public function getBatchSize(): int { return $this->batchSize; }
    public function setBatchSize(int $value): self { $this->batchSize = min(500, max(1, $value)); return $this; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function setIdempotencyKey(?string $value): self { $this->idempotencyKey = $value; return $this; }
    public function getDateAdded(): \DateTimeInterface { return $this->dateAdded; }
    public function getDateModified(): \DateTimeInterface { return $this->dateModified; }
    public function getCompletedAt(): ?\DateTimeInterface { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeInterface $value): self { $this->completedAt = $value; return $this->touch(); }

    private function touch(): self
    {
        $this->dateModified = new \DateTimeImmutable();

        return $this;
    }
}
