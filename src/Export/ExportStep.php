<?php

namespace App\Export;

/**
 * Étapes d'un export, dans l'ordre d'exécution. Chaque libellé décrit l'opération réellement
 * en cours côté serveur (affiché tel quel par le frontend).
 */
enum ExportStep: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Loading = 'loading';
    case Template = 'template';
    case Surgeries = 'surgeries';
    case Supervisors = 'supervisors';
    case Summary = 'summary';
    case Sheets = 'sheets';
    case Writing = 'writing';
    case Ready = 'ready';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En attente de traitement…',
            self::Preparing => "Préparation de l'export…",
            self::Loading => 'Récupération des interventions…',
            self::Template => 'Chargement du modèle Excel…',
            self::Surgeries => 'Traitement des interventions',
            self::Supervisors => 'Décompte par superviseur',
            self::Summary => 'Calcul de la liste récapitulative',
            self::Sheets => 'Génération des feuilles Excel…',
            self::Writing => 'Finalisation du fichier…',
            self::Ready => 'Fichier prêt à télécharger.',
        };
    }
}
