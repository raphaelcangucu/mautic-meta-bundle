<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaConnection>
 */
final class MetaConnectionRepository extends CommonRepository
{
    public function getTableAlias(): string { return 'mc'; }
}
