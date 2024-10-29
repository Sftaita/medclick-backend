<?php

namespace App\Events;

use App\Entity\Years;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Formations;
use App\Entity\Surgeons;
use App\Entity\Surgeries;
use App\Repository\FormationsRepository;
use App\Repository\NomenclatureRepository;
use App\Repository\SurgeonsRepository;
use App\Repository\SurgeriesRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Security;

/**
 * Permet à chaque fois qu'un chirurgien est créer ou modifié dans une anée, de déterminé s'il est le maitre de stage. 
 * Si c'est le cas, il change les autres maitre de stage de l'année en question afin d'être unique.
 */
class CompleteSurgeonDelele implements EventSubscriberInterface
{

    private $surgeryRepository;

    public function __construct(Security $security, SurgeriesRepository $surgeryRepository)
    {
        $this->security = $security;
        $this->surgeryRepository = $surgeryRepository;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['DeleteSurgeries', EventPriorities::PRE_VALIDATE]
        ];
    }

    public function DeleteSurgeries(ViewEvent $event)
    {
        $surgeon = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();


        if ($surgeon instanceof Surgeons && ($method === "DELETE")) {

            $yearId = $surgeon->getYear();
         
            return $this->surgeryRepository->deleteSurgeryBySurgeon($surgeon, $yearId);
        }
    }
}
