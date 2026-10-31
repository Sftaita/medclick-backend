<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\Advertising\Emplacements;
use App\Entity\Marketing;
use App\Entity\PartnerPlacementAssignment as Assignment;
use App\Service\EmplacementManager;
use App\Service\PartnerPlacementConflict;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration « Publicités & partenaires », emplacement par emplacement (ROLE_ADMIN :
 * access_control ^/api/admin). Voir EmplacementManager et docs/PUBLICITES-PARTENAIRES.md.
 *
 * Contenus : type « ad » (publicité) ou « partner » (attribution d'une entreprise).
 * Toute modification est un brouillon jusqu'à POST /{key}/publish, sauf « deactivate » et les
 * réglages (immédiats). Conflit à la publication : 409 { message, conflicts }.
 */
#[Route('/api/admin/emplacements', requirements: ['key' => '[a-z_]+', 'id' => '\d+', 'type' => 'ad|partner'])]
final class EmplacementAdminController
{
    public function __construct(
        private readonly EmplacementManager $manager,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_emplacements', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'today' => $this->manager->today(),
            'screens' => Emplacements::SCREENS,
            'emplacements' => $this->manager->overview(),
        ]);
    }

    /** ?date=AAAA-MM-JJ : jour de l'aperçu (aujourd'hui par défaut). */
    #[Route('/{key}', name: 'admin_emplacement', methods: ['GET'])]
    public function show(string $key, Request $request): JsonResponse
    {
        return $this->run($key, function () use ($key, $request) {
            $date = $request->query->get('date');
            if ($date !== null && (\DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date)) {
                throw new \InvalidArgumentException("Date d'aperçu invalide (AAAA-MM-JJ).");
            }

            return $this->manager->detail($key, $date);
        });
    }

    /** { enabled?: bool, frequency?: every_login|daily|weekly } : immédiat. */
    #[Route('/{key}/settings', name: 'admin_emplacement_settings', methods: ['PUT'])]
    public function settings(string $key, Request $request): JsonResponse
    {
        return $this->run($key, function () use ($key, $request) {
            $this->manager->updateSettings($key, self::body($request));

            return $this->manager->detail($key);
        });
    }

    /** Nouveau contenu (brouillon) : { type: ad|partner, … }. */
    #[Route('/{key}/contents', name: 'admin_emplacement_content_create', methods: ['POST'])]
    public function create(string $key, Request $request): JsonResponse
    {
        return $this->run($key, function () use ($key, $request) {
            $data = self::body($request);
            if (($data['type'] ?? 'ad') === 'partner') {
                $this->manager->createAssignment($key, $data);
            } else {
                $this->manager->createAd($key, $data);
            }

            return $this->manager->detail($key);
        }, Response::HTTP_CREATED);
    }

    #[Route('/{key}/contents/{type}/{id}', name: 'admin_emplacement_content_update', methods: ['PUT'])]
    public function update(string $key, string $type, int $id, Request $request): JsonResponse
    {
        return $this->run($key, function () use ($key, $type, $id, $request) {
            $content = $this->find($type, $id);
            $content instanceof Marketing
                ? $this->manager->updateAd($key, $content, self::body($request))
                : $this->manager->updateAssignment($key, $content, self::body($request));

            return $this->manager->detail($key);
        });
    }

    #[Route('/{key}/contents/{type}/{id}', name: 'admin_emplacement_content_delete', methods: ['DELETE'])]
    public function delete(string $key, string $type, int $id): JsonResponse
    {
        return $this->run($key, function () use ($key, $type, $id) {
            $content = $this->find($type, $id);
            $content instanceof Marketing ? $this->manager->deleteAd($key, $content) : $this->manager->deleteAssignment($key, $content);

            return $this->manager->detail($key);
        });
    }

    /** deactivate (immédiat), reactivate (en attente), discard (annule les modifications). */
    #[Route('/{key}/contents/{type}/{id}/{action}', name: 'admin_emplacement_content_action', methods: ['POST'], requirements: ['action' => 'deactivate|reactivate|discard'])]
    public function action(string $key, string $type, int $id, string $action): JsonResponse
    {
        return $this->run($key, function () use ($key, $type, $id, $action) {
            $content = $this->find($type, $id);
            match (true) {
                $content instanceof Marketing && $action === 'deactivate' => $this->manager->deactivateAd($key, $content),
                $content instanceof Marketing && $action === 'reactivate' => $this->manager->reactivateAd($key, $content),
                $content instanceof Marketing => $this->manager->discardAd($key, $content),
                $action === 'deactivate' => $this->manager->deactivateAssignment($key, $content),
                $action === 'discard' => $this->manager->discardAssignment($key, $content),
                default => throw new \InvalidArgumentException('Une attribution retirée ne se réactive pas : créez-en une nouvelle.'),
            };

            return $this->manager->detail($key);
        });
    }

    /** { ids: [publicités dans l'ordre d'affichage] } : en attente de publication. */
    #[Route('/{key}/order', name: 'admin_emplacement_order', methods: ['PUT'])]
    public function order(string $key, Request $request): JsonResponse
    {
        return $this->run($key, function () use ($key, $request) {
            $this->manager->reorder($key, self::body($request)['ids'] ?? null);

            return $this->manager->detail($key);
        });
    }

    /** { replace?: bool } : publie toutes les modifications de l'emplacement. */
    #[Route('/{key}/publish', name: 'admin_emplacement_publish', methods: ['POST'])]
    public function publish(string $key, Request $request): JsonResponse
    {
        return $this->run($key, function () use ($key, $request) {
            $published = $this->manager->publish($key, (self::body($request, true)['replace'] ?? false) === true);

            return ['published' => $published] + $this->manager->detail($key);
        });
    }

    #[Route('/{key}/discard', name: 'admin_emplacement_discard', methods: ['POST'])]
    public function discard(string $key): JsonResponse
    {
        return $this->run($key, function () use ($key) {
            $this->manager->discardAll($key);

            return $this->manager->detail($key);
        });
    }

    private function run(string $key, \Closure $action, int $status = Response::HTTP_OK): JsonResponse
    {
        if (!Emplacements::exists($key)) {
            return new JsonResponse(['message' => 'Emplacement inconnu.'], Response::HTTP_NOT_FOUND);
        }
        try {
            return new JsonResponse($action(), $status);
        } catch (PartnerPlacementConflict $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'conflicts' => $e->conflicts], Response::HTTP_CONFLICT);
        } catch (NotFoundHttpException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    private function find(string $type, int $id): Marketing|Assignment
    {
        $content = $this->em->find($type === 'ad' ? Marketing::class : Assignment::class, $id);

        return $content ?? throw new NotFoundHttpException('Contenu introuvable : rechargez la page.');
    }

    private static function body(Request $request, bool $optional = false): mixed
    {
        $content = $request->getContent();
        if ($optional && trim($content) === '') {
            return [];
        }

        return json_decode($content, true);
    }
}

