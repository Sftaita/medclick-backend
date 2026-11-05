<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\NomenclatureRepository;
use function Symfony\Component\String\u;

/**
 * Recherche publique des codes INAMI (/codes-inami, docs/Design/recherche-inami/SPECIFICATIONS.md).
 *
 * Lit à chaque appel la nomenclature ACTUELLE (codes proposés aujourd'hui, comme les listes de
 * sélection : actifs, sans abrogation vérifiée atteinte, codes partagés compris), puis filtre,
 * trie et découpe en pages ici, de la même manière quelle que soit la base (MySQL ou SQLite) :
 * - texte : insensible à la casse et aux accents ; plusieurs mots = tous présents dans le nom ;
 * - chiffres seuls (au moins 2, espaces, points et tirets ignorés) : code ambulatoire ou
 *   hospitalisation contenant ces chiffres ;
 * - tri « relevance » : noms qui commencent par la recherche d'abord, puis ordre alphabétique ;
 *   « name » : alphabétique ; « nk » : lettre (K, N) puis valeur numérique.
 * Une spécialité compte au plus quelques milliers de codes : le filtrage en mémoire reste rapide.
 */
final class PublicNomenclatureSearch
{
    public const PAGE_SIZE = 25;
    public const MAX_PAGE = 400;
    public const MAX_QUERY_LENGTH = 100;
    public const ALL = 'all';
    public const SORTS = ['relevance', 'name', 'nk'];
    public const ORDERS = ['asc', 'desc'];

    public function __construct(private readonly NomenclatureRepository $nomenclature)
    {
    }

    /** @return list<array{id: string, count: int}> */
    public function specialities(): array
    {
        return $this->nomenclature->fetchPublicSpecialities();
    }

    /**
     * @return array{items: list<array<string, ?string>>, total: int, page: int, pageSize: int, pages: int}
     */
    public function search(string $speciality, string $query, string $sort, string $order, int $page): array
    {
        $rows = $this->nomenclature->fetchPublicCodes($speciality === self::ALL ? null : $speciality);
        $rows = self::filter($rows, $query);
        $rows = self::sort($rows, $sort, $order === 'desc' ? -1 : 1, $query);

        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $items = array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return [
            'items' => array_map(static fn (array $row) => [
                'name' => $row['name'],
                'codeAmbulant' => self::code($row['codeAmbulant']),
                'codeHospitalisation' => self::code($row['codeHospitalisation']),
                'n' => $row['n'] === null || trim($row['n']) === '' ? null : trim($row['n']),
                'speciality' => $row['speciality'],
            ], $items),
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'pages' => $pages,
        ];
    }

    /** Minuscules sans accents, apostrophes remplacées par une espace. */
    public static function normalize(string $text): string
    {
        return u($text)->ascii()->lower()->replaceMatches("/['’`]/", ' ')->toString();
    }

    /** Chiffres d'une recherche par code (« 289-414 » → « 289414 »), ou null pour une recherche texte. */
    public static function codeQuery(string $query): ?string
    {
        $query = trim($query);
        $digits = preg_replace('/\D/', '', $query);

        return preg_match('/^[\d\s.\-]+$/', $query) === 1 && strlen($digits) >= 2 ? $digits : null;
    }

    /**
     * @param list<array<string, ?string>> $rows
     * @return list<array<string, ?string>>
     */
    private static function filter(array $rows, string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return $rows;
        }
        $digits = self::codeQuery($query);
        if ($digits !== null) {
            return array_values(array_filter($rows, static fn (array $row) => str_contains(preg_replace('/\D/', '', (string) $row['codeAmbulant']), $digits)
                || str_contains(preg_replace('/\D/', '', (string) $row['codeHospitalisation']), $digits)));
        }
        $words = preg_split('/\s+/', self::normalize($query), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($rows, static function (array $row) use ($words): bool {
            $name = self::normalize($row['name']);
            foreach ($words as $word) {
                if (!str_contains($name, $word)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param list<array<string, ?string>> $rows
     * @return list<array<string, ?string>>
     */
    private static function sort(array $rows, string $sort, int $direction, string $query): array
    {
        $prefix = self::codeQuery($query) === null ? trim(self::normalize($query)) : '';
        $keyed = array_map(static fn (array $row) => [$row, self::normalize($row['name']), self::nk($row['n'])], $rows);
        usort($keyed, static function (array $a, array $b) use ($sort, $direction, $prefix): int {
            if ($sort === 'nk') {
                return ([$a[2][0], $a[2][1]] <=> [$b[2][0], $b[2][1]]) * $direction ?: strcmp($a[1], $b[1]);
            }
            if ($sort === 'relevance' && $prefix !== '') {
                $byPrefix = (str_starts_with($a[1], $prefix) ? 0 : 1) <=> (str_starts_with($b[1], $prefix) ? 0 : 1);
                if ($byPrefix !== 0) {
                    return $byPrefix;
                }
            }

            return strcmp($a[1], $b[1]) * $direction;
        });

        return array_map(static fn (array $entry) => $entry[0], $keyed);
    }

    /** « N200 », « K 180 », « 225 » → [lettre, valeur] pour le tri. */
    private static function nk(?string $value): array
    {
        $value = trim((string) $value);
        if (preg_match('/^([NKnk])\s*([\d.,]+)$/', $value, $match) === 1) {
            return [strtoupper($match[1]), (float) str_replace(',', '.', $match[2])];
        }

        return ['', is_numeric(str_replace(',', '.', $value)) ? (float) str_replace(',', '.', $value) : -1.0];
    }

    private static function code(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : $code;
    }
}
