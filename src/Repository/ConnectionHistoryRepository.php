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
        ->innerJoin('c.user', 'u') 
        ->where('c.date >= :begin')
        ->andWhere('c.date <= :end')
        ->setParameter('begin', $startDate)
        ->setParameter('end', new \DateTime())
        ->select("c.id, c.date, u.id AS user_id, u.firstname, u.lastname, u.speciality") 
        ->getQuery()
        ->getResult(); 
    }

    public function findLastTenNonAdminConnections()
    {
        $results = $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u')
            ->where('u.roles NOT LIKE :adminRole')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->orderBy('c.date', 'DESC')
            ->setMaxResults(10)
            ->select("c.id, c.date, u.id AS user_id, u.firstname, u.lastname, u.speciality")
            ->getQuery()
            ->getResult();

        return array_map(function ($entry) {
            // Conversion de la date dans le fuseau horaire de Bruxelles
            $brusselsTimezone = new \DateTimeZone('Europe/Brussels');
            $entry['date']->setTimezone($brusselsTimezone);
            $entry['date'] = $entry['date']->format('Y-m-d H:i:s');
            return $entry;
        }, $results);
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

public function findUsersActiveBetween(\DateTimeInterface $startDate, \DateTimeInterface $endDate)
{
    return $this->createQueryBuilder('ch')
        ->select('DISTINCT u.id')
        ->join('ch.user', 'u')
        ->where('ch.date >= :startDate')
        ->andWhere('ch.date <= :endDate')
        ->setParameter('startDate', $startDate)
        ->setParameter('endDate', $endDate)
        ->getQuery()
        ->getResult();
}



}
