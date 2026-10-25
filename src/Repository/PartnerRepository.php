<?php

namespace App\Repository;

use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Partner>
 */
class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    /** Partenaire affiché ce jour-là : publié, date de début atteinte, date de fin non dépassée. */
    public function findLive(\DateTimeImmutable $today): ?Partner
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :published')
            ->andWhere('p.startsOn <= :today')
            ->andWhere('p.endsOn IS NULL OR p.endsOn >= :today')
            ->setParameter('published', Partner::STATUS_PUBLISHED)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            // Les chevauchements sont refusés à la publication ; le plus récent l'emporte par sécurité.
            ->orderBy('p.startsOn', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Partenaire publié (autre que $ignored) dont la période croise [$start, $end] ($end null :
     * sans fin). Un partenaire retiré libère sa période.
     */
    public function findOverlapping(\DateTimeImmutable $start, ?\DateTimeImmutable $end, ?Partner $ignored): ?Partner
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.status = :published')
            ->andWhere('p.endsOn IS NULL OR p.endsOn >= :start')
            ->setParameter('published', Partner::STATUS_PUBLISHED)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->orderBy('p.startsOn', SortDirection::Ascending)
            ->setMaxResults(1);
        if ($end !== null) {
            $qb->andWhere('p.startsOn <= :end')->setParameter('end', $end, Types::DATE_IMMUTABLE);
        }
        if ($ignored?->getId() !== null) {
            $qb->andWhere('p.id != :ignored')->setParameter('ignored', $ignored->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** @return list<Partner> Historique : le plus récent d'abord (brouillons sans date en tête). */
    public function findHistory(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.startsOn', SortDirection::Descending)
            ->addOrderBy('p.id', SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }
}
