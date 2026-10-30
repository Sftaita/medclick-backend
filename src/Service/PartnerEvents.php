<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\PartnerPlacementAssignment as Assignment;
use App\Repository\PartnerRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mesure des emplacements partenaires : mêmes règles que les campagnes (SponsorDelivery).
 * Impression = au moins 50 % visible pendant 1 s (contrôlé par le front) ; un visiteur n'est
 * compté qu'une fois par firme, emplacement et type sur 30 minutes. Visiteur = empreinte HMAC
 * quotidienne, effacée après 24 h : aucune donnée nominative, aucune IP.
 */
final class PartnerEvents
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerRepository $partners,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone(PartnerManager::TIMEZONE));
    }

    /** @return bool true si l'événement a été compté */
    public function record(Partner $partner, string $placement, string $kind, string $viewer): bool
    {
        $now = $this->now();
        $since = $now->sub(new \DateInterval(SponsorDelivery::DEDUP_WINDOW));
        $hashes = array_values(array_unique(array_map(
            fn (string $day) => hash_hmac('sha256', 'partner|' . $viewer . '|' . $day, $this->secret),
            [$now->format('Y-m-d'), $since->format('Y-m-d')],
        )));
        $local = static fn (\DateTimeImmutable $d) => \DateTime::createFromFormat('Y-m-d H:i:s', $d->format('Y-m-d H:i:s'));

        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'UPDATE partner_event SET viewer_hash = NULL WHERE viewer_hash IS NOT NULL AND viewed_at < ?',
            [$local($now->sub(new \DateInterval(SponsorDelivery::VIEWER_RETENTION)))],
            [Types::DATETIME_MUTABLE],
        );
        $duplicate = $connection->fetchOne(
            'SELECT 1 FROM partner_event WHERE partner_id = ? AND placement = ? AND kind = ? AND viewer_hash IN (?) AND viewed_at >= ?',
            [$partner->getId(), $placement, $kind, $hashes, $local($since)],
            [Types::INTEGER, Types::STRING, Types::STRING, ArrayParameterType::STRING, Types::DATETIME_MUTABLE],
        );
        if ($duplicate !== false) {
            return false;
        }
        $this->em->persist(new PartnerEvent($partner, $placement, $kind, $hashes[0], $local($now)));
        $this->em->flush();

        return true;
    }

    /**
     * Rapport d'une période (jours inclus) : totaux, par firme (et par emplacement), par
     * emplacement, par jour. Agrégats uniquement.
     */
    public function report(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT partner_id, placement, kind, DATE(viewed_at) AS day, COUNT(*) AS n FROM partner_event'
            . ' WHERE viewed_at >= ? AND viewed_at < ? GROUP BY partner_id, placement, kind, DATE(viewed_at)',
            [$from->format('Y-m-d 00:00:00'), $to->modify('+1 day')->format('Y-m-d 00:00:00')],
        );
        $byPartner = [];
        $byPlacement = [];
        $daily = [];
        $totals = [];
        foreach ($rows as $row) {
            $key = $row['kind'] === PartnerEvent::KIND_CLICK ? 'clicks' : 'impressions';
            $n = (int) $row['n'];
            $id = (int) $row['partner_id'];
            $day = substr((string) $row['day'], 0, 10);
            $byPartner[$id]['total'][$key] = ($byPartner[$id]['total'][$key] ?? 0) + $n;
            $byPartner[$id]['placements'][$row['placement']][$key] = ($byPartner[$id]['placements'][$row['placement']][$key] ?? 0) + $n;
            $byPlacement[$row['placement']][$key] = ($byPlacement[$row['placement']][$key] ?? 0) + $n;
            $daily[$day][$key] = ($daily[$day][$key] ?? 0) + $n;
            $totals[$key] = ($totals[$key] ?? 0) + $n;
        }

        $names = [];
        foreach ($byPartner === [] ? [] : $this->partners->findBy(['id' => array_keys($byPartner)]) as $partner) {
            $names[$partner->getId()] = $partner->getName();
        }
        $partners = [];
        foreach ($byPartner as $id => $values) {
            $placements = [];
            foreach (Assignment::PLACEMENTS as $placement) {
                if (isset($values['placements'][$placement])) {
                    $placements[] = ['placement' => $placement, 'label' => Assignment::LABELS[$placement]] + SponsorStats::metrics($values['placements'][$placement]);
                }
            }
            $partners[] = ['id' => $id, 'name' => $names[$id] ?? '#' . $id, 'placements' => $placements] + SponsorStats::metrics($values['total']);
        }
        usort($partners, static fn (array $a, array $b) => [$b['impressions'], $a['name']] <=> [$a['impressions'], $b['name']]);

        $placementRows = [];
        foreach (Assignment::PLACEMENTS as $placement) {
            $placementRows[] = ['placement' => $placement, 'label' => Assignment::LABELS[$placement]] + SponsorStats::metrics($byPlacement[$placement] ?? []);
        }
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $days[] = ['date' => $day->format('Y-m-d')] + SponsorStats::metrics($daily[$day->format('Y-m-d')] ?? []);
        }

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'totals' => SponsorStats::metrics($totals),
            'partners' => $partners,
            'placements' => $placementRows,
            'daily' => $days,
        ];
    }

    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }
}
