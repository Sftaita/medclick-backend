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

    

}