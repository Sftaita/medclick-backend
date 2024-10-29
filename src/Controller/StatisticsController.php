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
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;


/**
 * @Route("/api/statistics/", name="StatisticsController")
 */
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
    /**
     * @Route("update/{userId<\d+>}", name="update", methods={ "POST" })
     * @IsGranted("ROLE_ADMIN")
     */
    public function update($userId, EntityManagerInterface  $manager)
    {

        $final = ['firstHand' => 0, 'secondHand' => 0, 'consultations' => 0, 'gardes' => 0, 'formations' => 0];

        // 1. On récupère l'utilisateur avec l'id.

        $user = $this->userRepository->find($userId);
        if ($user == null) {
            die;
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
            $statistics->setConsultations(5);
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

    /**
     * @Route("fetch/{userId<\d+>}", name="fetch", methods={"GET"})
     */
    public function fetch($userId)
    {

        $list = $this->statisticsRepository->findOneById($userId);


        // On spécifie que l'on utilise un encoder en JSON
        $encoders = [new JsonEncoder()];

        //On instancie le "normaliseur" pour convertir la collection en tableau
        $normalizers = [new ObjectNormalizer()];



        // On fait la conversion en json
        // On instencie le convertisseur
        $serializer = new Serializer($normalizers, $encoders);

        // On converit en json
        $jsonContent = $serializer->serialize($list, 'json');
        //, [
        //    'circular_reference_handler' => function($test){
        //        return $test->getId();
        //   }
        //]);



        // On instancie la réponse
        $respone = new Response($jsonContent);

        // On ajoute l'entête HTTP
        $respone->headers->set('Content-Type', 'application/json');

        // On envoie la réponse
        return $respone;
    }
}
