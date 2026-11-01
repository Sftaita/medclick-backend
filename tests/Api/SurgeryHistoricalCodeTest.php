<?php

namespace App\Tests\Api;

use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Entity\Surgeons;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Étape 3 (nomenclature INAMI) : une intervention déjà enregistrée garde son code INAMI
 * historique, son intitulé, sa spécialité et sa référence nomenclature tant que l'utilisateur
 * ne choisit pas réellement un autre acte.
 *
 * Cas de production reproduit : la ligne de nomenclature a été renumérotée depuis la saisie
 * (ex. Méniscectomie 300344N250 enregistrée, 276640N250 aujourd'hui) ; l'intervention porte
 * donc un code différent du code actuel de sa nomenclature.
 */
class SurgeryHistoricalCodeTest extends ApiTestBase
{
    private const OLD_CODE = '300344N250';
    private const OLD_NAME = 'Méniscectomie interne ou externe du genou (libellé 2019)';

    private User $alice;
    private Years $year;
    private Surgeons $boss;
    private Surgeons $other;
    private Nomenclature $menisque;   // renumérotée : 276640 + N250
    private Nomenclature $appendice;  // autre acte

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);
        $this->boss = $this->createSurgeon('Dupont', true);
        $this->other = $this->createSurgeon('Lambert', false);

        $this->menisque = (new Nomenclature())
            ->setSpeciality('ortho')
            ->setName('Méniscectomie interne ou externe du genou')
            ->setCodeHospitalisation('276640')
            ->setN('N250')
            ->setType('1')
            ->setSubType('knee');
        $this->appendice = (new Nomenclature())
            ->setSpeciality('dig')
            ->setName('Appendicectomie')
            ->setCodeHospitalisation('241415')
            ->setN('N200');
        $this->em->persist($this->menisque);
        $this->em->persist($this->appendice);
        $this->em->flush();
    }

    // ------------------------------------------------- PUT /api/surgeries/{id} (fronts actuels)

    public function testChangingTheDateKeepsTheHistoricalCode(): void
    {
        $surgery = $this->historicalSurgery();

        // Payload exact du front déployé (SurgeryFormPage) : GET ne renvoie pas surgeryId => "".
        $this->put($surgery, [
            'date' => '2024-03-15',
            'year' => '/api/years/' . $this->year->getId(),
            'speciality' => 'ortho',
            'name' => self::OLD_NAME,
            'surgeryId' => '',
            'position' => '1',
            'firstHand' => (string) $this->alice->getId(),
            'secondHand' => '',
        ]);

        $updated = $this->reload($surgery);
        $this->assertHistoricalIdentity($updated);
        $this->assertSame('2024-03-15', $updated->getDate()->format('Y-m-d'));
    }

    public function testChangingTheRoleKeepsTheHistoricalCode(): void
    {
        $surgery = $this->historicalSurgery();

        $this->put($surgery, ['position' => '2', 'firstHand' => (string) $this->boss->getId(), 'secondHand' => (string) $this->alice->getId()]);

        $updated = $this->reload($surgery);
        $this->assertHistoricalIdentity($updated);
        $this->assertSame('2', $updated->getPosition());
        $this->assertSame((string) $this->boss->getId(), $updated->getFirstHand());
    }

    public function testChangingTheSupervisorKeepsTheHistoricalCode(): void
    {
        $surgery = $this->historicalSurgery('3');

        $this->put($surgery, ['secondHand' => (string) $this->other->getId()]);

        $updated = $this->reload($surgery);
        $this->assertHistoricalIdentity($updated);
        $this->assertSame((string) $this->other->getId(), $updated->getSecondHand());
    }

    public function testResendingTheSameActKeepsTheHistoricalCode(): void
    {
        // Avant correction : surgeryId égal à l'acte lié => code actuel 276640N250 recopié.
        foreach ([(string) $this->menisque->getId(), $this->menisque->getId()] as $surgeryId) {
            $surgery = $this->historicalSurgery();

            $this->put($surgery, ['surgeryId' => $surgeryId, 'date' => '2024-04-01', 'position' => '1']);

            $updated = $this->reload($surgery);
            $this->assertHistoricalIdentity($updated);
            $this->assertSame('2024-04-01', $updated->getDate()->format('Y-m-d'));
        }
    }

    public function testIdentityFieldsWithoutAnotherActAreIgnored(): void
    {
        $surgery = $this->historicalSurgery();

        // Nom, code ou spécialité envoyés sans choix d'un autre acte : l'identité ne bouge pas
        // (l'ancien formulaire pouvait envoyer le nom d'un acte B sans son surgeryId).
        $this->put($surgery, [
            'name' => 'Appendicectomie',
            'speciality' => 'dig',
            'code' => '999999N1',
            'surgeryId' => '',
            'position' => '1',
        ]);

        $this->assertHistoricalIdentity($this->reload($surgery));
    }

    public function testPatchKeepsTheHistoricalCode(): void
    {
        $surgery = $this->historicalSurgery();

        $this->clientFor($this->alice)->request('PATCH', '/api/surgeries/' . $surgery->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['date' => '2024-05-02', 'surgeryId' => (string) $this->menisque->getId()],
        ]);
        $this->assertResponseIsSuccessful();

        $updated = $this->reload($surgery);
        $this->assertHistoricalIdentity($updated);
        $this->assertSame('2024-05-02', $updated->getDate()->format('Y-m-d'));
    }

    public function testChoosingAnotherActUpdatesAllIdentityFieldsTogether(): void
    {
        $surgery = $this->historicalSurgery();

        $this->put($surgery, [
            'name' => 'Appendicectomie',
            'speciality' => 'dig',
            'surgeryId' => (string) $this->appendice->getId(),
            'position' => '1',
        ]);

        $this->assertIdentity($this->reload($surgery), $this->appendice, '241415N200', 'Appendicectomie', 'dig');
    }

    public function testChoosingAnotherActIgnoresInconsistentPayloadFields(): void
    {
        $surgery = $this->historicalSurgery();

        // Nom, code et spécialité du payload incohérents avec l'acte choisi : l'acte fait foi.
        $this->put($surgery, [
            'name' => 'Nom saisi à la main',
            'speciality' => 'ortho',
            'code' => '000000',
            'surgeryId' => $this->appendice->getId(),
        ]);

        $this->assertIdentity($this->reload($surgery), $this->appendice, '241415N200', 'Appendicectomie', 'dig');
    }

    public function testComingBackToTheOriginalActAfterAChangeTakesItsCurrentCode(): void
    {
        $surgery = $this->historicalSurgery();

        $this->put($surgery, ['surgeryId' => (string) $this->appendice->getId()]);
        $this->put($surgery, ['surgeryId' => (string) $this->menisque->getId()]);

        // Choix explicite d'un autre acte (le ménisque après l'appendice) : code actuel recopié.
        $this->assertIdentity($this->reload($surgery), $this->menisque, '276640N250', $this->menisque->getName(), 'ortho');
    }

    public function testUnknownActIsRejectedAndChangesNothing(): void
    {
        $surgery = $this->historicalSurgery();

        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['surgeryId' => '999999', 'position' => '2']]);
        $this->assertResponseStatusCodeSame(400);

        $unchanged = $this->reload($surgery);
        $this->assertHistoricalIdentity($unchanged);
        $this->assertSame('1', $unchanged->getPosition());
    }

    // ------------------------------------------------- Ancienne route PUT /api/surgeries/update/{id}

    public function testLegacyRouteWithTheSameActKeepsTheHistoricalCode(): void
    {
        $surgery = $this->historicalSurgery();

        $this->legacyPut($surgery, ['surgeryId' => $this->menisque->getId(), 'date' => '2024-06-10', 'position' => 2, 'firstHand' => (string) $this->boss->getId()]);
        $this->assertResponseIsSuccessful();

        $updated = $this->reload($surgery);
        $this->assertHistoricalIdentity($updated);
        $this->assertSame('2024-06-10', $updated->getDate()->format('Y-m-d'));
        $this->assertSame('2', $updated->getPosition());
        $this->assertSame((string) $this->boss->getId(), $updated->getFirstHand());
    }

    public function testLegacyRouteWithoutActKeepsTheHistoricalCode(): void
    {
        $surgery = $this->historicalSurgery();

        $this->legacyPut($surgery, ['date' => '2024-06-11', 'position' => 1]);
        $this->assertResponseIsSuccessful();

        $updated = $this->reload($surgery);
        $this->assertHistoricalIdentity($updated);
        $this->assertSame('2024-06-11', $updated->getDate()->format('Y-m-d'));
    }

    public function testLegacyRouteWithAnotherActUpdatesAllIdentityFieldsTogether(): void
    {
        $surgery = $this->historicalSurgery();

        $this->legacyPut($surgery, ['surgeryId' => (string) $this->appendice->getId(), 'date' => '2024-06-12', 'position' => 1]);
        $this->assertResponseIsSuccessful();

        $this->assertIdentity($this->reload($surgery), $this->appendice, '241415N200', 'Appendicectomie', 'dig');
    }

    public function testLegacyRouteRejectsAnUnknownActOrAMissingActWithoutNomenclature(): void
    {
        $surgery = $this->historicalSurgery();
        $this->legacyPut($surgery, ['surgeryId' => 999999, 'date' => '2024-06-13', 'position' => 2]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertHistoricalIdentity($this->reload($surgery));
        $this->assertSame('1', $this->reload($surgery)->getPosition());

        // Intervention sans nomenclature : un acte reste obligatoire (comportement historique).
        $free = (new Surgeries())
            ->setYear($this->em->getReference(Years::class, $this->year->getId()))->setDate(new \DateTime('2020-01-01'))->setSpeciality('ortho')
            ->setName('Saisie libre')->setCode('111111N1')->setPosition('1');
        $this->em->persist($free);
        $this->em->flush();
        $this->legacyPut($free, ['date' => '2024-06-13', 'position' => 1]);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testCreationRoutesStillCopyTheCurrentActAndEditsKeepIt(): void
    {
        $client = $this->clientFor($this->alice);

        // Ancienne (/add) et nouvelle (/addNewSurgery) création : comportement inchangé.
        foreach (['/api/surgeries/add', '/api/surgeries/addNewSurgery'] as $route) {
            $client->request('POST', $route, ['json' => [
                'date' => '2025-11-02',
                'year' => $this->year->getId(),
                'surgeryId' => $this->appendice->getId(),
                'position' => 1,
            ]]);
            $this->assertResponseIsSuccessful();

            $created = static::getContainer()->get('doctrine')->getManager()->getRepository(Surgeries::class)->findOneBy([], ['id' => 'DESC']);
            $this->assertIdentity($created, $this->appendice, '241415N200', 'Appendicectomie', 'dig');

            // Lecture par l'API (fronts) puis modification du rôle seul : acte conservé.
            $read = $client->request('GET', '/api/surgeries/' . $created->getId())->toArray();
            $this->assertSame('Appendicectomie', $read['name']);
            $this->put($created, ['position' => '3', 'secondHand' => (string) $this->boss->getId()]);
            $this->assertIdentity($this->reload($created), $this->appendice, '241415N200', 'Appendicectomie', 'dig');
        }
    }

    // ------------------------------------------------- Interventions historiques « favorites »

    public function testHistoricalFavoritesSurgeryIsStrictlyPreservedWithoutNewAct(): void
    {
        $edits = [
            'date' => fn (Surgeries $s) => $this->put($s, ['date' => '2024-03-15', 'speciality' => 'favorites', 'name' => self::OLD_NAME, 'surgeryId' => '']),
            'rôle' => fn (Surgeries $s) => $this->put($s, ['position' => '2', 'firstHand' => (string) $this->boss->getId(), 'speciality' => 'favorites']),
            'superviseur' => fn (Surgeries $s) => $this->put($s, ['secondHand' => (string) $this->other->getId()]),
            'même acte (chaîne)' => fn (Surgeries $s) => $this->put($s, ['surgeryId' => (string) $this->menisque->getId(), 'speciality' => 'ortho']),
            'même acte (entier)' => fn (Surgeries $s) => $this->put($s, ['surgeryId' => $this->menisque->getId(), 'speciality' => 'favorites']),
            'PATCH' => function (Surgeries $s) {
                $this->clientFor($this->alice)->request('PATCH', '/api/surgeries/' . $s->getId(), [
                    'headers' => ['Content-Type' => 'application/merge-patch+json'],
                    'json' => ['date' => '2024-05-02', 'surgeryId' => (string) $this->menisque->getId()],
                ]);
                $this->assertResponseIsSuccessful();
            },
            'ancienne route, même acte' => function (Surgeries $s) {
                $this->legacyPut($s, ['surgeryId' => $this->menisque->getId(), 'position' => 1]);
                $this->assertResponseIsSuccessful();
            },
            'ancienne route, sans acte' => function (Surgeries $s) {
                $this->legacyPut($s, ['position' => 1]);
                $this->assertResponseIsSuccessful();
            },
        ];

        foreach ($edits as $label => $edit) {
            $surgery = $this->historicalSurgery('3', 'favorites');
            $edit($surgery);

            $updated = $this->reload($surgery);
            $this->assertSame($this->menisque->getId(), $updated->getNomenclature()?->getId(), "$label : nomenclature_id");
            $this->assertSame(self::OLD_CODE, $updated->getCode(), "$label : code");
            $this->assertSame(self::OLD_NAME, $updated->getName(), "$label : intitulé");
            $this->assertSame('favorites', $updated->getSpeciality(), "$label : spécialité");
        }
    }

    public function testHistoricalFavoritesSurgeryWithAnotherActTakesTheActSpeciality(): void
    {
        $surgery = $this->historicalSurgery('1', 'favorites');

        // Choix explicite d'un autre acte (via « Mes favoris », le front envoyait « favorites »).
        $this->put($surgery, ['surgeryId' => (string) $this->appendice->getId(), 'speciality' => 'favorites']);

        $this->assertIdentity($this->reload($surgery), $this->appendice, '241415N200', 'Appendicectomie', 'dig');
    }

    // ------------------------------------------------- Aucune autre donnée touchée, export

    public function testEditingOneSurgeryLeavesAllOtherSurgeriesUntouched(): void
    {
        $edited = $this->historicalSurgery();
        $others = [$this->historicalSurgery(), $this->historicalSurgery('3')];
        $before = $this->snapshot();

        $this->put($edited, ['date' => '2024-07-01', 'position' => '1']);
        $this->legacyPut($edited, ['surgeryId' => $this->menisque->getId(), 'date' => '2024-07-02', 'position' => 1]);
        $this->assertResponseIsSuccessful();

        $after = $this->snapshot();
        foreach ($others as $other) {
            $this->assertSame($before[$other->getId()], $after[$other->getId()]);
        }
        // Intervention modifiée : seuls la date (et le rôle) ont changé.
        $expected = $before[$edited->getId()];
        $expected['date'] = '2024-07-02';
        $this->assertSame($expected, $after[$edited->getId()]);
    }

    public function testTheExcelBookletKeepsTheHistoricalCodeAfterAnEdit(): void
    {
        $surgery = $this->historicalSurgery();
        $client = $this->clientFor($this->alice);

        $client->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['date' => '2024-03-15', 'surgeryId' => (string) $this->menisque->getId()]]);
        $this->assertResponseIsSuccessful();

        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $client->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);

        $cells = [];
        foreach ([1, 2] as $index) {
            $cells[$index] = array_map(fn ($value) => trim((string) $value), array_merge(...$book->getSheet($index)->toArray()));
        }
        $this->assertContains(self::OLD_CODE, $cells[1], 'carnet');
        $this->assertContains(self::OLD_CODE, $cells[2], 'récapitulatif');
        $this->assertNotContains('276640N250', array_merge($cells[1], $cells[2]));
    }

    // ------------------------------------------------- Helpers

    /** Intervention enregistrée avec l'ancien code, liée à la ligne de nomenclature renumérotée. */
    private function historicalSurgery(string $position = '1', string $speciality = 'ortho'): Surgeries
    {
        // Références gérées : reload() vide le gestionnaire d'entités entre deux requêtes.
        $surgery = (new Surgeries())
            ->setYear($this->em->getReference(Years::class, $this->year->getId()))
            ->setDate(new \DateTime('2019-11-05'))
            ->setNomenclature($this->em->getReference(Nomenclature::class, $this->menisque->getId()))
            ->setCode(self::OLD_CODE)
            ->setName(self::OLD_NAME)
            ->setSpeciality($speciality)
            ->setPosition($position)
            ->setFirstHand((string) $this->alice->getId())
            ->setSecondHand($position === '3' ? (string) $this->boss->getId() : null)
            ->setCreatedAt(new \DateTime('2019-11-05 10:00'));
        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    private function createSurgeon(string $lastName, bool $boss): Surgeons
    {
        $surgeon = (new Surgeons())->setFirstName('Dr')->setLastName($lastName)->setBoss($boss)->setYear($this->year);
        $this->em->persist($surgeon);
        $this->em->flush();

        return $surgeon;
    }

    private function put(Surgeries $surgery, array $payload): void
    {
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => $payload]);
        $this->assertResponseIsSuccessful();
    }

    private function legacyPut(Surgeries $surgery, array $payload): void
    {
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/update/' . $surgery->getId(), ['json' => $payload + [
            'year' => $this->year->getId(),
            'date' => '2024-01-01',
        ]]);
    }

    private function assertHistoricalIdentity(Surgeries $surgery): void
    {
        $this->assertIdentity($surgery, $this->menisque, self::OLD_CODE, self::OLD_NAME, 'ortho');
    }

    private function assertIdentity(Surgeries $surgery, Nomenclature $nomenclature, string $code, string $name, string $speciality): void
    {
        $this->assertSame($nomenclature->getId(), $surgery->getNomenclature()?->getId(), 'référence nomenclature_id');
        $this->assertSame($code, $surgery->getCode(), 'code INAMI');
        $this->assertSame($name, $surgery->getName(), 'intitulé');
        $this->assertSame($speciality, $surgery->getSpeciality(), 'spécialité');
    }

    /** Toutes les colonnes de toutes les interventions, lues directement en base. */
    private function snapshot(): array
    {
        $rows = static::getContainer()->get('doctrine')->getConnection()->fetchAllAssociative('SELECT * FROM surgeries ORDER BY id');

        return array_column($rows, null, 'id');
    }

    private function reload(Surgeries $surgery): Surgeries
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->find(Surgeries::class, $surgery->getId());
    }
}
