<?php
$fuelData = is_array($fuelData ?? null) ? $fuelData : [];
$kpis = $fuelData['kpis'] ?? [];
$dailyChart = $fuelData['daily_chart'] ?? ['points' => [], 'average' => 0];
$transportChart = $fuelData['transport_chart'] ?? ['items' => [], 'total' => 0];
$normative = $fuelData['normative'] ?? [];
$latestFillups = $fuelData['latest_fillups'] ?? [];
$allFillups = $fuelData['all_fillups'] ?? [];
$unassociatedFillups = $fuelData['unassociated_fillups'] ?? [];
$tripConsumption = $fuelData['trip_consumption'] ?? [];
$transportConsumption = $fuelData['transport_consumption'] ?? [];
$tripOptions = $fuelData['trip_options'] ?? [];
$syncLogs = $fuelData['sync_logs'] ?? [];
$lastSync = $fuelData['last_sync'] ?? null;
$vehicleOptions = $fuelData['vehicle_options'] ?? [];
$excludedVehicles = is_array($excludedVehicles ?? null) ? $excludedVehicles : [];
// Formularul "Vehicule sincronizate": optiunile din selector + numerele deja excluse,
// grupate pe categoria de capacitate.
$fuelSyncKey = static fn (string $value): string => str_replace(' ', '', strtoupper(trim($value)));
$fuelSyncGroups = [];
$fuelSyncSeen = [];
foreach ($vehicleOptions as $option) {
    $registration = trim((string) ($option['vehicle_registration'] ?? ''));
    if ($registration === '' || isset($fuelSyncSeen[$fuelSyncKey($registration)])) {
        continue;
    }
    $fuelSyncSeen[$fuelSyncKey($registration)] = true;
    $group = trim((string) ($option['categorie_capacitate'] ?? '')) ?: 'Fara categorie';
    $fuelSyncGroups[$group][] = [
        'registration' => $registration,
        'label' => trim((string) ($option['marca'] ?? '') . ' ' . (string) ($option['model'] ?? '')),
        'excluded' => isset($excludedVehicles[$fuelSyncKey($registration)]),
    ];
}
foreach ($excludedVehicles as $key => $registration) {
    if (!isset($fuelSyncSeen[$key])) {
        $fuelSyncGroups['Fara categorie'][] = ['registration' => $registration, 'label' => '', 'excluded' => true];
    }
}
ksort($fuelSyncGroups);
if (isset($fuelSyncGroups['Fara categorie'])) {
    $noCategory = $fuelSyncGroups['Fara categorie'];
    unset($fuelSyncGroups['Fara categorie']);
    $fuelSyncGroups['Fara categorie'] = $noCategory;
}
$fuelSyncTotal = array_sum(array_map('count', $fuelSyncGroups));
$fuelSyncExcludedCount = count($excludedVehicles);
$comparison = is_array($fuelData['comparison'] ?? null) ? $fuelData['comparison'] : null;
$vehicleComparison = is_array($fuelData['vehicle_comparison'] ?? null) ? $fuelData['vehicle_comparison'] : [];
$vehicleDailyCharts = is_array($fuelData['vehicle_daily_charts'] ?? null) ? $fuelData['vehicle_daily_charts'] : [];
$priceEvolution = is_array($fuelData['price_evolution'] ?? null) ? $fuelData['price_evolution'] : [];
$selectedVehicles = is_array($filters['vehicles'] ?? null) ? array_values(array_filter(array_map('strval', $filters['vehicles']), static fn (string $v): bool => trim($v) !== '')) : [];
$seriesPalette = ['#1d6cff', '#f59e0b', '#22c55e', '#8b5cf6', '#ef4444', '#0891b2'];
$compare = is_array($compare ?? null) ? $compare : [
    'mode' => 'periods',
    'label_a' => '',
    'label_b' => '',
    'vehicle_a' => '',
    'vehicle_b' => '',
    'period_a' => '',
    'period_b' => '',
    'subtitle' => '',
];
$currentUrl = $_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'carburanti']);

// ---------------------------------------------------------------------
// Eticheta afisata in campul unic de perioada: "Iulie 2026" pentru o luna
// intreaga, altfel "01.07.2026 – 15.07.2026".
// ---------------------------------------------------------------------
$monthNamesRo = [1 => 'Ianuarie', 2 => 'Februarie', 3 => 'Martie', 4 => 'Aprilie', 5 => 'Mai', 6 => 'Iunie',
    7 => 'Iulie', 8 => 'August', 9 => 'Septembrie', 10 => 'Octombrie', 11 => 'Noiembrie', 12 => 'Decembrie'];
$periodLabel = (string) ($filters['period'] ?? '');
try {
    $periodFromDate = new DateTimeImmutable((string) ($filters['date_from'] ?? ''));
    $periodToDate = new DateTimeImmutable((string) ($filters['date_to'] ?? ''));
    if ($periodFromDate->format('j') === '1'
        && $periodToDate->format('Y-m-d') === $periodFromDate->modify('last day of this month')->format('Y-m-d')) {
        $periodLabel = $monthNamesRo[(int) $periodFromDate->format('n')] . ' ' . $periodFromDate->format('Y');
    } else {
        $periodLabel = $periodFromDate->format('d.m.Y') . ' – ' . $periodToDate->format('d.m.Y');
    }
} catch (Throwable) {
    // pastreaza eticheta implicita din filtre
}

$formatLiters = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' L';
$formatCurrency = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' lei';
$formatPercent = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' %';
$formatKm = static fn (mixed $value): string => (float) $value > 0 ? format_number_ro((float) $value, 0) . ' km' : '-';
$formatDateTime = static fn (?string $value): string => format_datetime_ro($value);
$normKmSource = match ((string) ($normative['km_source'] ?? '')) {
    'alimentari' => 'alimentări',
    'dispecer' => 'dispecer',
    default => '',
};

// ---------------------------------------------------------------------
// Starea T0 (mecanismul FULL / T0)
// ---------------------------------------------------------------------
$canManageFull = (bool) ($canManageFull ?? false);
$t0Mode = (string) ($normative['t0_mode'] ?? 'missing');
$t0Vehicle = (string) ($normative['vehicle'] ?? '');
$t0MonthStart = (string) ($normative['month_start'] ?? '');
$t0Candidates = is_array($normative['candidates'] ?? null) ? $normative['candidates'] : [];
$t0WindowLabel = '';
if (($normative['t0_window_start'] ?? '') !== '' && ($normative['t0_window_end'] ?? '') !== '') {
    $t0WindowLabel = (new DateTimeImmutable((string) $normative['t0_window_start']))->format('d.m.Y')
        . ' – '
        . (new DateTimeImmutable((string) $normative['t0_window_end']))->format('d.m.Y');
}
$t0MonthLabel = $t0MonthStart !== '' ? (new DateTimeImmutable($t0MonthStart))->format('m.Y') : '';

/**
 * Blocul de stare T0, reutilizat in tab-ul General si in tab-ul Consum normat.
 */
$renderT0Status = static function () use (
    $normative,
    $t0Mode,
    $t0Vehicle,
    $t0MonthStart,
    $t0MonthLabel,
    $t0WindowLabel,
    $canManageFull,
    $currentUrl,
    $formatDateTime,
    $formatLiters,
    $formatKm
): void {
    $startFull = is_array($normative['start_full'] ?? null) ? $normative['start_full'] : null;
    $pillClass = match ($t0Mode) {
        'manual' => 'fuel-pill-manual',
        'auto' => 'fuel-pill-auto',
        default => 'fuel-pill-warning',
    };
    $pillLabel = match ($t0Mode) {
        'manual' => 'Setat manual',
        'auto' => 'Determinat automat',
        'no_vehicle' => 'Fără vehicul',
        default => 'Lipsă',
    };
    ?>
    <div class="fuel-t0-block <?= $t0Mode === 'missing' ? 'is-missing' : '' ?>">
        <div class="fuel-t0-head">
            <span class="fuel-t0-label">T0<?= $t0MonthLabel !== '' ? ' · ' . e($t0MonthLabel) : '' ?></span>
            <span class="fuel-pill <?= e($pillClass) ?>"><?= e($pillLabel) ?></span>
        </div>
        <?php if ($startFull !== null): ?>
            <div class="fuel-t0-value">
                <strong><?= e($formatDateTime((string) ($startFull['fillup_datetime'] ?? ''))) ?></strong>
                <span class="fuel-pill fuel-pill-full">FULL</span>
            </div>
            <div class="fuel-t0-meta">
                <?= e($formatLiters((float) ($startFull['quantity_liters'] ?? 0))) ?>
                · <?= e($formatKm((float) ($startFull['odometer_km'] ?? 0))) ?>
                · <?= e((string) ($startFull['vehicle_registration'] ?? '')) ?>
            </div>
            <?php if ($t0Mode === 'manual' && ($normative['t0_manual_note'] ?? null) !== null): ?>
                <div class="fuel-t0-meta">Notă: <?= e((string) $normative['t0_manual_note']) ?></div>
            <?php endif; ?>
            <?php if ($t0Mode === 'auto'): ?>
                <div class="fuel-t0-meta">Fereastră ±4 zile: <?= e($t0WindowLabel) ?><?php
                    $count = (int) ($normative['t0_candidate_count'] ?? 0);
                    echo $count > 1 ? ' · ' . e((string) $count) . ' FULL-uri eligibile, ales cel mai apropiat de 1 ale lunii' : '';
                ?></div>
            <?php endif; ?>
        <?php elseif ($t0Mode === 'no_vehicle'): ?>
            <div class="fuel-t0-value"><strong>Nu există vehicul pentru calcul</strong></div>
        <?php else: ?>
            <div class="fuel-t0-value"><strong>T0 lipsă</strong></div>
            <div class="fuel-t0-meta">Nu există FULL în fereastra ±4 zile (<?= e($t0WindowLabel) ?>).</div>
        <?php endif; ?>

        <?php if ($canManageFull && $t0Vehicle !== '' && $t0MonthStart !== ''): ?>
            <div class="fuel-t0-actions">
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#fuelT0Modal">
                    <i class="bi bi-pin-map" aria-hidden="true"></i> Setează T0 manual
                </button>
                <?php if ($t0Mode === 'manual'): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'clear_t0'])) ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="vehicle" value="<?= e($t0Vehicle) ?>">
                        <input type="hidden" name="month_start" value="<?= e($t0MonthStart) ?>">
                        <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                onclick="return confirm('Elimini T0 manual si revii la selectia automata?');">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Revino la automat
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
};
$consumptionKmSource = (string) ($kpis['consumption_km_source'] ?? '');
$consumptionDetail = '';
if ((float) ($kpis['consumption_liters'] ?? 0) > 0 && (float) ($kpis['linked_km'] ?? 0) > 0) {
    $consumptionDetail = $formatLiters((float) ($kpis['consumption_liters'] ?? 0))
        . ' / '
        . $formatKm((float) ($kpis['linked_km'] ?? 0));
    if ($consumptionKmSource === 'alimentari') {
        $consumptionDetail .= ' din alimentari';
    } elseif ($consumptionKmSource === 'dispecer') {
        $consumptionDetail .= ' din dispecer';
    }
}
$fuelTypeLabel = static function (string $type): string {
    return match ($type) {
        'motorina' => 'Motorina',
        'adblue' => 'AdBlue',
        default => $type !== '' ? $type : '-',
    };
};
$transportLabel = static function (?string $type) use ($transportLabels): string {
    $type = (string) $type;
    if (isset($transportLabels[$type])) {
        return (string) $transportLabels[$type];
    }

    return match ($type) {
        'primar', 'primar_tona' => 'Primar',
        'distributie' => 'Distributie',
        'compresor' => 'Compresor',
        'primar_distributie' => 'Primar + Distributie',
        default => $type !== '' ? $type : '-',
    };
};
$changeBadge = static function (?float $value): string {
    if ($value === null) {
        return '<span class="fuel-kpi-change is-muted"><i class="bi bi-dash-lg" aria-hidden="true"></i> fara comparatie</span>';
    }

    $isGood = $value <= 0;
    $icon = $isGood ? 'bi-arrow-down-short' : 'bi-arrow-up-short';
    $class = $isGood ? 'is-good' : 'is-bad';

    return '<span class="fuel-kpi-change ' . $class . '"><i class="bi ' . $icon . '" aria-hidden="true"></i> '
        . e(format_number_ro(abs($value), 1)) . '% fata de perioada trecuta</span>';
};
$associationLabel = static function (array $row): string {
    $tripId = (int) ($row['trip_id'] ?? 0);
    if ($tripId <= 0) {
        return '<span class="fuel-pill fuel-pill-warning">Alimentare neasociată</span>';
    }

    $matchType = (string) ($row['match_type'] ?? 'automatic');
    $badgeClass = $matchType === 'manual' ? 'fuel-pill-manual' : 'fuel-pill-auto';

    return '<span class="fuel-pill ' . $badgeClass . '">Cursa #' . e((string) $tripId) . '</span>';
};
$renderFillupRows = static function (array $rows, bool $compact = false) use ($formatDateTime, $formatLiters, $formatKm, $formatCurrency, $fuelTypeLabel, $associationLabel, $currentUrl, $canManageFull): void {
    if ($rows === []) {
        $colspan = $compact ? 6 : 11;
        echo '<tr><td colspan="' . $colspan . '" class="text-center text-muted py-4">Nu exista alimentari pentru filtrele selectate.</td></tr>';
        return;
    }

    foreach ($rows as $row) {
        $tripId = (int) ($row['trip_id'] ?? 0);
        $isManualRow = (string) ($row['source_type'] ?? 'api') === 'manual';
        ?>
        <tr>
            <td>
                <?= e($formatDateTime((string) ($row['fillup_datetime'] ?? ''))) ?>
                <?php if ($isManualRow): ?>
                    <?php
                    $manualPaymentLabel = match ((string) ($row['payment_method'] ?? '')) {
                        'card_personal' => 'card pers.',
                        'altul' => 'manuală',
                        default => 'numerar',
                    };
                    ?>
                    <span class="fuel-pill fuel-pill-cash" title="Alimentare introdusă manual, plătită în afara cardului CardOil"><?= e($manualPaymentLabel) ?></span>
                <?php endif; ?>
            </td>
            <td class="fw-semibold"><?= e((string) ($row['vehicle_registration'] ?? '-')) ?></td>
            <?php if (!$compact): ?>
                <td><?= e(trim((string) ($row['driver_name'] ?? '')) !== '' ? (string) $row['driver_name'] : '-') ?></td>
                <td><?= e((string) ($row['station_name'] ?? '-')) ?></td>
            <?php endif; ?>
            <td><?= e($fuelTypeLabel((string) ($row['fuel_type'] ?? ''))) ?></td>
            <td><?= e($formatLiters((float) ($row['quantity_liters'] ?? 0))) ?></td>
            <?php if ($compact): ?>
                <td><?= e($formatCurrency((float) ($row['total_value'] ?? 0))) ?></td>
            <?php endif; ?>
            <?php if (!$compact): ?>
                <td>
                    <?php
                    $odoValue = (float) ($row['odometer_km'] ?? 0);
                    $odoPrevious = (float) ($row['previous_odometer_km'] ?? 0);
                    $odoIsManual = ($row['odometer_km_manual'] ?? null) !== null;
                    $odoInconsistent = !$odoIsManual
                        && (string) ($row['fuel_type'] ?? '') === 'motorina'
                        && $odoValue > 0 && $odoPrevious > 0 && $odoValue < $odoPrevious;
                    ?>
                    <?= e($formatKm($odoValue)) ?>
                    <?php if ($odoIsManual): ?>
                        <span class="fuel-pill fuel-pill-manual" title="Odometru corectat manual, protejat la sincronizarea CardOil">corectat</span>
                    <?php elseif ($odoInconsistent): ?>
                        <i class="bi bi-exclamation-triangle-fill text-warning"
                           title="Odometru mai mic decât alimentarea precedentă (<?= e(format_number_ro($odoPrevious, 0)) ?> km) — probabil km tastați greșit la pompă"
                           aria-hidden="true"></i>
                    <?php endif; ?>
                </td>
                <td><?= e($formatCurrency((float) ($row['total_value'] ?? 0))) ?></td>
                <td>
                    <span class="fuel-pill <?= !empty($row['is_full']) ? 'fuel-pill-full' : 'fuel-pill-partial' ?>">
                        <?= !empty($row['is_full']) ? 'Full' : 'Partial' ?>
                    </span>
                    <?php if (($row['is_full_manual'] ?? null) !== null): ?>
                        <span class="fuel-pill fuel-pill-manual" title="Decizie manuală, protejată la sincronizarea CardOil">manual</span>
                    <?php endif; ?>
                </td>
                <td><?= $associationLabel($row) ?></td>
            <?php endif; ?>
            <td>
                <div class="fuel-row-actions">
                    <?php if (!$compact): ?>
                        <form method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'set_full'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                            <input type="hidden" name="fillup_id" value="<?= e((string) ((int) ($row['id'] ?? 0))) ?>">
                            <input type="hidden" name="is_full" value="<?= !empty($row['is_full']) ? '0' : '1' ?>">
                            <button class="fuel-icon-btn" type="submit" title="<?= !empty($row['is_full']) ? 'Marcheaza Partial' : 'Marcheaza Full' ?>">
                                <i class="bi <?= !empty($row['is_full']) ? 'bi-circle' : 'bi-check2-circle' ?>" aria-hidden="true"></i>
                            </button>
                        </form>
                        <button
                            type="button"
                            class="fuel-icon-btn"
                            data-fuel-odo-open
                            data-fillup-id="<?= e((string) ((int) ($row['id'] ?? 0))) ?>"
                            data-fillup-label="<?= e($formatDateTime((string) ($row['fillup_datetime'] ?? '')) . ' · ' . (string) ($row['vehicle_registration'] ?? '-') . ' · ' . $formatLiters((float) ($row['quantity_liters'] ?? 0))) ?>"
                            data-odo-value="<?= e((string) ((int) ($row['odometer_km'] ?? 0))) ?>"
                            data-odo-manual="<?= ($row['odometer_km_manual'] ?? null) !== null ? '1' : '0' ?>"
                            title="Corectează odometrul"
                        >
                            <i class="bi bi-speedometer2" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                    <?php if ($tripId > 0): ?>
                        <a class="fuel-icon-btn" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $tripId])) ?>" title="Deschide cursa">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </a>
                    <?php else: ?>
                        <button
                            type="button"
                            class="fuel-icon-btn"
                            data-fuel-link-open
                            data-fillup-id="<?= e((string) ((int) ($row['id'] ?? 0))) ?>"
                            data-fillup-label="<?= e($formatDateTime((string) ($row['fillup_datetime'] ?? '')) . ' - ' . (string) ($row['vehicle_registration'] ?? '-')) ?>"
                            title="Asociaza manual"
                        >
                            <i class="bi bi-link-45deg" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                    <?php if ($isManualRow && trim((string) ($row['receipt_path'] ?? '')) !== ''): ?>
                        <a
                            class="fuel-icon-btn"
                            href="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'receipt', 'fillup_id' => (int) ($row['id'] ?? 0)])) ?>"
                            target="_blank"
                            rel="noopener"
                            title="Deschide bonul fiscal"
                        >
                            <i class="bi bi-receipt" aria-hidden="true"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($isManualRow && $canManageFull): ?>
                        <button
                            type="button"
                            class="fuel-icon-btn fuel-icon-btn-danger"
                            data-fuel-delete-open
                            data-fillup-id="<?= e((string) ((int) ($row['id'] ?? 0))) ?>"
                            data-fillup-label="<?= e($formatDateTime((string) ($row['fillup_datetime'] ?? '')) . ' · ' . (string) ($row['vehicle_registration'] ?? '-') . ' · ' . $formatLiters((float) ($row['quantity_liters'] ?? 0))) ?>"
                            title="Șterge alimentarea manuală"
                        >
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }
};
$renderPriceChart = static function (array $series, string $lineColor) use ($filters): string {
    static $chartSeq = 0;

    $points = is_array($series['points'] ?? null) ? array_values($series['points']) : [];
    if ($points === []) {
        return '<div class="fuel-empty-chart">Nu există prețuri CardOil în perioada selectată.</div>';
    }

    $chartSeq++;
    $gradientId = 'fuelPriceFill' . $chartSeq;

    $values = array_map(static fn (array $point): float => (float) ($point['value'] ?? 0), $points);
    $minValue = min($values);
    $maxValue = max($values);
    $minIndex = (int) array_search($minValue, $values, true);
    $maxIndex = (int) array_search($maxValue, $values, true);
    $span = $maxValue - $minValue;
    // Scara stransa: variatiile de cativa bani trebuie sa fie vizibile.
    if ($span < 0.2) {
        $center = ($minValue + $maxValue) / 2;
        $minValue = $center - 0.1;
        $maxValue = $center + 0.1;
        $span = 0.2;
    }
    $yMin = max(0.0, $minValue - ($span * 0.15));
    $yMax = $maxValue + ($span * 0.15);
    $ySpan = max(0.0001, $yMax - $yMin);

    $width = 760;
    $height = 220;
    $left = 56;
    $right = 16;
    $top = 14;
    $bottom = 30;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $baseY = $top + $plotHeight;

    $startTs = strtotime((string) ($filters['date_from'] ?? '') . ' 00:00:00') ?: 0;
    $endTs = strtotime((string) ($filters['date_to'] ?? '') . ' 23:59:59') ?: ($startTs + 86400);
    $tsSpan = max(1, $endTs - $startTs);

    $xFor = static function (string $date) use ($startTs, $tsSpan, $left, $plotWidth): float {
        $ts = strtotime($date . ' 12:00:00') ?: $startTs;
        $ratio = min(1.0, max(0.0, ($ts - $startTs) / $tsSpan));
        return $left + ($ratio * $plotWidth);
    };
    $yFor = static function (float $value) use ($yMin, $ySpan, $top, $plotHeight): float {
        return $top + ($plotHeight - ((($value - $yMin) / $ySpan) * $plotHeight));
    };

    $grid = '';
    for ($i = 0; $i <= 4; $i++) {
        $value = $yMin + (($yMax - $yMin) / 4) * $i;
        $y = round($yFor($value), 2);
        $grid .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) . '" y2="' . $y . '" class="fuel-chart-gridline"/>';
        $grid .= '<text x="8" y="' . ($y + 4) . '" class="fuel-chart-axis">' . e(format_number_ro($value, 2)) . '</text>';
    }

    $averageLine = '';
    $avg = (float) ($series['avg'] ?? 0);
    if ($avg > 0 && $avg >= $yMin && $avg <= $yMax) {
        $avgY = round($yFor($avg), 2);
        $averageLine = '<line x1="' . $left . '" y1="' . $avgY . '" x2="' . ($width - $right) . '" y2="' . $avgY . '" class="fuel-chart-average"/>'
            . '<text x="' . ($width - $right) . '" y="' . ($avgY - 5) . '" text-anchor="end" class="fuel-chart-axis fuel-price-avg-tag">medie ' . e(format_number_ro($avg, 3)) . '</text>';
    }

    // Datele punctelor ajung si in JS: tooltip, crosshair si navigare cu tastatura.
    $svgPoints = [];
    $payload = [];
    $previousValue = null;
    foreach ($points as $index => $point) {
        $date = (string) ($point['date'] ?? '');
        $value = (float) ($point['value'] ?? 0);
        $liters = (float) ($point['liters'] ?? 0);
        $x = round($xFor($date), 2);
        $y = round($yFor($value), 2);
        $svgPoints[] = $x . ',' . $y;

        $deltaText = '';
        if ($previousValue !== null && $previousValue > 0) {
            $delta = $value - $previousValue;
            $sign = $delta > 0 ? '+' : ($delta < 0 ? '-' : '±');
            $deltaText = $sign . format_number_ro(abs($delta), 3) . ' lei ('
                . $sign . format_number_ro(abs($delta) / $previousValue * 100, 2) . '%) față de ziua anterioară';
        }
        $vsAvgText = '';
        if ($avg > 0) {
            $diff = $value - $avg;
            $sign = $diff > 0 ? '+' : ($diff < 0 ? '-' : '±');
            $vsAvgText = $sign . format_number_ro(abs($diff), 3) . ' lei față de medie';
        }

        $payload[] = [
            'x' => $x,
            'y' => $y,
            'label' => (string) ($point['label'] ?? ''),
            'full' => $date !== '' ? (new DateTimeImmutable($date))->format('d.m.Y') : '',
            'value' => format_number_ro($value, 4) . ' lei/L',
            'liters' => format_number_ro($liters, 0) . ' L alimentați',
            'delta' => $deltaText,
            'vsAvg' => $vsAvgText,
            'kind' => $index === $minIndex ? 'min' : ($index === $maxIndex ? 'max' : ''),
        ];
        $previousValue = $value;
    }

    $area = '';
    $line = '';
    if (count($svgPoints) > 1) {
        $firstX = explode(',', $svgPoints[0])[0];
        $lastX = explode(',', $svgPoints[count($svgPoints) - 1])[0];
        $area = '<polygon class="fuel-price-area" fill="url(#' . $gradientId . ')" points="'
            . e($firstX . ',' . $baseY . ' ' . implode(' ', $svgPoints) . ' ' . $lastX . ',' . $baseY)
            . '"/>';
        $line = '<polyline points="' . e(implode(' ', $svgPoints)) . '" class="fuel-chart-series" style="stroke: ' . e($lineColor) . ';"/>';
    }

    $dots = '';
    foreach ($payload as $point) {
        $dots .= '<circle cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="3.5" class="fuel-price-dot" style="fill: ' . e($lineColor) . ';"/>';
    }

    // Marcajele de minim si maxim raman vizibile si fara hover.
    $extremes = '';
    if ($minIndex !== $maxIndex) {
        foreach (['min' => $minIndex, 'max' => $maxIndex] as $kind => $index) {
            if (!isset($payload[$index])) {
                continue;
            }
            $point = $payload[$index];
            $labelX = min(max((float) $point['x'], $left + 30), $width - $right - 30);
            $labelY = $kind === 'max' ? max($top + 11, (float) $point['y'] - 13) : min($baseY - 4, (float) $point['y'] + 19);
            $extremes .= '<circle cx="' . $point['x'] . '" cy="' . $point['y'] . '" r="6" class="fuel-price-extreme is-' . $kind . '" style="stroke: ' . e($lineColor) . ';"/>'
                . '<text x="' . round($labelX, 2) . '" y="' . round($labelY, 2) . '" text-anchor="middle" class="fuel-price-extreme-label is-' . $kind . '">'
                . e(($kind === 'max' ? 'max ' : 'min ') . format_number_ro((float) $values[$index], 3) . ' · ' . $point['label']) . '</text>';
        }
    }

    $labels = '';
    $count = count($points);
    $labelEvery = max(1, (int) ceil($count / 6));
    foreach ($points as $index => $point) {
        if ($index % $labelEvery !== 0 && $index !== $count - 1) {
            continue;
        }
        $x = round($xFor((string) ($point['date'] ?? '')), 2);
        $labels .= '<text x="' . $x . '" y="' . ($height - 8) . '" text-anchor="middle" class="fuel-chart-axis">' . e((string) ($point['label'] ?? '')) . '</text>';
    }

    $hover = '<g class="fuel-price-hover" aria-hidden="true">'
        . '<line class="fuel-price-crosshair" x1="0" y1="' . $top . '" x2="0" y2="' . $baseY . '"/>'
        . '<circle class="fuel-price-focus" cx="0" cy="0" r="6" style="stroke: ' . e($lineColor) . ';"/>'
        . '</g>';

    $hitbox = '<rect class="fuel-price-hitbox" x="' . $left . '" y="' . $top . '" width="' . $plotWidth . '" height="' . $plotHeight . '"/>';

    $ariaLabel = 'Evoluție preț carburant. Minim ' . format_number_ro((float) $values[$minIndex], 3) . ' lei/L pe '
        . (string) ($points[$minIndex]['label'] ?? '') . ', maxim ' . format_number_ro((float) $values[$maxIndex], 3) . ' lei/L pe '
        . (string) ($points[$maxIndex]['label'] ?? '') . '.';

    $svg = '<svg class="fuel-price-chart" viewBox="0 0 ' . $width . ' ' . $height . '" aria-hidden="true" focusable="false">'
        . '<defs><linearGradient id="' . $gradientId . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="' . e($lineColor) . '" stop-opacity="0.26"/>'
        . '<stop offset="100%" stop-color="' . e($lineColor) . '" stop-opacity="0"/>'
        . '</linearGradient></defs>'
        . $grid
        . $averageLine
        . $area
        . $line
        . $dots
        . $extremes
        . $labels
        . $hover
        . $hitbox
        . '</svg>';

    $json = json_encode([
        'width' => $width,
        'left' => $left,
        'right' => $right,
        'points' => $payload,
        'minIndex' => $minIndex,
        'maxIndex' => $maxIndex,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return '<div class="fuel-price-chart-wrap" data-fuel-price-chart tabindex="0" role="img" aria-label="' . e($ariaLabel) . '">'
        . $svg
        . '<div class="fuel-price-tooltip" role="status" aria-live="polite" hidden></div>'
        . '<script type="application/json" data-fuel-price-data>' . $json . '</script>'
        . '</div>';
};
$renderLineChart = static function (array $chart): string {
    $points = $chart['points'] ?? [];
    if (!is_array($points) || $points === []) {
        return '<div class="fuel-empty-chart">Nu exista date pentru grafic.</div>';
    }

    $values = array_map(static fn (array $point): float => (float) ($point['value'] ?? 0), $points);
    $average = (float) ($chart['average'] ?? 0);
    $maxValue = max(50.0, $average, ...$values);
    $maxValue = ceil($maxValue / 10) * 10;
    $width = 760;
    $height = 270;
    $left = 44;
    $right = 16;
    $top = 18;
    $bottom = 38;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $count = count($points);
    $step = $count > 1 ? $plotWidth / ($count - 1) : $plotWidth;
    $svgPoints = [];

    foreach ($points as $index => $point) {
        $x = $left + ($step * $index);
        $value = max(0.0, (float) ($point['value'] ?? 0));
        $y = $top + ($plotHeight - (($value / $maxValue) * $plotHeight));
        $svgPoints[] = round($x, 2) . ',' . round($y, 2);
    }

    $grid = '';
    for ($i = 0; $i <= 5; $i++) {
        $value = ($maxValue / 5) * $i;
        $y = $top + ($plotHeight - (($value / $maxValue) * $plotHeight));
        $grid .= '<line x1="' . $left . '" y1="' . round($y, 2) . '" x2="' . ($width - $right) . '" y2="' . round($y, 2) . '" class="fuel-chart-gridline"/>';
        $grid .= '<text x="8" y="' . (round($y, 2) + 4) . '" class="fuel-chart-axis">' . e((string) round($value)) . '</text>';
    }

    $labels = '';
    $labelEvery = max(1, (int) ceil($count / 6));
    foreach ($points as $index => $point) {
        if ($index % $labelEvery !== 0 && $index !== $count - 1) {
            continue;
        }

        $x = $left + ($step * $index);
        $labels .= '<text x="' . round($x, 2) . '" y="' . ($height - 8) . '" text-anchor="middle" class="fuel-chart-axis">' . e((string) ($point['label'] ?? '')) . '</text>';
    }

    $averageLine = '';
    if ($average > 0) {
        $avgY = $top + ($plotHeight - (($average / $maxValue) * $plotHeight));
        $averageLine = '<line x1="' . $left . '" y1="' . round($avgY, 2) . '" x2="' . ($width - $right) . '" y2="' . round($avgY, 2) . '" class="fuel-chart-average"/>';
    }

    return '<svg class="fuel-line-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Consum mediu motorina">'
        . $grid
        . $averageLine
        . '<polyline points="' . e(implode(' ', $svgPoints)) . '" class="fuel-chart-line"/>'
        . $labels
        . '</svg>';
};
$fleetAverageL100 = static function (array $rows): float {
    $liters = 0.0;
    $km = 0.0;
    foreach ($rows as $row) {
        $rowKm = (float) ($row['km'] ?? 0);
        $rowConsum = (float) ($row['consum_motorina'] ?? 0);
        if ($rowKm <= 0 || $rowConsum <= 0) {
            continue;
        }
        $liters += ($rowConsum * $rowKm) / 100;
        $km += $rowKm;
    }

    return $km > 0 ? round(($liters / $km) * 100, 2) : 0.0;
};
$compareBadge = static function (?float $percent, string $better): string {
    if ($percent === null) {
        return '<span class="fuel-kpi-change is-muted"><i class="bi bi-dash-lg" aria-hidden="true"></i> fără referință</span>';
    }

    $icon = $percent < 0 ? 'bi-arrow-down-short' : ($percent > 0 ? 'bi-arrow-up-short' : 'bi-dash-lg');
    if ($better === 'neutral' || $percent === 0.0) {
        $class = 'is-muted';
    } elseif ($better === 'lower') {
        $class = $percent < 0 ? 'is-good' : 'is-bad';
    } else {
        $class = $percent > 0 ? 'is-good' : 'is-bad';
    }

    return '<span class="fuel-kpi-change ' . $class . '"><i class="bi ' . $icon . '" aria-hidden="true"></i> '
        . e(format_number_ro(abs($percent), 1)) . '%</span>';
};
$renderCompareChart = static function (array $chartA, array $chartB, bool $useDayIndex): string {
    $pointsA = is_array($chartA['points'] ?? null) ? array_values($chartA['points']) : [];
    $pointsB = is_array($chartB['points'] ?? null) ? array_values($chartB['points']) : [];
    $countA = count($pointsA);
    $countB = count($pointsB);
    $count = max($countA, $countB);
    if ($count === 0) {
        return '<div class="fuel-empty-chart">Nu exista date pentru grafic.</div>';
    }

    $values = [10.0];
    foreach ([$pointsA, $pointsB] as $series) {
        foreach ($series as $point) {
            $values[] = (float) ($point['value'] ?? 0);
        }
    }
    $maxValue = ceil(max($values) / 10) * 10;
    $width = 760;
    $height = 270;
    $left = 44;
    $right = 16;
    $top = 18;
    $bottom = 38;
    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;
    $step = $count > 1 ? $plotWidth / ($count - 1) : $plotWidth;

    $buildPolyline = static function (array $points, string $class) use ($left, $top, $plotHeight, $step, $maxValue): string {
        if ($points === []) {
            return '';
        }

        $svgPoints = [];
        foreach ($points as $index => $point) {
            $x = $left + ($step * $index);
            $value = max(0.0, (float) ($point['value'] ?? 0));
            $y = $top + ($plotHeight - (($value / $maxValue) * $plotHeight));
            $svgPoints[] = round($x, 2) . ',' . round($y, 2);
        }

        return '<polyline points="' . e(implode(' ', $svgPoints)) . '" class="' . $class . '"/>';
    };

    $grid = '';
    for ($i = 0; $i <= 5; $i++) {
        $value = ($maxValue / 5) * $i;
        $y = $top + ($plotHeight - (($value / $maxValue) * $plotHeight));
        $grid .= '<line x1="' . $left . '" y1="' . round($y, 2) . '" x2="' . ($width - $right) . '" y2="' . round($y, 2) . '" class="fuel-chart-gridline"/>';
        $grid .= '<text x="8" y="' . (round($y, 2) + 4) . '" class="fuel-chart-axis">' . e((string) round($value)) . '</text>';
    }

    $labels = '';
    $labelEvery = max(1, (int) ceil($count / 6));
    for ($index = 0; $index < $count; $index++) {
        if ($index % $labelEvery !== 0 && $index !== $count - 1) {
            continue;
        }

        if ($useDayIndex) {
            $label = 'Zi ' . ($index + 1);
        } else {
            $label = (string) ($pointsA[$index]['label'] ?? $pointsB[$index]['label'] ?? '');
        }

        $x = $left + ($step * $index);
        $labels .= '<text x="' . round($x, 2) . '" y="' . ($height - 8) . '" text-anchor="middle" class="fuel-chart-axis">' . e($label) . '</text>';
    }

    return '<svg class="fuel-line-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Comparatie consum mediu motorina">'
        . $grid
        . $buildPolyline($pointsB, 'fuel-chart-line-b')
        . $buildPolyline($pointsA, 'fuel-chart-line')
        . $labels
        . '</svg>';
};
$donutStyle = static function (array $items): string {
    if ($items === []) {
        return 'background: conic-gradient(#e2e8f0 0deg 360deg);';
    }

    $colors = [
        'primar' => '#2563eb',
        'distributie' => '#22c55e',
        'compresor' => '#f59e0b',
        'primar_distributie' => '#8b5cf6',
        'neasociat' => '#94a3b8',
    ];
    $cursor = 0.0;
    $segments = [];
    foreach ($items as $item) {
        $key = (string) ($item['key'] ?? 'neasociat');
        $degrees = max(0.0, ((float) ($item['percent'] ?? 0)) * 3.6);
        $start = $cursor;
        $end = min(360.0, $cursor + $degrees);
        $segments[] = ($colors[$key] ?? '#94a3b8') . ' ' . round($start, 2) . 'deg ' . round($end, 2) . 'deg';
        $cursor = $end;
    }
    if ($cursor < 360.0) {
        $segments[] = '#e2e8f0 ' . round($cursor, 2) . 'deg 360deg';
    }

    return 'background: conic-gradient(' . implode(', ', $segments) . ');';
};
?>

<div class="fuel-page">
    <div class="fuel-page-heading">
        <div>
            <h1>Carburanți</h1>
            <p>Monitorizare alimentări, consumuri și analiză pe vehicul și tip transport</p>
        </div>
        <?php if ($canManageFull): ?>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#fuelSyncVehiclesModal"
                        title="Alege pentru ce vehicule se importa alimentarile din CardOil">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    Vehicule sincronizate
                    <span class="badge <?= $fuelSyncExcludedCount > 0 ? 'text-bg-warning' : 'text-bg-light' ?> ms-1">
                        <?= (int) ($fuelSyncTotal - $fuelSyncExcludedCount) ?>/<?= (int) $fuelSyncTotal ?>
                    </span>
                </button>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#fuelAddManualModal">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Adaugă alimentare
                </button>
            </div>
        <?php endif; ?>
    </div>

    <form class="fuel-filter-card" method="get" action="<?= e(url('index.php')) ?>">
        <input type="hidden" name="page" value="carburanti">
        <div class="fuel-filter-grid">
            <div>
                <label class="form-label" for="fuel_period_display">Perioadă</label>
                <div class="fuel-date-input">
                    <input type="text" class="form-control" id="fuel_period_display"
                           value="<?= e($periodLabel) ?>" placeholder="Alege perioada" readonly>
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                </div>
                <input type="hidden" name="date_from" id="fuel_date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
                <input type="hidden" name="date_to" id="fuel_date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
            </div>
            <?php
            // Marcile disponibile, din optiunile de vehicule (fisa flotei).
            $fuelBrands = [];
            foreach ($vehicleOptions as $brandOption) {
                $brandValue = trim((string) ($brandOption['marca'] ?? ''));
                if ($brandValue === '') {
                    continue;
                }
                // aceeasi marca poate fi scrisa diferit in fise (Mercedes /
                // MERCEDES); pastram o singura optiune, prima intalnita
                $brandKey = mb_strtoupper($brandValue);
                if (!isset($fuelBrands[$brandKey])) {
                    $fuelBrands[$brandKey] = $brandValue;
                }
            }
            $fuelBrands = array_values($fuelBrands);
            usort($fuelBrands, static fn (string $a, string $b): int => strcasecmp($a, $b));
            $activeBrand = trim((string) ($filters['brand'] ?? ''));
            ?>
            <div>
                <label class="form-label" for="fuel_brand">Marcă</label>
                <select class="form-select" id="fuel_brand" name="brand">
                    <option value="">Toate mărcile</option>
                    <?php foreach ($fuelBrands as $brandValue): ?>
                        <option value="<?= e($brandValue) ?>" <?= strcasecmp($activeBrand, $brandValue) === 0 ? 'selected' : '' ?>><?= e($brandValue) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" id="fuel_vehicles_label">Vehicule</label>
                <div class="dropdown fuel-vehicle-multiselect" id="fuelVehicleMultiselect">
                    <button class="form-select text-start" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-labelledby="fuel_vehicles_label">
                        <?php
                        $selectedCount = count($selectedVehicles);
                        if ($selectedCount === 0) {
                            echo 'Toate vehiculele';
                        } elseif ($selectedCount === 1) {
                            echo e((string) $selectedVehicles[0]);
                        } else {
                            echo e($selectedCount . ' vehicule selectate');
                        }
                        ?>
                    </button>
                    <?php
                    // Vehiculele grupate pe CATEGORIA de capacitate, ca in Configurare
                    // transport. Eticheta grupei vine din catalogul de categorii, nu din
                    // capacitatea tehnica; capacitatea reala se afiseaza langa vehicul.
                    // Vehiculele fara fisa / fara categorie ajung in grupa de la final.
                    $fuelVehicleCandidates = [];
                    foreach ($vehicleOptions as $vehicle) {
                        if (trim((string) ($vehicle['vehicle_registration'] ?? '')) === '') {
                            continue;
                        }
                        // Cu filtrul de marca activ, selectorul arata doar
                        // vehiculele marcii respective.
                        if ($activeBrand !== '' && strcasecmp(trim((string) ($vehicle['marca'] ?? '')), $activeBrand) !== 0) {
                            continue;
                        }
                        $fuelVehicleCandidates[] = $vehicle;
                    }
                    $fuelVehicleGroups = build_vehicle_capacity_groups($fuelVehicleCandidates, [
                        'id_key' => 'vehicle_registration',
                        'fallback_label' => 'Fără categorie',
                    ]);
                    ?>
                    <div class="dropdown-menu fuel-vehicle-menu">
                        <input type="search" class="form-control form-control-sm mb-2" placeholder="Caută vehicul..." data-vehicle-search>
                        <div class="fuel-vehicle-menu-actions">
                            <button type="button" class="btn btn-link btn-sm p-0" data-vehicle-clear>Șterge selecția (toate vehiculele)</button>
                        </div>
                        <div class="fuel-vehicle-menu-empty text-muted small px-2 py-1" hidden>Niciun vehicul găsit.</div>
                        <?php foreach ($fuelVehicleGroups as $capacityGroup): ?>
                            <?php
                            $groupHasSelection = false;
                            foreach ($capacityGroup['vehicles'] as $groupVehicle) {
                                if (in_array(trim((string) ($groupVehicle['vehicle_registration'] ?? '')), $selectedVehicles, true)) {
                                    $groupHasSelection = true;
                                    break;
                                }
                            }
                            ?>
                            <div class="fuel-vehicle-group<?= $groupHasSelection ? '' : ' is-collapsed' ?>" data-vehicle-group>
                                <div class="fuel-vehicle-group-head" data-vehicle-group-head>
                                    <input class="form-check-input m-0" type="checkbox" data-vehicle-group-toggle aria-label="Selectează toate vehiculele: <?= e((string) $capacityGroup['label']) ?>">
                                    <span><?= e((string) $capacityGroup['label']) ?></span>
                                    <span class="fuel-vehicle-group-count"><?= e((string) count($capacityGroup['vehicles'])) ?></span>
                                    <i class="bi bi-chevron-down fuel-vehicle-group-chevron" aria-hidden="true"></i>
                                </div>
                                <?php foreach ($capacityGroup['vehicles'] as $vehicle): ?>
                                    <?php
                                    $vehicleValue = trim((string) ($vehicle['vehicle_registration'] ?? ''));
                                    $vehicleBrand = trim(trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')));
                                    // Capacitatea REALA, informativa; grupa este categoria.
                                    $vehicleRealCapacity = vehicle_capacity_format_tons($vehicle['capacitate_transport'] ?? null);
                                    ?>
                                    <label class="fuel-vehicle-option" data-brand="<?= e(mb_strtoupper(trim((string) ($vehicle['marca'] ?? '')))) ?>">
                                        <input type="checkbox" class="form-check-input" name="vehicles[]" value="<?= e($vehicleValue) ?>" <?= in_array($vehicleValue, $selectedVehicles, true) ? 'checked' : '' ?>>
                                        <span><?= e($vehicleValue . ($vehicleBrand !== '' ? ' - ' . $vehicleBrand : '')) ?></span>
                                        <?php if ($vehicleRealCapacity !== null): ?><span class="text-muted small ms-auto"><?= e($vehicleRealCapacity) ?></span><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label" for="fuel_transport_group">Tip Transport</label>
                <select class="form-select" id="fuel_transport_group" name="transport_group">
                    <option value="">Toate</option>
                    <option value="primar" <?= (string) ($filters['transport_group'] ?? '') === 'primar' ? 'selected' : '' ?>>Primar</option>
                    <option value="distributie" <?= (string) ($filters['transport_group'] ?? '') === 'distributie' ? 'selected' : '' ?>>Distribuție</option>
                    <option value="compresor" <?= (string) ($filters['transport_group'] ?? '') === 'compresor' ? 'selected' : '' ?>>Compresor</option>
                    <option value="primar_distributie" <?= (string) ($filters['transport_group'] ?? '') === 'primar_distributie' ? 'selected' : '' ?>>Primar + Distribuție</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="fuel_type">Tip Carburant</label>
                <select class="form-select" id="fuel_type" name="fuel_type">
                    <option value="">Toate</option>
                    <option value="motorina" <?= (string) ($filters['fuel_type'] ?? '') === 'motorina' ? 'selected' : '' ?>>Motorină</option>
                    <option value="adblue" <?= (string) ($filters['fuel_type'] ?? '') === 'adblue' ? 'selected' : '' ?>>AdBlue</option>
                </select>
            </div>
            <div class="fuel-filter-actions">
                <!-- Filtrele se aplica automat la schimbare; butonul ramane ca fallback fara JS. -->
                <button class="btn btn-outline-primary" type="submit" data-fuel-filter-submit>
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                    Actualizează
                </button>
            </div>
        </div>
    </form>

    <?php
    $vehicleKpiCards = [];
    if (count($selectedVehicles) >= 2 && $vehicleComparison !== []) {
        $rowsByKey = [];
        foreach ($vehicleComparison as $row) {
            $rowsByKey[str_replace(' ', '', strtoupper((string) ($row['vehicle_registration'] ?? '')))] = $row;
        }
        foreach ($selectedVehicles as $index => $plate) {
            $key = str_replace(' ', '', strtoupper($plate));
            $row = $rowsByKey[$key] ?? ['vehicle_registration' => $plate, 'missing' => true];
            $row['color'] = $seriesPalette[$index % count($seriesPalette)];
            $vehicleKpiCards[] = $row;
        }
    }

    $vehiclesWithConsum = array_values(array_filter($vehicleKpiCards, static fn (array $card): bool => empty($card['missing']) && (float) ($card['consum_motorina'] ?? 0) > 0));
    $bestVehicleKey = null;
    $worstVehicleKey = null;
    $vehicleDiff = null;
    if (count($vehiclesWithConsum) >= 2) {
        usort($vehiclesWithConsum, static fn (array $a, array $b): int => (float) $a['consum_motorina'] <=> (float) $b['consum_motorina']);
        $bestCard = $vehiclesWithConsum[0];
        $worstCard = $vehiclesWithConsum[count($vehiclesWithConsum) - 1];
        $bestVehicleKey = (string) $bestCard['vehicle_registration'];
        $worstVehicleKey = (string) $worstCard['vehicle_registration'];
        $vehicleDiff = ['a' => $worstCard, 'b' => $bestCard];
    } elseif (count($vehicleKpiCards) >= 2 && empty($vehicleKpiCards[0]['missing']) && empty($vehicleKpiCards[1]['missing'])) {
        $vehicleDiff = ['a' => $vehicleKpiCards[0], 'b' => $vehicleKpiCards[1]];
    }

    $signedNumber = static fn (float $value, int $decimals): string => ($value > 0 ? '+' : '') . format_number_ro($value, $decimals);
    ?>

    <?php if ($vehicleKpiCards !== []): ?>
        <div class="fuel-vehicle-kpi-grid">
            <?php foreach ($vehicleKpiCards as $cardIndex => $card): ?>
                <?php
                $plate = (string) ($card['vehicle_registration'] ?? '-');
                $cardConsum = (float) ($card['consum_motorina'] ?? 0);
                ?>
                <article class="fuel-kpi-card fuel-vehicle-kpi-card" style="--vehicle-accent: <?= e((string) ($card['color'] ?? '#1d6cff')) ?>; animation-delay: <?= e(number_format($cardIndex * 0.08, 2, '.', '')) ?>s;">
                    <div>
                        <span class="fuel-vehicle-kpi-name"><?= e($plate) ?></span>
                        <?php if (!empty($card['missing'])): ?>
                            <strong>Fără alimentări</strong>
                            <small>Nu există date în perioada selectată</small>
                        <?php else: ?>
                            <strong><?= $cardConsum > 0 ? e(format_number_ro($cardConsum, 2)) . ' L/100 km' : '- L/100 km' ?></strong>
                            <small>Motorină: <?= e($formatLiters((float) ($card['motorina'] ?? 0))) ?> · AdBlue: <?= e($formatLiters((float) ($card['adblue'] ?? 0))) ?></small>
                            <small>Cost: <?= e($formatCurrency((float) ($card['total_value'] ?? 0))) ?> · <?= e($formatKm((float) ($card['km'] ?? 0))) ?></small>
                            <?php if ($plate === $bestVehicleKey): ?>
                                <span class="fuel-kpi-change is-good"><i class="bi bi-trophy" aria-hidden="true"></i> Cel mai economic</span>
                            <?php elseif ($plate === $worstVehicleKey): ?>
                                <span class="fuel-kpi-change is-bad"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Cel mai mare consum</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <i class="bi bi-truck" aria-hidden="true"></i>
                </article>
            <?php endforeach; ?>

            <?php if ($vehicleDiff !== null): ?>
                <?php
                $diffA = $vehicleDiff['a'];
                $diffB = $vehicleDiff['b'];
                $diffConsum = (float) ($diffA['consum_motorina'] ?? 0) - (float) ($diffB['consum_motorina'] ?? 0);
                $diffMotorina = (float) ($diffA['motorina'] ?? 0) - (float) ($diffB['motorina'] ?? 0);
                $diffAdblue = (float) ($diffA['adblue'] ?? 0) - (float) ($diffB['adblue'] ?? 0);
                $diffCost = (float) ($diffA['total_value'] ?? 0) - (float) ($diffB['total_value'] ?? 0);
                ?>
                <article class="fuel-kpi-card fuel-vehicle-kpi-card fuel-vehicle-diff-card" style="animation-delay: <?= e(number_format(count($vehicleKpiCards) * 0.08, 2, '.', '')) ?>s;">
                    <div>
                        <span class="fuel-vehicle-kpi-name"><?= e((string) $diffA['vehicle_registration']) ?> vs <?= e((string) $diffB['vehicle_registration']) ?></span>
                        <strong><?= e($signedNumber($diffConsum, 2)) ?> L/100 km</strong>
                        <small>Motorină: <?= e($signedNumber($diffMotorina, 2)) ?> L · AdBlue: <?= e($signedNumber($diffAdblue, 2)) ?> L</small>
                        <small>Cost: <?= e($signedNumber($diffCost, 2)) ?> lei</small>
                    </div>
                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                </article>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="fuel-kpi-grid">
        <article class="fuel-kpi-card fuel-accent-green">
            <div>
                <span>Motorină consumată</span>
                <strong><?= e($formatLiters((float) ($kpis['motorina_liters'] ?? 0))) ?></strong>
                <small>Cost total: <?= e($formatCurrency((float) ($kpis['total_value'] ?? 0))) ?></small>
                <?= $changeBadge($kpis['changes']['motorina_liters'] ?? null) ?>
            </div>
            <i class="bi bi-fuel-pump" aria-hidden="true"></i>
        </article>
        <article class="fuel-kpi-card fuel-accent-blue">
            <div>
                <span>AdBlue consumat</span>
                <strong><?= e($formatLiters((float) ($kpis['adblue_liters'] ?? 0))) ?></strong>
                <small>Raportat pe perioada selectată</small>
                <?= $changeBadge($kpis['changes']['adblue_liters'] ?? null) ?>
            </div>
            <i class="bi bi-bag-fill" aria-hidden="true"></i>
        </article>
        <article class="fuel-kpi-card fuel-accent-amber">
            <div>
                <span>Consum mediu Motorină</span>
                <strong><?= e(format_number_ro((float) ($kpis['motorina_avg_l100'] ?? 0), 2)) ?> L/100 km</strong>
                <small><?= e($consumptionDetail !== '' ? $consumptionDetail : 'Pe perioada selectata') ?></small>
                <?= $changeBadge($kpis['changes']['motorina_avg_l100'] ?? null) ?>
            </div>
            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
        </article>
        <article class="fuel-kpi-card fuel-accent-cyan">
            <div>
                <span>Consum mediu AdBlue</span>
                <strong><?= e($formatPercent((float) ($kpis['adblue_percent'] ?? 0))) ?></strong>
                <small>Raportat la motorină</small>
                <?= $changeBadge($kpis['changes']['adblue_percent'] ?? null) ?>
            </div>
            <i class="bi bi-droplet-fill" aria-hidden="true"></i>
        </article>
        <article class="fuel-kpi-card fuel-accent-purple">
            <div>
                <span>Cost total carburant</span>
                <strong><?= e($formatCurrency((float) ($kpis['total_value'] ?? 0))) ?></strong>
                <small>Motorină + AdBlue</small>
                <?= $changeBadge($kpis['changes']['total_value'] ?? null) ?>
            </div>
            <i class="bi bi-cash-coin" aria-hidden="true"></i>
        </article>
    </div>
    <?php
    $fuelSplitTotal = (float) ($kpis['total_value'] ?? 0);
    $fuelSplitGroups = [
        ['label' => 'Vehicule grele', 'hint' => 'Camioane, capete tractor', 'icon' => 'bi-truck', 'accent' => 'blue', 'value' => (float) ($kpis['heavy_value'] ?? 0), 'liters' => (float) ($kpis['heavy_liters'] ?? 0), 'fillups' => (int) ($kpis['heavy_fillups'] ?? 0)],
        ['label' => 'Vehicule ușoare', 'hint' => 'Autoturisme, autoutilitare', 'icon' => 'bi-car-front', 'accent' => 'purple', 'value' => (float) ($kpis['light_value'] ?? 0), 'liters' => (float) ($kpis['light_liters'] ?? 0), 'fillups' => (int) ($kpis['light_fillups'] ?? 0)],
    ];
    ?>
    <div class="fuel-split" aria-label="Cost carburant pe categorie de vehicul">
        <?php foreach ($fuelSplitGroups as $group): ?>
            <?php $share = $fuelSplitTotal > 0 ? $group['value'] / $fuelSplitTotal * 100 : 0.0; ?>
            <article class="fuel-split-card fuel-split-<?= e($group['accent']) ?>">
                <i class="bi <?= e($group['icon']) ?>" aria-hidden="true"></i>
                <div class="fuel-split-main">
                    <span><?= e($group['label']) ?> <small><?= e($group['hint']) ?></small></span>
                    <strong><?= e($formatCurrency($group['value'])) ?></strong>
                    <small><?= e($formatLiters($group['liters'])) ?> · <?= e(format_number_ro((float) $group['fillups'], 0)) ?> alimentări</small>
                </div>
                <div class="fuel-split-share">
                    <strong><?= e(format_number_ro($share, 1)) ?>%</strong>
                    <small>din cost total</small>
                    <div class="fuel-split-bar"><span style="width: <?= e(number_format(min(100.0, max(0.0, $share)), 1, '.', '')) ?>%"></span></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="fuel-tabs">
        <ul class="nav nav-tabs" id="fuelTabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" id="fuel-overview-tab" data-bs-toggle="tab" data-bs-target="#fuel-overview" type="button" role="tab">Prezentare generală</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="fuel-fillups-tab" data-bs-toggle="tab" data-bs-target="#fuel-fillups" type="button" role="tab">Alimentări</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="fuel-trips-tab" data-bs-toggle="tab" data-bs-target="#fuel-trips" type="button" role="tab">Consum pe curse</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="fuel-transport-tab" data-bs-toggle="tab" data-bs-target="#fuel-transport" type="button" role="tab">Consum pe tip transport</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="fuel-norm-tab" data-bs-toggle="tab" data-bs-target="#fuel-norm" type="button" role="tab">Consum normat</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="fuel-compare-tab" data-bs-toggle="tab" data-bs-target="#fuel-compare" type="button" role="tab">Comparație</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="fuel-reports-tab" data-bs-toggle="tab" data-bs-target="#fuel-reports" type="button" role="tab">Rapoarte</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="fuel-kmcheck-tab" data-bs-toggle="tab" data-bs-target="#fuel-kmcheck" type="button" role="tab">Verificare km (GPS/CAN)</button></li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="fuel-unassociated-tab" data-bs-toggle="tab" data-bs-target="#fuel-unassociated" type="button" role="tab">
                    Alimentări neasociate
                    <?php if (count($unassociatedFillups) > 0): ?><span class="fuel-tab-badge"><?= e((string) count($unassociatedFillups)) ?></span><?php endif; ?>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <section class="tab-pane fade show active" id="fuel-overview" role="tabpanel" aria-labelledby="fuel-overview-tab" tabindex="0">
                <div class="fuel-chart-grid">
                    <article class="fuel-card fuel-chart-card fuel-line-card">
                        <div class="fuel-card-header">
                            <h2>Consum mediu Motorină (L/100 km)</h2>
                            <span class="fuel-select-chip">Zilnic <i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                        </div>
                        <?= $renderLineChart($dailyChart) ?>
                        <div class="fuel-chart-legend">
                            <span><i class="legend-line"></i> Consum mediu (L/100 km)</span>
                            <span><i class="legend-dashed"></i> Medie lună: <?= e(format_number_ro((float) ($dailyChart['average'] ?? 0), 2)) ?> L/100 km</span>
                        </div>
                    </article>

                    <article class="fuel-card fuel-chart-card">
                        <div class="fuel-card-header">
                            <h2>Consum pe tip transport (litri)</h2>
                        </div>
                        <div class="fuel-donut-wrap">
                            <div class="fuel-donut" style="<?= e($donutStyle((array) ($transportChart['items'] ?? []))) ?>">
                                <div>
                                    <span>Total</span>
                                    <strong><?= e($formatLiters((float) ($transportChart['total'] ?? 0))) ?></strong>
                                </div>
                            </div>
                            <div class="fuel-donut-legend">
                                <?php foreach ((array) ($transportChart['items'] ?? []) as $item): ?>
                                    <div>
                                        <span class="fuel-dot fuel-dot-<?= e((string) ($item['key'] ?? 'neasociat')) ?>"></span>
                                        <strong><?= e((string) ($item['label'] ?? '-')) ?></strong>
                                        <small><?= e($formatLiters((float) ($item['liters'] ?? 0))) ?> (<?= e(format_number_ro((float) ($item['percent'] ?? 0), 1)) ?>%)</small>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (($transportChart['items'] ?? []) === []): ?>
                                    <p class="text-muted mb-0">Nu exista alimentari asociate curselor.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>

                    <article class="fuel-card fuel-norm-card">
                        <div class="fuel-card-header">
                            <h2>Consum normat (interval curent)</h2>
                            <span class="fuel-status-badge <?= ($normative['status'] ?? '') === 'valid' ? 'is-valid' : 'is-invalid' ?>">
                                <?= e((string) ($normative['message'] ?? 'Interval invalid')) ?>
                            </span>
                        </div>
                        <?php $renderT0Status(); ?>
                        <div class="fuel-norm-grid">
                            <div>
                                <small>Full început</small>
                                <strong><?= e($formatDateTime((string) (($normative['start_full']['fillup_datetime'] ?? '') ?: ''))) ?></strong>
                                <span><?= e($formatLiters((float) ($normative['start_full']['quantity_liters'] ?? 0))) ?> · <?= e($formatKm((float) ($normative['start_full']['odometer_km'] ?? 0))) ?></span>
                            </div>
                            <div>
                                <small>Următor full</small>
                                <strong><?= e($formatDateTime((string) (($normative['next_full']['fillup_datetime'] ?? '') ?: ''))) ?></strong>
                                <span><?= e($formatLiters((float) ($normative['next_full']['quantity_liters'] ?? 0))) ?> · <?= e($formatKm((float) ($normative['next_full']['odometer_km'] ?? 0))) ?></span>
                            </div>
                            <div>
                                <small>Km parcurși</small>
                                <strong><?= e(format_number_ro((float) ($normative['km'] ?? 0), 0)) ?> km</strong>
                                <?php if ($normKmSource !== ''): ?><span>Sursa: <?= e($normKmSource) ?></span><?php endif; ?>
                            </div>
                            <div>
                                <small>Motorină consumată</small>
                                <strong><?= e($formatLiters((float) ($normative['motorina_liters'] ?? 0))) ?></strong>
                            </div>
                            <div>
                                <small>Consum normat</small>
                                <strong class="text-success"><?= e(format_number_ro((float) ($normative['norm_l100'] ?? 0), 2)) ?> L/100 km</strong>
                            </div>
                            <div>
                                <small>Consum AdBlue</small>
                                <strong><?= e($formatPercent((float) ($normative['adblue_percent'] ?? 0))) ?></strong>
                            </div>
                        </div>
                        <p class="fuel-norm-status">
                            <i class="bi <?= ($normative['status'] ?? '') === 'valid' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i>
                            Status: <?= e((string) ($normative['message'] ?? 'Interval invalid')) ?>
                        </p>
                        <?php if (!empty($normative['odometer_warning'])): ?>
                            <p class="fuel-norm-status text-warning">
                                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                                <?= e((string) $normative['odometer_warning']) ?>
                            </p>
                        <?php endif; ?>
                    </article>
                </div>

                <article class="fuel-card fuel-price-card">
                    <div class="fuel-card-header">
                        <h2>Evoluție preț carburant (lei/L)</h2>
                        <span class="fuel-select-chip">Prețuri CardOil · medie zilnică ponderată cu litrii</span>
                    </div>
                    <div class="fuel-price-grid">
                        <?php
                        $priceSections = [
                            ['key' => 'motorina', 'title' => 'Motorină', 'color' => '#1d6cff'],
                            ['key' => 'adblue', 'title' => 'AdBlue', 'color' => '#0891b2'],
                        ];
                        ?>
                        <?php foreach ($priceSections as $section): ?>
                            <?php
                            $priceSeries = is_array($priceEvolution[$section['key']] ?? null) ? $priceEvolution[$section['key']] : ['points' => []];
                            // Data la care s-a atins minimul / maximul, ca sa fie vizibila direct in antet.
                            $pricePoints = is_array($priceSeries['points'] ?? null) ? array_values($priceSeries['points']) : [];
                            $priceMinLabel = '';
                            $priceMaxLabel = '';
                            if ($pricePoints !== []) {
                                $priceValues = array_map(static fn (array $p): float => (float) ($p['value'] ?? 0), $pricePoints);
                                $priceMinLabel = (string) ($pricePoints[(int) array_search(min($priceValues), $priceValues, true)]['label'] ?? '');
                                $priceMaxLabel = (string) ($pricePoints[(int) array_search(max($priceValues), $priceValues, true)]['label'] ?? '');
                            }
                            ?>
                            <div class="fuel-price-panel">
                                <div class="fuel-price-panel-header">
                                    <strong><span class="fuel-dot" style="background: <?= e((string) $section['color']) ?>"></span> <?= e((string) $section['title']) ?></strong>
                                    <?php if ($pricePoints !== []): ?>
                                        <div class="fuel-price-stats">
                                            <span>Ultimul: <strong><?= e(format_number_ro((float) ($priceSeries['latest'] ?? 0), 2)) ?></strong></span>
                                            <button type="button" class="fuel-price-jump is-min" data-fuel-price-jump="min" title="Arată pe grafic ziua cu prețul minim">
                                                <i class="bi bi-arrow-down-short" aria-hidden="true"></i>
                                                Min: <strong><?= e(format_number_ro((float) ($priceSeries['min'] ?? 0), 2)) ?></strong>
                                                <?php if ($priceMinLabel !== ''): ?><em><?= e($priceMinLabel) ?></em><?php endif; ?>
                                            </button>
                                            <button type="button" class="fuel-price-jump is-max" data-fuel-price-jump="max" title="Arată pe grafic ziua cu prețul maxim">
                                                <i class="bi bi-arrow-up-short" aria-hidden="true"></i>
                                                Max: <strong><?= e(format_number_ro((float) ($priceSeries['max'] ?? 0), 2)) ?></strong>
                                                <?php if ($priceMaxLabel !== ''): ?><em><?= e($priceMaxLabel) ?></em><?php endif; ?>
                                            </button>
                                            <span>Medie: <?= e(format_number_ro((float) ($priceSeries['avg'] ?? 0), 2)) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?= $renderPriceChart($priceSeries, (string) $section['color']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <div class="fuel-table-split">
                    <article class="fuel-card">
                        <div class="fuel-card-header">
                            <h2>Ultimele alimentări</h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table fuel-table">
                                <thead>
                                    <tr>
                                        <th>Data și ora</th>
                                        <th>Vehicul</th>
                                        <th>Șofer</th>
                                        <th>Stație</th>
                                        <th>Tip carburant</th>
                                        <th>Cantitate</th>
                                        <th>Km</th>
                                        <th>Valoare</th>
                                        <th>Tip alimentare</th>
                                        <th>Asociere cursă</th>
                                        <th>Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody><?php $renderFillupRows($latestFillups); ?></tbody>
                            </table>
                        </div>
                        <a class="fuel-card-link" href="#fuel-fillups" data-fuel-tab-link="fuel-fillups-tab">Vezi toate alimentările <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </article>

                    <article class="fuel-card">
                        <div class="fuel-card-header">
                            <h2>Alimentări neasociate <span class="fuel-count-badge"><?= e((string) count($unassociatedFillups)) ?></span></h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table fuel-table fuel-table-compact">
                                <thead>
                                    <tr>
                                        <th>Data și ora</th>
                                        <th>Vehicul</th>
                                        <th>Tip carburant</th>
                                        <th>Cantitate</th>
                                        <th>Valoare</th>
                                        <th>Acțiuni</th>
                                    </tr>
                                </thead>
                                <tbody><?php $renderFillupRows(array_slice($unassociatedFillups, 0, 4), true); ?></tbody>
                            </table>
                        </div>
                        <a class="fuel-card-link" href="#fuel-unassociated" data-fuel-tab-link="fuel-unassociated-tab">Vezi toate alimentările neasociate <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </article>
                </div>
            </section>

            <section class="tab-pane fade" id="fuel-fillups" role="tabpanel" aria-labelledby="fuel-fillups-tab" tabindex="0">
                <article class="fuel-card">
                    <div class="fuel-card-header"><h2>Alimentări</h2></div>
                    <div class="table-responsive">
                        <table class="table fuel-table">
                            <thead>
                                <tr>
                                    <th>Data și ora</th>
                                    <th>Vehicul</th>
                                    <th>Șofer</th>
                                    <th>Stație</th>
                                    <th>Tip carburant</th>
                                    <th>Cantitate</th>
                                    <th>Km</th>
                                    <th>Valoare</th>
                                    <th>Tip alimentare</th>
                                    <th>Asociere cursă</th>
                                    <th>Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody><?php $renderFillupRows($allFillups); ?></tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="tab-pane fade" id="fuel-trips" role="tabpanel" aria-labelledby="fuel-trips-tab" tabindex="0">
                <article class="fuel-card">
                    <div class="fuel-card-header"><h2>Consum pe curse</h2></div>
                    <div class="table-responsive">
                        <table class="table fuel-table">
                            <thead>
                                <tr>
                                    <th>Cursă</th>
                                    <th>Vehicul</th>
                                    <th>Tip transport</th>
                                    <th>Interval</th>
                                    <th>Km</th>
                                    <th>Motorină</th>
                                    <th>AdBlue</th>
                                    <th>Motorină L/100 km</th>
                                    <th>AdBlue %</th>
                                    <th>Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($tripConsumption === []): ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">Nu exista curse asociate in perioada selectata.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($tripConsumption as $row): ?>
                                    <tr>
                                        <td class="fw-semibold">#<?= e((string) ((int) ($row['trip_id'] ?? 0))) ?></td>
                                        <td><?= e((string) ($row['vehicle_registration'] ?? '-')) ?></td>
                                        <td><?= e($transportLabel((string) ($row['tip_transport'] ?? ''))) ?></td>
                                        <td><?= e(format_date_ro((string) ($row['data_inceput'] ?? ''))) ?> - <?= e(format_date_ro((string) ($row['data_sfarsit'] ?? ''))) ?></td>
                                        <td><?= e(format_number_ro((float) ($row['km'] ?? 0), 0)) ?></td>
                                        <td><?= e($formatLiters((float) ($row['motorina'] ?? 0))) ?></td>
                                        <td><?= e($formatLiters((float) ($row['adblue'] ?? 0))) ?></td>
                                        <td><?= e(format_number_ro((float) ($row['consum_motorina'] ?? 0), 2)) ?></td>
                                        <td><?= e($formatPercent((float) ($row['consum_adblue'] ?? 0))) ?></td>
                                        <td><?= e($formatCurrency((float) ($row['total_value'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="tab-pane fade" id="fuel-transport" role="tabpanel" aria-labelledby="fuel-transport-tab" tabindex="0">
                <article class="fuel-card">
                    <div class="fuel-card-header"><h2>Consum pe tip transport</h2></div>
                    <div class="table-responsive">
                        <table class="table fuel-table">
                            <thead>
                                <tr>
                                    <th>Tip transport</th>
                                    <th>Km</th>
                                    <th>Motorină</th>
                                    <th>AdBlue</th>
                                    <th>Motorină L/100 km</th>
                                    <th>AdBlue %</th>
                                    <th>Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($transportConsumption === []): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">Nu exista consum asociat tipurilor de transport.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($transportConsumption as $row): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e((string) ($row['label'] ?? '-')) ?></td>
                                        <td><?= e(format_number_ro((float) ($row['km'] ?? 0), 0)) ?></td>
                                        <td><?= e($formatLiters((float) ($row['motorina'] ?? 0))) ?></td>
                                        <td><?= e($formatLiters((float) ($row['adblue'] ?? 0))) ?></td>
                                        <td><?= e(format_number_ro((float) ($row['consum_motorina'] ?? 0), 2)) ?></td>
                                        <td><?= e($formatPercent((float) ($row['consum_adblue'] ?? 0))) ?></td>
                                        <td><?= e($formatCurrency((float) ($row['total_value'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="tab-pane fade" id="fuel-norm" role="tabpanel" aria-labelledby="fuel-norm-tab" tabindex="0">
                <article class="fuel-card fuel-norm-details">
                    <div class="fuel-card-header">
                        <h2>Consum normat</h2>
                        <span class="fuel-status-badge <?= ($normative['status'] ?? '') === 'valid' ? 'is-valid' : 'is-invalid' ?>"><?= e((string) ($normative['message'] ?? 'Interval invalid')) ?></span>
                    </div>
                    <?php $renderT0Status(); ?>
                    <div class="fuel-norm-grid is-wide">
                        <div><small>Vehicul</small><strong><?= e((string) ($normative['vehicle'] ?? '-')) ?></strong></div>
                        <div><small>Full început</small><strong><?= e($formatDateTime((string) (($normative['start_full']['fillup_datetime'] ?? '') ?: ''))) ?></strong><span><?= e($formatKm((float) ($normative['start_full']['odometer_km'] ?? 0))) ?></span></div>
                        <div><small>Următor full</small><strong><?= e($formatDateTime((string) (($normative['next_full']['fillup_datetime'] ?? '') ?: ''))) ?></strong><span><?= e($formatKm((float) ($normative['next_full']['odometer_km'] ?? 0))) ?></span></div>
                        <div><small>Km parcurși</small><strong><?= e(format_number_ro((float) ($normative['km'] ?? 0), 0)) ?> km</strong><?php if ($normKmSource !== ''): ?><span>Sursa: <?= e($normKmSource) ?></span><?php endif; ?></div>
                        <div><small>Motorină consumată</small><strong><?= e($formatLiters((float) ($normative['motorina_liters'] ?? 0))) ?></strong></div>
                        <div><small>Consum normat</small><strong><?= e(format_number_ro((float) ($normative['norm_l100'] ?? 0), 2)) ?> L/100 km</strong></div>
                        <div><small>Consum AdBlue</small><strong><?= e($formatPercent((float) ($normative['adblue_percent'] ?? 0))) ?></strong></div>
                        <div><small>Status</small><strong><?= e((string) ($normative['message'] ?? 'Interval invalid')) ?></strong>
                            <?php if (!empty($normative['odometer_warning'])): ?>
                                <span class="text-warning d-block mt-1"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> <?= e((string) $normative['odometer_warning']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            </section>

            <section class="tab-pane fade" id="fuel-compare" role="tabpanel" aria-labelledby="fuel-compare-tab" tabindex="0">
                <?php
                $fleetAvg = $fleetAverageL100($vehicleComparison);

                // Widgetul de comparatie este randat client-side cu Chart.js, la fel ca
                // in Dashboard Analitic V2: serverul trimite doar randurile agregate.
                $compareRows = [];
                foreach ($vehicleComparison as $row) {
                    $rowLiters = (float) ($row['motorina'] ?? 0) + (float) ($row['adblue'] ?? 0);
                    $compareRows[] = [
                        'nume' => (string) ($row['vehicle_registration'] ?? '-'),
                        'alimentari' => (int) ($row['fillup_count'] ?? 0),
                        'motorina' => round((float) ($row['motorina'] ?? 0), 2),
                        'adblue' => round((float) ($row['adblue'] ?? 0), 2),
                        'km' => round((float) ($row['km'] ?? 0), 2),
                        'consum_motorina' => round((float) ($row['consum_motorina'] ?? 0), 2),
                        'consum_adblue' => round((float) ($row['consum_adblue'] ?? 0), 2),
                        'cost' => round((float) ($row['total_value'] ?? 0), 2),
                        'cost_km' => round((float) ($row['cost_per_km'] ?? 0), 3),
                        'pret_mediu' => $rowLiters > 0 ? round((float) ($row['total_value'] ?? 0) / $rowLiters, 3) : 0.0,
                        'km_source' => (string) ($row['km_source'] ?? ''),
                    ];
                }

                // Seriile zilnice exista doar pentru vehiculele alese in filtrul paginii.
                $compareDaily = [];
                foreach ($vehicleDailyCharts as $chartData) {
                    $dailyPoints = (array) (($chartData['chart'] ?? [])['points'] ?? []);
                    $compareDaily[] = [
                        'nume' => (string) ($chartData['vehicle'] ?? '-'),
                        'average' => round((float) ($chartData['average'] ?? 0), 2),
                        'labels' => array_map(static fn (array $point): string => (string) ($point['label'] ?? ''), $dailyPoints),
                        'values' => array_map(static fn (array $point): float => round((float) ($point['value'] ?? 0), 2), $dailyPoints),
                    ];
                }

                $fuelCompareConfig = [
                    'rows' => $compareRows,
                    'daily' => $compareDaily,
                    'fleetAvg' => $fleetAvg,
                    'period' => (string) ($filters['period'] ?? ''),
                    'preselected' => $selectedVehicles,
                ];
                ?>
                <link rel="stylesheet" href="<?= e(url('assets/css/dashboard-analitic-v2.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/dashboard-analitic-v2.css'))) ?>">

                <div class="da2 fuel-compare-da2" id="fuelCompareRoot">
                    <script type="application/json" id="fuelCompareConfig"><?= json_encode($fuelCompareConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

                    <article class="da2-card">
                        <header class="da2-card-head">
                            <div>
                                <h2>Comparație vehicule</h2>
                                <p class="da2-card-sub">Bifează vehiculele și metricile de comparat<?= $fleetAvg > 0 ? ' · medie flotă ' . e(format_number_ro($fleetAvg, 2)) . ' L/100 km' : '' ?>.</p>
                            </div>
                            <div class="da2-card-tools">
                                <div class="da2-seg" data-fuel-seg="view">
                                    <button type="button" data-value="bars" class="is-active">Bare</button>
                                    <button type="button" data-value="evolution">Evoluție</button>
                                </div>
                                <button type="button" class="da2-btn da2-btn-sm" id="fuelCompareTop">Adaugă top 5</button>
                                <button type="button" class="da2-btn da2-btn-sm da2-btn-ghost" id="fuelCompareClear">Golește</button>
                            </div>
                        </header>

                        <div class="da2-compare-grid">
                            <div class="da2-compare-picker">
                                <div class="da2-ms-search da2-ms-search-inline">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input type="search" id="fuelCompareSearch" placeholder="Caută vehicul…" autocomplete="off">
                                </div>
                                <div class="da2-compare-list" id="fuelCompareList"></div>
                            </div>
                            <div class="da2-compare-main">
                                <div class="da2-metric-toggles" id="fuelCompareMetrics"></div>
                                <div class="da2-chart da2-chart-lg"><canvas id="fuelCompareChart"></canvas></div>
                                <p class="da2-card-sub" id="fuelCompareNote"></p>
                            </div>
                        </div>
                    </article>

                    <div class="da2-grid-2">
                        <article class="da2-card">
                            <header class="da2-card-head">
                                <div>
                                    <h2>Profil normalizat</h2>
                                    <p class="da2-card-sub">Fiecare axă este scalată 0–100 față de cea mai mare valoare din selecție.</p>
                                </div>
                            </header>
                            <div class="da2-chart da2-chart-lg"><canvas id="fuelCompareRadar"></canvas></div>
                        </article>

                        <article class="da2-card">
                            <header class="da2-card-head">
                                <div>
                                    <h2>Structura alimentărilor</h2>
                                    <p class="da2-card-sub">Litri de motorină și AdBlue, pentru vehiculele selectate.</p>
                                </div>
                            </header>
                            <div class="da2-chart da2-chart-lg"><canvas id="fuelCompareMix"></canvas></div>
                        </article>
                    </div>

                    <article class="da2-card">
                        <header class="da2-card-head">
                            <div>
                                <h2>Tabel comparativ</h2>
                                <p class="da2-card-sub">Δ este diferența față de media vehiculelor selectate.</p>
                            </div>
                        </header>
                        <div class="da2-table-wrap"><table class="da2-table" id="fuelCompareTable"></table></div>
                    </article>
                </div>
                    <article class="fuel-card">
                        <div class="fuel-card-header">
                            <h2><?= count($selectedVehicles) >= 2 ? 'Vehicule selectate' : 'Toate vehiculele' ?> <span class="fuel-count-badge"><?= e((string) count($vehicleComparison)) ?></span></h2>
                            <span class="fuel-select-chip"><?= e((string) ($filters['period'] ?? '')) ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table fuel-table fuel-vehicle-table" id="fuelVehicleTable">
                                <thead>
                                    <tr>
                                        <th data-sort="text">Vehicul <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">Alimentări <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">Motorină <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">AdBlue <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">Km <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">Motorină L/100 km <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">AdBlue % <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">Cost <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                        <th data-sort="num">Cost/km <i class="bi bi-arrow-down-up" aria-hidden="true"></i></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($vehicleComparison === []): ?>
                                        <tr><td colspan="9" class="text-center text-muted py-4">Nu exista alimentari pentru filtrele selectate.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($vehicleComparison as $row): ?>
                                        <?php
                                        $rowConsum = (float) ($row['consum_motorina'] ?? 0);
                                        $isAbove = $fleetAvg > 0 && $rowConsum > $fleetAvg;
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?= e((string) ($row['vehicle_registration'] ?? '-')) ?></td>
                                            <td data-value="<?= e((string) ((int) ($row['fillup_count'] ?? 0))) ?>"><?= e((string) ((int) ($row['fillup_count'] ?? 0))) ?></td>
                                            <td data-value="<?= e((string) ((float) ($row['motorina'] ?? 0))) ?>"><?= e($formatLiters((float) ($row['motorina'] ?? 0))) ?></td>
                                            <td data-value="<?= e((string) ((float) ($row['adblue'] ?? 0))) ?>"><?= e($formatLiters((float) ($row['adblue'] ?? 0))) ?></td>
                                            <td data-value="<?= e((string) ((float) ($row['km'] ?? 0))) ?>">
                                                <?= e($formatKm((float) ($row['km'] ?? 0))) ?>
                                                <?php if (($row['km_source'] ?? '') === 'dispecer'): ?><small class="text-muted d-block">din dispecer</small><?php endif; ?>
                                            </td>
                                            <td data-value="<?= e((string) $rowConsum) ?>">
                                                <?php if ($rowConsum > 0): ?>
                                                    <span class="fuel-consum-value <?= $isAbove ? 'is-above' : 'is-below' ?>"><?= e(format_number_ro($rowConsum, 2)) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-value="<?= e((string) ((float) ($row['consum_adblue'] ?? 0))) ?>"><?= (float) ($row['consum_adblue'] ?? 0) > 0 ? e($formatPercent((float) ($row['consum_adblue'] ?? 0))) : '<span class="text-muted">-</span>' ?></td>
                                            <td data-value="<?= e((string) ((float) ($row['total_value'] ?? 0))) ?>"><?= e($formatCurrency((float) ($row['total_value'] ?? 0))) ?></td>
                                            <td data-value="<?= e((string) ((float) ($row['cost_per_km'] ?? 0))) ?>"><?= (float) ($row['cost_per_km'] ?? 0) > 0 ? e(format_number_ro((float) ($row['cost_per_km'] ?? 0), 2)) . ' lei/km' : '<span class="text-muted">-</span>' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="fuel-compare-hint">Click pe antetul unei coloane pentru sortare. Vehiculele fără km (odometru sau curse asociate) nu au consum mediu calculabil.</p>
                    </article>

                <article class="fuel-card fuel-compare-form-card">
                    <div class="fuel-card-header">
                        <h2>Comparație consumuri</h2>
                        <span class="fuel-select-chip"><?= e((string) ($compare['subtitle'] ?? '')) ?></span>
                    </div>
                    <form method="get" action="<?= e(url('index.php')) ?>" id="fuelCompareForm">
                        <input type="hidden" name="page" value="carburanti">
                        <input type="hidden" name="period" value="<?= e((string) ($filters['period'] ?? '')) ?>">
                        <?php foreach ($selectedVehicles as $selectedVehicle): ?>
                            <input type="hidden" name="vehicles[]" value="<?= e($selectedVehicle) ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="transport_group" value="<?= e((string) ($filters['transport_group'] ?? '')) ?>">
                        <input type="hidden" name="fuel_type" value="<?= e((string) ($filters['fuel_type'] ?? '')) ?>">
                        <input type="hidden" name="brand" value="<?= e((string) ($filters['brand'] ?? '')) ?>">
                        <div class="fuel-compare-form-grid">
                            <div>
                                <label class="form-label" for="fuel_compare_mode">Mod comparație</label>
                                <select class="form-select" id="fuel_compare_mode" name="compare_mode">
                                    <option value="periods" <?= (string) ($compare['mode'] ?? 'periods') === 'periods' ? 'selected' : '' ?>>Două perioade</option>
                                    <option value="vehicles" <?= (string) ($compare['mode'] ?? '') === 'vehicles' ? 'selected' : '' ?>>Două vehicule</option>
                                </select>
                            </div>
                            <div data-compare-mode="periods">
                                <label class="form-label" for="fuel_compare_period_a">Perioada A</label>
                                <div class="fuel-date-input">
                                    <input type="text" class="form-control" id="fuel_compare_period_a" name="compare_period_a"
                                           value="<?= e((string) ($compare['period_a'] ?? '')) ?>"
                                           data-range-from="<?= e((string) (($compare['filters_a']['date_from'] ?? ''))) ?>"
                                           data-range-to="<?= e((string) (($compare['filters_a']['date_to'] ?? ''))) ?>"
                                           placeholder="Alege perioada A" readonly>
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                </div>
                            </div>
                            <div data-compare-mode="periods">
                                <label class="form-label" for="fuel_compare_period_b">Perioada B</label>
                                <div class="fuel-date-input">
                                    <input type="text" class="form-control" id="fuel_compare_period_b" name="compare_period_b"
                                           value="<?= e((string) ($compare['period_b'] ?? '')) ?>"
                                           data-range-from="<?= e((string) (($compare['filters_b']['date_from'] ?? ''))) ?>"
                                           data-range-to="<?= e((string) (($compare['filters_b']['date_to'] ?? ''))) ?>"
                                           placeholder="Alege perioada B" readonly>
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                </div>
                            </div>
                            <div data-compare-mode="vehicles" hidden>
                                <label class="form-label" for="fuel_compare_vehicle_a">Vehicul A</label>
                                <select class="form-select" id="fuel_compare_vehicle_a" name="compare_vehicle_a">
                                    <option value="">Toate vehiculele</option>
                                    <?php foreach ($vehicleOptions as $vehicle): ?>
                                        <?php $vehicleValue = (string) ($vehicle['vehicle_registration'] ?? ''); ?>
                                        <option value="<?= e($vehicleValue) ?>" <?= (string) ($compare['vehicle_a'] ?? '') === $vehicleValue ? 'selected' : '' ?>><?= e($vehicleValue) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div data-compare-mode="vehicles" hidden>
                                <label class="form-label" for="fuel_compare_vehicle_b">Vehicul B</label>
                                <select class="form-select" id="fuel_compare_vehicle_b" name="compare_vehicle_b">
                                    <option value="">Toate vehiculele</option>
                                    <?php foreach ($vehicleOptions as $vehicle): ?>
                                        <?php $vehicleValue = (string) ($vehicle['vehicle_registration'] ?? ''); ?>
                                        <option value="<?= e($vehicleValue) ?>" <?= (string) ($compare['vehicle_b'] ?? '') === $vehicleValue ? 'selected' : '' ?>><?= e($vehicleValue) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fuel-compare-form-actions">
                                <button class="btn btn-primary" type="submit">
                                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                                    Compară
                                </button>
                            </div>
                        </div>
                        <p class="fuel-compare-hint">Comparația păstrează filtrele curente (tip transport, tip carburant<?= (string) ($compare['mode'] ?? '') === 'vehicles' ? '' : ', vehicul' ?>).</p>
                    </form>
                </article>

                <?php if ($comparison !== null): ?>
                    <?php
                    $compareMetrics = is_array($comparison['metrics'] ?? null) ? $comparison['metrics'] : [];
                    $compareLabelA = (string) ($compare['label_a'] ?? 'A');
                    $compareLabelB = (string) ($compare['label_b'] ?? 'B');
                    $sideHasData = static fn (array $summary): bool =>
                        (float) ($summary['motorina_liters'] ?? 0) > 0
                        || (float) ($summary['adblue_liters'] ?? 0) > 0
                        || (float) ($summary['total_value'] ?? 0) > 0;
                    $emptySides = [];
                    if (!$sideHasData((array) ($comparison['summary_a'] ?? []))) {
                        $emptySides[] = $compareLabelA;
                    }
                    if (!$sideHasData((array) ($comparison['summary_b'] ?? []))) {
                        $emptySides[] = $compareLabelB;
                    }
                    ?>
                    <?php if ($emptySides !== []): ?>
                        <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <div>
                                Nu există alimentări pentru <?= count($emptySides) === 2 ? 'niciuna dintre perioade' : ('<strong>' . e(implode('', $emptySides)) . '</strong>') ?>.
                                Comparația de mai jos arată doar cifrele celeilalte perioade — alege o perioadă cu date pentru o comparație reală.
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="fuel-compare-grid">
                        <article class="fuel-card">
                            <div class="fuel-card-header">
                                <h2>Indicatori comparați</h2>
                                <div class="fuel-compare-chips">
                                    <span class="fuel-compare-chip fuel-compare-chip-a"><i></i><?= e($compareLabelA) ?></span>
                                    <span class="fuel-compare-chip fuel-compare-chip-b"><i></i><?= e($compareLabelB) ?></span>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table fuel-table fuel-compare-table">
                                    <thead>
                                        <tr>
                                            <th>Indicator</th>
                                            <th><?= e($compareLabelA) ?></th>
                                            <th><?= e($compareLabelB) ?></th>
                                            <th>Diferență</th>
                                            <th>Variație</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($compareMetrics === []): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">Nu exista date pentru comparatie.</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($compareMetrics as $metric): ?>
                                            <?php
                                            $decimals = (int) ($metric['decimals'] ?? 2);
                                            $unit = (string) ($metric['unit'] ?? '');
                                            $formatMetric = static fn (float $value): string => format_number_ro($value, $decimals) . ($unit !== '' ? ' ' . $unit : '');
                                            $delta = (float) ($metric['delta'] ?? 0);
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?= e((string) ($metric['label'] ?? '-')) ?></td>
                                                <td><?= e($formatMetric((float) ($metric['value_a'] ?? 0))) ?></td>
                                                <td><?= e($formatMetric((float) ($metric['value_b'] ?? 0))) ?></td>
                                                <td><?= e(($delta > 0 ? '+' : '') . $formatMetric($delta)) ?></td>
                                                <td><?= $compareBadge(isset($metric['percent']) ? (is_numeric($metric['percent']) ? (float) $metric['percent'] : null) : null, (string) ($metric['better'] ?? 'neutral')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p class="fuel-compare-hint">Variația este calculată pentru <?= e($compareLabelA) ?> față de <?= e($compareLabelB) ?>.</p>
                        </article>

                        <article class="fuel-card fuel-chart-card">
                            <div class="fuel-card-header">
                                <h2>Consum mediu Motorină (L/100 km)</h2>
                            </div>
                            <?= $renderCompareChart((array) ($comparison['chart_a'] ?? []), (array) ($comparison['chart_b'] ?? []), (string) ($compare['mode'] ?? 'periods') === 'periods') ?>
                            <div class="fuel-chart-legend">
                                <span><i class="legend-line"></i> <?= e($compareLabelA) ?></span>
                                <span><i class="legend-line legend-line-b"></i> <?= e($compareLabelB) ?></span>
                            </div>
                        </article>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tab-pane fade" id="fuel-kmcheck" role="tabpanel" aria-labelledby="fuel-kmcheck-tab" tabindex="0">
                <article class="fuel-card">
                    <div class="fuel-card-header">
                        <div>
                            <h2>Verificare km și litri cu GPS / CAN</h2>
                            <ul class="fuel-kmcheck-howto">
                                <li><strong>Km:</strong> cât arată odometrul tastat de șofer că a mers camionul de la alimentarea precedentă, față de cât a înregistrat GPS-ul în același interval (între orele celor două bonuri).</li>
                                <li><strong>Litri:</strong> litrii de pe bon, față de cât a crescut nivelul în rezervor (sonda) la alimentare.</li>
                                <li><strong>Diferență</strong> = declarat minus măsurat. Minus = șoferul a declarat mai puțini km / pe bon sunt mai puțini litri decât au intrat. Verde = în toleranță, galben = de urmărit, roșu = de verificat.</li>
                            </ul>
                        </div>
                        <div class="fuel-kmcheck-tools">
                            <label class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" data-kmcheck-only-issues>
                                <span class="form-check-label">Doar diferențe</span>
                            </label>
                        </div>
                    </div>
                    <details class="fuel-kmcheck-calib">
                        <summary>
                            <i class="bi bi-speedometer2" aria-hidden="true"></i>
                            Opțional: odometru GPS absolut
                            <span class="fuel-kmcheck-muted">— nu e necesar pentru verificarea de mai jos (<?= e((string) count($kmCalibrations ?? [])) ?> citiri de pe bord)</span>
                        </summary>
                        <p class="fuel-kmcheck-sub">
                            Introdu km de pe bord citiți sigur (poză cu bordul, service, ITP, tahograf) și ora citirii. Odometrul GPS la fiecare alimentare = această citire + km GPS parcurși de atunci.
                            Se folosește cea mai recentă citire dinaintea alimentării; o citire nouă (ex. lunar) ține eroarea GPS mică.
                        </p>
                        <?php if ($canManageFull): ?>
                            <form method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'km_calibration_save'])) ?>" class="fuel-kmcheck-calib-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                                <label>Vehicul
                                    <select name="vehicle_registration" class="form-select form-select-sm" required>
                                        <option value="">Alege…</option>
                                        <?php foreach ($vehicleOptions as $vehicle): ?>
                                            <?php $calibPlate = (string) ($vehicle['vehicle_registration'] ?? ''); ?>
                                            <?php if ($calibPlate !== ''): ?>
                                                <option value="<?= e($calibPlate) ?>"><?= e($calibPlate) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Km pe bord
                                    <input type="text" name="reading_km" class="form-control form-control-sm" inputmode="numeric" placeholder="ex. 1337545" required>
                                </label>
                                <label>Data și ora citirii
                                    <input type="datetime-local" name="reading_datetime" class="form-control form-control-sm" max="<?= e(date('Y-m-d\TH:i')) ?>" required>
                                </label>
                                <label>Sursa
                                    <select name="source" class="form-select form-select-sm" required>
                                        <?php foreach (($kmCalibrationSources ?? []) as $sourceKey => $sourceLabel): ?>
                                            <option value="<?= e($sourceKey) ?>"><?= e($sourceLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="fuel-kmcheck-calib-note">Notă
                                    <input type="text" name="note" class="form-control form-control-sm" maxlength="255" placeholder="opțional">
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă citirea</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($kmCalibrations)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm fuel-kmcheck-calib-table">
                                    <thead><tr><th>Vehicul</th><th class="text-end">Km pe bord</th><th>Data citirii</th><th>Sursa</th><th>Notă</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($kmCalibrations as $calibration): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= e((string) $calibration['vehicle_registration']) ?></td>
                                                <td class="text-end"><?= e(format_number_ro((float) $calibration['reading_km'], 0)) ?></td>
                                                <td><?= e(date('d.m.Y H:i', strtotime((string) $calibration['reading_datetime']))) ?></td>
                                                <td><?= e((string) (($kmCalibrationSources ?? [])[$calibration['source']] ?? $calibration['source'])) ?></td>
                                                <td><?= e((string) ($calibration['note'] ?? '')) ?></td>
                                                <td class="text-end">
                                                    <?php if ($canManageFull): ?>
                                                        <form method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'km_calibration_delete'])) ?>">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                                                            <input type="hidden" name="calibration_id" value="<?= e((string) (int) $calibration['id']) ?>">
                                                            <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Șterge citirea"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </details>
                    <div class="fuel-kmcheck-summary" data-kmcheck-summary></div>
                    <div class="table-responsive">
                        <table class="table fuel-table fuel-kmcheck-table">
                            <thead data-kmcheck-head></thead>
                            <tbody data-kmcheck-body></tbody>
                        </table>
                    </div>
                    <script type="application/json" data-kmcheck-config><?= json_encode([
                        'endpoint' => build_query_url(['page' => 'carburanti', 'action' => 'km_check_fetch']),
                        'rows' => $kmCheckRows ?? [],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
                </article>
            </section>

            <section class="tab-pane fade" id="fuel-reports" role="tabpanel" aria-labelledby="fuel-reports-tab" tabindex="0">
                <article class="fuel-card">
                    <div class="fuel-card-header"><h2>Rapoarte și sincronizări</h2></div>
                    <div class="table-responsive">
                        <table class="table fuel-table">
                            <thead>
                                <tr>
                                    <th>Start</th>
                                    <th>Final</th>
                                    <th>Perioadă API</th>
                                    <th>Status</th>
                                    <th>Primite</th>
                                    <th>Inserate</th>
                                    <th>Actualizate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($syncLogs === []): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">Nu exista sincronizari inregistrate.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($syncLogs as $log): ?>
                                    <tr>
                                        <td><?= e($formatDateTime((string) ($log['sync_started_at'] ?? ''))) ?></td>
                                        <td><?= e($formatDateTime((string) ($log['sync_finished_at'] ?? ''))) ?></td>
                                        <td><?= e(format_date_ro((string) ($log['date_from'] ?? ''))) ?> - <?= e(format_date_ro((string) ($log['date_to'] ?? ''))) ?></td>
                                        <td><span class="fuel-pill fuel-pill-auto"><?= e((string) ($log['status'] ?? '-')) ?></span></td>
                                        <td><?= e((string) ((int) ($log['records_received'] ?? 0))) ?></td>
                                        <td><?= e((string) ((int) ($log['records_inserted'] ?? 0))) ?></td>
                                        <td><?= e((string) ((int) ($log['records_updated'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="tab-pane fade" id="fuel-unassociated" role="tabpanel" aria-labelledby="fuel-unassociated-tab" tabindex="0">
                <article class="fuel-card">
                    <div class="fuel-card-header"><h2>Alimentări neasociate</h2></div>
                    <div class="table-responsive">
                        <table class="table fuel-table fuel-table-compact">
                            <thead>
                                <tr>
                                    <th>Data și ora</th>
                                    <th>Vehicul</th>
                                    <th>Tip carburant</th>
                                    <th>Cantitate</th>
                                    <th>Valoare</th>
                                    <th>Acțiuni</th>
                                </tr>
                            </thead>
                            <tbody><?php $renderFillupRows($unassociatedFillups, true); ?></tbody>
                        </table>
                    </div>
                </article>
            </section>
        </div>
    </div>

    <div class="fuel-page-footer">
        <span>
            Datele sunt preluate din API CardOil Avantaj.
            <?php if (is_array($lastSync)): ?>
                Ultima actualizare: <?= e($formatDateTime((string) ($lastSync['sync_finished_at'] ?? $lastSync['sync_started_at'] ?? ''))) ?>
            <?php endif; ?>
        </span>
        <form method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'sync_now'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                Sincronizează acum
            </button>
        </form>
    </div>
</div>

<?php if ($canManageFull && $t0Vehicle !== '' && $t0MonthStart !== ''): ?>
<div class="modal fade" id="fuelT0Modal" tabindex="-1" aria-labelledby="fuelT0ModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'set_t0'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <input type="hidden" name="vehicle" value="<?= e($t0Vehicle) ?>">
            <input type="hidden" name="month_start" value="<?= e($t0MonthStart) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelT0ModalTitle">Setează T0 manual — <?= e($t0Vehicle) ?> · <?= e($t0MonthLabel) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="fuel-modal-subtitle">
                    Fereastra automată ±4 zile este <strong><?= e($t0WindowLabel) ?></strong>.
                    Alimentările din fereastră sunt marcate cu <span class="fuel-pill fuel-pill-auto">în fereastră</span>.
                    Poți alege și o alimentare din afara ferestrei — decizia rămâne înregistrată ca manuală.
                </p>
                <?php if ($t0Candidates === []): ?>
                    <p class="text-muted mb-0">Nu există alimentări de motorină pentru acest vehicul în intervalul afișabil.</p>
                <?php else: ?>
                    <div class="fuel-table-wrap">
                        <table class="fuel-table fuel-t0-table">
                            <thead>
                                <tr>
                                    <th scope="col"><span class="visually-hidden">Selectează</span></th>
                                    <th scope="col">Data / ora</th>
                                    <th scope="col">Vehicul</th>
                                    <th scope="col">Litri</th>
                                    <th scope="col">Odometru</th>
                                    <th scope="col">Carburant</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Sursa</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($t0Candidates as $candidate): ?>
                                    <?php
                                    $candidateId = (int) ($candidate['id'] ?? 0);
                                    $candidateIsFull = !empty($candidate['is_full']);
                                    $candidateOdo = (int) ($candidate['odometer_km'] ?? 0);
                                    $candidateSource = (string) ($candidate['full_source'] ?? 'api');
                                    ?>
                                    <tr class="<?= !empty($candidate['in_t0_window']) ? 'is-in-window' : '' ?>">
                                        <td>
                                            <input class="form-check-input" type="radio" name="fillup_id"
                                                   id="fuelT0Pick<?= e((string) $candidateId) ?>"
                                                   value="<?= e((string) $candidateId) ?>"
                                                   data-is-full="<?= $candidateIsFull ? '1' : '0' ?>" required>
                                        </td>
                                        <td>
                                            <label class="mb-0" for="fuelT0Pick<?= e((string) $candidateId) ?>">
                                                <?= e($formatDateTime((string) ($candidate['fillup_datetime'] ?? ''))) ?>
                                            </label>
                                            <?php if (!empty($candidate['in_t0_window'])): ?>
                                                <span class="fuel-pill fuel-pill-auto">în fereastră</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e((string) ($candidate['vehicle_registration'] ?? '-')) ?></td>
                                        <td><?= e($formatLiters((float) ($candidate['quantity_liters'] ?? 0))) ?></td>
                                        <td>
                                            <?= e($formatKm((float) $candidateOdo)) ?>
                                            <?php if ($candidateOdo <= 0): ?>
                                                <span class="fuel-pill fuel-pill-warning">fără odometru</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e($fuelTypeLabel((string) ($candidate['fuel_type'] ?? ''))) ?></td>
                                        <td>
                                            <span class="fuel-pill <?= $candidateIsFull ? 'fuel-pill-full' : 'fuel-pill-partial' ?>">
                                                <?= $candidateIsFull ? 'Full' : 'Partial' ?>
                                            </span>
                                        </td>
                                        <td><span class="fuel-pill <?= $candidateSource === 'manual' ? 'fuel-pill-manual' : 'fuel-pill-auto' ?>"><?= e($candidateSource === 'manual' ? 'manual' : 'API') ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="fuel-t0-confirm" id="fuelT0Confirm" hidden>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="fuelT0ConfirmFull" name="confirm_mark_full">
                            <label class="form-check-label" for="fuelT0ConfirmFull">
                                Alimentarea selectată este <strong>Parțială</strong>. Confirm transformarea ei în FULL pentru a o folosi ca T0.
                            </label>
                        </div>
                    </div>

                    <label class="form-label mt-3" for="fuelT0Note">Notă (opțional)</label>
                    <input type="text" class="form-control" id="fuelT0Note" name="note" maxlength="255"
                           placeholder="Motivul setării manuale">
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                <button type="submit" class="btn btn-primary" <?= $t0Candidates === [] ? 'disabled' : '' ?>>Stabilește T0</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageFull): ?>
<div class="modal fade" id="fuelAddManualModal" tabindex="-1" aria-labelledby="fuelAddManualModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'add_manual'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelAddManualModalTitle">Adaugă alimentare manuală</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="fuel-modal-subtitle">
                    Pentru alimentări plătite în afara cardului CardOil (numerar, card personal etc.).
                    Rândul primește o etichetă cu modul de plată, este protejat la sincronizări
                    și nu influențează monitorizarea tarifelor CardOil.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="fuelManualVehicle">Vehicul *</label>
                        <select class="form-select" id="fuelManualVehicle" name="vehicle_registration" required>
                            <option value="">Selectează vehiculul</option>
                            <?php foreach ($vehicleOptions as $vehicleOption): ?>
                                <?php $vehicleValue = (string) ($vehicleOption['vehicle_registration'] ?? ''); ?>
                                <option value="<?= e($vehicleValue) ?>"><?= e($vehicleValue) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="fuelManualDatetime">Data și ora *</label>
                        <input type="datetime-local" class="form-control" id="fuelManualDatetime" name="fillup_datetime" value="<?= e(date('Y-m-d\TH:i')) ?>" max="<?= e(date('Y-m-d\TH:i')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="fuelManualFuelType">Tip carburant *</label>
                        <select class="form-select" id="fuelManualFuelType" name="fuel_type" required>
                            <option value="motorina">Motorină</option>
                            <option value="adblue">AdBlue</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="fuelManualQuantity">Cantitate (L) *</label>
                        <input type="text" class="form-control" id="fuelManualQuantity" name="quantity_liters" inputmode="decimal" placeholder="ex. 10,5" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="fuelManualValue">Valoare totală (lei, cu TVA) *</label>
                        <input type="text" class="form-control" id="fuelManualValue" name="total_value" inputmode="decimal" placeholder="ex. 52,50" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="fuelManualPayment">Mod de plată *</label>
                        <select class="form-select" id="fuelManualPayment" name="payment_method" required>
                            <option value="numerar">Numerar</option>
                            <option value="card_personal">Card personal (șofer)</option>
                            <option value="altul">Altul</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="fuelManualOdometer">Odometru (km)</label>
                        <input type="text" class="form-control" id="fuelManualOdometer" name="odometer_km" inputmode="numeric" placeholder="opțional">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="fuelManualStation">Stație</label>
                        <input type="text" class="form-control" id="fuelManualStation" name="station_name" placeholder="implicit: după modul de plată">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="fuelManualDriver">Șofer</label>
                        <input type="text" class="form-control" id="fuelManualDriver" name="driver_name" placeholder="opțional">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="fuelManualNote">Notă (nr. bon, motiv)</label>
                        <input type="text" class="form-control" id="fuelManualNote" name="note" placeholder="ex. bon fiscal 123 / card refuzat">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="fuelManualIsFull" name="is_full" value="1">
                            <label class="form-check-label" for="fuelManualIsFull">Alimentare Full (rezervor plin)</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="fuelManualReceipt">Bon fiscal (poză sau PDF)</label>
                        <input type="file" class="form-control" id="fuelManualReceipt" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp,application/pdf">
                        <small class="text-muted">Opțional, maximum 5 MB. Bonul se poate deschide ulterior din tabelul de alimentări.</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Adaugă alimentarea
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="fuelSyncVehiclesModal" tabindex="-1" aria-labelledby="fuelSyncVehiclesModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'vehicle_selection'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelSyncVehiclesModalTitle">Vehicule sincronizate din CardOil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">
                    Se importa alimentarile doar pentru vehiculele bifate. Pentru cele debifate, sincronizarea
                    (manuala sau automata) ignora alimentarile venite din CardOil. Lista este comuna pentru toti utilizatorii;
                    alimentarile deja importate si cele adaugate manual raman neschimbate.
                </p>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <input type="search" class="form-control form-control-sm" id="fuelSyncSearch" style="max-width: 240px;"
                           placeholder="Cauta numar..." autocomplete="off">
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" data-fuel-sync-all="1">Toate</button>
                        <button type="button" class="btn btn-outline-secondary" data-fuel-sync-all="0">Niciunul</button>
                    </div>
                    <span class="ms-auto small fw-semibold" id="fuelSyncCount"></span>
                </div>
                <div id="fuelSyncList">
                    <?php if ($fuelSyncGroups === []): ?>
                        <div class="text-muted small">Nu exista vehicule.</div>
                    <?php endif; ?>
                    <?php foreach ($fuelSyncGroups as $groupName => $groupVehicles): ?>
                        <div class="fuel-sync-group border rounded mb-2">
                            <label class="d-flex align-items-center gap-2 fw-semibold small px-2 py-1 m-0 bg-body-tertiary" style="cursor:pointer;">
                                <input type="checkbox" class="form-check-input m-0 fuel-sync-grp">
                                <?= e($groupName) ?> <span class="text-muted fw-normal">(<?= count($groupVehicles) ?>)</span>
                            </label>
                            <div class="p-2" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:.2rem .8rem;">
                                <?php foreach ($groupVehicles as $vehicle): ?>
                                    <label class="form-check small m-0 fuel-sync-item" data-search="<?= e(strtolower($vehicle['registration'] . ' ' . $vehicle['label'])) ?>"
                                           title="<?= e($vehicle['label']) ?>">
                                        <input type="hidden" name="all[]" value="<?= e($vehicle['registration']) ?>">
                                        <input type="checkbox" class="form-check-input fuel-sync-car" name="included[]"
                                               value="<?= e($vehicle['registration']) ?>" <?= $vehicle['excluded'] ? '' : 'checked' ?>>
                                        <span class="form-check-label"><?= e($vehicle['registration']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($fuelSyncExcludedCount > 0): ?>
                    <button type="button" class="btn btn-outline-danger me-auto" data-bs-toggle="modal" data-bs-target="#fuelPurgeModal">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                        Șterge datele deja importate…
                    </button>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                <button type="submit" class="btn btn-primary">Salvează</button>
            </div>
        </form>
    </div>
</div>

<?php if ($fuelSyncExcludedCount > 0): ?>
<div class="modal fade" id="fuelPurgeModal" tabindex="-1" aria-labelledby="fuelPurgeModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'purge_excluded'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelPurgeModalTitle">Șterge alimentările vehiculelor excluse</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">
                    Se șterg definitiv alimentările venite din CardOil pentru cele <?= (int) $fuelSyncExcludedCount ?> vehicule debifate
                    în „Vehicule sincronizate”, începând cu data aleasă. Dispar și asocierile lor cu cursele și deciziile T0.
                    Alimentările adăugate manual rămân.
                </p>
                <div class="d-flex align-items-end gap-2 mb-3">
                    <div>
                        <label class="form-label small mb-1" for="fuelPurgeFrom">Începând cu</label>
                        <input type="date" class="form-control form-control-sm" id="fuelPurgeFrom" name="date_from" value="2026-07-01" required>
                    </div>
                </div>
                <div id="fuelPurgePreview" class="small text-muted"
                     data-url="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'purge_preview'])) ?>">Se calculează…</div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="confirm" value="1" id="fuelPurgeConfirm" required>
                    <label class="form-check-label small" for="fuelPurgeConfirm">
                        Am verificat lista de mai sus și vreau ștergerea definitivă a acestor alimentări.
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                <button type="submit" class="btn btn-danger" id="fuelPurgeSubmit" disabled>
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    Șterge definitiv
                </button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('fuelPurgeModal');
    var dateInput = document.getElementById('fuelPurgeFrom');
    var preview = document.getElementById('fuelPurgePreview');
    var confirmBox = document.getElementById('fuelPurgeConfirm');
    var submit = document.getElementById('fuelPurgeSubmit');
    var total = 0;
    var fmt = function (n, d) { return Number(n).toLocaleString('ro-RO', { minimumFractionDigits: d, maximumFractionDigits: d }); };
    var esc = function (v) { return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

    function updateSubmit() { submit.disabled = !(confirmBox.checked && total > 0); }

    function load() {
        total = 0;
        confirmBox.checked = false;
        updateSubmit();
        preview.textContent = 'Se calculează…';
        fetch(preview.getAttribute('data-url') + '&date_from=' + encodeURIComponent(dateInput.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { preview.textContent = res.error || 'Eroare.'; return; }
                if (res.rows.length === 0) { preview.textContent = 'Nu există alimentări CardOil de șters pentru vehiculele excluse din această perioadă.'; return; }
                var liters = 0, value = 0;
                var rows = res.rows.map(function (r) {
                    total += r.fillups; liters += r.liters; value += r.total_value;
                    return '<tr><td>' + esc(r.vehicle_registration) + '</td><td class="text-end">' + r.fillups + '</td><td class="text-end">' +
                        fmt(r.liters, 2) + '</td><td class="text-end">' + fmt(r.total_value, 2) + '</td><td>' +
                        esc(r.first_at.substring(0, 10)) + ' → ' + esc(r.last_at.substring(0, 10)) + '</td></tr>';
                }).join('');
                preview.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Vehicul</th><th class="text-end">Alimentări</th>' +
                    '<th class="text-end">Litri</th><th class="text-end">Valoare (lei)</th><th>Perioadă</th></tr></thead><tbody>' + rows +
                    '</tbody><tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end">' + total + '</td><td class="text-end">' +
                    fmt(liters, 2) + '</td><td class="text-end">' + fmt(value, 2) + '</td><td></td></tr></tfoot></table>';
                updateSubmit();
            })
            .catch(function () { preview.textContent = 'Previzualizarea nu a putut fi încărcată.'; });
    }

    modal.addEventListener('show.bs.modal', load);
    dateInput.addEventListener('change', load);
    confirmBox.addEventListener('change', updateSubmit);
})();
</script>
<?php endif; ?>
<script>
(function () {
    var list = document.getElementById('fuelSyncList');
    if (!list) { return; }
    var search = document.getElementById('fuelSyncSearch');
    var count = document.getElementById('fuelSyncCount');

    function sync() {
        list.querySelectorAll('.fuel-sync-group').forEach(function (group) {
            var cars = group.querySelectorAll('.fuel-sync-car');
            var on = group.querySelectorAll('.fuel-sync-car:checked').length;
            var head = group.querySelector('.fuel-sync-grp');
            head.checked = cars.length > 0 && on === cars.length;
            head.indeterminate = on > 0 && on < cars.length;
        });
        count.textContent = list.querySelectorAll('.fuel-sync-car:checked').length + ' din ' +
            list.querySelectorAll('.fuel-sync-car').length + ' sincronizate';
    }

    // Toate / Niciunul si bifa de grup actioneaza doar pe vehiculele vizibile dupa cautare.
    function setVisible(scope, value) {
        scope.querySelectorAll('.fuel-sync-item').forEach(function (item) {
            if (item.style.display !== 'none') { item.querySelector('.fuel-sync-car').checked = value; }
        });
        sync();
    }

    list.addEventListener('change', function (event) {
        if (event.target.classList.contains('fuel-sync-grp')) {
            setVisible(event.target.closest('.fuel-sync-group'), event.target.checked);
            return;
        }
        sync();
    });
    document.querySelectorAll('[data-fuel-sync-all]').forEach(function (btn) {
        btn.addEventListener('click', function () { setVisible(list, btn.getAttribute('data-fuel-sync-all') === '1'); });
    });
    search.addEventListener('input', function () {
        var term = search.value.trim().toLowerCase();
        list.querySelectorAll('.fuel-sync-item').forEach(function (item) {
            item.style.display = term === '' || item.getAttribute('data-search').indexOf(term) !== -1 ? '' : 'none';
        });
        list.querySelectorAll('.fuel-sync-group').forEach(function (group) {
            var any = Array.prototype.some.call(group.querySelectorAll('.fuel-sync-item'), function (i) { return i.style.display !== 'none'; });
            group.style.display = any ? '' : 'none';
        });
    });
    sync();
})();
</script>

<div class="modal fade" id="fuelDeleteManualModal" tabindex="-1" aria-labelledby="fuelDeleteManualModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'delete_manual'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <input type="hidden" name="fillup_id" id="fuelDeleteFillupId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelDeleteManualModalTitle">Șterge alimentarea manuală</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="fuel-modal-subtitle" id="fuelDeleteFillupLabel">Alimentare manuală</p>
                <p class="mb-0 text-muted">Ștergerea este definitivă și elimină și asocierea cu cursa, împreună cu bonul fiscal atașat. Doar alimentările introduse manual pot fi șterse — rândurile din CardOil rămân intacte.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                    Șterge definitiv
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="fuelLinkModal" tabindex="-1" aria-labelledby="fuelLinkModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'link_fillup'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <input type="hidden" name="fillup_id" id="fuelLinkFillupId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelLinkModalTitle">Asociere alimentare</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="fuel-modal-subtitle" id="fuelLinkFillupLabel">Alimentare neasociată</p>
                <label class="form-label" for="fuelLinkTripId">Cursă</label>
                <select class="form-select" id="fuelLinkTripId" name="trip_id" required>
                    <option value="">Selectează cursa</option>
                    <?php foreach ($tripOptions as $trip): ?>
                        <?php
                        $tripId = (int) ($trip['id'] ?? 0);
                        $tripLabel = '#' . $tripId . ' - ' . (string) ($trip['nr_inmatriculare'] ?? '-')
                            . ' - ' . $transportLabel((string) ($trip['tip_transport'] ?? ''))
                            . ' - ' . format_date_ro((string) ($trip['data_inceput'] ?? ''));
                        ?>
                        <option value="<?= e((string) $tripId) ?>"><?= e($tripLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                <button type="submit" class="btn btn-primary">Asociază</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="fuelOdoModal" tabindex="-1" aria-labelledby="fuelOdoModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'carburanti', 'action' => 'set_odometer'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
            <input type="hidden" name="fillup_id" id="fuelOdoFillupId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="fuelOdoModalTitle">Corectează odometrul</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <p class="fuel-modal-subtitle" id="fuelOdoFillupLabel">Alimentare</p>
                <label class="form-label" for="fuelOdoValue">Kilometraj corect (km bord)</label>
                <input type="number" class="form-control" id="fuelOdoValue" name="odometer_km" min="1" max="5000000" step="1" required>
                <small class="text-muted d-block mt-2">
                    Corecția este o decizie manuală: rămâne valabilă la sincronizările CardOil.
                    Folosește km reali din bord / foaia de parcurs.
                </small>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-outline-secondary me-auto" name="reset_api" value="1"
                        id="fuelOdoResetBtn" formnovalidate hidden>
                    Revino la valoarea API
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                <button type="submit" class="btn btn-primary">Salvează corecția</button>
            </div>
        </form>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/ro.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // -----------------------------------------------------------------
    // Filtre cu auto-aplicare: orice schimbare retrimite formularul,
    // fara sa mai fie nevoie de butonul "Actualizeaza".
    // -----------------------------------------------------------------
    var filterForm = document.querySelector('.fuel-filter-card');
    var submitFilters = function () {
        if (!filterForm) {
            return;
        }
        // Feedback vizual pana la reincarcarea paginii.
        filterForm.classList.add('is-reloading');
        if (filterForm.requestSubmit) {
            filterForm.requestSubmit();
        } else {
            filterForm.submit();
        }
    };

    // Un singur camp de perioada: calendar de interval (flatpickr) cu scurtaturi
    // de luna. Selectarea completa a intervalului aplica filtrul imediat.
    var periodDisplay = document.getElementById('fuel_period_display');
    var dateFromInput = document.getElementById('fuel_date_from');
    var dateToInput = document.getElementById('fuel_date_to');
    if (filterForm && periodDisplay && dateFromInput && dateToInput) {
        var MONTHS_RO = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie',
            'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
        var toIso = function (d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        };
        var toRo = function (d) {
            return String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
        };
        var prettyLabel = function (start, end) {
            var lastDay = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
            if (start.getDate() === 1
                && end.getFullYear() === start.getFullYear()
                && end.getMonth() === start.getMonth()
                && end.getDate() === lastDay) {
                return MONTHS_RO[start.getMonth()] + ' ' + start.getFullYear();
            }
            return toRo(start) + ' – ' + toRo(end);
        };
        var applyRange = function (start, end) {
            var newFrom = toIso(start);
            var newTo = toIso(end);
            if (newFrom === dateFromInput.value && newTo === dateToInput.value) {
                return; // deschis si inchis fara schimbare: nu reincarca
            }
            dateFromInput.value = newFrom;
            dateToInput.value = newTo;
            periodDisplay.value = prettyLabel(start, end);
            submitFilters();
        };

        if (window.flatpickr) {
            var initialDates = [];
            if (dateFromInput.value && dateToInput.value) {
                initialDates = [dateFromInput.value, dateToInput.value];
            }
            flatpickr(periodDisplay, {
                mode: 'range',
                locale: window.flatpickr.l10ns && window.flatpickr.l10ns.ro ? 'ro' : 'default',
                dateFormat: 'Y-m-d',
                defaultDate: initialDates,
                onReady: function (selectedDates, dateStr, fp) {
                    // Scurtaturi: luna aceasta / luna trecuta, direct in calendar.
                    var presets = document.createElement('div');
                    presets.className = 'fuel-fp-presets';
                    [['Luna aceasta', 0], ['Luna trecută', 1]].forEach(function (preset) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = preset[0];
                        button.addEventListener('click', function () {
                            var now = new Date();
                            var start = new Date(now.getFullYear(), now.getMonth() - preset[1], 1);
                            var end = new Date(now.getFullYear(), now.getMonth() - preset[1] + 1, 0);
                            fp.setDate([start, end], false);
                            fp.close();
                        });
                        presets.appendChild(button);
                    });
                    fp.calendarContainer.appendChild(presets);
                },
                onClose: function (selectedDates) {
                    if (selectedDates.length === 2) {
                        applyRange(selectedDates[0], selectedDates[1]);
                    } else {
                        // selectie incompleta: revino la eticheta valorii curente
                        periodDisplay.value = periodDisplay.defaultValue;
                    }
                }
            });
            // flatpickr isi scrie propriul format la initializare; readucem
            // eticheta prietenoasa generata de server ("Iulie 2026").
            periodDisplay.value = periodDisplay.defaultValue;
        } else {
            // Fallback fara CDN: cele doua campuri devin calendare native vizibile.
            periodDisplay.closest('.fuel-date-input').hidden = true;
            [dateFromInput, dateToInput].forEach(function (input) {
                input.type = 'date';
                input.classList.add('form-control', 'mt-1');
                input.addEventListener('change', function () {
                    if (dateFromInput.value && dateToInput.value && dateFromInput.value <= dateToInput.value) {
                        submitFilters();
                    }
                });
            });
        }
    }

    ['fuel_transport_group', 'fuel_type'].forEach(function (id) {
        var select = document.getElementById(id);
        if (select) {
            select.addEventListener('change', submitFilters);
        }
    });

    // Filtrul de marca: pastreaza doar vehiculele bifate care apartin marcii
    // alese (restul selectiei ar produce un rezultat gol), apoi aplica.
    var brandSelect = document.getElementById('fuel_brand');
    if (brandSelect) {
        brandSelect.addEventListener('change', function () {
            var brand = brandSelect.value.trim().toUpperCase();
            if (brand !== '') {
                document.querySelectorAll('#fuelVehicleMultiselect .fuel-vehicle-option').forEach(function (option) {
                    var checkbox = option.querySelector('input[type="checkbox"]');
                    if (checkbox && checkbox.checked && (option.getAttribute('data-brand') || '') !== brand) {
                        checkbox.checked = false;
                    }
                });
            }
            submitFilters();
        });
    }

    var vehicleMultiselect = document.getElementById('fuelVehicleMultiselect');
    if (vehicleMultiselect) {
        var toggleButton = vehicleMultiselect.querySelector('[data-bs-toggle="dropdown"]');
        var checkboxes = vehicleMultiselect.querySelectorAll('input[type="checkbox"][name="vehicles[]"]');
        var searchInput = vehicleMultiselect.querySelector('[data-vehicle-search]');
        var clearButton = vehicleMultiselect.querySelector('[data-vehicle-clear]');

        var updateVehicleLabel = function () {
            var selected = [];
            checkboxes.forEach(function (checkbox) {
                if (checkbox.checked) {
                    selected.push(checkbox.value);
                }
            });
            if (selected.length === 0) {
                toggleButton.textContent = 'Toate vehiculele';
            } else if (selected.length === 1) {
                toggleButton.textContent = selected[0];
            } else {
                toggleButton.textContent = selected.length + ' vehicule selectate';
            }
        };

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', updateVehicleLabel);
        });

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                checkboxes.forEach(function (checkbox) { checkbox.checked = false; });
                updateVehicleLabel();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var term = searchInput.value.trim().toUpperCase().replace(/\s+/g, '');
                var menu = vehicleMultiselect.querySelector('.fuel-vehicle-menu');
                var totalVisible = 0;
                // In timpul cautarii, grupele restranse isi arata optiunile
                // potrivite; grupele fara potriviri se ascund complet.
                if (menu) {
                    menu.classList.toggle('is-searching', term !== '');
                }
                vehicleMultiselect.querySelectorAll('[data-vehicle-group]').forEach(function (group) {
                    var groupVisible = 0;
                    group.querySelectorAll('.fuel-vehicle-option').forEach(function (option) {
                        var text = (option.textContent || '').trim().toUpperCase().replace(/\s+/g, '');
                        var visible = term === '' || text.indexOf(term) !== -1;
                        option.hidden = !visible;
                        if (visible) {
                            groupVisible += 1;
                        }
                    });
                    group.hidden = groupVisible === 0;
                    totalVisible += groupVisible;
                });
                var emptyMessage = vehicleMultiselect.querySelector('.fuel-vehicle-menu-empty');
                if (emptyMessage) {
                    emptyMessage.hidden = totalVisible > 0;
                }
            });
        }

        // Grupele de capacitate: bifarea grupului (selecteaza tot / nimic),
        // starea indeterminate si plierea la click pe antet.
        var refreshGroupToggle = function (group) {
            if (!group) {
                return;
            }
            var toggle = group.querySelector('[data-vehicle-group-toggle]');
            if (!toggle) {
                return;
            }
            var options = Array.prototype.slice.call(group.querySelectorAll('.fuel-vehicle-option input[type="checkbox"]'));
            var checkedCount = options.filter(function (input) { return input.checked; }).length;
            toggle.checked = options.length > 0 && checkedCount === options.length;
            toggle.indeterminate = checkedCount > 0 && checkedCount < options.length;
        };

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                vehicleMultiselect.querySelectorAll('[data-vehicle-group]').forEach(refreshGroupToggle);
            });
        }

        vehicleMultiselect.querySelectorAll('[data-vehicle-group]').forEach(function (group) {
            refreshGroupToggle(group);

            var head = group.querySelector('[data-vehicle-group-head]');
            if (head) {
                head.addEventListener('click', function (event) {
                    if (event.target instanceof HTMLInputElement) {
                        return; // click pe checkbox-ul de grup, nu pe antet
                    }
                    group.classList.toggle('is-collapsed');
                });
            }

            var toggle = group.querySelector('[data-vehicle-group-toggle]');
            if (toggle) {
                toggle.addEventListener('change', function () {
                    var shouldCheck = toggle.checked;
                    group.querySelectorAll('.fuel-vehicle-option input[type="checkbox"]').forEach(function (input) {
                        if (!input.closest('[hidden]') && input.checked !== shouldCheck) {
                            input.checked = shouldCheck;
                        }
                    });
                    toggle.indeterminate = false;
                    updateVehicleLabel();
                });
            }

            group.querySelectorAll('.fuel-vehicle-option input[type="checkbox"]').forEach(function (input) {
                input.addEventListener('change', function () {
                    refreshGroupToggle(group);
                });
            });
        });

        // Auto-aplicare la inchiderea dropdown-ului, doar daca selectia s-a
        // schimbat. Bifarea mai multor vehicule nu declanseaza reincarcari
        // intermediare - filtrul pleaca o singura data, la inchidere.
        var vehicleSelectionSnapshot = '';
        var currentVehicleSelection = function () {
            var selected = [];
            checkboxes.forEach(function (checkbox) {
                if (checkbox.checked) {
                    selected.push(checkbox.value);
                }
            });
            return selected.join('|');
        };
        vehicleMultiselect.addEventListener('show.bs.dropdown', function () {
            vehicleSelectionSnapshot = currentVehicleSelection();
        });
        vehicleMultiselect.addEventListener('hidden.bs.dropdown', function () {
            if (currentVehicleSelection() !== vehicleSelectionSnapshot) {
                submitFilters();
            }
        });
    }

    var compareModeSelect = document.getElementById('fuel_compare_mode');
    if (compareModeSelect) {
        var syncCompareMode = function () {
            document.querySelectorAll('[data-compare-mode]').forEach(function (field) {
                var active = field.getAttribute('data-compare-mode') === compareModeSelect.value;
                field.hidden = !active;
                field.querySelectorAll('input, select').forEach(function (control) {
                    control.disabled = !active;
                });
            });
        };
        compareModeSelect.addEventListener('change', syncCompareMode);
        syncCompareMode();
    }

    // Perioadele A/B din comparatie: acelasi calendar de interval ca filtrul
    // principal; selectia completa trimite direct formularul de comparatie.
    var compareForm = document.getElementById('fuelCompareForm');
    if (compareForm && window.flatpickr) {
        ['fuel_compare_period_a', 'fuel_compare_period_b'].forEach(function (id) {
            var input = document.getElementById(id);
            if (!input) {
                return;
            }
            var pad = function (n) { return String(n).padStart(2, '0'); };
            var toRoDate = function (d) { return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear(); };
            var initial = [];
            if (input.getAttribute('data-range-from') && input.getAttribute('data-range-to')) {
                initial = [input.getAttribute('data-range-from'), input.getAttribute('data-range-to')];
            }
            flatpickr(input, {
                mode: 'range',
                locale: window.flatpickr.l10ns && window.flatpickr.l10ns.ro ? 'ro' : 'default',
                dateFormat: 'Y-m-d',
                defaultDate: initial,
                onClose: function (selectedDates) {
                    if (selectedDates.length === 2) {
                        // formatul asteptat de server: "zz.ll.aaaa - zz.ll.aaaa"
                        input.value = toRoDate(selectedDates[0]) + ' - ' + toRoDate(selectedDates[1]);
                        if (compareForm.requestSubmit) {
                            compareForm.requestSubmit();
                        } else {
                            compareForm.submit();
                        }
                    } else {
                        input.value = input.defaultValue;
                    }
                }
            });
            input.value = input.defaultValue;
        });
    }

    var vehicleTable = document.getElementById('fuelVehicleTable');
    if (vehicleTable) {
        var sortState = { index: -1, dir: 1 };
        vehicleTable.querySelectorAll('thead th[data-sort]').forEach(function (th, index) {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function () {
                var tbody = vehicleTable.querySelector('tbody');
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr')).filter(function (row) {
                    return row.children.length > 1;
                });
                if (rows.length === 0) {
                    return;
                }

                sortState.dir = sortState.index === index ? -sortState.dir : 1;
                sortState.index = index;
                var numeric = th.getAttribute('data-sort') === 'num';

                rows.sort(function (a, b) {
                    var cellA = a.children[index];
                    var cellB = b.children[index];
                    if (numeric) {
                        var valA = parseFloat(cellA.getAttribute('data-value') || '0') || 0;
                        var valB = parseFloat(cellB.getAttribute('data-value') || '0') || 0;
                        return (valA - valB) * sortState.dir;
                    }
                    return cellA.textContent.trim().localeCompare(cellB.textContent.trim(), 'ro') * sortState.dir;
                });

                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    }

    // Linkurile din carduri stau in afara #fuelTabs, iar Bootstrap Tab nu functioneaza
    // pe declansatoare din afara listei de taburi: activam butonul real al tabului.
    document.querySelectorAll('[data-fuel-tab-link]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var tabButton = document.getElementById(link.getAttribute('data-fuel-tab-link'));
            if (!tabButton || !window.bootstrap) {
                return;
            }
            event.preventDefault();
            bootstrap.Tab.getOrCreateInstance(tabButton).show();
            tabButton.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    if (window.bootstrap && new URLSearchParams(window.location.search).has('compare_mode')) {
        var compareTabButton = document.getElementById('fuel-compare-tab');
        if (compareTabButton) {
            bootstrap.Tab.getOrCreateInstance(compareTabButton).show();
        }
    }

    // T0 manual: cere confirmare explicita cand alimentarea aleasa nu e FULL.
    // Nicio alimentare partiala nu devine FULL in tacere.
    var t0Confirm = document.getElementById('fuelT0Confirm');
    if (t0Confirm) {
        var t0ConfirmBox = document.getElementById('fuelT0ConfirmFull');
        document.querySelectorAll('#fuelT0Modal input[name="fillup_id"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var needsConfirm = radio.checked && radio.getAttribute('data-is-full') !== '1';
                t0Confirm.hidden = !needsConfirm;
                if (t0ConfirmBox) {
                    t0ConfirmBox.required = needsConfirm;
                    if (!needsConfirm) {
                        t0ConfirmBox.checked = false;
                    }
                }
            });
        });
    }

    var modalElement = document.getElementById('fuelLinkModal');
    var fillupIdInput = document.getElementById('fuelLinkFillupId');
    var fillupLabel = document.getElementById('fuelLinkFillupLabel');
    if (!modalElement || !fillupIdInput || !fillupLabel || !window.bootstrap) {
        return;
    }

    var modal = new bootstrap.Modal(modalElement);
    document.querySelectorAll('[data-fuel-link-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            fillupIdInput.value = button.getAttribute('data-fillup-id') || '';
            fillupLabel.textContent = button.getAttribute('data-fillup-label') || 'Alimentare neasociată';
            modal.show();
        });
    });

    // Modalul de corectie a odometrului.
    var odoModalElement = document.getElementById('fuelOdoModal');
    if (odoModalElement && window.bootstrap) {
        var odoModal = new bootstrap.Modal(odoModalElement);
        var odoFillupId = document.getElementById('fuelOdoFillupId');
        var odoLabel = document.getElementById('fuelOdoFillupLabel');
        var odoValue = document.getElementById('fuelOdoValue');
        var odoResetBtn = document.getElementById('fuelOdoResetBtn');
        document.querySelectorAll('[data-fuel-odo-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                odoFillupId.value = button.getAttribute('data-fillup-id') || '';
                odoLabel.textContent = button.getAttribute('data-fillup-label') || 'Alimentare';
                var current = parseInt(button.getAttribute('data-odo-value') || '0', 10);
                odoValue.value = current > 0 ? current : '';
                // Butonul de revenire la API apare doar cand exista o corectie manuala.
                odoResetBtn.hidden = button.getAttribute('data-odo-manual') !== '1';
                odoModal.show();
            });
        });
    }

    // Modalul de confirmare pentru stergerea alimentarilor manuale.
    // (window.confirm este blocat in browserul embedded, deci folosim modal.)
    var deleteModalElement = document.getElementById('fuelDeleteManualModal');
    if (deleteModalElement && window.bootstrap) {
        var deleteModal = new bootstrap.Modal(deleteModalElement);
        var deleteFillupId = document.getElementById('fuelDeleteFillupId');
        var deleteFillupLabel = document.getElementById('fuelDeleteFillupLabel');
        document.querySelectorAll('[data-fuel-delete-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                deleteFillupId.value = button.getAttribute('data-fillup-id') || '';
                deleteFillupLabel.textContent = button.getAttribute('data-fillup-label') || 'Alimentare manuală';
                deleteModal.show();
            });
        });
    }
});
</script>

<script>
// Grafic interactiv "Evolutie pret carburant": crosshair, tooltip pe zi,
// navigare cu sageti si sarituri directe la minimul / maximul perioadei.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-fuel-price-chart]').forEach(function (wrap) {
        var dataNode = wrap.querySelector('[data-fuel-price-data]');
        var svg = wrap.querySelector('svg');
        var tooltip = wrap.querySelector('.fuel-price-tooltip');
        if (!dataNode || !svg || !tooltip) {
            return;
        }

        var chart;
        try {
            chart = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            return;
        }
        var points = chart.points || [];
        if (!points.length) {
            return;
        }

        var hoverGroup = svg.querySelector('.fuel-price-hover');
        var crosshair = svg.querySelector('.fuel-price-crosshair');
        var focusDot = svg.querySelector('.fuel-price-focus');
        var panel = wrap.closest('.fuel-price-panel') || wrap;
        var activeIndex = -1;
        // Selectia facuta din butoanele Min/Max ramane fixata pana la urmatoarea
        // interactiune cu graficul (altfel blur-ul de dupa click ar inchide tooltipul).
        var pinned = false;

        function addLine(parent, text, className) {
            if (!text) {
                return;
            }
            var row = document.createElement('div');
            row.className = className;
            row.textContent = text;
            parent.appendChild(row);
        }

        function buildTooltip(point) {
            tooltip.textContent = '';

            var head = document.createElement('div');
            head.className = 'fuel-tip-date';
            head.textContent = point.full || point.label || '';
            if (point.kind) {
                var badge = document.createElement('span');
                badge.className = 'fuel-tip-badge is-' + point.kind;
                badge.textContent = point.kind === 'max' ? 'maxim perioadă' : 'minim perioadă';
                head.appendChild(badge);
            }
            tooltip.appendChild(head);

            addLine(tooltip, point.value, 'fuel-tip-value');
            addLine(tooltip, point.liters, 'fuel-tip-meta');

            if (point.delta) {
                var deltaRow = document.createElement('div');
                deltaRow.className = 'fuel-tip-meta fuel-tip-delta';
                if (point.delta.charAt(0) === '+') {
                    deltaRow.classList.add('is-up');
                } else if (point.delta.charAt(0) === '-') {
                    deltaRow.classList.add('is-down');
                }
                deltaRow.textContent = point.delta;
                tooltip.appendChild(deltaRow);
            }

            addLine(tooltip, point.vsAvg, 'fuel-tip-meta');
        }

        function placeTooltip(point) {
            var svgRect = svg.getBoundingClientRect();
            var wrapRect = wrap.getBoundingClientRect();
            var scale = svgRect.width / (chart.width || 760);
            var x = (svgRect.left - wrapRect.left) + (point.x * scale);
            var y = (svgRect.top - wrapRect.top) + (point.y * scale);

            tooltip.hidden = false;
            var tipWidth = tooltip.offsetWidth;
            var tipHeight = tooltip.offsetHeight;
            var half = tipWidth / 2;
            var clampedX = Math.min(Math.max(x, half + 4), Math.max(half + 4, wrapRect.width - half - 4));

            // Daca punctul e prea sus, tooltipul trece sub linie ca sa nu iasa din card.
            tooltip.classList.toggle('is-below', (y - tipHeight - 16) < 0);
            tooltip.style.left = clampedX + 'px';
            tooltip.style.top = y + 'px';
        }

        function activate(index) {
            if (index < 0 || index >= points.length) {
                return;
            }
            var point = points[index];
            activeIndex = index;

            if (crosshair) {
                crosshair.setAttribute('x1', point.x);
                crosshair.setAttribute('x2', point.x);
            }
            if (focusDot) {
                focusDot.setAttribute('cx', point.x);
                focusDot.setAttribute('cy', point.y);
            }
            if (hoverGroup) {
                hoverGroup.classList.add('is-active');
            }

            buildTooltip(point);
            placeTooltip(point);
        }

        function deactivate(force) {
            if (pinned && force !== true) {
                return;
            }
            pinned = false;
            activeIndex = -1;
            if (hoverGroup) {
                hoverGroup.classList.remove('is-active');
            }
            tooltip.hidden = true;
            tooltip.classList.remove('is-below');
        }

        function nearestIndex(clientX) {
            var svgRect = svg.getBoundingClientRect();
            if (!svgRect.width) {
                return -1;
            }
            var viewX = ((clientX - svgRect.left) / svgRect.width) * (chart.width || 760);
            var best = 0;
            var bestDistance = Infinity;
            for (var i = 0; i < points.length; i++) {
                var distance = Math.abs(points[i].x - viewX);
                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = i;
                }
            }
            return best;
        }

        svg.addEventListener('mousemove', function (event) {
            pinned = false;
            var index = nearestIndex(event.clientX);
            if (index !== activeIndex) {
                activate(index);
            }
        });
        svg.addEventListener('mouseleave', function () {
            deactivate(true);
        });

        svg.addEventListener('touchstart', function (event) {
            if (event.touches.length) {
                pinned = false;
                activate(nearestIndex(event.touches[0].clientX));
            }
        }, { passive: true });
        svg.addEventListener('touchmove', function (event) {
            if (event.touches.length) {
                activate(nearestIndex(event.touches[0].clientX));
            }
        }, { passive: true });

        // Focusul sta pe wrapper (div): Chrome nu focuseaza fiabil elementele SVG la click.
        svg.addEventListener('mousedown', function (event) {
            event.preventDefault();
            wrap.focus({ preventScroll: true });
        });

        wrap.addEventListener('keydown', function (event) {
            var index = activeIndex;
            if (event.key === 'ArrowRight') {
                index = index < 0 ? 0 : Math.min(points.length - 1, index + 1);
            } else if (event.key === 'ArrowLeft') {
                index = index < 0 ? points.length - 1 : Math.max(0, index - 1);
            } else if (event.key === 'Home') {
                index = 0;
            } else if (event.key === 'End') {
                index = points.length - 1;
            } else if (event.key === 'Escape') {
                deactivate(true);
                return;
            } else {
                return;
            }
            event.preventDefault();
            pinned = false;
            activate(index);
        });
        wrap.addEventListener('blur', function () {
            deactivate(false);
        });

        panel.querySelectorAll('[data-fuel-price-jump]').forEach(function (button) {
            button.addEventListener('click', function () {
                var kind = button.getAttribute('data-fuel-price-jump');
                var index = kind === 'max' ? chart.maxIndex : chart.minIndex;
                if (typeof index !== 'number') {
                    return;
                }
                pinned = true;
                activate(index);
                wrap.focus({ preventScroll: true });
            });
        });

        // Un click in afara panoului inchide selectia fixata din butoane.
        document.addEventListener('click', function (event) {
            if (pinned && !panel.contains(event.target)) {
                deactivate(true);
            }
        });

        window.addEventListener('resize', function () {
            if (activeIndex >= 0) {
                placeTooltip(points[activeIndex]);
            }
        });
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/carburanti-compare.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/carburanti-compare.js'))) ?>"></script>
<script src="<?= e(url('assets/js/fuel-km-check.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/fuel-km-check.js'))) ?>"></script>
