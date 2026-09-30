<?php

namespace App\Controller\AdminControllers;

use App\Repository\UserRepository;
use App\Repository\ConnectionHistoryRepository;
use App\Repository\YearsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Security;

class UsersController extends AbstractController
{
    /**
     * Retourne la liste des utilisateurs inscrits.
     */
    #[Route('/api/admin/users', name: 'users_list', methods: ['GET'])]
    public function usersList(UserRepository $userRepository, ConnectionHistoryRepository $connectionHistoryRepository): JsonResponse
    {
        $usersData = [];

        foreach ($userRepository->findAll() as $user) {
            $history = $connectionHistoryRepository->findByUserOrderedByDate($user);

            $createdAt = $user->getCreatedAt() ? $user->getCreatedAt()->format('Y-m-d H:i:s') : null;
            $validatedAt = $user->getValidatedAt() ? $user->getValidatedAt()->format('Y-m-d H:i:s') : null;
            $lastLoginDate = !empty($history) && $history[0]->getDate() ? $history[0]->getDate()->format('Y-m-d H:i:s') : null;

            $usersData[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'role' => $user->getRoles(),
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'createdAt' => $createdAt,
                'validatedAt' => $validatedAt,
                'speciality' => $user->getSpeciality(),
                'nbOfConnection' => count($history),
                'lastLoginDate' => $lastLoginDate,
            ];
        }

        return new JsonResponse($usersData);
    }

    /**
     * Retourne les statistiques d'un utilisateur spécifique.
     */
    #[Route('/api/userStat/{id}', name: 'user_stat', methods: ['GET'])]
    public function userStat(int $id, YearsRepository $yearsRepository): JsonResponse
    {
        // Uniquement ses propres statistiques, sauf pour un administrateur.
        if ($this->getUser()->getId() !== $id && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['message' => 'Accès refusé'], JsonResponse::HTTP_FORBIDDEN);
        }

        $yearsData = $yearsRepository->getUserStat($id);

        return new JsonResponse($yearsData);
    }

    #[Route('/api/admin/fetchUserById/{id}', name: 'userProfil', methods: ['GET'])]
    public function fecthUserProfil(int $id, UserRepository $userRepository, YearsRepository $yearsRepository): JsonResponse
    {
        $searchedUser = $userRepository->findOneBy(['id' => $id]);

        // Vérifie si l'utilisateur existe
        if (!$searchedUser) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Utilisateur introuvable.',
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        

        // Prépare les données de l'utilisateur pour la réponse
        $userData = [
            'id' => $searchedUser->getId(),
            'firstname' => $searchedUser->getFirstname(),
            'lastname' => $searchedUser->getLastname(),
            'speciality' => $searchedUser->getSpeciality(),
            'email' => $searchedUser->getEmail(),
            'role' => $searchedUser->getRoles(),
            'createdAt' => $searchedUser->getCounter(),
            'validatedAt' => $searchedUser-> getValidatedAt(),
            'years' => $yearsRepository->getYearsByUserId($searchedUser->getId())
            // Ajoutez d'autres champs si nécessaire
        ];

        // Retourne les données utilisateur en JSON
        return new JsonResponse([
            'success' => true,
            'data' => $userData,
        ], JsonResponse::HTTP_OK);
    }

}
