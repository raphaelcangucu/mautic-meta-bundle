<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\UserBundle\Entity\User;

class MetaConversation extends CommonEntity
{
    private $id;
    private MetaAsset $asset;
    private ?Lead $contact = null;
    private ?User $assignee = null;
    private string $channel = '';
    private string $recipient = '';
    private string $status = 'open';
    private int $unreadCount = 0;
    private \DateTimeInterface $dateAdded;
    private \DateTimeInterface $lastMessageAt;
    private ?\DateTimeInterface $lastInboundAt = null;

    public function __construct()
    {
        $this->dateAdded = $this->lastMessageAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $b = new ClassMetadataBuilder($metadata);
        $b->setTable('meta_conversations')->setCustomRepositoryClass(MetaConversationRepository::class)->addUniqueConstraint(['asset_id', 'channel', 'recipient'], 'meta_conversation_identity')->addIndex(['status', 'last_message_at'], 'meta_conversation_inbox');
        $b->addId();
        $b->createManyToOne('asset', MetaAsset::class)->addJoinColumn('asset_id', 'id', false, false, 'CASCADE')->build();
        $b->createManyToOne('contact', Lead::class)->addJoinColumn('contact_id', 'id', true, false, 'SET NULL')->build();
        $b->createManyToOne('assignee', User::class)->addJoinColumn('assignee_id', 'id', true, false, 'SET NULL')->build();
        $b->addField('channel', Types::STRING, ['length' => 32]);
        $b->addField('recipient', Types::STRING, ['length' => 191]);
        $b->addField('status', Types::STRING, ['length' => 32]);
        $b->addField('unreadCount', Types::INTEGER, ['columnName' => 'unread_count']);
        $b->addField('dateAdded', Types::DATETIME_IMMUTABLE, ['columnName' => 'date_added']);
        $b->addField('lastMessageAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'last_message_at']);
        $b->addNullableField('lastInboundAt', Types::DATETIME_IMMUTABLE, 'last_inbound_at');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAsset(): MetaAsset
    {
        return $this->asset;
    }

    public function setAsset(MetaAsset $v): self
    {
        $this->asset = $v;

        return $this;
    }

    public function getContact(): ?Lead
    {
        return $this->contact;
    }

    public function setContact(?Lead $v): self
    {
        $this->contact = $v;

        return $this;
    }

    public function getAssignee(): ?User
    {
        return $this->assignee;
    }

    public function setAssignee(?User $v): self
    {
        $this->assignee = $v;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $v): self
    {
        $this->channel = $v;

        return $this;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function setRecipient(string $v): self
    {
        $this->recipient = $v;

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

    public function getUnreadCount(): int
    {
        return $this->unreadCount;
    }

    public function setUnreadCount(int $v): self
    {
        $this->unreadCount = max(0, $v);

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getLastMessageAt(): \DateTimeInterface
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(\DateTimeInterface $v): self
    {
        $this->lastMessageAt = $v;

        return $this;
    }

    public function getLastInboundAt(): ?\DateTimeInterface
    {
        return $this->lastInboundAt;
    }

    public function setLastInboundAt(?\DateTimeInterface $v): self
    {
        $this->lastInboundAt = $v;

        return $this;
    }
}
