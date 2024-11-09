<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(UserInterface $user, string $newEncodedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newEncodedPassword);
        $this->_em->persist($user);
        $this->_em->flush();
    }

    /**
     * Cherche le token d'inscription de l'utilsiateur.
     */
    public function fetchValidationToken($user)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u = :val')
            ->setParameter('val', $user)
            ->select('u.token')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Renvoie la liste de tous les utilisateurs.
     * ROLE: ADMIN.
     */
    public function getUsersList()
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.statistics', 'e')
            ->select('u.firstname, u.lastname, u.id, u.createdAt, u.validatedAt, u.counter, e.firstHandSurgeries, e.secondHandSurgeries, e.fistHandHelpedSurgeries, e.consultations, e.gardes, e.formations')
            ->getQuery()
            ->getResult();
    }

    /**
     * Renvoie l'id de l'utilisateur.
     */
    public function getId($user)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u = :val')
            ->setParameter('val', $user)
            ->select('u.id')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Renvoie l'id, le prénom, le nom et l'adresse email de l'utilisateur.
     * @param obj $user 
     * @return array
     */
    public function getUserInfo($user)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u = :val')
            ->setParameter('val', $user)
            ->select('u.id, u.firstname, u.lastname, u.email')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Renvoie le conter d'utilisation de l'utilisateur.
     * @param user Utilisateur
     */
    public function getCounter($user)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u = :val')
            ->setParameter('val', $user)
            ->select('u.counter')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Permet de modifier le counter
     */
    public function setIncrement(User $user, $increment)
    {
        $user->setCounter($increment);
        $this->_em->persist($user);
        $this->_em->flush();
    }

    public function findLastTenUsersWithValidationStatus()
{
    return $this->createQueryBuilder('u')
        ->select('u.id, u.firstname, u.lastname, u.email, u.validatedAt')
        ->orderBy('u.createdAt', 'DESC')
        ->setMaxResults(10)
        ->getQuery()
        ->getResult();
}




    // /**
    //  * @return User[] Returns an array of User objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('u.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
