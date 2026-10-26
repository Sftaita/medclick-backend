<?php

namespace App\Export;

/**
 * Reçoit la progression RÉELLE d'une génération : chaque étape correspond à une opération
 * effectivement en cours ; un total n'est donné que lorsqu'il est connu (nombre d'éléments
 * à traiter), jamais estimé à partir du temps écoulé.
 */
interface ExportProgress
{
    /** Début d'une étape (voir ExportStep) ; $total = nombre d'éléments à traiter, si connu. */
    public function step(ExportStep $step, ?int $total = null): void;

    /** Nombre d'éléments de l'étape en cours effectivement traités. */
    public function advance(int $done): void;
}
