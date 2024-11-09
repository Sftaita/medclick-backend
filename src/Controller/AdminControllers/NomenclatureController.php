<?php

namespace App\Controller\AdminControllers;

use App\Entity\Nomenclature;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\NomenclatureRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class NomenclatureController extends AbstractController{

    /**
     * @Route("/api/admin/nomenclature/{speciality}", name="GetListOfNomenclature", methods={"GET"})
     */
    public function getNomenclature($speciality, NomenclatureRepository $nomenclatureRepository): JsonResponse
    {
        if (!isset($speciality) || !is_string($speciality) || strlen($speciality) > 15) {
            return new JsonResponse(['error' => 'Invalid speciality'], 400);
        }

        $nomenclatures = $nomenclatureRepository->findBy(['speciality' => $speciality]);

        $data = array();
        foreach($nomenclatures as $nomenclature){
            $data[] = [
                'id' => $nomenclature->getId(),
                'speciality' => $nomenclature->getSpeciality(),
                'codeAmbulant' => $nomenclature->getCodeAmbulant(),
                'codeHospitalisation' => $nomenclature->getCodeHospitalisation(),
                'n' => $nomenclature->getN(),
                'name' => $nomenclature->getName(),
                'type' => $nomenclature->getType(),
                'subType' => $nomenclature->getSubType(),
            ];
        }

        return $this->json($data, 200, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
    }

    /**
     * @Route("/api/admin/nomenclature", name="PostNomenclature", methods={"POST"})
     */
    public function postNomenclature(Request $request, NomenclatureRepository $nomenclatureRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        
        $data = json_decode($request->getContent(), true);

        $nomenclature = new Nomenclature;
        
        $nomenclature->setSpeciality($data['speciality'])
                    ->setCodeAmbulant($data['codeAmbulant'] ?? $nomenclature->getCodeAmbulant())
                    ->setCodeHospitalisation($data['codeHospitalisation'] ?? $nomenclature->getCodeHospitalisation())
                    ->setN($data['n'] ?? $nomenclature->getN())
                    ->setName($data['name'] ?? $nomenclature->getName())
                    ->setType($data['type'] ?? $nomenclature->getType())
                    ->setSubType($data['subType'] ?? $nomenclature->getSubType());

        $entityManager->persist($nomenclature);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Nomenclature added successfully'], 200);
    }


    /**
     * @Route("/api/admin/nomenclature", name="UpdateNomenclature", methods={"PUT"})
     */
    public function updateNomenclature(Request $request, NomenclatureRepository $nomenclatureRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        
        $data = json_decode($request->getContent(), true);

        $nomenclature = $nomenclatureRepository->find($data['id']);
        
        if (!$nomenclature) {
            return new JsonResponse(['error' => 'Nomenclature not found'], 404);
        }

        $nomenclature->setSpeciality($data['speciality'])
                    ->setCodeAmbulant($data['codeAmbulant'] ?? $nomenclature->getCodeAmbulant())
                    ->setCodeHospitalisation($data['codeHospitalisation'] ?? $nomenclature->getCodeHospitalisation())
                    ->setN($data['n'] ?? $nomenclature->getN())
                    ->setName($data['name'] ?? $nomenclature->getName())
                    ->setType($data['type'] ?? $nomenclature->getType())
                    ->setSubType($data['subType'] ?? $nomenclature->getSubType());

        $entityManager->persist($nomenclature);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Nomenclature updated successfully'], 200);
    }

    /**
     * @Route("/api/admin/nomenclature/update-type", name="UpdateNomenclatureType", methods={"PUT"})
     */
    public function updateNomenclatureType(Request $request, NomenclatureRepository $nomenclatureRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Vérifier que les données contiennent bien 'ids' et 'type'
        if (!isset($data['ids']) || !is_array($data['ids']) || !isset($data['type'])) {
            return new JsonResponse(['error' => 'Invalid data format'], 400);
        }

        // Vérifier que 'type' est soit 1 soit 2
        if (!in_array($data['type'], [1, 2])) {
            return new JsonResponse(['error' => 'Invalid type, must be 1 or 2'], 400);
        }

        // Parcourir les ids et vérifier qu'ils sont bien des entiers
        $ids = $data['ids'];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                return new JsonResponse(['error' => 'Invalid ID type, IDs must be integers'], 400);
            }
        }

        // Pour chaque ID, récupérer la nomenclature et mettre à jour son type
        foreach ($ids as $id) {
            $nomenclature = $nomenclatureRepository->find($id);

            // Vérifier si la nomenclature existe
            if (!$nomenclature) {
                return new JsonResponse(['error' => "Nomenclature with ID $id not found"], 404);
            }

            // Mettre à jour le type
            $nomenclature->setType($data['type']);
            $entityManager->persist($nomenclature);
        }

        // Enregistrer toutes les modifications
        $entityManager->flush();

        return new JsonResponse(['message' => 'Types updated successfully'], 200);
    }

    /**
     * @Route("/api/admin/nomenclature/update-subtype", name="UpdateNomenclatureSubType", methods={"PUT"})
     */
    public function updateNomenclatureSubType(Request $request, NomenclatureRepository $nomenclatureRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Liste des sous-types autorisés
        $allowedSubTypes = ["", "shoulder", "humerus", "elbow", "forearm", "wristhand", "back", "pelvic", "hip", "proximalFemur", "midFemur", "distalFemur", "knee", "limb", "ankle", "foot"];

        // Vérifier que les données contiennent bien 'ids' et 'subType'
        if (!isset($data['ids']) || !is_array($data['ids']) || !isset($data['subType'])) {
            return new JsonResponse(['error' => 'Invalid data format'], 400);
        }

        // Vérifier que 'subType' est dans la liste autorisée
        if (!in_array($data['subType'], $allowedSubTypes)) {
            return new JsonResponse(['error' => 'Invalid subType value'], 400);
        }

        // Parcourir les ids et vérifier qu'ils sont bien des entiers
        $ids = $data['ids'];
        foreach ($ids as $id) {
            if (!is_int($id)) {
                return new JsonResponse(['error' => 'Invalid ID type, IDs must be integers'], 400);
            }
        }

        // Pour chaque ID, récupérer la nomenclature et mettre à jour son subType
        foreach ($ids as $id) {
            $nomenclature = $nomenclatureRepository->find($id);

            // Vérifier si la nomenclature existe
            if (!$nomenclature) {
                return new JsonResponse(['error' => "Nomenclature with ID $id not found"], 404);
            }

            // Mettre à jour le sous-type
            $nomenclature->setSubType($data['subType']);
            $entityManager->persist($nomenclature);
        }
        
       
        // Enregistrer toutes les modifications
        $entityManager->flush();

        return new JsonResponse(['message' => 'SubTypes updated successfully'], 200);
    }



    

}