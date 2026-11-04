<?php

namespace App\Tests\Excel;

use App\Entity\Nomenclature;
use App\Entity\NomenclatureHistoricalSpeciality;
use App\Entity\Surgeries;
use App\Entity\Years;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ADR-215 : rubriques de chirurgie générale (H1), TOTAL GENERAL (H2) et sous-totaux des années 6 et 7 (H3).
 */
final class RecapitulatifRubriquesGeneralesTest extends ExcelTestCase
{
    private const TITLES = ['dig' => 'DIGESTIVE', 'general' => 'GENERAL', 'uro' => 'UROLOGIE', 'vasc' => 'VASCULAIRE', 'thor' => 'THORACIQUE', 'plastic' => 'PLASTIQUE', 'neuro' => 'NEUROLOGIE', 'transp' => 'TRANSPLANTATION'];

    public static function specialities(): iterable
    {
        foreach (self::TITLES as $speciality => $title) {
            yield $title => [$speciality, $title];
        }
    }

    /** Une seule spécialité présente : elle reste sous son titre, quelle que soit sa place. */
    #[DataProvider('specialities')]
    public function testASpecialityAloneStaysUnderItsOwnTitle(string $speciality, string $title): void
    {
        [$user, $yearId, $year] = $this->year();
        $this->surgery($year, $this->act($speciality, 'Acte'), $speciality, 'Acte', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        self::assertSame([$title => ['Acte']], $this->generalSections($sheet));
    }

    /** Rubriques vides intercalées : aucun décalage. */
    public function testEmptySpecialitiesDoNotShiftTheNextOnes(): void
    {
        [$user, $yearId, $year] = $this->year();
        foreach (['transp', 'vasc', 'plastic', 'neuro'] as $speciality) {
            $this->surgery($year, $this->act($speciality, "Acte $speciality"), $speciality, "Acte $speciality", '1');
            $this->surgery($year, $this->act($speciality, "Autre $speciality"), $speciality, "Autre $speciality", '2');
        }
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        self::assertSame([
            'VASCULAIRE' => ['Acte vasc', 'Autre vasc'],
            'PLASTIQUE' => ['Acte plastic', 'Autre plastic'],
            'NEUROLOGIE' => ['Acte neuro', 'Autre neuro'],
            'TRANSPLANTATION' => ['Acte transp', 'Autre transp'],
        ], $this->generalSections($sheet));
        self::assertSame(2, $this->subtotal($sheet, 'VASCULAIRE', 'U'));
        self::assertSame(2, $this->subtotal($sheet, 'TRANSPLANTATION', 'U'));
        self::assertSame(0, $this->subtotal($sheet, 'DIGESTIVE', 'U'));
        self::assertSame(8, $this->generalTotal($sheet, 'U'));
    }

    /** Les huit rubriques, une aide chacune dans l'année exportée : le total les compte toutes (urologie, neurologie, thoracique comprises). */
    public function testGeneralTotalCountsEverySection(): void
    {
        [$user, $yearId, $year] = $this->year();
        foreach (array_keys(self::TITLES) as $speciality) {
            $this->surgery($year, $this->act($speciality, "Acte $speciality"), $speciality, "Acte $speciality", '2');
        }
        $this->em->flush();
        $column = chr(ord('E') + $year->getYearOfFormation() - 1);

        $sheet = $this->summary($user, $yearId);
        self::assertSame(8, $this->generalTotal($sheet, $column));
        self::assertSame(8, $this->generalTotal($sheet, 'L'));
    }

    /** Toutes les colonnes d'années (assistant et opérateur) référencent les huit sous-totaux. */
    public function testEveryYearColumnReferencesTheEightSubtotals(): void
    {
        [$user, $yearId, $year] = $this->year();
        $this->surgery($year, $this->act('uro', 'Acte'), 'uro', 'Acte', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        [$subtotals, $total] = $this->generalRows($sheet);
        self::assertCount(8, $subtotals);
        foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'] as $column) {
            preg_match_all('/' . $column . '(\d+)/', (string) $sheet->getCell("$column$total")->getValue(), $m);
            self::assertSame([], array_values(array_diff($subtotals, array_map('intval', $m[1]))), "Colonne $column");
        }
    }

    /** H3 : chaque sous-total de chaque année additionne les lignes de sa rubrique, dans sa colonne. */
    public function testEverySubtotalSumsItsOwnLines(): void
    {
        [$user, $yearId, $year] = $this->year();
        $this->surgery($year, $this->act('uro', 'Acte'), 'uro', 'Acte', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        foreach ($this->generalRows($sheet)[0] as $row) {
            foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K'] as $column) {
                self::assertMatchesRegularExpression('/^=SUM\\(' . $column . '\\d+:' . $column . '\\d+\\)$/', (string) $sheet->getCell("$column$row")->getValue(), "$column$row");
            }
        }
    }

    /** H3 : une aide digestive par année : chaque colonne vaut 1, le total de la rubrique 7. */
    public function testYearsSixAndSevenAreNoLongerInflated(): void
    {
        [$user, $yearId] = $this->seed('audit-annees-6-7');

        $sheet = $this->summary($user, $yearId);
        $row = $this->generalRows($sheet)[0][0];
        $total = $this->generalRows($sheet)[1];
        foreach (['E', 'F', 'G', 'H', 'I', 'J', 'K'] as $column) {
            self::assertSame(1, (int) $sheet->getCell("$column$row")->getCalculatedValue(), "Sous-total $column");
            self::assertSame(1, (int) $sheet->getCell("$column$total")->getCalculatedValue(), "TOTAL GENERAL $column");
        }
        self::assertSame(7, (int) $sheet->getCell("L$row")->getCalculatedValue());
        self::assertSame(7, (int) $sheet->getCell("L$total")->getCalculatedValue());
        self::assertSame(7, (int) $sheet->getCell("U$total")->getCalculatedValue());
    }

    /** ADR-214 : le rachis 14 n) en neurochirurgie reste sous Rachis ; l'intracrânien sous Neurologie, le digestif sous Digestive. */
    public function testSharedSpineStaysInTheSpineRubric(): void
    {
        [$user, $yearId, $year] = $this->year();
        $spine = (new Nomenclature())->setSpeciality('ortho')->setName('Discectomie')->setType('1')->setSubType('back')->setCodeHospitalisation('410000')->setN('K1');
        $this->em->persist($spine);
        $this->em->persist(new NomenclatureHistoricalSpeciality($spine, 'neuro'));
        $this->surgery($year, $spine, 'neuro', 'Discectomie', '1');
        $this->surgery($year, $spine, 'ortho', 'Discectomie', '1');
        $this->surgery($year, $this->act('neuro', 'Tumeur intracrânienne'), 'neuro', 'Tumeur intracrânienne', '1');
        $this->surgery($year, $this->act('dig', 'Colectomie'), 'dig', 'Colectomie', '2');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        self::assertSame(['DIGESTIVE' => ['Colectomie'], 'NEUROLOGIE' => ['Tumeur intracrânienne']], $this->generalSections($sheet));
        self::assertSame(2, $this->sectionSubtotalBeforeFirstTotal($sheet, 'Rachis'), 'Rachis : orthopédie et neurochirurgie');
        self::assertSame(2, $this->generalTotal($sheet, 'U'));
    }

    /** Acte à plusieurs spécialités : chaque ligne sous sa rubrique ; une valeur hors liste reste avec l'acte ; un acte sans section reste absent. */
    public function testAMixedActGoesUnderEachRecordedSpeciality(): void
    {
        [$user, $yearId, $year] = $this->year();
        $act = $this->act('dig', 'Hernie');
        $this->surgery($year, $act, 'dig', 'Hernie', '1');
        $this->surgery($year, $act, 'vasc', 'Hernie', '1');
        $this->surgery($year, $act, 'favorites', 'Hernie', '1');
        $absent = $this->act('uro', 'Absent');
        $this->surgery($year, $absent, 'favorites', 'Absent', '1');
        $this->surgery($year, $absent, 'uro', 'Absent', '1');
        $this->em->flush();

        $sheet = $this->summary($user, $yearId);
        self::assertSame(['DIGESTIVE' => ['Hernie (Chirurgie digestive)', 'Hernie (Favoris)'], 'VASCULAIRE' => ['Hernie']], $this->generalSections($sheet));
        self::assertSame(3, $this->generalTotal($sheet, 'U'));
    }

    /** H10 inchangé : sans nomenclature, tout reste dans la section de la première intervention. */
    public function testInterventionsWithoutNomenclatureStayTogether(): void
    {
        [$user, $yearId, $year] = $this->year();
        $this->surgery($year, null, 'vasc', 'Sans lien', '1');
        $this->surgery($year, null, 'uro', 'Sans lien autre', '1');
        $this->em->flush();

        self::assertSame(['VASCULAIRE' => ['Sans lien', 'Sans lien autre']], $this->generalSections($this->summary($user, $yearId)));
    }

    public function testExportingChangesNoIntervention(): void
    {
        [$user, $yearId, $year] = $this->year();
        $this->surgery($year, $this->act('thor', 'Lobectomie'), 'thor', 'Lobectomie', '2');
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

    private function act(string $speciality, string $name): Nomenclature
    {
        static $i = 0;
        $i++;
        $act = (new Nomenclature())->setSpeciality($speciality)->setName($name)->setCodeHospitalisation((string) (500000 + $i))->setN('N1');
        $this->em->persist($act);

        return $act;
    }

    private function surgery(Years $year, ?Nomenclature $act, string $speciality, string $name, string $position): void
    {
        $surgery = (new Surgeries())->setYear($year)->setDate(new \DateTime('2025-11-02'))->setPosition($position)
            ->setSpeciality($speciality)->setName($name)->setCode('C' . crc32($name));
        if ($act !== null) {
            $surgery->setNomenclature($act);
        }
        $this->em->persist($surgery);
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

    /** @return array{0: list<int>, 1: int} sous-totaux et ligne du TOTAL GENERAL de chirurgie générale */
    private function generalRows(Worksheet $sheet): array
    {
        $block = 0;
        $subtotals = [];
        for ($row = 14, $max = $sheet->getHighestRow(); $row <= $max; $row++) {
            $a = trim((string) $sheet->getCell("A$row")->getValue());
            if ($a === 'Sous total' && $block === 2) {
                $subtotals[] = $row;
            } elseif ($a === 'TOTAL GENERAL' && ++$block === 3) {
                return [$subtotals, $row];
            }
        }
        self::fail('TOTAL GENERAL de chirurgie générale absent');
    }

    /** @return array<string, list<string>> rubrique => libellés, rubriques non vides de chirurgie générale */
    private function generalSections(Worksheet $sheet): array
    {
        [, $total] = $this->generalRows($sheet);
        $sections = [];
        $title = null;
        for ($row = $total; $row >= 14 && $title !== 'DIGESTIVE'; $row--) {
            $a = trim((string) $sheet->getCell("A$row")->getValue());
            $b = trim((string) $sheet->getCell("B$row")->getValue());
            if ($b !== '') {
                $pending[] = $b;
            } elseif (isset(array_flip(self::TITLES)[$a])) {
                $title = $a;
                if (!empty($pending)) {
                    $sections[$a] = array_reverse($pending);
                }
                $pending = [];
            }
        }

        return array_reverse($sections, true);
    }

    private function subtotal(Worksheet $sheet, string $title, string $column): int
    {
        [$subtotals] = $this->generalRows($sheet);

        return (int) $sheet->getCell($column . $subtotals[array_search($title, array_values(self::TITLES), true)])->getCalculatedValue();
    }

    private function generalTotal(Worksheet $sheet, string $column): int
    {
        return (int) $sheet->getCell($column . $this->generalRows($sheet)[1])->getCalculatedValue();
    }

    private function sectionSubtotalBeforeFirstTotal(Worksheet $sheet, string $title): int
    {
        for ($row = 14, $in = false; ; $row++) {
            $a = trim((string) $sheet->getCell("A$row")->getValue());
            $in = $in || mb_strtolower($a) === mb_strtolower($title);
            if ($in && $a === 'Sous total') {
                return (int) $sheet->getCell("U$row")->getCalculatedValue();
            }
        }
    }
}
