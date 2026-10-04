<?php

namespace App\Controller\Years;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Years;
use App\Repository\YearsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class YearsController extends AbstractController
{
    #[Route('/api/years/create', name: 'createYears', methods: ['POST'])]
    public function createYears(Security $security, YearsRepository $yearsRepository, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        // Récupérer les données de la requête
        $data = json_decode($request->getContent(), true);

        // Vérifications des types des données
        if (!isset($data['yearOfFormation'], $data['hospital'], $data['master'], $data['dateOfStart'])) {
            return $this->errorResponse('Les champs yearOfFormation, hospital, master et dateOfStart sont requis', 400);
        }

        if (!is_string($data['yearOfFormation'])) {
            return $this->errorResponse('yearOfFormation doit être une chaîne de caractères', 400);
        }

        if (!is_string($data['hospital']) || !is_string($data['master'])) {
            return $this->errorResponse('hospital et master doivent être des chaînes de caractères', 400);
        }

        // Validation de dateOfStart
        $dateOfStart = \DateTime::createFromFormat('Y-m-d', $data['dateOfStart']);
        if (!$dateOfStart || $dateOfStart->format('Y-m-d') !== $data['dateOfStart']) {
            return $this->errorResponse('dateOfStart doit être une date valide au format AAAA-MM-JJ', 400);
        }

        // Récupérer l'utilisateur actuel
        $user = $security->getUser();
        if (!$user) {
            return $this->errorResponse('Utilisateur non authentifié', 401);
        }

        // Vérifier si le binôme yearOfFormation et utilisateur existe déjà
        $existingYear = $yearsRepository->findOneBy([
            'yearOfFormation' => $data['yearOfFormation'],
            'user' => $user,
        ]);

        if ($existingYear) {
            return $this->errorResponse('Cette année est déjà enregistrée pour cet utilisateur', 409);
        }

        // Enregistrer la nouvelle année
        $newYear = new Years(); // Remplacez avec le nom réel de votre entité
        $newYear->setYearOfFormation($data['yearOfFormation']);
        $newYear->setHospital($data['hospital']);
        $newYear->setMaster($data['master']);
        $newYear->setDateOfStart($dateOfStart); // Ajouter la date de début
        $newYear->setUser($user);

        $entityManager->persist($newYear);
        $entityManager->flush();

        return new JsonResponse(['message' => 'Année enregistrée avec succès'], 201);
    }

    /**
     * Réponse d'erreur : "message" est la clé lue par la PWA (YearPage) ; "error" est
     * conservée pour compatibilité avec les clients existants.
     */
    private function errorResponse(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'message' => $message], $status);
    }
}
