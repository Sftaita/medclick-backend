<?php

namespace App\Tests\Fixtures;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Commande qui échoue volontairement (environnement de test uniquement). */
#[AsCommand(name: 'app:test:crash')]
class FailingCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new \LogicException('Commande en échec');
    }
}
