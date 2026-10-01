<?php

namespace App\Command;

use App\Repository\ErrorLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les erreurs non revues depuis N jours (RGPD : durée de conservation limitée).
 * À planifier quotidiennement (cron hPanel) : php bin/console app:errors:purge
 */
#[AsCommand(name: 'app:errors:purge', description: 'Supprime les erreurs dont la dernière occurrence est ancienne.')]
class PurgeErrorLogsCommand extends Command
{
    public function __construct(private ErrorLogRepository $errors)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Durée de conservation en jours', 90);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) $input->getOption('days');
        if ($days < 1) {
            (new SymfonyStyle($input, $output))->error('--days doit être >= 1');

            return Command::INVALID;
        }

        $deleted = $this->errors->purgeOlderThan(new \DateTimeImmutable(sprintf('-%d days', $days)));
        (new SymfonyStyle($input, $output))->success(sprintf('%d erreur(s) supprimée(s) (plus de %d jours).', $deleted, $days));

        return Command::SUCCESS;
    }
}
