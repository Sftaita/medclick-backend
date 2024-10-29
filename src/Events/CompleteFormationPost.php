<?php

namespace App\Events;

use App\Entity\Years;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Formations;
use App\Entity\Surgeries;
use App\Repository\FormationsRepository;
use App\Repository\NomenclatureRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Security;

class CompleteFormationPost implements EventSubscriberInterface
{
    
    private $security;
    private $formationRepository;

    public function __construct(Security $security, FormationsRepository $formationRepository){
        $this->security =$security;
        $this->formationRepository = $formationRepository;
    }

    public static function getSubscribedEvents()
    {
        return[
            KernelEvents::VIEW =>['CompletePost', EventPriorities::PRE_VALIDATE]
        ];
    }

    public function CompletePost(ViewEvent $event){
        $formation = $event -> getControllerResult();

        $method = $event->getRequest()->getMethod();

        if($formation instanceof Formations && ($method === "POST" || $method === "PUT")){

            $location = $formation->getLocation();
            
            if($location === "local"){
                
                $year = $formation->getYear();
                $hospital = $year->getHospital();
                $action = $formation->setLocation($hospital);
            }  
        }

        
        
    }
}
