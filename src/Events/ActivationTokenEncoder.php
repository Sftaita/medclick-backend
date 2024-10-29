<?php

namespace App\Events;


use App\Entity\User;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use ApiPlatform\Core\EventListener\EventPriorities;
use App\Controller\MailerController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Flex\Unpack\Result;

class ActivationTokenEncoder implements EventSubscriberInterface{

    private $mailer;

    public function __construct(MailerController $mailer)
    {
        $this->mailer= $mailer;
    }

    public static function getSubscribedEvents(){                   
        return [                                                        
            KernelEvents::VIEW                                              
            => ['TokenGenerator', EventPriorities :: PRE_WRITE]      
        ];
    }

    /**
     * Au moment de la création d'un compte, créé un token d'activation et l'envoie par email.
     *
     * @param ViewEvent $event
     * @return void
     */
    public function TokenGenerator (ViewEvent $event){                              
        $result = $event->getControllerResult();
        $method = $event->getRequest() -> getMethod();                      
        
        if ($result instanceof User && $method === "POST") {   
            
            //On génére le token d'activation
            $token = md5(uniqid());
            $result->setToken($token)
                    ->setCreatedAt(new \DateTime())
            ;  

            // On récupère l'adresse email.
            $email = $result->getEmail();
            $firstName = $result->getFirstname();
            
            //On envoie un email avec le code d'activation.
            
            $to = $email;
            $subect = "Activation de votre compte";
            $template = "email\activationEmail.html.twig";
            $parameters = array(
                "firstname" => $firstName,
                "token" => $token
            );
            
            
            /*
            $text = 
            " Bonjour " . $firstName ."<br><br>

            Bienvenue dans la communauté! <br><br>

            Voici le lien à suivre afin d'activer ton compte: <br><br> https://www.medclick.be/activation/" . $token ."<br><br>
            
            Med-Click
            "
            
            ;
            */
            $this->mailer->sendEmail($to,$subect, $template,$parameters);
            
              
        }  
    }


}