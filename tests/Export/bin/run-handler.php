<?php

/*
 * Traite un export dans un processus séparé, comme le worker (tests des arrêts fatals :
 * dépassement mémoire, durée maximale). Usage : php tests/Export/bin/run-handler.php <id interne>
 * Variables d'environnement (EXPORT_MEMORY_LIMIT, EXPORT_TIMEOUT_SECONDS…) transmises par le test.
 */

use App\Export\Job\GenerateExportHandler;
use App\Export\Job\Message\GenerateExport;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');

$kernel = new Kernel('test', true);
$kernel->boot();
// Comme en production : les avertissements n'interrompent pas la génération.
restore_error_handler();
restore_exception_handler();

$kernel->getContainer()->get('test.service_container')->get(GenerateExportHandler::class)(new GenerateExport((int) $argv[1]));
echo "terminé\n";
