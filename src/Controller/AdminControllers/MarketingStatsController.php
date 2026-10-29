<?php

namespace App\Controller\AdminControllers;

use App\Service\SponsorDelivery;
use App\Service\SponsorStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques publicitaires (ROLE_ADMIN : access_control ^/api/admin) : rapport d'une période
 * et export CSV pour l'annonceur. Paramètres : from, to (AAAA-MM-JJ, 30 derniers jours par
 * défaut), campaign (id) ou sponsor (nom de l'annonceur), facultatifs.
 */
#[Route('/api/admin')]
class MarketingStatsController extends AbstractController
{
    public function __construct(
        private readonly SponsorStats $stats,
        private readonly SponsorDelivery $delivery,
    ) {
    }

    #[Route('/marketing-stats', name: 'admin_marketing_stats', methods: ['GET'])]
    public function report(Request $request): JsonResponse
    {
        try {
            [$from, $to, $campaign, $sponsor] = $this->filters($request);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->stats->report($from, $to, $campaign, $sponsor));
    }

    #[Route('/marketing-stats.csv', name: 'admin_marketing_stats_csv', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        try {
            [$from, $to, $campaign, $sponsor] = $this->filters($request);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $name = sprintf('medclick-publicite-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd'));

        return new Response($this->stats->csv($from, $to, $campaign, $sponsor), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable, ?int, ?string} */
    private function filters(Request $request): array
    {
        [$from, $to] = SponsorStats::period($request->query->get('from') ?: null, $request->query->get('to') ?: null, $this->delivery->today());
        $campaign = $request->query->get('campaign');
        if ($campaign !== null && $campaign !== '' && !ctype_digit($campaign)) {
            throw new \InvalidArgumentException('Campagne invalide.');
        }
        $sponsor = trim((string) $request->query->get('sponsor', ''));

        return [$from, $to, $campaign ? (int) $campaign : null, $sponsor === '' ? null : $sponsor];
    }
}
