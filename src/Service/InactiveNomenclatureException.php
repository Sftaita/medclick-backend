<?php

namespace App\Service;

/**
 * Acte INAMI désactivé choisi pour une nouvelle intervention, un nouveau favori ou un changement
 * d'acte (ADR-210). Un code inactif reste accepté uniquement s'il est déjà lié et inchangé.
 */
final class InactiveNomenclatureException extends \DomainException
{
    /** Message affiché à l'utilisateur. */
    public const MESSAGE = "Ce code INAMI n'est plus actif. Choisissez un acte actif dans la liste.";

    /** Valeur de la clé `reason` des réponses JSON des contrôleurs custom (lue par la PWA). */
    public const REASON = 'nomenclature_inactive';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
