<?php

namespace App\Controller\AdminControllers;

use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;


class UserStat extends AbstractController
{
    /**
     * @Route("/api/userStat/{id}", name="user_stat", methods={"GET"})
     */
    public function UsersStat($id, UserRepository $Users, YearsRepository $year)
    {

        $years = $year->getUserStat($id);


        // On spécifie que l'on utilise un encoder en JSON
        $encoders = [new JsonEncoder()];

        //On instancie le "normaliseur" pour convertir la collection en tableau
        $normalizers = [new ObjectNormalizer()];



        // On fait la conversion en json
        // On instencie le convertisseur
        $serializer = new Serializer($normalizers, $encoders);

        // On converit en json
        $jsonContent = $serializer->serialize($years, 'json');
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
