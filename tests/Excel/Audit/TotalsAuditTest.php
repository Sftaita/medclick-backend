<?php

namespace App\Tests\Excel\Audit;

use App\Tests\Excel\ExcelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Audit automatique des totaux du carnet de stage (CarnetTotalsAuditor : recalcul indépendant à
 * partir des données source, contrôle des valeurs, des formules et de leurs plages).
 *
 * Le fichier doit rester identique à l'ancien moteur : les écarts historiques découverts ne sont
 * PAS corrigés (décision en attente, voir docs/AUDIT-TOTAUX-EXCEL.md). Ce test fige donc l'état
 * audité : toute règle respectée doit le rester, et chaque écart connu doit garder exactement la
 * même ampleur. Un nouvel écart, ou la correction d'un écart connu, fait échouer le test.
 */
final class TotalsAuditTest extends ExcelTestCase
{
    /**
     * Écarts connus par scénario (règle => nombre de contrôles en échec), avec leur référence
     * dans docs/AUDIT-TOTAUX-EXCEL.md. Toutes les autres règles : 0 écart.
     */
    private const KNOWN = [
        // H2 (TOTAL GENERAL de chirurgie générale) et H3 (sous-totaux années 6-7) sont présents dans
        // tout fichier : ce sont des formules du gabarit.
        'audit-reference' => ['R3' => 16, 'R4' => 5],
        'audit-trente-superviseurs' => ['R3' => 16, 'R4' => 5],
        // H9 : deux maîtres de stage.
        'audit-deux-maitres' => ['C1' => 1, 'C3' => 4, 'C4' => 3, 'C5' => 1, 'C6' => 3, 'C7' => 1, 'C8' => 2, 'C9' => 2, 'R3' => 16, 'R4' => 5],
        // H1 : spécialités générales décalées.
        'audit-specialites-generales' => ['R1' => 9, 'R3' => 16, 'R4' => 5],
        // H3 avec des valeurs (années 6-7 de chirurgie générale).
        'audit-annees-6-7' => ['R3' => 18, 'R4' => 5],
        // H4 : plus de 7 années.
        'audit-neuf-annees' => ['R3' => 322, 'R4' => 29],
        // H10 / H11 : sans nomenclature, données de référence hors liste.
        'audit-sans-nomenclature' => ['R1' => 3, 'R3' => 16, 'R4' => 5, 'R5' => 4],
        // H5 (plus de 9 dates de consultation dans un mois) ; H13 (#DIV/0! sans intervention) ; H15 (type « plastic »).
        'audit-consultations-10-dates' => ['C6' => 4, 'K1' => 1, 'K2' => 1, 'K3' => 1, 'K4' => 2, 'R3' => 16, 'R4' => 5],
        // H6 : plus de 9 gardes dans un mois.
        'audit-gardes-10-par-mois' => ['C6' => 4, 'G2' => 2, 'G3' => 1, 'R3' => 16, 'R4' => 5],
        // H7 : garde finissant à minuit.
        'audit-garde-minuit' => ['A3' => 1, 'A5' => 1, 'C6' => 4, 'R3' => 16, 'R4' => 5],
        // H8 : évènement à cheval sur le nouvel an.
        'audit-nouvel-an' => ['A3' => 2, 'A5' => 1, 'C6' => 4, 'R3' => 16, 'R4' => 5],
        // H12 : positions hors 1, 2, 3.
        'audit-positions' => ['C5' => 1, 'R3' => 16, 'R4' => 5, 'R5' => 3],
    ];

    public static function scenarios(): iterable
    {
        foreach (array_keys(self::KNOWN) as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('scenarios')]
    public function testTotalsMatchTheAuditedState(string $scenario): void
    {
        [$user, $yearId] = $this->seed($scenario);
        $result = $this->runNew($user, $yearId);
        $path = tempnam(sys_get_temp_dir(), 'audit') . '.xlsx';
        file_put_contents($path, $result['bytes']);
        try {
            $findings = (new CarnetTotalsAuditor())->audit($this->em->getConnection(), $user->getId(), $yearId, $path);
        } finally {
            @unlink($path);
        }

        $failures = [];
        $details = [];
        foreach ($findings as $f) {
            if (!$f['ok']) {
                $failures[$f['rule']] = ($failures[$f['rule']] ?? 0) + 1;
                $details[] = "{$f['rule']} {$f['where']}";
            }
        }
        ksort($failures);
        $known = self::KNOWN[$scenario];
        ksort($known);

        self::assertSame($known, $failures, "Écarts audités modifiés ($scenario) :\n" . implode("\n", array_slice($details, 0, 40)));
        // Chaque règle a bien été contrôlée (aucune règle silencieusement ignorée).
        self::assertGreaterThan(200, count($findings));
    }

    /** Le cas de référence calculé à la main : tous les totaux du carnet et de l'orthopédie sont exacts. */
    public function testHandComputedReferenceCase(): void
    {
        [$user, $yearId] = $this->seed('audit-reference');
        $result = $this->runNew($user, $yearId);
        $path = tempnam(sys_get_temp_dir(), 'audit') . '.xlsx';
        file_put_contents($path, $result['bytes']);
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        @unlink($path);

        // Carnet : 6 interventions (2 aides, 2 premières mains aidées, 2 premières mains).
        $logbook = $book->getSheet(1);
        $totals = null;
        foreach ($logbook->getRowIterator() as $row) {
            if ($logbook->getCell('C' . $row->getRowIndex())->getValue() === 'Nbre total des interventions') {
                $totals = $row->getRowIndex() - 1;
            }
        }
        self::assertSame([2, 0, 2, 2, 6], [
            $logbook->getCell("I$totals")->getCalculatedValue(), $logbook->getCell("J$totals")->getCalculatedValue(),
            $logbook->getCell("L$totals")->getCalculatedValue(), $logbook->getCell("M$totals")->getCalculatedValue(),
            $logbook->getCell('H' . ($totals + 1))->getCalculatedValue(),
        ]);
        // Liste récapitulative : hanche élective (1 aide, 2 opérateur+aîné, 1 opérateur seul) et genou trauma.
        $summary = $book->getSheet(2);
        $rows = [];
        foreach ($summary->getRowIterator() as $row) {
            $r = $row->getRowIndex();
            if (in_array((string) $summary->getCell("B$r")->getValue(), ['  Acte ortho 1', '  Acte ortho 2'], true)) {
                $rows[] = [trim($summary->getCell("B$r")->getValue()), $summary->getCell("L$r")->getValue(), $summary->getCell("T$r")->getValue(),
                    $summary->getCell("U$r")->getValue(), $summary->getCell("V$r")->getValue()];
            }
        }
        self::assertSame([['Acte ortho 1', 1, 3, 4, '75%'], ['Acte ortho 2', 1, 1, 2, '50%']], $rows);
        $book->disconnectWorksheets();
    }
}
