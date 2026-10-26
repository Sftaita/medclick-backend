<?php

namespace App\Export\Job;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Export\ExportStep;

/**
 * Représentation JSON d'un export (API utilisateur et administration). Dates ISO 8601.
 *
 * Progression : « processed / total » et « percent » portent sur l'étape en cours et ne sont
 * fournis que si l'étape a un total réel ; sinon le frontend affiche une animation d'activité.
 * Aucun pourcentage global : la durée relative des étapes n'est pas connue à l'avance.
 */
final class ExportJobPresenter
{
    /** Étapes présentées à l'utilisateur, dans l'ordre d'exécution. */
    private const STEPS = [ExportStep::Preparing, ExportStep::Loading, ExportStep::Template, ExportStep::Surgeries,
        ExportStep::Summary, ExportStep::Sheets, ExportStep::Writing];

    public function __construct(private ExportStorage $storage)
    {
    }

    /**
     * @param list<ExportJobEvent> $events
     */
    public function forUser(ExportJob $job, array $events, ?int $ahead = null): array
    {
        $step = ExportStep::tryFrom($job->getStep()) ?? ExportStep::Queued;
        $percent = null;
        if ($job->getTotal() !== null && $job->getProcessed() !== null) {
            $percent = $job->getTotal() > 0 ? (int) floor(100 * min($job->getProcessed(), $job->getTotal()) / $job->getTotal()) : 100;
        }
        $downloadable = in_array($job->getStatus(), [ExportJob::STATUS_READY, ExportJob::STATUS_DOWNLOADED], true)
            && $job->getFileDeletedAt() === null && $this->storage->exists($job);

        return [
            'id' => $job->getPublicId(),
            'diagnosticId' => $job->getDiagnosticId(),
            'type' => $job->getType(),
            'yearId' => $job->getParameters()['yearId'] ?? null,
            'status' => $job->getStatus(),
            'step' => $step->value,
            'stepLabel' => $step->label(),
            'processed' => $job->getProcessed(),
            'total' => $job->getTotal(),
            'percent' => $percent,
            'queuePosition' => $job->getStatus() === ExportJob::STATUS_PENDING ? $ahead : null,
            'rowCount' => $job->getRowCount(),
            'requestedAt' => self::date($job->getRequestedAt()),
            'startedAt' => self::date($job->getStartedAt()),
            'finishedAt' => self::date($job->getFinishedAt()),
            'durationMs' => $job->getDurationMs(),
            'fileSize' => $downloadable ? $job->getFileSize() : null,
            'expiresAt' => $downloadable ? self::date($job->getExpiresAt()) : null,
            'downloadUrl' => $downloadable ? 'exports/' . $job->getPublicId() . '/download' : null,
            'downloaded' => $job->getFirstDownloadedAt() !== null,
            'cancelRequested' => $job->isCancelRequested(),
            'error' => $job->getStatus() === ExportJob::STATUS_FAILED ? [
                'category' => $job->getErrorCategory(),
                'message' => $job->getErrorMessage(),
            ] : null,
            'steps' => $this->steps($job, $events),
        ];
    }

    /**
     * Étapes connues, avec leur état réel : faite (et sa durée mesurée), en cours, à venir.
     *
     * @param list<ExportJobEvent> $events
     */
    public function steps(ExportJob $job, array $events): array
    {
        $reached = [];
        foreach ($events as $event) {
            if ($event->getType() === ExportJobEvent::STEP && ($step = ExportStep::tryFrom((string) $event->getStep()))) {
                $reached[$step->value] = ['atMs' => $event->getContext()['atMs'] ?? null, 'total' => $event->getContext()['total'] ?? null];
            }
        }
        if ($job->getStartedAt() !== null) {
            // La préparation commence à la prise en charge par le worker.
            $reached = [ExportStep::Preparing->value => ['atMs' => 0, 'total' => null]] + $reached;
        }
        // État de la dernière étape atteinte, selon l'issue de l'export.
        $lastState = match (true) {
            $job->isActive() => 'running',
            $job->getStatus() === ExportJob::STATUS_FAILED => 'failed',
            $job->getStatus() === ExportJob::STATUS_CANCELLED => 'stopped',
            default => 'done',
        };

        $result = [];
        foreach (self::STEPS as $index => $step) {
            $info = $reached[$step->value] ?? null;
            // Début de l'étape atteinte suivante (= fin de celle-ci), s'il y en a une.
            $next = null;
            foreach (array_slice(self::STEPS, $index + 1) as $later) {
                if (isset($reached[$later->value])) {
                    $next = $reached[$later->value]['atMs'];
                    break;
                }
            }
            $state = $info === null ? 'pending' : ($next !== null ? 'done' : $lastState);
            $end = $next ?? ($job->isActive() ? null : $job->getDurationMs());
            $result[] = [
                'step' => $step->value,
                'label' => $step->label(),
                'state' => $state,
                'total' => $info['total'] ?? null,
                'durationMs' => $info !== null && $info['atMs'] !== null && $end !== null ? max(0, $end - $info['atMs']) : null,
            ];
        }

        return $result;
    }

    /**
     * Vue administrateur : historique complet, erreurs, alertes, téléchargements. Aucun lien de
     * téléchargement (l'administrateur n'a pas accès aux fichiers des utilisateurs).
     *
     * @param list<ExportJobEvent> $events
     */
    public function forAdmin(ExportJob $job, ?string $email, array $events = [], bool $withEvents = false): array
    {
        $data = [
            'id' => $job->getPublicId(),
            'diagnosticId' => $job->getDiagnosticId(),
            'correlationId' => $job->getCorrelationId(),
            'type' => $job->getType(),
            'parameters' => $job->getParameters(),
            'user' => ['id' => $job->getUserId(), 'email' => $email],
            'status' => $job->getStatus(),
            'step' => $job->getStep(),
            'stepLabel' => (ExportStep::tryFrom($job->getStep()) ?? ExportStep::Queued)->label(),
            'processed' => $job->getProcessed(),
            'total' => $job->getTotal(),
            'rowCount' => $job->getRowCount(),
            'attempts' => $job->getAttempts(),
            'requestedAt' => self::date($job->getRequestedAt()),
            'startedAt' => self::date($job->getStartedAt()),
            'finishedAt' => self::date($job->getFinishedAt()),
            'heartbeatAt' => self::date($job->getHeartbeatAt()),
            'durationMs' => $job->getDurationMs(),
            'file' => [
                'size' => $job->getFileSize(),
                'expiresAt' => self::date($job->getExpiresAt()),
                'deletedAt' => self::date($job->getFileDeletedAt()),
            ],
            'download' => [
                'attempts' => $job->getDownloadAttempts(),
                'firstServedAt' => self::date($job->getFirstServedAt()),
                'firstConfirmedAt' => self::date($job->getFirstDownloadedAt()),
                'notDownloadedAt' => self::date($job->getNotDownloadedAt()),
            ],
            'error' => $job->getErrorCategory() !== null ? [
                'category' => $job->getErrorCategory(),
                'code' => $job->getErrorCode(),
                'message' => $job->getErrorMessage(),
                'step' => $job->getErrorStep(),
                'stepLabel' => ExportStep::tryFrom((string) $job->getErrorStep())?->label(),
            ] : null,
            'alert' => [
                'status' => $job->getAlertStatus(),
                'attempts' => $job->getAlertAttempts(),
                'sentAt' => self::date($job->getAlertSentAt()),
                'lastAttemptAt' => self::date($job->getAlertLastAttemptAt()),
                'lastError' => $job->getAlertLastError(),
                'duplicateOf' => $job->getAlertDuplicateOf(),
            ],
        ];
        if ($withEvents) {
            $data['steps'] = $this->steps($job, $events);
            $data['events'] = array_map(fn (ExportJobEvent $e) => [
                'type' => $e->getType(),
                'occurredAt' => self::date($e->getOccurredAt()),
                'step' => $e->getStep(),
                'message' => $e->getMessage(),
                'context' => $e->getContext(),
            ], $events);
        }

        return $data;
    }

    private static function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }
}
