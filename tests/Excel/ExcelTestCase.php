<?php

namespace App\Tests\Excel;

use App\Entity\User;
use App\Export\CarnetDeStage\CarnetDeStageDataLoader;
use App\Export\CarnetDeStage\CarnetDeStageExporter;
use App\Export\CarnetDeStage\CarnetDeStageSpreadsheetBuilder;
use App\Export\CarnetDeStage\CarnetDeStageTemplate;
use App\Export\ExportProgress;
use App\Export\NullProgress;
use App\Tests\ApiTestBase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use App\Tests\Excel\Legacy\LegacyExcelNewVersion;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Exécute les moteurs d'export sur les jeux de données fictifs, dans les conditions de la
 * production : avertissements PHP non bloquants (APP_DEBUG=0 → journalisés, pas d'exception).
 */
abstract class ExcelTestCase extends ApiTestBase
{
    public const REFERENCE_DIR = __DIR__ . '/../Fixtures/excel-reference';

    protected function seed(string $scenario): array
    {
        return (new ExportDatasetFactory($this->em))->build($scenario);
    }

    /**
     * Moteur figé (oracle) : renvoie le contenu XLSX tel que servi par /api/excel2.
     *
     * @return array{bytes: string, seconds: float, peakMemory: int, warnings: list<string>}
     */
    protected function runLegacy(User $user, int $yearId): array
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        $this->em->clear();

        $controller = new LegacyExcelNewVersion(
            $container->get('security.helper'),
            $container->get(\App\Repository\UserRepository::class),
            $container->get(\App\Repository\YearsRepository::class),
            $container->get(\App\Repository\ConsultationsRepository::class),
            $container->get(\App\Repository\GardesRepository::class),
            $container->get(\App\Repository\FormationsRepository::class),
        );
        $locator = new Container();
        $locator->set('parameter_bag', new ContainerBag($container));
        $controller->setContainer($locator);

        return $this->measure(function () use ($controller, $yearId) {
            $response = $controller->ExcelGenerator2($yearId);
            ob_start();
            $response->sendContent();

            return ob_get_clean();
        });
    }

    /**
     * Nouveau moteur (CarnetDeStageExporter), même conditions que runLegacy().
     *
     * @return array{bytes: string, seconds: float, peakMemory: int, warnings: list<string>}
     */
    protected function runNew(User $user, int $yearId, ?ExportProgress $progress = null, ?ClockInterface $clock = null): array
    {
        $container = static::getContainer();
        $this->em->clear();
        $exporter = new CarnetDeStageExporter(
            new CarnetDeStageDataLoader(
                $this->em,
                $container->get(\App\Repository\UserRepository::class),
                $container->get(\App\Repository\YearsRepository::class),
                $container->get(\App\Repository\ConsultationsRepository::class),
                $container->get(\App\Repository\GardesRepository::class),
                $container->get(\App\Repository\FormationsRepository::class),
            ),
            new CarnetDeStageSpreadsheetBuilder($clock ?? new NativeClock()),
            new CarnetDeStageTemplate(
                $container->getParameter('kernel.project_dir') . '/public/ExcelTemplate.xlsx',
                $container->getParameter('kernel.cache_dir') . '/carnet-de-stage',
                $container->getParameter('kernel.secret'),
            ),
        );
        $path = tempnam(sys_get_temp_dir(), 'carnet') . '.xlsx';

        try {
            return $this->measure(function () use ($exporter, $user, $yearId, $path, $progress) {
                $exporter->export($user->getId(), $yearId, $path, $progress ?? new NullProgress());

                return file_get_contents($path);
            });
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{equivalent: bool, differences: list<string>, stats: array<string, int>, toleratedVolatile: list<string>}
     */
    protected function compareBytes(string $expected, string $actual): array
    {
        $a = tempnam(sys_get_temp_dir(), 'xa');
        $b = tempnam(sys_get_temp_dir(), 'xb');
        file_put_contents($a, $expected);
        file_put_contents($b, $actual);
        try {
            return (new XlsxComparator())->compare($a, $b);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    protected static function assertEquivalent(array $report, string $label): void
    {
        self::assertTrue($report['equivalent'], "$label : fichiers Excel différents\n- " . implode("\n- ", $report['differences']));
        self::assertGreaterThan(0, $report['stats']['cellules']);
    }

    /**
     * @return array{bytes: string, seconds: float, peakMemory: int, warnings: list<string>}
     */
    protected function measure(callable $run): array
    {
        $warnings = [];
        set_error_handler(function (int $level, string $message, string $file, int $line) use (&$warnings) {
            $warnings[] = basename($file) . ':' . $line . ' ' . $message;

            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $start = hrtime(true);
        try {
            $bytes = $run();
        } finally {
            restore_error_handler();
        }

        return [
            'bytes' => $bytes,
            'seconds' => (hrtime(true) - $start) / 1e9,
            'peakMemory' => memory_get_peak_usage() - $base,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
