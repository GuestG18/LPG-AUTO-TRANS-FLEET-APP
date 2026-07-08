<?php
$vehicleOptions = is_array($vehicleOptions ?? null) ? $vehicleOptions : [];
$selectedVehicleId = (int) ($selectedVehicleId ?? 0);
$record = is_array($selectedVehicle ?? null) ? $selectedVehicle : null;
$tireContext = is_array($vehicleTireContext ?? null) ? $vehicleTireContext : [];
$axisVehicleView = in_array((string) ($axisVehicleView ?? 'camion'), ['camion', 'ansamblu'], true) ? (string) $axisVehicleView : 'camion';
$axisVehicleViewOptions = is_array($axisVehicleViewOptions ?? null) ? $axisVehicleViewOptions : ['camion' => 'Camion', 'ansamblu' => 'Ansamblu'];
$axisVehicleFilters = is_array($axisVehicleFilters ?? null) ? $axisVehicleFilters : [];
$axisVehicleFilterOptions = is_array($axisVehicleFilterOptions ?? null) ? $axisVehicleFilterOptions : [];
$axisTransportCapacityFilter = (string) ($axisVehicleFilters['capacitate_transport'] ?? '');
$axisMmaFilter = (string) ($axisVehicleFilters['mma'] ?? '');
$axisTransportCapacityOptions = is_array($axisVehicleFilterOptions['capacitate_transport'] ?? null) ? $axisVehicleFilterOptions['capacitate_transport'] : [];
$axisMmaOptions = is_array($axisVehicleFilterOptions['mma'] ?? null) ? $axisVehicleFilterOptions['mma'] : [];
$axisFiltersActive = $axisTransportCapacityFilter !== '' || $axisMmaFilter !== '';
$axisAssemblyContext = is_array($axisAssemblyContext ?? null) ? $axisAssemblyContext : null;

$tireLayout = is_array($tireContext['layout'] ?? null) ? $tireContext['layout'] : [];
$tirePositions = is_array($tireContext['positions'] ?? null) ? $tireContext['positions'] : [];
$tireAlerts = is_array($tireContext['alerts'] ?? null) ? $tireContext['alerts'] : [];
$availableTires = is_array($tireContext['available_tires'] ?? null) ? $tireContext['available_tires'] : [];
$tireHistory = is_array($tireContext['history'] ?? null) ? $tireContext['history'] : [];
$layoutOptions = is_array($tireContext['layout_options'] ?? null) ? $tireContext['layout_options'] : [];
$vehicleType = (string) ($record['tip_vehicul'] ?? ($tireContext['vehicle_type'] ?? 'autovehicul'));
$layoutCurrentValue = (string) ($tireContext['layout_current_value'] ?? ($record['formula_axelor'] ?? ''));
$layoutCurrentValue = $layoutCurrentValue !== '' ? $layoutCurrentValue : (string) ($record['formula_axelor'] ?? '');
$todayDate = (string) ($tireContext['today'] ?? date('Y-m-d'));
$mountedTires = (int) ($tireLayout['mounted_tires'] ?? 0);
$expectedTires = (int) ($tireLayout['expected_tires'] ?? count($tirePositions));
$unmountedPositions = (int) ($tireLayout['unmounted_positions'] ?? max(0, $expectedTires - $mountedTires));
$wearDefaultLimitKm = 80000;

$resolveAxleRole = static function (string $vehicleTypeValue, string $layoutValue, int $axleNo): string {
    $layoutValue = strtolower(trim($layoutValue));

    if ($vehicleTypeValue === 'camion') {
        if ($axleNo === 1) {
            return 'Directie';
        }

        if ($layoutValue === '6x2' && $axleNo === 3) {
            return 'Stanga / Dreapta';
        }

        if ($layoutValue === '8x2' && in_array($axleNo, [2, 3], true)) {
            return 'Stanga / Dreapta';
        }

        if (
            ($layoutValue === '4x2' && $axleNo === 2)
            || ($layoutValue === '6x2' && $axleNo === 2)
            || ($layoutValue === '8x2' && $axleNo === 4)
        ) {
            return 'Tractiune';
        }
    }

    if ($vehicleTypeValue === 'cap_tractor') {
        return $axleNo === 1 ? 'Directie' : 'Tractiune';
    }

    if (function_exists('is_trailer_vehicle_type') && is_trailer_vehicle_type($vehicleTypeValue)) {
        return 'Semiremorca';
    }

    if (in_array($vehicleTypeValue, ['autovehicul', 'autoutilitara'], true)) {
        return $axleNo === 1 ? 'Directie' : 'Tractiune';
    }

    return '';
};

$computeWearMeta = static function (?array $tire, int $defaultLimitKm): array {
    if ($tire === null) {
        return [
            'status_code' => 'empty',
            'status_label' => 'Gol',
            'badge_class' => 'tire-wear-badge tire-wear-badge-empty',
            'progress_class' => 'bg-secondary',
            'progress_width' => 0,
            'percent_class' => 'text-muted',
            'percent_display' => '-',
            'km_display' => '-',
        ];
    }

    $usedKm = max(0, (int) round((float) ($tire['km_total_used'] ?? 0)));
    $limitKm = isset($tire['estimated_life_km']) && (int) $tire['estimated_life_km'] > 0
        ? (int) $tire['estimated_life_km']
        : $defaultLimitKm;
    $percentValue = $limitKm > 0 ? ((float) $usedKm / (float) $limitKm) * 100.0 : 0.0;
    $progressWidth = max(0.0, min(100.0, $percentValue));

    $statusCode = 'ok';
    $statusLabel = 'OK';
    $badgeClass = 'tire-wear-badge tire-wear-badge-ok';
    $progressClass = 'bg-success';
    $percentClass = 'text-success';

    if ($percentValue > 90.0) {
        $statusCode = 'critic';
        $statusLabel = 'CRITIC';
        $badgeClass = 'tire-wear-badge tire-wear-badge-critic';
        $progressClass = 'bg-danger';
        $percentClass = 'text-danger';
    } elseif ($percentValue > 75.0) {
        $statusCode = 'warning';
        $statusLabel = 'ATENTIE';
        $badgeClass = 'tire-wear-badge tire-wear-badge-warning';
        $progressClass = 'bg-warning';
        $percentClass = 'text-warning';
    }

    return [
        'status_code' => $statusCode,
        'status_label' => $statusLabel,
        'badge_class' => $badgeClass,
        'progress_class' => $progressClass,
        'progress_width' => $progressWidth,
        'percent_class' => $percentClass,
        'percent_display' => (string) ((int) round($percentValue)) . '%',
        'km_display' => number_format((float) $usedKm, 0, ',', '.') . ' / ' . number_format((float) $limitKm, 0, ',', '.') . ' km',
    ];
};

$wearMetaDefaults = [
    'status_code' => 'empty',
    'status_label' => 'Gol',
    'badge_class' => 'tire-wear-badge tire-wear-badge-empty',
    'progress_class' => 'bg-secondary',
    'progress_width' => 0,
    'percent_class' => 'text-muted',
    'percent_display' => '-',
    'km_display' => '-',
];
$normalizeWearMeta = static fn(array $rawWearMeta): array => array_replace($wearMetaDefaults, $rawWearMeta);
$resolveVisualBadgeLabel = static fn(?array $position): string => $position !== null ? trim((string) ($position['position_code'] ?? '')) : '';

$renderVehicleTirePositionItem = static function (array $axlePosition) use ($computeWearMeta, $normalizeWearMeta, $wearDefaultLimitKm): void {
    $wearMetaRaw = is_array($axlePosition['wear_meta'] ?? null) ? $axlePosition['wear_meta'] : $computeWearMeta(null, $wearDefaultLimitKm);
    $wearMeta = $normalizeWearMeta($wearMetaRaw);
    $sideLabel = (string) ($axlePosition['position_label'] ?? '-');
    if (str_contains($sideLabel, ' - ')) {
        $parts = explode(' - ', $sideLabel, 2);
        $sideLabel = trim((string) ($parts[1] ?? $sideLabel));
    }
    ?>
    <div class="vehicle-tire-position-item">
        <div class="vehicle-tire-position-code"><?= e((string) ($axlePosition['position_code'] ?? '-')) ?></div>
        <div class="vehicle-tire-position-label"><?= e($sideLabel) ?></div>
        <div class="text-center mb-2">
            <span class="<?= e((string) $wearMeta['badge_class']) ?>">
                <span class="tire-wear-badge-dot"></span>
                <?= e((string) $wearMeta['status_label']) ?>
            </span>
        </div>
        <?php if ((string) ($wearMeta['status_code'] ?? '') !== 'empty'): ?>
            <div class="small text-muted text-center mb-1"><?= e((string) $wearMeta['km_display']) ?></div>
            <div class="tire-wear-progress mb-1">
                <div class="progress-bar <?= e((string) $wearMeta['progress_class']) ?>" style="width: <?= e(number_format((float) $wearMeta['progress_width'], 2, '.', '')) ?>%"></div>
            </div>
            <div class="small fw-semibold text-center <?= e((string) $wearMeta['percent_class']) ?>"><?= e((string) $wearMeta['percent_display']) ?></div>
        <?php endif; ?>
    </div>
    <?php
};

$tirePositionsPrepared = [];
$axleGroups = [];
foreach ($tirePositions as $position) {
    $positionTire = is_array($position['tire'] ?? null) ? $position['tire'] : null;
    $wearMeta = $normalizeWearMeta($computeWearMeta($positionTire, $wearDefaultLimitKm));
    $position['wear_meta'] = $wearMeta;
    $tirePositionsPrepared[] = $position;

    $axleNo = max(0, (int) ($position['axle_no'] ?? 0));
    if (!isset($axleGroups[$axleNo])) {
        $axleGroups[$axleNo] = [
            'axle_no' => $axleNo,
            'role' => $resolveAxleRole($vehicleType, $layoutCurrentValue, $axleNo),
            'positions' => [],
        ];
    }
    $axleGroups[$axleNo]['positions'][] = $position;
}
ksort($axleGroups);

$layoutSummary = trim($layoutCurrentValue) !== ''
    ? $layoutCurrentValue . ' (' . (string) $expectedTires . ' anvelope)'
    : '-';

$buildVehicleLabel = static function (array $vehicle): string {
    $plate = trim((string) ($vehicle['nr_inmatriculare'] ?? ''));
    $makeModel = trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''));
    $label = trim($plate . ($makeModel !== '' ? ' - ' . $makeModel : ''));

    return $label !== '' ? $label : 'Vehicul #' . (string) ((int) ($vehicle['id'] ?? 0));
};

$buildVehicleAxisDisplayData = static function (array $vehicleRecord, array $context) use ($resolveAxleRole, $computeWearMeta, $normalizeWearMeta, $wearDefaultLimitKm): array {
    $displayLayout = is_array($context['layout'] ?? null) ? $context['layout'] : [];
    $displayPositions = is_array($context['positions'] ?? null) ? $context['positions'] : [];
    $displayVehicleType = (string) ($vehicleRecord['tip_vehicul'] ?? ($context['vehicle_type'] ?? 'autovehicul'));
    $displayLayoutValue = (string) ($context['layout_current_value'] ?? ($vehicleRecord['formula_axelor'] ?? ''));
    $displayLayoutValue = $displayLayoutValue !== '' ? $displayLayoutValue : (string) ($vehicleRecord['formula_axelor'] ?? '');
    $displayMountedTires = (int) ($displayLayout['mounted_tires'] ?? 0);
    $displayExpectedTires = (int) ($displayLayout['expected_tires'] ?? count($displayPositions));
    $displayUnmountedPositions = (int) ($displayLayout['unmounted_positions'] ?? max(0, $displayExpectedTires - $displayMountedTires));

    $displayGroups = [];
    $displayPreparedPositions = [];
    foreach ($displayPositions as $position) {
        $positionTire = is_array($position['tire'] ?? null) ? $position['tire'] : null;
        $position['wear_meta'] = $normalizeWearMeta($computeWearMeta($positionTire, $wearDefaultLimitKm));
        $displayPreparedPositions[] = $position;

        $axleNo = max(0, (int) ($position['axle_no'] ?? 0));
        if (!isset($displayGroups[$axleNo])) {
            $displayGroups[$axleNo] = [
                'axle_no' => $axleNo,
                'role' => $resolveAxleRole($displayVehicleType, $displayLayoutValue, $axleNo),
                'positions' => [],
            ];
        }
        $displayGroups[$axleNo]['positions'][] = $position;
    }
    ksort($displayGroups);

    return [
        'axle_groups' => $displayGroups,
        'positions_prepared' => $displayPreparedPositions,
        'layout_summary' => trim($displayLayoutValue) !== ''
            ? $displayLayoutValue . ' (' . (string) $displayExpectedTires . ' anvelope)'
            : '-',
        'mounted_tires' => $displayMountedTires,
        'expected_tires' => $displayExpectedTires,
        'unmounted_positions' => $displayUnmountedPositions,
        'vehicle_type' => $displayVehicleType,
        'layout_current_value' => $displayLayoutValue,
    ];
};

$axisVehicleDisplayBlocks = [];
if ($record !== null && $axisVehicleView === 'ansamblu' && is_array($axisAssemblyContext['members'] ?? null)) {
    foreach ($axisAssemblyContext['members'] as $member) {
        $memberRecord = is_array($member['record'] ?? null) ? $member['record'] : null;
        $memberContext = is_array($member['context'] ?? null) ? $member['context'] : [];
        if ($memberRecord === null) {
            continue;
        }

        $axisVehicleDisplayBlocks[] = [
            'role_label' => (string) ($member['role_label'] ?? (function_exists('vehicle_type_label') ? vehicle_type_label((string) ($memberRecord['tip_vehicul'] ?? '')) : 'Vehicul')),
            'label' => (string) (($member['label'] ?? '') !== '' ? $member['label'] : $buildVehicleLabel($memberRecord)),
            'record' => $memberRecord,
            'view' => $buildVehicleAxisDisplayData($memberRecord, $memberContext),
        ];
    }
}

if ($record !== null && $axisVehicleDisplayBlocks === []) {
    $axisVehicleDisplayBlocks[] = [
        'role_label' => function_exists('vehicle_type_label') ? vehicle_type_label($vehicleType) : $vehicleType,
        'label' => $buildVehicleLabel($record),
        'record' => $record,
        'view' => [
            'axle_groups' => $axleGroups,
            'positions_prepared' => $tirePositionsPrepared,
            'layout_summary' => $layoutSummary,
            'mounted_tires' => $mountedTires,
            'expected_tires' => $expectedTires,
            'unmounted_positions' => $unmountedPositions,
            'vehicle_type' => $vehicleType,
            'layout_current_value' => $layoutCurrentValue,
        ],
    ];
}

$summaryVehicleTypeValue = $axisVehicleView === 'ansamblu'
    ? 'Ansamblu'
    : (function_exists('vehicle_type_label') ? vehicle_type_label((string) ($tireLayout['vehicle_type'] ?? $vehicleType)) : $vehicleType);
$summaryLayoutValue = $layoutSummary;
$summaryMountedTires = $mountedTires;
$summaryExpectedTires = $expectedTires;
$summaryUnmountedPositions = $unmountedPositions;
if ($axisVehicleView === 'ansamblu' && $axisVehicleDisplayBlocks !== []) {
    $summaryLayoutParts = [];
    $summaryMountedTires = 0;
    $summaryExpectedTires = 0;
    $summaryUnmountedPositions = 0;
    foreach ($axisVehicleDisplayBlocks as $displayBlock) {
        $blockView = is_array($displayBlock['view'] ?? null) ? $displayBlock['view'] : [];
        $summaryLayoutParts[] = (string) ($displayBlock['role_label'] ?? 'Vehicul') . ': ' . (string) ($blockView['layout_summary'] ?? '-');
        $summaryMountedTires += (int) ($blockView['mounted_tires'] ?? 0);
        $summaryExpectedTires += (int) ($blockView['expected_tires'] ?? 0);
        $summaryUnmountedPositions += (int) ($blockView['unmounted_positions'] ?? 0);
    }
    $summaryLayoutValue = implode(' + ', $summaryLayoutParts);
}

$axisTirePositionRows = [];
foreach ($axisVehicleDisplayBlocks as $displayBlock) {
    $blockRecord = is_array($displayBlock['record'] ?? null) ? $displayBlock['record'] : [];
    $blockView = is_array($displayBlock['view'] ?? null) ? $displayBlock['view'] : [];
    $blockPositions = is_array($blockView['positions_prepared'] ?? null) ? $blockView['positions_prepared'] : [];
    foreach ($blockPositions as $blockPosition) {
        $axisTirePositionRows[] = [
            'position' => $blockPosition,
            'vehicle_id' => (int) ($blockRecord['id'] ?? 0),
            'vehicle_label' => (string) ($displayBlock['label'] ?? $buildVehicleLabel($blockRecord)),
            'vehicle_role' => (string) ($displayBlock['role_label'] ?? ''),
            'vehicle_type' => (string) ($blockView['vehicle_type'] ?? ($blockRecord['tip_vehicul'] ?? 'autovehicul')),
            'layout_current_value' => (string) ($blockView['layout_current_value'] ?? ($blockRecord['formula_axelor'] ?? '')),
        ];
    }
}
$showVehicleColumnInPositions = $axisVehicleView === 'ansamblu';
?>

<style>
.axis-config-page .vehicle-tire-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(135px, 1fr)) auto;
    gap: .75rem;
    align-items: center;
}
.axis-config-page .vehicle-tire-summary-card .card-body { padding: .85rem; }
.axis-config-page .vehicle-tire-summary-item {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #f9fafb;
    min-height: 58px;
    padding: .55rem .7rem;
}
.axis-config-page .vehicle-tire-summary-item .label {
    color: #6b7280;
    font-size: .74rem;
    line-height: 1.15;
}
.axis-config-page .vehicle-tire-summary-item .value {
    color: #111827;
    font-weight: 700;
    line-height: 1.25;
    margin-top: .15rem;
}
.axis-config-page .vehicle-tire-summary-action {
    justify-self: end;
    min-width: 220px;
}
.axis-config-page .vehicle-tire-summary-action .btn {
    min-width: 220px;
}
.axis-config-page .vehicle-tire-panels-grid {
    display: grid;
    grid-template-columns: minmax(360px, 42%) minmax(0, 1fr);
    gap: 1rem;
    align-items: stretch;
}
.axis-config-page .vehicle-tire-panel-left,
.axis-config-page .vehicle-tire-panel-right {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.axis-config-page .vehicle-tire-panel-right { gap: .85rem; }
.axis-config-page .vehicle-tire-main-card { flex: 1 1 auto; width: 100%; }
.axis-config-page .vehicle-tire-axle-scroll,
.axis-config-page .vehicle-tire-table-scroll {
    scrollbar-gutter: stable;
}
.axis-config-page .vehicle-tire-axle-scroll {
    max-height: min(67vh, 640px);
    overflow-y: auto;
    padding: .65rem .75rem;
}
.axis-config-page .vehicle-tire-table-scroll {
    max-height: min(67vh, 640px);
    overflow: auto;
}
.axis-config-page .vehicle-tire-axle-grid {
    background: #fcfdff;
    border: 1px solid #dbe4ef;
    border-radius: 8px;
    display: grid;
    gap: 0;
}
.axis-config-page .vehicle-tire-display-block {
    display: grid;
    gap: .5rem;
}
.axis-config-page .vehicle-tire-display-block + .vehicle-tire-display-block {
    border-top: 1px solid #e5e7eb;
    margin-top: .75rem;
    padding-top: .75rem;
}
.axis-config-page .vehicle-tire-display-heading {
    align-items: baseline;
    display: flex;
    flex-wrap: wrap;
    gap: .35rem .75rem;
    justify-content: space-between;
}
.axis-config-page .vehicle-tire-display-title {
    color: #111827;
    font-size: .98rem;
    font-weight: 700;
}
.axis-config-page .vehicle-tire-display-meta {
    color: #6b7280;
    font-size: .8rem;
    line-height: 1.25;
}
.axis-config-page .vehicle-tire-axle-row {
    align-items: center;
    display: grid;
    gap: .65rem;
    grid-template-columns: minmax(120px, 158px) minmax(0, 1fr);
    padding: .5rem .65rem;
}
.axis-config-page .vehicle-tire-axle-row + .vehicle-tire-axle-row {
    border-top: 1px solid #e5edf7;
}
.axis-config-page .vehicle-tire-axle-copy {
    min-width: 0;
}
.axis-config-page .vehicle-tire-axle-title {
    color: #1f2937;
    font-size: .9rem;
    font-weight: 700;
    line-height: 1.2;
    text-align: left;
}
.axis-config-page .vehicle-tire-axle-count {
    color: #4b5563;
    font-size: .78rem;
    line-height: 1.2;
    margin-top: .18rem;
    text-align: left;
}
.axis-config-page .vehicle-axle-visual {
    align-items: center;
    display: flex;
    justify-content: space-between;
    margin: 0 auto;
    max-width: 100%;
    min-height: 42px;
    padding: 0 1.55rem;
    position: relative;
    width: min(310px, 100%);
}
.axis-config-page .vehicle-axle-visual::before {
    background: linear-gradient(90deg, #9ba8be 0%, #5f6f8d 50%, #9ba8be 100%);
    border-radius: 999px;
    content: '';
    height: 3px;
    left: 48px;
    position: absolute;
    right: 48px;
    top: 50%;
    transform: translateY(-50%);
    z-index: 0;
}
.axis-config-page .vehicle-axle-joint {
    background: #dbe4ef;
    border: 1px solid #5d6d89;
    border-radius: 999px;
    box-shadow: 0 0 0 2px rgba(255,255,255,.9);
    height: 10px;
    left: 50%;
    position: absolute;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 10px;
    z-index: 1;
}
.axis-config-page .vehicle-axle-wheel-side {
    align-items: center;
    display: inline-flex;
    flex: 0 0 auto;
    gap: .32rem;
    min-width: 0;
    position: relative;
    z-index: 2;
}
.axis-config-page .vehicle-tire-icon-wrap {
    display: inline-flex;
    flex: 0 0 auto;
    overflow: visible;
    position: relative;
}
.axis-config-page .vehicle-tire-icon {
    background: linear-gradient(180deg, #49586e 0%, #1f2937 100%);
    border: 1px solid #1f2937;
    border-radius: 9px;
    box-shadow: inset 0 0 0 2px rgba(255,255,255,.08);
    height: 42px;
    width: 18px;
}
.axis-config-page .vehicle-tire-icon-trigger {
    align-items: center;
    appearance: none;
    cursor: pointer;
    display: inline-flex;
    flex: 0 0 auto;
    justify-content: center;
    overflow: visible;
    padding: 0;
    position: relative;
}
.axis-config-page .vehicle-tire-icon-wrap[draggable="true"] .vehicle-tire-icon {
    cursor: grab;
}
.axis-config-page .vehicle-tire-icon-wrap.is-dragging .vehicle-tire-icon {
    cursor: grabbing;
    opacity: .55;
}
.axis-config-page .vehicle-tire-icon-empty {
    opacity: .55;
}
.axis-config-page .vehicle-tire-icon-wrap.is-drop-target .vehicle-tire-icon {
    outline: 3px solid rgba(37, 99, 235, .7);
    outline-offset: 3px;
}
.axis-config-page .vehicle-tire-icon-wrap.is-drop-swap .vehicle-tire-icon {
    outline-color: rgba(245, 158, 11, .85);
}
.axis-config-page.is-axis-dragging .vehicle-tire-position-popover {
    display: none;
}
.axis-config-page .vehicle-tire-position-popover {
    left: 50%;
    max-width: 76vw;
    opacity: 0;
    pointer-events: none;
    position: absolute;
    top: calc(100% + .55rem);
    transform: translate(-50%, -.25rem) scale(.98);
    transition: opacity .14s ease, transform .14s ease, visibility .14s ease;
    visibility: hidden;
    width: 230px;
    z-index: 30;
}
.axis-config-page .vehicle-tire-icon-wrap:hover .vehicle-tire-position-popover,
.axis-config-page .vehicle-tire-icon-wrap.is-active .vehicle-tire-position-popover,
.axis-config-page .vehicle-tire-icon-wrap:focus-within .vehicle-tire-position-popover {
    opacity: 1;
    transform: translate(-50%, 0) scale(1);
    visibility: visible;
}
.axis-config-page .vehicle-tire-position-item {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    min-height: 82px;
    padding: .6rem .75rem;
}
.axis-config-page .vehicle-tire-position-popover .vehicle-tire-position-item {
    box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
    min-height: 0;
    width: 100%;
}
.axis-config-page .vehicle-tire-position-code {
    color: #1f2937;
    font-size: 1rem;
    font-weight: 700;
    text-align: center;
}
.axis-config-page .vehicle-tire-position-label {
    color: #4b5563;
    font-size: .82rem;
    min-height: 24px;
    text-align: center;
}
.axis-config-page .tire-wear-badge {
    align-items: center;
    border: 1px solid transparent;
    border-radius: 999px;
    display: inline-flex;
    font-size: .75rem;
    font-weight: 700;
    gap: .32rem;
    justify-content: center;
    padding: .14rem .55rem;
}
.axis-config-page .tire-wear-badge-dot {
    border-radius: 999px;
    display: inline-block;
    height: 8px;
    width: 8px;
}
.axis-config-page .tire-wear-badge-ok { background: #ecf8ef; border-color: #b7e4c7; color: #166534; }
.axis-config-page .tire-wear-badge-ok .tire-wear-badge-dot { background: #2f9e44; }
.axis-config-page .tire-wear-badge-warning { background: #fff7e7; border-color: #f6d08b; color: #b45309; }
.axis-config-page .tire-wear-badge-warning .tire-wear-badge-dot { background: #e5a50a; }
.axis-config-page .tire-wear-badge-critic { background: #fdecec; border-color: #f4b5b5; color: #b91c1c; }
.axis-config-page .tire-wear-badge-critic .tire-wear-badge-dot { background: #dc2626; }
.axis-config-page .tire-wear-badge-empty { background: #f3f4f6; border-color: #d1d5db; color: #4b5563; }
.axis-config-page .tire-wear-badge-empty .tire-wear-badge-dot { background: #9ca3af; }
.axis-config-page .tire-wear-progress {
    background: #e5e7eb;
    border-radius: 999px;
    height: 7px;
    overflow: hidden;
}
.axis-config-page .tire-wear-progress .progress-bar { border-radius: 999px; }
.axis-config-page .vehicle-tire-legend-grid {
    display: grid;
    gap: .8rem;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}
.axis-config-page .vehicle-tire-legend-card {
    align-content: start;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    display: grid;
    gap: .25rem;
    min-height: 104px;
    padding: .65rem .75rem;
}
.axis-config-page .vehicle-tire-legend-ok { background: #f1fbf4; border-color: #b7e4c7; }
.axis-config-page .vehicle-tire-legend-warning { background: #fffaf2; border-color: #f6d08b; }
.axis-config-page .vehicle-tire-legend-critic { background: #fff4f4; border-color: #f4b5b5; }
.axis-config-page .vehicle-tire-alert-info { background: #eff6ff; border-color: #bfd7ff; color: #1e3a8a; }
.axis-config-page .vehicle-tire-positions-card .table {
    font-size: .88rem;
    min-width: 980px;
    table-layout: fixed;
}
.axis-config-page .vehicle-tire-positions-card .table th {
    background: #f8fafc;
    color: #4b5563;
    font-size: .76rem;
    letter-spacing: .02em;
    position: sticky;
    top: 0;
    text-transform: uppercase;
    white-space: nowrap;
    z-index: 2;
}
.axis-config-page .vehicle-tire-positions-card .table th,
.axis-config-page .vehicle-tire-positions-card .table td {
    line-height: 1.35;
    padding: .55rem .6rem;
    vertical-align: middle;
}
.axis-config-page .vehicle-tire-positions-card .table tbody tr:nth-child(even) > * { background: #fbfdff; }
.axis-config-page .axis-table-col-vehicle { width: 130px; }
.axis-config-page .axis-table-col-axle { width: 130px; }
.axis-config-page .axis-table-col-position { width: 115px; }
.axis-config-page .axis-table-col-tire { width: 160px; }
.axis-config-page .axis-table-col-status { width: 90px; }
.axis-config-page .axis-table-col-wear-km { width: 120px; }
.axis-config-page .axis-table-col-wear-percent { width: 90px; }
.axis-config-page .axis-table-col-dot { width: 70px; }
.axis-config-page .axis-table-col-actions { width: 75px; }
.axis-config-page .axis-table-cell-vehicle,
.axis-config-page .axis-table-cell-axle,
.axis-config-page .axis-table-cell-position,
.axis-config-page .axis-table-cell-tire {
    overflow-wrap: normal;
    word-break: normal;
}
.axis-config-page .axis-table-vehicle-role,
.axis-config-page .axis-table-position-code {
    color: #111827;
    display: block;
    font-weight: 700;
    white-space: nowrap;
}
.axis-config-page .axis-table-vehicle-label,
.axis-config-page .axis-table-position-label,
.axis-config-page .axis-table-muted {
    color: #6b7280;
    display: block;
    font-size: .82rem;
    line-height: 1.35;
}
.axis-config-page .axis-table-empty-tire {
    color: #4b5563;
    white-space: nowrap;
}
@media (max-width: 1399.98px) {
    .axis-config-page .vehicle-tire-panels-grid,
    .axis-config-page .vehicle-tire-summary-grid,
    .axis-config-page .vehicle-tire-legend-grid {
        grid-template-columns: 1fr;
    }
    .axis-config-page .vehicle-tire-summary-action,
    .axis-config-page .vehicle-tire-summary-action .btn {
        min-width: 0;
        width: 100%;
    }
}
@media (max-width: 575.98px) {
    .axis-config-page .vehicle-tire-axle-row {
        grid-template-columns: 1fr;
        gap: .35rem;
    }
    .axis-config-page .vehicle-tire-axle-title,
    .axis-config-page .vehicle-tire-axle-count {
        text-align: center;
    }
}
</style>

<div class="axis-config-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1">Configura&#539;ie Axe</h2>
            <div class="text-muted">Anvelope &gt; Configurarea axelor</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock'])) ?>">Stoc anvelope</a>
            <?php if ($record !== null): ?>
                <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $record['id']])) ?>">Detalii vehicul</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="get" action="<?= e(url('index.php')) ?>" class="row g-3 align-items-end" data-axis-vehicle-selector>
                <input type="hidden" name="page" value="mentenanta">
                <input type="hidden" name="action" value="axis_config">
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="axis_vehicle_view">Tip afisare</label>
                    <select class="form-select" id="axis_vehicle_view" name="vehicle_view">
                        <?php foreach ($axisVehicleViewOptions as $viewValue => $viewLabel): ?>
                            <option value="<?= e((string) $viewValue) ?>" <?= (string) $axisVehicleView === (string) $viewValue ? 'selected' : '' ?>>
                                <?= e((string) $viewLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="axis_mma_filter">MMA</label>
                    <select class="form-select" id="axis_mma_filter" name="mma" data-axis-filter>
                        <option value="">Toate</option>
                        <?php foreach ($axisMmaOptions as $optionValue => $optionLabel): ?>
                            <option value="<?= e((string) $optionValue) ?>" <?= $axisMmaFilter === (string) $optionValue ? 'selected' : '' ?>>
                                <?= e((string) $optionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="axis_capacity_filter">Capacitate transport</label>
                    <select class="form-select" id="axis_capacity_filter" name="capacitate_transport" data-axis-filter>
                        <option value="">Toate</option>
                        <?php foreach ($axisTransportCapacityOptions as $optionValue => $optionLabel): ?>
                            <option value="<?= e((string) $optionValue) ?>" <?= $axisTransportCapacityFilter === (string) $optionValue ? 'selected' : '' ?>>
                                <?= e((string) $optionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label" for="axis_vehicle_id">Vehicul</label>
                    <select class="form-select" id="axis_vehicle_id" name="vehicle_id">
                        <option value="">-- Selecteaza <?= $axisVehicleView === 'ansamblu' ? 'ansamblu' : 'camion' ?> --</option>
                        <?php foreach ($vehicleOptions as $optionValue => $optionLabel): ?>
                            <option value="<?= e((string) $optionValue) ?>" <?= (string) $selectedVehicleId === (string) $optionValue ? 'selected' : '' ?>>
                                <?= e((string) $optionLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-xl-2 d-grid d-xl-flex gap-2 justify-content-xl-end">
                    <button type="submit" class="btn btn-primary">Deschide configuratia</button>
                    <?php if ($axisFiltersActive): ?>
                        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'axis_config', 'vehicle_view' => $axisVehicleView])) ?>">Reseteaza</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($record === null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body py-5 text-center">
                <div class="display-6 text-primary mb-3"><i class="bi bi-diagram-3"></i></div>
                <h3 class="h5">Selecteaza un vehicul</h3>
                <p class="text-muted mb-0">Configurarea axelor este specifica fiecarui vehicul.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm mb-3 vehicle-tire-summary-card">
            <div class="card-body">
                <div class="vehicle-tire-summary-grid">
                    <div class="vehicle-tire-summary-item">
                        <div class="label">Tip vehicul</div>
                        <div class="value"><?= e($summaryVehicleTypeValue) ?></div>
                    </div>
                    <div class="vehicle-tire-summary-item">
                        <div class="label">Formula axelor</div>
                        <div class="value"><?= e($summaryLayoutValue) ?></div>
                    </div>
                    <div class="vehicle-tire-summary-item">
                        <div class="label">Anvelope montate</div>
                        <div class="value"><?= e((string) $summaryMountedTires) ?> / <?= e((string) $summaryExpectedTires) ?></div>
                    </div>
                    <div class="vehicle-tire-summary-item">
                        <div class="label">Pozitii libere</div>
                        <div class="value <?= $summaryUnmountedPositions > 0 ? 'text-warning' : 'text-success' ?>"><?= e((string) $summaryUnmountedPositions) ?></div>
                    </div>
                    <div class="vehicle-tire-summary-action">
                        <a href="#vehicle_tire_layout_config" class="btn btn-primary">Schimba configuratia axelor</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3" id="vehicle_tire_layout_config">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Schimba configuratia axelor</h3>
            </div>
            <div class="card-body">
                <form id="vehicle_tire_layout_form" method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'update_tire_layout'])) ?>" class="row g-3 align-items-end" data-mounted-tires="<?= e((string) $mountedTires) ?>" data-initial-layout="<?= e($layoutCurrentValue) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
                    <input type="hidden" name="return_to" value="axis_config">
                    <input type="hidden" name="return_vehicle_view" value="<?= e($axisVehicleView) ?>">
                    <input type="hidden" name="return_capacitate_transport" value="<?= e($axisTransportCapacityFilter) ?>">
                    <input type="hidden" name="return_mma" value="<?= e($axisMmaFilter) ?>">
                    <div class="col-12 col-md-8">
                        <label class="form-label" for="tire_layout_value">Configuratie axe</label>
                        <?php if ($layoutOptions !== []): ?>
                            <select class="form-select" id="tire_layout_value" name="tire_layout_value">
                                <?php foreach ($layoutOptions as $layoutValue => $layoutLabel): ?>
                                    <option value="<?= e((string) $layoutValue) ?>" <?= (string) $layoutCurrentValue === (string) $layoutValue ? 'selected' : '' ?>>
                                        <?= e((string) $layoutLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" class="form-control" id="tire_layout_value" name="tire_layout_value" value="<?= e($layoutCurrentValue) ?>" maxlength="20">
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-4 d-grid d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary">Aplica configuratia</button>
                    </div>
                </form>
            </div>
        </div>

        <form id="axis_tire_drag_form" method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'move_tire'])) ?>" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="axis_config">
            <input type="hidden" name="return_vehicle_id" value="<?= e((string) $selectedVehicleId) ?>">
            <input type="hidden" name="return_vehicle_view" value="<?= e($axisVehicleView) ?>">
            <input type="hidden" name="return_capacitate_transport" value="<?= e($axisTransportCapacityFilter) ?>">
            <input type="hidden" name="return_mma" value="<?= e($axisMmaFilter) ?>">
            <input type="hidden" name="source_vehicle_id" value="">
            <input type="hidden" name="target_vehicle_id" value="">
            <input type="hidden" name="target_position_id" value="">
            <input type="hidden" name="tire_id" value="">
            <input type="hidden" name="move_date" value="<?= e($todayDate) ?>">
            <input type="hidden" name="move_reason" value="Mutare din configuratia axelor">
            <input type="hidden" name="allow_swap" value="1">
        </form>

        <div class="vehicle-tire-panels-grid mb-3">
            <div class="vehicle-tire-panel-left">
                <div class="card border-0 shadow-sm vehicle-tire-main-card">
                    <div class="card-header bg-white">
                        <h3 class="h6 mb-0">Configuratie axe si pozitii</h3>
                    </div>
                    <div class="card-body vehicle-tire-axle-scroll">
                        <?php if ($axisVehicleDisplayBlocks === []): ?>
                            <div class="text-muted">Nu exista pozitii generate inca pentru acest vehicul.</div>
                        <?php else: ?>
                            <?php foreach ($axisVehicleDisplayBlocks as $displayBlock): ?>
                                <?php
                                $displayView = is_array($displayBlock['view'] ?? null) ? $displayBlock['view'] : [];
                                $displayAxleGroups = is_array($displayView['axle_groups'] ?? null) ? $displayView['axle_groups'] : [];
                                $displayRecord = is_array($displayBlock['record'] ?? null) ? $displayBlock['record'] : [];
                                $displayVehicleId = (int) ($displayRecord['id'] ?? 0);
                                $displayRoleLabel = (string) ($displayBlock['role_label'] ?? 'Vehicul');
                                $displayLabel = (string) ($displayBlock['label'] ?? '-');
                                $displayLayoutSummary = (string) ($displayView['layout_summary'] ?? '-');
                                $displayMountedTires = (int) ($displayView['mounted_tires'] ?? 0);
                                $displayExpectedTires = (int) ($displayView['expected_tires'] ?? 0);
                                ?>
                                <div class="vehicle-tire-display-block">
                                    <div class="vehicle-tire-display-heading">
                                        <div>
                                            <div class="vehicle-tire-display-title"><?= e($displayRoleLabel) ?></div>
                                            <div class="vehicle-tire-display-meta"><?= e($displayLabel) ?></div>
                                        </div>
                                        <div class="vehicle-tire-display-meta">
                                            <?= e($displayLayoutSummary) ?> | <?= e((string) $displayMountedTires) ?> / <?= e((string) $displayExpectedTires) ?> montate
                                        </div>
                                    </div>
                                    <?php if ($displayAxleGroups === []): ?>
                                        <div class="text-muted">Nu exista pozitii generate inca pentru acest vehicul.</div>
                                    <?php else: ?>
                                        <div class="vehicle-tire-axle-grid">
                                            <?php foreach ($displayAxleGroups as $axleGroup): ?>
                                                <?php
                                                $axleNo = (int) ($axleGroup['axle_no'] ?? 0);
                                                $axleRole = (string) ($axleGroup['role'] ?? '');
                                                $axlePositions = is_array($axleGroup['positions'] ?? null) ? $axleGroup['positions'] : [];
                                                $axleTitle = 'AXA ' . $axleNo . ($axleRole !== '' ? ' - ' . $axleRole : '');
                                                $leftPositions = [];
                                                $rightPositions = [];
                                                foreach ($axlePositions as $axlePosition) {
                                                    $sideCode = strtoupper((string) ($axlePosition['side_code'] ?? ''));
                                                    if ($sideCode !== '' && str_starts_with($sideCode, 'L')) {
                                                        $leftPositions[] = $axlePosition;
                                                    } elseif ($sideCode !== '' && str_starts_with($sideCode, 'R')) {
                                                        $rightPositions[] = $axlePosition;
                                                    }
                                                }
                                                if ($leftPositions === [] || $rightPositions === []) {
                                                    $middleIndex = (int) ceil(count($axlePositions) / 2);
                                                    $leftPositions = array_slice($axlePositions, 0, $middleIndex);
                                                    $rightPositions = array_slice($axlePositions, $middleIndex);
                                                }
                                                ?>
                                                <div class="vehicle-tire-axle-row">
                                                    <div class="vehicle-tire-axle-copy">
                                                        <div class="vehicle-tire-axle-title"><?= e($axleTitle) ?></div>
                                                        <div class="vehicle-tire-axle-count"><?= e((string) count($axlePositions)) ?> anvelope<?= count($axlePositions) >= 4 ? ' (dubla)' : '' ?></div>
                                                    </div>
                                                    <div class="vehicle-axle-visual">
                                                        <div class="vehicle-axle-wheel-side vehicle-axle-wheel-side-left">
                                                            <?php foreach ($leftPositions as $iconPosition): ?>
                                                                <?php
                                                                $tireBadgeLabel = $resolveVisualBadgeLabel($iconPosition);
                                                                $iconTire = is_array($iconPosition['tire'] ?? null) ? $iconPosition['tire'] : null;
                                                                $iconTireId = $iconTire !== null ? (int) ($iconTire['id'] ?? 0) : 0;
                                                                $iconPositionId = (int) ($iconPosition['position_id'] ?? 0);
                                                                $iconPositionLabel = trim($displayRoleLabel . ' ' . (string) ($iconPosition['position_code'] ?? ''));
                                                                $iconButtonClass = 'vehicle-tire-icon vehicle-tire-icon-trigger' . ($iconTireId <= 0 ? ' vehicle-tire-icon-empty' : '');
                                                                ?>
                                                                <div class="vehicle-tire-icon-wrap"
                                                                     data-position-id="<?= e((string) $iconPositionId) ?>"
                                                                     data-vehicle-id="<?= e((string) $displayVehicleId) ?>"
                                                                     data-position-label="<?= e($iconPositionLabel) ?>"
                                                                     <?php if ($iconTireId > 0): ?>
                                                                         data-tire-id="<?= e((string) $iconTireId) ?>"
                                                                         draggable="true"
                                                                     <?php endif; ?>>
                                                                    <button type="button" class="<?= e($iconButtonClass) ?>" aria-label="Afiseaza pozitia <?= e($tireBadgeLabel) ?>" aria-expanded="false"></button>
                                                                    <div class="vehicle-tire-position-popover">
                                                                        <?php $renderVehicleTirePositionItem($iconPosition); ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <span class="vehicle-axle-joint" aria-hidden="true"></span>
                                                        <div class="vehicle-axle-wheel-side vehicle-axle-wheel-side-right">
                                                            <?php foreach ($rightPositions as $iconPosition): ?>
                                                                <?php
                                                                $tireBadgeLabel = $resolveVisualBadgeLabel($iconPosition);
                                                                $iconTire = is_array($iconPosition['tire'] ?? null) ? $iconPosition['tire'] : null;
                                                                $iconTireId = $iconTire !== null ? (int) ($iconTire['id'] ?? 0) : 0;
                                                                $iconPositionId = (int) ($iconPosition['position_id'] ?? 0);
                                                                $iconPositionLabel = trim($displayRoleLabel . ' ' . (string) ($iconPosition['position_code'] ?? ''));
                                                                $iconButtonClass = 'vehicle-tire-icon vehicle-tire-icon-trigger' . ($iconTireId <= 0 ? ' vehicle-tire-icon-empty' : '');
                                                                ?>
                                                                <div class="vehicle-tire-icon-wrap"
                                                                     data-position-id="<?= e((string) $iconPositionId) ?>"
                                                                     data-vehicle-id="<?= e((string) $displayVehicleId) ?>"
                                                                     data-position-label="<?= e($iconPositionLabel) ?>"
                                                                     <?php if ($iconTireId > 0): ?>
                                                                         data-tire-id="<?= e((string) $iconTireId) ?>"
                                                                         draggable="true"
                                                                     <?php endif; ?>>
                                                                    <button type="button" class="<?= e($iconButtonClass) ?>" aria-label="Afiseaza pozitia <?= e($tireBadgeLabel) ?>" aria-expanded="false"></button>
                                                                    <div class="vehicle-tire-position-popover">
                                                                        <?php $renderVehicleTirePositionItem($iconPosition); ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="vehicle-tire-panel-right">
                <?php if ($tireAlerts !== []): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h3 class="h6 mb-0">Alerte anvelope</h3>
                        </div>
                        <div class="card-body">
                            <?php foreach ($tireAlerts as $alert): ?>
                                <?php $alertType = in_array((string) ($alert['type'] ?? ''), ['danger', 'warning', 'info', 'success'], true) ? (string) $alert['type'] : 'warning'; ?>
                                <div class="alert alert-<?= e($alertType) ?> mb-2">
                                    <strong><?= e((string) ($alert['title'] ?? 'Alerta')) ?>:</strong>
                                    <?= e((string) ($alert['message'] ?? '')) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm vehicle-tire-main-card vehicle-tire-positions-card">
                    <div class="card-header bg-white">
                        <h3 class="h6 mb-0">Pozitii anvelope si uzura</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive vehicle-tire-table-scroll">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                <tr>
                                    <?php if ($showVehicleColumnInPositions): ?>
                                        <th class="axis-table-col-vehicle">Vehicul</th>
                                    <?php endif; ?>
                                    <th class="axis-table-col-axle">Axa</th>
                                    <th class="axis-table-col-position">Pozitie</th>
                                    <th class="axis-table-col-tire">Anvelopa</th>
                                    <th class="axis-table-col-status">Status</th>
                                    <th class="axis-table-col-wear-km">Uzura km</th>
                                    <th class="axis-table-col-wear-percent">Uzura %</th>
                                    <th class="axis-table-col-dot">DOT</th>
                                    <th class="axis-table-col-actions text-end pe-3">Actiuni</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if ($axisTirePositionRows === []): ?>
                                    <tr>
                                        <td colspan="<?= $showVehicleColumnInPositions ? '9' : '8' ?>" class="text-muted text-center py-3">Nu exista pozitii generate inca pentru acest vehicul.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($axisTirePositionRows as $positionRow): ?>
                                        <?php
                                        $position = is_array($positionRow['position'] ?? null) ? $positionRow['position'] : [];
                                        $tire = is_array($position['tire'] ?? null) ? $position['tire'] : null;
                                        $wearMetaRaw = is_array($position['wear_meta'] ?? null) ? $position['wear_meta'] : $computeWearMeta($tire, $wearDefaultLimitKm);
                                        $wearMeta = $normalizeWearMeta($wearMetaRaw);
                                        $axleNo = (int) ($position['axle_no'] ?? 0);
                                        $rowVehicleType = (string) ($positionRow['vehicle_type'] ?? $vehicleType);
                                        $rowLayoutCurrentValue = (string) ($positionRow['layout_current_value'] ?? $layoutCurrentValue);
                                        $rowVehicleId = (int) ($positionRow['vehicle_id'] ?? ((int) ($record['id'] ?? 0)));
                                        $rowVehicleRole = (string) ($positionRow['vehicle_role'] ?? '');
                                        $rowVehicleLabel = (string) ($positionRow['vehicle_label'] ?? '');
                                        $axleRole = $resolveAxleRole($rowVehicleType, $rowLayoutCurrentValue, $axleNo);
                                        $axleLabel = 'Axa ' . $axleNo . ($axleRole !== '' ? ' (' . $axleRole . ')' : '');
                                        ?>
                                        <tr>
                                            <?php if ($showVehicleColumnInPositions): ?>
                                                <td class="axis-table-cell-vehicle">
                                                    <span class="axis-table-vehicle-role"><?= e($rowVehicleRole !== '' ? $rowVehicleRole : (function_exists('vehicle_type_label') ? vehicle_type_label($rowVehicleType) : $rowVehicleType)) ?></span>
                                                    <span class="axis-table-vehicle-label"><?= e($rowVehicleLabel !== '' ? $rowVehicleLabel : '-') ?></span>
                                                </td>
                                            <?php endif; ?>
                                            <td class="axis-table-cell-axle"><?= e($axleLabel) ?></td>
                                            <td class="axis-table-cell-position">
                                                <span class="axis-table-position-code"><?= e((string) ($position['position_code'] ?? '-')) ?></span>
                                                <span class="axis-table-position-label"><?= e((string) ($position['position_label'] ?? '-')) ?></span>
                                            </td>
                                            <td class="axis-table-cell-tire">
                                                <?php if ($tire !== null): ?>
                                                    <div class="fw-semibold"><?= e(trim((string) (($tire['brand'] ?? '') . ' ' . ($tire['model'] ?? '')))) ?></div>
                                                    <div class="axis-table-muted">
                                                        SN: <?= e((string) ($tire['serial_number'] ?? '-')) ?>
                                                        <?php if (!empty($tire['tire_size'])): ?> | <?= e((string) $tire['tire_size']) ?><?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="axis-table-empty-tire">Pozitie libera</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="<?= e((string) $wearMeta['badge_class']) ?>">
                                                    <span class="tire-wear-badge-dot"></span>
                                                    <?= e((string) $wearMeta['status_label']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($tire !== null): ?>
                                                    <div class="small mb-1"><?= e((string) $wearMeta['km_display']) ?></div>
                                                    <div class="tire-wear-progress">
                                                        <div class="progress-bar <?= e((string) $wearMeta['progress_class']) ?>" style="width: <?= e(number_format((float) $wearMeta['progress_width'], 2, '.', '')) ?>%"></div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-semibold <?= e((string) $wearMeta['percent_class']) ?>"><?= e((string) $wearMeta['percent_display']) ?></td>
                                            <td><?= $tire !== null ? e((string) (($tire['dot_code'] ?? '') !== '' ? $tire['dot_code'] : '-')) : '<span class="text-muted">-</span>' ?></td>
                                            <td class="text-end pe-3">
                                                <?php if ($tire !== null && isset($position['allocation_id']) && $position['allocation_id'] !== null): ?>
                                                    <?php $vehicleTireId = (int) ($tire['id'] ?? 0); ?>
                                                    <div class="dropdown d-inline-block">
                                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i class="bi bi-three-dots"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end text-start">
                                                            <li>
                                                                <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock', 'q' => (string) ($tire['serial_number'] ?? '')])) ?>">
                                                                    <i class="bi bi-eye me-2"></i>Vezi anvelopa
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock', 'q' => (string) ($tire['serial_number'] ?? '')])) ?>">
                                                                    <i class="bi bi-arrow-left-right me-2"></i>Muta anvelopa
                                                                </a>
                                                            </li>
                                                            <?php foreach (['spare' => 'Muta in rezerva', 'damaged' => 'Marcheaza deteriorata', 'missing' => 'Marcheaza lipsa', 'removed' => 'Scoate din uz'] as $statusValue => $statusLabel): ?>
                                                                <li>
                                                                    <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'change_tire_status'])) ?>">
                                                                        <?= csrf_field() ?>
                                                                        <input type="hidden" name="vehicle_id" value="<?= e((string) $rowVehicleId) ?>">
                                                                        <input type="hidden" name="return_to" value="axis_config">
                                                                        <input type="hidden" name="return_vehicle_view" value="<?= e($axisVehicleView) ?>">
                                                                        <input type="hidden" name="return_capacitate_transport" value="<?= e($axisTransportCapacityFilter) ?>">
                                                                        <input type="hidden" name="return_mma" value="<?= e($axisMmaFilter) ?>">
                                                                        <input type="hidden" name="tire_id" value="<?= e((string) $vehicleTireId) ?>">
                                                                        <input type="hidden" name="status" value="<?= e($statusValue) ?>">
                                                                        <input type="hidden" name="reason" value="<?= e($statusLabel) ?>">
                                                                        <button type="submit" class="dropdown-item<?= $statusValue === 'missing' ? ' text-danger' : '' ?>">
                                                                            <?= e($statusLabel) ?>
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endforeach; ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'unmount_tire'])) ?>">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="vehicle_id" value="<?= e((string) $rowVehicleId) ?>">
                                                                    <input type="hidden" name="return_to" value="axis_config">
                                                                    <input type="hidden" name="return_vehicle_view" value="<?= e($axisVehicleView) ?>">
                                                                    <input type="hidden" name="return_capacitate_transport" value="<?= e($axisTransportCapacityFilter) ?>">
                                                                    <input type="hidden" name="return_mma" value="<?= e($axisMmaFilter) ?>">
                                                                    <input type="hidden" name="allocation_id" value="<?= e((string) ((int) $position['allocation_id'])) ?>">
                                                                    <input type="hidden" name="unmount_date" value="<?= e($todayDate) ?>">
                                                                    <input type="hidden" name="status_end" value="spare">
                                                                    <button type="submit" class="dropdown-item text-danger" data-confirm="Sigur doresti demontarea anvelopei de pe aceasta pozitie?">
                                                                        <i class="bi bi-box-arrow-down me-2"></i>Demonteaza
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-3 py-2 small text-muted border-top">
                            Uzura este calculata in functie de km parcursi si limita configurata pentru fiecare anvelopa.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Legenda status uzura</h3>
            </div>
            <div class="card-body">
                <div class="vehicle-tire-legend-grid">
                    <div class="vehicle-tire-legend-card vehicle-tire-legend-ok">
                        <div class="fw-semibold text-success">OK (Verde)</div>
                        <div class="small">Uzura &lt;= 75%</div>
                        <div class="small text-muted">Anvelopa este in parametri optimi.</div>
                    </div>
                    <div class="vehicle-tire-legend-card vehicle-tire-legend-warning">
                        <div class="fw-semibold text-warning">ATENTIE (Galben)</div>
                        <div class="small">Uzura &gt; 75% si &lt;= 90%</div>
                        <div class="small text-muted">Anvelopa se apropie de limita de inlocuire.</div>
                    </div>
                    <div class="vehicle-tire-legend-card vehicle-tire-legend-critic">
                        <div class="fw-semibold text-danger">CRITIC (Rosu)</div>
                        <div class="small">Uzura &gt; 90%</div>
                        <div class="small text-muted">Anvelopa trebuie inlocuita in cel mai scurt timp.</div>
                    </div>
                    <div class="vehicle-tire-legend-card vehicle-tire-alert-info">
                        <div class="fw-semibold">Avertizare automata</div>
                        <div class="small">Cand uzura depaseste 90%, pozitia anvelopei devine CRITIC (rosu).</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Monteaza anvelopa existenta</h3>
            </div>
            <div class="card-body">
                <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'mount_tire'])) ?>" class="row g-3 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
                    <input type="hidden" name="return_to" value="axis_config">
                    <input type="hidden" name="return_vehicle_view" value="<?= e($axisVehicleView) ?>">
                    <input type="hidden" name="return_capacitate_transport" value="<?= e($axisTransportCapacityFilter) ?>">
                    <input type="hidden" name="return_mma" value="<?= e($axisMmaFilter) ?>">

                    <div class="col-12">
                        <label class="form-label" for="mount_tire_id">Anvelopa disponibila</label>
                        <select class="form-select" id="mount_tire_id" name="tire_id" required>
                            <option value="">-- Selecteaza --</option>
                            <?php foreach ($availableTires as $availableTire): ?>
                                <?php
                                $availableTireId = (int) ($availableTire['id'] ?? 0);
                                $availableLabel = trim((string) ($availableTire['brand'] ?? '') . ' ' . (string) ($availableTire['model'] ?? ''));
                                $availableSerial = trim((string) ($availableTire['serial_number'] ?? ''));
                                $availableTargetType = function_exists('vehicle_type_label') ? vehicle_type_label((string) ($availableTire['target_vehicle_type'] ?? 'universal')) : (string) ($availableTire['target_vehicle_type'] ?? 'universal');
                                ?>
                                <option value="<?= e((string) $availableTireId) ?>">
                                    <?= e($availableLabel !== '' ? $availableLabel : 'Anvelopa #' . $availableTireId) ?> | SN: <?= e($availableSerial !== '' ? $availableSerial : '-') ?> | Tip: <?= e($availableTargetType) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="mount_position_id">Pozitie montaj</label>
                        <select class="form-select" id="mount_position_id" name="position_id" required>
                            <option value="">-- Selecteaza --</option>
                            <?php foreach ($tirePositions as $position): ?>
                                <?php $positionTire = is_array($position['tire'] ?? null) ? $position['tire'] : null; ?>
                                <option value="<?= e((string) ((int) ($position['position_id'] ?? 0))) ?>" <?= $positionTire !== null ? 'disabled' : '' ?>>
                                    <?= e((string) ($position['position_code'] ?? '-')) ?> - <?= e((string) ($position['position_label'] ?? '-')) ?><?= $positionTire !== null ? ' (ocupata)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6">
                        <label class="form-label" for="mount_date">Data montaj</label>
                        <input type="date" class="form-control" id="mount_date" name="mount_date" value="<?= e($todayDate) ?>" required>
                    </div>

                    <div class="col-12">
                        <button type="submit" class="btn btn-primary" <?= $availableTires === [] ? 'disabled' : '' ?>>Monteaza anvelopa</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Istoric miscari anvelope</h3>
            </div>
            <div class="card-body p-0">
                <?php if ($tireHistory === []): ?>
                    <div class="p-3 text-muted">Nu exista istoric de miscari pentru acest vehicul.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Anvelopa</th>
                                <th>Pozitie</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Km start</th>
                                <th>Km end</th>
                                <th>Status final</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tireHistory as $historyRow): ?>
                                <tr>
                                    <td>
                                        <?= e(trim((string) (($historyRow['brand'] ?? '') . ' ' . ($historyRow['model'] ?? '')))) ?><br>
                                        <small class="text-muted">SN: <?= e((string) ($historyRow['serial_number'] ?? '-')) ?></small>
                                    </td>
                                    <td><?= e((string) (($historyRow['position_code'] ?? '') !== '' ? $historyRow['position_code'] : '-')) ?></td>
                                    <td><?= e(format_date_ro((string) ($historyRow['data_start'] ?? ''))) ?></td>
                                    <td><?= e(format_date_ro((string) ($historyRow['data_end'] ?? ''))) ?></td>
                                    <td><?= $historyRow['km_start'] !== null ? e(number_format((float) ((int) $historyRow['km_start']), 0, ',', '.')) : '-' ?></td>
                                    <td><?= $historyRow['km_end'] !== null ? e(number_format((float) ((int) $historyRow['km_end']), 0, ',', '.')) : '-' ?></td>
                                    <td>
                                        <?php
                                        $historyStatus = (string) ($historyRow['status_end'] ?? '');
                                        if ($historyStatus === '') {
                                            echo '<span class="badge text-bg-success">In utilizare</span>';
                                        } elseif (function_exists('tire_status_badge_html')) {
                                            echo tire_status_badge_html($historyStatus);
                                        } else {
                                            echo e($historyStatus);
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectorForm = document.querySelector('[data-axis-vehicle-selector]');
    const selector = document.getElementById('axis_vehicle_id');
    const viewSelector = document.getElementById('axis_vehicle_view');
    const filterSelectors = selectorForm instanceof HTMLFormElement
        ? selectorForm.querySelectorAll('[data-axis-filter]')
        : [];
    if (selectorForm instanceof HTMLFormElement && viewSelector instanceof HTMLSelectElement) {
        viewSelector.addEventListener('change', function () {
            if (selector instanceof HTMLSelectElement) {
                selector.value = '';
            }
            selectorForm.submit();
        });
    }
    filterSelectors.forEach(function (filterSelector) {
        if (!(filterSelector instanceof HTMLSelectElement)) {
            return;
        }

        filterSelector.addEventListener('change', function () {
            if (selector instanceof HTMLSelectElement) {
                selector.value = '';
            }
            selectorForm.submit();
        });
    });
    if (selectorForm instanceof HTMLFormElement && selector instanceof HTMLSelectElement) {
        selector.addEventListener('change', function () {
            if (selector.value !== '') {
                selectorForm.submit();
            }
        });
    }

    const layoutForm = document.getElementById('vehicle_tire_layout_form');
    const layoutField = document.getElementById('tire_layout_value');
    if (layoutForm instanceof HTMLFormElement && (layoutField instanceof HTMLInputElement || layoutField instanceof HTMLSelectElement)) {
        const mountedTires = Number(layoutForm.getAttribute('data-mounted-tires') || '0');
        const initialLayout = (layoutForm.getAttribute('data-initial-layout') || '').trim();
        if (mountedTires > 0) {
            layoutForm.addEventListener('submit', function (event) {
                const nextLayout = layoutField.value.trim();
                if (initialLayout !== '' && nextLayout !== '' && nextLayout !== initialLayout) {
                    const message = 'Vehiculul are deja ' + mountedTires + ' anvelope montate. Doresti sa schimbi configuratia axelor? Pozitiile existente raman protejate.';
                    if (!window.confirm(message)) {
                        event.preventDefault();
                    }
                }
            });
        }
    }

    const tireTriggers = Array.from(document.querySelectorAll('.vehicle-tire-icon-trigger'));
    const tireWraps = tireTriggers
        .map(function (trigger) { return trigger.closest('.vehicle-tire-icon-wrap'); })
        .filter(function (wrap) { return wrap !== null; });
    const dragMoveForm = document.getElementById('axis_tire_drag_form');
    let activeTireDrag = null;

    const clearDropHighlights = function () {
        tireWraps.forEach(function (wrap) {
            wrap.classList.remove('is-drop-target', 'is-drop-swap', 'is-dragging');
        });
        document.querySelector('.axis-config-page')?.classList.remove('is-axis-dragging');
    };

    if (dragMoveForm instanceof HTMLFormElement) {
        const setMoveInput = function (name, value) {
            const input = dragMoveForm.querySelector('[name="' + name + '"]');
            if (input instanceof HTMLInputElement) {
                input.value = value;
            }
        };

        tireWraps.forEach(function (wrap) {
            const positionId = wrap.getAttribute('data-position-id') || '';
            const vehicleId = wrap.getAttribute('data-vehicle-id') || '';
            const tireId = wrap.getAttribute('data-tire-id') || '';
            const positionLabel = wrap.getAttribute('data-position-label') || positionId;

            if (positionId === '' || vehicleId === '') {
                return;
            }

            if (tireId !== '') {
                wrap.addEventListener('dragstart', function (event) {
                    activeTireDrag = {
                        tireId: tireId,
                        sourceVehicleId: vehicleId,
                        sourcePositionId: positionId,
                        sourcePositionLabel: positionLabel,
                    };
                    wrap.classList.add('is-dragging');
                    document.querySelector('.axis-config-page')?.classList.add('is-axis-dragging');
                    if (event.dataTransfer !== null) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', tireId);
                    }
                    hideTireLabels(null);
                });

                wrap.addEventListener('dragend', function () {
                    activeTireDrag = null;
                    clearDropHighlights();
                });
            }

            wrap.addEventListener('dragover', function (event) {
                if (activeTireDrag === null || activeTireDrag.sourcePositionId === positionId) {
                    return;
                }

                event.preventDefault();
                if (event.dataTransfer !== null) {
                    event.dataTransfer.dropEffect = 'move';
                }
                wrap.classList.add('is-drop-target');
                if ((wrap.getAttribute('data-tire-id') || '') !== '') {
                    wrap.classList.add('is-drop-swap');
                }
            });

            wrap.addEventListener('dragleave', function () {
                wrap.classList.remove('is-drop-target', 'is-drop-swap');
            });

            wrap.addEventListener('drop', function (event) {
                if (activeTireDrag === null || activeTireDrag.sourcePositionId === positionId) {
                    return;
                }

                event.preventDefault();
                const targetTireId = wrap.getAttribute('data-tire-id') || '';
                if (targetTireId !== '') {
                    const targetLabel = wrap.getAttribute('data-position-label') || positionId;
                    const message = 'Pozitia ' + targetLabel + ' este ocupata. Doresti sa schimbi anvelopele intre pozitii?';
                    if (!window.confirm(message)) {
                        clearDropHighlights();
                        activeTireDrag = null;
                        return;
                    }
                }

                setMoveInput('tire_id', activeTireDrag.tireId);
                setMoveInput('source_vehicle_id', activeTireDrag.sourceVehicleId);
                setMoveInput('target_vehicle_id', vehicleId);
                setMoveInput('target_position_id', positionId);
                dragMoveForm.submit();
            });
        });
    }

    const hideTireLabels = function (exceptWrap) {
        tireWraps.forEach(function (wrap) {
            if (wrap === exceptWrap) {
                return;
            }
            wrap.classList.remove('is-active');
            const trigger = wrap.querySelector('.vehicle-tire-icon-trigger');
            if (trigger instanceof HTMLButtonElement) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    };

    tireTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const wrap = trigger.closest('.vehicle-tire-icon-wrap');
            if (!(wrap instanceof HTMLElement)) {
                return;
            }
            const shouldShow = !wrap.classList.contains('is-active');
            hideTireLabels(wrap);
            wrap.classList.toggle('is-active', shouldShow);
            trigger.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function () {
        hideTireLabels(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            hideTireLabels(null);
        }
    });

    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            const message = element.getAttribute('data-confirm') || '';
            if (message !== '' && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
});
</script>
