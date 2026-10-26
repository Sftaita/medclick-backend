<?php

namespace App\Command;

use App\Export\Monitoring\ExportMonitor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Surveillance des exports à la demande : la même que celle planifiée chaque minute dans le worker
 * (App\Export\Monitoring\ExportMonitorSchedule).
 */
#[AsCommand(name: 'app:exports:monitor', description: 'Exports bloqués, non téléchargés, fichiers expirés, alertes à renvoyer, rétention.')]
class MonitorExportsCommand extends Command
{
    public function __construct(private ExportMonitor $monitor)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->monitor->run();
        (new SymfonyStyle($input, $output))->definitionList(...array_map(
            fn (string $key, int $value) => [$key => $value], array_keys($report), $report));

        return Command::SUCCESS;
    }
}
