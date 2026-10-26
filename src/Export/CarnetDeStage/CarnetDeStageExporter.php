<?php

namespace App\Export\CarnetDeStage;

use App\Export\ExportFailure;
use App\Export\ExportProgress;
use App\Export\ExportStep;
use App\Export\TrackingProgress;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\Exception\ORMException;
use PhpOffice\PhpSpreadsheet\Calculation\Exception as CalculationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Génère le carnet de stage officiel (export « Excel (New) », /api/excel2) dans un fichier.
 * Utilisé par la génération asynchrone (ExportJob) et par l'ancienne route synchrone.
 *
 * Chaque échec est qualifié (ExportFailure) avec l'étape en cours : base de données, génération
 * du classeur ou écriture du fichier. Le fichier est écrit sous un nom temporaire puis renommé :
 * un fichier présent est toujours complet.
 */
final class CarnetDeStageExporter
{
    public const TYPE = 'carnet_de_stage';

    public function __construct(
        private CarnetDeStageDataLoader $loader,
        private CarnetDeStageSpreadsheetBuilder $builder,
        private CarnetDeStageTemplate $template,
    ) {
    }

    /**
     * @return array{rows: int} nombre d'interventions de l'année exportée
     *
     * @throws ExportFailure
     */
    public function export(int $userId, int $yearId, string $targetPath, ExportProgress $progress): array
    {
        $progress = new TrackingProgress($progress);

        $progress->step(ExportStep::Loading);
        try {
            $data = $this->loader->load($userId, $yearId);
        } catch (ExportFailure $failure) {
            throw $failure;
        } catch (DbalException|ORMException $exception) {
            throw new ExportFailure(ExportFailure::DATABASE, 'Lecture des données impossible (' . $exception::class . ')', ExportStep::Loading, $exception);
        }

        try {
            $progress->step(ExportStep::Template);
            $spreadsheet = $this->template->load();
            $this->builder->build($spreadsheet, $data, $progress);
        } catch (ExportFailure $failure) {
            throw $failure;
        } catch (\Throwable $exception) {
            throw new ExportFailure(ExportFailure::XLSX_GENERATION, 'Génération du classeur impossible (' . $exception::class . ')', $progress->current, $exception);
        }

        $progress->step(ExportStep::Writing);
        $temporary = $targetPath . '.part';
        try {
            if (!is_dir(dirname($temporary)) || !is_writable(dirname($temporary))) {
                throw new \RuntimeException('Dossier de destination absent ou non accessible en écriture');
            }
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($temporary);
            if (!@rename($temporary, $targetPath)) {
                throw new \RuntimeException('Renommage du fichier temporaire impossible');
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            // Le calcul des formules a lieu pendant l'écriture : c'est alors une erreur de génération.
            $category = self::causedBy($exception, CalculationException::class) ? ExportFailure::XLSX_GENERATION : ExportFailure::FILE_WRITE;
            throw new ExportFailure($category, "Écriture du fichier impossible (" . $exception::class . ')', ExportStep::Writing, $exception);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return ['rows' => count($data->currentYearSurgeries)];
    }

    private static function causedBy(\Throwable $exception, string $class): bool
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
