<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\LeadBundle\Entity\Lead;

class MetaMessage extends CommonEntity
{
    /**
     * @var int|null
     */
    private $id;
    private MetaAsset $asset;
    private ?Lead $contact = null;
    private ?MetaConversation $conversation = null;
    private ?string $externalId = null;
    private string $channel = '';
    private string $direction = 'outbound';
    private string $messageType = '';
    private string $recipient = '';
    private string $status = 'pending';
    /**
     * @var array<string, mixed>
     */
    private array $payload = [];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $response = null;
    private ?string $error = null;
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
        $builder->setTable('meta_messages')
            ->setCustomRepositoryClass(MetaMessageRepository::class)
            ->addUniqueConstraint(['external_id'], 'meta_message_external_id')
            ->addIndex(['channel', 'status', 'date_added'], 'meta_message_status')
            ->addIndex(['recipient'], 'meta_message_recipient');
        $builder->addId();
        $builder->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $builder->createManyToOne('contact', Lead::class)->addJoinColumn('contact_id', 'id', true, false, 'SET NULL')->build();
        $builder->createManyToOne('conversation', MetaConversation::class)->addJoinColumn('conversation_id', 'id', true, false, 'SET NULL')->build();
        $builder->addNullableField('externalId', Types::STRING, 'external_id');
        $builder->addField('channel', Types::STRING, ['length' => 32]);
        $builder->addField('direction', Types::STRING, ['length' => 16]);
        $builder->addField('messageType', Types::STRING, ['columnName' => 'message_type', 'length' => 32]);
        $builder->addField('recipient', Types::STRING, ['length' => 191]);
        $builder->addField('status', Types::STRING, ['length' => 32]);
        $builder->addField('payload', Types::JSON);
        $builder->addNullableField('response', Types::JSON);
        $builder->addNullableField('error', Types::TEXT);
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

        return $this;
    }

    public function getContact(): ?Lead
    {
        return $this->contact;
    }

    public function setContact(?Lead $value): self
    {
        $this->contact = $value;

        return $this;
    }

    public function getConversation(): ?MetaConversation
    {
        return $this->conversation;
    }

    public function setConversation(?MetaConversation $value): self
    {
        $this->conversation = $value;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $value): self
    {
        $this->externalId = $value;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $value): self
    {
        $this->channel = $value;

        return $this;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $value): self
    {
        $this->direction = $value;

        return $this;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function setMessageType(string $value): self
    {
        $this->messageType = $value;

        return $this;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function setRecipient(string $value): self
    {
        $this->recipient = $value;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $value): self
    {
        $this->status = $value;
        $this->dateModified = new \DateTime();

        return $this;
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

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponse(): ?array
    {
        return $this->response;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    public function setResponse(?array $value): self
    {
        $this->response = $value;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $value): self
    {
        $this->error = $value;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getDateModified(): ?\DateTimeInterface
    {
        return $this->dateModified;
    }
}
