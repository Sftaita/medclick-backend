<?php

namespace App\Controller;

use App\Entity\Nomenclature;
use App\Repository\NomenclatureRepository;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Liste des actes proposés pour une spécialité (choix d'un acte).
 *
 * - sans paramètre : codes actifs (forme historique de la réponse, anciens fronts) ;
 * - `?date=AAAA-MM-JJ` (date de l'intervention) : codes utilisables à cette date, y compris les
 *   anciens codes dont l'abrogation vérifiée est postérieure (saisie tardive), signalés par
 *   `active: false` et `inamiRepealedAt` (ADR-210).
 */
class GetNomenclatureBySpecialityController extends AbstractController
{
    #[Route('/api/nomenclature/{speciality}', name: 'nomenclature', methods: ['GET'])]
    public function fetchNomenclature($speciality, NomenclatureRepository $nomenclature, Request $request)
    {
        $dateParam = $request->query->get('date');
        if ($dateParam !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dateParam);
            if (!$date || $date->format('Y-m-d') !== $dateParam) {
                return new JsonResponse(['message' => 'Date invalide (format AAAA-MM-JJ).'], JsonResponse::HTTP_BAD_REQUEST);
            }

            return new JsonResponse($nomenclature->fetchUsableBySpecialityOn((string) $speciality, $date));
        }

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