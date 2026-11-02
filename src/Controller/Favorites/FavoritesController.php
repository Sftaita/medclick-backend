<?php

namespace App\Controller\Favorites;

use App\Entity\Favorites;
use App\Entity\Nomenclature;
use App\Repository\FavoritesRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\NomenclatureRepository;
use App\Service\InactiveNomenclatureException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Favoris d'intervention (raccourcis vers un acte de la nomenclature).
 *
 * Étape 4 (ADR-210) : le statut d'un favori (`active`) est celui de la nomenclature LIÉE, jamais
 * déduit du code copié dans le favori. Un favori dont le code est désactivé reste enregistré et
 * listé ; il ne peut plus servir à créer une intervention (refus de /api/surgeries/addNewSurgery)
 * et un favori ne peut être créé ou redirigé que vers un code actif.
 */
class FavoritesController extends AbstractController
{
    private $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }
    
    // Priorité : sans elle, la route d'API Platform /api/favorites/{id} (chargée avant) l'intercepte.
    #[Route('/api/favorites/getMyList', name: 'GetFavoritesList', methods: ['GET'], priority: 1)]
    public function addSurgery(Security $security, FavoritesRepository $favoritesRepository)
    {
        $user = $security->getUser();
        
        $favorites = $favoritesRepository->findBy(['user' => $user]);

        $data = array();

        foreach($favorites as $favorite){
            $data[] =[
                'id' => $favorite->getId(),
                'surgeryId' => $favorite->getSurgery()?->getId(),
                'codeHospitalisation'=> $favorite->getCodeHospitalisation(),
                'name' => $favorite->getSurgeryName(),
                'shorcut' => $favorite->getShortcut(),
                'speciality' => $favorite->getSpeciality(),
                // Statut de la nomenclature liée (sans nomenclature : inutilisable).
                'active' => $favorite->getSurgery()?->isActive() ?? false,
                // Date officielle d'abrogation VÉRIFIÉE (code actif ou non) : le favori ne sert que
                // pour une intervention antérieure ; null sans abrogation vérifiée.
                'historicalUntil' => self::historicalUntil($favorite),
            ];
        }

        return($this->json($data, 200 , ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]));
    }

    /**
     * Favori à modifier (FavoritePage de la PWA, y compris les builds déjà en cache).
     * GET /api/favorites/{id} (API Platform) ne fournit pas surgeryId, d'où cette route.
     */
    #[Route('/api/favorites/getById/{id<\d+>}', name: 'GetFavoriteById', methods: ['GET'])]
    public function getFavorite(int $id, FavoritesRepository $favoritesRepository): JsonResponse
    {
        $favorite = $favoritesRepository->find($id);

        // Favori inexistant ou appartenant à un autre utilisateur : même réponse.
        if (!$favorite || !$this->isGranted('OWNER', $favorite)) {
            return new JsonResponse(['message' => 'Favori introuvable.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $favorite->getId(),
            'shortcut' => $favorite->getShortcut(),
            'SurgeryName' => $favorite->getSurgeryName(),
            'codeHospitalisation' => $favorite->getCodeHospitalisation(),
            'speciality' => $favorite->getSpeciality(),
            'surgeryId' => $favorite->getSurgery()?->getId(),
            'active' => $favorite->getSurgery()?->isActive() ?? false,
            'historicalUntil' => self::historicalUntil($favorite),
        ]);
    }

    #[Route('/api/favorites/addNew', name: 'AddANewFavorites-NewVersion', methods: ['POST'])]
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

        if (!$surgery) {
            return new JsonResponse(['error' => 'Surgery not found.'], 404);
        }

        // Étape 4 (ADR-210) : un nouveau favori n'utilise qu'un code INAMI actif.
        if (!self::selectableForFavorite($surgery)) {
            return new JsonResponse([
                'error' => self::refusal($surgery),
                'reason' => InactiveNomenclatureException::REASON,
            ], 400);
        }

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

    // Priorité : sans elle, la route d'API Platform PUT /api/favorites/{id} l'intercepte.
    #[Route('/api/favorites/updateNew', name: 'UpdateFavorites-NewVersion', methods: ['PUT'], priority: 1)]
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

        // The favorite must belong to the connected user
        if (!$this->isGranted('OWNER', $favorite)) {
            return new JsonResponse(['error' => 'Favorite not found.'], 404);
        }

        // Check if the surgeryId has changed (un favori sans nomenclature liée doit en recevoir une)
        if ($data['surgeryId'] !== $favorite->getSurgery()?->getId()) {
            // Search in nomenclature table the surgery by id
            $surgery = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId']]);

            if (!$surgery) {
                return new JsonResponse(['error' => 'Surgery not found.'], 404);
            }

            // Étape 4 (ADR-210) : un nouveau favori n'utilise qu'un code INAMI actif.
            if (!self::selectableForFavorite($surgery)) {
                return new JsonResponse([
                    'error' => self::refusal($surgery),
                    'reason' => InactiveNomenclatureException::REASON,
                ], 400);
            }

            // Update favorite with new surgery information
            $favorite->setShortcut($data['shortcut'])
                ->setSurgeryName($surgery->getName())
                ->setCodeHospitalisation($surgery->getCodeHospitalisation() . "" . $surgery->getN())
                ->setSpeciality($surgery->getSpeciality())
                ->setSurgery($surgery);
        } else {
            // Update only the shortcut if surgery hasn't changed (même si son code est devenu inactif :
            // le favori reste enregistré, seul son nom est modifié)
            $favorite->setShortcut($data['shortcut']);
        }

       // Save the updated favorite
        $entityManager = $this->doctrine->getManager();
        $entityManager->persist($favorite);
        $entityManager->flush();

        return new JsonResponse([
            'message' => "ok"
        ], JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' =>  $_ENV['CORS_ALLOW_ORIGIN']]);

    }

    /** « AAAA-MM-JJ » : abrogation vérifiée du code lié, actif ou non (utilisable avant cette date), sinon null. */
    private static function historicalUntil(Favorites $favorite): ?string
    {
        return $favorite->getSurgery()?->getVerifiedRepealDate()?->format('Y-m-d');
    }

    /**
     * Un favori (sans date d'intervention) ne pointe que vers un code actif ET non abrogé
     * aujourd'hui : l'abrogation vérifiée prime sur `active` (ADR-210).
     */
    private static function selectableForFavorite(Nomenclature $nomenclature): bool
    {
        return $nomenclature->isActive() && $nomenclature->isUsableOn(new \DateTimeImmutable('today'));
    }

    private static function refusal(Nomenclature $nomenclature): string
    {
        return InactiveNomenclatureException::forDate($nomenclature, new \DateTimeImmutable('today'))->getMessage();
    }
}
