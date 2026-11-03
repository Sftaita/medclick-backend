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

    public function testNeuroInterventionsOfAnOrthoActKeepTheirClassificationAndTheirOwnCodes(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('ortho', '1', 'back', 'Discectomie');
        $this->em->persist((new NomenclatureHistoricalSpeciality($act, 'neuro')));
        $this->surgery($year, $act, 'ortho', self::B, 'Discectomie', '1');
        $this->surgery($year, $act, 'neuro', self::B, 'Discectomie', '1');
        $this->surgery($year, $act, 'neuro', self::A, 'Discectomie', '2');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        $lines = $this->lines($sheet);
        self::assertSame([self::B, self::B, self::A], array_column($lines, 'code'), 'Rachis orthopédique, puis les deux codes en neurochirurgie');
        self::assertSame(1, $this->sectionTotal($sheet, 'U', 'Rachis'));
        self::assertSame(3, array_sum(array_column($lines, 'total')));
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

    private function sectionsWithLines(Worksheet $sheet): int
    {
        $rows = array_column($this->lines($sheet), 'row');

        return $rows === [] ? 0 : (int) (max($rows) - min($rows) === count($rows) - 1);
    }
}
