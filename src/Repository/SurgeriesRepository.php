<?php

namespace App\Repository;

use App\Entity\Surgeries;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Surgeries|null find($id, $lockMode = null, $lockVersion = null)
 * @method Surgeries|null findOneBy(array $criteria, array $orderBy = null)
 * @method Surgeries[]    findAll()
 * @method Surgeries[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SurgeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Surgeries::class);
    }

    /**
     * Renvoie toute les interventions de l'année choisie.
     * @param int $year Année d'extraction des données.
     */
    public function getYears($year)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :val')
            ->setParameter('val', $year)
            ->select('u.code, u.name, u.firstHand, u.secondHand, u.position, u.date')
            ->orderBy('u.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Renvoie une liste des interventions de manière unique.
     * @param int $year Année d'extraction des données.
     * @return array Liste des interventions unique.
     */
    public function getDinstinct($year)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :val')
            ->setParameter('val', $year)
            ->select('DISTINCT u.name, u.code')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte le nombre d'intervention réalisé en première main.
     * @param int Id du chirurgien recherché.
     * @param int Id de l'année.
     * @return int Nombre d'intervention.
     */
    public function countAsFirstHand($IdSurgeon, $IdYear)
    {
        $check =  $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->andWhere('u.firstHand = :param2')
            ->setParameters(array('param1' => $IdYear, 'param2' => $IdSurgeon))
            ->select('u.id')
            ->getQuery()
            ->getResult();

        return count($check);
    }

     /**
     * Supprime les interventions liées au chirurgien en cours de suppression.
     * @param int Id du chirurgien en cours de suppression.
     * @param int Id de l'année d'enregistrement du chirurgien.
     */
    public function deleteSurgeryBySurgeon($surgeon, $yearId )
    {
        $q= $this->createQueryBuilder('u')
            ->delete()
            ->where('u.year = :param2')
            ->andWhere('u.position = 2 OR u.position = 3')
            ->andWhere('u.firstHand = :param1 OR u.secondHand = :param1')
            ->setParameters(array('param1' => $surgeon, 'param2' => $yearId ))
            ->getQuery()
            ->getResult();
        return;
    }

    /**
     * Compte le nombre de fois qu'une intervention a été réalisée.
     * @param int $SurgeryName Nom de l'intervention.
     * @param obj $year
     * @return array Tableau de la somme des interventions en première, deuxième ou troisième main.
     */
    public function countSurgery($year, $SurgeryName)
    {
        $arr =  $this->createQueryBuilder('u')
            ->andWhere('u.name = :param1')
            ->andWhere('u.year = :param2')
            ->setParameters(array('param1' => $SurgeryName, 'param2' => $year))
            ->select('u.position')
            ->getQuery()
            ->getResult();

        $data = array_column($arr, 'position');
        $counts = array_count_values($data);
        return $counts;
    }

    /**
     * Compte le nombre d'intervention réalisé en deuxième main.
     * @param int Id du chirurgien recherché.
     * @param int Id de l'année.
     * @return int Nombre d'intervention.
     */
    public function countAsSecondHand($IdSurgeon, $IdYear)
    {
        $check =  $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->andWhere('u.secondHand = :param2')
            ->setParameters(array('param1' => $IdYear, 'param2' => $IdSurgeon))
            ->select('COUNT (u.firstHand)')
            ->getQuery()
            ->getResult();


        return $check;
    }

    /**
     * Compte le nombre d'intervention ou le superviseur assistait.
     * @param int Id du chirurgien recherché.
     * @param int Id de l'année.
     * @return int Nombre d'intervention.
     */
    public function BossAsSecondHand($IdSurgeon, $IdYear)
    {
        $check =  $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->andWhere('u.secondHand = :param2')
            ->setParameters(array('param1' => $IdYear, 'param2' => $IdSurgeon))
            ->select('u.id')
            ->getQuery()
            ->getResult();

        return count($check);
    }

    /**
     * Permet un décompte des chirurgies en première main seule, première main accompagnée et deuxième main.
     * @param int Id de l'année.
     * @return  array 
     */
    public function countThis($yearId)
    {
        $check =  $this->createQueryBuilder('u')
            ->andWhere('u.year = :val')
            ->setParameter('val', $yearId)
            ->select('count(u.firstHand), count(u.secondHand)')
            ->getQuery()
            ->getResult();

        $result = ['firstHand' => $check[0][1], 'secondHand' => $check[0][2]];

        return $result;
    }

    /**
     * @param year Année d'intêret
     * @return array Décompte des interventions 
     */
    public function summary($year)
    {
        $querry = $this->createQueryBuilder('u')
            ->andWhere('u.year = :val')
            ->setParameter('val', $year)
            ->select('u.name, u.code, u.position')
            ->distinct(('u.name'))
            ->getQuery()
            ->getResult();

        return $querry;
    }

     /*
     *
     */
    public function getSurgeriesByYear($year)
    {
        $querry = $this->createQueryBuilder('s')
            ->andWhere('s.year = :val')
            ->setParameter('val', $year)
            ->select('u.name, u.code, u.position')
            ->getQuery()
            ->getResult();

        return $querry;
    }

    /**
     * Renvoie les chirurgie qui n om par de nomenclature attribué
     *
     * @param integer $limit
     * @param integer $offset
     * @return array
     */
    public function findSurgeriesWithoutNomenclature(int $limit, int $offset): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.nomenclature IS NULL')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /*
    public function findOneBySomeField($value): ?Surgeries
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
