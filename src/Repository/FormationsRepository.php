<?php

namespace App\Repository;

use App\Entity\Formations;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Formations|null find($id, $lockMode = null, $lockVersion = null)
 * @method Formations|null findOneBy(array $criteria, array $orderBy = null)
 * @method Formations[]    findAll()
 * @method Formations[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FormationsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Formations::class);
    }

    /**
     * Renvoie tous les évènement de formation de l'année choisie.
     * @param int $Idyear Année (id) d'extraction des données.
     * @return array Liste des évènements de formation.
     */
    public function getFormations($IdYear)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->setParameters(array('param1' => $IdYear))
            ->select('u.id, u.event, u.dateOfStart, u.dateOfEnd, u.name, u.description, u.location, u.role')
            ->getQuery()
            ->getResult();;
    }

    /**
     * Renvoie une liste des formations de manière unique.
     * @param int $year Année d'extraction des données.
     * @return array Liste des formations unique.
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
    //  * @return Formations[] Returns an array of Formations objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('f.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Formations
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
