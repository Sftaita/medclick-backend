<?php

namespace App\Controller\AdminControllers;

use App\Repository\ErrorLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consultation des erreurs rencontrées par les utilisateurs (ROLE_ADMIN via /api/admin).
 */
class ErrorLogController
{
    public function __construct(private ErrorLogRepository $errors)
    {
    }

    /**
     * Liste regroupée, de la plus récente à la plus ancienne.
     * Filtres : ?status=open|resolved|all (défaut open), ?source=api|console|pwa|admin,
     * ?limit= (max 200, défaut 50), ?offset=.
     */
    #[Route('/api/admin/errors', name: 'admin_errors_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('status', 'open');
        $limit = max(1, min(200, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $result = $this->errors->search(
            in_array($status, ['open', 'resolved'], true) ? $status : null,
            $request->query->get('source') ?: null,
            $limit,
            $offset
        );

        return new JsonResponse([
            'total' => $result['total'],
            'items' => array_map(fn ($error) => $error->toArray(), $result['items']),
        ]);
    }

    /** Détail complet : trace, contexte, navigateur. */
    #[Route('/api/admin/errors/{id<\d+>}', name: 'admin_errors_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $error = $this->errors->find($id);

        return $error
            ? new JsonResponse($error->toArray(true))
            : new JsonResponse(['message' => 'Erreur introuvable'], JsonResponse::HTTP_NOT_FOUND);
    }

    /** Marque une erreur comme résolue ({"resolved": true}) ou la rouvre ({"resolved": false}). */
    #[Route('/api/admin/errors/{id<\d+>}', name: 'admin_errors_update', methods: ['PUT'])]
    public function update(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $error = $this->errors->find($id);
        if (!$error) {
            return new JsonResponse(['message' => 'Erreur introuvable'], JsonResponse::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_bool($data['resolved'] ?? null)) {
            return new JsonResponse(['message' => 'Champ "resolved" (booléen) attendu'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $error->setResolvedAt($data['resolved'] ? new \DateTimeImmutable() : null);
        $em->flush();

        return new JsonResponse($error->toArray());
    }
}
