<?php
declare(strict_types=1);

$report = is_array($report ?? null) ? $report : [];
$filters = (array) ($report['filters'] ?? []);
$lookups = (array) ($report['lookups'] ?? []);
$kpiCards = (array) ($report['kpis']['cards'] ?? []);
$refKpi = (array) ($report['kpis']['refacturari'] ?? []);
$visibility = (array) ($report['visibility'] ?? []);
$activityRows = (array) ($report['activity']['rows'] ?? []);
$activityTotals = (array) ($report['activity']['totals'] ?? []);
$activityChart = (array) ($report['activity']['chart'] ?? []);
$distribution = (array) ($report['distribution'] ?? []);
$primaryRoutes = (array) ($report['primary_routes']['routes'] ?? []);
$primaryTotals = (array) ($report['primary_routes']['totals'] ?? []);
$vehicles = (array) ($report['vehicles'] ?? []);
$vehicleRows = (array) ($vehicles['rows'] ?? []);
$vehicleTripColumns = (array) ($vehicles['detail_columns'] ?? []);
$vehicleDetail = (array) ($vehicles['detail'] ?? []);
$refacturari = (array) ($report['refacturari'] ?? []);
$refGroups = (array) ($refacturari['summary_groups'] ?? []);
$refTypeGroups = (array) ($refacturari['type_groups'] ?? []);
$refRows = (array) ($refacturari['rows'] ?? []);
$refPagination = (array) ($refacturari['pagination'] ?? []);
$tariffEvolution = (array) ($report['tariff_evolution']['rows'] ?? []);
$tariffShowBeneficiary = count(array_unique(array_column($tariffEvolution, 'beneficiary'))) > 1;
$generatedAt = (string) ($report['generated_at'] ?? date('Y-m-d H:i:s'));
$mode = (string) ($filters['tip_activitate'] ?? '');

/*
 * Densitatea tabelelor. "compact" (implicit) agrega coloanele pe tipuri de
 * transport intr-o singura coloana "Activitate" si muta restul in randul
 * expandabil; "detaliat" pastreaza tabelele late, cu toate coloanele.
 * Este strict o optiune de afisare, deci se citeste direct din query string.
 */
$tableView = ($_GET['view'] ?? '') === 'detaliat' ? 'detaliat' : 'compact';
$isCompact = $tableView === 'compact';

$filterValue = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));
$fmt = static function (float|int|string|null $value, int $decimals = 2): string {
    $number = is_numeric($value) ? (float) $value : 0.0;
    return number_format($number, $decimals, ',', '.');
};
$fmtSmart = static function (float|int|string|null $value, int $maxDecimals = 2) use ($fmt): string {
    $number = is_numeric($value) ? (float) $value : 0.0;
    $decimals = abs($number - round($number)) < 0.0001 ? 0 : $maxDecimals;
    return $fmt($number, $decimals);
};
$fmtMoney = static fn (float|int|string|null $value): string => $fmt(is_numeric($value) ? (float) $value : 0.0, 2);
$fmtMoneyKpi = static fn (float|int|string|null $value): string => $fmt(is_numeric($value) ? (float) $value : 0.0, 0) . ' RON';
$fmtKm = static fn (float|int|string|null $value): string => (is_numeric($value) && (float) $value > 0) ? $fmtSmart($value, 0) . ' km' : '-';
$fmtTone = static fn (float|int|string|null $value): string => (is_numeric($value) && (float) $value > 0) ? $fmtSmart($value, 2) . ' tone' : '-';
$fmtPercent = static fn (float|int|string|null $value): string => $fmt(is_numeric($value) ? (float) $value : 0.0, 2) . '%';
$fmtCapacity = static fn (mixed $value): string => is_numeric($value) && (float) $value > 0 ? $fmtSmart($value, 2) . ' t' : '-';
$fmtPlain = static fn (float|int|string|null $value, int $maxDecimals = 2): string => is_numeric($value) && (float) $value > 0 ? $fmtSmart($value, $maxDecimals) : '-';
$fmtDate = static fn (string $value): string => ($ts = strtotime($value)) !== false ? date('d.m.Y H:i', $ts) : date('d.m.Y H:i');

$queryFor = static function (array $overrides = []) use ($filters, $tableView): string {
    $base = [
        'page' => 'centralizator_facturare',
        'view' => $tableView,
        'month' => (string) ($filters['month'] ?? ''),
        'beneficiar_id' => (string) ($filters['beneficiar_id'] ?? ''),
        'tip_activitate' => (string) ($filters['tip_activitate'] ?? ''),
        'tip_marfa' => (string) ($filters['tip_marfa'] ?? ''),
        'loc_incarcare_id' => (string) ($filters['loc_incarcare_id'] ?? ''),
        'zona_distributie_id' => (string) ($filters['zona_distributie_id'] ?? ''),
        'ruta' => (string) ($filters['ruta'] ?? ''),
        'vehicle_id' => (string) ($filters['vehicle_id'] ?? ''),
        'vehicle_sort' => (string) ($filters['vehicle_sort'] ?? 'capacity_asc'),
        'per_page' => (string) ($filters['per_page'] ?? '10'),
    ];
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($base[$key]);
        } else {
            $base[$key] = (string) $value;
        }
    }
    return build_query_url($base);
};

$comparisonMarkup = static function (?array $comparison): string {
    if ($comparison === null) {
        return '<span class="cf-compare-label">vs. luna anterioară</span>';
    }
    $direction = (string) ($comparison['direction'] ?? 'up');
    $class = $direction === 'down' ? 'is-down' : 'is-up';
    $icon = $direction === 'down' ? 'bi-arrow-down' : 'bi-arrow-up-right';
    $percent = number_format((float) ($comparison['percent'] ?? 0), 2, ',', '.') . '%';
    return '<span class="cf-compare-label">vs. luna anterioară</span><strong class="cf-compare ' . e($class) . '">' . e($percent) . ' <i class="bi ' . e($icon) . '" aria-hidden="true"></i></strong>';
};

/* Variatia compacta pe un rand din defalcarea unui card; fara luna anterioara nu afisam nimic. */
$breakdownChange = static function (?array $comparison): string {
    if ($comparison === null) {
        return '';
    }
    $isDown = (string) ($comparison['direction'] ?? 'up') === 'down';
    $percent = number_format((float) ($comparison['percent'] ?? 0), 2, ',', '.') . '%';
    return '<span class="cf-kpi-bd-change ' . ($isDown ? 'is-down' : 'is-up') . '" title="vs. luna anterioară">'
        . '<i class="bi ' . ($isDown ? 'bi-arrow-down' : 'bi-arrow-up-right') . '" aria-hidden="true"></i> ' . e($percent)
        . '</span>';
};

$kpiValue = static function (array $card) use ($fmtMoneyKpi, $fmtSmart): string {
    $unit = (string) ($card['unit'] ?? '');
    $value = $card['value'] ?? 0;
    if ($unit === 'RON') {
        return $fmtMoneyKpi($value);
    }
    return $fmtSmart($value, $unit === 'tone' ? 2 : 0) . ($unit !== '' ? ' ' . $unit : '');
};

/*
 * Bara de proportie inline. Inlocuieste diagramele donut: aceeasi informatie,
 * citita pe randul careia ii apartine, fara un al doilea element vizual.
 */
$sharePercent = static function (float|int|string|null $value): string {
    $percent = is_numeric($value) ? (float) $value : 0.0;
    $percent = max(0.0, min(100.0, $percent));

    return number_format($percent, 2, '.', '');
};

$renderShare = static function (float|int|string|null $percent, string $color) use ($sharePercent, $fmtPercent): string {
    return '<span class="cf-share">'
        . '<span class="cf-share-bar" style="--share: ' . e($sharePercent($percent)) . '%; --dot: ' . e($color) . '"><i></i></span>'
        . '<span class="cf-share-value">' . e($fmtPercent($percent)) . '</span>'
        . '</span>';
};

$tripDetailValue = static function (array $row, array $col) use ($fmtMoney, $fmtKm, $fmtTone, $fmtSmart): string {
    $key = (string) ($col['key'] ?? '');
    $format = (string) ($col['format'] ?? 'text');
    $value = $row[$key] ?? null;
    if ($format === 'money') {
        return $fmtMoney($value);
    }
    if ($format === 'km') {
        return $fmtKm($value);
    }
    if ($format === 'tone') {
        return $fmtTone($value);
    }
    if ($format === 'tariff') {
        return is_numeric($value) && (float) $value > 0 ? $fmtSmart($value, 2) . ' RON/t' : '-';
    }
    if ($format === 'rate_km') {
        return is_numeric($value) && (float) $value > 0 ? $fmtSmart($value, 4) : '-';
    }

    return trim((string) $value) !== '' ? (string) $value : '-';
};

$renderTripDetails = static function (array $rows, array $columns) use ($tripDetailValue): string {
    ob_start();
    ?>
    <div class="cf-trip-detail">
        <div class="cf-trip-scroll">
            <table class="cf-table cf-trip-table">
                <thead>
                <tr><?php foreach ($columns as $col): ?><th class="<?= ($col['align'] ?? '') === 'right' ? 'is-number' : '' ?>"><?= e((string) ($col['label'] ?? '')) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= e((string) max(1, count($columns))) ?>"><div class="cf-empty">Nu există curse pentru vehiculul selectat.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $detailRow): ?>
                        <tr>
                            <?php foreach ($columns as $col): ?>
                                <td class="<?= ($col['align'] ?? '') === 'right' ? 'is-number' : '' ?>"><?= e($tripDetailValue($detailRow, $col)) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};

/* Pret unitar fara zerouri inutile: 1,2700 -> 1,27; 2,0000 -> 2. */
$fmtRate = static function (float|int|string|null $value): string {
    $formatted = number_format(is_numeric($value) ? (float) $value : 0.0, 4, ',', '.');

    return rtrim(rtrim($formatted, '0'), ',');
};

/* Calculul facturarii ca text: "12.000 km × 1,27 RON/km"; "~" marcheaza un pret mediu. */
$renderBillingCalc = static function (mixed $quantity, string $unit, mixed $rate, bool $isAverage = false) use ($fmtSmart, $fmtRate): string {
    $qty = is_numeric($quantity) ? (float) $quantity : 0.0;
    if ($qty <= 0) {
        return '-';
    }
    $text = $fmtSmart($qty, $unit === 'km' ? 0 : 2) . ' ' . $unit;
    if (is_numeric($rate) && (float) $rate > 0) {
        $text .= ' × ' . ($isAverage ? '~' : '') . $fmtRate($rate) . ' RON/' . $unit;
    }

    return $text;
};

/*
 * Ce s-a facturat pe un tip de transport, in randul expandat al tipului din
 * "Activitati pe tipuri de transport": intai grupurile (rute sau, la Distributie,
 * tarife) cu cantitate x pret, apoi, la desfasurarea unui grup, cursele lui.
 */
/*
 * Calculul pe componente (P+D, Compresor): "10 t × 60 RON/t + 180 km × 1,5 RON/km".
 * Un cost fix pe cursa inlocuieste complet calculul si apare ca atare.
 */
$renderComponentCalc = static function (array $components) use ($fmtSmart, $fmtRate): string {
    $parts = [];
    foreach ($components as $component) {
        $unit = (string) ($component['quantity_unit'] ?? '');
        $rate = (!empty($component['rate_average']) ? '~' : '') . $fmtRate($component['rate'] ?? 0);
        $rideCount = (float) ($component['quantity'] ?? 0);
        $parts[] = $unit === 'cursă'
            ? ($rideCount > 1 ? $fmtSmart($rideCount, 0) . ' curse × ' : '') . $rate . ' RON/cursă (cost fix)'
            : $fmtSmart($component['quantity'] ?? 0, $unit === 'km' ? 0 : 2) . ' ' . $unit . ' × ' . $rate . ' RON/' . $unit;
    }

    return implode(' + ', $parts);
};

/*
 * Refacturarile unui rand de facturare. Randul parinte primeste pe dreapta un
 * comutator icon-only - acelasi .cf-expand-btn ca cel din stanga, cu chevron
 * vertical - iar panoul se deschide pe toata latimea, direct sub rand, independent
 * de sectiunea de curse (tinta separata: reinvoice-details-*).
 */
$renderRefundToggle = static function (string $targetId, string $label): string {
    return '<button class="cf-expand-btn is-vertical" type="button" aria-expanded="false" aria-controls="' . e($targetId) . '"'
        . ' aria-label="' . e($label) . '" title="' . e($label) . '" data-vehicle-toggle>'
        . '<i class="bi bi-chevron-down" aria-hidden="true"></i></button>';
};

/*
 * Panoul refacturarilor unui rand de facturare. Primul nivel este un sumar grupat pe
 * Tip + Denumire; fiecare grup se desfasoara (tinta proprie, "<idBase>-g<n>") pana
 * la inregistrarile lui. Gruparea este doar de afisare: totalurile vin din
 * inregistrarile brute, iar liniile au fost deja atribuite randului de facturare.
 */
$renderRefundPanel = static function (array $lines, string $title, string $idBase) use ($fmtSmart, $fmtMoney): string {
    $total = 0.0;
    $expenseIds = [];
    $groups = [];
    foreach ($lines as $line) {
        $amount = (float) ($line['amount'] ?? 0);
        $quantity = (float) ($line['quantity'] ?? 0);
        $typeLabel = trim((string) ($line['type_label'] ?? ''));
        $name = trim((string) ($line['name'] ?? ''));
        $total += $amount;
        $expenseIds[(int) ($line['expense_id'] ?? 0)] = true;

        $groupKey = mb_strtolower($typeLabel, 'UTF-8') . '|' . mb_strtolower($name, 'UTF-8');
        $groups[$groupKey] ??= ['type_label' => $typeLabel, 'name' => $name, 'total' => 0.0, 'prices' => [], 'lines' => []];
        $groups[$groupKey]['total'] += $amount;
        $groups[$groupKey]['lines'][] = $line;
        /*
         * Cantitatea x valoarea unitara, adunate pe valori unitare identice, in ordinea
         * aparitiei: "3 × 198,00" sau "1 × 22,00 + 1 × 18,00" - niciodata o medie.
         */
        $unitPrice = round((float) ($line['unit_price'] ?? $amount), 2);
        $priceKey = number_format($unitPrice, 2, '.', '');
        $groups[$groupKey]['prices'][$priceKey] ??= ['unit_price' => $unitPrice, 'quantity' => 0.0];
        $groups[$groupKey]['prices'][$priceKey]['quantity'] += $quantity > 0 ? $quantity : 1.0;
    }

    $groupCount = count($groups);
    $recordCount = count($expenseIds);
    $counter = $groupCount . ($groupCount === 1 ? ' grup' : ' grupuri')
        . ' • ' . $recordCount . ($recordCount === 1 ? ' înregistrare' : ' înregistrări');

    $rows = '';
    $index = 0;
    foreach ($groups as $group) {
        $index++;
        $groupId = $idBase . '-g' . $index;
        $quantityParts = [];
        foreach ($group['prices'] as $price) {
            $quantityParts[] = $fmtSmart($price['quantity'], 2) . ' × ' . $fmtMoney($price['unit_price']);
        }
        $typeCell = $group['type_label'] !== '' ? $group['type_label'] : '-';
        $nameCell = $group['name'] !== '' ? $group['name'] : '-';

        $records = '';
        foreach ($group['lines'] as $line) {
            $lineQuantity = (float) ($line['quantity'] ?? 0);
            $records .= '<tr>'
                . '<td>' . e((string) ($line['date_label'] ?? '-')) . '</td>'
                . '<td>' . e($typeCell) . '</td>'
                . '<td>' . e($nameCell) . '</td>'
                . '<td class="cf-vehicle-cell">' . e((string) ($line['vehicle_label'] ?? '-')) . '</td>'
                . '<td class="is-number">' . e($fmtSmart($lineQuantity > 0 ? $lineQuantity : 1, 2)) . '</td>'
                . '<td class="is-number">' . e($fmtMoney($line['amount'] ?? 0)) . '</td>'
                . '</tr>';
        }

        $rows .= '<tr class="cf-vehicle-parent" data-vehicle-row>'
            . '<td class="cf-expand-cell"><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="' . e($groupId) . '"'
            . ' aria-label="' . e('Înregistrări ' . $typeCell . ' ' . $nameCell) . '" data-vehicle-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>'
            . '<td>' . e($typeCell) . '</td>'
            . '<td>' . e($nameCell) . '</td>'
            . '<td>' . e(implode(' + ', $quantityParts)) . '</td>'
            . '<td class="is-number">' . e($fmtMoney($group['total'])) . '</td>'
            . '</tr>'
            . '<tr class="cf-vehicle-detail-row" id="' . e($groupId) . '" hidden><td colspan="5" class="cf-trip-detail-cell">'
            . '<div class="cf-refund-records"><table class="cf-table">'
            . '<thead><tr><th>Data</th><th>Tip</th><th>Denumire</th><th>Nr. înmatriculare</th><th class="is-number">buc</th><th class="is-number">Valoare total</th></tr></thead>'
            . '<tbody>' . $records . '</tbody>'
            . '</table></div></td></tr>';
    }

    return '<div class="cf-refund-panel">'
        . '<div class="cf-refund-panel-head">'
        . '<span class="cf-refund-title"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i> ' . e($title) . '</span>'
        . '<span class="cf-refund-count">' . e($counter) . '</span>'
        . '</div>'
        . '<table class="cf-table">'
        . '<thead><tr><th class="cf-expand-th"></th><th>Tip</th><th>Denumire</th><th>Cantitate × Valoare unitară</th><th class="is-number">Total</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '<tfoot><tr><td></td><td colspan="3">Total refacturări</td><td class="is-number">' . e($fmtMoney($total)) . '</td></tr></tfoot>'
        . '</table></div>';
};

$renderTypeRoutes = static function (string $type, array $routes, string $groupBy = 'route', array $leftoverRefunds = []) use ($fmtSmart, $fmtMoney, $renderBillingCalc, $renderComponentCalc, $renderRefundToggle, $renderRefundPanel): string {
    $byPrice = $groupBy === 'price';
    $idPrefix = 'cf_route_trips_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $type) . '_';
    $html = '<div class="cf-breakdown"><div class="cf-type-routes"><table class="cf-table">'
        /* Calculul facturarii vine primul (ce se factureaza), apoi ruta sau tariful, cursele si valoarea. */
        . '<thead><tr><th class="cf-expand-th"></th>'
        . '<th>Calcul facturare</th><th>' . ($byPrice ? 'Tarif / rute' : 'Rută') . '</th><th class="is-number">Curse</th>'
        /* Ultima coloana: comutatorul refacturarilor, doar pe randurile care au refacturari. */
        . '<th class="is-number">Valoare RON</th><th class="cf-expand-th"></th></tr></thead><tbody>';

    foreach ($routes as $route) {
        $short = (string) ($route['short'] ?? '');
        $label = (string) ($route['label'] ?? '-');
        $unit = (string) ($route['unit'] ?? '');
        $isAverage = !empty($route['rate_multiple']);
        $tripsId = $idPrefix . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($route['key'] ?? ''));
        $groupCalc = $renderComponentCalc((array) ($route['components'] ?? []));
        $missingCount = (int) ($route['missing_count'] ?? 0);
        if ($groupCalc === '' && !empty($route['component_billing'])) {
            $groupCalc = 'calcul indisponibil';
        } elseif ($groupCalc !== '' && $missingCount > 0) {
            /* Calculul adunat acopera doar cursele reconstituite; restul sunt semnalate. */
            $groupCalc .= ' + ' . $missingCount . ($missingCount === 1 ? ' cursă' : ' curse') . ' fără calcul';
        }
        $differsCount = (int) ($route['differs_count'] ?? 0);
        $groupWarn = $differsCount > 0
            ? ' <i class="bi bi-exclamation-triangle-fill cf-calc-warn" title="' . e($differsCount . ($differsCount === 1 ? ' cursă are' : ' curse au') . ' valoarea salvată diferită de recalcularea cu tarifele din data cursei') . '"></i>'
            : '';
        $groupCell = $byPrice
            ? e($label) . ' <span class="cf-route-codes">' . e(implode(', ', (array) ($route['route_codes'] ?? []))) . '</span>'
            : '<span class="cf-route-code">' . e($short) . '</span>' . e($label);
        $routeRefunds = (array) ($route['refunds'] ?? []);
        /* Tinta separata de cea a curselor, ca cele doua sectiuni sa se deschida independent. */
        $refundsId = 'reinvoice-details-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $type . '-' . (string) ($route['key'] ?? ''));
        $tripsCell = '<td class="is-number">' . e($fmtSmart($route['trips'] ?? 0, 0)) . '</td>';
        $calcTitle = $isAverage ? ' title="Preț mediu: cursele rutei au prețuri diferite"' : '';
        $calcContent = e($groupCalc !== '' ? $groupCalc : $renderBillingCalc($route['quantity'] ?? 0, $unit, $route['rate'] ?? null, $isAverage)) . $groupWarn;

        $html .= '<tr class="cf-vehicle-parent" data-vehicle-row>'
            . '<td class="cf-expand-cell"><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="' . e($tripsId) . '" aria-label="Curse ' . e($label) . '" data-vehicle-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>'
            . '<td class="cf-calc"' . $calcTitle . '>' . $calcContent . '</td><td>' . $groupCell . '</td>' . $tripsCell
            . '<td class="is-number">' . e($fmtMoney($route['value'] ?? 0)) . '</td>'
            . '<td class="cf-expand-cell">' . ($routeRefunds !== [] ? $renderRefundToggle($refundsId, 'Refacturări ' . $label) : '') . '</td>'
            . '</tr>'
            . '<tr class="cf-vehicle-detail-row" id="' . e($tripsId) . '" hidden><td colspan="6" class="cf-trip-detail-cell">'
            . '<div class="cf-breakdown"><div class="cf-type-routes"><table class="cf-table">'
            . ($byPrice
                ? '<thead><tr><th>Nr. cursă</th><th>Data</th><th>Rută</th><th>Vehicul</th>'
                : '<thead><tr><th>Data</th><th>Vehicul</th><th>Nr. cursă</th><th>Rută</th>')
            . '<th class="is-number">Calcul facturare</th><th class="is-number">Valoare RON</th></tr></thead><tbody>';

        foreach ((array) ($route['trip_rows'] ?? []) as $trip) {
            $date = '<td>' . e((string) ($trip['date_label'] ?? '-')) . '</td>';
            $vehicle = '<td class="cf-vehicle-cell">' . e((string) ($trip['vehicle_label'] ?? '-')) . '</td>';
            $raceNo = '<td>' . e((string) ($trip['race_no'] ?? '-')) . '</td>';
            $tripComponentCalc = $renderComponentCalc((array) ($trip['components'] ?? []));
            /* P+D / Compresor fara componente: nu inventam o formula din totalul salvat. */
            $noComponents = $tripComponentCalc === '' && ($trip['recomputed_total'] ?? null) !== null;
            $tripCalc = e($noComponents
                ? 'calcul indisponibil'
                : ($tripComponentCalc !== '' ? $tripComponentCalc : $renderBillingCalc($trip['quantity'] ?? 0, (string) ($trip['unit'] ?? $unit), $trip['rate'] ?? null)));
            $warnParts = [];
            if (!empty($trip['differs'])) {
                $warnParts[] = 'Valoare salvată ' . $fmtMoney($trip['value'] ?? 0) . ' RON; recalculat cu tarifele din data cursei: '
                    . $fmtMoney($trip['recomputed_total'] ?? 0) . ' RON.';
            }
            if (!empty($trip['differs']) || $noComponents) {
                foreach ((array) ($trip['pricing_warnings'] ?? []) as $pricingWarning) {
                    $warnParts[] = (string) $pricingWarning;
                }
            }
            if ($noComponents && $warnParts === []) {
                $warnParts[] = 'Cursa nu are cantități facturabile (sau tarife pentru ele) din care să se reconstituie calculul.';
            }
            if ($warnParts !== []) {
                $tripCalc .= ' <i class="bi bi-exclamation-triangle-fill cf-calc-warn" title="' . e(implode(' ', $warnParts)) . '"></i>';
            }
            if (($trip['calc_source'] ?? '') === 'saved') {
                $infoText = 'Calcul din prețul salvat pe cursă: motorul de tarifare nu reproduce valoarea (regulă lipsă sau tarif modificat';
                $infoText .= ($trip['engine_total'] ?? null) !== null
                    ? '; recalculat cu tarifele din data cursei: ' . $fmtMoney($trip['engine_total']) . ' RON).'
                    : ').';
                foreach ((array) ($trip['pricing_warnings'] ?? []) as $pricingWarning) {
                    $infoText .= ' ' . $pricingWarning;
                }
                $tripCalc .= ' <i class="bi bi-info-circle cf-calc-info" title="' . e($infoText) . '"></i>';
            }
            $routeCell = $byPrice
                ? '<td class="cf-vehicle-cell">' . e((string) ($trip['route_label'] ?? '-')) . ' <span class="cf-route-code">' . e((string) ($trip['route_short'] ?? '')) . '</span></td>'
                : '<td><span class="cf-route-code">' . e($short) . '</span></td>';

            $html .= '<tr>'
                . ($byPrice ? $raceNo . $date . $routeCell . $vehicle : $date . $vehicle . $raceNo . $routeCell)
                . '<td class="is-number cf-calc">' . $tripCalc . '</td>'
                . '<td class="is-number">' . e($fmtMoney($trip['value'] ?? 0)) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></div></div></td></tr>';

        /* Refacturarile randului: sectiune proprie, direct sub rand, pe toata latimea tabelului. */
        if ($routeRefunds !== []) {
            $html .= '<tr class="cf-vehicle-detail-row cf-refund-detail-row" id="' . e($refundsId) . '" hidden>'
                . '<td colspan="6" class="cf-trip-detail-cell">' . $renderRefundPanel($routeRefunds, 'Refacturări', $refundsId) . '</td></tr>';
        }
    }

    /* Totalul tipului: doar cursele si valoarea adunate, fara formula de calcul. */
    $totalTrips = 0;
    $totalValue = 0.0;
    $refundsTotal = 0.0;
    $hasRefunds = $leftoverRefunds !== [];
    foreach ($routes as $route) {
        $totalTrips += (int) ($route['trips'] ?? 0);
        $totalValue += (float) ($route['value'] ?? 0);
        foreach ((array) ($route['refunds'] ?? []) as $refundLine) {
            $refundsTotal += (float) ($refundLine['amount'] ?? 0);
            $hasRefunds = true;
        }
    }
    foreach ($leftoverRefunds as $refundLine) {
        $refundsTotal += (float) ($refundLine['amount'] ?? 0);
    }

    $html .= '<tr class="cf-total-row"><td></td><td>TOTAL</td><td></td>'
        . '<td class="is-number">' . e($fmtSmart($totalTrips, 0)) . '</td>'
        . '<td class="is-number">' . e($fmtMoney($totalValue)) . '</td><td></td></tr>';
    if ($hasRefunds) {
        /*
         * Refacturarile unor curse din alte luni (refacturarea se filtreaza dupa data ei)
         * nu au rand de facturare in luna curenta: se deschid din randul TOTAL REFACTURĂRI.
         */
        $leftoverId = 'reinvoice-details-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $type) . '-alte-luni';
        $html .= '<tr class="cf-total-row cf-refund-total"><td></td><td>TOTAL REFACTURĂRI</td><td></td><td></td>'
            . '<td class="is-number">' . e($fmtMoney($refundsTotal)) . '</td>'
            . '<td class="cf-expand-cell">' . ($leftoverRefunds !== [] ? $renderRefundToggle($leftoverId, 'Refacturări curse din alte luni') : '') . '</td></tr>';
        if ($leftoverRefunds !== []) {
            $html .= '<tr class="cf-vehicle-detail-row cf-refund-detail-row" id="' . e($leftoverId) . '" hidden>'
                . '<td colspan="6" class="cf-trip-detail-cell">' . $renderRefundPanel($leftoverRefunds, 'Refacturări curse din alte luni', $leftoverId) . '</td></tr>';
        }
    }

    return $html . '</tbody></table></div></div>';
};

$renderStatTiles = static function (array $activity, array $extra = []) use ($fmtMoney): string {
    if ($activity === [] && $extra === []) {
        return '';
    }

    $html = '<div class="cf-tiles">';
    foreach ($activity as $item) {
        $html .= '<div class="cf-tile is-' . e((string) $item['tone']) . '">'
            . '<span class="cf-tile-label">' . e((string) $item['label']) . '</span>'
            . '<strong>' . e((string) $item['metric']) . '</strong>'
            . '<span class="cf-tile-foot">' . e($fmtMoney($item['value'] ?? 0)) . ' RON</span>'
            . '</div>';
    }
    foreach ($extra as $item) {
        $html .= '<div class="cf-tile">'
            . '<span class="cf-tile-label">' . e((string) ($item['label'] ?? '')) . '</span>'
            . '<strong>' . e((string) ($item['value'] ?? '-')) . '</strong>'
            . ((string) ($item['foot'] ?? '') !== '' ? '<span class="cf-tile-foot">' . e((string) $item['foot']) . '</span>' : '')
            . '</div>';
    }

    return $html . '</div>';
};

/* Randul expandat al unui vehicul: sinteza pe tipuri + rute + lista de curse. */
$renderVehicleBreakdown = static function (array $activity, array $extra, string $tripsHtml) use ($renderStatTiles): string {
    return '<div class="cf-breakdown">'
        . $renderStatTiles($activity, $extra)
        . $tripsHtml
        . '</div>';
};

/*
 * Gruparea pe capacitate, folosita de ambele tabele de vehicule.
 * Se face peste randurile deja filtrate si calculate de serviciu, deci agregarea
 * respecta automat filtrele active si nu dubleaza nimic fata de randul TOTAL.
 * Ordinea vehiculelor in interiorul unui grup ramane cea primita (vehicle_sort).
 */
$groupRowsByCapacity = static function (array $rows, bool $descending = false): array {
    $groups = [];
    foreach ($rows as $row) {
        $capacity = $row['capacity'] ?? null;
        $hasCapacity = is_numeric($capacity) && (float) $capacity > 0;
        $key = $hasCapacity ? 'c' . number_format((float) $capacity, 4, '.', '') : 'none';

        $groups[$key] ??= [
            'key' => $key,
            'capacity' => $hasCapacity ? (float) $capacity : null,
            'vehicles' => 0,
            'rows' => [],
        ];
        $groups[$key]['vehicles']++;
        $groups[$key]['rows'][] = $row;
    }

    uasort($groups, static function (array $a, array $b) use ($descending): int {
        /* "Fara capacitate" ramane ultimul indiferent de directia de sortare. */
        if ($a['capacity'] === null || $b['capacity'] === null) {
            return ($a['capacity'] === null ? 1 : 0) <=> ($b['capacity'] === null ? 1 : 0);
        }

        return $descending ? ($b['capacity'] <=> $a['capacity']) : ($a['capacity'] <=> $b['capacity']);
    });

    return array_values($groups);
};

/* Sume pentru tabelul "Detaliat": metricile sunt plate pe rand. */
$sumFlatMetrics = static function (array $rows): array {
    $sums = ['trips' => 0.0, 'km' => 0.0, 'tone' => 0.0, 'activity' => 0.0, 'value' => 0.0];
    foreach ($rows as $row) {
        foreach (array_keys($sums) as $metric) {
            $sums[$metric] += (float) ($row[$metric] ?? 0);
        }
    }

    return $sums;
};

/* Eticheta tipului de refacturare, inclusiv componentele taxelor de drum. */
$refTypeLabel = static function (string $type): string {
    return [
        'taxa_acces' => 'Taxă acces',
        'port' => 'Port',
        'trece' => 'Trecere',
        'motorina' => 'Motorină',
        'taxe_drum' => 'Taxe drum',
        'diurna' => 'Diurnă',
        'service' => 'Service',
        'alte' => 'Alte treceri',
        'difference' => 'Diferență nealocată',
    ][$type] ?? ($type !== '' ? mb_convert_case(str_replace('_', ' ', $type), MB_CASE_TITLE, 'UTF-8') : 'Nespecificat');
};

/* Randul expandat al unei refacturari: campurile scoase din tabelul restrans. */
$renderRefBreakdown = static function (array $fields): string {
    $html = '<div class="cf-breakdown"><dl class="cf-facts">';
    foreach ($fields as $label => $value) {
        $html .= '<div><dt>' . e((string) $label) . '</dt><dd>' . e((string) $value) . '</dd></div>';
    }

    return $html . '</dl></div>';
};

/*
 * Rezumatul filtrelor active.
 * Beneficiarul este obligatoriu - cand lipseste din URL, serviciul alege automat
 * clientul cu cele mai multe curse din luna. Fara un indicator permanent, toate
 * cifrele de mai jos par sa fie pe toata firma, desi sunt pe un singur client.
 */
$labelFor = static function (array $list, string $value, string $valueKey, string $labelKey): string {
    if ($value === '') {
        return '';
    }
    foreach ($list as $item) {
        $candidate = $item[$valueKey] ?? '';
        $candidate = is_int($candidate) ? (string) $candidate : (string) $candidate;
        if ($candidate === $value) {
            return (string) ($item[$labelKey] ?? '');
        }
    }

    return '';
};

$isAllBeneficiaries = (int) ($filters['beneficiar_id'] ?? 0) <= 0;
$scopeBeneficiary = $isAllBeneficiaries
    ? 'Toți beneficiarii'
    : $labelFor((array) ($lookups['beneficiaries'] ?? []), $filterValue('beneficiar_id'), 'id', 'nume');
$scopeMonth = (string) ($filters['month_label'] ?? '') !== ''
    ? (string) $filters['month_label']
    : $labelFor((array) ($lookups['months'] ?? []), $filterValue('month'), 'value', 'label');

/* Doar filtrele optionale care restrang efectiv setul de date. */
$activeFilters = [];
$activityLabel = (string) ((array) ($lookups['activity_types'] ?? []))[$filterValue('tip_activitate')] ?? '';
if ($filterValue('tip_activitate') !== '' && $activityLabel !== '') {
    $activeFilters[] = ['label' => 'Tip activitate', 'value' => $activityLabel];
}
foreach ([
    ['Tip marfă', 'tip_marfa', 'cargo', 'value', 'label'],
    ['Vehicul', 'vehicle_id', 'vehicles', 'id', 'nr_inmatriculare'],
    ['Loc încărcare', 'loc_incarcare_id', 'loading_locations', 'id', 'label'],
    ['Zonă descărcare', 'zona_distributie_id', 'unloading_zones', 'id', 'label'],
    ['Rută', 'ruta', 'routes', 'value', 'label'],
] as [$label, $filterKey, $lookupKey, $valueKey, $labelKey]) {
    $value = $labelFor((array) ($lookups[$lookupKey] ?? []), $filterValue($filterKey), $valueKey, $labelKey);
    if ($value !== '') {
        $activeFilters[] = ['label' => $label, 'value' => $value];
    }
}

/* Grupurile urmeaza directia sortarii pe capacitate; "Fara capacitate" e mereu ultimul. */
$capacityGroupsDescending = $filterValue('vehicle_sort') === 'capacity_desc';

$gridMode = $mode !== '' ? 'mode-' . str_replace('_', '-', $mode) : 'mode-all';
$exportUrl = $queryFor(['action' => 'export', 'p' => null]);
$resetUrl = build_query_url(['page' => 'centralizator_facturare']);
$refDefaultExpanded = true;
?>

<style>
.cf-page {
    --cf-blue: #075df5;
    --cf-text: #071a44;
    --cf-muted: #536580;
    --cf-border: #d9e2ef;
    --cf-soft: #fbfcff;
    --cf-green: #059669;
    color: var(--cf-text);
    font-size: 13px;
}
/*
 * Fara overflow: hidden - ar transforma shell-ul in scrollport si ar dezactiva
 * position: sticky pentru bara de context. Colturile se rotunjesc pe capete.
 */
.cf-shell {
    border: 1px solid var(--cf-border);
    border-radius: 10px;
    background: #fff;
}
.cf-header { border-radius: 9px 9px 0 0; }
.cf-footer { border-radius: 0 0 9px 9px; }
/* Contextul raportului: ce beneficiar / luna / filtre stau in spatele cifrelor. */
.cf-scope {
    position: sticky;
    top: 54px;
    z-index: 6;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px 16px;
    padding: 9px 14px;
    border-bottom: 1px solid #cddffa;
    background: #eef4ff;
    color: #1c2d54;
}
.cf-scope-main {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 12px;
}
.cf-scope-lead {
    color: #51617f;
    font-size: 12px;
    font-weight: 700;
}
.cf-scope-client {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: #0e204a;
    font-size: 15px;
    font-weight: 900;
}
.cf-scope-client i { color: #0b4fd8; }
.cf-scope-month {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #26385f;
    font-size: 13px;
    font-weight: 800;
}
.cf-scope-filters {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}
.cf-scope-hint {
    color: #6b7a95;
    font-size: 12px;
    font-weight: 700;
}
.cf-scope-chip {
    padding: 3px 9px;
    border: 1px solid #c2d9fb;
    border-radius: 20px;
    background: #fff;
    color: #10458f;
    font-size: 11px;
    font-weight: 800;
}
.cf-scope-chip b { font-weight: 900; }
/* Situatia generala primeste alt accent, ca sa nu fie confundata cu un client. */
.cf-scope.is-all {
    border-bottom-color: #cfe6d8;
    background: #edf8f2;
}
.cf-scope.is-all .cf-scope-client i { color: #079b63; }
.cf-scope.is-all .cf-scope-chip { border-color: #b6e6d0; color: #0a6244; }
.cf-header {
    min-height: 74px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 18px 18px 16px;
    border-bottom: 1px solid var(--cf-border);
}
.cf-title {
    display: flex;
    gap: 12px;
    align-items: center;
}
.cf-title-icon {
    width: 28px;
    height: 28px;
    border: 3px solid #081a43;
    border-radius: 5px;
    display: grid;
    place-items: center;
    font-size: 15px;
}
.cf-title h1 {
    margin: 0;
    font-size: 26px;
    line-height: 1;
    font-weight: 900;
    letter-spacing: 0;
}
.cf-title p {
    margin: 6px 0 0;
    color: #172850;
    font-size: 13px;
    font-weight: 600;
}
.cf-export {
    height: 38px;
    border: 1px solid var(--cf-border);
    border-radius: 7px;
    padding: 0 14px;
    color: #10224b;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    text-decoration: none;
    font-weight: 800;
}
.cf-export i { color: #079b63; font-size: 18px; }
.cf-header-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.cf-filters {
    display: grid;
    grid-template-columns: 1.22fr 1.12fr .95fr .95fr .9fr auto;
    gap: 14px;
    align-items: end;
    padding: 12px 14px 14px;
    border-bottom: 1px solid var(--cf-border);
    background: #fff;
}
.cf-field label {
    display: block;
    margin-bottom: 6px;
    color: #172850;
    font-size: 12px;
    font-weight: 800;
}
.cf-field.is-required label::after {
    content: " *";
    color: #ef4444;
}
.cf-field select {
    width: 100%;
    height: 40px;
    border: 1px solid #cdd9ea;
    border-radius: 6px;
    background: #fff;
    color: #071a44;
    padding: 0 12px;
    font-weight: 800;
}
.cf-reset-btn {
    height: 40px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    font-weight: 900;
    text-decoration: none;
    border: 1px solid transparent;
    padding: 0 18px;
    white-space: nowrap;
}
.cf-reset-btn {
    color: #17264c;
    background: #fff;
    border-color: var(--cf-border);
}
.cf-main-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr)) minmax(360px, .98fr);
    /*
     * Aceasta este structura situatiei generale (mode-all): panourile specifice
     * unui tip de transport nu se randeaza aici, deci nu apar nici in grid.
     */
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "activity activity activity activity activity activity ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
        "tariffs tariffs tariffs tariffs tariffs tariffs tariffs"
        "refTable refTable refTable refTable refTable refTable refTable";
    gap: 14px;
    padding: 14px;
    background: var(--cf-soft);
}
/*
 * Fiecare mod are exact panourile pe care le randeaza buildVisibility(): daca un
 * sablon ar numi o zona care nu se randeaza, ar ramane un rand gol in grid.
 * Primar tone si Compresor nu au panou dedicat de detaliu.
 */
.cf-main-grid.mode-primar-distributie {
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "primaryTable primaryTable primaryTable distribution distribution distribution ref"
        "distTable distTable distTable distTable distTable distTable ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
        "tariffs tariffs tariffs tariffs tariffs tariffs tariffs"
        "refTable refTable refTable refTable refTable refTable refTable";
}
.cf-main-grid.mode-primar {
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "primaryTable primaryTable primaryTable primaryTable primaryTable primaryTable ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
        "tariffs tariffs tariffs tariffs tariffs tariffs tariffs"
        "refTable refTable refTable refTable refTable refTable refTable";
}
.cf-main-grid.mode-primar-tona,
.cf-main-grid.mode-compresor {
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
        "tariffs tariffs tariffs tariffs tariffs tariffs tariffs"
        "refTable refTable refTable refTable refTable refTable refTable";
}
.cf-main-grid.mode-distributie {
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "distribution distribution distribution distribution distribution distribution ref"
        "distTable distTable distTable distTable distTable distTable ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
        "tariffs tariffs tariffs tariffs tariffs tariffs tariffs"
        "refTable refTable refTable refTable refTable refTable refTable";
}
.cf-card,
.cf-panel {
    border: 1px solid var(--cf-border);
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 8px 22px rgba(15, 23, 42, .035);
}
.cf-kpi {
    min-height: 104px;
    padding: 18px 20px;
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr);
    gap: 16px;
    align-items: center;
    /* Cand un card vecin isi desfasoara defalcarea, continutul ramane sus, nu centrat. */
    align-content: start;
}
.cf-kpi:nth-of-type(1) { grid-area: kpi1; }
.cf-kpi:nth-of-type(2) { grid-area: kpi2; }
.cf-kpi:nth-of-type(3) { grid-area: kpi3; }
.cf-kpi.is-blue { background: linear-gradient(135deg, #f7fbff, #fff); }
.cf-kpi.is-green { background: linear-gradient(135deg, #f7fffb, #fff); }
.cf-kpi.is-purple { background: linear-gradient(135deg, #fbf8ff, #fff); }
.cf-kpi-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    font-size: 34px;
    color: #6b9dfb;
}
.cf-kpi.is-green .cf-kpi-icon { color: #079b63; }
.cf-kpi.is-purple .cf-kpi-icon { color: #8b5cf6; }
.cf-kpi-title,
.cf-ref-title {
    display: block;
    color: #0042c7;
    font-size: 12px;
    line-height: 1.2;
    font-weight: 900;
    text-transform: uppercase;
}
.cf-kpi.is-green .cf-kpi-title { color: #079b63; }
.cf-kpi.is-purple .cf-kpi-title { color: #4f20d8; }
.cf-kpi-value,
.cf-ref-value {
    display: block;
    margin-top: 8px;
    font-size: 23px;
    line-height: 1;
    font-weight: 900;
}
.cf-kpi-foot {
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 14px;
    min-height: 18px;
    color: #1f315b;
    font-size: 11px;
}
.cf-compare-label { color: #51617f; font-weight: 600; }
.cf-compare { display: block; margin-top: 3px; font-weight: 900; }
.cf-compare.is-up { color: #059669; }
.cf-compare.is-down { color: #dc2626; }
.cf-ref-card {
    grid-area: ref;
    border-color: #ff8a57;
    background: linear-gradient(135deg, #fff8f4, #fff);
    align-self: start;
    overflow: hidden;
}
.cf-ref-head {
    min-height: 104px;
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr) 28px;
    gap: 15px;
    align-items: start;
    padding: 17px 18px;
}
.cf-ref-icon {
    width: 42px;
    height: 42px;
    border-radius: 5px;
    background: #fb7b45;
    color: #fff;
    display: grid;
    place-items: center;
    font-size: 27px;
}
.cf-ref-title { color: #f04416; }
.cf-ref-sub {
    margin-top: 14px;
    color: #394868;
    border: 0;
    background: transparent;
    padding: 0;
    font-size: 12px;
    font-weight: 800;
}
.cf-ref-sub i { margin-right: 5px; color: #31598e; }
.cf-ref-close {
    width: 28px;
    height: 28px;
    border: 0;
    background: transparent;
    color: #0e204a;
    font-size: 22px;
    display: none;
    align-items: center;
    justify-content: center;
}
.cf-ref-card.is-expanded .cf-ref-close { display: flex; }
.cf-ref-details { display: none; padding: 0 14px 14px; }
.cf-ref-card.is-expanded .cf-ref-details { display: block; }
.cf-ref-tabs {
    display: flex;
    gap: 22px;
    border-bottom: 1px solid #f0d2c5;
    margin: 0 0 13px;
}
.cf-ref-tab {
    border: 0;
    background: transparent;
    padding: 0 0 11px;
    color: #2d3d63;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    position: relative;
}
.cf-ref-tab.is-active { color: #f04416; }
.cf-ref-tab.is-active::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 2px;
    background: #f04416;
}
.cf-ref-panel { display: none; }
.cf-ref-panel.is-active { display: block; }
.cf-ref-scroll {
    max-height: 286px;
    overflow: auto;
    border: 1px solid var(--cf-border);
    border-radius: 7px;
    background: #fff;
}
.cf-ref-all {
    margin-top: 12px;
    width: 100%;
    height: 36px;
    border: 1px solid var(--cf-border);
    border-radius: 6px;
    color: #18274e;
    background: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
    font-weight: 900;
}
.cf-panel { padding: 16px; min-width: 0; }
.cf-panel h2 {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 14px;
    padding-bottom: 13px;
    border-bottom: 1px solid var(--cf-border);
    color: #0042c7;
    font-size: 15px;
    line-height: 1.2;
    font-weight: 900;
    text-transform: uppercase;
}
.cf-activity-summary { grid-area: activity; }
.cf-distribution-summary { grid-area: distribution; }
.cf-primary-table { grid-area: primaryTable; }
.cf-distribution-table { grid-area: distTable; }
.cf-vehicle-detail { grid-area: vehicleDetail; }
.cf-refact-table { grid-area: refTable; }
/* Primar - pe rute: aceeasi ruta poate aparea o data per tarif facturat. */
.cf-primary-row.is-continuation > td:not(.cf-expand-cell):first-of-type { color: #51617f; }
.cf-route-continuation { margin-right: 4px; color: #93a3bd; font-weight: 900; }
.cf-route-split-badge {
    margin-left: 6px;
    padding: 1px 7px;
    border-radius: 999px;
    background: #fff4e5;
    color: #b45309;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}
.cf-route-period { margin-left: 8px; color: #51617f; font-size: 11px; font-weight: 700; white-space: nowrap; }
/* Sub cifra, nu langa ea: altfel insigna ar impinge pretul si ar strica alinierea coloanei. */
.cf-rate-flag {
    display: block;
    width: fit-content;
    margin: 3px 0 0 auto;
    padding: 1px 6px;
    border-radius: 999px;
    background: #eaf2ff;
    color: #1d4ed8;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}
.cf-tariff-why { padding: 10px 12px; }
.cf-tariff-why-alert {
    margin: 0 0 10px;
    padding: 8px 10px;
    border-radius: 8px;
    background: #fff4e5;
    color: #92400e;
    font-size: 12px;
    font-weight: 700;
}
.cf-tariff-why-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 8px 18px;
    margin: 0;
}
.cf-tariff-why-grid dt { color: #51617f; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .02em; }
.cf-tariff-why-grid dd { margin: 2px 0 0; color: #1f2d45; font-size: 13px; font-weight: 700; }
.cf-tariff-why-muted { color: #51617f; font-size: 11px; font-weight: 600; }
.cf-tariff-why-empty { margin: 0; color: #51617f; font-size: 12px; font-weight: 600; }
/* Evolutia tarifelor: tarifele in vigoare pentru luna, cu valoarea pe care au inlocuit-o. */
.cf-tariff-evolution { grid-area: tariffs; }
.cf-tariff-evolution .cf-table { min-width: 760px; }
.cf-tariff-delta { margin-left: 6px; font-size: 11px; font-weight: 900; }
.cf-tariff-delta.is-up { color: #059669; }
.cf-tariff-delta.is-down { color: #dc2626; }
.cf-tariff-meta { color: #51617f; font-size: 12px; font-weight: 700; }
.cf-tariff-scope { margin-left: 6px; color: #51617f; font-size: 11px; font-weight: 700; }
/* Cardurile generale se pot desfasura: defalcarea totalului pe tipuri de transport. */
.cf-kpi-toggle {
    margin-top: 10px;
    padding: 0;
    border: 0;
    background: transparent;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #394868;
    font-size: 12px;
    font-weight: 800;
}
.cf-kpi-toggle i { transition: transform .16s ease; }
.cf-kpi-toggle[aria-expanded="true"] i { transform: rotate(180deg); }
.cf-kpi-breakdown {
    grid-column: 1 / -1;
    display: grid;
    gap: 10px;
    margin: 0;
    padding: 12px 0 0;
    border-top: 1px solid var(--cf-border);
    list-style: none;
}
.cf-kpi-breakdown[hidden] { display: none; }
.cf-kpi-bd-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 3px 10px;
    align-items: center;
    font-size: 12px;
}
.cf-kpi-bd-row .cf-transport-name {
    gap: 7px;
    min-width: 0;
    color: #26385f;
    font-weight: 800;
}
.cf-kpi-bd-row strong {
    color: #071a44;
    font-weight: 900;
    white-space: nowrap;
}
.cf-kpi-bd-meta {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    color: #51617f;
    font-size: 11px;
    font-weight: 700;
}
.cf-kpi-bd-meta .cf-share { justify-content: flex-start; }
.cf-kpi-bd-change { font-weight: 900; white-space: nowrap; }
.cf-kpi-bd-change.is-up { color: #059669; }
.cf-kpi-bd-change.is-down { color: #dc2626; }
/*
 * Bare de proportie inline, in locul diagramelor donut. Cifra ramane langa bara,
 * pe randul la care se refera, iar panoul nu mai are nevoie de un al doilea
 * element vizual care sa repete aceleasi procente.
 */
.cf-share {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}
/*
 * Latime fixa in tabel: intr-o celula ingusta, o bara flexibila s-ar strange la
 * cativa pixeli si proportia n-ar mai fi lizibila.
 */
.cf-share-bar {
    position: relative;
    flex: 0 0 auto;
    width: 64px;
    height: 6px;
    border-radius: 999px;
    background: #e7edf8;
    overflow: hidden;
}
.cf-share-bar.is-wide {
    width: auto;
    height: 7px;
}
.cf-share-bar i {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: var(--share, 0%);
    border-radius: inherit;
    background: var(--dot, #2f7df4);
}
.cf-share-value {
    flex: 0 0 auto;
    font-variant-numeric: tabular-nums;
}
/*
 * Latimile coloanelor sunt lasate pe seama continutului (nu procente fixe):
 * cu table-layout: fixed capetele scurte ca "% din total curse" se rupeau pe
 * 3-4 randuri pe orice ecran sub ~1500px. Panoul fiind ingust, tabelul are o
 * latime minima si deruleaza lateral cand nu incape.
 */
.cf-activity-summary .cf-table {
    min-width: 360px;
    font-size: 12px;
}
.cf-activity-summary .cf-table th,
.cf-activity-summary .cf-table td {
    padding: 8px 8px;
}
/* Capetele se pot rupe pe doua randuri, valorile numerice raman pe unul singur. */
.cf-activity-summary .cf-table th.is-number { white-space: normal; }
.cf-activity-summary .cf-transport-name {
    align-items: flex-start;
    white-space: normal;
}
/*
 * Randul expandat al unui tip de transport: rutele facturate, iar pentru fiecare
 * ruta cursele ei. Indentarile sunt mai mici decat in tabelele late, pentru ca
 * panoul sta langa cardul de refacturari.
 */
.cf-activity-summary .cf-breakdown { padding: 10px 10px 10px 20px; }
.cf-type-routes {
    overflow-x: auto;
    border: 1px solid var(--cf-border);
    border-radius: 7px;
    background: #fff;
}
.cf-type-routes .cf-table tr:last-child > td { border-bottom: 0; }
.cf-route-code {
    display: inline-block;
    min-width: 34px;
    margin-right: 8px;
    padding: 1px 6px;
    border-radius: 5px;
    background: #eef4ff;
    color: #0b4fd8;
    font-size: 11px;
    font-weight: 900;
    text-align: center;
}
.cf-calc { font-variant-numeric: tabular-nums; }
.cf-route-codes { margin-left: 6px; color: #51617f; font-size: 11px; font-weight: 800; }
/* Calculul pe componente poate fi lung (P+D, Compresor): se rupe pe randuri. */
.cf-type-routes td.cf-calc { white-space: normal; min-width: 170px; }
.cf-calc-warn { margin-left: 5px; color: #d97706; cursor: help; }
.cf-calc-info { margin-left: 5px; color: #64748b; cursor: help; }
/*
 * Refacturarile unui rand de facturare: comutatorul din dreapta este acelasi
 * .cf-expand-btn ca cel din stanga, doar cu chevron vertical (jos -> sus). Panoul
 * sta sub rand, pe toata latimea tabelului, cu accentul portocaliu al refacturarilor.
 */
.cf-expand-btn.is-vertical[aria-expanded="true"] i { transform: rotate(180deg); }
.cf-refund-panel {
    margin: 2px 10px 10px;
    border: 1px solid #f3dccd;
    border-radius: 7px;
    background: #fff;
    overflow: hidden;
}
.cf-refund-panel-head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid #f3dccd;
    background: #fffaf6;
}
.cf-refund-title { color: #c2410c; font-weight: 900; white-space: nowrap; }
.cf-refund-title i { margin-right: 4px; }
.cf-refund-count { color: #51617f; font-size: 12px; font-weight: 700; }
.cf-activity-summary .cf-refund-panel .cf-table { min-width: 0; }
.cf-activity-summary .cf-refund-panel .cf-table th,
.cf-activity-summary .cf-refund-panel .cf-table td { padding: 7px 12px; }
.cf-refund-panel .cf-table td:not(.is-number):not(.cf-expand-cell) { white-space: normal; }
/* Inregistrarile unui grup: tabel imbricat pe latimea grupului, usor separat vizual. */
.cf-refund-records {
    margin: 4px 10px 8px 44px;
    overflow-x: auto;
    border: 1px solid var(--cf-border);
    border-radius: 7px;
    background: #fff;
}
.cf-refund-records .cf-table tbody tr:last-child > td { border-bottom: 0; }
.cf-refund-panel tfoot td {
    border-bottom: 0;
    background: #fbfcff;
    color: #071a44;
    font-weight: 900;
}
.cf-refund-total > td { color: #c2410c; }
/*
 * Latimea "sticky" (--cf-visible-width) e gandita pentru randul expandat al tabelului
 * lat de pe primul nivel. In sectiunile imbricate (curse) ar mosteni latimea
 * tabelului exterior si continutul s-ar taia in stanga.
 */
.cf-type-routes .cf-trip-detail-cell > .cf-breakdown {
    position: static;
    width: auto;
}
.cf-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--cf-border);
    border-radius: 7px;
    scrollbar-width: thin;
    /*
     * Umbre laterale care apar doar cand tabelul chiar poate fi derulat pe
     * orizontala: gradientele albe sunt "local" (se misca odata cu continutul si
     * acopera umbra cand esti la capat), cele radiale sunt "scroll" (raman fixe).
     */
    background:
        linear-gradient(to right, #fff 28%, rgba(255, 255, 255, 0)) 0 0 / 30px 100% no-repeat local,
        linear-gradient(to left, #fff 28%, rgba(255, 255, 255, 0)) 100% 0 / 30px 100% no-repeat local,
        radial-gradient(farthest-side at 0 50%, rgba(7, 26, 68, .24), rgba(7, 26, 68, 0)) 0 0 / 16px 100% no-repeat scroll,
        radial-gradient(farthest-side at 100% 50%, rgba(7, 26, 68, .24), rgba(7, 26, 68, 0)) 100% 0 / 16px 100% no-repeat scroll;
}
.cf-table,
.cf-ref-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.cf-table th,
.cf-table td,
.cf-ref-table th,
.cf-ref-table td {
    border-bottom: 1px solid var(--cf-border);
    padding: 9px 11px;
    vertical-align: middle;
}
.cf-table th,
.cf-ref-table th {
    color: #26385f;
    background: #fbfcff;
    font-size: 12px;
    font-weight: 900;
    text-align: left;
}
.cf-table td,
.cf-ref-table td {
    color: #1c2d54;
    font-weight: 650;
}
.cf-table .is-number,
.cf-ref-table .is-number { text-align: right; white-space: nowrap; }
.cf-table .is-center { text-align: center; }
.cf-expand-cell,
.cf-expand-th {
    width: 38px;
    min-width: 38px;
    text-align: center;
}
.cf-expand-btn {
    width: 26px;
    height: 26px;
    border: 1px solid #cfe0f5;
    border-radius: 6px;
    background: #fff;
    color: #0b4fd8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background .16s ease, transform .16s ease;
}
.cf-expand-btn:hover { background: #f0f6ff; }
.cf-expand-btn i { transition: transform .16s ease; }
.cf-expand-btn[aria-expanded="true"] i { transform: rotate(90deg); }
/* ---- Mod compact: tile-uri si randul expandat ---- */
.cf-dim { color: #8d9ab1; }
.cf-breakdown {
    padding: 12px 12px 12px 44px;
    border-left: 3px solid #b9d5ff;
    background: linear-gradient(90deg, #f4f8ff, #fff);
}
.cf-breakdown .cf-trip-detail {
    padding: 0;
    border-left: 0;
    background: transparent;
}
.cf-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px;
    margin-bottom: 12px;
}
.cf-tile {
    display: grid;
    gap: 3px;
    padding: 9px 11px;
    border: 1px solid var(--chip-border, #d9e2ef);
    border-radius: 7px;
    background: #fff;
}
.cf-tile.is-blue { --chip-border: #c2d9fb; }
.cf-tile.is-green { --chip-border: #b6e6d0; }
.cf-tile.is-purple { --chip-border: #d7c9fa; }
.cf-tile.is-orange { --chip-border: #f9cfb4; }
.cf-tile.is-teal { --chip-border: #b3e3e9; }
.cf-tile-label {
    color: #51617f;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
}
.cf-tile strong {
    color: #071a44;
    font-size: 16px;
    line-height: 1.15;
    font-weight: 900;
}
.cf-tile-foot { color: #51617f; font-size: 11px; font-weight: 700; }
.cf-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 10px 18px;
    margin: 0;
}
.cf-facts dt {
    color: #51617f;
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
}
.cf-facts dd {
    margin: 2px 0 0;
    color: #1c2d54;
    font-size: 13px;
    font-weight: 700;
}
.cf-vehicle-parent.is-expanded > td { background: #eef5ff; }
.cf-view-switch {
    display: inline-flex;
    padding: 3px;
    border: 1px solid var(--cf-border);
    border-radius: 7px;
    background: #f4f7fc;
}
.cf-view-switch a {
    padding: 5px 13px;
    border-radius: 5px;
    color: #51617f;
    text-decoration: none;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
}
.cf-view-switch a.is-active {
    background: #fff;
    color: #0e204a;
    box-shadow: 0 1px 3px rgba(15, 23, 42, .12);
}
/* ---- Grupare pe capacitate: randul de grup si randurile de vehicul ---- */
.cf-cap-body[hidden] { display: none; }
.cf-cap-group {
    cursor: pointer;
    background: #f4f8fe;
}
.cf-cap-group:hover > td { background: #e9f2ff; }
.cf-cap-group > td {
    border-bottom: 1px solid #cfdff3;
    font-weight: 800;
}
.cf-cap-label {
    color: #0e204a;
    font-size: 13px;
    font-weight: 900;
    white-space: nowrap;
}
.cf-cap-count {
    color: #51617f;
    font-weight: 800;
    white-space: nowrap;
}
/* Indentarea subtila care separa nivelul vehicul de nivelul capacitate. */
.cf-cap-body .cf-vehicle-parent > td:first-child { padding-left: 26px; }
.cf-cap-body .cf-vehicle-parent > td:nth-child(2) {
    box-shadow: inset 2px 0 0 #dbe6f6;
    padding-left: 14px;
}
.cf-toolbar-controls {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
/* Randuri-frunza dintr-un grup: nu se mai pot expanda, doar detaliaza grupul. */
.cf-leaf-row > td:nth-child(2) {
    box-shadow: inset 2px 0 0 #dbe6f6;
    padding-left: 14px;
    color: #51617f;
}
.cf-group-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #a3b0c6;
    font-size: 12px;
}
.cf-group-actions button {
    border: 0;
    background: transparent;
    padding: 0;
    color: #0b4fd8;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}
.cf-group-actions button:hover { text-decoration: underline; }
.cf-filter-box {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 32px;
    padding: 0 11px;
    border: 1px solid var(--cf-border);
    border-radius: 6px;
    background: #fff;
    color: #7d8aa1;
}
.cf-filter-box:focus-within {
    border-color: #7fb0f7;
    box-shadow: 0 0 0 3px rgba(11, 79, 216, .12);
}
.cf-filter-box input {
    width: 190px;
    border: 0;
    outline: 0;
    background: transparent;
    color: #172850;
    font-size: 12px;
    font-weight: 700;
}
.cf-filter-box input::placeholder { color: #97a3b8; font-weight: 600; }
.cf-filter-note {
    margin: 9px 0 0;
    color: #0b4fd8;
    font-size: 12px;
    font-weight: 800;
}
.cf-filter-note.is-empty { color: #8a5a13; }
/* Numarul de inmatriculare nu trebuie rupt pe mai multe randuri. */
.cf-vehicle-cell { white-space: nowrap; }
.cf-vehicle-parent { cursor: pointer; }
.cf-vehicle-parent:hover td { background: #f8fbff; }
.cf-vehicle-parent td { border-bottom-color: #d8e5f5; }
.cf-route-summary {
    display: inline-block;
    max-width: 260px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: bottom;
}
.cf-vehicle-detail-row[hidden] { display: none; }
.cf-trip-detail-cell {
    padding: 0 !important;
    background: #f8fbff;
}
/*
 * Randul expandat traieste intr-un <td colspan> lat cat tot tabelul, deci pe
 * tabelele care deruleaza lateral jumatatea lui dreapta ar ramane invizibila.
 * Il fixam la latimea vizibila a containerului (--cf-visible-width, calculata
 * in JS) si il lipim de marginea din stanga.
 */
.cf-trip-detail-cell > .cf-breakdown,
.cf-trip-detail-cell > .cf-trip-detail {
    position: sticky;
    left: 0;
    width: var(--cf-visible-width, auto);
    max-width: 100%;
}
.cf-trip-detail {
    margin: 0;
    padding: 10px 12px 12px 44px;
    border-left: 3px solid #b9d5ff;
    background: linear-gradient(90deg, #f4f8ff, #fff);
}
.cf-trip-scroll {
    max-height: 270px;
    overflow: auto;
    border: 1px solid #d5e2f3;
    border-radius: 7px;
    background: #fff;
}
.cf-trip-table {
    min-width: 920px;
    font-size: 12px;
}
.cf-trip-table th,
.cf-trip-table td {
    padding: 7px 9px;
}
.cf-trip-table th {
    position: sticky;
    top: 0;
    z-index: 1;
}
.cf-total-row td,
.cf-total-row th {
    background: #fbfcff;
    font-weight: 900;
}
.cf-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex: 0 0 auto;
    background: var(--dot, #2f7df4);
}
.cf-transport-name,
.cf-ref-location {
    display: inline-flex;
    align-items: center;
    gap: 9px;
}
.cf-dist-grid {
    display: grid;
    grid-template-columns: minmax(0, .9fr) minmax(0, 1.35fr);
    gap: 22px;
}
.cf-dist-total {
    padding: 0 14px 0 8px;
    border-right: 1px solid var(--cf-border);
}
/* Cantitatea care nu intra in pret (tone la facturarea pe km si invers). */
.cf-dist-secondary {
    display: block;
    margin-top: 6px;
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
}
.cf-label {
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
    color: #12234d;
}
.cf-big {
    display: block;
    margin-top: 7px;
    color: #071a44;
    font-size: 23px;
    line-height: 1;
    font-weight: 900;
}
.cf-cargo-list {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid var(--cf-border);
    display: grid;
    gap: 12px;
}
.cf-cargo-row,
.cf-bucket-top,
.cf-bucket-bottom {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
}
.cf-cargo-row span {
    display: block;
    font-size: 12px;
    font-weight: 900;
    text-transform: uppercase;
}
.cf-cargo-row strong { color: #0042c7; font-size: 14px; }
.cf-cargo-row em {
    color: var(--cf-green);
    font-style: normal;
    font-weight: 900;
}
.cf-bucket-list { display: grid; gap: 11px; min-width: 0; margin-top: 8px; }
.cf-bucket-item { display: grid; gap: 5px; min-width: 0; }
.cf-bucket-name {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    line-height: 1.2;
    font-weight: 800;
}
.cf-bucket-tone,
.cf-bucket-percent { white-space: nowrap; text-align: right; font-weight: 800; }
.cf-bucket-rate {
    color: #071a44;
    font-size: 18px;
    font-weight: 900;
    white-space: nowrap;
}
.cf-vehicle-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
}
.cf-sort-form {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #26385f;
    font-size: 12px;
    font-weight: 800;
}
.cf-sort-form select {
    height: 32px;
    border: 1px solid var(--cf-border);
    border-radius: 6px;
    padding: 0 10px;
    color: #172850;
    font-weight: 800;
}
/* Latimile minime tin doar de modul detaliat; in compact tabelele incap singure. */
.is-detailed .cf-vehicle-detail > .cf-table-wrap > .cf-table { min-width: 760px; }
.is-detailed .cf-refact-table .cf-table { min-width: 1240px; }
.is-compact .cf-vehicle-detail > .cf-table-wrap > .cf-table,
.is-compact .cf-refact-table .cf-table { min-width: 520px; }
.cf-empty {
    padding: 20px 12px;
    color: var(--cf-muted);
    text-align: center;
    font-weight: 800;
}
.cf-warning {
    margin: 9px 0 0;
    color: #8a5a13;
    font-size: 12px;
    font-weight: 700;
}
.cf-note {
    margin-top: 9px;
    color: #677894;
    font-size: 12px;
    font-weight: 700;
}
.cf-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 14px;
    color: #566986;
    font-size: 12px;
}
.cf-pagination {
    display: flex;
    align-items: center;
    gap: 16px;
}
.cf-page-size {
    height: 34px;
    min-width: 64px;
    border: 1px solid var(--cf-border);
    border-radius: 6px;
    color: #10224b;
    font-weight: 800;
}
.cf-page-link {
    width: 34px;
    height: 34px;
    color: #0e204a;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-size: 22px;
}
.cf-page-link.is-disabled { color: #a3adc0; pointer-events: none; }
/* ==========================================================================
 * Responsive
 * Praguri: 2200 (ultra-wide) / 1500 / 1180 / 991.98 / 767.98 / 575.98.
 * ========================================================================== */

/* Monitoare foarte late: continutul nu se mai intinde la nesfarsit. */
@media (min-width: 2200px) {
    .cf-page {
        max-width: 2100px;
        margin: 0 auto;
    }
}

/* Acelasi principiu la 6 coloane: cardul de refacturari trece pe rand propriu. */
@media (max-width: 1500px) {
    .cf-main-grid,
    .cf-main-grid.mode-primar,
    .cf-main-grid.mode-primar-tona,
    .cf-main-grid.mode-distributie,
    .cf-main-grid.mode-primar-distributie,
    .cf-main-grid.mode-compresor {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
    .cf-main-grid {
        grid-template-areas:
            "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3"
            "ref ref ref ref ref ref"
            "activity activity activity activity activity activity"
            "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
            "tariffs tariffs tariffs tariffs tariffs tariffs"
            "refTable refTable refTable refTable refTable refTable";
    }
    .cf-main-grid.mode-primar-distributie {
        grid-template-areas:
            "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3"
            "ref ref ref ref ref ref"
            "primaryTable primaryTable primaryTable distribution distribution distribution"
            "distTable distTable distTable distTable distTable distTable"
            "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
            "tariffs tariffs tariffs tariffs tariffs tariffs"
            "refTable refTable refTable refTable refTable refTable";
    }
    .cf-main-grid.mode-primar {
        grid-template-areas:
            "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3"
            "ref ref ref ref ref ref"
            "primaryTable primaryTable primaryTable primaryTable primaryTable primaryTable"
            "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
            "tariffs tariffs tariffs tariffs tariffs tariffs"
            "refTable refTable refTable refTable refTable refTable";
    }
    .cf-main-grid.mode-primar-tona,
    .cf-main-grid.mode-compresor {
        grid-template-areas:
            "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3"
            "ref ref ref ref ref ref"
            "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
            "tariffs tariffs tariffs tariffs tariffs tariffs"
            "refTable refTable refTable refTable refTable refTable";
    }
    .cf-main-grid.mode-distributie {
        grid-template-areas:
            "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3"
            "ref ref ref ref ref ref"
            "distribution distribution distribution distribution distribution distribution"
            "distTable distTable distTable distTable distTable distTable"
            "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
            "tariffs tariffs tariffs tariffs tariffs tariffs"
            "refTable refTable refTable refTable refTable refTable";
    }
}

/*
 * Sub 1180px renuntam la grid-template-areas si lasam elementele sa curga in
 * ordinea din DOM (kpi1, kpi2, kpi3, ref, activity, distribution, tabele).
 * Asa functioneaza identic pentru toate variantele .mode-*, care au seturi
 * diferite de panouri, fara sa ramana randuri goale in grid.
 */
@media (max-width: 1180px) {
    .cf-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .cf-main-grid,
    .cf-main-grid.mode-primar,
    .cf-main-grid.mode-primar-tona,
    .cf-main-grid.mode-distributie,
    .cf-main-grid.mode-primar-distributie,
    .cf-main-grid.mode-compresor {
        grid-template-columns: repeat(6, minmax(0, 1fr));
        grid-template-areas: none;
    }
    /* Implicit tot ce e panou/card ocupa toata latimea... */
    .cf-main-grid > .cf-card,
    .cf-main-grid > .cf-panel {
        grid-area: auto;
        grid-column: 1 / -1;
    }
    /* ...iar cele trei carduri KPI stau pe acelasi rand. */
    .cf-main-grid > .cf-kpi { grid-column: auto / span 2; }
    .cf-dist-grid { grid-template-columns: 1fr; }
    .cf-dist-total {
        border-right: 0;
        border-bottom: 1px solid var(--cf-border);
        padding: 0 0 14px;
    }
}

/* Tablete: cardurile KPI raman pe un rand, deci au nevoie de continut compact. */
@media (max-width: 991.98px) {
    .cf-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cf-route-summary { max-width: 190px; }
    /* Cele trei carduri KPI raman pe un rand, deci au nevoie de continut mai compact. */
    .cf-kpi {
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 10px;
        padding: 14px;
    }
    .cf-kpi-icon {
        width: 34px;
        height: 34px;
        font-size: 27px;
    }
    .cf-kpi-value { font-size: 20px; }
    .cf-kpi-foot {
        flex-direction: column;
        align-items: flex-end;
        gap: 0;
        margin-top: 10px;
    }
}

/* Telefoane: o coloana si spatii mai mici, ca latimea sa ramana pentru date. */
@media (max-width: 767.98px) {
    /*
     * <main class="p-4"> din layout adauga 24px stanga/dreapta. Impreuna cu
     * padding-ul din .cf-main-grid si .cf-panel ramaneau ~260px utili din 375px,
     * asa ca recuperam o parte din marginea exterioara.
     */
    .cf-page {
        margin-left: -24px;
        margin-right: -24px;
    }
    .cf-shell {
        border-left: 0;
        border-right: 0;
        border-radius: 0;
    }
    .cf-header,
    .cf-footer { border-radius: 0; }
    .cf-scope { padding: 8px 12px; }
    .cf-scope-client { font-size: 14px; }
    .cf-main-grid > .cf-kpi,
    .cf-main-grid > .cf-ref-card,
    .cf-main-grid > .cf-activity-summary,
    .cf-main-grid > .cf-distribution-summary { grid-column: 1 / -1; }
    .cf-main-grid { padding: 10px; gap: 10px; }
    .cf-panel { padding: 12px; }
    .cf-panel h2 {
        margin-bottom: 11px;
        padding-bottom: 10px;
        font-size: 13px;
    }
    .cf-header,
    .cf-footer,
    .cf-vehicle-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .cf-header { min-height: 0; padding: 14px; gap: 12px; }
    .cf-title h1 { font-size: 21px; }
    .cf-export { justify-content: center; }
    .cf-filters { grid-template-columns: 1fr; padding: 12px; gap: 10px; }
    .cf-kpi { min-height: 0; padding: 14px; gap: 12px; }
    .cf-kpi-value,
    .cf-ref-value { font-size: 20px; }
    .cf-ref-head {
        grid-template-columns: 42px minmax(0, 1fr);
        min-height: 0;
        padding: 14px;
    }
    .cf-ref-close { grid-column: 2; justify-self: end; }
    .cf-ref-details { padding: 0 12px 12px; }
    .cf-ref-tabs { gap: 16px; }
    .cf-sort-form select { flex: 1 1 auto; min-width: 0; }
    .cf-footer { padding: 12px; }
    .cf-pagination { justify-content: space-between; }

    /*
     * Tabelele raman derulabile pe orizontala (au min-width), dar capetele de
     * coloana nu se mai rup pe 3-4 randuri si celulele sunt mai compacte.
     */
    .cf-table th,
    .cf-table td,
    .cf-ref-table th,
    .cf-ref-table td { padding: 8px 9px; }
    .cf-table th,
    .cf-ref-table th { white-space: nowrap; }
    .cf-route-summary { max-width: 150px; }

    .cf-breakdown { padding: 10px; }
    .cf-tiles { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; }
    .cf-facts { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 575.98px) {
    .cf-title h1 { font-size: 19px; }
    .cf-title p { font-size: 12px; }
    .cf-route-summary { max-width: 120px; }
}
</style>

<div class="cf-page <?= $isCompact ? 'is-compact' : 'is-detailed' ?>">
    <div class="cf-shell">
        <header class="cf-header">
            <div class="cf-title">
                <div class="cf-title-icon" aria-hidden="true"><i class="bi bi-card-list"></i></div>
                <div>
                    <h1>Centralizator facturare</h1>
                    <p>Sumar activități și facturare pe curse - pentru toate tipurile de transport</p>
                </div>
            </div>
            <div class="cf-header-actions">
                <div class="cf-view-switch" role="group" aria-label="Densitate tabele">
                    <a class="<?= $isCompact ? 'is-active' : '' ?>" href="<?= e($queryFor(['view' => 'compact'])) ?>" aria-current="<?= $isCompact ? 'true' : 'false' ?>">Compact</a>
                    <a class="<?= $isCompact ? '' : 'is-active' ?>" href="<?= e($queryFor(['view' => 'detaliat'])) ?>" aria-current="<?= $isCompact ? 'false' : 'true' ?>">Detaliat</a>
                </div>
                <a class="cf-export" href="<?= e($exportUrl) ?>"><i class="bi bi-file-earmark-excel-fill" aria-hidden="true"></i> Export Excel</a>
            </div>
        </header>

        <form class="cf-filters" method="get" data-auto-filter-form>
            <input type="hidden" name="page" value="centralizator_facturare">
            <input type="hidden" name="view" value="<?= e($tableView) ?>">
            <div class="cf-field is-required">
                <label for="cf_month">Lună</label>
                <select id="cf_month" name="month">
                    <?php foreach ((array) ($lookups['months'] ?? []) as $month): ?>
                        <option value="<?= e((string) ($month['value'] ?? '')) ?>" <?= $filterValue('month') === (string) ($month['value'] ?? '') ? 'selected' : '' ?>>
                            <?= e((string) ($month['label'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field is-required">
                <label for="cf_beneficiar">Beneficiar / Client</label>
                <select id="cf_beneficiar" name="beneficiar_id">
                    <option value="0" <?= $isAllBeneficiaries ? 'selected' : '' ?>>Toți beneficiarii</option>
                    <?php foreach ((array) ($lookups['beneficiaries'] ?? []) as $beneficiary): ?>
                        <?php $beneficiaryId = (string) ((int) ($beneficiary['id'] ?? 0)); ?>
                        <option value="<?= e($beneficiaryId) ?>" <?= $filterValue('beneficiar_id') === $beneficiaryId ? 'selected' : '' ?>>
                            <?= e((string) ($beneficiary['nume'] ?? '-')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field">
                <label for="cf_activity">Tip activitate</label>
                <select id="cf_activity" name="tip_activitate">
                    <?php foreach ((array) ($lookups['activity_types'] ?? []) as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= $filterValue('tip_activitate') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field">
                <label for="cf_cargo">Tip marfă</label>
                <select id="cf_cargo" name="tip_marfa">
                    <option value="">Toate</option>
                    <?php foreach ((array) ($lookups['cargo'] ?? []) as $cargo): ?>
                        <option value="<?= e((string) ($cargo['value'] ?? '')) ?>" <?= $filterValue('tip_marfa') === (string) ($cargo['value'] ?? '') ? 'selected' : '' ?>>
                            <?= e((string) ($cargo['label'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field">
                <label for="cf_vehicle">Vehicul</label>
                <select id="cf_vehicle" name="vehicle_id">
                    <option value="">Toate vehiculele</option>
                    <?php foreach ((array) ($lookups['vehicles'] ?? []) as $vehicle): ?>
                        <?php $vehicleId = (string) ((int) ($vehicle['id'] ?? 0)); ?>
                        <option value="<?= e($vehicleId) ?>" <?= $filterValue('vehicle_id') === $vehicleId ? 'selected' : '' ?>>
                            <?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a class="cf-reset-btn" href="<?= e($resetUrl) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează</a>
            <?php /* Restul filtrelor continua mai jos; bara de context e dupa formular. */ ?>
            <div class="cf-field">
                <label for="cf_loading">Loc încărcare</label>
                <select id="cf_loading" name="loc_incarcare_id" data-route-parent-filter>
                    <option value="">Toate</option>
                    <?php foreach ((array) ($lookups['loading_locations'] ?? []) as $location): ?>
                        <?php $locationId = (string) ((int) ($location['id'] ?? 0)); ?>
                        <option value="<?= e($locationId) ?>" <?= $filterValue('loc_incarcare_id') === $locationId ? 'selected' : '' ?>>
                            <?= e((string) ($location['label'] ?? 'Necunoscut')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field">
                <label for="cf_unloading">Zonă descărcare</label>
                <select id="cf_unloading" name="zona_distributie_id" data-route-parent-filter>
                    <option value="">Toate</option>
                    <?php foreach ((array) ($lookups['unloading_zones'] ?? []) as $zone): ?>
                        <?php $zoneId = (string) ((int) ($zone['id'] ?? 0)); ?>
                        <option value="<?= e($zoneId) ?>" <?= $filterValue('zona_distributie_id') === $zoneId ? 'selected' : '' ?>>
                            <?= e((string) ($zone['label'] ?? 'Necunoscut')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field">
                <label for="cf_route">Rută</label>
                <select id="cf_route" name="ruta">
                    <option value="">Toate</option>
                    <?php foreach ((array) ($lookups['routes'] ?? []) as $route): ?>
                        <option value="<?= e((string) ($route['value'] ?? '')) ?>" <?= $filterValue('ruta') === (string) ($route['value'] ?? '') ? 'selected' : '' ?>>
                            <?= e((string) ($route['label'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php /* Bara de context: ramane lipita sub topbar cat timp derulezi raportul. */ ?>
        <div class="cf-scope <?= $isAllBeneficiaries ? 'is-all' : '' ?>" role="status">
            <div class="cf-scope-main">
                <span class="cf-scope-lead">Raport pentru</span>
                <strong class="cf-scope-client"><i class="bi <?= $isAllBeneficiaries ? 'bi-globe2' : 'bi-buildings-fill' ?>" aria-hidden="true"></i> <?= e($scopeBeneficiary !== '' ? $scopeBeneficiary : 'beneficiar neselectat') ?></strong>
                <?php if ($scopeMonth !== ''): ?>
                    <span class="cf-scope-month"><i class="bi bi-calendar3" aria-hidden="true"></i> <?= e($scopeMonth) ?></span>
                <?php endif; ?>
            </div>
            <div class="cf-scope-filters">
                <?php if ($activeFilters === []): ?>
                    <span class="cf-scope-hint"><?= $isAllBeneficiaries ? 'Situația generală - toate activitățile, toți beneficiarii' : 'Fără alte filtre - toate activitățile acestui beneficiar' ?></span>
                <?php else: ?>
                    <?php foreach ($activeFilters as $activeFilter): ?>
                        <span class="cf-scope-chip"><b><?= e((string) $activeFilter['label']) ?>:</b> <?= e((string) $activeFilter['value']) ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <main class="cf-main-grid <?= e($gridMode) ?>">
            <?php foreach (array_values($kpiCards) as $index => $card): ?>
                <?php
                $cardUnit = (string) ($card['unit'] ?? '');
                $breakdown = (array) ($card['breakdown'] ?? []);
                $breakdownId = 'cf-kpi-breakdown-' . $index;
                ?>
                <article class="cf-card cf-kpi is-<?= e((string) ($card['theme'] ?? 'blue')) ?>">
                    <div class="cf-kpi-icon" aria-hidden="true"><i class="bi <?= e((string) ($card['icon'] ?? 'bi-bar-chart-fill')) ?>"></i></div>
                    <div>
                        <span class="cf-kpi-title"><?= e((string) ($card['title'] ?? '-')) ?></span>
                        <strong class="cf-kpi-value"><?= e($kpiValue($card)) ?></strong>
                        <div class="cf-kpi-foot"><?= $comparisonMarkup($card['comparison'] ?? null) ?></div>
                        <?php if ($breakdown !== []): ?>
                            <button class="cf-kpi-toggle" type="button" aria-expanded="false" aria-controls="<?= e($breakdownId) ?>" data-kpi-toggle>
                                <i class="bi bi-chevron-down" aria-hidden="true"></i> Pe tipuri de transport
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($breakdown !== []): ?>
                        <ul class="cf-kpi-breakdown" id="<?= e($breakdownId) ?>" hidden>
                            <?php foreach ($breakdown as $item): ?>
                                <?php $itemColor = (string) ($item['color'] ?? '#2f7df4'); ?>
                                <li class="cf-kpi-bd-row" style="--dot: <?= e($itemColor) ?>">
                                    <span class="cf-transport-name"><span class="cf-dot"></span><?= e((string) ($item['label'] ?? '-')) ?></span>
                                    <strong><?= e($kpiValue(['value' => $item['value'] ?? 0, 'unit' => $cardUnit])) ?></strong>
                                    <span class="cf-kpi-bd-meta">
                                        <?= $renderShare($item['share_percent'] ?? 0, $itemColor) ?>
                                        <span>
                                            <?php if ($cardUnit !== 'curse'): ?><?= e($fmtSmart($item['trips'] ?? 0, 0)) ?> curse<?php endif; ?>
                                            <?= $breakdownChange($item['comparison'] ?? null) ?>
                                        </span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <article class="cf-card cf-ref-card <?= $refDefaultExpanded ? 'is-expanded' : '' ?>" data-ref-card>
                <div class="cf-ref-head">
                    <div class="cf-ref-icon" aria-hidden="true"><i class="bi bi-receipt-cutoff"></i></div>
                    <div>
                        <span class="cf-ref-title"><?= e((string) ($refKpi['title'] ?? 'Total refacturări')) ?></span>
                        <strong class="cf-ref-value"><?= e($fmtMoneyKpi($refKpi['value'] ?? 0)) ?></strong>
                        <button class="cf-ref-sub" type="button" data-ref-toggle><i class="bi bi-arrow-down" aria-hidden="true"></i> Click pentru detalii</button>
                        <div class="cf-kpi-foot"><?= $comparisonMarkup($refKpi['comparison'] ?? null) ?></div>
                    </div>
                    <button class="cf-ref-close" type="button" aria-label="Închide detaliile" data-ref-close>&times;</button>
                </div>
                <div class="cf-ref-details">
                    <div class="cf-ref-tabs" role="tablist">
                        <button class="cf-ref-tab is-active" type="button" data-ref-tab="crossings">Sumar treceri (poduri / porturi)</button>
                        <button class="cf-ref-tab" type="button" data-ref-tab="types">Sumar pe tipuri de cursă</button>
                    </div>
                    <div class="cf-ref-panel is-active" data-ref-panel="crossings">
                        <div class="cf-ref-scroll">
                            <table class="cf-ref-table">
                                <thead><tr><th>Locație trecere</th><th class="is-number">Nr. treceri</th><th class="is-number">Valoare (RON)</th></tr></thead>
                                <tbody>
                                <?php if ($refGroups === []): ?>
                                    <tr><td colspan="3"><div class="cf-empty">Nu există refacturări pentru filtrul curent.</div></td></tr>
                                <?php else: ?>
                                    <?php foreach ($refGroups as $group): ?>
                                        <tr>
                                            <td><span class="cf-ref-location"><i class="bi bi-bank" aria-hidden="true"></i><?= e((string) ($group['label'] ?? '-')) ?></span></td>
                                            <td class="is-number"><?= e($fmtSmart($group['quantity'] ?? 0, 0)) ?></td>
                                            <td class="is-number"><?= e($fmtMoney($group['amount'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="cf-total-row">
                                        <td>TOTAL TRECERI</td>
                                        <td class="is-number"><?= e($fmtSmart($refacturari['quantity_total'] ?? 0, 0)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($refacturari['total_amount'] ?? 0)) ?></td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="cf-ref-panel" data-ref-panel="types">
                        <div class="cf-ref-scroll">
                            <table class="cf-ref-table">
                                <thead><tr><th>Tip cursă</th><th class="is-number">Linii</th><th class="is-number">Valoare (RON)</th></tr></thead>
                                <tbody>
                                <?php if ($refTypeGroups === []): ?>
                                    <tr><td colspan="3"><div class="cf-empty">Nu există grupări disponibile.</div></td></tr>
                                <?php else: ?>
                                    <?php foreach ($refTypeGroups as $group): ?>
                                        <tr>
                                            <td><?= e((string) ($group['label'] ?? '-')) ?></td>
                                            <td class="is-number"><?= e($fmtSmart($group['records'] ?? 0, 0)) ?></td>
                                            <td class="is-number"><?= e($fmtMoney($group['amount'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="cf-total-row"><td>TOTAL</td><td class="is-number"><?= e($fmtSmart($refacturari['record_count'] ?? 0, 0)) ?></td><td class="is-number"><?= e($fmtMoney($refacturari['total_amount'] ?? 0)) ?></td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <a class="cf-ref-all" href="#cf_ref_table">Vezi toate refacturările <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                </div>
            </article>

            <?php if (!empty($visibility['activity_summary'])): ?>
                <section class="cf-panel cf-activity-summary">
                    <h2>Activități pe tipuri de transport <i class="bi bi-info-circle" aria-hidden="true"></i></h2>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead><tr><th class="cf-expand-th"></th><th>Tip transport</th><th class="is-number">Curse</th><th class="is-number">Km</th><th class="is-number">Tone/Activ.</th><th class="is-number">% din total curse</th></tr></thead>
                            <tbody>
                            <?php foreach ($activityRows as $row): ?>
                                <?php
                                $metricTone = (float) ($row['tone'] ?? 0) > 0 ? $fmtSmart($row['tone'], 2) . ' t' : ((float) ($row['activity'] ?? 0) > 0 ? $fmtSmart($row['activity'], 2) . ' ' . (string) ($row['activity_unit'] ?? '') : '-');
                                $typeKey = (string) ($row['key'] ?? '');
                                $typeRoutes = (array) ($row['routes'] ?? []);
                                $typeLeftoverRefunds = (array) ($row['refund_leftovers'] ?? []);
                                /* Un tip fara curse in luna dar cu refacturari pe curse din alte luni ramane desfasurabil. */
                                $typeExpandable = $typeRoutes !== [] || $typeLeftoverRefunds !== [];
                                $typeDetailId = 'cf_type_routes_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $typeKey);
                                ?>
                                <tr<?= $typeExpandable ? ' class="cf-vehicle-parent" data-vehicle-row' : '' ?>>
                                    <td class="cf-expand-cell">
                                        <?php if ($typeExpandable): ?>
                                            <button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="<?= e($typeDetailId) ?>" aria-label="Rute <?= e((string) ($row['label'] ?? '')) ?>" data-vehicle-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="cf-transport-name"><span class="cf-dot" style="--dot: <?= e((string) ($row['color'] ?? '#2f7df4')) ?>"></span><?= e((string) ($row['label'] ?? '-')) ?></span></td>
                                    <td class="is-number"><?= e($fmtSmart($row['trips'] ?? 0, 0)) ?></td>
                                    <td class="is-number"><?= e($fmtKm($row['km'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($metricTone) ?></td>
                                    <td class="is-number"><?= $renderShare($row['share_percent'] ?? 0, (string) ($row['color'] ?? '#2f7df4')) ?></td>
                                </tr>
                                <?php if ($typeExpandable): ?>
                                    <tr class="cf-vehicle-detail-row" id="<?= e($typeDetailId) ?>" hidden>
                                        <td colspan="6" class="cf-trip-detail-cell"><?= $renderTypeRoutes($typeKey, $typeRoutes, (string) ($row['group_by'] ?? 'route'), $typeLeftoverRefunds) ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <tr class="cf-total-row">
                                <td></td>
                                <td>TOTAL</td>
                                <td class="is-number"><?= e($fmtSmart($activityTotals['trips'] ?? 0, 0)) ?></td>
                                <td class="is-number"><?= e($fmtKm($activityTotals['km'] ?? 0)) ?></td>
                                <td class="is-number"><?= e($fmtTone($activityTotals['tone'] ?? 0)) ?></td>
                                <td class="is-number"><?= (int) ($activityTotals['trips'] ?? 0) > 0 ? '100%' : '0%' ?></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="cf-note">Deschide un tip de transport pentru rutele facturate (cantitate × preț), apoi o rută pentru cursele ei sau rândul de refacturări pentru fiecare refacturare.</p>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['distribution'])): ?>
                <section class="cf-panel cf-distribution-summary">
                    <h2>Distribuție - Rezumat <i class="bi bi-info-circle" aria-hidden="true"></i></h2>
                    <?php
                    /*
                     * Rezumatul urmeaza cum e facturata distributia in Configurare transport
                     * (billing): pe km -> totalul si split-ul pe km; pe tona -> ca inainte;
                     * unitati diferite in filtru (ex. mai multi beneficiari) -> split pe valoare.
                     */
                    $distBilling = (array) ($distribution['billing'] ?? []);
                    $distMetric = (string) ($distBilling['metric'] ?? 'tone');
                    $distShareBasis = (string) ($distBilling['share_basis'] ?? 'tone');
                    $distUsesKm = !empty($distBilling['uses_km']);
                    $distRateUnit = (string) ($distBilling['rate_unit_label'] ?? '');
                    $distMetricFmt = $distMetric === 'km' ? $fmtKm : $fmtTone;
                    $distBucketQty = static function (array $bucket) use ($distShareBasis, $distUsesKm, $fmtSmart, $fmtMoney): string {
                        return match ($distShareBasis) {
                            'km' => $fmtSmart($bucket['km'] ?? 0, 0) . ' km',
                            'tone' => $fmtSmart($bucket['tone'] ?? 0, 2) . ' t' . ($distUsesKm ? ' · ' . $fmtSmart($bucket['km'] ?? 0, 0) . ' km' : ''),
                            default => $fmtMoney($bucket['value'] ?? 0) . ' RON',
                        };
                    };
                    ?>
                    <?php if ((int) ($distribution['total_trips'] ?? 0) <= 0): ?>
                        <div class="cf-empty">Nu există activitate de distribuție pentru filtrul curent.</div>
                    <?php else: ?>
                    <div class="cf-dist-grid">
                        <div class="cf-dist-total">
                            <span class="cf-label"><?= $distMetric === 'km' ? 'Total km facturați' : 'Total tone' ?></span>
                            <strong class="cf-big"><?= e($distMetricFmt($distribution['total_' . $distMetric] ?? 0)) ?></strong>
                            <?php if ($distMetric === 'km'): ?>
                                <span class="cf-dist-secondary"><?= e($fmtTone($distribution['total_tone'] ?? 0)) ?> transportate</span>
                            <?php elseif ($distUsesKm): ?>
                                <span class="cf-dist-secondary"><?= e($fmtKm($distribution['total_km'] ?? 0)) ?> facturați pe km</span>
                            <?php endif; ?>
                            <div class="cf-cargo-list">
                                <?php foreach ((array) ($distribution['cargo_totals'] ?? []) as $cargo): ?>
                                    <div class="cf-cargo-row">
                                        <div><span><?= e((string) ($cargo['label'] ?? '-')) ?></span><strong><?= e($distMetricFmt($cargo[$distMetric] ?? 0)) ?></strong></div>
                                        <em><?= e($fmtPercent($cargo['percent'] ?? 0)) ?></em>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <div class="cf-label">Split preț<?= $distRateUnit !== '' ? ' (' . e($distRateUnit) . ')' : ($distShareBasis === 'value' ? ' (pe valoare)' : '') ?></div>
                            <div class="cf-bucket-list">
                                <?php foreach ((array) ($distribution['tariff_buckets'] ?? []) as $bucket): ?>
                                    <div class="cf-bucket-item">
                                        <div class="cf-bucket-top">
                                            <span class="cf-bucket-name"><span class="cf-dot" style="--dot: <?= e((string) ($bucket['color'] ?? '#2f7df4')) ?>"></span><?= e((string) ($bucket['label'] ?? '-')) ?></span>
                                            <span class="cf-bucket-tone"><?= e($distBucketQty($bucket)) ?></span>
                                        </div>
                                        <span class="cf-share-bar is-wide" style="--share: <?= e($sharePercent($bucket['percent'] ?? 0)) ?>%; --dot: <?= e((string) ($bucket['color'] ?? '#2f7df4')) ?>"><i></i></span>
                                        <div class="cf-bucket-bottom">
                                            <strong class="cf-bucket-rate"><?= ($bucket['rate_label'] ?? '') !== '' ? e((string) $bucket['rate_label']) : '-' ?></strong>
                                            <span class="cf-bucket-percent"><?= e($fmtPercent($bucket['percent'] ?? 0)) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (($distribution['warnings'] ?? []) !== []): ?><p class="cf-warning"><?= e((string) reset($distribution['warnings'])) ?></p><?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['primary_routes'])): ?>
                <?php
                /*
                 * O rută apare pe câte un rând per tarif chiar facturat: dacă tariful
                 * s-a schimbat în mijlocul lunii, vezi separat câte curse au mers pe
                 * vechiul preț și câte pe cel nou. Rândul se desfășoară cu explicația
                 * schimbării, adusă din Administrare tarife.
                 */
                $fmtPercentSigned = static function ($value): string {
                    $value = (float) $value;

                    return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%';
                };
                /* Preturile la combustibil se citesc cu 4 zecimale, ca in Administrare tarife. */
                $fmtFuelPrice = static fn ($value): string => $fmt(is_numeric($value) ? (float) $value : 0.0, 4);
                ?>
                <section class="cf-panel cf-primary-table">
                    <h2>Detalii Primar km - pe rute</h2>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead><tr><th class="cf-expand-th"></th><th>Rută</th><th class="is-number">Curse</th><th class="is-number">Km parcurși</th><th class="is-number">Preț / km (RON)</th><th class="is-number">Valoare (RON)</th></tr></thead>
                            <tbody>
                            <?php foreach ($primaryRoutes as $index => $route): ?>
                                <?php
                                $tariff = is_array($route['tariff'] ?? null) ? $route['tariff'] : null;
                                $isSplit = (int) ($route['route_rate_count'] ?? 1) > 1;
                                $isContinuation = $isSplit && empty($route['is_route_first']);
                                $detailId = 'cf-primary-tariff-' . $index;
                                $label = (string) ($route['route_short'] ?? '-') . ' la ' . (string) ($route['rate_label'] ?? '-') . ' lei/km';
                                ?>
                                <tr class="cf-primary-row<?= $isSplit ? ' is-split' : '' ?><?= $isContinuation ? ' is-continuation' : '' ?>">
                                    <td class="cf-expand-cell">
                                        <button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="<?= e($detailId) ?>" aria-label="Detalii tarif <?= e($label) ?>" data-vehicle-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                                    </td>
                                    <td>
                                        <?php if ($isContinuation): ?>
                                            <span class="cf-route-continuation" aria-hidden="true">↳</span>
                                        <?php endif; ?>
                                        <?= e((string) ($route['route_short'] ?? '-')) ?> (<?= e((string) ($route['route_label'] ?? '-')) ?>)
                                        <?php if ($isSplit && !empty($route['is_route_first'])): ?>
                                            <span class="cf-route-split-badge"><?= e((string) $route['route_rate_count']) ?> tarife în perioadă</span>
                                        <?php endif; ?>
                                        <?php if ($isSplit): ?>
                                            <span class="cf-route-period"><?= e((string) ($route['period_label'] ?? '-')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="is-number"><?= e($fmtSmart($route['trips'] ?? 0, 0)) ?></td>
                                    <td class="is-number"><?= e($fmtKm($route['km'] ?? 0)) ?></td>
                                    <td class="is-number">
                                        <?= e((string) ($route['rate_label'] ?? '-')) ?>
                                        <?php if ($tariff !== null && !empty($tariff['changed_in_period'])): ?>
                                            <span class="cf-rate-flag" title="Tarif intrat în vigoare în luna raportului">nou din <?= e((string) $tariff['valid_from_label']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="is-number"><?= e($fmtMoney($route['value'] ?? 0)) ?></td>
                                </tr>
                                <tr class="cf-vehicle-detail-row" id="<?= e($detailId) ?>" hidden>
                                    <td colspan="6" class="cf-trip-detail-cell">
                                        <div class="cf-tariff-why">
                                            <?php if ($tariff !== null): ?>
                                                <?php if (!empty($tariff['changed_in_period'])): ?>
                                                    <p class="cf-tariff-why-alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Tariful s-a schimbat în interiorul lunii raportate, de la <?= e((string) $tariff['valid_from_label']) ?>. De aceea ruta apare pe mai multe rânduri.</p>
                                                <?php endif; ?>
                                                <dl class="cf-tariff-why-grid">
                                                    <div><dt>Tarif aplicat</dt><dd><?= e($fmtMoney($tariff['value'] ?? 0)) ?> <?= e((string) ($tariff['unit'] ?? '')) ?> <span class="cf-tariff-why-muted">(<?= e((string) ($tariff['component_label'] ?? '')) ?>)</span></dd></div>
                                                    <div><dt>În vigoare</dt><dd><?= e((string) ($tariff['valid_from_label'] ?? '-')) ?> → <?= e((string) ($tariff['valid_to_label'] ?? 'în continuare')) ?></dd></div>
                                                    <?php if ($tariff['previous_value'] !== null): ?>
                                                        <div><dt>Tarif anterior</dt><dd><?= e($fmtMoney($tariff['previous_value'])) ?> <?= e((string) ($tariff['unit'] ?? '')) ?><?php if ($tariff['delta_percent'] !== null): ?> <span class="cf-tariff-delta <?= ((float) $tariff['delta_percent']) >= 0 ? 'is-up' : 'is-down' ?>"><?= e($fmtPercentSigned($tariff['delta_percent'])) ?></span><?php endif; ?></dd></div>
                                                    <?php endif; ?>
                                                    <div><dt>Curse la acest tarif</dt><dd><?= e($fmtSmart($route['trips'] ?? 0, 0)) ?> · <?= e((string) ($route['period_label'] ?? '-')) ?></dd></div>
                                                    <?php if (($tariff['changed_by'] ?? '') !== '' || ($tariff['changed_at_label'] ?? '') !== ''): ?>
                                                        <div><dt>Operat de</dt><dd><?= e((string) ($tariff['changed_by'] ?? '-')) ?><?php if (($tariff['changed_at_label'] ?? '') !== ''): ?> · <?= e((string) $tariff['changed_at_label']) ?><?php endif; ?></dd></div>
                                                    <?php endif; ?>
                                                    <?php if (($tariff['fuel']['variation_percent'] ?? null) !== null): ?>
                                                        <div><dt>Variație combustibil</dt><dd>
                                                            <span class="cf-tariff-delta <?= ((float) $tariff['fuel']['variation_percent']) >= 0 ? 'is-up' : 'is-down' ?>"><?= e($fmtPercentSigned($tariff['fuel']['variation_percent'])) ?></span>
                                                            <?php if (($tariff['fuel']['reference_price'] ?? null) !== null && ($tariff['fuel']['observed_price'] ?? null) !== null): ?>
                                                                <span class="cf-tariff-why-muted">referință <?= e($fmtFuelPrice($tariff['fuel']['reference_price'])) ?> → observat <?= e($fmtFuelPrice($tariff['fuel']['observed_price'])) ?> lei/L</span>
                                                            <?php endif; ?>
                                                            <?php if (($tariff['fuel']['liters'] ?? null) !== null): ?>
                                                                <span class="cf-tariff-why-muted">· <?= e($fmtSmart($tariff['fuel']['liters'], 2)) ?> L analizați</span>
                                                            <?php endif; ?>
                                                            <?php if (($tariff['fuel']['period_start'] ?? null) !== null): ?>
                                                                <span class="cf-tariff-why-muted">· <?= e((string) $tariff['fuel']['period_start']) ?> - <?= e((string) ($tariff['fuel']['period_end'] ?? $tariff['fuel']['period_start'])) ?></span>
                                                            <?php endif; ?>
                                                        </dd></div>
                                                    <?php endif; ?>
                                                    <?php if (($tariff['reason'] ?? '') !== ''): ?>
                                                        <div><dt>Motiv</dt><dd><?= e((string) $tariff['reason']) ?></dd></div>
                                                    <?php endif; ?>
                                                </dl>
                                            <?php else: ?>
                                                <p class="cf-tariff-why-empty">Curse facturate la <?= e((string) ($route['rate_label'] ?? '-')) ?> lei/km în perioada <?= e((string) ($route['period_label'] ?? '-')) ?>. Nu am găsit o versiune de tarif cu această valoare în Administrare tarife pentru beneficiarul selectat.</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="cf-total-row"><td class="cf-expand-cell"></td><td>TOTAL</td><td class="is-number"><?= e($fmtSmart($primaryTotals['trips'] ?? 0, 0)) ?></td><td class="is-number"><?= e($fmtKm($primaryTotals['km'] ?? 0)) ?></td><td class="is-number">-</td><td class="is-number"><?= e($fmtMoney($primaryTotals['value'] ?? 0)) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['distribution_matrix'])): ?>
                <?php
                /* Coloanele de pret arata cantitatea principala (km sau tone); km facturati apar separat cand exista si tone. */
                $matrixBilling = (array) ($distribution['billing'] ?? []);
                $matrixMetric = (string) ($matrixBilling['metric'] ?? 'tone');
                $matrixUnit = $matrixMetric === 'km' ? 'km' : 'tone';
                $matrixDecimals = $matrixMetric === 'km' ? 0 : 2;
                $matrixExtraKm = $matrixMetric !== 'km' && !empty($matrixBilling['uses_km']);
                $matrixBuckets = (array) ($distribution['tariff_buckets'] ?? []);
                ?>
                <section class="cf-panel cf-distribution-table">
                    <h2>Detalii Distribuție - pe marfă și preț</h2>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead>
                            <tr>
                                <th>Tip marfă</th>
                                <?php foreach ($matrixBuckets as $bucket): ?><th class="is-number"><?= e((string) ($bucket['label'] ?? '-')) ?><?php if (($bucket['rate_label'] ?? '') !== '' && str_starts_with((string) ($bucket['label'] ?? ''), 'Preț')): ?> · <?= e((string) $bucket['rate_label']) ?><?php endif; ?> (<?= e($matrixUnit) ?>)</th><?php endforeach; ?>
                                <th class="is-number">Total <?= e($matrixUnit) ?></th>
                                <?php if ($matrixMetric === 'km'): ?><th class="is-number">Total tone</th><?php elseif ($matrixExtraKm): ?><th class="is-number">Total km</th><?php endif; ?>
                                <th class="is-number">% din total</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (($distribution['cargo_by_tariff'] ?? []) === []): ?>
                                <tr><td colspan="<?= e((string) (4 + count($matrixBuckets))) ?>"><div class="cf-empty">Nu există activitate de distribuție pentru filtrul curent.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ((array) ($distribution['cargo_by_tariff'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= e((string) ($row['label'] ?? '-')) ?></td>
                                    <?php foreach ($matrixBuckets as $bucket): ?>
                                        <?php $bucketKey = (string) ($bucket['key'] ?? ''); ?>
                                        <td class="is-number"><?= e($fmtPlain($row['buckets'][$bucketKey] ?? 0, $matrixDecimals)) ?></td>
                                    <?php endforeach; ?>
                                    <td class="is-number"><?= e($fmtSmart($row['total_' . $matrixMetric] ?? 0, $matrixDecimals)) ?></td>
                                    <?php if ($matrixMetric === 'km'): ?><td class="is-number"><?= e($fmtSmart($row['total_tone'] ?? 0, 2)) ?></td><?php elseif ($matrixExtraKm): ?><td class="is-number"><?= e($fmtSmart($row['total_km'] ?? 0, 0)) ?></td><?php endif; ?>
                                    <td class="is-number"><?= e($fmtPercent($row['percent'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="cf-total-row">
                                <td>TOTAL</td>
                                <?php foreach ($matrixBuckets as $bucket): ?>
                                    <?php $bucketKey = (string) ($bucket['key'] ?? ''); ?>
                                    <td class="is-number"><?= e($fmtPlain($distribution['matrix_totals']['buckets'][$bucketKey] ?? 0, $matrixDecimals)) ?></td>
                                <?php endforeach; ?>
                                <td class="is-number"><?= e($fmtSmart($distribution['matrix_totals']['total_' . $matrixMetric] ?? 0, $matrixDecimals)) ?></td>
                                <?php if ($matrixMetric === 'km'): ?><td class="is-number"><?= e($fmtSmart($distribution['matrix_totals']['total_tone'] ?? 0, 2)) ?></td><?php elseif ($matrixExtraKm): ?><td class="is-number"><?= e($fmtSmart($distribution['matrix_totals']['total_km'] ?? 0, 0)) ?></td><?php endif; ?>
                                <td class="is-number"><?= (float) ($distribution['matrix_totals']['percent'] ?? 0) > 0 ? '100%' : '0%' ?></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['vehicle_detail'])): ?>
                <section class="cf-panel cf-vehicle-detail">
                    <?php
                    /* In compact scoatem coloana de ruta din tabel: ajunge in randul expandat. */
                    $detailColumns = (array) ($vehicleDetail['columns'] ?? []);
                    if ($isCompact) {
                        $detailColumns = array_values(array_filter(
                            $detailColumns,
                            static fn (array $col): bool => (string) ($col['key'] ?? '') !== 'route_summary'
                        ));
                    }
                    /*
                     * Tabelul e grupat pe capacitate, deci primele doua coloane au doua
                     * intelesuri: pe randul de grup arata capacitatea si numarul de
                     * vehicule, pe randul de vehicul numarul de inmatriculare si capacitatea.
                     */
                    $detailColumns = array_map(static function (array $col): array {
                        $key = (string) ($col['key'] ?? '');
                        if ($key === 'vehicle') {
                            $col['label'] = 'Capacitate / Vehicul';
                        } elseif ($key === 'capacity') {
                            $col['label'] = 'Vehicule / Capacitate';
                        }

                        return $col;
                    }, $detailColumns);
                    $detailColCount = max(1, count($detailColumns));
                    $detailRows = (array) ($vehicleDetail['rows'] ?? []);
                    $capacityGroups = $groupRowsByCapacity($detailRows, $capacityGroupsDescending);
                    ?>
                    <div class="cf-vehicle-toolbar">
                        <h2 style="margin:0;padding-bottom:0;border-bottom:0;">Activitate pe vehicule - Detaliat (după activitatea filtrată)</h2>
                        <?php if ($capacityGroups !== []): ?>
                            <div class="cf-toolbar-controls">
                                <label class="cf-filter-box">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input type="search" placeholder="Caută capacitate sau vehicul" aria-label="Filtrează după capacitate sau vehicul" data-vehicle-filter>
                                </label>
                                <div class="cf-group-actions">
                                    <button type="button" data-cap-expand-all>Extinde toate</button>
                                    <span aria-hidden="true">|</span>
                                    <button type="button" data-cap-collapse-all>Restrânge toate</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="cf-table-wrap">
                        <table class="cf-table cf-grouped">
                            <thead><tr><?php foreach ($detailColumns as $col): ?><?php $headKey = (string) ($col['key'] ?? ''); ?><th class="<?= $headKey === 'toggle' ? 'cf-expand-th' : (($col['align'] ?? '') === 'right' ? 'is-number' : '') ?>"><?= e((string) ($col['label'] ?? '')) ?></th><?php endforeach; ?></tr></thead>
                            <?php if ($capacityGroups === []): ?>
                                <tbody>
                                <tr><td colspan="<?= e((string) $detailColCount) ?>"><div class="cf-empty">Nu există vehicule cu activitate pentru filtrul curent.</div></td></tr>
                                </tbody>
                            <?php else: ?>
                                <?php foreach ($capacityGroups as $group): ?>
                                    <?php
                                    $groupId = 'cf_cap_detail_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $group['key']);
                                    $groupLabel = $group['capacity'] !== null ? $fmtCapacity($group['capacity']) : 'Fără capacitate';
                                    $vehicleCount = (int) $group['vehicles'];
                                    $groupSums = $sumFlatMetrics($group['rows']);
                                    ?>
                                    <tbody class="cf-cap-head">
                                    <tr class="cf-cap-group" data-cap-row>
                                        <?php foreach ($detailColumns as $col): ?>
                                            <?php $key = (string) ($col['key'] ?? ''); ?>
                                            <td class="<?= ($col['align'] ?? '') === 'right' ? 'is-number' : '' ?>">
                                                <?php if ($key === 'toggle'): ?><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="<?= e($groupId) ?>" data-cap-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                                                <?php elseif ($key === 'vehicle'): ?><span class="cf-cap-label"><?= e($groupLabel) ?></span>
                                                <?php elseif ($key === 'capacity'): ?><span class="cf-cap-count"><?= e($fmtSmart($vehicleCount, 0)) ?> <?= $vehicleCount === 1 ? 'vehicul' : 'vehicule' ?></span>
                                                <?php elseif ($key === 'route_summary'): ?>-
                                                <?php elseif ($key === 'value'): ?><?= e($fmtMoney($groupSums[$key] ?? 0)) ?>
                                                <?php elseif ($key === 'km'): ?><?= e($fmtKm($groupSums[$key] ?? 0)) ?>
                                                <?php elseif ($key === 'tone'): ?><?= e($fmtTone($groupSums[$key] ?? 0)) ?>
                                                <?php elseif ($key === 'trips'): ?><?= e($fmtSmart($groupSums[$key] ?? 0, 0)) ?>
                                                <?php elseif ($key === 'activity'): ?><?= e($fmtPlain($groupSums[$key] ?? 0, 2)) ?>
                                                <?php else: ?>-<?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    </tbody>
                                    <tbody class="cf-cap-body" id="<?= e($groupId) ?>" hidden>
                                    <?php foreach ($group['rows'] as $row): ?>
                                        <?php $vehicleKey = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($row['key'] ?? uniqid('veh_', false))); $detailId = 'cf_vehicle_detail_' . $vehicleKey; ?>
                                        <tr class="cf-vehicle-parent" data-vehicle-row>
                                            <?php foreach ($detailColumns as $col): ?>
                                                <?php $key = (string) ($col['key'] ?? ''); ?>
                                                <td class="<?= ($col['align'] ?? '') === 'right' ? 'is-number' : ($key === 'vehicle' ? 'cf-vehicle-cell' : '') ?>">
                                                    <?php if ($key === 'toggle'): ?><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="<?= e($detailId) ?>" data-vehicle-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                                                    <?php elseif ($key === 'vehicle'): ?><i class="bi bi-truck" aria-hidden="true"></i> <?= e((string) ($row[$key] ?? '-')) ?>
                                                    <?php elseif ($key === 'route_summary'): ?><span class="cf-route-summary" title="<?= e((string) ($row[$key] ?? '-')) ?>"><?= e((string) ($row[$key] ?? '-')) ?></span>
                                                    <?php elseif ($key === 'capacity'): ?><?= e($fmtCapacity($row[$key] ?? null)) ?>
                                                    <?php elseif ($key === 'value'): ?><?= e($fmtMoney($row[$key] ?? 0)) ?>
                                                    <?php elseif ($key === 'km'): ?><?= e($fmtKm($row[$key] ?? 0)) ?>
                                                    <?php elseif ($key === 'tone'): ?><?= e($fmtTone($row[$key] ?? 0)) ?>
                                                    <?php elseif ($key === 'trips'): ?><?= e($fmtSmart($row[$key] ?? 0, 0)) ?>
                                                    <?php elseif ($key === 'activity'): ?><?= e($fmtPlain($row[$key] ?? 0, 2)) ?>
                                                    <?php else: ?><?= e((string) ($row[$key] ?? '-')) ?><?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr class="cf-vehicle-detail-row" id="<?= e($detailId) ?>" hidden>
                                            <td colspan="<?= e((string) $detailColCount) ?>" class="cf-trip-detail-cell">
                                                <?php $tripsHtml = $renderTripDetails((array) ($row['detail_rows'] ?? []), (array) ($row['detail_columns'] ?? $vehicleTripColumns)); ?>
                                                <?php if ($isCompact): ?>
                                                    <?= $renderVehicleBreakdown([], [
                                                        ['label' => 'Rută', 'value' => (string) ($row['route_summary'] ?? '-')],
                                                    ], $tripsHtml) ?>
                                                <?php else: ?>
                                                    <?= $tripsHtml ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                <?php endforeach; ?>
                                <tbody>
                                <tr class="cf-total-row">
                                    <?php foreach ($detailColumns as $col): ?>
                                        <?php $key = (string) ($col['key'] ?? ''); ?>
                                        <td class="<?= ($col['align'] ?? '') === 'right' ? 'is-number' : '' ?>">
                                            <?php if ($key === 'toggle'): ?>
                                            <?php elseif ($key === 'vehicle'): ?>TOTAL
                                            <?php elseif ($key === 'capacity'): ?>-
                                            <?php elseif ($key === 'route_summary'): ?>-
                                            <?php elseif ($key === 'value'): ?><?= e($fmtMoney($vehicleDetail['totals'][$key] ?? 0)) ?>
                                            <?php elseif ($key === 'km'): ?><?= e($fmtKm($vehicleDetail['totals'][$key] ?? 0)) ?>
                                            <?php elseif ($key === 'tone'): ?><?= e($fmtTone($vehicleDetail['totals'][$key] ?? 0)) ?>
                                            <?php elseif ($key === 'trips'): ?><?= e($fmtSmart($vehicleDetail['totals'][$key] ?? 0, 0)) ?>
                                            <?php elseif ($key === 'activity'): ?><?= e($fmtPlain($vehicleDetail['totals'][$key] ?? 0, 2)) ?>
                                            <?php else: ?>-<?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                                </tbody>
                            <?php endif; ?>
                        </table>
                    </div>
                    <p class="cf-filter-note" data-vehicle-filter-note hidden></p>
                    <p class="cf-note">Se afișează doar vehiculele care au activitate pentru filtrele selectate.</p>
                    <?php if (($vehicles['warnings'] ?? []) !== []): ?><p class="cf-warning"><?= e((string) reset($vehicles['warnings'])) ?></p><?php endif; ?>
                </section>
            <?php endif; ?>

            <?php
            /*
             * Evolutia tarifelor: ce tarif se aplica lunii raportului, ce valoare a
             * inlocuit si cine a operat schimbarea in Administrare tarife. Panoul se
             * randeaza mereu, ca zona "tariffs" din grid sa nu ramana goala.
             */
            $tariffDate = static function (?string $value): string {
                $value = trim((string) $value);
                if ($value === '') {
                    return '-';
                }
                $timestamp = strtotime($value);

                return $timestamp !== false ? date('d.m.Y', $timestamp) : $value;
            };
            ?>
            <section class="cf-panel cf-tariff-evolution">
                <h2>Evoluție tarife (Administrare tarife) <i class="bi bi-info-circle" aria-hidden="true"></i></h2>
                <?php if ($tariffEvolution === []): ?>
                    <div class="cf-empty">Nu există tarife versionate pentru luna și filtrele selectate.</div>
                <?php else: ?>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead>
                            <tr>
                                <?php if ($tariffShowBeneficiary): ?><th>Beneficiar</th><?php endif; ?>
                                <th>Tarif</th>
                                <th>Rută</th>
                                <th class="is-number">Valoare</th>
                                <th class="is-number">Valoare anterioară</th>
                                <th class="is-number">Valabil din</th>
                                <th class="is-number">Până la</th>
                                <th>Modificat în Administrare tarife</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tariffEvolution as $tariffRow): ?>
                                <?php
                                $previousValue = $tariffRow['previous_value'] ?? null;
                                $deltaPercent = $previousValue !== null && (float) $previousValue > 0
                                    ? ((((float) $tariffRow['value']) - (float) $previousValue) / (float) $previousValue) * 100
                                    : null;
                                $changedBy = trim((string) ($tariffRow['changed_by'] ?? ''));
                                $changedAt = trim((string) ($tariffRow['changed_at'] ?? ''));
                                $fuelVariation = $tariffRow['fuel_variation'] ?? null;
                                ?>
                                <tr>
                                    <?php if ($tariffShowBeneficiary): ?><td><?= e((string) ($tariffRow['beneficiary'] ?? '-')) ?></td><?php endif; ?>
                                    <td>
                                        <?= e((string) ($tariffRow['component_label'] ?? '-')) ?>
                                        <span class="cf-tariff-scope"><?= e((string) ($tariffRow['transport_label'] ?? '')) ?></span>
                                    </td>
                                    <td><?= e((string) ($tariffRow['route_label'] ?? '-')) ?></td>
                                    <td class="is-number"><?= e($fmtMoney($tariffRow['value'] ?? 0) . ' ' . (string) ($tariffRow['unit'] ?? '')) ?></td>
                                    <td class="is-number">
                                        <?php if ($previousValue === null): ?>
                                            <span class="cf-dim">primul tarif</span>
                                        <?php else: ?>
                                            <?= e($fmtMoney($previousValue)) ?>
                                            <?php if ($deltaPercent !== null): ?>
                                                <span class="cf-tariff-delta <?= $deltaPercent >= 0 ? 'is-up' : 'is-down' ?>"><?= e(($deltaPercent >= 0 ? '+' : '') . $fmt($deltaPercent, 2) . '%') ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="is-number"><?= e($tariffDate((string) ($tariffRow['valid_from'] ?? ''))) ?></td>
                                    <td class="is-number"><?= ($tariffRow['valid_to'] ?? null) !== null ? e($tariffDate((string) $tariffRow['valid_to'])) : '<span class="cf-dim">în vigoare</span>' ?></td>
                                    <td class="cf-tariff-meta">
                                        <?= $changedBy !== '' || $changedAt !== '' ? e(trim($changedBy . ($changedAt !== '' ? ' · ' . $tariffDate($changedAt) : ''))) : '<span class="cf-dim">-</span>' ?>
                                        <?php if ($fuelVariation !== null): ?>
                                            <span class="cf-tariff-scope" title="Variația prețului la combustibil la momentul modificării"><?= e(($fuelVariation >= 0 ? '+' : '') . $fmt($fuelVariation, 2) . '% combustibil') ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="cf-note">Tarifele în vigoare pentru luna raportului, cu valoarea pe care au înlocuit-o. Modificările se operează în Administrare tarife.</p>
                <?php endif; ?>
            </section>

            <section class="cf-panel cf-refact-table" id="cf_ref_table">
                <?php
                /*
                 * In compact panoul raspunde la intrebarea din anexa: cate treceri,
                 * porturi, diurne etc. si ce suma. Gruparea vine din summary_groups,
                 * unde cantitatea e deja numarul de bucati pentru taxele de drum si
                 * numarul de inregistrari pentru restul.
                 */
                $refByType = [];
                foreach ($refGroups as $refGroup) {
                    $typeKey = (string) ($refGroup['type'] ?? 'alte');
                    $refByType[$typeKey] ??= ['label' => $refTypeLabel($typeKey), 'quantity' => 0.0, 'amount' => 0.0, 'lines' => []];
                    $refByType[$typeKey]['quantity'] += (float) ($refGroup['quantity'] ?? 0);
                    $refByType[$typeKey]['amount'] += (float) ($refGroup['amount'] ?? 0);
                    $refByType[$typeKey]['lines'][] = $refGroup;
                }
                uasort($refByType, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
                $refTypeQtyTotal = array_sum(array_column($refByType, 'quantity'));
                $refTypeAmountTotal = array_sum(array_column($refByType, 'amount'));
                ?>
                <h2><?= $isCompact ? 'Refacturări - sumar pe tipuri' : 'Refacturări - sumar pe curse' ?></h2>
                <?php if ($isCompact): ?>
                    <div class="cf-table-wrap">
                        <table class="cf-table cf-grouped">
                            <thead>
                            <tr><th class="cf-expand-th"></th><th>Tip</th><th class="is-number">Nr.</th><th class="is-number">Valoare (RON)</th></tr>
                            </thead>
                            <?php if ($refByType === []): ?>
                                <tbody>
                                <tr><td colspan="4"><div class="cf-empty">Nu există refacturări pentru filtrul curent.</div></td></tr>
                                </tbody>
                            <?php else: ?>
                                <?php foreach ($refByType as $typeKey => $refType): ?>
                                    <?php $refGroupId = 'cf_ref_type_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $typeKey); ?>
                                    <tbody class="cf-cap-head">
                                    <tr class="cf-cap-group" data-cap-row>
                                        <td class="cf-expand-cell"><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="<?= e($refGroupId) ?>" data-cap-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>
                                        <td><span class="cf-cap-label"><?= e((string) $refType['label']) ?></span></td>
                                        <td class="is-number"><?= e($fmtSmart($refType['quantity'], 2)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($refType['amount'])) ?></td>
                                    </tr>
                                    </tbody>
                                    <tbody class="cf-cap-body" id="<?= e($refGroupId) ?>" hidden>
                                    <?php foreach ($refType['lines'] as $refLine): ?>
                                        <tr class="cf-leaf-row">
                                            <td></td>
                                            <td><?= e((string) ($refLine['label'] ?? '-')) ?></td>
                                            <td class="is-number"><?= e($fmtSmart($refLine['quantity'] ?? 0, 2)) ?></td>
                                            <td class="is-number"><?= e($fmtMoney($refLine['amount'] ?? 0)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                <?php endforeach; ?>
                                <tbody>
                                <tr class="cf-total-row">
                                    <td></td>
                                    <td>TOTAL REFACTURĂRI</td>
                                    <td class="is-number"><?= e($fmtSmart($refTypeQtyTotal, 2)) ?></td>
                                    <td class="is-number"><?= e($fmtMoney($refTypeAmountTotal)) ?></td>
                                </tr>
                                </tbody>
                            <?php endif; ?>
                        </table>
                    </div>
                    <p class="cf-note">Numărul reprezintă bucăți pentru taxele de drum (acces, port, trecere) și număr de înregistrări pentru celelalte tipuri. Comută pe „Detaliat” pentru lista completă pe curse.</p>
                <?php else: ?>
                <?php $refCols = 11; ?>
                <div class="cf-table-wrap">
                    <table class="cf-table">
                        <thead>
                            <tr><th>Nr. cursă</th><th>Data</th><th>Tip activitate</th><th>Rută / Zonă</th><th>Vehicul</th><th>Tip marfă</th><th class="is-number">Tone</th><th class="is-number">Km</th><th class="is-number">Valoare cursă (RON)</th><th class="is-number">Refacturare (RON)</th><th>Observații</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($refRows === []): ?>
                            <tr><td colspan="<?= e((string) $refCols) ?>"><div class="cf-empty">Nu există refacturări pentru filtrul curent.</div></td></tr>
                        <?php else: ?>
                            <?php foreach ($refRows as $row): ?>
                                <tr>
                                    <td><?= e((string) ($row['race_no'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['date_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['tip_transport_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['route_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['vehicle_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['tip_marfa_label'] ?? '-')) ?></td>
                                    <td class="is-number"><?= e($fmtTone($row['tone'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtKm($row['km'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtMoney($row['trip_value'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtMoney($row['refacturare_amount'] ?? 0)) ?></td>
                                    <td><?= e(implode(' / ', array_values((array) ($row['observations'] ?? []))) ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="cf-total-row"><td colspan="8">TOTAL REFACTURĂRI</td><td class="is-number"><?= e($fmtMoney($refacturari['totals_by_table']['trip_value'] ?? 0)) ?></td><td class="is-number"><?= e($fmtMoney($refacturari['totals_by_table']['refacturare'] ?? 0)) ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php if (($refacturari['warnings'] ?? []) !== []): ?><p class="cf-warning"><?= e((string) reset($refacturari['warnings'])) ?></p><?php endif; ?>
            </section>
        </main>

        <footer class="cf-footer">
            <span>Ultima actualizare: <?= e($fmtDate($generatedAt)) ?></span>
            <div class="cf-pagination">
                <form method="get">
                    <input type="hidden" name="page" value="centralizator_facturare">
                    <input type="hidden" name="month" value="<?= e($filterValue('month')) ?>">
                    <input type="hidden" name="beneficiar_id" value="<?= e($filterValue('beneficiar_id')) ?>">
                    <input type="hidden" name="tip_activitate" value="<?= e($filterValue('tip_activitate')) ?>">
                    <input type="hidden" name="tip_marfa" value="<?= e($filterValue('tip_marfa')) ?>">
                    <input type="hidden" name="loc_incarcare_id" value="<?= e($filterValue('loc_incarcare_id')) ?>">
                    <input type="hidden" name="zona_distributie_id" value="<?= e($filterValue('zona_distributie_id')) ?>">
                    <input type="hidden" name="ruta" value="<?= e($filterValue('ruta')) ?>">
                    <input type="hidden" name="vehicle_id" value="<?= e($filterValue('vehicle_id')) ?>">
                    <input type="hidden" name="vehicle_sort" value="<?= e($filterValue('vehicle_sort')) ?>">
                    <select class="cf-page-size" name="per_page" onchange="this.form.submit()" aria-label="Rezultate pe pagină">
                        <?php foreach ((array) ($lookups['per_page_options'] ?? [10, 25, 50]) as $option): ?>
                            <option value="<?= e((string) $option) ?>" <?= (int) ($refPagination['per_page'] ?? 10) === (int) $option ? 'selected' : '' ?>><?= e((string) $option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <span><?= e((string) ($refPagination['from'] ?? 0)) ?>-<?= e((string) ($refPagination['to'] ?? 0)) ?> din <?= e((string) ($refPagination['total_rows'] ?? 0)) ?></span>
                <?php $currentPageNo = (int) ($refPagination['page'] ?? 1); $totalPages = (int) ($refPagination['total_pages'] ?? 1); ?>
                <a class="cf-page-link <?= $currentPageNo <= 1 ? 'is-disabled' : '' ?>" href="<?= e($queryFor(['p' => (string) max(1, $currentPageNo - 1)])) ?>" aria-label="Pagina anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                <a class="cf-page-link <?= $currentPageNo >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e($queryFor(['p' => (string) min($totalPages, $currentPageNo + 1)])) ?>" aria-label="Pagina următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </div>
        </footer>
    </div>
</div>

<script>
(function () {
    /* Cardurile generale: defalcarea pe tipuri de transport se deschide la cerere. */
    document.querySelectorAll('[data-kpi-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var panel = document.getElementById(button.getAttribute('aria-controls'));
            if (!panel) {
                return;
            }
            var expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            panel.hidden = expanded;
        });
    });

    var filterForm = document.querySelector('[data-auto-filter-form]');
    if (filterForm) {
        filterForm.querySelectorAll('select').forEach(function (field) {
            field.addEventListener('change', function () {
                var routeField = filterForm.querySelector('select[name="ruta"]');
                if (routeField && field.hasAttribute('data-route-parent-filter')) {
                    routeField.value = '';
                }
                if (typeof filterForm.requestSubmit === 'function') {
                    filterForm.requestSubmit();
                } else {
                    filterForm.submit();
                }
            });
        });
    }

    /* Latimea vizibila a fiecarui tabel derulabil, pentru randurile expandate. */
    function syncVisibleWidths() {
        document.querySelectorAll('.cf-table-wrap').forEach(function (wrap) {
            wrap.style.setProperty('--cf-visible-width', wrap.clientWidth + 'px');
        });
    }

    syncVisibleWidths();
    window.addEventListener('resize', syncVisibleWidths);

    function toggleVehicleDetail(button) {
        if (!button) {
            return;
        }
        var detailId = button.getAttribute('aria-controls') || '';
        var detailRow = detailId ? document.getElementById(detailId) : null;
        if (!detailRow) {
            return;
        }
        var expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        detailRow.hidden = expanded;
        var parentRow = button.closest('tr');
        if (parentRow) {
            parentRow.classList.toggle('is-expanded', !expanded);
        }
    }

    document.querySelectorAll('[data-vehicle-toggle]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            toggleVehicleDetail(button);
        });
    });

    /*
     * Nivelul de grup (capacitate). Randurile vehiculelor stau intr-un <tbody>
     * propriu, deci un singur toggle le ascunde/afiseaza pe toate. La restrangere
     * inchidem si detaliile de vehicul deschise inauntru, ca sa nu ramana o stare
     * pe jumatate deschisa la reexpandare.
     */
    function toggleCapacityGroup(button, force) {
        if (!button) {
            return;
        }
        var groupId = button.getAttribute('aria-controls') || '';
        var body = groupId ? document.getElementById(groupId) : null;
        if (!body) {
            return;
        }
        var expanded = button.getAttribute('aria-expanded') === 'true';
        var next = typeof force === 'boolean' ? force : !expanded;
        if (next === expanded) {
            return;
        }
        button.setAttribute('aria-expanded', next ? 'true' : 'false');
        body.hidden = !next;
        if (!next) {
            body.querySelectorAll('[data-vehicle-toggle][aria-expanded="true"]').forEach(function (inner) {
                toggleVehicleDetail(inner);
            });
        }
    }

    document.querySelectorAll('[data-cap-toggle]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            toggleCapacityGroup(button);
        });
    });

    document.querySelectorAll('[data-cap-row]').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('button, a, input, select, textarea, label')) {
                return;
            }
            toggleCapacityGroup(row.querySelector('[data-cap-toggle]'));
        });
    });

    document.querySelectorAll('[data-cap-expand-all], [data-cap-collapse-all]').forEach(function (control) {
        var expand = control.hasAttribute('data-cap-expand-all');
        control.addEventListener('click', function () {
            var scope = control.closest('.cf-panel') || document;
            scope.querySelectorAll('[data-cap-toggle]').forEach(function (button) {
                toggleCapacityGroup(button, expand);
            });
            /* Daca un filtru e activ, il reaplicam ca sa nu reapara randuri ascunse. */
            var filter = scope.querySelector('[data-vehicle-filter]');
            if (filter && filter.value.trim() !== '') {
                filter.dispatchEvent(new Event('input'));
            }
        });
    });

    /*
     * Filtrare in pagina pentru tabelele grupate pe capacitate.
     * Cauta atat in eticheta grupului ("7 t", "Fara capacitate") cat si in numarul
     * de inmatriculare. Cand doar unele vehicule dintr-un grup corespund, grupul se
     * deschide automat si arata numai vehiculele gasite.
     */
    function normalizeText(value) {
        /* Fara diacritice: "Distributie" trebuie sa gaseasca si "Distribuție". */
        return (value || '').toString().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    document.querySelectorAll('[data-vehicle-filter]').forEach(function (input) {
        var panel = input.closest('.cf-panel');
        if (!panel) {
            return;
        }
        var note = panel.querySelector('[data-vehicle-filter-note]');
        var totalRow = panel.querySelector('.cf-total-row');
        var heads = Array.prototype.slice.call(panel.querySelectorAll('.cf-cap-head'));

        function bodyOf(head) {
            var button = head.querySelector('[data-cap-toggle]');
            var id = button && button.getAttribute('aria-controls');
            return id ? document.getElementById(id) : null;
        }

        function applyFilter() {
            var query = normalizeText(input.value.trim());
            var filtering = query !== '';
            var matched = 0;
            var total = 0;

            heads.forEach(function (head) {
                var body = bodyOf(head);
                var button = head.querySelector('[data-cap-toggle]');
                var groupRow = head.querySelector('.cf-cap-group');
                var rows = body ? Array.prototype.slice.call(body.querySelectorAll('tr.cf-vehicle-parent')) : [];
                total += rows.length;

                if (!filtering) {
                    head.hidden = false;
                    rows.forEach(function (row) { row.hidden = false; });
                    toggleCapacityGroup(button, false);
                    return;
                }

                /*
                 * Cautam doar in celulele de identitate (capacitate, nr. inmatriculare),
                 * nu in tot randul: altfel "20" ar corespunde si unui "1.200 km".
                 */
                var groupLabel = groupRow ? groupRow.querySelector('.cf-cap-label') : null;
                var groupHit = normalizeText(groupLabel ? groupLabel.textContent : '').indexOf(query) !== -1;
                var hits = 0;
                rows.forEach(function (row) {
                    var plate = row.querySelector('.cf-vehicle-cell');
                    var capacity = row.children[2];
                    var identity = normalizeText((plate ? plate.textContent : '') + ' ' + (capacity ? capacity.textContent : ''));
                    var hit = groupHit || identity.indexOf(query) !== -1;
                    row.hidden = !hit;
                    if (hit) {
                        hits++;
                    } else {
                        var detail = row.nextElementSibling;
                        if (detail && detail.classList.contains('cf-vehicle-detail-row')) {
                            detail.hidden = true;
                        }
                    }
                });

                matched += hits;
                head.hidden = hits === 0;
                toggleCapacityGroup(button, hits > 0);
            });

            if (totalRow) {
                /* Totalul descrie intreg setul, nu selectia: il ascundem cat timp filtram. */
                totalRow.hidden = filtering;
            }
            if (note) {
                note.hidden = !filtering;
                note.classList.toggle('is-empty', filtering && matched === 0);
                if (!filtering) {
                    note.textContent = '';
                } else {
                    note.textContent = matched === 0
                        ? 'Niciun vehicul nu corespunde căutării.'
                        : 'Se afișează ' + matched + ' din ' + total + ' vehicule. Totalul este ascuns cât timp filtrezi.';
                }
            }
        }

        input.addEventListener('input', applyFilter);
        input.addEventListener('search', applyFilter);
    });

    document.querySelectorAll('[data-vehicle-row]').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('button, a, input, select, textarea, label')) {
                return;
            }
            toggleVehicleDetail(row.querySelector('[data-vehicle-toggle]'));
        });
        row.addEventListener('keydown', function (event) {
            if (event.target.closest('button, a, input, select, textarea, label')) {
                return;
            }
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            event.preventDefault();
            toggleVehicleDetail(row.querySelector('[data-vehicle-toggle]'));
        });
    });

    var card = document.querySelector('[data-ref-card]');
    if (!card) {
        return;
    }
    card.querySelectorAll('[data-ref-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            card.classList.toggle('is-expanded');
        });
    });
    card.querySelectorAll('[data-ref-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            card.classList.remove('is-expanded');
        });
    });
    card.querySelectorAll('[data-ref-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            var target = button.getAttribute('data-ref-tab') || '';
            card.querySelectorAll('[data-ref-tab]').forEach(function (tab) {
                tab.classList.toggle('is-active', tab === button);
            });
            card.querySelectorAll('[data-ref-panel]').forEach(function (panel) {
                panel.classList.toggle('is-active', panel.getAttribute('data-ref-panel') === target);
            });
        });
    });
})();
</script>
