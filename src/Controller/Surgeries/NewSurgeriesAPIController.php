<?php 

namespace App\Controller\Surgeries;

use App\Entity\Surgeries;
use App\Repository\NomenclatureRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Routing\Annotation\Route;
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
    

    /**
     * @Route("/api/surgeries/addNewSurgery", name="Add a new surgery - version 2", methods={"POST"})
     */
    public function addNewSurgery(
        UserRepository $userRepository,
        YearsRepository $yearsRepository, 
        NomenclatureRepository $nomenclatureRepository,
        Request $request
    ): JsonResponse {

        // Find the resident based on the user ID.
        $resident = $userRepository->findOneBy(['id' => $this->security->getUser()]);
        
        if (!$resident) {
            $this->handleMissingData("Aucun utilisateur retrouvé");
        }

        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);

        // Find the current year by ID
        $year = $yearsRepository->findOneBy(['id' => $data['year']]);
        
        if (!$year) {
            $this->handleMissingData("Année non retrouvé");
        }

        // Find the surgery reference by its ID.
        $surgeryReference = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId']]);

        if (!$surgeryReference) {
            $this->handleMissingData("Cette intervention n'est pas retrouvée en base de données");
        }

        // Create a unique code for the surgery.
        $code = $surgeryReference->getCodeHospitalisation() . '' . $surgeryReference->getN();

        // Create a new Surgeries entity.
        $surgery = new Surgeries;

        // Set the attributes of the new surgery entity.
        $surgery->setYear($year)
            ->setNomenclature($surgeryReference)
            ->setDate(new \DateTime($data['date']))
            ->setSpeciality($surgeryReference->getSpeciality())
            ->setCode($code)
            ->setName($surgeryReference->getName())
            ->setPosition($data['position'])
            ->setCreatedAt(new \DateTime()); // Set the current date and time.

        // Depending on the position, set the FirstHand and SecondHand attributes.
        switch ($data['position']) {
            case 1:
                $surgery->setFirstHand($resident->getId());
                break;
            case 2:
                $surgery->setFirstHand($data['firstHand']);
                $surgery->setSecondHand($resident->getId());
                break;
            case 3:
                $surgery->setFirstHand($resident->getId());
                $surgery->setSecondHand($data['secondHand']);
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