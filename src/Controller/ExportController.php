<?php

namespace App\Controller;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Entity\User;
use App\Entity\Years;
use App\Export\Job\ExportJobManager;
use App\Export\Job\ExportJobPresenter;
use App\Export\Job\ExportStorage;
use App\Repository\ExportJobEventRepository;
use App\Repository\ExportJobRepository;
use App\Repository\YearsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exports Excel asynchrones de l'utilisateur connecté (docs/EXPORTS.md).
 *
 * Isolation : un utilisateur ne voit, n'annule et ne télécharge que SES exports ; un export d'un
 * autre utilisateur répond 404 (son existence n'est pas révélée), y compris pour un administrateur.
 */
class ExportController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function __construct(
        private ExportJobManager $manager,
        private ExportJobRepository $jobs,
        private ExportJobEventRepository $events,
        private ExportJobPresenter $presenter,
        private ExportStorage $storage,
        #[Autowire(service: 'limiter.export_request')]
        private RateLimiterFactoryInterface $exportRequestLimiter,
    ) {
    }

    /** Demande d'export (body : {"yearId": 12}). 202 si créé, 200 si un export identique est déjà en cours. */
    #[Route('/api/exports', name: 'export_create', methods: ['POST'])]
    public function create(Request $request, YearsRepository $years): JsonResponse
    {
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true);
        $year = is_array($data) && is_int($data['yearId'] ?? null) ? $years->find($data['yearId']) : null;
        if (!$year instanceof Years) {
            return new JsonResponse(['error' => 'Année de formation introuvable'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isGranted('OWNER', $year)) {
            return new JsonResponse(['error' => 'No rights on this year'], Response::HTTP_FORBIDDEN);
        }

        $limit = $this->exportRequestLimiter->create('user-' . $user->getId())->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => "Trop de demandes d'export. Réessayez plus tard."], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            ]);
        }

        [$job, $created] = $this->manager->request($user, $year);

        return new JsonResponse($this->present($job), $created ? Response::HTTP_ACCEPTED : Response::HTTP_OK);
    }

    /** Exports des dernières 24 h (reprise après rafraîchissement de la page), filtrables par année. */
    #[Route('/api/exports', name: 'export_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $yearId = $request->query->has('yearId') ? $request->query->getInt('yearId') : null;
        $jobs = $this->jobs->findRecentForUser($user->getId(), $this->manager->now()->modify('-24 hours'), $yearId);
        foreach ($jobs as $job) {
            $this->manager->checkLiveness($job);
        }

        return new JsonResponse(['items' => array_map(fn (ExportJob $job) => $this->present($job), $jobs)]);
    }

    /** État et progression réelle (interrogé par le frontend pendant la génération). */
    #[Route('/api/exports/{id}', name: 'export_show', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $job = $this->ownJob($id);
        if ($job === null) {
            return $this->notFound();
        }
        $this->manager->checkLiveness($job);

        return new JsonResponse($this->present($job), headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/api/exports/{id}/cancel', name: 'export_cancel', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function cancel(string $id): JsonResponse
    {
        $job = $this->ownJob($id);
        if ($job === null) {
            return $this->notFound();
        }
        $this->manager->cancel($job);

        return new JsonResponse($this->present($job));
    }

    /**
     * Téléchargement (propriétaire uniquement). L'envoi complet est un évènement OBSERVÉ côté
     * serveur (tous les octets écrits sans coupure détectée) ; seule la confirmation du navigateur
     * (POST …/confirm) prouve la réception du fichier entier.
     */
    #[Route('/api/exports/{id}/download', name: 'export_download', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function download(string $id): Response
    {
        $job = $this->ownJob($id);
        if ($job === null) {
            return $this->notFound();
        }
        $job->incrementDownloadAttempts();
        $this->manager->em()->flush();

        $path = $this->storage->pathFor($job);
        $available = in_array($job->getStatus(), [ExportJob::STATUS_READY, ExportJob::STATUS_DOWNLOADED], true)
            && $job->getFileDeletedAt() === null && $path !== null && is_file($path);
        if (!$available) {
            $expired = $job->getStatus() === ExportJob::STATUS_EXPIRED || $job->getFileDeletedAt() !== null;
            $this->manager->event($job, ExportJobEvent::DOWNLOAD_REFUSED, $job->getStep(), $expired ? 'Fichier expiré' : 'Fichier indisponible (statut ' . $job->getStatus() . ')');

            return new JsonResponse(['error' => $expired ? 'Le fichier a expiré : relancez l\'export.' : "Le fichier n'est pas disponible."],
                $expired ? Response::HTTP_GONE : Response::HTTP_CONFLICT);
        }

        $this->manager->event($job, ExportJobEvent::DOWNLOAD_STARTED, $job->getStep(), null, ['attempt' => $job->getDownloadAttempts()]);
        $size = (int) filesize($path);
        $response = new StreamedResponse(function () use ($job, $path, $size) {
            ignore_user_abort(true);
            $sent = 0;
            $handle = fopen($path, 'rb');
            while ($handle !== false && !feof($handle) && !connection_aborted()) {
                $chunk = fread($handle, 65536);
                echo $chunk;
                $sent += strlen($chunk);
                flush();
            }
            if ($handle !== false) {
                fclose($handle);
            }
            $job = $this->manager->managed($job);
            if ($sent === $size && !connection_aborted()) {
                if ($job->getFirstServedAt() === null) {
                    $job->setFirstServedAt($this->manager->now());
                }
                $this->manager->event($job, ExportJobEvent::DOWNLOAD_SERVED, $job->getStep(), 'Fichier envoyé en entier (non confirmé par le navigateur)', ['bytes' => $sent]);
            } else {
                $this->manager->event($job, ExportJobEvent::DOWNLOAD_INTERRUPTED, $job->getStep(), 'Envoi interrompu (connexion fermée)', ['bytes' => $sent, 'size' => $size]);
            }
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Length', (string) $size);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'carnet-de-stage-annee-' . preg_replace('/[^0-9A-Za-z]/', '', (string) ($job->getParameters()['yearOfFormation'] ?? '')) . '.xlsx',
        ));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    /** Confirmation du navigateur : fichier reçu en entier (body : {"size": 273428}). */
    #[Route('/api/exports/{id}/confirm', name: 'export_confirm', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function confirm(string $id, Request $request): JsonResponse
    {
        $job = $this->ownJob($id);
        if ($job === null) {
            return $this->notFound();
        }
        $data = json_decode($request->getContent(), true);
        $size = is_array($data) && is_int($data['size'] ?? null) ? $data['size'] : null;

        if ($size === null || $size !== $job->getFileSize()) {
            $this->manager->event($job, ExportJobEvent::DOWNLOAD_SIZE_MISMATCH, $job->getStep(), 'Taille reçue différente du fichier généré',
                ['received' => $size, 'expected' => $job->getFileSize()]);

            return new JsonResponse(['error' => 'Fichier incomplet : relancez le téléchargement.'], Response::HTTP_CONFLICT);
        }

        if ($job->getFirstDownloadedAt() === null) {
            $job->setFirstDownloadedAt($this->manager->now());
            $connection = $this->manager->em()->getConnection();
            $connection->executeStatement('UPDATE export_job SET status = ? WHERE id = ? AND status = ?',
                [ExportJob::STATUS_DOWNLOADED, $job->getId(), ExportJob::STATUS_READY]);
            $this->manager->em()->flush();
            $this->manager->em()->refresh($job);
        }
        $this->manager->event($job, ExportJobEvent::DOWNLOAD_CONFIRMED, $job->getStep(), 'Réception complète confirmée par le navigateur', ['size' => $size]);

        return new JsonResponse($this->present($job));
    }

    private function present(ExportJob $job): array
    {
        return $this->presenter->forUser($job, $this->events->findForJob($job),
            $job->getStatus() === ExportJob::STATUS_PENDING ? $this->jobs->countAhead($job) : null);
    }

    private function ownJob(string $publicId): ?ExportJob
    {
        $job = $this->jobs->findOneByPublicId($publicId);

        return $job !== null && $job->getUserId() === $this->currentUser()->getId() ? $job : null;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'Export introuvable'], Response::HTTP_NOT_FOUND);
    }
}
