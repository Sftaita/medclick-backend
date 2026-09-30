<?php

namespace App\Controller;


use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

class ForgottenPasswordController extends AbstractController
{
    /**
     * Durée de validité du lien de réinitialisation.
     */
    public const TOKEN_TTL = '+1 hour';

    private const INVALID_LINK = "Lien de réinitialisation invalide ou expiré.";

    /**
     * Envoie un lien de réinitialisation. La réponse est identique que le compte existe ou non
     * (pas d'énumération des emails).
     *
     * @Route("api/forgottenPassword", name="forgotten_password" , methods={"POST"})
     */
    public function forgottenPassword(Request $request, UserRepository $userRepository, TokenGeneratorInterface $tokenGenerator, MailerController $mailer)
    {
        $parameters = json_decode($request->getContent(), true);
        $username = $parameters['username'] ?? null;

        if (!is_string($username) || $username === '') {
            return new JsonResponse(['message' => "L'email doit être renseigné"], JsonResponse::HTTP_BAD_REQUEST);
        }

        //On cherche l'utilisateur dans la base de donnée
        $user = $userRepository->findOneByEmail($username);

        if ($user) {
            $token = $tokenGenerator->generateToken();

            $user->setResetToken($token)
                ->setResetTokenRequestedAt(new \DateTime());
            $this->getDoctrine()->getManager()->flush();

            //On envoie un email avec le lien de réinitialisation.
            $mailer->sendEmail($user->getEmail(), "Ré-initialisation du mot de passse", "email/emailReseterEmail.html.twig", [
                "firstname" => $user->getFirstname(),
                "token" => $token
            ]);
        }

        return new JsonResponse([
            'message' => "Si un compte existe pour cet email, un lien de réinitialisation vient d'être envoyé."
        ]);
    }

    /**
     * @Route("api/resetPassword", name="reset_password" , methods={"POST"})
     */
    public function resetPassword(Request $request, UserRepository $userRepository, UserPasswordEncoderInterface $encoder)
    {
        $parameters = json_decode($request->getContent(), true);
        $token = $parameters['token'] ?? null;
        $username = $parameters['email'] ?? null;
        $password = $parameters['password'] ?? null;

        if (!is_string($token) || !is_string($username) || !is_string($password)) {
            return new JsonResponse(['message' => self::INVALID_LINK], JsonResponse::HTTP_BAD_REQUEST);
        }

        // Mêmes règles que l'entité User.
        if (mb_strlen($password) < 6 || mb_strlen($password) > 50) {
            return new JsonResponse(['message' => "Le mot de passe doit contenir entre 6 et 50 caractères"], JsonResponse::HTTP_BAD_REQUEST);
        }

        $user = $userRepository->findOneByEmail($username);

        $registeredToken = $user ? $user->getResetToken() : null;
        $requestedAt = $user ? $user->getResetTokenRequestedAt() : null;

        $isValid = $registeredToken !== null
            && $requestedAt !== null
            && hash_equals($registeredToken, $token)
            && (clone $requestedAt)->modify(self::TOKEN_TTL) > new \DateTime();

        if (!$isValid) {
            return new JsonResponse(['message' => self::INVALID_LINK], JsonResponse::HTTP_BAD_REQUEST);
        }

        $user->setPassword($encoder->encodePassword($user, $password))
            ->setResetToken(null)
            ->setResetTokenRequestedAt(null);
        $this->getDoctrine()->getManager()->flush();

        return new JsonResponse(['message' => "Mot de passe modifié"]);
    }
}
