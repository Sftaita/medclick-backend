<?php

namespace App\Service;

use App\Entity\Nomenclature;

/**
 * Spécialité demandée non autorisée pour l'acte choisi (ADR-211) : ni sa spécialité principale, ni
 * une spécialité avec laquelle le code est officiellement partagé.
 */
final class SpecialityNotAllowedException extends \DomainException
{
    /** Valeur de la clé `reason` des réponses JSON des contrôleurs custom. */
    public const REASON = 'speciality_not_allowed';

    public function __construct(Nomenclature $reference, string $speciality)
    {
        parent::__construct(sprintf(
            "L'acte %s ne peut pas être enregistré dans la spécialité « %s ».",
            $reference->getCodeHospitalisation() . $reference->getN(),
            $speciality,
        ));
    }
}
