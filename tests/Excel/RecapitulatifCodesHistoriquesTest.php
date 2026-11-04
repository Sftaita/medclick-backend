<?php

namespace App\Tests\Excel;

use App\Entity\Nomenclature;
use App\Entity\NomenclatureHistoricalSpeciality;
use App\Entity\Surgeries;
use App\Entity\Years;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ADR-213 : une ligne du récapitulatif = un code réellement enregistré. Les sections et leurs totaux ne
 * changent pas : seule la répartition en lignes change.
 */
final class RecapitulatifCodesHistoriquesTest extends ExcelTestCase
{
    private const A = '224910K305';
    private const B = '225551K360';

    public function testTwoRecordedCodesOfTheSameActGiveTwoLinesWithTheirOwnCounters(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('ortho', '1', 'back', 'Arthrodèse lombaire');
        $this->surgery($year, $act, 'ortho', self::B, 'Arthrodèse lombaire', '1');
        $this->surgery($year, $act, 'ortho', self::A, 'Arthrodèse lombaire', '2');
        $this->surgery($year, $act, 'ortho', self::B, 'Arthrodèse lombaire', '3');
        $this->surgery($year, $act, 'ortho', self::A, 'Arthrodèse lombaire', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        // Colonnes du modèle (7 années) : aide L, opérateur T, A+O U.
        self::assertSame([self::B, self::A], array_column($this->lines($sheet), 'code'), 'La ligne du premier code reste la première');
        self::assertSame(['aide' => 0, 'operateur' => 2, 'total' => 2], $this->counters($sheet, self::B));
        self::assertSame(['aide' => 1, 'operateur' => 1, 'total' => 2], $this->counters($sheet, self::A));
        self::assertSame(4, $this->sectionTotal($sheet, 'U', 'Rachis'), 'Sous-total de la section');
    }

    public function testTypographyDoesNotSplitAndADifferentLabelDoes(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('ortho', '1', 'hip', "Fracture d'un os");
        $this->surgery($year, $act, 'ortho', self::A, "Fracture d'un os", '1');
        $this->surgery($year, $act, 'ortho', ' ' . strtolower(self::A) . ' ', "  fracture  d’un OS ", '1');
        $this->surgery($year, $act, 'ortho', self::A, 'Autre acte historique', '1');
        $this->em->flush();

        $lines = $this->lines($this->summary($user, $yearId));
        self::assertSame(["Fracture d'un os", 'Autre acte historique'], array_column($lines, 'name'));
        self::assertSame([2, 1], array_column($lines, 'total'));
    }

    public function testAnActKeepsItsSectionSoThatNoInterventionIsLostOrMoved(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('dig', null, null, 'Acte digestif');
        // La valeur historique « favorites » n'a pas de section propre : l'intervention reste comptée avec l'acte.
        $this->surgery($year, $act, 'dig', self::A, 'Acte digestif', '1');
        $this->surgery($year, $act, 'favorites', self::A, 'Acte digestif', '2');
        $this->surgery($year, $act, 'vasc', self::B, 'Acte digestif', '1');
        // Première intervention sous une valeur sans section : l'acte reste hors du récapitulatif, comme avant.
        $absent = $this->act('neuro', null, null, 'Acte absent');
        $this->surgery($year, $absent, 'favorites', self::A, 'Acte absent', '1');
        $this->surgery($year, $absent, 'neuro', self::A, 'Acte absent', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        $lines = $this->lines($sheet);
        self::assertSame([1, 1, 1], array_column($lines, 'total'), 'Trois lignes distinctes, une seule section');
        self::assertSame(3, array_sum(array_column($lines, 'total')));
        self::assertNotContains('Acte absent', array_column($lines, 'name'));
        self::assertSame(1, $this->sectionsWithLines($sheet));
    }

    /** ADR-214 : un acte 14 n) enregistré en neurochirurgie est présenté dans la rubrique Rachis, avec ses propres codes. */
    public function testSharedSpineActsRecordedInNeurosurgeryAreInTheSpineRubric(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('ortho', '1', 'back', 'Discectomie');
        $this->em->persist(new NomenclatureHistoricalSpeciality($act, 'neuro'));
        $this->surgery($year, $act, 'ortho', self::B, 'Discectomie', '1');
        $this->surgery($year, $act, 'neuro', self::B, 'Discectomie', '1');
        $this->surgery($year, $act, 'neuro', self::A, 'Discectomie', '2');
        $this->em->flush();
        $query = 'SELECT id, code, name, speciality, position, nomenclature_id FROM surgeries ORDER BY id';
        $before = $this->em->getConnection()->fetchAllAssociative($query);

        $sheet = $this->summary($user, $yearId);
        $lines = $this->lines($sheet);
        self::assertSame([self::B, self::B, self::A], array_column($lines, 'code'));
        self::assertSame(['Discectomie (Orthopédie)', 'Discectomie (Neurochirurgie)', 'Discectomie'], array_column($lines, 'name'), 'Mention seulement là où elle distingue deux lignes');
        self::assertSame(3, $this->sectionTotal($sheet, 'U', 'Rachis'));
        self::assertSame($before, $this->em->getConnection()->fetchAllAssociative($query), 'Spécialité et libellé enregistrés inchangés');
    }

    /** Traumatologie du rachis : l'acte reste dans le bloc de son type. */
    public function testSharedSpineTraumaActGoesToTheTraumaSpineRubric(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('ortho', '2', 'back', 'Fracture vertébrale');
        $this->em->persist(new NomenclatureHistoricalSpeciality($act, 'neuro'));
        $this->surgery($year, $act, 'neuro', self::A, 'Fracture vertébrale', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        self::assertSame(['Fracture vertébrale'], array_column($this->lines($sheet), 'name'), 'Sans ligne orthopédique : aucune mention');
        $row = $this->lines($sheet)[0]['row'];
        self::assertGreaterThan($this->rowOf($sheet, 'TOTAL GENERAL'), $row, 'Bloc traumatologie, après le total du bloc électif');
    }

    /** Un code 14 n) dont la région MedClick n'est pas le rachis : la neurochirurgie va en Rachis, l'orthopédie garde sa région. */
    public function testSharedActWithAnotherRegionKeepsOrthopaedicsWhereItWas(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('ortho', '1', 'wristhand', 'Drainage rachidien');
        $this->em->persist(new NomenclatureHistoricalSpeciality($act, 'neuro'));
        $this->surgery($year, $act, 'ortho', self::A, 'Autre libellé historique', '1');
        $this->surgery($year, $act, 'neuro', self::A, 'Drainage rachidien', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        self::assertSame(1, $this->sectionTotal($sheet, 'U', 'Poignet-Main'));
        self::assertSame(1, $this->sectionTotal($sheet, 'U', 'Rachis'));
    }

    /** L'exception est ciblée : le reste ne bouge pas. */
    public function testOtherActsStayWhereTheyWere(): void
    {
        [$user, $yearId, $year] = $this->year();
        $notShared = $this->act('ortho', '1', 'back', 'Rachis non partagé');
        $this->surgery($year, $notShared, 'neuro', self::A, 'Rachis non partagé', '1');
        $favorite = $this->act('ortho', '1', 'back', 'Rachis en favori');
        $this->em->persist(new NomenclatureHistoricalSpeciality($favorite, 'neuro'));
        $this->surgery($year, $favorite, 'favorites', self::B, 'Rachis en favori', '1');
        $brain = $this->act('neuro', null, null, 'Tumeur intracrânienne');
        $this->surgery($year, $brain, 'neuro', self::A, 'Tumeur intracrânienne', '1');
        $bowel = $this->act('dig', null, null, 'Colectomie');
        $this->surgery($year, $bowel, 'dig', self::B, 'Colectomie', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        // Sections générales remplies dans l'ordre (écart H1 connu) : digestif, puis neurochirurgie.
        self::assertSame(['Colectomie', 'Tumeur intracrânienne'], array_column($this->lines($sheet), 'name'));
        self::assertGreaterThan($this->rowOf($sheet, 'TOTAL GENERAL', 2), $this->lines($sheet)[0]['row']);
    }

    /** Seule la spécialité distingue les lignes d'un acte, hors rachis : même mention. */
    public function testSpecialityMentionOnlyWhenItDistinguishesTwoLines(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('dig', null, null, 'Hernie');
        $this->surgery($year, $act, 'dig', self::A, 'Hernie', '1');
        $this->surgery($year, $act, 'general', self::A, 'Hernie', '2');
        $this->surgery($year, $act, 'general', self::B, 'Hernie', '2');
        $this->em->flush();

        self::assertSame(['Hernie (Chirurgie digestive)', 'Hernie (Chirurgie générale)', 'Hernie'], array_column($this->lines($this->summary($user, $yearId)), 'name'));
    }

    public function testExportingChangesNothingInTheInterventions(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('ortho', '1', 'hip', 'Prothèse');
        $this->surgery($year, $act, 'ortho', self::A, 'Prothèse', '1');
        $this->surgery($year, $act, 'ortho', self::B, 'Prothèse', '2');
        $this->em->flush();
        $query = 'SELECT id, code, name, speciality, position, nomenclature_id FROM surgeries ORDER BY id';
        $before = $this->em->getConnection()->fetchAllAssociative($query);

        $this->summary($user, $yearId);

        self::assertSame($before, $this->em->getConnection()->fetchAllAssociative($query));
    }

    /** @return array{0: \App\Entity\User, 1: int, 2: Years} */
    private function year(): array
    {
        [$user, $yearId] = $this->seed('vide');

        return [$user, $yearId, $this->em->find(Years::class, $yearId)];
    }

    private function act(string $speciality, ?string $type, ?string $subType, string $name): Nomenclature
    {
        static $i = 0;
        $i++;
        $act = (new Nomenclature())->setSpeciality($speciality)->setName($name)->setType($type)->setSubType($subType)
            ->setCodeHospitalisation((string) (400000 + $i))->setN('N1');
        $this->em->persist($act);

        return $act;
    }

    private function surgery(Years $year, Nomenclature $act, string $speciality, string $code, string $name, string $position): void
    {
        $this->em->persist((new Surgeries())->setYear($year)->setDate(new \DateTime('2025-11-02'))->setPosition($position)
            ->setSpeciality($speciality)->setName($name)->setCode($code)->setNomenclature($act));
    }

    private function summary($user, int $yearId): Worksheet
    {
        $result = $this->runExport($user, $yearId);
        $path = tempnam(sys_get_temp_dir(), 'recap') . '.xlsx';
        file_put_contents($path, $result['bytes']);
        $book = IOFactory::load($path);
        @unlink($path);

        return $book->getSheet(2);
    }

    /** @return list<array{code: string, name: string, total: int, row: int}> */
    private function lines(Worksheet $sheet): array
    {
        $lines = [];
        for ($row = 16, $max = $sheet->getHighestRow(); $row <= $max; $row++) {
            $name = trim((string) $sheet->getCell("B$row")->getValue());
            if ($name !== '' && is_numeric($sheet->getCell("U$row")->getCalculatedValue())) {
                $lines[] = ['code' => trim((string) $sheet->getCell("A$row")->getValue()), 'name' => $name, 'total' => (int) $sheet->getCell("U$row")->getCalculatedValue(), 'row' => $row];
            }
        }

        return $lines;
    }

    private function counters(Worksheet $sheet, string $code): array
    {
        foreach ($this->lines($sheet) as $line) {
            if ($line['code'] === $code) {
                $r = $line['row'];

                return ['aide' => (int) $sheet->getCell("L$r")->getCalculatedValue(), 'operateur' => (int) $sheet->getCell("T$r")->getCalculatedValue(), 'total' => (int) $sheet->getCell("U$r")->getCalculatedValue()];
            }
        }
        self::fail("Code absent du récapitulatif : $code");
    }

    /** Valeur calculée du sous-total de la section (formule du modèle, plage étendue par les insertions). */
    private function sectionTotal(Worksheet $sheet, string $column, string $title): int
    {
        for ($row = 14, $max = $sheet->getHighestRow(), $in = false; $row <= $max; $row++) {
            $a = trim((string) $sheet->getCell("A$row")->getValue());
            $in = $in || mb_strtolower($a) === mb_strtolower($title);
            if ($in && $a === 'Sous total') {
                return (int) $sheet->getCell("$column$row")->getCalculatedValue();
            }
        }
        self::fail("Section absente : $title");
    }

    private function rowOf(Worksheet $sheet, string $text, int $occurrence = 1): int
    {
        for ($row = 14, $max = $sheet->getHighestRow(); $row <= $max; $row++) {
            if (trim((string) $sheet->getCell("A$row")->getValue()) === $text && --$occurrence === 0) {
                return $row;
            }
        }
        self::fail("Absent : $text");
    }

    private function sectionsWithLines(Worksheet $sheet): int
    {
        $rows = array_column($this->lines($sheet), 'row');

        return $rows === [] ? 0 : (int) (max($rows) - min($rows) === count($rows) - 1);
    }
}
