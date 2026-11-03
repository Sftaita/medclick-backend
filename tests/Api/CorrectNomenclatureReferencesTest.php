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
 * ADR-212 : correction des codes de la nomenclature. Une intervention ou un favori existant conserve
 * son code copié ; seule une NOUVELLE saisie utilise le code corrigé.
 */
class CorrectNomenclatureReferencesTest extends ApiTestBase
{
    private User $alice;
    private Years $year;
    private Nomenclature $spine;
    private Nomenclature $next;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);
        // Colonnes décalées, comme en production : 225094 est l'ambulant de l'acte suivant.
        $this->spine = $this->code('225094', '225083', 'K200', 'Microdiscectomie sur 1 niveau');
        $this->next = $this->code('225116', '225105', 'K250', 'Microdiscectomie sur 2 niveaux');
        $this->em->flush();
        $this->dir = sys_get_temp_dir() . '/medclick-correct-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testDryRunThenApplyThenSecondRunThenRevert(): void
    {
        $before = $this->nomenclatureRows();

        $this->assertMatchesRegularExpression('/à corriger\s+2/', $this->run_()->getDisplay());
        $this->assertSame($before, $this->nomenclatureRows(), 'simulation : aucune écriture');

        $this->assertMatchesRegularExpression('/corrigée\s+2/', $this->run_(apply: true)->getDisplay());
        $rows = $this->nomenclatureRows();
        $this->assertSame(['225083', '225072'], [$rows[$this->spine->getId()]['code_hospitalisation'], $rows[$this->spine->getId()]['code_ambulant']]);
        $this->assertSame(['225105', '225094'], [$rows[$this->next->getId()]['code_hospitalisation'], $rows[$this->next->getId()]['code_ambulant']]);
        foreach ($rows as $id => $row) {
            // Seules les deux colonnes de codes changent.
            $expected = $before[$id];
            $expected['code_hospitalisation'] = $row['code_hospitalisation'];
            $expected['code_ambulant'] = $row['code_ambulant'];
            $this->assertSame($expected, $row);
        }
        $this->assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nomenclature'));

        $display = $this->run_(apply: true)->getDisplay();
        $this->assertMatchesRegularExpression('/corrigée\s+0/', $display);
        $this->assertMatchesRegularExpression('/déjà dans cet état \(ignorée\)\s+2/', $display);
        $this->assertSame($rows, $this->nomenclatureRows());

        $this->assertMatchesRegularExpression('/à rétablir\s+2/', $this->run_(revert: true)->getDisplay());
        $this->run_(apply: true, revert: true);
        $this->assertSame($before, $this->nomenclatureRows());
    }

    public function testAnInconsistentPlanIsRefusedWithoutAnyWrite(): void
    {
        $before = $this->nomenclatureRows();
        $cases = [
            'état inattendu' => fn (array $p) => array_replace_recursive($p, ['correct' => [0 => ['before' => ['codeHospitalisation' => '111111'], 'after' => ['codeHospitalisation' => '222222']]]]),
            'autre libellé' => fn (array $p) => array_replace_recursive($p, ['correct' => [0 => ['name' => 'Autre']]]),
            'autre valeur' => fn (array $p) => array_replace_recursive($p, ['correct' => [0 => ['n' => 'K999']]]),
            'autre spécialité' => fn (array $p) => array_replace_recursive($p, ['correct' => [0 => ['speciality' => 'neuro']]]),
            'code invalide' => fn (array $p) => array_replace_recursive($p, ['correct' => [0 => ['after' => ['codeAmbulant' => 'abc']]]]),
            'même code deux fois' => fn (array $p) => array_replace_recursive($p, ['correct' => [0 => ['after' => ['codeAmbulant' => '225083']]]]),
            'code déjà porté ailleurs' => fn (array $p) => array_replace_recursive($p, ['correct' => [0 => ['after' => ['codeAmbulant' => '225105']]]]),
        ];
        foreach ($cases as $label => $change) {
            $this->assertSame(1, $this->runPlan($change($this->plan()), apply: true)->getStatusCode(), $label);
            $this->assertSame($before, $this->nomenclatureRows(), $label);
        }
    }

    public function testAKnownDuplicateIsAcceptedOnlyWithItsExactLines(): void
    {
        $other = $this->code('300000', '225072', 'K200', 'Doublon du même acte');
        $this->em->flush();

        $plan = $this->plan();
        $this->assertSame(1, $this->runPlan($plan, apply: true)->getStatusCode(), 'code 225072 sur deux lignes, non prévu');
        $plan['knownDuplicates'] = ['225072' => [$this->spine->getId(), $other->getId()]];
        $this->assertSame(0, $this->runPlan($plan, apply: true)->getStatusCode());
    }

    /** La règle absolue : interventions, favoris, compteurs et exports inchangés après correction. */
    public function testHistoricalDataAndExportsAreUnchangedAndNewEntriesUseTheCorrectedCode(): void
    {
        $historical = $this->surgery($this->spine, 'ortho', '225094K200', '2024-03-01');
        $neuro = $this->surgery($this->next, 'neuro', '225116K250', '2024-03-02');
        $favorite = (new Favorites())->setUser($this->em->getReference(User::class, $this->alice->getId()))->setShortcut('Disque')
            ->setSurgeryName($this->spine->getName())->setCodeHospitalisation('225094K200')->setSpeciality('ortho')
            ->setSurgery($this->em->getReference(Nomenclature::class, $this->spine->getId()));
        $this->em->persist($favorite);
        $this->em->flush();
        $favoriteId = $favorite->getId();

        $surgeries = $this->surgeryRows();
        $favorites = $this->favoriteRows();
        $exportBefore = $this->exportedSheets();
        $list = $this->clientFor($this->alice)->request('GET', '/api/surgeries')->toArray()['hydra:member'];

        $this->run_(apply: true);
        $this->em->clear();

        // Base, API et export : strictement identiques.
        $this->assertSame($surgeries, $this->surgeryRows());
        $this->assertSame($favorites, $this->favoriteRows());
        $this->assertSame($exportBefore, $this->exportedSheets());
        $client = $this->clientFor($this->alice);
        $this->assertSame($list, $client->request('GET', '/api/surgeries')->toArray()['hydra:member']);
        $this->assertSame('225094K200', $client->request('GET', '/api/favorites/getMyList')->toArray()[0]['codeHospitalisation'], 'code copié du favori conservé');

        // Modifier une intervention historique, même en renvoyant l'acte : code et libellé enregistrés conservés.
        $client->request('PUT', '/api/surgeries/' . $historical->getId(), ['json' => ['date' => '2024-03-05', 'surgeryId' => (string) $this->spine->getId(), 'speciality' => 'ortho']]);
        $this->assertResponseIsSuccessful();
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/update/' . $neuro->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2024-03-06', 'position' => 1, 'surgeryId' => $this->next->getId(),
        ]]);
        $this->assertResponseIsSuccessful();
        $rows = $this->surgeryRows();
        $this->assertSame(['225094K200', 'ortho', $this->spine->getId()], [$rows[$historical->getId()]['code'], $rows[$historical->getId()]['speciality'], (int) $rows[$historical->getId()]['nomenclature_id']]);
        $this->assertSame(['225116K250', 'neuro', $this->next->getId()], [$rows[$neuro->getId()]['code'], $rows[$neuro->getId()]['speciality'], (int) $rows[$neuro->getId()]['nomenclature_id']]);

        // Renommer le favori : code copié conservé.
        $this->clientFor($this->alice)->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $favoriteId, 'surgeryId' => $this->spine->getId(), 'shortcut' => 'Disque L5', 'speciality' => 'ortho']]);
        $this->assertResponseIsSuccessful();
        $row = $this->favoriteRows()[$favoriteId];
        $this->assertSame(['Disque L5', '225094K200'], [$row['shortcut'], $row['code_hospitalisation']]);

        // Nouvelle saisie, y compris depuis ce favori (surgeryId) : code officiel corrigé.
        $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'date' => '2026-10-15', 'year' => $this->year->getId(), 'surgeryId' => $this->spine->getId(), 'speciality' => 'ortho', 'position' => 1,
        ]]);
        $this->assertResponseIsSuccessful();
        $new = array_values(array_filter($this->surgeryRows(), fn ($r) => str_starts_with($r['date'], '2026-10-15')));
        $this->assertCount(1, $new);
        $this->assertSame(['225083K200', $this->spine->getId()], [$new[0]['code'], (int) $new[0]['nomenclature_id']]);

        // Nouveau favori : code corrigé ; liste de choix : code corrigé.
        $this->clientFor($this->alice)->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'Nouveau', 'surgeryId' => $this->spine->getId(), 'speciality' => 'ortho']]);
        $this->assertResponseIsSuccessful();
        $this->assertContains('225083K200', array_column($this->favoriteRows(), 'code_hospitalisation'));
        $items = array_column($this->clientFor($this->alice)->request('GET', '/api/nomenclature/ortho')->toArray(), null, 'id');
        $this->assertSame('225083', $items[$this->spine->getId()]['codeHospitalisation']);
    }

    /** Validité historique (ADR-210) inchangée par une correction des codes. */
    public function testRepealRulesAreUnchanged(): void
    {
        $this->run_(apply: true);
        $this->em->clear();
        $spine = $this->em->find(Nomenclature::class, $this->spine->getId());
        $at = new \DateTimeImmutable('2026-10-02 09:00:00');
        $spine->setInamiRepealedAt(new \DateTimeImmutable('2026-10-01'))->setInamiRepealSource('Moniteur belge du 15/09/2026')->verifyRepeal($at)->deactivate($at);
        $this->em->flush();

        $post = fn (string $date) => $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'date' => $date, 'year' => $this->year->getId(), 'surgeryId' => $this->spine->getId(), 'position' => 1,
        ]]);
        $this->assertSame(200, $post('2026-09-30')->getStatusCode());
        $response = $post('2026-10-01');
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('225083K200', $response->toArray(false)['message']);
    }

    /** La commande manuelle ne relie plus que les favoris sans nomenclature. */
    public function testUpdateFavoritesNeverRelinksALinkedFavorite(): void
    {
        $same = $this->code('300000', '300001', 'K200', 'Microdiscectomie sur 1 niveau, doublon');
        $this->em->flush();
        $alice = $this->em->getReference(User::class, $this->alice->getId());
        $linked = (new Favorites())->setUser($alice)->setShortcut('Lié')->setSurgeryName($this->next->getName())->setCodeHospitalisation('225116K250')->setSpeciality('ortho')
            ->setSurgery($this->em->getReference(Nomenclature::class, $same->getId()));
        $unlinked = (new Favorites())->setUser($alice)->setShortcut('Sans lien')->setSurgeryName($this->next->getName())->setCodeHospitalisation('225116K250')->setSpeciality('ortho');
        $this->em->persist($linked);
        $this->em->persist($unlinked);
        $this->em->flush();
        $ids = [$linked->getId(), $unlinked->getId()];

        (new CommandTester((new Application(static::$kernel))->find('app:update-favorites')))->execute([]);

        $rows = $this->favoriteRows();
        $this->assertSame($same->getId(), (int) $rows[$ids[0]]['surgery_id'], 'favori déjà lié : inchangé');
        $this->assertSame($this->next->getId(), (int) $rows[$ids[1]]['surgery_id'], 'favori sans lien : relié');
    }

    public function testTheShippedPlansAreConsistent(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/data/inami/';
        $correct = json_decode(file_get_contents($dir . 'plan-correction-references-14n-20261101.json'), true);
        $share = json_decode(file_get_contents($dir . 'plan-partage-rachis-14n-20261101-2-corriges.json'), true);
        $initial = json_decode(file_get_contents($dir . 'plan-partage-rachis-14n-20261101.json'), true);

        $this->assertCount(72, $correct['correct']);
        $ids = array_column($correct['correct'], 'id');
        $this->assertSame([6950, 6957], array_column($correct['unchanged'], 'id'));
        $withdrawn = array_column($initial['withdraw'], 'id');
        $merged = array_merge($ids, [6950, 6957]);
        sort($merged);
        $this->assertSame($withdrawn, $merged, 'exactement les lignes non exactes de la 3.7.0');
        $this->assertContains(6871, $ids);
        $this->assertContains(6872, $ids);
        $this->assertSame([225632, 226494, 226542], array_keys($correct['knownDuplicates']));

        $usage = [];
        foreach ($correct['correct'] as $item) {
            $this->assertNotSame($item['before'], $item['after']);
            $this->assertNotSame(['codeHospitalisation' => $item['before']['codeAmbulant'], 'codeAmbulant' => $item['before']['codeHospitalisation']], $item['after'], 'pas une simple inversion');
            foreach ($item['after'] as $code) {
                $this->assertArrayNotHasKey($code, $usage, 'cible unique');
                $usage[$code] = true;
            }
        }

        $this->assertSame($ids, array_column($share['share'], 'id'));
        $this->assertSame([6950, 6957], array_column($share['withdraw'], 'id'));
        $this->assertArrayNotHasKey('classify', $share, 'classement historique 3.7.0 non modifié');
        foreach ($share['share'] as $i => $item) {
            $this->assertSame($correct['correct'][$i]['after']['codeHospitalisation'], $item['codeHospitalisation']);
            $this->assertSame($correct['correct'][$i]['after']['codeAmbulant'], $item['codeAmbulant']);
        }
        $this->assertSame([], array_intersect($ids, array_column($initial['share'], 'id')), 'aucune ligne déjà partagée');
    }

    // ------------------------------------------------- Helpers

    private function code(string $hosp, string $amb, string $n, string $name): Nomenclature
    {
        $nomenclature = (new Nomenclature())->setSpeciality('ortho')->setCodeHospitalisation($hosp)->setCodeAmbulant($amb)
            ->setN($n)->setName($name)->setType('1')->setSubType('back');
        $this->em->persist($nomenclature);

        return $nomenclature;
    }

    private function plan(): array
    {
        return [
            'source' => ['label' => 'test'],
            'correct' => [
                ['id' => $this->spine->getId(), 'speciality' => 'ortho', 'n' => 'K200', 'name' => $this->spine->getName(),
                    'before' => ['codeHospitalisation' => '225094', 'codeAmbulant' => '225083'], 'after' => ['codeHospitalisation' => '225083', 'codeAmbulant' => '225072']],
                ['id' => $this->next->getId(), 'speciality' => 'ortho', 'n' => 'K250', 'name' => $this->next->getName(),
                    'before' => ['codeHospitalisation' => '225116', 'codeAmbulant' => '225105'], 'after' => ['codeHospitalisation' => '225105', 'codeAmbulant' => '225094']],
            ],
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
        $tester = new CommandTester((new Application(static::$kernel))->find('app:nomenclature:correct-references'));
        $tester->execute(['plan' => $path] + ($apply ? ['--apply' => true] : []) + ($revert ? ['--revert' => true] : []));
        $this->em->clear();

        return $tester;
    }

    private function surgery(Nomenclature $nomenclature, string $speciality, string $code, string $date): Surgeries
    {
        $surgery = (new Surgeries())
            ->setYear($this->em->getReference(Years::class, $this->year->getId()))
            ->setDate(new \DateTime($date))
            ->setNomenclature($this->em->getReference(Nomenclature::class, $nomenclature->getId()))
            ->setCode($code)
            ->setName($nomenclature->getName())
            ->setSpeciality($speciality)
            ->setPosition('1')
            ->setFirstHand((string) $this->alice->getId())
            ->setCreatedAt(new \DateTime('2024-03-10 10:00'));
        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    private function nomenclatureRows(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM nomenclature ORDER BY id'), null, 'id');
    }

    private function surgeryRows(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM surgeries ORDER BY id'), null, 'id');
    }

    private function favoriteRows(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM favorites ORDER BY id'), null, 'id');
    }

    /** Contenu de toutes les feuilles de l'export (la date de génération exceptée, feuille de garde). */
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
        $this->assertStringContainsString('225094K200', json_encode($sheets));

        return $sheets;
    }
}
