<?php

namespace App\Tests\Excel;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Compare deux fichiers XLSX sans comparaison binaire (l'ordre et les dates internes du ZIP
 * peuvent varier). Deux niveaux, tous deux exigés :
 *
 * 1. Paquet : mêmes parties ; chaque partie identique octet par octet, ou après canonisation XML
 *    (C14N). Seules les dates de création/modification de docProps/core.xml sont tolérées.
 * 2. Sémantique (lecture PhpSpreadsheet) : feuilles (noms, ordre, état), chaque cellule (valeur,
 *    type, formule, style résolu : police, remplissage, bordures, alignement, format, protection),
 *    fusions, dimensions des lignes/colonnes, mise en page et impression, volets, protections,
 *    validations, mises en forme conditionnelles, filtres, liens, commentaires, dessins, noms
 *    définis et propriétés du classeur.
 */
final class XlsxComparator
{
    private const VOLATILE_CORE = ['created', 'modified'];
    private const MAX_REPORTED = 40;

    /** @var list<string> */
    private array $differences = [];
    /** @var array<string, int> */
    private array $stats = [];

    /**
     * @return array{equivalent: bool, differences: list<string>, stats: array<string, int>, toleratedVolatile: list<string>}
     */
    public function compare(string $expectedPath, string $actualPath): array
    {
        $this->differences = [];
        $this->stats = ['parties' => 0, 'partiesIdentiquesOctet' => 0, 'partiesIdentiquesC14N' => 0, 'feuilles' => 0,
            'cellules' => 0, 'fusions' => 0, 'lignesDimensionnees' => 0, 'colonnesDimensionnees' => 0, 'dessins' => 0];
        $tolerated = $this->comparePackage($expectedPath, $actualPath);

        $reader = IOFactory::createReader('Xlsx');
        $this->compareWorkbook($reader->load($expectedPath), $reader->load($actualPath));

        return [
            'equivalent' => $this->differences === [],
            'differences' => array_slice($this->differences, 0, self::MAX_REPORTED),
            'stats' => $this->stats,
            'toleratedVolatile' => $tolerated,
        ];
    }

    /**
     * @return list<string> métadonnées volatiles tolérées
     */
    private function comparePackage(string $expectedPath, string $actualPath): array
    {
        $expected = $this->readZip($expectedPath);
        $actual = $this->readZip($actualPath);
        $tolerated = [];

        foreach (array_diff(array_keys($expected), array_keys($actual)) as $missing) {
            $this->diff("Paquet : partie absente du fichier produit : $missing");
        }
        foreach (array_diff(array_keys($actual), array_keys($expected)) as $extra) {
            $this->diff("Paquet : partie en trop dans le fichier produit : $extra");
        }

        foreach (array_intersect_key($expected, $actual) as $name => $content) {
            ++$this->stats['parties'];
            if ($content === $actual[$name]) {
                ++$this->stats['partiesIdentiquesOctet'];
                continue;
            }
            if (!preg_match('/\.(xml|rels|vml)$/', $name)) {
                $this->diff("Paquet : partie binaire différente : $name");
                continue;
            }
            $a = $this->canonical($content, $volatileA);
            $b = $this->canonical($actual[$name], $volatileB);
            if ($a === $b) {
                ++$this->stats['partiesIdentiquesC14N'];
                if ($volatileA !== $volatileB) {
                    $tolerated[] = "$name : " . implode(', ', array_keys(array_diff_assoc($volatileB, $volatileA)));
                }
                continue;
            }
            $this->diff("Paquet : contenu XML différent dans $name : " . $this->firstDifference($a, $b));
        }

        return $tolerated;
    }

    /**
     * @return array<string, string>
     */
    private function readZip(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Archive XLSX illisible : $path");
        }
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $parts[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        ksort($parts);

        return $parts;
    }

    private function canonical(string $xml, ?array &$volatile = null): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        $volatile = [];
        // Seules les dates de docProps/core.xml (création/modification) sont tolérées.
        if ($dom->documentElement?->localName === 'coreProperties') {
            foreach (iterator_to_array($dom->documentElement->childNodes) as $node) {
                if ($node instanceof \DOMElement && in_array($node->localName, self::VOLATILE_CORE, true)) {
                    $volatile[$node->localName] = $node->textContent;
                    $node->parentNode->removeChild($node);
                }
            }
        }

        return $dom->C14N();
    }

    private function firstDifference(string $a, string $b): string
    {
        $length = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $length && $a[$i] === $b[$i]) {
            ++$i;
        }
        $start = max(0, $i - 60);

        return sprintf("octet %d ; attendu « …%s… » ; obtenu « …%s… »", $i, substr($a, $start, 140), substr($b, $start, 140));
    }

    private function compareWorkbook(Spreadsheet $a, Spreadsheet $b): void
    {
        $this->same('Classeur : noms des feuilles', $a->getSheetNames(), $b->getSheetNames());
        $this->same('Classeur : feuille active', $a->getActiveSheetIndex(), $b->getActiveSheetIndex());
        $this->same('Classeur : propriétés', $this->properties($a), $this->properties($b));
        $this->same('Classeur : protection', (array) $a->getSecurity(), (array) $b->getSecurity());
        $this->same('Classeur : noms définis', $this->definedNames($a), $this->definedNames($b));

        foreach ($a->getWorksheetIterator() as $index => $sheetA) {
            $sheetB = $b->getSheet($index);
            ++$this->stats['feuilles'];
            $this->compareSheet($sheetA->getTitle(), $sheetA, $sheetB);
        }
    }

    private function compareSheet(string $title, Worksheet $a, Worksheet $b): void
    {
        $p = "Feuille « $title »";
        $this->same("$p : état", $a->getSheetState(), $b->getSheetState());
        $this->same("$p : couleur d'onglet", $a->getTabColor()->getARGB(), $b->getTabColor()->getARGB());
        $this->same("$p : dimension", $a->calculateWorksheetDimension(), $b->calculateWorksheetDimension());

        // Cellules : union des coordonnées, valeur brute (formule comprise), type et style résolu.
        $coordinates = array_unique([...$a->getCoordinates(false), ...$b->getCoordinates(false)]);
        $styleCacheA = $styleCacheB = [];
        foreach ($coordinates as $coordinate) {
            ++$this->stats['cellules'];
            $ca = $a->getCellCollection()->get($coordinate);
            $cb = $b->getCellCollection()->get($coordinate);
            if ($ca === null || $cb === null) {
                $this->diff("$p $coordinate : cellule présente d'un seul côté (" . ($ca === null ? 'attendu absente' : 'obtenu absente') . ')');
                continue;
            }
            $va = $this->cellValue($ca->getValue());
            $vb = $this->cellValue($cb->getValue());
            if ($va !== $vb || $ca->getDataType() !== $cb->getDataType()) {
                $this->diff(sprintf('%s %s : valeur attendue %s (%s), obtenue %s (%s)', $p, $coordinate,
                    $this->short($va), $ca->getDataType(), $this->short($vb), $cb->getDataType()));
            }
            $sa = $styleCacheA[$ca->getXfIndex()] ??= $this->styleSignature($a->getParent()->getCellXfByIndex($ca->getXfIndex()));
            $sb = $styleCacheB[$cb->getXfIndex()] ??= $this->styleSignature($b->getParent()->getCellXfByIndex($cb->getXfIndex()));
            if ($sa !== $sb) {
                $this->diff("$p $coordinate : style différent : " . $this->arrayDiff($sa, $sb));
            }
        }

        $mergesA = array_values($a->getMergeCells());
        $mergesB = array_values($b->getMergeCells());
        $this->stats['fusions'] += count($mergesA);
        $this->same("$p : cellules fusionnées (ensemble)", $this->sorted($mergesA), $this->sorted($mergesB));
        $this->same("$p : cellules fusionnées (ordre)", $mergesA, $mergesB);

        $this->same("$p : dimensions par défaut", $this->dimension($a->getDefaultColumnDimension()) + $this->dimension($a->getDefaultRowDimension()),
            $this->dimension($b->getDefaultColumnDimension()) + $this->dimension($b->getDefaultRowDimension()));
        $columnsA = array_map(fn ($d) => $this->dimension($d), $a->getColumnDimensions());
        $columnsB = array_map(fn ($d) => $this->dimension($d), $b->getColumnDimensions());
        $this->stats['colonnesDimensionnees'] += count($columnsA);
        $this->same("$p : largeurs et propriétés des colonnes", $columnsA, $columnsB);
        $rowsA = array_map(fn ($d) => $this->dimension($d), $a->getRowDimensions());
        $rowsB = array_map(fn ($d) => $this->dimension($d), $b->getRowDimensions());
        ksort($rowsA);
        ksort($rowsB);
        $this->stats['lignesDimensionnees'] += count($rowsA);
        $this->same("$p : hauteurs et propriétés des lignes", $rowsA, $rowsB);

        $this->same("$p : mise en page", $this->object($a->getPageSetup()), $this->object($b->getPageSetup()));
        $this->same("$p : marges", $this->object($a->getPageMargins()), $this->object($b->getPageMargins()));
        $this->same("$p : en-têtes et pieds de page", $this->object($a->getHeaderFooter(), ['headerFooterImages']), $this->object($b->getHeaderFooter(), ['headerFooterImages']));
        $this->same("$p : sauts de page", array_map(fn ($x) => $this->object($x), $a->getBreaks()), array_map(fn ($x) => $this->object($x), $b->getBreaks()));
        $this->same("$p : affichage", $this->view($a), $this->view($b));
        $this->same("$p : protection", $this->object($a->getProtection()), $this->object($b->getProtection()));
        $this->same("$p : plages protégées", $this->object($a->getProtectedCells()), $this->object($b->getProtectedCells()));
        $this->same("$p : filtre automatique", $a->getAutoFilter()->getRange(), $b->getAutoFilter()->getRange());
        $this->same("$p : validations de données", array_map(fn ($v) => $this->object($v), $a->getDataValidationCollection()),
            array_map(fn ($v) => $this->object($v), $b->getDataValidationCollection()));
        $this->same("$p : mises en forme conditionnelles", $this->conditional($a), $this->conditional($b));
        $this->same("$p : liens hypertextes", array_map(fn ($l) => [$l->getUrl(), $l->getTooltip()], $a->getHyperlinkCollection()),
            array_map(fn ($l) => [$l->getUrl(), $l->getTooltip()], $b->getHyperlinkCollection()));
        $this->same("$p : commentaires", array_map(fn ($c) => (string) $c->getText(), $a->getComments()), array_map(fn ($c) => (string) $c->getText(), $b->getComments()));
        $this->same("$p : dessins", $this->drawings($a), $this->drawings($b));
        $this->same("$p : tableaux", array_map(fn ($t) => [$t->getName(), $t->getRange()], iterator_to_array($a->getTableCollection())),
            array_map(fn ($t) => [$t->getName(), $t->getRange()], iterator_to_array($b->getTableCollection())));
    }

    /**
     * Valeur comparable : scalaire tel quel ; texte enrichi = suite de [texte, police].
     */
    private function cellValue(mixed $value): mixed
    {
        if (!$value instanceof RichText) {
            return $value;
        }
        $runs = [];
        foreach ($value->getRichTextElements() as $element) {
            $font = $element->getFont();
            $runs[] = [$element->getText(), $font === null ? null : [$font->getName(), $font->getSize(), $font->getBold(),
                $font->getItalic(), $font->getUnderline(), $font->getStrikethrough(), $font->getSuperscript(),
                $font->getSubscript(), $font->getColor()->getARGB()]];
        }

        return ['texteEnrichi' => $runs];
    }

    private function styleSignature(Style $style): array
    {
        $font = $style->getFont();
        $fill = $style->getFill();
        $alignment = $style->getAlignment();
        $border = fn ($b) => [$b->getBorderStyle(), $b->getColor()->getARGB()];
        $borders = $style->getBorders();

        return [
            'font' => [$font->getName(), $font->getSize(), $font->getBold(), $font->getItalic(), $font->getUnderline(),
                $font->getStrikethrough(), $font->getSuperscript(), $font->getSubscript(), $font->getColor()->getARGB(), $font->getScheme()],
            'fill' => [$fill->getFillType(), $fill->getRotation(), $fill->getStartColor()->getARGB(), $fill->getEndColor()->getARGB()],
            'borders' => [$border($borders->getLeft()), $border($borders->getRight()), $border($borders->getTop()),
                $border($borders->getBottom()), $border($borders->getDiagonal()), $borders->getDiagonalDirection()],
            'alignment' => [$alignment->getHorizontal(), $alignment->getVertical(), $alignment->getWrapText(), $alignment->getShrinkToFit(),
                $alignment->getIndent(), $alignment->getTextRotation(), $alignment->getReadOrder()],
            'numberFormat' => $style->getNumberFormat()->getFormatCode(),
            'protection' => [$style->getProtection()->getLocked(), $style->getProtection()->getHidden()],
            'quotePrefix' => $style->getQuotePrefix(),
        ];
    }

    private function dimension(object $dimension): array
    {
        $data = ['visible' => $dimension->getVisible(), 'outline' => $dimension->getOutlineLevel(),
            'collapsed' => $dimension->getCollapsed(), 'xf' => $dimension->getXfIndex()];
        if (method_exists($dimension, 'getWidth')) {
            $data += ['width' => $dimension->getWidth(), 'autoSize' => $dimension->getAutoSize()];
        } else {
            $data += ['height' => $dimension->getRowHeight(), 'zeroHeight' => $dimension->getZeroHeight()];
        }

        return $data;
    }

    private function view(Worksheet $sheet): array
    {
        return [
            'freezePane' => $sheet->getFreezePane(), 'topLeft' => $sheet->getTopLeftCell(),
            'selected' => $sheet->getSelectedCells(), 'gridlines' => $sheet->getShowGridlines(),
            'printGridlines' => $sheet->getPrintGridlines(), 'rowColHeaders' => $sheet->getShowRowColHeaders(),
            'rightToLeft' => $sheet->getRightToLeft(), 'summaryBelow' => $sheet->getShowSummaryBelow(),
            'summaryRight' => $sheet->getShowSummaryRight(), 'sheetView' => $this->object($sheet->getSheetView()),
            'codeName' => $sheet->getCodeName(),
        ];
    }

    private function conditional(Worksheet $sheet): array
    {
        $result = [];
        foreach ($sheet->getConditionalStylesCollection() as $range => $conditionals) {
            foreach ($conditionals as $conditional) {
                $result[$range][] = [$conditional->getConditionType(), $conditional->getOperatorType(), $conditional->getConditions(),
                    $conditional->getText(), $conditional->getStopIfTrue(), $this->styleSignature($conditional->getStyle())];
            }
        }

        return $result;
    }

    private function drawings(Worksheet $sheet): array
    {
        $result = [];
        foreach ($sheet->getDrawingCollection() as $drawing) {
            ++$this->stats['dessins'];
            $data = [get_class($drawing), $drawing->getName(), $drawing->getDescription(), $drawing->getCoordinates(),
                $drawing->getCoordinates2(), $drawing->getOffsetX(), $drawing->getOffsetY(), $drawing->getWidth(),
                $drawing->getHeight(), $drawing->getRotation()];
            if (method_exists($drawing, 'getPath') && is_file($drawing->getPath())) {
                $data[] = hash_file('sha256', $drawing->getPath());
            }
            $result[] = $data;
        }

        return $result;
    }

    private function definedNames(Spreadsheet $spreadsheet): array
    {
        $result = [];
        foreach ($spreadsheet->getDefinedNames() as $name) {
            $result[] = [$name->getName(), $name->getScope()?->getTitle(), $name->getValue(), $name->getLocalOnly(), $name->getWorksheet()?->getTitle()];
        }

        return $result;
    }

    private function properties(Spreadsheet $spreadsheet): array
    {
        $p = $spreadsheet->getProperties();

        return [$p->getCreator(), $p->getLastModifiedBy(), $p->getTitle(), $p->getSubject(), $p->getDescription(),
            $p->getKeywords(), $p->getCategory(), $p->getCompany(), $p->getManager(), $p->getCustomProperties()];
    }

    /**
     * Propriétés scalaires d'un objet (y compris privées), récursivement.
     */
    private function object(mixed $value, array $skip = [], int $depth = 0): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->object($v, $skip, $depth + 1), $value);
        }
        if (!is_object($value) || $depth > 4) {
            return $value;
        }
        $result = ['@' => get_class($value)];
        foreach ((array) $value as $key => $v) {
            $key = preg_replace('/^\0[^\0]+\0/', '', $key);
            if (in_array($key, $skip, true) || $v instanceof Worksheet || $v instanceof Spreadsheet) {
                continue;
            }
            $result[$key] = $this->object($v, $skip, $depth + 1);
        }

        return $result;
    }

    private function same(string $label, mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            $this->diff("$label : " . $this->arrayDiff($expected, $actual));
        }
    }

    private function arrayDiff(mixed $expected, mixed $actual): string
    {
        if (is_array($expected) && is_array($actual)) {
            foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
                if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                    return "[$key] attendu " . $this->short($expected[$key] ?? null) . ', obtenu ' . $this->short($actual[$key] ?? null);
                }
            }
            if (array_keys($expected) !== array_keys($actual)) {
                return 'ordre différent';
            }
        }

        return 'attendu ' . $this->short($expected) . ', obtenu ' . $this->short($actual);
    }

    private function short(mixed $value): string
    {
        $text = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return strlen($text) > 200 ? substr($text, 0, 200) . '…' : $text;
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function diff(string $message): void
    {
        $this->differences[] = $message;
    }
}
