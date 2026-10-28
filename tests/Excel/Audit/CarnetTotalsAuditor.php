<?php

namespace App\Tests\Excel\Audit;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Audit mathématique INDÉPENDANT du carnet de stage : les données source sont relues en SQL brut
 * (sans le code du moteur), chaque total est recalculé selon sa règle métier documentée
 * (self::RULES), puis comparé aux valeurs écrites et aux formules CALCULÉES du fichier, avec
 * contrôle des plages de chaque formule (aucune plage arrêtée avant la dernière ligne).
 *
 * Résultat : une liste de constats { rule, ok, where, expected, actual }. Un constat en échec
 * n'est jamais « corrigé » ici : il documente un écart à présenter (voir docs/AUDIT-TOTAUX-EXCEL.md).
 */
final class CarnetTotalsAuditor
{
    /** Règles métier vérifiées (identifiant → règle). */
    public const RULES = [
        // Carnet de stage (feuille 2)
        'C1' => 'Carnet : une ligne par intervention de l\'année exportée, aucune omise ni ajoutée, triée par date (date, code, libellé exacts).',
        'C2' => 'Carnet : position 1 → « Opérateur seul » (L) ; 2 → « Assistant 1er » (I) + code de l\'aîné (K) ; 3 → « Opérateur + aîné » (M) + code de l\'aîné (N).',
        'C3' => 'Carnet : les sommes I, J, L, M couvrent toutes les lignes du tableau (plage depuis la 1re jusqu\'au-delà de la dernière ligne).',
        'C4' => 'Carnet : totaux I = nb de positions 2, J = 0, L = nb de positions 1, M = nb de positions 3.',
        'C5' => 'Carnet : « Nbre total des interventions » = nombre d\'interventions de l\'année.',
        'C6' => 'Carnet : pourcentages I/J/L/M = part de chaque colonne dans le total.',
        'C7' => 'Carnet : décompte par superviseur, Assistances (K) = positions 2 dont il est l\'aîné, Opérateur+aîné (M) = positions 3 dont il est l\'aîné, % = K/(K+M) et M/(K+M) à 0,1 près.',
        'C8' => 'Carnet : la ligne « Total » du décompte couvre toutes les lignes de superviseurs et vaut leur somme.',
        'C9' => 'Carnet : toutes les assistances du candidat sont attribuées à un superviseur (total décompte = total candidat).',
        // Liste récapitulative (feuille 3)
        'R1' => 'Récapitulatif : une ligne par code de nomenclature et par section (région ou spécialité), assistant = positions 2, opérateur = positions 1 et 3, par année de formation, toutes années de l\'utilisateur.',
        'R2' => 'Récapitulatif : sur chaque ligne, total assistant = somme des années, total opérateur = somme des années, A+O = assistant + opérateur, % = opérateur / (A+O) à 0,01 près.',
        'R3' => 'Récapitulatif : chaque « Sous total » couvre toutes les lignes de sa section et vaut leur somme.',
        'R4' => 'Récapitulatif : chaque « TOTAL GENERAL » additionne TOUS les sous-totaux de son bloc, dans chaque colonne.',
        'R5' => 'Récapitulatif : toutes les interventions (positions 1 à 3) de toutes les années figurent dans une ligne (aucune exclue).',
        'R6' => 'Récapitulatif : une ligne ne regroupe que des interventions du même code (pas de regroupement sous le libellé d\'un autre acte).',
        // Consultations (feuille 4)
        'K1' => 'Consultations : chaque date de consultation apparaît dans la ligne de son mois, avec la somme des patients de cette date.',
        'K2' => 'Consultations : le total d\'un mois couvre toutes les dates du mois et vaut la somme des patients du mois.',
        'K3' => 'Consultations : le total annuel vaut la somme de tous les patients de l\'année.',
        'K4' => 'Consultations : le total par type de consultation vaut la somme des patients de ce type ; la somme des types = total annuel.',
        // Gardes (feuille 5)
        'G1' => 'Gardes : chaque garde apparaît dans la ligne de son mois (date de début, nombre de patients).',
        'G2' => 'Gardes : le total d\'un mois couvre toutes les gardes du mois et vaut la somme de leurs patients.',
        'G3' => 'Gardes : « Nb patients traités » = somme des patients de toutes les gardes.',
        'G4' => 'Gardes : « Nb total de gardes » (à côté de son libellé) = nombre de gardes de l\'année.',
        // Rapport d'activité (feuille 6)
        'A1' => 'Rapport : consultations par mois, jour de la semaine et moment (matin par défaut, après-midi, soir) = somme des patients.',
        'A2' => 'Rapport : interventions par mois et jour de la semaine, « CF assistant » = positions 2, « CF opérateur » = positions 1 et 3.',
        'A3' => 'Rapport : formations (par type) et gardes, demi-journées occupées (matin < 12 h, après-midi 12–18 h, soir ≥ 18 h ; une demi-journée compte si l\'évènement la chevauche), jours intermédiaires compris, y compris d\'une année civile à l\'autre.',
        'A4' => 'Rapport : la ligne TOTAL de chaque mois = somme de la colonne du mois.',
        'A5' => 'Rapport : TOTAL ANNUEL = somme des 12 totaux mensuels de la colonne.',
        // Évaluation du candidat (feuille 7)
        'E1' => 'Évaluation : une ligne par jour de formation (du jour de début au jour de fin inclus), dans la bonne section (participant à l\'hôpital, ailleurs, orateur/organisateur).',
    ];

    private const MONTH_LABELS = ['OCTOBRE' => 10, 'NOVEMBRE' => 11, 'DECEMBRE' => 12, 'JANVIER' => 1, 'FEVRIER' => 2, 'MARS' => 3,
        'AVRIL' => 4, 'MAI' => 5, 'JUIN' => 6, 'JUILLET' => 7, 'AOUT' => 8, 'SEPTEMBRE' => 9];
    private const ELECTIVE = ['shoulder' => 'EPAULE', 'humerus' => 'HUMERUS', 'elbow' => 'COUDE', 'forearm' => 'AVANT-BRAS',
        'wristhand' => 'POIGNET-MAIN', 'back' => 'RACHIS', 'pelvic' => 'BASSIN', 'hip' => 'HANCHE', 'proximalFemur' => 'FEMUR PROXIMAL',
        'midFemur' => 'FEMUR DIAPHYSE', 'distalFemur' => 'FEMUR DISTAL', 'knee' => 'GENOU', 'limb' => 'JAMBE', 'ankle' => 'CHEVILLE', 'foot' => 'PIED'];
    private const GENERAL = ['dig' => 'DIGESTIVE', 'general' => 'GENERAL', 'uro' => 'UROLOGIE', 'vasc' => 'VASCULAIRE',
        'thor' => 'THORACIQUE', 'plastic' => 'PLASTIQUE', 'neuro' => 'NEUROLOGIE', 'transp' => 'TRANSPLANTATION'];
    private const CONSULTATION_TYPES = ['ortho' => 'Orthopédie', 'traumato' => 'Traumatologie', 'dig' => 'Digestive', 'uro' => 'Urologie',
        'vasc' => 'Vasculaire', 'plast' => 'Plastique'];
    /** Rapport d'activité : colonne de début de chaque mois (8 colonnes : staff, journal, cours, congrès, consultations, assistant, opérateur, garde). */
    private const REPORT_MONTH_COLUMNS = [9 => 'C', 10 => 'K', 11 => 'S', 12 => 'AD', 1 => 'AL', 2 => 'AT', 3 => 'C', 4 => 'K', 5 => 'S', 6 => 'AD', 7 => 'AL', 8 => 'AT'];

    /** @var list<array{rule: string, ok: bool, where: string, expected: mixed, actual: mixed}> */
    private array $findings = [];

    /**
     * @return list<array{rule: string, ok: bool, where: string, expected: mixed, actual: mixed}>
     */
    public function audit(Connection $db, int $userId, int $yearId, string $xlsxPath): array
    {
        $this->findings = [];
        $data = $this->load($db, $userId, $yearId);
        $book = IOFactory::createReader('Xlsx')->load($xlsxPath);

        $this->logbook($book->getSheet(1), $data);
        $this->summary($book, $book->getSheet(2), $data);
        $this->consultations($book->getSheet(3), $data);
        $this->gardes($book->getSheet(4), $data);
        $this->activityReport($book->getSheet(5), $data);
        $this->evaluation($book->getSheet(6), $data);
        $book->disconnectWorksheets();

        return $this->findings;
    }

    /** Synthèse : règle → [contrôles, échecs, premier échec]. */
    public static function summarize(array $findings): array
    {
        $summary = [];
        foreach (self::RULES as $rule => $text) {
            $summary[$rule] = ['checks' => 0, 'failures' => 0, 'first' => null];
        }
        foreach ($findings as $f) {
            $summary[$f['rule']]['checks']++;
            if (!$f['ok']) {
                $summary[$f['rule']]['failures']++;
                $summary[$f['rule']]['first'] ??= sprintf('%s : attendu %s, obtenu %s', $f['where'], json_encode($f['expected'], JSON_UNESCAPED_UNICODE), json_encode($f['actual'], JSON_UNESCAPED_UNICODE));
            }
        }

        return $summary;
    }

    /*------------------------------------------------------------ Données source (SQL brut) */

    private function load(Connection $db, int $userId, int $yearId): array
    {
        $years = $db->fetchAllAssociative('SELECT id, year_of_formation, hospital FROM years WHERE user_id = ? ORDER BY id', [$userId]);
        $current = array_values(array_filter($years, fn ($y) => (int) $y['id'] === $yearId))[0];
        $yearOf = array_column($years, 'year_of_formation', 'id');
        $surgeries = $db->fetchAllAssociative('SELECT s.id, s.date, s.position, s.first_hand, s.second_hand, s.code, s.name, s.speciality, s.year_id,
                n.id AS nid, n.type, n.sub_type FROM surgeries s LEFT JOIN nomenclature n ON n.id = s.nomenclature_id
                WHERE s.year_id IN (SELECT id FROM years WHERE user_id = ?) ORDER BY s.year_id, s.id', [$userId]);
        foreach ($surgeries as &$s) {
            $s['yearOfFormation'] = $yearOf[$s['year_id']];
            $s['day'] = new \DateTimeImmutable(substr($s['date'], 0, 10));
        }
        unset($s);
        $currentSurgeries = array_values(array_filter($surgeries, fn ($s) => (int) $s['year_id'] === $yearId));
        usort($currentSurgeries, fn ($a, $b) => [$a['day'], (int) $a['id']] <=> [$b['day'], (int) $b['id']]);

        return [
            'years' => $years,
            'current' => $current,
            'surgeries' => $surgeries,
            'currentSurgeries' => $currentSurgeries,
            'surgeons' => $db->fetchAllAssociative('SELECT id, first_name, last_name, boss FROM surgeons WHERE year_id = ? ORDER BY id', [$yearId]),
            'consultations' => $db->fetchAllAssociative('SELECT date, number, day_part, speciality FROM consultations WHERE year_id = ?', [$yearId]),
            'gardes' => $db->fetchAllAssociative('SELECT date_of_start, date_of_end, number FROM gardes WHERE year_id = ?', [$yearId]),
            'formations' => $db->fetchAllAssociative('SELECT event, date_of_start, date_of_end, name, description, location, role FROM formations WHERE year_id = ?', [$yearId]),
            'maxYear' => max(7, ...array_map(fn ($y) => (int) $y['year_of_formation'], $years)),
        ];
    }

    /*------------------------------------------------------------ Carnet de stage */

    private function logbook(Worksheet $sheet, array $data): void
    {
        $header = $this->findRow($sheet, 'A', fn ($v) => $v === 'Date');
        // Première ligne datée après l'en-tête (normalement 4 lignes plus bas).
        $first = $header + 1;
        while ($first < $header + 10 && !preg_match('/^\d\d-\d\d-\d{4}$/', $this->text($sheet, "A$first"))) {
            $first++;
        }
        if ($first === $header + 10) {
            $first = $header + 4; // tableau vide
        } elseif ($first !== $header + 4) {
            $this->check('C1', "Carnet : première ligne du tableau", $header + 4, $first);
        }
        $rows = [];
        for ($r = $first; $this->text($sheet, "A$r") !== ''; $r++) {
            $rows[] = $r;
        }
        $last = end($rows) ?: $first;
        $surgeries = $data['currentSurgeries'];
        $this->check('C1', 'Carnet : nombre de lignes', count($surgeries), count($rows));

        $labels = $this->supervisorLabels($data['surgeons']);
        $counts = ['1' => 0, '2' => 0, '3' => 0];
        foreach ($surgeries as $i => $s) {
            $r = $rows[$i] ?? null;
            $position = trim((string) $s['position']);
            if (isset($counts[$position])) {
                $counts[$position]++;
            }
            if ($r === null) {
                continue;
            }
            $this->check('C1', "Carnet A$r:C$r", [$s['day']->format('d-m-Y'), (string) $s['code'], (string) $s['name']],
                [$this->text($sheet, "A$r"), $this->text($sheet, "B$r"), $this->text($sheet, "C$r")]);
            $expected = ['I' => $position === '2' ? '1' : '', 'K' => $position === '2' ? (string) ($labels[$s['first_hand']] ?? '') : '',
                'L' => $position === '1' ? '1' : '', 'M' => $position === '3' ? '1' : '', 'N' => $position === '3' ? (string) ($labels[$s['second_hand']] ?? '') : ''];
            $actual = array_map(fn ($c) => $this->text($sheet, "$c$r"), array_combine(array_keys($expected), array_keys($expected)));
            $this->check('C2', "Carnet I$r:N$r (position $position)", $expected, $actual);
        }

        $totals = $this->findRow($sheet, 'I', fn ($v, $cell) => $cell->getDataType() === 'f' && str_starts_with($cell->getValue(), '=SUM(I'), $last);
        foreach (['I', 'J', 'L', 'M'] as $col) {
            [$from, $to] = $this->range($sheet->getCell("$col$totals")->getValue());
            $this->check('C3', "Carnet $col$totals plage", "$first:≥$last", "$from:$to", $from === $first && $to >= $last);
        }
        $expected = ['I' => $counts['2'], 'J' => 0, 'L' => $counts['1'], 'M' => $counts['3']];
        foreach ($expected as $col => $value) {
            $this->check('C4', "Carnet $col$totals", $value, $this->calc($sheet, "$col$totals"));
        }
        $all = array_sum($expected);
        $this->check('C5', 'Carnet H' . ($totals + 1), count($surgeries), $this->calc($sheet, 'H' . ($totals + 1)));
        foreach ($expected as $col => $value) {
            $this->check('C6', "Carnet $col" . ($totals + 2), $all ? $value / $all : null, $this->calc($sheet, $col . ($totals + 2)), null, 1e-9);
        }

        // Décompte par superviseur.
        $tally = [];
        foreach ($data['surgeons'] as $sup) {
            $tally[$sup['id']] = ['K' => 0, 'M' => 0];
        }
        foreach ($surgeries as $s) {
            $position = trim((string) $s['position']);
            if ($position === '2' && isset($tally[(int) $s['first_hand']]) && (string) (int) $s['first_hand'] === trim((string) $s['first_hand'])) {
                $tally[(int) $s['first_hand']]['K']++;
            }
            if ($position === '3' && isset($tally[(int) $s['second_hand']]) && (string) (int) $s['second_hand'] === trim((string) $s['second_hand'])) {
                $tally[(int) $s['second_hand']]['M']++;
            }
        }
        $totalRow = $this->findRow($sheet, 'H', fn ($v) => $v === 'Total', $totals);
        $supRows = [];
        for ($r = $totals + 3; $r < $totalRow; $r++) {
            if (str_starts_with($this->text($sheet, "F$r"), 'id:')) {
                $supRows[$this->text($sheet, "F$r")] = $r;
            }
        }
        $this->check('C7', 'Décompte : nombre de lignes de superviseurs', count($data['surgeons']), count($supRows));
        $byLabel = array_flip(array_map('strval', $labels));
        foreach ($supRows as $text => $r) {
            $id = $byLabel[trim(substr($text, 3))] ?? null;
            $exp = $id !== null ? $tally[$id] : ['K' => null, 'M' => null];
            $sum = ($exp['K'] ?? 0) + ($exp['M'] ?? 0);
            $pct = fn ($n) => ($sum == 0 ? 0 : round($n / $sum * 100, 1)) . '%';
            $this->check('C7', "Décompte K$r:N$r ($text)", [$exp['K'], $pct($exp['K']), $exp['M'], $pct($exp['M'])],
                [$this->calc($sheet, "K$r"), $this->text($sheet, "L$r"), $this->calc($sheet, "M$r"), $this->text($sheet, "N$r")]);
        }
        $firstSup = $supRows ? min($supRows) : $totalRow;
        $lastSup = $supRows ? max($supRows) : $totalRow;
        foreach (['K', 'M'] as $col) {
            [$from, $to] = $this->range($sheet->getCell("$col$totalRow")->getValue());
            $this->check('C8', "Décompte $col$totalRow plage", "≤$firstSup:≥$lastSup", "$from:$to", $from <= $firstSup && $to >= $lastSup);
            $this->check('C8', "Décompte $col$totalRow", array_sum(array_column($tally, $col)), $this->calc($sheet, "$col$totalRow"));
        }
        $this->check('C9', "Décompte K$totalRow = assistances du candidat", $counts['2'], $this->calc($sheet, "K$totalRow"));
        $this->check('C9', "Décompte M$totalRow = opérateur+aîné du candidat", $counts['3'], $this->calc($sheet, "M$totalRow"));
    }

    /** Libellés des superviseurs (A, B, …) : maître de stage d'abord, puis nom, puis prénom. */
    private function supervisorLabels(array $surgeons): array
    {
        $sorted = $surgeons;
        usort($sorted, fn ($a, $b) => [(int) !(bool) $a['boss'], $a['last_name'], $a['first_name']] <=> [(int) !(bool) $b['boss'], $b['last_name'], $b['first_name']]);
        $labels = [];
        foreach ($sorted as $i => $s) {
            $labels[$s['id']] = $i < 26 ? chr(65 + $i) : $i + 1;
        }

        return $labels;
    }

    /*------------------------------------------------------------ Liste récapitulative */

    private function summary(Spreadsheet $book, Worksheet $sheet, array $data): void
    {
        $maxYear = $data['maxYear'];
        $col = fn (int $i) => Coordinate::stringFromColumnIndex($i);
        $assistantCols = array_map(fn ($i) => $col(4 + $i), range(1, $maxYear));
        $operatorCols = array_map(fn ($i) => $col(5 + $maxYear + $i), range(1, $maxYear));
        $assistantTotal = $col(5 + $maxYear);
        $operatorTotal = $col(6 + 2 * $maxYear);
        $total = $col(7 + 2 * $maxYear);
        $percent = $col(8 + 2 * $maxYear);

        // Attendu : regroupement selon la règle métier, avec motifs d'exclusion.
        $expected = [];
        $included = 0;
        $excluded = [];
        $merged = [];
        foreach ($data['surgeries'] as $s) {
            $position = trim((string) $s['position']);
            $isOrtho = $s['speciality'] === 'ortho' && in_array((string) $s['type'], ['1', '2'], true);
            if ($isOrtho) {
                $block = $s['type'] === '1' ? 'elective' : 'trauma';
                $section = self::ELECTIVE[$s['sub_type']] ?? null;
                $reason = $section === null ? 'orthopédie : sous-type « ' . $s['sub_type'] . ' » hors des 15 régions' : null;
            } else {
                $block = 'general';
                $section = self::GENERAL[$s['speciality']] ?? null;
                $reason = match (true) {
                    $s['speciality'] === 'ortho' => 'orthopédie sans type électif/trauma (sans nomenclature ou type vide)',
                    $section === null => 'spécialité « ' . $s['speciality'] . ' » hors des 8 de chirurgie générale',
                    ($s['sub_type'] ?? '') !== '' => 'chirurgie générale avec sous-type « ' . $s['sub_type'] . ' »',
                    default => null,
                };
            }
            $invalidPosition = !in_array($position, ['1', '2', '3'], true);
            if ((int) $s['yearOfFormation'] < 1 || (int) $s['yearOfFormation'] > $maxYear) {
                $reason ??= 'année de formation « ' . $s['yearOfFormation'] . ' » hors colonnes';
            }
            if ($reason !== null) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;
                continue;
            }
            // La ligne existe dès la première intervention de ce code dans la section (son libellé),
            // mais seules les positions 1, 2 et 3 sont comptées.
            $key = $s['nid'] ?? 'sans-nomenclature';
            $row = &$expected[$block][$section][$key];
            $row ??= ['code' => (string) $s['code'], 'name' => (string) $s['name'], 'a' => array_fill(1, $maxYear, 0), 'o' => array_fill(1, $maxYear, 0), 'codes' => []];
            $row['codes'][(string) $s['code'] . '|' . $s['name']] = true;
            if ($invalidPosition) {
                unset($row);
                $excluded['position « ' . $position . ' » (ni 1, ni 2, ni 3), non comptée'] = ($excluded['position « ' . $position . ' » (ni 1, ni 2, ni 3), non comptée'] ?? 0) + 1;
                continue;
            }
            $row[$position === '2' ? 'a' : 'o'][(int) $s['yearOfFormation']]++;
            unset($row);
            $included++;
        }

        // Lecture du fichier : blocs (TOTAL GENERAL), sections, lignes de données, sous-totaux.
        $blocks = ['elective', 'trauma', 'general'];
        $blockIndex = 0;
        $section = null;
        $sections = [];
        $grand = [];
        for ($r = 14, $max = $sheet->getHighestRow(); $r <= $max; $r++) {
            $a = $this->text($sheet, "A$r");
            if ($a === 'TOTAL GENERAL') {
                $grand[$blocks[$blockIndex]] = $r;
                $blockIndex++;
                $section = null;
                continue;
            }
            $titles = $blockIndex < 2 ? self::ELECTIVE : self::GENERAL;
            if (in_array($a, $titles, true)) {
                $section = $a;
                $sections[$blocks[$blockIndex]][$section] = ['header' => $r, 'rows' => [], 'subtotal' => null];
                continue;
            }
            if ($section === null) {
                continue;
            }
            if ($a === 'Sous total') {
                $sections[$blocks[$blockIndex]][$section]['subtotal'] = $r;
                $section = null;
            } elseif ($this->text($sheet, "B$r") !== '') {
                $sections[$blocks[$blockIndex]][$section]['rows'][] = $r;
            }
        }

        $listed = 0;
        foreach ($blocks as $block) {
            foreach ($sections[$block] ?? [] as $title => $info) {
                $exp = array_values($expected[$block][$title] ?? []);
                $this->check('R1', "Récapitulatif $block/$title : nombre de lignes", count($exp), count($info['rows']));
                foreach ($info['rows'] as $i => $r) {
                    $e = $exp[$i] ?? null;
                    $values = array_map(fn ($c) => $this->calc($sheet, "$c$r"), [...$assistantCols, ...$operatorCols]);
                    $this->check('R1', "Récapitulatif $block/$title ligne $r", $e ? [trim($e['code']), trim($e['name']), ...array_values($e['a']), ...array_values($e['o'])] : null,
                        [$this->text($sheet, "A$r"), $this->text($sheet, "B$r"), ...$values]);
                    if ($e !== null) {
                        $this->check('R6', "Récapitulatif $block/$title ligne $r : codes regroupés", 1, count($e['codes']));
                    }
                    $a = array_sum(array_slice($values, 0, $maxYear));
                    $o = array_sum(array_slice($values, $maxYear));
                    $pct = round($a + $o > 0 ? $o / ($a + $o) * 100 : 0, 2) . '%';
                    $this->check('R2', "Récapitulatif ligne $r totaux", [$a, $o, $a + $o, $pct],
                        [$this->calc($sheet, "$assistantTotal$r"), $this->calc($sheet, "$operatorTotal$r"), $this->calc($sheet, "$total$r"), $this->text($sheet, "$percent$r")]);
                    $listed += $a + $o;
                }
                // Sous-totaux : plage et valeur, pour chaque colonne d'année.
                $sub = $info['subtotal'];
                foreach ([...$assistantCols, ...$operatorCols] as $c) {
                    $formula = (string) $sheet->getCell("$c$sub")->getValue();
                    [$from, $to] = $this->range($formula);
                    $firstRow = $info['rows'] ? min($info['rows']) : $info['header'] + 1;
                    $lastRow = $info['rows'] ? max($info['rows']) : $info['header'] + 1;
                    $this->check('R3', "Sous total $block/$title $c$sub plage", "≤$firstRow:≥$lastRow", "$from:$to", $from <= $firstRow && $to >= $lastRow && $from > $info['header']);
                    $sum = array_sum(array_map(fn ($r) => $this->calc($sheet, "$c$r"), $info['rows']));
                    $this->check('R3', "Sous total $block/$title $c$sub", $sum, $this->calc($sheet, "$c$sub"));
                }
            }
            // Totaux généraux : références à tous les sous-totaux du bloc, et valeur.
            if (isset($grand[$block])) {
                $g = $grand[$block];
                $subRows = array_map(fn ($i) => $i['subtotal'], $sections[$block] ?? []);
                foreach ([...$assistantCols, ...$operatorCols] as $c) {
                    preg_match_all('/' . $c . '(\d+)/', (string) $sheet->getCell("$c$g")->getValue(), $m);
                    $refs = array_map('intval', $m[1]);
                    $missing = array_values(array_diff($subRows, $refs));
                    $this->check('R4', "TOTAL GENERAL $block $c$g : sous-totaux référencés", [], array_map(fn ($r) => array_search($r, $subRows, true) . " ($c$r)", $missing));
                    $expectedTotal = array_sum(array_map(fn ($r) => $this->calc($sheet, "$c$r"), $subRows));
                    $this->check('R4', "TOTAL GENERAL $block $c$g", $expectedTotal, $this->calc($sheet, "$c$g"));
                }
            }
        }

        // Total des colonnes A+O des lignes = interventions qui doivent y figurer selon la règle.
        $this->check('R5', 'Récapitulatif : interventions comptées dans les lignes', $included, $listed);
        foreach ($excluded as $reason => $n) {
            $this->check('R5', "Exclue(s) du récapitulatif : $reason", 0, $n);
        }
    }

    /*------------------------------------------------------------ Consultations */

    private function consultations(Worksheet $sheet, array $data): void
    {
        $byDate = [];
        $byMonth = [];
        $byType = [];
        foreach ($data['consultations'] as $c) {
            $day = new \DateTimeImmutable(substr($c['date'], 0, 10));
            $byDate[(int) $day->format('n')][$day->format('d-m-y')] = ($byDate[(int) $day->format('n')][$day->format('d-m-y')] ?? 0) + (int) $c['number'];
            $byMonth[(int) $day->format('n')] = ($byMonth[(int) $day->format('n')] ?? 0) + (int) $c['number'];
            $byType[(string) $c['speciality']] = ($byType[(string) $c['speciality']] ?? 0) + (int) $c['number'];
        }
        if ($data['consultations'] === []) {
            return;
        }
        $legend = $this->findColumn($sheet, 10, 'Type de consultation');
        $this->monthlyTable($sheet, $legend, $byDate, $byMonth, 'K', aggregated: true);

        // Total annuel : cellule sous les totaux mensuels (somme des 12 totaux).
        $grand = $this->grandTotalCell($sheet, 36);
        $this->check('K3', "Consultations total annuel $grand", array_sum($byMonth), $grand ? $this->calc($sheet, $grand) : null);
        // Types de consultation (libellé en $legend, valeur 2 colonnes plus loin).
        $shown = [];
        for ($r = 11; $r <= 20; $r++) {
            $label = $this->text($sheet, $this->offset($legend, 0) . $r);
            if ($label !== '') {
                $shown[$label] = (int) trim($this->text($sheet, $this->offset($legend, 2) . $r));
            }
        }
        $expectedTypes = [];
        foreach (self::CONSULTATION_TYPES as $key => $label) {
            if (($byType[$key] ?? 0) !== 0) {
                $expectedTypes[$label] = $byType[$key];
            }
        }
        $this->check('K4', 'Consultations par type', $expectedTypes, $shown);
        $others = array_diff_key($byType, self::CONSULTATION_TYPES);
        $this->check('K4', 'Consultations d\'un type non listé (absentes des types)', 0, array_sum($others));
        $this->check('K4', 'Somme des types = total annuel', array_sum($byMonth), array_sum($shown));
    }

    /*------------------------------------------------------------ Gardes */

    private function gardes(Worksheet $sheet, array $data): void
    {
        $byMonth = [];
        $items = [];
        foreach ($data['gardes'] as $g) {
            $start = new \DateTimeImmutable($g['date_of_start']);
            $m = (int) $start->format('n');
            $items[$m][] = $start->format('d-m-y') . ' ' . (int) $g['number'];
            $byMonth[$m] = ($byMonth[$m] ?? 0) + (int) $g['number'];
        }
        $legend = $this->findColumn($sheet, 10, 'Info complémentaire');
        $this->monthlyTable($sheet, $legend, $items, $byMonth, 'G', aggregated: false);

        $patientsLabel = $this->findRow($sheet, $legend, fn ($v) => $v === 'Nb patients traités', 10);
        $cell = $this->offset($legend, 2) . $patientsLabel;
        $this->check('G3', "Gardes « Nb patients traités » $cell", array_sum($byMonth), $this->calc($sheet, $cell));
        $countLabel = $this->findRow($sheet, $legend, fn ($v) => $v === 'Nb total de gardes', 10);
        $cell = $this->offset($legend, 2) . $countLabel;
        $this->check('G4', "Gardes « Nb total de gardes » $cell", count($data['gardes']), $this->calc($sheet, $cell));
    }

    /**
     * Tableau mensuel (consultations ou gardes) : une ligne de dates par mois, valeurs dessous,
     * total du mois par formule SUM. Vérifie le contenu de chaque mois et la plage du total.
     */
    private function monthlyTable(Worksheet $sheet, string $legend, array $expectedItems, array $expectedTotals, string $prefix, bool $aggregated): void
    {
        $lastCol = Coordinate::columnIndexFromString($legend) - 1;
        foreach (self::MONTH_LABELS as $label => $month) {
            $r = $this->findRow($sheet, 'A', fn ($v) => $v === $label);
            $items = [];
            $formulaCell = null;
            for ($c = 2; $c <= $lastCol; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $value = $sheet->getCell($col . ($r + 1));
                if ($value->getDataType() === 'f') {
                    $formulaCell = $col . ($r + 1);
                    continue;
                }
                $date = $this->text($sheet, $col . $r);
                if ($date !== '' && preg_match('/^\d\d-\d\d-\d\d$/', $date)) {
                    $items[] = [$date, (int) $value->getValue()];
                }
            }
            $expected = $expectedItems[$month] ?? [];
            if ($aggregated) {
                $actual = [];
                foreach ($items as [$d, $n]) {
                    $actual[$d] = $n;
                }
                ksort($actual);
                ksort($expected);
                $this->check($prefix . '1', "$label : dates et patients", $expected, $actual);
            } else {
                $actual = array_map(fn ($i) => $i[0] . ' ' . $i[1], $items);
                sort($actual);
                sort($expected);
                $this->check($prefix . '1', "$label : gardes", $expected, $actual);
            }
            $monthTotal = $expectedTotals[$month] ?? 0;
            if ($formulaCell === null) {
                $this->check($prefix . '2', "$label : formule du total mensuel", 'présente', 'absente (écrasée)');
                continue;
            }
            [$from, $to] = $this->columnRange($sheet->getCell($formulaCell)->getValue());
            $lastItemCol = 1;
            for ($c = 2; $c <= $lastCol; $c++) {
                if ($this->text($sheet, Coordinate::stringFromColumnIndex($c) . $r) !== '' && $sheet->getCell(Coordinate::stringFromColumnIndex($c) . ($r + 1))->getDataType() !== 'f') {
                    $lastItemCol = $c;
                }
            }
            $this->check($prefix . '2', "$label : plage du total $formulaCell", 'B:≥' . Coordinate::stringFromColumnIndex($lastItemCol), "$from:$to",
                $from === 'B' && Coordinate::columnIndexFromString($to) >= $lastItemCol);
            $this->check($prefix . '2', "$label : total $formulaCell", $monthTotal, $this->calc($sheet, $formulaCell));
        }
    }

    /*------------------------------------------------------------ Rapport d'activité */

    private function activityReport(Worksheet $sheet, array $data): void
    {
        $expected = [];  // [month][offset][row] => valeur
        $add = function (int $month, int $offset, int $day, int $period, int|float $n) use (&$expected) {
            $base = ($month <= 2 || $month >= 9) ? 4 : 30;
            $row = $base + ($day - 1) * 3 + $period;
            $expected[$month][$offset][$row] = ($expected[$month][$offset][$row] ?? 0) + $n;
        };
        foreach ($data['consultations'] as $c) {
            $d = new \DateTimeImmutable(substr($c['date'], 0, 10));
            $period = match ($c['day_part']) { 'afternoon' => 1, 'night' => 2, default => 0 };
            $add((int) $d->format('n'), 4, (int) $d->format('N'), $period, (int) $c['number']);
        }
        foreach ($data['currentSurgeries'] as $s) {
            $p = trim((string) $s['position']);
            if (in_array($p, ['1', '2', '3'], true)) {
                $add((int) $s['day']->format('n'), $p === '2' ? 5 : 6, (int) $s['day']->format('N'), 0, 1);
            }
        }
        $events = ['staff' => 0, 'journal' => 1, 'lesson' => 2, 'congres' => 3];
        foreach ($data['formations'] as $f) {
            if (isset($events[$f['event']])) {
                foreach ($this->halfDays($f['date_of_start'], $f['date_of_end']) as [$day, $period]) {
                    $add((int) $day->format('n'), $events[$f['event']], (int) $day->format('N'), $period, 1);
                }
            }
        }
        foreach ($data['gardes'] as $g) {
            foreach ($this->halfDays($g['date_of_start'], $g['date_of_end']) as [$day, $period]) {
                $add((int) $day->format('n'), 7, (int) $day->format('N'), $period, 1);
            }
        }

        $annual = array_fill(0, 8, 0);
        foreach (self::REPORT_MONTH_COLUMNS as $month => $startCol) {
            $base = ($month <= 2 || $month >= 9) ? 4 : 30;
            for ($offset = 0; $offset < 8; $offset++) {
                $col = $this->offset($startCol, $offset);
                $rule = match (true) { $offset === 4 => 'A1', $offset === 5 || $offset === 6 => 'A2', default => 'A3' };
                $values = [];
                $exp = [];
                for ($row = $base; $row < $base + 21; $row++) {
                    $v = $this->calc($sheet, "$col$row");
                    $values[$row] = $v ?? 0;
                    $exp[$row] = $expected[$month][$offset][$row] ?? 0;
                }
                $this->check($rule, "Rapport mois $month colonne $col (lignes $base-" . ($base + 20) . ')', $exp, $values);
                $totalRow = $base + 21;
                $this->check('A4', "Rapport total mois $month $col$totalRow", array_sum($values), $this->calc($sheet, "$col$totalRow"));
                $annual[$offset] += array_sum($exp);
            }
        }
        // Total annuel (ligne 52, colonnes AD..AK).
        for ($offset = 0; $offset < 8; $offset++) {
            $col = $this->offset('AD', $offset);
            $this->check('A5', "Rapport total annuel $col" . '52', $annual[$offset], $this->calc($sheet, $col . '52'));
        }
    }

    /**
     * Demi-journées occupées par un évènement : (jour, 0 matin | 1 après-midi | 2 soir), une
     * demi-journée comptant si l'évènement la chevauche. Calcul par parcours réel du temps.
     *
     * @return list<array{0: \DateTimeImmutable, 1: int}>
     */
    private function halfDays(string $start, string $end): array
    {
        $start = new \DateTimeImmutable($start);
        $end = new \DateTimeImmutable($end);
        $slots = [];
        for ($day = $start->setTime(0, 0); $day <= $end; $day = $day->modify('+1 day')) {
            foreach ([[0, 12], [12, 18], [18, 24]] as $period => [$from, $to]) {
                $slotStart = $day->setTime($from, 0);
                $slotEnd = $to === 24 ? $day->modify('+1 day') : $day->setTime($to, 0);
                if ($start < $slotEnd && $end > $slotStart) {
                    $slots[] = [$day, $period];
                }
            }
        }

        return $slots;
    }

    /*------------------------------------------------------------ Évaluation du candidat */

    private function evaluation(Worksheet $sheet, array $data): void
    {
        $hospital = $data['current']['hospital'];
        $expected = [0 => [], 1 => [], 2 => []];
        foreach ($data['formations'] as $f) {
            $section = $f['role'] !== 'participant' ? 2 : ($f['location'] === $hospital ? 0 : 1);
            $day = (new \DateTimeImmutable($f['date_of_start']))->setTime(0, 0);
            $last = (new \DateTimeImmutable($f['date_of_end']))->setTime(0, 0);
            for (; $day <= $last; $day = $day->modify('+1 day')) {
                $expected[$section][] = $day->format('d-m-Y') . ' ' . trim((string) $f['name']);
            }
        }
        $all = array_merge(...$expected);
        $actual = [];
        for ($r = 88, $max = $sheet->getHighestRow(); $r <= $max; $r++) {
            $date = $this->text($sheet, "A$r");
            if (preg_match('/^\d\d-\d\d-\d{4}$/', $date)) {
                $actual[] = $date . ' ' . trim($this->text($sheet, "I$r"));
            }
        }
        sort($all);
        sort($actual);
        $this->check('E1', 'Évaluation : jours de formation listés', $all, $actual);
    }

    /*------------------------------------------------------------ Outils */

    private function check(string $rule, string $where, mixed $expected, mixed $actual, ?bool $ok = null, float $tolerance = 0.0): void
    {
        if ($ok === null) {
            if (is_float($expected) || is_float($actual)) {
                $ok = $expected !== null && $actual !== null && abs((float) $expected - (float) $actual) <= max($tolerance, 1e-9);
            } else {
                $ok = $this->normalize($expected) === $this->normalize($actual);
            }
        }
        $this->findings[] = ['rule' => $rule, 'ok' => $ok, 'where' => $where, 'expected' => $expected, 'actual' => $actual];
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->normalize($v), $value);
        }
        if (is_numeric($value)) {
            return (string) (0 + $value);
        }

        return $value === null ? '' : (string) $value;
    }

    private function text(Worksheet $sheet, string $cell): string
    {
        if (!$sheet->cellExists($cell)) {
            return '';
        }
        $value = $sheet->getCell($cell)->getValue();

        return trim($value instanceof RichText ? $value->getPlainText() : (string) $value);
    }

    private function calc(Worksheet $sheet, string $cell): mixed
    {
        if (!$sheet->cellExists($cell)) {
            return null;
        }
        $value = $sheet->getCell($cell)->getCalculatedValue();
        if (is_string($value) && is_numeric(trim($value))) {
            return 0 + trim($value);
        }

        return $value === '' ? null : $value;
    }

    private function findRow(Worksheet $sheet, string $col, callable $match, int $after = 0): int
    {
        for ($r = $after + 1, $max = $sheet->getHighestRow(); $r <= $max; $r++) {
            if ($sheet->cellExists("$col$r") && $match($this->text($sheet, "$col$r"), $sheet->getCell("$col$r"))) {
                return $r;
            }
        }
        throw new \RuntimeException("Ligne introuvable en colonne $col");
    }

    private function findColumn(Worksheet $sheet, int $row, string $label): string
    {
        $max = Coordinate::columnIndexFromString($sheet->getHighestColumn());
        for ($c = 1; $c <= $max; $c++) {
            if ($this->text($sheet, Coordinate::stringFromColumnIndex($c) . $row) === $label) {
                return Coordinate::stringFromColumnIndex($c);
            }
        }
        throw new \RuntimeException("Libellé introuvable : $label");
    }

    private function grandTotalCell(Worksheet $sheet, int $row): ?string
    {
        foreach ($sheet->getRowIterator($row, $row)->current()->getCellIterator() as $cell) {
            if ($cell->getDataType() === 'f') {
                return $cell->getCoordinate();
            }
        }

        return null;
    }

    private function offset(string $col, int $n): string
    {
        return Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($col) + $n);
    }

    /** @return array{0: int, 1: int} lignes de la première plage A1:A9 d'une formule */
    private function range(string $formula): array
    {
        return preg_match('/[A-Z]+(\d+):[A-Z]+(\d+)/', $formula, $m) ? [(int) $m[1], (int) $m[2]] : [0, 0];
    }

    /** @return array{0: string, 1: string} colonnes de la première plage B12:J12 d'une formule */
    private function columnRange(string $formula): array
    {
        return preg_match('/([A-Z]+)\d+:([A-Z]+)\d+/', $formula, $m) ? [$m[1], $m[2]] : ['', ''];
    }
}
