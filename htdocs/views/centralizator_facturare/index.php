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
$statusBadgeClasses = [
    'in_curs_facturare' => 'text-bg-warning',
    'nefacturat' => 'text-bg-danger',
    'facturat' => 'text-bg-success',
];

$summaryTotals = array_merge([
    'total_curse' => 0,
    'total_facturare' => 0.0,
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
], (array) ($summaryTotals ?? []));

if ((int) $summaryTotals['total_curse'] === 0 && ($summaryByStatus ?? []) !== []) {
    foreach (($summaryByStatus ?? []) as $summaryRow) {
        $summaryTotals['total_curse'] += (int) ($summaryRow['total_curse'] ?? 0);
        $summaryTotals['total_facturare'] += (float) ($summaryRow['total_facturare'] ?? 0);
        $summaryTotals['total_refacturare'] += (float) ($summaryRow['total_refacturare'] ?? 0);
        $summaryTotals['total_cheltuieli'] += (float) ($summaryRow['total_cheltuieli'] ?? 0);
        $summaryTotals['sold_estimativ'] += (float) ($summaryRow['sold_estimativ'] ?? 0);
        $summaryTotals['expense_count'] += (int) ($summaryRow['expense_count'] ?? 0);
        $summaryTotals['refacturare_count'] += (int) ($summaryRow['refacturare_count'] ?? 0);
        $summaryTotals['curse_de_refacturat'] += (int) ($summaryRow['curse_de_refacturat'] ?? 0);
        $summaryTotals['total_km'] += (float) ($summaryRow['total_km'] ?? 0);
        $summaryTotals['total_km_facturati'] += (float) ($summaryRow['total_km_facturati'] ?? 0);
        $summaryTotals['total_tone_incarcate'] += (float) ($summaryRow['total_tone_incarcate'] ?? 0);
        $summaryTotals['total_tone_prelevate'] += (float) ($summaryRow['total_tone_prelevate'] ?? 0);
        $summaryTotals['total_tone_livrate'] += (float) ($summaryRow['total_tone_livrate'] ?? 0);
    }
}

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

$regularExpensesForRow = static function (array $row): array {
    $expenses = is_array($row['expenses_breakdown'] ?? null) ? $row['expenses_breakdown'] : [];
    return array_values(array_filter($expenses, static fn (array $expense): bool => (float) ($expense['suma'] ?? 0) > 0));
};
$refacturareExpensesForRow = static function (array $row): array {
    $expenses = is_array($row['expenses_breakdown'] ?? null) ? $row['expenses_breakdown'] : [];
    return array_values(array_filter($expenses, static fn (array $expense): bool => (float) ($expense['refacturare_suma'] ?? 0) > 0));
};

$rows = is_array($rows ?? null) ? $rows : [];
$invoicedRows = [];
$notInvoicedRows = [];
$refacturareItems = [];
foreach ($rows as $row) {
    $statusKey = (string) ($row['status_facturare'] ?? 'in_curs_facturare');
    if (!isset(($billingStatuses ?? [])[$statusKey])) {
        $statusKey = 'in_curs_facturare';
    }
    $statusKey === 'facturat' ? $invoicedRows[] = $row : $notInvoicedRows[] = $row;
    foreach ($refacturareExpensesForRow($row) as $expenseRow) {
        $refacturareItems[] = ['row' => $row, 'expense' => $expenseRow];
    }
}

$facturatSummary = (array) (($summaryByStatus ?? [])['facturat'] ?? []);
$facturatCount = (int) ($facturatSummary['total_curse'] ?? 0);
$facturatAmount = (float) ($facturatSummary['total_facturare'] ?? 0);
$facturatRefacturareAmount = (float) ($facturatSummary['total_refacturare'] ?? 0);
$facturatExpenseAmount = (float) ($facturatSummary['total_cheltuieli'] ?? 0);
$facturatKmFacturati = (float) ($facturatSummary['total_km_facturati'] ?? 0);
$facturatToneIncarcate = (float) ($facturatSummary['total_tone_incarcate'] ?? 0);
$facturatTonePrelevate = (float) ($facturatSummary['total_tone_prelevate'] ?? 0);
$facturatToneLivrate = (float) ($facturatSummary['total_tone_livrate'] ?? 0);
$nefacturatSummary = (array) (($summaryByStatus ?? [])['nefacturat'] ?? []);
$nefacturatCount = (int) ($nefacturatSummary['total_curse'] ?? 0);
$nefacturatAmount = (float) ($nefacturatSummary['total_facturare'] ?? 0);
$nefacturatRefacturareAmount = (float) ($nefacturatSummary['total_refacturare'] ?? 0);
$nefacturatExpenseAmount = (float) ($nefacturatSummary['total_cheltuieli'] ?? 0);
$nefacturatKmFacturati = (float) ($nefacturatSummary['total_km_facturati'] ?? 0);
$nefacturatToneIncarcate = (float) ($nefacturatSummary['total_tone_incarcate'] ?? 0);
$nefacturatTonePrelevate = (float) ($nefacturatSummary['total_tone_prelevate'] ?? 0);
$nefacturatToneLivrate = (float) ($nefacturatSummary['total_tone_livrate'] ?? 0);
$notInvoicedCount = 0;
$notInvoicedAmount = 0.0;
$notInvoicedRefacturareAmount = 0.0;
$notInvoicedExpenseAmount = 0.0;
$notInvoicedKmFacturati = 0.0;
$notInvoicedToneIncarcate = 0.0;
$notInvoicedTonePrelevate = 0.0;
$notInvoicedToneLivrate = 0.0;
foreach (($summaryByStatus ?? []) as $statusKey => $summaryRow) {
    if ((string) $statusKey === 'facturat') {
        continue;
    }
    $notInvoicedCount += (int) ($summaryRow['total_curse'] ?? 0);
    $notInvoicedAmount += (float) ($summaryRow['total_facturare'] ?? 0);
    $notInvoicedRefacturareAmount += (float) ($summaryRow['total_refacturare'] ?? 0);
    $notInvoicedExpenseAmount += (float) ($summaryRow['total_cheltuieli'] ?? 0);
    $notInvoicedKmFacturati += (float) ($summaryRow['total_km_facturati'] ?? 0);
    $notInvoicedToneIncarcate += (float) ($summaryRow['total_tone_incarcate'] ?? 0);
    $notInvoicedTonePrelevate += (float) ($summaryRow['total_tone_prelevate'] ?? 0);
    $notInvoicedToneLivrate += (float) ($summaryRow['total_tone_livrate'] ?? 0);
}

$expenseTypeTotals = is_array($expenseTypeTotals ?? null) ? $expenseTypeTotals : [];
$refacturareTypeTotals = is_array($refacturareTypeTotals ?? null) ? $refacturareTypeTotals : [];
$summaryByStatusTransport = is_array($summaryByStatusTransport ?? null) ? $summaryByStatusTransport : [];

$transportSummaryNumericFields = [
    'total_curse',
    'total_facturare',
    'total_refacturare',
    'total_cheltuieli',
    'expense_count',
    'refacturare_count',
    'curse_de_refacturat',
    'total_km',
    'total_km_facturati',
    'total_km_totali',
    'total_km_dislocare',
    'total_ore_aspirare',
    'total_tone_incarcate',
    'total_tone_prelevate',
    'total_tone_livrate',
    'total_tone_lichid',
    'total_tone_gazos',
];

$getTransportSummaryRows = static function (array $source, array $statuses) use ($transportSummaryNumericFields): array {
    $merged = [];

    foreach ($statuses as $statusKey) {
        foreach ((array) ($source[(string) $statusKey] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transportKey = trim((string) ($row['tip_transport'] ?? '-'));
            $transportKey = $transportKey !== '' ? $transportKey : '-';

            if (!isset($merged[$transportKey])) {
                $merged[$transportKey] = [
                    'tip_transport' => $transportKey,
                    'status_facturare' => (string) $statusKey,
                ];
                foreach ($transportSummaryNumericFields as $field) {
                    $merged[$transportKey][$field] = 0.0;
                }
            }

            foreach ($transportSummaryNumericFields as $field) {
                $merged[$transportKey][$field] += (float) ($row[$field] ?? 0);
            }
        }
    }

    uasort($merged, static function (array $left, array $right): int {
        $amountCompare = (float) ($right['total_facturare'] ?? 0) <=> (float) ($left['total_facturare'] ?? 0);
        if ($amountCompare !== 0) {
            return $amountCompare;
        }

        return strcmp((string) ($left['tip_transport'] ?? ''), (string) ($right['tip_transport'] ?? ''));
    });

    return array_values($merged);
};

$allSummaryStatuses = array_values(array_unique(array_merge(
    ['facturat', 'in_curs_facturare', 'nefacturat'],
    array_map('strval', array_keys($summaryByStatusTransport))
)));
$notInvoicedSummaryStatuses = array_values(array_filter(
    $allSummaryStatuses,
    static fn (string $statusKey): bool => $statusKey !== 'facturat'
));

$facturatTransportRows = $getTransportSummaryRows($summaryByStatusTransport, ['facturat']);
$notInvoicedTransportRows = $getTransportSummaryRows($summaryByStatusTransport, $notInvoicedSummaryStatuses);
$nefacturatTransportRows = $getTransportSummaryRows($summaryByStatusTransport, ['nefacturat']);
$refacturareTransportRows = array_values(array_filter(
    $getTransportSummaryRows($summaryByStatusTransport, $allSummaryStatuses),
    static fn (array $row): bool => (float) ($row['total_refacturare'] ?? 0) > 0
));

$renderTransportBreakdown = static function (array $rows, string $amountField = 'total_facturare') use (
    $transportTypes,
    $money,
    $formatKm,
    $formatTons,
    $formatHours
): string {
    if ($rows === []) {
        return '<div class="billing-transport-empty">Fara curse pentru filtrul curent.</div>';
    }

    $addMetric = static function (array &$metrics, string $label, mixed $rawValue, string $formattedValue): void {
        if ((float) ($rawValue ?? 0) <= 0) {
            return;
        }

        $metrics[] = $label . ' ' . $formattedValue;
    };

    ob_start();
    ?>
    <div class="billing-transport-breakdown">
        <div class="billing-transport-title">Pe tip transport</div>
        <?php foreach ($rows as $row): ?>
            <?php
            $transportKey = (string) ($row['tip_transport'] ?? '-');
            $transportLabel = (string) ($transportTypes[$transportKey] ?? ($transportKey !== '' ? $transportKey : '-'));
            $metrics = [];
            $addMetric($metrics, 'Livrat', $row['total_tone_livrate'] ?? 0, $formatTons($row['total_tone_livrate'] ?? 0));
            $addMetric($metrics, 'Incarcat', $row['total_tone_incarcate'] ?? 0, $formatTons($row['total_tone_incarcate'] ?? 0));
            $addMetric($metrics, 'Prelevat', $row['total_tone_prelevate'] ?? 0, $formatTons($row['total_tone_prelevate'] ?? 0));
            $addMetric($metrics, 'Km fact.', $row['total_km_facturati'] ?? 0, $formatKm($row['total_km_facturati'] ?? 0));

            if ($transportKey === 'compresor') {
                $addMetric($metrics, 'Aspirare', $row['total_ore_aspirare'] ?? 0, $formatHours($row['total_ore_aspirare'] ?? 0));
                $addMetric($metrics, 'Lichid', $row['total_tone_lichid'] ?? 0, $formatTons($row['total_tone_lichid'] ?? 0));
                $addMetric($metrics, 'Gazos', $row['total_tone_gazos'] ?? 0, $formatTons($row['total_tone_gazos'] ?? 0));
                $addMetric($metrics, 'Km disloc.', $row['total_km_dislocare'] ?? 0, $formatKm($row['total_km_dislocare'] ?? 0));
            }

            if ($metrics === []) {
                $addMetric($metrics, 'Km rulati', $row['total_km'] ?? 0, $formatKm($row['total_km'] ?? 0));
            }
            ?>
            <div class="billing-transport-line">
                <div class="billing-transport-main">
                    <strong><?= e($transportLabel) ?></strong>
                    <span><?= e((string) ((int) ($row['total_curse'] ?? 0))) ?> curse</span>
                </div>
                <b><?= e($money($row[$amountField] ?? 0)) ?></b>
                <small><?= e($metrics !== [] ? implode(' · ', $metrics) : 'Fara elemente cantitative') ?></small>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

$renderRideCard = static function (array $row) use (
    $billingStatuses,
    $statusBadgeClasses,
    $transportTypes,
    $money,
    $formatKm,
    $formatDate,
    $formatDateRange,
    $formatTimeRange,
    $formatDuration,
    $formatMetric,
    $expenseLabel,
    $goodsLabel,
    $costKmLabel,
    $regularExpensesForRow,
    $refacturareExpensesForRow,
    $clean,
    $show,
    $currentListUrl
): string {
    $raceId = (int) ($row['id'] ?? 0);
    $statusKey = (string) ($row['status_facturare'] ?? 'in_curs_facturare');
    if (!isset($billingStatuses[$statusKey])) {
        $statusKey = 'in_curs_facturare';
    }
    $statusLabel = (string) ($billingStatuses[$statusKey] ?? $statusKey);
    $transportKey = (string) ($row['tip_transport'] ?? '');
    $transportLabel = (string) ($transportTypes[$transportKey] ?? '-');
    $regularExpenses = $regularExpensesForRow($row);
    $refacturareExpenses = $refacturareExpensesForRow($row);
    $isInvoiced = $statusKey === 'facturat';
    $totalFacturare = (float) ($row['total_facturare'] ?? 0);
    $totalRefacturare = (float) ($row['total_refacturare'] ?? 0);
    $cardClass = $isInvoiced ? 'is-invoiced' : 'needs-invoice';
    $regularExpenseTotal = 0.0;
    foreach ($regularExpenses as $expenseRow) {
        $regularExpenseTotal += (float) ($expenseRow['suma'] ?? 0);
    }
    $toPositiveFloat = static function (mixed $value): ?float {
        if ($value === null || $value === '' || !is_numeric((string) $value)) {
            return null;
        }
        $number = (float) $value;
        return $number > 0 ? $number : null;
    };
    $formatFormulaNumber = static function (?float $value, int $decimals = 2): string {
        if ($value === null) {
            return '-';
        }
        return format_number_ro($value, $value === floor($value) ? 0 : $decimals);
    };
    $kmCursaValue = $toPositiveFloat($row['km_cursa'] ?? null);
    $kmDislocareValue = $toPositiveFloat($row['km_dislocare'] ?? null);
    $cantitateValue = $toPositiveFloat($row['cantitate_incarcata'] ?? null);
    $tonaLivrataValue = $toPositiveFloat($row['tona_livrata'] ?? null);
    $oreAspirareValue = $toPositiveFloat($row['ore_aspirare'] ?? null);
    $tonaLichidaValue = $toPositiveFloat($row['tona_aspirata_lichida'] ?? null);
    $tonaGazoasaValue = $toPositiveFloat($row['tona_aspirata_gazoasa'] ?? null);
    $tarifValue = $toPositiveFloat($row['pret_tarifare'] ?? null);
    $relevantCostKmValue = match ($transportKey) {
        'compresor' => $toPositiveFloat($row['cost_km_compresor'] ?? null),
        'distributie' => $toPositiveFloat($row['cost_km_distributie'] ?? null),
        'primar_distributie' => $toPositiveFloat($row['cost_km_mixt'] ?? null),
        default => $toPositiveFloat($row['cost_km_primar'] ?? null),
    };
    $calculationLabel = 'Calcul';
    $calculationParts = [];
    if ($transportKey === 'compresor') {
        if ($kmDislocareValue !== null && $relevantCostKmValue !== null) {
            $calculationParts[] = $formatFormulaNumber($kmDislocareValue, 2) . ' km x ' . $formatFormulaNumber($relevantCostKmValue, 2) . ' lei/km';
        } else {
            if ($oreAspirareValue !== null) {
                $calculationParts[] = $formatFormulaNumber($oreAspirareValue, 2) . ' h aspirare';
            }
            if ($tonaLivrataValue !== null) {
                $calculationParts[] = $formatFormulaNumber($tonaLivrataValue, 2) . ' t livrate';
            }
            if ($tonaLichidaValue !== null) {
                $calculationParts[] = $formatFormulaNumber($tonaLichidaValue, 2) . ' t lichid';
            }
            if ($tonaGazoasaValue !== null) {
                $calculationParts[] = $formatFormulaNumber($tonaGazoasaValue, 2) . ' t gazos';
            }
        }
    } elseif ($transportKey === 'primar' || $transportKey === 'primar_km') {
        $rate = $relevantCostKmValue ?? $tarifValue;
        if ($kmCursaValue !== null && $rate !== null) {
            $calculationParts[] = $formatFormulaNumber($kmCursaValue, 2) . ' km x ' . $formatFormulaNumber($rate, 2) . ' lei/km';
        }
    } elseif ($transportKey === 'primar_tona') {
        if ($cantitateValue !== null && $tarifValue !== null) {
            $calculationParts[] = $formatFormulaNumber($cantitateValue, 2) . ' t x ' . $formatFormulaNumber($tarifValue, 2) . ' lei/t';
        }
    } elseif ($transportKey === 'distributie') {
        if ($cantitateValue !== null && $tarifValue !== null) {
            $calculationParts[] = $formatFormulaNumber($cantitateValue, 2) . ' t x ' . $formatFormulaNumber($tarifValue, 2) . ' lei/t';
        } elseif ($kmCursaValue !== null && $relevantCostKmValue !== null) {
            $calculationParts[] = $formatFormulaNumber($kmCursaValue, 2) . ' km x ' . $formatFormulaNumber($relevantCostKmValue, 2) . ' lei/km';
        }
    } elseif ($transportKey === 'primar_distributie') {
        if ($cantitateValue !== null && $tarifValue !== null) {
            $calculationParts[] = $formatFormulaNumber($cantitateValue, 2) . ' t x ' . $formatFormulaNumber($tarifValue, 2) . ' lei/t';
        }
        if ($kmCursaValue !== null && $relevantCostKmValue !== null) {
            $calculationParts[] = $formatFormulaNumber($kmCursaValue, 2) . ' km x ' . $formatFormulaNumber($relevantCostKmValue, 2) . ' lei/km';
        }
    }
    $calculationText = $calculationParts !== []
        ? implode(' + ', $calculationParts) . ' = ' . $money($totalFacturare)
        : 'Total calculat: ' . $money($totalFacturare);
    $liquid = $formatMetric($row['tona_aspirata_lichida'] ?? null, 't');
    $gas = $formatMetric($row['tona_aspirata_gazoasa'] ?? null, 't');
    $details = [
        ['Perioada', $formatDateRange($row)],
        ['Ore', $formatTimeRange($row)],
        ['Durata', $formatDuration($row['durata_cursa_minute'] ?? null)],
        ['Beneficiar', $show($row['beneficiar_nume'] ?? '')],
        ['Tip transport', $transportLabel],
        ['Tip marfa', $goodsLabel($row['tip_marfa'] ?? '')],
        ['Loc incarcare', $show($row['loc_incarcare_nume'] ?? '')],
        ['Loc plecare', $show($row['loc_plecare'] ?? '')],
        ['Loc aspirare', $show($row['loc_aspirare'] ?? '')],
        ['Loc livrare', $show($row['loc_livrare'] ?? '')],
        ['Inchidere cursa', $show($row['loc_livrare_cursa'] ?? '')],
        ['Zona', $show($row['zona_distributie_nume'] ?? '')],
        ['Km rulati', $formatKm($row['km_rulati'] ?? 0)],
        ['Km facturati', $formatKm($row['km_cursa'] ?? 0)],
        ['Km totali', $formatKm($row['km_totali'] ?? 0)],
        ['Km dislocare', $formatKm($row['km_dislocare'] ?? 0)],
        ['Cantitate incarcare', $formatMetric($row['cantitate_incarcata'] ?? null)],
        ['Cantitate prelevata', $formatMetric($row['cantitate_prelevata'] ?? null)],
        ['Tone livrate', $formatMetric($row['tona_livrata'] ?? null, 't')],
        ['Tone lichid/gaz', $liquid === '-' && $gas === '-' ? '-' : $liquid . ' / ' . $gas],
        ['Nr. clienti', $formatMetric($row['nr_clienti'] ?? null, '', 0)],
        ['Ore functionare', $formatMetric($row['ore_functionare'] ?? null, 'h')],
        ['Ore aspirare', $formatMetric($row['ore_aspirare'] ?? null, 'h')],
        ['Tarif', $money($row['pret_tarifare'] ?? 0)],
        ['Cost km calculat', $costKmLabel($row)],
    ];

    ob_start();
    ?>
    <article class="billing-ride-card <?= e($cardClass) ?>">
        <div class="billing-ride-scan">
            <div class="billing-race-main">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <a class="billing-race-link" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">#<?= e((string) $raceId) ?></a>
                    <span class="badge <?= e((string) ($statusBadgeClasses[$statusKey] ?? 'text-bg-secondary')) ?>"><?= e($statusLabel) ?></span>
                </div>
                <h4><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?> <span>/ <?= e((string) ($row['sofer_nume'] ?? '-')) ?></span></h4>
                <p><?= e($formatDateRange($row)) ?> | <?= e($transportLabel) ?> | <?= e($show($row['beneficiar_nume'] ?? '')) ?></p>
            </div>

            <div class="billing-scan-cell">
                <span>Ruta / zona</span>
                <strong><?= e($show($row['loc_incarcare_nume'] ?? '')) ?> -> <?= e($show($row['zona_distributie_nume'] ?? ($row['loc_livrare'] ?? ''))) ?></strong>
                <small><?= e($goodsLabel($row['tip_marfa'] ?? '')) ?></small>
            </div>

            <div class="billing-scan-cell">
                <span>Km</span>
                <strong><?= e($formatKm($row['km_rulati'] ?? 0)) ?></strong>
                <small>facturati <?= e($formatKm($row['km_cursa'] ?? 0)) ?></small>
            </div>

            <div class="billing-ride-money">
                <span><?= $isInvoiced ? 'Facturat transport' : 'De facturat transport' ?></span>
                <strong class="<?= $isInvoiced ? 'text-success' : 'text-warning' ?>"><?= e($money($totalFacturare)) ?></strong>
                <small class="billing-total-formula"><b><?= e($calculationLabel) ?>:</b> <?= e($calculationText) ?></small>
                <?php if ($totalRefacturare > 0): ?>
                    <small>Refacturare: <b><?= e($money($totalRefacturare)) ?></b></small>
                <?php else: ?>
                    <small>Fara refacturare</small>
                <?php endif; ?>
            </div>

            <div class="billing-scan-cell">
                <span>Cheltuieli</span>
                <strong><?= $regularExpenses === [] ? 'Fara cheltuieli' : e($money($regularExpenseTotal)) ?></strong>
                <small><?= e((string) count($regularExpenses)) ?> linii</small>
            </div>

            <div class="billing-scan-actions">
                <form method="post" action="<?= e(build_query_url(['page' => 'centralizator_facturare', 'action' => 'update_status'])) ?>" class="billing-status-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                    <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                    <select class="form-select form-select-sm dispatcher-status-select" id="billing_status_<?= e((string) $raceId) ?>" name="status_facturare" onchange="this.form.submit()" aria-label="Status facturare">
                        <?php foreach ($billingStatuses as $optionKey => $optionLabel): ?>
                            <option value="<?= e((string) $optionKey) ?>" <?= $statusKey === (string) $optionKey ? 'selected' : '' ?>>
                                <?= e((string) $optionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">Editeaza</a>
            </div>
        </div>

        <details class="billing-more-details">
            <summary>Detalii cursa</summary>
            <div class="billing-detail-grid">
                <?php foreach ($details as $detail): ?>
                    <div class="billing-detail-item">
                        <span><?= e((string) $detail[0]) ?></span>
                        <strong><?= e((string) $detail[1]) ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if ($clean($row['observatii'] ?? '') !== ''): ?>
                    <div class="billing-detail-item is-wide">
                        <span>Observatii</span>
                        <strong><?= e($clean($row['observatii'] ?? '')) ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <div class="billing-card-panels">
                <div class="billing-mini-panel">
                    <h5>Cheltuieli pe cursa</h5>
                    <?php if ($regularExpenses === []): ?>
                        <span class="text-muted small">Fara cheltuieli inregistrate.</span>
                    <?php else: ?>
                        <div class="billing-expense-stack">
                            <?php foreach ($regularExpenses as $expenseRow): ?>
                                <span class="badge rounded-pill text-bg-light border text-dark billing-expense-pill">
                                    <?= e($expenseLabel((string) ($expenseRow['tip_cheltuiala'] ?? ''))) ?>:
                                    <?= e($money($expenseRow['suma'] ?? 0)) ?>
                                    <?php if ($clean($expenseRow['data_cheltuiala'] ?? '') !== ''): ?> / <?= e($formatDate($expenseRow['data_cheltuiala'] ?? '')) ?><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="billing-mini-panel is-refacturare">
                    <h5>Refacturare din cheltuieli</h5>
                    <?php if ($refacturareExpenses === []): ?>
                        <span class="text-muted small">Nimic de refacturat din cheltuieli.</span>
                    <?php else: ?>
                        <div class="billing-expense-stack">
                            <?php foreach ($refacturareExpenses as $expenseRow): ?>
                                <span class="badge rounded-pill text-bg-primary billing-expense-pill">
                                    <?= e($expenseLabel((string) ($expenseRow['refacturare_tip_cheltuiala'] ?? ''))) ?>:
                                    <?= e($money($expenseRow['refacturare_suma'] ?? 0)) ?>
                                    <?php if ($clean($expenseRow['refacturare_data'] ?? '') !== ''): ?> / <?= e($formatDate($expenseRow['refacturare_data'] ?? '')) ?><?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </details>
    </article>
    <?php
    return (string) ob_get_clean();
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h2 class="h5 mb-1">Centralizator Facturare</h2>
        <p class="text-muted mb-0">Raspunde rapid: ce s-a facturat, ce ramane de facturat si ce se refactureaza din cheltuieli.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la Dispecer curse</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white"><h3 class="h6 mb-0">Filtre</h3></div>
    <div class="card-body">
        <form method="get" class="billing-filter-form">
            <input type="hidden" name="page" value="centralizator_facturare">
            <input type="hidden" name="action" value="index">
            <div class="billing-filter-field is-wide">
                <label class="form-label" for="billing_filter_q">Cautare</label>
                <input type="text" class="form-control" id="billing_filter_q" name="q" value="<?= e((string) $search) ?>" placeholder="Nr. auto, sofer, beneficiar...">
            </div>
            <div class="billing-filter-field">
                <label class="form-label" for="billing_filter_status">Status</label>
                <select class="form-select" id="billing_filter_status" name="status_facturare">
                    <option value="">Toate</option>
                    <?php foreach (($billingStatuses ?? []) as $statusKey => $statusLabel): ?>
                        <option value="<?= e((string) $statusKey) ?>" <?= (string) ($filters['status_facturare'] ?? '') === (string) $statusKey ? 'selected' : '' ?>><?= e((string) $statusLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="billing-filter-field">
                <label class="form-label" for="billing_filter_tip_transport">Tip transport</label>
                <select class="form-select" id="billing_filter_tip_transport" name="tip_transport">
                    <option value="">Toate</option>
                    <?php foreach (($transportTypes ?? []) as $typeKey => $typeLabel): ?>
                        <option value="<?= e((string) $typeKey) ?>" <?= (string) ($filters['tip_transport'] ?? '') === (string) $typeKey ? 'selected' : '' ?>><?= e((string) $typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="billing-filter-field">
                <label class="form-label" for="billing_filter_zone">Zona distributie</label>
                <select class="form-select" id="billing_filter_zone" name="zona_distributie_id">
                    <option value="">Toate zonele</option>
                    <?php foreach (($distributionZones ?? []) as $zone): ?>
                        <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                        <option value="<?= e((string) $zoneId) ?>" <?= (string) ($filters['zona_distributie_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>><?= e((string) ($zone['nume'] ?? '-')) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text billing-filter-help">Util pentru Distributie / Primar+Distributie.</div>
            </div>
            <div class="billing-filter-field is-wide">
                <label class="form-label" for="billing_filter_vehicle">Nr. inmatriculare</label>
                <select class="form-select" id="billing_filter_vehicle" name="vehicle_id">
                    <option value="">Toate</option>
                    <?php foreach (($vehicles ?? []) as $vehicle): ?>
                        <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                        <option value="<?= e((string) $vehicleId) ?>" <?= (string) ($filters['vehicle_id'] ?? '') === (string) $vehicleId ? 'selected' : '' ?>><?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="billing-filter-field is-wide">
                <label class="form-label" for="billing_filter_beneficiar">Beneficiar</label>
                <select class="form-select" id="billing_filter_beneficiar" name="beneficiar_id">
                    <option value="">Toti</option>
                    <?php foreach (($beneficiaries ?? []) as $beneficiary): ?>
                        <?php $beneficiaryId = (int) ($beneficiary['id'] ?? 0); ?>
                        <option value="<?= e((string) $beneficiaryId) ?>" <?= (string) ($filters['beneficiar_id'] ?? '') === (string) $beneficiaryId ? 'selected' : '' ?>><?= e((string) ($beneficiary['nume'] ?? '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="billing-filter-field">
                <label class="form-label" for="billing_filter_data_start">Data de la</label>
                <input type="date" class="form-control" id="billing_filter_data_start" name="data_start" value="<?= e((string) ($filters['data_start'] ?? '')) ?>">
            </div>
            <div class="billing-filter-field">
                <label class="form-label" for="billing_filter_data_end">Data pana la</label>
                <input type="date" class="form-control" id="billing_filter_data_end" name="data_end" value="<?= e((string) ($filters['data_end'] ?? '')) ?>">
            </div>
            <div class="billing-filter-field is-actions">
                <button type="submit" class="btn btn-primary">Aplica filtre</button>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'centralizator_facturare'])) ?>">Reseteaza</a>
            </div>
        </form>
    </div>
</div>

<div class="billing-question-grid mb-3">
    <div class="billing-question-card is-invoiced">
        <span class="billing-question-label">Facturat transport</span>
        <strong><?= e($money($facturatAmount)) ?></strong>
        <div class="billing-card-breakdown">
            <div><span>Curse facturate</span><b><?= e((string) $facturatCount) ?></b></div>
            <div><span>Tone livrate</span><b><?= e($formatTons($facturatToneLivrate)) ?></b></div>
            <div><span>Tone incarcate</span><b><?= e($formatTons($facturatToneIncarcate)) ?></b></div>
            <div><span>Tone prelevate</span><b><?= e($formatTons($facturatTonePrelevate)) ?></b></div>
            <div><span>Refacturare</span><b><?= e($money($facturatRefacturareAmount)) ?></b></div>
            <div><span>Cheltuieli</span><b><?= e($money($facturatExpenseAmount)) ?></b></div>
            <div><span>Km facturati</span><b><?= e($formatKm($facturatKmFacturati)) ?></b></div>
        </div>
        <?= $renderTransportBreakdown($facturatTransportRows) ?>
    </div>
    <div class="billing-question-card is-pending">
        <span class="billing-question-label">De facturat transport</span>
        <strong><?= e($money($notInvoicedAmount)) ?></strong>
        <div class="billing-card-breakdown">
            <div><span>Curse deschise</span><b><?= e((string) $notInvoicedCount) ?></b></div>
            <div><span>Tone livrate</span><b><?= e($formatTons($notInvoicedToneLivrate)) ?></b></div>
            <div><span>Tone incarcate</span><b><?= e($formatTons($notInvoicedToneIncarcate)) ?></b></div>
            <div><span>Tone prelevate</span><b><?= e($formatTons($notInvoicedTonePrelevate)) ?></b></div>
            <div><span>Refacturare</span><b><?= e($money($notInvoicedRefacturareAmount)) ?></b></div>
            <div><span>Cheltuieli</span><b><?= e($money($notInvoicedExpenseAmount)) ?></b></div>
            <div><span>Km facturati</span><b><?= e($formatKm($notInvoicedKmFacturati)) ?></b></div>
        </div>
        <?= $renderTransportBreakdown($notInvoicedTransportRows) ?>
    </div>
    <div class="billing-question-card is-refacturare">
        <span class="billing-question-label">De refacturat din cheltuieli</span>
        <strong><?= e($money($summaryTotals['total_refacturare'])) ?></strong>
        <div class="billing-card-breakdown">
            <div><span>Curse</span><b><?= e((string) ((int) $summaryTotals['curse_de_refacturat'])) ?></b></div>
            <div><span>Linii refacturare</span><b><?= e((string) ((int) $summaryTotals['refacturare_count'])) ?></b></div>
            <div><span>Tone livrate</span><b><?= e($formatTons($summaryTotals['total_tone_livrate'])) ?></b></div>
            <div><span>Tone incarcate</span><b><?= e($formatTons($summaryTotals['total_tone_incarcate'])) ?></b></div>
            <div><span>Cheltuieli sursa</span><b><?= e($money($summaryTotals['total_cheltuieli'])) ?></b></div>
            <div><span>Sold estimativ</span><b><?= e($money($summaryTotals['sold_estimativ'])) ?></b></div>
        </div>
        <?= $renderTransportBreakdown($refacturareTransportRows, 'total_refacturare') ?>
    </div>
    <div class="billing-question-card is-unbilled">
        <span class="billing-question-label">Nefacturat</span>
        <strong><?= e($money($nefacturatAmount)) ?></strong>
        <div class="billing-card-breakdown">
            <div><span>Curse nefacturate</span><b><?= e((string) $nefacturatCount) ?></b></div>
            <div><span>Tone livrate</span><b><?= e($formatTons($nefacturatToneLivrate)) ?></b></div>
            <div><span>Tone incarcate</span><b><?= e($formatTons($nefacturatToneIncarcate)) ?></b></div>
            <div><span>Tone prelevate</span><b><?= e($formatTons($nefacturatTonePrelevate)) ?></b></div>
            <div><span>Refacturare</span><b><?= e($money($nefacturatRefacturareAmount)) ?></b></div>
            <div><span>Cheltuieli</span><b><?= e($money($nefacturatExpenseAmount)) ?></b></div>
            <div><span>Km facturati</span><b><?= e($formatKm($nefacturatKmFacturati)) ?></b></div>
        </div>
        <?= $renderTransportBreakdown($nefacturatTransportRows) ?>
    </div>
</div>

<div class="billing-insight-grid mb-3">
    <?php if (($vehicleKmRows ?? []) !== []): ?>
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                <h3 class="h6 mb-0">Km pe vehicul</h3>
                <small class="text-muted">curse filtrate</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Nr. auto</th><th class="text-end">Curse</th><th class="text-end">Km rulati</th><th class="text-end">Km facturati</th><th class="text-end">De refacturat</th></tr></thead>
                        <tbody>
                        <?php foreach (($vehicleKmRows ?? []) as $vehicleKmRow): ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) ($vehicleKmRow['nr_inmatriculare'] ?? '-')) ?></td>
                                <td class="text-end"><?= e((string) ((int) ($vehicleKmRow['total_curse'] ?? 0))) ?></td>
                                <td class="text-end"><?= e($formatKm($vehicleKmRow['total_km'] ?? 0)) ?></td>
                                <td class="text-end"><?= e($formatKm($vehicleKmRow['km_facturati'] ?? 0)) ?></td>
                                <td class="text-end"><?= e($money($vehicleKmRow['total_refacturare'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white"><h3 class="h6 mb-0">Cheltuieli si refacturari pe tip</h3></div>
        <div class="card-body">
            <div class="billing-type-totals">
                <div>
                    <div class="text-muted small mb-2">Cheltuieli cursa</div>
                    <?php if ($expenseTypeTotals === []): ?>
                        <span class="text-muted small">Fara cheltuieli in filtrul curent.</span>
                    <?php else: ?>
                        <?php foreach ($expenseTypeTotals as $typeRow): ?>
                            <div class="billing-type-row"><span><?= e($expenseLabel((string) ($typeRow['tip_cheltuiala'] ?? ''))) ?></span><strong><?= e($money($typeRow['total_suma'] ?? 0)) ?></strong></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="text-muted small mb-2">Refacturare din cheltuieli</div>
                    <?php if ($refacturareTypeTotals === []): ?>
                        <span class="text-muted small">Nimic de refacturat.</span>
                    <?php else: ?>
                        <?php foreach ($refacturareTypeTotals as $typeRow): ?>
                            <div class="billing-type-row is-refacturare"><span><?= e($expenseLabel((string) ($typeRow['refacturare_tip_cheltuiala'] ?? ''))) ?></span><strong><?= e($money($typeRow['total_suma'] ?? 0)) ?></strong></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<section class="billing-section mb-3">
    <div class="billing-section-header">
        <div>
            <span class="billing-section-kicker">Din cheltuieli</span>
            <h3>De refacturat catre beneficiar</h3>
            <p>Aici apar doar sumele bifate ca Refacturare in formularul de cheltuieli. Nu este acelasi lucru cu statusul facturii.</p>
        </div>
        <strong><?= e($money($summaryTotals['total_refacturare'])) ?></strong>
    </div>

    <?php if ($refacturareItems === []): ?>
        <div class="billing-empty-state">Nu exista cheltuieli marcate pentru refacturare in filtrul curent.</div>
    <?php else: ?>
        <div class="billing-refacturare-grid">
            <?php foreach ($refacturareItems as $item): ?>
                <?php
                $row = (array) $item['row'];
                $expense = (array) $item['expense'];
                $raceId = (int) ($row['id'] ?? 0);
                $statusKey = (string) ($row['status_facturare'] ?? 'in_curs_facturare');
                $statusLabel = (string) (($billingStatuses ?? [])[$statusKey] ?? $statusKey);
                $sourceAmount = (float) ($expense['suma'] ?? 0);
                $sourceType = (string) ($expense['tip_cheltuiala'] ?? '');
                $refacturareType = (string) ($expense['refacturare_tip_cheltuiala'] ?? '');
                $detailsRows = json_decode((string) ($expense['refacturare_detalii'] ?? ''), true);
                $detailsRows = is_array($detailsRows) ? $detailsRows : [];
                ?>
                <article class="billing-refacturare-card">
                    <div class="d-flex justify-content-between gap-2 align-items-start">
                        <div>
                            <span class="badge text-bg-primary mb-2">Refacturare din cheltuieli</span>
                            <h4 class="h6 mb-1"><?= e($expenseLabel($refacturareType)) ?> - <?= e($money($expense['refacturare_suma'] ?? 0)) ?></h4>
                            <p class="mb-0 text-muted small">Cursa <a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">#<?= e((string) $raceId) ?></a>, <?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?>, <?= e((string) ($row['beneficiar_nume'] ?? '-')) ?></p>
                        </div>
                        <span class="badge <?= e((string) ($statusBadgeClasses[$statusKey] ?? 'text-bg-secondary')) ?>"><?= e($statusLabel) ?></span>
                    </div>
                    <div class="billing-refacturare-meta">
                        <span>Data refacturare: <strong><?= e($formatDate($expense['refacturare_data'] ?? '')) ?></strong></span>
                        <span>Sursa cheltuiala: <strong><?= e($expenseLabel($sourceType)) ?><?= $sourceAmount > 0 ? ' / ' . e($money($sourceAmount)) : '' ?></strong></span>
                        <span>Data cheltuiala: <strong><?= e($formatDate($expense['data_cheltuiala'] ?? '')) ?></strong></span>
                    </div>
                    <?php if ($detailsRows !== []): ?>
                        <div class="billing-road-tax-lines">
                            <?php foreach ($detailsRows as $detailKey => $detailRow): ?>
                                <?php if (!is_array($detailRow)) { continue; } ?>
                                <?php
                                $detailLabels = ['taxa_acces' => 'Taxa acces', 'port' => 'Port', 'trece' => 'Trece'];
                                $detailQty = (float) ($detailRow['bucati'] ?? $detailRow['quantity'] ?? 0);
                                $detailPrice = (float) ($detailRow['pret'] ?? $detailRow['price'] ?? 0);
                                $detailTotal = (float) ($detailRow['total'] ?? ($detailQty * $detailPrice));
                                ?>
                                <span><?= e((string) ($detailLabels[(string) $detailKey] ?? 'Taxa')) ?>: <?= e(format_number_ro($detailQty, $detailQty === floor($detailQty) ? 0 : 2)) ?> x <?= e($money($detailPrice)) ?> = <?= e($money($detailTotal)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($clean($expense['refacturare_observatii'] ?? '') !== ''): ?><p class="small mb-0 mt-2 text-muted"><?= e($clean($expense['refacturare_observatii'] ?? '')) ?></p><?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary mt-3" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">Deschide cursa</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php
$rideSections = [
    [
        'title' => 'Facturat - ce este deja inchis',
        'description' => 'Cursele de aici au status facturat. Cardul arata pentru ce s-a facturat transportul si ce cheltuieli au existat pe cursa.',
        'rows' => $invoicedRows,
        'empty' => 'Nu exista curse facturate in filtrul curent.',
    ],
    [
        'title' => 'Nefacturat / in curs - ce ramane de facturat',
        'description' => 'Cursele de aici nu sunt inchise ca facturate. Transportul ramane de facturat, iar refacturarea apare separat numai daca vine din cheltuieli.',
        'rows' => $notInvoicedRows,
        'empty' => 'Nu exista curse nefacturate sau in curs in filtrul curent.',
    ],
];
?>

<?php foreach ($rideSections as $section): ?>
    <section class="billing-section mb-3">
        <div class="billing-section-header">
            <div>
                <span class="billing-section-kicker">Curse</span>
                <h3><?= e((string) $section['title']) ?></h3>
                <p><?= e((string) $section['description']) ?></p>
            </div>
            <span class="badge text-bg-light border text-dark"><?= e((string) count((array) $section['rows'])) ?> pe pagina</span>
        </div>
        <?php if (($section['rows'] ?? []) === []): ?>
            <div class="billing-empty-state"><?= e((string) $section['empty']) ?></div>
        <?php else: ?>
            <div class="billing-ride-list">
                <?php foreach ((array) $section['rows'] as $row): ?>
                    <?= $renderRideCard((array) $row) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <?php
            $currentPageIndex = (int) ($pagination['page'] ?? 1);
            $totalPages = (int) ($pagination['total_pages'] ?? 1);
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
