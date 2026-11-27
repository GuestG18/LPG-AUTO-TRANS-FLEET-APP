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
$generatedAt = (string) ($report['generated_at'] ?? date('Y-m-d H:i:s'));
$mode = (string) ($filters['tip_activitate'] ?? '');


$filterValue = static fn (string $key): string => trim((string) ($filters[$key] ?? ''));
require __DIR__ . '/_kpi_helpers.php'; // $fmt, $fmtSmart, $fmtMoneyKpi, $fmtPercent, $comparisonMarkup, $breakdownChange, $kpiValue
$fmtMoney = static fn (float|int|string|null $value): string => $fmt(is_numeric($value) ? (float) $value : 0.0, 2);
$fmtKm = static fn (float|int|string|null $value): string => (is_numeric($value) && (float) $value > 0) ? $fmtSmart($value, 0) . ' km' : '-';
$fmtTone = static fn (float|int|string|null $value): string => (is_numeric($value) && (float) $value > 0) ? $fmtSmart($value, 2) . ' tone' : '-';
$fmtCapacity = static fn (mixed $value): string => is_numeric($value) && (float) $value > 0 ? $fmtSmart($value, 2) . ' t' : '-';
$fmtPlain = static fn (float|int|string|null $value, int $maxDecimals = 2): string => is_numeric($value) && (float) $value > 0 ? $fmtSmart($value, $maxDecimals) : '-';
$fmtDate = static fn (string $value): string => ($ts = strtotime($value)) !== false ? date('d.m.Y H:i', $ts) : date('d.m.Y H:i');

/* Filtrele cu selectie multipla, cu numele parametrilor din URL (param[]=...). */
$listFilters = [
    'beneficiar_id' => array_map('strval', (array) ($filters['beneficiar_ids'] ?? [])),
    'tip_activitate' => array_map('strval', (array) ($filters['tip_activitate_list'] ?? [])),
    'tip_marfa' => array_map('strval', (array) ($filters['tip_marfa_list'] ?? [])),
    'loc_incarcare_id' => array_map('strval', (array) ($filters['loc_incarcare_ids'] ?? [])),
    'zona_distributie_id' => array_map('strval', (array) ($filters['zona_distributie_ids'] ?? [])),
    'ruta' => array_map('strval', (array) ($filters['rute'] ?? [])),
    'vehicle_id' => array_map('strval', (array) ($filters['vehicle_ids'] ?? [])),
    'categorie_capacitate_id' => array_map('strval', (array) ($filters['capacity_category_ids'] ?? [])),
];

$queryFor = static function (array $overrides = []) use ($filters, $listFilters): string {
    $base = [
        'page' => 'centralizator_facturare',
        'month' => (string) ($filters['month'] ?? ''),
    ] + $listFilters + [
        'vehicle_sort' => (string) ($filters['vehicle_sort'] ?? 'capacity_asc'),
        'stare_cursa' => (string) ($filters['stare_cursa'] ?? ''),
        'per_page' => (string) ($filters['per_page'] ?? '10'),
    ];
    if ($base['stare_cursa'] === '') {
        unset($base['stare_cursa']);
    }
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($base[$key]);
        } else {
            $base[$key] = (string) $value;
        }
    }
    return build_query_url($base);
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

/* $footerRow: un rand <tr> optional, afisat ca <tfoot> (totalul unui grup de facturare). */
$renderTripDetails = static function (array $rows, array $columns, string $footerRow = '') use ($tripDetailValue): string {
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
                <?php if ($footerRow !== ''): ?><tfoot><?= $footerRow ?></tfoot><?php endif; ?>
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

/* Avertizarea de calcul: duce la cursele cu problema in Dispecer curse, cand exista acces. */
$calcWarnIcon = static function (string $title, string $href = ''): string {
    $icon = '<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>';

    return $href !== ''
        ? ' <a class="cf-calc-warn is-link" href="' . e($href) . '" title="' . e($title . ' Click: deschide în Dispecer curse.') . '" aria-label="' . e($title) . '">' . $icon . '</a>'
        : ' <span class="cf-calc-warn" title="' . e($title) . '">' . $icon . '</span>';
};

/*
 * Celula "Calcul facturare" a unui grup de facturare (ruta sau tarif), comuna pentru
 * "Activitati pe tipuri de transport" si pentru grupurile unui vehicul din
 * "Activitate pe vehicule - Detaliat": componentele adunate sau cantitate x pret,
 * cu avertizarea pentru cursele a caror valoare difera de recalculare.
 */
$renderGroupCalc = static function (array $group, string $label) use ($renderComponentCalc, $renderBillingCalc, $calcWarnIcon, $filters): string {
    $groupCalc = $renderComponentCalc((array) ($group['components'] ?? []));
    $missingCount = (int) ($group['missing_count'] ?? 0);
    if ($groupCalc === '' && !empty($group['component_billing'])) {
        $groupCalc = 'calcul indisponibil';
    } elseif ($groupCalc !== '' && $missingCount > 0) {
        /* Calculul adunat acopera doar cursele reconstituite; restul sunt semnalate. */
        $groupCalc .= ' + ' . $missingCount . ($missingCount === 1 ? ' cursă' : ' curse') . ' fără calcul';
    }
    $differsCount = (int) ($group['differs_count'] ?? 0);
    $groupWarn = '';
    if ($differsCount > 0) {
        $differIds = [];
        foreach ((array) ($group['trip_rows'] ?? []) as $trip) {
            if (!empty($trip['differs']) && (int) ($trip['trip_id'] ?? 0) > 0) {
                $differIds[] = (int) $trip['trip_id'];
            }
        }
        $groupWarn = $calcWarnIcon(
            $differsCount . ($differsCount === 1 ? ' cursă are' : ' curse au') . ' valoarea salvată diferită de recalcularea cu tarifele din data cursei.',
            can_route('dispecer_curse') && $differIds !== [] ? build_query_url([
                'page' => 'dispecer_curse',
                'ids' => implode(',', $differIds),
                'ids_label' => 'cursele cu valoare diferită de recalculare - ' . $label . ', ' . (string) ($filters['month_label'] ?? ''),
            ]) : ''
        );
    }
    $isAverage = !empty($group['rate_multiple']);
    $calcTitle = $isAverage ? ' title="Preț mediu: cursele rutei au prețuri diferite"' : '';
    $calcContent = e($groupCalc !== '' ? $groupCalc : $renderBillingCalc($group['quantity'] ?? 0, (string) ($group['unit'] ?? ''), $group['rate'] ?? null, $isAverage)) . $groupWarn;

    return '<td class="cf-calc"' . $calcTitle . '>' . $calcContent . '</td>';
};

/* Ruta (cod + eticheta) sau, la gruparea pe tarif, tariful + codurile rutelor lui. */
$renderGroupLabel = static function (array $group, bool $byPrice): string {
    return $byPrice
        ? e((string) ($group['label'] ?? '-')) . ' <span class="cf-route-codes">' . e(implode(', ', (array) ($group['route_codes'] ?? []))) . '</span>'
        : '<span class="cf-route-code">' . e((string) ($group['short'] ?? '')) . '</span>' . e((string) ($group['label'] ?? '-'));
};

$renderTypeRoutes = static function (string $type, array $routes, string $groupBy = 'route', array $leftoverRefunds = [], array $priceSplit = []) use ($fmt, $fmtSmart, $fmtMoney, $renderBillingCalc, $renderComponentCalc, $renderRefundToggle, $renderRefundPanel, $calcWarnIcon, $renderGroupCalc, $renderGroupLabel, $filters): string {
    /*
     * Avertizarea de calcul duce la cursele cu problema, in Dispecer curse (unde se
     * corecteaza tariful / valoarea): pe grup lista exacta a curselor, pe cursa cursa ei.
     * Fara acces la Dispecer curse ramane doar iconita cu explicatia.
     */
    $canOpenTrips = can_route('dispecer_curse');
    $warnIcon = $calcWarnIcon;
    $byPrice = $groupBy === 'price';
    $idPrefix = 'cf_route_trips_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $type) . '_';
    $html = '<div class="cf-breakdown"><div class="cf-type-routes"><table class="cf-table">'
        /* Calculul facturarii vine primul (ce se factureaza), apoi ruta sau tariful, cursele si valoarea. */
        . '<thead><tr><th class="cf-expand-th"></th>'
        . '<th>Calcul facturare</th><th>' . ($byPrice ? 'Tarif / rute' : 'Rută') . '</th><th class="is-number">Curse</th>'
        /* Ultima coloana: comutatorul refacturarilor, doar pe randurile care au refacturari. */
        . '<th class="is-number">Valoare RON</th><th class="cf-expand-th"></th></tr></thead><tbody>';

    /* Randul unei curse, neschimbat: Data / Vehicul / Ruta / Calcul facturare / Valoare RON. */
    $renderTripRow = static function (array $trip, string $short, string $unit) use ($byPrice, $canOpenTrips, $warnIcon, $fmtMoney, $renderBillingCalc, $renderComponentCalc): string {
        $tripId = (int) ($trip['trip_id'] ?? 0);
        $date = '<td>' . e((string) ($trip['date_label'] ?? '-')) . '</td>';
        $vehicle = '<td class="cf-vehicle-cell">' . e((string) ($trip['vehicle_label'] ?? '-')) . '</td>';
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
            $tripCalc .= $warnIcon(
                implode(' ', $warnParts),
                $canOpenTrips && $tripId > 0 ? build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $tripId]) : ''
            );
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

        /* Randul deschide cursa in Dispecer curse, ca in Istoric activitati sofer. */
        $rowAttrs = $canOpenTrips && $tripId > 0
            ? ' class="cf-trip-link" data-row-href="' . e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $tripId])) . '" tabindex="0" title="Deschide cursa în Dispecer curse"'
            : '';
        return '<tr' . $rowAttrs . '>'
            . ($byPrice ? $date . $routeCell . $vehicle : $date . $vehicle . $routeCell)
            . '<td class="is-number cf-calc">' . $tripCalc . '</td>'
            . '<td class="is-number">' . e($fmtMoney($trip['value'] ?? 0)) . '</td>'
            . '</tr>';
    };
    $tripHead = ($byPrice
            ? '<thead><tr><th>Data</th><th>Rută</th><th>Vehicul</th>'
            : '<thead><tr><th>Data</th><th>Vehicul</th><th>Rută</th>')
        . '<th class="is-number">Calcul facturare</th><th class="is-number">Valoare RON</th></tr></thead>';

    /*
     * Nivelul vehicul din grupul de facturare: un rand per vehicul cu cursele lui din
     * grupul acesta (sumarul vine din serviciu - groupVehicleSummaries), iar sub el, la
     * desfasurare, chiar cursele lui. La Distributie, tonele pe clasele de pret existente
     * (Pret mic / Pret mare, plus "Alte tarife" cand exista); fara clasificare mic / mare
     * in raport si la celelalte tipuri, calculul adunat al vehiculului.
     */
    $splitColumns = [];
    if ($type === 'distributie' && !empty($priceSplit['has_split'])) {
        $splitColumns = ['low' => 'Tone preț mic', 'high' => 'Tone preț mare'];
        if (!empty($priceSplit['has_other'])) {
            $splitColumns['other'] = 'Alte tarife';
        }
    }
    $splitCell = static function (array $split, string $column) use ($fmt, $fmtSmart): string {
        if ($column !== 'other') {
            return e($fmt($split[$column . '_tone'] ?? 0, 2) . ' t');
        }
        $parts = [];
        if ((float) ($split['other_tone'] ?? 0) > 0 || (float) ($split['other_km'] ?? 0) <= 0) {
            $parts[] = $fmt($split['other_tone'] ?? 0, 2) . ' t';
        }
        if ((float) ($split['other_km'] ?? 0) > 0) {
            $parts[] = $fmtSmart($split['other_km'], 0) . ' km';
        }
        $labels = (array) ($split['other_labels'] ?? []);
        $text = e(implode(' + ', $parts));

        return $labels !== [] ? '<span title="' . e(implode(', ', $labels)) . '">' . $text . '</span>' : $text;
    };
    $renderGroupVehicles = static function (array $route, string $tripsId, string $short, string $unit) use ($type, $splitColumns, $splitCell, $tripHead, $renderTripRow, $renderComponentCalc, $fmtSmart, $fmtMoney, $warnIcon, $canOpenTrips, $filters): string {
        $vehicles = (array) ($route['vehicles'] ?? []);
        $columnCount = 3 + ($splitColumns !== [] ? count($splitColumns) : 1);
        $html = '<div class="cf-breakdown cf-group-vehicles"><div class="cf-type-routes"><table class="cf-table">'
            . '<thead><tr><th class="cf-expand-th"></th><th>Vehicul</th>';
        if ($splitColumns !== []) {
            foreach ($splitColumns as $columnLabel) {
                $html .= '<th class="is-number">' . e($columnLabel) . '</th>';
            }
        } else {
            $html .= '<th>' . ($type === 'primar' ? 'Km × Tarif' : 'Calcul facturare') . '</th>';
        }
        $html .= '<th class="is-number">Valoare RON</th></tr></thead><tbody>';

        if ($vehicles === []) {
            $html .= '<tr><td colspan="' . $columnCount . '"><div class="cf-empty">Nu există curse în acest grup.</div></td></tr>';
        }
        foreach ($vehicles as $vehicle) {
            $plate = (string) ($vehicle['vehicle_label'] ?? '-');
            $vehicleTrips = (int) ($vehicle['trips'] ?? 0);
            /* Id stabil: grupul parinte + vehiculul, ca fiecare vehicul sa se deschida independent. */
            $detailId = $tripsId . '__' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($vehicle['key'] ?? ''));

            $cells = '';
            if ($splitColumns !== []) {
                foreach (array_keys($splitColumns) as $column) {
                    $cells .= '<td class="is-number cf-calc">' . $splitCell((array) ($vehicle['price_split'] ?? []), $column) . '</td>';
                }
            } else {
                $calc = $renderComponentCalc((array) ($vehicle['components'] ?? []));
                $missing = (int) ($vehicle['missing_count'] ?? 0);
                if ($calc === '' && !empty($vehicle['component_billing'])) {
                    $calc = 'calcul indisponibil';
                } elseif ($calc !== '' && $missing > 0) {
                    $calc .= ' + ' . $missing . ($missing === 1 ? ' cursă' : ' curse') . ' fără calcul';
                }
                $differs = (int) ($vehicle['differs_count'] ?? 0);
                $warn = '';
                if ($differs > 0) {
                    $differIds = [];
                    foreach ((array) ($vehicle['trip_rows'] ?? []) as $trip) {
                        if (!empty($trip['differs']) && (int) ($trip['trip_id'] ?? 0) > 0) {
                            $differIds[] = (int) $trip['trip_id'];
                        }
                    }
                    $warn = $warnIcon(
                        $differs . ($differs === 1 ? ' cursă are' : ' curse au') . ' valoarea salvată diferită de recalcularea cu tarifele din data cursei.',
                        $canOpenTrips && $differIds !== [] ? build_query_url([
                            'page' => 'dispecer_curse',
                            'ids' => implode(',', $differIds),
                            'ids_label' => 'cursele cu valoare diferită de recalculare - ' . $plate . ', ' . (string) ($filters['month_label'] ?? ''),
                        ]) : ''
                    );
                }
                $cells .= '<td class="cf-calc">' . e($calc !== '' ? $calc : '-') . $warn . '</td>';
            }

            $html .= '<tr class="cf-vehicle-parent cf-group-vehicle" data-vehicle-row>'
                . '<td class="cf-expand-cell"><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="' . e($detailId) . '" aria-label="Curse ' . e($plate) . '" data-vehicle-toggle data-nested-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>'
                . '<td class="cf-vehicle-cell">' . e($plate) . ' <span class="cf-vehicle-trips">' . e($fmtSmart($vehicleTrips, 0) . ($vehicleTrips === 1 ? ' cursă' : ' curse')) . '</span></td>'
                . $cells
                . '<td class="is-number">' . e($fmtMoney($vehicle['value'] ?? 0)) . '</td>'
                . '</tr>'
                . '<tr class="cf-vehicle-detail-row" id="' . e($detailId) . '" hidden><td colspan="' . $columnCount . '" class="cf-trip-detail-cell">'
                . '<div class="cf-breakdown"><div class="cf-type-routes"><table class="cf-table">' . $tripHead . '<tbody>';
            foreach ((array) ($vehicle['trip_rows'] ?? []) as $trip) {
                $html .= $renderTripRow($trip, $short, $unit);
            }
            $html .= '</tbody></table></div></div></td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    };

    foreach ($routes as $route) {
        $short = (string) ($route['short'] ?? '');
        $label = (string) ($route['label'] ?? '-');
        $unit = (string) ($route['unit'] ?? '');
        $tripsId = $idPrefix . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($route['key'] ?? ''));
        $groupCell = $renderGroupLabel($route, $byPrice);
        $routeRefunds = (array) ($route['refunds'] ?? []);
        /* Tinta separata de cea a curselor, ca cele doua sectiuni sa se deschida independent. */
        $refundsId = 'reinvoice-details-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $type . '-' . (string) ($route['key'] ?? ''));
        $tripsCell = '<td class="is-number">' . e($fmtSmart($route['trips'] ?? 0, 0)) . '</td>';

        $html .= '<tr class="cf-vehicle-parent" data-vehicle-row>'
            . '<td class="cf-expand-cell"><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="' . e($tripsId) . '" aria-label="Curse ' . e($label) . '" data-vehicle-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>'
            . $renderGroupCalc($route, $label) . '<td>' . $groupCell . '</td>' . $tripsCell
            . '<td class="is-number">' . e($fmtMoney($route['value'] ?? 0)) . '</td>'
            . '<td class="cf-expand-cell">' . ($routeRefunds !== [] ? $renderRefundToggle($refundsId, 'Refacturări ' . $label) : '') . '</td>'
            . '</tr>'
            . '<tr class="cf-vehicle-detail-row" id="' . e($tripsId) . '" hidden><td colspan="6" class="cf-trip-detail-cell">'
            . $renderGroupVehicles($route, $tripsId, $short, $unit)
            . '</td></tr>';

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

/*
 * Nivelul "rezumat calcul facturare" al unui vehicul din "Activitate pe vehicule -
 * Detaliat": aceleasi randuri ca la desfasurarea unui tip de transport (calcul, tarif /
 * ruta, curse, valoare, refacturari), dar doar cu cursele vehiculului. Fiecare rand
 * deschide, sub el, tabelul de curse existent al vehiculului, filtrat pe cursele grupului.
 */
$renderVehicleBillingGroups = static function (array $groups, string $idBase, array $detailRows, array $detailColumns) use ($fmtSmart, $fmtMoney, $fmtKm, $fmtTone, $renderGroupCalc, $renderGroupLabel, $renderRefundToggle, $renderRefundPanel, $renderTripDetails): string {
    /* Un vehicul cu mai multe tipuri de transport: punctul tipului arata de unde vine grupul. */
    $mixedTypes = count(array_unique(array_column($groups, 'type'))) > 1;
    /*
     * Totalul grupului, ca rand <tfoot> al tabelului lui de curse: aliniat pe aceleasi
     * coloane, adunat din valorile numerice ale curselor grupului (nu din textul afisat).
     * Primele coloane (pana la prima coloana numerica) poarta eticheta: ruta sau tariful
     * grupului si numarul de curse. Refacturarile nu intra, ca si in randul grupului.
     */
    $renderGroupFooter = static function (array $group, array $trips) use ($detailColumns, $fmtSmart, $fmtMoney, $fmtKm, $fmtTone): string {
        $labelSpan = 0;
        foreach ($detailColumns as $col) {
            if (($col['align'] ?? '') === 'right') {
                break;
            }
            $labelSpan++;
        }
        $labelSpan = max(1, $labelSpan);
        $context = ($group['group_by'] ?? 'route') === 'price'
            ? trim((string) ($group['label'] ?? '-') . ' · ' . implode(', ', (array) ($group['route_codes'] ?? [])), ' ·')
            : (string) ($group['label'] ?? '-');
        $count = count($trips);
        $sum = static fn (string $key): float => array_sum(array_map(static fn (array $trip): float => (float) ($trip[$key] ?? 0), $trips));

        $cells = '<td colspan="' . $labelSpan . '"><span class="cf-group-total-label">TOTAL</span> — ' . e($context)
            . ' <span class="cf-group-total-trips">' . e($fmtSmart($count, 0) . ($count === 1 ? ' cursă' : ' curse')) . '</span></td>';
        foreach (array_slice($detailColumns, $labelSpan) as $col) {
            $key = (string) ($col['key'] ?? '');
            $class = ($col['align'] ?? '') === 'right' ? ' class="is-number"' : '';
            $text = match ($key) {
                'km' => $fmtKm($sum('km')),
                'tone' => $fmtTone($sum('tone')),
                'value' => $fmtMoney(round($sum('value'), 2)),
                'compressor_activity_label' => $sum('compressor_activity') > 0
                    ? $fmtSmart($sum('compressor_activity'), 2) . ' ' . (string) ($trips[0]['compressor_unit'] ?? '')
                    : '-',
                default => '',
            };
            $cells .= '<td' . $class . '>' . e($text) . '</td>';
        }

        return '<tr class="cf-group-total">' . $cells . '</tr>';
    };
    /* Acelasi antet ca la desfasurarea unui tip de transport. */
    $onlyPriceGroups = $groups !== [] && array_filter($groups, static fn (array $group): bool => ($group['group_by'] ?? 'route') !== 'price') === [];
    $html = '<div class="cf-breakdown cf-vehicle-billing"><div class="cf-type-routes"><table class="cf-table">'
        . '<thead><tr><th class="cf-expand-th"></th><th>Calcul facturare</th><th>' . ($onlyPriceGroups ? 'Tarif / rute' : 'Rută') . '</th>'
        . '<th class="is-number">Curse</th><th class="is-number">Valoare RON</th><th class="cf-expand-th"></th></tr></thead><tbody>';
    foreach ($groups as $group) {
        $label = (string) ($group['label'] ?? '-');
        /* Id stabil din cheia grupului (tip + ruta / tarif), nu din pozitia lui. */
        $groupId = $idBase . '__' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($group['key'] ?? ''));
        $refunds = (array) ($group['refunds'] ?? []);
        $refundsId = 'reinvoice-details-' . $groupId;
        $tripKeys = array_flip((array) ($group['trip_keys'] ?? []));
        $groupTrips = array_values(array_filter($detailRows, static fn (array $trip): bool => isset($tripKeys[(string) ($trip['type'] ?? '') . ':' . (int) ($trip['trip_id'] ?? 0)])));
        /* Direct tabelul de curse (ruta e deja pe randul grupului si in coloana Rută), cu totalul grupului dedesubt. */
        $tripsHtml = $renderTripDetails($groupTrips, $detailColumns, $groupTrips !== [] ? $renderGroupFooter($group, $groupTrips) : '');
        $typeDot = $mixedTypes
            ? '<span class="cf-dot" style="--dot: ' . e((string) ($group['color'] ?? '#2f7df4')) . '" title="' . e((string) ($group['type_label'] ?? '')) . '"></span>'
            : '';

        $html .= '<tr class="cf-vehicle-parent" data-vehicle-row>'
            . '<td class="cf-expand-cell"><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="' . e($groupId) . '" aria-label="Curse ' . e($label) . '" data-vehicle-toggle data-nested-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>'
            . $renderGroupCalc($group, $label)
            . '<td>' . $typeDot . $renderGroupLabel($group, ($group['group_by'] ?? 'route') === 'price') . '</td>'
            . '<td class="is-number">' . e($fmtSmart($group['trips'] ?? 0, 0)) . '</td>'
            . '<td class="is-number">' . e($fmtMoney($group['value'] ?? 0)) . '</td>'
            . '<td class="cf-expand-cell">' . ($refunds !== [] ? str_replace('data-vehicle-toggle', 'data-vehicle-toggle data-nested-toggle', $renderRefundToggle($refundsId, 'Refacturări ' . $label)) : '') . '</td>'
            . '</tr>'
            . '<tr class="cf-vehicle-detail-row" id="' . e($groupId) . '" hidden><td colspan="6" class="cf-trip-detail-cell">' . $tripsHtml . '</td></tr>';
        if ($refunds !== []) {
            $html .= '<tr class="cf-vehicle-detail-row cf-refund-detail-row" id="' . e($refundsId) . '" hidden>'
                . '<td colspan="6" class="cf-trip-detail-cell">' . $renderRefundPanel($refunds, 'Refacturări', $refundsId) . '</td></tr>';
        }
    }

    /*
     * Totalul vehiculului, sub toate grupurile lui: o singura ruta -> numele ei, altfel
     * "vehicul". Km si tonele sunt adunate din curse, ca in totalul fiecarui grup;
     * refacturarile nu intra, ca si in randurile de mai sus.
     */
    $routeLabels = array_unique(array_map(static fn (array $group): string => ($group['group_by'] ?? 'route') === 'price' ? '' : (string) ($group['label'] ?? ''), $groups));
    $totalContext = count($routeLabels) === 1 && reset($routeLabels) !== '' ? ' — ' . e((string) reset($routeLabels)) : ' vehicul';
    $sumDetail = static fn (string $key): float => array_sum(array_map(static fn (array $trip): float => (float) ($trip[$key] ?? 0), $detailRows));
    /* compressor_activity e completat si pe alte tipuri de curse; aici conteaza doar compresorul. */
    $compressorTrips = array_values(array_filter($detailRows, static fn (array $trip): bool => (string) ($trip['type'] ?? '') === 'compresor'));
    $compressorActivity = array_sum(array_map(static fn (array $trip): float => (float) ($trip['compressor_activity'] ?? 0), $compressorTrips));
    $totalMetrics = array_filter([
        $sumDetail('km') > 0 ? $fmtKm($sumDetail('km')) : '',
        $sumDetail('tone') > 0 ? $fmtTone($sumDetail('tone')) : '',
        $compressorActivity > 0 ? $fmtSmart($compressorActivity, 2) . ' ' . (string) ($compressorTrips[0]['compressor_unit'] ?? '') : '',
    ], static fn (string $metric): bool => $metric !== '');

    return $html . '</tbody><tfoot><tr class="cf-group-total">'
        . '<td colspan="2"><span class="cf-group-total-label">TOTAL</span>' . $totalContext . '</td>'
        . '<td class="is-number">' . e(implode(' · ', $totalMetrics)) . '</td>'
        . '<td class="is-number">' . e($fmtSmart(array_sum(array_column($groups, 'trips')), 0)) . '</td>'
        . '<td class="is-number">' . e($fmtMoney(array_sum(array_column($groups, 'value')))) . '</td>'
        . '<td></td></tr></tfoot></table></div></div>';
};

/*
 * Gruparea pe capacitate, folosita de ambele tabele de vehicule.
 * Se face peste randurile deja filtrate si calculate de serviciu, deci agregarea
 * respecta automat filtrele active si nu dubleaza nimic fata de randul TOTAL.
 * Ordinea vehiculelor in interiorul unui grup ramane cea primita (vehicle_sort).
 */
/*
 * Gruparea randurilor de vehicul se face pe CATEGORIA de capacitate (eticheta din
 * catalogul centralizat), nu pe capacitatea tehnica. Capacitatea reala a fiecarui
 * vehicul ramane pe randul lui, in coloana "Capacitate", si este singura care
 * intra in calcule. Astfel un grup "18.5 TONE" poate contine vehicule de 18.5,
 * 19 si 20 de tone, exact ca in selectoare.
 */
$groupRowsByCapacity = static function (array $rows, bool $descending = false): array {
    $groups = [];
    foreach ($rows as $row) {
        $categoryId = (int) ($row['capacity_category_id'] ?? 0);
        $hasCategory = $categoryId > 0;
        $key = $hasCategory ? 'cat' . $categoryId : 'none';

        $groups[$key] ??= [
            'key' => $key,
            'category' => $hasCategory ? (string) ($row['capacity_category'] ?? '') : null,
            'order' => $hasCategory ? (int) ($row['capacity_category_order'] ?? 0) : PHP_INT_MAX,
            'vehicles' => 0,
            'rows' => [],
        ];
        $groups[$key]['vehicles']++;
        $groups[$key]['rows'][] = $row;
    }

    uasort($groups, static function (array $a, array $b) use ($descending): int {
        /* "Fara categorie" ramane ultimul indiferent de directia de sortare. */
        if ($a['category'] === null || $b['category'] === null) {
            return ($a['category'] === null ? 1 : 0) <=> ($b['category'] === null ? 1 : 0);
        }

        $byOrder = $descending ? ($b['order'] <=> $a['order']) : ($a['order'] <=> $b['order']);

        return $byOrder ?: strcmp((string) $a['category'], (string) $b['category']);
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

$scope = (array) ($report['scope'] ?? []);
$canOpenRefacturari = function_exists('can') ? can('dispecer_curse', 'refacturari_view') : true;
$isAllBeneficiaries = !empty($scope['all_beneficiaries']);
$scopeBeneficiary = (string) ($scope['beneficiary'] ?? '');
$scopeMonth = (string) ($filters['month_label'] ?? '') !== ''
    ? (string) $filters['month_label']
    : $labelFor((array) ($lookups['months'] ?? []), $filterValue('month'), 'value', 'label');

/* Doar filtrele optionale care restrang efectiv setul de date. */
$activeFilters = (array) ($scope['active'] ?? []);

/*
 * Filtru cu selectie multipla: un buton cu valorile alese si un meniu cu cautare si
 * casute de bifat. Nicio valoare bifata = toate. Formularul se trimite la inchiderea
 * meniului, o singura data, doar daca selectia s-a schimbat.
 */
$renderMultiSelect = static function (string $id, string $label, string $name, array $options, array $selected, string $placeholder, bool $required = false, bool $routeParent = false): string {
    $selectedMap = array_flip($selected);
    ob_start();
    ?>
    <div class="cf-field<?= $required ? ' is-required' : '' ?>">
        <label id="<?= e($id) ?>_label"><?= e($label) ?></label>
        <div class="cf-multi" data-multi-select<?= $routeParent ? ' data-route-parent-filter' : '' ?><?= $name === 'ruta' ? ' data-route-filter' : '' ?>>
            <button class="cf-multi-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-labelledby="<?= e($id) ?>_label">
                <span class="cf-multi-placeholder" data-multi-placeholder><?= e($placeholder) ?></span>
                <span class="cf-multi-values" data-multi-values></span>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="dropdown-menu cf-multi-menu">
                <?php if (count($options) > 6): ?>
                    <input type="search" class="form-control form-control-sm cf-multi-search" placeholder="Caută..." data-multi-search>
                <?php endif; ?>
                <div class="cf-multi-actions">
                    <button type="button" data-multi-all>Bifează afișate</button>
                    <button type="button" data-multi-none>Golește (<?= e(mb_strtolower($placeholder)) ?>)</button>
                </div>
                <div class="cf-multi-options">
                    <?php if ($options === []): ?>
                        <div class="cf-multi-empty">Nu există opțiuni pentru filtrul curent.</div>
                    <?php else: ?>
                        <?php foreach ($options as $value => $optionLabel): ?>
                            <label class="cf-multi-option" data-multi-option>
                                <input type="checkbox" name="<?= e($name) ?>[]" value="<?= e((string) $value) ?>" <?= isset($selectedMap[(string) $value]) ? 'checked' : '' ?>>
                                <span><?= e((string) $optionLabel) ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
/* Optiunile unui lookup ca [valoare => eticheta]. */
$optionsOf = static function (array $items, string $valueKey, string $labelKey, string $fallback = '-'): array {
    $options = [];
    foreach ($items as $item) {
        $label = trim((string) ($item[$labelKey] ?? ''));
        $options[(string) ($item[$valueKey] ?? '')] = $label !== '' ? $label : $fallback;
    }

    return $options;
};

/* Grupurile urmeaza directia sortarii pe capacitate; "Fara capacitate" e mereu ultimul. */
$capacityGroupsDescending = $filterValue('vehicle_sort') === 'capacity_desc';

$gridMode = $mode !== '' ? 'mode-' . str_replace('_', '-', $mode) : 'mode-all';
/* Pagina arata mereu raportul detaliat; scurt / detaliat se alege doar la export. */
$exportUrl = $queryFor(['action' => 'export', 'p' => null, 'view' => null]);
$exportShortUrl = $queryFor(['action' => 'export', 'p' => null, 'view' => 'scurt']);
$exportVehicleUrl = $queryFor(['action' => 'export', 'p' => null, 'view' => 'vehicule']);
$resetUrl = build_query_url(['page' => 'centralizator_facturare']);
?>

<link rel="stylesheet" href="<?= e(url('assets/css/centralizator-facturare.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/centralizator-facturare.css'))) ?>">

<div class="cf-page is-detailed">
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
                <details class="cf-export-menu" data-export-menu>
                    <summary class="cf-export"><i class="bi bi-file-earmark-excel-fill" aria-hidden="true"></i> Export Excel <i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                    <div class="cf-export-list">
                        <a href="<?= e($exportShortUrl) ?>"><i class="bi bi-list-ul" aria-hidden="true"></i><span><strong>Raport scurt</strong><small>Tabel filtrabil pe vehicule și rute, cu refacturări și TVA</small></span></a>
                        <a href="<?= e($exportUrl) ?>"><i class="bi bi-list-nested" aria-hidden="true"></i><span><strong>Raport detaliat</strong><small>Rute, vehicule, curse și refacturări</small></span></a>
                        <a href="<?= e($exportVehicleUrl) ?>" data-export-vehicle><i class="bi bi-truck" aria-hidden="true"></i><span><strong>Raport centralizat pe vehicule</strong><small>Un rând pe vehicul și rută, cu total pe lună</small></span></a>
                        <?php /* Raportul pe vehicule poate cuprinde mai multe luni: cate o sectiune pe luna, pana la luna aleasa. */ ?>
                        <label class="cf-export-range">
                            <span>Secțiuni lunare de la</span>
                            <select data-export-vehicle-from>
                                <?php foreach ((array) ($lookups['months'] ?? []) as $month): ?>
                                    <?php $monthValue = (string) ($month['value'] ?? ''); ?>
                                    <?php if ($monthValue !== '' && $monthValue <= $filterValue('month')): ?>
                                        <option value="<?= e($monthValue) ?>" <?= $monthValue === $filterValue('month') ? 'selected' : '' ?>><?= e((string) ($month['label'] ?? $monthValue)) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </details>
            </div>
        </header>

        <form class="cf-filters" method="get" data-auto-filter-form>
            <input type="hidden" name="page" value="centralizator_facturare">
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
            <div class="cf-field">
                <label for="cf_trip_state">Stare curse</label>
                <select id="cf_trip_state" name="stare_cursa">
                    <option value="" <?= $filterValue('stare_cursa') === '' ? 'selected' : '' ?>>Toate cursele</option>
                    <option value="inchise" <?= $filterValue('stare_cursa') === 'inchise' ? 'selected' : '' ?>>Doar închise</option>
                </select>
            </div>
            <?= $renderMultiSelect('cf_beneficiar', 'Beneficiar / Client', 'beneficiar_id', $optionsOf((array) ($lookups['beneficiaries'] ?? []), 'id', 'nume'), $listFilters['beneficiar_id'], 'Toți beneficiarii', true) ?>
            <?php
            $activityOptions = (array) ($lookups['activity_types'] ?? []);
            unset($activityOptions['']);
            ?>
            <?= $renderMultiSelect('cf_activity', 'Tip activitate', 'tip_activitate', $activityOptions, $listFilters['tip_activitate'], 'Toate') ?>
            <?= $renderMultiSelect('cf_cargo', 'Tip marfă', 'tip_marfa', $optionsOf((array) ($lookups['cargo'] ?? []), 'value', 'label'), $listFilters['tip_marfa'], 'Toate') ?>
            <?= $renderMultiSelect('cf_vehicle', 'Vehicul', 'vehicle_id', $optionsOf((array) ($lookups['vehicles'] ?? []), 'id', 'nr_inmatriculare'), $listFilters['vehicle_id'], 'Toate vehiculele') ?>
            <a class="cf-reset-btn" href="<?= e($resetUrl) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează</a>
            <?php /* Restul filtrelor continua mai jos; bara de context e dupa formular. */ ?>
            <?= $renderMultiSelect('cf_loading', 'Loc încărcare', 'loc_incarcare_id', $optionsOf((array) ($lookups['loading_locations'] ?? []), 'id', 'label', 'Necunoscut'), $listFilters['loc_incarcare_id'], 'Toate', false, true) ?>
            <?= $renderMultiSelect('cf_unloading', 'Zonă descărcare', 'zona_distributie_id', $optionsOf((array) ($lookups['unloading_zones'] ?? []), 'id', 'label', 'Necunoscut'), $listFilters['zona_distributie_id'], 'Toate', false, true) ?>
            <?= $renderMultiSelect('cf_route', 'Rută', 'ruta', $optionsOf((array) ($lookups['routes'] ?? []), 'value', 'label'), $listFilters['ruta'], 'Toate') ?>
            <?= $renderMultiSelect('cf_capacity', 'Capacitate transport', 'categorie_capacitate_id', $optionsOf((array) ($lookups['capacity_categories'] ?? []), 'id', 'label'), $listFilters['categorie_capacitate_id'], 'Toate capacitățile') ?>
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
            <?php require __DIR__ . '/_kpi_cards.php'; ?>

            <article class="cf-card cf-ref-card<?= $refGroups !== [] ? ' has-breakdown' : '' ?>" data-ref-card<?= $refGroups !== [] ? ' tabindex="0" aria-expanded="false"' : '' ?>>
                <div class="cf-ref-head">
                    <div class="cf-ref-icon" aria-hidden="true"><i class="bi bi-receipt-cutoff"></i></div>
                    <div>
                        <span class="cf-ref-title"><?= e((string) ($refKpi['title'] ?? 'Total refacturări')) ?></span>
                        <strong class="cf-ref-value"><?= e($fmtMoneyKpi($refKpi['value'] ?? 0)) ?></strong>
                        <div class="cf-kpi-foot"><?= $comparisonMarkup($refKpi['comparison'] ?? null) ?></div>
                    </div>
                </div>
                <?php
                /*
                 * A doua fata a cardului: la click cardul se transforma intr-un panou
                 * care pluteste peste continutul de dedesubt (grila nu se misca). Fiecare
                 * trecere se desface in bucatile cumparate la fiecare pret unitar.
                 */
                ?>
                <div class="cf-ref-details" role="group" aria-label="Detalii refacturări">
                    <div class="cf-ref-details-head">
                        <span class="cf-ref-title"><?= e((string) ($refKpi['title'] ?? 'Total refacturări')) ?></span>
                        <strong><?= e($fmtMoneyKpi($refKpi['value'] ?? 0)) ?></strong>
                        <button class="cf-ref-close" type="button" aria-label="Închide detaliile" data-ref-close>&times;</button>
                    </div>
                    <div class="cf-ref-tabs" role="tablist">
                        <button class="cf-ref-tab is-active" type="button" data-ref-tab="crossings">Treceri (poduri / porturi)</button>
                        <button class="cf-ref-tab" type="button" data-ref-tab="types">Pe tipuri de cursă</button>
                    </div>
                    <div class="cf-ref-panel is-active" data-ref-panel="crossings">
                        <div class="cf-ref-list">
                            <div class="cf-ref-list-head"><span></span><span>Locație trecere</span><span class="is-number">Nr.</span><span class="is-number">Valoare</span></div>
                            <?php if ($refGroups === []): ?>
                                <div class="cf-empty">Nu există refacturări pentru filtrul curent.</div>
                            <?php else: ?>
                                <?php foreach (array_values($refGroups) as $groupIndex => $group): ?>
                                    <?php
                                    $groupPrices = array_values((array) ($group['prices'] ?? []));
                                    usort($groupPrices, static fn (array $a, array $b): int => ((float) $b['quantity'] <=> (float) $a['quantity']) ?: ((float) $b['unit_price'] <=> (float) $a['unit_price']));
                                    $pricesId = 'cf-ref-prices-' . $groupIndex;
                                    $rowCells = '<span class="cf-ref-location"><i class="bi bi-bank" aria-hidden="true"></i>' . e((string) ($group['label'] ?? '-')) . '</span>'
                                        . '<span class="is-number">' . e($fmtSmart($group['quantity'] ?? 0, 0)) . '</span>'
                                        . '<strong class="is-number">' . e($fmtMoney($group['amount'] ?? 0)) . '</strong>';
                                    ?>
                                    <div class="cf-ref-item">
                                        <?php if ($groupPrices !== []): ?>
                                            <button class="cf-ref-row" type="button" aria-expanded="false" aria-controls="<?= e($pricesId) ?>" data-ref-row>
                                                <i class="bi bi-chevron-right cf-ref-chevron" aria-hidden="true"></i><?= $rowCells ?>
                                            </button>
                                            <ul class="cf-ref-prices" id="<?= e($pricesId) ?>" hidden>
                                                <?php foreach ($groupPrices as $price): ?>
                                                    <?php
                                                    $priceText = $fmtSmart($price['quantity'] ?? 0, 2) . ' × ' . $fmtMoney($price['unit_price'] ?? 0) . ' RON';
                                                    $priceIds = array_values((array) ($price['expense_ids'] ?? []));
                                                    /* Randul deschide exact aceste refacturari in Refacturari curse. */
                                                    $priceUrl = $priceIds !== [] && $canOpenRefacturari ? build_query_url([
                                                        'page' => 'dispecer_curse',
                                                        'action' => 'refacturari',
                                                        'data_start' => (string) ($filters['date_start'] ?? ''),
                                                        'data_end' => (string) ($filters['date_end'] ?? ''),
                                                        'ids' => implode(',', $priceIds),
                                                        'ids_label' => (string) ($group['label'] ?? '') . ' - ' . $priceText . ', ' . (string) ($filters['month_label'] ?? ''),
                                                    ]) : '';
                                                    ?>
                                                    <li>
                                                        <?php if ($priceUrl !== ''): ?>
                                                            <a class="cf-ref-price-link" href="<?= e($priceUrl) ?>" title="Deschide aceste refacturări în Refacturări curse">
                                                                <span><?= e($priceText) ?></span>
                                                                <strong><?= e($fmtMoney($price['amount'] ?? 0)) ?></strong>
                                                            </a>
                                                        <?php else: ?>
                                                            <span><?= e($priceText) ?></span>
                                                            <strong><?= e($fmtMoney($price['amount'] ?? 0)) ?></strong>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <div class="cf-ref-row"><span></span><?= $rowCells ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <div class="cf-ref-row is-total">
                                    <span></span>
                                    <span>TOTAL TRECERI</span>
                                    <span class="is-number"><?= e($fmtSmart($refacturari['quantity_total'] ?? 0, 0)) ?></span>
                                    <strong class="is-number"><?= e($fmtMoney($refacturari['total_amount'] ?? 0)) ?></strong>
                                </div>
                            <?php endif; ?>
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
                <?php
                /*
                 * La filtrare panoul arata doar tipurile care au ceva in setul filtrat
                 * (curse sau refacturari pe curse din alte luni); fara filtre raman toate.
                 */
                $activityFiltered = $activeFilters !== [] || !$isAllBeneficiaries;
                $activityVisibleRows = $activityFiltered
                    ? array_values(array_filter($activityRows, static fn (array $row): bool => (int) ($row['trips'] ?? 0) > 0 || (array) ($row['refund_leftovers'] ?? []) !== []))
                    : $activityRows;
                ?>
                <section class="cf-panel cf-activity-summary">
                    <h2>Activități pe tipuri de transport <i class="bi bi-info-circle" aria-hidden="true"></i></h2>
                    <?php $activityCols = 6; ?>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead><tr><th class="cf-expand-th"></th><th>Tip transport</th><th class="is-number">Curse</th><th class="is-number">Km</th><th class="is-number">Tone/Activ.</th><th class="is-number">% din total curse</th></tr></thead>
                            <tbody>
                            <?php if ($activityVisibleRows === []): ?>
                                <tr><td colspan="<?= e((string) $activityCols) ?>"><div class="cf-empty">Nu există curse pentru filtrul curent.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($activityVisibleRows as $row): ?>
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
                                        <td colspan="6" class="cf-trip-detail-cell"><?= $renderTypeRoutes($typeKey, $typeRoutes, (string) ($row['group_by'] ?? 'route'), $typeLeftoverRefunds, (array) ($row['price_split'] ?? [])) ?></td>
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
                    /* Fara coloana de capacitate: numarul de vehicule al grupului sta langa categorie. */
                    $detailColumns = array_values(array_filter(
                        (array) ($vehicleDetail['columns'] ?? []),
                        static fn (array $col): bool => (string) ($col['key'] ?? '') !== 'capacity'
                    ));
                    /* Grupat pe CATEGORIA de capacitate: prima coloana e categoria sau vehiculul. */
                    $detailColumns = array_map(static function (array $col): array {
                        if ((string) ($col['key'] ?? '') === 'vehicle') {
                            $col['label'] = 'Categorie / Vehicul';
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
                                    <input type="search" placeholder="Caută categorie sau vehicul" aria-label="Filtrează după categorie sau vehicul" data-vehicle-filter>
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
                                    $groupLabel = $group['category'] !== null && $group['category'] !== ''
                                        ? (string) $group['category']
                                        : 'Fără categorie';
                                    $vehicleCount = (int) $group['vehicles'];
                                    $groupSums = $sumFlatMetrics($group['rows']);
                                    ?>
                                    <tbody class="cf-cap-head">
                                    <tr class="cf-cap-group" data-cap-row>
                                        <?php foreach ($detailColumns as $col): ?>
                                            <?php $key = (string) ($col['key'] ?? ''); ?>
                                            <td class="<?= ($col['align'] ?? '') === 'right' ? 'is-number' : '' ?>">
                                                <?php if ($key === 'toggle'): ?><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="<?= e($groupId) ?>" data-cap-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                                                <?php elseif ($key === 'vehicle'): ?><span class="cf-cap-label"><?= e($groupLabel) ?></span> <span class="cf-cap-count"><?= e($fmtSmart($vehicleCount, 0)) ?> <?= $vehicleCount === 1 ? 'vehicul' : 'vehicule' ?></span>
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
                                                <?php $vehicleBillingGroups = (array) ($row['billing_groups'] ?? []); ?>
                                                <?php if ($vehicleBillingGroups !== []): ?>
                                                    <?= $renderVehicleBillingGroups($vehicleBillingGroups, $detailId, (array) ($row['detail_rows'] ?? []), (array) ($row['detail_columns'] ?? $vehicleTripColumns)) ?>
                                                <?php else: ?>
                                                    <?= $renderTripDetails((array) ($row['detail_rows'] ?? []), (array) ($row['detail_columns'] ?? $vehicleTripColumns)) ?>
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

            <section class="cf-panel cf-refact-table" id="cf_ref_table">
                <h2>Refacturări - sumar pe curse</h2>
                <?php $refCols = 10; $canOpenRefTrips = can_route('dispecer_curse'); ?>
                <div class="cf-table-wrap">
                    <table class="cf-table">
                        <thead>
                            <tr><th>Data</th><th>Tip activitate</th><th>Rută / Zonă</th><th>Vehicul</th><th>Tip marfă</th><th class="is-number">Tone</th><th class="is-number">Km</th><th class="is-number">Valoare cursă (RON)</th><th class="is-number">Refacturare (RON)</th><th>Observații</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($refRows === []): ?>
                            <tr><td colspan="<?= e((string) $refCols) ?>"><div class="cf-empty">Nu există refacturări pentru filtrul curent.</div></td></tr>
                        <?php else: ?>
                            <?php foreach ($refRows as $row): ?>
                                <?php $refTripId = (int) ($row['cursa_id'] ?? 0); ?>
                                <?php /* Randul deschide cursa in Dispecer curse, ca tabelele de curse de mai sus. */ ?>
                                <tr<?= $canOpenRefTrips && $refTripId > 0 ? ' class="cf-trip-link" data-row-href="' . e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $refTripId])) . '" tabindex="0" title="Deschide cursa în Dispecer curse"' : '' ?>>
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
                            <tr class="cf-total-row"><td colspan="7">TOTAL REFACTURĂRI</td><td class="is-number"><?= e($fmtMoney($refacturari['totals_by_table']['trip_value'] ?? 0)) ?></td><td class="is-number"><?= e($fmtMoney($refacturari['totals_by_table']['refacturare'] ?? 0)) ?></td><td></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (($refacturari['warnings'] ?? []) !== []): ?><p class="cf-warning"><?= e((string) reset($refacturari['warnings'])) ?></p><?php endif; ?>
            </section>
        </main>

        <footer class="cf-footer">
            <span>Ultima actualizare: <?= e($fmtDate($generatedAt)) ?></span>
            <div class="cf-pagination">
                <form method="get">
                    <input type="hidden" name="page" value="centralizator_facturare">
                    <input type="hidden" name="month" value="<?= e($filterValue('month')) ?>">
                    <?php foreach ($listFilters as $listName => $listValues): ?>
                        <?php foreach ($listValues as $listValue): ?>
                            <input type="hidden" name="<?= e($listName) ?>[]" value="<?= e($listValue) ?>">
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <input type="hidden" name="vehicle_sort" value="<?= e($filterValue('vehicle_sort')) ?>">
                    <?php if ($filterValue('stare_cursa') !== ''): ?>
                        <input type="hidden" name="stare_cursa" value="<?= e($filterValue('stare_cursa')) ?>">
                    <?php endif; ?>
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
    var filterForm = document.querySelector('[data-auto-filter-form]');
    if (filterForm) {
        var submitFilters = function () {
            if (typeof filterForm.requestSubmit === 'function') {
                filterForm.requestSubmit();
            } else {
                filterForm.submit();
            }
        };
        /* Luna ramane o singura alegere: se aplica imediat. */
        filterForm.querySelectorAll('select').forEach(function (field) {
            field.addEventListener('change', submitFilters);
        });

        var checkedValues = function (root) {
            return Array.prototype.map.call(root.querySelectorAll('input[type="checkbox"]:checked'), function (input) {
                return input.value;
            }).join('|');
        };
        /* Butonul arata primele valori bifate ca etichete, restul ca "+N". */
        var refreshMulti = function (root) {
            var valuesWrap = root.querySelector('[data-multi-values]');
            var placeholder = root.querySelector('[data-multi-placeholder]');
            var checked = Array.prototype.slice.call(root.querySelectorAll('input[type="checkbox"]:checked'));
            valuesWrap.innerHTML = '';
            placeholder.hidden = checked.length > 0;
            checked.slice(0, 2).forEach(function (input) {
                var chip = document.createElement('span');
                chip.className = 'cf-multi-chip';
                chip.textContent = input.closest('[data-multi-option]').textContent.trim();
                valuesWrap.appendChild(chip);
            });
            if (checked.length > 2) {
                var more = document.createElement('span');
                more.className = 'cf-multi-chip is-more';
                more.textContent = '+' + String(checked.length - 2);
                valuesWrap.appendChild(more);
            }
        };

        filterForm.querySelectorAll('[data-multi-select]').forEach(function (root) {
            var initial = checkedValues(root);
            refreshMulti(root);
            root.addEventListener('change', function () {
                refreshMulti(root);
            });

            var search = root.querySelector('[data-multi-search]');
            if (search) {
                search.addEventListener('input', function () {
                    var query = search.value.trim().toLowerCase();
                    root.querySelectorAll('[data-multi-option]').forEach(function (option) {
                        option.hidden = option.textContent.toLowerCase().indexOf(query) === -1;
                    });
                });
            }
            var setAll = function (checked) {
                root.querySelectorAll('[data-multi-option]').forEach(function (option) {
                    if (!option.hidden || !checked) {
                        option.querySelector('input').checked = checked;
                    }
                });
                refreshMulti(root);
            };
            root.querySelector('[data-multi-all]').addEventListener('click', function () { setAll(true); });
            root.querySelector('[data-multi-none]').addEventListener('click', function () { setAll(false); });

            /* Se aplica la inchiderea meniului, doar daca selectia difera de cea incarcata. */
            root.addEventListener('hidden.bs.dropdown', function () {
                if (checkedValues(root) === initial) {
                    return;
                }
                /* Rutele depind de locul de incarcare / zona: o selectie noua le reseteaza. */
                if (root.hasAttribute('data-route-parent-filter')) {
                    filterForm.querySelectorAll('[data-route-filter] input[type="checkbox"]').forEach(function (input) {
                        input.checked = false;
                    });
                }
                submitFilters();
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
        if (expanded) {
            /* Grupul restrans isi inchide si vehiculele: la redeschidere, cursele pornesc ascunse. */
            detailRow.querySelectorAll('[data-nested-toggle][aria-expanded="true"]').forEach(function (inner) {
                toggleVehicleDetail(inner);
            });
        }
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
        /* Doar randurile tabelului principal: grupurile de facturare au tabelul lor in randul expandat. */
        var totalRow = panel.querySelector('table.cf-grouped > tbody > tr.cf-total-row');
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
                var rows = body ? Array.prototype.slice.call(body.querySelectorAll(':scope > tr.cf-vehicle-parent')) : [];
                total += rows.length;

                if (!filtering) {
                    head.hidden = false;
                    rows.forEach(function (row) { row.hidden = false; });
                    toggleCapacityGroup(button, false);
                    return;
                }

                /*
                 * Cautam doar in celulele de identitate (categorie, nr. inmatriculare),
                 * nu in tot randul: altfel "20" ar corespunde si unui "1.200 km".
                 */
                var groupLabel = groupRow ? groupRow.querySelector('.cf-cap-label') : null;
                var groupHit = normalizeText(groupLabel ? groupLabel.textContent : '').indexOf(query) !== -1;
                var hits = 0;
                rows.forEach(function (row) {
                    var plate = row.querySelector('.cf-vehicle-cell');
                    var identity = normalizeText(plate ? plate.textContent : '');
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

    /* Randurile de cursa: click deschide cursa, Ctrl / click mijlociu intr-un tab nou. */
    var openTripRow = function (row, newTab) {
        var href = row.getAttribute('data-row-href');
        if (!href) {
            return;
        }
        if (newTab) {
            window.open(href, '_blank', 'noopener');
        } else {
            window.location.href = href;
        }
    };
    document.addEventListener('click', function (event) {
        var row = event.target.closest('tr[data-row-href]');
        if (!row || event.target.closest('a, button, input, select, label')) {
            return;
        }
        /* Textul selectat cu mouse-ul nu este un click de deschidere. */
        if (window.getSelection && String(window.getSelection()).length > 0) {
            return;
        }
        openTripRow(row, event.ctrlKey || event.metaKey);
    });
    document.addEventListener('auxclick', function (event) {
        var row = event.button === 1 ? event.target.closest('tr[data-row-href]') : null;
        if (row && !event.target.closest('a, button')) {
            event.preventDefault();
            openTripRow(row, true);
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.matches && event.target.matches('tr[data-row-href]')) {
            openTripRow(event.target, event.ctrlKey || event.metaKey);
        }
    });

    /* Meniul Export Excel (scurt / detaliat) se inchide la click in afara lui sau cu Escape. */
    var exportMenu = document.querySelector('[data-export-menu]');
    if (exportMenu) {
        /* Luna de inceput a raportului pe vehicule intra in link ca month_from. */
        var vehicleLink = exportMenu.querySelector('[data-export-vehicle]');
        var vehicleFrom = exportMenu.querySelector('[data-export-vehicle-from]');
        if (vehicleLink && vehicleFrom) {
            vehicleFrom.addEventListener('change', function () {
                var url = new URL(vehicleLink.href, window.location.href);
                url.searchParams.set('month_from', vehicleFrom.value);
                vehicleLink.href = url.toString();
            });
        }
        document.addEventListener('click', function (event) {
            if (exportMenu.open && (!exportMenu.contains(event.target) || event.target.closest('.cf-export-list a'))) {
                exportMenu.open = false;
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && exportMenu.open) {
                exportMenu.open = false;
            }
        });
    }

    var card = document.querySelector('[data-ref-card]');
    if (!card) {
        return;
    }
    /* Panoul se deschide doar la click (fara previzualizare la hover). */
    var setRefOpen = function (open) {
        if (!card.classList.contains('has-breakdown')) {
            return;
        }
        card.classList.toggle('is-open', open);
        card.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    card.addEventListener('click', function (event) {
        if (!card.classList.contains('is-open') && !event.target.closest('[data-ref-close]')) {
            setRefOpen(true);
        }
    });
    card.addEventListener('keydown', function (event) {
        if (event.target === card && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            setRefOpen(!card.classList.contains('is-open'));
        }
    });
    card.querySelectorAll('[data-ref-close]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            setRefOpen(false);
            card.focus();
        });
    });
    document.addEventListener('click', function (event) {
        if (card.classList.contains('is-open') && !card.contains(event.target)) {
            setRefOpen(false);
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && card.classList.contains('is-open')) {
            setRefOpen(false);
            card.focus();
        }
    });
    /* Fiecare trecere se desface in bucatile cumparate la fiecare pret unitar. */
    card.querySelectorAll('[data-ref-row]').forEach(function (button) {
        button.addEventListener('click', function () {
            var list = document.getElementById(button.getAttribute('aria-controls'));
            if (!list) {
                return;
            }
            var expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            list.hidden = expanded;
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
