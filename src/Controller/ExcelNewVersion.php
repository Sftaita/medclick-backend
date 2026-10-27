<?php

namespace App\Controller;

use App\Entity\User;
use App\Export\CarnetDeStage\CarnetDeStageExporter;
use App\Export\ExportFailure;
use App\Export\Job\ExportStorage;
use App\Export\NullProgress;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Export synchrone du carnet de stage, conservé pour l'ancien frontend (CRA, encore servi) : le
 * nouveau frontend passe par /api/exports (asynchrone, progression réelle). Même fichier, mêmes
 * réponses qu'avant ; le moteur est App\Export\CarnetDeStage (l'ancien code est figé dans
 * tests/Excel/Legacy, qui vérifie l'équivalence). La demande est historisée
 * (App\Export\Job\SyncExportRecorder).
 *
 * /api/excel/{year} (« Excel (Old) ») : l'ancien moteur v1 a été retiré (27/10/2026) ; l'adresse
 * reste un alias de ce même export officiel pour ne pas casser l'ancien frontend.
 */
class ExcelNewVersion extends AbstractController
{
    public function __construct(private CarnetDeStageExporter $exporter, private ExportStorage $storage)
    {
    }

    /**
     * @param int $year Définie l'année d'interet pour l'extraction des données.
     * @return Response
     */
    #[Route('/api/excel2/{year}', name: 'excel', methods: ['GET'])]
    #[Route('/api/excel/{year}', name: 'excelOldVersion', methods: ['GET'])]
    public function ExcelGenerator2(int $year, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('User not found', Response::HTTP_NOT_FOUND);
        }

        $path = $this->storage->directory() . '/sync-' . bin2hex(random_bytes(16)) . '.xlsx';
        try {
            $result = $this->exporter->export($user->getId(), $year, $path, new NullProgress());
        } catch (ExportFailure $failure) {
            return match ($failure->category) {
                ExportFailure::NOT_FOUND => new Response('User not found', Response::HTTP_NOT_FOUND),
                ExportFailure::ACCESS_DENIED => new Response('No rights on this year', Response::HTTP_FORBIDDEN),
                default => throw $failure,
            };
        }
        $request->attributes->set('_export_rows', $result['rows']);

        // Fichier envoyé par blocs puis supprimé (rien n'est conservé pour la route synchrone).
        $response = new StreamedResponse(function () use ($path) {
            $handle = fopen($path, 'rb');
            while ($handle !== false && !feof($handle) && !connection_aborted()) {
                echo fread($handle, 65536);
                flush();
            }
            if ($handle !== false) {
                fclose($handle);
            }
            @unlink($path);
        });

        $dispositionHeader = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'export.xlsx'
        );

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $dispositionHeader);
        $response->headers->set('Content-Length', (string) filesize($path));

        return $response;
    }
}
