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
            die;
        }else{

            // On vérifie si un utilisateur possède ce token.
            $user= $userRepo->findOneBy(['token' => $token]);
    
            // Si aucun utilisateur possède ce token
            if(!$user){
                throw $this->createNotFoundException("Cet utlisateur n'existe pas");
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