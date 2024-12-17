<?php

namespace App\Controller;

use App\Entity\Surgeries;
use App\Entity\User;
use App\Repository\ConsultationsRepository;
use App\Repository\FormationsRepository;
use App\Repository\GardesRepository;
use App\Repository\NomenclatureRepository;
use App\Repository\SurgeonsRepository;
use App\Repository\SurgeriesRepository;
use App\Repository\UserRepository;
use App\Repository\YearsRepository;
use DateInterval;
use DatePeriod;
use DateTime;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\FILL;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;




class ExcelGeneratorController extends AbstractController
{
    private $security;
    private $year;
    private $surgeriesRepo;
    private $surgeonsRepo;
    private $userRepo;
    private $consultationRepo;
    private $nomenclature;
    private $formationRepository;
    private $gardes;


    public function __construct(Security $security, YearsRepository $year, SurgeriesRepository $surgeriesRepo, SurgeonsRepository $surgeonsRepo, UserRepository $userRepo, ConsultationsRepository $consultationRepo, NomenclatureRepository $nomenclature, FormationsRepository  $formationRepository, GardesRepository $gardes)
    {
        $this->security = $security;
        $this->year = $year;
        $this->surgeriesRepo = $surgeriesRepo;
        $this->surgeonsRepo = $surgeonsRepo;
        $this->userRepo = $userRepo;
        $this->consultationRepo = $consultationRepo;
        $this->nomenclature = $nomenclature;
        $this->formationRepository =  $formationRepository;
        $this->gardes = $gardes;
    }



    /**
     * @Route("/api/excel/{year}", name="excelOldVersion", methods={ "GET" })
     * @param int $year Définie l'année d'interet pour l'extraction des données.
     */
    public function ExcelGenerator($year)
    {


        $user = $this->security->getUser();

        if (!$user) {
            dd("Pas d'utilisateur retrouvé");
        }

        $request = $this->userRepo->getUserInfo($user);

        /**
         * @var int Id de l'utilsateur
         */
        $idUser = $request['id'];

        /**
         * @var string Prénom de l'utilisateur
         */
        $firstName = $request['firstname'];

        /**
         * @var string Nom de l'utilisateur
         */
        $lastName = $request['lastname'];

        /**
         * @var string Email de l'utilisateur
         */
        $email = $request['email'];


        /**
         * @var obj Année en cour de traitement
         */
        $testo = $this->year->find($year);


        /**
         * @var array Liste des chirurgies liée à l'année
         */
        $surgeries = $this->surgeriesRepo->getYears($testo);

        /**
         * @var array Liste récapitulative compté de l'année
         */
        $surgeriesSummary = array();

        $request = $this->surgeriesRepo->summary($testo);

        foreach ($request as $sur) {
            if (!array_key_exists($sur['name'], $surgeriesSummary)) {
                $surgeriesSummary[$sur['name']] = array("code" => "", "name" => $sur['name'], "firsthand" => 0, "secondhand" => 0);
            }
        }

        /**
         * @var array Liste des chirurgiens lié à l'année.
         */
        $surgeons = $this->surgeonsRepo->findSurgeons($testo);


        /**
         * @var array Nombre de première main pour chaque chirurgien.
         */
        $first = array();

        /**
         * @var array Nombre de deuxième main pour chaque chirurgien.
         */
        $second = array();

        /**
         * @var array Lebel des chirurgiens.
         */
        $LABELS = [];

        /**
         * @var array Ensemble des évènements de formations liés à une année spécifique.
         */
        $formations = $this->formationRepository->getFormations($testo);

        /**
         * @var array Informations concernant l'année
         */
        $yearInfo = $this->year->getInfo($testo);

        /**
         * @var string Nom de l'hopital de formation correspondant à l'année
         */
        $hospital = $yearInfo['hospital'];

        /**
         * @var string Year Of Formation : Renvoie l'année de formation 
         */
        $yof = $yearInfo['yearOfFormation'];

        /**
         * @var DateTime Date Of Start : Date de début de stage.
         */
        $dof = $yearInfo['dateOfStart'];

        /**
         * @var string Nom du mettre de stage correspondant à l'année.
         */
        $master = $yearInfo['master'];

        /**
         * @var array Liste des gardes liée à l'année.
         */
        $gardes = $this->gardes->getGardes($testo);

        /**
         * @var int Nombre de première main en solo
         */
        $firstHandSoloSum = 0;
        /**
         * @var int Nombre d'intervention en première main aidée
         */
        $firstHandHelpSum = 0;

        /**
         * @var int Nombre de deuxième main
         */
        $secondHandSum = 0;


        $outline = [
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK,
                    'color' => ['argb' => '16365C'],
                ],
            ],
        ];

        // Référencement des chirurgiens :

        $alphabet = "A";
        foreach ($surgeons as $id) {
            $LABELS[$id["id"]] = $alphabet;
            $alphabet++;
            $first[$id["id"]] = 0;
            $second[$id["id"]] = 0;
        }


        // Creation de la table : 

        $spreadsheet = new Spreadsheet();

        /*----------------------------------------------------- PAGE DE GARDE ---------------------------------------------------------------------*/
        $reader = IOFactory::createReader('Xlsx');

        $spreadsheet = $reader->load('ExcelTemplateOldVersion.xlsx');

        $sheet1 = $spreadsheet->getSheet(0);

        $sheet1->setCellValue("B24", (ucfirst($yof) . "e" . " année"));
        $sheet1->setCellValue("F26", ucfirst($lastName));
        $sheet1->setCellValue("F27", ucfirst($firstName));
        $sheet1->setCellValue("F28", $email);

        /*----------------------------------------------------- CARNET DE STAGE ---------------------------------------------------------------------*/

        $acceuil = $spreadsheet->getSheet(1);

        // Encodage des identifiant :

        $acceuil->setCellValue("H14", "    Dr " . ucfirst($lastName) . " " . ucfirst($firstName))
            ->setCellValue("H19", "    " . $hospital)
            ->setCellValue("H22", "    Dr " . $master)
            ->setCellValue("H24", "    " . date_format($dof, 'd-m-Y'))
            ->setCellValue("H26", "    " . $yof)
            ->setCellValue("H30", "    " . date("d-m-Y"));

        // Encodage des superviseurs :

        if (!empty($surgeons)) {
            $row = 39;
            $step = 1;
            foreach ($surgeons as $surgeon) {

                if($surgeon["boss"]){
                    $acceuil->setCellValue('E' . $row, "  id:     " . $LABELS[$surgeon['id']])
                            ->setCellValue('F' . $row, $surgeon['firstName'])
                            ->setCellValue('G' . $row, $surgeon['lastName']);
                    $step++;
                    $row++;
                }elseif(!$surgeon["boss"] & ($step==1) & (count($surgeons) == 1)){
                    $row= 40;
                    //$acceuil->insertNewRowBefore($row + 1, 1);
                    $acceuil->setCellValue('E' . $row, "  id:     " . $LABELS[$surgeon['id']])
                            ->setCellValue('F' . $row, $surgeon['firstName'])
                            ->setCellValue('G' . $row, $surgeon['lastName']);
                    $step++;
                    $row++;
                }elseif(!$surgeon["boss"] & ($step==1) & (count($surgeons) > 1)){
                    $row= 40;
                    $acceuil->insertNewRowBefore($row + 1, 1);
                    $acceuil->setCellValue('E' . $row, "  id:     " . $LABELS[$surgeon['id']])
                            ->setCellValue('F' . $row, $surgeon['firstName'])
                            ->setCellValue('G' . $row, $surgeon['lastName']);
                    $step++;
                    $row++;
                }
                elseif($step >= 2){
                    $acceuil->insertNewRowBefore($row + 1, 1)
                            ->setCellValue('E' . $row, "  id:     " . $LABELS[$surgeon['id']])
                            ->setCellValue('F' . $row, $surgeon['firstName'])
                            ->setCellValue('G' . $row, $surgeon['lastName']);
                    $row++; 
                }
            }
        } else {
            $acceuil->setCellValue('E39', "ATTENTION")
                ->setCellValue('F39', "Pas de chirurgien")
                ->setCellValue('G39', "renseigné !");
        }

        if(count($surgeons) == 1 & (!$surgeons[0]["boss"])){
          
        }else{
            $acceuil->removeRow($row, 1);
        };
        

        // Encodage des interventions :

        if(count($surgeons) > 1 & (!$surgeons[0]["boss"])){
            $row = $row + 7;
        }elseif(count($surgeons) == 1 & (!$surgeons[0]["boss"])){
            $row = $row + 7;
        }else{
            $row = $row + 7;
        };

        

        foreach ($surgeries as $surgery) {

            // Composition du récapitulatif des intervention ($surgeriesSummary)
            if (array_key_exists($surgery['name'], $surgeriesSummary)) {

                $surgeriesSummary[$surgery['name']]['code'] = $surgery['code'];

                if ($surgery['position'] == 1 || $surgery['position'] == 3) {
                    $surgeriesSummary[$surgery['name']]['firsthand']++;
                }

                if ($surgery['position'] == 2) {
                    $surgeriesSummary[$surgery['name']]['secondhand']++;
                }
            }

            $acceuil->insertNewRowBefore($row + 1, 1)
                ->mergeCells("C" . $row . ":H" . $row);

            $acceuil->setCellValue('A' . $row, "  " . date_format($surgery['date'], 'd-m-Y'))
                ->setCellValue('B' . $row, "  " . $surgery['code'])
                ->setCellValue('C' . $row, "  " . $surgery['name']);

            if ($surgery['position'] == 1) {

                $acceuil->setCellValue('L' . $row, "1");
                $firstHandSoloSum++;
            }

            if ($surgery['position'] == 2) {

                $acceuil->setCellValue('I' . $row, "1")
                    ->setCellValue('K' . $row, $LABELS[$surgery['firstHand']]);
                $secondHandSum++;

                $first[$surgery['firstHand']]++;
            }

            if ($surgery['position'] == 3) {

                $acceuil->setCellValue('M' . $row, "1")
                    ->setCellValue('N' . $row, $LABELS[$surgery['secondHand']]);
                $firstHandHelpSum++;
                $second[$surgery['secondHand']]++;
            }

            $row++;
        }
        unset($surgery);

        $acceuil->removeRow($row, 1);

        //  Total en fin de tableau
        $row++;
        $acceuil->setCellValue('L' . $row, $firstHandSoloSum)
            ->setCellValue('J' . $row, '0')
            ->setCellValue('I' . $row, $secondHandSum)
            ->setCellValue('M' . $row, $firstHandHelpSum);


        // Décompte final
        $row = $row + 10;

        $acceuil->setCellValue('G' . $row, "Dr   " . ucfirst($firstName))
            ->setCellValue('I' . $row, ucfirst($lastName));

        $startRow = $row + 2;
        $count = 0;

        if (!empty($surgeons)) {
            
            foreach ($surgeons as $test) {
                $count++;

                if($test["boss"] & ($count==1)){
                    $row++;
                }elseif(!$test["boss"] & ($count==1)){
                    $row= $row + 2;
                    $acceuil->insertNewRowBefore($row + 1, 1);
                }
                elseif($count >= 2){
                    $acceuil->insertNewRowBefore($row + 1, 1);
                }

                $acceuil->setCellValue('F' . $row, "  id:     " . $LABELS[$test['id']]);
                $acceuil->setCellValue('G' . $row, "   Dr   " . $test['firstName'])
                    ->mergeCells("G" . $row . ":H" . $row);
                $acceuil->setCellValue('I' . $row, $test['lastName'])
                    ->mergeCells("I" . $row . ":J" . $row);


                // Calcul des rapports :

                $surgeonId = $test['id'];

                $acceuil->setCellValue('K' . $row, $first[$surgeonId]);
                if (($second[$surgeonId] + $first[$surgeonId]) !== 0) {
                    $acceuil->setCellValue('L' . $row, round(($first[$surgeonId] / ($second[$surgeonId] + $first[$surgeonId]) * 100), 1) . " %");
                } else {
                    $acceuil->setCellValue('L' . $row, "0 %");
                }
                $acceuil->setCellValue('M' . $row, $second[$surgeonId]);

                if (($second[$surgeonId] + $first[$surgeonId]) !== 0) {
                    $acceuil->setCellValue('N' . $row, round(($second[$surgeonId] / ($second[$surgeonId] + $first[$surgeonId]) * 100), 1) . " %");
                } else {
                    $acceuil->setCellValue('N' . $row, "0 %");
                }

                // Pour éviter le bug. Si un seul superviseur enregistrer et qu'il s'agit du maître de stage.
                if((count($surgeons) === 1) & $test["boss"]){
                    $endRow = $row + 2;
                }else{
                    $endRow = $row++;
                }

                unset($surgeonId);
            }

            $acceuil->mergeCells("C" . $startRow . ":E" . $endRow);
            if($count > 1){
               $acceuil->removeRow($endRow + 1, 1); 
            }elseif(($count = 1) & (!$surgeons[0]["boss"])){
                $acceuil->removeRow($endRow + 1, 1);
            }
            
        }



        /*----------------------------------------------------- LISTE RECAPITULATIVE ---------------------------------------------------------------------*/
        $sheet2 = $spreadsheet->getSheet(2);

        $cells = [1 => "E", 2 => "F", 3 => "G", 4 => "H", 5 => "I", 6 => "J", 7 => "K"];


        $sheet2->setCellValue("G3", "  Dr " . ucfirst($firstName) . " " . ucfirst($lastName))
            ->setCellValue("E4", "  " . ucfirst($hospital))
            ->setCellValue("E5", "  Dr " . ucfirst($master))
            ->getStyle($cells[$yof] . 6)->applyFromArray($outline);

        if (!empty($surgeriesSummary)) {

            $row = 16;

            $colFirst = ["", "E", "F", "G", "H", "I", "J", "K"];
            $colSecond = ["", "M", "N", "O", "P", "Q", "R", "S"];

            foreach ($surgeriesSummary as $sur) {

                $sheet2->insertNewRowBefore($row + 1, 1)
                    ->mergeCells("B" . $row . ":D" . ($row))
                    ->getStyle("B" . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

                $sheet2->setCellValue('A' . $row, "   " . $sur['code'])
                    ->setCellValue('B' . $row, "   " . $sur['name'])
                    ->setCellValue($colFirst[$yof] . $row, $sur['secondhand'])
                    ->setCellValue($colSecond[$yof] . $row, $sur['firsthand'])
                    ->setCellValue('L' . $row, $sur['secondhand'])
                    ->setCellValue('T' . $row, $sur['firsthand'])
                    ->setCellValue('U' . $row, ($sur['firsthand'] + $sur['secondhand']))
                    ;
                    
                if($sur['firsthand'] !== 0){
                    $percentage = ($sur['firsthand']/($sur['firsthand'] + $sur['secondhand'])*100);
                    $sheet2->setCellValue('V' . $row, round($percentage)."%");
                }else{
                    $sheet2->setCellValue('V' . $row, "0%");
                }
                
                $row++;
            }

            $sheet2->removeRow($row, 1);
        }

        /*----------------------------------------------------- CONSULTATIONS ----------------------------------------------------------------------*/

        $sheet3 = $spreadsheet->getSheet(3);

        // Complétion de l'entête :

        $sheet3->setCellValue("C3", "  Dr " . ucfirst($firstName) . " " . ucfirst($lastName))
            ->setCellValue("C4", "  " . ucfirst($hospital))
            ->setCellValue("C5", "  Dr " . ucfirst($master))
            ->setCellValue("C6", "  " . $yof);

        $rawByMonth = [1 => 18, 2 => 20, 3 => 22, 4 => 24, 5 => 26, 6 => 28, 7 => 30, 8 => 32, 9 => 34, 10 => 12, 11 => 14, 12 => 16];
        $ColByMonth = [1 => 'B', 2 => 'B', 3 => 'B', 4 => 'B', 5 => 'B', 6 => 'B', 7 => 'B', 8 => 'B', 9 => 'B', 10 => 'B', 11 => 'B', 12 => 'B'];


        // 1. Chercher toutes les consultation trier par mois.

        $query = $this->consultationRepo->getConsultations($year);

        // On recherche chaque date unique.
        $dates = $this->consultationRepo->getUniqDate($year);

        // On travail sur chaque date unique.

        if (!empty($query)) {

            foreach ($dates as $date) {

                // Décompte du nombre d'intevention ce jour la.
                $nb = array();

                // Somme des consultation par mois

                $SumByMonth = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0];

                foreach ($query as $consultation) {

                    $date1 = date_format($consultation['date'], 'd-m-Y');
                    $date2 = date_format($date['date'], 'd-m-Y');

                    if ($date1 == $date2) {
                        $nb[] = $consultation['number'];
                    }

                    // Ajoute pour chaque mois le nombre de consultation.

                    $SumByMonth[date_format($consultation['date'], 'n')] = $SumByMonth[date_format($consultation['date'], 'n')] + $consultation['number'];
                }

                // Somme des consultation par date défini.
                $total = array_sum($nb);



                $currentContentRow = $rawByMonth[date_format($date['date'], 'n')];
                $currentContentCol = $ColByMonth[date_format($date['date'], 'n')];


                $rowfordate = $currentContentRow - 1;
                $sheet3->setCellValue($currentContentCol . $rowfordate, date_format($date['date'], 'd-m-y'))
                    ->setCellValue($currentContentCol . $currentContentRow, $total);

                // On décalle la colonne du mois d'une unité.
                if ($currentContentCol < "K" || $currentContentCol < max($ColByMonth)) {
                    $currentContentCol++;
                } else {
                    $currentContentCol++;
                    $sheet3->insertNewColumnBefore($currentContentCol, 1)
                        ->setCellValue($currentContentCol . "10", "DATE");
                }

                $ColByMonth[date_format($date['date'], 'n')] = $currentContentCol;
            }



            // Calcul des totaux par discipline :

            $specialies = ["ortho", "traumato", "dig", "uro", "vasc", "plast"];
            $SPE_LABELS = ["ortho" => "Orthopédie", "traumato" => "Traumatologie", "dig" => "Digestive", "uro" => "Urologie", "vasc" => "Vasculaire", "plast" => "Plastique"];

            $table = array();

            foreach ($specialies as $speciality) {

                $provisoirs = array_filter($query, function ($var) use ($speciality) {
                    return ($var['speciality'] == $speciality);
                });

                $total = 0;
                foreach($provisoirs as $provisoir){
                    $total = $total + $provisoir['number'];
                }
            
            
                $table[$speciality] = $total;
            }
            
            // Détermination des colonnes pour le récapitulatif.

            /**
             * @var string Dernière colonne du tableau des dates.
             */
            $endCol = max($ColByMonth);

            if ($endCol >= "K") {
                $sheet3->removeColumn($endCol, 3);

                // Colonne de l'intitulé de la discipline.
                for ($i = 1; $i <= 2; $i++) {
                    $endCol++;
                }

                // Colonne du nombre de consultation.
                $totalCol = $endCol;
                for ($i = 1; $i <= 2; $i++) {
                    $totalCol++;
                }
            } else {
                $sheet3->removeColumn("K", 3);
                $endCol = "M";
                $totalCol = "O";
            }

            $row = 11;


            foreach ($table as $t => $n) {
                if ($n !== 0) {
                    $sheet3->setCellValue($endCol . $row, "  " . $SPE_LABELS[$t])
                        ->setCellValue($totalCol . $row, "  " . $n);
                    $row++;
                }
            }
        }


        /*----------------------------------------------------- GARDES ---------------------------------------------------------------------*/

        $sheet4 = $spreadsheet->getSheet(4);

        // Complétion de l'entête :

        $sheet4->setCellValue("C3", "  Dr " . ucfirst($firstName) . " " . ucfirst($lastName))
            ->setCellValue("C4", "  " . ucfirst($hospital))
            ->setCellValue("C5", "  Dr " . ucfirst($master))
            ->setCellValue("C6", "  " . $yof);

        $rows = ["1" => "17", "2" => "19", "3" => "21", "4" => "23", "5" => "25", "6" => "27", "7" => "29", "8" => "31", "9" => "33", "10" => "11", "11" => "13", "12" => "15"];
        $ColByMonth = [1 => 'B', 2 => 'B', 3 => 'B', 4 => 'B', 5 => 'B', 6 => 'B', 7 => 'B', 8 => 'B', 9 => 'B', 10 => 'B', 11 => 'B', 12 => 'B'];

        /**
         * @var array Nombre de patient vu pednant la garde
         */
        $SumByMonth = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0];


        for ($month = 1; $month <= 12; $month++) {

            $column = $ColByMonth[$month];

            // Complétion :

            foreach ($gardes as $garde) {

                if (date_format($garde['dateOfStart'], 'n') == $month) {

                    $sheet4->setCellValue($column . $rows[$month], date_format($garde['dateOfStart'], 'd-m-y'))
                        ->setCellValue($column . ($rows[$month] + 1), $garde['number']);

                    $SumByMonth[$month] = $SumByMonth[$month] + $garde['number'];

                    // On décalle la colonne du mois d'une unité.

                    if ($column < "K" || $column < max($ColByMonth)) {
                        $column++;
                    } else {
                        $column++;
                        $sheet4->insertNewColumnBefore($column, 1)
                            ->setCellValue($column . "10", "DATE");
                    }

                    $ColByMonth[$month] = $column;
                }
            }
        }

        $endCol = max($ColByMonth);

        if ($endCol > "K") {
            $sheet4->removeColumn($endCol, 3);

            // Colonne de l'intitulé de la discipline.
            for ($i = 1; $i <= 2; $i++) {
                $endCol++;
            }

            // Colonne du nombre de consultation.
            $totalCol = $endCol;
            for ($i = 1; $i <= 2; $i++) {
                $totalCol++;
            }
        } else {
            $sheet4->removeColumn("K", 3);
            $endCol = "M";
            $totalCol = "O";
        }


        $sheet4->setCellValue($totalCol . "11", count($gardes));



        /*----------------------------------------------------- Rapport d'activité ---------------------------------------------------------*/

        $sheet5 = $spreadsheet->getSheet(5);

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

        $consultations = $query;

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
                            $sheet5->setCellValue($ColByMonth[$month] . $row, $number);
                            $total =  $total + $number;
                        }
                        $row++;
                    }
                }
                $sheet5->setCellValue($ColByMonth[$month] . $row, $total);
                unset($currentDay, $number);
            }
        }

        // Intervention :

        if (!empty($surgeries)) {
            /**
             * @var array Tableau des interventions ou l'utlisateur est en première main 
             */
            $firstHands = array_filter($surgeries, function ($var) {
                return ($var['position'] == 1 or $var['position'] == 3);
            });

            /**
             * @var array Tableau des interventions ou l'utlisateur est en deuxième main 
             */
            $secondHands = array_filter($surgeries, function ($var) {
                return ($var['position'] == 2);
            });


            // On trie les interventions par mois :

            //Première main : 

            $ColByMonth = ["1" => "AR", "2" => "AZ", "3" => "I", "4" => "Q", "5" => "Y", "6" => "AJ", "7" => "AR", "8" => "AZ", "9" => "I", "10" => "Q", "11" => "Y", "12" => "AJ"];

            for ($month = 1; $month <= 12; $month++) {

                $row = getRow($month);
                $days = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];

                // Comptage par jours : 
                foreach ($firstHands as $request) {

                    if (date_format($request['date'], 'n') == $month) {
                        $days[date_format($request['date'], 'N')]++;
                    }
                }
                unset($request);

                // On complète le tableau :

                $total = 0;
                foreach ($days as $number) {

                    if ($number !== 0) {
                        $sheet5->setCellValue($ColByMonth[$month] . $row, $number);
                        $total = $total + $number;
                    }

                    $row = $row + 3;
                }
                $sheet5->setCellValue($ColByMonth[$month] . $row, $total);
                unset($number);
            }
            unset($month);

            //Deuxième main : 

            $ColByMonth = ["1" => "AQ", "2" => "AY", "3" => "H", "4" => "P", "5" => "X", "6" => "AI", "7" => "AQ", "8" => "AY", "9" => "H", "10" => "P", "11" => "X", "12" => "AI"];

            for ($month = 1; $month <= 12; $month++) {

                $row = getRow($month);
                $days = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];

                // Comptage par jours : 

                foreach ($secondHands as $request) {

                    if (date_format($request['date'], 'n') == $month) {

                        $days[date_format($request['date'], 'N')]++;
                    }
                }
                unset($request);

                // On complète le tableau :
                $total = 0;
                foreach ($days as $number) {

                    if ($number !== 0) {
                        $sheet5->setCellValue($ColByMonth[$month] . $row, $number);
                        $total = $total + $number;
                    }

                    $row = $row + 3;
                }
                $sheet5->setCellValue($ColByMonth[$month] . $row, $total);
                unset($number);
            }
        }

        // Formations :

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
                                $sheet5->setCellValue($col . $row, $e);
                                $total = $total + $e;
                            }
                            $row++;
                        }
                    }


                    $sheet5->setCellValue($col . $row, $total);

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
                            $sheet5->setCellValue($col . $row, $TimePeriod);
                            $total = $total + $TimePeriod;
                        }
                        $row++;
                    }
                }


                $sheet5->setCellValue($col . $row, $total);
            }
            unset($garde);
        }

        /*----------------------------------------------------- Evaluation candidat ---------------------------------------------------------*/

        $sheet6 = $spreadsheet->getSheet(6);


        $ROLE_LABELS = ["speaker" => "Orateur", "organiser" => "Organisateur"];

        $sheet6->setCellValue("H4", ucfirst($firstName) . " " . ucfirst($lastName))
            ->setCellValue("E5", "  " . ucfirst($hospital))
            ->setCellValue("E6", "  Dr " . ucfirst($master))
            ->setCellValue("O7", "  " . $yof);

        /**
         * @var array Tableau groupant et classant les 3 conditions tels ques décrite dans la variable $parts.
         */
        $sections = [];

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

                        $sheet6->setCellValue("A" . $row, date_format($date, 'd-m-Y'))
                            ->setCellValue("C" . $row,  "   " . $formation["location"])
                            ->setCellValue("I" . $row,  "   " . $formation["name"]);

                        if ($formation["role"] === "participant") {
                            $sheet6->setCellValue("R" . $row,  "   " . $formation["description"]);
                        } else {
                            $sheet6->setCellValue("R" . $row,  "   " . $ROLE_LABELS[$formation["role"]] . ":  " . $formation["description"]);
                        }

                        $sheet6->insertNewRowBefore($row + 1, 1);
                        $row++;
                        $sheet6->mergeCells("A" . $row . ":B" . $row)
                            ->mergeCells("C" . $row . ":H" . $row)
                            ->mergeCells("I" . $row . ":Q" . $row)
                            ->mergeCells("R" . $row . ":X" . $row);
                    }
                }
                $row = $row + 6;
                unset($formation);
            }
        }

        /*----------------------------------------------------- FIN ------------------------------------------------------------------------*/

        header("Content-Type:   application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=abc.xls");
        header('Access-Control-Allow-Origin: *');
        //header('Content-Disposition: attachment;filename="test.xlsx"');
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit();
    }
}