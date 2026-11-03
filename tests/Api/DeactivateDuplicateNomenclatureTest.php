<?php

namespace App\Tests\Api;

use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-212 : désactivation administrative d'une ligne en doublon. Elle sort des listes à toute date, ce n'est pas une
 * abrogation INAMI, et ses interventions historiques restent intactes, modifiables et exportées, sans recodage.
 */
class DeactivateDuplicateNomenclatureTest extends ApiTestBase
{
    private const REASON = 'Désactivation administrative (non abrogée INAMI) : doublon';

    private User $alice;
    private Years $year;
    private Nomenclature $duplicate;
    private Nomenclature $kept;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);
        $this->duplicate = $this->code('226542', '226494', 'Fermeture d\'une brèche de la dure mère');
        $this->kept = $this->code('226505', '226494', 'Fermeture d\'une brèche de la dure mère');
        $this->em->flush();
        $this->dir = sys_get_temp_dir() . '/medclick-doublons-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testDryRunApplySecondRunAndExactRevert(): void
    {
        $before = $this->row();

        $this->assertMatchesRegularExpression('/à désactiver\s+1/', $this->run_()->getDisplay());
        $this->assertSame($before, $this->row(), 'simulation : aucune écriture');

        $this->assertMatchesRegularExpression('/désactivée\s+1/', $this->run_(apply: true)->getDisplay());
        $after = $this->row();
        $this->assertSame(0, (int) $after['active']);
        $this->assertSame(self::REASON, $after['deactivation_reason']);
        $this->assertNotNull($after['deactivated_at']);
        // Pas une abrogation officielle ; rien d'autre ne change.
        foreach (['inami_repealed_at', 'inami_repeal_source', 'inami_repeal_verified_at'] as $column) {
            $this->assertNull($after[$column], $column);
        }
        $expected = array_merge($before, array_intersect_key($after, array_flip(['active', 'deactivated_at', 'deactivation_reason'])));
        $this->assertSame($expected, $after);

        $display = $this->run_(apply: true)->getDisplay();
        $this->assertMatchesRegularExpression('/désactivée\s+0/', $display);
        $this->assertMatchesRegularExpression('/déjà dans cet état \(ignorée\)\s+1/', $display);
        $this->assertSame($after, $this->row(), 'seconde exécution : date technique conservée');

        $this->assertMatchesRegularExpression('/à rétablir\s+1/', $this->run_(revert: true)->getDisplay());
        $this->run_(apply: true, revert: true);
        $this->assertSame($before, $this->row(), 'retour arrière exact');
        $this->assertMatchesRegularExpression('/rétablie\s+0/', $this->run_(apply: true, revert: true)->getDisplay());
    }

    public function testAnInconsistentPlanIsRefusedWithoutAnyWrite(): void
    {
        $before = $this->row();
        $cases = [
            'sans justification' => ['reason' => ' '],
            'autre code' => ['codeHospitalisation' => '999999'],
            'autre libellé' => ['name' => 'Autre'],
            'autre valeur' => ['n' => 'K999'],
            'ligne conservée inconnue' => ['sameActAs' => 999999],
            'ligne conservée = elle-même' => ['sameActAs' => $this->duplicate->getId()],
            'correction non appliquée' => ['sameActCodes' => ['codeHospitalisation' => '111111', 'codeAmbulant' => '222222']],
        ];
        foreach ($cases as $label => $change) {
            $plan = $this->plan();
            $plan['deactivate'][0] = array_merge($plan['deactivate'][0], $change);
            $this->assertSame(1, $this->runPlan($plan, apply: true)->getStatusCode(), $label);
            $this->assertSame($before, $this->row(), $label);
        }

        // Ligne conservée d'un autre acte.
        $other = $this->code('300000', '300001', 'Autre acte');
        $this->em->flush();
        $plan = $this->plan();
        $plan['deactivate'][0]['sameActAs'] = $other->getId();
        $plan['deactivate'][0]['sameActCodes'] = ['codeHospitalisation' => '300000', 'codeAmbulant' => '300001'];
        $this->assertSame(1, $this->runPlan($plan, apply: true)->getStatusCode());

        // Ligne partagée en neurochirurgie.
        $duplicate = $this->em->find(Nomenclature::class, $this->duplicate->getId());
        $duplicate->shareWith('neuro');
        $this->em->flush();
        $this->assertSame(1, $this->run_(apply: true)->getStatusCode());
        $this->em->find(Nomenclature::class, $this->duplicate->getId())->unshareWith('neuro');
        $this->em->flush();

        // Ligne portant une abrogation officielle : jamais confondue.
        $duplicate = $this->em->find(Nomenclature::class, $this->duplicate->getId());
        $duplicate->setInamiRepealedAt(new \DateTimeImmutable('2026-01-01'))->setInamiRepealSource('Moniteur');
        $this->em->flush();
        $this->assertSame(1, $this->run_(apply: true)->getStatusCode());
        $this->assertSame(1, (int) $this->row()['active']);
    }

    /** Inactive sans abrogation vérifiée : proposée à aucune date ; refusée à toute date. La ligne conservée reste. */
    public function testTheDuplicateIsNeverOfferedAndTheKeptLineStays(): void
    {
        $this->run_(apply: true);
        $client = $this->clientFor($this->alice);
        foreach (['', '?date=2000-01-01', '?date=2020-06-15', '?date=2026-10-15'] as $query) {
            $ids = array_column($client->request('GET', '/api/nomenclature/ortho' . $query)->toArray(), 'id');
            $this->assertNotContains($this->duplicate->getId(), $ids, "doublon $query");
            $this->assertContains($this->kept->getId(), $ids, "ligne conservée $query");
        }

        foreach (['2000-01-01', '2026-10-15'] as $date) {
            foreach (['addNewSurgery', 'add'] as $route) {
                $response = $this->clientFor($this->alice)->request('POST', '/api/surgeries/' . $route, ['json' => [
                    'date' => $date, 'year' => $this->year->getId(), 'surgeryId' => $this->duplicate->getId(), 'position' => 1,
                ]]);
                $this->assertSame(400, $response->getStatusCode(), "$route $date");
                $this->assertSame('nomenclature_inactive', $response->toArray(false)['reason']);
            }
            $response = $this->clientFor($this->alice)->request('POST', '/api/surgeries', ['json' => [
                'date' => $date, 'speciality' => 'ortho', 'name' => 'x', 'position' => '1',
                'year' => '/api/years/' . $this->year->getId(), 'surgeryId' => (string) $this->duplicate->getId(),
            ]]);
            $this->assertSame(422, $response->getStatusCode(), "API Platform $date");
        }
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM surgeries'));

        $response = $this->clientFor($this->alice)->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'x', 'surgeryId' => $this->duplicate->getId()]]);
        $this->assertSame(400, $response->getStatusCode());
        $response = $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'date' => '2026-10-15', 'year' => $this->year->getId(), 'surgeryId' => $this->kept->getId(), 'position' => 1,
        ]]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('226505K200', $this->surgeryRows()[array_key_last($this->surgeryRows())]['code']);
    }

    /** Intervention historique : conservée, consultable, modifiable sans recodage, exportée. */
    public function testHistoricalInterventionsStayIntactEditableAndExported(): void
    {
        $surgery = (new Surgeries())
            ->setYear($this->em->getReference(Years::class, $this->year->getId()))
            ->setDate(new \DateTime('2024-03-01'))
            ->setNomenclature($this->em->getReference(Nomenclature::class, $this->duplicate->getId()))
            ->setCode('226542K200')
            ->setName($this->duplicate->getName())
            ->setSpeciality('ortho')
            ->setPosition('1')
            ->setFirstHand((string) $this->alice->getId())
            ->setCreatedAt(new \DateTime('2024-03-10 10:00'));
        $this->em->persist($surgery);
        $this->em->flush();
        $id = $surgery->getId();

        $rows = $this->surgeryRows();
        $export = $this->exportedSheets();
        $this->run_(apply: true);

        $this->assertSame($rows, $this->surgeryRows());
        $this->assertSame($export, $this->exportedSheets());
        $item = $this->clientFor($this->alice)->request('GET', '/api/surgeries/' . $id)->toArray();
        $this->assertSame([$this->duplicate->getName(), 'ortho'], [$item['name'], $item['speciality']], 'consultable');
        $this->assertNull($item['inamiRepealedAt'] ?? null, 'pas présentée comme abrogée');

        // Modification sans acte, en renvoyant l'acte, et par l'ancienne route ; date et rôle seuls changent.
        $client = $this->clientFor($this->alice);
        $response = $client->request('PUT', '/api/surgeries/' . $id, ['json' => ['date' => '2024-03-02']]);
        $this->assertSame(200, $response->getStatusCode());
        $response = $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $id, ['json' => ['date' => '2024-03-03', 'surgeryId' => (string) $this->duplicate->getId(), 'speciality' => 'ortho']]);
        $this->assertSame(200, $response->getStatusCode());
        $response = $this->clientFor($this->alice)->request('PUT', '/api/surgeries/update/' . $id, ['json' => [
            'year' => $this->year->getId(), 'date' => '2024-03-04', 'position' => 1, 'surgeryId' => $this->duplicate->getId(),
        ]]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayNotHasKey('warning', $response->toArray(), 'aucun avertissement d\'abrogation');

        $row = $this->surgeryRows()[$id];
        $this->assertSame(
            ['226542K200', $this->duplicate->getName(), 'ortho', $this->duplicate->getId(), '2024-03-04'],
            [$row['code'], $row['name'], $row['speciality'], (int) $row['nomenclature_id'], substr($row['date'], 0, 10)],
            'aucun recodage vers la ligne conservée',
        );
    }

    /** Administration : justification visible ; une réactivation l'efface. Logique d'abrogation inchangée. */
    public function testAdministrationShowsTheReasonAndReactivationClearsIt(): void
    {
        $this->run_(apply: true);
        $admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $items = array_column($this->clientFor($admin)->request('GET', '/api/admin/nomenclature/ortho')->toArray(), null, 'id');
        $this->assertSame(self::REASON, $items[$this->duplicate->getId()]['deactivationReason']);
        $this->assertFalse($items[$this->duplicate->getId()]['active']);
        $this->assertNull($items[$this->duplicate->getId()]['inamiRepealedAt']);
        $this->assertNull($items[$this->kept->getId()]['deactivationReason']);

        $this->clientFor($admin)->request('PUT', '/api/admin/nomenclature/' . $this->duplicate->getId() . '/status', ['json' => ['active' => true]]);
        $this->assertResponseIsSuccessful();
        $row = $this->row();
        $this->assertSame([1, null, null], [(int) $row['active'], $row['deactivation_reason'], $row['deactivated_at']]);
    }

    public function testTheShippedPlanMatchesTheCorrectionPlan(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/data/inami/';
        $plan = json_decode(file_get_contents($dir . 'plan-desactivation-doublons-14n-20261101.json'), true);
        $correct = array_column(json_decode(file_get_contents($dir . 'plan-correction-references-14n-20261101.json'), true)['correct'], null, 'id');

        $this->assertSame([6950, 6957], array_column($plan['deactivate'], 'id'));
        $this->assertSame([6951, 6956], array_column($plan['deactivate'], 'sameActAs'));
        foreach ($plan['deactivate'] as $line) {
            $kept = $correct[$line['sameActAs']];
            $this->assertSame($kept['after'], $line['sameActCodes']);
            $this->assertSame([$kept['name'], $kept['n']], [$line['name'], $line['n']]);
            $this->assertStringContainsString('non abrogée INAMI', $line['reason']);
            $this->assertLessThanOrEqual(255, mb_strlen($line['reason']));
        }
    }

    // ------------------------------------------------- Helpers

    private function code(string $hosp, string $amb, string $name): Nomenclature
    {
        $nomenclature = (new Nomenclature())->setSpeciality('ortho')->setCodeHospitalisation($hosp)->setCodeAmbulant($amb)
            ->setN('K200')->setName($name)->setType('1')->setSubType('back');
        $this->em->persist($nomenclature);

        return $nomenclature;
    }

    private function plan(): array
    {
        return [
            'source' => ['label' => 'test'],
            'deactivate' => [[
                'id' => $this->duplicate->getId(), 'codeHospitalisation' => '226542', 'codeAmbulant' => '226494', 'n' => 'K200', 'speciality' => 'ortho',
                'name' => $this->duplicate->getName(), 'sameActAs' => $this->kept->getId(),
                'sameActCodes' => ['codeHospitalisation' => '226505', 'codeAmbulant' => '226494'], 'reason' => self::REASON,
            ]],
        ];
    }

    private function run_(bool $apply = false, bool $revert = false): CommandTester
    {
        return $this->runPlan($this->plan(), $apply, $revert);
    }

    private function runPlan(array $plan, bool $apply = false, bool $revert = false): CommandTester
    {
        $path = $this->dir . '/plan.json';
        file_put_contents($path, json_encode($plan, JSON_UNESCAPED_UNICODE));
        $tester = new CommandTester((new Application(static::$kernel))->find('app:nomenclature:deactivate-duplicates'));
        $tester->execute(['plan' => $path] + ($apply ? ['--apply' => true] : []) + ($revert ? ['--revert' => true] : []));
        $this->em->clear();

        return $tester;
    }

    private function row(): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM nomenclature WHERE id = ?', [$this->duplicate->getId()]);
    }

    private function surgeryRows(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM surgeries ORDER BY id'), null, 'id');
    }

    private function exportedSheets(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);
        $sheets = [];
        foreach ($book->getAllSheets() as $i => $sheet) {
            if ($i > 0) {
                $sheets[$sheet->getTitle()] = $sheet->toArray();
            }
        }
        $this->assertStringContainsString('226542K200', json_encode($sheets));

        return $sheets;
    }
}
