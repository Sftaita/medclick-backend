<?php

namespace App\Controller;

use App\Entity\Surgeons;
use App\Repository\YearsRepository;
use App\Repository\SurgeonsRepository;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


class GetSurgeonsController extends AbstractController
{
    /**
     * @Route("/api/list/{id}", name="list", methods={"GET"})
     */
    public function CheckDate($id, SurgeonsRepository $Years)
    {
        
        $list = $Years->findSurgeons($id);
         
        
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