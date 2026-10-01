<?php

namespace App\Repository;

use App\Entity\ConnectionHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ConnectionHistory|null find($id, $lockMode = null, $lockVersion = null)
 * @method ConnectionHistory|null findOneBy(array $criteria, array $orderBy = null)
 * @method ConnectionHistory[]    findAll()
 * @method ConnectionHistory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ConnectionHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnectionHistory::class);
    }

    /**
     * Renvoie l'historique selon l'intervalle demandé
     *
     * @param string Intervalle rechercher
     * @return obj
     */
    public function findByInterval($interval)
    {
        $date = new \DateTime($interval);
        $startDate = $date->format('Y-m-d');

        return $this->createQueryBuilder('c')
        ->innerJoin('c.user', 'u') 
        ->where('c.date >= :begin')
        ->andWhere('c.date <= :end')
        ->setParameter('begin', $startDate)
        ->setParameter('end', new \DateTime())
        ->select("c.id, c.date, u.id AS user_id, u.firstname, u.lastname, u.speciality") 
        ->getQuery()
        ->getResult(); 
    }

    public function findLastTenNonAdminConnections()
    {
        $results = $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u')
            ->where('u.roles NOT LIKE :adminRole')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->orderBy('c.date', 'DESC')
            ->setMaxResults(10)
            ->select("c.id, c.date, u.id AS user_id, u.firstname, u.lastname, u.speciality")
            ->getQuery()
            ->getResult();

        return array_map(function ($entry) {
            // Conversion de la date dans le fuseau horaire de Bruxelles
            $brusselsTimezone = new \DateTimeZone('Europe/Brussels');
            $entry['date']->setTimezone($brusselsTimezone);
            $entry['date'] = $entry['date']->format('Y-m-d H:i:s');
            return $entry;
        }, $results);
    }


    /**
 * Récupère l'historique de connexion d'un utilisateur, trié par date.
 */
public function findByUserOrderedByDate($user)
{
    return $this->createQueryBuilder('u')
        ->andWhere('u.user = :val')
        ->setParameter('val', $user)
        ->orderBy('u.date', 'DESC')
        ->getQuery()
        ->getResult();
}

public function findUsersActiveBetween(\DateTimeInterface $startDate, \DateTimeInterface $endDate)
{
    return $this->createQueryBuilder('ch')
        ->select('DISTINCT u.id')
        ->join('ch.user', 'u')
        ->where('ch.date >= :startDate')
        ->andWhere('ch.date <= :endDate')
        ->setParameter('startDate', $startDate)
        ->setParameter('endDate', $endDate)
        ->getQuery()
        ->getResult();
}

/**
 * Récupère l'historique de connexion des 12 derniers mois, calcule
 * le nombre d'utilisateurs uniques et le nombre total de connexions
 * pour chaque mois, puis détermine les moyennes mensuelles.
 *
 * @return array Tableau contenant :
 *               - 'results': un tableau indexé, où chaque élément contient :
 *                   * 'month'             : le mois (1-12)
 *                   * 'year'              : l'année (ex: 2025)
 *                   * 'unique_users'      : le nombre d'utilisateurs distincts
 *                   * 'total_connections' : le nombre total de connexions
 *               - 'averages': un tableau associatif avec :
 *                   * 'average_unique_users'
 *                   * 'average_total_connections'
 */
public function findMonthlyStats(): array
{
    // 1) Calcul de la date actuelle et de la date d'il y a 1 an
    $currentDate = new \DateTime();
    $oneYearAgo  = (clone $currentDate)->modify('-1 year');

    // 2) Requête Doctrine pour récupérer toutes les connexions sur les 12 derniers mois
    //    On ne fait aucun groupement côté DQL ; on va tout agréger en PHP ensuite.
    $query = $this->createQueryBuilder('ch')
        ->where('ch.date >= :oneYearAgo')
        ->setParameter('oneYearAgo', $oneYearAgo)
        ->orderBy('ch.date', 'ASC')
        ->getQuery();

    // On récupère toutes les entités (p. ex. ConnectionHistory) sous forme de tableau
    $allConnections = $query->getResult();

    // 3) Préparation d'un tableau pour agréger nos stats par "année-mois"
    $results = [];

    // 4) On parcourt toutes les connexions pour regrouper mois/année, total, et utilisateurs uniques
    foreach ($allConnections as $connection) {
        // Récupération de l'année et du mois (au format numérique)
        $year  = $connection->getDate()->format('Y');
        $month = $connection->getDate()->format('m');

        // Création d'une clé unique pour identifier le mois-année (ex: "2025-02")
        $key = $year . '-' . $month;

        // Initialisation du tableau s'il n'existe pas
        if (!isset($results[$key])) {
            $results[$key] = [
                'month'             => (int) $month, // conversion en int
                'year'              => (int) $year,
                'unique_users_list' => [], // temporaire pour collecter les IDs utilisateur
                'total_connections' => 0
            ];
        }

        // Incrémentation du total de connexions
        $results[$key]['total_connections']++;

        // Récupération de l'ID de l'utilisateur (selon votre entité/relation)
        $userId = $connection->getUser()->getId();
        // Ajout au tableau associatif pour éviter les doublons
        $results[$key]['unique_users_list'][$userId] = true;
    }

    // 5) Conversion des listes d'IDs en simple count (nombre d'utilisateurs uniques)
    foreach ($results as $k => $row) {
        $results[$k]['unique_users'] = count($row['unique_users_list']);
        unset($results[$k]['unique_users_list']); // plus besoin de la liste détaillée
    }

    // 6) Tri par clé ("YYYY-MM") pour avoir l'ordre chronologique
    ksort($results);

    // 7) Calcul des moyennes
    //    On fait la somme des unique_users et total_connections,
    //    puis on divise par le nombre de mois trouvés.
    $monthCount           = count($results);
    $sumUniqueUsers       = 0;
    $sumTotalConnections  = 0;

    foreach ($results as $row) {
        $sumUniqueUsers      += $row['unique_users'];
        $sumTotalConnections += $row['total_connections'];
    }

    // Évite la division par zéro si la base est vide
    $averageUniqueUsers = $monthCount > 0 ? $sumUniqueUsers / $monthCount : 0;
    $averageTotalConnections = $monthCount > 0 ? $sumTotalConnections / $monthCount : 0;

    // 8) On prépare le tableau final avec deux entrées :
    //    - 'results'  : la liste des stats mensuelles
    //    - 'averages' : les deux moyennes globales
    $finalResults = [
        'results' => array_values($results), // re-indexe les résultats (0,1,2,...)
        'averages' => [
            'average_unique_users'      => $averageUniqueUsers,
            'average_total_connections' => $averageTotalConnections
        ],
    ];

    return $finalResults;
}





}
