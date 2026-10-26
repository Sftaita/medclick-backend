<?php

namespace App\Export\Job;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Entity\User;
use App\Export\CarnetDeStage\CarnetDeStageExporter;
use App\Export\ExportFailure;
use App\Export\ExportStep;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Historique des exports des anciennes routes SYNCHRONES (/api/excel2 et /api/excel), encore
 * appelées par les anciens frontends : chaque demande crée une entrée, même en cas d'échec.
 * Le fichier est servi directement dans la réponse : aucun fichier n'est conservé, l'envoi
 * complet est observé côté serveur (jamais confirmé par le navigateur).
 */
final class SyncExportRecorder implements EventSubscriberInterface
{
    /** Route => type d'export enregistré. */
    private const ROUTES = [
        'excel' => CarnetDeStageExporter::TYPE,
        'excelOldVersion' => 'carnet_de_stage_v1',
    ];
    private const JOB = '_sync_export_job';
    private const EXCEPTION = '_sync_export_exception';

    public function __construct(
        private ExportJobManager $manager,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
            KernelEvents::EXCEPTION => ['onException', 200],
            KernelEvents::RESPONSE => ['onResponse', -200],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $type = self::ROUTES[$request->attributes->get('_route')] ?? null;
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$event->isMainRequest() || $type === null || !$user instanceof User) {
            return;
        }

        try {
            $now = $this->manager->now();
            $job = new ExportJob(Uuid::v4()->toRfc4122(), bin2hex(random_bytes(8)), $user->getId(), $type,
                ['yearId' => (int) $request->attributes->get('year'), 'channel' => 'sync'], $now);
            $job->setStatus(ExportJob::STATUS_RUNNING)->setStartedAt($now)->setHeartbeatAt($now)
                ->setStep(ExportStep::Preparing->value)->incrementAttempts();
            $this->manager->em()->persist($job);
            $this->manager->event($job, ExportJobEvent::STARTED, ExportStep::Preparing->value, 'Route synchrone ' . $request->getPathInfo());
            $request->attributes->set(self::JOB, $job);
            $request->attributes->set('_sync_export_started', hrtime(true));
        } catch (\Throwable $exception) {
            // L'historique ne doit jamais empêcher l'export lui-même.
            $this->logger->error('[export] Historique de l\'export synchrone impossible', ['failure' => $exception::class, 'userId' => $user->getId()]);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->getRequest()->attributes->has(self::JOB)) {
            $event->getRequest()->attributes->set(self::EXCEPTION, $event->getThrowable());
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $job = $request->attributes->get(self::JOB);
        if (!$job instanceof ExportJob) {
            return;
        }
        $request->attributes->remove(self::JOB);
        $response = $event->getResponse();
        $elapsed = fn () => (int) round((hrtime(true) - $request->attributes->get('_sync_export_started')) / 1e6);

        try {
            if (!$response->isSuccessful() || !$response instanceof StreamedResponse) {
                $this->fail($job, $response->getStatusCode(), $request->attributes->get(self::EXCEPTION), $elapsed());

                return;
            }
            // Fichier généré (ancien moteur : généré pendant l'envoi lui-même).
            $job = $this->manager->managed($job);
            $job->setRowCount($request->attributes->get('_export_rows'));
            $this->manager->em()->flush();

            $callback = $response->getCallback();
            $response->setCallback(function () use ($callback, $job, $elapsed) {
                $bytes = 0;
                ob_start(function (string $buffer) use (&$bytes) {
                    $bytes += strlen($buffer);

                    return $buffer;
                }, 65536);
                try {
                    $callback();
                } catch (\Throwable $exception) {
                    // Ancien moteur : la génération a lieu pendant l'envoi.
                    ob_end_flush();
                    $this->fail($job, Response::HTTP_INTERNAL_SERVER_ERROR, $exception, $elapsed());

                    throw $exception;
                }
                ob_end_flush();
                $this->served($job, $bytes, !connection_aborted(), $elapsed());
            });
        } catch (\Throwable $exception) {
            $this->manager->log('error', $job, 'Historique de l\'export synchrone incomplet', ['failure' => $exception::class]);
        }
    }

    private function served(ExportJob $job, int $bytes, bool $complete, int $durationMs): void
    {
        try {
            $job = $this->manager->managed($job);
            $now = $this->manager->now();
            $connection = $this->manager->em()->getConnection();
            $connection->executeStatement('UPDATE export_job SET status = ?, step = ?, finished_at = ?, duration_ms = ?, file_size = ?,
                    expires_at = ?, file_deleted_at = ?, download_attempts = 1, first_served_at = ? WHERE id = ? AND status = ?',
                [ExportJob::STATUS_READY, ExportStep::Ready->value, $now->format('Y-m-d H:i:s'), $durationMs, $bytes,
                    $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $complete ? $now->format('Y-m-d H:i:s') : null,
                    $job->getId(), ExportJob::STATUS_RUNNING]);
            $this->manager->em()->refresh($job);
            $this->manager->event($job, $complete ? ExportJobEvent::DOWNLOAD_SERVED : ExportJobEvent::DOWNLOAD_INTERRUPTED, ExportStep::Ready->value,
                $complete ? 'Fichier envoyé en entier par la route synchrone (non confirmé par le navigateur)' : 'Envoi interrompu (connexion fermée)',
                ['bytes' => $bytes, 'durationMs' => $durationMs]);
        } catch (\Throwable $exception) {
            $this->manager->log('error', $job, 'Historique de l\'export synchrone incomplet', ['failure' => $exception::class]);
        }
    }

    private function fail(ExportJob $job, int $status, ?\Throwable $exception, int $durationMs): void
    {
        $message = $exception?->getMessage() ?? '';
        $category = match (true) {
            $status === Response::HTTP_NOT_FOUND => ExportFailure::NOT_FOUND,
            $status === Response::HTTP_FORBIDDEN => ExportFailure::ACCESS_DENIED,
            $exception instanceof ExportFailure => $exception->category,
            str_contains($message, 'Maximum execution time') => ExportFailure::TIMEOUT,
            str_contains($message, 'Allowed memory size') => ExportFailure::MEMORY,
            $exception instanceof DbalException => ExportFailure::DATABASE,
            default => ExportFailure::UNEXPECTED,
        };
        $failure = $exception instanceof ExportFailure ? $exception
            : new ExportFailure($category, 'Route synchrone : HTTP ' . $status, null, $exception);
        $this->manager->fail($job, $failure, durationMs: $durationMs);
    }
}
