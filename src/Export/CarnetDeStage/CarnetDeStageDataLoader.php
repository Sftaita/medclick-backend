<?php

namespace App\Export\CarnetDeStage;

use App\Entity\Nomenclature;
use App\Entity\Surgeries;
use App\Export\ExportFailure;
use App\Export\ExportStep;
use App\Repository\ConsultationsRepository;
use App\Repository\FormationsRepository;
use App\Repository\GardesRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Charge les données d'un carnet de stage en un nombre fixe de requêtes : années, interventions
 * (une requête par année, sans hydratation d'entités), nomenclatures (une requête), superviseurs,
 * consultations, gardes et formations. L'ancien moteur chargeait la nomenclature de chaque
 * intervention à la demande (une requête par code distinct, soit plusieurs centaines).
 *
 * Ordre des interventions : celui de la collection Years::getSurgeries() de l'ancien moteur
 * (requête sans ORDER BY sur l'index year_id, donc par id croissant en InnoDB comme en SQLite),
 * rendu ici explicite par « ORDER BY s.id ».
 */
final class CarnetDeStageDataLoader
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private YearsRepository $yearsRepository,
        private ConsultationsRepository $consultationRepository,
        private GardesRepository $gardeRepository,
        private FormationsRepository $formationsRepository,
    ) {
    }

    /**
     * @throws ExportFailure NOT_FOUND (utilisateur inconnu) ou ACCESS_DENIED (année d'un autre utilisateur)
     */
    public function load(int $userId, int $yearId): CarnetDeStageData
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            throw new ExportFailure(ExportFailure::NOT_FOUND, 'User not found', ExportStep::Loading);
        }

        $allYears = $this->yearsRepository->findBy(['user' => $user]);
        $currentYear = null;
        foreach ($allYears as $year) {
            if ($year->getId() === $yearId) {
                $currentYear = $year;
                break;
            }
        }
        if (!$currentYear) {
            throw new ExportFailure(ExportFailure::ACCESS_DENIED, 'No rights on this year', ExportStep::Loading);
        }

        $allSurgeries = [];
        foreach ($allYears as $year) {
            $rows = $this->em->createQueryBuilder()
                ->select('s.id, s.date, s.speciality, s.name, s.position, s.firstHand, s.secondHand, s.code, IDENTITY(s.nomenclature) AS nomenclatureId')
                ->from(Surgeries::class, 's')
                ->where('s.year = :year')
                ->setParameter('year', $year->getId())
                ->orderBy('s.id', \SortDirection::Ascending)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $row['yearId'] = $year->getId();
                $row['yearOfFormation'] = $year->getYearOfFormation();
                $allSurgeries[] = $row;
            }
        }
        $this->attachNomenclatures($allSurgeries);
        $currentYearSurgeries = array_values(array_filter($allSurgeries, fn (array $s) => $s['yearId'] === $yearId));

        $surgeons = [];
        foreach ($currentYear->getSurgeons() as $surgeon) {
            $surgeons[] = [
                'id' => $surgeon->getId(),
                'firstname' => $surgeon->getFirstName(),
                'lastname' => $surgeon->getLastName(),
                'isManager' => $surgeon->getBoss(),
            ];
        }

        return new CarnetDeStageData(
            user: $user,
            currentYear: $currentYear,
            allYears: $allYears,
            surgeons: $surgeons,
            allSurgeries: $allSurgeries,
            currentYearSurgeries: $currentYearSurgeries,
            consultations: $this->consultationRepository->getConsultations($currentYear->getId()),
            uniqueDates: $this->consultationRepository->getUniqDate($currentYear->getId()),
            // Mêmes appels que l'ancien moteur (tableau contenant l'année).
            gardes: $this->gardeRepository->getGardes([$currentYear]),
            formations: $this->formationsRepository->getFormations([$currentYear]),
        );
    }

    /**
     * Ajoute type et sous-type de nomenclature à chaque intervention (une seule requête).
     *
     * @param list<array<string, mixed>> $surgeries
     */
    private function attachNomenclatures(array &$surgeries): void
    {
        $ids = array_values(array_unique(array_filter(array_column($surgeries, 'nomenclatureId'), fn ($id) => $id !== null)));
        $nomenclatures = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $this->em->createQueryBuilder()
                ->select('n.id, n.type, n.subType')
                ->from(Nomenclature::class, 'n')
                ->where('n.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $nomenclatures[$row['id']] = $row;
            }
        }

        foreach ($surgeries as &$surgery) {
            $id = $surgery['nomenclatureId'];
            if ($id !== null && !isset($nomenclatures[$id])) {
                // L'ancien moteur échouait aussi (entité introuvable au chargement paresseux).
                throw new ExportFailure(ExportFailure::DATABASE, "Nomenclature $id introuvable", ExportStep::Loading);
            }
            $surgery['type'] = $id !== null ? $nomenclatures[$id]['type'] : null;
            $surgery['subtype'] = $id !== null ? $nomenclatures[$id]['subType'] : null;
        }
        unset($surgery);
    }
}
