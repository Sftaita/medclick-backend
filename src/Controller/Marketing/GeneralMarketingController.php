<?php

namespace App\Controller\Marketing;

use DateTime;
use App\Entity\Marketing;
use App\Entity\MarketingImage;
use App\Entity\MarketingView;
use App\Service\SponsorDelivery;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\MarketingImageRepository;
use App\Repository\MarketingRepository;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class GeneralMarketingController extends AbstractController
{
    private $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    #[Route('/api/marketing/active', name: 'get_active_campaign', methods: ['GET'])]
    public function getActiveCampaign(MarketingRepository $marketingRepository, SponsorDelivery $delivery): JsonResponse
    {
        // Campagnes plein écran à la connexion uniquement (les autres emplacements : /slots).
        $campaigns = array_values(array_filter(
            $marketingRepository->findLiveOn($delivery->today()),
            static fn (Marketing $m) => $m->hasPlacement(Marketing::PLACEMENT_LOGIN_INTERSTITIAL) && SponsorDelivery::hasVisualFor($m, Marketing::PLACEMENT_LOGIN_INTERSTITIAL),
        ));

        // Si aucune campagne active n'est trouvée
        if (!$campaigns) {
            return new JsonResponse(['message' => 'No active campaign found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Sélectionner une campagne au hasard
        $randomCampaign = $campaigns[array_rand($campaigns)];

        // Plus aucun comptage ici : le front précharge la campagne sans l'afficher. Les impressions
        // réellement vues arrivent par POST /api/marketing/events (docs/SPONSORS.md).

        // Déterminer la durée avec une valeur par défaut de 5
        $duration = $randomCampaign->getDuration();

        // Renvoyer les informations de la campagne en JSON
        $campaignData = [
            'id' => $randomCampaign->getId(),
            'campaign_name' => $randomCampaign->getCampaignName(),
            'status' => $randomCampaign->getStatus(),
            'start_date' => $randomCampaign->getStartDate()->format('Y-m-d'),
            'end_date' => $randomCampaign->getEndDate() ? $randomCampaign->getEndDate()->format('Y-m-d') : null,
            'views' => $randomCampaign->getViews(), // Compteur historique (figé)
            'clicks' => $randomCampaign->getClicks(),
            'duration' => $duration, // Inclure la durée
            'formats' => [
                'smartphone' => $randomCampaign->getSmartphoneFormat(),
                'tablet_portrait' => $randomCampaign->getTabletPortraitFormat(),
                'tablet_landscape' => $randomCampaign->getTabletLandscapeFormat(),
                'screen_14_inch' => $randomCampaign->getScreen14InchFormat(),
                'large_screen' => $randomCampaign->getLargeScreenFormat(),
            ],
            'redirect_url' => $randomCampaign->getRedirectUrl(), // Inclure l'URL de redirection
        ];

        return new JsonResponse($campaignData, JsonResponse::HTTP_OK);
    }

    /**
     * Campagnes affichées aujourd'hui sur chaque emplacement en carte (connexion, tableau de bord
     * ordinateur et mobile), MAX_ROTATION au plus. Public (page de connexion), lecture seule :
     * l'appel ne compte aucune impression.
     */
    #[Route('/api/marketing/slots', name: 'marketing_slots', methods: ['GET'])]
    public function slots(SponsorDelivery $delivery): JsonResponse
    {
        $slots = array_map(
            static fn (array $campaigns) => array_map($delivery->toPublicArray(...), $campaigns),
            $delivery->slots(),
        );

        return new JsonResponse($slots, JsonResponse::HTTP_OK, ['Cache-Control' => 'no-cache']);
    }

    /**
     * Impression réellement vue (au moins 50 % visible pendant 1 s) ou clic, envoyé par le front :
     * { campaign, placement, type: impression|click, visitor }. visitor : identifiant aléatoire du
     * navigateur (16 à 64 caractères), ignoré si l'utilisateur est connecté. Doublons du même
     * visiteur ignorés sur 30 minutes (recorded: false). Public, limité en débit par IP.
     */
    #[Route('/api/marketing/events', name: 'marketing_event', methods: ['POST'])]
    public function event(Request $request, MarketingRepository $marketingRepository, SponsorDelivery $delivery): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $fail = static fn (string $message) => new JsonResponse(['message' => $message], JsonResponse::HTTP_BAD_REQUEST);
        if (!is_array($data)) {
            return $fail('Requête invalide.');
        }
        $kinds = ['impression' => MarketingView::KIND_IMPRESSION, 'click' => MarketingView::KIND_CLICK];
        $kind = $kinds[$data['type'] ?? null] ?? null;
        if ($kind === null) {
            return $fail("Type d'événement inconnu.");
        }
        $placement = $data['placement'] ?? null;
        if (!is_string($placement) || !in_array($placement, Marketing::PLACEMENTS, true)) {
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

        $campaign = filter_var($data['campaign'] ?? null, FILTER_VALIDATE_INT) ? $marketingRepository->find((int) $data['campaign']) : null;
        // Campagne inconnue, terminée ou pas diffusée sur cet emplacement : rien n'est compté.
        if ($campaign === null || !$delivery->isServable($campaign, $placement)) {
            return new JsonResponse(['recorded' => false], JsonResponse::HTTP_ACCEPTED);
        }

        return new JsonResponse(['recorded' => $delivery->record($campaign, $placement, $kind, $viewer)], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * Visuel importé (page de connexion, sans JWT). Nom = empreinte du contenu : mis en cache
     * sans limite. Servi comme une image inerte (nosniff, CSP sans script).
     */
    #[Route('/api/marketing/image/{file}', name: 'marketing_image', methods: ['GET'], requirements: ['file' => '[a-f0-9]{64}\.webp'])]
    public function image(string $file, MarketingImageRepository $images): Response
    {
        $image = $images->findByFileName($file);
        if ($image === null) {
            return new JsonResponse(['message' => 'Visuel introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new Response($image->getData(), Response::HTTP_OK, [
            'Content-Type' => MarketingImage::MIME_TYPE,
            'Content-Disposition' => 'inline; filename="' . $image->getFileName() . '"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * Clic sur la publicité (page de connexion de la PWA, sans JWT) : incrémente le compteur
     * affiché dans l'admin. Public mais limité en débit (App\Security\PublicEndpointRateLimiter).
     */
    #[Route('/api/marketing/incrementCampaign/{id<\d+>}', name: 'increment_campaign_click', methods: ['PUT'])]
    public function incrementClick(int $id): JsonResponse
    {
        // Incrément atomique en base : aucun clic perdu en cas d'appels simultanés.
        $updated = $this->doctrine->getManager()->createQuery(
            'UPDATE App\Entity\Marketing m SET m.clicks = m.clicks + 1 WHERE m.id = :id'
        )->setParameter('id', $id)->execute();

        if ($updated === 0) {
            return new JsonResponse(['message' => 'Campagne introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['message' => 'ok']);
    }
}
