<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;

class MetaWebhookEvent extends CommonEntity
{
    /**
     * @var int|null
     */
    private $id;
    private MetaConnection $connection;
    private string $eventKey = '';
    private string $objectType = '';
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];
    private string $status = 'received';
    private int $attempts = 0;
    private \DateTimeInterface $receivedAt;
    private ?\DateTimeInterface $processedAt = null;
    private ?string $lastError = null;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_webhook_events')
            ->setCustomRepositoryClass(MetaWebhookEventRepository::class)
            ->addUniqueConstraint(['connection_id', 'event_key'], 'meta_webhook_event_identity')
            ->addIndex(['status', 'received_at'], 'meta_webhook_processing');
        $builder->addId();
        $builder->createManyToOne('connection', MetaConnection::class)
            ->addJoinColumn('connection_id', 'id', false, false, 'CASCADE')
            ->build();
        $builder->addField('eventKey', Types::STRING, ['columnName' => 'event_key', 'length' => 191]);
        $builder->addField('objectType', Types::STRING, ['columnName' => 'object_type', 'length' => 64]);
        $builder->addField('payload', Types::JSON);
        $builder->addField('status', Types::STRING, ['length' => 32]);
        $builder->addField('attempts', Types::INTEGER);
        $builder->addField('receivedAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'received_at']);
        $builder->addNullableField('processedAt', Types::DATETIME_MUTABLE, 'processed_at');
        $builder->addNullableField('lastError', Types::TEXT, 'last_error');
    }

    public function getId(): ?int { return $this->id; }
    public function getConnection(): MetaConnection { return $this->connection; }
    public function setConnection(MetaConnection $value): self { $this->connection = $value; return $this; }
    public function getEventKey(): string { return $this->eventKey; }
    public function setEventKey(string $value): self { $this->eventKey = $value; return $this; }
    public function getObjectType(): string { return $this->objectType; }
    public function setObjectType(string $value): self { $this->objectType = $value; return $this; }
    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array { return $this->payload; }
    /**
     * @param array<string, mixed> $value
     */
    public function setPayload(array $value): self { $this->payload = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): self { $this->status = $value; return $this; }
    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $value): self { $this->attempts = $value; return $this; }
    public function getReceivedAt(): \DateTimeInterface { return $this->receivedAt; }
    public function getProcessedAt(): ?\DateTimeInterface { return $this->processedAt; }
    public function setProcessedAt(?\DateTimeInterface $value): self { $this->processedAt = $value; return $this; }
    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $value): self { $this->lastError = $value; return $this; }
}
