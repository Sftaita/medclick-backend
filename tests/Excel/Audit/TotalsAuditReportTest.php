<?php

namespace App\Tests\Excel\Audit;

use App\Tests\Excel\ExcelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exploration : imprime la synthèse de l'audit des totaux pour un scénario (développement).
 *   EXCEL_SCENARIOS=petit,consultations-denses php vendor/bin/phpunit --group excel-audit-report
 */
#[Group('excel-audit-report')]
final class TotalsAuditReportTest extends ExcelTestCase
{
    public static function scenarios(): iterable
    {
        // Sans EXCEL_SCENARIOS : le cas de référence (un fournisseur vide est une erreur PHPUnit, même groupe exclu).
        foreach (array_filter(explode(',', (string) getenv('EXCEL_SCENARIOS'))) ?: ['audit-reference'] as $s) {
            yield $s => [$s];
        }
    }

    #[DataProvider('scenarios')]
    public function testReport(string $scenario): void
    {
        [$user, $yearId] = $this->seed($scenario);
        $result = $this->runExport($user, $yearId);
        $path = tempnam(sys_get_temp_dir(), 'audit') . '.xlsx';
        file_put_contents($path, $result['bytes']);
        $findings = (new CarnetTotalsAuditor())->audit($this->em->getConnection(), $user->getId(), $yearId, $path);
        fwrite(STDERR, "\n=== $scenario\n");
        foreach (CarnetTotalsAuditor::summarize($findings) as $rule => $s) {
            fwrite(STDERR, sprintf("%-3s %5d contrôles %4d écarts %s\n", $rule, $s['checks'], $s['failures'], $s['first'] ? mb_substr($s['first'], 0, 400) : ''));
        }
        // EXCEL_DETAIL=R3,R4 : tous les écarts de ces règles.
        $detail = array_filter(explode(',', (string) getenv('EXCEL_DETAIL')));
        foreach ($findings as $f) {
            if (!$f['ok'] && in_array($f['rule'], $detail, true)) {
                fwrite(STDERR, sprintf("  ✗ %s %s : attendu %s, obtenu %s\n", $f['rule'], $f['where'],
                    json_encode($f['expected'], JSON_UNESCAPED_UNICODE), json_encode($f['actual'], JSON_UNESCAPED_UNICODE)));
            }
        }
        @unlink($path);
        self::assertNotEmpty($findings);
    }
}
