<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Statistics;
use App\Repository\ConsultationsRepository;
use App\Repository\FormationsRepository;
use App\Repository\GardesRepository;
use App\Repository\StatisticsRepository;
use App\Repository\SurgeriesRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Security;


#[Route('/api/statistics/', name: 'StatisticsController')]
class StatisticsController
{
    private $userRepository;
    private $yearsRepository;
    private $consultationsRepository;
    private $gardeRepository;
    private $formationRepository;
    private $surgeriesRepository;
    private $statisticsRepository;


    public function __construct(UserRepository $userRepository, YearsRepository $yearsRepository, SurgeriesRepository $surgeriesRepository, StatisticsRepository $statisticsRepository, ConsultationsRepository $consultationsRepository, GardesRepository $gardeRepository, FormationsRepository $formationRepository)
    {
        $this->userRepository = $userRepository;
        $this->yearsRepository = $yearsRepository;
        $this->consultationsRepository = $consultationsRepository;
        $this->gardeRepository = $gardeRepository;
        $this->surgeriesRepository = $surgeriesRepository;
        $this->statisticsRepository = $statisticsRepository;
        $this->formationRepository = $formationRepository;
    }
    #[Route('update/{userId<\d+>}', name: 'update', methods: ['POST'])]
    public function update($userId, EntityManagerInterface  $manager, AuthorizationCheckerInterface $authorizationChecker)
    {
        // Réservé aux administrateurs.
        if (!$authorizationChecker->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['message' => 'Accès refusé'], JsonResponse::HTTP_FORBIDDEN);
        }

        $final = ['firstHand' => 0, 'secondHand' => 0, 'consultations' => 0, 'gardes' => 0, 'formations' => 0];

        // 1. On récupère l'utilisateur avec l'id.

        $user = $this->userRepository->find($userId);
        if ($user == null) {
            return new JsonResponse(['message' => 'Utilisateur introuvable'], JsonResponse::HTTP_NOT_FOUND);
        }

        // 2. On recherche dans Year Entity toutes les années de l'utilisateurs.

        $years = array();

        $request = $this->yearsRepository->fetchById($user);
        if ($request !== null) {
            foreach ($request as $n) {
                $years[] = $n['id'];
            }
        }


        // 3. On compte toute les interventions, consultation et garde et formation dans chaque année.
        $total = array();
        if ($years !== null) {


            foreach ($years as $year) {
                $surgeryRequest = $this->surgeriesRepository->countThis($year);
                $total[] = $surgeryRequest;

                $consultationsRequest = $this->consultationsRepository->countThis($year);
                if ($consultationsRequest !== null) {
                    $final['consultations'] = $final['consultations'] + $consultationsRequest[0][1];
                }

                $gardesRequest = $this->gardeRepository->countThis($year);
                if ($gardesRequest !== null) {
                    $final['gardes'] = $final['gardes'] + $gardesRequest[0][1];
                }

                $formationRequest = $this->formationRepository->countThis($year);
                if ($formationRequest !== null) {
                    $final['formations'] = $final['formations'] + $formationRequest[0][1];
                }
            }
        }

        foreach ($total as $t) {
            $final['firstHand'] = $final['firstHand'] + $t['firstHand'];
            $final['secondHand'] = $final['secondHand'] + $t['secondHand'];
        }

        // 4. On met à jour Statistics entity.

        $request = $this->statisticsRepository->findOneBy(array('user' => $user));

        if ($request === null) {

            $statistics = new Statistics();
            $statistics->setUser($user);
        } else {
            $statistics = $request;
        }
        $statistics->setFirstHandSurgeries($final['firstHand'])
            ->setSecondHandSurgeries($final['secondHand'])
            ->setConsultations($final['consultations'])
            ->setGardes($final['gardes'])
            ->setFormations($final['formations']);

        $manager->persist($statistics);
        $manager->flush();


        return new Response('ok');
    }

    #[Route('fetch/{userId<\d+>}', name: 'fetch', methods: ['GET'])]
    public function fetch($userId, Security $security, AuthorizationCheckerInterface $authorizationChecker)
    {
        // Statistiques de l'utilisateur $userId.
        $statistics = $this->statisticsRepository->findOneById($userId);

        if (!$statistics) {
            return new JsonResponse(null);
        }

        // Uniquement ses propres statistiques, sauf pour un administrateur.
        $owner = $statistics->getUser();
        if ((!$owner || $owner->getId() !== $security->getUser()->getId()) && !$authorizationChecker->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['message' => 'Accès refusé'], JsonResponse::HTTP_FORBIDDEN);
        }

        // Champs explicites : ne jamais sérialiser l'entité User liée (hash du mot de passe, tokens).
        return new JsonResponse([
            'id' => $statistics->getId(),
            'firstHandSurgeries' => $statistics->getFirstHandSurgeries(),
            'secondHandSurgeries' => $statistics->getSecondHandSurgeries(),
            'fistHandHelpedSurgeries' => $statistics->getFistHandHelpedSurgeries(),
            'consultations' => $statistics->getConsultations(),
            'gardes' => $statistics->getGardes(),
            'formations' => $statistics->getFormations(),
        ]);
    }
}
