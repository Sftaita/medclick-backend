<?php

namespace App\Export;

/**
 * Progression ignorée (alias synchrones /api/excel2 et /api/excel).
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
