<?php

namespace App\Export\Job;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Export\ExportFailure;
use App\Export\ExportProgress;
use App\Export\ExportStep;

/**
 * Progression réelle d'un export, enregistrée en base pendant la génération (lue par l'API
 * GET /api/exports/{id} que le frontend interroge).
 *
 * - Chaque étape est enregistrée dès qu'elle commence (évènement « step » avec son total).
 * - Les avancements sont écrits au plus toutes les 0,5 s (signe de vie du worker compris).
 * - À chaque écriture : vérification de l'annulation demandée et de la durée maximale.
 */
final class JobProgress implements ExportProgress
{
    private const WRITE_EVERY = 0.5;
    private const CANCEL_CHECK_EVERY = 1.0;

    public ExportStep $current = ExportStep::Preparing;
    private float $lastWrite = 0.0;
    private float $lastCancelCheck = 0.0;

    /**
     * @param int $started début de la génération (hrtime, en nanosecondes)
     * @param int $timeoutSeconds durée au-delà de laquelle la génération échoue
     */
    public function __construct(
        private ExportJobManager $manager,
        private ExportJob $job,
        private int $started,
        private int $timeoutSeconds,
    ) {
    }

    /** Millisecondes écoulées depuis le début de la génération (horloge monotone). */
    public function elapsedMs(): int
    {
        return (int) round((hrtime(true) - $this->started) / 1e6);
    }

    public function step(ExportStep $step, ?int $total = null): void
    {
        $this->checkDeadline();
        $this->current = $step;
        $this->job->setStep($step->value)->setTotal($total)->setProcessed($total !== null ? 0 : null);
        if ($step === ExportStep::Surgeries) {
            $this->job->setRowCount($total);
        }
        $this->write(true);
        $this->manager->event($this->job, ExportJobEvent::STEP, $step->value, null,
            ['atMs' => $this->elapsedMs()] + ($total !== null ? ['total' => $total] : []));
    }

    public function advance(int $done): void
    {
        $this->job->setProcessed($done);
        if (self::now() - $this->lastWrite >= self::WRITE_EVERY) {
            $this->checkDeadline();
            $this->write(false);
        }
    }

    private function write(bool $forceCancelCheck): void
    {
        $this->job->setHeartbeatAt($this->manager->now());
        $this->manager->em()->flush();
        $this->lastWrite = self::now();

        if ($forceCancelCheck || $this->lastWrite - $this->lastCancelCheck >= self::CANCEL_CHECK_EVERY) {
            $this->lastCancelCheck = $this->lastWrite;
            // Lu directement en base : l'annulation est écrite par une autre requête (API).
            $cancelled = $this->manager->em()->getConnection()
                ->fetchOne('SELECT cancel_requested FROM export_job WHERE id = ?', [$this->job->getId()]);
            if ((bool) $cancelled) {
                throw new ExportCancelled('Export annulé par l\'utilisateur');
            }
        }
    }

    private function checkDeadline(): void
    {
        if ($this->elapsedMs() > $this->timeoutSeconds * 1000) {
            throw new ExportFailure(ExportFailure::TIMEOUT, 'Durée maximale de génération dépassée', $this->current);
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
