<?php

namespace App\Tests\Export;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Export\CarnetDeStage\CarnetDeStageDataLoader;
use App\Export\CarnetDeStage\CarnetDeStageExporter;
use App\Export\CarnetDeStage\CarnetDeStageSpreadsheetBuilder;
use App\Export\CarnetDeStage\CarnetDeStageTemplate;
use App\Export\ExportFailure;
use App\Export\Job\ExportAlerter;
use App\Export\Job\ExportJobManager;
use App\Export\Job\ExportSettings;
use App\Export\NullProgress;
use App\Repository\ExportJobRepository;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Process\Process;

/**
 * Détection et qualification des échecs, surveillance des exports bloqués, alertes e-mail.
 */
final class ExportFailureTest extends ExportTestCase
{
    public function testWorkerStoppedDuringGenerationIsDetectedAndAlerted(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        // Le worker prend l'export en charge puis disparaît (SIGKILL, redémarrage du conteneur…).
        $this->manager()->start($this->job($job['id']));

        $this->travel(119);
        self::assertSame('running', $client->request('GET', "/api/exports/{$job['id']}")->toArray()['status']);
        $this->travel(2);
        $failed = $client->request('GET', "/api/exports/{$job['id']}")->toArray();

        self::assertSame('failed', $failed['status']);
        self::assertSame(ExportFailure::WORKER_INTERRUPTED, $failed['error']['category']);
        self::assertContains(ExportJobEvent::STALLED, $this->eventTypes($job['id']));
        self::assertSame(ExportJob::ALERT_SENT, $this->job($job['id'])->getAlertStatus());

        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertSame('admin.alertes@example.test', $emails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Blocage', $emails[0]->getSubject());
        $text = $emails[0]->getTextBody();
        foreach ([$failed['diagnosticId'], 'resident.fictif@example.test', 'Préparation', '/admin/exports?id=' . $job['id'], 'worker_interrupted'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        // Jamais de donnée médicale ni de pièce jointe.
        self::assertStringNotContainsString('Acte fictif', $text);
        self::assertStringNotContainsString('CHU Test', $text);
        self::assertSame([], $emails[0]->getAttachments());

        // La surveillance ne le signale pas une seconde fois.
        $this->monitor();
        self::assertCount(1, $this->sentEmails());
    }

    public function testRequestNeverPickedUpByAWorkerFails(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);

        $this->travel(901);
        self::assertSame(1, $this->monitor()['interrompus']);
        $failed = $this->job($job['id']);
        self::assertSame(ExportJob::STATUS_FAILED, $failed->getStatus());
        self::assertSame(ExportFailure::WORKER_UNAVAILABLE, $failed->getErrorCategory());
    }

    public function testRedeliveredMessageAfterACrashIsNotGeneratedTwice(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->manager()->start($this->job($job['id']));

        // Message redélivré au worker redémarré : pas de nouvelle génération (pas de boucle de plantage).
        $this->handle($this->job($job['id']));

        $failed = $this->job($job['id']);
        self::assertSame(ExportJob::STATUS_FAILED, $failed->getStatus());
        self::assertSame(ExportFailure::WORKER_INTERRUPTED, $failed->getErrorCategory());
        self::assertSame(1, $failed->getAttempts());
        self::assertContains(ExportJobEvent::REDELIVERED, $this->eventTypes($job['id']));
        self::assertNull($failed->getFileName());
    }

    public function testFrozenWorkerExceedingTheMaximumDurationIsStopped(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $entity = $this->job($job['id']);
        $this->manager()->start($entity);

        // Signe de vie récent, mais durée maximale (300 s) dépassée de plus d'une minute.
        for ($i = 0; $i < 4; $i++) {
            $this->travel(100);
            $entity = $this->job($job['id'])->setHeartbeatAt($this->manager()->now());
            $this->em->flush();
        }
        $this->monitor();
        self::assertSame(ExportFailure::TIMEOUT, $this->job($job['id'])->getErrorCategory());
    }

    public function testDatabaseFailureIsQualified(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->em->getConnection()->executeStatement('DROP TABLE surgeries');

        $this->processQueue();

        $failed = $this->job($job['id']);
        self::assertSame(ExportJob::STATUS_FAILED, $failed->getStatus());
        self::assertSame(ExportFailure::DATABASE, $failed->getErrorCategory());
        self::assertSame('loading', $failed->getErrorStep());
        // Code technique : exception, emplacement dans le code de l'application, SQLSTATE.
        self::assertSame('TableNotFoundException@CarnetDeStageDataLoader.php:69 SQLSTATE[HY000]', $failed->getErrorCode());
        self::assertSame('La lecture des données a échoué.', $failed->getErrorMessage());
        self::assertCount(1, $this->sentEmails());
    }

    public function testWorkbookGenerationFailureIsQualified(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $broken = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($broken, 'pas un classeur');

        try {
            $this->exporter(new CarnetDeStageTemplate($broken, sys_get_temp_dir() . '/tpl-' . uniqid(), 'secret'))
                ->export($user->getId(), $yearId, sys_get_temp_dir() . '/x.xlsx', new NullProgress());
            self::fail('Génération impossible attendue');
        } catch (ExportFailure $failure) {
            self::assertSame(ExportFailure::XLSX_GENERATION, $failure->category);
            self::assertSame('template', $failure->step->value);
        }
    }

    public function testFileWriteFailureIsQualified(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $target = static::getContainer()->getParameter('kernel.project_dir') . '/var/dossier-inexistant/' . uniqid() . '/x.xlsx';

        try {
            $this->exporter()->export($user->getId(), $yearId, $target, new NullProgress());
            self::fail('Écriture impossible attendue');
        } catch (ExportFailure $failure) {
            self::assertSame(ExportFailure::FILE_WRITE, $failure->category);
            self::assertSame('writing', $failure->step->value);
        }
        self::assertFileDoesNotExist($target . '.part', 'Aucun fichier partiel');
    }

    public function testMemoryExhaustionInTheWorkerIsRecorded(): void
    {
        [$user, $yearId] = $this->seed('volume-1000');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);

        $process = $this->runWorkerProcess($this->job($job['id'])->getId(), ['EXPORT_MEMORY_LIMIT' => '96M']);
        self::assertNotSame(0, $process->getExitCode(), 'Le processus du worker doit s\'arrêter (erreur fatale)');
        self::assertStringContainsString('Allowed memory size', $process->getOutput() . $process->getErrorOutput());

        $failed = $this->job($job['id']);
        self::assertSame(ExportJob::STATUS_FAILED, $failed->getStatus());
        self::assertSame(ExportFailure::MEMORY, $failed->getErrorCategory());
        self::assertNotNull($failed->getErrorStep());
        self::assertContains(ExportJobEvent::FAILED, $this->eventTypes($job['id']));
        self::assertSame([], glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/exports-test/*.xlsx'));
    }

    public function testTimeoutInTheWorkerIsRecorded(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);

        $process = $this->runWorkerProcess($this->job($job['id'])->getId(), ['EXPORT_TIMEOUT_SECONDS' => '0']);
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $failed = $this->job($job['id']);
        self::assertSame(ExportFailure::TIMEOUT, $failed->getErrorCategory());
        self::assertSame('La génération a dépassé la durée maximale autorisée.', $failed->getErrorMessage());
    }

    public function testIdenticalIncidentsSendASingleAlert(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $first = $this->requestExport($client, $yearId);
        $this->travel(901);
        $this->monitor();
        $second = $this->requestExport($client, $yearId);
        $this->travel(901);
        $this->monitor();

        self::assertCount(1, $this->sentEmails());
        $duplicate = $this->job($second['id']);
        self::assertSame(ExportJob::ALERT_SUPPRESSED, $duplicate->getAlertStatus());
        self::assertSame($first['id'], $duplicate->getAlertDuplicateOf());
        self::assertContains(ExportJobEvent::ALERT_SUPPRESSED, $this->eventTypes($second['id']));

        // Après la période anti-doublon (60 min), le même incident est de nouveau signalé.
        $this->travel(3600);
        $third = $this->requestExport($client, $yearId);
        $this->travel(901);
        $this->monitor();
        self::assertCount(2, $this->sentEmails());
        self::assertSame(ExportJob::ALERT_SENT, $this->job($third['id'])->getAlertStatus());
    }

    public function testFailedAlertIsKeptAndRetried(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->travel(901);
        $entity = $this->job($job['id']);
        $this->manager()->fail($entity, new ExportFailure(ExportFailure::WORKER_UNAVAILABLE, 'test'));
        // Première alerte partie normalement ; on rejoue l'envoi avec un SMTP indisponible.
        $entity = $this->job($job['id']);
        $entity->setAlertStatus(ExportJob::ALERT_NONE);
        $attempts = $entity->getAlertAttempts();

        // SMTP indisponible.
        $failing = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connexion SMTP refusée');
            }
        };
        $this->alerter($failing)->onFailure($entity);

        $entity = $this->job($job['id']);
        self::assertSame(ExportJob::ALERT_FAILED, $entity->getAlertStatus());
        self::assertSame($attempts + 1, $entity->getAlertAttempts());
        self::assertStringContainsString('TransportException', $entity->getAlertLastError());
        self::assertContains(ExportJobEvent::ALERT_FAILED, $this->eventTypes($job['id']));

        // Nouvelle tentative contrôlée : pas avant le délai (croissant : 5, 15, 60, 240 min selon le
        // nombre de tentatives ; ici 2 tentatives → 15 min), puis envoyée par la surveillance.
        $this->travel(14 * 60);
        self::assertSame(0, $this->monitor()['alertesRenvoyees']);
        $this->travel(2 * 60);
        $before = count($this->sentEmails());
        self::assertSame(1, $this->monitor()['alertesRenvoyees']);
        self::assertCount($before + 1, $this->sentEmails());
        self::assertSame(ExportJob::ALERT_SENT, $this->job($job['id'])->getAlertStatus());
        self::assertSame($attempts + 2, $this->job($job["id"])->getAlertAttempts());
    }

    public function testNotDownloadedDigestIsSentAtMostOncePerDay(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->processQueue();
        $this->travel(31 * 60);

        $alerter = $this->alerter(static::getContainer()->get(MailerInterface::class), notDownloadedThreshold: 1);
        $pending = [$this->job($job['id'])->setNotDownloadedAt($this->manager()->now())];
        $this->em->flush();

        self::assertTrue($alerter->notDownloadedDigest($pending, false));
        self::assertFalse($alerter->notDownloadedDigest($pending, true), 'Déjà envoyé dans les 24 h');
        self::assertCount(1, $this->sentEmails());
        self::assertStringContainsString($job['diagnosticId'], $this->sentEmails()[0]->getTextBody());
        self::assertNotNull($this->job($job['id'])->getNotDownloadedAlertedAt());
    }

    public function testFunctionalCausesDoNotAlert(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        // L'année est supprimée avant la génération.
        $this->em->getConnection()->executeStatement('DELETE FROM surgeries');
        $this->em->getConnection()->executeStatement('DELETE FROM surgeons');
        $this->em->getConnection()->executeStatement('DELETE FROM consultations');
        $this->em->getConnection()->executeStatement('DELETE FROM gardes');
        $this->em->getConnection()->executeStatement('DELETE FROM formations');
        $this->em->getConnection()->executeStatement('DELETE FROM years');

        $this->processQueue();

        self::assertSame(ExportFailure::ACCESS_DENIED, $this->job($job['id'])->getErrorCategory());
        self::assertSame([], $this->sentEmails());
    }

    private function manager(): ExportJobManager
    {
        return static::getContainer()->get(ExportJobManager::class);
    }

    private function alerter(MailerInterface $mailer, int $notDownloadedThreshold = 0): ExportAlerter
    {
        $settings = static::getContainer()->get(ExportSettings::class);
        $settings = new ExportSettings($settings->storageDir, $settings->fileTtlMinutes, $settings->timeoutSeconds,
            $settings->stallSeconds, $settings->pendingMaxSeconds, $settings->notDownloadedAfterMinutes, $notDownloadedThreshold,
            $settings->alertEmail, $settings->alertDedupMinutes, $settings->historyRetentionDays, $settings->memoryLimit,
            $settings->publicFrontendUrl);

        return new ExportAlerter(static::getContainer()->get('doctrine'), static::getContainer()->get(ExportJobRepository::class),
            $mailer, Clock::get(), new NullLogger(), $settings);
    }

    private function exporter(?CarnetDeStageTemplate $template = null): CarnetDeStageExporter
    {
        $container = static::getContainer();

        return new CarnetDeStageExporter(
            $container->get(CarnetDeStageDataLoader::class),
            new CarnetDeStageSpreadsheetBuilder(Clock::get()),
            $template ?? $container->get(CarnetDeStageTemplate::class),
        );
    }

    private function runWorkerProcess(int $jobId, array $env): Process
    {
        $root = static::getContainer()->getParameter('kernel.project_dir');
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=256M', $root . '/tests/Export/bin/run-handler.php', (string) $jobId], $root, $env + ['APP_ENV' => 'test']);
        $process->setTimeout(300);
        $process->run();

        return $process;
    }
}
