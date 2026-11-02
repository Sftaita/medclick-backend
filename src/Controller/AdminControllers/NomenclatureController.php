<?php

namespace App\Controller\AdminControllers;

use App\Entity\Nomenclature;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\NomenclatureRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Nomenclature INAMI (administration, ROLE_ADMIN via access_control sur /api/admin).
 *
 * Étape 4 (ADR-210) : les listes renvoient TOUS les codes, actifs et inactifs, avec leur statut,
 * la date officielle d'abrogation INAMI et la date technique de désactivation. Un code se
 * désactive ou se réactive (PUT /api/admin/nomenclature/{id}/status) ; il n'est jamais supprimé
 * et ses interventions et favoris liés ne sont jamais modifiés.
 */
class NomenclatureController extends AbstractController{

    /** Représentation d'un code dans l'administration (listes, changement de statut). */
    private static function normalize(Nomenclature $nomenclature): array
    {
        return [
            'id' => $nomenclature->getId(),
            'speciality' => $nomenclature->getSpeciality(),
            'codeAmbulant' => $nomenclature->getCodeAmbulant(),
            'codeHospitalisation' => $nomenclature->getCodeHospitalisation(),
            'n' => $nomenclature->getN(),
            'name' => $nomenclature->getName(),
            'type' => $nomenclature->getType(),
            'subType' => $nomenclature->getSubType(),
            'active' => $nomenclature->isActive(),
            // Date officielle (jour) et date technique (heure murale du serveur, comme les autres
            // dates de l'administration).
            'inamiRepealedAt' => $nomenclature->getInamiRepealedAt()?->format('Y-m-d'),
            'deactivatedAt' => $nomenclature->getDeactivatedAt()?->format('Y-m-d H:i:s'),
            // Vérification de la date officielle (source citée, date de vérification) : seule une
            // date vérifiée autorise la saisie tardive d'un acte réalisé avant l'abrogation.
            'inamiRepealSource' => $nomenclature->getInamiRepealSource(),
            'inamiRepealVerifiedAt' => $nomenclature->getInamiRepealVerifiedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /** Tous les codes de toutes les spécialités, actifs et inactifs. */
    #[Route('/api/admin/nomenclature', name: 'GetAllNomenclature', methods: ['GET'])]
    public function getAllNomenclature(NomenclatureRepository $nomenclatureRepository): JsonResponse
    {
        $nomenclatures = $nomenclatureRepository->findBy([], ['speciality' => 'ASC', 'id' => 'ASC']);

        return $this->json(array_map(self::normalize(...), $nomenclatures));
    }

    /** Interventions et favoris liés à un code : affichés avant confirmation d'un changement de statut. */
    #[Route('/api/admin/nomenclature/{id<\d+>}/usage', name: 'GetNomenclatureUsage', methods: ['GET'])]
    public function getNomenclatureUsage(int $id, NomenclatureRepository $nomenclatureRepository): JsonResponse
    {
        $nomenclature = $nomenclatureRepository->find($id);
        if (!$nomenclature) {
            return new JsonResponse(['error' => 'Nomenclature not found'], 404);
        }

        return $this->json($nomenclatureRepository->countUsage($nomenclature));
    }

    /**
     * Active ou désactive un code. Corps : { active: bool, inamiRepealedAt?: "AAAA-MM-JJ" | null,
     * inamiRepealSource?: string | null, inamiRepealVerified?: bool }.
     *
     * - désactivation : la date technique est fixée par le serveur (la première désactivation est
     *   conservée si le code l'était déjà) ;
     * - réactivation : la date technique est effacée ;
     * - inamiRepealedAt (date officielle) et inamiRepealSource ne changent que si la clé est envoyée ;
     *   un changement de l'une ou de l'autre efface la vérification ;
     * - inamiRepealVerified: true atteste la vérification (date officielle ET source requises, sinon
     *   400) ; false la retire. Sans vérification, un code inactif n'est jamais utilisable, même pour
     *   une intervention antérieure (aucune période de validité supposée).
     *
     * Seule la ligne de nomenclature change : ni suppression, ni modification des interventions et
     * des favoris liés (qui conservent code, intitulé, spécialité et relation).
     */
    #[Route('/api/admin/nomenclature/{id<\d+>}/status', name: 'UpdateNomenclatureStatus', methods: ['PUT'])]
    public function updateNomenclatureStatus(
        int $id,
        Request $request,
        NomenclatureRepository $nomenclatureRepository,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        // Canal « nomenclature » : fichier dédié en production (config/packages/prod/monolog.yaml).
        #[Target('nomenclature')] LoggerInterface $nomenclatureLogger,
    ): JsonResponse {
        $nomenclature = $nomenclatureRepository->find($id);
        if (!$nomenclature) {
            return new JsonResponse(['error' => 'Nomenclature not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['active']) || !is_bool($data['active'])) {
            return new JsonResponse(['error' => 'Le statut « active » (booléen) est requis.'], 400);
        }

        if (array_key_exists('inamiRepealedAt', $data)) {
            $value = $data['inamiRepealedAt'];
            $repealedAt = null;
            if ($value !== null && $value !== '') {
                $repealedAt = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if (!$repealedAt || $repealedAt->format('Y-m-d') !== $value) {
                    return new JsonResponse(['error' => "Date officielle d'abrogation invalide (format AAAA-MM-JJ)."], 400);
                }
            }
            $nomenclature->setInamiRepealedAt($repealedAt);
        }

        if (array_key_exists('inamiRepealSource', $data)) {
            $source = $data['inamiRepealSource'];
            if ($source !== null && (!is_string($source) || mb_strlen($source) > 255)) {
                return new JsonResponse(['error' => "Source de l'abrogation invalide (texte de 255 caractères au plus)."], 400);
            }
            $nomenclature->setInamiRepealSource($source);
        }

        if (array_key_exists('inamiRepealVerified', $data)) {
            if (!is_bool($data['inamiRepealVerified'])) {
                return new JsonResponse(['error' => '« inamiRepealVerified » doit être un booléen.'], 400);
            }
            if ($data['inamiRepealVerified']) {
                try {
                    $nomenclature->verifyRepeal($clock->now());
                } catch (\DomainException $e) {
                    return new JsonResponse(['error' => $e->getMessage()], 400);
                }
            } else {
                $nomenclature->unverifyRepeal();
            }
        }

        if ($data['active']) {
            $nomenclature->activate();
        } else {
            $nomenclature->deactivate($clock->now());
        }

        $entityManager->flush();

        // Trace d'administration (identifiants techniques seulement, aucune donnée personnelle).
        $admin = $this->getUser();
        $nomenclatureLogger->notice('Nomenclature INAMI : statut modifié', [
            'nomenclature_id' => $nomenclature->getId(),
            'active' => $nomenclature->isActive(),
            'inami_repealed_at' => $nomenclature->getInamiRepealedAt()?->format('Y-m-d'),
            'inami_repeal_source' => $nomenclature->getInamiRepealSource(),
            'inami_repeal_verified' => $nomenclature->getInamiRepealVerifiedAt() !== null,
            'admin_id' => $admin instanceof User ? $admin->getId() : null,
        ]);

        return $this->json(self::normalize($nomenclature));
    }

    #[Route('/api/admin/nomenclature/{speciality}', name: 'GetListOfNomenclature', methods: ['GET'])]
    public function getNomenclature($speciality, NomenclatureRepository $nomenclatureRepository): JsonResponse
    {
        if (!isset($speciality) || !is_string($speciality) || strlen($speciality) > 15) {
            return new JsonResponse(['error' => 'Invalid speciality'], 400);
        }

        // Codes actifs ET inactifs de la spécialité (statut et dates inclus).
        $nomenclatures = $nomenclatureRepository->findBy(['speciality' => $speciality]);

        $data = array_map(self::normalize(...), $nomenclatures);

        return $this->json($data, 200, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
    }

    #[Route('/api/admin/nomenclature', name: 'PostNomenclature', methods: ['POST'])]
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


    #[Route('/api/admin/nomenclature', name: 'UpdateNomenclature', methods: ['PUT'])]
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

    #[Route('/api/admin/nomenclature/update-type', name: 'UpdateNomenclatureType', methods: ['PUT'])]
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

    #[Route('/api/admin/nomenclature/update-subtype', name: 'UpdateNomenclatureSubType', methods: ['PUT'])]
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