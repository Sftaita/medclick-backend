<?php

namespace App\Service;

use App\Entity\Nomenclature;

/**
 * Nouvelle date d'une intervention existante, sans changement d'acte, postérieure à l'abrogation
 * vérifiée de son code INAMI (ADR-210). La modification est refusée : le code historique n'est
 * jamais remplacé en silence ; l'utilisateur garde la date ou choisit un autre acte.
 */
final class RepealedOnDateException extends \DomainException
{
    /** Valeur de la clé `reason` des réponses JSON des contrôleurs custom (lue par la PWA). */
    public const REASON = 'nomenclature_repealed_on_date';

    public function __construct(Nomenclature $reference, \DateTimeInterface $date)
    {
        parent::__construct(sprintf(
            "Le code INAMI %s de cette intervention a été abrogé le %s : la date du %s n'est pas possible avec cet acte. Gardez une date antérieure ou choisissez un autre acte.",
            $reference->getCodeHospitalisation() . $reference->getN(),
            $reference->getVerifiedRepealDate()?->format('d/m/Y'),
            $date->format('d/m/Y'),
        ));
    }
}
