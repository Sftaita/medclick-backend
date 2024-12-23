<?php

namespace App\Events;

use App\Entity\User;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use ApiPlatform\Core\EventListener\EventPriorities;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class PasswordEncoderSubcriber implements EventSubscriberInterface {                   

    /**
     * Permet d'utiliser l'interface d'encodage mentionné de symfony
     *
     * @var UserPasswordEncoderInterface
     */
    private $encoder;

   
    public function __construct(UserPasswordEncoderInterface $encoder)
    {
        $this->encoder = $encoder;
    }


    public static function getSubscribedEvents(){                   
        return [                                                      
            KernelEvents::VIEW => ['encodePassword', EventPriorities::PRE_WRITE]             
        ];
    }

    public function encodePassword (ViewEvent $event){                        
        $result = $event->getControllerResult();
        
        $method = $event->getRequest() -> getMethod();   

        if ($result instanceof User && $method === "POST") {        
           
            $hash = $this->encoder->encodePassword($result, $result->getPassword());                        
            $result->setPassword($hash);
            
        }  
    }


}
