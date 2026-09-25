<?php
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summaryByStatus = is_array($summaryByStatus ?? null) ? $summaryByStatus : [];
$summaryTotals = is_array($summaryTotals ?? null) ? $summaryTotals : [];
$expenseTypeTotals = is_array($expenseTypeTotals ?? null) ? $expenseTypeTotals : [];
$refacturareTypeTotals = is_array($refacturareTypeTotals ?? null) ? $refacturareTypeTotals : [];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$drivers = is_array($drivers ?? null) ? $drivers : [];
$beneficiaries = is_array($beneficiaries ?? null) ? $beneficiaries : [];
$locationOptions = is_array($locationOptions ?? null) ? $locationOptions : [];
$search = trim((string) ($search ?? ''));

$transportTypes = is_array($transportTypes ?? null) ? $transportTypes : [
    'primar' => 'Primar',
    'distributie' => 'Distribuție',
    'primar_distributie' => 'P+D',
    'compresor' => 'Compresor',
];
$billingStatuses = is_array($billingStatuses ?? null) ? $billingStatuses : [
    'facturat' => 'Facturat',
    'in_curs_facturare' => 'În curs',
    'nefacturat' => 'Nefacturat',
];

$goodsTypes = [
    'butan' => 'Butan',
    'propan' => 'Propan',
    'autogaz' => 'Autogaz',
];
$expenseTypes = [
    'alte' => 'Alte cheltuieli',
    'diurna' => 'Diurnă',
    'service' => 'Reparații',
    'taxe_drum' => 'Taxe drum',
    'motorina' => 'Motorină',
];
$financialOrder = ['alte', 'diurna', 'service', 'taxe_drum'];
$transportGroupLabels = [
    'primar' => 'Primar',
    'distributie' => 'Distribuție',
    'primar_distributie' => 'P+D',
    'compresor' => 'Compresor',
];
$statusToneClasses = [
    'facturat' => 'is-success',
    'in_curs_facturare' => 'is-pending',
    'nefacturat' => 'is-danger',
];
$summaryMetricDefaults = [
    'total_curse' => 0,
    'total_facturare' => 0.0,
    'total_refacturare_facturata' => 0.0,
    'total_refacturare' => 0.0,
    'total_cheltuieli' => 0.0,
    'expense_count' => 0,
    'refacturare_count' => 0,
    'curse_de_refacturat' => 0,
    'total_km' => 0.0,
    'total_km_facturati' => 0.0,
    'total_km_totali' => 0.0,
    'total_km_dislocare' => 0.0,
    'total_tone_incarcate' => 0.0,
    'total_tone_prelevate' => 0.0,
    'total_tone_livrate' => 0.0,
];

$money = static fn (mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$clean = static fn (mixed $value): string => trim((string) ($value ?? ''));
$show = static fn (mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '-';

$formatKm = static function (mixed $value): string {
    $km = is_numeric((string) $value) ? (float) $value : 0.0;
    return format_number_ro($km, $km === floor($km) ? 0 : 2) . ' km';
};

$formatTons = static function (mixed $value): string {
    $tons = is_numeric((string) $value) ? (float) $value : 0.0;
    return format_number_ro($tons, $tons === floor($tons) ? 0 : 2) . ' t';
};

$formatDate = static function (mixed $value): string {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? format_date_ro($value) : '-';
};

$normalizeTons = static function (mixed $value, mixed $capacity = null): ?float {
    if ($value === null || $value === '' || !is_numeric((string) $value)) {
        return null;
    }

    $amount = (float) $value;
    if ($amount <= 0) {
        return null;
    }

    $capacityValue = is_numeric((string) $capacity) ? (float) $capacity : 0.0;
    if ($capacityValue > 0 && $amount > ($capacityValue * 3)) {
        return $amount / 1000;
    }

    return $amount >= 1000 ? $amount / 1000 : $amount;
};

$rowTons = static function (array $row) use ($normalizeTons): float {
    $transportType = (string) ($row['tip_transport'] ?? '');
    $capacity = $row['capacitate_transport'] ?? null;

    if ($transportType === 'compresor') {
        foreach (['tona_livrata', 'cantitate_prelevata'] as $field) {
            $tons = $normalizeTons($row[$field] ?? null, $capacity);
            if ($tons !== null) {
                return $tons;
            }
        }

        $liquid = $normalizeTons($row['tona_aspirata_lichida'] ?? null, $capacity) ?? 0.0;
        $gas = $normalizeTons($row['tona_aspirata_gazoasa'] ?? null, $capacity) ?? 0.0;
        if (($liquid + $gas) > 0) {
            return $liquid + $gas;
        }
    }

    return $normalizeTons($row['cantitate_incarcata'] ?? null, $capacity) ?? 0.0;
};

$transportGroupKey = static function (mixed $transportType): string {
    $transportType = (string) $transportType;
    return match ($transportType) {
        'primar', 'primar_tona' => 'primar',
        'distributie' => 'distributie',
        'primar_distributie' => 'primar_distributie',
        'compresor' => 'compresor',
        default => 'primar',
    };
};

$goodsLabel = static function (mixed $value) use ($goodsTypes): string {
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return '-';
    }

    $labels = [];
    foreach (array_filter(array_map('trim', explode(',', $raw))) as $key) {
        $labels[] = (string) ($goodsTypes[$key] ?? $key);
    }

    return $labels !== [] ? implode(', ', $labels) : '-';
};

$expenseLabel = static fn (string $type): string => (string) ($expenseTypes[$type] ?? ($type !== '' ? $type : 'Cheltuială'));

$regularExpensesForRow = static function (array $row): array {
    $expenses = is_array($row['expenses_breakdown'] ?? null) ? $row['expenses_breakdown'] : [];
    return array_values(array_filter($expenses, static fn (array $expense): bool => (float) ($expense['suma'] ?? 0) > 0));
};

$refacturareExpensesForRow = static function (array $row): array {
    $expenses = is_array($row['expenses_breakdown'] ?? null) ? $row['expenses_breakdown'] : [];
    return array_values(array_filter(
        $expenses,
        static fn (array $expense): bool => (float) ($expense['refacturare_suma'] ?? 0) > 0
            && (int) ($expense['refacturare_facturata'] ?? 0) !== 1
    ));
};

$calculationFormula = static function (array $row) use ($rowTons, $formatKm, $formatTons, $money): string {
    $transportType = (string) ($row['tip_transport'] ?? '');
    $rate = (float) ($row['pret_tarifare'] ?? 0);
    $total = (float) ($row['total_facturare'] ?? 0);
    $km = (float) ($row['km_cursa'] ?? 0);
    $kmTotal = (float) ($row['km_totali'] ?? 0);
    $hours = (float) ($row['ore_aspirare'] ?? ($row['ore_functionare'] ?? 0));
    $tons = $rowTons($row);

    if ($transportType === 'primar' && $km > 0 && $rate > 0) {
        return $formatKm($km) . ' x ' . $money($rate) . '/km = ' . $money($total);
    }

    if ($transportType === 'primar_tona' && $tons > 0 && $rate > 0) {
        return $formatTons($tons) . ' x ' . $money($rate) . '/t = ' . $money($total);
    }

    if ($transportType === 'distributie') {
        if ($tons > 0 && $rate > 0) {
            return $formatTons($tons) . ' x ' . $money($rate) . ' = ' . $money($total);
        }
        if ($km > 0 && $rate > 0) {
            return $formatKm($km) . ' x ' . $money($rate) . ' = ' . $money($total);
        }
    }

    if ($transportType === 'primar_distributie') {
        $basis = $kmTotal > 0 ? $formatKm($kmTotal) : ($tons > 0 ? $formatTons($tons) : 'Rută mixtă');
        return $basis . ' | tarif salvat ' . $money($rate) . ' = ' . $money($total);
    }

    if ($transportType === 'compresor') {
        if ($hours > 0 && $rate > 0) {
            return format_number_ro($hours, $hours === floor($hours) ? 0 : 2) . ' h x ' . $money($rate) . ' = ' . $money($total);
        }
        if ($tons > 0 && $rate > 0) {
            return $formatTons($tons) . ' x ' . $money($rate) . ' = ' . $money($total);
        }
    }

    return 'Calcul salvat în Dispecer: ' . $money($total);
};

$getSummaryRow = static function (array $summarySource, string $statusKey) use ($summaryMetricDefaults): array {
    return array_merge($summaryMetricDefaults, (array) ($summarySource[$statusKey] ?? []));
};

$sumSummaryRows = static function (array $summaryRows) use ($summaryMetricDefaults): array {
    $merged = $summaryMetricDefaults;
    foreach ($summaryRows as $summaryRow) {
        foreach ($summaryMetricDefaults as $field => $defaultValue) {
            $merged[$field] += is_numeric((string) ($summaryRow[$field] ?? null)) ? (float) $summaryRow[$field] : 0.0;
        }
    }

    return $merged;
};

$summaryInvoiceTotal = static function (array $summaryRow): float {
    return (float) ($summaryRow['total_facturare'] ?? 0) + (float) ($summaryRow['total_refacturare_facturata'] ?? 0);
};

$facturatSummary = $getSummaryRow($summaryByStatus, 'facturat');
$inProgressSummary = $getSummaryRow($summaryByStatus, 'in_curs_facturare');
$unbilledSummary = $getSummaryRow($summaryByStatus, 'nefacturat');
$openSummary = $sumSummaryRows([$inProgressSummary, $unbilledSummary]);

$preparedRows = [];
foreach ($rows as $row) {
    $statusKey = (string) ($row['status_facturare'] ?? 'in_curs_facturare');
    if (!isset($billingStatuses[$statusKey])) {
        $statusKey = 'in_curs_facturare';
    }

    $regularExpenses = $regularExpensesForRow($row);
    $refacturareExpenses = $refacturareExpensesForRow($row);
    $regularExpenseTotal = 0.0;
    foreach ($regularExpenses as $expenseRow) {
        $regularExpenseTotal += (float) ($expenseRow['suma'] ?? 0);
    }

    $refacturareTotal = 0.0;
    foreach ($refacturareExpenses as $expenseRow) {
        $refacturareTotal += (float) ($expenseRow['refacturare_suma'] ?? 0);
    }

    $routeStart = trim((string) ($row['loc_incarcare_nume'] ?? ''));
    if ($routeStart === '') {
        $routeStart = trim((string) ($row['loc_plecare'] ?? ''));
    }

    $routeEnd = trim((string) ($row['zona_distributie_nume'] ?? ''));
    if ($routeEnd === '') {
        $routeEnd = trim((string) ($row['loc_livrare'] ?? ''));
    }
    if ($routeEnd === '') {
        $routeEnd = trim((string) ($row['loc_livrare_cursa'] ?? ''));
    }

    $transportGroup = $transportGroupKey($row['tip_transport'] ?? '');
    $preparedRows[] = array_merge($row, [
        '_status_key' => $statusKey,
        '_status_label' => (string) ($billingStatuses[$statusKey] ?? $statusKey),
        '_status_tone' => (string) ($statusToneClasses[$statusKey] ?? 'is-neutral'),
        '_transport_group' => $transportGroup,
        '_transport_label' => (string) ($transportGroupLabels[$transportGroup] ?? '-'),
        '_goods_label' => $goodsLabel($row['tip_marfa'] ?? ''),
        '_route_start' => $routeStart !== '' ? $routeStart : '-',
        '_route_end' => $routeEnd !== '' ? $routeEnd : '-',
        '_tons_value' => $rowTons($row),
        '_display_total' => (float) ($row['total_facturare'] ?? 0) + (float) ($row['total_refacturare_facturata'] ?? 0),
        '_regular_expenses' => $regularExpenses,
        '_refacturare_expenses' => $refacturareExpenses,
        '_regular_expense_total' => $regularExpenseTotal,
        '_refacturare_total' => $refacturareTotal,
        '_invoiced_refacturare_total' => (float) ($row['total_refacturare_facturata'] ?? 0),
        '_formula' => $calculationFormula($row),
    ]);
}

$statusCardDefinitions = [
    'facturat' => [
        'label' => 'Facturat',
        'tone' => 'is-success',
        'statuses' => ['facturat'],
    ],
    'open' => [
        'label' => 'În curs + nefacturat',
        'tone' => 'is-pending',
        'statuses' => ['in_curs_facturare', 'nefacturat'],
    ],
    'nefacturat' => [
        'label' => 'Nefacturat',
        'tone' => 'is-danger',
        'statuses' => ['nefacturat'],
    ],
];
$statusCardSummaries = [];
foreach ($statusCardDefinitions as $cardKey => $cardDefinition) {
    $statusCardSummaries[$cardKey] = [
        'total_curse' => 0,
        'total' => 0.0,
        'refacturare' => 0.0,
        'breakdown' => [],
    ];
    foreach ($transportGroupLabels as $transportKey => $transportLabel) {
        $statusCardSummaries[$cardKey]['breakdown'][$transportKey] = [
            'label' => $transportLabel,
            'curse' => 0,
            'total' => 0.0,
            'refacturare' => 0.0,
        ];
    }
}
foreach ($preparedRows as $row) {
    $rowStatusKey = (string) ($row['_status_key'] ?? 'in_curs_facturare');
    $rowTransportGroup = (string) ($row['_transport_group'] ?? 'primar');
    $rowTotal = (float) ($row['total_facturare'] ?? 0);
    $rowRefacturare = (float) ($row['_refacturare_total'] ?? 0);

    foreach ($statusCardDefinitions as $cardKey => $cardDefinition) {
        if (!in_array($rowStatusKey, (array) ($cardDefinition['statuses'] ?? []), true)) {
            continue;
        }

        $statusCardSummaries[$cardKey]['total_curse']++;
        $statusCardSummaries[$cardKey]['total'] += $rowTotal;
        $statusCardSummaries[$cardKey]['refacturare'] += $rowRefacturare;
        if (!isset($statusCardSummaries[$cardKey]['breakdown'][$rowTransportGroup])) {
            continue;
        }
        $statusCardSummaries[$cardKey]['breakdown'][$rowTransportGroup]['curse']++;
        $statusCardSummaries[$cardKey]['breakdown'][$rowTransportGroup]['total'] += $rowTotal;
        $statusCardSummaries[$cardKey]['breakdown'][$rowTransportGroup]['refacturare'] += $rowRefacturare;
    }
}

$transportBreakdown = [];
foreach ($transportGroupLabels as $key => $label) {
    $transportBreakdown[$key] = [
        'label' => $label,
        'curse' => 0,
        'tone' => 0.0,
        'km_facturati' => 0.0,
        'km_rulati' => 0.0,
        'value' => 0.0,
    ];
}

$cargoTotals = [];
$vehicleGroups = [];
$totalDislocareKm = 0.0;
foreach ($preparedRows as $row) {
    $groupKey = (string) ($row['_transport_group'] ?? 'primar');
    $transportBreakdown[$groupKey]['curse']++;
    $transportBreakdown[$groupKey]['tone'] += (float) ($row['_tons_value'] ?? 0);
    $transportBreakdown[$groupKey]['km_facturati'] += (float) ($row['km_cursa'] ?? 0);
    $transportBreakdown[$groupKey]['km_rulati'] += (float) ($row['km_rulati'] ?? 0);
    $transportBreakdown[$groupKey]['value'] += (float) ($row['_display_total'] ?? 0);
    $totalDislocareKm += (float) ($row['km_dislocare'] ?? 0);

    $goodsKeys = array_values(array_filter(array_map('trim', explode(',', (string) ($row['tip_marfa'] ?? '')))));
    if ($goodsKeys !== []) {
        $share = (float) ($row['_tons_value'] ?? 0) / count($goodsKeys);
        foreach ($goodsKeys as $goodsKey) {
            if (!isset($cargoTotals[$goodsKey])) {
                $cargoTotals[$goodsKey] = 0.0;
            }
            $cargoTotals[$goodsKey] += $share;
        }
    }

    $vehicleId = (int) ($row['vehicle_id'] ?? 0);
    if ($vehicleId <= 0) {
        $vehicleId = -1 * (int) ($row['id'] ?? count($vehicleGroups) + 1);
    }

    if (!isset($vehicleGroups[$vehicleId])) {
        $vehicleGroups[$vehicleId] = [
            'vehicle_id' => $vehicleId,
            'nr_inmatriculare' => (string) ($row['nr_inmatriculare'] ?? '-'),
            'curse' => 0,
            'tone' => 0.0,
            'km_facturati' => 0.0,
            'km_rulati' => 0.0,
            'value' => 0.0,
            'transport_counts' => array_fill_keys(array_keys($transportGroupLabels), 0),
            'rows' => [],
        ];
    }

    $vehicleGroups[$vehicleId]['curse']++;
    $vehicleGroups[$vehicleId]['tone'] += (float) ($row['_tons_value'] ?? 0);
    $vehicleGroups[$vehicleId]['km_facturati'] += (float) ($row['km_cursa'] ?? 0);
    $vehicleGroups[$vehicleId]['km_rulati'] += (float) ($row['km_rulati'] ?? 0);
    $vehicleGroups[$vehicleId]['value'] += (float) ($row['_display_total'] ?? 0);
    $vehicleGroups[$vehicleId]['transport_counts'][$groupKey]++;
    $vehicleGroups[$vehicleId]['rows'][] = $row;
}

$totalTrips = count($preparedRows);
$totalTons = array_sum(array_column($transportBreakdown, 'tone'));
$totalKmFacturati = array_sum(array_column($transportBreakdown, 'km_facturati'));
$totalInvoiceValue = array_sum(array_column($transportBreakdown, 'value'));

$buildTypeTotals = static function (array $rows, string $typeField): array {
    $totals = [];
    foreach ($rows as $row) {
        $type = trim((string) ($row[$typeField] ?? ''));
        if ($type === '') {
            $type = 'alte';
        }
        if (!isset($totals[$type])) {
            $totals[$type] = 0.0;
        }
        $totals[$type] += (float) ($row['total_suma'] ?? 0);
    }

    return $totals;
};

$expenseTotalsByType = $buildTypeTotals($expenseTypeTotals, 'tip_cheltuiala');
$refacturareTotalsByType = $buildTypeTotals($refacturareTypeTotals, 'refacturare_tip_cheltuiala');
$totalExpenses = array_sum($expenseTotalsByType);
$totalRefacturare = array_sum($refacturareTotalsByType);
$activityPageKey = trim((string) ($activityPageKey ?? 'istoric_activitate'));
if ($activityPageKey === '') {
    $activityPageKey = 'istoric_activitate';
}

$filterValue = static fn (string $key): string => is_array($filters[$key] ?? null) ? '' : trim((string) ($filters[$key] ?? ''));
$filterArray = static fn (string $key): array => array_values(array_filter((array) ($filters[$key] ?? []), static fn ($value): bool => trim((string) $value) !== ''));

$activeFilterCount = 0;
foreach ([
    $search,
    $filterValue('status_facturare'),
    $filterValue('nr_inmatriculare'),
    $filterValue('tip_marfa'),
] as $filterItem) {
    if ($filterItem !== '') {
        $activeFilterCount++;
    }
}
foreach (['tip_transport', 'vehicle_id', 'driver_id', 'beneficiar_id', 'locatie_operationala', 'loc_incarcare', 'zona_distributie'] as $arrayFilterKey) {
    if ($filterArray($arrayFilterKey) !== []) {
        $activeFilterCount++;
    }
}

$filtersOpen = $activeFilterCount > 0 || (string) ($_GET['filters_open'] ?? '') === '1';
$currentListUrl = build_query_url([
    'page' => $activityPageKey,
    'action' => 'index',
    'q' => $search,
    'status_facturare' => $filterValue('status_facturare'),
    'tip_transport' => $filterArray('tip_transport'),
    'nr_inmatriculare' => $filterValue('nr_inmatriculare'),
    'vehicle_id' => $filterArray('vehicle_id'),
    'driver_id' => $filterArray('driver_id'),
    'beneficiar_id' => $filterArray('beneficiar_id'),
    'tip_marfa' => $filterValue('tip_marfa'),
    'locatie_operationala' => $filterArray('locatie_operationala'),
    'loc_incarcare' => $filterArray('loc_incarcare'),
    'zona_distributie' => $filterArray('zona_distributie'),
]);
$resetUrl = build_query_url(['page' => $activityPageKey, 'action' => 'index', 'filters_open' => '1']);
$openVehicleId = (int) ($_GET['open_vehicle'] ?? 0);

$locationOptionRows = static function (array $source, string $bucket): array {
    $rows = (array) ($source[$bucket] ?? []);
    return array_values(array_filter($rows, static fn ($row): bool => is_array($row) && trim((string) ($row['value'] ?? '')) !== ''));
};

$renderFinancialRows = static function (array $totals, array $order, array $labels, callable $money): string {
    $seen = [];
    ob_start();
    foreach ($order as $key) {
        $seen[$key] = true;
        ?>
        <div class="billing-ref-money-row">
            <span><?= e((string) ($labels[$key] ?? $key)) ?></span>
            <strong><?= e($money($totals[$key] ?? 0)) ?></strong>
        </div>
        <?php
    }

    foreach ($totals as $key => $value) {
        if (isset($seen[$key]) || (float) $value <= 0) {
            continue;
        }
        ?>
        <div class="billing-ref-money-row">
            <span><?= e((string) ($labels[$key] ?? $key)) ?></span>
            <strong><?= e($money($value)) ?></strong>
        </div>
        <?php
    }

    return (string) ob_get_clean();
};

$renderKpiRows = static function (array $breakdown, string $field, callable $formatter): string {
    ob_start();
    foreach ($breakdown as $row) {
        ?>
        <div class="billing-ref-kpi-row">
            <span><?= e((string) ($row['label'] ?? '-')) ?></span>
            <strong><?= e($formatter($row[$field] ?? 0)) ?></strong>
        </div>
        <?php
    }

    return (string) ob_get_clean();
};

$renderMultiSelect = static function (
    string $id,
    string $name,
    array $options,
    array $selectedValues,
    string $placeholder = 'Selectează...'
): string {
    $selectedMap = [];
    foreach ($selectedValues as $value) {
        $selectedMap[(string) $value] = true;
    }

    ob_start();
    ?>
    <div class="billing-ref-multi" data-multi-select>
        <button class="billing-ref-multi-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">
            <span class="billing-ref-multi-placeholder" data-multi-placeholder><?= e($placeholder) ?></span>
            <span class="billing-ref-multi-values" data-multi-values></span>
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
        <div class="dropdown-menu billing-ref-multi-menu">
            <input type="search" class="form-control form-control-sm billing-ref-multi-search" placeholder="Caută..." data-multi-search>
            <div class="billing-ref-multi-options">
                <?php if ($options === []): ?>
                    <div class="billing-ref-multi-empty">Nu există opțiuni în filtrul curent.</div>
                <?php else: ?>
                    <?php foreach ($options as $index => $option): ?>
                        <?php
                        $value = (string) ($option['value'] ?? '');
                        $label = (string) ($option['label'] ?? $value);
                        $optionId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id . '_' . (string) $index);
                        ?>
                        <label class="billing-ref-multi-option" data-multi-option>
                            <input
                                type="checkbox"
                                name="<?= e($name) ?>"
                                id="<?= e((string) $optionId) ?>"
                                value="<?= e($value) ?>"
                                <?= isset($selectedMap[$value]) ? 'checked' : '' ?>
                            >
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>

<style>
.billing-ref-page {
    --billing-ref-ink: #071c55;
    --billing-ref-muted: #597095;
    --billing-ref-soft: #f6f8fc;
    --billing-ref-border: #d9e4f5;
    --billing-ref-blue: #0b55f4;
    --billing-ref-success: #15803d;
    --billing-ref-warning: #ea580c;
    --billing-ref-danger: #dc2626;
    height: calc(100dvh - 6.45rem);
    min-height: 0;
    margin-top: -0.65rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    overflow: hidden;
    color: var(--billing-ref-ink);
}

.billing-ref-kpi-grid {
    flex: 0 0 auto;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    align-items: start;
    gap: 0.86rem;
}

.billing-ref-kpi-card,
.billing-ref-status-card,
.billing-ref-filter-panel,
.billing-ref-finance-card,
.billing-ref-activity-shell {
    border: 1px solid var(--billing-ref-border);
    border-radius: 14px;
    box-shadow: none;
}

.billing-ref-kpi-card {
    min-height: 156px;
    padding: 0.9rem 1.05rem 0.8rem;
    overflow: hidden;
}

.billing-ref-kpi-card.is-collapsible {
    min-height: 0;
    padding: 0;
    transition: min-height 0.2s ease, border-color 0.18s ease;
}

.billing-ref-kpi-card.is-collapsible.is-open {
    min-height: 156px;
}

.billing-ref-kpi-card.is-blue { background: #f6f9ff; }
.billing-ref-kpi-card.is-green { background: #f5fbf8; }
.billing-ref-kpi-card.is-cyan { background: #f4fbff; }
.billing-ref-kpi-card.is-orange { background: #fff9f1; }
.billing-ref-kpi-card.is-purple { background: #f8f7ff; }

.billing-ref-kpi-label {
    margin: 0;
    color: #0644c4;
    font-size: 0.82rem;
    font-weight: 800;
}

.billing-ref-kpi-value {
    display: block;
    margin-top: 0.26rem;
    color: #06164c;
    font-size: 1.42rem;
    line-height: 1.1;
    font-weight: 900;
}

.billing-ref-kpi-toggle {
    width: 100%;
    min-height: 3.52rem;
    display: flex;
    align-items: center;
    gap: 0.46rem;
    padding: 0.78rem 0.84rem;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
}

.billing-ref-kpi-toggle .billing-ref-kpi-label {
    min-width: 0;
    overflow: hidden;
    flex: 0 1 auto;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.billing-ref-kpi-toggle .billing-ref-kpi-value {
    flex: 0 0 auto;
    margin-top: 0;
    font-size: 0.98rem;
    white-space: nowrap;
}

.billing-ref-kpi-chevron {
    margin-left: auto;
    color: #0b55f4;
    font-size: 0.78rem;
    transition: transform 0.18s ease;
}

.billing-ref-kpi-card.is-open .billing-ref-kpi-chevron {
    transform: rotate(180deg);
}

.billing-ref-kpi-details {
    max-height: 0;
    overflow: hidden;
    opacity: 0;
    transition: max-height 0.2s ease, opacity 0.16s ease;
}

.billing-ref-kpi-card.is-open .billing-ref-kpi-details {
    max-height: 9.5rem;
    opacity: 1;
}

.billing-ref-kpi-lines {
    display: grid;
    gap: 0.18rem;
    margin-top: 0.5rem;
    padding-top: 0.5rem;
    border-top: 1px solid #dfe8f6;
}

.billing-ref-kpi-card.is-collapsible .billing-ref-kpi-lines {
    margin-top: 0;
    padding: 0.5rem 1.05rem 0.78rem;
}

.billing-ref-kpi-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.7rem;
    align-items: center;
    color: #0a2d74;
    font-size: 0.72rem;
    font-weight: 700;
}

.billing-ref-kpi-row strong {
    font-weight: 850;
    text-align: right;
    white-space: nowrap;
}

.billing-ref-kpi-separator {
    height: 1px;
    margin: 0.08rem 0;
    background: #dfe8f6;
}

.billing-ref-dashboard-grid {
    flex: 1 1 auto;
    min-height: 0;
    display: grid;
    grid-template-columns: minmax(0, 1fr) clamp(220px, 16vw, 260px);
    gap: 0.9rem;
    overflow: hidden;
}

.billing-ref-left-stack {
    min-width: 0;
    min-height: 0;
    display: grid;
    grid-template-rows: auto auto auto minmax(0, 1fr);
    gap: 0.64rem;
}

.billing-ref-status-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: start;
    gap: 0.74rem;
    transition: grid-template-columns 0.2s ease;
}

.billing-ref-status-grid[data-open-status="facturat"] {
    grid-template-columns: minmax(0, 1.55fr) minmax(0, 0.85fr) minmax(0, 0.85fr);
}

.billing-ref-status-grid[data-open-status="open"] {
    grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.55fr) minmax(0, 0.85fr);
}

.billing-ref-status-grid[data-open-status="nefacturat"] {
    grid-template-columns: minmax(0, 0.85fr) minmax(0, 0.85fr) minmax(0, 1.55fr);
}

.billing-ref-status-card {
    min-width: 0;
    min-height: 68px;
    padding: 0;
    background: #ffffff;
    overflow: hidden;
    transition: border-color 0.18s ease, background-color 0.18s ease;
}

.billing-ref-status-card.is-open.is-success {
    border-color: #86efac;
    background: #fbfffc;
}

.billing-ref-status-card.is-open.is-pending {
    border-color: #fdba74;
    background: #fffaf3;
}

.billing-ref-status-card.is-open.is-danger {
    border-color: #fca5a5;
    background: #fffafa;
}

.billing-ref-status-toggle {
    width: 100%;
    min-height: 68px;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    grid-template-rows: auto auto;
    gap: 0.32rem 0.62rem;
    align-items: center;
    padding: 0.62rem 0.82rem;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
}

.billing-ref-status-top {
    display: contents;
}

.billing-ref-status-chevron {
    grid-column: 3;
    grid-row: 1;
    justify-self: end;
    color: #0b55f4;
    font-size: 0.82rem;
    transition: transform 0.18s ease, color 0.18s ease;
}

.billing-ref-status-card.is-open .billing-ref-status-chevron {
    transform: rotate(180deg);
}

.billing-ref-status-card.is-open.is-success .billing-ref-status-chevron {
    color: #15803d;
}

.billing-ref-status-card.is-open.is-pending .billing-ref-status-chevron {
    color: #ea580c;
}

.billing-ref-status-card.is-open.is-danger .billing-ref-status-chevron {
    color: #dc2626;
}

.billing-ref-status-summary {
    display: contents;
    color: #153d83;
    font-size: 0.78rem;
    line-height: 1.25;
    font-weight: 800;
}

.billing-ref-status-summary > span {
    align-self: center;
    min-width: 0;
    overflow: visible;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.billing-ref-status-summary > span:first-child {
    grid-column: 2;
    grid-row: 1;
    justify-self: end;
}

.billing-ref-status-summary > span + span {
    grid-column: 1 / -1;
    grid-row: 2;
    justify-self: start;
    padding-left: 0;
    border-left: 0;
}

.billing-ref-status-summary strong {
    color: #06164c;
    font-size: 0.82rem;
    font-weight: 900;
    white-space: nowrap;
}

.billing-ref-pill {
    display: inline-flex;
    align-items: center;
    grid-column: 1;
    grid-row: 1;
    justify-self: start;
    min-height: 1.45rem;
    padding: 0.18rem 0.56rem;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 850;
    white-space: nowrap;
}

.billing-ref-pill.is-success {
    color: #15803d;
    background: #dcfce7;
}

.billing-ref-pill.is-pending {
    color: #ea580c;
    background: #ffedd5;
}

.billing-ref-pill.is-danger {
    color: #dc2626;
    background: #fee2e2;
}

.billing-ref-pill.is-neutral {
    color: #0b3b86;
    background: #edf4ff;
}

.billing-ref-status-breakdown {
    max-height: 0;
    overflow: hidden;
    border-top: 0 solid transparent;
    opacity: 0;
    transition: max-height 0.2s ease, opacity 0.16s ease, border-top-color 0.16s ease;
}

.billing-ref-status-card.is-open .billing-ref-status-breakdown {
    max-height: 10rem;
    border-top: 1px solid #e2eaf6;
    opacity: 1;
}

.billing-ref-status-breakdown-inner {
    display: grid;
    gap: 0.22rem;
    padding: 0.42rem 0.9rem 0.66rem;
}

.billing-ref-status-breakdown-row {
    display: grid;
    grid-template-columns: minmax(74px, 1fr) minmax(54px, auto) minmax(78px, auto) minmax(112px, auto);
    gap: 0.7rem;
    align-items: center;
    color: #173d80;
    font-size: 0.68rem;
    line-height: 1.18;
    font-weight: 760;
}

.billing-ref-status-breakdown-row strong {
    color: #06164c;
    font-weight: 900;
    text-align: right;
    white-space: nowrap;
}

.billing-ref-filter-panel {
    overflow: hidden;
    background: #ffffff;
}

.billing-ref-filter-header {
    min-height: 2.64rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
    padding: 0.55rem 0.85rem;
}

.billing-ref-filter-title {
    display: inline-flex;
    align-items: center;
    gap: 0.48rem;
    margin: 0;
    color: #06164c;
    font-size: 0.88rem;
    font-weight: 850;
}

.billing-ref-filter-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border: 0;
    background: transparent;
    color: #0b55f4;
    font-size: 0.72rem;
    font-weight: 800;
}

.billing-ref-filter-body {
    max-height: 0;
    overflow: hidden;
    border-top: 0 solid transparent;
    transition: max-height 0.22s ease, border-top-color 0.22s ease;
}

.billing-ref-filter-panel.is-open .billing-ref-filter-body {
    max-height: 18rem;
    border-top: 1px solid #edf2fb;
}

.billing-ref-filter-inner {
    padding: 0.75rem 0.95rem 0.82rem;
}

.billing-ref-filter-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.66rem 1rem;
    align-items: end;
}

.billing-ref-field {
    min-width: 0;
}

.billing-ref-field label {
    display: block;
    margin: 0 0 0.22rem;
    color: #0f2d70;
    font-size: 0.7rem;
    font-weight: 800;
}

.billing-ref-field .form-control,
.billing-ref-field .form-select {
    min-height: 2.05rem;
    padding-top: 0.34rem;
    padding-bottom: 0.34rem;
    border-color: #cfdcf0;
    border-radius: 5px;
    color: #10275f;
    font-size: 0.76rem;
    font-weight: 650;
}

.billing-ref-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.65rem;
}

.billing-ref-actions .btn {
    min-height: 2.05rem;
    padding: 0.36rem 0.9rem;
    border-radius: 5px;
    font-size: 0.72rem;
    font-weight: 850;
}

.billing-ref-location-block[hidden] {
    display: none !important;
}

.billing-ref-location-block[data-location-block="pd"] {
    grid-column: span 2;
}

.billing-ref-location-dual {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.6rem;
}

.billing-ref-filter-note {
    margin: 0.68rem 0 0;
    color: #31578d;
    font-size: 0.7rem;
    line-height: 1.4;
    font-weight: 700;
}

.billing-ref-multi {
    position: relative;
}

.billing-ref-multi-toggle {
    width: 100%;
    min-height: 2.05rem;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 0.35rem;
    padding: 0.26rem 0.58rem;
    border: 1px solid #cfdcf0;
    border-radius: 5px;
    color: #10275f;
    background: #ffffff;
    font-size: 0.76rem;
    font-weight: 650;
    text-align: left;
}

/* Placeholder-ul si chips-urile stau in aceeasi celula; sageata ramane pe acelasi rand. */
.billing-ref-multi-placeholder,
.billing-ref-multi-values {
    grid-column: 1;
    grid-row: 1;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.billing-ref-multi-toggle > .bi {
    grid-column: 2;
    grid-row: 1;
    font-size: 0.72rem;
}

.billing-ref-multi-values {
    display: flex;
    gap: 0.25rem;
}

.billing-ref-multi-values:empty {
    display: none;
}

.billing-ref-multi-chip {
    max-width: 7.4rem;
    padding: 0.08rem 0.34rem;
    overflow: hidden;
    border-radius: 999px;
    background: #edf4ff;
    color: #0b55f4;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 0.68rem;
    font-weight: 850;
}

.billing-ref-multi-placeholder {
    color: #6d7f9e;
}

.billing-ref-multi-menu {
    z-index: 1060;
    width: min(22rem, calc(100vw - 32px));
    padding: 0.55rem;
    border-color: #ccd9ec;
    border-radius: 8px;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.14);
}

.billing-ref-multi-search {
    margin-bottom: 0.45rem;
}

.billing-ref-multi-options {
    max-height: 11.5rem;
    overflow-y: auto;
}

.billing-ref-multi-option {
    min-height: 1.9rem;
    display: flex;
    align-items: center;
    gap: 0.42rem;
    padding: 0.28rem 0.35rem;
    border-radius: 5px;
    color: #0f2d70;
    font-size: 0.76rem;
    font-weight: 650;
}

.billing-ref-multi-option:hover {
    background: #f4f7fc;
}

.billing-ref-multi-empty {
    padding: 0.55rem 0.3rem;
    color: #6d7f9e;
    font-size: 0.76rem;
}

.billing-ref-finance-column {
    display: grid;
    grid-template-rows: repeat(3, minmax(0, 1fr));
    align-content: stretch;
    align-items: stretch;
    gap: 0.72rem;
    min-width: 0;
    min-height: 0;
    height: 100%;
    max-height: 100%;
    overflow: hidden;
}

.billing-ref-finance-column > .billing-ref-kpi-card,
.billing-ref-finance-column > .billing-ref-finance-card {
    min-height: 0;
    height: 100%;
}

.billing-ref-finance-card {
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    padding: 0.64rem 0.82rem;
}

.billing-ref-finance-card.is-expense {
    background: #f6faff;
}

.billing-ref-finance-card.is-refact {
    background: #fff8f1;
}

.billing-ref-finance-title {
    display: inline-flex;
    align-items: center;
    min-height: 1.36rem;
    padding: 0.12rem 0.5rem;
    border-radius: 999px;
    color: #075ceb;
    background: #eef5ff;
    font-size: 0.76rem;
    font-weight: 850;
}

.billing-ref-finance-card.is-refact .billing-ref-finance-title {
    color: #ea580c;
    background: #ffedd5;
}

.billing-ref-finance-total {
    display: block;
    margin: 0.5rem 0 0.42rem;
    color: #06164c;
    font-size: 1.16rem;
    line-height: 1;
    font-weight: 900;
}

.billing-ref-money-list {
    flex: 0 0 auto;
    min-height: 0;
    display: grid;
    align-content: start;
    gap: 0.24rem;
    overflow: visible;
    padding-top: 0.42rem;
    border-top: 1px solid #dfe8f6;
}

.billing-ref-money-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.48rem;
    color: #173d80;
    font-size: 0.66rem;
    line-height: 1.08;
    font-weight: 750;
}

.billing-ref-money-row strong {
    text-align: right;
    white-space: nowrap;
}

.billing-ref-activity-shell {
    min-height: 0;
    width: 100%;
    align-self: stretch;
    max-height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #ffffff;
}

.billing-ref-activity-header {
    flex: 0 0 auto;
    padding: 0.48rem 0 0.22rem;
}

.billing-ref-activity-title {
    margin: 0;
    color: #06164c;
    font-size: 0.98rem;
    font-weight: 900;
}

.billing-ref-activity-subtitle {
    margin: 0.08rem 0 0;
    color: #153d83;
    font-size: 0.72rem;
    line-height: 1.25;
    font-weight: 650;
}

.billing-ref-activity-scroll {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
    overflow-y: auto;
    scrollbar-gutter: stable;
    scrollbar-width: thin;
    scrollbar-color: #9fb3d1 #edf3fb;
    border: 1px solid var(--billing-ref-border);
    border-radius: 11px;
    background: #ffffff;
}

.billing-ref-activity-scroll::-webkit-scrollbar {
    width: 10px;
}

.billing-ref-activity-scroll::-webkit-scrollbar-track {
    background: #edf3fb;
    border-radius: 999px;
}

.billing-ref-activity-scroll::-webkit-scrollbar-thumb {
    background: #9fb3d1;
    border: 2px solid #edf3fb;
    border-radius: 999px;
}

.billing-ref-empty {
    padding: 2rem 1rem;
    color: var(--billing-ref-muted);
    text-align: center;
    font-size: 0.9rem;
}

.billing-ref-vehicle {
    display: flex;
    flex-direction: column;
    border-bottom: 1px solid #e6edf8;
}

.billing-ref-vehicle:not(.is-open) {
    flex: 1 0 clamp(4.7rem, 8dvh, 6.1rem);
}

.billing-ref-vehicle:not(.is-open) .billing-ref-vehicle-summary {
    flex: 1 1 auto;
}

.billing-ref-vehicle.is-open {
    flex: 0 0 auto;
}

.billing-ref-vehicle:last-child {
    border-bottom: 0;
}

.billing-ref-vehicle-summary {
    width: 100%;
    min-height: clamp(4.7rem, 8dvh, 6.1rem);
    display: grid;
    grid-template-columns: minmax(140px, 1.15fr) repeat(4, minmax(104px, 0.82fr)) minmax(380px, 2.25fr);
    gap: 0.82rem;
    align-items: center;
    padding: 0.95rem 1.08rem;
    border: 0;
    background: #ffffff;
    color: var(--billing-ref-ink);
    text-align: left;
}

.billing-ref-vehicle-summary:focus-visible,
.billing-ref-kpi-toggle:focus-visible,
.billing-ref-filter-toggle:focus-visible,
.billing-ref-multi-toggle:focus-visible,
.billing-ref-status-toggle:focus-visible,
.billing-ref-status-badge:focus-visible {
    outline: 3px solid rgba(13, 110, 253, 0.24);
    outline-offset: 2px;
}

.billing-ref-vehicle-title {
    display: grid;
    gap: 0.22rem;
}

.billing-ref-vehicle-title strong {
    color: #06164c;
    font-size: 1.06rem;
    font-weight: 900;
}

.billing-ref-vehicle-title span,
.billing-ref-vehicle-metric span {
    color: #153d83;
    font-size: 0.76rem;
    font-weight: 720;
}

.billing-ref-vehicle-metric {
    display: grid;
    gap: 0.14rem;
    color: #06164c;
    font-size: 0.86rem;
    font-weight: 850;
    white-space: nowrap;
}

.billing-ref-transport-counts {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 1rem;
    min-width: 0;
}

.billing-ref-transport-counts span {
    color: #153d83;
    font-size: 0.72rem;
    font-weight: 800;
    white-space: nowrap;
}

.billing-ref-trip-wrap {
    display: none;
    padding: 0 0.9rem 0.82rem;
}

.billing-ref-vehicle.is-open .billing-ref-trip-wrap {
    display: block;
}

.billing-ref-trip-table-wrap {
    overflow-x: auto;
    border: 1px solid #e1e8f4;
    border-radius: 8px;
}

.billing-ref-trip-table {
    width: 100%;
    min-width: 1020px;
    border-collapse: collapse;
    font-size: 0.76rem;
}

.billing-ref-trip-table th {
    padding: 0.5rem 0.46rem;
    color: #395a91;
    background: #f8fbff;
    border-bottom: 1px solid #e1e8f4;
    font-weight: 850;
    white-space: nowrap;
}

.billing-ref-trip-table td {
    padding: 0.64rem 0.46rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.76);
    color: #0e2e6f;
    font-weight: 720;
    vertical-align: middle;
}

.billing-ref-trip-table th:nth-last-child(2),
.billing-ref-trip-table td:nth-last-child(2) {
    min-width: 17rem;
}

.billing-ref-trip-table td:nth-last-child(2) {
    white-space: nowrap;
}

.billing-ref-trip-table th:last-child,
.billing-ref-trip-table td:last-child {
    width: 5.3rem;
    min-width: 5.3rem;
    position: sticky;
    right: 0;
    z-index: 2;
    text-align: center;
    white-space: nowrap;
    box-shadow: -8px 0 12px rgba(15, 23, 42, 0.08);
}

.billing-ref-trip-table th:last-child {
    z-index: 3;
    background: #f8fbff;
}

.billing-ref-trip-row.is-success td {
    background: #c9f2d4;
}

.billing-ref-trip-row.is-pending td {
    background: #ffe0a3;
}

.billing-ref-trip-row.is-danger td {
    background: #ffc8d0;
}

.billing-ref-trip-row.is-success td:first-child {
    box-shadow: inset 5px 0 0 #16a34a;
}

.billing-ref-trip-row.is-pending td:first-child {
    box-shadow: inset 5px 0 0 #f97316;
}

.billing-ref-trip-row.is-danger td:first-child {
    box-shadow: inset 5px 0 0 #dc2626;
}

.billing-ref-trip-main {
    display: grid;
    gap: 0.08rem;
    min-width: 7.4rem;
}

.billing-ref-trip-main span {
    color: #102d6c;
}

.billing-ref-status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 4.8rem;
    padding: 0.24rem 0.5rem;
    border: 0;
    border-radius: 5px;
    font-size: 0.74rem;
    font-weight: 900;
    line-height: 1.1;
    cursor: pointer;
}

.billing-ref-status-badge.dropdown-toggle::after {
    margin-left: 0.35rem;
}

.billing-ref-status-badge.is-success {
    color: #ffffff;
    background: #16a34a;
}

.billing-ref-status-badge.is-pending {
    color: #ffffff;
    background: #f97316;
}

.billing-ref-status-badge.is-danger {
    color: #ffffff;
    background: #dc2626;
}

.billing-ref-status-menu.is-floating {
    position: fixed !important;
    display: block;
    z-index: 1085;
    min-width: 8.8rem;
    padding: 0.35rem;
    border: 1px solid #cfdcf0;
    border-radius: 8px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.2);
}

.billing-ref-status-menu .dropdown-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.65rem;
    border-radius: 5px;
    font-size: 0.78rem;
    font-weight: 700;
}

.billing-ref-status-menu .dropdown-item.is-current::after {
    content: "Curent";
    color: #0b55f4;
    font-size: 0.68rem;
    font-weight: 850;
}

.billing-ref-ajax-error {
    display: none;
    margin-top: 0.45rem;
    color: #b91c1c;
    font-size: 0.72rem;
    font-weight: 750;
}

.billing-ref-ajax-error.is-visible {
    display: block;
}

@media (max-width: 1399.98px) {
    .billing-ref-kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (min-width: 1200px) and (max-height: 760px) {
    .billing-ref-page {
        gap: 0.5rem;
    }

    .billing-ref-kpi-grid,
    .billing-ref-dashboard-grid,
    .billing-ref-left-stack,
    .billing-ref-finance-column,
    .billing-ref-status-grid {
        gap: 0.55rem;
    }

    .billing-ref-kpi-card {
        min-height: 136px;
        padding: 0.68rem 0.9rem 0.6rem;
    }

    .billing-ref-kpi-card.is-collapsible {
        min-height: 0;
        padding: 0;
    }

    .billing-ref-kpi-card.is-collapsible.is-open {
        min-height: 136px;
    }

    .billing-ref-kpi-toggle {
        min-height: 3.12rem;
        padding: 0.62rem 0.78rem;
    }

    .billing-ref-kpi-card.is-collapsible .billing-ref-kpi-lines {
        padding: 0.42rem 0.9rem 0.58rem;
    }

    .billing-ref-kpi-label {
        font-size: 0.76rem;
    }

    .billing-ref-kpi-value {
        font-size: 1.24rem;
    }

    .billing-ref-kpi-lines {
        gap: 0.12rem;
        margin-top: 0.36rem;
        padding-top: 0.36rem;
    }

    .billing-ref-status-card {
        min-height: 62px;
    }

    .billing-ref-status-toggle {
        min-height: 62px;
        padding: 0.5rem 0.72rem;
    }

    .billing-ref-status-summary,
    .billing-ref-status-breakdown-row {
        font-size: 0.68rem;
    }

    .billing-ref-filter-header {
        min-height: 2.28rem;
        padding: 0.42rem 0.76rem;
    }

    .billing-ref-filter-title {
        font-size: 0.8rem;
    }

    .billing-ref-filter-toggle {
        font-size: 0.68rem;
    }

    .billing-ref-activity-header {
        padding: 0.32rem 0 0.16rem;
    }

    .billing-ref-activity-title {
        font-size: 0.9rem;
    }
}

@media (max-width: 1199.98px) {
    .billing-ref-page {
        height: auto;
        min-height: 0;
        margin-top: 0;
        overflow: visible;
    }

    .billing-ref-dashboard-grid {
        grid-template-columns: 1fr;
    }

    .billing-ref-finance-column {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        grid-template-rows: auto;
        height: auto;
        overflow: visible;
    }

    .billing-ref-finance-column > .billing-ref-kpi-card,
    .billing-ref-finance-column > .billing-ref-finance-card {
        height: auto;
    }

    .billing-ref-activity-scroll {
        max-height: 62vh;
    }
}

@media (max-width: 991.98px) {
    .billing-ref-kpi-grid,
    .billing-ref-status-grid,
    .billing-ref-status-grid[data-open-status],
    .billing-ref-finance-column {
        grid-template-columns: 1fr;
    }

    .billing-ref-vehicle-summary {
        grid-template-columns: 1fr;
    }

    .billing-ref-transport-counts {
        justify-content: flex-start;
        flex-wrap: wrap;
    }
}

/* Filtre: 4 coloane pe desktop, 2 pe laptop mic/tableta, 1 pe telefon. */
@media (max-width: 1199.98px) {
    .billing-ref-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .billing-ref-filter-panel.is-open .billing-ref-filter-body {
        max-height: 40rem;
    }
}

@media (max-width: 575.98px) {
    .billing-ref-filter-grid,
    .billing-ref-location-dual {
        grid-template-columns: 1fr;
    }

    .billing-ref-filter-panel.is-open .billing-ref-filter-body {
        max-height: 70rem;
    }

    .billing-ref-location-block[data-location-block="pd"] {
        grid-column: auto;
    }

    .billing-ref-actions {
        justify-content: stretch;
    }

    .billing-ref-actions > * {
        flex: 1 1 0;
    }

    .billing-ref-bulk-status .form-select {
        flex: 1 1 100%;
    }
}
</style>

<div class="billing-ref-page">
    <div class="billing-ref-dashboard-grid">
        <main class="billing-ref-left-stack">
            <section class="billing-ref-kpi-grid" aria-label="Totaluri facturare">
                <article class="billing-ref-kpi-card is-blue is-collapsible is-open" data-kpi-card="total-trips">
            <button
                type="button"
                class="billing-ref-kpi-toggle"
                id="billing_kpi_toggle_total_trips"
                aria-expanded="true"
                aria-controls="billing_kpi_total_trips"
                data-kpi-toggle
            >
                <span class="billing-ref-kpi-label">Total curse</span>
                <strong class="billing-ref-kpi-value"><?= e((string) $totalTrips) ?></strong>
                <i class="bi bi-chevron-down billing-ref-kpi-chevron" aria-hidden="true"></i>
            </button>
            <div class="billing-ref-kpi-details" id="billing_kpi_total_trips" role="region" aria-labelledby="billing_kpi_toggle_total_trips" aria-hidden="false" data-kpi-details>
                <div class="billing-ref-kpi-lines">
                    <?= $renderKpiRows($transportBreakdown, 'curse', static fn ($value): string => (string) ((int) $value)) ?>
                </div>
            </div>
        </article>

        <article class="billing-ref-kpi-card is-green is-collapsible" data-kpi-card="total-tons">
            <button
                type="button"
                class="billing-ref-kpi-toggle"
                id="billing_kpi_toggle_total_tons"
                aria-expanded="false"
                aria-controls="billing_kpi_total_tons"
                data-kpi-toggle
            >
                <span class="billing-ref-kpi-label">Total tone</span>
                <strong class="billing-ref-kpi-value"><?= e($formatTons($totalTons)) ?></strong>
                <i class="bi bi-chevron-down billing-ref-kpi-chevron" aria-hidden="true"></i>
            </button>
            <div class="billing-ref-kpi-details" id="billing_kpi_total_tons" role="region" aria-labelledby="billing_kpi_toggle_total_tons" aria-hidden="true" data-kpi-details>
                <div class="billing-ref-kpi-lines">
                    <?= $renderKpiRows($transportBreakdown, 'tone', $formatTons) ?>
                </div>
            </div>
        </article>

        <article class="billing-ref-kpi-card is-cyan is-collapsible" data-kpi-card="total-invoiced-km">
            <button
                type="button"
                class="billing-ref-kpi-toggle"
                id="billing_kpi_toggle_total_invoiced_km"
                aria-expanded="false"
                aria-controls="billing_kpi_total_invoiced_km"
                data-kpi-toggle
            >
                <span class="billing-ref-kpi-label">Total Km Facturați</span>
                <strong class="billing-ref-kpi-value"><?= e($formatKm($totalKmFacturati)) ?></strong>
                <i class="bi bi-chevron-down billing-ref-kpi-chevron" aria-hidden="true"></i>
            </button>
            <div class="billing-ref-kpi-details" id="billing_kpi_total_invoiced_km" role="region" aria-labelledby="billing_kpi_toggle_total_invoiced_km" aria-hidden="true" data-kpi-details>
                <div class="billing-ref-kpi-lines">
                    <?= $renderKpiRows($transportBreakdown, 'km_facturati', $formatKm) ?>
                    <span class="billing-ref-kpi-separator" aria-hidden="true"></span>
                    <div class="billing-ref-kpi-row">
                        <span>Dislocare</span>
                        <strong><?= e($formatKm($totalDislocareKm)) ?></strong>
                    </div>
                </div>
            </div>
        </article>

        <article class="billing-ref-kpi-card is-orange is-collapsible" data-kpi-card="total-value">
            <button
                type="button"
                class="billing-ref-kpi-toggle"
                id="billing_kpi_toggle_total_value"
                aria-expanded="false"
                aria-controls="billing_kpi_total_value"
                data-kpi-toggle
            >
                <span class="billing-ref-kpi-label">Total</span>
                <strong class="billing-ref-kpi-value"><?= e($money($totalInvoiceValue)) ?></strong>
                <i class="bi bi-chevron-down billing-ref-kpi-chevron" aria-hidden="true"></i>
            </button>
            <div class="billing-ref-kpi-details" id="billing_kpi_total_value" role="region" aria-labelledby="billing_kpi_toggle_total_value" aria-hidden="true" data-kpi-details>
                <div class="billing-ref-kpi-lines">
                    <?= $renderKpiRows($transportBreakdown, 'value', $money) ?>
                </div>
            </div>
        </article>

            </section>

            <section class="billing-ref-status-grid" aria-label="Status facturare" data-status-grid>
                <?php foreach ([
                    ['key' => 'facturat', 'label' => 'Facturat', 'summary' => $facturatSummary, 'tone' => 'is-success'],
                    ['key' => 'open', 'label' => 'În curs + nefacturat', 'summary' => $openSummary, 'tone' => 'is-pending'],
                    ['key' => 'nefacturat', 'label' => 'Nefacturat', 'summary' => $unbilledSummary, 'tone' => 'is-danger'],
                ] as $statusCard): ?>
                    <?php
                    $statusCardKey = (string) ($statusCard['key'] ?? '');
                    $cardSummary = (array) ($statusCardSummaries[$statusCardKey] ?? []);
                    $cardPanelId = 'billing_status_breakdown_' . preg_replace('/[^a-z0-9_-]/i', '_', $statusCardKey);
                    ?>
                    <article class="billing-ref-status-card <?= e((string) ($statusCard['tone'] ?? '')) ?>" data-status-card="<?= e($statusCardKey) ?>">
                        <button
                            type="button"
                            class="billing-ref-status-toggle"
                            aria-expanded="false"
                            aria-controls="<?= e($cardPanelId) ?>"
                            data-status-card-toggle
                        >
                            <span class="billing-ref-status-top">
                                <span class="billing-ref-pill <?= e((string) ($statusCard['tone'] ?? '')) ?>"><?= e((string) ($statusCard['label'] ?? '-')) ?></span>
                                <i class="bi bi-chevron-down billing-ref-status-chevron" aria-hidden="true"></i>
                            </span>
                            <span class="billing-ref-status-summary">
                                <span>Total: <strong data-status-card-total><?= e($money($cardSummary['total'] ?? 0)) ?></strong></span>
                                <span>Total curse: <strong data-status-card-count><?= e((string) ((int) ($cardSummary['total_curse'] ?? 0))) ?></strong></span>
                            </span>
                        </button>
                        <div
                            class="billing-ref-status-breakdown"
                            id="<?= e($cardPanelId) ?>"
                            role="region"
                            aria-hidden="true"
                            data-status-breakdown
                        >
                            <div class="billing-ref-status-breakdown-inner">
                                <?php foreach ($transportGroupLabels as $transportKey => $transportLabel): ?>
                                    <?php $breakdownRow = (array) (($cardSummary['breakdown'][$transportKey] ?? []) ?: []); ?>
                                    <div class="billing-ref-status-breakdown-row" data-status-breakdown-row="<?= e((string) $transportKey) ?>">
                                        <span><?= e((string) $transportLabel) ?></span>
                                        <strong><span data-status-breakdown-count><?= e((string) ((int) ($breakdownRow['curse'] ?? 0))) ?></span> curse</strong>
                                        <strong data-status-breakdown-total><?= e($money($breakdownRow['total'] ?? 0)) ?></strong>
                                        <strong>Refacturare <span data-status-breakdown-refact><?= e($money($breakdownRow['refacturare'] ?? 0)) ?></span></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="billing-ref-filter-panel <?= $filtersOpen ? 'is-open' : '' ?>" data-filter-panel>
                <div class="billing-ref-filter-header">
                    <h2 class="billing-ref-filter-title"><i class="bi bi-funnel" aria-hidden="true"></i>Filtre activitate vehicule</h2>
                    <button
                        type="button"
                        class="billing-ref-filter-toggle"
                        aria-expanded="<?= $filtersOpen ? 'true' : 'false' ?>"
                        data-filter-toggle
                    >
                        <span data-filter-toggle-label><?= $filtersOpen ? 'Ascunde filtrele' : 'Afișează filtrele' ?></span>
                        <i class="bi bi-chevron-up" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="billing-ref-filter-body">
                    <div class="billing-ref-filter-inner">
                        <form method="get" data-filter-form>
                            <input type="hidden" name="page" value="<?= e($activityPageKey) ?>">
                            <input type="hidden" name="action" value="index">
                            <input type="hidden" name="filters_open" value="1">

                            <div class="billing-ref-filter-grid">
                                <div class="billing-ref-field">
                                    <label>Nr. înmatriculare</label>
                                    <?= $renderMultiSelect(
                                        'billing_filter_vehicles',
                                        'vehicle_id[]',
                                        array_map(static fn (array $vehicle): array => [
                                            'value' => (string) ((int) ($vehicle['id'] ?? 0)),
                                            'label' => (string) ($vehicle['nr_inmatriculare'] ?? '-'),
                                        ], $vehicles),
                                        $filterArray('vehicle_id')
                                    ) ?>
                                    <?php if ($filterValue('nr_inmatriculare') !== ''): ?>
                                        <input type="hidden" name="nr_inmatriculare" value="<?= e($filterValue('nr_inmatriculare')) ?>">
                                    <?php endif; ?>
                                </div>

                                <div class="billing-ref-field" data-transport-filter>
                                    <label>Tip transport</label>
                                    <?= $renderMultiSelect(
                                        'billing_filter_tip_transport',
                                        'tip_transport[]',
                                        array_map(static fn ($typeKey, $typeLabel): array => ['value' => (string) $typeKey, 'label' => (string) $typeLabel], array_keys($transportTypes), $transportTypes),
                                        $filterArray('tip_transport')
                                    ) ?>
                                </div>

                                <div class="billing-ref-field billing-ref-location-block" data-location-block="all">
                                    <label>Locație operațională</label>
                                    <?= $renderMultiSelect('billing_locations_all', 'locatie_operationala[]', $locationOptionRows($locationOptions, 'all'), $filterArray('locatie_operationala')) ?>
                                </div>

                                <div class="billing-ref-field billing-ref-location-block" data-location-block="primar" hidden>
                                    <label>Loc de încărcare</label>
                                    <?= $renderMultiSelect('billing_locations_primar', 'locatie_operationala[]', $locationOptionRows($locationOptions, 'primar'), $filterArray('locatie_operationala')) ?>
                                </div>

                                <div class="billing-ref-field billing-ref-location-block" data-location-block="distributie" hidden>
                                    <label>Zonă de distribuție</label>
                                    <?= $renderMultiSelect('billing_locations_distributie', 'locatie_operationala[]', $locationOptionRows($locationOptions, 'distributie'), $filterArray('locatie_operationala')) ?>
                                </div>

                                <div class="billing-ref-field billing-ref-location-block" data-location-block="compresor" hidden>
                                    <label>Locație activitate</label>
                                    <?= $renderMultiSelect('billing_locations_compresor', 'locatie_operationala[]', $locationOptionRows($locationOptions, 'compresor'), $filterArray('locatie_operationala')) ?>
                                </div>

                                <div class="billing-ref-field billing-ref-location-block" data-location-block="pd" hidden>
                                    <div class="billing-ref-location-dual">
                                        <div>
                                            <label>Loc de încărcare</label>
                                            <?= $renderMultiSelect('billing_locations_load', 'loc_incarcare[]', $locationOptionRows($locationOptions, 'loc_incarcare'), $filterArray('loc_incarcare')) ?>
                                        </div>
                                        <div>
                                            <label>Zonă de distribuție</label>
                                            <?= $renderMultiSelect('billing_locations_zone', 'zona_distributie[]', $locationOptionRows($locationOptions, 'zona_distributie'), $filterArray('zona_distributie')) ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="billing-ref-field">
                                    <label for="billing_filter_tip_marfa">Tip marfă</label>
                                    <select class="form-select" id="billing_filter_tip_marfa" name="tip_marfa">
                                        <option value="">Selectează...</option>
                                        <?php foreach ($goodsTypes as $goodsKey => $goodsName): ?>
                                            <option value="<?= e($goodsKey) ?>" <?= $filterValue('tip_marfa') === $goodsKey ? 'selected' : '' ?>><?= e($goodsName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="billing-ref-field">
                                    <label>Beneficiar</label>
                                    <?= $renderMultiSelect(
                                        'billing_filter_beneficiar',
                                        'beneficiar_id[]',
                                        array_map(static fn (array $beneficiary): array => [
                                            'value' => (string) ((int) ($beneficiary['id'] ?? 0)),
                                            'label' => (string) ($beneficiary['nume'] ?? '-'),
                                        ], $beneficiaries),
                                        $filterArray('beneficiar_id')
                                    ) ?>
                                </div>

                                <div class="billing-ref-field">
                                    <label for="billing_filter_status">Status</label>
                                    <select class="form-select" id="billing_filter_status" name="status_facturare">
                                        <option value="">Selectează...</option>
                                        <?php foreach ($billingStatuses as $statusKey => $statusLabel): ?>
                                            <option value="<?= e((string) $statusKey) ?>" <?= $filterValue('status_facturare') === (string) $statusKey ? 'selected' : '' ?>><?= e((string) $statusLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="billing-ref-field">
                                    <label>Șofer</label>
                                    <?= $renderMultiSelect(
                                        'billing_filter_driver',
                                        'driver_id[]',
                                        array_map(static fn (array $driver): array => [
                                            'value' => (string) ((int) ($driver['id'] ?? 0)),
                                            'label' => (string) ($driver['nume'] ?? '-'),
                                        ], $drivers),
                                        $filterArray('driver_id')
                                    ) ?>
                                </div>

                                <div class="billing-ref-actions">
                                    <a class="btn btn-outline-primary" href="<?= e($resetUrl) ?>">Resetează</a>
                                    <button type="submit" class="btn btn-primary">Aplică filtre</button>
                                </div>
                            </div>

                            <p class="billing-ref-filter-note">Eticheta câmpului «Locație operațională» se adaptează după Tip transport: Primar → Loc de încărcare | Distribuție → Zonă de distribuție | Primar + Distribuție → Loc de încărcare + Zonă de distribuție | Compresor → Locație activitate</p>
                        </form>
                    </div>
                </div>
            </section>

            <?php
            /* Fara filtre: sumarul pe vehicule (ca inainte). Cu filtre aplicate: tabelul cu toate cursele pe toata zona. */
            $activityView = ($activeFilterCount > 0 || $openVehicleId > 0) && $vehicleGroups !== [] ? 'trips' : 'vehicles';
            ?>
            <section class="billing-ref-activity-shell" aria-labelledby="billing_activity_title" data-activity-view="<?= e($activityView) ?>">
                <div class="billing-ref-activity-header">
                    <div class="billing-ref-activity-heading">
                        <div>
                            <h2 class="billing-ref-activity-title" id="billing_activity_title">Activitate vehicule</h2>
                            <p class="billing-ref-activity-subtitle" data-activity-subtitle="vehicles">Vehicule care au transportat după numărul de înmatriculare. Apasă pe un vehicul pentru a vedea cursele și calculul de facturare.</p>
                            <p class="billing-ref-activity-subtitle" data-activity-subtitle="trips">Toate cursele din filtrul curent. Bifează cursele și schimbă-le statusul în bloc.</p>
                        </div>
                        <?php if ($vehicleGroups !== []): ?>
                            <div class="billing-ref-view-switch" role="group" aria-label="Mod de afișare">
                                <button type="button" data-activity-view-button="vehicles">Sumar vehicule</button>
                                <button type="button" data-activity-view-button="trips">Curse</button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="billing-ref-ajax-error" data-status-error role="alert"></div>
                </div>

                <?php if ($vehicleGroups === []): ?>
                    <div class="billing-ref-activity-scroll">
                        <div class="billing-ref-empty">Nu există curse pentru filtrul curent.</div>
                    </div>
                <?php else: ?>
                    <?php
                    $bulkRaceIds = [];
                    foreach ($vehicleGroups as $bulkGroup) {
                        foreach ((array) ($bulkGroup['rows'] ?? []) as $bulkRow) {
                            $bulkRaceId = (int) ($bulkRow['id'] ?? 0);
                            if ($bulkRaceId > 0) {
                                $bulkRaceIds[$bulkRaceId] = $bulkRaceId;
                            }
                        }
                    }
                    ?>
                    <div class="billing-ref-activity-scroll billing-ref-vehicle-list">
                        <?php foreach ($vehicleGroups as $vehicleGroup): ?>
                            <?php $vehicleId = (int) ($vehicleGroup['vehicle_id'] ?? 0); ?>
                            <article class="billing-ref-vehicle <?= $vehicleId === $openVehicleId ? 'is-active' : '' ?>" data-vehicle data-vehicle-id="<?= e((string) $vehicleId) ?>">
                                <button
                                    type="button"
                                    class="billing-ref-vehicle-summary"
                                    aria-pressed="<?= $vehicleId === $openVehicleId ? 'true' : 'false' ?>"
                                    data-vehicle-label="<?= e((string) ($vehicleGroup['nr_inmatriculare'] ?? '-')) ?>"
                                    data-vehicle-toggle
                                >
                                    <span class="billing-ref-vehicle-title">
                                        <strong><?= e((string) ($vehicleGroup['nr_inmatriculare'] ?? '-')) ?></strong>
                                        <span><?= e((string) ((int) ($vehicleGroup['curse'] ?? 0))) ?> curse</span>
                                    </span>
                                    <span class="billing-ref-vehicle-metric"><span>Tone</span><?= e($formatTons($vehicleGroup['tone'] ?? 0)) ?></span>
                                    <span class="billing-ref-vehicle-metric"><span>Km facturați</span><?= e($formatKm($vehicleGroup['km_facturati'] ?? 0)) ?></span>
                                    <span class="billing-ref-vehicle-metric"><span>Km rulați</span><?= e($formatKm($vehicleGroup['km_rulati'] ?? 0)) ?></span>
                                    <span class="billing-ref-vehicle-metric"><span>Total</span><?= e($money($vehicleGroup['value'] ?? 0)) ?></span>
                                    <span class="billing-ref-transport-counts">
                                        <?php foreach ($transportGroupLabels as $transportKey => $transportLabel): ?>
                                            <span><?= e($transportLabel) ?> <?= e((string) ((int) (($vehicleGroup['transport_counts'][$transportKey] ?? 0)))) ?></span>
                                        <?php endforeach; ?>
                                    </span>
                                </button>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="billing-ref-trips-panel">
                        <div class="billing-ref-trips-head">
                            <h3 class="billing-ref-trips-title">
                                Curse <span data-trips-visible-count><?= e((string) count($bulkRaceIds)) ?></span>
                                <span class="billing-ref-trips-vehicle-chip" data-trips-vehicle-chip hidden>
                                    <span data-trips-vehicle-label></span>
                                    <button type="button" aria-label="Arată cursele tuturor vehiculelor" data-trips-vehicle-clear>&times;</button>
                                </span>
                            </h3>
                            <form method="post" action="<?= e(build_query_url(['page' => $activityPageKey, 'action' => 'bulk_update_status'])) ?>" class="billing-ref-bulk-status" data-bulk-status-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="race_ids" value="<?= e(implode(',', $bulkRaceIds)) ?>">
                                <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                                <span class="billing-ref-bulk-label">Bifate: <strong data-bulk-selected-count><?= e((string) count($bulkRaceIds)) ?></strong></span>
                                <button type="button" class="btn btn-sm btn-link px-1" data-bulk-select-all="1">Bifează toate</button>
                                <button type="button" class="btn btn-sm btn-link px-1" data-bulk-select-all="0">Debifează toate</button>
                                <span class="billing-ref-bulk-hint" title="Apasă pe coloana de bife și trage peste rânduri, sau Shift + click pentru un interval.">(trage pe bife sau Shift + click)</span>
                                <span class="billing-ref-bulk-label">→ schimbă statusul în:</span>
                                <select name="status_facturare" class="form-select form-select-sm" data-bulk-status-select required>
                                    <option value="">Alege statusul…</option>
                                    <?php foreach ($billingStatuses as $optionKey => $optionLabel): ?>
                                        <option value="<?= e((string) $optionKey) ?>"><?= e((string) $optionLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bulk-status-ask disabled>Aplică</button>
                                <span class="billing-ref-bulk-confirm" data-bulk-status-confirm hidden>
                                    <span data-bulk-status-question></span>
                                    <button type="submit" class="btn btn-sm btn-primary">Da, aplică</button>
                                    <button type="button" class="btn btn-sm btn-link" data-bulk-status-cancel>Anulează</button>
                                </span>
                            </form>
                            <?php
                            /*
                             * Exportul ia randurile exact cum sunt afisate: doar cele vizibile (vehicul +
                             * filtrele din antet) si bifate, in ordinea curenta (inclusiv sortarea din antet).
                             * Rezumatul filtrelor din formular ajunge in capul fisierului.
                             */
                            $exportFilterParts = [];
                            $namesFor = static function (array $ids, array $list, string $idKey, string $labelKey): string {
                                $names = [];
                                foreach ($list as $item) {
                                    if (in_array((string) ((int) ($item[$idKey] ?? 0)), $ids, true)) {
                                        $names[] = (string) ($item[$labelKey] ?? '');
                                    }
                                }

                                return implode(', ', array_filter($names));
                            };
                            foreach ([
                                ['Vehicul', $namesFor($filterArray('vehicle_id'), $vehicles, 'id', 'nr_inmatriculare')],
                                ['Tip transport', implode(', ', array_map(static fn ($key): string => (string) ($transportTypes[$key] ?? $key), $filterArray('tip_transport')))],
                                ['Locație', implode(', ', array_merge($filterArray('locatie_operationala'), $filterArray('loc_incarcare'), $filterArray('zona_distributie')))],
                                ['Tip marfă', (string) ($goodsTypes[$filterValue('tip_marfa')] ?? '')],
                                ['Beneficiar', $namesFor($filterArray('beneficiar_id'), $beneficiaries, 'id', 'nume')],
                                ['Status', (string) ($billingStatuses[$filterValue('status_facturare')] ?? '')],
                                ['Șofer', $namesFor($filterArray('driver_id'), $drivers, 'id', 'nume')],
                                ['Căutare', $search],
                            ] as [$exportLabel, $exportValue]) {
                                if (trim((string) $exportValue) !== '') {
                                    $exportFilterParts[] = $exportLabel . ': ' . $exportValue;
                                }
                            }
                            ?>
                            <form method="post" action="<?= e(build_query_url(['page' => $activityPageKey, 'action' => 'export_trips'])) ?>" class="billing-ref-trips-export" data-trips-export-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="filters_summary" value="<?= e(implode(' · ', $exportFilterParts)) ?>">
                                <input type="hidden" name="payload" value="" data-trips-export-payload>
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Exportă în Excel cursele afișate și bifate, în ordinea din tabel">
                                    <i class="bi bi-file-earmark-excel-fill" aria-hidden="true"></i> Export Excel (<span data-trips-export-count><?= e((string) count($bulkRaceIds)) ?></span>)
                                </button>
                            </form>
                        </div>

                        <div class="billing-ref-activity-scroll billing-ref-trips-scroll">
                            <?php /* data-column-filter: sortare si filtrare din antet (assets/js/table-column-filter.js). */ ?>
                            <table class="billing-ref-trip-table" data-column-filter>
                                <thead>
                                    <tr>
                                        <th class="billing-ref-bulk-cell" data-no-filter><input type="checkbox" class="form-check-input" checked data-bulk-check-vehicle aria-label="Bifează toate cursele afișate"></th>
                                        <th data-no-filter>#</th>
                                        <th>Cursă</th>
                                        <th>Vehicul</th>
                                        <th>Șofer</th>
                                        <th>Transport</th>
                                        <th>Marfă</th>
                                        <th>Tone</th>
                                        <th>Km facturați</th>
                                        <th>Km rulați</th>
                                        <th>Facturat</th>
                                        <th>Calcul</th>
                                        <?php /* Statusul se schimba din celula (dropdown), deci nu se filtreaza de aici. */ ?>
                                        <th data-no-filter>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $canOpenTrip = can_route('dispecer_curse'); ?>
                                    <?php foreach ($vehicleGroups as $vehicleGroup): ?>
                                        <?php $vehicleId = (int) ($vehicleGroup['vehicle_id'] ?? 0); ?>
                                        <?php foreach ((array) ($vehicleGroup['rows'] ?? []) as $row): ?>
                                            <?php
                                            $raceId = (int) ($row['id'] ?? 0);
                                            $statusKey = (string) ($row['_status_key'] ?? 'in_curs_facturare');
                                            $rowTone = (string) ($row['_status_tone'] ?? 'is-neutral');
                                            ?>
                                            <tr
                                                class="billing-ref-trip-row <?= e($rowTone) ?>"
                                                data-trip-row
                                                data-race-id="<?= e((string) $raceId) ?>"
                                                data-trip-vehicle-id="<?= e((string) $vehicleId) ?>"
                                                data-status="<?= e($statusKey) ?>"
                                                data-transport-group="<?= e((string) ($row['_transport_group'] ?? 'primar')) ?>"
                                                data-transport-value="<?= e((string) ((float) ($row['total_facturare'] ?? 0))) ?>"
                                                data-emitted-value="<?= e((string) ((float) ($row['_invoiced_refacturare_total'] ?? 0))) ?>"
                                                data-refact-value="<?= e((string) ((float) ($row['_refacturare_total'] ?? 0))) ?>"
                                                <?php if ($canOpenTrip): ?>
                                                    data-row-href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>"
                                                    tabindex="0"
                                                    title="Deschide cursa în Dispecer curse"
                                                <?php endif; ?>
                                            >
                                                <td class="billing-ref-bulk-cell"><input type="checkbox" class="form-check-input" checked value="<?= e((string) $raceId) ?>" data-bulk-check aria-label="Bifează cursa #<?= e((string) $raceId) ?>"></td>
                                                <td data-trip-index></td>
                                                <td>
                                                    <span class="billing-ref-trip-main">
                                                        <span><?= e((string) ($row['_route_start'] ?? '-')) ?> &rarr; <?= e((string) ($row['_route_end'] ?? '-')) ?></span>
                                                    </span>
                                                </td>
                                                <td class="billing-ref-trip-vehicle"><?= e((string) ($vehicleGroup['nr_inmatriculare'] ?? '-')) ?></td>
                                                <td><?= e($show($row['sofer_nume'] ?? '')) ?></td>
                                                <td><?= e((string) ($row['_transport_label'] ?? '-')) ?></td>
                                                <td><?= e((string) ($row['_goods_label'] ?? '-')) ?></td>
                                                <td><?= e($formatTons($row['_tons_value'] ?? 0)) ?></td>
                                                <td><?= e($formatKm($row['km_cursa'] ?? 0)) ?></td>
                                                <td><?= e($formatKm($row['km_rulati'] ?? 0)) ?></td>
                                                <td><?= e($money($row['_display_total'] ?? 0)) ?></td>
                                                <td><?= e((string) ($row['_formula'] ?? '-')) ?></td>
                                                <td>
                                                    <form method="post" action="<?= e(build_query_url(['page' => $activityPageKey, 'action' => 'update_status'])) ?>" data-status-form>
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                                                        <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                                                        <div class="billing-ref-status-dropdown" data-status-dropdown>
                                                            <button class="billing-ref-status-badge dropdown-toggle <?= e($rowTone) ?>" type="button" aria-expanded="false" aria-haspopup="menu" data-status-toggle data-status-badge>
                                                                <?= e((string) ($row['_status_label'] ?? '-')) ?>
                                                            </button>
                                                            <div class="dropdown-menu billing-ref-status-menu" role="menu" data-status-menu hidden>
                                                                <?php foreach ($billingStatuses as $optionKey => $optionLabel): ?>
                                                                    <button
                                                                        type="button"
                                                                        class="dropdown-item <?= $statusKey === (string) $optionKey ? 'is-current' : '' ?>"
                                                                        data-status-option="<?= e((string) $optionKey) ?>"
                                                                        role="menuitemradio"
                                                                        aria-checked="<?= $statusKey === (string) $optionKey ? 'true' : 'false' ?>"
                                                                    >
                                                                        <?= e((string) $optionLabel) ?>
                                                                    </button>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>

        <aside class="billing-ref-finance-column" aria-label="Tip marfă, cheltuieli și refacturare">
            <article class="billing-ref-kpi-card is-purple">
                <p class="billing-ref-kpi-label">Tip marfă</p>
                <strong class="billing-ref-kpi-value"><?= e($formatTons($totalTons)) ?></strong>
                <div class="billing-ref-kpi-lines">
                    <?php foreach ($goodsTypes as $goodsKey => $goodsName): ?>
                        <div class="billing-ref-kpi-row">
                            <span><?= e($goodsName) ?></span>
                            <strong><?= e($formatTons($cargoTotals[$goodsKey] ?? 0)) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="billing-ref-finance-card is-expense">
                <span class="billing-ref-finance-title">Cheltuieli</span>
                <strong class="billing-ref-finance-total"><?= e($money($totalExpenses)) ?></strong>
                <div class="billing-ref-money-list">
                    <?= $renderFinancialRows($expenseTotalsByType, $financialOrder, $expenseTypes, $money) ?>
                </div>
            </article>

            <article class="billing-ref-finance-card is-refact">
                <span class="billing-ref-finance-title">De refacturat</span>
                <strong class="billing-ref-finance-total"><?= e($money($totalRefacturare)) ?></strong>
                <div class="billing-ref-money-list">
                    <?= $renderFinancialRows($refacturareTotalsByType, $financialOrder, $expenseTypes, $money) ?>
                </div>
            </article>
        </aside>
    </div>
</div>

<style>
.billing-ref-bulk-status { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .75rem; padding: .6rem .75rem; border: 1px dashed #b9c6e4; border-radius: 10px; background: #f6f8fd; font-size: .9rem; }
.billing-ref-bulk-status .form-select { width: auto; min-width: 160px; }
.billing-ref-bulk-confirm { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .5rem; font-weight: 600; color: #8a4b00; }
.billing-ref-bulk-confirm[hidden] { display: none; }
/* Un singur mod vizibil: sumarul pe vehicule (fara filtre) sau tabelul de curse (cu filtre). */
.billing-ref-activity-shell[data-activity-view="vehicles"] .billing-ref-trips-panel,
.billing-ref-activity-shell[data-activity-view="trips"] .billing-ref-vehicle-list,
.billing-ref-activity-shell[data-activity-view="vehicles"] [data-activity-subtitle="trips"],
.billing-ref-activity-shell[data-activity-view="trips"] [data-activity-subtitle="vehicles"] { display: none; }
.billing-ref-activity-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
.billing-ref-view-switch { display: inline-flex; flex: 0 0 auto; padding: 2px; border: 1px solid #cfdcf0; border-radius: 8px; background: #f6f8fd; }
.billing-ref-view-switch button { padding: 0.25rem 0.75rem; border: 0; border-radius: 6px; background: transparent; color: #31578d; font-size: 0.76rem; font-weight: 800; }
.billing-ref-activity-shell[data-activity-view="vehicles"] [data-activity-view-button="vehicles"],
.billing-ref-activity-shell[data-activity-view="trips"] [data-activity-view-button="trips"] { background: #0b55f4; color: #fff; }
.billing-ref-vehicle-list .billing-ref-vehicle-summary:hover { background: #f6f9ff; }
.billing-ref-trips-panel { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
.billing-ref-trips-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem 1rem; padding: 0 0 0.45rem; }
.billing-ref-trips-title { display: inline-flex; align-items: center; gap: 0.5rem; margin: 0; color: #06164c; font-size: 0.98rem; font-weight: 900; }
.billing-ref-trips-title [data-trips-visible-count] { padding: 0.05rem 0.5rem; border-radius: 999px; background: #edf4ff; color: #0b55f4; font-size: 0.78rem; }
.billing-ref-trips-vehicle-chip { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.1rem 0.25rem 0.1rem 0.6rem; border-radius: 999px; background: #0b55f4; color: #fff; font-size: 0.76rem; font-weight: 800; }
.billing-ref-trips-vehicle-chip[hidden] { display: none; }
.billing-ref-trips-vehicle-chip button { border: 0; background: transparent; color: #fff; font-size: 1rem; line-height: 1; padding: 0 0.3rem; }
.billing-ref-trips-head .billing-ref-bulk-status { margin-top: 0; padding: 0.35rem 0.6rem; }
.billing-ref-trips-scroll { display: block; overflow-x: auto; }
.billing-ref-trips-scroll .billing-ref-trip-table { min-width: 1120px; }
.billing-ref-trips-scroll .billing-ref-trip-table thead th { position: sticky; top: 0; z-index: 3; }
.billing-ref-trips-scroll .billing-ref-trip-table thead th:last-child { z-index: 4; }
.billing-ref-trip-vehicle { font-weight: 850; white-space: nowrap; }
.billing-ref-trip-row[hidden] { display: none; }
/*
 * Randul deschide cursa in Dispecer curse, ca in Istoric activitati sofer: singurul
 * semnal este textul care devine albastru; fundalul statusului ramane neschimbat.
 */
.billing-ref-trip-row[data-row-href] { cursor: pointer; }
.billing-ref-trip-row[data-row-href] td,
.billing-ref-trip-row[data-row-href] td a,
.billing-ref-trip-row[data-row-href] td span { transition: color .2s ease; }
.billing-ref-trip-row[data-row-href]:hover td,
.billing-ref-trip-row[data-row-href]:hover td a,
.billing-ref-trip-row[data-row-href]:hover .billing-ref-trip-main span,
.billing-ref-trip-row[data-row-href]:focus-visible td,
.billing-ref-trip-row[data-row-href]:focus-visible td a,
.billing-ref-trip-row[data-row-href]:focus-visible .billing-ref-trip-main span { color: #2563eb; }
.billing-ref-trip-row[data-row-href]:focus { outline: none; }
.billing-ref-trip-row[data-row-href] td:last-child { cursor: default; }
@media (prefers-reduced-motion: reduce) {
    .billing-ref-trip-row[data-row-href] td,
    .billing-ref-trip-row[data-row-href] td a,
    .billing-ref-trip-row[data-row-href] td span { transition: none; }
}
@media (max-width: 1199.98px) {
    .billing-ref-trips-scroll { max-height: 70vh; }
}
.billing-ref-trip-table .billing-ref-bulk-cell { width: 34px; text-align: center; }
.billing-ref-trip-table .billing-ref-bulk-cell .form-check-input { margin: 0; cursor: pointer; }
/* Selectia prin tragere pe coloana de bife: fara selectie de text cat tine apasat. */
.billing-ref-trip-table tbody td.billing-ref-bulk-cell { cursor: ns-resize; }
.billing-ref-trip-table.is-drag-selecting,
.billing-ref-trip-table.is-drag-selecting * { user-select: none; cursor: ns-resize !important; }
.billing-ref-bulk-hint { color: #64748b; font-size: .78rem; }
.billing-ref-trips-export { margin-top: .75rem; }
.billing-ref-trips-export .btn { font-weight: 700; white-space: nowrap; }
</style>
<?php /* Sortare + filtrare din antetul tabelului de curse (comun cu Istoric activitati sofer). */ ?>
<script src="<?= e(url('assets/js/table-column-filter.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/table-column-filter.js'))) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var bulkForm = document.querySelector('[data-bulk-status-form]');
    if (bulkForm) {
        var bulkSelect = bulkForm.querySelector('[data-bulk-status-select]');
        var bulkAsk = bulkForm.querySelector('[data-bulk-status-ask]');
        var bulkConfirm = bulkForm.querySelector('[data-bulk-status-confirm]');
        var bulkQuestion = bulkForm.querySelector('[data-bulk-status-question]');
        var bulkCountEl = bulkForm.querySelector('[data-bulk-selected-count]');
        var bulkIdsInput = bulkForm.querySelector('input[name="race_ids"]');
        var bulkChecks = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-check]'));
        var headCheck = document.querySelector('[data-bulk-check-vehicle]');
        var exportForm = document.querySelector('[data-trips-export-form]');
        var exportCountEl = document.querySelector('[data-trips-export-count]');
        var visibleCountEl = document.querySelector('[data-trips-visible-count]');
        var vehicleChip = document.querySelector('[data-trips-vehicle-chip]');
        var vehicleChipLabel = document.querySelector('[data-trips-vehicle-label]');
        var isVisible = function (box) {
            return !box.closest('[data-trip-row]').hidden;
        };
        /* Actiunea in bloc lucreaza doar pe cursele afisate (dupa vehiculul ales) si bifate. */
        var selectedIds = function () {
            return bulkChecks.filter(function (box) { return box.checked && isVisible(box); }).map(function (box) { return box.value; });
        };
        var resetBulk = function () {
            var ids = selectedIds();
            var visible = bulkChecks.filter(isVisible);
            bulkIdsInput.value = ids.join(',');
            bulkCountEl.textContent = ids.length + ' din ' + visible.length;
            if (exportCountEl) exportCountEl.textContent = String(ids.length);
            bulkConfirm.hidden = true;
            bulkAsk.hidden = false;
            bulkAsk.disabled = bulkSelect.value === '' || ids.length === 0;
            if (headCheck) {
                headCheck.checked = visible.length > 0 && ids.length === visible.length;
                headCheck.indeterminate = ids.length > 0 && ids.length < visible.length;
            }
        };
        var setAllVisible = function (value) {
            bulkChecks.filter(isVisible).forEach(function (box) { box.checked = value; });
            resetBulk();
        };
        var tripTable = document.querySelector('.billing-ref-trip-table');
        /* Numerotarea si contorul se recalculeaza dupa randurile chiar vizibile (vehicul + filtre antet). */
        var renumberTrips = function () {
            var index = 0;
            document.querySelectorAll('[data-trip-row]').forEach(function (row) {
                var indexCell = row.querySelector('[data-trip-index]');
                if (!row.hidden) {
                    index += 1;
                    if (indexCell) indexCell.textContent = String(index);
                } else if (indexCell) {
                    indexCell.textContent = '';
                }
            });
            if (visibleCountEl) visibleCountEl.textContent = String(index);

            return index;
        };
        var showVehicleTrips = function (vehicleId, label) {
            document.querySelectorAll('[data-trip-row]').forEach(function (row) {
                var outsideVehicle = vehicleId !== '' && row.getAttribute('data-trip-vehicle-id') !== vehicleId;
                // Filtrele din antet respecta acest marcaj (data-external-hidden).
                row.setAttribute('data-external-hidden', outsideVehicle ? '1' : '');
                row.hidden = outsideVehicle;
            });
            if (tripTable && typeof tripTable.columnFilterRefresh === 'function') {
                tripTable.columnFilterRefresh();
            }
            var index = renumberTrips();
            document.querySelectorAll('[data-vehicle]').forEach(function (vehicle) {
                var active = vehicleId !== '' && vehicle.getAttribute('data-vehicle-id') === vehicleId;
                vehicle.classList.toggle('is-active', active);
                var button = vehicle.querySelector('[data-vehicle-toggle]');
                if (button) button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            if (visibleCountEl) visibleCountEl.textContent = String(index);
            if (vehicleChip) vehicleChip.hidden = vehicleId === '';
            if (vehicleChipLabel) vehicleChipLabel.textContent = label || '';
            var tripsScroll = document.querySelector('.billing-ref-trips-scroll');
            if (tripsScroll) tripsScroll.scrollTop = 0;
            resetBulk();
        };
        /* Filtrele din antetul tabelului: renumerotam si recalculam selectia in bloc. */
        if (tripTable) {
            tripTable.addEventListener('columnfilter:applied', function () {
                renumberTrips();
                resetBulk();
            });
        }
        var activityShell = document.querySelector('[data-activity-view]');
        var setActivityView = function (view) {
            if (activityShell) activityShell.setAttribute('data-activity-view', view);
        };
        document.querySelectorAll('[data-activity-view-button]').forEach(function (button) {
            button.addEventListener('click', function () {
                var view = button.getAttribute('data-activity-view-button');
                /* Tab-ul „Curse” arata mereu toate cursele filtrului; vehiculul se alege din sumar. */
                if (view === 'trips') showVehicleTrips('', '');
                setActivityView(view);
            });
        });
        /* Click pe vehicul in sumar: deschide tabelul de curse doar pentru acel vehicul. */
        document.querySelectorAll('[data-vehicle-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var vehicle = button.closest('[data-vehicle]');
                showVehicleTrips(vehicle.getAttribute('data-vehicle-id') || '', button.getAttribute('data-vehicle-label'));
                setActivityView('trips');
            });
        });
        var chipClear = document.querySelector('[data-trips-vehicle-clear]');
        if (chipClear) chipClear.addEventListener('click', function () { showVehicleTrips('', ''); });
        bulkChecks.forEach(function (box) { box.addEventListener('change', resetBulk); });
        if (headCheck) headCheck.addEventListener('change', function () { setAllVisible(headCheck.checked); });

        /*
         * Selectie cu mouse-ul pe mai multe randuri:
         *  - apasa pe coloana de bife si trage in sus / jos: toate randurile parcurse
         *    primesc starea primului rand (bifat sau debifat); la marginea tabelului
         *    lista se deruleaza singura;
         *  - Shift + click pe o bifa: aplica starea pe tot intervalul de la ultima bifa.
         * Lucreaza doar pe randurile vizibile (dupa vehicul si filtrele din antet).
         */
        var tripsScrollBox = document.querySelector('.billing-ref-trips-scroll');
        var dragState = null;
        var anchorBox = null;
        var visibleChecks = function () { return bulkChecks.filter(isVisible); };
        var boxInRow = function (element) {
            var row = element && element.closest ? element.closest('[data-trip-row]') : null;
            return row && !row.hidden ? row.querySelector('[data-bulk-check]') : null;
        };
        var applyRange = function (fromBox, toBox, value) {
            var list = visibleChecks();
            var from = list.indexOf(fromBox);
            var to = list.indexOf(toBox);
            if (from < 0 || to < 0) {
                return;
            }
            for (var i = Math.min(from, to); i <= Math.max(from, to); i++) {
                list[i].checked = value;
            }
        };
        /* Click-ul care urmeaza dupa mouseup nu trebuie sa mai comute bifa sau sa deschida cursa. */
        var swallowNextClick = function () {
            var swallow = function (event) {
                event.preventDefault();
                event.stopPropagation();
            };
            document.addEventListener('click', swallow, true);
            window.setTimeout(function () { document.removeEventListener('click', swallow, true); }, 0);
        };
        var dragTo = function (element) {
            var box = boxInRow(element);
            if (dragState && box && box !== dragState.lastBox) {
                /* Intervalul fata de randul de start: si la miscari rapide nu sare niciun rand. */
                applyRange(dragState.startBox, box, dragState.value);
                dragState.lastBox = box;
                resetBulk();
            }
        };
        var stopAutoScroll = function () {
            if (dragState && dragState.timer) {
                window.clearInterval(dragState.timer);
                dragState.timer = null;
            }
        };

        if (tripTable) {
            tripTable.addEventListener('mousedown', function (event) {
                var cell = event.button === 0 ? event.target.closest('td.billing-ref-bulk-cell') : null;
                var box = cell ? boxInRow(cell) : null;
                if (!box) {
                    return;
                }
                event.preventDefault();
                var value = !box.checked;
                if (event.shiftKey && anchorBox && visibleChecks().indexOf(anchorBox) >= 0) {
                    applyRange(anchorBox, box, value);
                } else {
                    box.checked = value;
                }
                anchorBox = box;
                dragState = { startBox: box, lastBox: box, value: value, timer: null, x: event.clientX, y: event.clientY };
                tripTable.classList.add('is-drag-selecting');
                resetBulk();
            });

            document.addEventListener('mousemove', function (event) {
                if (!dragState) {
                    return;
                }
                dragState.x = event.clientX;
                dragState.y = event.clientY;
                dragTo(event.target);
                if (!tripsScrollBox) {
                    return;
                }
                var rect = tripsScrollBox.getBoundingClientRect();
                var edge = 36;
                var step = event.clientY < rect.top + edge ? -14 : (event.clientY > rect.bottom - edge ? 14 : 0);
                stopAutoScroll();
                if (step !== 0) {
                    dragState.timer = window.setInterval(function () {
                        tripsScrollBox.scrollTop += step;
                        /* Randul de sub cursor s-a schimbat prin derulare, nu prin miscare. */
                        dragTo(document.elementFromPoint(dragState.x, Math.min(Math.max(dragState.y, rect.top + 1), rect.bottom - 1)));
                    }, 30);
                }
            });

            document.addEventListener('mouseup', function () {
                if (!dragState) {
                    return;
                }
                stopAutoScroll();
                dragState = null;
                tripTable.classList.remove('is-drag-selecting');
                swallowNextClick();
                resetBulk();
            });
        }
        bulkForm.querySelectorAll('[data-bulk-select-all]').forEach(function (button) {
            button.addEventListener('click', function () {
                setAllVisible(button.getAttribute('data-bulk-select-all') === '1');
            });
        });
        bulkSelect.addEventListener('change', resetBulk);

        /*
         * Export Excel: randurile vizibile si bifate, in ordinea din tabel, cu textul din
         * celule (ce vezi e ce exporti). Fara coloana de bife; statusul se ia din eticheta.
         */
        if (exportForm && tripTable) {
            exportForm.addEventListener('submit', function (event) {
                var headCells = Array.prototype.slice.call(tripTable.querySelectorAll('thead tr:first-child th'));
                var columns = [];
                var columnIndexes = [];
                headCells.forEach(function (th, index) {
                    if (th.classList.contains('billing-ref-bulk-cell')) {
                        return;
                    }
                    var label = th.querySelector('.races-head-label') || th;
                    columns.push(label.textContent.replace(/\s+/g, ' ').trim() || 'Nr.');
                    columnIndexes.push(index);
                });
                columns.push('De refacturat');

                var rows = [];
                tripTable.querySelectorAll('tbody tr[data-trip-row]').forEach(function (row) {
                    var box = row.querySelector('[data-bulk-check]');
                    if (row.hidden || !box || !box.checked) {
                        return;
                    }
                    var cells = columnIndexes.map(function (index) {
                        var cell = row.cells[index];
                        if (!cell) {
                            return '';
                        }
                        var badge = cell.querySelector('[data-status-badge]');
                        return (badge || cell).textContent.replace(/\s+/g, ' ').trim();
                    });
                    cells.push(row.getAttribute('data-refact-value') || '0');
                    rows.push({ id: row.getAttribute('data-race-id'), status: row.getAttribute('data-status') || '', cells: cells });
                });

                if (rows.length === 0) {
                    event.preventDefault();
                    window.alert('Nu există curse bifate de exportat.');
                    return;
                }
                exportForm.querySelector('[data-trips-export-payload]').value = JSON.stringify({ columns: columns, rows: rows });
            });
        }
        bulkAsk.addEventListener('click', function () {
            var label = bulkSelect.options[bulkSelect.selectedIndex].text;
            bulkQuestion.textContent = 'Confirmi trecerea a ' + selectedIds().length + ' curse pe „' + label + '”?';
            bulkAsk.hidden = true;
            bulkConfirm.hidden = false;
        });
        bulkForm.querySelector('[data-bulk-status-cancel]').addEventListener('click', resetBulk);
        bulkForm.addEventListener('submit', function (event) {
            bulkIdsInput.value = selectedIds().join(',');
            if (bulkIdsInput.value === '') {
                event.preventDefault();
            }
        });
        var activeVehicle = document.querySelector('[data-vehicle].is-active');
        showVehicleTrips(
            activeVehicle ? (activeVehicle.getAttribute('data-vehicle-id') || '') : '',
            activeVehicle ? (activeVehicle.querySelector('[data-vehicle-toggle]').getAttribute('data-vehicle-label') || '') : ''
        );
    }

    var panel = document.querySelector('[data-filter-panel]');
    var toggle = document.querySelector('[data-filter-toggle]');
    var transportFilter = document.querySelector('[data-transport-filter]');
    var statusError = document.querySelector('[data-status-error]');
    var moneyFormatter = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var statusConfig = {
        facturat: { label: 'Facturat', tone: 'is-success' },
        in_curs_facturare: { label: 'În curs', tone: 'is-pending' },
        nefacturat: { label: 'Nefacturat', tone: 'is-danger' }
    };

    var formatMoney = function (value) {
        return moneyFormatter.format(Number(value) || 0) + ' lei';
    };

    var setFilterOpen = function (open) {
        if (!(panel instanceof HTMLElement) || !(toggle instanceof HTMLElement)) {
            return;
        }

        panel.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        var label = toggle.querySelector('[data-filter-toggle-label]');
        if (label instanceof HTMLElement) {
            label.textContent = open ? 'Ascunde filtrele' : 'Afișează filtrele';
        }
    };

    if (toggle instanceof HTMLElement) {
        toggle.addEventListener('click', function () {
            setFilterOpen(!panel.classList.contains('is-open'));
        });
    }

    var refreshMulti = function (root) {
        var valuesWrap = root.querySelector('[data-multi-values]');
        var placeholder = root.querySelector('[data-multi-placeholder]');
        var checked = Array.prototype.slice.call(root.querySelectorAll('input[type="checkbox"]:checked:not(:disabled)'));
        if (!(valuesWrap instanceof HTMLElement) || !(placeholder instanceof HTMLElement)) {
            return;
        }

        valuesWrap.innerHTML = '';
        placeholder.style.display = checked.length > 0 ? 'none' : '';
        checked.slice(0, 2).forEach(function (input) {
            var option = input.closest('[data-multi-option]');
            var chip = document.createElement('span');
            chip.className = 'billing-ref-multi-chip';
            chip.textContent = option ? option.textContent.trim() : input.value;
            valuesWrap.appendChild(chip);
        });
        if (checked.length > 2) {
            var more = document.createElement('span');
            more.className = 'billing-ref-multi-chip';
            more.textContent = '+' + String(checked.length - 2);
            valuesWrap.appendChild(more);
        }
    };

    document.querySelectorAll('[data-multi-select]').forEach(function (root) {
        refreshMulti(root);
        root.addEventListener('change', function () {
            refreshMulti(root);
        });

        var search = root.querySelector('[data-multi-search]');
        if (search instanceof HTMLInputElement) {
            search.addEventListener('input', function () {
                var query = search.value.trim().toLowerCase();
                root.querySelectorAll('[data-multi-option]').forEach(function (option) {
                    option.style.display = option.textContent.toLowerCase().indexOf(query) !== -1 ? '' : 'none';
                });
            });
        }
    });

    var setLocationMode = function () {
        var mode = 'all';
        /* Eticheta locatiei se adapteaza doar cand e bifat un singur tip de transport. */
        var checkedTypes = transportFilter instanceof HTMLElement
            ? Array.prototype.map.call(transportFilter.querySelectorAll('input[type="checkbox"]:checked'), function (input) { return input.value; })
            : [];
        var selected = checkedTypes.length === 1 ? checkedTypes[0] : '';
        if (selected === 'primar') {
            mode = 'primar';
        } else if (selected === 'distributie') {
            mode = 'distributie';
        } else if (selected === 'primar_distributie') {
            mode = 'pd';
        } else if (selected === 'compresor') {
            mode = 'compresor';
        }

        document.querySelectorAll('[data-location-block]').forEach(function (block) {
            var active = block.getAttribute('data-location-block') === mode;
            block.hidden = !active;
            block.querySelectorAll('input, select, button').forEach(function (input) {
                if (input === transportFilter) {
                    return;
                }
                input.disabled = !active;
            });
            block.querySelectorAll('[data-multi-select]').forEach(refreshMulti);
        });
    };

    setLocationMode();
    if (transportFilter instanceof HTMLElement) {
        transportFilter.addEventListener('change', setLocationMode);
    }

    var kpiAccordionStorageKey = 'fleet.centralizator.topKpiOpen';
    var kpiAccordionNoneValue = '__none__';
    var defaultOpenKpiCard = 'total-trips';

    var kpiCardExists = function (cardKey) {
        return Array.prototype.some.call(
            document.querySelectorAll('[data-kpi-card]'),
            function (card) {
                return card.getAttribute('data-kpi-card') === cardKey;
            }
        );
    };

    var setOpenKpiCard = function (cardKey, persist) {
        var hasOpenCard = false;
        document.querySelectorAll('[data-kpi-card]').forEach(function (card) {
            var isTarget = cardKey !== '' && card.getAttribute('data-kpi-card') === cardKey;
            var toggleButton = card.querySelector('[data-kpi-toggle]');
            var details = card.querySelector('[data-kpi-details]');
            card.classList.toggle('is-open', isTarget);
            if (toggleButton instanceof HTMLElement) {
                toggleButton.setAttribute('aria-expanded', isTarget ? 'true' : 'false');
            }
            if (details instanceof HTMLElement) {
                details.setAttribute('aria-hidden', isTarget ? 'false' : 'true');
            }
            if (isTarget) {
                hasOpenCard = true;
            }
        });

        if (!persist) {
            return;
        }

        try {
            window.localStorage.setItem(kpiAccordionStorageKey, hasOpenCard ? cardKey : kpiAccordionNoneValue);
        } catch (error) {
            // Local storage is optional; the KPI accordion still works without it.
        }
    };

    document.querySelectorAll('[data-kpi-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var card = button.closest('[data-kpi-card]');
            if (!(card instanceof HTMLElement)) {
                return;
            }

            var cardKey = card.getAttribute('data-kpi-card') || '';
            setOpenKpiCard(card.classList.contains('is-open') ? '' : cardKey, true);
        });
    });

    try {
        var storedKpiCard = window.localStorage.getItem(kpiAccordionStorageKey);
        if (storedKpiCard === kpiAccordionNoneValue) {
            setOpenKpiCard('', false);
        } else if (storedKpiCard !== null && kpiCardExists(storedKpiCard)) {
            setOpenKpiCard(storedKpiCard, false);
        } else {
            setOpenKpiCard(defaultOpenKpiCard, false);
        }
    } catch (error) {
        setOpenKpiCard(defaultOpenKpiCard, false);
    }

    var statusAccordionStorageKey = 'fleet.centralizator.statusCardOpen';

    var setOpenStatusCard = function (cardKey, persist) {
        var statusGrid = document.querySelector('[data-status-grid]');
        var hasOpenCard = false;
        document.querySelectorAll('[data-status-card]').forEach(function (card) {
            var isTarget = cardKey !== '' && card.getAttribute('data-status-card') === cardKey;
            var toggleButton = card.querySelector('[data-status-card-toggle]');
            var breakdown = card.querySelector('[data-status-breakdown]');
            card.classList.toggle('is-open', isTarget);
            if (toggleButton instanceof HTMLElement) {
                toggleButton.setAttribute('aria-expanded', isTarget ? 'true' : 'false');
            }
            if (breakdown instanceof HTMLElement) {
                breakdown.setAttribute('aria-hidden', isTarget ? 'false' : 'true');
            }
            if (isTarget) {
                hasOpenCard = true;
            }
        });
        if (statusGrid instanceof HTMLElement) {
            if (hasOpenCard) {
                statusGrid.setAttribute('data-open-status', cardKey);
            } else {
                statusGrid.removeAttribute('data-open-status');
            }
        }

        if (!persist) {
            return;
        }

        try {
            if (hasOpenCard) {
                window.localStorage.setItem(statusAccordionStorageKey, cardKey);
            } else {
                window.localStorage.removeItem(statusAccordionStorageKey);
            }
        } catch (error) {
            // Local storage is optional; the accordion still works without it.
        }
    };

    document.querySelectorAll('[data-status-card-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var card = button.closest('[data-status-card]');
            if (!(card instanceof HTMLElement)) {
                return;
            }

            var cardKey = card.getAttribute('data-status-card') || '';
            setOpenStatusCard(card.classList.contains('is-open') ? '' : cardKey, true);
        });
    });

    try {
        var storedStatusCard = window.localStorage.getItem(statusAccordionStorageKey) || '';
        var storedStatusCardExists = Array.prototype.some.call(
            document.querySelectorAll('[data-status-card]'),
            function (card) {
                return card.getAttribute('data-status-card') === storedStatusCard;
            }
        );
        if (storedStatusCard !== '' && storedStatusCardExists) {
            setOpenStatusCard(storedStatusCard, false);
        }
    } catch (error) {
        // Local storage can be unavailable in private or restricted contexts.
    }

    var activeStatusMenu = null;

    var closeStatusMenu = function () {
        if (!(activeStatusMenu instanceof HTMLElement)) {
            activeStatusMenu = null;
            return;
        }

        var activeToggle = activeStatusMenu._billingRefToggle;
        activeStatusMenu.hidden = true;
        activeStatusMenu.classList.remove('show', 'is-floating');
        activeStatusMenu.style.left = '';
        activeStatusMenu.style.top = '';
        activeStatusMenu.style.minWidth = '';
        if (activeToggle instanceof HTMLElement) {
            activeToggle.setAttribute('aria-expanded', 'false');
        }
        activeStatusMenu = null;
    };

    var openStatusMenu = function (toggleButton, menu) {
        closeStatusMenu();
        if (!(toggleButton instanceof HTMLElement) || !(menu instanceof HTMLElement)) {
            return;
        }

        var gap = 6;
        var buttonRect = toggleButton.getBoundingClientRect();
        menu._billingRefToggle = toggleButton;
        menu.hidden = false;
        menu.classList.add('show', 'is-floating');
        menu.style.minWidth = Math.max(buttonRect.width, 148) + 'px';

        var menuRect = menu.getBoundingClientRect();
        var maxLeft = Math.max(gap, window.innerWidth - menuRect.width - gap);
        var left = Math.min(Math.max(gap, buttonRect.right - menuRect.width), maxLeft);
        var spaceBelow = window.innerHeight - buttonRect.bottom - gap;
        var spaceAbove = buttonRect.top - gap;
        var opensAbove = spaceBelow < menuRect.height && spaceAbove > spaceBelow;
        var top = opensAbove ? buttonRect.top - menuRect.height - gap : buttonRect.bottom + gap;
        var maxTop = Math.max(gap, window.innerHeight - menuRect.height - gap);

        menu.style.left = Math.min(Math.max(gap, left), maxLeft) + 'px';
        menu.style.top = Math.min(Math.max(gap, top), maxTop) + 'px';
        toggleButton.setAttribute('aria-expanded', 'true');
        activeStatusMenu = menu;
    };

    document.querySelectorAll('[data-status-dropdown]').forEach(function (dropdown) {
        var toggleButton = dropdown.querySelector('[data-status-toggle]');
        var menu = dropdown.querySelector('[data-status-menu]');
        if (!(toggleButton instanceof HTMLElement) || !(menu instanceof HTMLElement)) {
            return;
        }

        toggleButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (activeStatusMenu === menu) {
                closeStatusMenu();
                return;
            }
            openStatusMenu(toggleButton, menu);
        });

        toggleButton.addEventListener('keydown', function (event) {
            if (!['Enter', ' ', 'ArrowDown'].includes(event.key)) {
                return;
            }

            event.preventDefault();
            openStatusMenu(toggleButton, menu);
            var current = menu.querySelector('.is-current');
            var firstOption = menu.querySelector('[data-status-option]');
            (current instanceof HTMLElement ? current : firstOption)?.focus();
        });

        menu.addEventListener('keydown', function (event) {
            var options = Array.prototype.slice.call(menu.querySelectorAll('[data-status-option]'));
            var currentIndex = options.indexOf(document.activeElement);

            if (event.key === 'Escape') {
                event.preventDefault();
                closeStatusMenu();
                toggleButton.focus();
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                var direction = event.key === 'ArrowDown' ? 1 : -1;
                var nextIndex = currentIndex >= 0 ? currentIndex + direction : 0;
                if (nextIndex < 0) nextIndex = options.length - 1;
                if (nextIndex >= options.length) nextIndex = 0;
                if (options[nextIndex] instanceof HTMLElement) {
                    options[nextIndex].focus();
                }
            }
        });
    });

    document.addEventListener('pointerdown', function (event) {
        if (!(activeStatusMenu instanceof HTMLElement)) {
            return;
        }

        var activeToggle = activeStatusMenu._billingRefToggle;
        if (activeStatusMenu.contains(event.target) || (activeToggle instanceof HTMLElement && activeToggle.contains(event.target))) {
            return;
        }

        closeStatusMenu();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeStatusMenu();
        }
    });

    window.addEventListener('resize', closeStatusMenu);
    document.querySelectorAll('.billing-ref-activity-scroll').forEach(function (scrollArea) {
        scrollArea.addEventListener('scroll', closeStatusMenu, { passive: true });
    });

    var recalculateStatusCards = function () {
        var transportGroups = ['primar', 'distributie', 'primar_distributie', 'compresor'];
        var buildBucket = function () {
            var bucket = { count: 0, transport: 0, refact: 0, breakdown: {} };
            transportGroups.forEach(function (transportGroup) {
                bucket.breakdown[transportGroup] = { count: 0, transport: 0, refact: 0 };
            });
            return bucket;
        };
        var totals = {
            facturat: buildBucket(),
            open: buildBucket(),
            nefacturat: buildBucket()
        };

        document.querySelectorAll('[data-trip-row]').forEach(function (row) {
            var status = row.getAttribute('data-status') || 'in_curs_facturare';
            var transportGroup = row.getAttribute('data-transport-group') || 'primar';
            if (transportGroups.indexOf(transportGroup) === -1) {
                transportGroup = 'primar';
            }
            var transport = Number(row.getAttribute('data-transport-value')) || 0;
            var refact = Number(row.getAttribute('data-refact-value')) || 0;
            var addTo = function (key) {
                totals[key].count += 1;
                totals[key].transport += transport;
                totals[key].refact += refact;
                totals[key].breakdown[transportGroup].count += 1;
                totals[key].breakdown[transportGroup].transport += transport;
                totals[key].breakdown[transportGroup].refact += refact;
            };

            if (status === 'facturat') {
                addTo('facturat');
            }
            if (status === 'in_curs_facturare' || status === 'nefacturat') {
                addTo('open');
            }
            if (status === 'nefacturat') {
                addTo('nefacturat');
            }
        });

        Object.keys(totals).forEach(function (key) {
            var card = document.querySelector('[data-status-card="' + key + '"]');
            if (!(card instanceof HTMLElement)) {
                return;
            }

            var countEl = card.querySelector('[data-status-card-count]');
            var totalEl = card.querySelector('[data-status-card-total]');

            if (countEl instanceof HTMLElement) countEl.textContent = String(totals[key].count);
            if (totalEl instanceof HTMLElement) totalEl.textContent = formatMoney(totals[key].transport);

            transportGroups.forEach(function (transportGroup) {
                var row = card.querySelector('[data-status-breakdown-row="' + transportGroup + '"]');
                if (!(row instanceof HTMLElement)) {
                    return;
                }

                var groupTotals = totals[key].breakdown[transportGroup];
                var groupCount = row.querySelector('[data-status-breakdown-count]');
                var groupTotal = row.querySelector('[data-status-breakdown-total]');
                var groupRefact = row.querySelector('[data-status-breakdown-refact]');
                if (groupCount instanceof HTMLElement) groupCount.textContent = String(groupTotals.count);
                if (groupTotal instanceof HTMLElement) groupTotal.textContent = formatMoney(groupTotals.transport);
                if (groupRefact instanceof HTMLElement) groupRefact.textContent = formatMoney(groupTotals.refact);
            });
        });
    };

    var setStatusError = function (message) {
        if (!(statusError instanceof HTMLElement)) {
            return;
        }
        statusError.textContent = message || '';
        statusError.classList.toggle('is-visible', Boolean(message));
    };

    document.querySelectorAll('[data-status-form]').forEach(function (form) {
        form.querySelectorAll('[data-status-option]').forEach(function (option) {
            option.addEventListener('click', function () {
                var nextStatus = option.getAttribute('data-status-option') || '';
                var row = form.closest('[data-trip-row]');
                if (!(row instanceof HTMLElement) || !statusConfig[nextStatus]) {
                    return;
                }

                closeStatusMenu();
                var formData = new FormData(form);
                formData.set('status_facturare', nextStatus);
                setStatusError('');
                form.querySelectorAll('button').forEach(function (button) {
                    button.disabled = true;
                });

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                }).then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok || !payload.ok) {
                            throw new Error(payload.message || 'Nu s-a putut actualiza statusul.');
                        }
                        return payload;
                    });
                }).then(function (payload) {
                    var previous = row.getAttribute('data-status') || 'in_curs_facturare';
                    var previousTone = statusConfig[previous] ? statusConfig[previous].tone : '';
                    var nextTone = statusConfig[nextStatus].tone;
                    var badge = row.querySelector('[data-status-badge]');

                    row.setAttribute('data-status', nextStatus);
                    if (previousTone) row.classList.remove(previousTone);
                    row.classList.add(nextTone);
                    if (badge instanceof HTMLElement) {
                        if (previousTone) badge.classList.remove(previousTone);
                        badge.classList.add(nextTone);
                        badge.textContent = payload.label || statusConfig[nextStatus].label;
                    }

                    form.querySelectorAll('[data-status-option]').forEach(function (button) {
                        var isCurrent = button.getAttribute('data-status-option') === nextStatus;
                        button.classList.toggle('is-current', isCurrent);
                        button.setAttribute('aria-checked', isCurrent ? 'true' : 'false');
                    });
                    recalculateStatusCards();
                }).catch(function (error) {
                    setStatusError(error.message || 'Nu s-a putut actualiza statusul.');
                }).finally(function () {
                    form.querySelectorAll('button').forEach(function (button) {
                        button.disabled = false;
                    });
                });
            });
        });
    });

    /*
     * Tabelul Curse: randul intreg deschide cursa in Dispecer curse (ca in Istoric
     * activitati sofer). Bifa, statusul si linkurile isi pastreaza actiunea;
     * Ctrl / Cmd / click cu rotita deschid in tab nou.
     */
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
    var isTripRowControl = function (target) {
        return !!target.closest('a, button, input, select, label, form, .billing-ref-bulk-cell');
    };

    document.addEventListener('click', function (event) {
        var row = event.target.closest('tr.billing-ref-trip-row[data-row-href]');
        if (!row || isTripRowControl(event.target)) {
            return;
        }
        if (window.getSelection && String(window.getSelection()).length > 0) {
            return;
        }
        openTripRow(row, event.ctrlKey || event.metaKey);
    });

    document.addEventListener('auxclick', function (event) {
        var row = event.button === 1 ? event.target.closest('tr.billing-ref-trip-row[data-row-href]') : null;
        if (row && !isTripRowControl(event.target)) {
            event.preventDefault();
            openTripRow(row, true);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.matches && event.target.matches('tr.billing-ref-trip-row[data-row-href]')) {
            openTripRow(event.target, event.ctrlKey || event.metaKey);
        }
    });
});
</script>
