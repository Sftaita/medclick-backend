<?php

namespace App\Controller\AdminControllers;

use App\Entity\Marketing;
use App\Repository\MarketingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/api/admin', name: 'marketing')]
class MarketingController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

   #[Route('/marketing', name: 'index', methods: ['GET'])]
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


    #[Route('/marketing/{id}', name: 'get_campaign', methods: ['GET'])]
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




   #[Route('/marketing', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if ($error = $this->validationError($data)) {
            return $error;
        }

        $marketing = new Marketing();
        $this->hydrate($marketing, $data);

        $this->entityManager->persist($marketing);
        $this->entityManager->flush();

        return $this->json(['status' => 'Marketing campaign created'], Response::HTTP_CREATED);
    }


    #[Route('/marketing/{id}', name: 'edit', methods: ['PUT'])]
    public function edit(Request $request, Marketing $marketing): Response
    {
        $data = json_decode($request->getContent(), true);
        if ($error = $this->validationError($data)) {
            return $error;
        }

        $this->hydrate($marketing, $data);
        $this->entityManager->flush();

        return $this->json(['status' => 'Marketing campaign updated']);
    }

    /**
     * Champs de la campagne (données déjà validées). Une date de fin vide ou absente retire la
     * date de fin (campagne sans échéance) : auparavant, elle ne pouvait plus être effacée.
     */
    private function hydrate(Marketing $marketing, array $data): void
    {
        $marketing->setCampaignName(trim($data['campaign_name']));
        $marketing->setStatus($data['status']);
        $marketing->setStartDate(new \DateTime($data['start_date']));
        $marketing->setEndDate(empty($data['end_date']) ? null : new \DateTime($data['end_date']));
        $marketing->setDuration((int) ($data['duration'] ?? 5));
        $marketing->setRedirectUrl(empty($data['redirect_url']) ? null : $data['redirect_url']);
        $marketing->setSmartphoneFormat(empty($data['smartphone_format']) ? null : $data['smartphone_format']);
        $marketing->setTabletPortraitFormat(empty($data['tablet_portrait_format']) ? null : $data['tablet_portrait_format']);
        $marketing->setTabletLandscapeFormat(empty($data['tablet_landscape_format']) ? null : $data['tablet_landscape_format']);
        $marketing->setScreen14InchFormat(empty($data['screen_14_inch_format']) ? null : $data['screen_14_inch_format']);
        $marketing->setLargeScreenFormat(empty($data['large_screen_format']) ? null : $data['large_screen_format']);
    }

    private const STATUSES = ['active', 'inactive'];

    private const FORMATS = [
        'smartphone_format' => 'Visuel smartphone',
        'tablet_portrait_format' => 'Visuel tablette portrait',
        'tablet_landscape_format' => 'Visuel tablette paysage',
        'screen_14_inch_format' => 'Visuel écran 14 pouces',
        'large_screen_format' => 'Visuel grand écran',
    ];

    /**
     * Contrôle des champs : 400 avec un message lisible au lieu d'une erreur 500 (champ manquant,
     * date invalide, texte trop long pour la colonne en mode SQL strict). Les visuels sont des URL
     * http(s) d'images hébergées : une image collée en data:… dépasse 500 caractères et était
     * tronquée, donc illisible.
     */
    private function validationError(mixed $data): ?JsonResponse
    {
        $fail = static fn (string $message) => new JsonResponse(['message' => $message], Response::HTTP_BAD_REQUEST);

        if (!is_array($data)) {
            return $fail('Requête invalide.');
        }
        $name = $data['campaign_name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return $fail('Le nom de la campagne est obligatoire.');
        }
        if (mb_strlen(trim($name)) > 400) {
            return $fail('Le nom de la campagne ne doit pas dépasser 400 caractères.');
        }
        if (!in_array($data['status'] ?? null, self::STATUSES, true)) {
            return $fail('Le statut doit être « active » ou « inactive ».');
        }
        $start = self::date($data['start_date'] ?? null);
        if (!$start) {
            return $fail('La date de début est obligatoire (AAAA-MM-JJ).');
        }
        if (!empty($data['end_date'])) {
            $end = self::date($data['end_date']);
            if (!$end) {
                return $fail('La date de fin est invalide (AAAA-MM-JJ).');
            }
            if ($end < $start) {
                return $fail('La date de fin doit suivre la date de début.');
            }
        }
        $duration = $data['duration'] ?? 5;
        if (filter_var($duration, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 15]]) === false) {
            return $fail("La durée d'affichage doit être un nombre entier de 1 à 15 secondes.");
        }
        if (!empty($data['redirect_url']) && !self::isHttpUrl($data['redirect_url'])) {
            return $fail("L'URL de redirection doit commencer par https:// ou http:// (500 caractères maximum).");
        }
        $hasVisual = false;
        foreach (self::FORMATS as $key => $label) {
            if (empty($data[$key])) {
                continue;
            }
            if (!self::isHttpUrl($data[$key])) {
                return $fail(sprintf("%s : indiquez l'URL https:// d'une image hébergée (500 caractères maximum, pas d'image collée).", $label));
            }
            $hasVisual = true;
        }
        if (!$hasVisual) {
            return $fail("Indiquez au moins un visuel (URL d'image).");
        }

        return null;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function isHttpUrl(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 500
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }


    #[Route('/marketing/{id}', name: 'delete_marketing_campaign', methods: ['DELETE'])]
    public function delete(Marketing $marketing, EntityManagerInterface $entityManager): JsonResponse
    {
        // Historique des affichages (marketing_view, clé étrangère sans cascade) : supprimé avec la
        // campagne, sinon une campagne déjà affichée ne pouvait pas être supprimée (erreur 500).
        $entityManager->createQuery('DELETE FROM App\Entity\MarketingView v WHERE v.marketing = :marketing')
            ->setParameter('marketing', $marketing)
            ->execute();
        $entityManager->remove($marketing);
        $entityManager->flush();

        return new JsonResponse(['status' => 'Marketing campaign deleted']);
    }

    #[Route('/marketing/{id}/status', name: 'update_status', methods: ['PUT'])]
    public function updateStatus(Request $request, Marketing $marketing): Response
    {
        $data = json_decode($request->getContent(), true);

        // On s'assure que le champ 'status' est présent dans la requête
        if (!isset($data['status'])) {
            return new JsonResponse(['error' => 'Status field is required'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($data['status'], self::STATUSES, true)) {
            return new JsonResponse(['message' => 'Le statut doit être « active » ou « inactive ».'], Response::HTTP_BAD_REQUEST);
        }

        // Met à jour le statut de la campagne
        $marketing->setStatus($data['status']);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'Marketing campaign status updated']);
    }
}
