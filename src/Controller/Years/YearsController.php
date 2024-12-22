<?php

namespace App\Controller\Years;

use App\Entity\Years;
use App\Repository\YearsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class YearsController extends AbstractController
{
    /**
     * @Route("/api/years/create", name="createYears", methods={"POST"})
     */
    public function createYears(Security $security, YearsRepository $yearsRepository, Request $request): JsonResponse
    {
        // Récupérer les données de la requête
        $data = json_decode($request->getContent(), true);

        // Vérifications des types des données
        if (!isset($data['yearOfFormation'], $data['hospital'], $data['master'], $data['dateOfStart'])) {
            return new JsonResponse(['error' => 'Les champs yearOfFormation, hospital, master et dateOfStart sont requis'], 400);
        }

        if (!is_string($data['yearOfFormation'])) {
            return new JsonResponse(['error' => 'yearOfFormation doit être une chaîne de caractères'], 400);
        }

        if (!is_string($data['hospital']) || !is_string($data['master'])) {
            return new JsonResponse(['error' => 'hospital et master doivent être des chaînes de caractères'], 400);
        }

        // Validation de dateOfStart
        $dateOfStart = \DateTime::createFromFormat('Y-m-d', $data['dateOfStart']);
        if (!$dateOfStart || $dateOfStart->format('Y-m-d') !== $data['dateOfStart']) {
            return new JsonResponse(['error' => 'dateOfStart doit être une date valide au format AAAA-MM-JJ'], 400);
        }

        // Récupérer l'utilisateur actuel
        $user = $security->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Utilisateur non authentifié'], 401);
        }

        // Vérifier si le binôme yearOfFormation et utilisateur existe déjà
        $existingYear = $yearsRepository->findOneBy([
            'yearOfFormation' => $data['yearOfFormation'],
            'user' => $user,
        ]);

        if ($existingYear) {
            return new JsonResponse(['error' => 'Cette année est déjà enregistrée pour cet utilisateur'], 409);
        }

        // Enregistrer la nouvelle année
        $newYear = new Years(); // Remplacez avec le nom réel de votre entité
        $newYear->setYearOfFormation($data['yearOfFormation']);
        $newYear->setHospital($data['hospital']);
        $newYear->setMaster($data['master']);
        $newYear->setDateOfStart($dateOfStart); // Ajouter la date de début
        $newYear->setUser($user);

        $entityManager = $this->getDoctrine()->getManager();
        $entityManager->persist($newYear);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Année enregistrée avec succès'], 201);
    }
}
