<?php

namespace App\Export\CarnetDeStage;

use App\Export\ExportProgress;
use App\Export\ExportStep;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Clock\ClockInterface;

/**
 * Remplit le gabarit du carnet de stage (public/ExcelTemplate.xlsx).
 *
 * Transcription fidèle de l'ancien moteur (ExcelNewVersion, copie figée dans
 * tests/Excel/Legacy) : mêmes cellules, mêmes valeurs, mêmes styles, même ordre d'opérations.
 * Seule différence : les lignes d'une section sont insérées en UN appel à insertNewRowBefore()
 * au lieu d'un appel par ligne. Chaque insertion réécrit toute la feuille et réanalyse toutes
 * ses formules (1 042 dans la liste récapitulative) : l'ancien moteur avait un coût quadratique
 * (plus de 2 minutes pour ~1 350 interventions). Insérer N lignes d'un coup donne exactement le
 * même résultat (chaque ligne insérée reprend le style de la ligne modèle, non encore modifiée),
 * ce que vérifient les tests d'équivalence (tests/Excel/ExcelEquivalenceTest.php).
 *
 * Les bizarreries de l'ancien moteur sont conservées à l'identique (ex. : seules les interventions
 * générales sans sous-type figurent dans la liste récapitulative).
 */
final class CarnetDeStageSpreadsheetBuilder
{
    /** Rapport de progression : au plus une notification toutes les N lignes traitées. */
    private const PROGRESS_EVERY = 25;

    private const ANATOMIC_REGIONS = [
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

    private const SPECIALITIES = [
        ['value' => 'dig', 'title' => 'Chirurgie digestive'],
        ['value' => 'general', 'title' => 'Chirurgie générale'],
        ['value' => 'uro', 'title' => 'Chirurgie urologique'],
        ['value' => 'vasc', 'title' => 'Chirurgie vasculaire'],
        ['value' => 'thor', 'title' => 'Chirurgie thoracique'],
        ['value' => 'plastic', 'title' => 'Chirurgie plastique'],
        ['value' => 'neuro', 'title' => 'Neurochirurgie'],
        ['value' => 'transp', 'title' => 'Transplantation'],
    ];

    /** @var array<int, int> style d'origine => style centré horizontalement (index du classeur en cours) */
    private array $centeredXf = [];

    public function __construct(private ClockInterface $clock)
    {
    }

    public function build(Spreadsheet $spreadsheet, CarnetDeStageData $data, ExportProgress $progress): void
    {
        $this->centeredXf = [];
        $this->coverPage($spreadsheet->getSheet(0), $data);
        $currentYearSurgeries = $this->logbook($spreadsheet->getSheet(1), $data, $progress);
        $this->summary($spreadsheet->getSheet(2), $data, $progress);

        $progress->step(ExportStep::Sheets);
        $this->consultations($spreadsheet->getSheet(3), $data);
        $this->gardes($spreadsheet->getSheet(4), $data);
        $this->activityReport($spreadsheet->getSheet(5), $data, $currentYearSurgeries);
        $this->candidateEvaluation($spreadsheet->getSheet(6), $data);
    }

    /*----------------------------------------------------- PAGE DE GARDE ---------------------------------------------------------------------*/

    private function coverPage(Worksheet $sheet, CarnetDeStageData $data): void
    {
        $user = $data->user;
        $sheet->setCellValue('B24', ucfirst((string) $data->currentYear->getYearOfFormation()) . 'e année');
        $sheet->setCellValue('F26', ucfirst((string) $user->getLastname()));
        $sheet->setCellValue('F27', ucfirst((string) $user->getFirstname()));
        $sheet->setCellValue('F28', $user->getEmail());
    }

    /*----------------------------------------------------- CARNET DE STAGE ---------------------------------------------------------------------*/

    /**
     * @return list<array<string, mixed>> interventions de l'année, triées par date
     */
    private function logbook(Worksheet $sheet, CarnetDeStageData $data, ExportProgress $progress): array
    {
        $user = $data->user;
        $year = $data->currentYear;

        $sheet->setCellValue('H14', '    Dr ' . ucfirst((string) $user->getLastname()) . ' ' . ucfirst((string) $user->getFirstname()))
            ->setCellValue('H19', '    ' . $year->getHospital())
            ->setCellValue('H22', '    Dr ' . $year->getMaster())
            ->setCellValue('H24', '    ' . date_format($year->getDateOfStart(), 'd-m-Y'))
            ->setCellValue('H26', '    ' . $year->getYearOfFormation())
            ->setCellValue('H30', '    ' . $this->clock->now()->format('d-m-Y'));

        // Superviseurs : d'abord le maître de stage, puis par nom puis prénom.
        $supervisors = [];
        foreach ($data->surgeons as $surgeon) {
            $supervisors[] = [
                'id' => $surgeon['id'],
                'firstname' => $surgeon['firstname'],
                'lastname' => $surgeon['lastname'],
                'isManager' => $surgeon['isManager'],
                'isFirsthand' => 0,
                'isSecondhand' => 0,
            ];
        }
        usort($supervisors, function ($a, $b) {
            if ($a['isManager'] == $b['isManager']) {
                if ($a['lastname'] == $b['lastname']) {
                    return strcmp($a['firstname'], $b['firstname']);
                }

                return strcmp($a['lastname'], $b['lastname']);
            }

            return $b['isManager'] - $a['isManager'];
        });

        $labels = range('A', 'Z');
        foreach ($supervisors as $index => &$supervisor) {
            $supervisor['label'] = $labels[$index] ?? ($index + 1);
        }
        unset($supervisor);

        // Liste des superviseurs (quelques lignes : insertions unitaires, comme l'ancien moteur).
        if (!empty($supervisors)) {
            $row = 39;
            $step = 1;
            $hasManager = false;
            foreach ($supervisors as $surgeon) {
                if ($surgeon['isManager']) {
                    $hasManager = true;
                    break;
                }
            }

            foreach ($supervisors as $labelIndex => $surgeon) {
                $label = $labels[$labelIndex] ?? $labelIndex + 1;

                if ($surgeon['isManager']) {
                    $sheet->setCellValue('E' . $row, '  id:     ' . $label)
                        ->setCellValue('F' . $row, $surgeon['firstname'])
                        ->setCellValue('G' . $row, $surgeon['lastname']);
                    $step++;
                    $row++;
                } elseif ($step == 1 && count($supervisors) == 1) {
                    $row = 40;
                    $sheet->setCellValue('E' . $row, '  id:     ' . $label)
                        ->setCellValue('F' . $row, $surgeon['firstname'])
                        ->setCellValue('G' . $row, $surgeon['lastname']);
                    $step++;
                    $row++;
                } elseif ($step == 1 && count($supervisors) > 1) {
                    $row = $hasManager ? 40 : 41;
                    $sheet->insertNewRowBefore($row + 1, 1);
                    $sheet->setCellValue('E' . $row, '  id:     ' . $label)
                        ->setCellValue('F' . $row, $surgeon['firstname'])
                        ->setCellValue('G' . $row, $surgeon['lastname']);
                    $step++;
                    $row++;
                } elseif ($step >= 2) {
                    $sheet->insertNewRowBefore($row + 1, 1)
                        ->setCellValue('E' . $row, '  id:     ' . $label)
                        ->setCellValue('F' . $row, $surgeon['firstname'])
                        ->setCellValue('G' . $row, $surgeon['lastname']);
                    $row++;
                }
            }
        } else {
            $row = 40;
            $sheet->setCellValue('E39', 'ATTENTION')
                ->setCellValue('F39', 'Pas de chirurgien')
                ->setCellValue('G39', 'renseigné !');
        }

        $sheet->removeRow($row, 1);

        // Tableau des interventions de l'année, par date (tri stable : ordre de chargement à date égale).
        $currentRow = $row + 7;
        $currentYearSurgeries = $data->currentYearSurgeries;
        usort($currentYearSurgeries, fn ($a, $b) => $a['date'] <=> $b['date']);

        $supervisorLabels = [];
        foreach ($supervisors as $supervisor) {
            $supervisorLabels[$supervisor['id']] = $supervisor['label'];
        }
        $supervisorIndex = [];
        foreach ($supervisors as $index => $supervisor) {
            // Premier superviseur de même id (comparaison souple, comme l'ancien « == » dans une boucle avec break).
            $supervisorIndex[(string) $supervisor['id']] ??= $index;
        }

        $total = count($currentYearSurgeries);
        $progress->step(ExportStep::Surgeries, $total);
        if ($total > 0) {
            // Une insertion de N lignes au lieu de N insertions d'une ligne (résultat identique).
            $sheet->insertNewRowBefore($currentRow + 1, $total);
        }

        foreach ($currentYearSurgeries as $done => $surgery) {
            $sheet->mergeCells('C' . $currentRow . ':H' . $currentRow);
            $sheet->setCellValue('A' . $currentRow, '  ' . $surgery['date']->format('d-m-Y'))
                ->setCellValue('B' . $currentRow, '  ' . $surgery['code'])
                ->setCellValue('C' . $currentRow, '  ' . $surgery['name']);

            switch ($surgery['position']) {
                case 1:
                    $sheet->setCellValue('L' . $currentRow, '1');
                    $this->centerHorizontally($sheet, 'L' . $currentRow);
                    break;

                case 2:
                    $firstHandId = $surgery['firstHand'];
                    $firstHandLabel = isset($supervisorLabels[$firstHandId]) ? $supervisorLabels[$firstHandId] : '';

                    $sheet->setCellValue('I' . $currentRow, '1')
                        ->setCellValue('K' . $currentRow, $firstHandLabel);
                    $this->centerHorizontally($sheet, 'I' . $currentRow);
                    $this->centerHorizontally($sheet, 'K' . $currentRow);

                    $index = $this->findSupervisor($supervisors, $supervisorIndex, $firstHandId);
                    if ($index !== null) {
                        $supervisors[$index]['isFirsthand']++;
                    }
                    break;

                case 3:
                    $secondHandId = $surgery['secondHand'];
                    $secondHandLabel = isset($supervisorLabels[$secondHandId]) ? $supervisorLabels[$secondHandId] : '';

                    $sheet->setCellValue('M' . $currentRow, '1')
                        ->setCellValue('N' . $currentRow, $secondHandLabel);
                    $this->centerHorizontally($sheet, 'M' . $currentRow);
                    $this->centerHorizontally($sheet, 'N' . $currentRow);

                    $index = $this->findSupervisor($supervisors, $supervisorIndex, $secondHandId);
                    if ($index !== null) {
                        $supervisors[$index]['isSecondhand']++;
                    }
                    break;
            }

            $currentRow++;
            if (($done + 1) % self::PROGRESS_EVERY === 0) {
                $progress->advance($done + 1);
            }
        }
        if ($total % self::PROGRESS_EVERY !== 0) {
            $progress->advance($total);
        }

        $sheet->removeRow($currentRow, 1);

        // Décompte final par superviseur.
        $currentRow += 11;
        $startRow = $currentRow + 2;

        $sheet->setCellValue('G' . $currentRow, 'Dr   ' . ucfirst((string) $user->getFirstname()))
            ->setCellValue('I' . $currentRow, ucfirst((string) $user->getLastname()));

        $hasManager = false;
        foreach ($supervisors as $supervisor) {
            if ($supervisor['isManager']) {
                $hasManager = true;
                $currentRow++;
                $this->fillSupervisorRow($sheet, $currentRow, $supervisor);
                break;
            }
        }
        if (!$hasManager) {
            $currentRow++;
        }
        foreach ($supervisors as $supervisor) {
            if (!$supervisor['isManager']) {
                $currentRow++;
                $this->fillSupervisorRow($sheet, $currentRow, $supervisor);
            }
        }

        $sheet->mergeCells('C' . $startRow . ':E' . $currentRow);
        $sheet->setCellValue('C' . $startRow, ' Collaborateurs');
        $sheet->getStyle('C' . $startRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->removeRow($currentRow + 1, 1);
        $sheet->removeRow($currentRow + 1, 1);

        return $currentYearSurgeries;
    }

    /**
     * Équivaut à getStyle($coordinate)->getAlignment()->setHorizontal(CENTER), en évitant de
     * rechercher à chaque appel le style résultant parmi tous les styles du classeur (empreinte
     * MD5 de chacun : 2 700 recherches pour ~1 350 interventions). Pour un même style d'origine,
     * PhpSpreadsheet aboutit toujours au même style (le premier style identique de la collection,
     * qui ne fait que s'allonger) : il est calculé une fois par la voie officielle puis réutilisé.
     * getStyle() est toujours appelé pour ses effets de bord (feuille active, cellule sélectionnée).
     */
    private function centerHorizontally(Worksheet $sheet, string $coordinate): void
    {
        $cell = $sheet->getCell($coordinate);
        $original = $cell->getXfIndex();
        if (!isset($this->centeredXf[$original])) {
            $sheet->getStyle($coordinate)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $this->centeredXf[$original] = $sheet->getCell($coordinate)->getXfIndex();

            return;
        }
        $sheet->getStyle($coordinate);
        $cell->setXfIndex($this->centeredXf[$original]);
    }

    /**
     * Indice du superviseur dont l'id est égal (« == ») à l'id donné, comme la boucle de l'ancien
     * moteur : recherche directe par clé, puis parcours complet pour les comparaisons souples rares
     * (ex. id nul ou chaîne vide).
     *
     * @param array<string, int> $supervisorIndex
     */
    private function findSupervisor(array $supervisors, array $supervisorIndex, mixed $id): ?int
    {
        if (is_string($id) && preg_match('/^[1-9][0-9]*$/', $id)) {
            return $supervisorIndex[$id] ?? null;
        }
        foreach ($supervisors as $index => $supervisor) {
            if ($supervisor['id'] == $id) {
                return $index;
            }
        }

        return null;
    }

    private function fillSupervisorRow(Worksheet $sheet, int $row, array $supervisor): void
    {
        $totalAssistance = $supervisor['isFirsthand'] + $supervisor['isSecondhand'];
        $percentageFirsthand = ($totalAssistance == 0) ? 0 : round(($supervisor['isFirsthand'] / $totalAssistance) * 100, 1);
        $percentageSecondhand = ($totalAssistance == 0) ? 0 : round(($supervisor['isSecondhand'] / $totalAssistance) * 100, 1);

        $sheet->insertNewRowBefore($row + 1, 1);
        $sheet->setCellValue('F' . $row, '  id:     ' . $supervisor['label']);
        $sheet->setCellValue('G' . $row, '   Dr   ' . $supervisor['firstname'])
            ->mergeCells('G' . $row . ':H' . $row);
        $sheet->setCellValue('I' . $row, $supervisor['lastname'])
            ->mergeCells('I' . $row . ':J' . $row);

        $sheet->setCellValue('K' . $row, $supervisor['isFirsthand']);
        $sheet->setCellValue('L' . $row, $percentageFirsthand . '%');
        $sheet->setCellValue('M' . $row, $supervisor['isSecondhand']);
        $sheet->setCellValue('N' . $row, $percentageSecondhand . '%');

        $sheet->getStyle('K' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /*----------------------------------------------------- LISTE RECAPITULATIVE ---------------------------------------------------------------------*/

    private function summary(Worksheet $sheet, CarnetDeStageData $data, ExportProgress $progress): void
    {
        $user = $data->user;
        $year = $data->currentYear;

        // Le modèle prévoit 7 années : colonnes ajoutées seulement si l'utilisateur a des années au-delà.
        $maxYearOfFormation = 7;
        foreach ($data->allYears as $yearOfFormation) {
            $maxYearOfFormation = max($maxYearOfFormation, (int) $yearOfFormation->getYearOfFormation());
        }
        $extraYears = $maxYearOfFormation - 7;

        if ($extraYears > 0) {
            $sheet->insertNewColumnBefore('S', $extraYears);
            $sheet->insertNewColumnBefore('K', $extraYears);
        }

        $formationYearColumns = [];
        $firstHandColumns = [];
        for ($i = 1; $i <= $maxYearOfFormation; $i++) {
            $formationYearColumns[$i] = Coordinate::stringFromColumnIndex(4 + $i);
            $firstHandColumns[$i] = Coordinate::stringFromColumnIndex(5 + $maxYearOfFormation + $i);
        }
        $columns = [
            'secondHandYears' => $formationYearColumns,
            'firstHandYears' => $firstHandColumns,
            'secondHandTotal' => Coordinate::stringFromColumnIndex(5 + $maxYearOfFormation),
            'firstHandTotal' => Coordinate::stringFromColumnIndex(6 + 2 * $maxYearOfFormation),
            'total' => Coordinate::stringFromColumnIndex(7 + 2 * $maxYearOfFormation),
            'percentage' => Coordinate::stringFromColumnIndex(8 + 2 * $maxYearOfFormation),
        ];

        if ($extraYears > 0) {
            $yearColumnWidth = $sheet->getColumnDimension('E')->getWidth();
            for ($i = 1; $i <= $maxYearOfFormation; $i++) {
                $sheet->setCellValue($formationYearColumns[$i] . 6, $i)
                    ->setCellValue($formationYearColumns[$i] . 13, $i)
                    ->setCellValue($firstHandColumns[$i] . 13, $i);
                $sheet->getColumnDimension($formationYearColumns[$i])->setWidth($yearColumnWidth);
                $sheet->getColumnDimension($firstHandColumns[$i])->setWidth($yearColumnWidth);
            }
        }

        $sheet->setCellValue('G3', '  Dr ' . ucfirst((string) $user->getFirstname()) . ' ' . ucfirst((string) $user->getLastname()))
            ->setCellValue('E4', '  ' . ucfirst((string) $year->getHospital()))
            ->setCellValue('E5', '  Dr ' . ucfirst((string) $year->getMaster()))
            ->getStyle($formationYearColumns[$year->getYearOfFormation()] . 6)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THICK);

        // Regroupement des interventions de toutes les années (même algorithme que l'ancien moteur).
        $total = count($data->allSurgeries);
        $progress->step(ExportStep::Summary, $total);
        $orthoTrauma = [];
        $orthoElective = [];
        $general = [];
        foreach ($data->allSurgeries as $done => $surgery) {
            $yearOfFormation = $surgery['yearOfFormation'];
            $subtype = $surgery['subtype'];
            $nomenclatureId = $surgery['nomenclatureId'];
            $position = $surgery['position'];

            switch (true) {
                case $surgery['speciality'] == 'ortho' && $surgery['type'] == '2':
                    $group = &$orthoTrauma;
                    break;
                case $surgery['speciality'] == 'ortho' && $surgery['type'] == '1':
                    $group = &$orthoElective;
                    break;
                default:
                    $group = &$general;
                    break;
            }
            if (!isset($group[$subtype])) {
                $group[$subtype] = [];
            }
            if (!isset($group[$subtype][$nomenclatureId])) {
                $group[$subtype][$nomenclatureId] = [
                    'name' => $surgery['name'],
                    'nomenclature' => $surgery['code'],
                    'speciality' => $surgery['speciality'],
                    'years' => [],
                ];
                for ($i = 1; $i <= $maxYearOfFormation; $i++) {
                    $group[$subtype][$nomenclatureId]['years'][$i] = ['firstHand' => 0, 'secondHand' => 0];
                }
            }
            if ($position == 1 || $position == 3) {
                $group[$subtype][$nomenclatureId]['years'][$yearOfFormation]['firstHand']++;
            } elseif ($position == 2) {
                $group[$subtype][$nomenclatureId]['years'][$yearOfFormation]['secondHand']++;
            }
            unset($group);

            if (($done + 1) % (self::PROGRESS_EVERY * 4) === 0) {
                $progress->advance($done + 1);
            }
        }
        if ($total % (self::PROGRESS_EVERY * 4) !== 0) {
            $progress->advance($total);
        }

        // Plan des sections (mêmes positions que l'ancien moteur) : lignes à écrire ou lignes du
        // modèle à sauter. La dernière section est écrite exactement comme par l'ancien moteur
        // (voir writeSummaryRows).
        $plan = [];
        foreach ([$orthoElective, $orthoTrauma] as $index => $group) {
            if ($index > 0) {
                $plan[] = 10;
            }
            foreach (self::ANATOMIC_REGIONS as $anatomic) {
                if (isset($group[$anatomic['value']])) {
                    $plan[] = $group[$anatomic['value']];
                    $plan[] = 3;
                } else {
                    $plan[] = 4;
                }
            }
        }

        // Interventions générales : seules celles sans sous-type (clé ""), comme l'ancien moteur.
        $plan[] = 10;
        foreach (self::SPECIALITIES as $speciality) {
            $detailsList = array_values(array_filter($general[''] ?? [], fn ($details) => $details['speciality'] === $speciality['value']));
            if ($detailsList) {
                $plan[] = $detailsList;
                $plan[] = 3;
            }
        }

        $lastSection = null;
        foreach ($plan as $index => $entry) {
            if (is_array($entry)) {
                $lastSection = $index;
            }
        }

        $currentRow = 16;
        foreach ($plan as $index => $entry) {
            $currentRow = is_int($entry)
                ? $currentRow + $entry
                : $this->writeSummaryRows($sheet, $currentRow, $entry, $maxYearOfFormation, $columns, $index === $lastSection);
        }
    }

    /**
     * Écrit une section de la liste récapitulative à partir de la ligne modèle $row ; renvoie la
     * ligne qui suit la section.
     *
     * L'ancien moteur insérait une ligne par élément puis supprimait la dernière, restée vide :
     * N + 1 opérations qui réécrivent chacune la feuille et ses 1 042 formules (~0,15 s chacune).
     * Insérer directement N - 1 lignes donne la même feuille (cellules, styles, formules, fusions,
     * dimensions) en une opération au plus.
     *
     * $asLegacy (dernière section de la feuille) : N lignes insérées, puis la ligne vide supprimée,
     * comme l'ancien moteur. Chaque insertion/suppression retrie l'index interne des cellules, dont
     * l'ordre détermine celui de la table des chaînes partagées du fichier : reproduire la dernière
     * opération de la feuille rend le fichier identique à l'octet, pas seulement équivalent.
     */
    private function writeSummaryRows(Worksheet $sheet, int $row, array $rows, int $maxYearOfFormation, array $columns, bool $asLegacy): int
    {
        if ($asLegacy) {
            $sheet->insertNewRowBefore($row + 1, count($rows));
        } elseif (count($rows) > 1) {
            $sheet->insertNewRowBefore($row + 1, count($rows) - 1);
        }

        foreach ($rows as $details) {
            $sheet->setCellValue('A' . $row, '  ' . $details['nomenclature']);
            $sheet->setCellValue('B' . $row, '  ' . $details['name']);

            $firstHandTotal = 0;
            $secondHandTotal = 0;
            for ($i = 1; $i <= $maxYearOfFormation; $i++) {
                $secondHandValue = $details['years'][$i]['secondHand'];
                $secondHandTotal += $secondHandValue;
                $sheet->setCellValue($columns['secondHandYears'][$i] . $row, $secondHandValue);
            }
            foreach ($columns['secondHandYears'] as $year => $column) {
                $firstHandValue = $details['years'][$year]['firstHand'];
                $firstHandTotal += $firstHandValue;
                $sheet->setCellValue($columns['firstHandYears'][$year] . $row, $firstHandValue);
            }

            $sheet->setCellValue($columns['firstHandTotal'] . $row, $firstHandTotal);
            $sheet->setCellValue($columns['secondHandTotal'] . $row, $secondHandTotal);
            $sheet->setCellValue($columns['total'] . $row, $firstHandTotal + $secondHandTotal);

            if ($firstHandTotal + $secondHandTotal > 0) {
                $percentage = ($firstHandTotal / ($firstHandTotal + $secondHandTotal)) * 100;
            } else {
                $percentage = 0;
            }
            $sheet->setCellValue($columns['percentage'] . $row, round($percentage, 2) . '%');

            $row++;
        }
        if ($asLegacy) {
            $sheet->removeRow($row, 1);
        }

        return $row;
    }

    /*----------------------------------------------------- CONSULTATIONS ----------------------------------------------------------------------*/

    private function consultations(Worksheet $sheet, CarnetDeStageData $data): void
    {
        $user = $data->user;
        $year = $data->currentYear;
        $consultations = $data->consultations;

        $sheet->setCellValue('C3', '  Dr ' . ucfirst((string) $user->getFirstname()) . ' ' . ucfirst((string) $user->getLastname()))
            ->setCellValue('C4', '  ' . ucfirst((string) $year->getHospital()))
            ->setCellValue('C5', '  Dr ' . ucfirst((string) $year->getMaster()))
            ->setCellValue('C6', '  ' . $year->getYearOfFormation());

        if (empty($consultations)) {
            return;
        }

        // Lignes de départ pour chaque mois.
        $rawByMonth = [1 => 18, 2 => 20, 3 => 22, 4 => 24, 5 => 26, 6 => 28, 7 => 30, 8 => 32, 9 => 34, 10 => 12, 11 => 14, 12 => 16];
        $colByMonth = array_fill(1, 12, 'B');

        // Somme par mois inutilisée, conservée : l'ancien moteur échoue (TypeError) sur un nombre non
        // numérique à cet endroit, le nouveau échoue donc dans les mêmes cas.
        $sumByMonth = array_fill(1, 12, 0);
        foreach ($consultations as $consultation) {
            $sumByMonth[date_format($consultation['date'], 'n')] += $consultation['number'];
        }

        // Total des consultations par date (même format 'd-m-y' que l'ancien moteur).
        $totalsByDate = [];
        foreach ($consultations as $consultation) {
            $key = date_format($consultation['date'], 'd-m-y');
            $totalsByDate[$key][] = $consultation['number'];
        }

        foreach ($data->uniqueDates as $date) {
            $currentMonth = date_format($date['date'], 'n');
            $currentDateFormatted = date_format($date['date'], 'd-m-y');
            $totalConsultationsForDate = array_sum($totalsByDate[$currentDateFormatted] ?? []);
            $currentContentRow = $rawByMonth[$currentMonth];
            $currentContentCol = $colByMonth[$currentMonth];

            $sheet->setCellValue($currentContentCol . ($currentContentRow - 1), $currentDateFormatted)
                ->setCellValue($currentContentCol . $currentContentRow, $totalConsultationsForDate);

            if ($currentContentCol < 'K') {
                $colByMonth[$currentMonth]++;
            } else {
                $colByMonth[$currentMonth]++;
                $sheet->insertNewColumnBefore($currentContentCol, 1)
                    ->setCellValue($currentContentCol . '10', 'DATE');
            }
        }

        $labels = [
            'ortho' => 'Orthopédie',
            'traumato' => 'Traumatologie',
            'dig' => 'Digestive',
            'uro' => 'Urologie',
            'vasc' => 'Vasculaire',
            'plast' => 'Plastique',
        ];
        $consultationsBySpeciality = [];
        foreach (array_keys($labels) as $speciality) {
            $filtered = array_filter($consultations, fn ($consultation) => $consultation['speciality'] === $speciality);
            $consultationsBySpeciality[$speciality] = array_sum(array_column($filtered, 'number'));
        }

        $endCol = max($colByMonth);
        if ($endCol >= 'K') {
            $sheet->removeColumn($endCol, 3);
            for ($i = 1; $i <= 2; $i++) {
                $endCol++;
            }
            $totalCol = $endCol;
            for ($i = 1; $i <= 2; $i++) {
                $totalCol++;
            }
        } else {
            $sheet->removeColumn('K', 3);
            $endCol = 'M';
            $totalCol = 'O';
        }

        $row = 11;
        foreach ($consultationsBySpeciality as $speciality => $total) {
            if ($total !== 0) {
                $sheet->setCellValue($endCol . $row, '  ' . $labels[$speciality])
                    ->setCellValue($totalCol . $row, '  ' . $total);
                $row++;
            }
        }
    }

    /*----------------------------------------------------- GARDES ---------------------------------------------------------*/

    private function gardes(Worksheet $sheet, CarnetDeStageData $data): void
    {
        $user = $data->user;
        $year = $data->currentYear;
        $gardes = $data->gardes;

        $sheet->setCellValue('C3', '  Dr ' . ucfirst((string) $user->getFirstname()) . ' ' . ucfirst((string) $user->getLastname()))
            ->setCellValue('C4', '  ' . ucfirst((string) $year->getHospital()))
            ->setCellValue('C5', '  Dr ' . ucfirst((string) $year->getMaster()))
            ->setCellValue('C6', '  ' . $year->getYearOfFormation());

        $rows = ['1' => '17', '2' => '19', '3' => '21', '4' => '23', '5' => '25', '6' => '27', '7' => '29', '8' => '31', '9' => '33', '10' => '11', '11' => '13', '12' => '15'];
        $colByMonth = array_fill(1, 12, 'B');
        // Inutilisée, conservée pour échouer dans les mêmes cas que l'ancien moteur (voir consultations).
        $sumByMonth = array_fill(1, 12, 0);

        for ($month = 1; $month <= 12; $month++) {
            $column = $colByMonth[$month];
            foreach ($gardes as $garde) {
                if (date_format($garde['dateOfStart'], 'n') == $month) {
                    $sheet->setCellValue($column . $rows[$month], date_format($garde['dateOfStart'], 'd-m-y'))
                        ->setCellValue($column . ($rows[$month] + 1), $garde['number']);
                    $sumByMonth[$month] += $garde['number'];

                    if ($column < 'K' || $column < max($colByMonth)) {
                        $column++;
                    } else {
                        $column++;
                        $sheet->insertNewColumnBefore($column, 1)
                            ->setCellValue($column . '10', 'DATE');
                    }
                    $colByMonth[$month] = $column;
                }
            }
        }

        $endCol = max($colByMonth);
        if ($endCol > 'K') {
            $sheet->removeColumn($endCol, 3);
            for ($i = 1; $i <= 2; $i++) {
                $endCol++;
            }
            $totalCol = $endCol;
            for ($i = 1; $i <= 2; $i++) {
                $totalCol++;
            }
        } else {
            $sheet->removeColumn('K', 3);
            $totalCol = 'O';
        }

        $sheet->setCellValue($totalCol . '11', count($gardes));
    }

    /*----------------------------------------------------- RAPPORT D'ACTIVITE ---------------------------------------------------------*/

    private function activityReport(Worksheet $sheet, CarnetDeStageData $data, array $currentYearSurgeries): void
    {
        $getRow = fn ($month) => ($month <= 2 || $month >= 9) ? 4 : 30;
        $consultations = $data->consultations;

        // Consultations par jour de la semaine et moment de la journée.
        if (!empty($consultations)) {
            $colByMonth = ['1' => 'AP', '2' => 'AX', '3' => 'G', '4' => 'O', '5' => 'W', '6' => 'AH', '7' => 'AP', '8' => 'AX', '9' => 'G', '10' => 'O', '11' => 'W', '12' => 'AH'];

            for ($month = 1; $month <= 12; $month++) {
                $row = $getRow($month);
                $table = [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]];

                foreach ($consultations as $cons) {
                    if (date_format($cons['date'], 'n') == $month) {
                        $day = date_format($cons['date'], 'N');
                        $period = 0;
                        if ($cons['dayPart'] === 'afternoon') {
                            $period = 1;
                        } elseif ($cons['dayPart'] === 'night') {
                            $period = 2;
                        }
                        $table[$day][$period] = $table[$day][$period] + $cons['number'];
                    }
                }

                $total = 0;
                foreach ($table as $currentDay) {
                    foreach ($currentDay as $number) {
                        if ($number !== 0) {
                            $sheet->setCellValue($colByMonth[$month] . $row, $number);
                            $total = $total + $number;
                        }
                        $row++;
                    }
                }
                $sheet->setCellValue($colByMonth[$month] . $row, $total);
            }
        }

        // Interventions par jour de la semaine : 1re main (positions 1 et 3), puis aide (position 2).
        if (!empty($currentYearSurgeries)) {
            $byPosition = [
                [fn ($p) => $p == 1 || $p == 3, ['1' => 'AR', '2' => 'AZ', '3' => 'I', '4' => 'Q', '5' => 'Y', '6' => 'AJ', '7' => 'AR', '8' => 'AZ', '9' => 'I', '10' => 'Q', '11' => 'Y', '12' => 'AJ']],
                [fn ($p) => $p == 2, ['1' => 'AQ', '2' => 'AY', '3' => 'H', '4' => 'P', '5' => 'X', '6' => 'AI', '7' => 'AQ', '8' => 'AY', '9' => 'H', '10' => 'P', '11' => 'X', '12' => 'AI']],
            ];
            foreach ($byPosition as [$filter, $colByMonth]) {
                $counts = [];
                foreach ($currentYearSurgeries as $surgery) {
                    if ($filter($surgery['position'])) {
                        $month = (int) $surgery['date']->format('n');
                        $day = (int) $surgery['date']->format('N');
                        $counts[$month][$day] = ($counts[$month][$day] ?? 0) + 1;
                    }
                }

                for ($month = 1; $month <= 12; $month++) {
                    $row = $getRow($month);
                    $total = 0;
                    for ($day = 1; $day <= 7; $day++) {
                        $number = $counts[$month][$day] ?? 0;
                        if ($number !== 0) {
                            $sheet->setCellValue($colByMonth[$month] . $row, $number);
                            $total = $total + $number;
                        }
                        $row = $row + 3;
                    }
                    $sheet->setCellValue($colByMonth[$month] . $row, $total);
                }
            }
        }

        // Formations par type, jour de la semaine et moment de la journée.
        $formations = $data->formations;
        if (!empty($formations)) {
            $colByMonth = ['1' => 'AL', '2' => 'AT', '3' => 'C', '4' => 'K', '5' => 'S', '6' => 'AD', '7' => 'AL', '8' => 'AT', '9' => 'C', '10' => 'K', '11' => 'S', '12' => 'AD'];

            for ($n = 1; $n <= 12; $n++) {
                $emptyWeek = [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]];
                $table = ['staff' => $emptyWeek, 'journal' => $emptyWeek, 'lesson' => $emptyWeek, 'congres' => $emptyWeek];
                $row = $getRow($n);

                foreach ($formations as $formation) {
                    // Un type inconnu n'ajoute une colonne que s'il compte au moins une demi-journée ce
                    // mois-ci (l'ancien moteur ne créait la clé qu'à la première incrémentation).
                    $type = $formation['event'];
                    $counts = $table[$type] ?? [];
                    $this->countPeriods($counts, $formation, $n);
                    if (isset($table[$type]) || $counts !== []) {
                        $table[$type] = $counts;
                    }
                }

                $saveRow = $row;
                $col = $colByMonth[$n];
                foreach ($table as $event) {
                    $total = 0;
                    $row = $saveRow;
                    foreach ($event as $d) {
                        foreach ($d as $e) {
                            if ($e !== 0) {
                                $sheet->setCellValue($col . $row, $e);
                                $total = $total + $e;
                            }
                            $row++;
                        }
                    }
                    $sheet->setCellValue($col . $row, $total);
                    $col++;
                }
            }
        }

        // Gardes par jour de la semaine et moment de la journée.
        $gardes = $data->gardes;
        if (!empty($gardes)) {
            $colByMonth = ['1' => 'AS', '2' => 'BA', '3' => 'J', '4' => 'R', '5' => 'Z', '6' => 'AK', '7' => 'AS', '8' => 'BA', '9' => 'J', '10' => 'R', '11' => 'Z', '12' => 'AK'];

            for ($n = 1; $n <= 12; $n++) {
                $table = [1 => [0, 0, 0], 2 => [0, 0, 0], 3 => [0, 0, 0], 4 => [0, 0, 0], 5 => [0, 0, 0], 6 => [0, 0, 0], 7 => [0, 0, 0]];
                $row = $getRow($n);

                foreach ($gardes as $garde) {
                    $this->countPeriods($table, $garde, $n);
                }

                $col = $colByMonth[$n];
                $total = 0;
                foreach ($table as $currentDay) {
                    foreach ($currentDay as $timePeriod) {
                        if ($timePeriod !== 0) {
                            $sheet->setCellValue($col . $row, $timePeriod);
                            $total = $total + $timePeriod;
                        }
                        $row++;
                    }
                }
                $sheet->setCellValue($col . $row, $total);
            }
        }
    }

    /**
     * Compte les demi-journées (matin < 12:00, après-midi < 18:00, soir) occupées par un évènement
     * (formation ou garde) pour le mois $n. Règles identiques à l'ancien moteur, y compris ses
     * particularités (le jour de début d'un évènement de plusieurs jours est celui du début, même
     * si le mois de fin diffère ; heures comparées en chaînes « HH:MM »).
     *
     * @param array<int, array{int, int, int}> $table
     */
    private function countPeriods(array &$table, array $event, int $n): void
    {
        $dayOfStart = date_format($event['dateOfStart'], 'd-m-Y');
        $dayOfEnd = date_format($event['dateOfEnd'], 'd-m-Y');
        $day = date_format($event['dateOfStart'], 'N');
        $startTime = date_format($event['dateOfStart'], 'H:i');
        $endTime = date_format($event['dateOfEnd'], 'H:i');

        // Évènement sur une journée.
        if ($dayOfStart == $dayOfEnd) {
            $month = date_format($event['dateOfStart'], 'n');

            if ($month == $n) {
                if ($startTime < '12:00') {
                    if ($endTime <= '12:00') {
                        $table[$day][0]++;
                    }
                    if ($endTime > '12:00' & $endTime <= '18:00') {
                        $table[$day][0]++;
                        $table[$day][1]++;
                    }
                    if ($endTime > '18:00') {
                        $table[$day][0]++;
                        $table[$day][1]++;
                        $table[$day][2]++;
                    }
                }
                if ($startTime >= '12:00' & $startTime < '18:00') {
                    if ($endTime <= '18:00') {
                        $table[$day][1]++;
                    }
                    if ($endTime > '18:00') {
                        $table[$day][1]++;
                        $table[$day][2]++;
                    }
                }
                if ($startTime >= '18:00') {
                    $table[$day][2]++;
                }
            }
        }

        // Évènement sur plusieurs jours.
        if ($dayOfStart !== $dayOfEnd) {
            // Jour de début.
            if (date_format($event['dateOfStart'], 'n') == $n) {
                if ($startTime < '12:00') {
                    $table[$day][0]++;
                    $table[$day][1]++;
                    $table[$day][2]++;
                }
                if ($startTime >= '12:00' & $startTime < '18:00') {
                    $table[$day][1]++;
                    $table[$day][2]++;
                }
                if ($startTime >= '18:00') {
                    $table[$day][2]++;
                }
            }

            // Jour de fin.
            $dayEnd = date_format($event['dateOfEnd'], 'N');
            if (date_format($event['dateOfEnd'], 'n') == $n) {
                if ($endTime <= '12:00') {
                    $table[$dayEnd][0]++;
                } elseif ($endTime > '12:00' & $endTime <= '18:00') {
                    $table[$dayEnd][0]++;
                    $table[$dayEnd][1]++;
                } elseif ($endTime >= '18:00') {
                    $table[$dayEnd][0]++;
                    $table[$dayEnd][1]++;
                    $table[$dayEnd][2]++;
                }
            }

            // Journées intermédiaires.
            $startDate = $event['dateOfStart'];
            $endDate = $event['dateOfEnd'];
            $period = new \DatePeriod($startDate, \DateInterval::createFromDateString('1 day'), $endDate);
            $from = date_format($startDate, 'z');
            $to = date_format($endDate, 'z');
            foreach ($period as $date) {
                $t = date_format($date, 'z');
                if (date_format($date, 'n') == $n & $t > $from & $t < $to) {
                    $dayOfWeek = date_format($date, 'N');
                    $table[$dayOfWeek][0]++;
                    $table[$dayOfWeek][1]++;
                    $table[$dayOfWeek][2]++;
                }
            }
        }
    }

    /*----------------------------------------------------- EVALUATION CANDIDAT ---------------------------------------------------------*/

    private function candidateEvaluation(Worksheet $sheet, CarnetDeStageData $data): void
    {
        $user = $data->user;
        $year = $data->currentYear;
        $roleLabels = ['speaker' => 'Orateur', 'organiser' => 'Organisateur'];

        $sheet->setCellValue('H4', ucfirst((string) $user->getFirstname()) . ' ' . ucfirst((string) $user->getLastname()))
            ->setCellValue('E5', '  ' . ucfirst((string) $year->getHospital()))
            ->setCellValue('E6', '  Dr ' . ucfirst((string) $year->getMaster()))
            ->setCellValue('O7', '  ' . $year->getYearOfFormation());

        $formations = $data->formations;
        if (empty($formations)) {
            return;
        }

        $hospital = $year->getHospital();
        $sections = [
            array_filter($formations, fn ($f) => ($f['location'] === $hospital & $f['role'] === 'participant')),
            array_filter($formations, fn ($f) => ($f['location'] !== $hospital & $f['role'] === 'participant')),
            array_filter($formations, fn ($f) => ($f['role'] !== 'participant')),
        ];

        $row = 88;
        foreach ($sections as $section) {
            foreach ($section as $formation) {
                // L'ancien moteur modifiait ces dates en place (objets partagés) : conservé à l'identique.
                $startDate = $formation['dateOfStart'];
                $startDate->setTime(0, 0);
                $endDate = $formation['dateOfEnd'];
                $endDate->setTime(12, 0);

                $period = new \DatePeriod($startDate, \DateInterval::createFromDateString('1 day'), $endDate);
                foreach ($period as $date) {
                    $sheet->setCellValue('A' . $row, date_format($date, 'd-m-Y'))
                        ->setCellValue('C' . $row, '   ' . $formation['location'])
                        ->setCellValue('I' . $row, '   ' . $formation['name']);

                    if ($formation['role'] === 'participant') {
                        $sheet->setCellValue('R' . $row, '   ' . $formation['description']);
                    } else {
                        $sheet->setCellValue('R' . $row, '   ' . $roleLabels[$formation['role']] . ':  ' . $formation['description']);
                    }

                    $sheet->insertNewRowBefore($row + 1, 1);
                    $row++;
                    $sheet->mergeCells('A' . $row . ':B' . $row)
                        ->mergeCells('C' . $row . ':H' . $row)
                        ->mergeCells('I' . $row . ':Q' . $row)
                        ->mergeCells('R' . $row . ':X' . $row);
                }
            }
            $row = $row + 6;
        }
    }
}
