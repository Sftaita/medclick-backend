<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\Advertising\Emplacements;
use App\Service\AdStats;
use App\Service\SponsorDelivery;
use App\Service\SponsorStats;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques « Publicités & partenaires » (ROLE_ADMIN) :
 * ?from=&to= (30 derniers jours par défaut), &emplacement=<clé>, &company=<id>.
 */
final class AdStatsController
{
    public function __construct(
        private readonly AdStats $stats,
        private readonly SponsorDelivery $delivery,
    ) {
    }

    #[Route('/api/admin/ad-stats', name: 'admin_ad_stats', methods: ['GET'])]
    public function report(Request $request): JsonResponse
    {
        try {
            [$from, $to] = SponsorStats::period($request->query->get('from') ?: null, $request->query->get('to') ?: null, $this->delivery->today());
            $emplacement = $request->query->get('emplacement') ?: null;
            if ($emplacement !== null && !Emplacements::exists($emplacement)) {
                throw new \InvalidArgumentException('Emplacement inconnu.');
            }
            $company = $request->query->get('company') ?: null;
            if ($company !== null && !ctype_digit($company)) {
                throw new \InvalidArgumentException('Entreprise invalide.');
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->stats->report($from, $to, $emplacement, $company === null ? null : (int) $company));
    }
}
