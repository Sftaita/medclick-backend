<?php

namespace App\Tests\Api;

use App\Entity\Favorites;
use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Entity\Surgeons;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Étape 4 (ADR-210), validité historique : un code INAMI dépend de la DATE RÉELLE de l'intervention.
 *
 * - code actif : utilisable ;
 * - code inactif avec date officielle d'abrogation VÉRIFIÉE : utilisable pour une intervention
 *   strictement antérieure (saisie tardive), refusé le jour de l'abrogation et après ;
 * - code inactif sans date vérifiée (absente ou non vérifiée) : jamais utilisable, quelle que soit la
 *   date (aucune période de validité supposée) ;
 * - acte inchangé : code historique préservé ; une nouvelle date au-delà de l'abrogation vérifiée
 *   est refusée explicitement (jamais de remplacement silencieux du code).
 */
class NomenclatureHistoricalValidityTest extends ApiTestBase
{
    private const REPEALED = '2026-10-01';

    private User $alice;
    private User $admin;
    private Years $year;
    private Surgeons $boss;
    private Nomenclature $current;     // actif
    private Nomenclature $verified;    // inactif, abrogation vérifiée au 01/10/2026
    private Nomenclature $unverified;  // inactif, date saisie mais non vérifiée
    private Nomenclature $unknown;     // inactif, sans date d'abrogation

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('alice@test.be');
        $this->admin = $this->createUser('admin@test.be', ['ROLE_ADMIN']);
        $this->year = $this->createYear($this->alice);
        $this->boss = (new Surgeons())->setFirstName('Dr')->setLastName('Dupont')->setBoss(true)->setYear($this->year);
        $this->em->persist($this->boss);

        $this->current = $this->nomenclature('Prothèse totale de genou', '289015', 'N5');
        $this->verified = $this->nomenclature('Méniscectomie (abrogé, date vérifiée)', '300344', 'N250');
        $this->unverified = $this->nomenclature('Arthrotomie (abrogé, date non vérifiée)', '289100', 'N1');
        $this->unknown = $this->nomenclature('Ostéotomie (abrogé, date inconnue)', '289200', 'N2');
        $this->em->flush();

        $at = new \DateTimeImmutable('2026-10-02 09:00:00');
        $this->verified->setInamiRepealedAt(new \DateTimeImmutable(self::REPEALED))->setInamiRepealSource('Moniteur belge du 15/09/2026')->verifyRepeal($at)->deactivate($at);
        $this->unverified->setInamiRepealedAt(new \DateTimeImmutable(self::REPEALED))->setInamiRepealSource('Moniteur belge du 15/09/2026')->deactivate($at);
        $this->unknown->deactivate($at);
        $this->em->flush();
    }

    // ------------------------------------------------- Création (saisie tardive)

    public function testLateEntryBeforeTheVerifiedRepealIsAcceptedOnEveryCreationRoute(): void
    {
        foreach (['addNewSurgery', 'add', 'apiPlatform'] as $route) {
            $this->assertSame(201, $this->create($route, $this->verified, '2026-09-30'), $route);
        }

        $rows = $this->rows();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('300344N250', $row['code'], 'code de l\'acte réalisé, copié tel quel');
            $this->assertSame($this->verified->getId(), (int) $row['nomenclature_id']);
            $this->assertSame('2026-09-30', substr($row['date'], 0, 10));
        }
    }

    public function testEntryOnOrAfterTheRepealIsRefusedOnEveryCreationRoute(): void
    {
        foreach ([self::REPEALED, '2026-10-15'] as $date) {
            foreach (['addNewSurgery', 'add', 'apiPlatform'] as $route) {
                [$status, $message] = $this->createWithMessage($route, $this->verified, $date);
                $this->assertSame($route === 'apiPlatform' ? 422 : 400, $status, "$route $date");
                $this->assertStringContainsString('abrogé le 01/10/2026', $message, "$route $date");
            }
        }
        $this->assertCount(0, $this->rows());
    }

    public function testAnUnverifiedOrUnknownRepealDateNeverOpensAValidityPeriod(): void
    {
        foreach ([$this->unverified, $this->unknown] as $code) {
            foreach (['addNewSurgery', 'add', 'apiPlatform'] as $route) {
                [$status, $message] = $this->createWithMessage($route, $code, '2015-01-01');
                $this->assertSame($route === 'apiPlatform' ? 422 : 400, $status, $route . ' ' . $code->getName());
                $this->assertSame("Ce code INAMI n'est plus actif. Choisissez un acte actif dans la liste.", $message);
            }
        }
        $this->assertCount(0, $this->rows());
    }

    public function testAnActiveCodeStaysUsableAtAnyDate(): void
    {
        $this->assertSame(201, $this->create('addNewSurgery', $this->current, '2015-01-01'));
        $this->assertSame(201, $this->create('addNewSurgery', $this->current, '2026-10-20'));
    }

    // ------------------------------------------------- Changement d'acte

    public function testChangingTheActUsesTheNewDateOfTheIntervention(): void
    {
        $client = $this->clientFor($this->alice);

        // PUT : acte abrogé choisi avec une date antérieure à l'abrogation (date envoyée = nouvelle date).
        $a = $this->surgery($this->current, '289015N5', '2026-10-20');
        $client->request('PUT', '/api/surgeries/' . $a->getId(), ['json' => ['date' => '2026-09-15', 'surgeryId' => (string) $this->verified->getId()]]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['300344N250', '2026-09-15'], $this->identity($a));

        // PUT, PATCH et ancienne route : même acte choisi pour une date postérieure → refusé, rien ne change.
        $b = $this->surgery($this->current, '289015N5', '2026-09-15');
        $before = $this->rows();
        $response = $client->request('PUT', '/api/surgeries/' . $b->getId(), ['json' => ['date' => '2026-10-20', 'surgeryId' => (string) $this->verified->getId()]]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('name', $response->toArray(false)['violations'][0]['propertyPath']);
        $client->request('PATCH', '/api/surgeries/' . $b->getId(), [
            'json' => ['date' => '2026-10-20', 'surgeryId' => $this->verified->getId()],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);
        $response = $client->request('PUT', '/api/surgeries/update/' . $b->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2026-10-20', 'position' => 1, 'surgeryId' => $this->verified->getId(),
        ]]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('nomenclature_inactive', $response->toArray(false)['reason']);
        $this->assertSame($before, $this->rows());

        // Ancienne route : acte abrogé pour une date antérieure → accepté.
        $client->request('PUT', '/api/surgeries/update/' . $b->getId(), ['json' => [
            'year' => $this->year->getId(), 'date' => '2026-09-16', 'position' => 1, 'surgeryId' => $this->verified->getId(),
        ]]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['300344N250', '2026-09-16'], $this->identity($b));

        // Code non vérifié : refusé même pour une date ancienne.
        $client->request('PUT', '/api/surgeries/' . $a->getId(), ['json' => ['date' => '2015-01-01', 'surgeryId' => (string) $this->unverified->getId()]]);
        $this->assertResponseStatusCodeSame(422);
    }

    // ------------------------------------------------- Acte inchangé, date modifiée

    public function testMovingAnInterventionPastTheVerifiedRepealIsRefusedWithoutAlteringTheCode(): void
    {
        $client = $this->clientFor($this->alice);
        $s = $this->surgery($this->verified, '300344N250-HIST', '2026-09-10');
        $before = $this->rows();

        $response = $client->request('PUT', '/api/surgeries/' . $s->getId(), ['json' => ['date' => '2026-10-05', 'surgeryId' => '']]);
        $this->assertResponseStatusCodeSame(422);
        $violation = $response->toArray(false)['violations'][0];
        $this->assertSame('date', $violation['propertyPath']);
        $this->assertStringContainsString('abrogé le 01/10/2026', $violation['message']);

        $client->request('PATCH', '/api/surgeries/' . $s->getId(), [
            'json' => ['date' => self::REPEALED],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->assertResponseStatusCodeSame(422);

        $response = $client->request('PUT', '/api/surgeries/update/' . $s->getId(), ['json' => ['year' => $this->year->getId(), 'date' => '2026-10-05', 'position' => 1]]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('nomenclature_repealed_on_date', $response->toArray(false)['reason']);
        $this->assertSame($before, $this->rows(), 'aucune donnée modifiée');

        // Nouvelle date toujours antérieure : acceptée, code historique conservé tel quel.
        $client->request('PUT', '/api/surgeries/' . $s->getId(), ['json' => ['date' => '2026-09-20', 'surgeryId' => '']]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['300344N250-HIST', '2026-09-20'], $this->identity($s));
    }

    public function testEditsWithoutDateChangeStayPossibleEvenForAnInterventionDatedAfterTheRepeal(): void
    {
        // Donnée historique incohérente (saisie avant les règles) : préservée et modifiable.
        $s = $this->surgery($this->verified, '300344N250', '2026-11-01');
        $client = $this->clientFor($this->alice);

        $client->request('PUT', '/api/surgeries/' . $s->getId(), ['json' => ['date' => '2026-11-01', 'position' => '2', 'firstHand' => (string) $this->boss->getId(), 'surgeryId' => '']]);
        $this->assertResponseIsSuccessful();
        $client->request('PUT', '/api/surgeries/update/' . $s->getId(), ['json' => ['year' => $this->year->getId(), 'date' => '2026-11-01', 'position' => 1]]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['300344N250', '2026-11-01'], $this->identity($s));
    }

    public function testDateChangesAreNotRestrictedWhenTheRepealDateIsNotVerified(): void
    {
        // Aucune date vérifiée : on ne suppose ni validité ni invalidité ; le code reste inchangé.
        $s = $this->surgery($this->unverified, '289100N1', '2026-09-10');
        $client = $this->clientFor($this->alice);

        $client->request('PUT', '/api/surgeries/' . $s->getId(), ['json' => ['date' => '2026-12-01', 'surgeryId' => '']]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(['289100N1', '2026-12-01'], $this->identity($s));
    }

    // ------------------------------------------------- Liste de choix selon la date

    public function testTheChoiceListFollowsTheDateOfTheIntervention(): void
    {
        $client = $this->clientFor($this->alice);

        $before = array_column($client->request('GET', '/api/nomenclature/ortho?date=2026-09-30')->toArray(), null, 'id');
        $this->assertSame([$this->current->getId(), $this->verified->getId()], array_keys($before));
        $this->assertTrue($before[$this->current->getId()]['active']);
        $this->assertNull($before[$this->current->getId()]['inamiRepealedAt']);
        $this->assertFalse($before[$this->verified->getId()]['active']);
        $this->assertSame(self::REPEALED, $before[$this->verified->getId()]['inamiRepealedAt']);

        $on = $client->request('GET', '/api/nomenclature/ortho?date=' . self::REPEALED)->toArray();
        $this->assertSame([$this->current->getId()], array_column($on, 'id'));

        // Sans date : codes actifs, forme historique de la réponse (anciens fronts).
        $legacy = $client->request('GET', '/api/nomenclature/ortho')->toArray();
        $this->assertSame([$this->current->getId()], array_column($legacy, 'id'));
        $this->assertArrayNotHasKey('active', $legacy[0]);

        foreach (['2026-02-30', '30/09/2026', 'demain'] as $invalid) {
            $client->request('GET', '/api/nomenclature/ortho?date=' . urlencode($invalid));
            $this->assertResponseStatusCodeSame(400, $invalid);
        }
    }

    // ------------------------------------------------- Favoris

    public function testFavoritesOfARepealedCodeAreUsableOnlyForAConsistentHistoricalDate(): void
    {
        $verified = $this->favorite($this->verified, 'Ménisque');
        $unverified = $this->favorite($this->unverified, 'Arthrotomie');
        $active = $this->favorite($this->current, 'PTG');
        $client = $this->clientFor($this->alice);

        $list = array_column($client->request('GET', '/api/favorites/getMyList')->toArray(), null, 'id');
        $this->assertSame([false, self::REPEALED], [$list[$verified->getId()]['active'], $list[$verified->getId()]['historicalUntil']]);
        $this->assertSame([false, null], [$list[$unverified->getId()]['active'], $list[$unverified->getId()]['historicalUntil']]);
        $this->assertSame([true, null], [$list[$active->getId()]['active'], $list[$active->getId()]['historicalUntil']]);
        $this->assertSame(self::REPEALED, $client->request('GET', '/api/favorites/getById/' . $verified->getId())->toArray()['historicalUntil']);

        // Intervention depuis le favori : acceptée avant l'abrogation, refusée après.
        $this->assertSame(201, $this->create('addNewSurgery', $this->verified, '2026-09-01'));
        $this->assertSame(400, $this->create('addNewSurgery', $this->verified, '2026-10-02'));

        // Un favori (sans date) ne peut pas être créé ni redirigé vers un code inactif.
        $client->request('POST', '/api/favorites/addNew', ['json' => ['surgeryId' => $this->verified->getId(), 'shortcut' => 'x']]);
        $this->assertResponseStatusCodeSame(400);
    }

    // ------------------------------------------------- Administration : vérification de la date

    public function testTheOfficialRepealDateMustBeVerifiedWithASource(): void
    {
        $client = $this->clientFor($this->admin);
        $id = $this->unknown->getId();

        // Vérification impossible sans date ou sans source.
        $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealVerified' => true]]);
        $this->assertResponseStatusCodeSame(400);
        $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealedAt' => '2026-06-01', 'inamiRepealVerified' => true]]);
        $this->assertResponseStatusCodeSame(400);
        $this->em->clear();
        $this->assertNull($this->em->find(Nomenclature::class, $id)->getInamiRepealedAt(), 'requête refusée : rien enregistré');

        // Date, source et vérification ensemble : acceptées, le code devient utilisable avant cette date.
        $item = $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => [
            'active' => false, 'inamiRepealedAt' => '2026-06-01', 'inamiRepealSource' => 'Moniteur belge du 20/05/2026', 'inamiRepealVerified' => true,
        ]])->toArray();
        $this->assertSame(['2026-06-01', 'Moniteur belge du 20/05/2026'], [$item['inamiRepealedAt'], $item['inamiRepealSource']]);
        $this->assertNotNull($item['inamiRepealVerifiedAt']);
        $this->assertSame(201, $this->create('addNewSurgery', $this->unknown, '2026-05-31'));
        $this->assertSame(400, $this->create('addNewSurgery', $this->unknown, '2026-06-01'));

        // Même date et même source renvoyées : vérification conservée (date d'origine).
        $again = $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => [
            'active' => false, 'inamiRepealedAt' => '2026-06-01', 'inamiRepealSource' => 'Moniteur belge du 20/05/2026', 'inamiRepealVerified' => true,
        ]])->toArray();
        $this->assertSame($item['inamiRepealVerifiedAt'], $again['inamiRepealVerifiedAt']);

        // Date modifiée sans nouvelle vérification : vérification effacée, plus aucune saisie tardive.
        $changed = $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealedAt' => '2026-07-01']])->toArray();
        $this->assertNull($changed['inamiRepealVerifiedAt']);
        $this->assertSame(400, $this->create('addNewSurgery', $this->unknown, '2026-05-31'));

        // Source modifiée : idem ; retrait explicite de la vérification : idem.
        $this->assertSame(200, $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealVerified' => true]])->getStatusCode());
        $changed = $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealSource' => 'Autre source']])->toArray();
        $this->assertNull($changed['inamiRepealVerifiedAt']);
        $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealVerified' => true]]);
        $unverified = $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false, 'inamiRepealVerified' => false]])->toArray();
        $this->assertNull($unverified['inamiRepealVerifiedAt']);

        foreach ([['inamiRepealVerified' => 'oui'], ['inamiRepealSource' => 12], ['inamiRepealSource' => str_repeat('x', 256)]] as $invalid) {
            $status = $client->request('PUT', "/api/admin/nomenclature/$id/status", ['json' => ['active' => false] + $invalid])->getStatusCode();
            $this->assertSame(400, $status, json_encode($invalid));
        }
    }

    // ------------------------------------------------- Export

    public function testALateEntryAppearsInTheExcelBookletWithItsHistoricalCode(): void
    {
        $this->assertSame(201, $this->create('addNewSurgery', $this->verified, '2026-09-30'));

        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $this->clientFor($this->alice)->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);
        $cells = array_map(fn ($value) => trim((string) $value), array_merge(...$book->getSheet(1)->toArray()));
        $this->assertContains('300344N250', $cells);
    }

    // ------------------------------------------------- Helpers

    private function nomenclature(string $name, string $code, string $n): Nomenclature
    {
        $nomenclature = (new Nomenclature())->setSpeciality('ortho')->setName($name)->setCodeHospitalisation($code)->setN($n)->setType('1')->setSubType('knee');
        $this->em->persist($nomenclature);

        return $nomenclature;
    }

    /** Intervention enregistrée directement (donnée existante). */
    private function surgery(Nomenclature $nomenclature, string $code, string $date): Surgeries
    {
        $surgery = (new Surgeries())
            ->setYear($this->em->getReference(Years::class, $this->year->getId()))
            ->setDate(new \DateTime($date))
            ->setNomenclature($this->em->getReference(Nomenclature::class, $nomenclature->getId()))
            ->setCode($code)
            ->setName($nomenclature->getName())
            ->setSpeciality('ortho')
            ->setPosition('1')
            ->setFirstHand((string) $this->alice->getId())
            ->setCreatedAt(new \DateTime('2026-09-01 10:00'));
        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    private function favorite(Nomenclature $nomenclature, string $shortcut): Favorites
    {
        $favorite = (new Favorites())
            ->setUser($this->em->getReference(User::class, $this->alice->getId()))
            ->setShortcut($shortcut)
            ->setSurgeryName($nomenclature->getName())
            ->setCodeHospitalisation($nomenclature->getCodeHospitalisation() . $nomenclature->getN())
            ->setSpeciality('ortho')
            ->setSurgery($this->em->getReference(Nomenclature::class, $nomenclature->getId()));
        $this->em->persist($favorite);
        $this->em->flush();

        return $favorite;
    }

    /** Statut normalisé : 201 si l'intervention est créée (200 des contrôleurs custom compris). */
    private function create(string $route, Nomenclature $nomenclature, string $date): int
    {
        return $this->createWithMessage($route, $nomenclature, $date)[0];
    }

    /** @return array{int, string} statut (201 = créée) et message de refus éventuel */
    private function createWithMessage(string $route, Nomenclature $nomenclature, string $date): array
    {
        $client = $this->clientFor($this->alice);
        if ($route === 'apiPlatform') {
            $response = $client->request('POST', '/api/surgeries', ['json' => [
                'date' => $date, 'speciality' => 'ortho', 'name' => 'x', 'position' => '1',
                'year' => '/api/years/' . $this->year->getId(), 'surgeryId' => (string) $nomenclature->getId(),
            ]]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);

            return [$status, $body['violations'][0]['message'] ?? ''];
        }
        $response = $client->request('POST', '/api/surgeries/' . $route, ['json' => [
            'date' => $date, 'year' => $this->year->getId(), 'surgeryId' => $nomenclature->getId(), 'position' => 1,
        ]]);
        $status = $response->getStatusCode();

        return [$status === 200 ? 201 : $status, $response->toArray(false)['message'] ?? ''];
    }

    private function rows(): array
    {
        return array_column($this->em->getConnection()->fetchAllAssociative('SELECT * FROM surgeries ORDER BY id'), null, 'id');
    }

    /** @return array{string, string} code et date (AAAA-MM-JJ) enregistrés */
    private function identity(Surgeries $surgery): array
    {
        $row = $this->rows()[$surgery->getId()];

        return [$row['code'], substr($row['date'], 0, 10)];
    }
}
