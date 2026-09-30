<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Controller\MailerController;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Écriture d'un utilisateur via l'API :
 *  - création : hash du mot de passe, token d'activation et envoi de l'email d'activation ;
 *  - modification : hash du mot de passe uniquement s'il a changé.
 *
 * @implements ProcessorInterface<User, User>
 */
final class UserProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerController $mailer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof User) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        if ($operation instanceof Post) {
            $data->setPassword($this->passwordHasher->hashPassword($data, $data->getPassword()));

            $token = bin2hex(random_bytes(16));
            $data->setToken($token)
                ->setCreatedAt(new \DateTime());

            $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

            $this->mailer->sendEmail($data->getEmail(), 'Activation de votre compte', 'email/activationEmail.html.twig', [
                'firstname' => $data->getFirstname(),
                'token' => $token,
            ]);

            return $result;
        }

        // PUT / PATCH : ne re-hasher que si un nouveau mot de passe a été envoyé.
        $previous = $context['previous_data'] ?? null;
        if (!$previous instanceof User || $previous->getPassword() !== $data->getPassword()) {
            $data->setPassword($this->passwordHasher->hashPassword($data, $data->getPassword()));
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
