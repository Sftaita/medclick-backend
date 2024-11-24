<?php

namespace App\Controller\AdminControllers;

use App\Entity\Marketing;
use App\Repository\MarketingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * @Route("/api/admin", name="marketing")
 */
class MarketingController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

   /**
     * @Route("/marketing", name="index", methods={"GET"})
     */
    public function index(MarketingRepository $marketingRepository): Response
    {
        $marketings = $marketingRepository->findAll();

        // Vérification et structuration des données pour chaque campagne
        $campaigns = [];
        foreach ($marketings as $marketing) {
            $campaigns[] = [
                'id' => $marketing->getId(),
                'campaign_name' => $marketing->getCampaignName(),
                'status' => $marketing->getStatus(),
                'start_date' => $marketing->getStartDate() ? $marketing->getStartDate()->format('Y-m-d') : null,
                'end_date' => $marketing->getEndDate() ? $marketing->getEndDate()->format('Y-m-d') : null,
                'views' => $marketing->getViews(),
                'clicks' => $marketing->getClicks(),
                'duration' => $marketing->getDuration(), // Ajout de la durée
                'redirect_url' => $marketing->getRedirectUrl(), // Ajout de l'URL de redirection
                'formats' => [
                    'smartphone' => $marketing->getSmartphoneFormat(),
                    'tablet_portrait' => $marketing->getTabletPortraitFormat(),
                    'tablet_landscape' => $marketing->getTabletLandscapeFormat(),
                    'screen_14_inch' => $marketing->getScreen14InchFormat(),
                    'large_screen' => $marketing->getLargeScreenFormat(),
                ]
            ];
        }

        return new JsonResponse($campaigns);
    }


    /**
     * @Route("/marketing/{id}", name="get_campaign", methods={"GET"})
     */
    public function getCampaignById(Marketing $marketing): Response
    {
        // Structure les données de la campagne pour la réponse JSON
        $campaignData = [
            'id' => $marketing->getId(),
            'campaign_name' => $marketing->getCampaignName(),
            'status' => $marketing->getStatus(),
            'start_date' => $marketing->getStartDate() ? $marketing->getStartDate()->format('Y-m-d') : null,
            'end_date' => $marketing->getEndDate() ? $marketing->getEndDate()->format('Y-m-d') : null,
            'views' => $marketing->getViews(),
            'clicks' => $marketing->getClicks(),
            'duration' => $marketing->getDuration(), // Ajout de la durée
            'redirect_url' => $marketing->getRedirectUrl(), // Ajout de l'URL de redirection
            'smartphone_format' => $marketing->getSmartphoneFormat(),
            'tablet_portrait_format' => $marketing->getTabletPortraitFormat(),
            'tablet_landscape_format' => $marketing->getTabletLandscapeFormat(),
            'screen_14_inch_format' => $marketing->getScreen14InchFormat(),
            'large_screen_format' => $marketing->getLargeScreenFormat(),
        ];

        return new JsonResponse($campaignData);
    }




   /**
     * @Route("/marketing", name="create", methods={"POST"})
     */
    public function create(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        $marketing = new Marketing();
        $marketing->setCampaignName($data['campaign_name']);
        $marketing->setStatus($data['status']);
        $marketing->setStartDate(new \DateTime($data['start_date']));
        $endDate = $data['end_date'] ?? null;
        if ($endDate) {
            $marketing->setEndDate(new \DateTime($endDate));
        }
        $marketing->setDuration($data['duration'] ?? 5); // Définit une valeur par défaut si non fourni
        $marketing->setRedirectUrl($data['redirect_url'] ?? null); // Null si non fourni
        $marketing->setSmartphoneFormat($data['smartphone_format'] ?? null);
        $marketing->setTabletPortraitFormat($data['tablet_portrait_format'] ?? null);
        $marketing->setTabletLandscapeFormat($data['tablet_landscape_format'] ?? null);
        $marketing->setScreen14InchFormat($data['screen_14_inch_format'] ?? null);
        $marketing->setLargeScreenFormat($data['large_screen_format'] ?? null);

        $this->entityManager->persist($marketing);
        $this->entityManager->flush();

        return $this->json(['status' => 'Marketing campaign created'], Response::HTTP_CREATED);
    }


    /**
     * @Route("/marketing/{id}", name="edit", methods={"PUT"})
     */
    public function edit(Request $request, Marketing $marketing): Response
    {
        $data = json_decode($request->getContent(), true);

        $marketing->setCampaignName($data['campaign_name']);
        $marketing->setStatus($data['status']);
        $marketing->setStartDate(new \DateTime($data['start_date']));
        $endDate = $data['end_date'] ?? null;
        if ($endDate) {
            $marketing->setEndDate(new \DateTime($endDate));
        }
        $marketing->setDuration($data['duration'] ?? 5); // Valeur par défaut si non fourni
        $marketing->setRedirectUrl($data['redirect_url'] ?? null); // Null si non fourni
        $marketing->setSmartphoneFormat($data['smartphone_format'] ?? null);
        $marketing->setTabletPortraitFormat($data['tablet_portrait_format'] ?? null);
        $marketing->setTabletLandscapeFormat($data['tablet_landscape_format'] ?? null);
        $marketing->setScreen14InchFormat($data['screen_14_inch_format'] ?? null);
        $marketing->setLargeScreenFormat($data['large_screen_format'] ?? null);

        $this->entityManager->flush();

        return $this->json(['status' => 'Marketing campaign updated']);
    }


    /**
     * @Route("/marketing/{id}", name="delete_marketing_campaign", methods={"DELETE"})
     */
    public function delete(Marketing $marketing, EntityManagerInterface $entityManager): JsonResponse
    {
        $entityManager->remove($marketing);
        $entityManager->flush();

        return new JsonResponse(['status' => 'Marketing campaign deleted']);
    }

    /**
     * @Route("/marketing/{id}/status", name="update_status", methods={"PUT"})
     */
    public function updateStatus(Request $request, Marketing $marketing): Response
    {
        $data = json_decode($request->getContent(), true);

        // On s'assure que le champ 'status' est présent dans la requête
        if (!isset($data['status'])) {
            return new JsonResponse(['error' => 'Status field is required'], Response::HTTP_BAD_REQUEST);
        }

        // Met à jour le statut de la campagne
        $marketing->setStatus($data['status']);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'Marketing campaign status updated']);
    }
}
