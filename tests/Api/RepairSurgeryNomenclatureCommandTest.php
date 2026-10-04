<?php

namespace App\Tests\Api;

use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use App\Tests\ApiTestBase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * LOT 2D.2 — app:repair-surgery-nomenclature : seules les interventions A1 (incohérence certaine,
 * nomenclature cible unique) sont corrigées ; dry-run par défaut ; snapshot et rollback exploitables.
 */
class RepairSurgeryNomenclatureCommandTest extends ApiTestBase
{
    private User $alice;
    private Years $year;
    private Nomenclature $hanche;      // SOURCE habituelle : ortho, code 2890851
    private Nomenclature $appendice;   // TARGET : dig, code 2414152
    private string $snapshotDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = $this->createUser('alice@test.be');
        $this->year = $this->createYear($this->alice);
        $this->hanche = $this->createNomenclature();
        $this->appendice = $this->nomenclature('Appendicectomie', 'dig', '241415', '2');

        $this->snapshotDir = sys_get_temp_dir() . '/medclick-repair-' . bin2hex(random_bytes(4));
        mkdir($this->snapshotDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->snapshotDir);
        parent::tearDown();
    }

    public function testCertainCandidateIsRepairedWithTheUniqueTarget(): void
    {
        $buggy = $this->buggySurgery('dig');

        $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertAligned($buggy, $this->appendice, '2414152');
    }

    public function testFavoritesSpecialityWrittenByTheBugIsPartOfTheTargetAlignment(): void
    {
        $buggy = $this->buggySurgery('favorites');

        $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertAligned($buggy, $this->appendice, '2414152');
    }

    public function testFavoritesSpecialityOutsideTheSignatureIsLeftUntouched(): void
    {
        // Nom et nomenclature cohérents, seule la spécialité vaut « favorites » : hors périmètre.
        $surgery = $this->surgery($this->hanche, 'Prothèse totale de hanche', '2890851', 'favorites');

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertSame('favorites', $this->reload($surgery)->getSpeciality());
        $this->assertStringContainsString('Rien à corriger', $output);
    }

    public function testSeveralTargetsWithTheSameNameAreSkipped(): void
    {
        $this->nomenclature('Appendicectomie', 'dig', '241430', '1');
        $buggy = $this->buggySurgery('dig');

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertUnchanged($buggy);
        $this->assertCounter('C plusieurs nomenclatures de ce nom — exclues', 1, $output);
    }

    public function testNoNomenclatureWithThatExactNameIsSkipped(): void
    {
        // Différence de casse seulement : pas de correspondance exacte, pas d'approximation.
        $surgery = $this->surgery($this->hanche, 'APPENDICECTOMIE', '2890851', 'dig');

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertUnchanged($surgery, 'APPENDICECTOMIE');
        $this->assertCounter('Aucune nomenclature de ce nom exact — exclues', 1, $output);
    }

    public function testAlreadyConsistentSurgeryIsSkipped(): void
    {
        $surgery = $this->surgery($this->hanche, 'Prothèse totale de hanche', '2890851', 'ortho');

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertAligned($surgery, $this->hanche, '2890851');
        $this->assertCounter('Déjà cohérentes', 1, $output);
    }

    public function testHistoricalSurgeryWithoutCreatedAtIsExcluded(): void
    {
        $historical = $this->buggySurgery('dig', createdAt: null);

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertUnchanged($historical);
        $this->assertCounter('A2 historiques (sans createdAt) — exclues', 1, $output);
    }

    public function testUnexpectedSpecialityIsExcluded(): void
    {
        $surgery = $this->buggySurgery('vasc');

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertUnchanged($surgery, speciality: 'vasc');
        $this->assertCounter('B spécialité inattendue — exclues', 1, $output);
    }

    public function testIncompleteTargetIsExcluded(): void
    {
        $this->nomenclature('Cure de hernie', 'dig', null, null);
        $surgery = $this->surgery($this->hanche, 'Cure de hernie', '2890851', 'dig');

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertUnchanged($surgery, 'Cure de hernie');
        $this->assertCounter('Nomenclature cible incomplète — exclues', 1, $output);
    }

    public function testDryRunIsTheDefaultAndWritesNothing(): void
    {
        $buggy = $this->buggySurgery('dig');
        $before = $this->table();

        $output = $this->repair([]);

        $this->assertSame($before, $this->table());
        $this->assertUnchanged($buggy);
        $this->assertSame([], glob($this->snapshotDir . '/*'));
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertCounter('A1 certaines — seraient modifiées', 1, $output);
    }

    public function testApplyRequiresASnapshotDirectoryOutsideTheProject(): void
    {
        $buggy = $this->buggySurgery('dig');

        $this->repair(['--apply' => true], 2);
        $this->repair(['--apply' => true, '--snapshot-dir' => static::getContainer()->getParameter('kernel.project_dir') . '/var'], 2);

        $this->assertUnchanged($buggy);
    }

    public function testSecondApplyChangesNothing(): void
    {
        $this->buggySurgery('dig');
        $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);
        $after = $this->table();

        $output = $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertSame($after, $this->table());
        $this->assertStringContainsString('Rien à corriger', $output);
        $this->assertCount(2, glob($this->snapshotDir . '/*.csv'), 'un seul run a écrit (snapshot + journal)');
    }

    public function testSnapshotAndJournalAllowAnExactRollback(): void
    {
        $buggy = $this->buggySurgery('favorites');
        $consistent = $this->surgery($this->hanche, 'Prothèse totale de hanche', '2890851', 'ortho');
        $before = $this->table();

        $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        [$snapshot] = array_values(array_filter(glob($this->snapshotDir . '/*.csv'), fn ($f) => !str_ends_with($f, '.applied.csv')));
        [$journal] = glob($this->snapshotDir . '/*.applied.csv');
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), file($snapshot, FILE_IGNORE_NEW_LINES));
        $this->assertSame(['run_id', 'script_version', 'surgery_id', 'before_nomenclature_id', 'before_code', 'before_speciality', 'after_nomenclature_id', 'after_code', 'after_speciality'], $rows[0]);
        $this->assertSame(['repair-surgery-nomenclature/2', (string) $buggy->getId(), (string) $this->hanche->getId(), '2890851', 'favorites', (string) $this->appendice->getId(), '2414152', 'dig'], array_slice($rows[1], 1));
        $this->assertCount(2, $rows);
        $this->assertStringContainsString($buggy->getId() . ',', file_get_contents($journal));
        $this->assertStringContainsString('corrigée', file_get_contents($journal));

        // Rollback : dry-run par défaut, puis application ; une seconde fois ne restaure plus rien.
        $this->assertStringContainsString('1 restaurable', $this->repair(['--rollback' => $snapshot]));
        $this->assertStringContainsString('1 ligne(s) restaurée(s)', $this->repair(['--rollback' => $snapshot, '--apply' => true]));
        $this->assertSame($before, $this->table());
        $this->assertStringContainsString('0 ligne(s) restaurée(s)', $this->repair(['--rollback' => $snapshot, '--apply' => true]));
        $this->assertAligned($consistent, $this->hanche, '2890851');
    }

    public function testExcelExportIsIncoherentBeforeAndCoherentAfterRepair(): void
    {
        $this->buggySurgery('dig');
        $client = $this->clientFor($this->alice);

        $this->assertSame(['2890851'], $this->exportedCodes($client, 'Appendicectomie'), 'avant : code A à côté du nom B');

        $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertSame(['2414152'], $this->exportedCodes($client, 'Appendicectomie'), 'après : export cohérent');
    }

    public function testSnapshotAndJournalAreCreatedPrivate(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Permissions POSIX non significatives sous Windows (vérifié en CI Linux).');
        }
        $this->buggySurgery('dig');

        $this->repair(['--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $files = glob($this->snapshotDir . '/*.csv');
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4), basename($file));
        }
    }

    // ---------------------------------------------------------------- Portée « favorites » (LOT 2D.3)

    public function testFavoritesCandidateOnlyGetsTheNomenclatureSpeciality(): void
    {
        $candidate = $this->surgery($this->appendice, 'Appendicectomie', '2414152', 'favorites');
        $before = $this->row($candidate);

        $this->repair(['--scope' => 'favorites', '--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $after = $this->row($candidate);
        $this->assertSame('dig', $after['speciality']);
        unset($before['speciality'], $after['speciality']);
        $this->assertSame($before, $after, 'seule la spécialité change');
    }

    public function testFavoritesExclusionsAreSkipped(): void
    {
        $this->nomenclature('Cure de hernie', 'dig', null, null);
        $codeMismatch = $this->surgery($this->appendice, 'Appendicectomie', '9999999', 'favorites');
        $nameMismatch = $this->surgery($this->appendice, 'Appendicectomie laparoscopique', '2414152', 'favorites');
        $incomplete = $this->surgery($this->nomenclatureNamed('Cure de hernie'), 'Cure de hernie', '', 'favorites');
        $withoutNomenclature = $this->surgeryWithoutNomenclature('favorites');
        $alreadyCorrect = $this->surgery($this->appendice, 'Appendicectomie', '2414152', 'dig');
        $before = $this->table();

        $output = $this->repair(['--scope' => 'favorites', '--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertSame($before, $this->table());
        $this->assertStringContainsString('Rien à corriger', $output);
        $this->assertCounter('Spécialité « favorites »', 4, $output);
        $this->assertCounter('F réparables — seraient modifiées', 0, $output);
        $this->assertCounter('F exclues : code différent de la nomenclature', 1, $output);
        $this->assertCounter('F exclues : nom différent de la nomenclature', 1, $output);
        $this->assertCounter('F exclues : nomenclature incomplète', 1, $output);
        $this->assertCounter('F exclues : sans nomenclature', 1, $output);
        unset($codeMismatch, $nameMismatch, $incomplete, $withoutNomenclature, $alreadyCorrect);
    }

    public function testFavoritesDryRunIsTheDefaultAndWritesNothing(): void
    {
        $this->surgery($this->appendice, 'Appendicectomie', '2414152', 'favorites');
        $before = $this->table();

        $output = $this->repair(['--scope' => 'favorites']);

        $this->assertSame($before, $this->table());
        $this->assertSame([], glob($this->snapshotDir . '/*'));
        $this->assertCounter('F réparables — seraient modifiées', 1, $output);
        $this->assertStringContainsString('1 intervention(s) seraient corrigées', $output);
    }

    public function testFavoritesScopeDoesNotTouchA1AndA1ScopeDoesNotTouchFavorites(): void
    {
        $buggy = $this->buggySurgery('dig');
        $favorite = $this->surgery($this->appendice, 'Appendicectomie', '2414152', 'favorites');

        $this->repair(['--scope' => 'favorites', '--apply' => true, '--snapshot-dir' => $this->snapshotDir]);
        $this->assertUnchanged($buggy);

        $this->repair(['--scope' => 'nomenclature', '--apply' => true, '--snapshot-dir' => $this->snapshotDir]);
        $this->assertSame('dig', $this->reload($favorite)->getSpeciality());
        $this->assertAligned($buggy, $this->appendice, '2414152');
    }

    public function testFavoritesSecondApplyAndRollback(): void
    {
        $this->surgery($this->appendice, 'Appendicectomie', '2414152', 'favorites');
        $initial = $this->table();

        $this->repair(['--scope' => 'favorites', '--apply' => true, '--snapshot-dir' => $this->snapshotDir]);
        $repaired = $this->table();
        $this->assertStringContainsString('Rien à corriger', $this->repair(['--scope' => 'favorites', '--apply' => true, '--snapshot-dir' => $this->snapshotDir]));
        $this->assertSame($repaired, $this->table());

        [$snapshot] = array_values(array_filter(glob($this->snapshotDir . '/repair-surgery-favorites-*.csv'), fn ($f) => !str_ends_with($f, '.applied.csv')));
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), file($snapshot, FILE_IGNORE_NEW_LINES));
        $this->assertCount(2, $rows);
        [, , , $beforeNomenclature, $beforeCode, $beforeSpeciality, $afterNomenclature, $afterCode, $afterSpeciality] = $rows[1];
        $this->assertSame([$beforeNomenclature, $beforeCode, 'favorites'], [$afterNomenclature, $afterCode, $beforeSpeciality], 'relation et code identiques avant/après');
        $this->assertSame('dig', $afterSpeciality);

        $this->assertStringContainsString('1 ligne(s) restaurée(s)', $this->repair(['--rollback' => $snapshot, '--apply' => true]));
        $this->assertSame($initial, $this->table());
    }

    public function testFavoritesExcelSummaryBeforeAndAfterRepair(): void
    {
        $this->surgery($this->appendice, 'Appendicectomie', '2414152', 'favorites');
        $client = $this->clientFor($this->alice);

        $this->assertSame([true, false], $this->exportContains($client, '2414152'), 'avant : carnet oui, récapitulatif non');

        $this->repair(['--scope' => 'favorites', '--apply' => true, '--snapshot-dir' => $this->snapshotDir]);

        $this->assertSame([true, true], $this->exportContains($client, '2414152'), 'après : présent dans le récapitulatif');
    }

    public function testUnknownScopeIsRefused(): void
    {
        $this->repair(['--scope' => 'tout'], 2);
    }

    // ---------------------------------------------------------------- Helpers

    private function repair(array $options, int $expectedStatus = 0): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:repair-surgery-nomenclature'));
        $status = $tester->execute($options);
        $this->assertSame($expectedStatus, $status, $tester->getDisplay());

        return preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** Ligne du tableau de compteurs : « libellé  n ». */
    private function assertCounter(string $label, int $count, string $output): void
    {
        $this->assertMatchesRegularExpression('/' . preg_quote($label, '/') . ' ' . $count . ' /', $output);
    }

    /** État de l'ancien PUT : relation et code de la hanche, nom de l'appendicectomie. */
    private function buggySurgery(string $speciality, ?\DateTime $createdAt = new \DateTime('2025-11-02 10:00')): Surgeries
    {
        return $this->surgery($this->hanche, 'Appendicectomie', '2890851', $speciality, $createdAt);
    }

    private function surgery(Nomenclature $nomenclature, string $name, ?string $code, string $speciality, ?\DateTime $createdAt = new \DateTime('2025-11-02 10:00')): Surgeries
    {
        $surgery = (new Surgeries())
            ->setYear($this->year)
            ->setDate(new \DateTime('2025-11-02'))
            ->setNomenclature($nomenclature)
            ->setCode($code)
            ->setName($name)
            ->setSpeciality($speciality)
            ->setPosition('1')
            ->setFirstHand((string) $this->alice->getId())
            ->setCreatedAt($createdAt);
        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    private function nomenclature(string $name, string $speciality, ?string $codeHospitalisation, ?string $n): Nomenclature
    {
        $nomenclature = (new Nomenclature())
            ->setName($name)
            ->setSpeciality($speciality)
            ->setCodeHospitalisation($codeHospitalisation)
            ->setN($n);
        $this->em->persist($nomenclature);
        $this->em->flush();

        return $nomenclature;
    }

    private function assertAligned(Surgeries $surgery, Nomenclature $nomenclature, string $code): void
    {
        $fresh = $this->reload($surgery);
        $this->assertSame($nomenclature->getId(), $fresh->getNomenclature()?->getId(), 'relation');
        $this->assertSame($code, $fresh->getCode(), 'code');
        $this->assertSame($nomenclature->getName(), $fresh->getName(), 'nom');
        $this->assertSame($nomenclature->getSpeciality(), $fresh->getSpeciality(), 'spécialité');
    }

    private function assertUnchanged(Surgeries $surgery, string $name = 'Appendicectomie', ?string $speciality = null): void
    {
        $fresh = $this->reload($surgery);
        $this->assertSame($this->hanche->getId(), $fresh->getNomenclature()?->getId());
        $this->assertSame('2890851', $fresh->getCode());
        $this->assertSame($name, $fresh->getName());
        $this->assertSame($speciality ?? $surgery->getSpeciality(), $fresh->getSpeciality());
    }

    private function table(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM surgeries ORDER BY id');
    }

    private function reload(Surgeries $surgery): Surgeries
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->find(Surgeries::class, $surgery->getId());
    }

    private function row(Surgeries $surgery): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM surgeries WHERE id = ?', [$surgery->getId()]);
    }

    private function nomenclatureNamed(string $name): Nomenclature
    {
        return $this->em->getRepository(Nomenclature::class)->findOneBy(['name' => $name]);
    }

    private function surgeryWithoutNomenclature(string $speciality): Surgeries
    {
        $surgery = (new Surgeries())
            ->setYear($this->year)
            ->setDate(new \DateTime('2025-11-02'))
            ->setName('Saisie libre')
            ->setSpeciality($speciality)
            ->setPosition('1')
            ->setCreatedAt(new \DateTime('2025-11-02 10:00'));
        $this->em->persist($surgery);
        $this->em->flush();

        return $surgery;
    }

    /** [code présent dans le carnet (feuille 1), code présent dans le récapitulatif (feuille 2)] */
    private function exportContains($client, string $code): array
    {
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $client->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $book = IOFactory::load($file);
        unlink($file);

        $found = [];
        foreach ([1, 2] as $index) {
            $cells = array_map(fn ($value) => trim((string) $value), array_merge(...$book->getSheet($index)->toArray()));
            $found[] = in_array($code, $cells, true);
        }

        return $found;
    }

    /** Codes (colonne B) des lignes du carnet (feuille « Carnet de stage ») portant ce nom. */
    private function exportedCodes($client, string $name): array
    {
        $file = tempnam(sys_get_temp_dir(), 'carnet');
        file_put_contents($file, $client->request('GET', '/api/excel2/' . $this->year->getId())->getContent());
        $sheet = IOFactory::load($file)->getSheet(1);
        unlink($file);

        $codes = [];
        foreach ($sheet->getRowIterator() as $row) {
            $number = $row->getRowIndex();
            if (trim((string) $sheet->getCell('C' . $number)->getValue()) === $name) {
                $codes[] = trim((string) $sheet->getCell('B' . $number)->getValue());
            }
        }

        return $codes;
    }
}
