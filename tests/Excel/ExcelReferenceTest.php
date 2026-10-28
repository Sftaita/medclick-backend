<?php

namespace App\Tests\Excel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;

/**
 * Non-régression STRICTE du carnet de stage : le fichier produit doit rester identique aux fichiers
 * de référence figés, vérifié par XlsxComparator (parties du paquet XLSX canonisées + contenu,
 * styles, fusions, dimensions, impression… cellule par cellule).
 *
 * Références (format officiel du carnet, validé en préproduction) :
 * - tests/Fixtures/excel-reference : scénarios fonctionnels, tirages aléatoires et cas de l'audit
 *   des totaux (données fictives, versionnées ; manifest.json : empreinte, date d'édition, origine) ;
 * - var/excel-reference : volumes jusqu'à 10 000 interventions (non versionnés, trop lourds ;
 *   test ignoré s'ils sont absents).
 * Un fichier de référence ne se régénère pas : une différence est une régression, ou un changement
 * de format décidé explicitement (nouvelles références et nouvelle validation).
 */
final class ExcelReferenceTest extends ExcelTestCase
{
    public static function scenarios(): iterable
    {
        foreach (ExportDatasetFactory::SCENARIOS as $scenario) {
            yield $scenario => [$scenario];
        }
        foreach (range(1, 6) as $seed) {
            yield "aleatoire-$seed" => ["aleatoire-$seed"];
        }
        // Cas particuliers de l'audit des totaux (tests/Excel/Audit).
        foreach (ExportDatasetFactory::AUDIT as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('scenarios')]
    public function testExportMatchesReferenceFile(string $scenario): void
    {
        $this->assertMatchesReference($scenario, self::REFERENCE_DIR);
    }

    public static function volumes(): iterable
    {
        foreach (ExportDatasetFactory::VOLUMES as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    /** Volumes (jusqu'à 10 000 interventions) : php vendor/bin/phpunit --group excel-volume */
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
            self::markTestSkipped("Référence absente : $reference");
        }
        $manifest = json_decode(file_get_contents("$dir/manifest.json"), true)[$scenario];
        self::assertSame($manifest['sha256'], hash_file('sha256', $reference), 'Fichier de référence modifié depuis sa génération');

        // Date d'édition écrite dans le carnet (H30) = jour de génération de la référence.
        $clock = new MockClock(\DateTimeImmutable::createFromFormat('!d-m-Y', $manifest['exportDate']));
        [$user, $yearId] = $this->seed($scenario);
        $result = $this->runExport($user, $yearId, clock: $clock);

        $report = $this->compareBytes(file_get_contents($reference), $result['bytes']);
        self::assertEquivalent($report, $scenario);
        fwrite(STDERR, sprintf("\n[%s] %.2f s, %.1f Mo, %d cellules, %d/%d parties identiques à l'octet",
            $scenario, $result['seconds'], $result['peakMemory'] / 1048576, $report['stats']['cellules'],
            $report['stats']['partiesIdentiquesOctet'], $report['stats']['parties']));
    }
}
