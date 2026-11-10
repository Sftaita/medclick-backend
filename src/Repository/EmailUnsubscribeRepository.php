<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailUnsubscribe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailUnsubscribe>
 */
class EmailUnsubscribeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailUnsubscribe::class);
    }

    public function isUnsubscribed(string $email): bool
    {
        return $this->findOneBy(['email' => EmailUnsubscribe::normalize($email)]) !== null;
    }

    /**
     * @return array<string, true> adresses désinscrites (minuscules)
     */
    public function allAddresses(): array
    {
        $rows = $this->createQueryBuilder('u')->select('u.email')->getQuery()->getSingleColumnResult();

        return array_fill_keys($rows, true);
    }

    /**
     * @return array{total: int, items: list<EmailUnsubscribe>}
     */
    public function search(?string $query, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.createdAt', \SortDirection::Descending)->addOrderBy('u.id', \SortDirection::Descending);
        if ($query !== null && $query !== '') {
            $qb->where('u.email LIKE :q')->setParameter('q', '%' . addcslashes(mb_strtolower($query), '%_\\') . '%');
        }
        $total = (int) (clone $qb)->select('COUNT(u.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();

        return ['total' => $total, 'items' => $items];
    }
}
