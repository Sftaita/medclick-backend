<?php

namespace App\Tests\Api;

use App\Entity\Favorites;
use App\Entity\Nomenclature;
use App\Entity\Statistics;
use App\Entity\Surgeries;
use App\Entity\Surgeons;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use Monolog\Handler\TestHandler;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Étape 4 (ADR-210) : codes INAMI actifs et inactifs.
 *
 * - seuls les codes actifs sont proposés et acceptés pour une NOUVELLE intervention, un nouveau
 *   favori ou un changement d'acte, sur toutes les routes (nouvelles et anciennes) ;
 * - une intervention historique liée à un code inactif reste visible et modifiable tant que son
 *   acte n'est pas changé : code, intitulé, spécialité et nomenclature_id restent identiques ;
 * - favoris : statut lu sur la nomenclature liée, jamais sur le code copié ; ils restent enregistrés ;
 * - l'administration active / désactive sans jamais supprimer ni toucher aux relations ;
 * - exports Excel, statistiques et classement type / subType inchangés.
 */
class NomenclatureActiveStatusTest extends ApiTestBase
{
    private const OLD_CODE = '300344N250';
    private const OLD_NAME = 'Méniscectomie (libellé 2019)';

    private User $alice;
    private User $admin;
    private Years $year;
    private Surgeons $boss;
    private Nomenclature $obsolete;  // code désactivé (abrogé)
    private Nomenclature $current;   // code actif
    private Nomenclature $otherObsolete; // autre code désactivé

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('alice@test.be');
        $this->admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $this->year = $this->createYear($this->alice);
        $this->boss = (new Surgeons())->setFirstName('Dr')->setLastName('Dupont')->setBoss(true)->setYear($this->year);
        $this->em->persist($this->boss);

        $this->obsolete = $this->nomenclature('Méniscectomie interne ou externe du genou', '300344', 'N250', '1', 'knee');
        $this->current = $this->nomenclature('Prothèse totale de genou', '289015', 'N5', '1', 'knee');
        $this->otherObsolete = $this->nomenclature('Arthrotomie du genou (abrogé)', '289100', 'N1', '2', 'knee');
        $this->em->flush();

        $this->obsolete->deactivate(new \DateTimeImmutable('2026-10-01 09:00:00'))->setInamiRepealedAt(new \DateTimeImmutable('2026-09-01'));
        $this->otherObsolete->deactivate(new \DateTimeImmutable('2026-10-01 09:00:00'));
        $this->em->flush();
    }

    // ------------------------------------------------- Migration et modèle

    public function testANewOrPreexistingCodeIsActiveByDefault(): void
    {
        // Ligne insérée sans la colonne (comme les 1 775 codes existants lors de la migration).
        $db = $this->em->getConnection();
        $db->executeStatement("INSERT INTO nomenclature (speciality, name) VALUES ('ortho', 'Code existant')");
        $row = $db->fetchAssociative("SELECT active, inami_repealed_at, deactivated_at FROM nomenclature WHERE name = 'Code existant'");
        $this->assertSame(1, (int) $row['active']);
        $this->assertNull($row['inami_repealed_at']);
        $this->assertNull($row['deactivated_at']);

        $this->assertTrue((new Nomenclature())->isActive());
    }

    public function testNoGlobalDoctrineFilterHidesInactiveCodes(): void
    {
        $this->assertSame([], $this->em->getFilters()->getEnabledFilters());
        $this->em->clear();
        $this->assertCount(3, $this->em->getRepository(Nomenclature::class)->findAll());
        $this->assertFalse($this->em->find(Nomenclature::class, $this->obsolete->getId())->isActive());
    }

    // ------------------------------------------------- Listes proposées à la création

    public function testTheCreationListOnlyProposesActiveCodes(): void
    {
        $list = $this->clientFor($this->alice)->request('GET', '/api/nomenclature/ortho')->toArray();

        $this->assertSame([$this->current->getId()], array_column($list, 'id'));
    }

    // ------------------------------------------------- Création refusée avec un code inactif

    public function testCreationWithAnInactiveCodeIsRefusedOnEveryRoute(): void
    {
        $client = $this->clientFor($this->alice);
        $payload = ['date' => '2026-10-20', 'year' => $this->year->getId(), 'surgeryId' => $this->obsolete->getId(), 'position' => 1];

        foreach (['/api/surgeries/addNewSurgery', '/api/surgeries/add'] as $route) {
            $response = $client->request('POST', $route, ['json' => $payload]);
            $this->assertResponseStatusCodeSame(400, $route);
            $this->assertSame('nomenclature_inactive', $response->toArray(false)['reason'], $route);
            $this->assertSame("Ce code INAMI n'est plus actif. Choisissez un acte actif dans la liste.", $response->toArray(false)['message'], $route);
        }

        // API Platform : surgeryId d'un code inactif → violation sur « name ».
        $response = $client->request('POST', '/api/surgeries', ['json' => [
            'date' => '2026-10-20', 'speciality' => 'ortho', 'name' => 'x', 'position' => '1',
            'year' => '/api/years/' . $this->year->getId(), 'surgeryId' => (string) $this->obsolete->getId(),
        ]]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('name', $response->toArray(false)['violations'][0]['propertyPath']);

        $this->assertSame(0, $this->rowCount('surgeries'));
    }

    public function testCreationWithAnActiveCodeStillWorksOnEveryRoute(): void
    {
        $client = $this->clientFor($this->alice);
        $payload = ['date' => '2026-10-20', 'year' => $this->year->getId(), 'surgeryId' => $this->current->getId(), 'position' => 1];

        $client->request('POST', '/api/surgeries/addNewSurgery', ['json' => $payload]);
        $this->assertResponseIsSuccessful();
        $client->request('POST', '/api/surgeries/add', ['json' => $payload]);
        $this->assertResponseIsSuccessful();
        $client->request('POST', '/api/surgeries', ['json' => [
            'date' => '2026-10-20', 'speciality' => 'ortho', 'name' => 'x', 'position' => '1',
            'year' => '/api/years/' . $this->year->getId(), 'surgeryId' => (string) $this->current->getId(),
        ]]);
        $this->assertResponseStatusCodeSame(201);

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT code, name, nomenclature_id FROM surgeries');
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('289015N5', $row['code']);
            $this->assertSame('Prothèse totale de genou', $row['name']);
            $this->assertSame($this->current->getId(), (int) $row['nomenclature_id']);
        }
    }

    public function testAnInactiveFavoriteCannotCreateAnIntervention(): void
    {
        $favorite = $this->favorite($this->obsolete, 'Ménisque');
        $client = $this->clientFor($this->alice);
        $item = $client->request('GET', '/api/favorites/getMyList')->toArray()[0];
        $this->assertFalse($item['active']);

        // La PWA envoie le surgeryId du favori : refusé.
        $client->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'date' => '2026-10-20', 'year' => $this->year->getId(), 'surgeryId' => $item['surgeryId'], 'position' => 1,
        ]]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, $this->rowCount('surgeries'));
        $this->assertNotNull($this->em->getRepository(Favorites::class)->find($favorite->getId()));
    }

    // ------------------------------------------------- Modification d'une intervention historique

    public function testAHistoricalInterventionWithAnInactiveCodeStaysVisibleAndEditable(): void
    {
        $surgery = $this->historicalSurgery();
        $client = $this->clientFor($this->alice);

        $read = $client->request('GET', '/api/surgeries/' . $surgery->getId())->toArray();
        $this->assertSame(self::OLD_NAME, $read['name']);
        $this->assertContains($surgery->getId(), array_column($client->request('GET', '/api/surgeries')->toArray()['hydra:member'], 'id'));

        // PWA actuelle : sans surgeryId.
        $client->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['date' => '2024-03-15', 'position' => '2', 'firstHand' => (string) $this->boss->getId(), 'surgeryId' => '']]);
        $this->assertResponseIsSuccessful();
        $this->assertHistorical($surgery, '2024-03-15');

        // Même acte inactif renvoyé (PUT, PATCH, ancienne route).
        $client->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['date' => '2024-03-16', 'surgeryId' => (string) $this->obsolete->getId()]]);
        $this->assertResponseIsSuccessful();
        $this->assertHistorical($surgery, '2024-03-16');

        $client->request('PATCH', '/api/surgeries/' . $surgery->getId(), [
            'json' => ['date' => '2024-03-17', 'surgeryId' => $this->obsolete->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertHistorical($surgery, '2024-03-17');

        $client->request('PUT', '/api/surgeries/update/' . $surgery->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2024-03-18', 'position' => 1, 'surgeryId' => $this->obsolete->getId(),
        ]]);
        $this->assertResponseIsSuccessful();
        $this->assertHistorical($surgery, '2024-03-18');

        $client->request('PUT', '/api/surgeries/update/' . $surgery->getId(), ['json' => ['year' => $this->year->getId(), 'date' => '2024-03-19', 'position' => 1]]);
        $this->assertResponseIsSuccessful();
        $this->assertHistorical($surgery, '2024-03-19');
    }

    public function testChangingToAnotherInactiveActIsRefusedAndChangesNothing(): void
    {
        $surgery = $this->historicalSurgery();
        $before = $this->snapshot();
        $client = $this->clientFor($this->alice);

        $response = $client->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['date' => '2024-03-15', 'surgeryId' => (string) $this->otherObsolete->getId()]]);
        $this->assertResponseStatusCodeSame(422);
        $violation = $response->toArray(false)['violations'][0];
        $this->assertSame('name', $violation['propertyPath']);
        $this->assertSame("Ce code INAMI n'est plus actif. Choisissez un acte actif dans la liste.", $violation['message']);

        $client->request('PATCH', '/api/surgeries/' . $surgery->getId(), [
            'json' => ['surgeryId' => $this->otherObsolete->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $response = $client->request('PUT', '/api/surgeries/update/' . $surgery->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2024-03-15', 'position' => 1, 'surgeryId' => $this->otherObsolete->getId(),
        ]]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('nomenclature_inactive', $response->toArray(false)['reason']);

        $this->assertSame($before, $this->snapshot());
    }

    public function testChangingToAnActiveActUpdatesTheIdentityTogether(): void
    {
        $surgery = $this->historicalSurgery();
        $legacy = $this->historicalSurgery();
        $client = $this->clientFor($this->alice);

        $client->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['date' => '2024-03-15', 'surgeryId' => (string) $this->current->getId()]]);
        $this->assertResponseIsSuccessful();
        $client->request('PUT', '/api/surgeries/update/' . $legacy->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2024-03-15', 'position' => 1, 'surgeryId' => $this->current->getId(),
        ]]);
        $this->assertResponseIsSuccessful();

        foreach ([$surgery, $legacy] as $edited) {
            $row = $this->snapshot()[$edited->getId()];
            $this->assertSame('289015N5', $row['code']);
            $this->assertSame('Prothèse totale de genou', $row['name']);
            $this->assertSame($this->current->getId(), (int) $row['nomenclature_id']);
        }
    }

    // ------------------------------------------------- Favoris

    public function testFavoriteStatusComesFromTheLinkedNomenclatureNotFromTheCopiedCode(): void
    {
        // Code copié identique à un code ACTIF, mais nomenclature liée inactive → inactif.
        $misleading = $this->favorite($this->obsolete, 'Trompeur', '289015N5');
        // Code copié ancien (renuméroté), nomenclature liée active → actif.
        $renumbered = $this->favorite($this->current, 'Renuméroté', '289999N9');
        $client = $this->clientFor($this->alice);

        $list = array_column($client->request('GET', '/api/favorites/getMyList')->toArray(), null, 'id');
        $this->assertFalse($list[$misleading->getId()]['active']);
        $this->assertTrue($list[$renumbered->getId()]['active']);
        // Les autres clés du contrat sont inchangées.
        $this->assertSame(['id', 'surgeryId', 'codeHospitalisation', 'name', 'shorcut', 'speciality', 'active'], array_keys($list[$misleading->getId()]));
        $this->assertSame('289015N5', $list[$misleading->getId()]['codeHospitalisation']);

        $this->assertFalse($client->request('GET', '/api/favorites/getById/' . $misleading->getId())->toArray()['active']);
        $this->assertTrue($client->request('GET', '/api/favorites/getById/' . $renumbered->getId())->toArray()['active']);
    }

    public function testFavoritesOfADeactivatedCodeAreKeptAndCanBeRenamedButNotPointedToAnInactiveCode(): void
    {
        $favorite = $this->favorite($this->current, 'PTG');
        $before = $this->favoriteRows();

        $this->changeStatus($this->current, ['active' => false]);
        $this->assertSame($before, $this->favoriteRows(), 'favori conservé tel quel');

        $client = $this->clientFor($this->alice);
        $this->assertFalse($client->request('GET', '/api/favorites/getMyList')->toArray()[0]['active']);

        // Renommer sans changer d'acte : accepté (même acte inactif).
        $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $favorite->getId(), 'surgeryId' => $this->current->getId(), 'shortcut' => 'PTG ancien']]);
        $this->assertResponseIsSuccessful();

        // Rediriger vers un autre code inactif ou créer un favori inactif : refusé.
        $response = $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $favorite->getId(), 'surgeryId' => $this->obsolete->getId(), 'shortcut' => 'x']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('nomenclature_inactive', $response->toArray(false)['reason']);
        $client->request('POST', '/api/favorites/addNew', ['json' => ['surgeryId' => $this->obsolete->getId(), 'shortcut' => 'x']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(1, $this->rowCount('favorites'));

        $row = $this->favoriteRows()[$favorite->getId()];
        $this->assertSame('PTG ancien', $row['shortcut']);
        $this->assertSame($this->current->getId(), (int) $row['surgery_id']);
        $this->assertSame('289015N5', $row['code_hospitalisation']);

        // Rediriger vers un code actif : accepté (le favori redevient utilisable).
        $this->changeStatus($this->current, ['active' => true]);
        $client->request('PUT', '/api/favorites/updateNew', ['json' => ['favoriteId' => $favorite->getId(), 'surgeryId' => $this->current->getId(), 'shortcut' => 'PTG']]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($client->request('GET', '/api/favorites/getMyList')->toArray()[0]['active']);
    }

    // ------------------------------------------------- Administration

    public function testAdminListsActiveAndInactiveCodesWithBothDates(): void
    {
        $client = $this->clientFor($this->admin);

        $list = array_column($client->request('GET', '/api/admin/nomenclature/ortho')->toArray(), null, 'id');
        $this->assertCount(3, $list);
        $this->assertTrue($list[$this->current->getId()]['active']);
        $this->assertNull($list[$this->current->getId()]['deactivatedAt']);
        $this->assertFalse($list[$this->obsolete->getId()]['active']);
        $this->assertSame('2026-09-01', $list[$this->obsolete->getId()]['inamiRepealedAt']);
        $this->assertSame('2026-10-01 09:00:00', $list[$this->obsolete->getId()]['deactivatedAt']);
        $this->assertNull($list[$this->otherObsolete->getId()]['inamiRepealedAt']);

        $this->nomenclature('Appendicectomie', '241415', 'N2', null, null, 'dig');
        $this->em->flush();
        $all = $client->request('GET', '/api/admin/nomenclature')->toArray();
        $this->assertCount(4, $all);
        $this->assertSame(['dig', 'ortho', 'ortho', 'ortho'], array_column($all, 'speciality'));

        $this->historicalSurgery();
        $this->favorite($this->obsolete, 'Ménisque');
        $this->assertSame(['surgeries' => 1, 'favorites' => 1], $client->request('GET', '/api/admin/nomenclature/' . $this->obsolete->getId() . '/usage')->toArray());
    }

    public function testDeactivationNeverDeletesNorTouchesHistoricalRelations(): void
    {
        $historical = $this->historicalSurgery();
        $created = $this->createThroughTheApi($this->current);
        $favorite = $this->favorite($this->current, 'PTG');
        $nomenclatureBefore = $this->nomenclatureRow($this->current);
        $surgeriesBefore = $this->snapshot();
        $favoritesBefore = $this->favoriteRows();

        $start = new \DateTimeImmutable('-1 second');
        $item = $this->changeStatus($this->current, ['active' => false, 'inamiRepealedAt' => '2026-12-31']);
        $this->assertFalse($item['active']);
        $this->assertSame('2026-12-31', $item['inamiRepealedAt']);
        $deactivatedAt = new \DateTimeImmutable($item['deactivatedAt']);
        $this->assertGreaterThanOrEqual($start, $deactivatedAt);
        $this->assertLessThanOrEqual(new \DateTimeImmutable('+1 second'), $deactivatedAt);

        $this->assertSame($surgeriesBefore, $this->snapshot(), 'interventions inchangées');
        $this->assertSame($favoritesBefore, $this->favoriteRows(), 'favoris inchangés');
        $this->assertSame(3, $this->rowCount('nomenclature'), 'aucune nomenclature supprimée');
        $after = $this->nomenclatureRow($this->current);
        foreach (['id', 'speciality', 'code_ambulant', 'code_hospitalisation', 'name', 'type', 'sub_type', 'n'] as $column) {
            $this->assertSame($nomenclatureBefore[$column], $after[$column], $column);
        }
        $this->assertNotNull($this->em->getRepository(Surgeries::class)->find($historical->getId()));
        $this->assertNotNull($this->em->getRepository(Surgeries::class)->find($created));
        $this->assertNotNull($this->em->getRepository(Favorites::class)->find($favorite->getId()));
    }

    public function testStatusChangeIsTracedOnTheDedicatedNomenclatureChannel(): void
    {
        $client = $this->clientFor($this->admin);
        $client->disableReboot();
        $client->request('GET', '/api/admin/nomenclature/' . $this->current->getId() . '/usage');
        $handler = new TestHandler();
        $client->getContainer()->get('monolog.logger.nomenclature')->pushHandler($handler);

        $client->request('PUT', '/api/admin/nomenclature/' . $this->current->getId() . '/status', ['json' => ['active' => false, 'inamiRepealedAt' => '2026-12-31']]);
        $this->assertResponseIsSuccessful();

        $this->assertTrue($handler->hasNoticeThatContains('Nomenclature INAMI : statut modifié'));
        $record = $handler->getRecords()[0];
        $this->assertSame('nomenclature', $record->channel);
        $this->assertSame([
            'nomenclature_id' => $this->current->getId(),
            'active' => false,
            'inami_repealed_at' => '2026-12-31',
            'admin_id' => $this->admin->getId(),
        ], $record->context);
    }

    public function testRepeatedDeactivationKeepsTheFirstTechnicalDateAndReactivationClearsIt(): void
    {
        $item = $this->changeStatus($this->obsolete, ['active' => false]);
        $this->assertSame('2026-10-01 09:00:00', $item['deactivatedAt']);
        $this->assertSame('2026-09-01', $item['inamiRepealedAt'], 'date officielle inchangée sans la clé');

        $item = $this->changeStatus($this->obsolete, ['active' => true]);
        $this->assertTrue($item['active']);
        $this->assertNull($item['deactivatedAt']);
        $this->assertSame('2026-09-01', $item['inamiRepealedAt']);

        $item = $this->changeStatus($this->obsolete, ['active' => true, 'inamiRepealedAt' => null]);
        $this->assertNull($item['inamiRepealedAt']);

        $list = $this->clientFor($this->alice)->request('GET', '/api/nomenclature/ortho')->toArray();
        $this->assertContains($this->obsolete->getId(), array_column($list, 'id'), 'réactivé : de nouveau proposé');
    }

    public function testStatusChangeIsValidatedAndRestrictedToAdmins(): void
    {
        $id = $this->current->getId();
        $admin = $this->clientFor($this->admin);

        foreach ([[], ['active' => 'false'], ['active' => 0]] as $payload) {
            $admin->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => $payload]);
            $this->assertResponseStatusCodeSame(400);
        }
        foreach (['2026-02-30', '01/02/2026', 20260101] as $date) {
            $admin->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealedAt' => $date]]);
            $this->assertResponseStatusCodeSame(400);
        }
        $admin->request('PUT', '/api/admin/nomenclature/999999/status', ['json' => ['active' => false]]);
        $this->assertResponseStatusCodeSame(404);

        $alice = $this->clientFor($this->alice);
        $alice->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false]]);
        $this->assertResponseStatusCodeSame(403);
        $alice->request('GET', "/api/admin/nomenclature/$id/usage");
        $this->assertResponseStatusCodeSame(403);
        $alice->request('GET', '/api/admin/nomenclature');
        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->assertTrue($this->em->find(Nomenclature::class, $id)->isActive(), 'aucune requête refusée n\'a modifié le code');
    }

    // ------------------------------------------------- Sécurité des carnets

    public function testExportStatisticsAndClassificationAreIdenticalAfterADeactivation(): void
    {
        $this->historicalSurgery();
        $this->createThroughTheApi($this->current);
        $this->createThroughTheApi($this->current, 2);

        $exportBefore = $this->exportCells();
        $statsBefore = $this->statistics();

        $this->changeStatus($this->current, ['active' => false, 'inamiRepealedAt' => '2026-12-31']);

        $this->assertSame($exportBefore, $this->exportCells(), 'carnet et récapitulatif identiques');
        $this->assertSame($statsBefore, $this->statistics(), 'statistiques identiques');
        $this->assertContains(self::OLD_CODE, $exportBefore[1]);
        $this->assertSame(2, count(array_keys($exportBefore[1], '289015N5', true)), 'interventions au code désactivé présentes');

        $classification = $this->em->getConnection()->fetchAllAssociative('SELECT id, type, sub_type FROM nomenclature ORDER BY id');
        $this->assertSame([
            ['id' => $this->obsolete->getId(), 'type' => '1', 'sub_type' => 'knee'],
            ['id' => $this->current->getId(), 'type' => '1', 'sub_type' => 'knee'],
            ['id' => $this->otherObsolete->getId(), 'type' => '2', 'sub_type' => 'knee'],
        ], array_map(fn ($row) => ['id' => (int) $row['id'], 'type' => $row['type'], 'sub_type' => $row['sub_type']], $classification));
    }

    // ------------------------------------------------- Helpers

    private function nomenclature(string $name, string $code, string $n, ?string $type, ?string $subType, string $speciality = 'ortho'): Nomenclature
    {
        $nomenclature = (new Nomenclature())->setSpeciality($speciality)->setName($name)->setCodeHospitalisation($code)->setN($n)->setType($type)->setSubType($subType);
        $this->em->persist($nomenclature);

        return $nomenclature;
    }

    /** Intervention saisie avant l'abrogation : ancien code, liée au code aujourd'hui inactif. */
    private function historicalSurgery(): Surgeries
    {
        $surgery = (new Surgeries())
            ->setYear($this->em->getReference(Years::class, $this->year->getId()))
            ->setDate(new \DateTime('2019-11-05'))
            ->setNomenclature($this->em->getReference(Nomenclature::class, $this->obsolete->getId()))
            ->setCode(self::OLD_CODE)
            ->setName(self::OLD_NAME)
            ->setSpeciality('ortho')
            ->setPosition('1')
            ->setFirstHand((string) $this->alice->getId())
            ->setCreatedAt(new \DateTime('2019-11-05 10:00'));
        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    private function createThroughTheApi(Nomenclature $nomenclature, int $position = 1): int
    {
        $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'date' => '2026-10-20', 'year' => $this->year->getId(), 'surgeryId' => $nomenclature->getId(),
            'position' => $position, 'firstHand' => $this->boss->getId(),
        ]]);
        $this->assertResponseIsSuccessful();

        return (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM surgeries');
    }

    private function favorite(Nomenclature $nomenclature, string $shortcut, ?string $copiedCode = null): Favorites
    {
        $favorite = (new Favorites())
            ->setUser($this->em->getReference(User::class, $this->alice->getId()))
            ->setShortcut($shortcut)
            ->setSurgeryName($nomenclature->getName())
            ->setCodeHospitalisation($copiedCode ?? $nomenclature->getCodeHospitalisation() . $nomenclature->getN())
            ->setSpeciality($nomenclature->getSpeciality())
            ->setSurgery($this->em->getReference(Nomenclature::class, $nomenclature->getId()));
        $this->em->persist($favorite);
        $this->em->flush();

        return $favorite;
    }

    private function changeStatus(Nomenclature $nomenclature, array $payload): array
    {
        $response = $this->clientFor($this->admin)->request('PUT', '/api/admin/nomenclature/' . $nomenclature->getId() . '/status', ['json' => $payload]);
        $this->assertResponseIsSuccessful();

        return $response->toArray();
    }

    private function assertHistorical(Surgeries $surgery, string $date): void
    {
        $row = $this->snapshot()[$surgery->getId()];
        $this->assertSame(self::OLD_CODE, $row['code']);
        $this->assertSame(self::OLD_NAME, $row['name']);
        $this->assertSame('ortho', $row['speciality']);
        $this->assertSame($this->obsolete->getId(), (int) $row['nomenclature_id']);
        $this->assertSame($date, substr($row['date'], 0, 10));
    }

    private function snapshot(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM surgeries ORDER BY id'), null, 'id');
    }

    private function favoriteRows(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM favorites ORDER BY id'), null, 'id');
    }

    private function nomenclatureRow(Nomenclature $nomenclature): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM nomenclature WHERE id = ?', [$nomenclature->getId()]);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM $table");
    }

    /** Cellules (texte, sans les espaces de tête) des feuilles « Carnet de stage » et « Récapitulatif ». */
    private function exportCells(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $this->assertResponseIsSuccessful();
        $book = IOFactory::load($file);
        unlink($file);

        $cells = [];
        foreach ([1, 2] as $index) {
            // La date d'édition du carnet peut changer d'une seconde à l'autre : seules les valeurs
            // de codes, intitulés et compteurs sont comparées (les dates du jour sont retirées).
            $values = array_map(fn ($value) => trim((string) $value), array_merge(...$book->getSheet($index)->toArray()));
            $cells[$index] = array_values(array_filter($values, fn ($value) => !str_contains($value, date('d/m/Y')) && !str_contains($value, date('Y-m-d'))));
        }

        return $cells;
    }

    private function statistics(): array
    {
        $this->clientFor($this->admin)->request('POST', '/api/statistics/update/' . $this->alice->getId());
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $statistics = $this->em->getRepository(Statistics::class)->findOneBy(['user' => $this->alice->getId()]);

        return [$statistics->getFirstHandSurgeries(), $statistics->getSecondHandSurgeries()];
    }
}
