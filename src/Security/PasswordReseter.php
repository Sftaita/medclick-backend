<?php

namespace App\Security;


use Exception;
use App\Repository\UserRepository;
use App\Controller\MailerController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

/**
 * @Route("/api/", name="PasswordReset")
 */
class PasswordReseter extends AbstractController
{

    /**
     * @Route("passwordReset", name="passwordReseter", methods={"GET"})
     */
    public function forgottenPassword($email, UserRepository $userRepo, MailerController $mailer, TokenGeneratorInterface $tokenGenerator, Request $request)
    {

        

        $email = $request->request->get('_email');
       
        $user = $userRepo->findOneByEmail($email);

        if (!$user) {
            throw $this->createNotFoundException("Cet utlisateur n'existe pas");
        } else {
            $token = $tokenGenerator->generateToken();

            try {
                $user->setResetToken($token);
                $manager = $this->getDoctrine()->getManager();
                $manager->persist($user);
                $manager->flush();
            } catch (Exception $e) {
                dd($e);
            }


            $url = $this->generateUrl("api/resetPassordUrl", ["token" => $token]);

            // On génère l'email

            $to = $email;
            $subect = "Réinitialisation de votre mot de passe";
            $template = "email\emailReseterEmail.html.twig";
            $parameters = array(
                "firstname" => "test",
                "url" => $url
            );

            $mailer->sendEmail($to, $subect, $template, $parameters);
        }
    }
}
