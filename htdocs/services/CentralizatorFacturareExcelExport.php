<?php
declare(strict_types=1);

require_once __DIR__ . '/SimpleXlsxWriter.php';

/*
 * Exportul Excel al paginii Centralizator facturare, construit ca pagina: aceleasi
 * sectiuni, in aceeasi ordine, cu aceleasi etichete si acelasi text "Calcul facturare".
 * Randurile care se desfasoara in pagina (rute -> curse, refacturari, vehicule) sunt
 * grupate in Excel (butonul +/- din stanga), restranse implicit, ca in pagina.
 *
 * Primeste raportul deja calculat de CentralizatorFacturareService::getExportData(),
 * deci cifrele sunt exact cele din pagina, pentru aceleasi filtre.
 */
class CentralizatorFacturareExcelExport
{
    private SimpleXlsxWriter $xlsx;

    public function __construct(private array $report)
    {
        $this->xlsx = new SimpleXlsxWriter();
    }

    public function save(string $path): void
    {
        $this->buildMainSheet();
        $this->buildVehicleSheet();
        $this->buildRefundsSheet();
        $this->buildTripsSheet();
        $this->xlsx->save($path);
    }

    /* ---------------------------------------------------------------- formatare */

    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function fmt(mixed $value, int $decimals = 2): string
    {
        return number_format(self::num($value), $decimals, ',', '.');
    }

    private static function fmtSmart(mixed $value, int $maxDecimals = 2): string
    {
        $number = self::num($value);

        return self::fmt($number, abs($number - round($number)) < 0.0001 ? 0 : $maxDecimals);
    }

    /* Pret unitar fara zerouri inutile: 1,2100 -> 1,21; 2,0000 -> 2. */
    private static function fmtRate(mixed $value): string
    {
        return rtrim(rtrim(number_format(self::num($value), 4, ',', '.'), '0'), ',');
    }

    private static function fmtKm(mixed $value): string
    {
        return self::num($value) > 0 ? self::fmtSmart($value, 0) . ' km' : '-';
    }

    private static function fmtTone(mixed $value): string
    {
        return self::num($value) > 0 ? self::fmtSmart($value, 2) . ' tone' : '-';
    }

    /* "12.000 km × 1,27 RON/km"; "~" marcheaza un pret mediu. Identic cu pagina. */
    private static function billingCalc(mixed $quantity, string $unit, mixed $rate, bool $isAverage = false): string
    {
        $qty = self::num($quantity);
        if ($qty <= 0) {
            return '-';
        }
        $text = self::fmtSmart($qty, $unit === 'km' ? 0 : 2) . ' ' . $unit;
        if (is_numeric($rate) && (float) $rate > 0) {
            $text .= ' × ' . ($isAverage ? '~' : '') . self::fmtRate($rate) . ' RON/' . $unit;
        }

        return $text;
    }

    /* "10 t × 60 RON/t + 180 km × 1,5 RON/km"; costul fix pe cursa apare ca atare. */
    private static function componentCalc(array $components): string
    {
        $parts = [];
        foreach ($components as $component) {
            $unit = (string) ($component['quantity_unit'] ?? '');
            $rate = (!empty($component['rate_average']) ? '~' : '') . self::fmtRate($component['rate'] ?? 0);
            $rideCount = self::num($component['quantity'] ?? 0);
            $parts[] = $unit === 'cursă'
                ? ($rideCount > 1 ? self::fmtSmart($rideCount, 0) . ' curse × ' : '') . $rate . ' RON/cursă (cost fix)'
                : self::fmtSmart($component['quantity'] ?? 0, $unit === 'km' ? 0 : 2) . ' ' . $unit . ' × ' . $rate . ' RON/' . $unit;
        }

        return implode(' + ', $parts);
    }

    private static function c(mixed $value, string $style): array
    {
        return ['v' => $value, 's' => $style];
    }

    /* Valoare numerica sau "-" (ca in pagina) cand e zero. */
    private static function numOrDash(mixed $value, string $numStyle, string $dashStyle): array
    {
        return self::num($value) > 0 ? self::c(self::num($value), $numStyle) : self::c('-', $dashStyle);
    }

    /* ---------------------------------------------------------------- foaia principala */

    /*
     * Grila foii principale (aceeasi pentru toate sectiunile):
     *   A Activitate / etichete   B Preț activitate   C Rută / text   D Curse
     *   E Valoare RON             F Km                G Tone          H %
     * In tabelele de facturare, Valoare = Activitate × Preț este formula Excel.
     */
    private const MAIN_WIDTH = 8;

    /* Rand din celule indexate pe litera coloanei; golurile primesc stilul $fill (pentru benzi colorate). */
    private static function cols(array $map, ?string $fill = null, int $width = self::MAIN_WIDTH): array
    {
        $cells = [];
        for ($i = 1; $i <= $width; $i++) {
            $letter = SimpleXlsxWriter::columnLetter($i);
            $cells[] = $map[$letter] ?? ($fill !== null ? self::c('', $fill) : null);
        }

        return $cells;
    }

    private function buildMainSheet(): void
    {
        $x = $this->xlsx;
        $s = $x->addSheet('Centralizator');
        $x->setColumnWidths($s, [1 => 30, 2 => 18, 3 => 46, 4 => 10, 5 => 17, 6 => 16, 7 => 16, 8 => 17]);
        $width = self::MAIN_WIDTH;

        $filters = (array) ($this->report['filters'] ?? []);
        $x->addRow($s, [self::c('Centralizator facturare', 'title')], ['height' => 24]);
        $x->addRow($s, [self::c('Raport pentru: ' . $this->scopeBeneficiary() . '  ·  ' . (string) ($filters['month_label'] ?? ''), 'muted')]);
        $active = $this->activeFilterLabels();
        $x->addRow($s, [self::c($active !== [] ? 'Filtre: ' . implode(' · ', $active) : ($this->isAllBeneficiaries() ? 'Situația generală - toate activitățile, toți beneficiarii' : 'Fără alte filtre - toate activitățile acestui beneficiar'), 'muted')]);
        $generatedAt = strtotime((string) ($this->report['generated_at'] ?? '')) ?: time();
        $x->addRow($s, [self::c('Ultima actualizare: ' . date('d.m.Y H:i', $generatedAt), 'muted')]);
        $x->addRow($s);

        $this->writeKpis($s, $width);
        $visibility = (array) ($this->report['visibility'] ?? []);
        if (!empty($visibility['activity_summary'])) {
            $this->writeActivity($s, $width);
        }
        if (!empty($visibility['distribution'])) {
            $this->writeDistributionSummary($s, $width);
        }
        if (!empty($visibility['primary_routes'])) {
            $this->writePrimaryRoutes($s, $width);
        }
        if (!empty($visibility['distribution_matrix'])) {
            $this->writeDistributionMatrix($s);
        }
        $this->writeTariffEvolution($s, $width);
        $this->writeRefundSummary($s, $width);
    }

    private function section(int $s, string $title, int $width): void
    {
        $cells = [self::c($title, 'section')];
        for ($i = 1; $i < $width; $i++) {
            $cells[] = self::c(null, 'section');
        }
        $this->xlsx->addRow($s, $cells, ['height' => 20, 'merge' => $width]);
    }

    private function writeKpis(int $s, int $width): void
    {
        $x = $this->xlsx;
        $this->section($s, 'Sumar', $width);
        foreach ((array) ($this->report['kpis']['cards'] ?? []) as $card) {
            $unit = (string) ($card['unit'] ?? '');
            $value = self::num($card['value'] ?? 0);
            $display = $unit === 'RON'
                ? self::fmt($value, 0) . ' RON'
                : self::fmtSmart($value, $unit === 'tone' ? 2 : 0) . ($unit !== '' ? ' ' . $unit : '');
            $breakdown = (array) ($card['breakdown'] ?? []);
            $row = $x->addRow($s, self::cols(['A' => self::c((string) ($card['title'] ?? '-'), 'kpi_label'), 'B' => self::c('', 'kpi_label'), 'C' => self::c($display, 'kpi_value')]), ['height' => 22]);
            /* "Pe tipuri de transport": se desfasoara ca in pagina. */
            foreach ($breakdown as $item) {
                $itemValue = self::num($item['value'] ?? 0);
                $itemDisplay = $unit === 'RON'
                    ? self::fmt($itemValue, 0) . ' RON'
                    : self::fmtSmart($itemValue, $unit === 'tone' ? 2 : 0) . ($unit !== '' ? ' ' . $unit : '');
                $x->addRow($s, self::cols([
                    'A' => self::c((string) ($item['label'] ?? '-'), 'text_l2'),
                    'C' => self::c($itemDisplay, 'small_text_num'),
                    'D' => self::c($unit !== 'curse' ? self::fmtSmart($item['trips'] ?? 0, 0) . ' curse' : '', 'small_text_num'),
                    'E' => self::c(self::num($item['share_percent'] ?? 0), 'percent'),
                ]), ['level' => 1, 'hidden' => true]);
            }
            if ($breakdown !== []) {
                $x->markCollapsed($s, $row);
            }
        }
        $ref = (array) ($this->report['kpis']['refacturari'] ?? []);
        $x->addRow($s, self::cols([
            'A' => self::c((string) ($ref['title'] ?? 'Total refacturări'), 'kpi_label'),
            'B' => self::c('', 'kpi_label'),
            'C' => self::c(self::fmt($ref['value'] ?? 0, 0) . ' RON', 'kpi_value'),
        ]), ['height' => 22]);
        $x->addRow($s);
    }

    /*
     * "Activități pe tipuri de transport": un rand per tip; sub el (grupat) tabelul
     * Activitate / Preț activitate / Rută / Curse / Valoare RON (= Activitate × Preț),
     * apoi cursele si refacturarile fiecarei rute, TOTAL si TOTAL REFACTURĂRI.
     */
    private function writeActivity(int $s, int $width): void
    {
        $x = $this->xlsx;
        $rows = (array) ($this->report['activity']['rows'] ?? []);
        $totals = (array) ($this->report['activity']['totals'] ?? []);
        if ($this->hasActiveFilters() || !$this->isAllBeneficiaries()) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['trips'] ?? 0) > 0 || (array) ($row['refund_leftovers'] ?? []) !== []));
        }

        $this->section($s, 'Activități pe tipuri de transport', $width);
        $x->addRow($s, self::cols([
            'A' => self::c('Tip transport', 'th'), 'D' => self::c('Curse', 'th_num'), 'E' => self::c('Valoare RON', 'th_num'),
            'F' => self::c('Km', 'th_num'), 'G' => self::c('Tone/Activ.', 'th_num'), 'H' => self::c('% din total curse', 'th_num'),
        ], 'th'));

        if ($rows === []) {
            $x->addRow($s, [self::c('Nu există curse pentru filtrul curent.', 'muted')]);
        }

        $typeRows = [];
        $typeValueSum = 0.0;
        foreach ($rows as $row) {
            $metric = self::num($row['tone'] ?? 0) > 0
                ? self::fmtSmart($row['tone'], 2) . ' t'
                : (self::num($row['activity'] ?? 0) > 0 ? self::fmtSmart($row['activity'], 2) . ' ' . (string) ($row['activity_unit'] ?? '') : '-');
            $routes = (array) ($row['routes'] ?? []);
            $leftovers = (array) ($row['refund_leftovers'] ?? []);
            $typeValue = self::num($row['value'] ?? 0);
            $typeValueSum += $typeValue;
            $typeRowNumber = $x->nextRowNumber($s);
            $hasDetail = $routes !== [] || $leftovers !== [];
            /* Valoarea tipului vine din randul lui TOTAL (formula), completat dupa ce il scriem. */
            $typeRows[] = $typeRowNumber;
            $typeCells = self::cols([
                'A' => self::c((string) ($row['label'] ?? '-'), 'group'),
                'D' => self::c((int) ($row['trips'] ?? 0), 'group_int'),
                'E' => self::c($typeValue, 'group_money'),
                'F' => self::c(self::fmtKm($row['km'] ?? 0), 'group_text_num'),
                'G' => self::c($metric, 'group_text_num'),
                'H' => self::c(self::num($row['share_percent'] ?? 0), 'group_percent'),
            ], 'group');

            if (!$hasDetail) {
                $x->addRow($s, $typeCells, ['height' => 20]);
                continue;
            }
            /* Randul tipului se scrie primul; formula lui trimite la TOTAL-ul de mai jos. */
            $placeholder = $x->addRow($s, $typeCells, ['height' => 20]);
            $totalRowNumber = $this->writeTypeRoutes($s, $routes, (string) ($row['group_by'] ?? 'route'), $leftovers);
            $typeCells[4] = ['v' => $typeValue, 's' => 'group_money', 'f' => 'E' . $totalRowNumber];
            $this->replaceRow($s, $placeholder, $typeCells);
            $x->markCollapsed($s, $placeholder);
        }

        $sumFormula = $typeRows !== [] ? 'SUM(' . implode(',', array_map(static fn (int $r): string => 'E' . $r, $typeRows)) . ')' : null;
        $x->addRow($s, self::cols([
            'A' => self::c('TOTAL', 'total'),
            'D' => self::c((int) ($totals['trips'] ?? 0), 'total_int'),
            'E' => ['v' => self::num($totals['value'] ?? $typeValueSum), 's' => 'total_money', 'f' => $sumFormula],
            'F' => self::c(self::fmtKm($totals['km'] ?? 0), 'total_text_num'),
            'G' => self::c(self::fmtTone($totals['tone'] ?? 0), 'total_text_num'),
            'H' => self::c((int) ($totals['trips'] ?? 0) > 0 ? 100.0 : 0.0, 'total_percent'),
        ], 'total'));
        $x->addRow($s, [self::c('Apasă + din stânga unui tip de transport pentru rutele facturate; Valoare RON = Activitate × Preț activitate (formulă). Apoi + pe o rută pentru cursele și refacturările ei.', 'muted')]);
        $x->addRow($s);
    }

    /* Rescrie celulele unui rand deja adaugat (pastreaza optiunile randului). */
    private function replaceRow(int $s, int $rowNumber, array $cells): void
    {
        $this->xlsx->replaceRowCells($s, $rowNumber, $cells);
    }

    /* Stilul numeric pentru o cantitate / un pret, dupa unitate: "13.200 km", "1,27 RON/km". */
    private function qtyStyle(string $unit, string $variant): string
    {
        return $this->xlsx->numberStyle(match ($unit) {
            'km' => '#,##0" km"',
            't' => '#,##0.00" t"',
            'cursă' => '[=1]0" cursă";#,##0" curse"',
            default => '#,##0.##' . ($unit !== '' ? '" ' . $unit . '"' : ''),
        }, $variant);
    }

    private function rateStyle(string $unit, string $variant): string
    {
        return $this->xlsx->numberStyle('#,##0.00##" RON/' . ($unit !== '' ? $unit : 'u') . '"', $variant);
    }

    /*
     * Partile de calcul (cantitate, unitate, pret) ale unei rute sau curse: componentele
     * (P+D, cost fix pe cursa, mai multe tarife) sau perechea simpla cantitate × pret.
     * @return array<int, array{qty: float, unit: string, rate: float, average: bool}>
     */
    private static function calcParts(array $item, string $fallbackUnit = ''): array
    {
        $parts = [];
        foreach ((array) ($item['components'] ?? []) as $component) {
            $qty = self::num($component['quantity'] ?? 0);
            $rate = self::num($component['rate'] ?? 0);
            if ($qty > 0 && $rate > 0) {
                $parts[] = ['qty' => $qty, 'unit' => (string) ($component['quantity_unit'] ?? ''), 'rate' => $rate, 'average' => !empty($component['rate_average'])];
            }
        }
        if ($parts === [] && (array) ($item['components'] ?? []) === []) {
            $qty = self::num($item['quantity'] ?? 0);
            $rate = self::num($item['rate'] ?? 0);
            if ($qty > 0 && $rate > 0) {
                $parts[] = ['qty' => $qty, 'unit' => (string) ($item['unit'] ?? $fallbackUnit), 'rate' => $rate, 'average' => !empty($item['rate_multiple'])];
            }
        }

        return $parts;
    }

    /*
     * Randurile de calcul pentru o ruta / cursa: cate un rand per parte de calcul, cu
     * Valoare = A×B (formula). Daca valoarea salvata difera de calcul (pret mediu, tarif
     * schimbat), se adauga un rand "ajustare" cu diferenta, ca totalul sa ramana cel din pagina.
     * Primul rand poarta textul din C si cursele; intoarce numerele randurilor cu valoare (col. E).
     *
     * @return int[]
     */
    private function writeCalcRows(int $s, array $parts, array $cCell, ?int $trips, float $value, array $options, string $variant, string $fallbackText = ''): array
    {
        $x = $this->xlsx;
        $small = $variant === 'small';
        $moneyStyle = $small ? 'small_money' : 'money';
        $textStyle = $small ? 'small' : 'text';
        $numStyle = $small ? 'small_int' : 'int';
        $firstStyle = $small ? 'text_l2' : 'text_l1';
        $valueRows = [];

        if ($parts === []) {
            $valueRows[] = $x->addRow($s, self::cols([
                'A' => self::c($fallbackText !== '' ? $fallbackText : '-', $firstStyle),
                'B' => self::c('', $textStyle),
                'C' => $cCell,
                'D' => $trips !== null ? self::c($trips, $numStyle) : self::c('', $textStyle),
                'E' => self::c($value, $moneyStyle),
            ]), $options);

            return $valueRows;
        }

        $computed = 0.0;
        foreach ($parts as $index => $part) {
            $partValue = round($part['qty'] * $part['rate'], 2);
            $computed += $partValue;
            $valueRows[] = $x->addRow($s, self::cols([
                'A' => ['v' => $part['qty'], 's' => $this->qtyStyle($part['unit'], $variant)],
                'B' => ['v' => $part['rate'], 's' => $this->rateStyle($part['unit'], $variant)],
                'C' => $index === 0 ? $cCell : self::c('+ ' . ($part['unit'] === 'cursă' ? 'cost fix' : 'componentă ' . $part['unit']), $textStyle),
                'D' => $index === 0 && $trips !== null ? self::c($trips, $numStyle) : self::c('', $textStyle),
                'E' => ['v' => $partValue, 's' => $moneyStyle, 'f' => 'A{r}*B{r}'],
                'F' => !empty($part['average']) ? self::c('preț mediu', 'muted') : null,
            ]), $options);
        }

        $difference = round($value - $computed, 2);
        if (abs($difference) >= 0.01) {
            $valueRows[] = $x->addRow($s, self::cols([
                'A' => self::c('ajustare', $small ? 'text_l3' : 'text_l2'),
                'B' => self::c('', $textStyle),
                'C' => self::c('Diferență față de valoarea salvată pe curse ⚠', 'warn'),
                'D' => self::c('', $textStyle),
                'E' => self::c($difference, $moneyStyle),
            ]), $options);
        }

        return $valueRows;
    }

    /* @return int numarul randului TOTAL al tipului (tinta formulei din randul tipului) */
    private function writeTypeRoutes(int $s, array $routes, string $groupBy, array $leftovers): int
    {
        $x = $this->xlsx;
        $byPrice = $groupBy === 'price';
        $l1 = ['level' => 1, 'hidden' => true];
        $l2 = ['level' => 2, 'hidden' => true];
        $l3 = ['level' => 3, 'hidden' => true];

        $x->addRow($s, self::cols([
            'A' => self::c('Activitate', 'sub_th_num'), 'B' => self::c('Preț activitate', 'sub_th_num'),
            'C' => self::c($byPrice ? 'Tarif / rute' : 'Rută', 'sub_th'),
            'D' => self::c('Curse', 'sub_th_num'), 'E' => self::c('Valoare RON', 'sub_th_num'),
        ]), $l1);

        $totalTrips = 0;
        $totalValue = 0.0;
        $valueRows = [];
        $refundsTotal = 0.0;
        $hasRefunds = $leftovers !== [];

        foreach ($routes as $route) {
            $unit = (string) ($route['unit'] ?? '');
            $label = (string) ($route['label'] ?? '-');
            /* Doar numele intreg al rutei: codurile (ex. B-L) nu spun nimic in afara aplicatiei. */
            $routeCell = $label;
            $routeValue = self::num($route['value'] ?? 0);
            $parts = self::calcParts($route, $unit);
            $fallback = !empty($route['component_billing']) ? 'calcul indisponibil' : '';

            /* Grup pe tarif (Distributie): un rand per ruta, cu numele intreg, nu cu codurile. */
            if ($byPrice) {
                $split = $this->writePriceGroupByRoute($s, $route, $parts, $l1, $l2, $l3);
                if ($split !== null) {
                    $valueRows[] = $split;
                    $totalTrips += (int) ($route['trips'] ?? 0);
                    $totalValue += $routeValue;
                    $hasRefunds = $hasRefunds || (array) ($route['refunds'] ?? []) !== [];
                    foreach ((array) ($route['refunds'] ?? []) as $line) {
                        $refundsTotal += self::num($line['amount'] ?? 0);
                    }
                    continue;
                }
                $routeCell = $label . ' — ' . implode(', ', $this->routeNames($route));
            }

            $routeRows = $this->writeCalcRows($s, $parts, self::c($routeCell, 'text'), (int) ($route['trips'] ?? 0), $routeValue, $l1, 'normal', $fallback);
            $valueRows = array_merge($valueRows, $routeRows);
            $groupParent = (int) end($routeRows);
            $totalTrips += (int) ($route['trips'] ?? 0);
            $totalValue += $routeValue;

            /* Cursele rutei, cu acelasi calcul pe coloane. */
            $trips = (array) ($route['trip_rows'] ?? []);
            if ($trips !== []) {
                $x->addRow($s, self::cols([
                    'A' => self::c('Activitate', 'sub_th_num'), 'B' => self::c('Preț activitate', 'sub_th_num'),
                    'C' => self::c('Nr. cursă · Data · Vehicul', 'sub_th'),
                    'D' => self::c('', 'sub_th'), 'E' => self::c('Valoare RON', 'sub_th_num'),
                ]), $l2);
            }
            foreach ($trips as $trip) {
                $tripRoute = $byPrice ? '  ·  ' . (string) ($trip['route_label'] ?? '') : '';
                $tripText = (string) ($trip['race_no'] ?? '-') . '  ·  ' . (string) ($trip['date_label'] ?? '-') . '  ·  ' . (string) ($trip['vehicle_label'] ?? '-') . $tripRoute;
                $tripParts = self::calcParts($trip, $unit);
                $noComponents = $tripParts === [] && ($trip['recomputed_total'] ?? null) !== null;
                $this->writeCalcRows($s, $tripParts, self::c($tripText, 'small'), null, self::num($trip['value'] ?? 0), $l2, 'small', $noComponents ? 'calcul indisponibil' : '');
            }

            /* Refacturarile rutei. */
            $routeRefunds = (array) ($route['refunds'] ?? []);
            if ($routeRefunds !== []) {
                $hasRefunds = true;
                foreach ($routeRefunds as $line) {
                    $refundsTotal += self::num($line['amount'] ?? 0);
                }
                $this->writeRefundPanel($s, $routeRefunds, 'Refacturări', $l2, $l3);
            }
            if ($trips !== [] || $routeRefunds !== []) {
                $x->markCollapsed($s, $groupParent);
            }
        }

        foreach ($leftovers as $line) {
            $refundsTotal += self::num($line['amount'] ?? 0);
        }

        /* TOTAL = suma formulelor de mai sus (doar randurile de ruta, nu si cursele din grupuri). */
        $sumFormula = $valueRows !== [] && count($valueRows) <= 250
            ? 'SUM(' . implode(',', array_map(static fn (int $r): string => 'E' . $r, $valueRows)) . ')'
            : null;
        $totalRow = $x->addRow($s, self::cols([
            'A' => self::c('TOTAL', 'total'),
            'D' => self::c($totalTrips, 'total_int'),
            'E' => ['v' => $totalValue, 's' => 'total_money', 'f' => $sumFormula],
        ], 'total'), $l1);
        if ($hasRefunds) {
            $refRow = $x->addRow($s, self::cols([
                'A' => self::c('TOTAL REFACTURĂRI', 'total'),
                'E' => self::c($refundsTotal, 'total_money'),
            ], 'total'), $l1);
            if ($leftovers !== []) {
                $this->writeRefundPanel($s, $leftovers, 'Refacturări curse din alte luni', $l2, $l3);
                $x->markCollapsed($s, $refRow);
            }
        }

        return $totalRow;
    }

    /* "Contesti - Sud" -> "Contesti → Sud", ca in restul exportului (doar primul separator). */
    private static function arrowRoute(string $label): string
    {
        $label = trim($label);

        return str_contains($label, '→') ? $label : (preg_replace('/\s+-\s+/u', ' → ', $label, 1) ?? $label);
    }

    /* Numele intregi ale rutelor unui grup ("Contesti → Sud"), in ordinea curselor. */
    private function routeNames(array $group): array
    {
        $names = [];
        foreach ((array) ($group['trip_rows'] ?? []) as $trip) {
            $name = self::arrowRoute((string) ($trip['route_label'] ?? ''));
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    /*
     * Un grup pe tarif (ex. "Tarif 70,00 RON/t") desfacut pe rute:
     *   rand de grup:  total cantitate | pret | "Tarif 70,00 RON/t · 3 rute" | curse | =SUM(rute)
     *   cate un rand per ruta:  tone ruta | pret | "Contesti → Sud" | curse | =A×B
     * Cursele fiecarei rute stau grupate sub randul ei. Merge doar cand toate cursele
     * grupului au un singur calcul cu acelasi pret; altfel intoarce null (afisare pe grup).
     *
     * @return int|null randul grupului (valoarea lui intra in TOTAL)
     */
    private function writePriceGroupByRoute(int $s, array $group, array $groupParts, array $l1, array $l2, array $l3): ?int
    {
        $x = $this->xlsx;
        if (count($groupParts) !== 1) {
            return null;
        }
        $unit = $groupParts[0]['unit'];
        $rate = $groupParts[0]['rate'];

        $byRoute = [];
        foreach ((array) ($group['trip_rows'] ?? []) as $trip) {
            $tripParts = self::calcParts($trip, $unit);
            if (count($tripParts) !== 1 || $tripParts[0]['unit'] !== $unit || abs($tripParts[0]['rate'] - $rate) > 0.00001) {
                return null;
            }
            $name = self::arrowRoute((string) ($trip['route_label'] ?? '')) ?: '-';
            $byRoute[$name] ??= ['qty' => 0.0, 'value' => 0.0, 'trips' => []];
            $byRoute[$name]['qty'] += $tripParts[0]['qty'];
            $byRoute[$name]['value'] += self::num($trip['value'] ?? 0);
            $byRoute[$name]['trips'][] = $trip;
        }
        if ($byRoute === []) {
            return null;
        }
        uasort($byRoute, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        $routeCount = count($byRoute);
        $groupRow = $x->addRow($s, self::cols([
            'A' => ['v' => self::num($group['quantity'] ?? 0), 's' => $this->qtyStyle($unit, 'group')],
            'B' => ['v' => $rate, 's' => $this->rateStyle($unit, 'group')],
            'C' => self::c((string) ($group['label'] ?? '-') . '  ·  ' . $routeCount . ($routeCount === 1 ? ' rută' : ' rute'), 'group'),
            'D' => self::c((int) ($group['trips'] ?? 0), 'group_int'),
            'E' => self::c(self::num($group['value'] ?? 0), 'group_money'),
        ], 'group'), $l1);

        /* Refacturarile grupului, direct sub randul lui (se deschid din + al grupului). */
        $refunds = (array) ($group['refunds'] ?? []);
        if ($refunds !== []) {
            $this->writeRefundPanel($s, $refunds, 'Refacturări', $l2, $l3);
            $x->markCollapsed($s, $groupRow);
        }

        $routeValueRows = [];
        foreach ($byRoute as $name => $routeData) {
            $rows = $this->writeCalcRows(
                $s,
                [['qty' => $routeData['qty'], 'unit' => $unit, 'rate' => $rate, 'average' => false]],
                self::c($name, 'text_l1'),
                count($routeData['trips']),
                round($routeData['value'], 2),
                $l1,
                'normal'
            );
            $routeValueRows = array_merge($routeValueRows, $rows);

            $x->addRow($s, self::cols([
                'A' => self::c('Activitate', 'sub_th_num'), 'B' => self::c('Preț activitate', 'sub_th_num'),
                'C' => self::c('Nr. cursă · Data · Vehicul', 'sub_th'),
                'D' => self::c('', 'sub_th'), 'E' => self::c('Valoare RON', 'sub_th_num'),
            ]), $l2);
            foreach ($routeData['trips'] as $trip) {
                $tripText = (string) ($trip['race_no'] ?? '-') . '  ·  ' . (string) ($trip['date_label'] ?? '-') . '  ·  ' . (string) ($trip['vehicle_label'] ?? '-');
                $this->writeCalcRows($s, self::calcParts($trip, $unit), self::c($tripText, 'small'), null, self::num($trip['value'] ?? 0), $l2, 'small');
            }
            $x->markCollapsed($s, (int) end($rows));
        }

        /* Valoarea grupului = suma randurilor de ruta (inclusiv eventualele ajustari). */
        $cells = self::cols([
            'A' => ['v' => self::num($group['quantity'] ?? 0), 's' => $this->qtyStyle($unit, 'group')],
            'B' => ['v' => $rate, 's' => $this->rateStyle($unit, 'group')],
            'C' => self::c((string) ($group['label'] ?? '-') . '  ·  ' . $routeCount . ($routeCount === 1 ? ' rută' : ' rute'), 'group'),
            'D' => self::c((int) ($group['trips'] ?? 0), 'group_int'),
            'E' => [
                'v' => self::num($group['value'] ?? 0),
                's' => 'group_money',
                'f' => count($routeValueRows) <= 250 ? 'SUM(' . implode(',', array_map(static fn (int $r): string => 'E' . $r, $routeValueRows)) . ')' : null,
            ],
        ], 'group');
        $x->replaceRowCells($s, $groupRow, $cells);

        return $groupRow;
    }

    /* Refacturarile unui rand: grupate pe Tip + Denumire, cu inregistrarile sub fiecare grup. */
    private function writeRefundPanel(int $s, array $lines, string $title, array $groupOpts, array $recordOpts): void
    {
        $x = $this->xlsx;
        $groups = [];
        $total = 0.0;
        foreach ($lines as $line) {
            $amount = self::num($line['amount'] ?? 0);
            $quantity = self::num($line['quantity'] ?? 0);
            $typeLabel = trim((string) ($line['type_label'] ?? ''));
            $name = trim((string) ($line['name'] ?? ''));
            $total += $amount;
            $key = mb_strtolower($typeLabel, 'UTF-8') . '|' . mb_strtolower($name, 'UTF-8');
            $groups[$key] ??= ['type_label' => $typeLabel, 'name' => $name, 'total' => 0.0, 'prices' => [], 'lines' => []];
            $groups[$key]['total'] += $amount;
            $groups[$key]['lines'][] = $line;
            $unitPrice = round(self::num($line['unit_price'] ?? $amount), 2);
            $priceKey = number_format($unitPrice, 2, '.', '');
            $groups[$key]['prices'][$priceKey] ??= ['unit_price' => $unitPrice, 'quantity' => 0.0];
            $groups[$key]['prices'][$priceKey]['quantity'] += $quantity > 0 ? $quantity : 1.0;
        }

        $x->addRow($s, self::cols([
            'A' => self::c($title, 'sub_th'), 'B' => self::c('', 'sub_th'),
            'C' => self::c('Tip · Denumire · Cantitate × Valoare unitară', 'sub_th'),
            'D' => self::c('', 'sub_th'), 'E' => self::c('Total', 'sub_th_num'),
        ]), $groupOpts);
        foreach ($groups as $group) {
            $parts = [];
            foreach ($group['prices'] as $price) {
                $parts[] = self::fmtSmart($price['quantity'], 2) . ' × ' . self::fmt($price['unit_price']);
            }
            $typeCell = $group['type_label'] !== '' ? $group['type_label'] : '-';
            $nameCell = $group['name'] !== '' ? $group['name'] : '-';
            $groupRow = $x->addRow($s, self::cols([
                'A' => self::c($typeCell, 'text_l2'),
                'B' => self::c('', 'small'),
                'C' => self::c($nameCell . '  ·  ' . implode(' + ', $parts), 'small'),
                'D' => self::c('', 'small'),
                'E' => self::c($group['total'], 'small_money'),
            ]), $groupOpts);
            foreach ($group['lines'] as $line) {
                $lineQty = self::num($line['quantity'] ?? 0);
                $x->addRow($s, self::cols([
                    'A' => self::c((string) ($line['date_label'] ?? '-'), 'text_l3'),
                    'B' => self::c(self::fmtSmart($lineQty > 0 ? $lineQty : 1, 2) . ' buc', 'small_text_num'),
                    'C' => self::c((string) ($line['vehicle_label'] ?? '-'), 'small'),
                    'D' => self::c('', 'small'),
                    'E' => self::c(self::num($line['amount'] ?? 0), 'small_money'),
                ]), $recordOpts);
            }
            $x->markCollapsed($s, $groupRow);
        }
        $x->addRow($s, self::cols([
            'A' => self::c('Total refacturări', 'total'), 'E' => self::c($total, 'total_money'),
        ], 'total', 5), $groupOpts);
    }

    private function writeDistributionSummary(int $s, int $width): void
    {
        $x = $this->xlsx;
        $dist = (array) ($this->report['distribution'] ?? []);
        $billing = (array) ($dist['billing'] ?? []);
        $metric = (string) ($billing['metric'] ?? 'tone');
        $shareBasis = (string) ($billing['share_basis'] ?? 'tone');
        $usesKm = !empty($billing['uses_km']);
        $metricFmt = static fn ($value): string => $metric === 'km' ? self::fmtKm($value) : self::fmtTone($value);

        $this->section($s, 'Distribuție - Rezumat', $width);
        if ((int) ($dist['total_trips'] ?? 0) <= 0) {
            $x->addRow($s, [self::c('Nu există activitate de distribuție pentru filtrul curent.', 'muted')]);
            $x->addRow($s);

            return;
        }

        $secondary = $metric === 'km'
            ? self::fmtTone($dist['total_tone'] ?? 0) . ' transportate'
            : ($usesKm ? self::fmtKm($dist['total_km'] ?? 0) . ' facturați pe km' : '');
        $x->addRow($s, self::cols([
            'A' => self::c($metric === 'km' ? 'Total km facturați' : 'Total tone', 'kpi_label'),
            'B' => self::c('', 'kpi_label'),
            'C' => self::c($metricFmt($dist['total_' . $metric] ?? 0), 'kpi_value'),
            'D' => self::c($secondary, 'muted'),
        ]), ['height' => 22]);

        $x->addRow($s, self::cols([
            'A' => self::c('Tip marfă', 'th'), 'C' => self::c($metric === 'km' ? 'Km' : 'Tone', 'th_num'),
            'D' => self::c('', 'th'), 'E' => self::c('% din total', 'th_num'),
        ], 'th', 5));
        foreach ((array) ($dist['cargo_totals'] ?? []) as $cargo) {
            $x->addRow($s, self::cols([
                'A' => self::c((string) ($cargo['label'] ?? '-'), 'text'),
                'C' => self::c($metricFmt($cargo[$metric] ?? 0), 'text_num'),
                'E' => self::c(self::num($cargo['percent'] ?? 0), 'percent'),
            ], 'text', 5));
        }

        $rateUnit = (string) ($billing['rate_unit_label'] ?? '');
        $x->addRow($s, self::cols([
            'A' => self::c('Split preț' . ($rateUnit !== '' ? ' (' . $rateUnit . ')' : ($shareBasis === 'value' ? ' (pe valoare)' : '')), 'th'),
            'B' => self::c('Preț', 'th_num'), 'C' => self::c('Cantitate', 'th_num'), 'D' => self::c('', 'th'),
            'E' => self::c('% din total', 'th_num'),
        ], 'th', 5));
        foreach ((array) ($dist['tariff_buckets'] ?? []) as $bucket) {
            $qty = match ($shareBasis) {
                'km' => self::fmtSmart($bucket['km'] ?? 0, 0) . ' km',
                'tone' => self::fmtSmart($bucket['tone'] ?? 0, 2) . ' t' . ($usesKm ? ' · ' . self::fmtSmart($bucket['km'] ?? 0, 0) . ' km' : ''),
                default => self::fmt($bucket['value'] ?? 0) . ' RON',
            };
            $x->addRow($s, self::cols([
                'A' => self::c((string) ($bucket['label'] ?? '-'), 'text'),
                'B' => self::c(($bucket['rate_label'] ?? '') !== '' ? (string) $bucket['rate_label'] : '-', 'text_num'),
                'C' => self::c($qty, 'text_num'),
                'E' => self::c(self::num($bucket['percent'] ?? 0), 'percent'),
            ], 'text', 5));
        }
        foreach ((array) ($dist['warnings'] ?? []) as $warning) {
            $x->addRow($s, [self::c((string) $warning, 'warn')], ['merge' => $width]);
            break;
        }
        $x->addRow($s);
    }

    /*
     * "Detalii Primar km - pe rute": acelasi tipar ca tabelul de facturare -
     * Km parcurși (A) × Preț / km (B) = Valoare (E, formula).
     */
    private function writePrimaryRoutes(int $s, int $width): void
    {
        $x = $this->xlsx;
        $routes = (array) ($this->report['primary_routes']['routes'] ?? []);
        $totals = (array) ($this->report['primary_routes']['totals'] ?? []);
        $signed = static fn ($value): string => (self::num($value) > 0 ? '+' : '') . self::fmt($value) . '%';

        $this->section($s, 'Detalii Primar km - pe rute', $width);
        $x->addRow($s, self::cols([
            'A' => self::c('Km parcurși', 'th_num'), 'B' => self::c('Preț / km (RON)', 'th_num'), 'C' => self::c('Rută', 'th'),
            'D' => self::c('Curse', 'th_num'), 'E' => self::c('Valoare (RON)', 'th_num'), 'F' => self::c('Perioadă curse', 'th'),
        ], 'th'));
        $valueRows = [];
        foreach ($routes as $route) {
            $tariff = is_array($route['tariff'] ?? null) ? $route['tariff'] : null;
            $isSplit = (int) ($route['route_rate_count'] ?? 1) > 1;
            $isContinuation = $isSplit && empty($route['is_route_first']);
            $label = ($isContinuation ? '↳ ' : '') . (string) ($route['route_short'] ?? '-') . ' (' . (string) ($route['route_label'] ?? '-') . ')';
            if ($isSplit && !empty($route['is_route_first'])) {
                $label .= '  ·  ' . (string) $route['route_rate_count'] . ' tarife în perioadă';
            }
            if ($tariff !== null && !empty($tariff['changed_in_period'])) {
                $label .= '  ·  tarif nou din ' . (string) $tariff['valid_from_label'];
            }
            $km = self::num($route['km'] ?? 0);
            $value = self::num($route['value'] ?? 0);
            $rate = self::num(str_replace(['.', ','], ['', '.'], (string) ($route['rate_label'] ?? '')));
            $formulaOk = $km > 0 && $rate > 0 && abs(round($km * $rate, 2) - $value) < 0.01;
            $routeRow = $x->addRow($s, self::cols([
                'A' => $km > 0 ? ['v' => $km, 's' => $this->qtyStyle('km', 'normal')] : self::c('-', 'text_num'),
                'B' => $rate > 0 ? ['v' => $rate, 's' => $this->rateStyle('km', 'normal')] : self::c((string) ($route['rate_label'] ?? '-'), 'text_num'),
                'C' => self::c($label, 'text'),
                'D' => self::c((int) ($route['trips'] ?? 0), 'int'),
                'E' => ['v' => $value, 's' => 'money', 'f' => $formulaOk ? 'A{r}*B{r}' : null],
                'F' => self::c((string) ($route['period_label'] ?? '-'), 'small'),
            ]));
            $valueRows[] = $routeRow;

            /* Explicatia tarifului, ca in randul desfasurat din pagina. */
            $facts = [];
            if ($tariff !== null) {
                if (!empty($tariff['changed_in_period'])) {
                    $facts[] = ['Atenție', 'Tariful s-a schimbat în interiorul lunii raportate, de la ' . (string) $tariff['valid_from_label'] . '.'];
                }
                $facts[] = ['Tarif aplicat', self::fmt($tariff['value'] ?? 0) . ' ' . (string) ($tariff['unit'] ?? '') . ' (' . (string) ($tariff['component_label'] ?? '') . ')'];
                $facts[] = ['În vigoare', (string) ($tariff['valid_from_label'] ?? '-') . ' → ' . (string) ($tariff['valid_to_label'] ?? 'în continuare')];
                if (($tariff['previous_value'] ?? null) !== null) {
                    $facts[] = ['Tarif anterior', self::fmt($tariff['previous_value']) . ' ' . (string) ($tariff['unit'] ?? '')
                        . (($tariff['delta_percent'] ?? null) !== null ? '  ' . $signed($tariff['delta_percent']) : '')];
                }
                $facts[] = ['Curse la acest tarif', self::fmtSmart($route['trips'] ?? 0, 0) . ' · ' . (string) ($route['period_label'] ?? '-')];
                if (($tariff['changed_by'] ?? '') !== '' || ($tariff['changed_at_label'] ?? '') !== '') {
                    $facts[] = ['Operat de', trim((string) ($tariff['changed_by'] ?? '-') . (($tariff['changed_at_label'] ?? '') !== '' ? ' · ' . $tariff['changed_at_label'] : ''))];
                }
                if (($tariff['fuel']['variation_percent'] ?? null) !== null) {
                    $fuel = (array) $tariff['fuel'];
                    $text = $signed($fuel['variation_percent']);
                    if (($fuel['reference_price'] ?? null) !== null && ($fuel['observed_price'] ?? null) !== null) {
                        $text .= '  referință ' . self::fmt($fuel['reference_price'], 4) . ' → observat ' . self::fmt($fuel['observed_price'], 4) . ' lei/L';
                    }
                    $facts[] = ['Variație combustibil', $text];
                }
                if (($tariff['reason'] ?? '') !== '') {
                    $facts[] = ['Motiv', (string) $tariff['reason']];
                }
            } else {
                $facts[] = ['Tarif', 'Nu am găsit o versiune de tarif cu această valoare în Administrare tarife pentru beneficiarul selectat.'];
            }
            foreach ($facts as [$factLabel, $factValue]) {
                $x->addRow($s, self::cols(['A' => self::c($factLabel, 'text_l2'), 'C' => self::c($factValue, 'small')]), ['level' => 1, 'hidden' => true]);
            }
            $x->markCollapsed($s, $routeRow);
        }
        $x->addRow($s, self::cols([
            'A' => self::c(self::fmtKm($totals['km'] ?? 0), 'total_text_num'),
            'B' => self::c('-', 'total_text_num'),
            'C' => self::c('TOTAL', 'total'),
            'D' => self::c((int) ($totals['trips'] ?? 0), 'total_int'),
            'E' => ['v' => self::num($totals['value'] ?? 0), 's' => 'total_money', 'f' => $valueRows !== [] && count($valueRows) <= 250 ? 'SUM(' . implode(',', array_map(static fn (int $r): string => 'E' . $r, $valueRows)) . ')' : null],
        ], 'total', 6));
        $x->addRow($s);
    }

    private function writeDistributionMatrix(int $s): void
    {
        $x = $this->xlsx;
        $dist = (array) ($this->report['distribution'] ?? []);
        $billing = (array) ($dist['billing'] ?? []);
        $metric = (string) ($billing['metric'] ?? 'tone');
        $unit = $metric === 'km' ? 'km' : 'tone';
        $decimals = $metric === 'km' ? 0 : 2;
        $extraKm = $metric !== 'km' && !empty($billing['uses_km']);
        $buckets = (array) ($dist['tariff_buckets'] ?? []);
        $numStyle = $decimals === 0 ? 'int' : 'num2';
        $width = max(4, count($buckets) + 4);

        $this->section($s, 'Detalii Distribuție - pe marfă și preț', $width);
        $head = [self::c('Tip marfă', 'th')];
        foreach ($buckets as $bucket) {
            $label = (string) ($bucket['label'] ?? '-');
            if (($bucket['rate_label'] ?? '') !== '' && str_starts_with($label, 'Preț')) {
                $label .= ' · ' . $bucket['rate_label'];
            }
            $head[] = self::c($label . ' (' . $unit . ')', 'th_num');
        }
        $head[] = self::c('Total ' . $unit, 'th_num');
        if ($metric === 'km') {
            $head[] = self::c('Total tone', 'th_num');
        } elseif ($extraKm) {
            $head[] = self::c('Total km', 'th_num');
        }
        $head[] = self::c('% din total', 'th_num');
        $x->addRow($s, $head);

        $cargoRows = (array) ($dist['cargo_by_tariff'] ?? []);
        if ($cargoRows === []) {
            $x->addRow($s, [self::c('Nu există activitate de distribuție pentru filtrul curent.', 'muted')]);
        }
        foreach ($cargoRows as $row) {
            $line = [self::c((string) ($row['label'] ?? '-'), 'text')];
            foreach ($buckets as $bucket) {
                $line[] = self::numOrDash($row['buckets'][(string) ($bucket['key'] ?? '')] ?? 0, $numStyle, 'text_num');
            }
            $line[] = self::c(self::num($row['total_' . $metric] ?? 0), $numStyle);
            if ($metric === 'km') {
                $line[] = self::c(self::num($row['total_tone'] ?? 0), 'num2');
            } elseif ($extraKm) {
                $line[] = self::c(self::num($row['total_km'] ?? 0), 'int');
            }
            $line[] = self::c(self::num($row['percent'] ?? 0), 'percent');
            $x->addRow($s, $line);
        }

        $matrixTotals = (array) ($dist['matrix_totals'] ?? []);
        $totalNum = $decimals === 0 ? 'total_int' : 'total_num2';
        $line = [self::c('TOTAL', 'total')];
        foreach ($buckets as $bucket) {
            $line[] = self::numOrDash($matrixTotals['buckets'][(string) ($bucket['key'] ?? '')] ?? 0, $totalNum, 'total_text_num');
        }
        $line[] = self::c(self::num($matrixTotals['total_' . $metric] ?? 0), $totalNum);
        if ($metric === 'km') {
            $line[] = self::c(self::num($matrixTotals['total_tone'] ?? 0), 'total_num2');
        } elseif ($extraKm) {
            $line[] = self::c(self::num($matrixTotals['total_km'] ?? 0), 'total_int');
        }
        $line[] = self::c(self::num($matrixTotals['percent'] ?? 0) > 0 ? 100.0 : 0.0, 'total_percent');
        $x->addRow($s, $line);
        $x->addRow($s);
    }

    private function writeTariffEvolution(int $s, int $width): void
    {
        $x = $this->xlsx;
        $rows = (array) ($this->report['tariff_evolution']['rows'] ?? []);
        $showBeneficiary = count(array_unique(array_column($rows, 'beneficiary'))) > 1;
        $date = static function (?string $value): string {
            $value = trim((string) $value);
            $ts = $value !== '' ? strtotime($value) : false;

            return $value === '' ? '-' : ($ts !== false ? date('d.m.Y', $ts) : $value);
        };

        $this->section($s, 'Evoluție tarife (Administrare tarife)', $width);
        if ($rows === []) {
            $x->addRow($s, [self::c('Nu există tarife versionate pentru luna și filtrele selectate.', 'muted')]);
            $x->addRow($s);

            return;
        }
        $x->addRow($s, self::cols([
            'A' => self::c('Tarif', 'th'), 'B' => self::c('Valoare', 'th_num'), 'C' => self::c('Aplicare · Rută', 'th'),
            'D' => self::c('', 'th'), 'E' => self::c('Valoare anterioară', 'th_num'), 'F' => self::c('Valabil din', 'th_num'),
            'G' => self::c('Până la', 'th_num'), 'H' => self::c('Modificat în Administrare tarife', 'th'),
        ], 'th'));
        foreach ($rows as $row) {
            $previous = $row['previous_value'] ?? null;
            $delta = $previous !== null && self::num($previous) > 0
                ? ((self::num($row['value'] ?? 0) - self::num($previous)) / self::num($previous)) * 100
                : null;
            $previousText = $previous === null
                ? 'primul tarif'
                : self::fmt($previous) . ($delta !== null ? '  ' . ($delta >= 0 ? '+' : '') . self::fmt($delta) . '%' : '');
            $changedBy = trim((string) ($row['changed_by'] ?? ''));
            $changedAt = trim((string) ($row['changed_at'] ?? ''));
            $meta = $changedBy !== '' || $changedAt !== '' ? trim($changedBy . ($changedAt !== '' ? ' · ' . $date($changedAt) : '')) : '-';
            if (($row['fuel_variation'] ?? null) !== null) {
                $meta .= '  (' . (self::num($row['fuel_variation']) >= 0 ? '+' : '') . self::fmt($row['fuel_variation']) . '% combustibil)';
            }
            $tariffLabel = (string) ($row['component_label'] ?? '-');
            if ($showBeneficiary) {
                $tariffLabel = (string) ($row['beneficiary'] ?? '-') . ' — ' . $tariffLabel;
            }
            $scope = trim((string) ($row['transport_label'] ?? '') . ' · ' . (string) ($row['route_label'] ?? '-'), ' ·');
            $x->addRow($s, self::cols([
                'A' => self::c($tariffLabel, 'text'),
                'B' => self::c(self::fmt($row['value'] ?? 0) . ' ' . (string) ($row['unit'] ?? ''), 'text_num'),
                'C' => self::c($scope, 'text'),
                'D' => self::c('', 'text'),
                'E' => self::c($previousText, 'text_num'),
                'F' => self::c($date((string) ($row['valid_from'] ?? '')), 'text_num'),
                'G' => self::c(($row['valid_to'] ?? null) !== null ? $date((string) $row['valid_to']) : 'în vigoare', 'text_num'),
                'H' => self::c($meta, 'text'),
            ]));
        }
        $x->addRow($s);
    }

    private function writeRefundSummary(int $s, int $width): void
    {
        $x = $this->xlsx;
        $labels = [
            'taxa_acces' => 'Taxă acces', 'port' => 'Port', 'trece' => 'Trecere', 'motorina' => 'Motorină',
            'taxe_drum' => 'Taxe drum', 'diurna' => 'Diurnă', 'service' => 'Service', 'alte' => 'Alte treceri',
            'difference' => 'Diferență nealocată',
        ];
        $byType = [];
        foreach ((array) ($this->report['refacturari']['summary_groups'] ?? []) as $group) {
            $type = (string) ($group['type'] ?? 'alte');
            $label = $labels[$type] ?? ($type !== '' ? mb_convert_case(str_replace('_', ' ', $type), MB_CASE_TITLE, 'UTF-8') : 'Nespecificat');
            $byType[$type] ??= ['label' => $label, 'quantity' => 0.0, 'amount' => 0.0, 'lines' => []];
            $byType[$type]['quantity'] += self::num($group['quantity'] ?? 0);
            $byType[$type]['amount'] += self::num($group['amount'] ?? 0);
            $byType[$type]['lines'][] = $group;
        }
        uasort($byType, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        $this->section($s, 'Refacturări - sumar pe tipuri', $width);
        if ($byType === []) {
            $x->addRow($s, [self::c('Nu există refacturări pentru filtrul curent.', 'muted')]);

            return;
        }
        $x->addRow($s, self::cols(['A' => self::c('Tip', 'th'), 'D' => self::c('Nr.', 'th_num'), 'E' => self::c('Valoare (RON)', 'th_num')], 'th', 5));
        $typeRows = [];
        foreach ($byType as $type) {
            $typeRow = $x->addRow($s, self::cols([
                'A' => self::c($type['label'], 'group'),
                'D' => self::c($type['quantity'], 'group_num2'), 'E' => self::c($type['amount'], 'group_money'),
            ], 'group', 5));
            $typeRows[] = $typeRow;
            foreach ($type['lines'] as $line) {
                $x->addRow($s, self::cols([
                    'A' => self::c((string) ($line['label'] ?? '-'), 'text_l1'),
                    'D' => self::c(self::num($line['quantity'] ?? 0), 'num2'), 'E' => self::c(self::num($line['amount'] ?? 0), 'money'),
                ], 'text', 5), ['level' => 1, 'hidden' => true]);
            }
            $x->markCollapsed($s, $typeRow);
        }
        $refs = static fn (string $col): string => 'SUM(' . implode(',', array_map(static fn (int $r): string => $col . $r, $typeRows)) . ')';
        $x->addRow($s, self::cols([
            'A' => self::c('TOTAL REFACTURĂRI', 'total'),
            'D' => ['v' => array_sum(array_column($byType, 'quantity')), 's' => 'total_num2', 'f' => $refs('D')],
            'E' => ['v' => array_sum(array_column($byType, 'amount')), 's' => 'total_money', 'f' => $refs('E')],
        ], 'total', 5));
        $x->addRow($s, [self::c('Numărul reprezintă bucăți pentru taxele de drum (acces, port, trecere) și număr de înregistrări pentru celelalte tipuri. Lista completă pe curse este în foaia „Refacturări”.', 'muted')]);
    }

    /* ---------------------------------------------------------------- foaia Vehicule */

    /*
     * "Activitate pe vehicule - Detaliat": grupat pe categoria de capacitate, cu
     * vehiculele sub fiecare categorie si cursele sub fiecare vehicul.
     */
    private function buildVehicleSheet(): void
    {
        if (empty(((array) ($this->report['visibility'] ?? []))['vehicle_detail'])) {
            return;
        }
        $x = $this->xlsx;
        $vehicles = (array) ($this->report['vehicles'] ?? []);
        $detail = (array) ($vehicles['detail'] ?? []);
        $columns = array_values(array_filter((array) ($detail['columns'] ?? []), static fn (array $col): bool => (string) ($col['key'] ?? '') !== 'toggle'));
        /* Ruta imediat dupa vehicul, ca textul lung sa stea in coloana lata. */
        usort($columns, static fn (array $a, array $b): int => (((string) ($a['key'] ?? '')) === 'vehicle' ? 0 : (((string) ($a['key'] ?? '')) === 'route_summary' ? 1 : 2))
            <=> (((string) ($b['key'] ?? '')) === 'vehicle' ? 0 : (((string) ($b['key'] ?? '')) === 'route_summary' ? 1 : 2)));
        $tripColumns = (array) ($vehicles['detail_columns'] ?? []);

        $s = $x->addSheet('Vehicule');
        $widths = [1 => 30, 2 => 40];
        for ($i = 3; $i <= max(count($columns), count($tripColumns) + 1) + 1; $i++) {
            $widths[$i] = 16;
        }
        $x->setColumnWidths($s, $widths);
        $x->addRow($s, [self::c('Activitate pe vehicule - Detaliat (după activitatea filtrată)', 'title')], ['height' => 24]);
        $x->addRow($s, [self::c('Raport pentru: ' . $this->scopeBeneficiary() . '  ·  ' . (string) ($this->report['filters']['month_label'] ?? ''), 'muted')]);
        $x->addRow($s);

        $labelFor = static function (array $col): string {
            return match ((string) ($col['key'] ?? '')) {
                'vehicle' => 'Categorie / Vehicul',
                'capacity' => 'Vehicule / Capacitate reală',
                default => (string) ($col['label'] ?? ''),
            };
        };
        $head = [];
        foreach ($columns as $col) {
            $head[] = self::c($labelFor($col), ($col['align'] ?? '') === 'right' ? 'th_num' : 'th');
        }
        $headRow = $x->addRow($s, $head);
        $x->freezeRows($s, $headRow);

        $cellFor = static function (string $key, mixed $value, string $prefix): array {
            $num = $prefix === 'group' ? 'group_' : ($prefix === 'total' ? 'total_' : '');
            $txt = $prefix === 'group' ? 'group' : ($prefix === 'total' ? 'total' : 'text');
            return match ($key) {
                'value' => self::c(self::num($value), $num . 'money'),
                'trips' => self::c((int) self::num($value), $num . 'int'),
                'km' => self::c(self::fmtKm($value), $num . 'text_num'),
                'tone' => self::c(self::fmtTone($value), $num . 'text_num'),
                'activity' => self::c(self::num($value) > 0 ? self::fmtSmart($value, 2) : '-', $num . 'text_num'),
                default => self::c(trim((string) $value) !== '' ? (string) $value : '-', $txt),
            };
        };

        $rows = (array) ($detail['rows'] ?? []);
        if ($rows === []) {
            $x->addRow($s, [self::c('Nu există vehicule cu activitate pentru filtrul curent.', 'muted')]);

            return;
        }

        foreach ($this->groupByCapacity($rows) as $group) {
            $sums = ['trips' => 0.0, 'km' => 0.0, 'tone' => 0.0, 'activity' => 0.0, 'value' => 0.0];
            foreach ($group['rows'] as $row) {
                foreach (array_keys($sums) as $metric) {
                    $sums[$metric] += self::num($row[$metric] ?? 0);
                }
            }
            $cells = [];
            foreach ($columns as $col) {
                $key = (string) ($col['key'] ?? '');
                $cells[] = match ($key) {
                    'vehicle' => self::c($group['category'] ?? 'Fără categorie', 'group'),
                    'capacity' => self::c($group['vehicles'] . ($group['vehicles'] === 1 ? ' vehicul' : ' vehicule'), 'group_text_num'),
                    'route_summary' => self::c('-', 'group'),
                    'value', 'km', 'tone', 'trips', 'activity' => $cellFor($key, $sums[$key], 'group'),
                    default => self::c('-', 'group'),
                };
            }
            $groupRow = $x->addRow($s, $cells, ['height' => 20]);

            foreach ($group['rows'] as $row) {
                $cells = [];
                foreach ($columns as $col) {
                    $key = (string) ($col['key'] ?? '');
                    $cells[] = match ($key) {
                        'vehicle' => self::c((string) ($row['vehicle'] ?? '-'), 'text_l1'),
                        'capacity' => self::c(self::num($row['capacity'] ?? 0) > 0 ? self::fmtSmart($row['capacity'], 2) . ' t' : '-', 'text_num'),
                        default => $cellFor($key, $row[$key] ?? null, 'row'),
                    };
                }
                $vehicleRow = $x->addRow($s, $cells, ['level' => 1, 'hidden' => true]);

                $rowTripColumns = (array) ($row['detail_columns'] ?? $tripColumns);
                $tripRows = (array) ($row['detail_rows'] ?? []);
                if ($tripRows === []) {
                    continue;
                }
                $tripHead = [self::c('', 'sub_th')];
                foreach ($rowTripColumns as $col) {
                    $tripHead[] = self::c((string) ($col['label'] ?? ''), ($col['align'] ?? '') === 'right' ? 'sub_th_num' : 'sub_th');
                }
                $x->addRow($s, $tripHead, ['level' => 2, 'hidden' => true]);
                foreach ($tripRows as $trip) {
                    $tripCells = [self::c('', 'small')];
                    foreach ($rowTripColumns as $col) {
                        $tripCells[] = $this->tripDetailCell($trip, $col);
                    }
                    $x->addRow($s, $tripCells, ['level' => 2, 'hidden' => true]);
                }
                $x->markCollapsed($s, $vehicleRow);
            }
            $x->markCollapsed($s, $groupRow);
        }

        $totals = (array) ($detail['totals'] ?? []);
        $cells = [];
        foreach ($columns as $col) {
            $key = (string) ($col['key'] ?? '');
            $cells[] = match ($key) {
                'vehicle' => self::c('TOTAL', 'total'),
                'value', 'km', 'tone', 'trips', 'activity' => $cellFor($key, $totals[$key] ?? 0, 'total'),
                default => self::c('-', 'total_text_num'),
            };
        }
        $x->addRow($s, $cells);
        $x->addRow($s, [self::c('Se afișează doar vehiculele care au activitate pentru filtrele selectate. Apasă + pentru vehiculele unei categorii și cursele unui vehicul.', 'muted')]);
    }

    private function tripDetailCell(array $row, array $col): array
    {
        $value = $row[(string) ($col['key'] ?? '')] ?? null;

        return match ((string) ($col['format'] ?? 'text')) {
            'money' => self::c(self::num($value), 'small_money'),
            'km' => self::num($value) > 0 ? self::c(self::num($value), 'small_int') : self::c('-', 'small_text_num'),
            'tone' => self::num($value) > 0 ? self::c(self::num($value), 'small_num2') : self::c('-', 'small_text_num'),
            'tariff' => self::c(self::num($value) > 0 ? self::fmtSmart($value, 2) . ' RON/t' : '-', 'small_text_num'),
            'rate_km' => self::c(self::num($value) > 0 ? self::fmtSmart($value, 4) : '-', 'small_text_num'),
            default => self::c(trim((string) $value) !== '' ? (string) $value : '-', ($col['align'] ?? '') === 'right' ? 'small_text_num' : 'small'),
        };
    }

    /* Aceeasi grupare ca in pagina: pe categoria de capacitate, "Fara categorie" ultima. */
    private function groupByCapacity(array $rows): array
    {
        $descending = (string) ($this->report['filters']['vehicle_sort'] ?? '') === 'capacity_desc';
        $groups = [];
        foreach ($rows as $row) {
            $categoryId = (int) ($row['capacity_category_id'] ?? 0);
            $key = $categoryId > 0 ? 'cat' . $categoryId : 'none';
            $groups[$key] ??= [
                'category' => $categoryId > 0 ? (string) ($row['capacity_category'] ?? '') : null,
                'order' => $categoryId > 0 ? (int) ($row['capacity_category_order'] ?? 0) : PHP_INT_MAX,
                'vehicles' => 0,
                'rows' => [],
            ];
            $groups[$key]['vehicles']++;
            $groups[$key]['rows'][] = $row;
        }
        uasort($groups, static function (array $a, array $b) use ($descending): int {
            if ($a['category'] === null || $b['category'] === null) {
                return ($a['category'] === null ? 1 : 0) <=> ($b['category'] === null ? 1 : 0);
            }
            $byOrder = $descending ? ($b['order'] <=> $a['order']) : ($a['order'] <=> $b['order']);

            return $byOrder ?: strcmp((string) $a['category'], (string) $b['category']);
        });

        return array_values($groups);
    }

    /* ---------------------------------------------------------------- foi de date */

    /* Lista completa a refacturarilor (vederea "Detaliat" din pagina). */
    private function buildRefundsSheet(): void
    {
        $x = $this->xlsx;
        $rows = (array) ($this->report['refacturari']['all_rows'] ?? []);
        $s = $x->addSheet('Refacturări');
        $x->setColumnWidths($s, [1 => 14, 2 => 12, 3 => 22, 4 => 32, 5 => 13, 6 => 16, 7 => 10, 8 => 10, 9 => 18, 10 => 18, 11 => 50]);
        $headRow = $x->addRow($s, [
            self::c('Nr. cursă', 'th'), self::c('Data', 'th'), self::c('Tip activitate', 'th'), self::c('Rută / Zonă', 'th'),
            self::c('Vehicul', 'th'), self::c('Tip marfă', 'th'), self::c('Tone', 'th_num'), self::c('Km', 'th_num'),
            self::c('Valoare cursă (RON)', 'th_num'), self::c('Refacturare (RON)', 'th_num'), self::c('Observații', 'th'),
        ]);
        $x->freezeRows($s, $headRow);
        $tripTotal = 0.0;
        $refTotal = 0.0;
        foreach ($rows as $row) {
            $tripTotal += self::num($row['trip_value'] ?? 0);
            $refTotal += self::num($row['refacturare_amount'] ?? 0);
            $x->addRow($s, [
                self::c((string) ($row['race_no'] ?? '-'), 'text'),
                self::c((string) ($row['date_label'] ?? '-'), 'text'),
                self::c((string) ($row['tip_transport_label'] ?? '-'), 'text'),
                self::c((string) ($row['route_label'] ?? '-'), 'text'),
                self::c((string) ($row['vehicle_label'] ?? '-'), 'text'),
                self::c((string) ($row['tip_marfa_label'] ?? '-'), 'text'),
                self::numOrDash($row['tone'] ?? 0, 'num2', 'text_num'),
                self::numOrDash($row['km'] ?? 0, 'int', 'text_num'),
                self::c(self::num($row['trip_value'] ?? 0), 'money'),
                self::c(self::num($row['refacturare_amount'] ?? 0), 'money'),
                self::c(implode(' / ', array_values((array) ($row['observations'] ?? []))) ?: '-', 'text'),
            ]);
        }
        if ($rows === []) {
            $x->addRow($s, [self::c('Nu există refacturări pentru filtrul curent.', 'muted')]);

            return;
        }
        $totals = (array) ($this->report['refacturari']['totals_by_table'] ?? []);
        $x->addRow($s, [
            self::c('TOTAL REFACTURĂRI', 'total'), self::c('', 'total'), self::c('', 'total'), self::c('', 'total'), self::c('', 'total'),
            self::c('', 'total'), self::c('', 'total'), self::c('', 'total'),
            self::c(self::num($totals['trip_value'] ?? $tripTotal), 'total_money'),
            self::c(self::num($totals['refacturare'] ?? $refTotal), 'total_money'),
            self::c('', 'total'),
        ]);
    }

    /* Toate cursele, un rand per cursa: foaia pentru filtrare/pivot in Excel. */
    private function buildTripsSheet(): void
    {
        $x = $this->xlsx;
        $s = $x->addSheet('Curse');
        $x->setColumnWidths($s, [1 => 13, 2 => 12, 3 => 14, 4 => 22, 5 => 20, 6 => 18, 7 => 32, 8 => 16, 9 => 10, 10 => 10, 11 => 26, 12 => 26, 13 => 20, 14 => 16]);
        $headRow = $x->addRow($s, [
            self::c('Vehicul', 'th'), self::c('Data', 'th'), self::c('Nr. cursă', 'th'), self::c('Tip activitate', 'th'),
            self::c('Loc încărcare', 'th'), self::c('Zonă descărcare', 'th'), self::c('Rută', 'th'), self::c('Tip marfă', 'th'),
            self::c('Km', 'th_num'), self::c('Tone', 'th_num'), self::c('Tarif', 'th'), self::c('Clasificare tarif', 'th'),
            self::c('Activitate compresor', 'th'), self::c('Valoare (RON)', 'th_num'),
        ]);
        $x->freezeRows($s, $headRow);
        $total = 0.0;
        foreach ((array) ($this->report['vehicles']['rows'] ?? []) as $vehicle) {
            foreach ((array) ($vehicle['detail_rows'] ?? []) as $row) {
                $total += self::num($row['value'] ?? 0);
                $x->addRow($s, [
                    self::c((string) ($vehicle['nr_inmatriculare'] ?? ''), 'text'),
                    self::c((string) ($row['date_label'] ?? ''), 'text'),
                    self::c((string) ($row['race_no'] ?? ''), 'text'),
                    self::c((string) ($row['type_label'] ?? ''), 'text'),
                    self::c((string) ($row['loc_label'] ?? ''), 'text'),
                    self::c((string) ($row['zone_label'] ?? ''), 'text'),
                    self::c((string) ($row['route_label'] ?? ''), 'text'),
                    self::c((string) ($row['cargo_label'] ?? ''), 'text'),
                    self::numOrDash($row['km'] ?? 0, 'int', 'text_num'),
                    self::numOrDash($row['tone'] ?? 0, 'num2', 'text_num'),
                    self::c((string) (($row['tariff_label'] ?? '') !== '' ? $row['tariff_label'] : ($row['tariff'] ?? '')), 'text'),
                    self::c((string) ($row['tariff_class'] ?? ''), 'text'),
                    self::c((string) ($row['compressor_activity_label'] ?? ''), 'text'),
                    self::c(self::num($row['value'] ?? 0), 'money'),
                ]);
            }
        }
        $cells = [self::c('TOTAL', 'total')];
        for ($i = 0; $i < 12; $i++) {
            $cells[] = self::c('', 'total');
        }
        $cells[] = self::c($total, 'total_money');
        $x->addRow($s, $cells);
    }

    /* ---------------------------------------------------------------- context */

    private function isAllBeneficiaries(): bool
    {
        return !empty($this->report['scope']['all_beneficiaries']);
    }

    private function scopeBeneficiary(): string
    {
        return (string) ($this->report['scope']['beneficiary'] ?? 'Toți beneficiarii');
    }

    /** @return string[] */
    private function activeFilterLabels(): array
    {
        /* Aceleasi etichete ca bara "Raport pentru" din pagina (construite in serviciu). */
        return array_map(
            static fn (array $filter): string => (string) ($filter['label'] ?? '') . ': ' . (string) ($filter['value'] ?? ''),
            (array) ($this->report['scope']['active'] ?? [])
        );
    }

    private function hasActiveFilters(): bool
    {
        return $this->activeFilterLabels() !== [];
    }
}
