<?php

namespace App\Export\Monitoring;

use App\Entity\ExportJob;
use App\Entity\ExportMonitorState;
use App\Export\Job\ExportAlerter;
use App\Export\Job\ExportSettings;
use App\Repository\ExportJobRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Présence du worker des exports, vue de l'extérieur du worker (docs/EXPORTS.md).
 *
 * - Le worker signale sa présence chaque minute (surveillance planifiée en son sein) : heartbeat().
 * - Le gardien planifié sur le serveur (cron, dans le conteneur de l'API) vérifie cette présence
 *   chaque minute, même si le worker a été arrêté à la main : check(). Worker absent = aucun signe
 *   de vie depuis EXPORT_WORKER_ABSENT_SECONDS et aucun export en cours qui avance.
 * - Une absence = un incident = un e-mail (renvoyé en cas d'échec d'envoi, délai croissant) ; aucun
 *   e-mail si un export bloqué par cet arrêt a déjà été signalé, et inversement (ExportAlerter).
 * - Une seule surveillance à la fois (worker, gardien, commande) : bail en base, acquire()/release().
 */
final class ExportWorkerWatch
{
    public const BY_WORKER = 'worker';
    public const BY_WATCHDOG = 'gardien';
    public const BY_COMMAND = 'commande';

    /** Durée du bail d'une surveillance (libéré à la fin ; expire si le processus s'arrête). */
    private const LEASE_SECONDS = 120;
    /** Le gardien est considéré actif s'il est passé dans ce délai (il tourne chaque minute). */
    private const WATCHDOG_ACTIVE_SECONDS = 300;

    public function __construct(
        private ManagerRegistry $doctrine,
        private ExportJobRepository $jobs,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ExportSettings $settings,
    ) {
    }

    /** Signe de vie du worker ; clôt l'absence en cours s'il y en a une. */
    public function heartbeat(): void
    {
        $this->ensureRow();
        $this->connection()->executeStatement('UPDATE export_monitor_state SET worker_seen_at = ? WHERE id = ?',
            [$this->format($this->now()), ExportMonitorState::ID]);
        $this->recovered();
    }

    /** Réserve la surveillance ; faux si une autre surveillance est en cours. */
    public function acquire(): bool
    {
        $this->ensureRow();
        $now = $this->now();

        return $this->connection()->executeStatement('UPDATE export_monitor_state SET run_lease_until = ?
                WHERE id = ? AND (run_lease_until IS NULL OR run_lease_until < ?)',
            [$this->format($now->modify('+' . self::LEASE_SECONDS . ' seconds')), ExportMonitorState::ID, $this->format($now)]) === 1;
    }

    public function release(string $by): void
    {
        $this->connection()->executeStatement('UPDATE export_monitor_state SET run_lease_until = NULL, last_run_at = ?, last_run_by = ? WHERE id = ?',
            [$this->format($this->now()), $by, ExportMonitorState::ID]);
    }

    /**
     * Passage du gardien : présence du worker, alerte d'absence (une par incident).
     *
     * @return array{workerAbsent: int, alerteWorker: int}
     */
    public function check(): array
    {
        $this->ensureRow();
        $now = $this->now();
        $this->connection()->executeStatement('UPDATE export_monitor_state SET last_watchdog_at = ? WHERE id = ?',
            [$this->format($now), ExportMonitorState::ID]);

        if ($this->workerAlive($this->state())) {
            $this->recovered();

            return ['workerAbsent' => 0, 'alerteWorker' => 0];
        }

        $state = $this->state();
        if ($state->getWorkerAbsentSince() === null) {
            // Début de l'absence : dernier signe de vie connu (ou maintenant si le worker n'a jamais été vu).
            $since = $state->getWorkerSeenAt() ?? $now;
            if ($this->connection()->executeStatement('UPDATE export_monitor_state SET worker_absent_since = ? WHERE id = ? AND worker_absent_since IS NULL',
                [$this->format($since), ExportMonitorState::ID]) === 1) {
                $this->logger->error('[export] Worker des exports absent', ['dernierSigneDeVie' => $state->getWorkerSeenAt()?->format(DATE_ATOM)]);
            }
            $state = $this->state();
        }

        // Worker jamais vu (premier démarrage) : délai de grâce avant l'alerte.
        if ($state->getWorkerSeenAt() === null && $state->getWorkerAbsentSince() > $now->modify('-' . $this->settings->workerAbsentSeconds . ' seconds')) {
            return ['workerAbsent' => 1, 'alerteWorker' => 0];
        }

        return ['workerAbsent' => 1, 'alerteWorker' => (int) $this->alert($state)];
    }

    /** Alerte « worker arrêté » envoyée pour l'absence en cours depuis $since (ExportAlerter : pas de doublon). */
    public function workerAlertSentSince(\DateTimeImmutable $since): bool
    {
        $this->ensureRow();
        $state = $this->state();

        return $state->getWorkerAbsentSince() !== null && $state->getWorkerAlertStatus() === ExportJob::ALERT_SENT
            && $state->getWorkerAlertSentAt() >= $since;
    }

    /**
     * État affiché dans l'administration.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $this->ensureRow();
        $state = $this->state();
        $alive = $this->workerAlive($state);
        $watchdogActive = $state->getLastWatchdogAt() !== null
            && $state->getLastWatchdogAt() >= $this->now()->modify('-' . self::WATCHDOG_ACTIVE_SECONDS . ' seconds');

        return [
            'worker' => [
                'status' => $alive ? 'running' : ($state->getWorkerSeenAt() === null ? 'unknown' : 'stopped'),
                'lastSeenAt' => $state->getWorkerSeenAt()?->format(DATE_ATOM),
                'absentSince' => $alive ? null : $state->getWorkerAbsentSince()?->format(DATE_ATOM),
                'alertStatus' => $alive ? null : $state->getWorkerAlertStatus(),
            ],
            'watchdog' => [
                'active' => $watchdogActive,
                'lastRunAt' => $state->getLastWatchdogAt()?->format(DATE_ATOM),
            ],
            'lastRun' => [
                'at' => $state->getLastRunAt()?->format(DATE_ATOM),
                'by' => $state->getLastRunBy(),
            ],
        ];
    }

    private function workerAlive(ExportMonitorState $state): bool
    {
        $now = $this->now();
        if ($state->getWorkerSeenAt() !== null && $state->getWorkerSeenAt() >= $now->modify('-' . $this->settings->workerAbsentSeconds . ' seconds')) {
            return true;
        }

        // Génération longue : le worker ne traite pas sa surveillance planifiée mais l'export avance.
        return $this->jobs->hasRunningHeartbeatSince($now->modify('-' . $this->settings->stallSeconds . ' seconds'));
    }

    private function recovered(): void
    {
        $state = $this->state();
        if ($state->getWorkerAbsentSince() === null) {
            return;
        }
        if ($this->connection()->executeStatement('UPDATE export_monitor_state SET worker_absent_since = NULL, worker_alert_status = ?,
                worker_alert_attempts = 0, worker_alert_last_attempt_at = NULL, worker_alert_last_error = NULL
                WHERE id = ? AND worker_absent_since IS NOT NULL', ['none', ExportMonitorState::ID]) === 1) {
            $this->logger->notice('[export] Worker des exports rétabli', [
                'absentDepuis' => $state->getWorkerAbsentSince()->format(DATE_ATOM),
                'alerte' => $state->getWorkerAlertStatus(),
            ]);
        }
    }

    private function alert(ExportMonitorState $state): bool
    {
        $status = $state->getWorkerAlertStatus();
        if (in_array($status, [ExportJob::ALERT_SENT, 'covered', ExportJob::ALERT_DISABLED], true)) {
            return false;
        }
        if ($this->settings->alertEmail === '') {
            $this->setAlert(['worker_alert_status' => ExportJob::ALERT_DISABLED]);

            return false;
        }
        // Un export bloqué par cet arrêt a déjà été signalé : même incident, pas de second e-mail.
        $dedupSince = $this->now()->modify('-' . $this->settings->alertDedupMinutes . ' minutes');
        if ($status === 'none' && $this->jobs->findWorkerAlertSentSince($dedupSince) !== null) {
            $this->setAlert(['worker_alert_status' => 'covered']);

            return false;
        }
        if ($status === ExportJob::ALERT_FAILED) {
            $delay = ExportAlerter::RETRY_DELAYS[$state->getWorkerAlertAttempts()] ?? null;
            if ($delay === null || $state->getWorkerAlertLastAttemptAt() > $this->now()->modify("-$delay minutes")) {
                return false;
            }
        }

        $now = $this->now();
        $attempts = $state->getWorkerAlertAttempts() + 1;
        try {
            $this->mailer->send($this->email($state));
        } catch (\Throwable $failure) {
            $this->setAlert(['worker_alert_status' => ExportJob::ALERT_FAILED, 'worker_alert_attempts' => $attempts,
                'worker_alert_last_attempt_at' => $this->format($now),
                'worker_alert_last_error' => $failure::class . ' : ' . mb_substr($failure->getMessage(), 0, 300)]);
            $this->logger->error('[export] Alerte « worker arrêté » non envoyée', ['failure' => $failure::class, 'attempt' => $attempts]);

            return false;
        }
        $this->setAlert(['worker_alert_status' => ExportJob::ALERT_SENT, 'worker_alert_attempts' => $attempts,
            'worker_alert_last_attempt_at' => $this->format($now), 'worker_alert_sent_at' => $this->format($now), 'worker_alert_last_error' => null]);

        return true;
    }

    private function email(ExportMonitorState $state): Email
    {
        $pending = $this->jobs->count(['status' => ExportJob::STATUS_PENDING]);
        $running = $this->jobs->count(['status' => ExportJob::STATUS_RUNNING]);
        $text = implode("\n", [
            'Le worker des exports Excel ne donne plus signe de vie : les exports demandés ne peuvent pas être générés.',
            '',
            'Dernier signe de vie : ' . $this->formatUtc($state->getWorkerSeenAt()),
            'Absent depuis : ' . $this->formatUtc($state->getWorkerAbsentSince()),
            'Exports en attente : ' . $pending . ' ; en cours : ' . $running,
            'Sans redémarrage, les exports en attente échoueront après ' . intdiv($this->settings->pendingMaxSeconds, 60) . ' minutes.',
            '',
            'Action : redémarrer le service « worker » du projet Docker de MedClick (docs/DEPLOIEMENT-VPS.md, exports Excel).',
            'Détail (connexion administrateur requise) : ' . rtrim($this->settings->publicFrontendUrl, '/') . '/admin/exports',
            '',
            'Un seul e-mail par arrêt ; le retour du worker est consigné dans les journaux.',
        ]);

        return (new Email())
            ->from('Medclick <service@medclick.be>')
            ->to($this->settings->alertEmail)
            ->subject('[MedClick] Worker des exports Excel arrêté — exports impossibles')
            ->text($text);
    }

    /** @param array<string, string|int|null> $values */
    private function setAlert(array $values): void
    {
        $this->connection()->update('export_monitor_state', $values, ['id' => ExportMonitorState::ID]);
    }

    private function state(): ExportMonitorState
    {
        $em = $this->doctrine->getManager();
        $state = $em->find(ExportMonitorState::class, ExportMonitorState::ID);
        $em->refresh($state);

        return $state;
    }

    private function ensureRow(): void
    {
        $connection = $this->connection();
        if ($connection->fetchOne('SELECT COUNT(*) FROM export_monitor_state WHERE id = ?', [ExportMonitorState::ID]) > 0) {
            return;
        }
        try {
            $connection->insert('export_monitor_state', ['id' => ExportMonitorState::ID, 'worker_alert_status' => 'none', 'worker_alert_attempts' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Créée au même instant par un autre processus.
        }
    }

    private function connection(): Connection
    {
        return $this->doctrine->getConnection();
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    private function format(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    private function formatUtc(?\DateTimeImmutable $date): string
    {
        return $date ? $date->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y H:i:s') . ' UTC' : 'jamais';
    }
}
