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
 * Même rapport en CSV pour l'annonceur (/api/admin/ad-stats.csv).
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
            [$from, $to, $emplacement, $company] = $this->filters($request);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->stats->report($from, $to, $emplacement, $company));
    }

    #[Route('/api/admin/ad-stats.csv', name: 'admin_ad_stats_csv', methods: ['GET'])]
    public function csv(Request $request): Response
    {
        try {
            [$from, $to, $emplacement, $company] = $this->filters($request);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        $name = sprintf('medclick-statistiques-%s-%s.csv', $from->format('Ymd'), $to->format('Ymd'));

        return new Response($this->stats->csv($from, $to, $emplacement, $company), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable, ?string, ?int}
     * @throws \InvalidArgumentException
     */
    private function filters(Request $request): array
    {
        [$from, $to] = SponsorStats::period($request->query->get('from') ?: null, $request->query->get('to') ?: null, $this->delivery->today());
        $emplacement = $request->query->get('emplacement') ?: null;
        if ($emplacement !== null && !Emplacements::exists($emplacement)) {
            throw new \InvalidArgumentException('Emplacement inconnu.');
        }
        $company = $request->query->get('company') ?: null;
        if ($company !== null && !ctype_digit($company)) {
            throw new \InvalidArgumentException('Entreprise invalide.');
        }

        return [$from, $to, $emplacement, $company === null ? null : (int) $company];
    }
}
