<?php

namespace App\Repository;

use App\Entity\ExportJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExportJob>
 */
class ExportJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExportJob::class);
    }

    public function findOneByPublicId(string $publicId): ?ExportJob
    {
        return $this->findOneBy(['publicId' => $publicId]);
    }

    /** Génération non terminée du même utilisateur, du même type et avec les mêmes filtres. */
    public function findActiveDuplicate(int $userId, string $type, array $parameters): ?ExportJob
    {
        foreach ($this->findBy(['userId' => $userId, 'type' => $type, 'status' => ExportJob::ACTIVE_STATUSES], ['id' => 'DESC']) as $job) {
            if ($job->getParameters() == $parameters) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Exports récents d'un utilisateur (reprise après rafraîchissement de la page).
     *
     * @return list<ExportJob>
     */
    public function findRecentForUser(int $userId, \DateTimeImmutable $since, ?int $yearId = null): array
    {
        $jobs = $this->createQueryBuilder('j')
            ->where('j.userId = :user')
            ->andWhere('j.requestedAt >= :since')
            ->setParameter('user', $userId)
            ->setParameter('since', $since)
            ->orderBy('j.id', \SortDirection::Descending)
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($jobs, fn (ExportJob $job) => $yearId === null || ($job->getParameters()['yearId'] ?? null) === $yearId));
    }

    /** Nombre de générations à traiter avant celle-ci (en cours + en attente plus anciennes). */
    public function countAhead(ExportJob $job): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.status = :running OR (j.status = :pending AND j.id < :id)')
            ->setParameter('running', ExportJob::STATUS_RUNNING)
            ->setParameter('pending', ExportJob::STATUS_PENDING)
            ->setParameter('id', $job->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ExportJob>
     */
    public function findByStatusBefore(array $statuses, string $dateField, \DateTimeImmutable $before, int $limit = 200): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status IN (:statuses)')
            ->andWhere("j.$dateField < :before")
            ->setParameter('statuses', $statuses)
            ->setParameter('before', $before)
            ->orderBy('j.id', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Dernière alerte envoyée pour un même incident depuis $since (anti-doublon). */
    public function findAlertSentSince(string $fingerprint, \DateTimeImmutable $since, int $excludeId): ?ExportJob
    {
        return $this->createQueryBuilder('j')
            ->where('j.alertFingerprint = :fingerprint')
            ->andWhere('j.alertStatus = :sent')
            ->andWhere('j.alertSentAt >= :since')
            ->andWhere('j.id != :id')
            ->setParameter('fingerprint', $fingerprint)
            ->setParameter('sent', ExportJob::ALERT_SENT)
            ->setParameter('since', $since)
            ->setParameter('id', $excludeId)
            ->orderBy('j.alertSentAt', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Liste filtrée de l'administration (avec l'email du demandeur s'il existe encore).
     *
     * @return array{items: list<array{0: ExportJob, email: ?string}>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $qb = $this->filtered($filters)
            ->leftJoin('App\Entity\User', 'u', 'WITH', 'u.id = j.userId')
            ->addSelect('u.email AS email')
            ->orderBy('j.id', \SortDirection::Descending)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $total = (int) $this->filtered($filters)->select('COUNT(j.id)')->getQuery()->getSingleScalarResult();

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }

    /**
     * Compteurs par statut sur la période filtrée (+ prêts jamais téléchargés).
     *
     * @return array<string, int>
     */
    public function countByStatus(array $filters): array
    {
        $rows = $this->filtered(array_diff_key($filters, ['status' => true]))
            ->select('j.status AS status, COUNT(j.id) AS n')
            ->groupBy('j.status')
            ->getQuery()
            ->getArrayResult();
        $counts = array_fill_keys(ExportJob::STATUSES, 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        $counts['notDownloaded'] = (int) $this->filtered(array_diff_key($filters, ['status' => true]))
            ->select('COUNT(j.id)')
            ->andWhere('j.notDownloadedAt IS NOT NULL')
            ->andWhere('j.firstDownloadedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return $counts;
    }

    private function filtered(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('j');
        if (!empty($filters['status'])) {
            $qb->andWhere('j.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('j.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['userId'])) {
            $qb->andWhere('j.userId = :userId')->setParameter('userId', (int) $filters['userId']);
        }
        if (!empty($filters['user'])) {
            $qb->andWhere('j.userId IN (SELECT u2.id FROM App\Entity\User u2 WHERE u2.email LIKE :email)')
                ->setParameter('email', '%' . addcslashes($filters['user'], '%_') . '%');
        }
        if (!empty($filters['from'])) {
            $qb->andWhere('j.requestedAt >= :from')->setParameter('from', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $qb->andWhere('j.requestedAt < :to')->setParameter('to', $filters['to']);
        }

        return $qb;
    }
}
