<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\Service\InamiSearchStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques de la recherche publique des codes INAMI (ROLE_ADMIN, access_control) : visites
 * par jour et spécialités consultées. Les affichages et clics de la bannière « Recherche INAMI »
 * figurent dans /api/admin/marketing-stats (emplacement inami_search).
 */
final class InamiSearchStatsController extends AbstractController
{
    #[Route('/api/admin/inami-stats', name: 'admin_inami_stats', methods: ['GET'])]
    public function summary(Request $request, InamiSearchStats $stats): JsonResponse
    {
        $days = filter_var($request->query->get('days', '30'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => InamiSearchStats::MAX_DAYS]]);
        if ($days === false) {
            return new JsonResponse(['message' => sprintf('Période : de 1 à %d jours.', InamiSearchStats::MAX_DAYS)], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($stats->summary($days));
    }
}
