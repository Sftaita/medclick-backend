<?php

namespace App\Controller\Favorites;

use App\Entity\Favorites;
use App\Repository\FavoritesRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\NomenclatureRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * 
 */
class FavoritesController extends AbstractController
{
    private $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }
    
    /**
     * @Route("/api/favorites/getMyList", name="GetFavoritesList", methods={"GET"})
     */
    public function addSurgery(Security $security, FavoritesRepository $favoritesRepository)
    {
        $user = $security->getUser();
        
        $favorites = $favoritesRepository->findBy(['user' => $user]);

        $data = array();

        foreach($favorites as $favorite){
            $data[] =[
                'id' => $favorite->getId(),
                'surgeryId' => $favorite->getSurgery()->getId(),
                'codeHospitalisation'=> $favorite->getCodeHospitalisation(),
                'name' => $favorite->getSurgeryName(),
                'shorcut' => $favorite->getShortcut(),
                'speciality' => $favorite->getSpeciality(),
            ];
        }

        return($this->json($data, 200 , ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]));
    }

    /**
     * @Route("/api/favorites/addNew", name="AddANewFavorites-NewVersion", methods={"POST"})
     */
    public function addNewFavorite(Request $request,Security $security, NomenclatureRepository $nomenclatureRepository)
    {
        $user = $security->getUser();

        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);
        
        // Validate that surgeryId is an integer
        if (!isset($data['surgeryId']) || !is_int($data['surgeryId'])) {
            return new JsonResponse(['error' => 'Invalid surgeryId. Must be an integer.'], 400);
        }

        // Validate that shortcut is a string
        if (!isset($data['shortcut']) || !is_string($data['shortcut'])) {
            return new JsonResponse(['error' => 'Invalid shortcut. Must be a string.'], 400);
        }

        //Search in nomenclature table the surgery by id
        $surgery = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId']]);

        $favorite = new Favorites;

        
        $favorite->setUser($user)
                ->setShortcut($data['shortcut'])
                ->setSurgeryName($surgery->getName())
                ->setCodeHospitalisation($surgery->getCodeHospitalisation()."".$surgery->getN())
                ->setSpeciality($surgery->getSpeciality())
                ->setSurgery($surgery);

        $entityManager = $this->doctrine->getManager();   
        $entityManager->persist($favorite);
        $entityManager->flush();

        return new JsonResponse([
            'message' => "ok"
        ], JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' =>  $_ENV['CORS_ALLOW_ORIGIN']]);

    }

    /**
     * @Route("/api/favorites/updateNew", name="UpdateFavorites-NewVersion", methods={"PUT"})
     */
    public function updateFavorite(Request $request,Security $security,FavoritesRepository $favoritesRepository, NomenclatureRepository $nomenclatureRepository)
    {
        $user = $security->getUser();

        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);
        
        // Validate that surgeryId is an integer
        if (!isset($data['surgeryId']) || !is_int($data['surgeryId'])) {
            return new JsonResponse(['error' => 'Invalid surgeryId. Must be an integer.'], 400);
        }

        // Validate that favoriteId is an integer
        if (!isset($data['favoriteId']) || !is_int($data['favoriteId'])) {
            return new JsonResponse(['error' => 'Invalid favoriteId. Must be an integer.'], 400);
        }

        // Validate that shortcut is a string
        if (!isset($data['shortcut']) || !is_string($data['shortcut'])) {
            return new JsonResponse(['error' => 'Invalid shortcut. Must be a string.'], 400);
        }

        // Search in favorites table the favorite by id
        $favorite = $favoritesRepository->findOneBy(['id' => $data['favoriteId']]);

        if (!$favorite) {
            return new JsonResponse(['error' => 'Favorite not found.'], 404);
        }

        // Check if the surgeryId has changed
        if ($data['surgeryId'] !== $favorite->getSurgery()->getId()) {
            // Search in nomenclature table the surgery by id
            $surgery = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId']]);

            if (!$surgery) {
                return new JsonResponse(['error' => 'Surgery not found.'], 404);
            }

            // Update favorite with new surgery information
            $favorite->setShortcut($data['shortcut'])
                ->setSurgeryName($surgery->getName())
                ->setCodeHospitalisation($surgery->getCodeHospitalisation() . "" . $surgery->getN())
                ->setSpeciality($surgery->getSpeciality())
                ->setSurgery($surgery);
        } else {
            // Update only the shortcut if surgery hasn't changed
            $favorite->setShortcut($data['shortcut']);
        }

       // Save the updated favorite
        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($favorite);
        $entityManager->flush();

        return new JsonResponse([
            'message' => "ok"
        ], JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' =>  $_ENV['CORS_ALLOW_ORIGIN']]);

    }
}