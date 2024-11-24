<?php

namespace App\Controller\AdminControllers;

use App\Repository\UserRepository;
use App\Repository\ConnectionHistoryRepository;
use App\Repository\YearsRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class UsersController extends AbstractController
{
    /**
     * @Route("/api/admin/users", name="users_list", methods={"GET"})
     * Retourne la liste des utilisateurs inscrits.
     */
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
     * @Route("/api/userStat/{id}", name="user_stat", methods={"GET"})
     * Retourne les statistiques d'un utilisateur spécifique.
     */
    public function userStat(int $id, YearsRepository $yearsRepository): JsonResponse
    {
        $yearsData = $yearsRepository->getUserStat($id);

        return new JsonResponse($yearsData, JsonResponse::HTTP_OK, [], true);
    }
}
