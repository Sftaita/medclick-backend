<?php

namespace App\Controller\AdminControllers;

use App\Entity\ExportJob;
use App\Entity\ExportJobEvent;
use App\Entity\User;
use App\Export\Job\ExportAlerter;
use App\Export\Job\ExportJobManager;
use App\Export\Job\ExportJobPresenter;
use App\Repository\ExportJobEventRepository;
use App\Repository\ExportJobRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Surveillance des exports (ROLE_ADMIN via /api/admin) : compteurs, historique filtré, détail des
 * étapes, erreurs et alertes. Jamais d'accès aux fichiers ni aux données exportées : le
 * téléchargement reste réservé au propriétaire (App\Controller\ExportController).
 * Chaque consultation du détail est journalisée dans l'historique de l'export.
 */
class ExportMonitoringController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function __construct(
        private ExportJobRepository $jobs,
        private ExportJobEventRepository $events,
        private ExportJobPresenter $presenter,
        private ExportJobManager $manager,
        private Security $security,
    ) {
    }

    /**
     * Liste et compteurs. Filtres : ?status=, ?type=, ?user= (partie de l'e-mail), ?userId=,
     * ?from=AAAA-MM-JJ, ?to=AAAA-MM-JJ (inclus), ?page= (50 par page).
     */
    #[Route('/api/admin/exports', name: 'admin_exports_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // Détection immédiate des exports bloqués (même si le worker et sa surveillance sont arrêtés).
        foreach ($this->jobs->findBy(['status' => ExportJob::ACTIVE_STATUSES]) as $job) {
            $this->manager->checkLiveness($job);
        }

        $filters = [
            'status' => in_array($request->query->get('status'), ExportJob::STATUSES, true) ? $request->query->get('status') : null,
            'type' => $request->query->get('type') ?: null,
            'user' => trim((string) $request->query->get('user')) ?: null,
            'userId' => $request->query->getInt('userId') ?: null,
            'from' => self::day($request->query->get('from')),
            'to' => self::day($request->query->get('to'))?->modify('+1 day'),
        ];
        $page = max(1, $request->query->getInt('page', 1));
        $result = $this->jobs->search($filters, $page, 50);

        return new JsonResponse([
            'counts' => $this->jobs->countByStatus($filters),
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 50,
            'items' => array_map(fn (array $row) => $this->presenter->forAdmin($row[0], $row['email']), $result['items']),
        ]);
    }

    /** Détail : étapes réellement exécutées, évènements, erreur, alerte (consultation journalisée). */
    #[Route('/api/admin/exports/{id}', name: 'admin_exports_show', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $job = $this->jobs->findOneByPublicId($id);
        if ($job === null) {
            return new JsonResponse(['message' => 'Export introuvable'], JsonResponse::HTTP_NOT_FOUND);
        }
        $this->manager->checkLiveness($job);
        $this->manager->event($job, ExportJobEvent::ADMIN_VIEWED, $job->getStep(), null, ['adminId' => $this->adminId()]);
        $user = $this->manager->em()->find(User::class, $job->getUserId());

        return new JsonResponse($this->presenter->forAdmin($job, $user?->getEmail(), $this->events->findForJob($job), true));
    }

    /** Nouvelle tentative contrôlée d'une alerte e-mail en échec. */
    #[Route('/api/admin/exports/{id}/alert/retry', name: 'admin_exports_alert_retry', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function retryAlert(string $id, ExportAlerter $alerter): JsonResponse
    {
        $job = $this->jobs->findOneByPublicId($id);
        if ($job === null) {
            return new JsonResponse(['message' => 'Export introuvable'], JsonResponse::HTTP_NOT_FOUND);
        }
        if ($job->getAlertStatus() !== ExportJob::ALERT_FAILED) {
            return new JsonResponse(['message' => "Aucune alerte en échec pour cet export"], JsonResponse::HTTP_CONFLICT);
        }
        $sent = $alerter->retry($job, true);
        $this->manager->log('info', $job, 'Nouvelle tentative d\'alerte demandée par un administrateur', ['adminId' => $this->adminId(), 'sent' => $sent]);
        $user = $this->manager->em()->find(User::class, $job->getUserId());

        return new JsonResponse(['sent' => $sent] + $this->presenter->forAdmin($job, $user?->getEmail(), $this->events->findForJob($job), true),
            $sent ? JsonResponse::HTTP_OK : JsonResponse::HTTP_BAD_GATEWAY);
    }

    private function adminId(): ?int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    private static function day(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null)
            : null;
    }
}
