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
 * Alias SYNCHRONES de l'export du carnet de stage, conservés temporairement pour la compatibilité
 * (frontend CRA encore servi, onglets ouverts) : /api/excel2/{year} et /api/excel/{year}. L'API
 * canonique est /api/exports (ExportController : génération asynchrone, progression, historique,
 * téléchargement). Même générateur (App\Export\CarnetDeStage), même fichier ; contrat de réponse
 * inchangé (200 + fichier .xlsx, 403 année d'un autre utilisateur, 404 année inconnue). Chaque
 * demande est historisée (App\Export\Job\SyncExportRecorder). Voir docs/EXPORTS.md.
 */
class CarnetDeStageExcelController extends AbstractController
{
    public function __construct(private CarnetDeStageExporter $exporter, private ExportStorage $storage)
    {
    }

    /** @param int $year identifiant de l'année de formation (Years) */
    #[Route('/api/excel2/{year}', name: 'carnet_de_stage_excel', methods: ['GET'])]
    #[Route('/api/excel/{year}', name: 'carnet_de_stage_excel_alias', methods: ['GET'])]
    public function download(int $year, Request $request): Response
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
