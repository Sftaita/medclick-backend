<?php

namespace App\Repository;

use App\Entity\ErrorLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ErrorLog>
 */
class ErrorLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ErrorLog::class);
    }

    /**
     * @param string|null $status "open" (non résolues), "resolved" ou null (toutes)
     *
     * @return array{total: int, items: ErrorLog[]}
     */
    public function search(?string $status, ?string $source, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('e');

        if ($status === 'open') {
            $qb->andWhere('e.resolvedAt IS NULL');
        } elseif ($status === 'resolved') {
            $qb->andWhere('e.resolvedAt IS NOT NULL');
        }

        if ($source) {
            $qb->andWhere('e.source = :source')->setParameter('source', $source);
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->orderBy('e.lastSeenAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return ['total' => $total, 'items' => $items];
    }

    /** Supprime les erreurs dont la dernière occurrence date d'avant $before. */
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('e')
            ->delete()
            ->where('e.lastSeenAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
