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
     * Liste sans date (favoris, anciens fronts) : codes ACTIFS dont aucune abrogation vérifiée
     * n'est atteinte au jour indiqué (aujourd'hui). Forme historique de la réponse.
     *
     * Les requêtes de sélection sont les seules filtrées sur le statut (ADR-210) : les interventions
     * historiques, les favoris, les statistiques, les exports et l'administration lisent tous les codes.
     *
     * @param string $speciality
     * @return array<int, array<string, mixed>>
     */
    public function fetchBySpeciality($speciality, ?\DateTimeInterface $today = null)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.speciality = :val')
            ->andWhere('u.active = :active')
            ->andWhere('u.inamiRepealVerifiedAt IS NULL OR u.inamiRepealedAt > :today')
            ->setParameter('val', $speciality)
            ->setParameter('active', true)
            ->setParameter('today', self::day($today ?? new \DateTimeImmutable('today')), Types::DATE_IMMUTABLE)
            ->select('u.id, u.codeAmbulant, u.codeHospitalisation, u.codeHospitalisation, u.name, u.n, u.type, u.subType ')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Codes d'une spécialité UTILISABLES pour une intervention réalisée à cette date (même règle
     * que Nomenclature::isUsableOn()) :
     * - abrogation vérifiée : seulement si elle est postérieure à l'intervention, actif ou non ;
     * - sans abrogation vérifiée : seulement les codes actifs.
     * Chaque ligne indique `active` et `inamiRepealedAt` (abrogation VÉRIFIÉE, « AAAA-MM-JJ », sinon null).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchUsableBySpecialityOn(string $speciality, \DateTimeInterface $date): array
    {
        $rows = $this->createQueryBuilder('u')
            ->andWhere('u.speciality = :val')
            ->andWhere('(u.inamiRepealVerifiedAt IS NOT NULL AND u.inamiRepealedAt > :date) OR (u.inamiRepealVerifiedAt IS NULL AND u.active = true)')
            ->setParameter('val', $speciality)
            ->setParameter('date', self::day($date), Types::DATE_IMMUTABLE)
            ->select('u.id, u.codeAmbulant, u.codeHospitalisation, u.name, u.n, u.type, u.subType, u.active, u.inamiRepealedAt, u.inamiRepealVerifiedAt')
            ->orderBy('u.id')
            ->getQuery()
            ->getResult();

        return array_map(static function (array $row): array {
            $verified = $row['inamiRepealVerifiedAt'] !== null;
            unset($row['inamiRepealVerifiedAt']);
            $row['active'] = (bool) $row['active'];
            $row['inamiRepealedAt'] = $verified ? $row['inamiRepealedAt']?->format('Y-m-d') : null;

            return $row;
        }, $rows);
    }

    private static function day(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
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
