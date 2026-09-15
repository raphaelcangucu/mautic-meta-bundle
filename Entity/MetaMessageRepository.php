<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaMessage>
 */
class MetaMessageRepository extends CommonRepository
{
    public function getTableAlias(): string { return 'mm'; }

    public function findLatestForAssetDirection(MetaAsset $asset, string $direction): ?MetaMessage
    {
        if (!in_array($direction, ['inbound', 'outbound'], true)) {
            throw new \InvalidArgumentException('Message direction must be inbound or outbound.');
        }

        $message = $this->findOneBy(
            ['asset' => $asset, 'channel' => 'whatsapp', 'direction' => $direction],
            ['dateAdded' => 'DESC', 'id' => 'DESC'],
        );

        return $message instanceof MetaMessage ? $message : null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{results: list<MetaMessage>, total: int}
     */
    public function getContactTimeline(int $contactId, string $channel, array $options): array
    {
        $query = $this->createQueryBuilder('mm')
            ->andWhere('IDENTITY(mm.contact) = :contactId')
            ->andWhere('mm.channel = :channel')
            ->setParameter('contactId', $contactId)
            ->setParameter('channel', $channel);
        if (($options['fromDate'] ?? null) instanceof \DateTimeInterface) {
            $query->andWhere('mm.dateAdded >= :fromDate')->setParameter('fromDate', $options['fromDate']);
        }
        if (($options['toDate'] ?? null) instanceof \DateTimeInterface) {
            $query->andWhere('mm.dateAdded <= :toDate')->setParameter('toDate', $options['toDate']);
        }
        $search = trim((string) ($options['search'] ?? ''));
        if ('' !== $search) {
            $query->andWhere('mm.recipient LIKE :search OR mm.messageType LIKE :search OR mm.status LIKE :search')->setParameter('search', '%'.$search.'%');
        }
        $countQuery = clone $query;
        $total = (int) $countQuery->select('COUNT(mm.id)')->getQuery()->getSingleScalarResult();
        if (false === ($options['paginated'] ?? true)) {
            return ['results' => [], 'total' => $total];
        }
        $results = $query->orderBy('mm.dateAdded', 'DESC')
            ->setFirstResult(max(0, (int) ($options['start'] ?? 0)))
            ->setMaxResults(max(1, (int) ($options['limit'] ?? 25)))
            ->getQuery()->getResult();

        return ['results' => array_values($results), 'total' => $total];
    }
}
