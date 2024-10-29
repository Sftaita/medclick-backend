<?php

namespace App\Controller\AdminControllers;

use App\Repository\ConnectionHistoryRepository;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


/**
 * @Route("/api/connection/", name="Sthhb")
 */
class ConnectionHistoryController extends AbstractController
{
    /**
     * @Route("history/{interval}", name="connection_history", methods={"GET"})
     * @IsGranted("ROLE_ADMIN")
     * Renvoie le nombre de connection par interval de temps.
     */
    public function connectionNumber($interval, ConnectionHistoryRepository $history)
    {
        $intervalArray = [
            "d1" => "- 1 day",
            "d2" => "- 2 days", 
            "d3" => "- 3 days", 
            "d4" => "- 4 days",
            "d5" => "- 5 days",
            "d6" => "- 6 days",
            "w1" => "- 1 week", 
            "w2" => "- 2 weeks", 
            "w3" => "- 3 weeks", 
            "m1" => "- 1 month", 
            "m2" => "- 2 months", 
            "m3" => "- 3 months", 
            "m6" => "- 6 months"
        ];

        $list = $history->findByInterval($intervalArray[$interval]);

        $data =  array();
        

        foreach ($list as $x) {
            $date = $x['date'];
            $formatedDate = $date->format('Y-m-d');            
            $data[] = ["id"=>  $x['id'], "user"=>  $x['1'], "date" => $formatedDate]; 
        }

        // On spécifie que l'on utilise un encoder en JSON
        $encoders = [new JsonEncoder()];

        //On instancie le "normaliseur" pour convertir la collection en tableau
        $normalizers = [new ObjectNormalizer()];



        // On fait la conversion en json
        // On instencie le convertisseur
        $serializer = new Serializer($normalizers, $encoders);

        // On converyit en json
        $jsonContent = $serializer->serialize($data, 'json');
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
