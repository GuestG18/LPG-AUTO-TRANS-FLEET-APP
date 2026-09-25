<?php
declare(strict_types=1);

/*
 * Scriitor XLSX minimal (fara dependinte, doar ZipArchive): foi multiple, stiluri
 * predefinite, latimi de coloana, celule unite si grupare pe randuri (outline),
 * ca randurile desfasurabile din pagini sa ramana desfasurabile si in Excel.
 *
 * Randurile se adauga cu addRow(sheet, cells, options); o celula este fie o valoare
 * simpla, fie ['v' => valoare, 's' => stil]. Valorile numerice raman numerice
 * (formatul de afisare vine din stil), deci se pot aduna in Excel.
 */
class SimpleXlsxWriter
{
    /* Nume de stil => index in cellXfs (vezi stylesXml()). */
    public const STYLES = [
        'default' => 0,
        'title' => 1,
        'muted' => 2,
        'section' => 3,
        'th' => 4,
        'th_num' => 5,
        'text' => 6,
        'int' => 7,
        'money' => 8,
        'group' => 9,
        'group_int' => 10,
        'group_money' => 11,
        'total' => 12,
        'total_int' => 13,
        'total_money' => 14,
        'sub_th' => 15,
        'sub_th_num' => 16,
        'text_l1' => 17,
        'text_l2' => 18,
        'small' => 19,
        'small_int' => 20,
        'small_money' => 21,
        'percent' => 22,
        'group_percent' => 23,
        'total_percent' => 24,
        'num2' => 25,
        'group_num2' => 26,
        'total_num2' => 27,
        'text_num' => 28,
        'group_text_num' => 29,
        'total_text_num' => 30,
        'small_text_num' => 31,
        'kpi_label' => 32,
        'kpi_value' => 33,
        'code' => 34,
        'warn' => 35,
        'small_num2' => 36,
        'text_l3' => 37,
    ];

    /** @var array<int, array{name: string, rows: array<int, array>, widths: array<int, float>, merges: string[], freeze: ?string}> */
    private array $sheets = [];

    /**
     * Stiluri numerice adaugate la rulare (ex. "#,##0\" km\""): nume => [cod format, varianta].
     * Varianta: 'normal' | 'small' (randuri de detaliu) | 'group' | 'total'.
     * @var array<string, array{0: string, 1: string}>
     */
    private array $customStyles = [];

    /*
     * Un stil numeric cu formatul dat; numele intors se foloseste ca 's' in celula.
     * Valoarea ramane numar (se poate aduna / folosi in formule), doar afisarea are unitatea.
     */
    public function numberStyle(string $formatCode, string $variant = 'normal'): string
    {
        $name = 'fmt:' . $variant . ':' . $formatCode;
        $this->customStyles[$name] = [$formatCode, $variant];

        return $name;
    }

    /* Numarul randului care va fi adaugat urmator (pentru formule care il refera). */
    public function nextRowNumber(int $sheet): int
    {
        return count($this->sheets[$sheet]['rows']) + 1;
    }

    public function addSheet(string $name): int
    {
        /* Excel: max 31 caractere, fara : \ / ? * [ ] */
        $clean = mb_substr(preg_replace('/[:\\\\\/\?\*\[\]]/u', ' ', $name) ?? 'Foaie', 0, 31);
        $this->sheets[] = ['name' => $clean, 'rows' => [], 'widths' => [], 'merges' => [], 'freeze' => null];

        return count($this->sheets) - 1;
    }

    /** @param array<int, float> $widths coloana (1-based) => latime */
    public function setColumnWidths(int $sheet, array $widths): void
    {
        $this->sheets[$sheet]['widths'] = $widths;
    }

    public function freezeRows(int $sheet, int $rows): void
    {
        $this->sheets[$sheet]['freeze'] = 'A' . ($rows + 1);
    }

    /**
     * @param array<int, mixed> $cells
     * @param array{level?: int, hidden?: bool, collapsed?: bool, height?: float, merge?: int} $options
     *        merge = numarul de coloane unite incepand cu prima celula
     * @return int numarul randului (1-based)
     */
    public function addRow(int $sheet, array $cells = [], array $options = []): int
    {
        $this->sheets[$sheet]['rows'][] = ['cells' => array_values($cells), 'options' => $options];
        $rowNumber = count($this->sheets[$sheet]['rows']);
        if (($options['merge'] ?? 0) > 1) {
            $this->sheets[$sheet]['merges'][] = 'A' . $rowNumber . ':' . self::columnLetter((int) $options['merge']) . $rowNumber;
        }

        return $rowNumber;
    }

    /* Inlocuieste celulele unui rand deja adaugat (ex. o formula care trimite la un rand scris ulterior). */
    public function replaceRowCells(int $sheet, int $rowNumber, array $cells): void
    {
        if (isset($this->sheets[$sheet]['rows'][$rowNumber - 1])) {
            $this->sheets[$sheet]['rows'][$rowNumber - 1]['cells'] = array_values($cells);
        }
    }

    /* Marcheaza randul-parinte ca restrans (butonul +/- din Excel). */
    public function markCollapsed(int $sheet, int $rowNumber): void
    {
        if (isset($this->sheets[$sheet]['rows'][$rowNumber - 1])) {
            $this->sheets[$sheet]['rows'][$rowNumber - 1]['options']['collapsed'] = true;
        }
    }

    public function save(string $path): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Extensia PHP zip nu este disponibilă.');
        }
        if ($this->sheets === []) {
            $this->addSheet('Foaie1');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Nu pot crea fișierul Excel.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        foreach ($this->sheets as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->sheetXml($sheet));
        }
        $zip->close();
    }

    public static function columnLetter(int $column): string
    {
        $letters = '';
        while ($column > 0) {
            $mod = ($column - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $column = intdiv($column - 1, 26);
        }

        return $letters;
    }

    private static function xml(string $value): string
    {
        /* Caracterele de control nu sunt permise in XML. */
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach (array_keys($this->sheets) as $index) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    private function workbookXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<sheet name="' . self::xml($sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        /* Formulele se recalculeaza la deschidere (valorile scrise sunt doar cache). */
        return $xml . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach (array_keys($this->sheets) as $index) {
            $xml .= '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }
        $stylesId = count($this->sheets) + 1;

        return $xml . '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function sheetXml(array $sheet): string
    {
        $maxLevel = 0;
        foreach ($sheet['rows'] as $row) {
            $maxLevel = max($maxLevel, (int) ($row['options']['level'] ?? 0));
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            /* Butonul +/- pe randul-parinte (deasupra detaliilor), ca in pagina. */
            . '<sheetPr><outlinePr summaryBelow="0" summaryRight="0"/></sheetPr>';

        $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ($sheet['freeze'] !== null) {
            $rowsFrozen = (int) substr($sheet['freeze'], 1) - 1;
            $xml .= '<pane ySplit="' . $rowsFrozen . '" topLeftCell="' . $sheet['freeze'] . '" activePane="bottomLeft" state="frozen"/>';
        }
        $xml .= '</sheetView></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="15"' . ($maxLevel > 0 ? ' outlineLevelRow="' . $maxLevel . '"' : '') . '/>';

        if ($sheet['widths'] !== []) {
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $column => $width) {
                $xml .= '<col min="' . $column . '" max="' . $column . '" width="' . number_format((float) $width, 2, '.', '') . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($sheet['rows'] as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $options = $row['options'];
            $attrs = ' r="' . $rowNumber . '"';
            if (($options['level'] ?? 0) > 0) {
                $attrs .= ' outlineLevel="' . (int) $options['level'] . '"';
            }
            if (!empty($options['hidden'])) {
                $attrs .= ' hidden="1"';
            }
            if (!empty($options['collapsed'])) {
                $attrs .= ' collapsed="1"';
            }
            if (isset($options['height'])) {
                $attrs .= ' ht="' . number_format((float) $options['height'], 2, '.', '') . '" customHeight="1"';
            }
            $xml .= '<row' . $attrs . '>';
            foreach ($row['cells'] as $colIndex => $cell) {
                $xml .= $this->cellXml(self::columnLetter($colIndex + 1) . $rowNumber, $cell, $rowNumber);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if ($sheet['merges'] !== []) {
            $xml .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $range) {
                $xml .= '<mergeCell ref="' . $range . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        $xml .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>';

        return $xml . '</worksheet>';
    }

    private function styleIndex(string $name): int
    {
        if (isset(self::STYLES[$name])) {
            return self::STYLES[$name];
        }
        $position = array_search($name, array_keys($this->customStyles), true);

        return $position === false ? 0 : count(self::STYLES) + (int) $position;
    }

    /*
     * Celula: valoare simpla sau ['v' => valoare, 's' => stil, 'f' => formula].
     * In formula, "{r}" este inlocuit cu numarul randului celulei (ex. "A{r}*B{r}");
     * 'v' ramane valoarea calculata afisata pana la recalcularea din Excel.
     */
    private function cellXml(string $ref, mixed $cell, int $rowNumber = 0): string
    {
        $style = 0;
        $value = $cell;
        $formula = null;
        if (is_array($cell)) {
            $value = $cell['v'] ?? null;
            $style = $this->styleIndex((string) ($cell['s'] ?? 'default'));
            $formula = isset($cell['f']) && $cell['f'] !== '' ? str_replace('{r}', (string) $rowNumber, (string) $cell['f']) : null;
        }
        $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';

        if ($formula !== null) {
            $cached = is_int($value) || is_float($value) ? '<v>' . (is_finite((float) $value) ? rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.') : '0') . '</v>' : '';

            return '<c r="' . $ref . '"' . $styleAttr . '><f>' . self::xml(ltrim($formula, '=')) . '</f>' . $cached . '</c>';
        }

        if ($value === null || $value === '') {
            return $style > 0 ? '<c r="' . $ref . '"' . $styleAttr . '/>' : '';
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) {
                $value = 0;
            }

            return '<c r="' . $ref . '"' . $styleAttr . '><v>' . (is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.')) . '</v></c>';
        }

        return '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t xml:space="preserve">' . self::xml((string) $value) . '</t></is></c>';
    }

    private function stylesXml(): string
    {
        /*
         * Formate numerice: Excel afiseaza separatorii dupa setarile regionale, deci
         * in Excel-ul romanesc 57030 apare ca 57.030 si 83471,4 ca 83.471,40 - ca in pagina.
         */
        $numFmts = [
            164 => '#,##0',
            165 => '#,##0.00',
            166 => '0.00"%"',
        ];
        /* 0 Calibri 11, 1 bold, 2 titlu, 3 gri, 4 alb bold, 5 gri mic, 6 albastru bold, 7 bold 12, 8 portocaliu */
        $fonts = [
            '<font><sz val="10"/><color rgb="FF1F2937"/><name val="Calibri"/><family val="2"/></font>',
            '<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/><family val="2"/></font>',
            '<font><b/><sz val="16"/><color rgb="FF0F172A"/><name val="Calibri"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/><family val="2"/></font>',
            '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>',
            '<font><sz val="9"/><color rgb="FF475569"/><name val="Calibri"/><family val="2"/></font>',
            '<font><b/><sz val="9"/><color rgb="FF1D4ED8"/><name val="Calibri"/><family val="2"/></font>',
            '<font><b/><sz val="14"/><color rgb="FF0F172A"/><name val="Calibri"/><family val="2"/></font>',
            '<font><i/><sz val="9"/><color rgb="FFB45309"/><name val="Calibri"/><family val="2"/></font>',
        ];
        /* 0/1 obligatorii, 2 sectiune (albastru inchis), 3 antet gri, 4 grup albastru deschis, 5 total, 6 sub-antet, 7 detaliu, 8 cod ruta */
        $fills = [
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FE"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFFCFCFD"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>',
        ];
        /* 0 fara, 1 linie jos fina, 2 linie jos + sus (total), 3 contur fin */
        $borders = [
            '<border><left/><right/><top/><bottom/><diagonal/></border>',
            '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>',
            '<border><left/><right/><top style="thin"><color rgb="FF94A3B8"/></top><bottom style="thin"><color rgb="FF94A3B8"/></bottom><diagonal/></border>',
            '<border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>',
        ];

        $xf = static function (int $font, int $fill, int $border, int $numFmt = 0, string $align = '', int $indent = 0, bool $wrap = false): string {
            $applies = ' applyFont="1"' . ($fill > 0 ? ' applyFill="1"' : '') . ($border > 0 ? ' applyBorder="1"' : '') . ($numFmt > 0 ? ' applyNumberFormat="1"' : '');
            $alignment = '';
            if ($align !== '' || $indent > 0 || $wrap) {
                $alignment = '<alignment vertical="center"' . ($align !== '' ? ' horizontal="' . $align . '"' : '')
                    . ($indent > 0 ? ' indent="' . $indent . '"' : '') . ($wrap ? ' wrapText="1"' : '') . '/>';
                $applies .= ' applyAlignment="1"';
            }

            return '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"' . $applies . '>' . $alignment . '</xf>';
        };

        /* Ordinea trebuie sa corespunda cu self::STYLES. */
        $cellXfs = [
            $xf(0, 0, 0),                          // default
            $xf(2, 0, 0),                          // title
            $xf(3, 0, 0),                          // muted
            $xf(4, 2, 0, 0, 'left', 1),            // section
            $xf(1, 3, 1, 0, 'left'),               // th
            $xf(1, 3, 1, 0, 'right'),              // th_num
            $xf(0, 0, 1, 0, 'left'),               // text
            $xf(0, 0, 1, 164, 'right'),            // int
            $xf(0, 0, 1, 165, 'right'),            // money
            $xf(1, 4, 1, 0, 'left'),               // group
            $xf(1, 4, 1, 164, 'right'),            // group_int
            $xf(1, 4, 1, 165, 'right'),            // group_money
            $xf(1, 5, 2, 0, 'left'),               // total
            $xf(1, 5, 2, 164, 'right'),            // total_int
            $xf(1, 5, 2, 165, 'right'),            // total_money
            $xf(1, 6, 1, 0, 'left', 1),            // sub_th
            $xf(1, 6, 1, 0, 'right'),              // sub_th_num
            $xf(0, 0, 1, 0, 'left', 1),            // text_l1
            $xf(5, 7, 1, 0, 'left', 3),            // text_l2
            $xf(5, 7, 1, 0, 'left'),               // small
            $xf(5, 7, 1, 164, 'right'),            // small_int
            $xf(5, 7, 1, 165, 'right'),            // small_money
            $xf(0, 0, 1, 166, 'right'),            // percent
            $xf(1, 4, 1, 166, 'right'),            // group_percent
            $xf(1, 5, 2, 166, 'right'),            // total_percent
            $xf(0, 0, 1, 165, 'right'),            // num2 (tone)
            $xf(1, 4, 1, 165, 'right'),            // group_num2
            $xf(1, 5, 2, 165, 'right'),            // total_num2
            $xf(0, 0, 1, 0, 'right'),              // text_num (text aliniat dreapta: "-", "57.030 km")
            $xf(1, 4, 1, 0, 'right'),              // group_text_num
            $xf(1, 5, 2, 0, 'right'),              // total_text_num
            $xf(5, 7, 1, 0, 'right'),              // small_text_num
            $xf(3, 6, 3, 0, 'left', 1),            // kpi_label
            $xf(7, 6, 3, 0, 'right'),              // kpi_value
            $xf(6, 8, 1, 0, 'center'),             // code (badge ruta)
            $xf(8, 0, 0, 0, 'left', 0, true),      // warn
            $xf(5, 7, 1, 165, 'right'),            // small_num2
            $xf(5, 7, 1, 0, 'left', 5),            // text_l3
        ];

        /* Stilurile numerice cu unitate, adaugate la rulare (numberStyle), dupa cele fixe. */
        $customFmtIds = [];
        foreach ($this->customStyles as [$code, $variant]) {
            if (!isset($customFmtIds[$code])) {
                $customFmtIds[$code] = 170 + count($customFmtIds);
                $numFmts[$customFmtIds[$code]] = $code;
            }
            $cellXfs[] = match ($variant) {
                'small' => $xf(5, 7, 1, $customFmtIds[$code], 'right'),
                'group' => $xf(1, 4, 1, $customFmtIds[$code], 'right'),
                'total' => $xf(1, 5, 2, $customFmtIds[$code], 'right'),
                default => $xf(0, 0, 1, $customFmtIds[$code], 'right'),
            };
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="' . count($numFmts) . '">';
        foreach ($numFmts as $id => $code) {
            $xml .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::xml($code) . '"/>';
        }
        $xml .= '</numFmts>'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        return $xml;
    }
}
