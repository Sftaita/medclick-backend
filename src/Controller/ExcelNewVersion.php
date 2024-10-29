<?php

namespace App\Controller;

use App\Repository\ConsultationsRepository;
use App\Repository\FormationsRepository;
use App\Repository\GardesRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class ExcelNewVersion extends AbstractController
{
    private $security;
    private $userRepository;
    private $yearsRepository;
    private $consultationRepository;
    private $gardeRepository;
    private $formationsRepository;
   

    public function __construct(Security $security, UserRepository $userRepository, YearsRepository $yearsRepository, ConsultationsRepository $consultationRepository, GardesRepository $gardeRepository, FormationsRepository $formationsRepository)
    {
        $this->security = $security;
        $this->userRepository = $userRepository;
        $this->yearsRepository = $yearsRepository;
        $this->consultationRepository = $consultationRepository;
        $this->gardeRepository = $gardeRepository;
        $this->formationsRepository = $formationsRepository;
     
       
    }

    /**
     * @Route("/api/excel2/{year}", name="excel", methods={ "GET" })
     * @param int $year Définie l'année d'interet pour l'extraction des données.
     * @return Response
     */
    public function ExcelGenerator2(int $year): Response
    {
        $user = $this->userRepository->findOneBy(['id' => $this->security->getUser()]);

        if (!$user) {
            return new Response('User not found', Response::HTTP_NOT_FOUND);
        }

        $userAllYears = $this->yearsRepository->findBy(['user' => $user]);

        // Vérifier que l'année appartient bien à l'utilisateur
        $currentYear = array_filter($userAllYears, function($yearInProcess) use ($year) {
            return $yearInProcess->getId() === $year;
        });

        if (empty($currentYear)) {
            return new Response('No rights on this year', Response::HTTP_FORBIDDEN);
        }

        // Initialise un tableau pour stocker toutes les interventions de l'utilisateur
        $allSurgeries = array();

        foreach ($userAllYears as $yearOfFormation) {
            // Récupère les interventions pour chaque année de formation et les ajoute au tableau $allSurgeries
            $surgeries = $yearOfFormation->getSurgeries()->getValues();
            $allSurgeries = array_merge($allSurgeries, $surgeries);
        }

        // Création de la table
        $spreadsheet = new Spreadsheet();
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load('ExcelTemplate.xlsx');

        // Récupération de l'année en cours
        $currentYearOfFormation = reset($currentYear); // Prend le premier élément correspondant

    /*----------------------------------------------------- PAGE DE GARDE ---------------------------------------------------------------------*/

        $currentSheet = $spreadsheet->getSheet(0);

        $currentSheet->setCellValue("B24", ucfirst($currentYearOfFormation->getYearOfFormation()) . "e année");
        $currentSheet->setCellValue("F26", ucfirst($user->getLastname()));
        $currentSheet->setCellValue("F27", ucfirst($user->getFirstname()));
        $currentSheet->setCellValue("F28", $user->getEmail());

    /*----------------------------------------------------- CARNET DE STAGE ---------------------------------------------------------------------*/

        $currentSheet = $spreadsheet->getSheet(1);

        $currentSheet->setCellValue("H14", "    Dr " . ucfirst($user->getLastname()) . " " . ucfirst($user->getFirstname()))
            ->setCellValue("H19", "    " . $currentYearOfFormation->getHospital())
            ->setCellValue("H22", "    Dr " . $currentYearOfFormation->getMaster())
            ->setCellValue("H24", "    " . date_format($currentYearOfFormation->getDateOfStart(), 'd-m-Y'))
            ->setCellValue("H26", "    " . $currentYearOfFormation->getYearOfFormation())
            ->setCellValue("H30", "    " . date("d-m-Y"));

        // Trouver les superviseurs de l'année en cours de formation
            $request = $currentYearOfFormation->getSurgeons()->getValues();
            $supervisors = array();

            foreach ($request as $currentVariable) {
                $supervisors[] = [
                    "id" => $currentVariable->getId(),
                    "firstname" => $currentVariable->getFirstname(),
                    "lastname" => $currentVariable->getLastname(),
                    "isManager" => $currentVariable->getBoss(),
                    "isFirsthand" => 0,
                    "isSecondhand" => 0
                ];
            };
            

            // Trier les superviseurs : d'abord le manager, puis le reste par ordre alphabétique du nom de famille
            usort($supervisors, function ($a, $b) {
                if ($a['isManager'] == $b['isManager']) {
                    // Si les noms de famille sont identiques, on compare les prénoms
                    if ($a['lastname'] == $b['lastname']) {
                        return strcmp($a['firstname'], $b['firstname']);
                    }
                    return strcmp($a['lastname'], $b['lastname']);
                }
                return $b['isManager'] - $a['isManager'];
            });

            // Attribuer les labels après le tri
            $labels = range('A', 'Z'); // Génère un tableau de lettres de A à Z
            $labelIndex = 0;

            foreach ($supervisors as &$supervisor) {
                $supervisor['label'] = $labels[$labelIndex] ?? ($labelIndex + 1); // Utilise la lettre si disponible, sinon l'index + 1
                $labelIndex++;
            }
            unset($supervisor); // Libérer la référence

        // Entré des superviseur dans la feuille
            if (!empty($supervisors)) {
                $row = 39;
                $step = 1;
                $labelIndex = 0;
                $labels = range('A', 'Z'); // Génère un tableau de lettres de A à Z
                $hasManager = false;
            
                foreach ($supervisors as $surgeon) {
                    if ($surgeon["isManager"]) {
                        $hasManager = true;
                        break;
                    }
                }
            
                foreach ($supervisors as $surgeon) {
                    $label = $labels[$labelIndex] ?? $labelIndex + 1; // Utilise la lettre si disponible, sinon l'index + 1
            
                    if ($surgeon["isManager"]) {
                        $currentSheet->setCellValue('E' . $row, "  id:     " . $label)
                                    ->setCellValue('F' . $row, $surgeon['firstname'])
                                    ->setCellValue('G' . $row, $surgeon['lastname']);
                        $step++;
                        $row++;
                    } elseif (!$surgeon["isManager"] && $step == 1 && count($supervisors) == 1) {
                        $row = 40;
                        $currentSheet->setCellValue('E' . $row, "  id:     " . $label)
                                    ->setCellValue('F' . $row, $surgeon['firstname'])
                                    ->setCellValue('G' . $row, $surgeon['lastname']);
                        $step++;
                        $row++;
                    } elseif (!$surgeon["isManager"] && $step == 1 && count($supervisors) > 1) {
                        $row = $hasManager ? 40 : 41; // Si pas de manager, commence à B40
                        $currentSheet->insertNewRowBefore($row + 1, 1);
                        $currentSheet->setCellValue('E' . $row, "  id:     " . $label)
                                    ->setCellValue('F' . $row, $surgeon['firstname'])
                                    ->setCellValue('G' . $row, $surgeon['lastname']);
                        $step++;
                        $row++;
                    } elseif ($step >= 2) {
                        $currentSheet->insertNewRowBefore($row + 1, 1)
                                    ->setCellValue('E' . $row, "  id:     " . $label)
                                    ->setCellValue('F' . $row, $surgeon['firstname'])
                                    ->setCellValue('G' . $row, $surgeon['lastname']);
                        $row++;
                    }
            
                    $labelIndex++;
                }
            } else {
                $currentSheet->setCellValue('E39', "ATTENTION")
                            ->setCellValue('F39', "Pas de chirurgien")
                            ->setCellValue('G39', "renseigné !");
            }

            $currentSheet->removeRow($row, 1);
        
        // Compléter le tableau des interventions
            $currentRow = $row + 7;

            // Récupérer et trier les interventions de l'année en cours par date
            $currentYearSurgeries = $currentYearOfFormation->getSurgeries()->getValues();
            usort($currentYearSurgeries, function ($a, $b) {
                return $a->getDate() <=> $b->getDate();
            });

            // Créer un tableau associatif pour les labels des superviseurs
            $supervisorLabels = [];
            foreach ($supervisors as $supervisor) {
                $supervisorLabels[$supervisor['id']] = $supervisor['label'];
            }

            foreach ($currentYearSurgeries as $currentSurgery) {
                // Insérer une nouvelle ligne avant la ligne suivante
                $currentSheet->insertNewRowBefore($currentRow + 1, 1);
                $currentSheet->mergeCells("C" . $currentRow . ":H" . $currentRow);
            
                // Remplir les cellules avec les données de l'intervention
                $currentSheet->setCellValue('A' . $currentRow, "  " . $currentSurgery->getDate()->format('d-m-Y'))
                             ->setCellValue('B' . $currentRow, "  " . $currentSurgery->getCode())
                             ->setCellValue('C' . $currentRow, "  " . $currentSurgery->getName());
            
                // Vérifier la position et remplir les cellules appropriées
                switch ($currentSurgery->getPosition()) {
                    case 1:
                        $currentSheet->setCellValue('L' . $currentRow, "1");
                        break;
                    case 2:
                        $firstHandId = $currentSurgery->getFirstHand();
                        $firstHandLabel = isset($supervisorLabels[$firstHandId]) ? $supervisorLabels[$firstHandId] : '';
            
                        $currentSheet->setCellValue('I' . $currentRow, "1")
                                     ->setCellValue('K' . $currentRow, $firstHandLabel);
            
                        // Incrémenter le compteur isFirsthand pour le superviseur
                        foreach ($supervisors as &$supervisor) {
                            if ($supervisor['id'] == $firstHandId) {
                                $supervisor['isFirsthand']++;
                                break;
                            }
                        }
                        unset($supervisor); // Libérer la référence
                        break;
            
                    case 3:
                        $secondHandId = $currentSurgery->getSecondHand();
                        $secondHandLabel = isset($supervisorLabels[$secondHandId]) ? $supervisorLabels[$secondHandId] : '';
            
                        $currentSheet->setCellValue('M' . $currentRow, "1")
                                     ->setCellValue('N' . $currentRow, $secondHandLabel);
            
                        // Incrémenter le compteur isSecondhand pour le superviseur
                        foreach ($supervisors as &$supervisor) {
                            if ($supervisor['id'] == $secondHandId) {
                                $supervisor['isSecondhand']++;
                                break;
                            }
                        }
                        unset($supervisor); // Libérer la référence
                        break;
                }
            
                // Incrémenter currentRow pour pointer vers la nouvelle ligne
                $currentRow++;
            }
            
            

            $currentSheet->removeRow($currentRow, 1);

            // Décompte final
            // Définir une fonction pour remplir les cellules
            function fillSupervisorRow($currentSheet, $currentRow, $currentSupervisor) {
                $totalAssistance = $currentSupervisor['isFirsthand'] + $currentSupervisor['isSecondhand'];
                $percentageFirsthand = ($totalAssistance == 0) ? 0 : round(($currentSupervisor['isFirsthand'] / $totalAssistance) * 100, 1);
                $percentageSecondhand = ($totalAssistance == 0) ? 0 : round(($currentSupervisor['isSecondhand'] / $totalAssistance) * 100, 1);

                // Informations du superviseur
                $currentSheet->insertNewRowBefore($currentRow + 1, 1);
                $currentSheet->setCellValue('F' . $currentRow, "  id:     " . $currentSupervisor['label']);
                $currentSheet->setCellValue('G' . $currentRow, "   Dr   " . $currentSupervisor['firstname'])
                            ->mergeCells("G" . $currentRow . ":H" . $currentRow);
                $currentSheet->setCellValue('I' . $currentRow, $currentSupervisor['lastname'])
                            ->mergeCells("I" . $currentRow . ":J" . $currentRow);
                
                $currentSheet->setCellValue('K' . $currentRow, $currentSupervisor['isFirsthand']);
                $currentSheet->setCellValue('L' . $currentRow, $percentageFirsthand . "%");
                $currentSheet->setCellValue('M' . $currentRow, $currentSupervisor['isSecondhand']);
                $currentSheet->setCellValue('N' . $currentRow, $percentageSecondhand . "%");
            }

            // Décompte final
            $currentRow += 11;
            $startRow = $currentRow + 2;

            // Statistique de l'utilisateur
            $currentSheet->setCellValue('G' . $currentRow, "Dr   " . ucfirst($user->getFirstname()))
                        ->setCellValue('I' . $currentRow, ucfirst($user->getLastname()));

            // Initialiser un flag pour vérifier si un manager est présent
            $hasManager = false;
            $supervisorCount = count($supervisors);

            // Parcourir les superviseurs pour trouver le manager et autres superviseurs
            foreach ($supervisors as $currentSupervisor) {
                if ($currentSupervisor['isManager']) {
                    $hasManager = true;
                    $currentRow++;
                    fillSupervisorRow($currentSheet, $currentRow, $currentSupervisor);
                    break;
                }
            }

            // Si aucun manager n'est trouvé, sauter une ligne
            if (!$hasManager) {
                $currentRow++;
            }

            // Parcourir les superviseurs pour ajouter les collaborateurs
            foreach ($supervisors as $currentSupervisor) {
                if (!$currentSupervisor['isManager']) {
                    $currentRow++;
                    fillSupervisorRow($currentSheet, $currentRow, $currentSupervisor);
                   // $currentSheet->getStyle('G' . $currentRow. ":J" . $currentRow)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK);
                }
            }

            // Fusionner les cellules et supprimer les lignes inutiles
            $currentSheet->mergeCells("C" . $startRow . ":E" . ($currentRow));
            $currentSheet->setCellValue('C' . $startRow, " Collaborateurs");
            $currentSheet->getStyle('C' . $startRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $currentSheet->removeRow($currentRow + 1, 1);
            $currentSheet->removeRow($currentRow + 1, 1);

       
    /*----------------------------------------------------- LISTE RECAPITULATIVE ---------------------------------------------------------------------*/

        $currentSheet = $spreadsheet->getSheet(2);

        $formationYearColumns = [
            1 => "E",
            2 => "F",
            3 => "G",
            4 => "H",
            5 => "I",
            6 => "J",
            7 => "K"
        ];


        $currentSheet->setCellValue("G3", "  Dr " . ucfirst($user->getFirstname()) . " " . ucfirst($user->getLastname()))
                    ->setCellValue("E4", "  " . ucfirst($currentYearOfFormation->getHospital()))
                    ->setCellValue("E5", "  Dr " . ucfirst($currentYearOfFormation->getMaster()))
                    ->getStyle($formationYearColumns[$currentYearOfFormation->getYearOfFormation()] . 6)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THICK);
        
        $anatomicRegion = [
            ['value' => 'shoulder', 'title' => 'ÉPAULE'],
            ['value' => 'humerus', 'title' => 'HUMERUS'],
            ['value' => 'elbow', 'title' => 'COUDE'],
            ['value' => 'forearm', 'title' => 'AVANT-BRAS'],
            ['value' => 'wristhand', 'title' => 'Poignet-Main'],
            ['value' => 'back', 'title' => 'Rachis'],
            ['value' => 'pelvic', 'title' => 'Bassin'],
            ['value' => 'hip', 'title' => 'Hanche'],
            ['value' => 'proximalFemur', 'title' => 'Fémur proximal'],
            ['value' => 'midFemur', 'title' => 'Diaphyse fémorale'],
            ['value' => 'distalFemur', 'title' => 'Fémur distal'],
            ['value' => 'knee', 'title' => 'Genou'],
            ['value' => 'limb', 'title' => 'Jambe'],
            ['value' => 'ankle', 'title' => 'Cheville'],
            ['value' => 'foot', 'title' => 'Pied'],
        ];

        $specialities = [
            ['value' => 'dig', 'title' => 'Chirurgie digestive'],
            ['value' => 'general', 'title' => 'Chirurgie générale'],
            ['value' => 'uro', 'title' => 'Chirurgie urologique'],
            ['value' => 'vasc', 'title' => 'Chirurgie vasculaire'],
            ['value' => 'thor', 'title' => 'Chirurgie thoracique'],
            ['value' => 'plastic', 'title' => 'Chirurgie plastique'],
            ['value' => 'neuro', 'title' => 'Neurochirurgie'],
            ['value' => 'transp', 'title' => 'Transplantation'],
        ];
        
        // Initialiser un tableau pour stocker les informations des interventions avec la nomenclature
        $surgeriesWithNomenclature = array();
        
        foreach ($allSurgeries as $surgery) {
            $nomenclature = $surgery->getNomenclature(); // Supposant que la méthode getNomenclature() existe
        
            // Ajoutez les informations de nomenclature si elles ne sont pas nulles
            $surgeriesWithNomenclature[] = [
                'id' => $surgery->getId(),
                'date' => $surgery->getDate(),
                'speciality' => $surgery->getSpeciality(),
                'name' => $surgery->getName(),
                'position' => $surgery->getPosition(),
                'year' => $surgery->getYear()->getId(),
                'yearOfFormation' => $surgery->getYear()->getYearOfFormation(),
                'firstHand' => $surgery->getFirstHand(),
                'secondHand' => $surgery->getSecondHand(),
                'code' => $surgery->getCode(),
                'nomenclatureId' => $nomenclature ? $nomenclature->getId() : null,
                'type' => $nomenclature ? $nomenclature->getType() : null,
                'subtype' => $nomenclature ? $nomenclature->getSubtype() : null,
            ];
        }
        
        // Initialiser les tableaux de tri
        $orthoTrauma = array();
        $orthoElective = array();
        $general = array();
        
        // Trier les interventions dans les tableaux appropriés
        foreach ($surgeriesWithNomenclature as $surgery) {
            $yearOfFormation = $surgery['yearOfFormation'];
            $subtype = $surgery['subtype'];
            $nomenclatureId = $surgery['nomenclatureId'];
            $nomenclature = $surgery['code'];
            $name = $surgery['name'];
            $position = $surgery['position'];
            $speciality = $surgery['speciality'];
           
        
            switch (true) {
                case $surgery['speciality'] == 'ortho' && $surgery['type'] == 'trauma':
                    if (!isset($orthoTrauma[$subtype])) {
                        $orthoTrauma[$subtype] = array();
                    }
                    if (!isset($orthoTrauma[$subtype][$nomenclatureId])) {
                        $orthoTrauma[$subtype][$nomenclatureId] = [
                            'name' => $name,
                            'nomenclature' => $nomenclature,
                            'speciality' => $speciality,
                            'years' => []
                        ];
                        for ($i = 1; $i <= 7; $i++) {
                            $orthoTrauma[$subtype][$nomenclatureId]['years'][$i] = [
                                'firstHand' => 0,
                                'secondHand' => 0
                            ];
                        }
                    }
        
                    if (($position == 1 || $position == 3)) {
                        $orthoTrauma[$subtype][$nomenclatureId]['years'][$yearOfFormation]['firstHand']++;
                    } elseif (($position == 2)) {
                        $orthoTrauma[$subtype][$nomenclatureId]['years'][$yearOfFormation]['secondHand']++;
                    }
                    break;
                
                case $surgery['speciality'] == 'ortho' && $surgery['type'] == 'elective':
                    if (!isset($orthoElective[$subtype])) {
                        $orthoElective[$subtype] = array();
                    }
                    if (!isset($orthoElective[$subtype][$nomenclatureId])) {
                        $orthoElective[$subtype][$nomenclatureId] = [
                            'name' => $name,
                            'nomenclature' => $nomenclature,
                            'speciality' => $speciality,
                            'years' => []
                        ];
                        for ($i = 1; $i <= 7; $i++) {
                            $orthoElective[$subtype][$nomenclatureId]['years'][$i] = [
                                'firstHand' => 0,
                                'secondHand' => 0
                            ];
                        }
                    }
        
                    if (($position == 1 || $position == 3)) {
                        $orthoElective[$subtype][$nomenclatureId]['years'][$yearOfFormation]['firstHand']++;
                    } elseif (($position == 2)) {
                        $orthoElective[$subtype][$nomenclatureId]['years'][$yearOfFormation]['secondHand']++;
                    }
                    break;
                
                default:
                    if (!isset($general[$subtype])) {
                        $general[$subtype] = array();
                    }
                    if (!isset($general[$subtype][$nomenclatureId])) {
                        $general[$subtype][$nomenclatureId] = [
                            'name' => $name,
                            'nomenclature' => $nomenclature,
                            'speciality' => $speciality,
                            'years' => []
                        ];
                        for ($i = 1; $i <= 7; $i++) {
                            $general[$subtype][$nomenclatureId]['years'][$i] = [
                                'firstHand' => 0,
                                'secondHand' => 0
                            ];
                        }
                    }
        
                    if (($position == 1 || $position == 3)) {
                        $general[$subtype][$nomenclatureId]['years'][$yearOfFormation]['firstHand']++;
                    } elseif (($position == 2)) {
                        $general[$subtype][$nomenclatureId]['years'][$yearOfFormation]['secondHand']++;
                    }
                    break;
            }
        }
        
        $currentRow = 16;
             
        foreach ($anatomicRegion as $anatomic) {

            // Recherche dans le tableau $orthoElective ceux dont $subtype = $anatomic["value"]
            if (isset($orthoElective[$anatomic["value"]])) {
        
                foreach ($orthoElective[$anatomic["value"]] as $nomenclatureId => $details) {
                    $currentSheet->insertNewRowBefore($currentRow + 1, 1);
        
                    // Mettre 'nomenclature' dans la cellule A
                    $currentSheet->setCellValue('A' . $currentRow, '  ' . $details['nomenclature']);
        
                    // Mettre 'name' dans la cellule B
                    $currentSheet->setCellValue('B' . $currentRow, '  ' . $details['name']);
        
                    // Variables pour stocker les totaux
                    $firstHandTotal = 0;
                    $secondHandTotal = 0;
        
                    // Remplir les cellules E à K (seconde main) et calculer la somme
                    for ($i = 1; $i <= 7; $i++) {
                        $secondHandValue = $details['years'][$i]['secondHand'];
                        $secondHandTotal += $secondHandValue; // Ajouter à la somme totale
                        $currentSheet->setCellValue($formationYearColumns[$i] . $currentRow, $secondHandValue);
                    }
        
                    // Remplir les cellules M à S (première main) et calculer la somme
                    $firstHandStartCol = 'M';
                    foreach ($formationYearColumns as $year => $column) {
                        $firstHandCol = chr(ord($firstHandStartCol) + ($year - 1));
                        $firstHandValue = $details['years'][$year]['firstHand'];
                        $firstHandTotal += $firstHandValue; // Ajouter à la somme totale
                        $currentSheet->setCellValue($firstHandCol . $currentRow, $firstHandValue);
                    }
        
                    // Écrire les sommes totales dans les colonnes L, T et U
                    $currentSheet->setCellValue('T' . $currentRow, $firstHandTotal); // Somme des premières mains
                    $currentSheet->setCellValue('L' . $currentRow, $secondHandTotal); // Somme des secondes mains
                    $currentSheet->setCellValue('U' . $currentRow, $firstHandTotal + $secondHandTotal); // Somme totale
        
                    // Calculer et écrire le pourcentage dans la colonne V, avec gestion de la division par zéro
                    if ($firstHandTotal + $secondHandTotal > 0) {
                        $percentage = ($firstHandTotal / ($firstHandTotal + $secondHandTotal)) * 100;
                    } else {
                        $percentage = 0;
                    }
                    $currentSheet->setCellValue('V' . $currentRow, round($percentage, 2) . '%'); // Pourcentage avec arrondi à 2 décimales
        
                    $currentRow++;
                }
                $currentSheet->removeRow($currentRow, 1);
                $currentRow += 3; // Sauter quelques lignes pour la prochaine spécialité
            } else {
                $currentRow += 4; // Si aucun élément trouvé, sauter quelques lignes
            }
        }
        

        // On refait la même chose avec $orthoTrauma
        $currentRow = $currentRow + 10;

        foreach ($anatomicRegion as $anatomic) {

            // Recherche dans le tableau $orthoTrauma ceux dont $subtype = $anatomic["value"]
            if (isset($orthoTrauma[$anatomic["value"]])) {

                foreach ($orthoTrauma[$anatomic["value"]] as $nomenclatureId => $details) {
                    $currentSheet->insertNewRowBefore($currentRow + 1, 1);

                    // Mettre 'nomenclature' dans la cellule A
                    $currentSheet->setCellValue('A' . $currentRow, '  ' . $details['nomenclature']);

                    // Mettre 'name' dans la cellule B
                    $currentSheet->setCellValue('B' . $currentRow, '  ' . $details['name']);

                    // Variables pour stocker les totaux
                    $firstHandTotal = 0;
                    $secondHandTotal = 0;

                    // Remplir les cellules E à K (seconde main) et calculer la somme
                    for ($i = 1; $i <= 7; $i++) {
                        $secondHandValue = $details['years'][$i]['secondHand'];
                        $secondHandTotal += $secondHandValue; // Ajouter à la somme totale
                        $currentSheet->setCellValue($formationYearColumns[$i] . $currentRow, $secondHandValue);
                    }

                    // Remplir les cellules M à S (première main) et calculer la somme
                    $firstHandStartCol = 'M';
                    foreach ($formationYearColumns as $year => $column) {
                        $firstHandCol = chr(ord($firstHandStartCol) + ($year - 1));
                        $firstHandValue = $details['years'][$year]['firstHand'];
                        $firstHandTotal += $firstHandValue; // Ajouter à la somme totale
                        $currentSheet->setCellValue($firstHandCol . $currentRow, $firstHandValue);
                    }

                    // Écrire les sommes totales dans les colonnes L, T et U
                    $currentSheet->setCellValue('L' . $currentRow, $firstHandTotal); // Somme des premières mains
                    $currentSheet->setCellValue('T' . $currentRow, $secondHandTotal); // Somme des secondes mains
                    $currentSheet->setCellValue('U' . $currentRow, $firstHandTotal + $secondHandTotal); // Somme totale

                    // Calculer et écrire le pourcentage dans la colonne V, avec gestion de la division par zéro
                    if ($firstHandTotal + $secondHandTotal > 0) {
                        $percentage = ($firstHandTotal / ($firstHandTotal + $secondHandTotal)) * 100;
                    } else {
                        $percentage = 0;
                    }
                    $currentSheet->setCellValue('V' . $currentRow, round($percentage, 2) . '%'); // Pourcentage avec arrondi à 2 décimales

                    $currentRow++;
                }
                $currentSheet->removeRow($currentRow, 1);
                $currentRow += 3; // Sauter quelques lignes pour la prochaine spécialité
            } else {
                $currentRow += 4; // Si aucun élément trouvé, sauter quelques lignes
            }
        }
 

        // Préparer les données regroupées par spécialité
        $groupedData = [];

        foreach ($specialities as $speciality) {
            foreach ($general[""] as $key => $details) {
                if ($details['speciality'] === $speciality['value']) {
                    if (!isset($groupedData[$speciality['title']])) {
                        $groupedData[$speciality['title']] = [];
                    }
                    $groupedData[$speciality['title']][] = $details;
                }
            }
        }

        $currentRow += 10; // Ajustement initial de la ligne

        foreach ($groupedData as $detailsList) {
            foreach ($detailsList as $details) {
                $currentSheet->insertNewRowBefore($currentRow + 1, 1);
                $currentSheet->setCellValue('A' . $currentRow, '  ' . $details['nomenclature']);
                $currentSheet->setCellValue('B' . $currentRow, '  ' . $details['name']);
        
                // Variables pour stocker les totaux
                $firstHandTotal = 0;
                $secondHandTotal = 0;
        
                // Remplir les cellules E à K (seconde main) et calculer la somme
                for ($i = 1; $i <= 7; $i++) {
                    $secondHandValue = $details['years'][$i]['secondHand'];
                    $secondHandTotal += $secondHandValue; // Ajouter à la somme totale
                    $currentSheet->setCellValue($formationYearColumns[$i] . $currentRow, $secondHandValue);
                }
        
                // Remplir les cellules M à S (première main) et calculer la somme
                $firstHandStartCol = 'M';
                foreach ($formationYearColumns as $year => $column) {
                    $firstHandCol = chr(ord($firstHandStartCol) + ($year - 1));
                    $firstHandValue = $details['years'][$year]['firstHand'];
                    $firstHandTotal += $firstHandValue; // Ajouter à la somme totale
                    $currentSheet->setCellValue($firstHandCol . $currentRow, $firstHandValue);
                }
        
                // Écrire les sommes totales dans les colonnes L, T et U
                $currentSheet->setCellValue('T' . $currentRow, $firstHandTotal); // Somme des premières mains
                $currentSheet->setCellValue('L' . $currentRow, $secondHandTotal); // Somme des secondes mains
                $currentSheet->setCellValue('U' . $currentRow, $firstHandTotal + $secondHandTotal); // Somme totale
        
                // Calculer et écrire le pourcentage dans la colonne V, avec gestion de la division par zéro
                if ($firstHandTotal + $secondHandTotal > 0) {
                    $percentage = ($firstHandTotal / ($firstHandTotal + $secondHandTotal)) * 100;
                } else {
                    $percentage = 0;
                }
                $currentSheet->setCellValue('V' . $currentRow, round($percentage, 2) . '%'); // Pourcentage avec arrondi à 2 décimales
        
                $currentRow++;
            }
            $currentSheet->removeRow($currentRow, 1);
            $currentRow += 3; // Sauter quelques lignes pour la prochaine spécialité
        }
        

    /*----------------------------------------------------- CONSULTATIONS ----------------------------------------------------------------------*/

        $currentSheet = $spreadsheet->getSheet(3);
               
        $currentSheet->setCellValue("C3", "  Dr " . ucfirst($user->getFirstname()) . " " . ucfirst($user->getLastname()))
            ->setCellValue("C4", "  " . ucfirst($currentYearOfFormation->getHospital()))
            ->setCellValue("C5", "  Dr " . ucfirst($currentYearOfFormation->getMaster()))
            ->setCellValue("C6", "  " . $currentYearOfFormation->getYearOfFormation());

        // Lignes de départ pour chaque mois
        $rawByMonth = [1 => 18, 2 => 20, 3 => 22, 4 => 24, 5 => 26, 6 => 28, 7 => 30, 8 => 32, 9 => 34, 10 => 12, 11 => 14, 12 => 16];
        $ColByMonth = array_fill(1, 12, 'B'); // Initialiser chaque mois à la colonne 'B'

        // 1. Chercher toutes les consultations triées par mois.
        $consultations = $this->consultationRepository->getConsultations($currentYearOfFormation->getId());
        $uniqueDates = $this->consultationRepository->getUniqDate($currentYearOfFormation->getId());

        // Vérification si des consultations existent
        if (!empty($consultations)) {

            // Somme des consultations par mois
            $SumByMonth = array_fill(1, 12, 0);

            foreach ($uniqueDates as $date) {

                $dailyConsultations = [];
                $currentMonth = date_format($date['date'], 'n');
                $currentDateFormatted = date_format($date['date'], 'd-m-y'); // Format de date en 'dd-mm-yy'

                // Calcul du nombre total de consultations pour cette date
                foreach ($consultations as $consultation) {
                    if (date_format($consultation['date'], 'd-m-y') === $currentDateFormatted) {
                        $dailyConsultations[] = $consultation['number'];
                    }
                    $SumByMonth[date_format($consultation['date'], 'n')] += $consultation['number'];
                }

                $totalConsultationsForDate = array_sum($dailyConsultations);
                $currentContentRow = $rawByMonth[$currentMonth];
                $currentContentCol = $ColByMonth[$currentMonth];

                $currentSheet->setCellValue($currentContentCol . ($currentContentRow - 1), $currentDateFormatted)
                    ->setCellValue($currentContentCol . $currentContentRow, $totalConsultationsForDate);

                // Décaler la colonne pour le mois en cours
                if ($currentContentCol < "K") {
                    $ColByMonth[$currentMonth]++;
                } else {
                    $ColByMonth[$currentMonth]++;
                    $currentSheet->insertNewColumnBefore($currentContentCol, 1)
                        ->setCellValue($currentContentCol . "10", "DATE");
                }
            }

            // Calcul des totaux par spécialité
            $specialies = ["ortho", "traumato", "dig", "uro", "vasc", "plast"];
            $SPE_LABELS = [
                "ortho" => "Orthopédie",
                "traumato" => "Traumatologie",
                "dig" => "Digestive",
                "uro" => "Urologie",
                "vasc" => "Vasculaire",
                "plast" => "Plastique"
            ];

            $consultationsBySpeciality = [];

            foreach ($specialies as $speciality) {
                $filteredConsultations = array_filter($consultations, function ($consultation) use ($speciality) {
                    return $consultation['speciality'] === $speciality;
                });

                $totalBySpeciality = array_sum(array_column($filteredConsultations, 'number'));
                $consultationsBySpeciality[$speciality] = $totalBySpeciality;
            }

            // Détermination des colonnes pour le récapitulatif
            $endCol = max($ColByMonth);

            if ($endCol >= "K") {
                $currentSheet->removeColumn($endCol, 3);

                // Colonne de l'intitulé de la spécialité
                for ($i = 1; $i <= 2; $i++) {
                    $endCol++;
                }

                // Colonne du nombre de consultations
                $totalCol = $endCol;
                for ($i = 1; $i <= 2; $i++) {
                    $totalCol++;
                }
            } else {
                $currentSheet->removeColumn("K", 3);
                $endCol = "M";
                $totalCol = "O";
            }

            $row = 11;

            foreach ($consultationsBySpeciality as $speciality => $total) {
                if ($total !== 0) {
                    $currentSheet->setCellValue($endCol . $row, "  " . $SPE_LABELS[$speciality])
                        ->setCellValue($totalCol . $row, "  " . $total);
                    $row++;
                }
            }
        }


    /*----------------------------------------------------- GARDES ---------------------------------------------------------*/

        $currentSheet = $spreadsheet->getSheet(4);

        $currentSheet->setCellValue("C3", "  Dr " . ucfirst($user->getFirstname()) . " " . ucfirst($user->getLastname()))
                    ->setCellValue("C4", "  " . ucfirst($currentYearOfFormation->getHospital()))
                    ->setCellValue("C5", "  Dr " . ucfirst($currentYearOfFormation->getMaster()))
                    ->setCellValue("C6", "  " . $currentYearOfFormation->getYearOfFormation());

        $gardes = $this->gardeRepository->getGardes($currentYear);

        // Lignes de départ pour chaque mois
        $rows = ["1" => "17", "2" => "19", "3" => "21", "4" => "23", "5" => "25", "6" => "27", "7" => "29", "8" => "31", "9" => "33", "10" => "11", "11" => "13", "12" => "15"];
        $ColByMonth = array_fill(1, 12, 'B'); // Initialiser chaque mois à la colonne 'B'
        
        // Nombre de patients vus pendant les gardes par mois
        $SumByMonth = array_fill(1, 12, 0);
        
        for ($month = 1; $month <= 12; $month++) {
        
            $column = $ColByMonth[$month];
        
            // Complétion des données par mois
            foreach ($gardes as $garde) {
        
                if (date_format($garde['dateOfStart'], 'n') == $month) {
        
                    $currentSheet->setCellValue($column . $rows[$month], date_format($garde['dateOfStart'], 'd-m-y'))
                        ->setCellValue($column . ($rows[$month] + 1), $garde['number']);
        
                    $SumByMonth[$month] += $garde['number'];
        
                    // Décalage de la colonne du mois
                    if ($column < "K" || $column < max($ColByMonth)) {
                        $column++;
                    } else {
                        $column++;
                        $currentSheet->insertNewColumnBefore($column, 1)
                            ->setCellValue($column . "10", "DATE");
                    }
        
                    $ColByMonth[$month] = $column;
                }
            }
        }
        
        $endCol = max($ColByMonth);
        
        if ($endCol > "K") {
            $currentSheet->removeColumn($endCol, 3);
        
            // Détermination de la colonne pour l'intitulé
            for ($i = 1; $i <= 2; $i++) {
                $endCol++;
            }
        
            // Détermination de la colonne pour le nombre de consultations
            $totalCol = $endCol;
            for ($i = 1; $i <= 2; $i++) {
                $totalCol++;
            }
        } else {
            $currentSheet->removeColumn("K", 3);
            $endCol = "M";
            $totalCol = "O";
        }
        
        // Afficher le total des gardes dans la colonne correcte
        $currentSheet->setCellValue($totalCol . "11", count($gardes));
        
    /*----------------------------------------------------- RAPPORT D'ACTIVITE ---------------------------------------------------------*/

    $currentSheet = $spreadsheet->getSheet(5);

    /**
     * Détermine la ligne de départ
     * @param int $month
     * @return int Numéro de ligne
     */
    function getRow($month)
    {
        if ($month <= 2 || $month >= 9) {
            $row = 4;
        } else {
            $row = 30;
        }

        return $row;
    }

    // Consultations :

    

    if (!empty($consultations)) {
        $ColByMonth = ["1" => "AP", "2" => "AX", "3" => "G", "4" => "O", "5" => "W", "6" => "AH", "7" => "AP", "8" => "AX", "9" => "G", "10" => "O", "11" => "W", "12" => "AH"];

        for ($month = 1; $month <= 12; $month++) {

            $row = getRow($month);

            /**
             * @var array Ensemble des consultations du mois en cours (n du foreach) 
             */
            $ConsForMonth = [];

            foreach ($consultations as $consultation) {
                if (date_format($consultation['date'], 'n') == $month) {
                    $ConsForMonth[] = $consultation;
                }
            }

            // on crée un tableau correspondant à chaque période de la journée selon les jours.
            $table = [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]];

            foreach ($ConsForMonth as $cons) {
                $day = date_format($cons['date'], 'N');       // 1-7

                /**
                 * @var int Periode de la journé : morning , afternoon, night
                 */
                $period = 0;

                if ($cons['dayPart'] === "afternoon") {
                    $period = 1;
                } elseif ($cons['dayPart'] === "night") {
                    $period = 2;
                };

                $table[$day][$period] = $table[$day][$period] + $cons["number"];
            }
            unset($cons);

            // On complète l'Excel : 

            $total = 0;

            foreach ($table as $currentDay) {

                foreach ($currentDay as $number) {

                    if ($number !== 0) {
                        $currentSheet->setCellValue($ColByMonth[$month] . $row, $number);
                        $total =  $total + $number;
                    }
                    $row++;
                }
            }
            $currentSheet->setCellValue($ColByMonth[$month] . $row, $total);
            unset($currentDay, $number);
        }
    }

    // Intervention :

        if (!empty($currentYearSurgeries)) {

            /**
             * @var array Filtre les interventions où l'utilisateur est en première main (position 1 ou 3)
             */
            $firstHands = array_filter($currentYearSurgeries, function ($var) {
                return ($var->getPosition() == 1 || $var->getPosition() == 3);
            });

            /**
             * @var array Filtre les interventions où l'utilisateur est en deuxième main (position 2)
             */
            $secondHands = array_filter($currentYearSurgeries, function ($var) {
                return $var->getPosition() == 2;
            });


            // On trie les interventions par mois :

            //Première main : 

            $ColByMonth = ["1" => "AR", "2" => "AZ", "3" => "I", "4" => "Q", "5" => "Y", "6" => "AJ", "7" => "AR", "8" => "AZ", "9" => "I", "10" => "Q", "11" => "Y", "12" => "AJ"];

            for ($month = 1; $month <= 12; $month++) {

                $row = getRow($month);
                $days = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];

                // Comptage par jours : 
                foreach ($firstHands as $request) {

                    if ($request->getDate()->format('n') == $month) {
                        $days[$request->getDate()->format('N')]++;
                    }
                }

                unset($request);

                // On complète le tableau :

                $total = 0;
                foreach ($days as $number) {

                    if ($number !== 0) {
                        $currentSheet->setCellValue($ColByMonth[$month] . $row, $number);
                        $total = $total + $number;
                    }

                    $row = $row + 3;
                }
                $currentSheet->setCellValue($ColByMonth[$month] . $row, $total);
                unset($number);
            }
            unset($month);

            //Deuxième main : 

            $ColByMonth = ["1" => "AQ", "2" => "AY", "3" => "H", "4" => "P", "5" => "X", "6" => "AI", "7" => "AQ", "8" => "AY", "9" => "H", "10" => "P", "11" => "X", "12" => "AI"];

            for ($month = 1; $month <= 12; $month++) {

                $row = getRow($month);
                $days = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];

                // Comptage par jours : 

                // Comptage par jours : 
                foreach ($secondHands as $request) {

                    if ($request->getDate()->format('n') == $month) {
                        $days[$request->getDate()->format('N')]++;
                    }
                }


                // On complète le tableau :
                $total = 0;
                foreach ($days as $number) {

                    if ($number !== 0) {
                        $currentSheet->setCellValue($ColByMonth[$month] . $row, $number);
                        $total = $total + $number;
                    }

                    $row = $row + 3;
                }
                $currentSheet->setCellValue($ColByMonth[$month] . $row, $total);
                unset($number);
            }
        }

    // Formations :

    $formations = $this->formationsRepository->getFormations($currentYear);
 

    if (!empty($formations)) {
        $ColByMonth = ["1" => "AL", "2" => "AT", "3" => "C", "4" => "K", "5" => "S", "6" => "AD", "7" => "AL", "8" => "AT", "9" => "C", "10" => "K", "11" => "S", "12" => "AD"];

        for ($n = 1; $n <= 12; $n++) {

            $table = ["staff" => [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]],  "journal" => [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]], "lesson" => [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]], "congres" => [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]]];
            $row = getRow($n);

            foreach ($formations as $formation) {

                /**
                 * @var string Type d'évènement (staff - journal - lesson -congres) .
                 */
                $type = $formation['event'];


                $dayOfStart = date_format($formation['dateOfStart'], 'd-m-Y');
                $dayOfEnd = date_format($formation['dateOfEnd'], 'd-m-Y');
                $month = date_format($formation['dateOfStart'], 'n');
                $day = date_format($formation['dateOfStart'], 'N');
                $startTime =  date_format($formation['dateOfStart'], 'H:i');
                $endTime =  date_format($formation['dateOfEnd'], 'H:i');


                // Première situation : L'évènement se déroule sur une journée.
                if ($dayOfStart == $dayOfEnd) {

                    $month = date_format($formation['dateOfStart'], 'n');
                    $day = date_format($formation['dateOfStart'], 'N');

                    if ($month == $n) {

                        if ($startTime < "12:00") {
                            if ($endTime <= "12:00") {
                                $table[$type][$day][0]++;
                            }

                            if ($endTime > "12:00" & $endTime <= "18:00") {
                                $table[$type][$day][0]++;
                                $table[$type][$day][1]++;
                            }

                            if ($endTime > "18:00") {
                                $table[$type][$day][0]++;
                                $table[$type][$day][1]++;
                                $table[$type][$day][2]++;
                            }
                        }

                        if ($startTime >= "12:00" & $startTime < "18:00") {
                            if ($endTime <= "18:00") {
                                $table[$type][$day][1]++;
                            }

                            if ($endTime > "18:00") {
                                $table[$type][$day][1]++;
                                $table[$type][$day][2]++;
                            }
                        }

                        if ($startTime >= "18:00") {
                            $table[$type][$day][2]++;
                        }
                    }
                }

                // Deuxième situation : L'évènement se déroule sur plus d'une journée.
                if ($dayOfStart !== $dayOfEnd) {

                    // 1. On complète la journée de départ .
                    // Condition: La date appartient au mois en cours de traitmeent.
                    $monthStart = date_format($formation['dateOfStart'], 'n');

                    if ($monthStart == $n) {

                        if ($startTime < "12:00") {
                            $table[$type][$day][0]++;
                            $table[$type][$day][1]++;
                            $table[$type][$day][2]++;
                        }

                        if ($startTime >= "12:00" & $startTime < "18:00") {
                            $table[$type][$day][1]++;
                            $table[$type][$day][2]++;
                        }

                        if ($startTime >= "18:00") {
                            $table[$type][$day][2]++;
                        }
                    }

                    // 2. On complète la journée de fin .
                    // Condition: La date appartient au mois en cours de traitmeent.
                    $monthEnd = date_format($formation['dateOfEnd'], 'n');
                    $dayEnd = date_format($formation['dateOfEnd'], 'N');



                    if ($monthEnd == $n) {
                        $endTime = date_format($formation['dateOfEnd'], 'H:i');
                        //dd($endTime, $formation);
                        if ($endTime <= "12:00") {
                            $table[$type][$dayEnd][0]++;
                        } elseif ($endTime > "12:00" & $endTime <= "18:00") {
                            $table[$type][$dayEnd][0]++;
                            $table[$type][$dayEnd][1]++;
                        } elseif ($endTime >= "18:00") {
                            $table[$type][$dayEnd][0]++;
                            $table[$type][$dayEnd][1]++;
                            $table[$type][$dayEnd][2]++;
                        }
                    }

                    // On complète les journées entre.
                    $startDate = $formation["dateOfStart"];
                    $endDate = $formation["dateOfEnd"];
                    $interval = \DateInterval::createFromDateString('1 day');
                    $period = new \DatePeriod($startDate, $interval, $endDate);

                    foreach ($period as $date) {

                        $month = date_format($date, 'n');
                        $day = date_format($date, 'N');

                        // z = Nombre de jours depuis le début de l'année .
                        $t =  date_format($date, 'z');
                        $from = date_format($startDate, 'z');
                        $to = date_format($endDate, 'z');


                        // Si le mois est égale à celui en cours :
                        if ($month == $n & $t > $from & $t < $to) {
                            $table[$type][$day][0]++;
                            $table[$type][$day][1]++;
                            $table[$type][$day][2]++;
                        }
                    }


                    //    
                }
            }

            $saveRow = $row;
            $col = $ColByMonth[$n];
            foreach ($table as $event) {
                $total = 0;
                $row = $saveRow;

                foreach ($event as $d) {


                    foreach ($d as $e) {
                        if ($e !== 0) {
                            $currentSheet->setCellValue($col . $row, $e);
                            $total = $total + $e;
                        }
                        $row++;
                    }
                }


                $currentSheet->setCellValue($col . $row, $total);

                $col++;
            }
            unset($tab);
        }
        unset($formation);
    }


    // Gardes :           

    if (!empty($gardes)) {
        $ColByMonth = ["1" => "AS", "2" => "BA", "3" => "J", "4" => "R", "5" => "Z", "6" => "AK", "7" => "AS", "8" => "BA", "9" => "J", "10" => "R", "11" => "Z", "12" => "AK"];

        // On crée un tableau correespondant à chaque période de la journée selon les jours du mois.

        for ($n = 1; $n <= 12; $n++) {

            $table = [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]];
            $row = getRow($n);

            foreach ($gardes as $garde) {

                $dayOfStart = date_format($garde['dateOfStart'], 'd-m-Y');
                $dayOfEnd = date_format($garde['dateOfEnd'], 'd-m-Y');

                $month = date_format($garde['dateOfStart'], 'n');
                $day = date_format($garde['dateOfStart'], 'N');

                $startTime =  date_format($garde['dateOfStart'], 'H:i');
                $endTime =  date_format($garde['dateOfEnd'], 'H:i');


                // Première situation : L'évènement se déroule sur une journée.
                if ($dayOfStart == $dayOfEnd) {

                    $month = date_format($garde['dateOfStart'], 'n');
                    $day = date_format($garde['dateOfStart'], 'N');

                    // Rempli les cases de la journée selon l'horaire :
                    if ($month == $n) {

                        if ($startTime < "12:00") {
                            if ($endTime <= "12:00") {
                                $table[$day][0]++;
                            }

                            if ($endTime > "12:00" & $endTime <= "18:00") {
                                $table[$day][0]++;
                                $table[$day][1]++;
                            }

                            if ($endTime > "18:00") {
                                $table[$day][0]++;
                                $table[$day][1]++;
                                $table[$day][2]++;
                            }
                        }

                        if ($startTime >= "12:00" & $startTime < "18:00") {
                            if ($endTime <= "18:00") {
                                $table[$day][1]++;
                            }

                            if ($endTime > "18:00") {
                                $table[$day][1]++;
                                $table[$day][2]++;
                            }
                        }

                        if ($startTime >= "18:00") {
                            $table[$day][2]++;
                        }
                    }
                }

                // Deuxième situation : L'évènement se déroule sur plus d'une journée.
                if ($dayOfStart !== $dayOfEnd) {

                    // 1. On complète la journée de départ .

                    // Condition: La date appartient au mois en cours de traitmeent.
                    $monthStart = date_format($garde['dateOfStart'], 'n');

                    if ($monthStart == $n) {

                        if ($startTime < "12:00") {
                            $table[$day][0]++;
                            $table[$day][1]++;
                            $table[$day][2]++;
                        }

                        if ($startTime >= "12:00" & $startTime < "18:00") {
                            $table[$day][1]++;
                            $table[$day][2]++;
                        }

                        if ($startTime >= "18:00") {
                            $table[$day][2]++;
                        }
                    }

                    // 2. On complète la journée de fin .
                    // Condition: La date appartient au mois en cours de traitmeent.
                    $monthEnd = date_format($garde['dateOfEnd'], 'n');
                    $dayEnd = date_format($garde['dateOfEnd'], 'N');



                    if ($monthEnd == $n) {
                        $endTime = date_format($garde['dateOfEnd'], 'H:i');

                        if ($endTime <= "12:00") {
                            $table[$dayEnd][0]++;
                        } elseif ($endTime > "12:00" & $endTime <= "18:00") {
                            $table[$dayEnd][0]++;
                            $table[$dayEnd][1]++;
                        } elseif ($endTime >= "18:00") {
                            $table[$dayEnd][0]++;
                            $table[$dayEnd][1]++;
                            $table[$dayEnd][2]++;
                        }
                    }

                    // On complète les journées entre.
                    $startDate = $garde["dateOfStart"];
                    $endDate = $garde["dateOfEnd"];
                    $interval = \DateInterval::createFromDateString('1 day');
                    $period = new \DatePeriod($startDate, $interval, $endDate);

                    foreach ($period as $date) {

                        $month = date_format($date, 'n');
                        $day = date_format($date, 'N');

                        // z = Nombre de jours depuis le début de l'année .
                        $t =  date_format($date, 'z');
                        $from = date_format($startDate, 'z');
                        $to = date_format($endDate, 'z');


                        // Si le mois est égale à celui en cours :
                        if ($month == $n & $t > $from & $t < $to) {
                            $table[$day][0]++;
                            $table[$day][1]++;
                            $table[$day][2]++;
                        }
                    }
                    unset($period, $date);
                    //    
                }
            }


            $col = $ColByMonth[$n];
            $total = 0;
            foreach ($table as $currentDay) {

                foreach ($currentDay as $TimePeriod) {
                    if ($TimePeriod !== 0) {
                        $currentSheet->setCellValue($col . $row, $TimePeriod);
                        $total = $total + $TimePeriod;
                    }
                    $row++;
                }
            }


            $currentSheet->setCellValue($col . $row, $total);
        }
        unset($garde);
    }

    /*----------------------------------------------------- Evaluation candidat ---------------------------------------------------------*/

        $currentSheet = $spreadsheet->getSheet(6);


        $ROLE_LABELS = ["speaker" => "Orateur", "organiser" => "Organisateur"];

        $currentSheet->setCellValue("H4", ucfirst($user->getFirstname()) . " " . ucfirst($user->getLastname()))
            ->setCellValue("E5", "  " . ucfirst($currentYearOfFormation->getHospital()))
            ->setCellValue("E6", "  Dr " . ucfirst($currentYearOfFormation->getMaster()))
            ->setCellValue("O7", "  " . $currentYearOfFormation->getYearOfFormation());     

        
        //Tableau groupant et classant les 3 conditions tels ques décrite dans la variable $parts.
         
        $sections = [];
        $hospital = $currentYearOfFormation->getHospital();

        if (!empty($formations)) {

            $Conditions = array_filter($formations, function ($var) use ($hospital) {
                return ($var["location"] === $hospital & $var["role"] === "participant");
            });
            $sections["A l'hopital de formation"] = $Conditions;

            $Conditions = array_filter($formations, function ($var) use ($hospital) {
                return ($var["location"] !== $hospital & $var["role"] === "participant");
            });
            $sections["En dehors de l'hopital de formation"] = $Conditions;

            $Conditions = array_filter($formations, function ($var) {
                return ($var["role"] !== "participant");
            });
            $sections["En tant qu'orateur"] = $Conditions;

            $row = 88;

            foreach ($sections as $section) {

                foreach ($section as $formation) {

                    $startDate = $formation["dateOfStart"];
                    $startDate->setTime(0, 0);

                    $endDate = $formation["dateOfEnd"];
                    $endDate->setTime(12, 0);

                    $interval = \DateInterval::createFromDateString('1 day');
                    $period = new \DatePeriod($startDate, $interval, $endDate);

                    foreach ($period as $date) {

                        $currentSheet->setCellValue("A" . $row, date_format($date, 'd-m-Y'))
                            ->setCellValue("C" . $row,  "   " . $formation["location"])
                            ->setCellValue("I" . $row,  "   " . $formation["name"]);

                        if ($formation["role"] === "participant") {
                            $currentSheet->setCellValue("R" . $row,  "   " . $formation["description"]);
                        } else {
                            $currentSheet->setCellValue("R" . $row,  "   " . $ROLE_LABELS[$formation["role"]] . ":  " . $formation["description"]);
                        }

                        $currentSheet->insertNewRowBefore($row + 1, 1);
                        $row++;
                        $currentSheet->mergeCells("A" . $row . ":B" . $row)
                            ->mergeCells("C" . $row . ":H" . $row)
                            ->mergeCells("I" . $row . ":Q" . $row)
                            ->mergeCells("R" . $row . ":X" . $row);
                    }
                }
                $row = $row + 6;
                unset($formation);
            }
        }






    /*----------------------------------------------------- GENERATION DU FICHIER EXCEL ---------------------------------------------------------*/
        // Génère le fichier Excel en réponse
        $response = new StreamedResponse(function() use ($spreadsheet) {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
        });

        $dispositionHeader = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'export.xlsx'
        );

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $dispositionHeader);

        return $response;
    }
}
