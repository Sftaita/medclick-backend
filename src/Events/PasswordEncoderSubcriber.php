<?php

namespace App\Events;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use ApiPlatform\Core\EventListener\EventPriorities;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PasswordEncoderSubcriber implements EventSubscriberInterface {

    /**
     * Permet d'utiliser l'interface d'encodage mentionné de symfony
     *
     * @var UserPasswordHasherInterface
     */
    private $encoder;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    public function __construct(UserPasswordHasherInterface $encoder, EntityManagerInterface $em)
    {
        $this->encoder = $encoder;
        $this->em = $em;
    }


    public static function getSubscribedEvents(){
        return [
            KernelEvents::VIEW => ['encodePassword', EventPriorities::PRE_WRITE]
        ];
    }

    /**
     * Hash le mot de passe à la création du compte, et lors d'une modification (PUT/PATCH)
     * uniquement si le mot de passe envoyé diffère de celui enregistré.
     */
    public function encodePassword (ViewEvent $event){
        $result = $event->getControllerResult();

        $method = $event->getRequest() -> getMethod();

        if (!$result instanceof User) {
            return;
        }

        if ($method === "POST") {
            $result->setPassword($this->encoder->hashPassword($result, $result->getPassword()));

            return;
        }

        if ($method === "PUT" || $method === "PATCH") {
            $original = $this->em->getUnitOfWork()->getOriginalEntityData($result);

            if (($original['password'] ?? null) !== $result->getPassword()) {
                $result->setPassword($this->encoder->hashPassword($result, $result->getPassword()));
            }
        }
    }


}
