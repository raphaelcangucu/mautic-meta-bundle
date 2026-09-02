<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaTrustedContactImport>
 */
final class MetaTrustedContactImportRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'mtci';
    }
}
