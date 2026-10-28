<?php

namespace App\Service;

use App\Entity\Marketing;
use App\Entity\MarketingView;
use App\Repository\MarketingRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Statistiques publicitaires de l'administration (docs/SPONSORS.md) : impressions réellement
 * vues et clics (dédoublonnés, voir SponsorDelivery), CTR, par campagne, annonceur, emplacement,
 * jour et mois. Uniquement des agrégats : aucune donnée d'utilisateur n'en sort.
 *
 * Les « vues historiques » (legacy_view : une par appel d'API, avant la mesure réelle) et les
 * compteurs historiques de la campagne sont présentés à part, jamais additionnés aux impressions.
 */
class SponsorStats
{
    public const MAX_DAYS = 366;

    public const PLACEMENT_LABELS = [
        Marketing::PLACEMENT_LOGIN => 'Connexion',
        Marketing::PLACEMENT_DASHBOARD_DESKTOP => 'Tableau de bord (ordinateur)',
        Marketing::PLACEMENT_DASHBOARD_MOBILE => 'Tableau de bord (mobile)',
        Marketing::PLACEMENT_LOGIN_INTERSTITIAL => 'Plein écran à la connexion',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly MarketingRepository $campaigns,
    ) {
    }

    /** Impressions et clics de toute la diffusion, par campagne. @return array<int, array{impressions: int, clicks: int}> */
    public function totalsByCampaign(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT marketing_id, kind, COUNT(*) AS n FROM marketing_view WHERE kind IN (?) GROUP BY marketing_id, kind',
            [[MarketingView::KIND_IMPRESSION, MarketingView::KIND_CLICK]],
            [ArrayParameterType::STRING],
        );
        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row['marketing_id']] ??= ['impressions' => 0, 'clicks' => 0];
            $totals[(int) $row['marketing_id']][$row['kind'] === MarketingView::KIND_CLICK ? 'clicks' : 'impressions'] = (int) $row['n'];
        }

        return $totals;
    }

    /**
     * Rapport d'une période (jours inclus), éventuellement limité à une campagne ou un annonceur.
     *
     * @throws \InvalidArgumentException période invalide
     */
    public function report(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $campaignId = null, ?string $sponsor = null): array
    {
        $rows = $this->rows($from, $to, $campaignId, $sponsor);
        $campaigns = $this->campaignsFor($campaignId, $sponsor);

        $byCampaign = [];
        $bySponsor = [];
        $byPlacement = [];
        $daily = [];
        $monthly = [];
        $totals = self::empty();
        foreach ($rows as $row) {
            $id = (int) $row['marketing_id'];
            $key = $row['kind'] === MarketingView::KIND_CLICK ? 'clicks' : 'impressions';
            $n = (int) $row['n'];
            $day = substr((string) $row['day'], 0, 10);
            $sponsorName = ($campaigns[$id] ?? null)?->getSponsorName() ?? 'Sans annonceur';

            $byCampaign[$id]['total'][$key] = ($byCampaign[$id]['total'][$key] ?? 0) + $n;
            $byCampaign[$id]['placements'][$row['placement']][$key] = ($byCampaign[$id]['placements'][$row['placement']][$key] ?? 0) + $n;
            $bySponsor[$sponsorName][$key] = ($bySponsor[$sponsorName][$key] ?? 0) + $n;
            $byPlacement[$row['placement']][$key] = ($byPlacement[$row['placement']][$key] ?? 0) + $n;
            $daily[$day][$key] = ($daily[$day][$key] ?? 0) + $n;
            $monthly[substr($day, 0, 7)][$key] = ($monthly[substr($day, 0, 7)][$key] ?? 0) + $n;
            $totals[$key] += $n;
        }

        $legacy = $this->legacyViews($campaignId, $sponsor);
        $campaignRows = [];
        foreach ($campaigns as $id => $campaign) {
            $placements = [];
            foreach ($campaign->getPlacements() as $placement) {
                $placements[] = ['placement' => $placement, 'label' => self::PLACEMENT_LABELS[$placement]] + self::metrics($byCampaign[$id]['placements'][$placement] ?? []);
            }
            // Emplacement retiré depuis, mais qui a eu des événements sur la période.
            foreach ($byCampaign[$id]['placements'] ?? [] as $placement => $values) {
                if (!$campaign->hasPlacement($placement)) {
                    $placements[] = ['placement' => $placement, 'label' => self::PLACEMENT_LABELS[$placement] ?? $placement] + self::metrics($values);
                }
            }
            $campaignRows[] = [
                'id' => $id,
                'name' => $campaign->getCampaignName(),
                'sponsor' => $campaign->getSponsorName(),
                'offer' => $campaign->getOffer(),
                'status' => $campaign->getStatus(),
                'startDate' => $campaign->getStartDate()?->format('Y-m-d'),
                'endDate' => $campaign->getEndDate()?->format('Y-m-d'),
                'placements' => $placements,
                'legacyViews' => $legacy[$id] ?? 0,
                'legacyClicks' => $campaign->getClicks() ?? 0,
            ] + self::metrics($byCampaign[$id]['total'] ?? []);
        }
        usort($campaignRows, static fn (array $a, array $b) => [$b['impressions'], $a['name']] <=> [$a['impressions'], $b['name']]);

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = ['date' => $day->format('Y-m-d')] + self::metrics($daily[$day->format('Y-m-d')] ?? []);
        }
        $months = [];
        for ($month = $from->modify('first day of this month'); $month <= $to; $month = $month->modify('+1 month')) {
            $months[] = ['month' => $month->format('Y-m')] + self::metrics($monthly[$month->format('Y-m')] ?? []);
        }

        $sponsors = [];
        foreach ($bySponsor as $name => $values) {
            $sponsors[] = ['sponsor' => $name] + self::metrics($values);
        }
        usort($sponsors, static fn (array $a, array $b) => $b['impressions'] <=> $a['impressions']);

        $placementRows = [];
        foreach (Marketing::PLACEMENTS as $placement) {
            if (isset($byPlacement[$placement])) {
                $placementRows[] = ['placement' => $placement, 'label' => self::PLACEMENT_LABELS[$placement]] + self::metrics($byPlacement[$placement]);
            }
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'totals' => self::metrics($totals),
            'campaigns' => $campaignRows,
            'sponsors' => $sponsors,
            'placements' => $placementRows,
            'daily' => $days,
            'monthly' => $months,
        ];
    }

    /**
     * Rapport CSV pour l'annonceur (séparateur « ; », UTF-8 avec BOM : ouverture directe dans
     * Excel) : une ligne par jour, campagne et emplacement ayant eu au moins un événement.
     */
    public function csv(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $campaignId = null, ?string $sponsor = null): string
    {
        $campaigns = $this->campaignsFor($campaignId, $sponsor);
        $lines = [];
        foreach ($this->rows($from, $to, $campaignId, $sponsor) as $row) {
            $key = substr((string) $row['day'], 0, 10) . '|' . $row['marketing_id'] . '|' . $row['placement'];
            $lines[$key] ??= ['day' => substr((string) $row['day'], 0, 10), 'id' => (int) $row['marketing_id'], 'placement' => $row['placement'], 'impressions' => 0, 'clicks' => 0];
            $lines[$key][$row['kind'] === MarketingView::KIND_CLICK ? 'clicks' : 'impressions'] += (int) $row['n'];
        }
        ksort($lines);

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Annonceur', 'Campagne', 'Emplacement', 'Impressions', 'Clics', 'CTR (%)'], ';', '"', '');
        $total = self::empty();
        foreach ($lines as $line) {
            $campaign = $campaigns[$line['id']] ?? null;
            $metrics = self::metrics($line);
            $total['impressions'] += $line['impressions'];
            $total['clicks'] += $line['clicks'];
            fputcsv($out, [
                $line['day'],
                self::cell($campaign?->getSponsorName() ?? ''),
                self::cell($campaign?->getCampaignName() ?? '#' . $line['id']),
                self::PLACEMENT_LABELS[$line['placement']] ?? $line['placement'],
                $line['impressions'],
                $line['clicks'],
                self::percent($metrics['ctr']),
            ], ';', '"', '');
        }
        fputcsv($out, ['Total ' . $from->format('Y-m-d') . ' au ' . $to->format('Y-m-d'), '', '', '', $total['impressions'], $total['clicks'], self::percent(self::metrics($total)['ctr'])], ';', '"', '');
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @throws \InvalidArgumentException */
    public static function period(?string $from, ?string $to, string $today): array
    {
        $parse = static function (?string $value, string $label): \DateTimeImmutable {
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if (!$date || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException("$label invalide (AAAA-MM-JJ).");
            }

            return $date;
        };
        $end = $to ? $parse($to, 'Date de fin') : $parse($today, 'Date du jour');
        $start = $from ? $parse($from, 'Date de début') : $end->modify('-29 days');
        if ($start > $end) {
            throw new \InvalidArgumentException('La date de début doit précéder la date de fin.');
        }
        if ($start->diff($end)->days >= self::MAX_DAYS) {
            throw new \InvalidArgumentException(sprintf('Période de %d jours au maximum.', self::MAX_DAYS));
        }

        return [$start, $end];
    }

    /** @return list<array{marketing_id: int|string, kind: string, placement: string, day: string, n: int|string}> */
    private function rows(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $campaignId, ?string $sponsor): array
    {
        $ids = $campaignId !== null || $sponsor !== null ? array_keys($this->campaignsFor($campaignId, $sponsor)) : null;
        if ($ids === []) {
            return [];
        }
        $sql = 'SELECT marketing_id, kind, placement, DATE(viewed_at) AS day, COUNT(*) AS n FROM marketing_view'
            . ' WHERE kind IN (?) AND viewed_at >= ? AND viewed_at < ?'
            . ($ids !== null ? ' AND marketing_id IN (?)' : '')
            . ' GROUP BY marketing_id, kind, placement, DATE(viewed_at)';
        $params = [[MarketingView::KIND_IMPRESSION, MarketingView::KIND_CLICK], $from->format('Y-m-d 00:00:00'), $to->modify('+1 day')->format('Y-m-d 00:00:00')];
        $types = [ArrayParameterType::STRING, 'string', 'string'];
        if ($ids !== null) {
            $params[] = $ids;
            $types[] = ArrayParameterType::INTEGER;
        }

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    /** @return array<int, Marketing> */
    private function campaignsFor(?int $campaignId, ?string $sponsor): array
    {
        $criteria = [];
        if ($campaignId !== null) {
            $criteria['id'] = $campaignId;
        }
        if ($sponsor !== null) {
            $criteria['sponsorName'] = $sponsor;
        }
        $list = [];
        foreach ($this->campaigns->findBy($criteria, ['id' => 'ASC']) as $campaign) {
            $list[$campaign->getId()] = $campaign;
        }

        return $list;
    }

    /** @return array<int, int> */
    private function legacyViews(?int $campaignId, ?string $sponsor): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT marketing_id, COUNT(*) AS n FROM marketing_view WHERE kind = ? GROUP BY marketing_id',
            [MarketingView::KIND_LEGACY],
        );
        $ids = $campaignId !== null || $sponsor !== null ? $this->campaignsFor($campaignId, $sponsor) : null;
        $legacy = [];
        foreach ($rows as $row) {
            if ($ids === null || isset($ids[(int) $row['marketing_id']])) {
                $legacy[(int) $row['marketing_id']] = (int) $row['n'];
            }
        }

        return $legacy;
    }

    /** @return array{impressions: int, clicks: int, ctr: ?float} CTR en %, null sans impression */
    public static function metrics(array $values): array
    {
        $impressions = (int) ($values['impressions'] ?? 0);
        $clicks = (int) ($values['clicks'] ?? 0);

        return ['impressions' => $impressions, 'clicks' => $clicks, 'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null];
    }

    private static function empty(): array
    {
        return ['impressions' => 0, 'clicks' => 0];
    }

    private static function percent(?float $ctr): string
    {
        return $ctr === null ? '' : number_format($ctr, 2, ',', '');
    }

    /** Cellule sans formule interprétable par un tableur (injection CSV). */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
