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
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Security;

/**
 * Permet à chaque fois qu'un chirurgien est créer ou modifié dans une anée, de déterminé s'il est le maitre de stage. 
 * Si c'est le cas, il change les autres maitre de stage de l'année en question afin d'être unique.
 */
class UniqBossEncoder implements EventSubscriberInterface
{

    private $security;
    private $surgeonsRepository;

    public function __construct(Security $security, SurgeonsRepository $surgeonsRepository)
    {
        $this->security = $security;
        $this->surgeonsRepository = $surgeonsRepository;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::VIEW => ['CheckUniqBoss', EventPriorities::PRE_VALIDATE]
        ];
    }

    public function CheckUniqBoss(ViewEvent $event)
    {
        $request = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();


        if ($request instanceof Surgeons && ($method === "POST" || $method === "PUT")) {
            $year = $request->getYear();
            $bossStatus = $request->getBoss();


            // Si ce chirugien est désigné comme étant le maitre de stage :
            if ($bossStatus == true) {

                // Cherche si un autre chirurgien lié à cette année là est connu comme maitre de stage.
                $otherBoss = $this->surgeonsRepository->getBoss($year);

                foreach ($otherBoss as $n) {
                    // Si c'est le cas, change le status de maitre de stage à false.
                    $n->setBoss(false);
                }
            }
        }
    }
}
