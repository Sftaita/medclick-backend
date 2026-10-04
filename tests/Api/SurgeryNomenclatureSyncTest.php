<?php

namespace App\Tests\Api;

use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * LOT 2D.1 — PUT /api/surgeries/{id} (opération API Platform utilisée par les fronts) : quand
 * l'utilisateur choisit une autre intervention, le nom, le code, la spécialité et la relation
 * nomenclature doivent tous suivre, comme à la création (/api/surgeries/addNewSurgery).
 */
class SurgeryNomenclatureSyncTest extends ApiTestBase
{
    private User $alice;
    private Years $year;
    private Nomenclature $hanche;
    private Nomenclature $appendice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);
        $this->hanche = $this->createNomenclature(); // ortho, 289085 + n 1
        $this->appendice = (new Nomenclature())
            ->setSpeciality('dig')
            ->setName('Appendicectomie')
            ->setCodeHospitalisation('241415')
            ->setN('2');
        $this->em->persist($this->appendice);
        $this->em->flush();
    }

    public function testPutWithAnotherInterventionSyncsNameCodeSpecialityAndNomenclature(): void
    {
        $surgery = $this->addSurgery($this->hanche);

        // Payload exact du nouveau front (SurgeryFormPage) après choix d'une autre intervention.
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => [
            'date' => '2025-11-02',
            'year' => '/api/years/' . $this->year->getId(),
            'speciality' => 'dig',
            'name' => 'Appendicectomie',
            'surgeryId' => (string) $this->appendice->getId(),
            'position' => '1',
            'firstHand' => (string) $this->alice->getId(),
            'secondHand' => '',
        ]]);
        $this->assertResponseIsSuccessful();

        $this->assertSynced($this->reload($surgery), $this->appendice, '2414152');
    }

    public function testPutWithIntegerSurgeryIdAndFavoritesSpecialityUsesTheNomenclature(): void
    {
        $surgery = $this->addSurgery($this->hanche);

        // Ancien front : surgeryId entier (Form.js) et spécialité « favorites » quand le choix
        // vient des favoris : la spécialité doit être celle de la nomenclature.
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => [
            'date' => '2025-11-02',
            'year' => '/api/years/' . $this->year->getId(),
            'speciality' => 'favorites',
            'name' => 'Appendicectomie',
            'surgeryId' => $this->appendice->getId(),
            'position' => '1',
        ]]);
        $this->assertResponseIsSuccessful();

        $this->assertSynced($this->reload($surgery), $this->appendice, '2414152');
    }

    public function testPutWithoutChangingTheInterventionKeepsNomenclatureAndCode(): void
    {
        $surgery = $this->addSurgery($this->hanche);

        // Le GET ne renvoie pas surgeryId : sans nouveau choix, le front envoie "".
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => [
            'date' => '2025-11-03',
            'year' => '/api/years/' . $this->year->getId(),
            'speciality' => 'ortho',
            'name' => 'Prothèse totale de hanche',
            'surgeryId' => '',
            'position' => '2',
            'firstHand' => '12',
            'secondHand' => (string) $this->alice->getId(),
        ]]);
        $this->assertResponseIsSuccessful();

        $updated = $this->reload($surgery);
        $this->assertSynced($updated, $this->hanche, '2890851');
        $this->assertSame('2', $updated->getPosition());
        $this->assertSame('12', $updated->getFirstHand());
        $this->assertSame('2025-11-03', $updated->getDate()->format('Y-m-d'));
    }

    public function testPartialPutWithoutSurgeryIdKeepsNomenclatureAndCode(): void
    {
        $surgery = $this->addSurgery($this->hanche);

        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['position' => '3']]);
        $this->assertResponseIsSuccessful();

        $updated = $this->reload($surgery);
        $this->assertSynced($updated, $this->hanche, '2890851');
        $this->assertSame('3', $updated->getPosition());
    }

    public function testPutWithUnknownInterventionIsRejectedAndChangesNothing(): void
    {
        $surgery = $this->addSurgery($this->hanche);

        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => [
            'name' => 'Intervention inconnue',
            'surgeryId' => '999999',
            'position' => '2',
        ]]);
        $this->assertResponseStatusCodeSame(400);

        $unchanged = $this->reload($surgery);
        $this->assertSynced($unchanged, $this->hanche, '2890851');
        $this->assertSame('1', $unchanged->getPosition());
    }

    public function testExistingErrorsAreKept(): void
    {
        $surgery = $this->addSurgery($this->hanche);
        $bob = $this->createUser('bob@test.be');

        // Violation de validation (nom vide) : 422 inchangé.
        $this->clientFor($this->alice)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => ['name' => '']]);
        $this->assertResponseStatusCodeSame(422);

        // Intervention d'un autre utilisateur : introuvable pour lui (filtre multi-tenant).
        $this->clientFor($bob)->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => [
            'surgeryId' => (string) $this->appendice->getId(),
        ]]);
        $this->assertResponseStatusCodeSame(404);

        $this->assertSynced($this->reload($surgery), $this->hanche, '2890851');
    }

    public function testExcelExportShowsTheCodeOfTheNewIntervention(): void
    {
        $surgery = $this->addSurgery($this->hanche);
        $client = $this->clientFor($this->alice);

        $client->request('PUT', '/api/surgeries/' . $surgery->getId(), ['json' => [
            'name' => 'Appendicectomie',
            'surgeryId' => (string) $this->appendice->getId(),
        ]]);
        $this->assertResponseIsSuccessful();

        // Ligne de l'intervention dans le carnet (excel2) : code (B) et nom (C) cohérents.
        $content = $client->request('GET', '/api/excel2/' . $this->year->getId())->getContent();
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $content);
        $sheet = IOFactory::load($file)->getSheet(1); // « Carnet de stage »
        unlink($file);

        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $number = $row->getRowIndex();
            if (trim((string) $sheet->getCell('C' . $number)->getValue()) === 'Appendicectomie') {
                $rows[] = trim((string) $sheet->getCell('B' . $number)->getValue());
            }
        }
        $this->assertSame(['2414152'], $rows);
    }

    // ---------------------------------------------------------------- Helpers

    /** Création par la route des fronts : nom, code, spécialité et relation cohérents. */
    private function addSurgery(Nomenclature $nomenclature): Surgeries
    {
        $this->clientFor($this->alice)->request('POST', '/api/surgeries/addNewSurgery', ['json' => [
            'date' => '2025-11-02',
            'year' => $this->year->getId(),
            'surgeryId' => $nomenclature->getId(),
            'position' => 1,
            'firstHand' => null,
            'secondHand' => null,
        ]]);
        $this->assertResponseIsSuccessful();

        $surgery = $this->freshEm()->getRepository(Surgeries::class)->findOneBy([], ['id' => 'DESC']);
        $this->assertSynced($surgery, $nomenclature, $nomenclature->getCodeHospitalisation() . $nomenclature->getN());

        return $surgery;
    }

    private function assertSynced(Surgeries $surgery, Nomenclature $nomenclature, string $code): void
    {
        $this->assertSame($nomenclature->getId(), $surgery->getNomenclature()?->getId(), 'relation nomenclature');
        $this->assertSame($code, $surgery->getCode(), 'code');
        $this->assertSame($nomenclature->getName(), $surgery->getName(), 'nom');
        $this->assertSame($nomenclature->getSpeciality(), $surgery->getSpeciality(), 'spécialité');
    }

    private function reload(Surgeries $surgery): Surgeries
    {
        return $this->freshEm()->find(Surgeries::class, $surgery->getId());
    }

    /** Entity manager du kernel courant, vidé, pour relire l'état réel de la base. */
    private function freshEm()
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em;
    }
}
