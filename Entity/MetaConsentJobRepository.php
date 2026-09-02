<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaConsentJob>
 */
final class MetaConsentJobRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'mcj';
    }

    /**
     * @return list<MetaConsentJob>
     */
    public function findDue(int $limit, \DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status IN (:statuses)')
            ->andWhere('j.availableAt <= :now')
            ->setParameter('statuses', ['pending', 'retry'])
            ->setParameter('now', $now)
            ->orderBy('j.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
