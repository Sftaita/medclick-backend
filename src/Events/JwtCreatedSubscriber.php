<?php

namespace App\Events;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;

class JwtCreatedSubscriber {
    public function updateJwtData(JWTCreatedEvent $event){
    
        // Récupérer l'utilisateur
        
            $user =$event->getUser();

        // Enrichir du nom et prénom :

                $data = $event->getData();
                $data['firstname'] = $user->getFirstname();
                $data['lastname'] = $user->getLastname();

                $event ->setData($data);
        
    }
}