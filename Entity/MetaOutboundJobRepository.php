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

    public function claim(MetaOutboundJob $job, \DateTimeImmutable $now): bool
    {
        if (null === $job->getId()) {
            return false;
        }

        $updated = $this->getEntityManager()->createQueryBuilder()
            ->update(MetaOutboundJob::class, 'moj')
            ->set('moj.status', ':processing')
            ->set('moj.lockedAt', ':now')
            ->set('moj.attempts', 'moj.attempts + 1')
            ->where('moj.id = :id')
            ->andWhere('moj.status IN (:statuses)')
            ->setParameters([
                'processing' => 'processing',
                'now'        => $now,
                'id'         => $job->getId(),
                'statuses'   => ['pending', 'retry'],
            ])
            ->getQuery()
            ->execute();

        if (1 !== $updated) {
            return false;
        }

        $this->getEntityManager()->refresh($job);

        return true;
    }

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
