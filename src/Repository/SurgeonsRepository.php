<?php

namespace App\Repository;

use App\Entity\Surgeons;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Surgeons|null find($id, $lockMode = null, $lockVersion = null)
 * @method Surgeons|null findOneBy(array $criteria, array $orderBy = null)
 * @method Surgeons[]    findAll()
 * @method Surgeons[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SurgeonsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Surgeons::class);
    }

    /**
     * Permet de récupéré la liste des chirurgiens associés à une année.
     *
     * @param [type] $id
     */
    public function findSurgeons($id)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :val')
            ->setParameter('val', $id)
            ->select('u.id, u.firstName, u.lastName, u.boss')
            ->orderBy('u.boss', 'DESC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Permet de récupéré l'identité du chirurgien.
     *
     * @param [type] $id id du chirurgien.
     */
    public function findSurgeon($id)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.id = :val')
            ->setParameter('val', $id)
            ->select('u.firstName, u.lastName')
            ->getQuery()
            ->getOneorNullResult()
        ;
    }

    /**
     * Permet de récupéré le(s) chirugien(s) lié(s) à l'année étant maitre de stage.
     *
     * @param  $id id du chirurgien.
     */
    public function getBoss($year)
    {
        $boss =  $this->createQueryBuilder('u')
        ->andWhere('u.year = :param1')
        ->andWhere('u.boss = 1')
        ->setParameters(array('param1'=> $year))
        ->select('u')
        ->getQuery()
        ->getResult();

        return $boss; 
    }

    // /**
    //  * @return Surgeons[] Returns an array of Surgeons objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Surgeons
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
