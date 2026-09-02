<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Application\Connection;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaAssetRepository;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;

final class AssetManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MetaAssetRepository $repository,
    ) {
    }

    public function create(MetaConnection $connection, array $data): MetaAsset
    {
        $type = AssetType::from((string) ($data['type'] ?? ''));
        $externalId = trim((string) ($data['external_id'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $externalId || '' === $name) {
            throw new \InvalidArgumentException('Asset name and Meta ID are required.');
        }
        if ($this->repository->findOneBy(['connection' => $connection, 'externalId' => $externalId, 'type' => $type->value]) instanceof MetaAsset) {
            throw new \InvalidArgumentException('This Meta asset is already registered for the connection.');
        }
        if (true === ($data['is_default'] ?? false)) {
            foreach ($this->repository->findBy(['connection' => $connection, 'type' => $type->value]) as $existing) {
                $existing->setIsDefault(false);
            }
        }
        $asset = (new MetaAsset())
            ->setConnection($connection)
            ->setName($name)
            ->setExternalId($externalId)
            ->setType($type)
            ->setUsername($this->nullable($data['username'] ?? null))
            ->setPhoneNumber($this->nullable($data['phone_number'] ?? null))
            ->setIsDefault((bool) ($data['is_default'] ?? false))
            ->setStatus('active')
            ->setIsPublished(true)
            ->setSettings([
                'default_region' => strtoupper((string) ($data['default_region'] ?? 'BR')),
                'contact_match_field' => $this->nullable($data['contact_match_field'] ?? null),
                'require_opt_in' => (bool) ($data['require_opt_in'] ?? true),
                ...$this->safetySettings($type, $data),
            ]);
        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return $asset;
    }

    public function update(MetaAsset $asset, array $data): MetaAsset
    {
        $type = AssetType::from((string) ($data['type'] ?? ''));
        $externalId = trim((string) ($data['external_id'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $externalId || '' === $name) {
            throw new \InvalidArgumentException('Asset name and Meta ID are required.');
        }
        $duplicate = $this->repository->findOneBy(['connection' => $asset->getConnection(), 'externalId' => $externalId, 'type' => $type->value]);
        if ($duplicate instanceof MetaAsset && $duplicate->getId() !== $asset->getId()) {
            throw new \InvalidArgumentException('This Meta asset is already registered for the connection.');
        }
        if (true === ($data['is_default'] ?? false)) {
            foreach ($this->repository->findBy(['connection' => $asset->getConnection(), 'type' => $type->value]) as $existing) {
                if ($existing->getId() !== $asset->getId()) {
                    $existing->setIsDefault(false);
                }
                $this->entityManager->persist($existing);
            }
        }
        $asset->setName($name)
            ->setExternalId($externalId)
            ->setType($type)
            ->setUsername($this->nullable($data['username'] ?? null))
            ->setPhoneNumber($this->nullable($data['phone_number'] ?? null))
            ->setIsDefault((bool) ($data['is_default'] ?? false))
            ->setSettings([
                'default_region' => strtoupper((string) ($data['default_region'] ?? 'BR')),
                'contact_match_field' => $this->nullable($data['contact_match_field'] ?? null),
                'require_opt_in' => (bool) ($data['require_opt_in'] ?? true),
                ...$this->safetySettings($type, $data),
            ]);
        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return $asset;
    }

    public function remove(MetaAsset $asset): void
    {
        $this->entityManager->remove($asset);
        $this->entityManager->flush();
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    /**
     * @return array<string, int|bool>
     */
    private function safetySettings(AssetType $type, array $data): array
    {
        $whatsApp = AssetType::WhatsAppPhoneNumber === $type;
        $dailyMaximum = $whatsApp ? 250 : 50;
        $hourlyMaximum = $whatsApp ? 50 : 20;
        $cooldownMinimum = $whatsApp ? 60 : 300;

        return [
            'anti_spam_enabled' => true,
            'daily_send_limit' => max(1, min($dailyMaximum, (int) ($data['daily_send_limit'] ?? $dailyMaximum))),
            'hourly_send_limit' => max(1, min($hourlyMaximum, (int) ($data['hourly_send_limit'] ?? $hourlyMaximum))),
            'recipient_daily_limit' => max(1, min(3, (int) ($data['recipient_daily_limit'] ?? 3))),
            'recipient_cooldown_seconds' => max($cooldownMinimum, min(86400, (int) ($data['recipient_cooldown_seconds'] ?? $cooldownMinimum))),
            'enforce_customer_service_window' => $whatsApp,
        ];
    }
}
