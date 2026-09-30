<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;


class TokenActivatorController extends AbstractController
{
    /**
     * @Route("/activation/{token}", name="activation", methods={"GET"})
     */
    public function VerifyToken($token, UserRepository $userRepo)
    {
        if(strlen($token) !== 32){
            throw $this->createNotFoundException("Lien d'activation invalide");
        }else{

            // On vérifie si un utilisateur possède ce token.
            $user= $userRepo->findOneBy(['token' => $token]);
    
            // Aucun utilisateur avec ce token : lien déjà utilisé (compte déjà activé) ou inconnu.
            // On renvoie vers la connexion plutôt que d'afficher une erreur.
            if(!$user){
                return $this->redirect('https://www.medclick.be/#/login');
            }
    
            // On supprime le token.
            $user->setToken(null);
            $user->setValidatedAt(new \DateTime());
    
            $em = $this->getDoctrine()->getManager();
            $em->persist($user);
            $em->flush();
           
            return $this->redirect('https://www.medclick.be/#/login');
        }
    }
}