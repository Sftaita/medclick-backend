<?php

namespace App\Tests\Export;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Droits d'accès, isolation entre utilisateurs, exports simultanés, administration, rétention.
 */
final class ExportAccessTest extends ExportTestCase
{
    public function testUsersOnlySeeTheirOwnExports(): void
    {
        [$alice, $aliceYear] = $this->seed('petit', 'alice@example.test');
        [$bob] = $this->seed('vide', 'bob@example.test');
        $admin = $this->createUser('admin@example.test', ['ROLE_ADMIN']);
        $aliceClient = $this->clientFor($alice);
        $job = $this->requestExport($aliceClient, $aliceYear);
        $this->processQueue();

        foreach ([$this->clientFor($bob), $this->clientFor($admin)] as $other) {
            self::assertSame(404, $other->request('GET', "/api/exports/{$job['id']}")->getStatusCode());
            self::assertSame(404, $other->request('GET', "/api/exports/{$job['id']}/download")->getStatusCode(), 'Même un administrateur ne télécharge pas le fichier d\'un utilisateur');
            self::assertSame(404, $other->request('POST', "/api/exports/{$job['id']}/cancel")->getStatusCode());
            self::assertSame(404, $other->request('POST', "/api/exports/{$job['id']}/confirm", ['json' => ['size' => 1]])->getStatusCode());
            self::assertSame([], $other->request('GET', '/api/exports')->toArray()['items']);
            // Ni demander l'export d'une année qui ne lui appartient pas.
            self::assertSame(403, $other->request('POST', '/api/exports', ['json' => ['yearId' => $aliceYear]])->getStatusCode());
        }
        self::assertSame(404, $aliceClient->request('POST', '/api/exports', ['json' => ['yearId' => 999999]])->getStatusCode());
        self::assertSame(404, $aliceClient->request('POST', '/api/exports', ['json' => ['yearId' => 'x']])->getStatusCode());
        self::assertSame(1, $this->em->getRepository(ExportJob::class)->count([]));

        // Sans authentification.
        self::assertSame(401, static::createClient()->request('GET', "/api/exports/{$job['id']}")->getStatusCode());
        self::assertSame(401, static::createClient()->request('GET', "/api/exports/{$job['id']}/download")->getStatusCode());
    }

    public function testSimultaneousExportsOfSeveralUsersAreQueuedAndKeptSeparate(): void
    {
        $users = [];
        foreach (['un', 'deux', 'trois'] as $index => $name) {
            [$user, $yearId] = $this->seed(['petit', 'multi-annees', 'sans-maitre'][$index], "resident.$name@example.test");
            $users[] = [$user, $yearId, $this->clientFor($user)];
        }
        $jobs = [];
        foreach ($users as [$user, $yearId, $client]) {
            $jobs[] = $this->requestExport($client, $yearId);
        }
        // File réelle : chacun connaît le nombre de générations avant la sienne.
        self::assertSame([0, 1, 2], array_column($jobs, 'queuePosition'));

        foreach ($jobs as $job) {
            $this->handle($this->job($job['id']));
        }
        foreach ($users as $index => [$user, $yearId, $client]) {
            $download = $this->body($client, "/api/exports/{$jobs[$index]['id']}/download");
            self::assertSame(200, $download['status']);
            $path = tempnam(sys_get_temp_dir(), 'x');
            file_put_contents($path, $download['content']);
            // Page de garde : l'adresse du propriétaire, et seulement la sienne.
            self::assertSame($user->getEmail(), IOFactory::load($path)->getSheet(0)->getCell('F28')->getValue());
            $direct = $this->runNew($user, $yearId, clock: \Symfony\Component\Clock\Clock::get());
            self::assertTrue($this->compareBytes($direct['bytes'], $download['content'])['equivalent']);
        }
        self::assertCount(3, array_unique(array_map(fn ($j) => $this->job($j['id'])->getFileSha256(), $jobs)));
    }

    public function testAdminMonitoringRequiresTheAdminRole(): void
    {
        [$user] = $this->seed('vide');
        $client = $this->clientFor($user);

        self::assertSame(403, $client->request('GET', '/api/admin/exports')->getStatusCode());
        self::assertSame(401, static::createClient()->request('GET', '/api/admin/exports')->getStatusCode());
    }

    public function testAdminSeesCountsHistoryAndDetailsWithoutMedicalData(): void
    {
        [$user, $yearId] = $this->seed('petit', 'resident.suivi@example.test');
        $client = $this->clientFor($user);
        $ok = $this->requestExport($client, $yearId);
        $this->processQueue();
        $failed = $this->requestExport($client, $yearId);
        $this->travel(901);
        $this->monitor();
        $this->travel(31 * 60);
        $this->monitor();

        $admin = $this->clientFor($this->createUser('admin@example.test', ['ROLE_ADMIN']));
        $list = $admin->request('GET', '/api/admin/exports')->toArray();
        self::assertSame(2, $list['total']);
        self::assertSame(1, $list['counts']['ready']);
        self::assertSame(1, $list['counts']['failed']);
        self::assertSame(1, $list['counts']['notDownloaded']);
        self::assertSame('resident.suivi@example.test', $list['items'][0]['user']['email']);

        // Filtres : statut, utilisateur, type, période.
        self::assertSame([$failed['id']], array_column($admin->request('GET', '/api/admin/exports?status=failed')->toArray()['items'], 'id'));
        self::assertSame(2, $admin->request('GET', '/api/admin/exports?user=suivi')->toArray()['total']);
        self::assertSame(0, $admin->request('GET', '/api/admin/exports?user=personne')->toArray()['total']);
        self::assertSame(2, $admin->request('GET', '/api/admin/exports?type=carnet_de_stage')->toArray()['total']);
        self::assertSame(2, $admin->request('GET', '/api/admin/exports?from=2026-10-27&to=2026-10-27')->toArray()['total']);
        self::assertSame(0, $admin->request('GET', '/api/admin/exports?from=2026-10-28')->toArray()['total']);

        $detail = $admin->request('GET', "/api/admin/exports/{$failed['id']}")->toArray();
        self::assertSame('worker_unavailable', $detail['error']['category']);
        self::assertSame('sent', $detail['alert']['status']);
        self::assertNotEmpty($detail['events']);
        self::assertArrayNotHasKey('downloadUrl', $detail);

        $readyDetail = $admin->request('GET', "/api/admin/exports/{$ok['id']}")->toArray();
        self::assertSame(['preparing', 'loading', 'template', 'surgeries', 'supervisors', 'summary', 'sheets', 'writing'], array_column($readyDetail['steps'], 'step'));
        self::assertNotNull($readyDetail['download']['notDownloadedAt']);
        // Aucune donnée médicale dans ce que voit l'administrateur.
        $json = json_encode($readyDetail, JSON_UNESCAPED_UNICODE);
        foreach (['Acte fictif', 'CHU Test', 'prénom', 'Formation fictive'] as $medical) {
            self::assertStringNotContainsString($medical, $json);
        }

        // Consultation journalisée.
        self::assertContains(ExportJobEvent::ADMIN_VIEWED, $this->eventTypes($ok['id']));
    }

    public function testAdminCanRetryAFailedAlert(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $job = $this->requestExport($client, $yearId);
        $this->travel(901);
        $this->monitor();
        $entity = $this->job($job['id'])->setAlertStatus(ExportJob::ALERT_FAILED)->setAlertLastError('SMTP indisponible');
        $this->em->flush();

        $admin = $this->clientFor($this->createUser('admin@example.test', ['ROLE_ADMIN']));
        $retried = $admin->request('POST', "/api/admin/exports/{$job['id']}/alert/retry")->toArray();
        self::assertTrue($retried['sent']);
        self::assertSame('sent', $retried['alert']['status']);
        self::assertSame(409, $admin->request('POST', "/api/admin/exports/{$job['id']}/alert/retry")->getStatusCode());
    }

    public function testHistoryRetentionAndOrphanFiles(): void
    {
        [$user, $yearId] = $this->seed('vide');
        $client = $this->clientFor($user);
        $old = $this->requestExport($client, $yearId);
        $this->processQueue();
        $this->travel(366 * 24 * 3600);
        $recent = $this->requestExport($client, $yearId);
        $this->processQueue();

        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/exports-test';
        file_put_contents("$dir/inconnu.xlsx", 'x');
        file_put_contents("$dir/00000000-0000-4000-8000-000000000000.xlsx", 'x');
        touch("$dir/inconnu.xlsx", time() - 7200);
        touch("$dir/00000000-0000-4000-8000-000000000000.xlsx", time() - 7200);

        $report = $this->monitor();

        self::assertSame(1, $report['historiqueSupprime']);
        self::assertNull($this->em->getRepository(ExportJob::class)->findOneBy(['publicId' => $old['id']]));
        // Évènements de l'export supprimé effacés avec lui ; ceux de l'export récent conservés.
        $recentJob = $this->job($recent['id']);
        self::assertSame($this->em->getRepository(ExportJobEvent::class)->count([]),
            $this->em->getRepository(ExportJobEvent::class)->count(['job' => $recentJob]));
        self::assertSame(2, $report['orphelins']);
        self::assertFileExists("$dir/{$recent['id']}.xlsx");
    }
}
