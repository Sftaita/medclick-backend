<?php

namespace App\Controller;

use App\Repository\PartnerLogoRepository;
use App\Service\PartnerManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Partenaire affiché (routes publiques : écran de connexion, tableau de bord, barre latérale,
 * page « Nos partenaires »). Administration : AdminControllers/PartnerAdminController.
 */
class PartnerController extends AbstractController
{
    public function __construct(private readonly PartnerManager $manager)
    {
    }

    /** Partenaire affiché aujourd'hui ; 204 s'il n'y en a pas (emplacements masqués). */
    #[Route('/api/partner/active', name: 'partner_active', methods: ['GET'])]
    public function active(): Response
    {
        $partner = $this->manager->findLive();
        if ($partner === null) {
            return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-cache']);
        }

        return new JsonResponse($this->manager->toArray($partner), Response::HTTP_OK, ['Cache-Control' => 'no-cache']);
    }

    /**
     * Logo (PNG ou SVG validé). URL adressée par le contenu : cache long sans risque. CSP sans
     * script ni ressource externe, même en ouverture directe d'un SVG.
     */
    #[Route('/api/partner/logo/{file}', name: 'partner_logo', methods: ['GET'])]
    public function logo(string $file, PartnerLogoRepository $logos): Response
    {
        $logo = $logos->findByFileName($file);
        if ($logo === null) {
            return new JsonResponse(['message' => 'Logo introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new Response($logo->getData(), Response::HTTP_OK, [
            'Content-Type' => $logo->getMimeType(),
            'Content-Disposition' => 'inline; filename="' . $logo->getFileName() . '"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
