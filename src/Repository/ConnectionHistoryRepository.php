<?php

namespace App\Repository;

use App\Entity\ConnectionHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ConnectionHistory|null find($id, $lockMode = null, $lockVersion = null)
 * @method ConnectionHistory|null findOneBy(array $criteria, array $orderBy = null)
 * @method ConnectionHistory[]    findAll()
 * @method ConnectionHistory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConnectionHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnectionHistory::class);
    }

    /**
     * Renvoie l'historique selon l'intervalle demandé
     *
     * @param string Intervalle rechercher
     * @return obj
     */
    public function findByInterval($interval)
    {
        $date = new \DateTime($interval);
        $startDate = $date->format('Y-m-d');

        return $this->createQueryBuilder('c')
            ->where('c.date >= :begin')
            ->andWhere('c.date <= :end')
            ->setParameter('begin', $startDate)
            ->setParameter('end', new \DateTime())
            ->select ("c.id, c.date, IDENTITY(c.user)")
            ->getQuery()
            ->getResult()
        ;    
    }

    /**
 * Récupère l'historique de connexion d'un utilisateur, trié par date.
 */
public function findByUserOrderedByDate($user)
{
    return $this->createQueryBuilder('u')
        ->andWhere('u.user = :val')
        ->setParameter('val', $user)
        ->orderBy('u.date', 'DESC')
        ->getQuery()
        ->getResult();
}


}
