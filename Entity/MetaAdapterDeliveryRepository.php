<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

class MetaAdapterDeliveryRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'mad';
    }

    public function findDue(int $limit, \DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('mad')->andWhere('mad.status IN (:statuses)')->andWhere('mad.availableAt <= :now')->setParameter('statuses', ['pending', 'retry'])->setParameter('now', $now)->orderBy('mad.id', 'ASC')->setMaxResults($limit)->getQuery()->getResult();
    }
}
