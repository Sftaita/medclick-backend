<?php

namespace App\Command;

use App\Repair\SurgeryNomenclatureRepairPlanner as Planner;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * LOT 2D.2 — réaligne sur leur nomenclature les interventions rendues incohérentes par l'ancien
 * PUT /api/surgeries/{id} (code et relation de A, nom de B). Périmètre : catégorie A1 uniquement
 * (voir SurgeryNomenclatureRepairPlanner) ; tout autre cas est ignoré.
 *
 * - Par défaut : DRY-RUN, uniquement des SELECT, aucun fichier écrit.
 * - --apply --snapshot-dir=DIR : écrit d'abord le snapshot CSV complet (avant/après de chaque ligne),
 *   puis corrige par lots transactionnels avec une mise à jour conditionnelle (la ligne doit avoir
 *   encore ses valeurs « avant ») ; chaque ligne corrigée est journalisée dans un second CSV.
 *   Relancer la commande ne modifie plus rien (les lignes corrigées ne sont plus candidates).
 * - --rollback=FICHIER : restaure les valeurs « avant » d'un snapshot (dry-run sans --apply),
 *   uniquement pour les lignes qui ont toujours les valeurs « après ».
 *
 * Aucune donnée personnelle dans les fichiers : identifiants techniques, codes et spécialités.
 */
#[AsCommand(name: 'app:repair-surgery-nomenclature', description: 'Réaligne les interventions incohérentes avec leur nomenclature (dry-run par défaut).')]
class RepairSurgeryNomenclatureCommand extends Command
{
    private const PAGE = 5000;
    private const SNAPSHOT_COLUMNS = [
        'run_id', 'script_version', 'surgery_id',
        'before_nomenclature_id', 'before_code', 'before_speciality',
        'after_nomenclature_id', 'after_code', 'after_speciality',
    ];

    public function __construct(
        private Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Applique réellement la correction (sinon dry-run)')
            ->addOption('snapshot-dir', null, InputOption::VALUE_REQUIRED, 'Dossier du snapshot CSV, hors du projet (obligatoire avec --apply)')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Lignes par transaction', 200)
            ->addOption('rollback', null, InputOption::VALUE_REQUIRED, 'Snapshot CSV à restaurer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $batchSize = (int) $input->getOption('batch-size');
        if ($batchSize < 1 || $batchSize > 1000) {
            $io->error('--batch-size doit être compris entre 1 et 1000.');

            return Command::INVALID;
        }

        if ($input->getOption('rollback') !== null) {
            return $this->rollback($io, (string) $input->getOption('rollback'), $apply, $batchSize);
        }

        $snapshotDir = null;
        if ($apply) {
            $snapshotDir = $this->checkSnapshotDir($io, $input->getOption('snapshot-dir'));
            if ($snapshotDir === null) {
                return Command::INVALID;
            }
        }

        [$counts, $plans, $bySpeciality, $byMonth] = $this->plan();

        $io->title(($apply ? 'APPLICATION' : 'DRY-RUN (aucune écriture)') . ' — ' . Planner::VERSION);
        $io->table(['Catégorie', 'Interventions'], [
            ['Interventions analysées', $counts['total']],
            ['Signature (code = SOURCE, nom ≠ SOURCE)', $counts['signature']],
            ['A1 certaines — seraient modifiées', $counts[Planner::REPAIRABLE]],
            ['A2 historiques (sans createdAt) — exclues', $counts[Planner::HISTORICAL]],
            ['B spécialité inattendue — exclues', $counts[Planner::SPECIALITY_MISMATCH]],
            ['C plusieurs nomenclatures de ce nom — exclues', $counts[Planner::AMBIGUOUS]],
            ['Aucune nomenclature de ce nom exact — exclues', $counts[Planner::NO_EXACT_TARGET]],
            ['Nomenclature cible incomplète — exclues', $counts[Planner::INCOMPLETE_TARGET]],
            ['Déjà cohérentes', $counts[Planner::CONSISTENT]],
            ['D autres incohérences (hors périmètre)', $counts[Planner::OUT_OF_SCOPE]],
        ]);
        ksort($byMonth);
        arsort($bySpeciality);
        $io->table(['Spécialité cible', 'A1'], array_map(null, array_keys($bySpeciality), array_values($bySpeciality)));
        $io->table(['Mois de l\'intervention', 'A1'], array_map(null, array_keys($byMonth), array_values($byMonth)));

        if (!$apply) {
            $io->success(sprintf('Dry-run : %d intervention(s) seraient corrigées. Aucune donnée modifiée.', count($plans)));

            return Command::SUCCESS;
        }
        if ($plans === []) {
            $io->success('Rien à corriger.');

            return Command::SUCCESS;
        }

        $runId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $snapshot = $snapshotDir . '/repair-surgery-nomenclature-' . $runId . '.csv';
        $applied = $snapshotDir . '/repair-surgery-nomenclature-' . $runId . '.applied.csv';
        $this->writeSnapshot($snapshot, $runId, $plans);

        $log = fopen($applied, 'x');
        fputcsv($log, ['run_id', 'surgery_id', 'applied_at', 'result'], escape: '');
        $updated = 0;
        $skipped = 0;
        foreach (array_chunk($plans, $batchSize) as $chunk) {
            $this->connection->transactional(function (Connection $connection) use ($chunk, $log, $runId, &$updated, &$skipped) {
                $lines = [];
                foreach ($chunk as $plan) {
                    $result = $connection->executeStatement(
                        'UPDATE surgeries SET nomenclature_id = ?, code = ?, name = ?, speciality = ?
                         WHERE id = ? AND nomenclature_id = ? AND code = ? AND name = ? AND speciality = ?',
                        [
                            $plan['after_nomenclature_id'], $plan['after_code'], $plan['name'], $plan['after_speciality'],
                            $plan['surgery_id'], $plan['before_nomenclature_id'], $plan['before_code'], $plan['name'], $plan['before_speciality'],
                        ],
                    );
                    $result === 1 ? $updated++ : $skipped++;
                    $lines[] = [$runId, $plan['surgery_id'], date(DATE_ATOM), $result === 1 ? 'corrigée' : 'ignorée (modifiée entre-temps)'];
                }
                foreach ($lines as $line) {
                    fputcsv($log, $line, escape: '');
                }
            });
            fflush($log);
        }
        fclose($log);

        $io->success(sprintf('%d intervention(s) corrigée(s), %d ignorée(s). Snapshot : %s ; journal : %s', $updated, $skipped, $snapshot, $applied));

        return Command::SUCCESS;
    }

    /**
     * Parcourt toutes les interventions par pages (SELECT uniquement) et construit le plan A1.
     */
    private function plan(): array
    {
        $planner = new Planner($this->connection->executeQuery(
            'SELECT id, name, code_hospitalisation, n, speciality FROM nomenclature'
        )->iterateAssociative());

        $counts = array_fill_keys([
            'total', 'signature', Planner::REPAIRABLE, Planner::HISTORICAL, Planner::SPECIALITY_MISMATCH, Planner::AMBIGUOUS,
            Planner::NO_EXACT_TARGET, Planner::INCOMPLETE_TARGET, Planner::CONSISTENT, Planner::OUT_OF_SCOPE,
        ], 0);
        $plans = [];
        $bySpeciality = [];
        $byMonth = [];
        $lastId = 0;

        do {
            $rows = $this->connection->executeQuery(
                'SELECT id, nomenclature_id, code, name, speciality, created_at, date FROM surgeries WHERE id > ? ORDER BY id LIMIT ' . self::PAGE,
                [$lastId],
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                ['status' => $status, 'target' => $target] = $planner->classify($row);
                $counts['total']++;
                $counts[$status]++;
                if (!in_array($status, [Planner::CONSISTENT, Planner::OUT_OF_SCOPE], true)) {
                    $counts['signature']++;
                }
                if ($status !== Planner::REPAIRABLE) {
                    continue;
                }
                $plans[] = [
                    'surgery_id' => $lastId,
                    'name' => $row['name'],
                    'before_nomenclature_id' => (int) $row['nomenclature_id'],
                    'before_code' => $row['code'],
                    'before_speciality' => $row['speciality'],
                    'after_nomenclature_id' => $target['id'],
                    'after_code' => $target['code'],
                    'after_speciality' => $target['speciality'],
                ];
                $bySpeciality[$target['speciality']] = ($bySpeciality[$target['speciality']] ?? 0) + 1;
                $month = substr((string) $row['date'], 0, 7);
                $byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
            }
        } while (count($rows) === self::PAGE);

        return [$counts, $plans, $bySpeciality, $byMonth];
    }

    private function checkSnapshotDir(SymfonyStyle $io, mixed $dir): ?string
    {
        if (!is_string($dir) || $dir === '') {
            $io->error('--apply exige --snapshot-dir (dossier hors du projet, le projet étant servi par le web).');

            return null;
        }
        $real = realpath($dir);
        $project = realpath($this->projectDir);
        if ($real === false || !is_dir($real) || !is_writable($real)) {
            $io->error('Dossier de snapshot introuvable ou non inscriptible : ' . $dir);

            return null;
        }
        if (str_starts_with($real . DIRECTORY_SEPARATOR, $project . DIRECTORY_SEPARATOR)) {
            $io->error('Le snapshot ne doit pas être écrit dans le projet (exposé sous public_html).');

            return null;
        }

        return $real;
    }

    /** Snapshot complet écrit et synchronisé sur disque AVANT la première modification. */
    private function writeSnapshot(string $file, string $runId, array $plans): void
    {
        $handle = fopen($file, 'x');
        fputcsv($handle, self::SNAPSHOT_COLUMNS, escape: '');
        foreach ($plans as $plan) {
            fputcsv($handle, [
                $runId, Planner::VERSION, $plan['surgery_id'],
                $plan['before_nomenclature_id'], $plan['before_code'], $plan['before_speciality'],
                $plan['after_nomenclature_id'], $plan['after_code'], $plan['after_speciality'],
            ], escape: '');
        }
        fflush($handle);
        fsync($handle);
        fclose($handle);
        chmod($file, 0600);
    }

    private function rollback(SymfonyStyle $io, string $file, bool $apply, int $batchSize): int
    {
        $handle = is_file($file) ? fopen($file, 'r') : false;
        if ($handle === false || fgetcsv($handle, escape: '') !== self::SNAPSHOT_COLUMNS) {
            $io->error('Snapshot illisible ou au mauvais format : ' . $file);

            return Command::INVALID;
        }
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_combine(self::SNAPSHOT_COLUMNS, $line);
        }
        fclose($handle);

        $restorable = 0;
        foreach ($rows as $row) {
            $restorable += (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM surgeries WHERE id = ? AND nomenclature_id = ? AND code = ? AND speciality = ?',
                [$row['surgery_id'], $row['after_nomenclature_id'], $row['after_code'], $row['after_speciality']],
            );
        }
        if (!$apply) {
            $io->success(sprintf('Dry-run du rollback : %d ligne(s) dans le snapshot, %d restaurable(s). Aucune donnée modifiée.', count($rows), $restorable));

            return Command::SUCCESS;
        }

        $restored = 0;
        foreach (array_chunk($rows, $batchSize) as $chunk) {
            $this->connection->transactional(function (Connection $connection) use ($chunk, &$restored) {
                foreach ($chunk as $row) {
                    $restored += $connection->executeStatement(
                        'UPDATE surgeries SET nomenclature_id = ?, code = ?, speciality = ?
                         WHERE id = ? AND nomenclature_id = ? AND code = ? AND speciality = ?',
                        [
                            $row['before_nomenclature_id'], $row['before_code'], $row['before_speciality'],
                            $row['surgery_id'], $row['after_nomenclature_id'], $row['after_code'], $row['after_speciality'],
                        ],
                    );
                }
            });
        }
        $io->success(sprintf('Rollback : %d ligne(s) restaurée(s) sur %d.', $restored, count($rows)));

        return Command::SUCCESS;
    }
}
