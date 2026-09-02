<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaConsentSyncRun>
 */
final class MetaConsentSyncRunRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'mcsr';
    }

    public function nextQueued(): ?MetaConsentSyncRun
    {
        $run = $this->createQueryBuilder('run')
            ->where('run.status IN (:statuses)')
            ->setParameter('statuses', ['syncing', 'failed'])
            ->orderBy('run.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $run instanceof MetaConsentSyncRun ? $run : null;
    }
}
