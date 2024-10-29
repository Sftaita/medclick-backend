<?php

namespace App\Repository;

use App\Entity\Nomenclature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Nomenclature|null find($id, $lockMode = null, $lockVersion = null)
 * @method Nomenclature|null findOneBy(array $criteria, array $orderBy = null)
 * @method Nomenclature[]    findAll()
 * @method Nomenclature[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NomenclatureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Nomenclature::class);
    }

    /**
     * Permet de récupéré la liste nomenclature par spécialitée.
     *
     * @param [type] $speciality
     * @return void
     */
    public function fetchBySpeciality($speciality)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.speciality = :val')
            ->setParameter('val', $speciality)
            ->select('u.id, u.codeAmbulant, u.codeHospitalisation, u.codeHospitalisation, u.name, u.n, u.type, u.subType ')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Permet de récupérer le code de reference de l'intervention.
     * @param string $name Nom commun de l'intervention. 
     */
    public function getReference($name)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.name = :val')
            ->setParameter('val', $name)
            ->select('u.codeHospitalisation, u.n, u.speciality')
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    
    // /**
    //  * @return Nomenclature[] Returns an array of Nomenclature objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Nomenclature
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
