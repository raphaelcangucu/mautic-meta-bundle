<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaContactIdentity>
 */
class MetaContactIdentityRepository extends CommonRepository
{
    public function getTableAlias(): string { return 'mci'; }

    public function findForAssetAndExternalId(MetaAsset $asset, string $externalId): ?MetaContactIdentity
    {
        $identity = $this->findOneBy(['asset' => $asset, 'externalId' => $externalId]);

        return $identity instanceof MetaContactIdentity ? $identity : null;
    }
}
