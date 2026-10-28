<?php

namespace App\Tests\Excel;

use App\Tests\Excel\Support\TimingProgress;
use PHPUnit\Framework\Attributes\Group;

/**
 * Mesure de l'export du carnet de stage, étape par étape (durée, mémoire maximale) :
 *   EXCEL_SCENARIOS=profil-reel,volume-10000 php vendor/bin/phpunit --group excel-profile
 */
#[Group('excel-profile')]
final class ProfileTest extends ExcelTestCase
{
    public function testProfile(): void
    {
        foreach (array_filter(explode(',', (string) getenv('EXCEL_SCENARIOS'))) as $scenario) {
            $this->setUp();
            [$user, $yearId] = $this->seed($scenario);
            $progress = new TimingProgress();
            $result = $this->runExport($user, $yearId, $progress);
            fwrite(STDERR, sprintf("\n%s : %.2f s, %.1f Mo — %s", $scenario, $result['seconds'], $result['peakMemory'] / 1048576, $progress->summary()));
        }
        self::assertTrue(true);
    }
}
