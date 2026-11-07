<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InamiSearchStat;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Statistiques de la recherche publique des codes INAMI : compteurs par jour (heure de
 * Belgique) des visites de la page et des spécialités consultées. Rien d'autre n'est
 * enregistré : ni visiteur, ni adresse IP, ni terme de recherche. Les publicités de la page sont
 * mesurées par le système existant (SponsorDelivery, emplacement inami_search).
 */
final class InamiSearchStats
{
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function today(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone(PartnerManager::TIMEZONE))
            ->setTime(0, 0);
    }

    /** Ajoute 1 au compteur du jour (créé au premier événement). */
    public function record(string $kind, string $speciality = ''): void
    {
        $day = $this->today()->format('Y-m-d');
        $increment = fn (): int => (int) $this->connection->executeStatement(
            'UPDATE inami_search_stat SET count = count + 1 WHERE day = ? AND kind = ? AND speciality = ?',
            [$day, $kind, $speciality],
        );
        if ($increment() > 0) {
            return;
        }
        try {
            $this->connection->insert('inami_search_stat', ['day' => $day, 'kind' => $kind, 'speciality' => $speciality, 'count' => 1]);
        } catch (UniqueConstraintViolationException) {
            // Créé entre-temps par une requête simultanée.
            $increment();
        }
    }

    /**
     * Synthèse des $days derniers jours (aujourd'hui compris).
     *
     * @return array{from: string, to: string, visits: int, daily: list<array{day: string, visits: int}>, specialities: list<array{speciality: string, count: int}>}
     */
    public function summary(int $days): array
    {
        $to = $this->today();
        $from = $to->modify(sprintf('-%d days', $days - 1));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT day, kind, speciality, count FROM inami_search_stat WHERE day >= ? AND day <= ? ORDER BY day',
            [$from, $to],
            [Types::DATE_IMMUTABLE, Types::DATE_IMMUTABLE],
        );

        $daily = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $daily[$day->format('Y-m-d')] = 0;
        }
        $specialities = [];
        foreach ($rows as $row) {
            $day = substr((string) $row['day'], 0, 10);
            if ($row['kind'] === InamiSearchStat::KIND_VISIT) {
                $daily[$day] = ($daily[$day] ?? 0) + (int) $row['count'];
            } elseif ($row['kind'] === InamiSearchStat::KIND_SPECIALITY) {
                $specialities[$row['speciality']] = ($specialities[$row['speciality']] ?? 0) + (int) $row['count'];
            }
        }
        // Les plus consultées d'abord, puis par identifiant (ordre stable).
        uksort($specialities, static fn (string|int $a, string|int $b) => [$specialities[$b], (string) $a] <=> [$specialities[$a], (string) $b]);

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'visits' => array_sum($daily),
            'daily' => array_map(static fn (string $day, int $visits) => ['day' => $day, 'visits' => $visits], array_keys($daily), array_values($daily)),
            'specialities' => array_map(static fn (string $speciality, int $count) => ['speciality' => $speciality, 'count' => $count], array_map('strval', array_keys($specialities)), array_values($specialities)),
        ];
    }
}
