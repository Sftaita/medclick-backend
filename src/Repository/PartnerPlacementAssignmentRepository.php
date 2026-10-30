<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PartnerPlacementAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PartnerPlacementAssignment> */
class PartnerPlacementAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerPlacementAssignment::class);
    }

    /** @return list<PartnerPlacementAssignment> */
    public function findLive(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.partner', 'p')->addSelect('p')
            ->andWhere('a.status = :status')
            ->andWhere('a.startsOn <= :today')
            ->andWhere('(a.endsOn IS NULL OR a.endsOn >= :today)')
            ->setParameter('status', PartnerPlacementAssignment::PUBLISHED)
            ->setParameter('today', $today, Types::DATE_IMMUTABLE)
            ->orderBy('a.startsOn', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()->getResult();
    }

    public function overlapping(
        string $placement,
        \DateTimeImmutable $startsOn,
        ?\DateTimeImmutable $endsOn,
        ?PartnerPlacementAssignment $ignored = null,
    ): ?PartnerPlacementAssignment {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.placement = :placement')
            ->andWhere('a.status = :status')
            ->andWhere('(a.endsOn IS NULL OR a.endsOn >= :startsOn)')
            ->setParameter('placement', $placement)
            ->setParameter('status', PartnerPlacementAssignment::PUBLISHED)
            ->setParameter('startsOn', $startsOn, Types::DATE_IMMUTABLE)
            ->setMaxResults(1);
        if ($endsOn !== null) {
            $qb->andWhere('a.startsOn <= :endsOn')
                ->setParameter('endsOn', $endsOn, Types::DATE_IMMUTABLE);
        }
        if ($ignored?->getId() !== null) {
            $qb->andWhere('a.id != :ignored')->setParameter('ignored', $ignored->getId());
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** @return list<PartnerPlacementAssignment> */
    public function history(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.partner', 'p')->addSelect('p')
            ->orderBy('a.startsOn', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()->getResult();
    }
}
