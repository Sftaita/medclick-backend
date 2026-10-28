<?php

namespace App\Command;

use App\Export\Monitoring\ExportMonitor;
use App\Export\Monitoring\ExportWorkerWatch;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Surveillance des exports : la même que celle planifiée chaque minute dans le worker
 * (App\Export\Monitoring\ExportMonitorSchedule).
 *
 * --watchdog : passage du gardien planifié sur le serveur (cron, chaque minute, dans le conteneur de
 * l'API, docs/DEPLOIEMENT-VPS.md) ; vérifie aussi la présence du worker et alerte s'il est arrêté.
 * N'affiche rien quand tout va bien (journal du cron sobre), sauf avec -v.
 */
#[AsCommand(name: 'app:exports:monitor', description: 'Exports bloqués, non téléchargés, fichiers expirés, alertes à renvoyer, rétention, présence du worker.')]
class MonitorExportsCommand extends Command
{
    public function __construct(private ExportMonitor $monitor, private ExportWorkerWatch $workerWatch)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('watchdog', null, InputOption::VALUE_NONE, 'Passage du gardien (cron) : vérifie aussi la présence du worker');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $watchdog = (bool) $input->getOption('watchdog');
        $report = $this->monitor->runExclusive($watchdog ? ExportWorkerWatch::BY_WATCHDOG : ExportWorkerWatch::BY_COMMAND) ?? ['surveillanceEnCoursAilleurs' => 1];
        if ($watchdog) {
            $report += $this->workerWatch->check();
        }

        $notable = array_filter($report);
        if ($notable !== [] || $output->isVerbose() || !$watchdog) {
            (new SymfonyStyle($input, $output))->definitionList(...array_map(
                fn (string $key, int $value) => [$key => $value], array_keys($report), $report));
        }

        return Command::SUCCESS;
    }
}
