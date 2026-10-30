<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PartnerPlacementManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Lecture publique des emplacements ; les écrans sensibles restent gérés par le frontend. */
final class PartnerPlacementController
{
    public function __construct(private readonly PartnerPlacementManager $manager)
    {
    }

    #[Route('/api/partner/placements', name: 'partner_placements', methods: ['GET'])]
    public function active(): JsonResponse
    {
        return new JsonResponse($this->manager->activeMap(), 200, ['Cache-Control' => 'no-store']);
    }
}
