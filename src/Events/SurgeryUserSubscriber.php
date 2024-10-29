<?php

namespace App\Events;

use App\Entity\Years;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use ApiPlatform\Core\EventListener\EventPriorities;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Security;

class SurgeryUserSubscriber implements EventSubscriberInterface
{
    
    private $security;

    public function __construct(Security $security){
        $this->security =$security;
    }

    public static function getSubscribedEvents()
    {
        return[
            KernelEvents::VIEW =>['setUserForYears', EventPriorities::PRE_VALIDATE]
        ];
    }

    public function setUserForYears(ViewEvent $event){
        $year =$event->getControllerResult();

        $method = $event->getRequest()->getMethod();

        if($year instanceof Years && $method === "POST"){

            // Trouver l'utilisateur actuellement connecté
            $user = $this->security->getUser();

            //Assigner l'utilisateur à l'année en cours d'enregistrement
            $year->setUser($user);
        }

        
        
    }
}