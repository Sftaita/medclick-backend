<?php

namespace App\Repository;

use App\Entity\Marketing;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Marketing>
 *
 * @method Marketing|null find($id, $lockMode = null, $lockVersion = null)
 * @method Marketing|null findOneBy(array $criteria, array $orderBy = null)
 * @method Marketing[]    findAll()
 * @method Marketing[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MarketingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Marketing::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(Marketing $entity, bool $flush = true): void
    {
        $this->_em->persist($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function remove(Marketing $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * Trouve les campagnes actives en cours (avec statut actif et dans la période actuelle).
     * 
     * @param \DateTime $currentDate La date actuelle
     * @return Marketing[] Renvoie un tableau des campagnes actives
     */
    public function findActiveCampaigns(\DateTime $currentDate): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.status = :status')
            ->andWhere('m.start_date <= :currentDate')
            ->andWhere('m.end_date IS NULL OR m.end_date >= :currentDate')
            ->setParameter('status', 'active')
            ->setParameter('currentDate', $currentDate)
            ->getQuery()
            ->getResult();
    }

    // /**
    //  * @return Marketing[] Returns an array of Marketing objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Marketing
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
