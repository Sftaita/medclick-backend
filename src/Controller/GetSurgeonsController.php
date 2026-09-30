<?php

namespace App\Controller;

use App\Repository\YearsRepository;
use App\Repository\SurgeonsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


class GetSurgeonsController extends AbstractController
{
    /**
     * Liste des chirurgiens (id, prénom, nom, maître de stage) d'une année de l'utilisateur connecté.
     */
    #[Route('/api/list/{id}', name: 'list', methods: ['GET'])]
    public function CheckDate($id, SurgeonsRepository $surgeonsRepository, YearsRepository $yearsRepository)
    {
        $year = $yearsRepository->find($id);

        // L'année doit exister et appartenir à l'utilisateur connecté.
        if (!$year || !$this->isGranted('OWNER', $year)) {
            return new JsonResponse(['message' => "Cette année ne vous appartient pas"], JsonResponse::HTTP_FORBIDDEN);
        }

        // findSurgeons() renvoie des tableaux scalaires (id, firstName, lastName, boss).
        return new JsonResponse($surgeonsRepository->findSurgeons($year));
    }
}
