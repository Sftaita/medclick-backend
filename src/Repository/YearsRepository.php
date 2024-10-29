<?php

namespace App\Repository;

use App\Entity\Years;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @method Years|null find($id, $lockMode = null, $lockVersion = null)
 * @method Years|null findOneBy(array $criteria, array $orderBy = null)
 * @method Years[]    findAll()
 * @method Years[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class YearsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Years::class);
    }

    /**
     * Permet de récupérer l'utilisateur de l'année'.
     * @param [type] $year id de l'anné
     */
    public function getUserId($year)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.id = :val')
            ->setParameter('val', $year)
            ->select('u.user')
            ->getQuery()
            ->getResult();
    }

    /**
     * Cherche une année ou l'Id user et l'Id année corresponde.
     * @param IdUser Id de l'utilisateur.
     * @param year Id de l'année
     * Renvoie un booleen.
     */
    public function check($IdUser, $year)
    {
        $check =  $this->createQueryBuilder('u')
            ->andWhere('u.user = :param1')
            ->andWhere('u.id = :param2')
            ->setParameters(array('param1' => $IdUser, 'param2' => $year))
            ->select('u')
            ->getQuery()
            ->getOneOrNullResult();

        if ($check !== null) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Renvoie le nom de l'hopital de formation.
     * @param year Id de l'année
     * @return string Nom de l'hôpital.
     */
    public function getHospital($year)
    {
        $hospital = $this->createQueryBuilder('u')
            ->andWhere('u.id = :param1')
            ->setParameters(array('param1' => $year))
            ->select('u.hospital')
            ->getQuery()
            ->getOneOrNullResult();

        return $hospital['hospital'];
    }

    /**
     * Renvoie le nom du maitre de stage.
     * @param year Id de l'année
     * @return string Nom du maitre de stage.
     */
    public function getMaster($year)
    {
        $hospital = $this->createQueryBuilder('u')
            ->andWhere('u.id = :param1')
            ->setParameters(array('param1' => $year))
            ->select('u.master')
            ->getQuery()
            ->getOneOrNullResult();

        return $hospital['master'];
    }

    /**
     * Renvoie l'année scolaire de formation .
     * @param year Id de l'année
     * @return string Année scolaire de formation.
     */
    public function getYOF($year)
    {
        $query = $this->createQueryBuilder('u')
            ->andWhere('u.id = :param1')
            ->setParameters(array('param1' => $year))
            ->select('u.yearOfFormation')
            ->getQuery()
            ->getOneOrNullResult();
        return $query['yearOfFormation'];
    }

    /**
     * Renvoie la date de début de stage .
     * @param obj Date de début de stage
     * @return date Année scolaire de formation.
     */
    public function getDOF($year)
    {
        $query = $this->createQueryBuilder('u')
            ->andWhere('u.id = :param1')
            ->setParameters(array('param1' => $year))
            ->select('u.dateOfStart')
            ->getQuery()
            ->getOneOrNullResult();
        return $query['dateOfStart'];
    }

    /**
     * Renvoie les statistique de l'utilisateur .
     * @param int Id de l'utilisateur
     * @return array 
     */
    public function getUserStat($id)
    {
        $query = $this->createQueryBuilder('u')
            ->andWhere('u.user = :param1')
            ->setParameters(array('param1' => $id))
            ->select('u.id')
            ->getQuery()
            ->getResult();
        return $query;
    }

    /**
     * Récupère les années d'un utilisateur.
     * @param obj $user 
     */
    public function fetchById($user)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.user = :val')
            ->setParameter('val', $user)
            ->select('u.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param year Année d'intêret
     * @return array Information concernant l'année 
     */
    public function getInfo($year)
    {
        $request = $this->createQueryBuilder('u')
            ->andWhere('u.id = :param1')
            ->setParameters(array('param1' => $year))
            ->select('u.hospital, u.yearOfFormation, u.master, u.dateOfStart')
            ->getQuery()
            ->getOneOrNullResult();

        return $request;
    }







    // /**
    //  * @return Years[] Returns an array of Years objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('y')
            ->andWhere('y.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('y.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Years
    {
        return $this->createQueryBuilder('y')
            ->andWhere('y.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
