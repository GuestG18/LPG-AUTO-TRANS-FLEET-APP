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
$vehicleTotals = (array) ($vehicles['totals'] ?? []);
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

$queryFor = static function (array $overrides = []) use ($filters): string {
    $base = [
        'page' => 'centralizator_facturare',
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

$kpiValue = static function (array $card) use ($fmtMoneyKpi, $fmtSmart): string {
    $unit = (string) ($card['unit'] ?? '');
    $value = $card['value'] ?? 0;
    if ($unit === 'RON') {
        return $fmtMoneyKpi($value);
    }
    return $fmtSmart($value, $unit === 'tone' ? 2 : 0) . ($unit !== '' ? ' ' . $unit : '');
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
.cf-shell {
    border: 1px solid var(--cf-border);
    border-radius: 10px;
    overflow: hidden;
    background: #fff;
}
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
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "activity activity activity distribution distribution distribution ref"
        "primaryTable primaryTable primaryTable distTable distTable distTable ref"
        "vehicleMatrix vehicleMatrix vehicleMatrix vehicleMatrix vehicleDetail vehicleDetail vehicleDetail"
        "refTable refTable refTable refTable refTable refTable refTable";
    gap: 14px;
    padding: 14px;
    background: var(--cf-soft);
}
.cf-main-grid.mode-primar,
.cf-main-grid.mode-primar-tona,
.cf-main-grid.mode-distributie,
.cf-main-grid.mode-primar-distributie,
.cf-main-grid.mode-compresor {
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "primaryTable primaryTable primaryTable distribution distribution distribution ref"
        "distTable distTable distTable distTable distTable distTable ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
        "refTable refTable refTable refTable refTable refTable refTable";
}
.cf-main-grid.mode-primar,
.cf-main-grid.mode-primar-tona,
.cf-main-grid.mode-compresor {
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "primaryTable primaryTable primaryTable primaryTable primaryTable primaryTable ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
        "refTable refTable refTable refTable refTable refTable refTable";
}
.cf-main-grid.mode-distributie {
    grid-template-areas:
        "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3 ref"
        "distribution distribution distribution distribution distribution distribution ref"
        "distTable distTable distTable distTable distTable distTable ref"
        "vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail vehicleDetail"
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
.cf-vehicle-matrix { grid-area: vehicleMatrix; }
.cf-vehicle-detail { grid-area: vehicleDetail; }
.cf-refact-table { grid-area: refTable; }
.cf-summary-grid {
    display: grid;
    grid-template-columns: 170px minmax(0, 1fr);
    gap: 16px;
    align-items: center;
}
.cf-donut {
    width: 152px;
    height: 152px;
    margin: 0 auto;
    border-radius: 50%;
    background: var(--chart, #e5e7eb);
    position: relative;
}
.cf-donut::after {
    content: "";
    position: absolute;
    inset: 34px;
    border-radius: 50%;
    background: #fff;
    box-shadow: inset 0 0 0 1px #edf2f7;
}
.cf-donut-center {
    position: absolute;
    inset: 43px;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #23365f;
    font-weight: 800;
}
.cf-donut-center strong {
    color: #071a44;
    font-size: 26px;
    line-height: 1;
}
.cf-donut-center span { margin-top: 6px; font-size: 12px; }
.cf-activity-summary .cf-donut {
    width: 142px;
    height: 142px;
}
.cf-activity-summary .cf-donut::after { inset: 32px; }
.cf-activity-summary .cf-donut-center { inset: 40px; }
.cf-activity-summary .cf-table {
    table-layout: fixed;
    font-size: 12px;
}
.cf-activity-summary .cf-table th,
.cf-activity-summary .cf-table td {
    padding: 8px 8px;
}
.cf-activity-summary .cf-table .is-number {
    white-space: normal;
}
.cf-activity-summary .cf-transport-name {
    align-items: flex-start;
    white-space: normal;
}
.cf-activity-summary .cf-table th:nth-child(1),
.cf-activity-summary .cf-table td:nth-child(1) { width: 35%; }
.cf-activity-summary .cf-table th:nth-child(2),
.cf-activity-summary .cf-table td:nth-child(2) { width: 13%; }
.cf-activity-summary .cf-table th:nth-child(3),
.cf-activity-summary .cf-table td:nth-child(3) { width: 18%; }
.cf-activity-summary .cf-table th:nth-child(4),
.cf-activity-summary .cf-table td:nth-child(4) { width: 18%; }
.cf-activity-summary .cf-table th:nth-child(5),
.cf-activity-summary .cf-table td:nth-child(5) { width: 16%; }
.cf-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--cf-border);
    border-radius: 7px;
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
.cf-split {
    display: grid;
    grid-template-columns: 122px minmax(0, 1fr);
    gap: 18px;
    align-items: center;
}
.cf-split .cf-donut { width: 118px; height: 118px; }
.cf-split .cf-donut::after { inset: 27px; }
.cf-bucket-list { display: grid; gap: 11px; min-width: 0; }
.cf-bucket-item { display: grid; gap: 4px; min-width: 0; }
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
.cf-vehicle-matrix > .cf-table-wrap > .cf-table { min-width: 1380px; }
.cf-vehicle-detail > .cf-table-wrap > .cf-table { min-width: 760px; }
.cf-refact-table .cf-table { min-width: 1240px; }
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
@media (max-width: 1500px) {
    .cf-main-grid,
    .cf-main-grid.mode-primar,
    .cf-main-grid.mode-primar-tona,
    .cf-main-grid.mode-distributie,
    .cf-main-grid.mode-primar-distributie,
    .cf-main-grid.mode-compresor {
        grid-template-columns: repeat(6, minmax(0, 1fr));
        grid-template-areas:
            "kpi1 kpi1 kpi2 kpi2 kpi3 kpi3"
            "ref ref ref ref ref ref"
            "activity activity activity distribution distribution distribution"
            "primaryTable primaryTable primaryTable distTable distTable distTable"
            "vehicleMatrix vehicleMatrix vehicleMatrix vehicleDetail vehicleDetail vehicleDetail"
            "refTable refTable refTable refTable refTable refTable";
    }
}
@media (max-width: 1180px) {
    .cf-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cf-main-grid,
    .cf-main-grid.mode-primar,
    .cf-main-grid.mode-primar-tona,
    .cf-main-grid.mode-distributie,
    .cf-main-grid.mode-primar-distributie,
    .cf-main-grid.mode-compresor {
        grid-template-columns: 1fr;
        grid-template-areas:
            "kpi1"
            "kpi2"
            "kpi3"
            "ref"
            "activity"
            "distribution"
            "primaryTable"
            "distTable"
            "vehicleMatrix"
            "vehicleDetail"
            "refTable";
    }
    .cf-summary-grid,
    .cf-dist-grid,
    .cf-split { grid-template-columns: 1fr; }
    .cf-dist-total {
        border-right: 0;
        border-bottom: 1px solid var(--cf-border);
        padding: 0 0 14px;
    }
}
@media (max-width: 720px) {
    .cf-header,
    .cf-footer,
    .cf-vehicle-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .cf-filters { grid-template-columns: 1fr; }
    .cf-ref-head { grid-template-columns: 42px minmax(0, 1fr); }
    .cf-ref-close { grid-column: 2; justify-self: end; }
}
</style>

<div class="cf-page">
    <div class="cf-shell">
        <header class="cf-header">
            <div class="cf-title">
                <div class="cf-title-icon" aria-hidden="true"><i class="bi bi-card-list"></i></div>
                <div>
                    <h1>Centralizator facturare</h1>
                    <p>Sumar activități și facturare pe curse - pentru toate tipurile de transport</p>
                </div>
            </div>
            <a class="cf-export" href="<?= e($exportUrl) ?>"><i class="bi bi-file-earmark-excel-fill" aria-hidden="true"></i> Export Excel</a>
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
            <div class="cf-field is-required">
                <label for="cf_beneficiar">Beneficiar / Client</label>
                <select id="cf_beneficiar" name="beneficiar_id">
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

        <main class="cf-main-grid <?= e($gridMode) ?>">
            <?php foreach (array_values($kpiCards) as $index => $card): ?>
                <article class="cf-card cf-kpi is-<?= e((string) ($card['theme'] ?? 'blue')) ?>">
                    <div class="cf-kpi-icon" aria-hidden="true"><i class="bi <?= e((string) ($card['icon'] ?? 'bi-bar-chart-fill')) ?>"></i></div>
                    <div>
                        <span class="cf-kpi-title"><?= e((string) ($card['title'] ?? '-')) ?></span>
                        <strong class="cf-kpi-value"><?= e($kpiValue($card)) ?></strong>
                        <div class="cf-kpi-foot"><?= $comparisonMarkup($card['comparison'] ?? null) ?></div>
                    </div>
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
                    <div class="cf-summary-grid">
                        <div class="cf-donut" style="--chart: <?= e((string) (($activityChart['gradient'] ?? '') ?: '#e5e7eb')) ?>">
                            <div class="cf-donut-center">
                                <strong><?= e($fmtSmart($activityTotals['trips'] ?? 0, 0)) ?></strong>
                                <span>curse totale</span>
                            </div>
                        </div>
                        <div class="cf-table-wrap">
                            <table class="cf-table">
                                <thead><tr><th>Tip transport</th><th class="is-number">Curse</th><th class="is-number">Km</th><th class="is-number">Tone/Activ.</th><th class="is-number">% din total curse</th></tr></thead>
                                <tbody>
                                <?php foreach ($activityRows as $row): ?>
                                    <?php $metricTone = (float) ($row['tone'] ?? 0) > 0 ? $fmtSmart($row['tone'], 2) . ' t' : ((float) ($row['activity'] ?? 0) > 0 ? $fmtSmart($row['activity'], 2) . ' ' . (string) ($row['activity_unit'] ?? '') : '-'); ?>
                                    <tr>
                                        <td><span class="cf-transport-name"><span class="cf-dot" style="--dot: <?= e((string) ($row['color'] ?? '#2f7df4')) ?>"></span><?= e((string) ($row['label'] ?? '-')) ?></span></td>
                                        <td class="is-number"><?= e($fmtSmart($row['trips'] ?? 0, 0)) ?></td>
                                        <td class="is-number"><?= e($fmtKm($row['km'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($metricTone) ?></td>
                                        <td class="is-number"><?= e($fmtPercent($row['share_percent'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="cf-total-row">
                                    <td>TOTAL</td>
                                    <td class="is-number"><?= e($fmtSmart($activityTotals['trips'] ?? 0, 0)) ?></td>
                                    <td class="is-number"><?= e($fmtKm($activityTotals['km'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtTone($activityTotals['tone'] ?? 0)) ?></td>
                                    <td class="is-number"><?= (int) ($activityTotals['trips'] ?? 0) > 0 ? '100%' : '0%' ?></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['distribution'])): ?>
                <section class="cf-panel cf-distribution-summary">
                    <h2>Distribuție - Rezumat <i class="bi bi-info-circle" aria-hidden="true"></i></h2>
                    <?php if ((float) ($distribution['total_tone'] ?? 0) <= 0): ?>
                        <div class="cf-empty">Nu există activitate de distribuție pentru filtrul curent.</div>
                    <?php else: ?>
                    <div class="cf-dist-grid">
                        <div class="cf-dist-total">
                            <span class="cf-label">Total tone</span>
                            <strong class="cf-big"><?= e($fmtTone($distribution['total_tone'] ?? 0)) ?></strong>
                            <div class="cf-cargo-list">
                                <?php foreach ((array) ($distribution['cargo_totals'] ?? []) as $cargo): ?>
                                    <div class="cf-cargo-row">
                                        <div><span><?= e((string) ($cargo['label'] ?? '-')) ?></span><strong><?= e($fmtTone($cargo['tone'] ?? 0)) ?></strong></div>
                                        <em><?= e($fmtPercent($cargo['percent'] ?? 0)) ?></em>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <div class="cf-label">Split preț (RON/tonă)</div>
                            <div class="cf-split">
                                <div class="cf-donut" style="--chart: <?= e((string) (($distribution['chart']['gradient'] ?? '') ?: '#e5e7eb')) ?>"></div>
                                <div class="cf-bucket-list">
                                    <?php foreach ((array) ($distribution['tariff_buckets'] ?? []) as $bucket): ?>
                                        <div class="cf-bucket-item">
                                            <div class="cf-bucket-top">
                                                <span class="cf-bucket-name"><span class="cf-dot" style="--dot: <?= e((string) ($bucket['color'] ?? '#2f7df4')) ?>"></span><?= e((string) ($bucket['label'] ?? '-')) ?></span>
                                                <span class="cf-bucket-tone"><?= e($fmtSmart($bucket['tone'] ?? 0, 2)) ?> t</span>
                                            </div>
                                            <div class="cf-bucket-bottom">
                                                <strong class="cf-bucket-rate"><?= ($bucket['tariff'] ?? null) !== null ? e($fmtSmart($bucket['tariff'], 2)) . ' RON/t' : '-' ?></strong>
                                                <span class="cf-bucket-percent"><?= e($fmtPercent($bucket['percent'] ?? 0)) ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (($distribution['warnings'] ?? []) !== []): ?><p class="cf-warning"><?= e((string) reset($distribution['warnings'])) ?></p><?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['primary_routes'])): ?>
                <section class="cf-panel cf-primary-table">
                    <h2>Detalii Primar km - pe rute</h2>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead><tr><th>Rută</th><th class="is-number">Curse</th><th class="is-number">Km parcurși</th><th class="is-number">Preț / km (RON)</th><th class="is-number">Valoare (RON)</th></tr></thead>
                            <tbody>
                            <?php foreach ($primaryRoutes as $route): ?>
                                <tr>
                                    <td><?= e((string) ($route['route_short'] ?? '-')) ?> (<?= e((string) ($route['route_label'] ?? '-')) ?>)</td>
                                    <td class="is-number"><?= e($fmtSmart($route['trips'] ?? 0, 0)) ?></td>
                                    <td class="is-number"><?= e($fmtKm($route['km'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e((string) ($route['rate_label'] ?? '-')) ?></td>
                                    <td class="is-number"><?= e($fmtMoney($route['value'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="cf-total-row"><td>TOTAL</td><td class="is-number"><?= e($fmtSmart($primaryTotals['trips'] ?? 0, 0)) ?></td><td class="is-number"><?= e($fmtKm($primaryTotals['km'] ?? 0)) ?></td><td class="is-number">-</td><td class="is-number"><?= e($fmtMoney($primaryTotals['value'] ?? 0)) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['distribution_matrix'])): ?>
                <section class="cf-panel cf-distribution-table">
                    <h2>Detalii Distribuție - pe marfă și preț</h2>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead>
                            <tr>
                                <th>Tip marfă</th>
                                <?php foreach ((array) ($distribution['tariff_buckets'] ?? []) as $bucket): ?><th class="is-number"><?= e((string) ($bucket['label'] ?? '-')) ?> (tone)</th><?php endforeach; ?>
                                <th class="is-number">Total tone</th><th class="is-number">% din total</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (($distribution['cargo_by_tariff'] ?? []) === []): ?>
                                <tr><td colspan="<?= e((string) (3 + count((array) ($distribution['tariff_buckets'] ?? [])))) ?>"><div class="cf-empty">Nu există tonaj de distribuție pentru filtrul curent.</div></td></tr>
                            <?php endif; ?>
                            <?php foreach ((array) ($distribution['cargo_by_tariff'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= e((string) ($row['label'] ?? '-')) ?></td>
                                    <?php foreach ((array) ($distribution['tariff_buckets'] ?? []) as $bucket): ?>
                                        <?php $bucketKey = (string) ($bucket['key'] ?? ''); ?>
                                        <td class="is-number"><?= e($fmtPlain($row['buckets'][$bucketKey] ?? 0, 2)) ?></td>
                                    <?php endforeach; ?>
                                    <td class="is-number"><?= e($fmtSmart($row['total_tone'] ?? 0, 2)) ?></td>
                                    <td class="is-number"><?= e($fmtPercent($row['percent'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="cf-total-row">
                                <td>TOTAL</td>
                                <?php foreach ((array) ($distribution['tariff_buckets'] ?? []) as $bucket): ?>
                                    <?php $bucketKey = (string) ($bucket['key'] ?? ''); ?>
                                    <td class="is-number"><?= e($fmtPlain($distribution['matrix_totals']['buckets'][$bucketKey] ?? 0, 2)) ?></td>
                                <?php endforeach; ?>
                                <td class="is-number"><?= e($fmtSmart($distribution['matrix_totals']['total_tone'] ?? 0, 2)) ?></td>
                                <td class="is-number"><?= (float) ($distribution['matrix_totals']['total_tone'] ?? 0) > 0 ? '100%' : '0%' ?></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['vehicle_matrix'])): ?>
                <section class="cf-panel cf-vehicle-matrix">
                    <div class="cf-vehicle-toolbar">
                        <h2 style="margin:0;padding-bottom:0;border-bottom:0;">Activitate pe vehicule - Rezumat general</h2>
                        <form class="cf-sort-form" method="get">
                            <span>Ordonează după:</span>
                            <?php foreach (['page','month','beneficiar_id','tip_activitate','tip_marfa','loc_incarcare_id','zona_distributie_id','ruta','vehicle_id','per_page'] as $hidden): ?>
                                <input type="hidden" name="<?= e($hidden) ?>" value="<?= e($hidden === 'page' ? 'centralizator_facturare' : $filterValue($hidden)) ?>">
                            <?php endforeach; ?>
                            <select name="vehicle_sort" onchange="this.form.submit()">
                                <?php foreach ((array) ($lookups['vehicle_sort_options'] ?? []) as $value => $label): ?>
                                    <option value="<?= e((string) $value) ?>" <?= $filterValue('vehicle_sort') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead>
                            <tr><th rowspan="2" class="cf-expand-th"></th><th rowspan="2">Vehicul</th><th rowspan="2" class="is-number">Capacitate</th><th rowspan="2" class="is-number">Nr. curse</th><th rowspan="2">Rută</th><th colspan="2" class="is-center">Primar km</th><th colspan="2" class="is-center">Primar tone</th><th colspan="2" class="is-center">Distribuție</th><th colspan="3" class="is-center">P+D</th><th colspan="2" class="is-center">Compresor</th><th rowspan="2" class="is-number">Total valoare</th></tr>
                            <tr><th class="is-number">Km</th><th class="is-number">Valoare</th><th class="is-number">Tone</th><th class="is-number">Valoare</th><th class="is-number">Tone</th><th class="is-number">Valoare</th><th class="is-number">Km</th><th class="is-number">Tone</th><th class="is-number">Valoare</th><th class="is-number">Tone/Activ.</th><th class="is-number">Valoare</th></tr>
                            </thead>
                            <tbody>
                            <?php if ($vehicleRows === []): ?>
                                <tr><td colspan="17"><div class="cf-empty">Nu există activitate pe vehicule pentru filtrul curent.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ($vehicleRows as $row): ?>
                                    <?php $vehicleKey = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($row['key'] ?? uniqid('veh_', false))); $detailId = 'cf_vehicle_matrix_' . $vehicleKey; ?>
                                    <tr class="cf-vehicle-parent" data-vehicle-row>
                                        <td class="cf-expand-cell"><button class="cf-expand-btn" type="button" aria-expanded="false" aria-controls="<?= e($detailId) ?>" data-vehicle-toggle><i class="bi bi-chevron-right" aria-hidden="true"></i></button></td>
                                        <td><i class="bi bi-truck" aria-hidden="true"></i> <?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                                        <td class="is-number"><?= e($fmtCapacity($row['capacity'] ?? null)) ?></td>
                                        <td class="is-number"><?= e($fmtSmart($row['trips'] ?? 0, 0)) ?></td>
                                        <td><span class="cf-route-summary" title="<?= e((string) ($row['route_summary'] ?? '-')) ?>"><?= e((string) ($row['route_summary'] ?? '-')) ?></span></td>
                                        <td class="is-number"><?= e($fmtKm($row['primar']['km'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($row['primar']['value'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtTone($row['primar_tona']['tone'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($row['primar_tona']['value'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtTone($row['distributie']['tone'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($row['distributie']['value'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtKm($row['primar_distributie']['km'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtTone($row['primar_distributie']['tone'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($row['primar_distributie']['value'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtPlain($row['compresor']['activity'] ?? 0, 2)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($row['compresor']['value'] ?? 0)) ?></td>
                                        <td class="is-number"><?= e($fmtMoney($row['total_value'] ?? 0)) ?></td>
                                    </tr>
                                    <tr class="cf-vehicle-detail-row" id="<?= e($detailId) ?>" hidden>
                                        <td colspan="17" class="cf-trip-detail-cell"><?= $renderTripDetails((array) ($row['detail_rows'] ?? []), $vehicleTripColumns) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="cf-total-row">
                                    <td></td><td>TOTAL</td><td class="is-number">-</td><td class="is-number"><?= e($fmtSmart($vehicleTotals['trips'] ?? 0, 0)) ?></td><td>-</td>
                                    <td class="is-number"><?= e($fmtKm($vehicleTotals['primar']['km'] ?? 0)) ?></td><td class="is-number"><?= e($fmtMoney($vehicleTotals['primar']['value'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtTone($vehicleTotals['primar_tona']['tone'] ?? 0)) ?></td><td class="is-number"><?= e($fmtMoney($vehicleTotals['primar_tona']['value'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtTone($vehicleTotals['distributie']['tone'] ?? 0)) ?></td><td class="is-number"><?= e($fmtMoney($vehicleTotals['distributie']['value'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtKm($vehicleTotals['primar_distributie']['km'] ?? 0)) ?></td><td class="is-number"><?= e($fmtTone($vehicleTotals['primar_distributie']['tone'] ?? 0)) ?></td><td class="is-number"><?= e($fmtMoney($vehicleTotals['primar_distributie']['value'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtPlain($vehicleTotals['compresor']['activity'] ?? 0, 2)) ?></td><td class="is-number"><?= e($fmtMoney($vehicleTotals['compresor']['value'] ?? 0)) ?></td>
                                    <td class="is-number"><?= e($fmtMoney($vehicleTotals['total_value'] ?? 0)) ?></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="cf-note">Vehicule sortate după capacitate de încărcare. Pentru capacități egale, se sortează alfabetic după nr. de înmatriculare.</p>
                </section>
            <?php endif; ?>

            <?php if (!empty($visibility['vehicle_detail'])): ?>
                <section class="cf-panel cf-vehicle-detail">
                    <div class="cf-vehicle-toolbar">
                        <h2 style="margin:0;padding-bottom:0;border-bottom:0;">Activitate pe vehicule - Detaliat (după activitatea filtrată)</h2>
                    </div>
                    <div class="cf-table-wrap">
                        <table class="cf-table">
                            <thead><tr><?php foreach ((array) ($vehicleDetail['columns'] ?? []) as $col): ?><?php $headKey = (string) ($col['key'] ?? ''); ?><th class="<?= $headKey === 'toggle' ? 'cf-expand-th' : (($col['align'] ?? '') === 'right' ? 'is-number' : '') ?>"><?= e((string) ($col['label'] ?? '')) ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                            <?php if (($vehicleDetail['rows'] ?? []) === []): ?>
                                <tr><td colspan="<?= e((string) max(1, count((array) ($vehicleDetail['columns'] ?? [])))) ?>"><div class="cf-empty">Nu există vehicule cu activitate pentru filtrul curent.</div></td></tr>
                            <?php else: ?>
                                <?php foreach ((array) ($vehicleDetail['rows'] ?? []) as $row): ?>
                                    <?php $vehicleKey = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($row['key'] ?? uniqid('veh_', false))); $detailId = 'cf_vehicle_detail_' . $vehicleKey; ?>
                                    <tr class="cf-vehicle-parent" data-vehicle-row>
                                        <?php foreach ((array) ($vehicleDetail['columns'] ?? []) as $col): ?>
                                            <?php $key = (string) ($col['key'] ?? ''); ?>
                                            <td class="<?= ($col['align'] ?? '') === 'right' ? 'is-number' : '' ?>">
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
                                        <td colspan="<?= e((string) max(1, count((array) ($vehicleDetail['columns'] ?? [])))) ?>" class="cf-trip-detail-cell"><?= $renderTripDetails((array) ($row['detail_rows'] ?? []), (array) ($row['detail_columns'] ?? $vehicleTripColumns)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="cf-total-row">
                                    <?php foreach ((array) ($vehicleDetail['columns'] ?? []) as $col): ?>
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
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="cf-note">Se afișează doar vehiculele care au activitate pentru filtrele selectate.</p>
                    <?php if (($vehicles['warnings'] ?? []) !== []): ?><p class="cf-warning"><?= e((string) reset($vehicles['warnings'])) ?></p><?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="cf-panel cf-refact-table" id="cf_ref_table">
                <h2>Refacturări - sumar pe curse</h2>
                <div class="cf-table-wrap">
                    <table class="cf-table">
                        <thead><tr><th>Nr. cursă</th><th>Data</th><th>Tip activitate</th><th>Rută / Zonă</th><th>Vehicul</th><th>Tip marfă</th><th class="is-number">Tone</th><th class="is-number">Km</th><th class="is-number">Valoare cursă (RON)</th><th class="is-number">Refacturare (RON)</th><th>Observații</th></tr></thead>
                        <tbody>
                        <?php if ($refRows === []): ?>
                            <tr><td colspan="11"><div class="cf-empty">Nu există refacturări pentru filtrul curent.</div></td></tr>
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
    }

    document.querySelectorAll('[data-vehicle-toggle]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            toggleVehicleDetail(button);
        });
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
