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
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @Route("/api/admin", name="marketing_")
 */
class MarketingController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/marketing", name="create", methods={"POST"})
     */
    public function create(Request $request, ValidatorInterface $validator): Response
    {
        $data = json_decode($request->getContent(), true);

        // Définir les contraintes de validation
        $constraints = new Assert\Collection([
            'campaign_name' => new Assert\NotBlank(['message' => 'Campaign name is required']),
            'status' => new Assert\Choice([
                'choices' => ['active', 'inactive'],
                'message' => 'Status must be either "active" or "inactive".'
            ]),
            'start_date' => new Assert\Date(['message' => 'Start date must be a valid date.']),
            'end_date' => [
                new Assert\Date(['message' => 'End date must be a valid date.']),
                new Assert\Expression([
                    'expression' => 'value >= this["start_date"]',
                    'message' => 'End date must be after or equal to the start date.',
                ])
            ],
            'duration' => [
                new Assert\Optional([
                    new Assert\Type(['type' => 'integer', 'message' => 'Duration must be an integer.']),
                    new Assert\Positive(['message' => 'Duration must be greater than zero.']),
                ]),
            ],
            'redirect_url' => new Assert\Optional([
                new Assert\Url(['message' => 'Redirect URL must be a valid URL.']),
            ]),
            'smartphone_format' => new Assert\Optional(),
            'tablet_portrait_format' => new Assert\Optional(),
            'tablet_landscape_format' => new Assert\Optional(),
            'screen_14_inch_format' => new Assert\Optional(),
            'large_screen_format' => new Assert\Optional(),
        ]);

        // Valider les données
        $violations = $validator->validate($data, $constraints);

        // Si des erreurs sont trouvées, renvoyer une réponse avec les messages d'erreur
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }

            return new JsonResponse(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Créer l'entité Marketing
        $marketing = new Marketing();
        $marketing->setCampaignName($data['campaign_name']);
        $marketing->setStatus($data['status']);
        $marketing->setStartDate(new \DateTime($data['start_date']));
        $endDate = $data['end_date'] ?? null;
        if ($endDate) {
            $marketing->setEndDate(new \DateTime($endDate));
        }
        $marketing->setDuration($data['duration'] ?? 5);
        $marketing->setRedirectUrl($data['redirect_url'] ?? null);
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
    public function edit(Request $request, Marketing $marketing, ValidatorInterface $validator): Response
    {
        $data = json_decode($request->getContent(), true);

        // Valider les données avec les mêmes contraintes que pour la création
        $constraints = new Assert\Collection([
            'campaign_name' => new Assert\NotBlank(['message' => 'Campaign name is required']),
            'status' => new Assert\Choice([
                'choices' => ['active', 'inactive'],
                'message' => 'Status must be either "active" or "inactive".'
            ]),
            'start_date' => new Assert\Date(['message' => 'Start date must be a valid date.']),
            'end_date' => [
                new Assert\Date(['message' => 'End date must be a valid date.']),
                new Assert\Expression([
                    'expression' => 'value >= this["start_date"]',
                    'message' => 'End date must be after or equal to the start date.',
                ])
            ],
            'duration' => [
                new Assert\Optional([
                    new Assert\Type(['type' => 'integer', 'message' => 'Duration must be an integer.']),
                    new Assert\Positive(['message' => 'Duration must be greater than zero.']),
                ]),
            ],
            'redirect_url' => new Assert\Optional([
                new Assert\Url(['message' => 'Redirect URL must be a valid URL.']),
            ]),
            'smartphone_format' => new Assert\Optional(),
            'tablet_portrait_format' => new Assert\Optional(),
            'tablet_landscape_format' => new Assert\Optional(),
            'screen_14_inch_format' => new Assert\Optional(),
            'large_screen_format' => new Assert\Optional(),
        ]);

        $violations = $validator->validate($data, $constraints);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }

            return new JsonResponse(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Mettre à jour l'entité Marketing
        $marketing->setCampaignName($data['campaign_name']);
        $marketing->setStatus($data['status']);
        $marketing->setStartDate(new \DateTime($data['start_date']));
        $endDate = $data['end_date'] ?? null;
        if ($endDate) {
            $marketing->setEndDate(new \DateTime($endDate));
        }
        $marketing->setDuration($data['duration'] ?? 5);
        $marketing->setRedirectUrl($data['redirect_url'] ?? null);
        $marketing->setSmartphoneFormat($data['smartphone_format'] ?? null);
        $marketing->setTabletPortraitFormat($data['tablet_portrait_format'] ?? null);
        $marketing->setTabletLandscapeFormat($data['tablet_landscape_format'] ?? null);
        $marketing->setScreen14InchFormat($data['screen_14_inch_format'] ?? null);
        $marketing->setLargeScreenFormat($data['large_screen_format'] ?? null);

        $this->entityManager->flush();

        return $this->json(['status' => 'Marketing campaign updated']);
    }
}
