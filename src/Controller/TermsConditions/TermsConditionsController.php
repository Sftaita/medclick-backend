<?php

namespace App\Controller\TermsConditions;

use App\Entity\User;
use App\Entity\TermsConditions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

class TermsConditionsController
{
    private $entityManager;
    private $security;

    public function __construct(EntityManagerInterface $entityManager, Security $security)
    {
        $this->entityManager = $entityManager;
        $this->security = $security;
    }

    /**
     * @Route("/api/terms-conditions", name="get_terms_conditions", methods={"GET"})
     */
    public function getTermsConditions(): JsonResponse
    {
        {
            // Récupérer la dernière version des conditions générales
            $terms = $this->entityManager->getRepository(TermsConditions::class)->findOneBy([], ['publishedAt' => 'DESC']);
        
            // Vérifier si aucune condition n'est trouvée
            if (!$terms) {
                return new JsonResponse(['error' => 'Conditions générales non trouvées.'], 404);
            }
        
            // Retourner les informations des conditions générales
            return new JsonResponse([
                'content' => $terms->getContent(),
                'publishedAt' => $terms->getPublishedAt()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @Route("/api/acceptTerms", name="accept_terms_conditions", methods={"PUT"})
     */
    public function acceptTermsConditions(): JsonResponse
    {
        $userConnected = $this->security->getUser();
        
        // Ensuite tu cherche le user via l'entity correrspondant

        // Récupérer l'entité User depuis la base de données
        $user = $this->entityManager->getRepository(User::class)->find($userConnected);

        if (!$user) {
            return new JsonResponse(['error' => 'Utilisateur non trouvé.'], 404);
        }

        // Ajouter les informations d'acceptation des conditions
        $user->setAcceptedTerms(true);
        $user->setTermsAcceptedDate(new \DateTimeImmutable());

        // Sauvegarder les modifications
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Conditions générales acceptées avec succès.',
            'userId' => $user->getId(),
            'termsAcceptedDate' => $user->getTermsAcceptedDate()->format('Y-m-d H:i:s'),
        ]);
    } 

}
