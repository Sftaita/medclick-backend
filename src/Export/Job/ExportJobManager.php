<?php

namespace App\Export\Job;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Entity\User;
use App\Entity\Years;
use App\Export\CarnetDeStage\CarnetDeStageExporter;
use App\Export\ExportFailure;
use App\Export\ExportStep;
use App\Export\Job\Message\GenerateExport;
use App\Repository\ExportJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle de vie d'un export : demande, prise en charge, fin, échec, annulation, surveillance.
 *
 * Les changements de statut sont des mises à jour CONDITIONNELLES en base
 * (« UPDATE … WHERE status IN (…) ») : l'API, le worker et la surveillance peuvent agir en même
 * temps sur un export sans jamais s'écraser (ex. annulation pendant la prise en charge, export
 * déclaré bloqué qui se termine finalement). Chaque transition ajoute un évènement à l'historique.
 */
final class ExportJobManager
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private ExportJobRepository $jobs,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LockFactory $locks,
        private LoggerInterface $logger,
        private ExportSettings $settings,
        private ExportStorage $storage,
        private ExportAlerter $alerter,
    ) {
    }

    /**
     * Demande d'export du carnet de stage d'une année. Une génération identique encore en cours
     * est renvoyée au lieu d'en créer une seconde (double clic, deux onglets, rafraîchissement).
     *
     * @return array{0: ExportJob, 1: bool} [export, créé]
     */
    public function request(User $user, Years $year): array
    {
        $parameters = ['yearId' => $year->getId(), 'yearOfFormation' => $year->getYearOfFormation()];

        // Verrou par utilisateur : deux requêtes simultanées ne créent qu'un export.
        $lock = $this->locks->createLock('export-request-' . $user->getId(), 30);
        $lock->acquire(true);
        try {
            $existing = $this->jobs->findActiveDuplicate($user->getId(), CarnetDeStageExporter::TYPE, $parameters);
            if ($existing !== null && !$this->checkLiveness($existing)) {
                $this->event($existing, ExportJobEvent::REUSED, $existing->getStep(), 'Demande identique : export en cours réutilisé');

                return [$existing, false];
            }

            $publicId = Uuid::v4()->toRfc4122();
            $job = new ExportJob($publicId, bin2hex(random_bytes(8)), $user->getId(), CarnetDeStageExporter::TYPE, $parameters, $this->now());
            $this->em()->persist($job);
            $this->event($job, ExportJobEvent::REQUESTED, ExportStep::Queued->value, null, ['yearOfFormation' => $parameters['yearOfFormation']]);
        } finally {
            $lock->release();
        }

        $this->log('info', $job, 'Export demandé');
        $this->bus->dispatch(new GenerateExport($job->getId()));

        return [$job, true];
    }

    /**
     * Prise en charge par le worker. Faux si l'export n'est plus en attente (annulé, déjà traité,
     * ou message redélivré après l'interruption d'un worker : l'export est alors déclaré échoué).
     */
    public function start(ExportJob $job): bool
    {
        if ($job->getStatus() === ExportJob::STATUS_RUNNING) {
            // Message redélivré : le worker précédent s'est arrêté pendant la génération.
            $this->fail($job, new ExportFailure(ExportFailure::WORKER_INTERRUPTED, 'Message redélivré après interruption du worker', ExportStep::tryFrom($job->getStep())), ExportJobEvent::REDELIVERED);

            return false;
        }

        $now = $this->now();
        if (!$this->transition($job, [ExportJob::STATUS_PENDING], ExportJob::STATUS_RUNNING, [
            'started_at' => $now, 'heartbeat_at' => $now, 'step' => ExportStep::Preparing->value, 'attempts' => $job->getAttempts() + 1,
        ])) {
            return false;
        }
        $this->event($job, ExportJobEvent::STARTED, ExportStep::Preparing->value, null, ['attempt' => $job->getAttempts()]);
        $this->log('info', $job, 'Génération commencée');

        return true;
    }

    /**
     * @param int|null $durationMs durée mesurée par le worker (horloge monotone, à la milliseconde)
     */
    public function complete(ExportJob $job, string $path, int $rowCount, ?int $durationMs = null): void
    {
        $now = $this->now();
        $ready = $this->transition($job, [ExportJob::STATUS_RUNNING], ExportJob::STATUS_READY, [
            'finished_at' => $now,
            'duration_ms' => $durationMs ?? $this->elapsedMs($job, $now),
            'step' => ExportStep::Ready->value,
            'processed' => null,
            'total' => null,
            'row_count' => $rowCount,
            'file_name' => basename($path),
            'file_size' => filesize($path),
            'file_sha256' => hash_file('sha256', $path),
            'expires_at' => $now->modify('+' . $this->settings->fileTtlMinutes . ' minutes'),
        ]);
        if (!$ready) {
            // Déclaré échoué ou annulé entre-temps : le fichier n'est pas proposé.
            @unlink($path);
            $this->log('warning', $job, 'Fichier généré après la fin de l\'export (statut ' . $job->getStatus() . ') : supprimé');

            return;
        }
        $this->event($job, ExportJobEvent::READY, ExportStep::Ready->value, null, [
            'durationMs' => $job->getDurationMs(), 'rows' => $rowCount, 'size' => $job->getFileSize(),
        ]);
        $this->log('info', $job, 'Export prêt', ['durationMs' => $job->getDurationMs(), 'rows' => $rowCount]);
    }

    /**
     * Échec qualifié : statut, catégorie, code, étape, évènement, journal technique et alerte.
     */
    public function fail(ExportJob $job, \Throwable $exception, string $eventType = ExportJobEvent::FAILED,
        array $from = ExportJob::ACTIVE_STATUSES, array $unchanged = [], ?int $durationMs = null): bool
    {
        // Après une erreur SQL, le gestionnaire d'entités a pu être rouvert : export rechargé.
        $job = $this->managed($job);
        $category = $exception instanceof ExportFailure ? $exception->category : ExportFailure::UNEXPECTED;
        $step = $exception instanceof ExportFailure && $exception->step ? $exception->step->value : $job->getStep();
        $code = ExportErrorDescriber::code($exception);
        $message = ExportErrorDescriber::message($category);
        $now = $this->now();

        if (!$this->transition($job, $from, ExportJob::STATUS_FAILED, [
            'finished_at' => $now,
            'duration_ms' => $durationMs ?? $this->elapsedMs($job, $now),
            'error_category' => $category,
            'error_code' => mb_substr($code, 0, 255),
            'error_message' => $message,
            'error_step' => $step,
        ], $unchanged)) {
            return false;
        }
        $this->event($job, $eventType, $step, $message, ['category' => $category, 'code' => $code]);
        $this->log('error', $job, 'Export échoué', ['category' => $category, 'step' => $step] + ExportErrorDescriber::logContext($exception));

        $this->alerter->onFailure($job);

        return true;
    }

    /**
     * Annulation demandée par l'utilisateur : immédiate si l'export attend encore, sinon le worker
     * s'arrête à sa prochaine étape de progression.
     */
    public function cancel(ExportJob $job): void
    {
        if ($this->transition($job, [ExportJob::STATUS_PENDING], ExportJob::STATUS_CANCELLED, ['finished_at' => $this->now()])) {
            $this->event($job, ExportJobEvent::CANCELLED, $job->getStep(), 'Annulé avant la prise en charge');

            return;
        }
        if ($job->getStatus() === ExportJob::STATUS_RUNNING && !$job->isCancelRequested()) {
            $this->em()->getConnection()->update('export_job', ['cancel_requested' => 1], ['id' => $job->getId()]);
            $this->em()->refresh($job);
            $this->event($job, ExportJobEvent::CANCEL_REQUESTED, $job->getStep());
        }
    }

    public function markCancelled(ExportJob $job): void
    {
        if ($this->transition($job, [ExportJob::STATUS_RUNNING], ExportJob::STATUS_CANCELLED, [
            'finished_at' => $this->now(), 'duration_ms' => $this->elapsedMs($job, $this->now()),
        ])) {
            $this->event($job, ExportJobEvent::CANCELLED, $job->getStep(), 'Annulé pendant la génération');
            $this->log('info', $job, 'Export annulé');
        }
    }

    /**
     * Surveillance d'un export non terminé : sans signe de vie du worker depuis EXPORT_STALL_SECONDS,
     * ou en cours depuis plus que la durée maximale, ou jamais pris en charge après
     * EXPORT_PENDING_MAX_SECONDS, il est déclaré échoué (et l'administrateur alerté). Appelée par la
     * tâche planifiée ET à chaque consultation de l'état : un worker arrêté net ne laisse jamais un
     * export « en cours » indéfiniment.
     *
     * @return bool vrai si l'export vient d'être déclaré échoué
     */
    public function checkLiveness(ExportJob $job): bool
    {
        $now = $this->now();
        if ($job->getStatus() === ExportJob::STATUS_RUNNING) {
            $heartbeat = $job->getHeartbeatAt() ?? $job->getStartedAt() ?? $job->getRequestedAt();
            if ($heartbeat < $now->modify('-' . $this->settings->stallSeconds . ' seconds')) {
                return $this->failIfStill($job, ExportFailure::WORKER_INTERRUPTED,
                    'Aucun signe de vie du worker depuis ' . ($now->getTimestamp() - $heartbeat->getTimestamp()) . ' s');
            }
            $started = $job->getStartedAt() ?? $job->getRequestedAt();
            // Marge de 60 s : le worker applique lui-même la limite ; ceci couvre un worker figé.
            if ($started < $now->modify('-' . ($this->settings->timeoutSeconds + 60) . ' seconds')) {
                return $this->failIfStill($job, ExportFailure::TIMEOUT, 'Durée maximale dépassée');
            }
        }
        if ($job->getStatus() === ExportJob::STATUS_PENDING
            && $job->getRequestedAt() < $now->modify('-' . $this->settings->pendingMaxSeconds . ' seconds')) {
            return $this->failIfStill($job, ExportFailure::WORKER_UNAVAILABLE, 'Jamais pris en charge par un worker');
        }

        return false;
    }

    public function event(ExportJob $job, string $type, ?string $step = null, ?string $message = null, ?array $context = null): ExportJobEvent
    {
        $event = new ExportJobEvent($job, $type, $this->now(), $step, $message, $context);
        $this->em()->persist($event);
        $this->em()->flush();

        return $event;
    }

    public function log(string $level, ExportJob $job, string $message, array $context = []): void
    {
        $this->logger->log($level, "[export {$job->getCorrelationId()}] $message", [
            'export' => $job->getPublicId(), 'correlationId' => $job->getCorrelationId(), 'type' => $job->getType(),
            'userId' => $job->getUserId(), 'status' => $job->getStatus(), 'step' => $job->getStep(),
        ] + $context);
    }

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }

    /**
     * Gestionnaire d'entités utilisable (rouvert si une erreur SQL l'a fermé pendant la génération).
     */
    public function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->doctrine->getManager();
        if (!$em->isOpen()) {
            $this->doctrine->resetManager();
            $em = $this->doctrine->getManager();
        }

        return $em;
    }

    /** L'export, rattaché au gestionnaire courant (rechargé si celui-ci a été rouvert). */
    public function managed(ExportJob $job): ExportJob
    {
        $em = $this->em();

        return $em->contains($job) ? $job : ($em->find(ExportJob::class, $job->getId()) ?? $job);
    }

    private function failIfStill(ExportJob $job, string $category, string $detail): bool
    {
        // Conditionnel : si le worker vient d'envoyer un signe de vie, l'export n'est pas touché.
        $unchanged = $job->getHeartbeatAt() ? ['heartbeat_at' => $job->getHeartbeatAt()] : [];
        // Worker interrompu : la génération s'est arrêtée au dernier signe de vie, pas à la détection.
        $durationMs = $category === ExportFailure::WORKER_INTERRUPTED && $job->getStartedAt() && $job->getHeartbeatAt()
            ? 1000 * ($job->getHeartbeatAt()->getTimestamp() - $job->getStartedAt()->getTimestamp())
            : null;

        return $this->fail($job, new ExportFailure($category, $detail, ExportStep::tryFrom($job->getStep())),
            ExportJobEvent::STALLED, [$job->getStatus()], $unchanged, $durationMs);
    }

    /**
     * UPDATE conditionnel du statut ; recharge l'entité. Faux si le statut n'était plus dans $from.
     *
     * @param array<string, mixed> $fields colonnes à écrire en même temps
     * @param array<string, mixed> $unchanged colonnes qui doivent encore avoir cette valeur
     */
    private function transition(ExportJob $job, array $from, string $to, array $fields = [], array $unchanged = []): bool
    {
        $connection = $this->em()->getConnection();
        $set = ['status = ?'];
        $values = [$to];
        foreach ($fields as $column => $value) {
            $set[] = "$column = ?";
            $values[] = $value instanceof \DateTimeImmutable ? $value->format('Y-m-d H:i:s') : $value;
        }
        $where = 'id = ? AND status IN (' . implode(', ', array_fill(0, count($from), '?')) . ')';
        $values = [...$values, $job->getId(), ...$from];
        foreach ($unchanged as $column => $value) {
            $where .= " AND $column = ?";
            $values[] = $value instanceof \DateTimeImmutable ? $value->format('Y-m-d H:i:s') : $value;
        }

        $changed = $connection->executeStatement('UPDATE export_job SET ' . implode(', ', $set) . " WHERE $where", $values) === 1;
        $this->em()->refresh($job);

        return $changed;
    }

    private function elapsedMs(ExportJob $job, \DateTimeImmutable $now): ?int
    {
        $start = $job->getStartedAt();

        return $start ? (int) round(((float) $now->format('U.u') - (float) $start->format('U.u')) * 1000) : null;
    }
}
