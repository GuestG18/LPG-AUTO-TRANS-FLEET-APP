<?php
declare(strict_types=1);

require_once __DIR__ . '/SimpleXlsxWriter.php';

/*
 * "Raport centralizat pe vehicule" din Centralizator facturare, dupa modelul
 * Situatie_ButanGas_Simplificata.xlsx (foile "Rezumat ..."): o foaie pe beneficiar,
 * o sectiune pe luna si loc de incarcare, un rand pe vehicul si ruta (cu cursele lui
 * grupate dedesubt, restranse implicit), apoi TOTAL.
 * Primeste randurile deja adunate de CentralizatorFacturareService::getVehicleSummaryExportData().
 * Pretul pe tona si pe km sunt valoarea totala impartita la cantitatea totala (formule), nu medii.
 */
class CentralizatorFacturareVehicleExport
{
    private const COLUMNS = ['Nr. auto', 'Rută', 'Nr. curse', 'Km total', 'Tone total', 'Preț/tonă', 'Încasare (lei)', 'Preț/km'];
    private const WIDTHS = [1 => 17, 24, 13, 15, 15, 15, 19, 14];
    private const LAST_COLUMN = 'H';
    private const FONT = 'Aptos Narrow';
    private const FORMAT_INT = '#,##0';
    private const FORMAT_DECIMAL = '#,##0.00';
    private const MONTHS = [1 => 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];

    private SimpleXlsxWriter $xlsx;

    /** @var array<string, string> */
    private array $styles = [];

    public function __construct(private array $report)
    {
        $this->xlsx = new SimpleXlsxWriter();
    }

    public function save(string $path): void
    {
        $this->defineStyles();
        $sheets = (array) ($this->report['sheets'] ?? []);
        if ($sheets === []) {
            $s = $this->addSheet('Rezumat');
            $this->title($s, 'Situație luna ' . $this->monthName() . ' — nu există curse pentru filtrele selectate');
        }
        $usedNames = [];
        foreach ($sheets as $sheet) {
            $s = $this->addSheet($this->uniqueSheetName('Rezumat ' . (string) $sheet['beneficiary'], $usedNames));
            foreach ((array) $sheet['sections'] as $index => $section) {
                if ($index > 0) {
                    /* Doua randuri goale intre sectiuni, ca in model. */
                    $this->xlsx->addRow($s);
                    $this->xlsx->addRow($s);
                }
                $this->writeSection($s, $section);
            }
        }
        $this->xlsx->save($path);
    }

    private function defineStyles(): void
    {
        $x = $this->xlsx;
        $base = ['font' => self::FONT, 'size' => 11, 'color' => '000000', 'align' => 'general'];
        $stripe = 'F2F6FA';
        $band = 'DCE6F1';
        $tripBase = ['font' => self::FONT, 'size' => 10, 'italic' => true, 'color' => '4B5563', 'align' => 'general'];
        $this->styles = [
            'title' => $x->defineStyle(['font' => self::FONT, 'size' => 12, 'bold' => true, 'color' => 'FFFFFF', 'fill' => '17365D', 'align' => 'general']),
            'head' => $x->defineStyle($base + ['bold' => true, 'fill' => $band]),
            'total_text' => $x->defineStyle($base + ['bold' => true, 'fill' => $band]),
            'total_int' => $x->defineStyle($base + ['bold' => true, 'fill' => $band, 'format' => self::FORMAT_INT]),
            'total_dec' => $x->defineStyle($base + ['bold' => true, 'fill' => $band, 'format' => self::FORMAT_DECIMAL]),
            'odd_text' => $x->defineStyle($base + ['fill' => $stripe]),
            'odd_int' => $x->defineStyle($base + ['fill' => $stripe, 'format' => self::FORMAT_INT]),
            'odd_dec' => $x->defineStyle($base + ['fill' => $stripe, 'format' => self::FORMAT_DECIMAL]),
            'even_text' => $x->defineStyle($base),
            'even_int' => $x->defineStyle($base + ['format' => self::FORMAT_INT]),
            'even_dec' => $x->defineStyle($base + ['format' => self::FORMAT_DECIMAL]),
            /* Cursele de sub un rand (restranse implicit): gri, cursiv, prima coloana indentata. */
            'trip_text' => $x->defineStyle(['align' => 'left', 'indent' => 1] + $tripBase),
            'trip_int' => $x->defineStyle($tripBase + ['format' => self::FORMAT_INT]),
            'trip_dec' => $x->defineStyle($tripBase + ['format' => self::FORMAT_DECIMAL]),
        ];
    }

    private function addSheet(string $name): int
    {
        $s = $this->xlsx->addSheet($name);
        $this->xlsx->setColumnWidths($s, self::WIDTHS);
        $this->xlsx->showGridLines($s);

        return $s;
    }

    /* Numele de foaie: max 31 caractere si unice (doi beneficiari cu acelasi inceput de nume). */
    private function uniqueSheetName(string $name, array &$used): string
    {
        $clean = trim((string) preg_replace('/[:\\\\\/\?\*\[\]]/u', ' ', $name));
        $candidate = mb_substr($clean, 0, 31);
        for ($i = 2; isset($used[mb_strtolower($candidate)]); $i++) {
            $suffix = ' (' . $i . ')';
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    private function title(int $s, string $text): void
    {
        $cells = array_fill(0, count(self::COLUMNS), ['v' => null, 's' => $this->styles['title']]);
        $cells[0]['v'] = $text;
        $row = $this->xlsx->addRow($s, $cells, ['height' => 26]);
        $this->xlsx->mergeRange($s, 'A' . $row . ':' . self::LAST_COLUMN . $row);
    }

    private function writeSection(int $s, array $section): void
    {
        $x = $this->xlsx;
        $this->title($s, 'Situație luna ' . $this->monthName((string) ($section['month'] ?? '')) . ' — ' . (string) $section['label']);
        $x->addRow($s, array_map(fn (string $column): array => ['v' => $column, 's' => $this->styles['head']], self::COLUMNS), ['height' => 22]);

        $rows = (array) $section['rows'];
        $first = $x->nextRowNumber($s);
        foreach ($rows as $index => $row) {
            $band = $index % 2 === 0 ? 'odd' : 'even';
            $details = (array) ($row['details'] ?? []);
            $trips = (int) $row['trips'];
            $km = (float) $row['km'];
            $tone = (float) $row['tone'];
            $value = (float) $row['value'];
            /*
             * Randul vehiculului aduna cursele de sub el cu SUBTOTAL; TOTAL-ul sectiunii
             * foloseste tot SUBTOTAL, care sare peste celulele SUBTOTAL - nimic nu se aduna de doua ori.
             */
            $summaryRow = $x->nextRowNumber($s);
            $detailFirst = $summaryRow + 1;
            $detailLast = $summaryRow + count($details);
            $sub = static fn (string $column): ?string => $details !== [] ? 'SUBTOTAL(9,' . $column . $detailFirst . ':' . $column . $detailLast . ')' : null;
            $x->addRow($s, [
                ['v' => (string) $row['vehicle'], 's' => $this->styles[$band . '_text']],
                ['v' => (string) $row['route'], 's' => $this->styles[$band . '_text']],
                ['v' => $trips, 's' => $this->styles[$band . '_int'], 'f' => $sub('C')],
                ['v' => $km, 's' => $this->styles[$band . '_int'], 'f' => $sub('D')],
                ['v' => $tone, 's' => $this->styles[$band . '_dec'], 'f' => $sub('E')],
                ['v' => $tone > 0 ? $value / $tone : 0, 's' => $this->styles[$band . '_dec'], 'f' => 'IFERROR(G{r}/E{r},0)'],
                ['v' => $value, 's' => $this->styles[$band . '_dec'], 'f' => $sub('G')],
                ['v' => $km > 0 ? $value / $km : 0, 's' => $this->styles[$band . '_dec'], 'f' => 'IFERROR(G{r}/D{r},0)'],
            ], $details !== [] ? ['collapsed' => true] : []);

            /* Cursele randului: grupate (butonul + din stanga), ascunse pana le deschizi. */
            foreach ($details as $detail) {
                $dKm = (float) $detail['km'];
                $dTone = (float) $detail['tone'];
                $dValue = (float) $detail['value'];
                /* Pe randul cursei: Nr. auto + Ruta unite, doar data cursei. */
                $x->addRow($s, [
                    ['v' => (string) $detail['date_label'], 's' => $this->styles['trip_text']],
                    ['v' => null, 's' => $this->styles['trip_text']],
                    ['v' => (int) $detail['trips'], 's' => $this->styles['trip_int']],
                    ['v' => $dKm, 's' => $this->styles['trip_int']],
                    ['v' => $dTone, 's' => $this->styles['trip_dec']],
                    ['v' => $dTone > 0 ? $dValue / $dTone : 0, 's' => $this->styles['trip_dec'], 'f' => 'IFERROR(G{r}/E{r},0)'],
                    ['v' => $dValue, 's' => $this->styles['trip_dec']],
                    ['v' => $dKm > 0 ? $dValue / $dKm : 0, 's' => $this->styles['trip_dec'], 'f' => 'IFERROR(G{r}/D{r},0)'],
                ], ['level' => 1, 'hidden' => true, 'merge' => 2]);
            }
        }
        $last = max($first, $x->nextRowNumber($s) - 1);

        $sum = static fn (string $key): float => array_sum(array_map(static fn (array $row): float => (float) $row[$key], $rows));
        $km = $sum('km');
        $tone = $sum('tone');
        $value = $sum('value');
        $range = static fn (string $column): string => 'SUBTOTAL(9,' . $column . $first . ':' . $column . $last . ')';
        $x->addRow($s, [
            ['v' => 'TOTAL', 's' => $this->styles['total_text']],
            ['v' => null, 's' => $this->styles['total_text']],
            ['v' => (int) $sum('trips'), 's' => $this->styles['total_int'], 'f' => $range('C')],
            ['v' => round($km, 2), 's' => $this->styles['total_int'], 'f' => $range('D')],
            ['v' => round($tone, 4), 's' => $this->styles['total_dec'], 'f' => $range('E')],
            ['v' => $tone > 0 ? $value / $tone : 0, 's' => $this->styles['total_dec'], 'f' => 'IFERROR(G{r}/E{r},0)'],
            ['v' => round($value, 2), 's' => $this->styles['total_dec'], 'f' => $range('G')],
            ['v' => $km > 0 ? $value / $km : 0, 's' => $this->styles['total_dec'], 'f' => 'IFERROR(G{r}/D{r},0)'],
        ], ['height' => 22]);
    }

    private function monthName(string $month = ''): string
    {
        $month = $month !== '' ? $month : (string) ($this->report['filters']['month'] ?? '');
        $number = (int) substr($month, 5, 2);

        return self::MONTHS[$number] ?? (string) ($this->report['filters']['month_label'] ?? $month);
    }
}
