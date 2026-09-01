<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaOutboundJob>
 */
class MetaOutboundJobRepository extends CommonRepository
{
    public function getTableAlias(): string { return 'moj'; }

    /**
     * @return list<MetaOutboundJob>
     */
    public function findDue(int $limit, \DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('moj')->andWhere('moj.status IN (:statuses)')->andWhere('moj.availableAt <= :now')
            ->setParameter('statuses', ['pending', 'retry'])->setParameter('now', $now)->orderBy('moj.availableAt', 'ASC')->addOrderBy('moj.id', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))->getQuery()->getResult();
    }

    /**
     * @return list<MetaOutboundJob>
     */
    public function findStalled(\DateTimeInterface $before): array
    {
        return $this->createQueryBuilder('moj')->andWhere('moj.status = :status')->andWhere('moj.lockedAt < :before')->setParameter('status', 'processing')->setParameter('before', $before)->getQuery()->getResult();
    }
}
