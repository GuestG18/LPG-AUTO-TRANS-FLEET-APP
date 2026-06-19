<?php
$baseQuery = [
    'page' => 'centralizator_facturare',
    'action' => 'index',
    'q' => $search,
    'status_facturare' => $filters['status_facturare'] ?? '',
    'tip_transport' => $filters['tip_transport'] ?? '',
    'vehicle_id' => $filters['vehicle_id'] ?? '',
    'beneficiar_id' => $filters['beneficiar_id'] ?? '',
    'zona_distributie_id' => $filters['zona_distributie_id'] ?? '',
    'data_start' => $filters['data_start'] ?? '',
    'data_end' => $filters['data_end'] ?? '',
];
$currentListUrl = build_query_url(array_merge($baseQuery, ['p' => (int) ($pagination['page'] ?? 1)]));

$expenseTypes = [
    'motorina' => 'Motorina',
    'taxe_drum' => 'Taxe drum',
    'diurna' => 'Diurna',
    'service' => 'Reparatii',
    'alte' => 'Alte cheltuieli',
];
$goodsTypes = [
    'butan' => 'Butan',
    'propan' => 'Propan',
    'autogaz' => 'Autogaz',
];
$statusToneClasses = [
    'in_curs_facturare' => 'is-pending',
    'nefacturat' => 'is-danger',
    'facturat' => 'is-success',
];
$summaryMetricDefaults = [
    'total_curse' => 0,
    'total_facturare' => 0.0,
    'total_refacturare_facturata' => 0.0,
    'total_refacturare' => 0.0,
    'total_cheltuieli' => 0.0,
    'sold_estimativ' => 0.0,
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

$formatHours = static function (mixed $value): string {
    $hours = is_numeric((string) $value) ? (float) $value : 0.0;
    return format_number_ro($hours, $hours === floor($hours) ? 0 : 2) . ' h';
};

$formatDate = static function (mixed $value): string {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? format_date_ro($value) : '-';
};

$formatDateRange = static function (array $row): string {
    $start = trim((string) (($row['data_inceput'] ?? '') !== '' ? $row['data_inceput'] : ($row['data_cursa'] ?? '')));
    $end = trim((string) ($row['data_sfarsit'] ?? ''));
    if ($start === '' && $end === '') {
        return '-';
    }

    $label = $start !== '' ? format_date_ro($start) : '-';
    return $end !== '' && $end !== $start ? $label . ' - ' . format_date_ro($end) : $label;
};

$formatTimeRange = static function (array $row): string {
    $start = substr(trim((string) ($row['ora_inceput'] ?? '')), 0, 5);
    $end = substr(trim((string) ($row['ora_sfarsit'] ?? '')), 0, 5);
    if ($start === '' && $end === '') {
        return '-';
    }

    return $start !== '' && $end !== '' ? $start . ' - ' . $end : ($start !== '' ? $start : $end);
};

$formatDuration = static function (mixed $minutes): string {
    if ($minutes === null || $minutes === '' || !is_numeric((string) $minutes)) {
        return '-';
    }

    $minutes = max(0, (int) $minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;

    if ($hours > 0 && $remaining > 0) {
        return $hours . 'h ' . $remaining . 'm';
    }

    return $hours > 0 ? $hours . 'h' : $remaining . 'm';
};

$formatMetric = static function (mixed $value, string $suffix = '', int $decimals = 2): string {
    if ($value === null || $value === '' || !is_numeric((string) $value)) {
        return '-';
    }

    $number = (float) $value;
    $formatted = format_number_ro($number, $number === floor($number) ? 0 : $decimals);

    return $suffix !== '' ? $formatted . ' ' . $suffix : $formatted;
};

$expenseLabel = static fn (string $type): string => (string) ($expenseTypes[$type] ?? ($type !== '' ? $type : 'Cheltuiala'));

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

$costKmLabel = static function (array $row) use ($formatMetric): string {
    $fieldByType = [
        'primar' => 'cost_km_primar',
        'primar_tona' => 'cost_km_primar',
        'distributie' => 'cost_km_distributie',
        'primar_distributie' => 'cost_km_mixt',
        'compresor' => 'cost_km_compresor',
    ];
    $field = $fieldByType[(string) ($row['tip_transport'] ?? '')] ?? '';

    return $field !== '' && (float) ($row[$field] ?? 0) > 0 ? $formatMetric($row[$field], 'lei/km') : '-';
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

$almostEqual = static fn (float $left, float $right, float $tolerance = 0.05): bool => abs($left - $right) <= $tolerance;

$buildSimpleBillingFacts = static function (array $row) use (
    $money,
    $formatKm,
    $formatTons,
    $formatHours,
    $formatMetric,
    $costKmLabel,
    $normalizeTons,
    $almostEqual
): array {
    $transportType = (string) ($row['tip_transport'] ?? '');
    $transportLabel = trim((string) ($row['_transport_label'] ?? ''));
    $goodsLabelValue = trim((string) ($row['_goods_label'] ?? ''));
    $rate = max(0.0, (float) ($row['pret_tarifare'] ?? 0));
    $total = max(0.0, (float) ($row['total_facturare'] ?? 0));
    $km = max(0.0, (float) ($row['km_cursa'] ?? 0));
    $kmTotal = max(0.0, (float) ($row['km_totali'] ?? 0));
    $kmDislocare = max(0.0, (float) ($row['km_dislocare'] ?? 0));
    $hours = max(0.0, (float) ($row['ore_aspirare'] ?? 0));
    $clients = max(0, (int) ($row['nr_clienti'] ?? 0));
    $loadedTons = $normalizeTons($row['cantitate_incarcata'] ?? null, $row['capacitate_transport'] ?? null);
    $prelevataTons = $normalizeTons($row['cantitate_prelevata'] ?? null, $row['capacitate_transport'] ?? null);
    $deliveredTons = is_numeric((string) ($row['tona_livrata'] ?? null)) && (float) ($row['tona_livrata'] ?? 0) > 0
        ? (float) $row['tona_livrata']
        : $loadedTons;
    $liquidTons = is_numeric((string) ($row['tona_aspirata_lichida'] ?? null)) && (float) ($row['tona_aspirata_lichida'] ?? 0) > 0
        ? (float) $row['tona_aspirata_lichida']
        : null;
    $gasTons = is_numeric((string) ($row['tona_aspirata_gazoasa'] ?? null)) && (float) ($row['tona_aspirata_gazoasa'] ?? 0) > 0
        ? (float) $row['tona_aspirata_gazoasa']
        : null;

    $carriedMain = '-';
    $carriedLines = [];
    $calculationLines = [];
    $invoiceFor = $transportLabel !== '' ? $transportLabel : 'Transport';

    if ($goodsLabelValue !== '' && $goodsLabelValue !== '-') {
        $carriedLines[] = 'Marfa: ' . $goodsLabelValue;
    }

    switch ($transportType) {
        case 'primar':
            $invoiceFor = 'Primar pe km';
            $carriedMain = $km > 0 ? $formatKm($km) . ' facturati' : 'Km facturati necompletati';
            if ($loadedTons !== null) {
                $carriedLines[] = 'Cantitate incarcata: ' . $formatTons($loadedTons);
            }
            if ($km > 0 && $rate > 0) {
                $calculationLines[] = $formatKm($km) . ' x ' . $money($rate) . '/km = ' . $money($km * $rate);
            }
            break;

        case 'primar_tona':
            $invoiceFor = 'Primar pe tone';
            $carriedMain = $loadedTons !== null ? $formatTons($loadedTons) . ' transportate' : 'Cantitate necompletata';
            if ($km > 0) {
                $carriedLines[] = 'Km facturati: ' . $formatKm($km);
            }
            if ($loadedTons !== null && $rate > 0) {
                $calculationLines[] = $formatTons($loadedTons) . ' x ' . $money($rate) . '/t = ' . $money($loadedTons * $rate);
            }
            break;

        case 'distributie':
            $invoiceFor = 'Distributie';
            $carriedMain = $loadedTons !== null ? $formatTons($loadedTons) . ' incarcate' : 'Cantitate necompletata';
            if ($km > 0) {
                $carriedLines[] = 'Km facturati: ' . $formatKm($km);
            }
            if ($clients > 0) {
                $carriedLines[] = 'Clienti: ' . (string) $clients;
            }
            if ($loadedTons !== null && $rate > 0 && $almostEqual($total, $loadedTons * $rate)) {
                $calculationLines[] = $formatTons($loadedTons) . ' x ' . $money($rate) . '/t = ' . $money($loadedTons * $rate);
            } elseif ($km > 0 && $rate > 0 && $almostEqual($total, $km * $rate)) {
                $calculationLines[] = $formatKm($km) . ' x ' . $money($rate) . '/km = ' . $money($km * $rate);
            } elseif ($rate > 0 && $almostEqual($total, $rate)) {
                $calculationLines[] = 'Cost fix ruta: ' . $money($rate);
            } else {
                if ($rate > 0) {
                    $calculationLines[] = 'Tarif baza salvat: ' . $money($rate);
                }
                if ($loadedTons !== null) {
                    $calculationLines[] = 'Cantitate folosita: ' . $formatTons($loadedTons);
                }
                if ($km > 0) {
                    $calculationLines[] = 'Km folositi: ' . $formatKm($km);
                }
            }
            break;

        case 'primar_distributie':
            $invoiceFor = 'Primar + distributie';
            $carriedMain = $loadedTons !== null ? $formatTons($loadedTons) . ' incarcate' : ($kmTotal > 0 ? $formatKm($kmTotal) . ' rulati' : 'Cantitate necompletata');
            if ($km > 0) {
                $carriedLines[] = 'Km primar: ' . $formatKm($km);
            }
            if ($kmTotal > 0) {
                $carriedLines[] = 'Km total: ' . $formatKm($kmTotal);
            }
            if ($clients > 0) {
                $carriedLines[] = 'Clienti: ' . (string) $clients;
            }
            if ($rate > 0) {
                $calculationLines[] = 'Tarif baza salvat: ' . $money($rate);
            }
            if ($loadedTons !== null) {
                $calculationLines[] = 'Tone folosite: ' . $formatTons($loadedTons);
            }
            if ($kmTotal > 0) {
                $calculationLines[] = 'Cost/km mixt: ' . $costKmLabel($row);
            }
            break;

        case 'compresor':
            $invoiceFor = 'Compresor';
            if ($prelevataTons !== null) {
                $carriedMain = $formatTons($prelevataTons) . ' prelevate';
            } elseif ($deliveredTons !== null) {
                $carriedMain = $formatTons($deliveredTons) . ' livrate';
            } elseif ($hours > 0) {
                $carriedMain = $formatHours($hours) . ' aspirare';
            } else {
                $carriedMain = 'Valori compresor necompletate';
            }
            if ($deliveredTons !== null) {
                $carriedLines[] = 'Tone livrate: ' . $formatTons($deliveredTons);
            }
            if ($liquidTons !== null) {
                $carriedLines[] = 'Aspirat lichid: ' . $formatTons($liquidTons);
            }
            if ($gasTons !== null) {
                $carriedLines[] = 'Aspirat gazos: ' . $formatTons($gasTons);
            }
            if ($hours > 0) {
                $calculationLines[] = 'Ore aspirare: ' . $formatHours($hours);
            }
            if ($kmDislocare > 0) {
                $calculationLines[] = 'Km dislocare: ' . $formatKm($kmDislocare);
            }
            if ($rate > 0) {
                $calculationLines[] = 'Tarif baza salvat: ' . $money($rate);
            }
            break;

        default:
            if ($loadedTons !== null) {
                $carriedMain = $formatTons($loadedTons) . ' incarcate';
            } elseif ($km > 0) {
                $carriedMain = $formatKm($km) . ' facturati';
            }
            if ($rate > 0) {
                $calculationLines[] = 'Tarif salvat: ' . $money($rate);
            }
            break;
    }

    if ($calculationLines === []) {
        $calculationLines[] = 'Calcul salvat in Dispecer curse.';
    }

    $calculationLines[] = 'Total transport: ' . $money($total);

    if ((float) ($row['_invoiced_refacturare_total'] ?? 0) > 0) {
        $calculationLines[] = 'Refacturare emisa separat: ' . $money($row['_invoiced_refacturare_total']);
    }

    return [
        'carried_main' => $carriedMain,
        'carried_lines' => array_values(array_unique($carriedLines)),
        'invoice_for' => $invoiceFor,
        'calculation_lines' => array_values(array_unique($calculationLines)),
    ];
};

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

$summaryByStatus = is_array($summaryByStatus ?? null) ? $summaryByStatus : [];
$summaryTotals = array_merge($summaryMetricDefaults, (array) ($summaryTotals ?? []));
$facturatSummary = $getSummaryRow($summaryByStatus, 'facturat');
$inProgressSummary = $getSummaryRow($summaryByStatus, 'in_curs_facturare');
$unbilledSummary = $getSummaryRow($summaryByStatus, 'nefacturat');
$openSummary = $sumSummaryRows([$inProgressSummary, $unbilledSummary]);

if ((int) $summaryTotals['total_curse'] === 0 && $summaryByStatus !== []) {
    $summaryTotals = $sumSummaryRows(array_values($summaryByStatus));
}

$rows = is_array($rows ?? null) ? $rows : [];
$preparedRows = [];
$refacturareItems = [];

foreach ($rows as $row) {
    $statusKey = (string) ($row['status_facturare'] ?? 'in_curs_facturare');
    if (!isset(($billingStatuses ?? [])[$statusKey])) {
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
        $refacturareItems[] = [
            'row' => $row,
            'expense' => $expenseRow,
        ];
    }
    $invoicedRefacturareTotal = (float) ($row['total_refacturare_facturata'] ?? 0);

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

    $transportKey = (string) ($row['tip_transport'] ?? '');
    $preparedRows[] = array_merge($row, [
        '_status_key' => $statusKey,
        '_status_label' => (string) (($billingStatuses ?? [])[$statusKey] ?? $statusKey),
        '_status_tone' => (string) ($statusToneClasses[$statusKey] ?? 'is-neutral'),
        '_transport_label' => (string) (($transportTypes ?? [])[$transportKey] ?? '-'),
        '_goods_label' => $goodsLabel($row['tip_marfa'] ?? ''),
        '_route_start' => $routeStart !== '' ? $routeStart : '-',
        '_route_end' => $routeEnd !== '' ? $routeEnd : '-',
        '_display_total' => (float) ($row['total_facturare'] ?? 0) + $invoicedRefacturareTotal,
        '_regular_expenses' => $regularExpenses,
        '_refacturare_expenses' => $refacturareExpenses,
        '_regular_expense_total' => $regularExpenseTotal,
        '_invoiced_refacturare_total' => $invoicedRefacturareTotal,
        '_refacturare_total' => $refacturareTotal,
    ]);
}

$vehicleKmRows = is_array($vehicleKmRows ?? null) ? $vehicleKmRows : [];
$expenseTypeTotals = is_array($expenseTypeTotals ?? null) ? $expenseTypeTotals : [];
$refacturareTypeTotals = is_array($refacturareTypeTotals ?? null) ? $refacturareTypeTotals : [];
$summaryByStatusTransport = is_array($summaryByStatusTransport ?? null) ? $summaryByStatusTransport : [];

$transportTotals = [];
foreach ($summaryByStatusTransport as $statusRows) {
    foreach ((array) $statusRows as $transportRow) {
        if (!is_array($transportRow)) {
            continue;
        }

        $transportKey = trim((string) ($transportRow['tip_transport'] ?? '-'));
        $transportKey = $transportKey !== '' ? $transportKey : '-';
        if (!isset($transportTotals[$transportKey])) {
            $transportTotals[$transportKey] = [
                'tip_transport' => $transportKey,
                'total_curse' => 0,
                'total_facturare' => 0.0,
                'total_refacturare_facturata' => 0.0,
                'total_refacturare' => 0.0,
            ];
        }

        $transportTotals[$transportKey]['total_curse'] += (int) ($transportRow['total_curse'] ?? 0);
        $transportTotals[$transportKey]['total_facturare'] += (float) ($transportRow['total_facturare'] ?? 0);
        $transportTotals[$transportKey]['total_refacturare_facturata'] += (float) ($transportRow['total_refacturare_facturata'] ?? 0);
        $transportTotals[$transportKey]['total_refacturare'] += (float) ($transportRow['total_refacturare'] ?? 0);
        $transportTotals[$transportKey]['display_total'] = (float) ($transportTotals[$transportKey]['total_facturare'] ?? 0) + (float) ($transportTotals[$transportKey]['total_refacturare_facturata'] ?? 0);
    }
}
uasort($transportTotals, static function (array $left, array $right): int {
    $amountCompare = (float) ($right['total_facturare'] ?? 0) <=> (float) ($left['total_facturare'] ?? 0);
    if ($amountCompare !== 0) {
        return $amountCompare;
    }

    return strcmp((string) ($left['tip_transport'] ?? ''), (string) ($right['tip_transport'] ?? ''));
});
$transportTotals = array_values($transportTotals);

$activeFilterCount = 0;
foreach ([
    $search,
    $filters['status_facturare'] ?? '',
    $filters['tip_transport'] ?? '',
    $filters['vehicle_id'] ?? '',
    $filters['beneficiar_id'] ?? '',
    $filters['zona_distributie_id'] ?? '',
    $filters['data_start'] ?? '',
    $filters['data_end'] ?? '',
] as $filterValue) {
    if (trim((string) $filterValue) !== '') {
        $activeFilterCount++;
    }
}

$hasAdvancedFilters = trim((string) ($filters['vehicle_id'] ?? '')) !== ''
    || trim((string) ($filters['beneficiar_id'] ?? '')) !== ''
    || trim((string) ($filters['zona_distributie_id'] ?? '')) !== '';
$advancedFilterCount = 0;
foreach ([
    $filters['vehicle_id'] ?? '',
    $filters['beneficiar_id'] ?? '',
    $filters['zona_distributie_id'] ?? '',
] as $filterValue) {
    if (trim((string) $filterValue) !== '') {
        $advancedFilterCount++;
    }
}

$renderStatusForm = static function (array $row) use ($billingStatuses, $currentListUrl): string {
    $raceId = (int) ($row['id'] ?? 0);
    $statusKey = (string) ($row['_status_key'] ?? 'in_curs_facturare');

    ob_start();
    ?>
    <form method="post" action="<?= e(build_query_url(['page' => 'centralizator_facturare', 'action' => 'update_status'])) ?>" class="billing-v2-status-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
        <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
        <select class="form-select form-select-sm" name="status_facturare" onchange="this.form.submit()" aria-label="Status facturare">
            <?php foreach ($billingStatuses as $optionKey => $optionLabel): ?>
                <option value="<?= e((string) $optionKey) ?>" <?= $statusKey === (string) $optionKey ? 'selected' : '' ?>>
                    <?= e((string) $optionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php
    return (string) ob_get_clean();
};

$renderLineItems = static function (
    array $items,
    string $typeField,
    string $amountField,
    string $dateField,
    string $observationField,
    bool $isRefacturare = false
) use ($expenseLabel, $money, $formatDate, $clean): string {
    if ($items === []) {
        return '<div class="billing-v2-empty-note">Nu exista linii pentru aceasta sectiune.</div>';
    }

    ob_start();
    ?>
    <div class="billing-v2-line-list">
        <?php foreach ($items as $item): ?>
            <?php
            $typeValue = trim((string) ($item[$typeField] ?? ''));
            $observationValue = $clean($item[$observationField] ?? '');
            ?>
            <div class="billing-v2-line-item<?= $isRefacturare ? ' is-refacturare' : '' ?>">
                <div class="billing-v2-line-item-main">
                    <strong><?= e($expenseLabel($typeValue)) ?></strong>
                    <span><?= e($formatDate($item[$dateField] ?? '')) ?></span>
                </div>
                <b><?= e($money($item[$amountField] ?? 0)) ?></b>
                <?php if ($observationValue !== ''): ?>
                    <small><?= e($observationValue) ?></small>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

$currentPageCount = count($preparedRows);
$totalFilteredRows = (int) ($pagination['total_rows'] ?? $currentPageCount);
$currentPageIndex = (int) ($pagination['page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);
$topVehicleRows = array_slice($vehicleKmRows, 0, 8);
$topRefacturareItems = array_slice($refacturareItems, 0, 8);
?>

<style>
.billing-v2-page {
    --billing-v2-ink: #17324a;
    --billing-v2-muted: #627386;
    --billing-v2-border: #d8e2eb;
    --billing-v2-surface: #ffffff;
    --billing-v2-surface-alt: #f6f9fc;
    --billing-v2-accent: #0f766e;
    --billing-v2-accent-soft: #e6f6f3;
    --billing-v2-warning: #b45309;
    --billing-v2-warning-soft: #fef1da;
    --billing-v2-danger: #b42318;
    --billing-v2-danger-soft: #fde8e8;
    --billing-v2-success: #15803d;
    --billing-v2-success-soft: #e9f9ee;
    color: var(--billing-v2-ink);
}

.billing-v2-hero {
    overflow: hidden;
    background:
        radial-gradient(circle at top right, rgba(15, 118, 110, 0.12), transparent 32%),
        linear-gradient(180deg, #ffffff 0%, #f4f8fb 100%);
    border: 1px solid var(--billing-v2-border);
}

.billing-v2-hero-body {
    padding: 1.5rem;
}

.billing-v2-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 0.6rem;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.08);
    color: var(--billing-v2-accent);
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.billing-v2-hero-copy h2 {
    margin: 0;
    font-size: clamp(1.45rem, 1.2rem + 0.8vw, 2rem);
    font-weight: 700;
    color: #10293e;
}

.billing-v2-hero-copy p {
    margin: 0.45rem 0 0;
    max-width: 62rem;
    color: var(--billing-v2-muted);
}

.billing-v2-hero-top {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
}

.billing-v2-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}

.billing-v2-count-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.65rem 0.9rem;
    border-radius: 0.85rem;
    border: 1px solid var(--billing-v2-border);
    background: rgba(255, 255, 255, 0.86);
    color: var(--billing-v2-ink);
    font-size: 0.9rem;
}

.billing-v2-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1.35rem;
}

.billing-v2-kpi {
    min-width: 0;
    padding: 1rem;
    border: 1px solid var(--billing-v2-border);
    border-radius: 1rem;
    background: rgba(255, 255, 255, 0.92);
}

.billing-v2-kpi span {
    display: block;
    color: var(--billing-v2-muted);
    font-size: 0.85rem;
}

.billing-v2-kpi strong {
    display: block;
    margin-top: 0.35rem;
    font-size: 1.35rem;
    line-height: 1.15;
    color: #0f2235;
}

.billing-v2-kpi small {
    display: block;
    margin-top: 0.45rem;
    color: var(--billing-v2-muted);
}

.billing-v2-kpi.is-success {
    background: linear-gradient(180deg, #ffffff 0%, var(--billing-v2-success-soft) 100%);
}

.billing-v2-kpi.is-pending {
    background: linear-gradient(180deg, #ffffff 0%, var(--billing-v2-warning-soft) 100%);
}

.billing-v2-kpi.is-refacturare {
    background: linear-gradient(180deg, #ffffff 0%, var(--billing-v2-accent-soft) 100%);
}

.billing-v2-kpi.is-balance {
    background: linear-gradient(180deg, #ffffff 0%, #eef3fb 100%);
}

.billing-v2-filter-card {
    border: 1px solid var(--billing-v2-border);
}

.billing-v2-section-title {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.billing-v2-section-title h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
}

.billing-v2-section-title p {
    margin: 0.3rem 0 0;
    color: var(--billing-v2-muted);
}

.billing-v2-filter-grid {
    display: grid;
    grid-template-columns: 2.1fr repeat(4, minmax(0, 1fr));
    gap: 0.9rem;
    align-items: end;
}

.billing-v2-filter-actions {
    display: flex;
    gap: 0.65rem;
    align-items: center;
    flex-wrap: wrap;
}

.billing-v2-advanced {
    grid-column: 1 / -1;
    padding-top: 0.25rem;
}

.billing-v2-advanced > summary {
    cursor: pointer;
    list-style: none;
    color: var(--billing-v2-accent);
    font-weight: 600;
}

.billing-v2-advanced > summary::-webkit-details-marker {
    display: none;
}

.billing-v2-advanced-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.9rem;
    margin-top: 0.9rem;
}

.billing-v2-main-card,
.billing-v2-side-card {
    border: 1px solid var(--billing-v2-border);
}

.billing-v2-results-box {
    min-width: 9rem;
    padding: 0.85rem 1rem;
    border-radius: 0.95rem;
    background: var(--billing-v2-surface-alt);
    text-align: right;
}

.billing-v2-results-box span,
.billing-v2-results-box small {
    display: block;
    color: var(--billing-v2-muted);
}

.billing-v2-results-box strong {
    display: block;
    color: #0f2235;
    font-size: 1.4rem;
}

.billing-v2-table {
    min-width: 980px;
}

.billing-v2-table.is-simple {
    min-width: 1180px;
}

.billing-v2-table thead th {
    border-bottom-width: 1px;
    color: var(--billing-v2-muted);
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    white-space: nowrap;
}

.billing-v2-table tbody td {
    vertical-align: top;
    border-color: #ebf0f5;
}

.billing-v2-main-link {
    display: inline-block;
    color: #0f4da8;
    font-weight: 700;
    text-decoration: none;
}

.billing-v2-main-link:hover {
    text-decoration: underline;
}

.billing-v2-main-stack,
.billing-v2-meta-stack {
    display: flex;
    flex-direction: column;
    gap: 0.18rem;
    min-width: 0;
}

.billing-v2-main-stack strong,
.billing-v2-value {
    color: #10293e;
}

.billing-v2-meta-stack span,
.billing-v2-main-stack small {
    color: var(--billing-v2-muted);
}

.billing-v2-value {
    display: block;
    font-weight: 700;
    white-space: nowrap;
}

.billing-v2-value.is-refacturare {
    color: #0f4da8;
}

.billing-v2-answer-main {
    display: block;
    color: #10293e;
    font-weight: 700;
}

.billing-v2-answer-lines,
.billing-v2-calc-lines {
    display: grid;
    gap: 0.18rem;
    margin-top: 0.35rem;
}

.billing-v2-answer-lines small,
.billing-v2-calc-lines small {
    color: var(--billing-v2-muted);
    line-height: 1.35;
}

.billing-v2-calc-lines small:last-child {
    color: #10293e;
    font-weight: 700;
}

.billing-v2-route-text {
    color: #10293e;
    font-weight: 700;
}

.billing-v2-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.34rem 0.62rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.billing-v2-pill.is-success {
    background: var(--billing-v2-success-soft);
    color: var(--billing-v2-success);
}

.billing-v2-pill.is-pending {
    background: var(--billing-v2-warning-soft);
    color: var(--billing-v2-warning);
}

.billing-v2-pill.is-danger {
    background: var(--billing-v2-danger-soft);
    color: var(--billing-v2-danger);
}

.billing-v2-pill.is-neutral {
    background: #edf2f7;
    color: var(--billing-v2-ink);
}

.billing-v2-status-cell {
    min-width: 11rem;
}

.billing-v2-status-form {
    margin-top: 0.55rem;
}

.billing-v2-detail-row td {
    padding-top: 0;
    border-top: 0;
}

.billing-v2-details {
    padding-top: 0.25rem;
}

.billing-v2-details > summary {
    cursor: pointer;
    color: #0f4da8;
    font-weight: 600;
}

.billing-v2-detail-layout {
    display: grid;
    grid-template-columns: 1.35fr 1fr;
    gap: 1rem;
    margin-top: 1rem;
}

.billing-v2-detail-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
}

.billing-v2-detail-card {
    min-width: 0;
    padding: 0.8rem;
    border: 1px solid var(--billing-v2-border);
    border-radius: 0.9rem;
    background: var(--billing-v2-surface-alt);
}

.billing-v2-detail-card span {
    display: block;
    color: var(--billing-v2-muted);
    font-size: 0.78rem;
}

.billing-v2-detail-card strong {
    display: block;
    margin-top: 0.25rem;
}

.billing-v2-detail-card.is-wide {
    grid-column: 1 / -1;
}

.billing-v2-detail-panels {
    display: grid;
    gap: 1rem;
}

.billing-v2-mini-panel {
    padding: 0.95rem;
    border: 1px solid var(--billing-v2-border);
    border-radius: 0.95rem;
    background: #ffffff;
}

.billing-v2-mini-panel.is-refacturare {
    background: linear-gradient(180deg, #ffffff 0%, var(--billing-v2-accent-soft) 100%);
}

.billing-v2-mini-panel h4 {
    margin: 0 0 0.8rem;
    font-size: 0.98rem;
    font-weight: 700;
}

.billing-v2-line-list {
    display: grid;
    gap: 0.7rem;
}

.billing-v2-line-item {
    display: grid;
    gap: 0.25rem;
    padding: 0.75rem 0.8rem;
    border: 1px solid var(--billing-v2-border);
    border-radius: 0.85rem;
    background: var(--billing-v2-surface-alt);
}

.billing-v2-line-item.is-refacturare {
    background: rgba(230, 246, 243, 0.65);
}

.billing-v2-line-item-main {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    color: var(--billing-v2-muted);
}

.billing-v2-line-item b {
    color: #10293e;
}

.billing-v2-line-item small,
.billing-v2-empty-note {
    color: var(--billing-v2-muted);
}

.billing-v2-sidebar {
    display: grid;
    gap: 1rem;
}

.billing-v2-summary-list,
.billing-v2-stacked-list {
    display: grid;
    gap: 0.75rem;
}

.billing-v2-summary-row,
.billing-v2-stacked-row {
    display: grid;
    gap: 0.2rem;
    padding: 0.8rem 0.9rem;
    border: 1px solid var(--billing-v2-border);
    border-radius: 0.9rem;
    background: var(--billing-v2-surface-alt);
}

.billing-v2-summary-row-top,
.billing-v2-stacked-row-top {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: center;
}

.billing-v2-summary-row strong,
.billing-v2-stacked-row strong {
    color: #10293e;
}

.billing-v2-summary-row small,
.billing-v2-stacked-row small {
    color: var(--billing-v2-muted);
}

.billing-v2-type-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.billing-v2-type-list {
    display: grid;
    gap: 0.55rem;
}

.billing-v2-type-row {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: center;
    padding: 0.55rem 0;
    border-bottom: 1px solid #edf2f7;
}

.billing-v2-type-row:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.billing-v2-type-row:first-child {
    padding-top: 0;
}

.billing-v2-empty-state {
    padding: 2.25rem 1rem;
    border: 1px dashed var(--billing-v2-border);
    border-radius: 1rem;
    background: var(--billing-v2-surface-alt);
    color: var(--billing-v2-muted);
    text-align: center;
}

@media (max-width: 1399.98px) {
    .billing-v2-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 1199.98px) {
    .billing-v2-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .billing-v2-detail-layout {
        grid-template-columns: 1fr;
    }

    .billing-v2-detail-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 767.98px) {
    .billing-v2-hero-body {
        padding: 1rem;
    }

    .billing-v2-kpi-grid,
    .billing-v2-filter-grid,
    .billing-v2-advanced-grid,
    .billing-v2-type-grid,
    .billing-v2-detail-grid {
        grid-template-columns: 1fr;
    }

    .billing-v2-hero-top,
    .billing-v2-filter-actions,
    .billing-v2-line-item-main,
    .billing-v2-summary-row-top,
    .billing-v2-stacked-row-top {
        align-items: flex-start;
        flex-direction: column;
    }

    .billing-v2-results-box {
        width: 100%;
        text-align: left;
    }
}
</style>

<div class="billing-v2-page">
    <section class="card border-0 shadow-sm billing-v2-hero mb-3">
        <div class="billing-v2-hero-body">
            <div class="billing-v2-hero-top">
                <div class="billing-v2-hero-copy">
                    <span class="billing-v2-eyebrow">Centralizare facturare</span>
                    <h2>Vezi clar fiecare cursa facturata</h2>
                    <p>Pentru fiecare cursa vezi ruta, cat s-a transportat, pentru ce se factureaza si calculul folosit din datele introduse in Dispecer curse.</p>
                </div>
                <div class="billing-v2-hero-actions">
                    <div class="billing-v2-count-badge">
                        <span>Rezultate filtrate</span>
                        <strong><?= e((string) $totalFilteredRows) ?></strong>
                    </div>
                    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la Dispecer curse</a>
                </div>
            </div>

            <div class="billing-v2-kpi-grid">
                <div class="billing-v2-kpi is-balance">
                    <span>Curse in filtru</span>
                    <strong><?= e((string) ((int) ($summaryTotals['total_curse'] ?? 0))) ?></strong>
                    <small><?= e((string) $currentPageCount) ?> afisate pe pagina din <?= e((string) $totalFilteredRows) ?> rezultate</small>
                </div>
                <div class="billing-v2-kpi is-success">
                    <span>Transportat</span>
                    <strong><?= e($formatTons($summaryTotals['total_tone_incarcate'] ?? 0)) ?></strong>
                    <small>Livrat <?= e($formatTons($summaryTotals['total_tone_livrate'] ?? 0)) ?> | Prelevat <?= e($formatTons($summaryTotals['total_tone_prelevate'] ?? 0)) ?></small>
                </div>
                <div class="billing-v2-kpi is-refacturare">
                    <span>Kilometri</span>
                    <strong><?= e($formatKm($summaryTotals['total_km_facturati'] ?? 0)) ?></strong>
                    <small>Rulati <?= e($formatKm($summaryTotals['total_km'] ?? 0)) ?> | Dislocare <?= e($formatKm($summaryTotals['total_km_dislocare'] ?? 0)) ?></small>
                </div>
                <div class="billing-v2-kpi is-pending">
                    <span>Facturare</span>
                    <strong><?= e($money($summaryInvoiceTotal($summaryTotals))) ?></strong>
                    <small>Transport <?= e($money($summaryTotals['total_facturare'] ?? 0)) ?> | Cheltuieli <?= e($money($summaryTotals['total_cheltuieli'] ?? 0)) ?> | De refacturat <?= e($money($summaryTotals['total_refacturare'] ?? 0)) ?></small>
                </div>
            </div>
        </div>
    </section>

    <section class="card border-0 shadow-sm billing-v2-filter-card mb-3">
        <div class="card-body">
            <div class="billing-v2-section-title">
                <div>
                    <h3>Filtre si cautare</h3>
                    <p>Pastreaza principalele campuri la vedere si foloseste filtrele avansate doar cand trebuie.</p>
                </div>
                <span class="billing-v2-pill is-neutral"><?= e((string) $activeFilterCount) ?> filtre active</span>
            </div>

            <form method="get" class="billing-v2-filter-grid">
                <input type="hidden" name="page" value="centralizator_facturare">
                <input type="hidden" name="action" value="index">

                <div>
                    <label class="form-label" for="billing_filter_q">Cautare</label>
                    <input type="text" class="form-control" id="billing_filter_q" name="q" value="<?= e((string) $search) ?>" placeholder="Nr. auto, sofer, beneficiar, locatie">
                </div>

                <div>
                    <label class="form-label" for="billing_filter_status">Status</label>
                    <select class="form-select" id="billing_filter_status" name="status_facturare">
                        <option value="">Toate</option>
                        <?php foreach (($billingStatuses ?? []) as $statusKey => $statusLabel): ?>
                            <option value="<?= e((string) $statusKey) ?>" <?= (string) ($filters['status_facturare'] ?? '') === (string) $statusKey ? 'selected' : '' ?>><?= e((string) $statusLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="billing_filter_tip_transport">Tip transport</label>
                    <select class="form-select" id="billing_filter_tip_transport" name="tip_transport">
                        <option value="">Toate</option>
                        <?php foreach (($transportTypes ?? []) as $typeKey => $typeLabel): ?>
                            <option value="<?= e((string) $typeKey) ?>" <?= (string) ($filters['tip_transport'] ?? '') === (string) $typeKey ? 'selected' : '' ?>><?= e((string) $typeLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="billing_filter_data_start">Data de la</label>
                    <input type="date" class="form-control" id="billing_filter_data_start" name="data_start" value="<?= e((string) ($filters['data_start'] ?? '')) ?>">
                </div>

                <div>
                    <label class="form-label" for="billing_filter_data_end">Data pana la</label>
                    <input type="date" class="form-control" id="billing_filter_data_end" name="data_end" value="<?= e((string) ($filters['data_end'] ?? '')) ?>">
                </div>

                <div class="billing-v2-filter-actions">
                    <button type="submit" class="btn btn-primary">Aplica filtre</button>
                    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'centralizator_facturare'])) ?>">Reseteaza</a>
                </div>

                <details class="billing-v2-advanced"<?= $hasAdvancedFilters ? ' open' : '' ?>>
                    <summary>Filtre avansate<?= $advancedFilterCount > 0 ? ' (' . e((string) $advancedFilterCount) . ')' : '' ?></summary>
                    <div class="billing-v2-advanced-grid">
                        <div>
                            <label class="form-label" for="billing_filter_vehicle">Nr. inmatriculare</label>
                            <select class="form-select" id="billing_filter_vehicle" name="vehicle_id">
                                <option value="">Toate</option>
                                <?php foreach (($vehicles ?? []) as $vehicle): ?>
                                    <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                    <option value="<?= e((string) $vehicleId) ?>" <?= (string) ($filters['vehicle_id'] ?? '') === (string) $vehicleId ? 'selected' : '' ?>><?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="billing_filter_beneficiar">Beneficiar</label>
                            <select class="form-select" id="billing_filter_beneficiar" name="beneficiar_id">
                                <option value="">Toti</option>
                                <?php foreach (($beneficiaries ?? []) as $beneficiary): ?>
                                    <?php $beneficiaryId = (int) ($beneficiary['id'] ?? 0); ?>
                                    <option value="<?= e((string) $beneficiaryId) ?>" <?= (string) ($filters['beneficiar_id'] ?? '') === (string) $beneficiaryId ? 'selected' : '' ?>><?= e((string) ($beneficiary['nume'] ?? '-')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="billing_filter_zone">Zona distributie</label>
                            <select class="form-select" id="billing_filter_zone" name="zona_distributie_id">
                                <option value="">Toate zonele</option>
                                <?php foreach (($distributionZones ?? []) as $zone): ?>
                                    <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                    <option value="<?= e((string) $zoneId) ?>" <?= (string) ($filters['zona_distributie_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>><?= e((string) ($zone['nume'] ?? '-')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </details>
            </form>
        </div>
    </section>

    <div class="row g-3">
        <div class="col-12 col-xxl-8">
            <section class="card border-0 shadow-sm billing-v2-main-card h-100">
                <div class="card-header bg-white border-0 pb-0">
                    <div class="billing-v2-section-title mb-0">
                        <div>
                            <h3>Curse si calcul facturare</h3>
                            <p>Randurile sunt organizate dupa intrebarile importante: ruta, cantitate, suma facturata si formula.</p>
                        </div>
                        <div class="billing-v2-results-box">
                            <span>Pe pagina</span>
                            <strong><?= e((string) $currentPageCount) ?></strong>
                            <small>din <?= e((string) $totalFilteredRows) ?> rezultate</small>
                        </div>
                    </div>
                </div>

                <div class="card-body pt-3">
                    <?php if ($preparedRows === []): ?>
                        <div class="billing-v2-empty-state">Nu exista curse pentru filtrul curent.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table billing-v2-table is-simple align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Cursa</th>
                                        <th>Ruta</th>
                                        <th>Transportat</th>
                                        <th>Facturat pentru</th>
                                        <th>Calcul</th>
                                        <th>Status</th>
                                        <th>Actiuni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($preparedRows as $row): ?>
                                        <?php
                                        $raceId = (int) ($row['id'] ?? 0);
                                        $regularExpenses = (array) ($row['_regular_expenses'] ?? []);
                                        $refacturareExpenses = (array) ($row['_refacturare_expenses'] ?? []);
                                        $billingFacts = $buildSimpleBillingFacts($row);
                                        $details = [
                                            ['Data incarcare', $formatDate($row['data_incarcare'] ?? '')],
                                            ['Perioada', $formatDateRange($row)],
                                            ['Ore', $formatTimeRange($row)],
                                            ['Durata', $formatDuration($row['durata_cursa_minute'] ?? null)],
                                            ['Tip transport', (string) ($row['_transport_label'] ?? '-')],
                                            ['Tip marfa', (string) ($row['_goods_label'] ?? '-')],
                                            ['Loc incarcare', $show($row['loc_incarcare_nume'] ?? '')],
                                            ['Loc livrare', $show($row['loc_livrare'] ?? '')],
                                            ['Zona', $show($row['zona_distributie_nume'] ?? '')],
                                            ['Km rulati', $formatKm($row['km_rulati'] ?? 0)],
                                            ['Km facturati', $formatKm($row['km_cursa'] ?? 0)],
                                            ['Km totali', $formatKm($row['km_totali'] ?? 0)],
                                            ['Km dislocare', $formatKm($row['km_dislocare'] ?? 0)],
                                            ['Cantitate incarcata', $formatMetric($row['cantitate_incarcata'] ?? null)],
                                            ['Cantitate prelevata', $formatMetric($row['cantitate_prelevata'] ?? null)],
                                            ['Tone livrate', $formatMetric($row['tona_livrata'] ?? null, 't')],
                                            ['Ore aspirare', $formatHours($row['ore_aspirare'] ?? null)],
                                            ['Tarif', $money($row['pret_tarifare'] ?? 0)],
                                            ['Cost/km', $costKmLabel($row)],
                                        ];
                                        $observatii = $clean($row['observatii'] ?? '');
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="billing-v2-main-stack">
                                                    <a class="billing-v2-main-link" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">#<?= e((string) $raceId) ?></a>
                                                    <strong><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></strong>
                                                    <span><?= e((string) ($row['sofer_nume'] ?? '-')) ?></span>
                                                    <?php if ($show($row['data_incarcare'] ?? '') !== '-'): ?>
                                                        <small>Incarcare: <?= e($formatDate($row['data_incarcare'] ?? '')) ?></small>
                                                    <?php endif; ?>
                                                    <small>Interval: <?= e($formatDateRange($row)) ?></small>
                                                    <small><?= e($show($row['beneficiar_nume'] ?? '')) ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="billing-v2-meta-stack">
                                                    <span class="billing-v2-route-text"><?= e((string) ($row['_route_start'] ?? '-')) ?> -> <?= e((string) ($row['_route_end'] ?? '-')) ?></span>
                                                    <?php if ($show($row['loc_livrare_cursa'] ?? '') !== '-'): ?>
                                                        <span>Livrare: <?= e($show($row['loc_livrare_cursa'] ?? '')) ?></span>
                                                    <?php endif; ?>
                                                    <span><?= e((string) ($row['_transport_label'] ?? '-')) ?></span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="billing-v2-answer-main"><?= e((string) ($billingFacts['carried_main'] ?? '-')) ?></span>
                                                <div class="billing-v2-answer-lines">
                                                    <?php foreach ((array) ($billingFacts['carried_lines'] ?? []) as $line): ?>
                                                        <small><?= e((string) $line) ?></small>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="billing-v2-main-stack">
                                                    <strong><?= e((string) ($billingFacts['invoice_for'] ?? '-')) ?></strong>
                                                    <span><?= e((string) ($row['_goods_label'] ?? '-')) ?></span>
                                                    <span class="billing-v2-value"><?= e($money($row['total_facturare'] ?? 0)) ?></span>
                                                    <?php if ((float) ($row['_invoiced_refacturare_total'] ?? 0) > 0): ?>
                                                        <small>Refacturare emisa: <?= e($money($row['_invoiced_refacturare_total'] ?? 0)) ?></small>
                                                    <?php endif; ?>
                                                    <?php if ((float) ($row['_regular_expense_total'] ?? 0) > 0): ?>
                                                        <small>Cheltuieli cursa: <?= e($money($row['_regular_expense_total'] ?? 0)) ?></small>
                                                    <?php endif; ?>
                                                    <?php if ((float) ($row['_refacturare_total'] ?? 0) > 0): ?>
                                                        <small>De refacturat: <?= e($money($row['_refacturare_total'] ?? 0)) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="billing-v2-calc-lines">
                                                    <?php foreach ((array) ($billingFacts['calculation_lines'] ?? []) as $line): ?>
                                                        <small><?= e((string) $line) ?></small>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td class="billing-v2-status-cell">
                                                <span class="billing-v2-pill <?= e((string) ($row['_status_tone'] ?? 'is-neutral')) ?>"><?= e((string) ($row['_status_label'] ?? '-')) ?></span>
                                                <?= $renderStatusForm($row) ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <a class="btn btn-sm btn-outline-primary w-100" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">Editeaza</a>
                                            </td>
                                        </tr>
                                        <tr class="billing-v2-detail-row">
                                            <td colspan="7">
                                                <details class="billing-v2-details">
                                                    <summary>Vezi detalii si linii</summary>
                                                    <div class="billing-v2-detail-layout">
                                                        <div class="billing-v2-detail-grid">
                                                            <?php foreach ($details as $detail): ?>
                                                                <div class="billing-v2-detail-card">
                                                                    <span><?= e((string) $detail[0]) ?></span>
                                                                    <strong><?= e((string) $detail[1]) ?></strong>
                                                                </div>
                                                            <?php endforeach; ?>
                                                            <?php if ($observatii !== ''): ?>
                                                                <div class="billing-v2-detail-card is-wide">
                                                                    <span>Observatii</span>
                                                                    <strong><?= e($observatii) ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="billing-v2-detail-panels">
                                                            <div class="billing-v2-mini-panel">
                                                                <h4>Cheltuieli pe cursa</h4>
                                                                <?= $renderLineItems($regularExpenses, 'tip_cheltuiala', 'suma', 'data_cheltuiala', 'observatii') ?>
                                                            </div>
                                                            <div class="billing-v2-mini-panel is-refacturare">
                                                                <h4>Refacturare din cheltuieli</h4>
                                                                <?= $renderLineItems($refacturareExpenses, 'refacturare_tip_cheltuiala', 'refacturare_suma', 'refacturare_data', 'refacturare_observatii', true) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="col-12 col-xxl-4">
            <div class="billing-v2-sidebar">
                <section class="card border-0 shadow-sm billing-v2-side-card">
                    <div class="card-header bg-white border-0">
                        <h3 class="h6 mb-0">Rezumat pe status</h3>
                    </div>
                    <div class="card-body">
                        <div class="billing-v2-summary-list">
                            <?php foreach ([
                                ['label' => 'Facturat', 'summary' => $facturatSummary, 'tone' => 'is-success'],
                                ['label' => 'In curs + nefacturat', 'summary' => $openSummary, 'tone' => 'is-pending'],
                                ['label' => 'Nefacturat', 'summary' => $unbilledSummary, 'tone' => 'is-danger'],
                            ] as $summaryCard): ?>
                                <div class="billing-v2-summary-row">
                                    <div class="billing-v2-summary-row-top">
                                        <span class="billing-v2-pill <?= e((string) $summaryCard['tone']) ?>"><?= e((string) $summaryCard['label']) ?></span>
                                        <strong><?= e($money($summaryInvoiceTotal((array) $summaryCard['summary']))) ?></strong>
                                    </div>
                                    <small><?= e((string) ((int) ($summaryCard['summary']['total_curse'] ?? 0))) ?> curse | Transport <?= e($money($summaryCard['summary']['total_facturare'] ?? 0)) ?> | Emisa <?= e($money($summaryCard['summary']['total_refacturare_facturata'] ?? 0)) ?> | De refacturat <?= e($money($summaryCard['summary']['total_refacturare'] ?? 0)) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>

                <section class="card border-0 shadow-sm billing-v2-side-card">
                    <div class="card-header bg-white border-0">
                        <h3 class="h6 mb-0">Cheltuieli si refacturari pe tip</h3>
                    </div>
                    <div class="card-body">
                        <div class="billing-v2-type-grid">
                            <div>
                                <div class="text-muted small mb-2">Cheltuieli cursa</div>
                                <?php if ($expenseTypeTotals === []): ?>
                                    <div class="billing-v2-empty-note">Fara cheltuieli in filtrul curent.</div>
                                <?php else: ?>
                                    <div class="billing-v2-type-list">
                                        <?php foreach ($expenseTypeTotals as $typeRow): ?>
                                            <div class="billing-v2-type-row">
                                                <span><?= e($expenseLabel((string) ($typeRow['tip_cheltuiala'] ?? ''))) ?></span>
                                                <strong><?= e($money($typeRow['total_suma'] ?? 0)) ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="text-muted small mb-2">Refacturare</div>
                                <?php if ($refacturareTypeTotals === []): ?>
                                    <div class="billing-v2-empty-note">Nimic de refacturat.</div>
                                <?php else: ?>
                                    <div class="billing-v2-type-list">
                                        <?php foreach ($refacturareTypeTotals as $typeRow): ?>
                                            <div class="billing-v2-type-row">
                                                <span><?= e($expenseLabel((string) ($typeRow['refacturare_tip_cheltuiala'] ?? ''))) ?></span>
                                                <strong><?= e($money($typeRow['total_suma'] ?? 0)) ?></strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <?php if ($transportTotals !== []): ?>
                    <section class="card border-0 shadow-sm billing-v2-side-card">
                        <div class="card-header bg-white border-0">
                            <h3 class="h6 mb-0">Transporturi in filtru</h3>
                        </div>
                        <div class="card-body">
                            <div class="billing-v2-stacked-list">
                                <?php foreach (array_slice($transportTotals, 0, 6) as $transportRow): ?>
                                    <?php $transportKey = (string) ($transportRow['tip_transport'] ?? '-'); ?>
                                    <div class="billing-v2-stacked-row">
                                        <div class="billing-v2-stacked-row-top">
                                            <strong><?= e((string) (($transportTypes ?? [])[$transportKey] ?? $transportKey)) ?></strong>
                                            <span><?= e($money($transportRow['display_total'] ?? 0)) ?></span>
                                        </div>
                                        <small><?= e((string) ((int) ($transportRow['total_curse'] ?? 0))) ?> curse | Transport <?= e($money($transportRow['total_facturare'] ?? 0)) ?> | Emisa <?= e($money($transportRow['total_refacturare_facturata'] ?? 0)) ?> | De refacturat <?= e($money($transportRow['total_refacturare'] ?? 0)) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($topRefacturareItems !== []): ?>
                    <section class="card border-0 shadow-sm billing-v2-side-card">
                        <div class="card-header bg-white border-0">
                            <h3 class="h6 mb-0">Refacturari din pagina curenta</h3>
                        </div>
                        <div class="card-body">
                            <div class="billing-v2-stacked-list">
                                <?php foreach ($topRefacturareItems as $item): ?>
                                    <?php
                                    $row = (array) ($item['row'] ?? []);
                                    $expense = (array) ($item['expense'] ?? []);
                                    $raceId = (int) ($row['id'] ?? 0);
                                    ?>
                                    <div class="billing-v2-stacked-row">
                                        <div class="billing-v2-stacked-row-top">
                                            <strong><?= e($expenseLabel((string) ($expense['refacturare_tip_cheltuiala'] ?? ''))) ?></strong>
                                            <span><?= e($money($expense['refacturare_suma'] ?? 0)) ?></span>
                                        </div>
                                        <small>#<?= e((string) $raceId) ?> | <?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?> | <?= e($show($row['beneficiar_nume'] ?? '')) ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($topVehicleRows !== []): ?>
                    <section class="card border-0 shadow-sm billing-v2-side-card">
                        <div class="card-header bg-white border-0">
                            <h3 class="h6 mb-0">Vehicule in filtru</h3>
                        </div>
                        <div class="card-body">
                            <div class="billing-v2-stacked-list">
                                <?php foreach ($topVehicleRows as $vehicleRow): ?>
                                    <div class="billing-v2-stacked-row">
                                        <div class="billing-v2-stacked-row-top">
                                            <strong><?= e((string) ($vehicleRow['nr_inmatriculare'] ?? '-')) ?></strong>
                                            <span><?= e((string) ((int) ($vehicleRow['total_curse'] ?? 0))) ?> curse</span>
                                        </div>
                                        <small><?= e($formatKm($vehicleRow['total_km'] ?? 0)) ?> rulati | <?= e($formatKm($vehicleRow['km_facturati'] ?? 0)) ?> facturati | <?= e($money($vehicleRow['total_refacturare'] ?? 0)) ?> refacturare</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="card border-0 shadow-sm billing-v2-main-card mt-3">
            <div class="card-body py-2">
                <?php
                $prevPage = max(1, $currentPageIndex - 1);
                $nextPage = min($totalPages, $currentPageIndex + 1);
                ?>
                <nav aria-label="Paginare centralizator facturare">
                    <ul class="pagination pagination-sm mb-0 justify-content-end">
                        <li class="page-item <?= $currentPageIndex <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $prevPage]))) ?>">Anterior</a></li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $currentPageIndex === $i ? 'active' : '' ?>"><a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $i]))) ?>"><?= e((string) $i) ?></a></li>
                        <?php endfor; ?>
                        <li class="page-item <?= $currentPageIndex >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $nextPage]))) ?>">Urmator</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    <?php endif; ?>
</div>
