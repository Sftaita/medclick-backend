<?php

namespace App\Tests\Api;

use App\Entity\Favorites;
use App\Entity\Nomenclature;
use App\Entity\NomenclatureHistoricalSpeciality;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ADR-211 : actes partagés entre spécialités (article 14 n), orthopédie et neurochirurgie).
 *
 * - un code = une seule ligne de nomenclature, proposée dans chaque spécialité autorisée ;
 * - l'intervention prend la spécialité CHOISIE (vérifiée par le serveur), avec le code, la
 *   nomenclature et l'intitulé de l'unique ligne ;
 * - les interventions historiques ne changent jamais de spécialité ;
 * - favoris, validité historique (abrogation), listes, exports : spécialité enregistrée.
 */
class SharedSpecialityTest extends ApiTestBase
{
    private const ROUTES = ['addNewSurgery', 'add', 'apiPlatform'];

    private User $alice;
    private Years $year;
    private Nomenclature $spine;       // ortho, partagé avec neuro (14 n)
    private Nomenclature $spineOther;  // ortho, partagé avec neuro (14 n)
    private Nomenclature $knee;        // ortho seul
    private Nomenclature $brain;       // neuro
    private Nomenclature $colon;       // dig
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);

        $this->spine = $this->code('ortho', '225094', '225083', 'K200', 'Microdiscectomie lombaire', '1', 'back');
        $this->spineOther = $this->code('ortho', '226542', '226531', 'K225', 'Arthrodèse lombaire', '1', 'back');
        $this->knee = $this->code('ortho', '289015', '289004', 'N5', 'Prothèse totale de genou', '1', 'knee');
        $this->brain = $this->code('neuro', '477016', '477005', 'K500', 'Exérèse d\'une tumeur cérébrale');
        $this->colon = $this->code('dig', '244031', '244020', 'N700', 'Colectomie');
        $this->em->flush();

        $this->dir = sys_get_temp_dir() . '/medclick-share-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $tester = $this->share([$this->spine, $this->spineOther], apply: true);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ------------------------------------------------- Listes de sélection

    public function testASpineActIsListedOnceInOrthopaedicsAndInNeurosurgery(): void
    {
        $client = $this->clientFor($this->alice);
        foreach (['', '?date=2026-10-15'] as $query) {
            $ortho = array_column($client->request('GET', '/api/nomenclature/ortho' . $query)->toArray(), 'id');
            $neuro = array_column($client->request('GET', '/api/nomenclature/neuro' . $query)->toArray(), 'id');
            $dig = array_column($client->request('GET', '/api/nomenclature/dig' . $query)->toArray(), 'id');

            sort($ortho);
            sort($neuro);
            $this->assertSame($this->ids($this->spine, $this->spineOther, $this->knee), $ortho, "ortho $query");
            $this->assertSame($this->ids($this->spine, $this->spineOther, $this->brain), $neuro, "neuro $query : actes neuro + rachis, chacun une fois");
            $this->assertSame([$this->colon->getId()], $dig, "autres spécialités inchangées $query");
        }
        $this->assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nomenclature'), 'aucune ligne dupliquée');
    }

    // ------------------------------------------------- Création

    public function testCreatingFromNeurosurgeryRecordsNeuroWithTheSameNomenclatureLine(): void
    {
        foreach (self::ROUTES as $route) {
            $this->assertSame(201, $this->create($route, $this->spine, 'neuro')[0], $route);
            $this->assertSame(201, $this->create($route, $this->spine, 'ortho')[0], $route);
        }

        $rows = $this->rows();
        $this->assertCount(6, $rows);
        $bySpeciality = array_count_values(array_column($rows, 'speciality'));
        $this->assertSame(['neuro' => 3, 'ortho' => 3], $bySpeciality);
        foreach ($rows as $row) {
            $this->assertSame($this->spine->getId(), (int) $row['nomenclature_id'], 'même ligne de nomenclature');
            $this->assertSame('225094K200', $row['code'], 'code INAMI original');
            $this->assertSame('Microdiscectomie lombaire', $row['name']);
        }
    }

    public function testWithoutAChosenSpecialityTheMainSpecialityIsKept(): void
    {
        // Anciens fronts : aucune spécialité, ou « favorites » (ancienne valeur du sélecteur).
        // (POST /api/surgeries exige déjà une spécialité : seule la valeur « favorites » y est testée.)
        foreach (self::ROUTES as $route) {
            if ($route !== 'apiPlatform') {
                $this->assertSame(201, $this->create($route, $this->spine, null)[0], $route);
                $this->assertSame(201, $this->create($route, $this->brain, null)[0], $route);
            }
            $this->assertSame(201, $this->create($route, $this->spine, 'favorites')[0], $route);
        }
        $rows = $this->rows();
        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $expected = (int) $row['nomenclature_id'] === $this->brain->getId() ? 'neuro' : 'ortho';
            $this->assertSame($expected, $row['speciality']);
        }
    }

    public function testASpecialityNotAllowedForTheCodeIsRefused(): void
    {
        $cases = [
            [$this->knee, 'neuro'],    // acte d'orthopédie non partagé
            [$this->spine, 'dig'],     // rachis : seulement ortho et neuro
            [$this->brain, 'ortho'],   // acte neurochirurgical non partagé
            [$this->colon, 'inconnue'],
        ];
        foreach ($cases as [$code, $speciality]) {
            foreach (self::ROUTES as $route) {
                [$status, $message, $reason] = $this->create($route, $code, $speciality);
                $this->assertSame($route === 'apiPlatform' ? 422 : 400, $status, "$route {$code->getName()} $speciality");
                $this->assertStringContainsString("ne peut pas être enregistré dans la spécialité « $speciality »", $message);
                if ($route !== 'apiPlatform') {
                    $this->assertSame('speciality_not_allowed', $reason);
                }
            }
        }
        $this->assertCount(0, $this->rows());
    }

    // ------------------------------------------------- Modification

    public function testEditingAHistoricalSpineInterventionNeverChangesItsSpeciality(): void
    {
        $client = $this->clientFor($this->alice);
        $historical = $this->surgery($this->spine, 'ortho', '225094K200', '2024-03-01');

        // PUT / PATCH / ancienne route : même acte renvoyé avec une autre spécialité, ou aucun acte.
        $client->request('PUT', '/api/surgeries/' . $historical->getId(), ['json' => ['date' => '2024-03-02', 'surgeryId' => (string) $this->spine->getId(), 'speciality' => 'neuro']]);
        $this->assertResponseIsSuccessful();
        $client->request('PATCH', '/api/surgeries/' . $historical->getId(), [
            'json' => ['date' => '2024-03-03', 'speciality' => 'neuro'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $client->request('PUT', '/api/surgeries/update/' . $historical->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2024-03-04', 'position' => 1, 'surgeryId' => $this->spine->getId(), 'speciality' => 'neuro',
        ]]);
        $this->assertResponseIsSuccessful();

        $row = $this->rows()[$historical->getId()];
        $this->assertSame(['ortho', '225094K200', $this->spine->getId(), '2024-03-04'], [$row['speciality'], $row['code'], (int) $row['nomenclature_id'], substr($row['date'], 0, 10)]);
    }

    public function testChangingTheActFromNeurosurgeryRecordsNeuro(): void
    {
        $client = $this->clientFor($this->alice);
        $a = $this->surgery($this->brain, 'neuro', '477016K500', '2026-09-01');
        $b = $this->surgery($this->brain, 'neuro', '477016K500', '2026-09-01');
        $c = $this->surgery($this->knee, 'ortho', '289015N5', '2026-09-01');

        $client->request('PUT', '/api/surgeries/' . $a->getId(), ['json' => ['date' => '2026-09-01', 'surgeryId' => (string) $this->spine->getId(), 'speciality' => 'neuro']]);
        $this->assertResponseIsSuccessful();
        $client->request('PUT', '/api/surgeries/update/' . $b->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2026-09-01', 'position' => 1, 'surgeryId' => $this->spineOther->getId(), 'speciality' => 'neuro',
        ]]);
        $this->assertResponseIsSuccessful();
        // Depuis l'orthopédie : ortho.
        $client->request('PUT', '/api/surgeries/' . $c->getId(), ['json' => ['date' => '2026-09-01', 'surgeryId' => (string) $this->spine->getId(), 'speciality' => 'ortho']]);
        $this->assertResponseIsSuccessful();

        $rows = $this->rows();
        $this->assertSame(['neuro', '225094K200', $this->spine->getId()], [$rows[$a->getId()]['speciality'], $rows[$a->getId()]['code'], (int) $rows[$a->getId()]['nomenclature_id']]);
        $this->assertSame(['neuro', '226542K225', $this->spineOther->getId()], [$rows[$b->getId()]['speciality'], $rows[$b->getId()]['code'], (int) $rows[$b->getId()]['nomenclature_id']]);
        $this->assertSame(['ortho', '225094K200'], [$rows[$c->getId()]['speciality'], $rows[$c->getId()]['code']]);

        // Spécialité non autorisée pour le nouvel acte : refus, rien ne change.
        $before = $this->rows();
        $response = $client->request('PUT', '/api/surgeries/' . $c->getId(), ['json' => ['date' => '2026-09-01', 'surgeryId' => (string) $this->knee->getId(), 'speciality' => 'neuro']]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('speciality', $response->toArray(false)['violations'][0]['propertyPath']);
        $response = $client->request('PUT', '/api/surgeries/update/' . $c->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2026-09-01', 'position' => 1, 'surgeryId' => $this->knee->getId(), 'speciality' => 'neuro',
        ]]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('speciality_not_allowed', $response->toArray(false)['reason']);
        $this->assertSame($before, $this->rows());
    }

    // ------------------------------------------------- Favoris

    public function testFavoritesKeepTheChosenSpeciality(): void
    {
        $client = $this->clientFor($this->alice);
        $client->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'Disque (neuro)', 'surgeryId' => $this->spine->getId(), 'speciality' => 'neuro', 'favoriteId' => null]]);
        $this->assertResponseIsSuccessful();
        $client->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'Disque (ortho)', 'surgeryId' => $this->spine->getId(), 'speciality' => 'ortho', 'favoriteId' => null]]);
        $this->assertResponseIsSuccessful();
        $client->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'Ancien front', 'surgeryId' => $this->spine->getId()]]);
        $this->assertResponseIsSuccessful();
        $response = $client->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'Genou', 'surgeryId' => $this->knee->getId(), 'speciality' => 'neuro']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('speciality_not_allowed', $response->toArray(false)['reason']);

        $list = array_column($client->request('GET', '/api/favorites/getMyList')->toArray(), null, 'shorcut');
        $this->assertSame(['Ancien front', 'Disque (neuro)', 'Disque (ortho)'], array_keys($this->sorted($list)));
        $this->assertSame('neuro', $list['Disque (neuro)']['speciality']);
        $this->assertSame('ortho', $list['Disque (ortho)']['speciality']);
        $this->assertSame('ortho', $list['Ancien front']['speciality']);
        foreach ($list as $favorite) {
            $this->assertSame($this->spine->getId(), $favorite['surgeryId'], 'même ligne de nomenclature');
            $this->assertSame('225094K200', $favorite['codeHospitalisation']);
        }

        // Renommage (spécialité enregistrée renvoyée) : inchangée ; même acte repris depuis la
        // neurochirurgie : spécialité neuro ; valeur non autorisée renvoyée : ignorée.
        $orthoId = $list['Disque (ortho)']['id'];
        $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $orthoId, 'surgeryId' => $this->spine->getId(), 'shortcut' => 'Disque', 'speciality' => 'ortho']]);
        $this->assertSame('ortho', $this->favorite($orthoId)['speciality']);
        $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $orthoId, 'surgeryId' => $this->spine->getId(), 'shortcut' => 'Disque', 'speciality' => 'dig']]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('ortho', $this->favorite($orthoId)['speciality']);
        $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $orthoId, 'surgeryId' => $this->spine->getId(), 'shortcut' => 'Disque', 'speciality' => 'neuro']]);
        $this->assertSame('neuro', $this->favorite($orthoId)['speciality']);
        // Autre acte : spécialité vérifiée.
        $response = $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $orthoId, 'surgeryId' => $this->knee->getId(), 'shortcut' => 'Disque', 'speciality' => 'neuro']]);
        $this->assertResponseStatusCodeSame(400);
        $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $orthoId, 'surgeryId' => $this->spineOther->getId(), 'shortcut' => 'Arthrodèse', 'speciality' => 'neuro']]);
        $this->assertResponseIsSuccessful();
        $row = $this->favorite($orthoId);
        $this->assertSame(['neuro', '226542K225', $this->spineOther->getId()], [$row['speciality'], $row['code_hospitalisation'], (int) $row['surgery_id']]);
    }

    /** ADR-211 : écritures brutes fermées ; lecture et suppression conservées, propriétaire vérifié. */
    public function testRawFavoriteWritesAreClosedAndLegitimateAccessIsKept(): void
    {
        $client = $this->clientFor($this->alice);
        $client->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'Disque', 'surgeryId' => $this->spine->getId(), 'speciality' => 'neuro']]);
        $this->assertResponseIsSuccessful();
        $id = $client->request('GET', '/api/favorites/getMyList')->toArray()[0]['id'];
        $before = $this->favorite($id);

        $forged = ['shortcut' => 'x', 'SurgeryName' => 'x', 'codeHospitalisation' => '000000', 'speciality' => 'dig'];
        $client->request('POST', '/api/favorites', ['json' => $forged]);
        $this->assertResponseStatusCodeSame(405);
        $client->request('PUT', '/api/favorites/' . $id, ['json' => $forged]);
        $this->assertResponseStatusCodeSame(405);
        $client->request('PATCH', '/api/favorites/' . $id, ['json' => $forged, 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        $this->assertResponseStatusCodeSame(405);
        $this->assertSame($before, $this->favorite($id));
        $this->assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM favorites'));

        $this->assertSame('neuro', $client->request('GET', '/api/favorites/' . $id)->toArray()['speciality']);
        $this->assertCount(1, $client->request('GET', '/api/favorites')->toArray()['hydra:member']);

        $this->clientFor($this->createUser('bob@test.be'))->request('DELETE', '/api/favorites/' . $id);
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame($before, $this->favorite($id));
        $response = $this->clientFor($this->alice)->request('DELETE', '/api/favorites/' . $id);
        $this->assertSame(204, $response->getStatusCode(), $response->getContent(false));
    }

    // ------------------------------------------------- Validité historique (ADR-210)

    public function testRepealRulesApplyIdenticallyFromNeurosurgery(): void
    {
        $spine = $this->em->find(Nomenclature::class, $this->spine->getId());
        $at = new \DateTimeImmutable('2026-10-02 09:00:00');
        $spine->setInamiRepealedAt(new \DateTimeImmutable('2026-10-01'))->setInamiRepealSource('Moniteur belge du 15/09/2026')->verifyRepeal($at)->deactivate($at);
        $this->em->flush();

        foreach (self::ROUTES as $route) {
            $this->assertSame(201, $this->create($route, $this->spine, 'neuro', '2026-09-30')[0], "$route saisie tardive");
            [$status, $message] = $this->create($route, $this->spine, 'neuro', '2026-10-01');
            $this->assertSame($route === 'apiPlatform' ? 422 : 400, $status, "$route jour de l'abrogation");
            $this->assertStringContainsString('abrogé le 01/10/2026', $message);
        }
        $this->assertSame(['neuro'], array_values(array_unique(array_column($this->rows(), 'speciality'))));

        $client = $this->clientFor($this->alice);
        $before = array_column($client->request('GET', '/api/nomenclature/neuro?date=2026-09-30')->toArray(), null, 'id');
        $this->assertFalse($before[$this->spine->getId()]['active']);
        $this->assertSame('2026-10-01', $before[$this->spine->getId()]['inamiRepealedAt']);
        $on = array_column($client->request('GET', '/api/nomenclature/neuro?date=2026-10-01')->toArray(), 'id');
        $this->assertNotContains($this->spine->getId(), $on);
        $this->assertNotContains($this->spine->getId(), array_column($client->request('GET', '/api/nomenclature/neuro')->toArray(), 'id'));

        $response = $client->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'x', 'surgeryId' => $this->spine->getId(), 'speciality' => 'neuro']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('nomenclature_inactive', $response->toArray(false)['reason']);
    }

    // ------------------------------------------------- Listes et export

    public function testListsAndTheExcelExportUseTheRecordedSpeciality(): void
    {
        $ortho = $this->surgery($this->spine, 'ortho', '225094K200', '2026-09-01');
        $this->assertSame(201, $this->create('addNewSurgery', $this->spine, 'neuro')[0]);
        $neuroId = max(array_keys($this->rows()));

        $client = $this->clientFor($this->alice);
        $items = array_column($client->request('GET', '/api/surgeries')->toArray()['hydra:member'], 'speciality', 'id');
        $this->assertSame('ortho', $items[$ortho->getId()]);
        $this->assertSame('neuro', $items[$neuroId]);

        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $client->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);

        // Récapitulatif (ADR-214) : deux lignes dans la rubrique Rachis, une par spécialité enregistrée.
        $withCode = fn ($book, int $sheet) => array_values(array_filter($book->getSheet($sheet)->toArray(), fn ($row) => in_array('225094K200', array_map(fn ($v) => trim((string) $v), $row), true)));
        $this->assertCount(2, $withCode($book, 1), 'carnet : une ligne par intervention');
        $this->assertCount(2, $withCode($book, 2), 'récapitulatif : acte listé une fois par spécialité enregistrée');

        // Retrait du partage, sans classement historique : l'intervention garde sa spécialité ; l'export
        // revient à l'ancien comportement pour cette ligne (région du code), sans erreur.
        $this->share([$this->spine], apply: true, revert: true);
        file_put_contents($file = tempnam(sys_get_temp_dir(), 'carnet'), $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);
        $this->assertCount(1, $withCode($book, 2));
        $this->assertSame('neuro', $this->rows()[$neuroId]['speciality']);
    }

    /**
     * Code NON autorisé en neurochirurgie mais classé (ADR-211) : l'intervention déjà enregistrée reste au
     * récapitulatif ; aucune nouvelle saisie possible ; aucune donnée modifiée ; orthopédie inchangée.
     */
    public function testHistoricalClassificationIsSeparateFromTheSelectionRight(): void
    {
        $plan = $this->plan([$this->spine]);
        $plan['withdraw'] = $this->plan([$this->spineOther])['share'];
        $plan['classify'] = $this->plan([$this->spine, $this->spineOther])['share'];

        // Retrait de l'autorisation excédentaire, classement ajouté ; seconde exécution sans effet.
        $display = $this->runShare($plan)->getDisplay();
        $this->assertMatchesRegularExpression('/autorisation à retirer\s+1/', $display);
        $this->assertMatchesRegularExpression('/classement à ajouter\s+2/', $display);
        $this->assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nomenclature_shared_speciality'), 'simulation : aucune écriture');
        $this->runShare($plan, apply: true);
        $display = $this->runShare($plan, apply: true)->getDisplay();
        $this->assertMatchesRegularExpression('/autorisation retirée\s+0/', $display);
        $this->assertMatchesRegularExpression('/déjà sans autorisation \(ignoré\)\s+1/', $display);
        $this->assertMatchesRegularExpression('/déjà autorisé \(ignoré\)\s+1/', $display);
        $this->assertMatchesRegularExpression('/déjà classé \(ignoré\)\s+2/', $display);
        $connection = $this->em->getConnection();
        $this->assertSame([$this->spine->getId()], array_map('intval', $connection->fetchFirstColumn('SELECT nomenclature_id FROM nomenclature_shared_speciality')));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM nomenclature_historical_speciality'));

        // Intervention historique en neurochirurgie sur le code retiré.
        $historical = $this->surgery($this->spineOther, 'neuro', '226542K225', '2024-03-01');
        $before = $this->rows();

        // Sélection : absent en neurochirurgie, présent en orthopédie ; saisie et favori refusés en neuro.
        $client = $this->clientFor($this->alice);
        $this->assertNotContains($this->spineOther->getId(), array_column($client->request('GET', '/api/nomenclature/neuro')->toArray(), 'id'));
        $this->assertContains($this->spine->getId(), array_column($client->request('GET', '/api/nomenclature/neuro')->toArray(), 'id'));
        $this->assertContains($this->spineOther->getId(), array_column($client->request('GET', '/api/nomenclature/ortho')->toArray(), 'id'));
        foreach (self::ROUTES as $route) {
            [$status] = $this->create($route, $this->spineOther, 'neuro');
            $this->assertSame($route === 'apiPlatform' ? 422 : 400, $status, $route);
        }
        $client = $this->clientFor($this->alice);
        $response = $client->request('POST', '/api/favorites/addNew', ['json' => ['shortcut' => 'x', 'surgeryId' => $this->spineOther->getId(), 'speciality' => 'neuro']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('speciality_not_allowed', $response->toArray(false)['reason']);
        $this->assertSame($before, $this->rows());

        // Modification de l'intervention historique : spécialité et code conservés.
        $client->request('PUT', '/api/surgeries/' . $historical->getId(), ['json' => ['date' => '2024-03-02', 'speciality' => 'neuro']]);
        $this->assertResponseIsSuccessful();
        $row = $this->rows()[$historical->getId()];
        $this->assertSame(['neuro', '226542K225', $this->spineOther->getId()], [$row['speciality'], $row['code'], (int) $row['nomenclature_id']]);

        // Récapitulatif : l'intervention historique en neuro et une intervention en orthopédie.
        $this->surgery($this->spineOther, 'ortho', '226542K225', '2024-04-01');
        $withCode = fn ($book, int $sheet) => array_values(array_filter($book->getSheet($sheet)->toArray(), fn ($row) => in_array('226542K225', array_map(fn ($v) => trim((string) $v), $row), true)));
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);
        $this->assertCount(2, $withCode($book, 2), 'une ligne par spécialité enregistrée, sans autorisation de saisie');

        // Sans classement ni autorisation (code hors 14 n) : traitement historique inchangé.
        $this->em->clear();
        $classified = $this->em->getRepository(NomenclatureHistoricalSpeciality::class)->findBy(['nomenclature' => $this->spineOther->getId()]);
        $this->assertCount(1, $classified);
        $this->em->remove($classified[0]);
        $this->em->flush();
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);
        $this->assertCount(1, $withCode($book, 2));

        // Retour arrière : autorisations et classements du plan retirés, retrait jamais annulé.
        $this->runShare($plan, apply: true, revert: true);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nomenclature_shared_speciality'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nomenclature_historical_speciality'));
        $this->assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM nomenclature'));

        // Plan incohérent : une autorisation à la fois accordée et retirée.
        $plan['withdraw'] = $plan['share'];
        $this->assertSame(1, $this->runShare($plan, apply: true)->getStatusCode());
    }

    // ------------------------------------------------- Commande de partage

    public function testTheShareCommandIsIdempotentValidatedAndRevertible(): void
    {
        $count = fn () => (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nomenclature_shared_speciality');
        $this->assertSame(2, $count());

        // Seconde exécution : rien à faire.
        $tester = $this->share([$this->spine, $this->spineOther], apply: true);
        $this->assertMatchesRegularExpression('/déjà autorisé \(ignoré\)\s+2/', $tester->getDisplay());
        $this->assertSame(2, $count());

        // Plan incohérent (code ou spécialité principale) : refusé sans écriture.
        $plan = $this->plan([$this->knee]);
        $plan['share'][0]['codeHospitalisation'] = '999999';
        $this->assertSame(1, $this->runShare($plan, apply: true)->getStatusCode());
        $plan = $this->plan([$this->brain]);
        $this->assertSame(1, $this->runShare($plan, apply: true)->getStatusCode(), 'spécialité principale neuro, partage neuro refusé');
        $this->assertSame(2, $count());

        // Simulation du retour arrière, puis retour arrière : les interventions gardent leur spécialité.
        $this->create('addNewSurgery', $this->spine, 'neuro');
        $before = $this->rows();
        $this->assertMatchesRegularExpression('/à retirer\s+2/', $this->share([$this->spine, $this->spineOther], revert: true)->getDisplay());
        $this->assertSame(2, $count());
        $this->share([$this->spine, $this->spineOther], apply: true, revert: true);
        $this->assertSame(0, $count());
        $this->assertSame($before, $this->rows());
        $this->assertSame(5, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nomenclature'));
    }

    public function testTheShippedPlanSharesTheOfficialArticle14nCodesWithNeurosurgery(): void
    {
        $plan = json_decode(file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/data/inami/plan-partage-rachis-14n-20261101.json'), true);
        $this->assertCount(56, $plan['share'], 'autorisations : paires officielles exactes uniquement');
        $this->assertCount(74, $plan['withdraw']);
        $this->assertCount(130, $plan['classify'], 'classement historique : toutes les lignes officielles 14 n)');
        $this->assertSame(260, $plan['source']['officialCodes']);
        $shared = array_column($plan['share'], 'id');
        $withdrawn = array_column($plan['withdraw'], 'id');
        $this->assertSame([], array_intersect($shared, $withdrawn));
        $merged = array_merge($shared, $withdrawn);
        sort($merged);
        $this->assertSame($merged, array_column($plan['classify'], 'id'));
        // 6961 : paire officielle exacte. 6871 et 6957 : références erronées. 6840 : colonnes décalées.
        $this->assertContains(6961, $shared);
        foreach ([6871, 6957, 6840] as $id) {
            $this->assertContains($id, $withdrawn);
        }
        foreach ($plan['withdraw'] as $item) {
            $this->assertNotSame('', $item['reason']);
        }
        $this->assertStringContainsString('14n', $plan['source']['label']);
        foreach ($plan['share'] as $item) {
            $this->assertSame('ortho', $item['speciality']);
            $this->assertSame(['neuro'], $item['specialities']);
        }
        $this->assertCount(56, array_unique($shared));
    }

    // ------------------------------------------------- Helpers

    private function code(string $speciality, string $hosp, string $amb, string $n, string $name, ?string $type = null, ?string $subType = null): Nomenclature
    {
        $nomenclature = (new Nomenclature())->setSpeciality($speciality)->setCodeHospitalisation($hosp)->setCodeAmbulant($amb)
            ->setN($n)->setName($name)->setType($type)->setSubType($subType);
        $this->em->persist($nomenclature);

        return $nomenclature;
    }

    /** @param list<Nomenclature> $codes */
    private function plan(array $codes): array
    {
        return [
            'source' => ['label' => 'test 14 n)'],
            'share' => array_map(fn (Nomenclature $n) => [
                'id' => $n->getId(), 'codeHospitalisation' => $n->getCodeHospitalisation(), 'codeAmbulant' => $n->getCodeAmbulant(),
                'speciality' => $n->getSpeciality(), 'specialities' => ['neuro'],
            ], $codes),
        ];
    }

    /** @param list<Nomenclature> $codes */
    private function share(array $codes, bool $apply = false, bool $revert = false): CommandTester
    {
        return $this->runShare($this->plan($codes), $apply, $revert);
    }

    private function runShare(array $plan, bool $apply = false, bool $revert = false): CommandTester
    {
        $path = $this->dir . '/plan.json';
        file_put_contents($path, json_encode($plan, JSON_UNESCAPED_UNICODE));
        $tester = new CommandTester((new Application(static::$kernel))->find('app:nomenclature:share-speciality'));
        $tester->execute(['plan' => $path] + ($apply ? ['--apply' => true] : []) + ($revert ? ['--revert' => true] : []));
        $this->em->clear();

        return $tester;
    }

    /** @return array{int, string, ?string} statut (201 = créée), message, raison */
    private function create(string $route, Nomenclature $nomenclature, ?string $speciality, string $date = '2026-10-15'): array
    {
        $client = $this->clientFor($this->alice);
        if ($route === 'apiPlatform') {
            $response = $client->request('POST', '/api/surgeries', ['json' => array_filter([
                'date' => $date, 'speciality' => $speciality, 'name' => 'x', 'position' => '1',
                'year' => '/api/years/' . $this->year->getId(), 'surgeryId' => (string) $nomenclature->getId(),
            ], fn ($v) => $v !== null)]);
            $body = $response->toArray(false);

            return [$response->getStatusCode(), $body['violations'][0]['message'] ?? '', null];
        }
        $response = $client->request('POST', '/api/surgeries/' . $route, ['json' => array_filter([
            'date' => $date, 'year' => $this->year->getId(), 'surgeryId' => $nomenclature->getId(), 'position' => 1, 'speciality' => $speciality,
        ], fn ($v) => $v !== null)]);
        $status = $response->getStatusCode();
        $body = $response->toArray(false);

        return [$status === 200 ? 201 : $status, $body['message'] ?? '', $body['reason'] ?? null];
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
            ->setCreatedAt(new \DateTime('2026-09-01 10:00'));
        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    private function rows(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM surgeries ORDER BY id'), null, 'id');
    }

    private function favorite(int $id): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM favorites WHERE id = ?', [$id]);
    }

    private function ids(Nomenclature ...$codes): array
    {
        $ids = array_map(fn (Nomenclature $n) => $n->getId(), $codes);
        sort($ids);

        return $ids;
    }

    private function sorted(array $list): array
    {
        ksort($list);

        return $list;
    }
}
