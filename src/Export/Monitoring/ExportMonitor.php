<?php

namespace App\Export\Monitoring;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Export\Job\ExportAlerter;
use App\Export\Job\ExportJobManager;
use App\Export\Job\ExportSettings;
use App\Export\Job\ExportStorage;
use App\Repository\ExportJobRepository;

/**
 * Surveillance périodique des exports (chaque minute, voir ExportMonitorSchedule ; aussi
 * « bin/console app:exports:monitor ») :
 *
 * 1. exports en cours sans signe de vie, trop longs ou jamais pris en charge → échec + alerte ;
 * 2. fichiers prêts jamais téléchargés après EXPORT_NOT_DOWNLOADED_AFTER_MINUTES → évènement
 *    (+ récapitulatif e-mail éventuel, au plus un par 24 h) ;
 * 3. fichiers expirés → supprimés (l'historique est conservé) ;
 * 4. alertes e-mail en échec → nouvelle tentative (délai croissant, 5 au plus) ;
 * 5. historique plus ancien que EXPORT_HISTORY_RETENTION_DAYS → supprimé ;
 * 6. fichiers orphelins du dossier des exports (sans export connu, temporaires) → supprimés.
 */
final class ExportMonitor
{
    public function __construct(
        private ExportJobManager $manager,
        private ExportJobRepository $jobs,
        private ExportStorage $storage,
        private ExportAlerter $alerter,
        private ExportSettings $settings,
    ) {
    }

    /**
     * @return array<string, int> nombre d'exports traités par contrôle
     */
    public function run(): array
    {
        $now = $this->manager->now();
        $report = ['interrompus' => 0, 'nonTelecharges' => 0, 'recapitulatifs' => 0, 'fichiersSupprimes' => 0,
            'expires' => 0, 'alertesRenvoyees' => 0, 'historiqueSupprime' => 0, 'orphelins' => 0];
        $connection = $this->manager->em()->getConnection();

        foreach ($this->jobs->findBy(['status' => ExportJob::ACTIVE_STATUSES]) as $job) {
            $report['interrompus'] += (int) $this->manager->checkLiveness($job);
        }

        $notDownloadedBefore = $now->modify('-' . $this->settings->notDownloadedAfterMinutes . ' minutes');
        foreach ($this->jobs->findByStatusBefore([ExportJob::STATUS_READY], 'finishedAt', $notDownloadedBefore) as $job) {
            // Route synchrone : le fichier part dans la réponse elle-même, rien à télécharger ensuite.
            if ($job->getNotDownloadedAt() !== null || ($job->getParameters()['channel'] ?? null) === 'sync') {
                continue;
            }
            if ($connection->executeStatement('UPDATE export_job SET not_downloaded_at = ? WHERE id = ? AND not_downloaded_at IS NULL AND first_downloaded_at IS NULL',
                [$now->format('Y-m-d H:i:s'), $job->getId()]) === 1) {
                $this->manager->em()->refresh($job);
                $this->manager->event($job, ExportJobEvent::NOT_DOWNLOADED, $job->getStep(),
                    'Fichier prêt, jamais téléchargé après ' . $this->settings->notDownloadedAfterMinutes . ' min');
                ++$report['nonTelecharges'];
            }
        }
        $report['recapitulatifs'] = (int) $this->notDownloadedDigest($now);

        foreach ($this->jobs->findByStatusBefore([ExportJob::STATUS_READY, ExportJob::STATUS_DOWNLOADED], 'expiresAt', $now) as $job) {
            if ($job->getFileDeletedAt() !== null) {
                continue;
            }
            $this->storage->delete($job);
            if ($connection->executeStatement('UPDATE export_job SET file_deleted_at = ? WHERE id = ? AND file_deleted_at IS NULL',
                [$now->format('Y-m-d H:i:s'), $job->getId()]) === 1) {
                $this->manager->em()->refresh($job);
                $this->manager->event($job, ExportJobEvent::FILE_DELETED, $job->getStep(), 'Fichier supprimé à expiration');
                ++$report['fichiersSupprimes'];
            }
            if ($job->getStatus() === ExportJob::STATUS_READY
                && $connection->executeStatement('UPDATE export_job SET status = ? WHERE id = ? AND status = ?',
                    [ExportJob::STATUS_EXPIRED, $job->getId(), ExportJob::STATUS_READY]) === 1) {
                $this->manager->em()->refresh($job);
                $this->manager->event($job, ExportJobEvent::EXPIRED, $job->getStep(), 'Expiré sans téléchargement confirmé');
                ++$report['expires'];
            }
        }

        foreach ($this->jobs->findBy(['alertStatus' => ExportJob::ALERT_FAILED]) as $job) {
            if ($job->getAlertAttempts() < ExportAlerter::MAX_ATTEMPTS && $this->alerter->retry($job)) {
                ++$report['alertesRenvoyees'];
            }
        }

        $report['historiqueSupprime'] = $this->purgeHistory($now->modify('-' . $this->settings->historyRetentionDays . ' days'));

        $report['orphelins'] = count($this->storage->deleteOrphans(function (string $publicId): bool {
            $job = $this->jobs->findOneByPublicId($publicId);

            return $job !== null && $job->getFileDeletedAt() === null
                && in_array($job->getStatus(), [ExportJob::STATUS_RUNNING, ExportJob::STATUS_READY, ExportJob::STATUS_DOWNLOADED], true);
        }, $now->modify('-1 hour')));

        return $report;
    }

    private function notDownloadedDigest(\DateTimeImmutable $now): bool
    {
        $since = $now->modify('-24 hours');
        $em = $this->manager->em();
        $pending = $em->createQuery('SELECT j FROM App\Entity\ExportJob j WHERE j.notDownloadedAt >= :since
                AND j.firstDownloadedAt IS NULL AND j.notDownloadedAlertedAt IS NULL ORDER BY j.id ASC')
            ->setParameter('since', $since)
            ->getResult();
        $sentRecently = (int) $em->createQuery('SELECT COUNT(j.id) FROM App\Entity\ExportJob j WHERE j.notDownloadedAlertedAt >= :since')
            ->setParameter('since', $since)
            ->getSingleScalarResult() > 0;

        return $this->alerter->notDownloadedDigest($pending, $sentRecently);
    }

    private function purgeHistory(\DateTimeImmutable $before): int
    {
        $em = $this->manager->em();
        $ids = array_column($em->createQuery('SELECT j.id FROM App\Entity\ExportJob j WHERE j.requestedAt < :before')
            ->setParameter('before', $before)->setMaxResults(1000)->getArrayResult(), 'id');
        if ($ids === []) {
            return 0;
        }
        // Évènements d'abord (la clé étrangère est en cascade, mais pas forcément appliquée par tous les moteurs).
        $em->createQuery('DELETE FROM App\Entity\ExportJobEvent e WHERE e.job IN (:ids)')->setParameter('ids', $ids)->execute();

        return $em->createQuery('DELETE FROM App\Entity\ExportJob j WHERE j.id IN (:ids)')->setParameter('ids', $ids)->execute();
    }
}
