<?php
declare(strict_types=1);

require_once __DIR__ . '/SimpleXlsxWriter.php';

/*
 * "Raport scurt" din Centralizator facturare: un tabel Excel nativ, plat, cu filtre pe
 * fiecare coloana si fara celule unite in date. Primeste randurile deja calculate de
 * CentralizatorFacturareService::getShortExportData(), deci cifrele si filtrele sunt
 * cele din pagina. Activitatea fara TVA si refacturarile cu TVA (asa sunt introduse)
 * raman valori din aplicatie, nu formule; in Excel se calculeaza doar cealalta valoare
 * a fiecaruia, totalurile pe rand si sumarul SUBTOTAL de deasupra.
 */
class CentralizatorFacturareShortExport
{
    /* Centralizatorul nu are o cota TVA proprie: singura sursa aici, editabila in B3 din foaie. */
    private const VAT_RATE = 0.21;

    private const COLUMNS = [
        'Nr. auto', 'Nr. curse auto*', 'Rută', 'Curse / rută', 'Tip transport', 'Km / cursă', 'Tone / cursă',
        'Tarif RON/km', 'Tarif RON/tonă', 'Valoare / cursă', 'Activitate fără TVA', 'Activitate cu TVA',
        'Tip cheltuială', 'Cantitate', 'Valoare unitară cu TVA', 'Refacturare fără TVA', 'Refacturare cu TVA',
        'Total fără TVA', 'Total cu TVA',
    ];
    private const WIDTHS = [1 => 16, 16, 34, 13, 17, 13, 13, 14, 15, 16, 20, 20, 26, 12, 15, 21, 20, 20, 20];
    private const LAST_COLUMN = 'S';
    private const HEADER_ROW = 7;
    private const FORMAT_RON = '#,##0.00 "RON";[Red]-#,##0.00 "RON";"–"';
    private const FORMAT_NUMBER = '#,##0.00;[Red]-#,##0.00;"–"';
    private const FORMAT_RATE = '#,##0.00##;[Red]-#,##0.00##;"–"';
    private const FORMAT_INT = '#,##0;[Red]-#,##0;"–"';
    private const BORDER = 'D5DEE8';
    private const FILL_COMPUTED = 'E8F5F3';

    private SimpleXlsxWriter $xlsx;

    /** @var array<string, string>|null stilurile randurilor de date */
    private ?array $styles = null;

    public function __construct(private array $report)
    {
        $this->xlsx = new SimpleXlsxWriter();
    }

    public function save(string $path): void
    {
        $this->buildReportSheet();
        $this->buildGuideSheet();
        $this->xlsx->save($path);
    }

    private function buildReportSheet(): void
    {
        $x = $this->xlsx;
        $s = $x->addSheet('Raport filtrabil');
        $rows = (array) ($this->report['rows'] ?? []);
        /* Rutele si cheltuielile se citesc intregi, fara sa impinga foaia prea mult in latime. */
        $textWidth = static fn (string $key, int $min, int $max): int => max($min, min($max, 2 + max([0, ...array_map(static fn (array $row): int => mb_strlen((string) ($row[$key] ?? '')), $rows)])));
        $x->setColumnWidths($s, array_replace(self::WIDTHS, [3 => $textWidth('route', 34, 62), 13 => $textWidth('expense_label', 26, 44)]));
        $firstDataRow = self::HEADER_ROW + 1;
        /* Un tabel Excel are nevoie de cel putin un rand de date: fara rezultate ramane unul gol. */
        $lastDataRow = $firstDataRow + max(1, count($rows)) - 1;

        $this->banner($s, 'Centralizator facturare — Raport scurt', ['fill' => '14243A', 'color' => 'FFFFFF', 'bold' => true, 'size' => 17], 38);
        $this->banner($s, $this->scopeLine(), ['fill' => 'EAF0F7', 'color' => '475569', 'size' => 10, 'wrap' => true], 25);
        $this->writeSummary($s, $rows, $firstDataRow, $lastDataRow);
        $filtersLine = $this->filtersLine();
        $this->banner($s, $filtersLine, ['fill' => 'F8FAFC', 'color' => '475569', 'size' => 10, 'wrap' => true], mb_strlen($filtersLine) > 320 ? 32 : 20);
        $this->banner($s, $rows === []
            ? 'Nu există înregistrări pentru filtrele selectate.'
            : 'Un rând = aceleași curse ale unui vehicul pe o rută. Totalurile de mai sus urmează filtrele din antetul tabelului.', ['fill' => 'F8FAFC', 'color' => $rows === [] ? 'B45309' : '64748B', 'size' => 10], 20);
        $this->writeSectionHeaders($s);

        $head = $x->defineStyle(['fill' => '20364B', 'color' => 'FFFFFF', 'bold' => true, 'border' => '20364B', 'align' => 'center', 'wrap' => true]);
        $x->addRow($s, array_map(static fn (string $column): array => ['v' => $column, 's' => $head], self::COLUMNS), ['height' => 36]);

        if ($rows === []) {
            $this->writeEmptyRow($s);
        }
        foreach ($rows as $row) {
            $this->writeDataRow($s, $row);
        }

        $x->addTable($s, 'RaportScurtFiltrabil', 'A' . self::HEADER_ROW . ':' . self::LAST_COLUMN . $lastDataRow, self::COLUMNS);
        $x->freezeRows($s, self::HEADER_ROW);

        $x->addRow($s);
        $this->banner($s, '* Nr. curse auto este informativ și se repetă pe rânduri. Totalul filtrat de curse este suma coloanei „Curse / rută”.', ['color' => '9A6700', 'size' => 10], 18);
    }

    /* Rand de text pe toata latimea, in afara tabelului. */
    private function banner(int $s, string $text, array $style, float $height): void
    {
        $x = $this->xlsx;
        $name = $x->defineStyle($style);
        $cells = array_fill(0, count(self::COLUMNS), ['v' => null, 's' => $name]);
        $cells[0] = ['v' => $text, 's' => $name];
        $row = $x->addRow($s, $cells, ['height' => $height]);
        $x->mergeRange($s, 'A' . $row . ':' . self::LAST_COLUMN . $row);
    }

    private function scopeLine(): string
    {
        $generatedAt = strtotime((string) ($this->report['generated_at'] ?? '')) ?: time();

        return 'Beneficiar: ' . $this->shorten((string) ($this->report['scope']['beneficiary'] ?? '-'))
            . '  •  Perioadă: ' . (string) ($this->report['filters']['month_label'] ?? '-')
            . '  •  Generat: ' . date('d.m.Y H:i', $generatedAt);
    }

    private function filtersLine(): string
    {
        $parts = array_map(fn (array $filter): string => (string) ($filter['label'] ?? '') . ': ' . $this->shorten((string) ($filter['value'] ?? '')), (array) ($this->report['scope']['active'] ?? []));

        return $parts !== [] ? 'Filtre aplicate: ' . implode('  •  ', $parts) : 'Filtre aplicate: fără filtre suplimentare (toate vehiculele, rutele și tipurile de transport din perioadă)';
    }

    /* O selectie lunga nu trebuie sa umfle antetul fixat al foii. */
    private function shorten(string $list): string
    {
        if (mb_strlen($list) <= 150) {
            return $list;
        }

        return rtrim(mb_substr($list, 0, 150), ' ,') . ' … (' . (substr_count($list, ', ') + 1) . ' selectate)';
    }

    private function writeSummary(int $s, array $rows, int $firstDataRow, int $lastDataRow): void
    {
        $x = $this->xlsx;
        $fill = 'F1F5F9';
        $label = $x->defineStyle(['fill' => $fill, 'bold' => true, 'wrap' => true, 'size' => 10]);
        $empty = $x->defineStyle(['fill' => $fill]);
        $input = $x->defineStyle(['fill' => 'FFF7D6', 'border' => 'E0C873', 'format' => '0%', 'align' => 'center', 'bold' => true]);
        $valueStyle = static fn (string $format): string => $x->defineStyle(['fill' => $fill, 'color' => '0B7285', 'bold' => true, 'size' => 12, 'format' => $format, 'align' => 'right']);
        $sum = static fn (string $key): float => array_sum(array_map(static fn (array $row): float => (float) ($row[$key] ?? 0), $rows));
        $mergeValue = static function (string $from, string $to) use ($x, $s): void {
            $x->mergeRange($s, $from . '3:' . $to . '3');
        };
        $subtotal = static fn (string $column): string => 'SUBTOTAL(109,' . $column . $firstDataRow . ':' . $column . $lastDataRow . ')';

        $amounts = array_map(fn (array $row): array => $this->rowAmounts($row), $rows);
        $withVat = round(array_sum(array_column($amounts, 'total_vat')), 2);
        $activity = round($sum('activity_value'), 2);
        $refundsNet = round(array_sum(array_column($amounts, 'refund_net')), 2);
        $refundsVat = round($sum('expense_amount_vat'), 2);

        $cells = array_fill(0, count(self::COLUMNS), ['v' => null, 's' => $empty]);
        $cells[0] = ['v' => 'TVA (%)', 's' => $label];
        $cells[1] = ['v' => self::VAT_RATE, 's' => $input];
        /* Eticheta, apoi valoarea; pe coloanele inguste valoarea ocupa doua, ca sa nu ajunga "####". */
        foreach ([
            [2, 1, 'Total curse', 'D', (int) $sum('trips'), self::FORMAT_INT],
            [4, 2, 'Total activitate fără TVA', 'K', $activity, self::FORMAT_RON],
            [7, 2, 'Total refacturări fără TVA', 'P', $refundsNet, self::FORMAT_RON],
            [10, 1, 'Total refacturări cu TVA', 'Q', $refundsVat, self::FORMAT_RON],
            [12, 2, 'Total general fără TVA', 'R', round($activity + $refundsNet, 2), self::FORMAT_RON],
            [15, 2, 'Total general cu TVA', 'S', $withVat, self::FORMAT_RON],
        ] as [$column, $span, $title, $source, $value, $format]) {
            $cells[$column] = ['v' => $title, 's' => $label];
            $cells[$column + 1] = ['v' => $value, 's' => $valueStyle($format), 'f' => $subtotal($source)];
            if ($span === 2) {
                $cells[$column + 2] = ['v' => null, 's' => $valueStyle($format)];
                $mergeValue(SimpleXlsxWriter::columnLetter($column + 2), SimpleXlsxWriter::columnLetter($column + 3));
            }
        }
        $x->addRow($s, $cells, ['height' => 34]);
    }

    private function writeSectionHeaders(int $s): void
    {
        $x = $this->xlsx;
        $cells = [];
        $rowNumber = $x->nextRowNumber($s);
        foreach ([['INFORMAȚII ACTIVITATE', '22577A', 'A', 'G', 7], ['COST ACTIVITATE', '176B84', 'H', 'L', 5], ['REFACTURĂRI', '6E5C8E', 'M', 'Q', 5], ['TOTAL GENERAL', '195A54', 'R', 'S', 2]] as [$title, $fill, $from, $to, $span]) {
            $style = $x->defineStyle(['fill' => $fill, 'color' => 'FFFFFF', 'bold' => true, 'align' => 'center']);
            $cells[] = ['v' => $title, 's' => $style];
            for ($i = 1; $i < $span; $i++) {
                $cells[] = ['v' => null, 's' => $style];
            }
            $x->mergeRange($s, $from . $rowNumber . ':' . $to . $rowNumber);
        }
        $x->addRow($s, $cells, ['height' => 26]);
    }

    private function dataStyles(): array
    {
        $x = $this->xlsx;
        $border = self::BORDER;
        $make = static fn (array $style): string => $x->defineStyle($style + ['border' => $border]);

        return [
            'text' => $make([]),
            'int' => $make(['format' => self::FORMAT_INT, 'align' => 'right']),
            'vehicle_trips' => $make(['format' => self::FORMAT_INT, 'align' => 'right', 'fill' => 'EEF2F7', 'color' => '64748B']),
            'number' => $make(['format' => self::FORMAT_NUMBER, 'align' => 'right']),
            'rate' => $make(['format' => self::FORMAT_RATE, 'align' => 'right']),
            'money' => $make(['format' => self::FORMAT_RON, 'align' => 'right', 'fill' => self::FILL_COMPUTED]),
            'money_bold' => $make(['format' => self::FORMAT_RON, 'align' => 'right', 'fill' => self::FILL_COMPUTED, 'bold' => true]),
        ];
    }

    /* Sumele cu TVA ale unui rand, rotunjite ca formulele din foaie. */
    private function rowAmounts(array $row): array
    {
        $activity = (float) ($row['activity_value'] ?? 0);
        $refundVat = (float) ($row['expense_amount_vat'] ?? 0);
        $activityVat = round($activity * (1 + self::VAT_RATE), 2);
        $refundNet = round($refundVat / (1 + self::VAT_RATE), 2);

        return [
            'activity_vat' => $activityVat,
            'refund_net' => $refundNet,
            'total' => round($activity + $refundNet, 2),
            'total_vat' => round($activityVat + $refundVat, 2),
        ];
    }

    private function writeDataRow(int $s, array $row): void
    {
        $styles = $this->styles ??= $this->dataStyles();
        $amounts = $this->rowAmounts($row);
        $hasActivity = ($row['activity_value'] ?? null) !== null;
        $hasRefund = ($row['expense_amount_vat'] ?? null) !== null;
        $cell = static fn (mixed $value, string $style): array => ['v' => $value, 's' => $styles[$style]];

        $this->xlsx->addRow($s, [
            $cell((string) $row['vehicle'], 'text'),
            $cell((int) $row['vehicle_trips'], 'vehicle_trips'),
            $cell((string) $row['route'], 'text'),
            $cell($row['trips'], 'int'),
            $cell((string) $row['type_label'], 'text'),
            $cell($row['km'], 'number'),
            $cell($row['tone'], 'number'),
            $cell($row['rate_km'], 'rate'),
            $cell($row['rate_ton'], 'rate'),
            $cell($row['value_per_trip'], 'number'),
            $cell($row['activity_value'], 'money'),
            $hasActivity ? ['v' => $amounts['activity_vat'], 's' => $styles['money'], 'f' => 'ROUND(K{r}*(1+$B$3),2)'] : $cell(null, 'money'),
            $cell($row['expense_label'], 'text'),
            $cell($row['expense_quantity'], 'number'),
            $cell($row['expense_unit_price'], 'rate'),
            $hasRefund ? ['v' => $amounts['refund_net'], 's' => $styles['money'], 'f' => 'ROUND(Q{r}/(1+$B$3),2)'] : $cell(null, 'money'),
            $cell($row['expense_amount_vat'], 'money'),
            ['v' => $amounts['total'], 's' => $styles['money_bold'], 'f' => 'K{r}+P{r}'],
            ['v' => $amounts['total_vat'], 's' => $styles['money_bold'], 'f' => 'L{r}+Q{r}'],
        ], ['height' => 22]);
    }

    private function writeEmptyRow(int $s): void
    {
        $style = $this->xlsx->defineStyle(['border' => self::BORDER]);
        $this->xlsx->addRow($s, array_fill(0, count(self::COLUMNS), ['v' => null, 's' => $style]), ['height' => 22]);
    }

    private function buildGuideSheet(): void
    {
        $x = $this->xlsx;
        $s = $x->addSheet('Ghid si verificari');
        $x->setColumnWidths($s, [1 => 30, 2 => 88, 3 => 20, 4 => 20, 5 => 14]);
        $title = $x->defineStyle(['fill' => '14243A', 'color' => 'FFFFFF', 'bold' => true, 'size' => 15]);
        $label = $x->defineStyle(['fill' => 'E6EFF5', 'color' => '193B56', 'bold' => true, 'border' => self::BORDER]);
        $text = $x->defineStyle(['wrap' => true, 'border' => self::BORDER]);

        $row = $x->addRow($s, [['v' => 'Ghid și verificări', 's' => $title], ['v' => null, 's' => $title], ['v' => null, 's' => $title], ['v' => null, 's' => $title], ['v' => null, 's' => $title]], ['height' => 34]);
        $x->mergeRange($s, 'A' . $row . ':E' . $row);
        $x->addRow($s);

        foreach ([
            ['Rânduri', 'Cursele aceluiași vehicul, tip de transport și rută stau pe un rând doar dacă au aceiași km, tone, tarife și valoare pe cursă.'],
            ['Mai multe cheltuieli', 'Primul rând poartă cursele și valoarea activității; rândurile următoare au doar identificarea și cheltuiala, ca activitatea să nu se adune de două ori.'],
            ['Curse P+D', 'Partea de km și partea de tone au rânduri separate, dar cursa se numără o singură dată, ca în pagină.'],
            ['Refacturări din alte luni', 'Refacturarea din luna raportului pe o cursă din altă lună are rând fără activitate.'],
            ['Valori', 'Activitatea fără TVA și refacturările cu TVA vin din Centralizator; km și tonele sunt cele din „Activități pe tipuri de transport”.'],
            ['TVA', 'Cota este în Raport filtrabil!B3. Activitatea cu TVA = valoarea fără TVA × (1 + cotă). Refacturările sunt introduse cu TVA: valoarea fără TVA = valoarea cu TVA / (1 + cotă). Rotunjirea se face pe fiecare rând.'],
            ['Totaluri', 'Rândul 3 din raport folosește SUBTOTAL(109, ...) și ține cont doar de rândurile vizibile.'],
        ] as [$name, $description]) {
            $x->addRow($s, [['v' => $name, 's' => $label], ['v' => $description, 's' => $text]], ['height' => 31]);
        }
        $x->addRow($s);
        $this->writeControlTable($s, $label, $text);
    }

    /* Reconcilierea: totalurile aplicatiei fata de suma tuturor randurilor (filtrate sau nu). */
    private function writeControlTable(int $s, string $label, string $text): void
    {
        $x = $this->xlsx;
        $control = (array) ($this->report['control'] ?? []);
        $rows = (array) ($this->report['rows'] ?? []);
        $last = self::HEADER_ROW + max(1, count($rows));
        $first = self::HEADER_ROW + 1;
        $border = self::BORDER;
        $number = $x->defineStyle(['format' => '#,##0.00', 'align' => 'right', 'border' => $border]);
        $sum = static fn (string $key): float => round(array_sum(array_map(static fn (array $row): float => (float) ($row[$key] ?? 0), $rows)), 2);

        $x->addRow($s, [['v' => 'Control', 's' => $label], ['v' => 'Toate rândurile tabelului, indiferent de filtrele Excel', 's' => $label], ['v' => 'Aplicație', 's' => $label], ['v' => 'Tabel', 's' => $label], ['v' => 'Diferență', 's' => $label]]);
        foreach ([
            ['Curse', (float) ($control['trips'] ?? 0), 'D', $sum('trips')],
            ['Activitate fără TVA', (float) ($control['activity'] ?? 0), 'K', $sum('activity_value')],
            ['Refacturări cu TVA', (float) ($control['refunds'] ?? 0), 'Q', $sum('expense_amount_vat')],
        ] as [$name, $application, $column, $tableValue]) {
            $range = "'Raport filtrabil'!" . $column . $first . ':' . $column . $last;
            $x->addRow($s, [
                ['v' => $name, 's' => $label],
                ['v' => null, 's' => $text],
                ['v' => $application, 's' => $number],
                ['v' => $tableValue, 's' => $number, 'f' => 'SUM(' . $range . ')'],
                ['v' => round($tableValue - $application, 2), 's' => $number, 'f' => 'ROUND(D{r}-C{r},2)'],
            ]);
        }
    }
}
