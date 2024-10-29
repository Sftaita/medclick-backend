<?php

namespace App\Repository;

use App\Entity\Gardes;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Gardes|null find($id, $lockMode = null, $lockVersion = null)
 * @method Gardes|null findOneBy(array $criteria, array $orderBy = null)
 * @method Gardes[]    findAll()
 * @method Gardes[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class GardesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Gardes::class);
    }

    /**
     * Renvoie toutes les gardes de l'année choisie.
     * @param object $IdYear Année d'extraction des données.
     * @return array Liste des gardes.
     */
    public function getGardes($IdYear)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->setParameters(array('param1' => $IdYear))
            ->select('u.id, u.dateOfStart, u.dateOfEnd, u.number')
            ->getQuery()
            ->getResult();
    }

    /**
     * Permet un décompte des gardes de l'année.
     * @param int Id de l'année.
     * @return  array 
     */
    public function countThis($yearId)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :val')
            ->setParameter('val', $yearId)
            ->select('count(u)')
            ->getQuery()
            ->getResult();
    }

    // /**
    //  * @return Gardes[] Returns an array of Gardes objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('g.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Gardes
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
