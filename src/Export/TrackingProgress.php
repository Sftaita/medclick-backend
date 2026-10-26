<?php

namespace App\Export;

/**
 * Relaie la progression et retient l'étape en cours (pour qualifier un échec).
 */
final class TrackingProgress implements ExportProgress
{
    public ExportStep $current = ExportStep::Preparing;

    public function __construct(private ExportProgress $inner)
    {
    }

    public function step(ExportStep $step, ?int $total = null): void
    {
        $this->current = $step;
        $this->inner->step($step, $total);
    }

    public function advance(int $done): void
    {
        $this->inner->advance($done);
    }
}
