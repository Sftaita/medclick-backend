<?php

namespace App\Repository;

use App\Entity\Consultations;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Consultations|null find($id, $lockMode = null, $lockVersion = null)
 * @method Consultations|null findOneBy(array $criteria, array $orderBy = null)
 * @method Consultations[]    findAll()
 * @method Consultations[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConsultationsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Consultations::class);
    }

    /**
     * Renvoie toute les consultations de l'année choisi.
     * @param int $Idyear Année (id) d'extraction des données.
     * @return array Liste des consultations.
     */
    public function getConsultations($IdYear)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->setParameters(array('param1' => $IdYear))
            ->select('u.date, u.number, u.dayPart, u.speciality')
            ->getQuery()
            ->getResult();;
    }

    /**
     * Renvoie les dates disponible de manière unique.
     * @param int $Idyear Année (id) d'extraction des données.
     * @return array Liste des dates de l'année choisie.
     */
    public function getUniqDate($IdYear)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->setParameters(array('param1' => $IdYear))
            ->select('DISTINCT u.date')
            ->getQuery()
            ->getResult();;
    }

    /**
     * Renvoie le nombre de consultation de l'utilisateur.
     * @param int $IdUser Utilisateur.
     * @return int Nombre total de consultation.
     */
    public function TotalConsultatitons($IdUser)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.user = :param1')
            ->setParameters(array('param1' => $IdUser))
            ->select('count(u.id)')
            ->getQuery()
            ->getResult();
    }

    /**
     * Permet un décompte des consultations de l'année.
     * @param int Id de l'année.
     * @return  array 
     */
    public function countThis($yearId)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.year = :val')
            ->setParameter('val', $yearId)
            ->select('COUNT(DISTINCT CONCAT(u.date, u.dayPart))')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie s'il existe une consultation ce jour et à ce moment.
     *
     * @param int $year
     * @param date $date
     * @param string $dayPart
     * @return int 
     */
    public function check($year, $date, $dayPart)
    {
        $check = $this->createQueryBuilder('u')
            ->andWhere('u.year = :param1')
            ->andWhere('u.date = :param2')
            ->andWhere('u.dayPart = :param3')
            ->setParameters(array('param1' => $year, 'param2' => $date, 'param3' => $dayPart))
            ->select('count(u)')
            ->getQuery()
            ->getResult();

        if ($check[0][1] == 0) {
            return 0;
        } elseif ($check[0][1]  == 1) {
            return 1;
        } elseif ($check[0][1]  > 1) {
            return 2;
        }
    }



    // /**
    //  * @return Consultations[] Returns an array of Consultations objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Consultations
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
