<?php

/*
 * Compare deux fichiers XLSX (structure + sémantique) et affiche le rapport.
 * Usage : php tests/Excel/bin/compare-xlsx.php <attendu.xlsx> <obtenu.xlsx>
 * Code de sortie : 0 si équivalents, 1 sinon.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $expected, $actual] = $argv + [null, null, null];
if (!$expected || !$actual) {
    fwrite(STDERR, "Usage : php tests/Excel/bin/compare-xlsx.php <attendu.xlsx> <obtenu.xlsx>\n");
    exit(2);
}

$report = (new App\Tests\Excel\XlsxComparator())->compare($expected, $actual);
echo ($report['equivalent'] ? 'ÉQUIVALENTS' : 'DIFFÉRENTS') . "\n";
foreach ($report['stats'] as $key => $value) {
    echo "  $key : $value\n";
}
foreach ($report['toleratedVolatile'] as $line) {
    echo "  toléré (métadonnée volatile) : $line\n";
}
foreach ($report['differences'] as $line) {
    echo "  ✗ $line\n";
}
exit($report['equivalent'] ? 0 : 1);
