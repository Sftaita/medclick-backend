<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Partner;
use App\Entity\PartnerPlacementAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/** @extends ServiceEntityRepository<PartnerPlacementAssignment> */
class PartnerPlacementAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerPlacementAssignment::class);
    }

    /**
     * Affectations publiées en cours ce jour-là, la plus récente d'abord.
     *
     * @return list<PartnerPlacementAssignment>
     */
    public function findLive(\DateTimeImmutable $day): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.partner', 'p')->addSelect('p')
            ->andWhere('a.status = :status')
            ->andWhere('a.startsOn <= :day')
            ->andWhere('a.endsOn IS NULL OR a.endsOn >= :day')
            ->setParameter('status', PartnerPlacementAssignment::PUBLISHED)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->orderBy('a.startsOn', SortDirection::Descending)
            ->addOrderBy('a.id', SortDirection::Descending)
            ->getQuery()->getResult();
    }

    /**
     * Affectations publiées du même emplacement dont la période croise [$start, $end] ($end
     * null : sans fin), hors $ignored.
     *
     * @return list<PartnerPlacementAssignment>
     */
    public function findOverlapping(string $placement, \DateTimeImmutable $start, ?\DateTimeImmutable $end, ?PartnerPlacementAssignment $ignored = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.placement = :placement')
            ->andWhere('a.status = :status')
            ->andWhere('a.endsOn IS NULL OR a.endsOn >= :start')
            ->setParameter('placement', $placement)
            ->setParameter('status', PartnerPlacementAssignment::PUBLISHED)
            ->setParameter('start', $start, Types::DATE_IMMUTABLE)
            ->orderBy('a.startsOn', SortDirection::Ascending)
            ->addOrderBy('a.id', SortDirection::Ascending);
        if ($end !== null) {
            $qb->andWhere('a.startsOn <= :end')->setParameter('end', $end, Types::DATE_IMMUTABLE);
        }
        if ($ignored?->getId() !== null) {
            $qb->andWhere('a.id != :ignored')->setParameter('ignored', $ignored->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /** Une firme est-elle diffusée ce jour-là sur au moins un emplacement ? */
    public function isPartnerLive(Partner $partner, \DateTimeImmutable $day, ?string $placement = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.partner = :partner')
            ->andWhere('a.status = :status')
            ->andWhere('a.startsOn <= :day')
            ->andWhere('a.endsOn IS NULL OR a.endsOn >= :day')
            ->setParameter('partner', $partner)
            ->setParameter('status', PartnerPlacementAssignment::PUBLISHED)
            ->setParameter('day', $day, Types::DATE_IMMUTABLE);
        if ($placement !== null) {
            $qb->andWhere('a.placement = :placement')->setParameter('placement', $placement);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** @return array<int, int> nombre d'affectations (tous statuts) par firme */
    public function countByPartner(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.partner) AS partner, COUNT(a.id) AS n')
            ->andWhere('a.partner IS NOT NULL')
            ->groupBy('a.partner')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r) => ['id' => (int) $r['partner'], 'n' => (int) $r['n']], $rows), 'n', 'id');
    }

    /** @return list<PartnerPlacementAssignment> tout l'historique, le plus récent d'abord */
    public function findHistory(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.partner', 'p')->addSelect('p')
            ->orderBy('a.startsOn', SortDirection::Descending)
            ->addOrderBy('a.id', SortDirection::Descending)
            ->getQuery()->getResult();
    }
}
