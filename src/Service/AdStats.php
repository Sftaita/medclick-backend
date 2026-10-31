<?php

declare(strict_types=1);

namespace App\Service;

use App\Advertising\Emplacements;
use App\Entity\MarketingView;
use App\Entity\PartnerEvent;
use App\Repository\PartnerRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Statistiques de l'administration « Publicités & partenaires » : impressions réellement vues,
 * clics et taux de clics, publicités (marketing_view) et encarts d'entreprise (partner_event)
 * réunis, mais toujours ventilés par placement technique : un carrousel n'est jamais additionné
 * à un encart partenaire (Emplacements::STAT_LABELS). Les aperçus de l'administration n'envoient
 * aucun événement ; les anciens affichages « legacy_view » ne sont jamais comptés.
 */
final class AdStats
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PartnerRepository $partners,
    ) {
    }

    /**
     * @param ?string $emplacement clé d'Emplacements (filtre)
     * @param ?int $companyId entreprise (filtre)
     */
    public function report(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $emplacement = null, ?int $companyId = null): array
    {
        $range = [$from->format('Y-m-d 00:00:00'), $to->modify('+1 day')->format('Y-m-d 00:00:00')];
        $rows = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT v.placement, v.kind, DATE(v.viewed_at) AS day, m.partner_id, m.sponsor_name, v.marketing_id, m.campaign_name, COUNT(*) AS n'
            . ' FROM marketing_view v INNER JOIN marketing m ON m.id = v.marketing_id'
            . ' WHERE v.kind IN (?) AND v.viewed_at >= ? AND v.viewed_at < ?'
            . ' GROUP BY v.placement, v.kind, DATE(v.viewed_at), m.partner_id, m.sponsor_name, v.marketing_id, m.campaign_name',
            [[MarketingView::KIND_IMPRESSION, MarketingView::KIND_CLICK], ...$range],
            [ArrayParameterType::STRING],
        ) as $row) {
            $rows[] = ['source' => 'ad', 'content' => 'ad:' . $row['marketing_id'], 'contentName' => $row['campaign_name']] + $row;
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT placement, kind, DATE(viewed_at) AS day, partner_id, COUNT(*) AS n FROM partner_event'
            . ' WHERE viewed_at >= ? AND viewed_at < ? GROUP BY placement, kind, DATE(viewed_at), partner_id',
            $range,
        ) as $row) {
            $rows[] = ['source' => 'partner', 'content' => 'partner:' . $row['partner_id'] . ':' . $row['placement'], 'contentName' => null, 'sponsor_name' => null] + $row;
        }

        $names = [];
        foreach ($this->partners->findAll() as $partner) {
            $names[$partner->getId()] = $partner->getName();
        }

        $totals = [];
        $daily = [];
        $byPlacement = [];
        $byCompany = [];
        $byContent = [];
        foreach ($rows as $row) {
            $placementKey = $row['placement'];
            $rowEmplacement = $row['source'] === 'ad' ? Emplacements::forCampaignPlacement($placementKey) : Emplacements::forPartnerPlacement($placementKey);
            if ($emplacement !== null && $rowEmplacement !== $emplacement) {
                continue;
            }
            $company = $row['partner_id'] !== null ? (int) $row['partner_id'] : null;
            if ($companyId !== null && $company !== $companyId) {
                continue;
            }
            $key = $row['kind'] === PartnerEvent::KIND_CLICK ? 'clicks' : 'impressions';
            $n = (int) $row['n'];
            $day = substr((string) $row['day'], 0, 10);
            $companyKey = $company !== null ? (string) $company : 'sponsor:' . ($row['sponsor_name'] ?? '');

            $totals[$key] = ($totals[$key] ?? 0) + $n;
            $daily[$day][$key] = ($daily[$day][$key] ?? 0) + $n;
            $byPlacement[$placementKey][$key] = ($byPlacement[$placementKey][$key] ?? 0) + $n;
            $byCompany[$companyKey]['name'] = $company !== null ? ($names[$company] ?? '#' . $company) : ($row['sponsor_name'] ?: 'Sans entreprise');
            $byCompany[$companyKey]['id'] = $company;
            $byCompany[$companyKey][$key] = ($byCompany[$companyKey][$key] ?? 0) + $n;
            $contentKey = $row['content'] . '|' . $placementKey;
            $byContent[$contentKey] ??= [
                'type' => $row['source'],
                'name' => $row['contentName'] ?? ($names[$company] ?? '#' . $company),
                'company' => $byCompany[$companyKey]['name'],
                'placement' => $placementKey,
                'label' => Emplacements::STAT_LABELS[$placementKey] ?? $placementKey,
                'emplacement' => $rowEmplacement,
            ];
            $byContent[$contentKey][$key] = ($byContent[$contentKey][$key] ?? 0) + $n;
        }

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = ['date' => $day->format('Y-m-d')] + SponsorStats::metrics($daily[$day->format('Y-m-d')] ?? []);
        }
        $placements = [];
        foreach (Emplacements::STAT_LABELS as $placement => $label) {
            $owner = Emplacements::forCampaignPlacement($placement) ?? Emplacements::forPartnerPlacement($placement);
            if ($emplacement !== null && $owner !== $emplacement) {
                continue;
            }
            $placements[] = ['placement' => $placement, 'label' => $label, 'emplacement' => $owner] + SponsorStats::metrics($byPlacement[$placement] ?? []);
        }
        $companies = array_map(static fn (array $c) => ['id' => $c['id'], 'name' => $c['name']] + SponsorStats::metrics($c), array_values($byCompany));
        usort($companies, static fn (array $a, array $b) => [$b['impressions'], $a['name']] <=> [$a['impressions'], $b['name']]);
        $contents = array_map(static fn (array $c) => array_diff_key($c, ['impressions' => 1, 'clicks' => 1]) + SponsorStats::metrics($c), array_values($byContent));
        usort($contents, static fn (array $a, array $b) => [$b['impressions'], $a['name']] <=> [$a['impressions'], $b['name']]);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'totals' => SponsorStats::metrics($totals),
            'daily' => $days,
            'placements' => $placements,
            'companies' => $companies,
            'contents' => $contents,
        ];
    }
}
