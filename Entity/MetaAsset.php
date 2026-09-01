<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;

class MetaAsset extends FormEntity
{
    /**
     * @var int|null
     */
    private $id;
    private MetaConnection $connection;
    private string $externalId = '';
    private string $type = '';
    private string $name = '';
    private ?string $description = null;
    private ?string $username = null;
    private ?string $phoneNumber = null;
    private string $status = 'pending';
    private bool $isDefault = false;
    /**
     * @var array<string, mixed>
     */
    private array $capabilities = [];

    /**
     * @var array<string, mixed>
     */
    private array $settings = [];

    public function __construct(?int $id = null)
    {
        $this->id = $id;
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_assets')
            ->setCustomRepositoryClass(MetaAssetRepository::class)
            ->addUniqueConstraint(['connection_id', 'external_id', 'asset_type'], 'meta_asset_identity')
            ->addIndex(['asset_type', 'status'], 'meta_asset_type_status');
        $builder->addIdColumns();
        $builder->createManyToOne('connection', MetaConnection::class)
            ->inversedBy('assets')
            ->addJoinColumn('connection_id', 'id', false, false, 'CASCADE')
            ->build();
        $builder->addField('externalId', Types::STRING, ['columnName' => 'external_id', 'length' => 191]);
        $builder->addField('type', Types::STRING, ['columnName' => 'asset_type', 'length' => 64]);
        $builder->addNullableField('username', Types::STRING);
        $builder->addNullableField('phoneNumber', Types::STRING, 'phone_number');
        $builder->addField('status', Types::STRING, ['length' => 32]);
        $builder->addField('isDefault', Types::BOOLEAN, ['columnName' => 'is_default']);
        $builder->addNullableField('capabilities', Types::JSON);
        $builder->addNullableField('settings', Types::JSON);
    }

    public function getId(): ?int { return $this->id; }
    public function getConnection(): MetaConnection { return $this->connection; }
    public function setConnection(MetaConnection $connection): self { $this->connection = $connection; return $this; }
    public function getExternalId(): string { return $this->externalId; }
    public function setExternalId(string $value): self { $this->externalId = trim($value); return $this; }
    public function getType(): AssetType { return AssetType::from($this->type); }
    public function setType(AssetType $type): self { $this->type = $type->value; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $value): self { $this->description = $value; return $this; }
    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $value): self { $this->username = $value; return $this; }
    public function getPhoneNumber(): ?string { return $this->phoneNumber; }
    public function setPhoneNumber(?string $value): self { $this->phoneNumber = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $value): self { $this->isDefault = $value; return $this; }
    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array { return $this->capabilities; }
    /**
     * @param array<string, mixed> $value
     */
    public function setCapabilities(array $value): self { $this->capabilities = $value; return $this; }
    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array { return $this->settings; }
    /**
     * @param array<string, mixed> $value
     */
    public function setSettings(array $value): self { $this->settings = $value; return $this; }
}
