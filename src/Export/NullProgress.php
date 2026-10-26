<?php

namespace App\Export;

/**
 * Progression ignorée (génération synchrone de l'ancienne route /api/excel2).
 */
final class NullProgress implements ExportProgress
{
    public function step(ExportStep $step, ?int $total = null): void
    {
    }

    public function advance(int $done): void
    {
    }
}
