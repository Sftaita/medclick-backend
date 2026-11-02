<?php

namespace App\Service;

use App\Entity\Nomenclature;

/**
 * Acte INAMI inutilisable à la date de l'intervention, choisi pour une nouvelle intervention, un
 * nouveau favori ou un changement d'acte (ADR-210). Un code inactif reste accepté s'il est déjà lié
 * et inchangé, ou, pour une saisie tardive, si l'intervention précède sa date officielle
 * d'abrogation VÉRIFIÉE.
 */
final class InactiveNomenclatureException extends \DomainException
{
    /** Message affiché à l'utilisateur (code inactif sans date d'abrogation vérifiée applicable). */
    public const MESSAGE = "Ce code INAMI n'est plus actif. Choisissez un acte actif dans la liste.";

    /** Valeur de la clé `reason` des réponses JSON des contrôleurs custom (lue par la PWA). */
    public const REASON = 'nomenclature_inactive';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }

    /** Code inactif pour cette date : abrogation vérifiée atteinte, ou pas de date vérifiée. */
    public static function forDate(Nomenclature $reference, \DateTimeInterface $date): self
    {
        $repealed = $reference->getVerifiedRepealDate();
        if ($repealed === null) {
            return new self();
        }

        return new self(sprintf(
            'Le code INAMI %s a été abrogé le %s : il ne peut servir que pour une intervention antérieure à cette date (intervention du %s).',
            $reference->getCodeHospitalisation() . $reference->getN(),
            $repealed->format('d/m/Y'),
            $date->format('d/m/Y'),
        ));
    }
}
