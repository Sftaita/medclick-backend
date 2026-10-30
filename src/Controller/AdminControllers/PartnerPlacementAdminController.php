<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\Entity\PartnerPlacementAssignment as Assignment;
use App\Service\PartnerEvents;
use App\Service\PartnerPlacementConflict;
use App\Service\PartnerPlacementManager;
use App\Service\SponsorStats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Emplacements partenaires (ROLE_ADMIN : access_control ^/api/admin) : calendrier des
 * affectations, attribution (un ou plusieurs emplacements, remplacement confirmé),
 * publication, retrait, suppression des brouillons et statistiques.
 *
 * Emplacement déjà attribué sur la période : 409 avec la liste des affectations en conflit ;
 * renvoyer la même requête avec « replace »: true pour remplacer.
 */
#[Route('/api/admin/partner-placements')]
final class PartnerPlacementAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerPlacementManager $manager,
    ) {
    }

    #[Route('', name: 'admin_partner_placements', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $placements = [];
        foreach (Assignment::PLACEMENTS as $key) {
            $placements[] = ['key' => $key, 'label' => Assignment::LABELS[$key], 'shared' => Assignment::isShared($key)];
        }

        return new JsonResponse([
            'today' => $this->manager->today()->format('Y-m-d'),
            'placements' => $placements,
            'live' => $this->manager->placementsOn(),
            'assignments' => $this->manager->history(),
        ]);
    }

    /** Une affectation par emplacement demandé : { placements, partnerId, startsOn, endsOn, publish, replace }. */
    #[Route('', name: 'admin_partner_placement_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $created = $this->manager->assign(json_decode($request->getContent(), true));
        } catch (PartnerPlacementConflict $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'conflicts' => $e->conflicts], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['assignments' => array_map($this->manager->toArray(...), $created)], Response::HTTP_CREATED);
    }

    /** Modification (firme, dates). Une affectation publiée est republiée (conflits recontrôlés). */
    #[Route('/{id<\d+>}', name: 'admin_partner_placement_update', methods: ['PUT'])]
    public function update(Assignment $assignment, Request $request): JsonResponse
    {
        if ($assignment->getStatus() === Assignment::WITHDRAWN) {
            return new JsonResponse(['message' => 'Affectation retirée : créez-en une nouvelle.'], Response::HTTP_CONFLICT);
        }
        $data = json_decode($request->getContent(), true);

        return $this->save($assignment, $data, $assignment->getStatus() === Assignment::PUBLISHED || (is_array($data) && ($data['publish'] ?? false) === true));
    }

    #[Route('/{id<\d+>}/publish', name: 'admin_partner_placement_publish', methods: ['POST'])]
    public function publish(Assignment $assignment, Request $request): JsonResponse
    {
        if ($assignment->getStatus() !== Assignment::DRAFT) {
            return new JsonResponse(['message' => 'Seul un brouillon peut être publié.'], Response::HTTP_CONFLICT);
        }

        return $this->save($assignment, null, true, (json_decode($request->getContent() ?: '{}', true)['replace'] ?? false) === true);
    }

    /** Arrête l'affichage (en ligne ou programmée) ; l'emplacement est libéré, l'historique conservé. */
    #[Route('/{id<\d+>}/withdraw', name: 'admin_partner_placement_withdraw', methods: ['POST'])]
    public function withdraw(Assignment $assignment): JsonResponse
    {
        if ($assignment->getStatus() !== Assignment::PUBLISHED) {
            return new JsonResponse(['message' => 'Seule une affectation publiée peut être retirée.'], Response::HTTP_CONFLICT);
        }
        $assignment->withdraw();
        $this->em->flush();

        return new JsonResponse($this->manager->toArray($assignment));
    }

    #[Route('/{id<\d+>}', name: 'admin_partner_placement_delete', methods: ['DELETE'])]
    public function delete(Assignment $assignment): JsonResponse
    {
        if ($assignment->getStatus() !== Assignment::DRAFT) {
            return new JsonResponse(['message' => 'Seul un brouillon peut être supprimé ; retirez plutôt l\'affectation.'], Response::HTTP_CONFLICT);
        }
        $this->em->remove($assignment);
        $this->em->flush();

        return new JsonResponse(['message' => 'Brouillon supprimé.']);
    }

    /** Impressions réellement vues et clics par firme et emplacement (?from=&to=, 30 derniers jours par défaut). */
    #[Route('/stats', name: 'admin_partner_placement_stats', methods: ['GET'])]
    public function stats(Request $request, PartnerEvents $events): JsonResponse
    {
        try {
            [$from, $to] = SponsorStats::period($request->query->get('from'), $request->query->get('to'), $events->today());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($events->report($from, $to));
    }

    private function save(Assignment $assignment, mixed $data, bool $publish, bool $replace = false): JsonResponse
    {
        if (is_array($data)) {
            $replace = $replace || ($data['replace'] ?? false) === true;
        }
        try {
            $this->em->wrapInTransaction(function () use ($assignment, $data, $publish, $replace): void {
                if ($data !== null) {
                    $this->manager->apply($assignment, $data);
                }
                if ($publish) {
                    $this->manager->publish($assignment, $replace);
                }
                $this->em->flush();
            });
        } catch (PartnerPlacementConflict $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'conflicts' => $e->conflicts], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->manager->toArray($assignment));
    }
}
