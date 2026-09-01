<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;

/**
 * @extends CommonRepository<MetaAsset>
 */
final class MetaAssetRepository extends CommonRepository
{
    /**
     * @return list<MetaAsset>
     */
    public function findEnabledByType(AssetType $type): array
    {
        return $this->findBy(['type' => $type->value, 'isPublished' => true, 'status' => 'active'], ['name' => 'ASC']);
    }

    public function getTableAlias(): string { return 'ma'; }
}
