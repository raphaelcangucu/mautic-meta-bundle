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

    /**
     * @return array{items: list<MetaContactIdentity>, total: int}
     */
    public function findPage(string $search, ?int $assetId, ?string $consentStatus, int $offset, int $limit): array
    {
        $query = $this->createQueryBuilder('mci')
            ->leftJoin('mci.asset', 'asset')->addSelect('asset')
            ->leftJoin('mci.contact', 'contact')->addSelect('contact')
            ->orderBy('mci.lastInteractionAt', 'DESC')->addOrderBy('mci.id', 'DESC');
        if ('' !== $search) {
            $query->andWhere("LOWER(mci.externalId) LIKE :search OR LOWER(COALESCE(mci.phoneNumber, '')) LIKE :search OR LOWER(COALESCE(mci.username, '')) LIKE :search OR LOWER(asset.name) LIKE :search OR LOWER(COALESCE(contact.email, '')) LIKE :search OR LOWER(CONCAT(COALESCE(contact.firstname, ''), ' ', COALESCE(contact.lastname, ''))) LIKE :search")
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }
        if (null !== $assetId) {
            $query->andWhere('asset.id = :assetId')->setParameter('assetId', $assetId);
        }
        if (null !== $consentStatus) {
            $query->andWhere('mci.consentStatus = :consentStatus')->setParameter('consentStatus', $consentStatus);
        }
        $count = clone $query;
        $total = (int) $count->select('COUNT(DISTINCT mci.id)')->getQuery()->getSingleScalarResult();
        $items = $query->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => array_values($items), 'total' => $total];
    }
}
