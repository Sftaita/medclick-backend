<?php

declare(strict_types=1);

namespace App\Advertising;

/**
 * Découpe des périodes (jours inclus, AAAA-MM-JJ, fin null = sans fin) en segments où le même
 * ensemble de contenus est en ligne : base du calendrier, des périodes libres et des conflits.
 */
final class Timeline
{
    /**
     * @param list<array{id: int, start: string, end: ?string}> $items
     * @return list<array{from: string, to: ?string, ids: list<int>}> segments à partir de $from,
     *         y compris les segments vides entre deux périodes (pas avant la première ni après
     *         la dernière, sauf $until : segments vides jusqu'à cette date)
     */
    public static function segments(array $items, string $from, ?string $until = null): array
    {
        $items = array_values(array_filter($items, static fn (array $i) => $i['end'] === null || $i['end'] >= $from));
        $bounds = [$from => true];
        foreach ($items as $item) {
            $bounds[max($item['start'], $from)] = true;
            if ($item['end'] !== null) {
                $bounds[self::next($item['end'])] = true;
            }
        }
        if ($until !== null) {
            $bounds[self::next($until)] = true;
        }
        $bounds = array_keys($bounds);
        sort($bounds);

        $segments = [];
        foreach ($bounds as $i => $start) {
            $to = isset($bounds[$i + 1]) ? self::previous($bounds[$i + 1]) : null;
            $ids = [];
            foreach ($items as $item) {
                if ($item['start'] <= $start && ($item['end'] === null || $item['end'] >= $start)) {
                    $ids[] = $item['id'];
                }
            }
            if ($to === null && $ids === []) {
                // Après la dernière période : rien à décrire (libre sans fin).
                if ($until === null || $start > $until) {
                    if ($segments === [] || $segments[array_key_last($segments)]['ids'] !== []) {
                        $segments[] = ['from' => $start, 'to' => null, 'ids' => []];
                    }
                    break;
                }
            }
            $last = $segments === [] ? null : $segments[array_key_last($segments)];
            if ($last !== null && $last['ids'] === $ids && $last['to'] !== null && self::next($last['to']) === $start) {
                $segments[array_key_last($segments)]['to'] = $to;
                continue;
            }
            $segments[] = ['from' => $start, 'to' => $to, 'ids' => $ids];
        }

        return $segments;
    }

    public static function next(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    }

    public static function previous(string $day): string
    {
        return (new \DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
    }

    /** Les deux périodes ont-elles au moins un jour en commun ? */
    public static function overlaps(string $startA, ?string $endA, string $startB, ?string $endB): bool
    {
        return ($endA === null || $endA >= $startB) && ($endB === null || $endB >= $startA);
    }
}
