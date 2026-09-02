<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;

class MetaAdapterDelivery extends CommonEntity
{
    private $id;
    private MetaConnection $connection;
    private ?MetaMessage $message = null;
    private string $adapterName = '';
    private string $url = '';
    private string $sealedSecret = '';
    private string $eventName = '';
    private string $eventId = '';
    private array $payload = [];
    private string $status = 'pending';
    private int $attempts = 0;
    private int $maxAttempts = 5;
    private int $timeout = 5;
    private \DateTimeInterface $availableAt;
    private \DateTimeInterface $dateAdded;
    private ?\DateTimeInterface $completedAt = null;
    private ?string $lastError = null;

    public function __construct()
    {
        $this->availableAt = $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $m): void
    {
        $b = new ClassMetadataBuilder($m);
        $b->setTable('meta_adapter_deliveries')->setCustomRepositoryClass(MetaAdapterDeliveryRepository::class)->addUniqueConstraint(['event_id', 'adapter_name'], 'meta_adapter_delivery_identity')->addIndex(['status', 'available_at'], 'meta_adapter_delivery_due');
        $b->addId();
        $b->createManyToOne('connection', MetaConnection::class)->addJoinColumn('connection_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('message', MetaMessage::class)->addJoinColumn('message_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('adapterName', Types::STRING, ['columnName' => 'adapter_name']);
        $b->addField('url', Types::TEXT);
        $b->addField('sealedSecret', Types::TEXT, ['columnName' => 'sealed_secret']);
        $b->addField('eventName', Types::STRING, ['columnName' => 'event_name']);
        $b->addField('eventId', Types::STRING, ['columnName' => 'event_id']);
        $b->addField('payload', Types::JSON);
        $b->addField('status', Types::STRING);
        $b->addField('attempts', Types::INTEGER);
        $b->addField('maxAttempts', Types::INTEGER, ['columnName' => 'max_attempts']);
        $b->addField('timeout', Types::INTEGER);
        $b->addField('availableAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'available_at']);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addNullableField('completedAt', Types::DATETIME_IMMUTABLE, 'completed_at');
        $b->addNullableField('lastError', Types::TEXT, 'last_error');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConnection(): MetaConnection
    {
        return $this->connection;
    }

    public function setConnection(MetaConnection $v): self
    {
        $this->connection = $v;

        return $this;
    }

    public function getMessage(): ?MetaMessage
    {
        return $this->message;
    }

    public function setMessage(?MetaMessage $v): self
    {
        $this->message = $v;

        return $this;
    }

    public function getAdapterName(): string
    {
        return $this->adapterName;
    }

    public function setAdapterName(string $v): self
    {
        $this->adapterName = $v;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $v): self
    {
        $this->url = $v;

        return $this;
    }

    public function getSealedSecret(): string
    {
        return $this->sealedSecret;
    }

    public function setSealedSecret(string $v): self
    {
        $this->sealedSecret = $v;

        return $this;
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function setEventName(string $v): self
    {
        $this->eventName = $v;

        return $this;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function setEventId(string $v): self
    {
        $this->eventId = $v;

        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $v): self
    {
        $this->payload = $v;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $v): self
    {
        $this->status = $v;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $v): self
    {
        $this->attempts = $v;

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $v): self
    {
        $this->maxAttempts = $v;

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $v): self
    {
        $this->timeout = min(15, max(2, $v));

        return $this;
    }

    public function getAvailableAt(): \DateTimeInterface
    {
        return $this->availableAt;
    }

    public function setAvailableAt(\DateTimeInterface $v): self
    {
        $this->availableAt = $v;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $v): self
    {
        $this->completedAt = $v;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $v): self
    {
        $this->lastError = $v;

        return $this;
    }
}
