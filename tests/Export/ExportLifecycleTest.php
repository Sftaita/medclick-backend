<?php

namespace App\Tests\Export;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Export\ExportStep;
use App\Tests\Excel\ExportDatasetFactory;
use App\Tests\Excel\XlsxComparator;

/**
 * Cycle de vie complet d'un export asynchrone : demande, progression réelle, fichier identique à la
 * référence, téléchargement observé puis confirmé, expiration, historique conservé.
 */
final class ExportLifecycleTest extends ExportTestCase
{
    public function testSuccessfulExportFromRequestToConfirmedDownload(): void
    {
        // Même date que les fichiers de référence (cellule H30 du carnet de stage).
        self::mockTime('2026-10-26 10:00:00');
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);

        $job = $this->requestExport($client, $yearId);
        self::assertSame('pending', $job['status']);
        self::assertSame(0, $job['queuePosition']);
        self::assertSame('queued', $job['step']);
        self::assertNull($job['percent'], 'Aucun pourcentage sans information réelle');
        self::assertMatchesRegularExpression('/^EXP-[0-9A-F]{8}$/', $job['diagnosticId']);

        self::assertSame(1, $this->processQueue());

        $ready = $client->request('GET', '/api/exports/' . $job['id'])->toArray();
        self::assertSame('ready', $ready['status']);
        self::assertSame(25, $ready['rowCount']);
        self::assertSame('exports/' . $job['id'] . '/download', $ready['downloadUrl']);
        self::assertGreaterThan(0, $ready['durationMs']);
        // Toutes les étapes réellement exécutées, dans l'ordre, avec une durée mesurée.
        self::assertSame(['preparing', 'loading', 'template', 'surgeries', 'supervisors', 'summary', 'sheets', 'writing'], array_column($ready['steps'], 'step'));
        self::assertSame(array_fill(0, 8, 'done'), array_column($ready['steps'], 'state'));
        // Totaux réels : interventions de l'année, puis lignes de la liste récapitulative (codes distincts).
        $totals = array_column($ready['steps'], 'total');
        self::assertSame([null, null, null, 25], array_slice($totals, 0, 4));
        self::assertSame(3, $totals[4], 'Décompte : maître de stage + 2 superviseurs');
        self::assertGreaterThan(0, $totals[5]);
        self::assertLessThanOrEqual(25, $totals[5]);
        self::assertSame([null, null], array_slice($totals, 6));
        foreach ($ready['steps'] as $step) {
            self::assertIsInt($step['durationMs']);
        }

        // Fichier téléchargé = fichier de référence figé (tests/Fixtures/excel-reference).
        $download = $this->body($client, '/api/' . $ready['downloadUrl']);
        self::assertSame(200, $download['status']);
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $download['headers']['content-type'][0]);
        self::assertSame((string) $ready['fileSize'], $download['headers']['content-length'][0]);
        self::assertStringContainsString('no-store', $download['headers']['cache-control'][0]);
        $report = $this->compareBytes(file_get_contents(self::REFERENCE_DIR . '/petit.xlsx'), $download['content']);
        self::assertTrue($report['equivalent'], implode("\n", $report['differences']));
        self::assertSame($report['stats']['parties'], $report['stats']['partiesIdentiquesOctet']);

        // Envoi observé côté serveur, mais pas encore confirmé : le statut reste « prêt ».
        self::assertSame(ExportJob::STATUS_READY, $this->job($job['id'])->getStatus());
        self::assertNotNull($this->job($job['id'])->getFirstServedAt());
        self::assertNull($this->job($job['id'])->getFirstDownloadedAt());

        $confirmed = $client->request('POST', "/api/exports/{$job['id']}/confirm", ['json' => ['size' => strlen($download['content'])]])->toArray();
        self::assertSame('downloaded', $confirmed['status']);
        self::assertTrue($confirmed['downloaded']);
        self::assertSame(1, $this->job($job['id'])->getDownloadAttempts());

        self::assertSame([
            ExportJobEvent::REQUESTED, ExportJobEvent::STARTED,
            // Une étape enregistrée pour chacune des 7 opérations après la prise en charge.
            ...array_fill(0, 7, ExportJobEvent::STEP),
            ExportJobEvent::READY, ExportJobEvent::DOWNLOAD_STARTED, ExportJobEvent::DOWNLOAD_SERVED, ExportJobEvent::DOWNLOAD_CONFIRMED,
        ], $this->eventTypes($job['id']));
    }

    public function testIncompleteDownloadIsNotConfirmed(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->processQueue();

        $response = $client->request('POST', "/api/exports/{$job['id']}/confirm", ['json' => ['size' => 12]]);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(ExportJob::STATUS_READY, $this->job($job['id'])->getStatus());
        self::assertContains(ExportJobEvent::DOWNLOAD_SIZE_MISMATCH, $this->eventTypes($job['id']));
    }

    public function testDoubleRequestReusesTheRunningExport(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);

        $first = $client->request('POST', '/api/exports', ['json' => ['yearId' => $yearId]]);
        self::assertSame(202, $first->getStatusCode());
        self::assertCount(1, $this->transport()->getSent());
        // (le noyau vide la file en mémoire entre deux requêtes : on compte les envois de chacune)
        $second = $client->request('POST', '/api/exports', ['json' => ['yearId' => $yearId]]);

        self::assertSame(200, $second->getStatusCode());
        self::assertSame($first->toArray()['id'], $second->toArray()['id']);
        self::assertCount(0, $this->transport()->getSent(), 'Aucune seconde génération lancée');
        self::assertSame(1, $this->em->getRepository(ExportJob::class)->count([]));
        self::assertContains(ExportJobEvent::REUSED, $this->eventTypes($first->toArray()['id']));

        // Une fois terminé, une nouvelle demande crée bien un nouvel export.
        $this->handle($this->job($first->toArray()['id']));
        self::assertSame(202, $client->request('POST', '/api/exports', ['json' => ['yearId' => $yearId]])->getStatusCode());
        self::assertSame(2, $this->em->getRepository(ExportJob::class)->count([]));
    }

    public function testExportCanBeFoundAgainAfterPageRefresh(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);

        $items = $client->request('GET', '/api/exports?yearId=' . $yearId)->toArray()['items'];
        self::assertSame([$job['id']], array_column($items, 'id'));
        self::assertSame([], $client->request('GET', '/api/exports?yearId=' . ($yearId + 999))->toArray()['items']);
    }

    public function testCancelBeforeProcessing(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);

        self::assertSame('cancelled', $client->request('POST', "/api/exports/{$job['id']}/cancel")->toArray()['status']);
        $this->processQueue();

        $cancelled = $this->job($job['id']);
        self::assertSame(ExportJob::STATUS_CANCELLED, $cancelled->getStatus());
        self::assertNull($cancelled->getFileName(), 'Aucun fichier généré');
        self::assertNotContains(ExportJobEvent::STARTED, $this->eventTypes($job['id']));
    }

    public function testCancelDuringGenerationStopsAtTheNextProgressStep(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $manager = static::getContainer()->get(\App\Export\Job\ExportJobManager::class);
        $entity = $this->job($job['id']);
        $manager->start($entity);

        $manager->cancel($entity);
        self::assertTrue($this->job($job['id'])->isCancelRequested());

        $progress = new \App\Export\Job\JobProgress($manager, $this->job($job['id']), hrtime(true), 300);
        try {
            $progress->step(ExportStep::Loading);
            self::fail('La génération aurait dû s\'arrêter');
        } catch (\App\Export\Job\ExportCancelled) {
        }
        $manager->markCancelled($this->job($job['id']));
        self::assertSame(ExportJob::STATUS_CANCELLED, $this->job($job['id'])->getStatus());
        self::assertContains(ExportJobEvent::CANCEL_REQUESTED, $this->eventTypes($job['id']));
    }

    public function testExpiredFileIsDeletedAndHistoryIsKept(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->processQueue();
        $path = static::getContainer()->get(\App\Export\Job\ExportStorage::class)->pathFor($this->job($job['id']));
        self::assertFileExists($path);

        $this->travel(121 * 60);
        $report = $this->monitor();

        self::assertSame(1, $report['fichiersSupprimes']);
        self::assertSame(1, $report['expires']);
        self::assertFileDoesNotExist($path);
        $expired = $this->job($job['id']);
        self::assertSame(ExportJob::STATUS_EXPIRED, $expired->getStatus());
        self::assertNotNull($expired->getFileDeletedAt());
        // Historique intact : étapes, durée, taille, évènements.
        self::assertSame(25, $expired->getRowCount());
        self::assertNotNull($expired->getDurationMs());
        self::assertContains(ExportJobEvent::READY, $this->eventTypes($job['id']));
        self::assertContains(ExportJobEvent::FILE_DELETED, $this->eventTypes($job['id']));

        $download = $this->body($client, "/api/exports/{$job['id']}/download");
        self::assertSame(410, $download['status']);
        self::assertContains(ExportJobEvent::DOWNLOAD_REFUSED, $this->eventTypes($job['id']));
        self::assertNull($client->request('GET', "/api/exports/{$job['id']}")->toArray()['downloadUrl']);
    }

    public function testReadyButNeverDownloadedIsRecordedWithoutEmailByDefault(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->processQueue();

        $this->travel(29 * 60);
        self::assertSame(0, $this->monitor()['nonTelecharges']);
        $this->travel(2 * 60);
        self::assertSame(1, $this->monitor()['nonTelecharges']);
        self::assertSame(0, $this->monitor()['nonTelecharges'], 'Signalé une seule fois');

        self::assertNotNull($this->job($job['id'])->getNotDownloadedAt());
        self::assertContains(ExportJobEvent::NOT_DOWNLOADED, $this->eventTypes($job['id']));
        // EXPORT_NOT_DOWNLOADED_ALERT_THRESHOLD = 0 par défaut : aucun e-mail pour un oubli.
        self::assertSame([], $this->sentEmails());
    }

    public function testProgressNeverMovesWithoutRealWork(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $manager = static::getContainer()->get(\App\Export\Job\ExportJobManager::class);
        $entity = $this->job($job['id']);
        $manager->start($entity);
        // Le worker a réellement traité 500 interventions sur 1 344, puis plus rien.
        $progress = new \App\Export\Job\JobProgress($manager, $entity, hrtime(true), 300);
        $progress->step(ExportStep::Loading);
        $progress->step(ExportStep::Template);
        $progress->step(ExportStep::Surgeries, 1344);
        usleep(600000);
        $progress->advance(500);

        $before = $client->request('GET', "/api/exports/{$job['id']}")->toArray();
        $this->travel(30);
        $after = $client->request('GET', "/api/exports/{$job['id']}")->toArray();

        self::assertSame(37, $before['percent']);
        self::assertSame([500, 1344, 37], [$after['processed'], $after['total'], $after['percent']]);
        self::assertSame('running', $after['steps'][3]['state']);
        self::assertSame('pending', $after['steps'][4]['state']);
    }

    public function testRealProgressReportsEveryProcessedRow(): void
    {
        [$user, $yearId] = $this->seed('volume-100');
        $progress = new \App\Tests\Excel\Support\TimingProgress();
        $this->runExport($user, $yearId, $progress);

        $steps = array_map(fn ($s) => $s['step'], $progress->steps);
        self::assertSame([ExportStep::Loading, ExportStep::Template, ExportStep::Surgeries, ExportStep::Supervisors, ExportStep::Summary, ExportStep::Sheets, ExportStep::Writing], $steps);
        foreach ($progress->steps as $step) {
            if ($step['total'] === null) {
                self::assertSame([], $step['advances'], $step['step']->value . ' : pas d\'avancement sans total');
                continue;
            }
            $advances = $step['advances'];
            self::assertSame($step['total'], end($advances), 'Le dernier avancement est le total');
            self::assertSame($advances, array_values(array_unique($advances)));
            $sorted = $advances;
            sort($sorted);
            self::assertSame($sorted, $advances, 'Avancements croissants');
            self::assertLessThanOrEqual($step['total'], max($advances));
        }
        self::assertSame(100, $progress->steps[2]['total'], 'Interventions de l\'année exportée');
        // Liste récapitulative : une ligne par code distinct retenu (au plus une par intervention).
        self::assertSame(6, $progress->steps[3]['total'], 'Décompte : maître de stage + 5 superviseurs');
        self::assertGreaterThan(0, $progress->steps[4]['total']);
        self::assertLessThanOrEqual(160, $progress->steps[4]['total']);
    }

    public function testLargeExportThroughTheWorker(): void
    {
        [$user, $yearId] = $this->seed('volume-1000');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->processQueue();

        $ready = $client->request('GET', "/api/exports/{$job['id']}")->toArray();
        self::assertSame('ready', $ready['status']);
        self::assertSame(1000, $ready['rowCount']);
        self::assertSame('done', $ready['steps'][5]['state']);
        self::assertLessThanOrEqual(2700, $ready['steps'][5]['total']);
        // Contenu identique à celui du moteur appelé directement.
        $direct = $this->runExport($user, $yearId, clock: \Symfony\Component\Clock\Clock::get());
        $download = $this->body($client, '/api/' . $ready['downloadUrl']);
        $report = (new XlsxComparator())->compare($this->tmp($direct['bytes']), $this->tmp($download['content']));
        self::assertTrue($report['equivalent'], implode("\n", $report['differences']));
    }

    /**
     * Constaté sur staging : après un gros export, rétablir la limite mémoire d'origine (inférieure à
     * l'usage du processus) provoquait un avertissement PHP journalisé en erreur. La limite élargie
     * reste alors en place, sans avertissement.
     */
    public function testMemoryLimitIsRestoredOnlyWhenTheUsageAllowsIt(): void
    {
        [$user, $yearId] = $this->seed('volume-100');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);

        $original = ini_get('memory_limit');
        $warnings = [];
        // Limite d'origine juste au-dessus de l'usage actuel : la génération la dépasse.
        $previous = (string) intdiv(memory_get_usage(true) + 16 * 1024 * 1024, 1024 * 1024) . 'M';
        ini_set('memory_limit', $previous);
        set_error_handler(function (int $type, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, \E_WARNING);
        try {
            $this->processQueue();
            $after = ini_get('memory_limit');
            $usage = memory_get_usage(true);
        } finally {
            restore_error_handler();
            ini_set('memory_limit', $original);
        }

        self::assertSame([], $warnings);
        self::assertSame(ExportJob::STATUS_READY, $this->job($job['id'])->getStatus());
        // Usage supérieur à la limite d'origine (cas d'un test exécuté seul : la génération fait
        // grandir le processus) : la limite élargie est conservée ; sinon la limite d'origine revient.
        self::assertSame($usage >= (int) $previous * 1024 * 1024
            ? static::getContainer()->get(\App\Export\Job\ExportSettings::class)->memoryLimit
            : $previous, $after);
    }

    public function testSynchronousRoutesAreRecordedInTheHistory(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $client = $this->clientFor($user);

        $sync = $this->body($client, '/api/excel2/' . $yearId);
        self::assertSame(200, $sync['status']);
        self::assertSame('attachment; filename=export.xlsx', $sync['headers']['content-disposition'][0]);

        $jobs = $this->em->getRepository(ExportJob::class)->findAll();
        self::assertCount(1, $jobs);
        self::assertSame(['yearId' => $yearId, 'channel' => 'sync'], $jobs[0]->getParameters());
        self::assertSame(ExportJob::STATUS_READY, $jobs[0]->getStatus());
        self::assertSame(25, $jobs[0]->getRowCount());
        self::assertSame(strlen($sync['content']), $jobs[0]->getFileSize());
        self::assertNotNull($jobs[0]->getFirstServedAt());
        self::assertNull($jobs[0]->getFirstDownloadedAt(), 'Envoi observé, jamais confirmé');
        self::assertContains(ExportJobEvent::DOWNLOAD_SERVED, $this->eventTypes($jobs[0]->getPublicId()));
        self::assertSame([], glob(static::getContainer()->getParameter('kernel.project_dir') . '/var/exports-test/*'), 'Aucun fichier conservé');

        // Pas de faux « non téléchargé » ni d'expiration pour la route synchrone.
        $this->travel(3 * 3600);
        $this->monitor();
        self::assertSame(ExportJob::STATUS_READY, $this->job($jobs[0]->getPublicId())->getStatus());
        self::assertNull($this->job($jobs[0]->getPublicId())->getNotDownloadedAt());
    }

    private function tmp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $bytes);

        return $path;
    }
}
