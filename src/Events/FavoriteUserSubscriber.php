<?php

namespace App\Events;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Favorites;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Security;

class FavoriteUserSubscriber implements EventSubscriberInterface
{
    
    private $security;

    public function __construct(Security $security){
        $this->security =$security;
    }

    public static function getSubscribedEvents()
    {
        return[
            KernelEvents::VIEW =>['setUserForFavorite', EventPriorities::PRE_VALIDATE]
        ];
    }

    public function setUserForFavorite(ViewEvent $event){
        $favorite =$event->getControllerResult();

        $method = $event->getRequest()->getMethod();

        if($favorite instanceof Favorites && $method === "POST"){

            // Trouver l'utilisateur actuellement connecté
            $user = $this->security->getUser();

            //Assigner l'utilisateur à l'année en cours d'enregistrement
            $favorite->setUser($user);
        }

        
        
    }
}