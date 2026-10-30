<?php

declare(strict_types=1);

namespace App\Controller\AdminControllers;

use App\Entity\PartnerPlacementAssignment;
use App\Repository\PartnerPlacementAssignmentRepository;
use App\Service\PartnerPlacementManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Administration (ROLE_ADMIN par security.yaml). La prod continue l'ancienne API tant que la PWA n'a pas migré. */
#[Route('/api/admin/partner-placements')]
final class PartnerPlacementAdminController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnerPlacementAssignmentRepository $repository,
        private readonly PartnerPlacementManager $manager,
    ) {
    }

    #[Route('', name: 'partner_placement_admin_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'placements' => PartnerPlacementManager::KEYS,
            'active' => $this->manager->activeMap(),
            'assignments' => $this->manager->history(),
        ]);
    }

    #[Route('', name: 'partner_placement_admin_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $a = new PartnerPlacementAssignment();
        $data = json_decode($request->getContent(), true);
        try {
            $this->manager->apply($a, $data);
            if (is_array($data) && ($data['publish'] ?? false) === true) {
                $this->manager->publish($a);
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }

        $this->em->persist($a);
        $this->em->flush();
        return new JsonResponse($this->manager->toArray($a), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'partner_placement_admin_update', methods: ['PUT'])]
    public function update(PartnerPlacementAssignment $a, Request $request): JsonResponse
    {
        if ($a->getStatus() === PartnerPlacementAssignment::WITHDRAWN) {
            return new JsonResponse(['message' => 'Affectation retirée.'], 409);
        }
        $data = json_decode($request->getContent(), true);
        try {
            $this->manager->apply($a, $data);
            if ($a->getStatus() === PartnerPlacementAssignment::PUBLISHED || (is_array($data) && ($data['publish'] ?? false) === true)) {
                $this->manager->publish($a);
            }
        } catch (\InvalidArgumentException $e) {
            // Un mauvais payload ne doit jamais laisser une entité modifiée en mémoire.
            $this->em->clear();
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }
        $this->em->flush();
        return new JsonResponse($this->manager->toArray($a));
    }

    #[Route('/{id<\d+>}/publish', name: 'partner_placement_admin_publish', methods: ['POST'])]
    public function publish(PartnerPlacementAssignment $a): JsonResponse
    {
        if ($a->getStatus() === PartnerPlacementAssignment::WITHDRAWN) {
            return new JsonResponse(['message' => 'Affectation retirée.'], 409);
        }
        try {
            $this->manager->publish($a);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }
        $this->em->flush();
        return new JsonResponse($this->manager->toArray($a));
    }

    #[Route('/{id<\d+>}/withdraw', name: 'partner_placement_admin_withdraw', methods: ['POST'])]
    public function withdraw(PartnerPlacementAssignment $a): JsonResponse
    {
        if ($a->getStatus() !== PartnerPlacementAssignment::PUBLISHED) {
            return new JsonResponse(['message' => 'Seule une affectation publiée peut être retirée.'], 409);
        }
        $a->withdraw();
        $this->em->flush();
        return new JsonResponse($this->manager->toArray($a));
    }
}
