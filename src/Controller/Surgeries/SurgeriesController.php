<?php

namespace App\Controller\Surgeries;

use App\Entity\Surgeries;
use App\Repository\NomenclatureRepository;
use App\Repository\SurgeriesRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;

/**
 * SurgeriesController class handles all the actions related to surgeries.
 */
class SurgeriesController extends AbstractController
{
    private $doctrine;

    /**
     * Constructor.
     *
     * @param ManagerRegistry $doctrine The doctrine service.
     */
    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     * @Route("/api/surgeries/add", name="Add a new surgery", methods={"POST"})
     *
     * Add a new surgery to the database.
     *
     * @param Request $request The HTTP request object.
     * @param Security $security The security service.
     * @param UserRepository $userRepository The user repository.
     * @param NomenclatureRepository $nomenclatureRepository The nomenclature repository.
     *
     * @return void
     */
    public function addSurgery(Request $request, Security $security, UserRepository $userRepository, NomenclatureRepository $nomenclatureRepository, YearsRepository $yearsRepository)
    {
        // Get the current user from the security service.
        $user = $security->getUser();

        // Find the resident based on the user ID.
        $resident = $userRepository->findOneBy(['id' => $user]);

        if(!$resident){
            return new JsonResponse([
                'message' => "Aucun utilisateur retrouvé" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);

        // Find the surgery reference by its ID.
        $surgeryReference = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId']]);

        if(!$surgeryReference){
            return new JsonResponse([
                'message' => "Cette intervention n'est pas retrouvé en base de donnée" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Find the current year by ID
        $year = $yearsRepository->findOneBy(['id' => $data['year']]);

        if(!$year){
            return new JsonResponse([
                'message' => "Année non retrouvé" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Code construction
        $code = $surgeryReference->getCodeHospitalisation().''.$surgeryReference->getN();

        // Create a new Surgeries entity.
        $surgery = new Surgeries;

        // Set the attributes of the new surgery entity.
        $surgery->setYear($year)
            ->setNomenclature($surgeryReference)
            ->setDate(new \DateTime($data['date']))
            ->setSpeciality($surgeryReference->getSpeciality())
            ->setCode($code)
            ->setName($surgeryReference->getName())
            ->setPosition($data['position']);

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
        ], JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' =>  $_ENV['CORS_ALLOW_ORIGIN']]);
    }

    /**
     * @Route("/api/surgeries/update/{id}", name="UpdateSurgery", methods={"PUT"})
     */
    public function updateSurgery($id, Request $request, Security $security, UserRepository $userRepository, NomenclatureRepository $nomenclatureRepository, YearsRepository $yearsRepository, SurgeriesRepository $surgeriesRepository)
    {
        // Get the current user from the security service.
        $user = $security->getUser();

        // Find the resident based on the user ID.
        $resident = $userRepository->findOneBy(['id' => $user]);

        if(!$resident){
            return new JsonResponse([
                'message' => "Aucun utilisateur retrouvé" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Find the existing surgery.
        $surgery = $surgeriesRepository->findOneBy(['id' => $id]);

        // Check if the Surgery is linked to this User
        if($resident->getId() !== $surgery->getYear()->getUser()->getId()){
            return new JsonResponse([
                'message' => "Cette évènement ne vous appartient pas" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }
        
        
        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);


        if(!$surgery){
            return new JsonResponse([
                'message' => "Aucune chirurgie retrouvée avec cet ID" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Find the surgery reference by its ID.
        $surgeryReference = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId']]);

        if(!$surgeryReference){
            return new JsonResponse([
                'message' => "Cette intervention n'est pas retrouvé en base de donnée" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Find the current year by ID
        $year = $yearsRepository->findOneBy(['id' => $data['year']]);

        if(!$year){
            return new JsonResponse([
                'message' => "Année non retrouvé" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Code construction
        $code = $surgeryReference->getCodeHospitalisation().''.$surgeryReference->getN();

        // Update the attributes of the existing surgery entity.
        $surgery->setYear($year)
            ->setNomenclature($surgeryReference)
            ->setDate(new \DateTime($data['date']))
            ->setSpeciality($surgeryReference->getSpeciality())
            ->setCode($code)
            ->setName($surgeryReference->getName())
            ->setPosition($data['position']);

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
                $surgery->setSecondHand($data['firstHand']);
                break;
        }

        // Get the entity manager from the doctrine service.
        $entityManager = $this->doctrine->getManager();

        // Flush the entity manager to commit the changes.
        $entityManager->flush();

        return new JsonResponse([
                'message' => "ok"
        ], JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' =>  $_ENV['CORS_ALLOW_ORIGIN']]);
    }

}
