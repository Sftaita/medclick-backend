<?php

namespace App\Tests\Export;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Export\ExportFailure;
use App\Export\Monitoring\ExportMonitor;
use App\Export\Monitoring\ExportWorkerWatch;
use App\Export\Monitoring\Message\MonitorExports;
use App\Export\Monitoring\MonitorExportsHandler;
use App\Tests\Export\Support\SentEmailCollector;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Gardien des exports indépendant du worker (cron sur le serveur : app:exports:monitor --watchdog).
 * Personne ne consulte l'état des exports (ni l'utilisateur ni l'administration, sauf le dernier
 * test) : la détection ne dépend que du gardien.
 */
final class ExportWatchdogTest extends ExportTestCase
{
    public function testWorkerStoppedManuallyIsReportedOnceAndPendingExportsFailWithoutSecondEmail(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $this->workerHeartbeat();
        // Le worker est arrêté à la main (docker compose stop worker) : plus aucun signe de vie.
        $this->travel(170);
        self::assertSame('', $this->watchdog());
        self::assertSame([], $this->sentEmails());

        $job = $this->requestExport($this->clientFor($user), $yearId);
        $this->travel(20);
        self::assertStringContainsString('workerAbsent', $this->watchdog());

        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertSame('[MedClick] Worker des exports Excel arrêté — exports impossibles', $emails[0]->getSubject());
        $text = $emails[0]->getTextBody();
        self::assertStringContainsString('Exports en attente : 1 ; en cours : 0', $text);
        self::assertStringContainsString('/admin/exports', $text);
        self::assertStringNotContainsString('resident.fictif@example.test', $text);
        self::assertSame([], $emails[0]->getAttachments());

        // Le gardien repasse chaque minute : pas de nouvel e-mail pour le même arrêt.
        foreach (range(1, 5) as $minute) {
            $this->travel(60);
            $this->watchdog();
        }
        self::assertCount(1, $this->sentEmails());

        // La demande jamais prise en charge échoue (worker indisponible) ; même incident, pas d'e-mail.
        $this->travel(600);
        $this->watchdog();
        $failed = $this->job($job['id']);
        self::assertSame(ExportJob::STATUS_FAILED, $failed->getStatus());
        self::assertSame(ExportFailure::WORKER_UNAVAILABLE, $failed->getErrorCategory());
        self::assertSame(ExportJob::ALERT_SUPPRESSED, $failed->getAlertStatus());
        self::assertContains(ExportJobEvent::ALERT_SUPPRESSED, $this->eventTypes($job['id']));
        self::assertCount(1, $this->sentEmails());

        $status = $this->watch()->status();
        self::assertSame('stopped', $status['worker']['status']);
        self::assertSame(ExportJob::ALERT_SENT, $status['worker']['alertStatus']);
        self::assertTrue($status['watchdog']['active']);
        self::assertSame(ExportWorkerWatch::BY_WATCHDOG, $status['lastRun']['by']);
    }

    public function testWorkerStoppedDuringGenerationGivesASingleAlert(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $this->workerHeartbeat();
        $job = $this->requestExport($this->clientFor($user), $yearId);
        // Le worker prend l'export en charge puis disparaît.
        static::getContainer()->get(\App\Export\Job\ExportJobManager::class)->start($this->job($job['id']));

        $this->travel(121);
        $this->watchdog();
        self::assertSame(ExportFailure::WORKER_INTERRUPTED, $this->job($job['id'])->getErrorCategory());
        self::assertCount(1, $this->sentEmails());
        self::assertStringContainsString('Blocage', $this->sentEmails()[0]->getSubject());

        // Absence du worker constatée ensuite : déjà signalée par l'export bloqué.
        $this->travel(60);
        self::assertStringContainsString('workerAbsent', $this->watchdog());
        self::assertCount(1, $this->sentEmails());
        self::assertSame('covered', $this->watch()->status()['worker']['alertStatus']);
    }

    public function testLongGenerationIsNotMistakenForAnAbsentWorker(): void
    {
        [$user, $yearId] = $this->seed('petit');
        $this->workerHeartbeat();
        $job = $this->requestExport($this->clientFor($user), $yearId);
        $manager = static::getContainer()->get(\App\Export\Job\ExportJobManager::class);
        $manager->start($this->job($job['id']));

        // Le worker génère : sa surveillance planifiée attend, mais l'export donne signe de vie.
        foreach (range(1, 4) as $minute) {
            $this->travel(60);
            $manager->em()->getConnection()->executeStatement('UPDATE export_job SET heartbeat_at = ? WHERE public_id = ?',
                [\Symfony\Component\Clock\Clock::get()->now()->format('Y-m-d H:i:s'), $job['id']]);
            self::assertSame('', $this->watchdog());
        }
        self::assertSame('running', $this->watch()->status()['worker']['status']);
        self::assertSame([], $this->sentEmails());
    }

    public function testWorkerBackClosesTheIncidentAndANewStopIsReportedAgain(): void
    {
        $this->workerHeartbeat();
        $this->travel(181);
        $this->watchdog();
        self::assertCount(1, $this->sentEmails());

        $this->workerHeartbeat();
        self::assertSame('running', $this->watch()->status()['worker']['status']);
        self::assertNull($this->watch()->status()['worker']['absentSince']);

        // Nouvel arrêt plus tard : nouvel incident, nouvel e-mail (au-delà de la période anti-doublon).
        $this->travel(3600);
        $this->watchdog();
        self::assertCount(2, $this->sentEmails());
    }

    public function testFailedAlertIsKeptAndRetriedWithIncreasingDelay(): void
    {
        $this->workerHeartbeat();
        $this->travel(181);
        SentEmailCollector::$fail = true;
        try {
            $this->watchdog();
            self::assertSame(ExportJob::ALERT_FAILED, $this->watch()->status()['worker']['alertStatus']);
            $this->travel(60);
            $this->watchdog();
        } finally {
            SentEmailCollector::$fail = false;
        }
        // Délai avant la 2e tentative : 5 minutes après la 1re.
        $this->travel(60);
        $this->watchdog();
        self::assertSame([], $this->sentEmails());
        $this->travel(240);
        $this->watchdog();
        self::assertCount(1, $this->sentEmails());
        self::assertSame(ExportJob::ALERT_SENT, $this->watch()->status()['worker']['alertStatus']);
    }

    public function testOnlyOneMonitoringRunsAtATime(): void
    {
        $watch = $this->watch();
        self::assertTrue($watch->acquire());
        self::assertNull(static::getContainer()->get(ExportMonitor::class)->runExclusive(ExportWorkerWatch::BY_WORKER));
        $watch->release(ExportWorkerWatch::BY_COMMAND);
        self::assertIsArray(static::getContainer()->get(ExportMonitor::class)->runExclusive(ExportWorkerWatch::BY_WORKER));
        // Bail d'un processus disparu : expire après 2 minutes.
        self::assertTrue($watch->acquire());
        $this->travel(121);
        self::assertIsArray(static::getContainer()->get(ExportMonitor::class)->runExclusive(ExportWorkerWatch::BY_WORKER));
    }

    public function testAdministrationShowsWorkerAndWatchdogState(): void
    {
        $client = $this->clientFor($this->createUser('admin@example.test', ['ROLE_ADMIN']));

        $this->workerHeartbeat();
        $this->watchdog();
        $monitoring = $client->request('GET', '/api/admin/exports')->toArray()['monitoring'];
        self::assertSame('running', $monitoring['worker']['status']);
        self::assertTrue($monitoring['watchdog']['active']);

        $this->travel(400);
        $monitoring = $client->request('GET', '/api/admin/exports')->toArray()['monitoring'];
        self::assertSame('stopped', $monitoring['worker']['status']);
        self::assertFalse($monitoring['watchdog']['active']);
    }

    /** Surveillance planifiée exécutée dans le worker : signe de vie du worker. */
    private function workerHeartbeat(): void
    {
        static::getContainer()->get(MonitorExportsHandler::class)(new MonitorExports());
    }

    /** Passage du gardien (cron) ; renvoie la sortie de la commande. */
    private function watchdog(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:exports:monitor'));
        $tester->execute(['--watchdog' => true]);
        self::assertSame(0, $tester->getStatusCode());

        return trim($tester->getDisplay());
    }

    private function watch(): ExportWorkerWatch
    {
        return static::getContainer()->get(ExportWorkerWatch::class);
    }
}
