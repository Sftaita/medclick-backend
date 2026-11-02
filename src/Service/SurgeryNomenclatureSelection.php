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
     * Un acte choisi pour une création ou un changement d'acte doit être utilisable à la date de
     * l'intervention : actif, ou inactif avec une date officielle d'abrogation VÉRIFIÉE postérieure
     * à l'intervention (saisie tardive). Sans date : seulement un code actif.
     *
     * @throws InactiveNomenclatureException
     */
    public static function assertSelectable(Nomenclature $reference, ?\DateTimeInterface $date = null): void
    {
        $usable = $date !== null ? $reference->isUsableOn($date) : $reference->isActive();
        if (!$usable) {
            throw $date !== null ? InactiveNomenclatureException::forDate($reference, $date) : new InactiveNomenclatureException();
        }
    }

    /**
     * Intervention existante dont l'acte est inchangé : sa nouvelle date ne doit pas franchir
     * l'abrogation VÉRIFIÉE de son code (le code historique n'est jamais remplacé en silence).
     * Date inchangée, code actif ou abrogation non vérifiée : aucune restriction (aucune période
     * de validité supposée, données historiques préservées).
     *
     * @throws RepealedOnDateException
     */
    public static function assertDateKeepsAct(?Nomenclature $current, ?\DateTimeInterface $previousDate, ?\DateTimeInterface $newDate): void
    {
        if ($current === null || $newDate === null || $previousDate?->format('Y-m-d') === $newDate->format('Y-m-d')) {
            return;
        }
        // Étape 5 : une intervention DÉJÀ datée dans la période abrogée (saisie avant les règles,
        // 18 807 en production) reste corrigible, même vers une autre date de cette période : le
        // code historique est conservé tel quel et un avertissement est renvoyé (repealWarning).
        // Seul le FRANCHISSEMENT de l'abrogation (date antérieure → date postérieure) est refusé.
        $wasRepealed = $previousDate !== null && $current->isKnownRepealedOn($previousDate);
        if (!$wasRepealed && $current->isKnownRepealedOn($newDate)) {
            throw new RepealedOnDateException($current, $newDate);
        }
    }

    /**
     * Avertissement (sans refus) : le code historique conservé est officiellement abrogé à la date
     * de l'intervention. Jamais de recodage automatique.
     */
    public static function repealWarning(?Nomenclature $current, ?\DateTimeInterface $date): ?string
    {
        if ($current === null || $date === null || !$current->isKnownRepealedOn($date)) {
            return null;
        }

        return sprintf(
            "Le code INAMI %s de cette intervention est officiellement abrogé depuis le %s. Il est conservé tel quel ; choisissez un autre acte pour le remplacer.",
            $current->getCodeHospitalisation() . $current->getN(),
            $current->getVerifiedRepealDate()->format('d/m/Y'),
        );
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
