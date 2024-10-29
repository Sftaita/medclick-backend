<?php

namespace App\Controller;


use App\Repository\UserRepository;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

class ForgottenPasswordController extends AbstractController
{
    /**
     * @Route("api/forgottenPassword", name="forgotten_password" , methods={"POST"})
     */
    public function forgottenPassword(Request $request, UserRepository $userRepository, TokenGeneratorInterface $tokenGenerator, MailerController $mailer)
    {
        // on récupère le mot de passe
        $parameters = json_decode($request->getContent(), true);
        $username = $parameters['username'];

        //On cherche l'utilisateur dans la base de donnée
        $user = $userRepository->findOneByEmail($username);
        $firstname = $user->getFirstname();
        
        if($user){
            $token = $tokenGenerator->generateToken();
            
            try{
                $user->setResetToken($token);
                $em = $this->getDoctrine()->getManager();
                $em->persist($user);
                $em->flush();
            }catch(Exception $e){
                throw new \Exception("Impossible de réinitialiser le mot de passe de ce compte!");
            }

            //On envoie un email avec le code d'activation.
            
            $to = $username;
            $subect = "Ré-initialisation du mot de passse";
            $template = "email/emailReseterEmail.html.twig"; 
            $parameters = array(
                "firstname" => $firstname,
                "token" => $token
            );

            $mailer->sendEmail($to,$subect, $template,$parameters);

            return;

        }
       
    }

    /**
     * @Route("api/resetPassword", name="reset_password" , methods={"POST"})
     */
    public function resetPassword(Request $request, UserRepository $userRepository, UserPasswordEncoderInterface $encoder)
    {
        // on récupère le mot de passe
        $parameters = json_decode($request->getContent(), true);
        $token = $parameters['token'];
        $username = $parameters['email'];
        $password = $parameters['password'];

        $user= $userRepository-> findOneByEmail($username);

        if(!$user){
            throw new \Exception("Cet utilisateurs n'existe pas");
        }else{
            $registeredToken = $user->getResetToken();

            if($registeredToken){

                if($registeredToken === $token){
                    $hash = $encoder->encodePassword($user, $password);
                    $user->setPassword($hash)
                        ->setResetToken(null)
                    ;
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($user);
                    $em->flush();
                    dd("Ca fonctionne");
                }else{
                    $user->setResetToken(null);
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($user);
                    $em->flush();
                    throw new \Exception("Erreur de token");
                }

            }else{
                throw new \Exception("Aucune demande de réinitialisation d'email n'a été introduite pour ce compte!");
            }
        }


        

        
       
    }
}
