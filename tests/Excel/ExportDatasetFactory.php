<?php

namespace App\Tests\Excel;

use App\Entity\Consultations;
use App\Entity\Formations;
use App\Entity\Gardes;
use App\Entity\Nomenclature;
use App\Entity\Surgeons;
use App\Entity\Surgeries;
use App\Entity\User;
use App\Entity\Years;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Jeux de données FICTIFS et déterministes pour les tests d'équivalence de l'export Excel.
 * Les volumes « profil-* » reproduisent la forme (volumes, répartitions) des plus gros carnets
 * relevés en agrégats anonymes sur la copie de préproduction (aucune donnée réelle).
 *
 * Chaque scénario renvoie [utilisateur, id de l'année exportée].
 */
final class ExportDatasetFactory
{
    public const SCENARIOS = [
        'vide', 'petit', 'multi-annees', 'huit-annees', 'sans-maitre', 'seul-maitre',
        'un-superviseur', 'aucun-superviseur', 'consultations-denses', 'formations-complexes',
        'cas-limites', 'sans-general',
    ];

    public const VOLUMES = ['volume-100', 'volume-1000', 'profil-reel', 'profil-9-annees', 'volume-10000'];

    private const ANATOMIC = ['shoulder', 'humerus', 'elbow', 'forearm', 'wristhand', 'back', 'pelvic', 'hip',
        'proximalFemur', 'midFemur', 'distalFemur', 'knee', 'limb', 'ankle', 'foot'];
    private const GENERAL = ['dig', 'general', 'uro', 'vasc', 'thor', 'plastic', 'neuro', 'transp'];

    private int $seed = 1;
    /** @var list<Nomenclature> */
    private array $nomenclatures = [];
    private int $pending = 0;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return array{0: User, 1: int}
     */
    public function build(string $scenario): array
    {
        $this->seed = crc32($scenario) ?: 1;
        $this->nomenclatures = [];

        if (str_starts_with($scenario, 'aleatoire-')) {
            $result = $this->random();
            $this->em->flush();
            $this->em->clear();

            return $result;
        }

        $result = match ($scenario) {
            'vide' => $this->vide(),
            'petit' => $this->standard(years: ['1'], export: '1', surgeries: [25], nomenclatures: 30, supervisors: 3),
            'multi-annees' => $this->standard(years: ['1', '2', '3'], export: '2', surgeries: [30, 40, 20], nomenclatures: 40, supervisors: 4),
            'huit-annees' => $this->standard(years: ['1', '2', '3', '4', '5', '6', '7', '8'], export: '8', surgeries: [5, 5, 5, 5, 5, 5, 5, 30], nomenclatures: 40, supervisors: 5),
            'sans-maitre' => $this->standard(years: ['2'], export: '2', surgeries: [30], nomenclatures: 30, supervisors: 4, boss: false),
            'seul-maitre' => $this->standard(years: ['1'], export: '1', surgeries: [15], nomenclatures: 20, supervisors: 1, boss: true),
            'un-superviseur' => $this->standard(years: ['1'], export: '1', surgeries: [15], nomenclatures: 20, supervisors: 1, boss: false),
            'aucun-superviseur' => $this->standard(years: ['1'], export: '1', surgeries: [15], nomenclatures: 20, supervisors: 0),
            'consultations-denses' => $this->standard(years: ['3'], export: '3', surgeries: [20], nomenclatures: 20, supervisors: 3, consultationDates: 160, gardes: 120),
            'formations-complexes' => $this->formationsComplexes(),
            'cas-limites' => $this->casLimites(),
            'sans-general' => $this->standard(years: ['1'], export: '1', surgeries: [20], nomenclatures: 20, supervisors: 2, onlyOrtho: true),
            'volume-100' => $this->standard(years: ['1', '2'], export: '2', surgeries: [60, 100], nomenclatures: 80, supervisors: 6),
            'volume-1000' => $this->standard(years: ['1', '2', '3'], export: '3', surgeries: [800, 900, 1000], nomenclatures: 300, supervisors: 12),
            // Forme du carnet le plus lourd relevé : ~1 350 interventions sur l'année, ~3 650 toutes années.
            'profil-reel' => $this->standard(years: ['1', '2', '3', '4'], export: '2', surgeries: [800, 1344, 900, 602], nomenclatures: 371, supervisors: 17, consultationDates: 68, gardes: 44, formations: 23),
            'profil-9-annees' => $this->standard(years: ['1', '2', '3', '4', '5', '6', '7', '8', '9'], export: '2', surgeries: [150, 1377, 150, 150, 150, 150, 150, 150, 293], nomenclatures: 364, supervisors: 20, consultationDates: 55, gardes: 42, formations: 17),
            'volume-10000' => $this->standard(years: ['1', '2'], export: '2', surgeries: [2000, 10000], nomenclatures: 600, supervisors: 20, consultationDates: 120, gardes: 80, formations: 40),
            default => throw new \InvalidArgumentException("Scénario inconnu : $scenario"),
        };

        $this->em->flush();
        $this->em->clear();

        return $result;
    }

    private function vide(): array
    {
        $user = $this->user();
        $year = $this->year($user, '1', '2025-10-01');

        return [$user, $year->getId()];
    }

    /**
     * @param list<string> $years années de formation créées (dans cet ordre)
     * @param list<int> $surgeries nombre d'interventions par année
     */
    private function standard(array $years, string $export, array $surgeries, int $nomenclatures, int $supervisors,
        ?bool $boss = null, int $consultationDates = 20, int $gardes = 12, int $formations = 8, bool $onlyOrtho = false): array
    {
        $user = $this->user();
        $this->nomenclatures($nomenclatures, $onlyOrtho);
        $exportId = null;

        foreach ($years as $index => $yearOfFormation) {
            $startYear = 2017 + $index;
            $year = $this->year($user, $yearOfFormation, $startYear . '-10-01');
            $surgeons = $this->surgeons($year, $supervisors, $boss ?? true);
            $this->surgeries($year, $surgeries[$index], $surgeons, $startYear, $onlyOrtho);

            if ($yearOfFormation === $export) {
                $exportId = $year->getId();
                $this->consultations($year, $consultationDates, $startYear);
                $this->gardes($year, $gardes, $startYear);
                $this->formations($year, $formations, $startYear);
            }
        }

        return [$user, $exportId];
    }

    private function formationsComplexes(): array
    {
        $user = $this->user();
        $this->nomenclatures(10, false);
        $year = $this->year($user, '2', '2025-10-01');
        $surgeons = $this->surgeons($year, 2, true);
        $this->surgeries($year, 10, $surgeons, 2025, false);

        // Bornes 12:00 / 18:00 exactes, plusieurs jours, changement de mois et d'année, rôles.
        $cases = [
            ['staff', '2025-11-03 08:00', '2025-11-03 12:00', 'CHU Test', 'participant'],
            ['staff', '2025-11-03 11:59', '2025-11-03 12:01', 'CHU Test', 'participant'],
            ['journal', '2025-11-04 12:00', '2025-11-04 18:00', 'Ailleurs', 'participant'],
            ['journal', '2025-11-05 12:00', '2025-11-05 18:01', 'Ailleurs', 'speaker'],
            ['lesson', '2025-11-06 18:00', '2025-11-06 21:00', 'CHU Test', 'organiser'],
            ['lesson', '2025-11-07 07:00', '2025-11-07 22:00', 'CHU Test', 'participant'],
            ['congres', '2025-11-28 09:00', '2025-12-02 17:00', 'Congrès', 'participant'],
            ['congres', '2025-12-30 13:00', '2026-01-02 11:00', 'Congrès', 'speaker'],
            ['congres', '2026-02-26 18:30', '2026-03-01 19:00', 'CHU Test', 'organiser'],
            ['staff', '2026-03-10 08:00', '2026-03-11 12:00', 'CHU Test', 'participant'],
            ['journal', '2026-04-14 12:00', '2026-04-16 18:00', 'Ailleurs', 'participant'],
            ['lesson', '2026-05-20 00:00', '2026-05-20 00:00', 'CHU Test', 'participant'],
        ];
        foreach ($cases as [$event, $start, $end, $location, $role]) {
            $this->persist((new Formations())->setEvent($event)->setDateOfStart(new \DateTime($start))
                ->setDateOfEnd(new \DateTime($end))->setName("Formation $event")->setDescription("Description $event")
                ->setLocation($location)->setRole($role)->setYear($year));
        }
        // Gardes sur plusieurs jours et à cheval sur deux mois.
        foreach ([['2025-11-30 20:00', '2025-12-01 08:00', '3'], ['2025-12-24 08:00', '2025-12-27 08:00', '12'],
            ['2026-01-10 12:00', '2026-01-10 18:00', '0'], ['2026-01-11 18:00', '2026-01-11 23:00', '4']] as [$start, $end, $number]) {
            $this->persist((new Gardes())->setDateOfStart(new \DateTime($start))->setDateOfEnd(new \DateTime($end))
                ->setNumber($number)->setYear($year));
        }
        $this->consultations($year, 15, 2025);

        return [$user, $year->getId()];
    }

    private function casLimites(): array
    {
        $user = $this->user('élodie', "o'brien-dupré");
        $this->nomenclatures(30, false);
        $year = $this->year($user, '3', '2025-10-01');

        // Plus de 26 superviseurs (libellés au-delà de Z), accents, homonymes, plusieurs maîtres de stage.
        $surgeons = [];
        for ($i = 0; $i < 30; $i++) {
            $surgeon = (new Surgeons())->setFirstName($i % 7 === 0 ? 'Jean' : 'Prénom' . chr(65 + $i % 26))
                ->setLastName($i % 5 === 0 ? 'Dupont' : 'Nom' . ($i < 26 ? chr(90 - $i) : 'É' . $i))
                ->setBoss($i === 3 || $i === 17)->setYear($year);
            $this->persist($surgeon);
            $surgeons[] = $surgeon;
        }
        $this->em->flush();

        // Positions inattendues, superviseurs absents, codes/nomenclatures nuls, dates identiques (tri stable).
        $positions = ['1', '2', '3', '4', '0', '2', '3', '1'];
        for ($i = 0; $i < 60; $i++) {
            $nomenclature = $i % 9 === 0 ? null : $this->pick($this->nomenclatures);
            $surgery = (new Surgeries())->setYear($year)
                ->setDate(new \DateTime(sprintf('2026-%02d-%02d', 1 + $i % 12, $i % 3 === 0 ? 15 : 1 + $i % 28)))
                ->setSpeciality($nomenclature?->getSpeciality() ?? 'ortho')
                ->setName($i === 7 ? str_repeat('Très longue intervention ', 39) : 'Intervention é' . $i)
                ->setPosition($positions[$i % 8])
                ->setFirstHand($i % 11 === 0 ? '999999' : (string) $this->pick($surgeons)->getId())
                ->setSecondHand($i % 13 === 0 ? null : (string) $this->pick($surgeons)->getId())
                ->setCode($i % 10 === 0 ? null : ($nomenclature ? $nomenclature->getCodeHospitalisation() . $nomenclature->getN() : '0000'))
                ->setNomenclature($nomenclature);
            $this->persist($surgery);
        }
        $this->consultations($year, 30, 2025);
        $this->gardes($year, 20, 2025);
        $this->formations($year, 12, 2025);

        return [$user, $year->getId()];
    }

    /**
     * Combinaison tirée au hasard (graine = nom du scénario) : nombre d'années, d'interventions,
     * de superviseurs, présence d'un maître de stage, consultations, gardes, formations.
     */
    private function random(): array
    {
        $count = $this->rand(1, 9);
        $years = array_map('strval', range(1, $count));
        $export = (string) $this->rand(1, $count);
        $surgeries = array_map(fn () => $this->rand(0, 60), $years);

        return $this->standard(years: $years, export: $export, surgeries: $surgeries,
            nomenclatures: $this->rand(5, 60), supervisors: $this->rand(0, 28), boss: $this->rand(0, 1) === 1,
            consultationDates: $this->rand(0, 90), gardes: $this->rand(0, 60), formations: $this->rand(0, 15),
            onlyOrtho: $this->rand(0, 4) === 0);
    }

    private function user(string $firstname = 'camille', string $lastname = 'martin'): User
    {
        $user = (new User())->setEmail('resident.fictif@example.test')->setFirstname($firstname)
            ->setLastname($lastname)->setRoles([])->setPassword('x')->setSpeciality('ortho');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function year(User $user, string $yearOfFormation, string $start): Years
    {
        $year = (new Years())->setUser($user)->setYearOfFormation($yearOfFormation)
            ->setDateOfStart(new \DateTime($start))->setHospital('CHU Test')->setMaster('maître fictif');
        $this->em->persist($year);
        $this->em->flush();

        return $year;
    }

    private function nomenclatures(int $count, bool $onlyOrtho): void
    {
        for ($i = 0; $i < $count; $i++) {
            $ortho = $onlyOrtho || $i % 10 < 8;
            $nomenclature = (new Nomenclature())
                ->setSpeciality($ortho ? 'ortho' : self::GENERAL[$i % 8])
                ->setName('Acte fictif n° ' . $i)
                ->setCodeHospitalisation((string) (200000 + 17 * $i))
                ->setN((string) ($i % 9));
            if ($ortho) {
                // ~78 % électif (type 1), ~22 % traumatologie (type 2), comme le profil relevé.
                $nomenclature->setType($i % 9 < 7 ? '1' : '2')->setSubType(self::ANATOMIC[$i % 15]);
            }
            $this->persist($nomenclature);
            $this->nomenclatures[] = $nomenclature;
        }
        $this->em->flush();
    }

    /**
     * @return list<Surgeons>
     */
    private function surgeons(Years $year, int $count, bool $withBoss): array
    {
        $surgeons = [];
        for ($i = 0; $i < $count; $i++) {
            $surgeon = (new Surgeons())->setFirstName('prénom' . $i)->setLastName('Nom' . chr(65 + ($i * 7) % 26))
                ->setBoss($withBoss && $i === intdiv($count, 2))->setYear($year);
            $this->persist($surgeon);
            $surgeons[] = $surgeon;
        }
        $this->em->flush();

        return $surgeons;
    }

    /**
     * @param list<Surgeons> $surgeons
     */
    private function surgeries(Years $year, int $count, array $surgeons, int $startYear, bool $onlyOrtho): void
    {
        // Répartition des positions du profil relevé : ~65 % aide (2), ~20 % 1re main aidée (3), ~15 % 1re main (1).
        $positions = ['2', '2', '2', '2', '2', '2', '2', '2', '2', '2', '2', '2', '2', '3', '3', '3', '3', '1', '1', '1'];
        for ($i = 0; $i < $count; $i++) {
            $nomenclature = $this->pick($this->nomenclatures);
            $position = $this->pick($positions);
            $day = new \DateTime(sprintf('%d-10-01', $startYear));
            $day->modify('+' . $this->rand(0, 364) . ' days');
            $surgery = (new Surgeries())->setYear($year)->setDate($day)
                ->setSpeciality($nomenclature->getSpeciality())
                ->setName($nomenclature->getName())
                ->setPosition($position)
                ->setFirstHand($surgeons && $position === '2' ? (string) $this->pick($surgeons)->getId() : null)
                ->setSecondHand($surgeons && $position === '3' ? (string) $this->pick($surgeons)->getId() : null)
                ->setCode($nomenclature->getCodeHospitalisation() . $nomenclature->getN())
                ->setNomenclature($nomenclature)
                ->setCreatedAt(new \DateTime('2026-01-01'));
            $this->persist($surgery);
        }
    }

    private function consultations(Years $year, int $dates, int $startYear): void
    {
        $specialities = ['ortho', 'ortho', 'traumato', 'dig', 'uro', 'vasc', 'plast'];
        $parts = ['morning', 'afternoon', 'night'];
        for ($i = 0; $i < $dates; $i++) {
            $day = new \DateTime(sprintf('%d-10-01', $startYear));
            // Moitié des dates concentrées sur novembre : dépasse les 10 colonnes du modèle.
            $day->modify('+' . ($i % 2 === 0 ? 31 + $i % 30 : $this->rand(0, 364)) . ' days');
            foreach (array_slice($parts, 0, 1 + $i % 2) as $part) {
                $this->persist((new Consultations())->setYear($year)->setDate(clone $day)->setDayPart($part)
                    ->setNumber((string) $this->rand(1, 25))->setSpeciality($this->pick($specialities)));
            }
        }
    }

    private function gardes(Years $year, int $count, int $startYear): void
    {
        for ($i = 0; $i < $count; $i++) {
            $start = new \DateTime(sprintf('%d-10-01 %02d:00', $startYear, [8, 12, 18, 20][$i % 4]));
            $start->modify('+' . ($i % 3 === 0 ? 60 + $i % 28 : $this->rand(0, 364)) . ' days');
            $end = (clone $start)->modify('+' . [4, 12, 24, 36][$i % 4] . ' hours');
            $this->persist((new Gardes())->setYear($year)->setDateOfStart($start)->setDateOfEnd($end)
                ->setNumber((string) $this->rand(0, 30)));
        }
    }

    private function formations(Years $year, int $count, int $startYear): void
    {
        $events = ['staff', 'journal', 'lesson', 'congres'];
        $roles = ['participant', 'participant', 'participant', 'speaker', 'organiser'];
        for ($i = 0; $i < $count; $i++) {
            $start = new \DateTime(sprintf('%d-10-01 %02d:30', $startYear, [7, 11, 13, 19][$i % 4]));
            $start->modify('+' . $this->rand(0, 360) . ' days');
            $end = (clone $start)->modify('+' . [2, 5, 26, 50][$i % 4] . ' hours');
            $this->persist((new Formations())->setYear($year)->setEvent($events[$i % 4])->setDateOfStart($start)
                ->setDateOfEnd($end)->setName('Formation fictive ' . $i)->setDescription('Sujet fictif ' . $i)
                ->setLocation($i % 3 === 0 ? 'CHU Test' : 'Autre lieu')->setRole($this->pick($roles)));
        }
    }

    private function persist(object $entity): void
    {
        $this->em->persist($entity);
        if (++$this->pending % 500 === 0) {
            $this->em->flush();
        }
    }

    private function rand(int $min, int $max): int
    {
        // LCG déterministe, indépendant de mt_rand et de la version de PHP.
        $this->seed = ($this->seed * 1103515245 + 12345) & 0x7fffffff;

        return $min + $this->seed % ($max - $min + 1);
    }

    /**
     * @template T
     * @param list<T> $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->rand(0, count($items) - 1)];
    }
}
