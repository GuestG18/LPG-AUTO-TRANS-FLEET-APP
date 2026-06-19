<?php
$detailFields = $module['detail_fields'] ?? $module['form_fields'];
$backUrl = $backUrl ?? build_query_url(['page' => $moduleKey]);
$driverDocuments = $driverDocuments ?? [];
$vehicleDocuments = $vehicleDocuments ?? [];
$documentCustomFieldRows = is_array($documentCustomFieldRows ?? null) ? $documentCustomFieldRows : [];
$driverDocumentCustomFieldRows = is_array($driverDocumentCustomFieldRows ?? null) ? $driverDocumentCustomFieldRows : [];
$statusContext = $statusContext ?? null;
$vehicleCouplingContext = $vehicleCouplingContext ?? null;
$vehicleTireContext = $vehicleTireContext ?? null;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0">Detalii <?= e($module['singular']) ?></h2>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => (int) $record['id']])) ?>">Editează</a>
        <a class="btn btn-outline-secondary" href="<?= e($backUrl) ?>">Înapoi</a>
    </div>
</div>

<?php if (in_array($moduleKey, ['vehicule', 'soferi'], true) && is_array($statusContext)): ?>
    <div class="alert <?= ($statusContext['status'] ?? 'inactiv') === 'activ' ? 'alert-success' : 'alert-warning' ?> mb-3">
        <div class="fw-semibold mb-2">
            Status calculat automat:
            <?= ($statusContext['status'] ?? 'inactiv') === 'activ' ? 'Activ' : 'Inactiv' ?>
        </div>
        <p class="mb-2">
            Statusul nu mai este setat manual. El se actualizeaza automat in functie de documentele obligatorii si de valabilitatea lor.
        </p>
        <?php if (($statusContext['checks'] ?? []) !== []): ?>
            <ul class="mb-0 ps-3">
                <?php foreach ($statusContext['checks'] as $check): ?>
                    <li>
                        <strong><?= e((string) ($check['label'] ?? '-')) ?>:</strong>
                        <?= e((string) ($check['message'] ?? '')) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <tbody>
                <?php foreach ($detailFields as $field => $meta): ?>
                    <?php
                    if (($meta['store'] ?? true) === false) {
                        continue;
                    }
                    ?>
                    <tr>
                        <th class="w-25 bg-light"><?= e($meta['label']) ?></th>
                        <td><?= format_value_html($record[$field] ?? null, $meta, $record) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($moduleKey === 'documente' && $documentCustomFieldRows !== []): ?>
    <?php $documentCustomFieldTypeLabels = ['text' => 'Text', 'number' => 'Numeric', 'date' => 'Data', 'checkbox' => 'Checkbox']; ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-1">Campuri personalizate</h3>
            <p class="text-muted small mb-0">Valori suplimentare completate pentru acest tip de document.</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <tbody>
                    <?php foreach ($documentCustomFieldRows as $customFieldRow): ?>
                        <?php
                        $fieldType = strtolower(trim((string) ($customFieldRow['type'] ?? 'text')));
                        $fieldValue = (string) ($customFieldRow['value'] ?? '');
                        if ($fieldType === 'date') {
                            $fieldValue = format_date_ro($fieldValue);
                        } elseif ($fieldType === 'checkbox') {
                            $fieldValue = $fieldValue === '1' ? 'Da' : 'Nu';
                        }
                        ?>
                        <tr>
                            <th class="w-25 bg-light">
                                <?= e((string) ($customFieldRow['label'] ?? '')) ?>
                                <div class="small text-muted fw-normal"><?= e((string) ($documentCustomFieldTypeLabels[$fieldType] ?? $fieldType)) ?></div>
                            </th>
                            <td><?= $fieldValue !== '' ? e($fieldValue) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'documente_soferi' && $driverDocumentCustomFieldRows !== []): ?>
    <?php $driverDocumentCustomFieldTypeLabels = ['text' => 'Text', 'number' => 'Numeric', 'date' => 'Data', 'checkbox' => 'Checkbox']; ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-1">Campuri personalizate</h3>
            <p class="text-muted small mb-0">Valori suplimentare completate pentru acest tip de document.</p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <tbody>
                    <?php foreach ($driverDocumentCustomFieldRows as $customFieldRow): ?>
                        <?php
                        $fieldType = strtolower(trim((string) ($customFieldRow['type'] ?? 'text')));
                        $fieldValue = (string) ($customFieldRow['value'] ?? '');
                        if ($fieldType === 'date') {
                            $fieldValue = format_date_ro($fieldValue);
                        } elseif ($fieldType === 'checkbox') {
                            $fieldValue = $fieldValue === '1' ? 'Da' : 'Nu';
                        }
                        ?>
                        <tr>
                            <th class="w-25 bg-light">
                                <?= e((string) ($customFieldRow['label'] ?? '')) ?>
                                <div class="small text-muted fw-normal"><?= e((string) ($driverDocumentCustomFieldTypeLabels[$fieldType] ?? $fieldType)) ?></div>
                            </th>
                            <td><?= $fieldValue !== '' ? e($fieldValue) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (in_array($moduleKey, ['documente', 'documente_soferi'], true)): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Fișier atașat și previzualizare</h3>
                <p class="text-muted small mb-0">Verifici rapid documentul încărcat și poți intra imediat în editare dacă observi o informație greșită.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'preview', 'id' => (int) $record['id']])) ?>">Vezi în aplicație</a>
                <?php if (!empty($record['fisier_stocat'])): ?>
                    <a class="btn btn-sm btn-outline-dark" href="<?= e(document_file_url((string) $record['fisier_stocat']) ?? '#') ?>" target="_blank" rel="noopener">Deschide fișierul</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => (int) $record['id']])) ?>">Corectează datele</a>
            </div>
        </div>
        <div class="card-body">
            <?= document_preview_html((string) ($record['fisier_original'] ?? ''), (string) ($record['fisier_stocat'] ?? '')) ?>
            <div class="alert alert-light border mt-3 mb-0">
                <strong>Serie / număr document:</strong>
                câmp opțional folosit doar când documentul are un identificator util, de exemplu seria permisului, seria atestatului sau un număr intern de referință.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'mentenanta'): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Factura atasata</h3>
                <p class="text-muted small mb-0">Deschizi rapid factura din interventie si o poti inlocui din editare daca ai incarcat varianta gresita.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'preview', 'id' => (int) $record['id']])) ?>">Vezi factura</a>
                <?php if (!empty($record['fisier_stocat'])): ?>
                    <a class="btn btn-sm btn-outline-dark" href="<?= e(document_file_url((string) $record['fisier_stocat']) ?? '#') ?>" target="_blank" rel="noopener">Deschide fisierul</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'edit', 'id' => (int) $record['id']])) ?>">Editeaza interventia</a>
            </div>
        </div>
        <div class="card-body">
            <?= document_preview_html((string) ($record['fisier_original'] ?? ''), (string) ($record['fisier_stocat'] ?? '')) ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'vehicule'): ?>
    <?php
    $coupling = is_array($vehicleCouplingContext) ? $vehicleCouplingContext : [];
    $vehicleType = (string) ($coupling['vehicle_type'] ?? ($record['tip_vehicul'] ?? 'autovehicul'));
    $vehicleTypeNormalized = normalize_vehicle_type($vehicleType);
    $activeCoupling = $coupling['active_coupling'] ?? null;
    $tractorOptions = is_array($coupling['tractor_options'] ?? null) ? $coupling['tractor_options'] : [];
    $trailerOptions = is_array($coupling['trailer_options'] ?? null) ? $coupling['trailer_options'] : [];
    $hasTrailerOptions = $trailerOptions !== [];
    $hasTractorOptions = $tractorOptions !== [];
    $couplingHistory = is_array($coupling['history'] ?? null) ? $coupling['history'] : [];
    $tireContext = is_array($vehicleTireContext) ? $vehicleTireContext : [];
    $tireLayout = is_array($tireContext['layout'] ?? null) ? $tireContext['layout'] : [];
    $tirePositions = is_array($tireContext['positions'] ?? null) ? $tireContext['positions'] : [];
    $tireAlerts = is_array($tireContext['alerts'] ?? null) ? $tireContext['alerts'] : [];
    $availableTires = is_array($tireContext['available_tires'] ?? null) ? $tireContext['available_tires'] : [];
    $tireHistory = is_array($tireContext['history'] ?? null) ? $tireContext['history'] : [];
    $layoutOptions = is_array($tireContext['layout_options'] ?? null) ? $tireContext['layout_options'] : [];
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

            if ($layoutValue === '6x2' && $axleNo === 2) {
                return 'Stanga / Dreapta';
            }

            if ($layoutValue === '8x2' && in_array($axleNo, [2, 3], true)) {
                return 'Stanga / Dreapta';
            }

            if (
                ($layoutValue === '4x2' && $axleNo === 2)
                || ($layoutValue === '6x2' && $axleNo === 3)
                || ($layoutValue === '8x2' && $axleNo === 4)
            ) {
                return 'Tractiune';
            }
        }

        if ($vehicleTypeValue === 'cap_tractor') {
            return $axleNo === 1 ? 'Directie' : 'Tractiune';
        }

        if (is_trailer_vehicle_type($vehicleTypeValue)) {
            return 'Semiremorca';
        }

        if ($vehicleTypeValue === 'autovehicul') {
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
    $normalizeWearMeta = static function (array $rawWearMeta) use ($wearMetaDefaults): array {
        return array_replace($wearMetaDefaults, $rawWearMeta);
    };
    $resolveVisualBadgeLabel = static function (?array $position): string {
        return $position !== null ? trim((string) ($position['position_code'] ?? '')) : '';
    };
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
                'role' => $resolveAxleRole((string) $vehicleType, (string) $layoutCurrentValue, $axleNo),
                'positions' => [],
            ];
        }
        $axleGroups[$axleNo]['positions'][] = $position;
    }
    ksort($axleGroups);

    $layoutSummary = trim((string) $layoutCurrentValue) !== ''
        ? (string) $layoutCurrentValue . ' (' . (string) $expectedTires . ' anvelope)'
        : '-';
    ?>

    <style>
    .vehicle-tire-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(135px, 1fr)) auto;
        gap: .75rem;
        align-items: center;
    }
    .vehicle-tire-summary-card .card-body {
        padding: .85rem;
    }
    .vehicle-tire-summary-item {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #f9fafb;
        padding: .55rem .7rem;
        min-height: 58px;
    }
    .vehicle-tire-summary-item .label {
        font-size: .74rem;
        color: #6b7280;
        line-height: 1.15;
    }
    .vehicle-tire-summary-item .value {
        font-weight: 700;
        color: #111827;
        line-height: 1.25;
        margin-top: .15rem;
    }
    .vehicle-tire-summary-action {
        justify-self: end;
        min-width: 220px;
    }
    .vehicle-tire-summary-action .btn {
        width: auto;
        min-width: 220px;
    }
    .vehicle-tire-panels-grid {
        display: grid;
        grid-template-columns: minmax(420px, 42%) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: stretch;
    }
    .vehicle-tire-panel-left,
    .vehicle-tire-panel-right {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .vehicle-tire-main-card {
        flex: 1 1 auto;
        width: 100%;
    }
    .vehicle-tire-panel-right {
        gap: .85rem;
    }
    .vehicle-tire-axle-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: .9rem;
    }
    .vehicle-tire-axle-card {
        border: 1px solid #dbe4ef;
        border-radius: 12px;
        background: #fcfdff;
        padding: .85rem;
    }
    .vehicle-tire-axle-title {
        text-align: center;
        font-size: 1.12rem;
        font-weight: 700;
        color: #1f2937;
    }
    .vehicle-tire-axle-count {
        text-align: center;
        color: #4b5563;
        font-size: 1rem;
        margin-bottom: .55rem;
    }
    .vehicle-axle-visual {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: space-between;
        max-width: min(460px, 100%);
        margin: 0 auto .75rem;
        padding: 0 2.2rem;
        min-height: 62px;
    }
    .vehicle-axle-visual::before {
        content: '';
        position: absolute;
        left: 72px;
        right: 72px;
        top: 50%;
        transform: translateY(-50%);
        height: 4px;
        border-radius: 999px;
        background: linear-gradient(90deg, #9ba8be 0%, #5f6f8d 50%, #9ba8be 100%);
    }
    .vehicle-axle-joint {
        position: absolute;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        width: 14px;
        height: 14px;
        border-radius: 999px;
        border: 2px solid #5d6d89;
        background: #dbe4ef;
        box-shadow: 0 0 0 3px rgba(255,255,255,.9);
        z-index: 1;
    }
    .vehicle-axle-wheel-side {
        display: inline-flex;
        align-items: center;
        gap: .56rem;
        position: relative;
        z-index: 2;
        flex: 0 0 auto;
        min-width: 0;
    }
    .vehicle-tire-icon-wrap {
        display: inline-flex;
        position: relative;
        flex: 0 0 auto;
        overflow: visible;
    }
    .vehicle-tire-position-popover {
        position: absolute;
        left: 50%;
        top: calc(100% + .55rem);
        width: 230px;
        max-width: 76vw;
        transform: translate(-50%, -.25rem) scale(.98);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity .14s ease, transform .14s ease, visibility .14s ease;
        z-index: 30;
    }
    .vehicle-tire-icon-wrap:hover .vehicle-tire-position-popover,
    .vehicle-tire-icon-wrap.is-active .vehicle-tire-position-popover,
    .vehicle-tire-icon-wrap:focus-within .vehicle-tire-position-popover {
        opacity: 1;
        visibility: visible;
        transform: translate(-50%, 0) scale(1);
    }
    .vehicle-tire-position-popover .vehicle-tire-position-item {
        width: 100%;
        min-height: 0;
        padding: .62rem .75rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, .14);
    }
    .vehicle-axle-wheel-side-left .vehicle-tire-icon-wrap:first-child .vehicle-tire-position-popover {
        left: 0;
        transform: translate(0, -.25rem) scale(.98);
    }
    .vehicle-axle-wheel-side-left .vehicle-tire-icon-wrap:first-child:hover .vehicle-tire-position-popover,
    .vehicle-axle-wheel-side-left .vehicle-tire-icon-wrap:first-child.is-active .vehicle-tire-position-popover,
    .vehicle-axle-wheel-side-left .vehicle-tire-icon-wrap:first-child:focus-within .vehicle-tire-position-popover {
        transform: translate(0, 0) scale(1);
    }
    .vehicle-axle-wheel-side-right .vehicle-tire-icon-wrap:last-child .vehicle-tire-position-popover {
        left: auto;
        right: 0;
        transform: translate(0, -.25rem) scale(.98);
    }
    .vehicle-axle-wheel-side-right .vehicle-tire-icon-wrap:last-child:hover .vehicle-tire-position-popover,
    .vehicle-axle-wheel-side-right .vehicle-tire-icon-wrap:last-child.is-active .vehicle-tire-position-popover,
    .vehicle-axle-wheel-side-right .vehicle-tire-icon-wrap:last-child:focus-within .vehicle-tire-position-popover {
        transform: translate(0, 0) scale(1);
    }
    .vehicle-tire-icon {
        width: 24px;
        height: 58px;
        border-radius: 11px;
        border: 1px solid #1f2937;
        background: linear-gradient(180deg, #49586e 0%, #1f2937 100%);
        box-shadow: inset 0 0 0 2px rgba(255,255,255,.08);
    }
    .vehicle-tire-icon-trigger {
        appearance: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        position: relative;
        flex: 0 0 auto;
        padding: 0;
        overflow: visible;
        cursor: pointer;
    }
    .vehicle-tire-icon-trigger:hover {
        filter: brightness(1.06);
    }
    .vehicle-tire-icon-trigger:focus-visible {
        outline: 2px solid rgba(37, 99, 235, .55);
        outline-offset: 3px;
    }
    .vehicle-tire-position-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .7rem;
        max-width: 560px;
        margin: 0 auto;
    }
    .vehicle-tire-position-item {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #f9fafb;
        min-height: 82px;
        padding: .6rem .75rem;
    }
    .vehicle-tire-position-code {
        font-size: 1rem;
        font-weight: 700;
        text-align: center;
        color: #1f2937;
    }
    .vehicle-tire-position-label {
        text-align: center;
        color: #4b5563;
        font-size: .82rem;
        min-height: 24px;
    }
    .tire-wear-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .32rem;
        border: 1px solid transparent;
        border-radius: 999px;
        padding: .14rem .55rem;
        font-size: .75rem;
        font-weight: 700;
    }
    .tire-wear-badge-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        display: inline-block;
    }
    .tire-wear-badge-ok {
        color: #166534;
        background: #ecf8ef;
        border-color: #b7e4c7;
    }
    .tire-wear-badge-ok .tire-wear-badge-dot { background: #2f9e44; }
    .tire-wear-badge-warning {
        color: #b45309;
        background: #fff7e7;
        border-color: #f6d08b;
    }
    .tire-wear-badge-warning .tire-wear-badge-dot { background: #e5a50a; }
    .tire-wear-badge-critic {
        color: #b91c1c;
        background: #fdecec;
        border-color: #f4b5b5;
    }
    .tire-wear-badge-critic .tire-wear-badge-dot { background: #dc2626; }
    .tire-wear-badge-empty {
        color: #4b5563;
        background: #f3f4f6;
        border-color: #d1d5db;
    }
    .tire-wear-badge-empty .tire-wear-badge-dot { background: #9ca3af; }
    .tire-wear-progress {
        height: 7px;
        background: #e5e7eb;
        border-radius: 999px;
        overflow: hidden;
    }
    .tire-wear-progress .progress-bar {
        border-radius: 999px;
    }
    .vehicle-tire-legend-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: .8rem;
    }
    .vehicle-tire-legend-card {
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fff;
        padding: .65rem .75rem;
    }
    .vehicle-tire-legend-wide .vehicle-tire-legend-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    .vehicle-tire-legend-wide .vehicle-tire-legend-card {
        display: grid;
        align-content: start;
        min-height: 104px;
        gap: .25rem;
    }
    .vehicle-tire-legend-wide .vehicle-tire-legend-card .fw-semibold,
    .vehicle-tire-legend-wide .vehicle-tire-legend-card .small {
        margin-bottom: 0 !important;
    }
    .vehicle-tire-panel-right .vehicle-tire-positions-card {
        flex: 1 1 auto;
    }
    .vehicle-tire-panel-right .vehicle-tire-positions-card .card-body {
        display: flex;
        flex-direction: column;
    }
    .vehicle-tire-panel-right .vehicle-tire-positions-card .table-responsive {
        flex: 1 1 auto;
    }
    .vehicle-tire-panel-right .vehicle-tire-positions-card .table {
        height: 100%;
    }
    .vehicle-tire-positions-card .table {
        font-size: .92rem;
    }
    .vehicle-tire-positions-card .table th {
        background: #f8fafc;
        color: #4b5563;
        font-size: .76rem;
        letter-spacing: .02em;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .vehicle-tire-positions-card .table th,
    .vehicle-tire-positions-card .table td {
        padding: .68rem .65rem;
        vertical-align: middle;
    }
    .vehicle-tire-positions-card .table tbody tr:nth-child(even) > * {
        background: #fbfdff;
    }
    @media (max-width: 1399.98px) {
        .vehicle-tire-panels-grid,
        .vehicle-tire-summary-grid {
            grid-template-columns: 1fr;
        }
        .vehicle-tire-legend-wide .vehicle-tire-legend-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .vehicle-tire-summary-action {
            justify-self: stretch;
            min-width: 0;
        }
        .vehicle-tire-summary-action .btn {
            width: 100%;
            min-width: 0;
        }
    }
    @media (max-width: 767.98px) {
        .vehicle-tire-axle-card {
            padding: .95rem;
        }
        .vehicle-axle-visual {
            padding: 0 1.8rem;
        }
        .vehicle-axle-wheel-side {
            gap: .38rem;
        }
        .vehicle-tire-position-popover {
            width: 205px;
            max-width: 68vw;
        }
        .vehicle-tire-position-popover .vehicle-tire-position-item {
            padding: .55rem .65rem;
        }
        .vehicle-axle-visual::before {
            left: 58px;
            right: 58px;
        }
        .vehicle-tire-position-grid,
        .vehicle-tire-legend-wide .vehicle-tire-legend-grid {
            grid-template-columns: 1fr;
        }
    }
    .vehicle-tire-legend-ok { border-color: #b7e4c7; background: #f1fbf4; }
    .vehicle-tire-legend-warning { border-color: #f6d08b; background: #fffaf2; }
    .vehicle-tire-legend-critic { border-color: #f4b5b5; background: #fff4f4; }
    .vehicle-tire-alert-info {
        border: 1px solid #bfd7ff;
        background: #eff6ff;
        color: #1e3a8a;
    }
    </style>

    <div class="card border-0 shadow-sm mt-4 vehicle-tire-summary-card">
        <div class="card-body">
            <div class="vehicle-tire-summary-grid">
                <div class="vehicle-tire-summary-item">
                    <div class="label">Tip vehicul</div>
                    <div class="value"><?= e(vehicle_type_label((string) ($tireLayout['vehicle_type'] ?? $vehicleType))) ?></div>
                </div>
                <div class="vehicle-tire-summary-item">
                    <div class="label">Formula axelor</div>
                    <div class="value"><?= e($layoutSummary) ?></div>
                </div>
                <div class="vehicle-tire-summary-item">
                    <div class="label">Anvelope montate</div>
                    <div class="value"><?= e((string) $mountedTires) ?> / <?= e((string) $expectedTires) ?></div>
                </div>
                <div class="vehicle-tire-summary-item">
                    <div class="label">Pozitii libere</div>
                    <div class="value <?= $unmountedPositions > 0 ? 'text-warning' : 'text-success' ?>"><?= e((string) $unmountedPositions) ?></div>
                </div>
                <div class="vehicle-tire-summary-action">
                    <a href="#vehicle_tire_layout_config" class="btn btn-primary">Schimba configuratia axelor</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3" id="vehicle_tire_layout_config">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Schimba configuratia axelor</h3>
        </div>
        <div class="card-body">
            <form id="vehicle_tire_layout_form" method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'update_tire_layout'])) ?>" class="row g-3 align-items-end" data-mounted-tires="<?= e((string) $mountedTires) ?>" data-initial-layout="<?= e($layoutCurrentValue) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
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
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.getElementById('vehicle_tire_layout_form');
                const layoutField = document.getElementById('tire_layout_value');

                if (!(form instanceof HTMLFormElement) || !(layoutField instanceof HTMLInputElement || layoutField instanceof HTMLSelectElement)) {
                    return;
                }

                const mountedTires = Number(form.getAttribute('data-mounted-tires') || '0');
                const initialLayout = (form.getAttribute('data-initial-layout') || '').trim();

                if (mountedTires <= 0) {
                    return;
                }

                form.addEventListener('submit', function (event) {
                    const nextLayout = layoutField.value.trim();
                    if (initialLayout !== '' && nextLayout !== '' && nextLayout !== initialLayout) {
                        const message = 'Vehiculul are deja ' + mountedTires + ' anvelope montate. Doresti sa schimbi configuratia axelor? Pozitiile existente raman protejate.';
                        if (!window.confirm(message)) {
                            event.preventDefault();
                        }
                    }
                });
            });
            </script>
        </div>
    </div>

    <div class="vehicle-tire-panels-grid mt-3">
        <div class="vehicle-tire-panel-left">
            <div class="card border-0 shadow-sm vehicle-tire-main-card">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Configuratie axe si pozitii</h3>
                </div>
                <div class="card-body">
                    <?php if ($axleGroups === []): ?>
                        <div class="text-muted">Nu exista pozitii generate inca pentru acest vehicul.</div>
                    <?php else: ?>
                        <div class="vehicle-tire-axle-grid">
                            <?php foreach ($axleGroups as $axleGroup): ?>
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
                                <div class="vehicle-tire-axle-card">
                                    <div class="vehicle-tire-axle-title"><?= e($axleTitle) ?></div>
                                    <div class="vehicle-tire-axle-count"><?= e((string) count($axlePositions)) ?> anvelope<?= count($axlePositions) >= 4 ? ' (dubla)' : '' ?></div>

                                    <div class="vehicle-axle-visual">
                                        <div class="vehicle-axle-wheel-side vehicle-axle-wheel-side-left">
                                            <?php foreach ($leftPositions as $iconPosition): ?>
                                                <?php $tireBadgeLabel = $resolveVisualBadgeLabel($iconPosition); ?>
                                                <div class="vehicle-tire-icon-wrap">
                                                    <button type="button" class="vehicle-tire-icon vehicle-tire-icon-trigger" aria-label="Afiseaza pozitia <?= e($tireBadgeLabel) ?>" aria-expanded="false"></button>
                                                    <div class="vehicle-tire-position-popover">
                                                        <?php $renderVehicleTirePositionItem($iconPosition); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <span class="vehicle-axle-joint" aria-hidden="true"></span>
                                        <div class="vehicle-axle-wheel-side vehicle-axle-wheel-side-right">
                                            <?php foreach ($rightPositions as $iconPosition): ?>
                                                <?php $tireBadgeLabel = $resolveVisualBadgeLabel($iconPosition); ?>
                                                <div class="vehicle-tire-icon-wrap">
                                                    <button type="button" class="vehicle-tire-icon vehicle-tire-icon-trigger" aria-label="Afiseaza pozitia <?= e($tireBadgeLabel) ?>" aria-expanded="false"></button>
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
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Axa</th>
                        <th>Pozitie</th>
                        <th>Anvelopa</th>
                        <th>Status</th>
                        <th>Uzura km</th>
                        <th>Uzura %</th>
                        <th>DOT</th>
                        <th class="text-end pe-3">Actiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($tirePositionsPrepared === []): ?>
                        <tr>
                            <td colspan="8" class="text-muted text-center py-3">Nu exista pozitii generate inca pentru acest vehicul.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tirePositionsPrepared as $position): ?>
                            <?php $tire = is_array($position['tire'] ?? null) ? $position['tire'] : null; ?>
                            <?php
                            $wearMetaRaw = is_array($position['wear_meta'] ?? null) ? $position['wear_meta'] : $computeWearMeta($tire, $wearDefaultLimitKm);
                            $wearMeta = $normalizeWearMeta($wearMetaRaw);
                            $axleNo = (int) ($position['axle_no'] ?? 0);
                            $axleRole = $resolveAxleRole((string) $vehicleType, (string) $layoutCurrentValue, $axleNo);
                            $axleLabel = 'Axa ' . $axleNo . ($axleRole !== '' ? ' (' . $axleRole . ')' : '');
                            ?>
                            <tr>
                                <td><?= e($axleLabel) ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e((string) ($position['position_code'] ?? '-')) ?></div>
                                    <div class="small text-muted"><?= e((string) ($position['position_label'] ?? '-')) ?></div>
                                </td>
                                <td>
                                    <?php if ($tire !== null): ?>
                                        <div class="fw-semibold"><?= e(trim((string) (($tire['brand'] ?? '') . ' ' . ($tire['model'] ?? '')))) ?></div>
                                        <div class="small text-muted">
                                            SN: <?= e((string) ($tire['serial_number'] ?? '-')) ?>
                                            <?php if (!empty($tire['tire_size'])): ?> | <?= e((string) $tire['tire_size']) ?><?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Pozitie libera</span>
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
                                <td>
                                    <?php if ($tire !== null): ?>
                                        <div><?= e((string) (($tire['dot_code'] ?? '') !== '' ? $tire['dot_code'] : '-')) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
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
                                                        <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'change_tire_status'])) ?>">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
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
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'unmount_tire'])) ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
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

    <div class="card border-0 shadow-sm mt-3 vehicle-tire-legend-wide">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Legenda status uzura</h3>
        </div>
        <div class="card-body">
            <div class="vehicle-tire-legend-grid">
                <div class="vehicle-tire-legend-card vehicle-tire-legend-ok">
                    <div class="fw-semibold text-success mb-1">OK (Verde)</div>
                    <div class="small mb-1">Uzura <= 75%</div>
                    <div class="small text-muted">Anvelopa este in parametri optimi.</div>
                </div>
                <div class="vehicle-tire-legend-card vehicle-tire-legend-warning">
                    <div class="fw-semibold text-warning mb-1">ATENTIE (Galben)</div>
                    <div class="small mb-1">Uzura > 75% si <= 90%</div>
                    <div class="small text-muted">Anvelopa se apropie de limita de inlocuire.</div>
                </div>
                <div class="vehicle-tire-legend-card vehicle-tire-legend-critic">
                    <div class="fw-semibold text-danger mb-1">CRITIC (Rosu)</div>
                    <div class="small mb-1">Uzura > 90%</div>
                    <div class="small text-muted">Anvelopa trebuie inlocuita in cel mai scurt timp.</div>
                </div>
                <div class="vehicle-tire-legend-card vehicle-tire-alert-info">
                    <div class="fw-semibold mb-1">Avertizare automata</div>
                    <div class="small">Cand uzura depaseste 90%, pozitia anvelopei devine CRITIC (rosu).</div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const tireTriggers = Array.from(document.querySelectorAll('.vehicle-tire-icon-trigger'));
        const tireWraps = tireTriggers
            .map(function (trigger) {
                return trigger.closest('.vehicle-tire-icon-wrap');
            })
            .filter(function (wrap) {
                return wrap !== null;
            });

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
    });
    </script>

    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Monteaza anvelopa existenta</h3>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'mount_tire'])) ?>" class="row g-3 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                        <div class="col-12">
                            <label class="form-label" for="mount_tire_id">Anvelopa disponibila</label>
                            <select class="form-select" id="mount_tire_id" name="tire_id" required>
                                <option value="">-- Selecteaza --</option>
                                <?php foreach ($availableTires as $availableTire): ?>
                                    <?php
                                    $availableTireId = (int) ($availableTire['id'] ?? 0);
                                    $availableLabel = trim((string) ($availableTire['brand'] ?? '') . ' ' . (string) ($availableTire['model'] ?? ''));
                                    $availableSerial = trim((string) ($availableTire['serial_number'] ?? ''));
                                    $availableTargetType = vehicle_type_label((string) ($availableTire['target_vehicle_type'] ?? 'universal'));
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
                                    <option value="<?= e((string) ((int) ($position['position_id'] ?? 0))) ?>">
                                        <?= e((string) ($position['position_code'] ?? '-')) ?> - <?= e((string) ($position['position_label'] ?? '-')) ?>
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
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
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
                                    } else {
                                        echo tire_status_badge_html($historyStatus);
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

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Cuplaj tractor - semiremorca</h3>
                <p class="text-muted small mb-0">Pentru cap tractor si semiremorca poti muta alocarea direct din aceasta pagina.</p>
            </div>
            <span class="badge text-bg-light border">Tip vehicul: <?= e(vehicle_type_label($vehicleType)) ?></span>
        </div>
        <div class="card-body">
            <?php if (in_array($vehicleTypeNormalized, ['autovehicul', 'camion'], true)): ?>
                <div class="alert alert-light border mb-0">
                    Acest vehicul este de tip <strong><?= e(vehicle_type_label($vehicleType)) ?></strong>. Cuplajul este disponibil doar pentru <strong>Cap tractor</strong> si <strong>Semi-remorca</strong>.
                </div>
            <?php elseif ($vehicleTypeNormalized === 'cap_tractor'): ?>
                <div class="mb-3">
                    <div class="small text-muted">Semiremorca curenta</div>
                    <div class="fw-semibold">
                        <?= $activeCoupling ? e((string) ($activeCoupling['semiremorca_nr'] ?? '-')) : 'Nicio semiremorca cuplata' ?>
                    </div>
                </div>

                <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'cupleaza'])) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tractor_id" value="<?= e((string) ((int) $record['id'])) ?>">
                    <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                    <div class="col-12 col-md-8">
                        <label class="form-label mb-1" for="semiremorca_id">Selecteaza semiremorca</label>
                        <select class="form-select" id="semiremorca_id" name="semiremorca_id" <?= $hasTrailerOptions ? 'required' : 'disabled' ?>>
                            <?php if ($hasTrailerOptions): ?>
                                <option value="">-- Selecteaza --</option>
                            <?php else: ?>
                                <option value="">Nu exista semiremorci active si necuplate.</option>
                            <?php endif; ?>
                            <?php foreach ($trailerOptions as $optionId => $optionLabel): ?>
                                <option value="<?= e((string) $optionId) ?>" <?= $activeCoupling && (int) ($activeCoupling['semiremorca_id'] ?? 0) === (int) $optionId ? 'selected' : '' ?>>
                                    <?= e((string) $optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= $hasTrailerOptions ? '' : 'disabled' ?>>Salveaza cuplaj</button>
                    </div>
                </form>

                <?php if ($activeCoupling): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'decupleaza'])) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="tractor_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Sigur doresti sa decuplezi semiremorca de pe acest tractor?">
                            Decupleaza semiremorca
                        </button>
                    </form>
                <?php endif; ?>
            <?php elseif (is_trailer_vehicle_type($vehicleType)): ?>
                <div class="mb-3">
                    <div class="small text-muted">Tractor curent</div>
                    <div class="fw-semibold">
                        <?= $activeCoupling ? e((string) ($activeCoupling['tractor_nr'] ?? '-')) : 'Niciun tractor cuplat' ?>
                    </div>
                </div>

                <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'cupleaza'])) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="semiremorca_id" value="<?= e((string) ((int) $record['id'])) ?>">
                    <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                    <div class="col-12 col-md-8">
                        <label class="form-label mb-1" for="tractor_id">Selecteaza cap tractor</label>
                        <select class="form-select" id="tractor_id" name="tractor_id" <?= $hasTractorOptions ? 'required' : 'disabled' ?>>
                            <option value="">-- Selecteaza --</option>
                            <?php foreach ($tractorOptions as $optionId => $optionLabel): ?>
                                <option value="<?= e((string) $optionId) ?>" <?= $activeCoupling && (int) ($activeCoupling['tractor_id'] ?? 0) === (int) $optionId ? 'selected' : '' ?>>
                                    <?= e((string) $optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= $hasTractorOptions ? '' : 'disabled' ?>>Muta pe tractor</button>
                    </div>
                </form>

                <?php if ($activeCoupling): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'decupleaza'])) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="semiremorca_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Sigur doresti sa decuplezi aceasta semiremorca?">
                            Decupleaza de pe tractor
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($couplingHistory !== []): ?>
                <div class="table-responsive mt-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Cap tractor</th>
                                <th>Semiremorca</th>
                                <th>Start</th>
                                <th>Sfarsit</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($couplingHistory as $history): ?>
                                <tr>
                                    <td><?= e((string) ($history['tractor_nr'] ?? '-')) ?></td>
                                    <td><?= e((string) ($history['semiremorca_nr'] ?? '-')) ?></td>
                                    <td><?= e(format_datetime_ro((string) ($history['data_start'] ?? ''))) ?></td>
                                    <td><?= e(format_datetime_ro((string) ($history['data_end'] ?? ''))) ?></td>
                                    <td><?= !empty($history['activ']) ? '<span class="badge text-bg-success">Activ</span>' : '<span class="badge text-bg-secondary">Incheiat</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Documente asociate vehiculului</h3>
                <p class="text-muted small mb-0">Vezi toate documentele atașate acestui vehicul și intră direct în previzualizare.</p>
            </div>
            <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'create', 'vehicle_id' => (int) $record['id']])) ?>">Adaugă document</a>
        </div>
        <div class="card-body p-0">
            <?php if ($vehicleDocuments === []): ?>
                <div class="p-3 text-muted">Nu există documente asociate acestui vehicul.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Tip document</th>
                            <th>Serie / număr</th>
                            <th>Fișier</th>
                            <th>Expirare</th>
                            <th class="text-end pe-3">Acțiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vehicleDocuments as $document): ?>
                            <?php
                            $customFieldRows = is_array($document['custom_field_display_rows'] ?? null) ? $document['custom_field_display_rows'] : [];
                            $vehicleDocumentExpiryDate = trim((string) ($document['data_expirare'] ?? ''));
                            $vehicleDocumentExpiryLabel = '';
                            if ($vehicleDocumentExpiryDate === '' && $customFieldRows !== []) {
                                $customDateRows = array_values(array_filter($customFieldRows, static function (array $customFieldRow): bool {
                                    return strtolower(trim((string) ($customFieldRow['type'] ?? 'text'))) === 'date'
                                        && trim((string) ($customFieldRow['value'] ?? '')) !== '';
                                }));
                                usort($customDateRows, static function (array $a, array $b): int {
                                    return strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? ''));
                                });

                                if ($customDateRows !== []) {
                                    $vehicleDocumentExpiryDate = (string) ($customDateRows[0]['value'] ?? '');
                                    $vehicleDocumentExpiryLabel = (string) ($customDateRows[0]['label'] ?? '');
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e((string) ($document['tip_document'] ?? '-')) ?></div>
                                    <?php if ($customFieldRows !== []): ?>
                                        <div class="d-flex flex-column gap-1 mt-1">
                                            <?php foreach ($customFieldRows as $customFieldRow): ?>
                                                <?php
                                                $fieldType = strtolower(trim((string) ($customFieldRow['type'] ?? 'text')));
                                                $fieldLabel = trim((string) ($customFieldRow['label'] ?? ''));
                                                $fieldValue = trim((string) ($customFieldRow['value'] ?? ''));

                                                if ($fieldType === 'checkbox') {
                                                    $displayValue = $fieldValue === '1' ? 'Da' : 'Nu';
                                                } elseif ($fieldType === 'date') {
                                                    $displayValue = $fieldValue !== '' ? format_date_ro($fieldValue) : '';
                                                } else {
                                                    $displayValue = $fieldValue;
                                                }
                                                ?>
                                                <?php if ($fieldType === 'checkbox' && $fieldValue === '1'): ?>
                                                    <span class="badge text-bg-light border align-self-start"><?= e($fieldLabel) ?></span>
                                                <?php elseif ($displayValue !== ''): ?>
                                                    <div class="small">
                                                        <span class="text-muted"><?= e($fieldLabel) ?>:</span>
                                                        <span class="fw-semibold"><?= e($displayValue) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) (($document['numar_document'] ?? '') !== '' ? $document['numar_document'] : '-')) ?></td>
                                <td><?= document_file_link_html((string) ($document['fisier_original'] ?? ''), (string) ($document['fisier_stocat'] ?? '')) ?></td>
                                <td>
                                    <?= expiry_badge_html($vehicleDocumentExpiryDate) ?>
                                    <?php if ($vehicleDocumentExpiryLabel !== ''): ?>
                                        <div class="small text-muted mt-1"><?= e($vehicleDocumentExpiryLabel) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex gap-1">
                                        <?php if (!empty($document['fisier_stocat'])): ?>
                                            <a class="btn btn-sm btn-outline-dark" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'preview', 'id' => (int) $document['id']])) ?>">Vezi în aplicație</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'show', 'id' => (int) $document['id']])) ?>">Detalii</a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'edit', 'id' => (int) $document['id']])) ?>">Editează</a>
                                    </div>
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

<?php if ($moduleKey === 'soferi'): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Documente asociate șoferului</h3>
                <p class="text-muted small mb-0">Vezi documentele încărcate pentru acest șofer și deschide rapid fișierele atașate.</p>
            </div>
            <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'create', 'driver_id' => (int) $record['id']])) ?>">Adaugă document</a>
        </div>
        <div class="card-body p-0">
            <?php if ($driverDocuments === []): ?>
                <div class="p-3 text-muted">Nu există documente asociate acestui șofer.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Tip document</th>
                            <th>Serie / număr</th>
                            <th>Fișier</th>
                            <th>Expirare</th>
                            <th class="text-end pe-3">Acțiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($driverDocuments as $document): ?>
                            <?php
                            $customFieldRows = is_array($document['custom_field_display_rows'] ?? null) ? $document['custom_field_display_rows'] : [];
                            $driverDocumentExpiryDate = trim((string) ($document['data_expirare'] ?? ''));
                            $driverDocumentExpiryLabel = '';
                            if ($driverDocumentExpiryDate === '' && $customFieldRows !== []) {
                                $customDateRows = array_values(array_filter($customFieldRows, static function (array $customFieldRow): bool {
                                    return strtolower(trim((string) ($customFieldRow['type'] ?? 'text'))) === 'date'
                                        && trim((string) ($customFieldRow['value'] ?? '')) !== '';
                                }));
                                usort($customDateRows, static function (array $a, array $b): int {
                                    return strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? ''));
                                });

                                if ($customDateRows !== []) {
                                    $driverDocumentExpiryDate = (string) ($customDateRows[0]['value'] ?? '');
                                    $driverDocumentExpiryLabel = (string) ($customDateRows[0]['label'] ?? '');
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e((string) ($document['tip_document'] ?? '-')) ?></div>
                                    <?php if ($customFieldRows !== []): ?>
                                        <div class="d-flex flex-column gap-1 mt-1">
                                            <?php foreach ($customFieldRows as $customFieldRow): ?>
                                                <?php
                                                $fieldType = strtolower(trim((string) ($customFieldRow['type'] ?? 'text')));
                                                $fieldLabel = trim((string) ($customFieldRow['label'] ?? ''));
                                                $fieldValue = trim((string) ($customFieldRow['value'] ?? ''));

                                                if ($fieldType === 'checkbox') {
                                                    $displayValue = $fieldValue === '1' ? 'Da' : 'Nu';
                                                } elseif ($fieldType === 'date') {
                                                    $displayValue = $fieldValue !== '' ? format_date_ro($fieldValue) : '';
                                                } else {
                                                    $displayValue = $fieldValue;
                                                }
                                                ?>
                                                <?php if ($fieldType === 'checkbox' && $fieldValue === '1'): ?>
                                                    <span class="badge text-bg-light border align-self-start"><?= e($fieldLabel) ?></span>
                                                <?php elseif ($displayValue !== ''): ?>
                                                    <div class="small">
                                                        <span class="text-muted"><?= e($fieldLabel) ?>:</span>
                                                        <span class="fw-semibold"><?= e($displayValue) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) (($document['numar_document'] ?? '') !== '' ? $document['numar_document'] : '-')) ?></td>
                                <td><?= document_file_link_html((string) ($document['fisier_original'] ?? ''), (string) ($document['fisier_stocat'] ?? '')) ?></td>
                                <td>
                                    <?= expiry_badge_html($driverDocumentExpiryDate) ?>
                                    <?php if ($driverDocumentExpiryLabel !== ''): ?>
                                        <div class="small text-muted mt-1"><?= e($driverDocumentExpiryLabel) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex gap-1">
                                        <?php if (!empty($document['fisier_stocat'])): ?>
                                            <a class="btn btn-sm btn-outline-dark" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'preview', 'id' => (int) $document['id']])) ?>">Vezi în aplicație</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'show', 'id' => (int) $document['id']])) ?>">Detalii</a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'edit', 'id' => (int) $document['id']])) ?>">Editează</a>
                                    </div>
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

<?php if ($moduleKey === 'documente' && !empty($documentAuditLogs)): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Audit log document</h3>
        </div>
        <div class="card-body">
            <div class="list-group list-group-flush">
                <?php foreach ($documentAuditLogs as $auditLog): ?>
                    <div class="list-group-item px-0">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <?= audit_action_badge_html((string) ($auditLog['actiune'] ?? '')) ?>
                                    <span class="fw-semibold"><?= e((string) ($auditLog['descriere'] ?? 'Acțiune fără descriere')) ?></span>
                                </div>
                                <div class="small text-muted">
                                    Utilizator: <?= e((string) ($auditLog['utilizator_nume'] ?? 'Necunoscut')) ?>
                                </div>
                            </div>
                            <small class="text-muted text-nowrap"><?= e(format_datetime_ro((string) ($auditLog['created_at'] ?? ''))) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
