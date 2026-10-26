<?php

namespace App\Tests\Excel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * Équivalence STRICTE du fichier Excel entre l'ancien moteur (copie figée, tests/Excel/Legacy) et
 * le nouveau (App\Export\CarnetDeStage), vérifiée par XlsxComparator (parties du paquet XLSX
 * canonisées + contenu, styles, fusions, dimensions, impression… cellule par cellule).
 *
 * Trois sources de vérité :
 * - l'oracle exécuté à côté du nouveau moteur sur les mêmes données (scénarios et tirages aléatoires) ;
 * - les fichiers de référence produits AVANT la refactorisation (tests/Fixtures/excel-reference) ;
 * - pour les volumes (groupe excel-volume), les références de var/excel-reference (voir ReferenceGenerationTest).
 */
final class ExcelEquivalenceTest extends ExcelTestCase
{
    public static function scenarios(): iterable
    {
        foreach (ExportDatasetFactory::SCENARIOS as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    public static function randomScenarios(): iterable
    {
        foreach (range(1, 6) as $seed) {
            yield "aleatoire-$seed" => ["aleatoire-$seed"];
        }
    }

    #[DataProvider('scenarios')]
    #[DataProvider('randomScenarios')]
    public function testNewEngineMatchesLegacyEngine(string $scenario): void
    {
        [$user, $yearId] = $this->seed($scenario);

        $legacy = $this->runLegacy($user, $yearId);
        $new = $this->runNew($user, $yearId);

        self::assertEquivalent($this->compareBytes($legacy['bytes'], $new['bytes']), $scenario);
    }

    #[DataProvider('scenarios')]
    public function testNewEngineMatchesReferenceFile(string $scenario): void
    {
        $this->assertMatchesReference($scenario, self::REFERENCE_DIR);
    }

    public static function volumes(): iterable
    {
        foreach (ExportDatasetFactory::VOLUMES as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    /**
     * Volumes (jusqu'à 10 000 interventions) contre les références de l'ancien moteur, générées une
     * fois (plusieurs minutes chacune avec l'ancien moteur) : php vendor/bin/phpunit --group excel-volume
     */
    #[Group('excel-volume')]
    #[DataProvider('volumes')]
    public function testVolumeMatchesReferenceFile(string $scenario): void
    {
        $this->assertMatchesReference($scenario, dirname(__DIR__, 2) . '/var/excel-reference');
    }

    private function assertMatchesReference(string $scenario, string $dir): void
    {
        $reference = "$dir/$scenario.xlsx";
        if (!is_file($reference)) {
            self::markTestSkipped("Référence absente : $reference (voir ReferenceGenerationTest)");
        }
        $manifest = json_decode(file_get_contents("$dir/manifest.json"), true)[$scenario];
        self::assertSame($manifest['sha256'], hash_file('sha256', $reference), 'Fichier de référence modifié depuis sa génération');

        // Date d'édition écrite par le moteur (H30 du carnet de stage) = jour de génération de la référence.
        $clock = new MockClock(\DateTimeImmutable::createFromFormat('!d-m-Y', $manifest['exportDate']));
        [$user, $yearId] = $this->seed($scenario);
        $new = $this->runNew($user, $yearId, clock: $clock);

        $report = $this->compareBytes(file_get_contents($reference), $new['bytes']);
        self::assertEquivalent($report, $scenario);
        fwrite(STDERR, sprintf("\n[%s] %.2f s, %.1f Mo, %d cellules, %d/%d parties identiques à l'octet",
            $scenario, $new['seconds'], $new['peakMemory'] / 1048576, $report['stats']['cellules'],
            $report['stats']['partiesIdentiquesOctet'], $report['stats']['parties']));
    }
}
