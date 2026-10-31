<?php

namespace App\Controller;

use App\Entity\PartnerEvent;
use App\Repository\PartnerLogoRepository;
use App\Repository\PartnerRepository;
use App\Service\PartnerEvents;
use App\Service\PartnerPlacementManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Firmes partenaires affichées (routes publiques : écran de connexion, tableau de bord, barre
 * latérale, menu mobile, page « Nos partenaires », accueil public) et leur mesure.
 * Administration : AdminControllers/PartnerFirmAdminController et PartnerPlacementAdminController.
 */
class PartnerController extends AbstractController
{
    public function __construct(private readonly PartnerPlacementManager $placements)
    {
    }

    /**
     * Firme de chaque emplacement aujourd'hui : { login_fallback: firme|null, …,
     * partners_page: [firmes] }. Aucune donnée personnelle.
     */
    #[Route('/api/partner/placements', name: 'partner_placements', methods: ['GET'])]
    public function placements(): JsonResponse
    {
        return new JsonResponse($this->placements->placementsOn(), Response::HTTP_OK, ['Cache-Control' => 'no-cache']);
    }

    /**
     * Ancienne API (clients d'avant les emplacements) : une seule firme, celle du tableau de
     * bord en priorité ; 204 si aucun emplacement n'est occupé.
     */
    #[Route('/api/partner/active', name: 'partner_active', methods: ['GET'])]
    public function active(): Response
    {
        $partner = $this->placements->legacyActive();
        if ($partner === null) {
            return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-cache']);
        }

        return new JsonResponse($partner, Response::HTTP_OK, ['Cache-Control' => 'no-cache']);
    }

    /** Fiche d'une firme diffusée aujourd'hui (lien de son emplacement) ; 404 sinon. */
    #[Route('/api/partner/{id<\d+>}', name: 'partner_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $partner = $this->placements->livePartner($id);
        if ($partner === null) {
            return new JsonResponse(['message' => 'Partenaire introuvable.'], Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-cache']);
        }

        return new JsonResponse($partner, Response::HTTP_OK, ['Cache-Control' => 'no-cache']);
    }

    /**
     * Impression réellement vue ou clic sur un emplacement partenaire : { partner, placement,
     * type: impression|click, visitor } (mêmes règles que /api/marketing/events). Firme non
     * diffusée sur cet emplacement aujourd'hui : rien n'est compté. Public, limité en débit.
     */
    #[Route('/api/partner/events', name: 'partner_event', methods: ['POST'])]
    public function event(Request $request, PartnerRepository $partners, PartnerEvents $events): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $fail = static fn (string $message) => new JsonResponse(['message' => $message], Response::HTTP_BAD_REQUEST);
        if (!is_array($data)) {
            return $fail('Requête invalide.');
        }
        $kinds = ['impression' => PartnerEvent::KIND_IMPRESSION, 'click' => PartnerEvent::KIND_CLICK];
        $kind = $kinds[$data['type'] ?? null] ?? null;
        if ($kind === null) {
            return $fail("Type d'événement inconnu.");
        }
        $placement = $data['placement'] ?? null;
        if (!is_string($placement)) {
            return $fail('Emplacement inconnu.');
        }
        $user = $this->getUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $viewer = 'u:' . $user->getId();
        } elseif (is_string($data['visitor'] ?? null) && preg_match('/^[A-Za-z0-9-]{16,64}$/', $data['visitor'])) {
            $viewer = 'a:' . $data['visitor'];
        } else {
            return $fail('Identifiant de visiteur invalide.');
        }
        $partner = filter_var($data['partner'] ?? null, FILTER_VALIDATE_INT) ? $partners->find((int) $data['partner']) : null;
        if ($partner === null || !$this->placements->isLiveOn($partner, $placement)) {
            return new JsonResponse(['recorded' => false], Response::HTTP_ACCEPTED);
        }

        return new JsonResponse(['recorded' => $events->record($partner, $placement, $kind, $viewer)], Response::HTTP_ACCEPTED);
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
