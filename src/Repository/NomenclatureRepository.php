<?php

namespace App\Repository;

use App\Entity\Nomenclature;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
     * Codes d'une spécialité UTILISABLES pour une intervention réalisée à cette date (saisie, y
     * compris tardive) : codes actifs, et codes inactifs dont la date officielle d'abrogation est
     * VÉRIFIÉE et postérieure à l'intervention (même règle que Nomenclature::isUsableOn()).
     * Chaque ligne indique `active` et, pour un ancien code, `inamiRepealedAt` (« AAAA-MM-JJ »).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchUsableBySpecialityOn(string $speciality, \DateTimeInterface $date): array
    {
        $rows = $this->createQueryBuilder('u')
            ->andWhere('u.speciality = :val')
            ->andWhere('u.active = true OR (u.inamiRepealVerifiedAt IS NOT NULL AND u.inamiRepealedAt > :date)')
            ->setParameter('val', $speciality)
            ->setParameter('date', \DateTimeImmutable::createFromInterface($date)->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->select('u.id, u.codeAmbulant, u.codeHospitalisation, u.name, u.n, u.type, u.subType, u.active, u.inamiRepealedAt')
            ->orderBy('u.id')
            ->getQuery()
            ->getResult();

        return array_map(static function (array $row): array {
            $row['active'] = (bool) $row['active'];
            // Date officielle exposée seulement pour un ancien code (elle est alors vérifiée).
            $row['inamiRepealedAt'] = $row['active'] ? null : $row['inamiRepealedAt']?->format('Y-m-d');

            return $row;
        }, $rows);
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
