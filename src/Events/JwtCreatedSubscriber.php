<?php

namespace App\Events;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;

class JwtCreatedSubscriber
{
    public function updateJwtData(JWTCreatedEvent $event)
    {
        // Récupérer l'utilisateur
        $user = $event->getUser();

        // Enrichir les données avec le prénom, nom, acceptedTerms et termsAcceptedDate
        $data = $event->getData();
        $data['firstname'] = $user->getFirstname();
        $data['lastname'] = $user->getLastname();
        
        // Ajouter les informations d'acceptation des termes
        $data['acceptedTerms'] = $user->getAcceptedTerms();
        $data['termsAcceptedDate'] = $user->getTermsAcceptedDate() 
            ? $user->getTermsAcceptedDate()->format('Y-m-d H:i:s') 
            : null;

        // Mettre à jour les données du token
        $event->setData($data);
    }
}
