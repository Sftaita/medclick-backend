<?php

namespace App\Controller\AdminControllers;

use DateTime;
use DatePeriod;
use DateInterval;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Repository\ConnectionHistoryRepository;

/**
 * @Route("/api/admin/", name="Sthhb")
 */
class ConnectionHistoryController extends AbstractController
{
    /**
     * @Route("history/quick", name="connection_history_quick", methods={"GET"})
     * Fournit le nombre de connexions des 7 derniers jours, le nombre d'utilisateurs uniques,
     * les 10 derniers utilisateurs uniques connectés, et les 10 derniers utilisateurs inscrits.
     */
    public function quickConnectionStats(ConnectionHistoryRepository $history, UserRepository $userRepository)
    {
        // Récupère les connexions des 7 derniers jours pour les statistiques journalières
        $last7DaysData = $history->findByInterval('-7 days');

        if (!is_array($last7DaysData)) {
            return new JsonResponse(['message' => 'Données de connexion invalides'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Calcul des statistiques journalières
        $dailyStats = [];
        foreach ($last7DaysData as $entry) {
            $dateKey = $entry['date']->format('Y-m-d');
            if (!isset($dailyStats[$dateKey])) {
                $dailyStats[$dateKey] = [
                    "connection_count" => 0,
                    "unique_users" => []
                ];
            }
            $dailyStats[$dateKey]["connection_count"]++;
            $dailyStats[$dateKey]["unique_users"][$entry['user_id']] = true;
        }

        $formattedDailyStats = [];
        $endDate = new DateTime();
        $startDate = (clone $endDate)->sub(new DateInterval('P6D'));
        $period = new DatePeriod($startDate, new DateInterval('P1D'), $endDate->add(new DateInterval('P1D')));
        foreach ($period as $date) {
            $dateKey = $date->format('Y-m-d');
            $formattedDailyStats[] = [
                "date" => $dateKey,
                "connection_count" => $dailyStats[$dateKey]["connection_count"] ?? 0,
                "unique_user_count" => isset($dailyStats[$dateKey]) ? count($dailyStats[$dateKey]["unique_users"]) : 0,
            ];
        }

        // Récupère tous les utilisateurs inscrits
        $allUsers = $userRepository->findAll();

        // Récupère les utilisateurs actifs depuis le début d'octobre jusqu'à aujourd'hui
        $octoberStart = new DateTime('first day of October last year');
        $activeUsers = $history->findUsersActiveBetween($octoberStart, $endDate);

        // Récupère les 10 derniers utilisateurs inscrits avec le statut de validation de leur token
        $lastTenUsers = $userRepository->findLastTenUsersWithValidationStatus();

        // Récupère les 10 dernières connexions d'utilisateurs non administrateurs
        $latestConnections = $history->findLastTenNonAdminConnections();

        // Compilation des données
        $data = [
            "daily_stats" => $formattedDailyStats,
            "all_users_count" => count($allUsers),
            "active_users_since_october" => count($activeUsers),
            "latest_users_registered" => $lastTenUsers,
            "latest_users_connected" => $latestConnections,
        ];

        return new JsonResponse($data);
    }
}
