<?php

namespace App\Controller;

use App\Entity\Nomenclature;
use App\Repository\NomenclatureRepository;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Contient une fonction qui renvoie la liste des nomenclature selon la spécialité demandé.
 */
class GetNomenclatureBySpecialityController extends AbstractController
{
    /**
     * @Route("/api/nomenclature/{speciality}", name="nomenclature", methods={"GET"})
     */
    public function fetchNomenclature($speciality, NomenclatureRepository $nomenclature)
    {
        // On cherche la nomenclature selon la specialité
        $list = $nomenclature->fetchBySpeciality($speciality);
         
        
        // On spécifie que l'on utilise un encoder en JSON
        $encoders = [new JsonEncoder()];

        //On instancie le "normaliseur" pour convertir la collection en tableau
        $normalizers = [new ObjectNormalizer()];

        

        // On fait la conversion en json
        // On instencie le convertisseur
        $serializer = new Serializer($normalizers, $encoders);

        // On converit en json
        $jsonContent = $serializer->serialize($list, 'json');
              

        // On instancie la réponse
        $respone = new Response($jsonContent);

        // On ajoute l'entête HTTP
        $respone->headers->set('Content-Type', 'application/json');

        // On envoie la réponse
        return $respone;

    }
}