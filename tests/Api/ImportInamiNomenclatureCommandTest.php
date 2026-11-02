<?php

namespace App\Tests\Api;

use App\Entity\Favorites;
use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Étape 5 : import du plan INAMI vérifié (app:nomenclature:import-inami).
 * Dry-run sans écriture, ajouts et abrogations établies, idempotence, refus d'un plan incohérent,
 * conflit jamais écrasé, interventions / favoris / export inchangés, saisie tardive.
 */
class ImportInamiNomenclatureCommandTest extends ApiTestBase
{
    private User $alice;
    private Years $year;
    private Nomenclature $old;     // abrogé au 01/01/2014 (sera désactivé)
    private Nomenclature $current; // toujours valide
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);
        $this->old = (new Nomenclature())->setSpeciality('ortho')->setName('Méniscectomie (ancien code)')->setCodeHospitalisation('300344')->setCodeAmbulant('300333')->setN('N250')->setType('1')->setSubType('knee');
        $this->current = (new Nomenclature())->setSpeciality('ortho')->setName('Méniscectomie')->setCodeHospitalisation('276640')->setCodeAmbulant('276636')->setN('N250')->setType('1')->setSubType('knee');
        $this->em->persist($this->old);
        $this->em->persist($this->current);
        $this->em->flush();

        // Données historiques liées au code abrogé (après l'abrogation, comme en production).
        foreach (['2013-06-01', '2025-03-10'] as $date) {
            $this->em->persist((new Surgeries())->setYear($this->year)->setNomenclature($this->old)->setCode('300344N250')
                ->setName('Méniscectomie (ancien code)')->setSpeciality('ortho')->setPosition('1')->setDate(new \DateTime($date))
                ->setFirstHand((string) $this->alice->getId()));
        }
        $this->em->persist((new Favorites())->setUser($this->alice)->setShortcut('Ménisque')->setSurgeryName('Méniscectomie (ancien code)')
            ->setCodeHospitalisation('300344N250')->setSpeciality('ortho')->setSurgery($this->old));
        $this->em->flush();
        $this->dir = sys_get_temp_dir() . '/import-inami-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testDryRunWritesNothing(): void
    {
        $before = $this->snapshot();
        $tester = $this->runImport($this->plan());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('aucune écriture', $tester->getDisplay());
        $this->assertSame($before, $this->snapshot());
    }

    public function testApplyAddsAndDeactivatesWithoutTouchingHistoricalData(): void
    {
        $nomenclatureBefore = $this->row('nomenclature', $this->old->getId());
        $surgeries = $this->rows('surgeries');
        $favorites = $this->rows('favorites');

        $tester = $this->runImport($this->plan(), true);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $this->em->clear();
        $old = $this->em->find(Nomenclature::class, $this->old->getId());
        $this->assertFalse($old->isActive());
        $this->assertSame('2014-01-01', $old->getInamiRepealedAt()->format('Y-m-d'));
        $this->assertNotNull($old->getInamiRepealVerifiedAt());
        $this->assertStringStartsWith('INAMI NomenSoft', $old->getInamiRepealSource());
        $after = $this->row('nomenclature', $this->old->getId());
        foreach (['id', 'speciality', 'code_ambulant', 'code_hospitalisation', 'name', 'type', 'sub_type', 'n'] as $column) {
            $this->assertSame($nomenclatureBefore[$column], $after[$column], $column);
        }

        $added = $this->em->getRepository(Nomenclature::class)->findOneBy(['codeHospitalisation' => '262625']);
        $this->assertSame(['uro', '262614', 'K75', null, null, true], [$added->getSpeciality(), $added->getCodeAmbulant(), $added->getN(), $added->getType(), $added->getSubType(), $added->isActive()]);
        $this->assertTrue($this->em->find(Nomenclature::class, $this->current->getId())->isActive());

        $this->assertSame($surgeries, $this->rows('surgeries'), 'interventions inchangées');
        $this->assertSame($favorites, $this->rows('favorites'), 'favoris inchangés');
    }

    public function testASecondRunChangesNothing(): void
    {
        $this->runImport($this->plan(), true);
        $before = $this->snapshot();

        $tester = $this->runImport($this->plan(), true);
        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertMatchesRegularExpression('/déjà présent \(ignoré\)\s+1/', $display);
        $this->assertMatchesRegularExpression('/déjà appliquée \(ignorée\)\s+1/', $display);
        $this->assertSame($before, $this->snapshot(), 'aucun doublon, aucune modification');
    }

    public function testAnIncoherentPlanIsRefusedBeforeAnyWrite(): void
    {
        $before = $this->snapshot();
        $plan = $this->plan();
        $plan['repeal'][0]['codeHospitalisation'] = '999999';
        $tester = $this->runImport($plan, true);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Plan refusé', $tester->getDisplay());

        $plan = $this->plan();
        $plan['add'][0] = ['speciality' => 'ortho', 'codeHospitalisation' => '286720', 'n' => 'N440', 'name' => 'Prothèse', 'reference' => 'INAMI'];
        $this->assertSame(1, $this->runImport($plan, true)->getStatusCode(), 'code orthopédique sans classification');

        $this->assertSame($before, $this->snapshot());
    }

    public function testAnotherRecordedRepealIsNeverOverwritten(): void
    {
        $this->old->setInamiRepealedAt(new \DateTimeImmutable('2015-01-01'));
        $this->em->flush();
        $tester = $this->runImport($this->plan(), true);
        $this->assertMatchesRegularExpression('/conflit \(ignorée\)\s+1/', $tester->getDisplay());

        $this->em->clear();
        $old = $this->em->find(Nomenclature::class, $this->old->getId());
        $this->assertTrue($old->isActive());
        $this->assertSame('2015-01-01', $old->getInamiRepealedAt()->format('Y-m-d'));
    }

    public function testSeparatePlansAndRevertOfTheDeactivations(): void
    {
        $initial = $this->row('nomenclature', $this->old->getId());
        $adds = ['add' => $this->plan()['add'], 'repeal' => []] + $this->plan();
        $deactivations = ['add' => [], 'repeal' => $this->plan()['repeal']] + $this->plan();

        // Ajouts seuls : aucune désactivation.
        $this->runImport($adds, true);
        $this->em->clear();
        $this->assertTrue($this->em->find(Nomenclature::class, $this->old->getId())->isActive());
        $this->assertNotNull($this->em->getRepository(Nomenclature::class)->findOneBy(['codeHospitalisation' => '262625']));

        // Désactivations, puis retour arrière : simulation sans écriture, puis état initial exact.
        $this->runImport($deactivations, true);
        $before = $this->snapshot();
        $this->assertMatchesRegularExpression('/à rétablir\s+1/', $this->runImport($deactivations, false, true)->getDisplay());
        $this->assertSame($before, $this->snapshot());
        $this->assertMatchesRegularExpression('/rétabli\s+1/', $this->runImport($deactivations, true, true)->getDisplay());
        $this->assertSame($initial, $this->row('nomenclature', $this->old->getId()), 'ligne revenue à son état initial');
        $this->assertNotNull($this->em->getRepository(Nomenclature::class)->findOneBy(['codeHospitalisation' => '262625']), 'ajout jamais supprimé');
    }

    public function testLateEntryAndExportAfterTheImport(): void
    {
        $exportBefore = $this->exportCells();
        $this->runImport($this->plan(), true);
        $this->assertSame($exportBefore, $this->exportCells(), 'carnet identique après l\'import');

        $client = $this->clientFor($this->alice);
        foreach (['2013-12-31' => 200, '2014-01-01' => 400, '2026-10-20' => 400] as $date => $status) {
            $client->request('POST', '/api/surgeries/addNewSurgery', ['json' => ['date' => $date, 'year' => $this->year->getId(), 'surgeryId' => $this->old->getId(), 'position' => 1]]);
            $this->assertSame($status, $client->getResponse()->getStatusCode(), $date);
        }
        $list = $client->request('GET', '/api/nomenclature/ortho?date=2013-12-31')->toArray();
        $this->assertContains($this->old->getId(), array_column($list, 'id'));
        $this->assertNotContains($this->old->getId(), array_column($client->request('GET', '/api/nomenclature/ortho')->toArray(), 'id'));
    }

    /**
     * Plan 3 livré (compléments des 25 codes en attente) : 20 ajouts valides, le doublon de libellé
     * « avec réduction » existant reste distinct, deuxième exécution sans effet.
     */
    public function testShippedComplementsPlanAddsTwentyDistinctCodes(): void
    {
        $twin = (new Nomenclature())->setSpeciality('ortho')->setName('Fracture du col du fémur')->setCodeHospitalisation('298266')
            ->setCodeAmbulant('298255')->setN('N300')->setType('2')->setSubType('hip');
        $this->em->persist($twin);
        $this->em->flush();
        $twinBefore = $this->row('nomenclature', $twin->getId());
        $plan = json_decode(file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/data/inami/plan-import-inami-20261101-3-complements.json'), true);
        $this->assertCount(20, $plan['add']);
        $this->assertSame([], $plan['repeal']);

        $this->assertMatchesRegularExpression('/à ajouter\s+20/', $this->runImport($plan)->getDisplay());
        $this->assertSame(0, $this->runImport($plan, true)->getStatusCode());
        $this->em->clear();
        $repository = $this->em->getRepository(Nomenclature::class);
        $neck = $repository->findOneBy(['codeHospitalisation' => '298745']);
        $this->assertSame(['ortho', '298734', 'N130', '2', 'hip', true], [$neck->getSpeciality(), $neck->getCodeAmbulant(), $neck->getN(), $neck->getType(), $neck->getSubType(), $neck->isActive()]);
        $this->assertStringEndsWith('sans réduction', $neck->getName());
        $thor = $repository->findOneBy(['codeHospitalisation' => '229482']);
        $this->assertSame(['thor', null, null], [$thor->getSpeciality(), $thor->getType(), $thor->getSubType()]);
        $this->assertSame($twinBefore, $this->row('nomenclature', $twin->getId()), 'code « avec réduction » inchangé');

        $before = $this->snapshot();
        $this->assertMatchesRegularExpression('/déjà présent \(ignoré\)\s+20/', $this->runImport($plan, true)->getDisplay());
        $this->assertSame($before, $this->snapshot());
    }

    // ------------------------------------------------- Helpers

    private function plan(): array
    {
        return [
            'source' => ['label' => 'INAMI NomenSoft — test'],
            'add' => [[
                'speciality' => 'uro', 'codeHospitalisation' => '262625', 'codeAmbulant' => '262614', 'n' => 'K75',
                'name' => 'Circoncision chez un enfant de moins de 6 ans', 'type' => null, 'subType' => null,
                'officialStart' => '2026-06-01', 'reference' => 'INAMI NomenSoft 01.11.2026 — création au 01/06/2026',
            ]],
            'repeal' => [[
                'id' => $this->old->getId(), 'codeHospitalisation' => '300344', 'repealedAt' => '2014-01-01',
                'source' => 'INAMI NomenSoft 01.11.2026 — KB2013022580 (publ. 28-11-2013) suppression au 01/01/2014',
            ]],
        ];
    }

    private function runImport(array $plan, bool $apply = false, bool $revert = false): CommandTester
    {
        $path = $this->dir . '/plan.json';
        file_put_contents($path, json_encode($plan, JSON_UNESCAPED_UNICODE));
        $tester = new CommandTester((new Application(static::$kernel))->find('app:nomenclature:import-inami'));
        $tester->execute(['plan' => $path] + ($apply ? ['--apply' => true] : []) + ($revert ? ['--revert' => true] : []));

        return $tester;
    }

    private function rows(string $table): array
    {
        return $this->em->getConnection()->fetchAllAssociative("SELECT * FROM $table ORDER BY id");
    }

    private function row(string $table, int $id): array
    {
        return $this->em->getConnection()->fetchAssociative("SELECT * FROM $table WHERE id = ?", [$id]);
    }

    private function snapshot(): array
    {
        return [$this->rows('nomenclature'), $this->rows('surgeries'), $this->rows('favorites')];
    }

    private function exportCells(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);
        $cells = [];
        foreach ([1, 2] as $index) {
            $values = array_map(fn ($v) => trim((string) $v), array_merge(...$book->getSheet($index)->toArray()));
            $cells[$index] = array_values(array_filter($values, fn ($v) => !str_contains($v, date('d/m/Y')) && !str_contains($v, date('Y-m-d'))));
        }

        return $cells;
    }
}
