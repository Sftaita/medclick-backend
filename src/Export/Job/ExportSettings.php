<?php

namespace App\Export\Job;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réglages des exports asynchrones (variables d'environnement EXPORT_*, valeurs par défaut dans
 * config/services.yaml, documentées dans docs/EXPORTS.md).
 */
final class ExportSettings
{
    public function __construct(
        /** Dossier des fichiers générés (volume partagé entre l'API et le worker). */
        #[Autowire('%env(EXPORT_STORAGE_DIR)%')]
        public readonly string $storageDir,
        /** Durée de vie d'un fichier prêt avant suppression automatique. */
        #[Autowire('%env(int:EXPORT_FILE_TTL_MINUTES)%')]
        public readonly int $fileTtlMinutes,
        /** Durée maximale d'une génération (au-delà : échec « timeout »). */
        #[Autowire('%env(int:EXPORT_TIMEOUT_SECONDS)%')]
        public readonly int $timeoutSeconds,
        /** Sans signe de vie du worker pendant cette durée, une génération en cours est déclarée interrompue. */
        #[Autowire('%env(int:EXPORT_STALL_SECONDS)%')]
        public readonly int $stallSeconds,
        /** Une demande jamais prise en charge au-delà de cette durée échoue (worker indisponible). */
        #[Autowire('%env(int:EXPORT_PENDING_MAX_SECONDS)%')]
        public readonly int $pendingMaxSeconds,
        /** Délai après lequel un fichier prêt et jamais téléchargé est signalé. */
        #[Autowire('%env(int:EXPORT_NOT_DOWNLOADED_AFTER_MINUTES)%')]
        public readonly int $notDownloadedAfterMinutes,
        /** Nombre d'exports non téléchargés (24 h) déclenchant un récapitulatif par e-mail ; 0 = jamais. */
        #[Autowire('%env(int:EXPORT_NOT_DOWNLOADED_ALERT_THRESHOLD)%')]
        public readonly int $notDownloadedAlertThreshold,
        /** Destinataire des alertes (vide = aucune alerte e-mail, l'évènement reste dans l'historique). */
        #[Autowire('%env(EXPORT_ALERT_EMAIL)%')]
        public readonly string $alertEmail,
        /** Un même incident (type, catégorie, étape, code) n'est alerté qu'une fois par période. */
        #[Autowire('%env(int:EXPORT_ALERT_DEDUP_MINUTES)%')]
        public readonly int $alertDedupMinutes,
        /** Durée de conservation de l'historique des exports. */
        #[Autowire('%env(int:EXPORT_HISTORY_RETENTION_DAYS)%')]
        public readonly int $historyRetentionDays,
        /** Limite mémoire PHP appliquée pendant une génération (worker). */
        #[Autowire('%env(EXPORT_MEMORY_LIMIT)%')]
        public readonly string $memoryLimit,
        /** Base des liens de l'interface d'administration dans les alertes. */
        #[Autowire('%app.public_frontend_url%')]
        public readonly string $publicFrontendUrl,
    ) {
    }
}
