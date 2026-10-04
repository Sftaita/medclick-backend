<?php 

namespace App\Controller\Surgeries;

use App\Entity\Surgeries;
use App\Repository\NomenclatureRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cette classe NewSurgeriesAPIController est destinée à gérer les nouvelles API pour les opérations chirurgicales.
 * L'objectif est d'introduire des fonctionnalités modifiées sans perturber les utilisateurs de l'ancienne version frontend.
 * 
 * Les principales fonctions incluent :
 * - createNewSurgery : Crée une nouvelle entrée de chirurgie.
 * - getNewSurgery : Récupère les détails d'une chirurgie spécifique par ID.
 * 
 */
class NewSurgeriesAPIController extends AbstractController
{
    private $doctrine;
    private $security;
    

    /**
     * @param ManagerRegistry $doctrine The doctrine service.
     */
    public function __construct(ManagerRegistry $doctrine, Security $security)
    {
        $this->doctrine = $doctrine;
        $this->security = $security;
    }

    private function handleMissingData(string $message): JsonResponse
    {
        return new JsonResponse([
            'message' => $message
        ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
    }
    

    #[Route('/api/surgeries/addNewSurgery', name: 'Add a new surgery - version 2', methods: ['POST'])]
    public function addNewSurgery(
        UserRepository $userRepository,
        YearsRepository $yearsRepository, 
        NomenclatureRepository $nomenclatureRepository,
        Request $request
    ): JsonResponse {

        // Find the resident based on the user ID.
        $resident = $userRepository->findOneBy(['id' => $this->security->getUser()]);
        
        if (!$resident) {
            return $this->handleMissingData("Aucun utilisateur retrouvé");
        }

        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);

        // Find the current year by ID
        $year = $yearsRepository->findOneBy(['id' => $data['year'] ?? null]);

        if (!$year) {
            return $this->handleMissingData("Année non retrouvé");
        }

        // The year must belong to the connected user.
        if (!$this->isGranted('OWNER', $year)) {
            return new JsonResponse([
                'message' => "Cette année ne vous appartient pas"
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        // Find the surgery reference by its ID.
        $surgeryReference = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId'] ?? null]);

        if (!$surgeryReference) {
            return $this->handleMissingData("Cette intervention n'est pas retrouvée en base de données");
        }

        // Role during the surgery: 1 (first hand), 2 (second hand), 3 (first hand, assisted).
        // Required: the column is NOT NULL and existing data only contains 1, 2 or 3.
        $position = $data['position'] ?? null;
        if (!in_array((string) $position, ['1', '2', '3'], true)) {
            return $this->handleMissingData("Veuillez indiquer votre rôle durant l'intervention.");
        }

        // Same permissive parsing as before, but an unreadable date is a client error, not a 500.
        try {
            $date = new \DateTime((string) ($data['date'] ?? ''));
        } catch (\Exception $e) {
            return $this->handleMissingData("Veuillez indiquer une date valide.");
        }
        if (empty($data['date'])) {
            return $this->handleMissingData("Veuillez indiquer une date valide.");
        }

        // Create a unique code for the surgery.
        $code = $surgeryReference->getCodeHospitalisation() . '' . $surgeryReference->getN();

        // Create a new Surgeries entity.
        $surgery = new Surgeries;

        // Set the attributes of the new surgery entity.
        $surgery->setYear($year)
            ->setNomenclature($surgeryReference)
            ->setDate($date)
            ->setSpeciality($surgeryReference->getSpeciality())
            ->setCode($code)
            ->setName($surgeryReference->getName())
            ->setPosition((string) $position)
            ->setCreatedAt(new \DateTime()); // Set the current date and time.

        // Depending on the position, set the FirstHand and SecondHand attributes.
        switch ((int) $position) {
            case 1:
                $surgery->setFirstHand($resident->getId());
                break;
            case 2:
                $surgery->setFirstHand($data['firstHand'] ?? null);
                $surgery->setSecondHand($resident->getId());
                break;
            case 3:
                $surgery->setFirstHand($resident->getId());
                $surgery->setSecondHand($data['secondHand'] ?? null);
                break;
        }

        // Get the entity manager from the doctrine service.
        $entityManager = $this->doctrine->getManager();

        // Persist the new surgery entity.
        $entityManager->persist($surgery);

        // Flush the entity manager to commit the changes.
        $entityManager->flush();

        return new JsonResponse([
            'message' => "ok"
        ], JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
    }

}