<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;


class TokenActivatorController extends AbstractController
{
    #[Route('/activation/{token}', name: 'activation', methods: ['GET'])]
    public function VerifyToken(
        $token,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        // Frontend de l'environnement (production : https://www.medclick.be).
        #[Autowire('%app.public_frontend_url%')] string $frontendUrl,
    ) {
        $login = $frontendUrl . '/#/login';

        if(strlen($token) !== 32){
            throw $this->createNotFoundException("Lien d'activation invalide");
        }else{

            // On vérifie si un utilisateur possède ce token.
            $user= $userRepo->findOneBy(['token' => $token]);
    
            // Aucun utilisateur avec ce token : lien déjà utilisé (compte déjà activé) ou inconnu.
            // On renvoie vers la connexion plutôt que d'afficher une erreur.
            if(!$user){
                return $this->redirect($login);
            }
    
            // On supprime le token.
            $user->setToken(null);
            $user->setValidatedAt(new \DateTime());
    
            $em->persist($user);
            $em->flush();
           
            return $this->redirect($login);
        }
    }
}