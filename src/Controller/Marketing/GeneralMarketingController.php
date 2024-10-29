<?php

namespace App\Controller\Marketing;

use DateTime;
use App\Entity\MarketingView;
use App\Repository\MarketingRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class GeneralMarketingController extends AbstractController
{

    private $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }
    
    /**
     * @Route("/api/marketing/active", name="get_active_campaign", methods={"GET"})
     */
    public function getActiveCampaign(MarketingRepository $marketingRepository): JsonResponse
    {
        $currentDate = new DateTime(); // Date actuelle

        // Utiliser la méthode du repository pour trouver les campagnes actives
        $campaigns = $marketingRepository->findActiveCampaigns($currentDate);

        // Si aucune campagne active n'est trouvée
        if (!$campaigns) {
            return new JsonResponse(['message' => 'No active campaign found'], JsonResponse::HTTP_NOT_FOUND);
        }

        // Sélectionner une campagne au hasard
        $randomCampaign = $campaigns[array_rand($campaigns)];

        // Incrémenter les vues de la campagne sélectionnée
        $randomCampaign->setViews($randomCampaign->getViews() + 1);
        
        // Enregistrer une nouvelle vue dans la table MarketingView
        $view = new MarketingView();
        $view->setMarketing($randomCampaign);
        $view->setViewedAt(new DateTime()); // Date et heure actuelles

        // Sauvegarder les modifications dans la base de données
        $entityManager = $this->doctrine->getManager();  
        $entityManager->persist($randomCampaign);
        $entityManager->persist($view);
        $entityManager->flush();

        // Renvoyer les informations de la campagne en JSON
        $campaignData = [
            'id' => $randomCampaign->getId(),
            'campaign_name' => $randomCampaign->getCampaignName(),
            'status' => $randomCampaign->getStatus(),
            'start_date' => $randomCampaign->getStartDate()->format('Y-m-d'),
            'end_date' => $randomCampaign->getEndDate() ? $randomCampaign->getEndDate()->format('Y-m-d') : null,
            'views' => $randomCampaign->getViews(), // Après l'incrémentation
            'clicks' => $randomCampaign->getClicks(),
            'formats' => [
                'smartphone' => $randomCampaign->getSmartphoneFormat(),
                'tablet_portrait' => $randomCampaign->getTabletPortraitFormat(),
                'tablet_landscape' => $randomCampaign->getTabletLandscapeFormat(),
                'screen_14_inch' => $randomCampaign->getScreen14InchFormat(),
                'large_screen' => $randomCampaign->getLargeScreenFormat(),
            ]
        ];

        return new JsonResponse($campaignData, JsonResponse::HTTP_OK);
    }
}