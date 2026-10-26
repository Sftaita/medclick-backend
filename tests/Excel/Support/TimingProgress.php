<?php

namespace App\Tests\Excel\Support;

use App\Export\ExportProgress;
use App\Export\ExportStep;

/**
 * Progression de test : enregistre chaque étape, ses avancements et sa durée.
 */
final class TimingProgress implements ExportProgress
{
    /** @var list<array{step: ExportStep, total: ?int, advances: list<int>, seconds: float}> */
    public array $steps = [];
    private float $start;

    public function step(ExportStep $step, ?int $total = null): void
    {
        $this->close();
        $this->start = hrtime(true) / 1e9;
        $this->steps[] = ['step' => $step, 'total' => $total, 'advances' => [], 'seconds' => 0.0];
    }

    public function advance(int $done): void
    {
        $this->steps[array_key_last($this->steps)]['advances'][] = $done;
    }

    public function close(): void
    {
        if ($this->steps) {
            $this->steps[array_key_last($this->steps)]['seconds'] = hrtime(true) / 1e9 - $this->start;
        }
    }

    public function summary(): string
    {
        $this->close();

        return implode(' | ', array_map(fn ($s) => sprintf('%s %.2fs%s', $s['step']->value, $s['seconds'],
            $s['total'] !== null ? ' (' . $s['total'] . ')' : ''), $this->steps));
    }
}
