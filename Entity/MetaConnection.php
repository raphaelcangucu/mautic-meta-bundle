<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;

class MetaConnection extends FormEntity
{
    /**
     * @var int|null
     */
    private $id;
    private string $name = '';
    private ?string $description = null;
    private string $appId = '';
    private string $encryptedAppSecret = '';
    private string $encryptedAccessToken = '';
    private string $encryptedVerifyToken = '';
    private string $graphVersion = 'v26.0';
    private string $status = 'pending';
    private ?\DateTimeInterface $tokenExpiresAt = null;
    /**
     * @var array<string, mixed>
     */
    private array $settings = [];

    /**
     * @var Collection<int, MetaAsset>
     */
    private Collection $assets;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
        $this->assets = new ArrayCollection();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('meta_connections')
            ->setCustomRepositoryClass(MetaConnectionRepository::class)
            ->addUniqueConstraint(['app_id'], 'meta_connection_app_id')
            ->addIndex(['status'], 'meta_connection_status');
        $builder->addIdColumns();
        $builder->addField('appId', Types::STRING, ['columnName' => 'app_id', 'length' => 191]);
        $builder->addField('encryptedAppSecret', Types::TEXT, ['columnName' => 'encrypted_app_secret']);
        $builder->addField('encryptedAccessToken', Types::TEXT, ['columnName' => 'encrypted_access_token']);
        $builder->addField('encryptedVerifyToken', Types::TEXT, ['columnName' => 'encrypted_verify_token']);
        $builder->addField('graphVersion', Types::STRING, ['columnName' => 'graph_version', 'length' => 16]);
        $builder->addField('status', Types::STRING, ['length' => 32]);
        $builder->addNullableField('tokenExpiresAt', Types::DATETIME_MUTABLE, 'token_expires_at');
        $builder->addNullableField('settings', Types::JSON);
        $builder->createOneToMany('assets', MetaAsset::class)
            ->mappedBy('connection')
            ->cascadeAll()
            ->orphanRemoval()
            ->build();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $value): self
    {
        $this->description = $value;

        return $this;
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    public function setAppId(string $appId): self
    {
        $this->appId = trim($appId);

        return $this;
    }

    public function getEncryptedAppSecret(): string
    {
        return $this->encryptedAppSecret;
    }

    public function setEncryptedAppSecret(string $value): self
    {
        $this->encryptedAppSecret = $value;

        return $this;
    }

    public function getEncryptedAccessToken(): string
    {
        return $this->encryptedAccessToken;
    }

    public function setEncryptedAccessToken(string $value): self
    {
        $this->encryptedAccessToken = $value;

        return $this;
    }

    public function getEncryptedVerifyToken(): string
    {
        return $this->encryptedVerifyToken;
    }

    public function setEncryptedVerifyToken(string $value): self
    {
        $this->encryptedVerifyToken = $value;

        return $this;
    }

    public function getGraphVersion(): string
    {
        return $this->graphVersion;
    }

    public function setGraphVersion(string $value): self
    {
        $this->graphVersion = trim($value);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeInterface $value): self
    {
        $this->tokenExpiresAt = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * @return Collection<int, MetaAsset>
     */
    public function getAssets(): Collection
    {
        return $this->assets;
    }

    public function addAsset(MetaAsset $asset): self
    {
        if (!$this->assets->contains($asset)) {
            $this->assets->add($asset);
            $asset->setConnection($this);
        }

return $this;
    }

    public function removeAsset(MetaAsset $asset): self
    {
        $this->assets->removeElement($asset);

        return $this;
    }
}
