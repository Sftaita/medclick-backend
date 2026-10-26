<?php

namespace App\Export\Job;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Export\CarnetDeStage\CarnetDeStageExporter;
use App\Export\ExportFailure;
use App\Export\Job\Message\GenerateExport;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Génère un export dans le worker (bin/console messenger:consume exports …).
 *
 * Protections : durée maximale (EXPORT_TIMEOUT_SECONDS, par signal SIGALRM si l'extension pcntl
 * est présente, et à chaque progression), limite mémoire (EXPORT_MEMORY_LIMIT) avec
 * enregistrement de l'échec même en cas d'arrêt fatal (dépassement mémoire), annulation par
 * l'utilisateur. Un worker tué net (SIGKILL, redémarrage) est détecté par la surveillance
 * (absence de signe de vie) : ExportJobManager::checkLiveness().
 */
#[AsMessageHandler]
final class GenerateExportHandler
{
    /** Export en cours dans ce processus (pour la fonction d'arrêt en cas d'erreur fatale). */
    private ?int $currentJobId = null;
    private ?JobProgress $currentProgress = null;
    private bool $shutdownRegistered = false;
    /** Réserve libérée en cas de dépassement mémoire pour pouvoir encore enregistrer l'échec. */
    private ?string $memoryReserve = null;

    public function __construct(
        private ExportJobManager $manager,
        private CarnetDeStageExporter $exporter,
        private ExportStorage $storage,
        private ExportSettings $settings,
    ) {
    }

    public function __invoke(GenerateExport $message): void
    {
        $job = $this->manager->em()->find(ExportJob::class, $message->jobId);
        if ($job === null || !$this->manager->start($job)) {
            return;
        }

        $path = $this->storage->directory() . '/' . $this->storage->fileNameFor($job);
        $started = hrtime(true);
        $progress = new JobProgress($this->manager, $job, $started, $this->settings->timeoutSeconds);
        $previousMemoryLimit = ini_get('memory_limit');
        // Sans effet (et sans avertissement) si le processus utilise déjà davantage : la génération
        // se poursuit alors avec la limite en place.
        @ini_set('memory_limit', $this->settings->memoryLimit);
        $this->arm($job, $progress);

        try {
            $result = $this->exporter->export($job->getUserId(), (int) ($job->getParameters()['yearId'] ?? 0), $path, $progress);
            $this->disarm();
            $this->manager->complete($job, $path, $result['rows'], self::elapsedMs($started));
        } catch (ExportCancelled) {
            $this->disarm();
            @unlink($path);
            $this->manager->markCancelled($this->manager->managed($job));
        } catch (\Throwable $exception) {
            $this->disarm();
            @unlink($path);
            $failure = $exception instanceof ExportFailure ? $exception
                : new ExportFailure(ExportFailure::UNEXPECTED, 'Erreur inattendue', $progress->current, $exception);
            $this->manager->fail($job, $failure, durationMs: self::elapsedMs($started));
        } finally {
            $this->disarm();
            ini_set('memory_limit', (string) $previousMemoryLimit);
            // Libère les données chargées (le worker traite les exports les uns après les autres).
            $this->manager->em()->clear();
            gc_collect_cycles();
        }
    }

    private function arm(ExportJob $job, JobProgress $progress): void
    {
        $this->currentJobId = $job->getId();
        $this->currentProgress = $progress;
        $this->memoryReserve = str_repeat('x', 2 * 1024 * 1024);
        if (!$this->shutdownRegistered) {
            register_shutdown_function($this->onShutdown(...));
            $this->shutdownRegistered = true;
        }

        // Durée maximale imposée même au milieu d'une opération longue de PhpSpreadsheet.
        if (\function_exists('pcntl_alarm')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, function () use ($progress): void {
                throw new ExportFailure(ExportFailure::TIMEOUT, 'Durée maximale de génération dépassée (signal)', $progress->current);
            });
            pcntl_alarm($this->settings->timeoutSeconds);
        }
    }

    private function disarm(): void
    {
        if (\function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
        $this->currentJobId = null;
        $this->currentProgress = null;
        $this->memoryReserve = null;
    }

    /**
     * Arrêt fatal du processus pendant une génération (dépassement mémoire, temps d'exécution) :
     * l'échec est enregistré avant la fin du processus ; le worker est ensuite redémarré.
     */
    private function onShutdown(): void
    {
        if ($this->currentJobId === null) {
            return;
        }
        $this->memoryReserve = null;
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $category = str_contains($error['message'], 'Allowed memory size') ? ExportFailure::MEMORY
            : (str_contains($error['message'], 'Maximum execution time') ? ExportFailure::TIMEOUT : ExportFailure::UNEXPECTED);

        try {
            $job = $this->manager->em()->find(ExportJob::class, $this->currentJobId);
            if ($job !== null) {
                $step = $this->currentProgress?->current;
                $fatal = new \ErrorException('Erreur fatale', 0, $error['type'], $error['file'], $error['line']);
                $this->manager->fail($job, new ExportFailure($category, 'Arrêt fatal du worker', $step, $fatal), ExportJobEvent::FAILED);
            }
        } catch (\Throwable) {
            // Base indisponible : la surveillance déclarera l'export interrompu (absence de signe de vie).
        }
    }

    private static function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
