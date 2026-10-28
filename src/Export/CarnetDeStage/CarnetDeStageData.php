<?php

namespace App\Export\CarnetDeStage;

use App\Entity\User;
use App\Entity\Years;

/**
 * Données d'un carnet de stage, chargées en une passe (voir CarnetDeStageDataLoader), dans l'ordre
 * historique du carnet de stage : l'ordre des lignes du fichier Excel en dépend.
 */
final class CarnetDeStageData
{
    /**
     * @param list<Years> $allYears années de l'utilisateur (ordre de YearsRepository::findBy)
     * @param list<array{id: int, firstname: ?string, lastname: ?string, isManager: ?bool}> $surgeons superviseurs de l'année exportée
     * @param list<array<string, mixed>> $allSurgeries interventions de toutes les années (année par année)
     * @param list<array<string, mixed>> $currentYearSurgeries interventions de l'année exportée
     */
    public function __construct(
        public readonly User $user,
        public readonly Years $currentYear,
        public readonly array $allYears,
        public readonly array $surgeons,
        public readonly array $allSurgeries,
        public readonly array $currentYearSurgeries,
        public readonly array $consultations,
        public readonly array $uniqueDates,
        public readonly array $gardes,
        public readonly array $formations,
    ) {
    }
}
