<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\CommonEntity;
use Mautic\LeadBundle\Entity\Lead;

class MetaTrustedContactImport extends CommonEntity
{
    private $id;
    private Lead $contact;
    private ?string $externalSubmissionId = null;
    private \DateTimeInterface $receivedAt;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_trusted_contact_imports')
            ->setCustomRepositoryClass(MetaTrustedContactImportRepository::class)
            ->addUniqueConstraint(['contact_id'], 'meta_trusted_import_contact');
        $builder->addId();
        $builder->createManyToOne('contact', Lead::class)->addJoinColumn('contact_id', 'id', false, false, 'CASCADE')->build();
        $builder->addNullableField('externalSubmissionId', Types::STRING, 'external_submission_id');
        $builder->addField('receivedAt', Types::DATETIME_IMMUTABLE, ['columnName' => 'received_at']);
    }

    public function getId(): ?int { return $this->id; }
    public function getContact(): Lead { return $this->contact; }
    public function setContact(Lead $contact): self { $this->contact = $contact; return $this; }
    public function getExternalSubmissionId(): ?string { return $this->externalSubmissionId; }
    public function setExternalSubmissionId(?string $value): self { $this->externalSubmissionId = $value; return $this; }
    public function getReceivedAt(): \DateTimeInterface { return $this->receivedAt; }
}
