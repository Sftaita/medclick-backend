<?php

namespace App\Tests\Excel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Génère les fichiers Excel de RÉFÉRENCE avec le moteur figé (oracle), avant toute refactorisation.
 * Exclu de la suite par défaut (phpunit.xml.dist) ; lancement explicite :
 *   php vendor/bin/phpunit --group excel-reference-generation
 * Scénarios fonctionnels → tests/Fixtures/excel-reference/ (versionnés, données fictives) ;
 * volumes → var/excel-reference/ (non versionnés, trop lourds).
 * Le manifeste note la date du jour écrite par le moteur (cellule H30 du carnet de stage).
 */
#[Group('excel-reference-generation')]
final class ReferenceGenerationTest extends ExcelTestCase
{
    public static function scenarios(): iterable
    {
        // EXCEL_SCENARIOS=petit,vide pour restreindre (sinon : tous).
        $only = array_filter(explode(',', (string) getenv('EXCEL_SCENARIOS')));
        foreach ([...ExportDatasetFactory::SCENARIOS, ...ExportDatasetFactory::VOLUMES] as $scenario) {
            if ($only && !in_array($scenario, $only, true)) {
                continue;
            }
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('scenarios')]
    public function testGenerateReference(string $scenario): void
    {
        [$user, $yearId] = $this->seed($scenario);
        $result = $this->runLegacy($user, $yearId);

        $volume = in_array($scenario, ExportDatasetFactory::VOLUMES, true);
        $dir = $volume ? dirname(__DIR__, 2) . '/var/excel-reference' : self::REFERENCE_DIR;
        @mkdir($dir, 0777, true);
        file_put_contents("$dir/$scenario.xlsx", $result['bytes']);

        $manifestFile = "$dir/manifest.json";
        $manifest = is_file($manifestFile) ? json_decode(file_get_contents($manifestFile), true) : [];
        $manifest[$scenario] = [
            'generatedAt' => date(DATE_ATOM),
            'engine' => 'ExcelNewVersion@b659514 (copie figée tests/Excel/Legacy)',
            'exportDate' => date('d-m-Y'),
            'sha256' => hash('sha256', $result['bytes']),
            'bytes' => strlen($result['bytes']),
            'seconds' => round($result['seconds'], 3),
            'peakMemoryMb' => round($result['peakMemory'] / 1048576, 1),
            'warnings' => $result['warnings'],
        ];
        ksort($manifest);
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        self::assertGreaterThan(1000, strlen($result['bytes']));
    }
}
