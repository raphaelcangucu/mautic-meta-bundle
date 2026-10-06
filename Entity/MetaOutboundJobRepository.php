<?php

declare(strict_types=1);

namespace MauticPlugin\MauticMetaBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<MetaOutboundJob>
 */
class MetaOutboundJobRepository extends CommonRepository
{
    /**
     * Estados de onde um job ainda pode sair: ou espera a vez, ou esta na mao de um
     * worker agora. Os outros - completed, failed, blocked, uncertain - nao voltam
     * sozinhos para a fila, entao contar qualquer um deles como bloqueio prenderia a
     * conversa ate alguem mexer na mao. Vale sobretudo para uncertain: nao saber se a
     * mensagem saiu e ruim, mas e menos ruim do que calar a conversa para sempre.
     */
    private const NON_TERMINAL_STATUSES = ['pending', 'retry', 'processing'];

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
        $queryBuilder = $this->createQueryBuilder('moj');

        // Uma conversa e o par canal + contato: o mesmo contato em dois canais sao dois
        // fios de conversa, e travar um nao pode calar o outro. Job sem contato - o caso
        // da campanha - nao pertence a conversa nenhuma e sai pela esquerda do OR, antes
        // que a subconsulta possa juntar todos os nulos numa unica fila de um por vez.
        //
        // "Anterior" e o id, nao o dateAdded: o auto-increment e a propria ordem de
        // enfileiramento e nunca empata, enquanto dois jobs criados no mesmo segundo
        // gravam o mesmo dateAdded e a regra nao saberia qual deles espera qual.
        //
        // A subconsulta ignora availableAt de proposito: o job anterior costuma estar
        // justamente em retry com availableAt no futuro, que e o caso que embaralha a
        // conversa - olhar so o que ja venceu nao enxergaria o bloqueio.
        $earlierInSameConversation = $this->getEntityManager()->createQueryBuilder()
            ->select('earlier.id')
            ->from(MetaOutboundJob::class, 'earlier')
            ->andWhere('earlier.contact = moj.contact')
            ->andWhere('earlier.asset = moj.asset')
            ->andWhere('earlier.status IN (:blockingStatuses)')
            ->andWhere('earlier.id < moj.id');

        return $queryBuilder->andWhere('moj.status IN (:statuses)')->andWhere('moj.availableAt <= :now')
            ->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->isNull('moj.contact'),
                $queryBuilder->expr()->not($queryBuilder->expr()->exists($earlierInSameConversation->getDQL())),
            ))
            ->setParameter('statuses', ['pending', 'retry'])->setParameter('blockingStatuses', self::NON_TERMINAL_STATUSES)->setParameter('now', $now)->orderBy('moj.availableAt', 'ASC')->addOrderBy('moj.id', 'ASC')
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
