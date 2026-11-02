<?php

namespace App\Service;

use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Repository\NomenclatureRepository;

/**
 * Choix de l'acte INAMI lors de la modification d'une intervention (PUT/PATCH API Platform et
 * ancienne route PUT /api/surgeries/update/{id}).
 *
 * Règle : le code, l'intitulé, la spécialité et la référence nomenclature d'une intervention
 * enregistrée forment son identité historique (copie figée, ADR-007). Ils ne sont recalculés
 * depuis la nomenclature que si l'utilisateur choisit réellement un AUTRE acte ; renvoyer
 * l'acte déjà lié, ou n'en envoyer aucun, ne doit jamais remplacer un ancien code par le code
 * actuel de la nomenclature.
 *
 * Étape 4 (ADR-210) : un acte INACTIF n'est accepté que s'il est déjà lié à l'intervention et
 * inchangé. Toute nouvelle intervention, tout nouveau favori et tout changement d'acte exigent un
 * code actif (assertSelectable).
 */
final class SurgeryNomenclatureSelection
{
    public function __construct(private NomenclatureRepository $nomenclatureRepository)
    {
    }

    /**
     * Acte choisi dans le payload (`surgeryId`, entier ou chaîne).
     *
     * @return Nomenclature|null null si aucun acte n'est envoyé (absent, null ou chaîne vide)
     *
     * @throws \InvalidArgumentException si l'identifiant est invalide ou inconnu
     */
    public function find(mixed $surgeryId): ?Nomenclature
    {
        if ($surgeryId === null || $surgeryId === '') {
            return null;
        }

        $id = is_int($surgeryId) || is_string($surgeryId) ? filter_var($surgeryId, FILTER_VALIDATE_INT) : false;
        $reference = $id !== false ? $this->nomenclatureRepository->find($id) : null;

        if (!$reference) {
            throw new \InvalidArgumentException('Nomenclature introuvable');
        }

        return $reference;
    }

    /** Vrai si l'acte choisi est différent de celui déjà lié à l'intervention. */
    public static function isAnotherAct(?Nomenclature $current, Nomenclature $selected): bool
    {
        return $current === null || $current->getId() !== $selected->getId();
    }

    /**
     * Un acte choisi pour une création ou un changement d'acte doit être actif.
     *
     * @throws InactiveNomenclatureException
     */
    public static function assertSelectable(Nomenclature $reference): void
    {
        if (!$reference->isActive()) {
            throw new InactiveNomenclatureException();
        }
    }

    /** Recopie ensemble la relation, le code, l'intitulé et la spécialité de l'acte choisi. */
    public static function applyAct(Surgeries $surgery, Nomenclature $reference): void
    {
        $surgery->setNomenclature($reference)
            ->setCode($reference->getCodeHospitalisation() . '' . $reference->getN())
            ->setName($reference->getName())
            ->setSpeciality($reference->getSpeciality());
    }
}
