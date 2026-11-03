<?php

namespace App\Controller\Surgeries;

use App\Entity\Surgeries;
use App\Repository\NomenclatureRepository;
use App\Repository\SurgeriesRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use App\Service\InactiveNomenclatureException;
use App\Service\RepealedOnDateException;
use App\Service\SpecialityNotAllowedException;
use App\Service\SurgeryNomenclatureSelection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * SurgeriesController class handles all the actions related to surgeries.
 */
class SurgeriesController extends AbstractController
{
    private $doctrine;

    /**
     * Constructor.
     *
     * @param ManagerRegistry $doctrine The doctrine service.
     */
    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    /**
     * Add a new surgery to the database.
     * @param Request $request The HTTP request object.
     * @param Security $security The security service.
     * @param UserRepository $userRepository The user repository.
     * @param NomenclatureRepository $nomenclatureRepository The nomenclature repository.
     * @return void
     */
    #[Route('/api/surgeries/add', name: 'Add a new surgery', methods: ['POST'])]
    public function addSurgery(Request $request, Security $security, UserRepository $userRepository, NomenclatureRepository $nomenclatureRepository, YearsRepository $yearsRepository)
    {
        // Get the current user from the security service.
        $user = $security->getUser();

        // Find the resident based on the user ID.
        $resident = $userRepository->findOneBy(['id' => $user]);

        if(!$resident){
            return new JsonResponse([
                'message' => "Aucun utilisateur retrouvé" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);

        // Find the surgery reference by its ID.
        $surgeryReference = $nomenclatureRepository->findOneBy(['id' => $data['surgeryId']]);

        if(!$surgeryReference){
            return new JsonResponse([
                'message' => "Cette intervention n'est pas retrouvé en base de donnée" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Date de l'intervention (illisible : erreur client, plus une erreur 500).
        $date = self::parseDate($data['date'] ?? null);
        if (!$date) {
            return new JsonResponse([
                'message' => "Veuillez indiquer une date valide."
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Étape 4 (ADR-210) : code utilisable à la DATE DE L'INTERVENTION (actif, ou abrogation
        // vérifiée postérieure à cette date pour une saisie tardive).
        try {
            SurgeryNomenclatureSelection::assertSelectable($surgeryReference, $date);
        } catch (InactiveNomenclatureException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'reason' => InactiveNomenclatureException::REASON,
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Find the current year by ID
        $year = $yearsRepository->findOneBy(['id' => $data['year']]);

        if(!$year){
            return new JsonResponse([
                'message' => "Année non retrouvé" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // The year must belong to the connected user.
        if(!$this->isGranted('OWNER', $year)){
            return new JsonResponse([
                'message' => "Cette année ne vous appartient pas"
            ], JsonResponse::HTTP_FORBIDDEN, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // ADR-211 : spécialité demandée vérifiée pour ce code (sans spécialité : principale).
        try {
            $speciality = SurgeryNomenclatureSelection::specialityFor($surgeryReference, $data['speciality'] ?? null);
        } catch (SpecialityNotAllowedException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'reason' => SpecialityNotAllowedException::REASON,
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Code construction
        $code = $surgeryReference->getCodeHospitalisation().''.$surgeryReference->getN();

        // Create a new Surgeries entity.
        $surgery = new Surgeries;

        // Set the attributes of the new surgery entity.
        $surgery->setYear($year)
            ->setNomenclature($surgeryReference)
            ->setDate($date)
            ->setSpeciality($speciality)
            ->setCode($code)
            ->setName($surgeryReference->getName())
            ->setPosition($data['position']);

        // Depending on the position, set the FirstHand and SecondHand attributes.
        switch ($data['position']) {
            case 1:
                $surgery->setFirstHand($resident->getId());
                break;
            case 2:
                $surgery->setFirstHand($data['firstHand']);
                $surgery->setSecondHand($resident->getId());
                break;
            case 3:
                $surgery->setFirstHand($resident->getId());
                $surgery->setSecondHand($data['secondHand']);
                break;
        }

        // Get the entity manager from the doctrine service.
        $entityManager = $this->doctrine->getManager();

        // Persist the new surgery entity.
        $entityManager->persist($surgery);

        // Flush the entity manager to commit the changes.
        $entityManager->flush();

        return new JsonResponse([
                'message' => "ok"
        ], JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' =>  $_ENV['CORS_ALLOW_ORIGIN']]);
    }

    #[Route('/api/surgeries/update/{id}', name: 'UpdateSurgery', methods: ['PUT'])]
    public function updateSurgery($id, Request $request, Security $security, UserRepository $userRepository, YearsRepository $yearsRepository, SurgeriesRepository $surgeriesRepository, SurgeryNomenclatureSelection $selection)
    {
        // Get the current user from the security service.
        $user = $security->getUser();

        // Find the resident based on the user ID.
        $resident = $userRepository->findOneBy(['id' => $user]);

        if(!$resident){
            return new JsonResponse([
                'message' => "Aucun utilisateur retrouvé" 
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Find the existing surgery.
        $surgery = $surgeriesRepository->findOneBy(['id' => $id]);

        if(!$surgery){
            return new JsonResponse([
                'message' => "Aucune chirurgie retrouvée avec cet ID"
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Check if the Surgery is linked to this User
        if(!$this->isGranted('OWNER', $surgery)){
            return new JsonResponse([
                'message' => "Cette évènement ne vous appartient pas"
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Get the POST data and decode it into an associative array.
        $data = json_decode($request->getContent(), true);

        // Acte choisi (surgeryId). Sans surgeryId, une intervention déjà liée garde son acte ; sans
        // nomenclature liée, un acte reste obligatoire (400, comme avant).
        try {
            $surgeryReference = $selection->find($data['surgeryId'] ?? null);
        } catch (\InvalidArgumentException) {
            $surgeryReference = false;
        }
        $currentReference = $surgery->getNomenclature();

        if($surgeryReference === false || (!$surgeryReference && !$currentReference)){
            return new JsonResponse([
                'message' => "Cette intervention n'est pas retrouvé en base de donnée"
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Find the current year by ID
        $year = $yearsRepository->findOneBy(['id' => $data['year']]);

        if(!$year){
            return new JsonResponse([
                'message' => "Année non retrouvé"
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // The target year must also belong to the connected user.
        if(!$this->isGranted('OWNER', $year)){
            return new JsonResponse([
                'message' => "Cette année ne vous appartient pas"
            ], JsonResponse::HTTP_FORBIDDEN, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        $date = self::parseDate($data['date'] ?? null);
        if (!$date) {
            return new JsonResponse([
                'message' => "Veuillez indiquer une date valide."
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }

        // Code, nom, spécialité et relation ne sont recopiés que si un AUTRE acte est choisi :
        // renvoyer l'acte déjà lié conserve l'ancien code INAMI et l'intitulé enregistrés, même
        // si ce code a été désactivé depuis. Étape 4 (ADR-210) : un AUTRE acte doit être
        // utilisable à la nouvelle date ; un acte inchangé ne peut pas être déplacé au-delà de
        // son abrogation vérifiée (refus explicite, jamais de remplacement silencieux du code).
        $anotherAct = $surgeryReference && SurgeryNomenclatureSelection::isAnotherAct($currentReference, $surgeryReference);
        try {
            if ($anotherAct) {
                SurgeryNomenclatureSelection::assertSelectable($surgeryReference, $date);
                // ADR-211 : spécialité du nouvel acte (demandée et autorisée, sinon principale).
                $speciality = SurgeryNomenclatureSelection::specialityFor($surgeryReference, $data['speciality'] ?? null);
            } else {
                SurgeryNomenclatureSelection::assertDateKeepsAct($currentReference, $surgery->getDate(), $date);
            }
        } catch (InactiveNomenclatureException|RepealedOnDateException|SpecialityNotAllowedException $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'reason' => $e::REASON,
            ], JsonResponse::HTTP_BAD_REQUEST, ['Access-Control-Allow-Origin' => $_ENV['CORS_ALLOW_ORIGIN']]);
        }
        if ($anotherAct) {
            SurgeryNomenclatureSelection::applyAct($surgery, $surgeryReference, $speciality);
        }
        // Code historique conservé mais officiellement abrogé à la date : avertissement (étape 5).
        $warning = $anotherAct ? null : SurgeryNomenclatureSelection::repealWarning($currentReference, $date);

        // Update the attributes of the existing surgery entity.
        $surgery->setYear($year)
            ->setDate($date)
            ->setPosition($data['position']);

        // Depending on the position, set the FirstHand and SecondHand attributes.
        switch ($data['position']) {
            case 1:
                $surgery->setFirstHand($resident->getId());
                break;
            case 2:
                $surgery->setFirstHand($data['firstHand']);
                $surgery->setSecondHand($resident->getId());
                break;
            case 3:
                $surgery->setFirstHand($resident->getId());
                $surgery->setSecondHand($data['secondHand'] ?? null);
                break;
        }

        // Get the entity manager from the doctrine service.
        $entityManager = $this->doctrine->getManager();

        // Flush the entity manager to commit the changes.
        $entityManager->flush();

        return new JsonResponse(array_filter([
                'message' => "ok",
                'warning' => $warning,
        ]), JsonResponse::HTTP_OK, ['Access-Control-Allow-Origin' =>  $_ENV['CORS_ALLOW_ORIGIN']]);
    }

    /** Date d'intervention envoyée (même lecture permissive qu'avant) ; null si absente ou illisible. */
    private static function parseDate(mixed $value): ?\DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTime($value);
        } catch (\Exception) {
            return null;
        }
    }
}
