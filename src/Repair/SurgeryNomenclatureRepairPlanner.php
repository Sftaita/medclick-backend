<?php

namespace App\Repair;

/**
 * LOT 2D.2 — classement d'une intervention vis-à-vis du bug du PUT corrigé en 2D.1 (le nom suivait
 * la nouvelle intervention, pas la relation nomenclature ni le code).
 *
 * Classe pure (tableaux en entrée, aucune dépendance) : utilisée par la commande
 * app:repair-surgery-nomenclature et exécutable telle quelle pour un diagnostic en lecture seule.
 *
 * Une intervention n'est réparable (A1) que si TOUTES les conditions sont réunies :
 * - code actuel = code de la nomenclature liée (SOURCE) ;
 * - nom actuel différent du nom de SOURCE ;
 * - une et une seule autre nomenclature (TARGET) porte exactement ce nom (comparaison binaire) ;
 * - TARGET possède nom, spécialité et code d'hospitalisation ;
 * - spécialité actuelle = celle de TARGET ou « favorites » (seuls cas produits par le bug) ;
 * - intervention créée par addNewSurgery (createdAt renseigné) : sinon cas historique A2, exclu.
 * Sinon : aucune modification. Pas d'approximation.
 */
final class SurgeryNomenclatureRepairPlanner
{
    public const VERSION = 'repair-surgery-nomenclature/1';

    public const REPAIRABLE = 'A1';               // incohérence certaine, cible unique : réparée
    public const HISTORICAL = 'A2';               // signature, cible unique, mais antérieure à createdAt
    public const SPECIALITY_MISMATCH = 'B';       // signature, cible unique, spécialité inattendue
    public const AMBIGUOUS = 'C';                 // plusieurs nomenclatures portent ce nom
    public const NO_EXACT_TARGET = 'sans_cible';  // nom différent mais aucune nomenclature de ce nom exact
    public const INCOMPLETE_TARGET = 'cible_incomplete';
    public const CONSISTENT = 'coherente';        // déjà correcte
    public const OUT_OF_SCOPE = 'D';              // autre incohérence (historique, favorites, sans nomenclature)

    /** @var array<int, array{id:int, name:?string, code:string, codeHospitalisation:?string, speciality:?string}> */
    private array $byId = [];

    /** @var array<string, list<int>> nom exact => ids */
    private array $byName = [];

    /**
     * @param iterable<array{id:int|string, name:?string, code_hospitalisation:?string, n:?string, speciality:?string}> $nomenclatures
     */
    public function __construct(iterable $nomenclatures)
    {
        foreach ($nomenclatures as $row) {
            $id = (int) $row['id'];
            $this->byId[$id] = [
                'id' => $id,
                'name' => $row['name'],
                // Même construction que addNewSurgery : codeHospitalisation . '' . n
                'code' => ($row['code_hospitalisation'] ?? '') . '' . ($row['n'] ?? ''),
                'codeHospitalisation' => $row['code_hospitalisation'],
                'speciality' => $row['speciality'],
            ];
            if ($row['name'] !== null) {
                $this->byName[$row['name']][] = $id;
            }
        }
    }

    /**
     * @param array{id:int|string, nomenclature_id:int|string|null, code:?string, name:?string, speciality:?string, created_at:?string} $surgery
     *
     * @return array{status:string, target:?array{id:int, name:?string, code:string, codeHospitalisation:?string, speciality:?string}}
     */
    public function classify(array $surgery): array
    {
        $source = $surgery['nomenclature_id'] !== null ? ($this->byId[(int) $surgery['nomenclature_id']] ?? null) : null;
        if ($source === null) {
            return ['status' => self::OUT_OF_SCOPE, 'target' => null];
        }

        if (($surgery['code'] ?? '') !== $source['code']) {
            return ['status' => self::OUT_OF_SCOPE, 'target' => null];
        }

        if ($surgery['name'] === $source['name']) {
            $status = $surgery['speciality'] === $source['speciality'] ? self::CONSISTENT : self::OUT_OF_SCOPE;

            return ['status' => $status, 'target' => null];
        }

        $targets = array_values(array_filter(
            $this->byName[$surgery['name'] ?? ''] ?? [],
            fn (int $id) => $id !== $source['id'],
        ));

        if ($targets === []) {
            return ['status' => self::NO_EXACT_TARGET, 'target' => null];
        }
        if (count($targets) > 1) {
            return ['status' => self::AMBIGUOUS, 'target' => null];
        }

        $target = $this->byId[$targets[0]];
        if ($target['name'] === null || $target['name'] === '' || $target['speciality'] === null || $target['speciality'] === ''
            || $target['codeHospitalisation'] === null || $target['codeHospitalisation'] === '') {
            return ['status' => self::INCOMPLETE_TARGET, 'target' => $target];
        }

        if ($surgery['speciality'] !== $target['speciality'] && $surgery['speciality'] !== 'favorites') {
            return ['status' => self::SPECIALITY_MISMATCH, 'target' => $target];
        }

        if ($surgery['created_at'] === null) {
            return ['status' => self::HISTORICAL, 'target' => $target];
        }

        return ['status' => self::REPAIRABLE, 'target' => $target];
    }
}
