<?php

namespace App\Controller\AdminControllers;

use App\Repository\UserRepository;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\Response;
use App\Repository\ConnectionHistoryRepository;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


class UsersListController extends AbstractController
{
    /**
     * @Route("/api/admin/users", name="users_list", methods={"GET"})
     * @IsGranted("ROLE_ADMIN")
     * Donne la liste des utilsiateurs incrits.
     */
    public function UsersList(UserRepository $userRepository, ConnectionHistoryRepository $connectionHistoryRepository)
    {

        $list = $userRepository->findAll();
        $users = array();

        foreach ($list  as $user ){
            $history = $connectionHistoryRepository->findByUserOrderedByDate($user);

            // Comptage des occurrences
            $historyCount = count($history);
            
            // Récupération de la dernière date de connexion (la plus récente)
            $lastLoginDate = $historyCount > 0 ? $history[0]->getDate()->format('Y-m-d H:i:s') : null;

            // Conversion des dates 'createdAt' et 'validatedAt' si elles existent
            $createdAt = $user->getCreatedAt() ? $user->getCreatedAt()->format('Y-m-d H:i:s') : null;
            $validatedAt = $user->getValidatedAt() ? $user->getValidatedAt()->format('Y-m-d H:i:s') : null;


            $users[] = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'role' =>$user->getRoles(),
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
                'createdAt' => $createdAt,
                'validaetedAt' => $validatedAt,
                'speciality' => $user->getSpeciality(),
                'nbOfConnection' => $historyCount,
                'lastLoginDate' => $lastLoginDate
            ];

            
        }
        return new JsonResponse($users);
    }
}
