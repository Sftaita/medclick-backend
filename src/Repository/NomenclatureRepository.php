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
     * Codes ACTIFS d'une spécialité : liste proposée pour choisir l'acte d'une nouvelle
     * intervention ou d'un favori (GET /api/nomenclature/{speciality}).
     *
     * Seule requête filtrée sur `active` (ADR-210) : les interventions historiques, les favoris,
     * les statistiques, les exports et l'administration lisent tous les codes, actifs ou non.
     *
     * @param string $speciality
     * @return array<int, array<string, mixed>>
     */
    public function fetchBySpeciality($speciality)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.speciality = :val')
            ->andWhere('u.active = :active')
            ->setParameter('val', $speciality)
            ->setParameter('active', true)
            ->select('u.id, u.codeAmbulant, u.codeHospitalisation, u.codeHospitalisation, u.name, u.n, u.type, u.subType ')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Nombre d'interventions et de favoris liés à un code (affiché avant d'en changer le statut).
     *
     * @return array{surgeries: int, favorites: int}
     */
    public function countUsage(Nomenclature $nomenclature): array
    {
        $em = $this->getEntityManager();

        return [
            'surgeries' => (int) $em->createQuery('SELECT COUNT(s.id) FROM App\Entity\Surgeries s WHERE s.nomenclature = :n')
                ->setParameter('n', $nomenclature)->getSingleScalarResult(),
            'favorites' => (int) $em->createQuery('SELECT COUNT(f.id) FROM App\Entity\Favorites f WHERE f.surgery = :n')
                ->setParameter('n', $nomenclature)->getSingleScalarResult(),
        ];
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
            ->orderBy('n.id', \SortDirection::Ascending)
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
