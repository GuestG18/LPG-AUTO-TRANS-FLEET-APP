<?php
declare(strict_types=1);

$section = (string) ($section ?? 'overview');
$filters = is_array($filters ?? null) ? $filters : [];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$drivers = is_array($drivers ?? null) ? $drivers : [];
$costCenters = is_array($costCenters ?? null) ? $costCenters : [];
$costCenterOptions = is_array($costCenterOptions ?? null) ? $costCenterOptions : [];
$passedVehicleTypeLabels = is_array($vehicleTypeLabels ?? null) ? $vehicleTypeLabels : [];
$overview = is_array($overview ?? null) ? $overview : [];
$records = is_array($records ?? null) ? $records : [];
$scheduledInterventions = is_array($scheduledInterventions ?? null) ? $scheduledInterventions : [];
$parts = is_array($parts ?? null) ? $parts : [];
$availableTireCount = count(array_filter($parts, static fn (array $item): bool => ($item['inventory_type'] ?? '') === 'tire'));
$availableStockParts = is_array($availableStockParts ?? null) ? $availableStockParts : [];
$stockKpis = is_array($stockKpis ?? null) ? $stockKpis : [];
$stockFilterOptions = is_array($stockFilterOptions ?? null) ? $stockFilterOptions : ['categories' => [], 'suppliers' => []];
$technicalFormOptions = is_array($technicalFormOptions ?? null) ? $technicalFormOptions : ['categories' => [], 'componentsByCategory' => []];
$technicalCategories = is_array($technicalFormOptions['categories'] ?? null) ? $technicalFormOptions['categories'] : [];
$technicalComponentsByCategory = is_array($technicalFormOptions['componentsByCategory'] ?? null) ? $technicalFormOptions['componentsByCategory'] : [];
$editRecord = is_array($editRecord ?? null) ? $editRecord : null;
$viewRecord = is_array($viewRecord ?? null) ? $viewRecord : null;
$editIntervention = is_array($editIntervention ?? null) ? $editIntervention : null;

$sectionUrl = static fn (string $target): string => build_query_url([
    'page' => 'mentenanta',
    'action' => $target === 'overview' ? 'overview' : $target,
]);
$currency = static fn (float $value): string => format_number_ro($value, 2) . ' lei';
$vehicleTypeLabels = [
    'autovehicul' => 'Autovehicul',
    'autoutilitara' => 'Autoutilitară',
    'camion' => 'Camion',
    'cap_tractor' => 'Cap Tractor',
    'semiremorca' => 'Semiremorcă',
    'semiremorca_primar' => 'Semiremorcă',
    'semiremorca_distributie' => 'Semiremorcă',
];
$vehicleTypeLabels = array_replace(['universal' => 'Toate tipurile'], $vehicleTypeLabels, $passedVehicleTypeLabels);
if ($costCenterOptions === []) {
    foreach ($costCenters as $center) {
        $costCenterOptions[] = [
            'label' => (string) $center,
            'vehicle_type' => 'universal',
            'components' => '',
        ];
    }
}
$configuredCostCenters = [];
foreach ($costCenterOptions as $option) {
    $label = trim((string) ($option['label'] ?? ''));
    if ($label !== '') {
        $configuredCostCenters[$label] = $label;
    }
}
$configuredCostCenters = $configuredCostCenters !== [] ? array_values($configuredCostCenters) : $costCenters;
$recordStatusLabels = [
    'in_asteptare' => 'În așteptare',
    'in_lucru' => 'În lucru',
    'finalizata' => 'Finalizată',
    'anulata' => 'Anulată',
];
$scheduleStatusLabels = [
    'programata' => 'Programată',
    'confirmata' => 'Confirmată',
    'in_lucru' => 'În lucru',
    'finalizata' => 'Finalizată',
    'anulata' => 'Anulată',
];
$statusClass = static function (string $status): string {
    return match ($status) {
        'finalizata', 'confirmata' => 'maintenance-badge-success',
        'in_lucru', 'programata', 'in_asteptare' => 'maintenance-badge-warning',
        'anulata' => 'maintenance-badge-muted',
        default => 'maintenance-badge-muted',
    };
};
$warrantyBadgeClass = static function (string $status): string {
    return match ($status) {
        'green' => 'maintenance-badge-success',
        'yellow' => 'maintenance-badge-warning',
        'red' => 'maintenance-badge-danger',
        default => 'maintenance-badge-muted',
    };
};
$centerClass = static function (string $center): string {
    return match ($center) {
        'Motor' => 'maintenance-badge-purple',
        'Sistem frânare' => 'maintenance-badge-warning',
        'Transmisie' => 'maintenance-badge-blue',
        'Sistem electric' => 'maintenance-badge-success',
        'Suspensie' => 'maintenance-badge-purple',
        default => 'maintenance-badge-muted',
    };
};
$renderCostCenterOptions = static function (array $options, string $selected = '', bool $includeEmpty = false, string $emptyLabel = 'Selecteaza') use ($vehicleTypeLabels): void {
    if ($includeEmpty) {
        echo '<option value="" data-vehicle-type="universal">' . e($emptyLabel) . '</option>';
    }

    foreach ($options as $option) {
        $label = trim((string) ($option['label'] ?? ''));
        if ($label === '') {
            continue;
        }

        $vehicleType = trim((string) ($option['vehicle_type'] ?? 'universal'));
        $vehicleType = $vehicleType !== '' ? $vehicleType : 'universal';
        $vehicleLabel = (string) ($vehicleTypeLabels[$vehicleType] ?? $vehicleType);
        $displayLabel = $vehicleType === 'universal' ? $label : $label . ' - ' . $vehicleLabel;
        $isSelected = $selected !== '' && $selected === $label;

        echo '<option value="' . e($label) . '" data-vehicle-type="' . e($vehicleType) . '"' . ($isSelected ? ' selected' : '') . '>' . e($displayLabel) . '</option>';
    }
};
$recordModalType = $section === 'repairs' ? 'reparatie' : 'intretinere';
$defaultRecord = [
    'id' => '', 'vehicle_id' => '', 'tip_interventie' => '', 'data_interventie' => date('Y-m-d'),
    'km_interventie' => '', 'centru_cost' => '', 'descriere' => '', 'status_interventie' => 'finalizata',
    'technical_category_id' => '', 'technical_component_id' => '', 'technical_health_percent' => '',
    'cost' => '', 'cost_manopera' => '', 'cost_piese' => '', 'zile_imobilizare' => '',
    'atelier' => '', 'furnizor_piesa' => '', 'piese_utilizate' => '', 'observatii' => '',
];
$recordForm = array_merge($defaultRecord, $editRecord ?? []);
$defaultSchedule = [
    'id' => '', 'vehicle_id' => '', 'tip_interventie' => 'intretinere', 'data_programata' => date('Y-m-d'),
    'cost_estimat' => '', 'furnizor' => '', 'driver_id' => '', 'client' => '', 'centru_cost' => '',
    'descriere' => '', 'status_interventie' => 'programata',
];
$scheduleForm = array_merge($defaultSchedule, $editIntervention ?? []);
?>

<div class="maintenance-page" data-maintenance-section="<?= e($section) ?>">
    <div class="maintenance-page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h1 class="maintenance-title mb-0"><?= $section === 'stock' ? 'Stoc Piese' : 'Mentenanță' ?></h1>
            <?php if ($section === 'interventions'): ?>
                <div class="text-muted mt-1">Planifică și urmărește intervențiile înainte de înregistrarea finală.</div>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a class="btn btn-outline-secondary maintenance-secondary-button" href="<?= e(build_query_url(array_merge($_GET, ['page' => 'mentenanta', 'action' => 'export_v2', 'section' => $section]))) ?>">
                <i class="bi bi-download me-1"></i><?= $section === 'stock' ? 'Exportă' : 'Export CSV' ?>
            </a>
            <?php if ($section === 'stock'): ?>
                <button class="btn btn-primary maintenance-primary-button" type="button" data-bs-toggle="modal" data-bs-target="#maintenancePartModal">
                    <i class="bi bi-plus-lg me-1"></i>Adaugă piesă
                </button>
            <?php elseif ($section === 'interventions'): ?>
                <button class="btn btn-primary maintenance-primary-button" type="button" data-bs-toggle="modal" data-bs-target="#maintenanceScheduleModal">
                    <i class="bi bi-plus-lg me-1"></i>Adaugă intervenție
                </button>
            <?php else: ?>
                <div class="btn-group">
                    <button class="btn btn-primary maintenance-primary-button" type="button" data-bs-toggle="modal" data-bs-target="#maintenanceScheduleModal">
                        <i class="bi bi-plus-lg me-1"></i>Adaugă intervenție
                    </button>
                    <button class="btn btn-primary dropdown-toggle dropdown-toggle-split maintenance-primary-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="visually-hidden">Alege tipul</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#maintenanceScheduleModal">Planifică intervenție</button></li>
                        <li><a class="dropdown-item" href="<?= e($sectionUrl('maintenance')) ?>#maintenance-records">Adaugă întreținere</a></li>
                        <li><a class="dropdown-item" href="<?= e($sectionUrl('repairs')) ?>#maintenance-records">Adaugă reparație</a></li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <nav class="maintenance-tabs" aria-label="Secțiuni mentenanță">
        <?php foreach ([
            'overview' => 'Prezentare generală',
            'maintenance' => 'Întreținere',
            'repairs' => 'Reparații',
            'stock' => 'Stoc',
        ] as $tabKey => $tabLabel): ?>
            <a class="maintenance-tab <?= $section === $tabKey ? 'active' : '' ?>" href="<?= e($sectionUrl($tabKey)) ?>"><?= e($tabLabel) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($section === 'overview'): ?>
        <?php
        $kpis = is_array($overview['kpis'] ?? null) ? $overview['kpis'] : [];
        $totalCost = (float) ($kpis['cost_total'] ?? 0);
        $totalInterventions = (int) ($kpis['total_interventii'] ?? 0);
        $vehicleCount = (int) ($kpis['vehicule_intervenite'] ?? 0);
        $fleetCount = (int) ($kpis['total_vehicule'] ?? 0);
        $averageDowntime = (float) ($kpis['timp_mediu'] ?? 0);
        $waiting = (int) ($kpis['in_asteptare'] ?? 0);
        $costByType = is_array($overview['cost_by_type'] ?? null) ? $overview['cost_by_type'] : [];
        $maintenanceCost = (float) ($costByType['intretinere'] ?? 0);
        $repairCost = (float) ($costByType['reparatie'] ?? 0);
        $chartTotal = max(0.01, $maintenanceCost + $repairCost);
        $maintenancePercent = (int) round(($maintenanceCost / $chartTotal) * 100);
        ?>

        <form class="maintenance-filter-card" method="get">
            <input type="hidden" name="page" value="mentenanta">
            <input type="hidden" name="action" value="overview">
            <div class="maintenance-filter-field maintenance-period-field">
                <label>Perioadă</label>
                <div class="maintenance-date-range">
                    <input class="form-control" type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
                    <span>–</span>
                    <input class="form-control" type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
                </div>
            </div>
            <div class="maintenance-filter-field">
                <label for="maintenance-filter-vehicle">Vehicul</label>
                <select class="form-select" id="maintenance-filter-vehicle" name="vehicle_id">
                    <option value="">Toate</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= e((string) $vehicle['id']) ?>" <?= (int) ($filters['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="maintenance-filter-field">
                <label for="maintenance-filter-type">Tip intervenție</label>
                <select class="form-select" id="maintenance-filter-type" name="record_type">
                    <option value="">Toate</option>
                    <option value="intretinere" <?= ($filters['record_type'] ?? '') === 'intretinere' ? 'selected' : '' ?>>Întreținere</option>
                    <option value="reparatie" <?= ($filters['record_type'] ?? '') === 'reparatie' ? 'selected' : '' ?>>Reparații</option>
                </select>
            </div>
            <div class="maintenance-filter-field">
                <label for="maintenance-filter-center">Centru de cost</label>
                <select class="form-select" id="maintenance-filter-center" name="centru_cost">
                    <option value="">Toate</option>
                    <?php foreach ($costCenters as $center): ?>
                        <option value="<?= e((string) $center) ?>" <?= ($filters['centru_cost'] ?? '') === $center ? 'selected' : '' ?>><?= e((string) $center) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="maintenance-filter-actions">
                <button class="btn btn-primary maintenance-primary-button" type="submit">Filtrează</button>
                <a class="btn btn-outline-secondary maintenance-secondary-button" href="<?= e($sectionUrl('overview')) ?>">Resetează</a>
            </div>
        </form>

        <div class="maintenance-kpi-grid">
            <?php foreach ([
                ['icon' => 'bi-wrench-adjustable', 'tone' => 'blue', 'title' => 'Total intervenții', 'value' => (string) $totalInterventions, 'note' => 'în perioada selectată', 'trend' => '↗ 15%', 'trend_note' => 'față de perioada anterioară'],
                ['icon' => 'bi-cash-stack', 'tone' => 'green', 'title' => 'Cost total', 'value' => $currency($totalCost), 'note' => 'în perioada selectată', 'trend' => '↗ 12%', 'trend_note' => 'față de perioada anterioară'],
                ['icon' => 'bi-truck', 'tone' => 'purple', 'title' => 'Vehicule intervenite', 'value' => (string) $vehicleCount, 'note' => 'din ' . $fleetCount . ' vehicule', 'trend' => '↗ 8%', 'trend_note' => 'față de perioada anterioară'],
                ['icon' => 'bi-clock-fill', 'tone' => 'orange', 'title' => 'Timp mediu imobilizare', 'value' => format_number_ro($averageDowntime, 1) . ' zile', 'note' => 'per intervenție', 'trend' => '↘ 3%', 'trend_note' => 'față de perioada anterioară'],
                ['icon' => 'bi-receipt-cutoff', 'tone' => 'cyan', 'title' => 'În așteptare', 'value' => (string) $waiting, 'note' => 'intervenții', 'trend' => '!', 'trend_note' => 'neconfirmate'],
            ] as $kpi): ?>
                <article class="maintenance-kpi-card tone-<?= e($kpi['tone']) ?>">
                    <div class="maintenance-kpi-main">
                        <span class="maintenance-kpi-icon"><i class="bi <?= e($kpi['icon']) ?>"></i></span>
                        <div>
                            <div class="maintenance-kpi-title"><?= e($kpi['title']) ?></div>
                            <div class="maintenance-kpi-value"><?= e($kpi['value']) ?></div>
                            <div class="maintenance-kpi-note"><?= e($kpi['note']) ?></div>
                        </div>
                    </div>
                    <div class="maintenance-kpi-trend"><span><?= e($kpi['trend']) ?></span><?= e($kpi['trend_note']) ?></div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="maintenance-chart-grid">
            <article class="maintenance-card maintenance-chart-card">
                <h2>Costuri pe tip intervenție</h2>
                <div class="maintenance-donut-wrap">
                    <div class="maintenance-donut" style="--maintenance-percent: <?= e((string) $maintenancePercent) ?>%"><span><?= e((string) $maintenancePercent) ?>%</span></div>
                    <div class="maintenance-chart-legend">
                        <div><span class="legend-dot blue"></span><strong>Întreținere</strong><small><?= e($currency($maintenanceCost)) ?> (<?= e((string) $maintenancePercent) ?>%)</small></div>
                        <div><span class="legend-dot red"></span><strong>Reparații</strong><small><?= e($currency($repairCost)) ?> (<?= e((string) (100 - $maintenancePercent)) ?>%)</small></div>
                    </div>
                </div>
            </article>

            <?php
            $renderBars = static function (array $items, string $emptyText = 'Nu există date pentru perioada selectată.'): void {
                $maxValue = 0.0;
                foreach ($items as $item) {
                    $maxValue = max($maxValue, (float) ($item['total'] ?? 0));
                }
                if ($items === []) {
                    echo '<div class="maintenance-empty-chart">' . e($emptyText) . '</div>';
                    return;
                }
                foreach ($items as $item) {
                    $value = (float) ($item['total'] ?? 0);
                    $width = $maxValue > 0 ? max(4, ($value / $maxValue) * 100) : 4;
                    echo '<div class="maintenance-bar-row">';
                    echo '<span class="maintenance-bar-label">' . e((string) ($item['label'] ?? '-')) . '</span>';
                    echo '<div class="maintenance-bar-track"><span style="width:' . e(number_format($width, 2, '.', '')) . '%"></span></div>';
                    echo '<strong>' . e(format_number_ro($value, 2)) . ' lei</strong>';
                    echo '</div>';
                }
            };
            ?>
            <article class="maintenance-card maintenance-chart-card">
                <h2>Costuri pe centru de cost (Top 5)</h2>
                <div class="maintenance-bars"><?php $renderBars((array) ($overview['cost_centers'] ?? [])); ?></div>
                <a class="maintenance-card-link" href="<?= e($sectionUrl('repairs')) ?>">Vezi toate <i class="bi bi-arrow-right"></i></a>
            </article>
            <article class="maintenance-card maintenance-chart-card">
                <h2>Costuri pe vehicul (Top 5)</h2>
                <div class="maintenance-bars"><?php $renderBars((array) ($overview['vehicle_costs'] ?? [])); ?></div>
                <?php if (!empty($overview['ensemble_costs'])): ?>
                    <div class="maintenance-ensemble-note"><i class="bi bi-link-45deg"></i> Ansambluri active, calculate dinamic</div>
                    <?php foreach ((array) $overview['ensemble_costs'] as $ensemble): ?>
                        <div class="maintenance-ensemble-row"><span><?= e((string) $ensemble['label']) ?></span><strong><?= e($currency((float) $ensemble['total'])) ?></strong></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a class="maintenance-card-link" href="<?= e($sectionUrl('maintenance')) ?>">Vezi toate <i class="bi bi-arrow-right"></i></a>
            </article>
        </div>

        <article class="maintenance-card maintenance-table-card">
            <div class="maintenance-card-heading"><h2>Intervenții recente</h2></div>
            <div class="table-responsive">
                <table class="table maintenance-table align-middle mb-0">
                    <thead><tr>
                        <th>Data intervenției</th><th>Vehicul</th><th>Tip intervenție</th><th>Centru de cost</th>
                        <th>Descriere</th><th>Furnizor</th><th>Cost (lei)</th><th>Status</th><th>Factură</th><th>Acțiuni</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ((array) ($overview['recent'] ?? []) as $row): ?>
                        <tr>
                            <td><?= e(format_date_ro((string) $row['data_interventie'])) ?></td>
                            <td><strong><?= e((string) $row['nr_inmatriculare']) ?></strong><small><?= e($vehicleTypeLabels[$row['tip_vehicul']] ?? (string) $row['tip_vehicul']) ?></small></td>
                            <td><span class="maintenance-badge <?= $row['record_type'] === 'reparatie' ? 'maintenance-badge-danger' : 'maintenance-badge-success' ?>"><?= $row['record_type'] === 'reparatie' ? 'Reparații' : 'Întreținere' ?></span></td>
                            <td><span class="maintenance-badge <?= e($centerClass((string) ($row['centru_cost'] ?? ''))) ?>"><?= e((string) ($row['centru_cost'] ?? 'Altele')) ?></span></td>
                            <td class="maintenance-description-cell"><?= e((string) ($row['descriere'] ?? $row['observatii'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['atelier'] ?? '-')) ?></td>
                            <td><strong><?= e(format_number_ro((float) $row['cost'], 2)) ?></strong></td>
                            <td><span class="maintenance-badge <?= e($statusClass((string) $row['status_interventie'])) ?>"><?= e($recordStatusLabels[$row['status_interventie']] ?? (string) $row['status_interventie']) ?></span></td>
                            <td><?= !empty($row['fisier_original']) ? '<span class="maintenance-invoice-badge">' . e((string) $row['fisier_original']) . '</span>' : '-' ?></td>
                            <td>
                                <div class="maintenance-actions">
                                    <a class="btn" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => $row['record_type'] === 'reparatie' ? 'repairs' : 'maintenance', 'view_id' => $row['id']])) ?>" aria-label="Vezi"><i class="bi bi-eye"></i></a>
                                    <a class="btn" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => $row['record_type'] === 'reparatie' ? 'repairs' : 'maintenance', 'edit_id' => $row['id']])) ?>" aria-label="Editează"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'delete_record'])) ?>">
                                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $row['id']) ?>"><input type="hidden" name="section" value="<?= $row['record_type'] === 'reparatie' ? 'repairs' : 'maintenance' ?>">
                                        <button class="btn danger" type="submit" data-confirm="Ștergi această intervenție?"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($overview['recent'])): ?><tr><td colspan="10" class="text-center text-muted py-4">Nu există intervenții pentru filtrele selectate.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <a class="maintenance-card-link mt-3" href="<?= e($sectionUrl('maintenance')) ?>">Vezi toate intervențiile <i class="bi bi-arrow-right"></i></a>
        </article>
    <?php endif; ?>

    <?php if ($section === 'interventions'): ?>
        <form class="maintenance-filter-card maintenance-compact-filter" method="get">
            <input type="hidden" name="page" value="mentenanta"><input type="hidden" name="action" value="interventions">
            <div class="maintenance-filter-field"><label>Vehicul</label><select class="form-select" name="vehicle_id"><option value="">Toate</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" <?= (int) ($filters['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare']) ?></option><?php endforeach; ?></select></div>
            <div class="maintenance-filter-field"><label>Status</label><select class="form-select" name="status"><option value="">Toate</option><?php foreach ($scheduleStatusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="maintenance-filter-actions"><button class="btn btn-primary" type="submit">Filtrează</button><a class="btn btn-outline-secondary" href="<?= e($sectionUrl('interventions')) ?>">Resetează</a></div>
        </form>
        <article class="maintenance-card maintenance-table-card">
            <div class="maintenance-card-heading"><h2>Intervenții planificate</h2><span><?= e((string) count($scheduledInterventions)) ?> înregistrări</span></div>
            <div class="table-responsive"><table class="table maintenance-table align-middle mb-0">
                <thead><tr><th>Data programată</th><th>Vehicul</th><th>Tip</th><th>Centru de cost</th><th>Descriere</th><th>Furnizor / Service</th><th>Șofer</th><th>Cost estimat</th><th>Status</th><th>Acțiuni</th></tr></thead>
                <tbody>
                <?php foreach ($scheduledInterventions as $row): ?><tr>
                    <td><?= e(format_date_ro((string) $row['data_programata'])) ?></td>
                    <td><strong><?= e((string) $row['nr_inmatriculare']) ?></strong><small><?= e($vehicleTypeLabels[$row['tip_vehicul']] ?? (string) $row['tip_vehicul']) ?></small></td>
                    <td><span class="maintenance-badge <?= $row['tip_interventie'] === 'reparatie' ? 'maintenance-badge-danger' : 'maintenance-badge-success' ?>"><?= $row['tip_interventie'] === 'reparatie' ? 'Reparație' : 'Întreținere' ?></span></td>
                    <td><span class="maintenance-badge <?= e($centerClass((string) ($row['centru_cost'] ?? ''))) ?>"><?= e((string) ($row['centru_cost'] ?? 'Altele')) ?></span></td>
                    <td class="maintenance-description-cell"><?= e((string) $row['descriere']) ?><?php if (!empty($row['client'])): ?><small>Client: <?= e((string) $row['client']) ?></small><?php endif; ?></td>
                    <td><?= e((string) ($row['furnizor'] ?? '-')) ?></td><td><?= e((string) ($row['sofer_nume'] ?? '-')) ?></td>
                    <td><?= e($currency((float) $row['cost_estimat'])) ?></td>
                    <td><span class="maintenance-badge <?= e($statusClass((string) $row['status_interventie'])) ?>"><?= e($scheduleStatusLabels[$row['status_interventie']] ?? (string) $row['status_interventie']) ?></span><?php if (!empty($row['converted_maintenance_id'])): ?><small class="d-block text-success mt-1">Convertită</small><?php endif; ?></td>
                    <td><div class="maintenance-actions"><a class="btn" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'edit_intervention_id' => $row['id']])) ?>"><i class="bi bi-pencil"></i></a><form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'delete_intervention'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $row['id']) ?>"><button class="btn danger" type="submit" data-confirm="Ștergi intervenția programată?"><i class="bi bi-trash"></i></button></form></div></td>
                </tr><?php endforeach; ?>
                <?php if ($scheduledInterventions === []): ?><tr><td colspan="10" class="text-center text-muted py-5">Nu există intervenții planificate pentru filtrele selectate.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </article>
    <?php endif; ?>

    <?php if (in_array($section, ['maintenance', 'repairs'], true)): ?>
        <div class="maintenance-section-toolbar" id="maintenance-records">
            <div><h2><?= $section === 'maintenance' ? 'Întreținere preventivă' : 'Reparații corective' ?></h2><p><?= $section === 'maintenance' ? 'Revizii și lucrări preventive, separate de reparațiile după defect.' : 'Defecțiuni și reparații corective, separate de întreținerea periodică.' ?></p></div>
            <button class="btn btn-primary maintenance-primary-button" type="button" data-bs-toggle="modal" data-bs-target="#maintenanceRecordModal"><i class="bi bi-plus-lg me-1"></i><?= $section === 'maintenance' ? 'Adaugă întreținere' : 'Adaugă reparație' ?></button>
        </div>
        <article class="maintenance-card maintenance-table-card">
            <div class="table-responsive"><table class="table maintenance-table align-middle mb-0">
                <thead><tr>
                    <?php if ($section === 'maintenance'): ?>
                        <th>Data</th><th>Vehicul</th><th>Tip vehicul</th><th>Km</th><th>Tip întreținere</th><th>Centru de cost</th><th>Piese utilizate</th><th>Furnizor</th><th>Cost total</th><th>Factură</th><th>Status</th><th>Acțiuni</th>
                    <?php else: ?>
                        <th>Data</th><th>Vehicul</th><th>Tip vehicul</th><th>Defecțiune</th><th>Centru de cost</th><th>Piese utilizate</th><th>Furnizor manoperă</th><th>Furnizor piesă</th><th>Cost manoperă</th><th>Cost piese</th><th>Cost total</th><th>Factură</th><th>Status</th><th>Acțiuni</th>
                    <?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($records as $row): ?><tr>
                    <td><?= e(format_date_ro((string) $row['data_interventie'])) ?></td>
                    <td><strong><?= e((string) $row['nr_inmatriculare']) ?></strong></td>
                    <td><?= e($vehicleTypeLabels[$row['tip_vehicul']] ?? (string) $row['tip_vehicul']) ?></td>
                    <?php if ($section === 'maintenance'): ?>
                        <td><?= !empty($row['km_interventie']) ? e(format_number_ro((float) $row['km_interventie'], 0)) : '-' ?></td>
                        <td><?= e((string) $row['tip_interventie']) ?></td>
                        <td><span class="maintenance-badge <?= e($centerClass((string) ($row['centru_cost'] ?? ''))) ?>"><?= e((string) ($row['centru_cost'] ?? 'Altele')) ?></span></td>
                        <td><?= e((string) ($row['piese_utilizate'] ?? '-')) ?></td><td><?= e((string) ($row['atelier'] ?? '-')) ?></td>
                        <td><strong><?= e($currency((float) $row['cost'])) ?></strong></td>
                    <?php else: ?>
                        <td class="maintenance-description-cell"><?= e((string) ($row['descriere'] ?? $row['tip_interventie'])) ?></td>
                        <td><span class="maintenance-badge <?= e($centerClass((string) ($row['centru_cost'] ?? ''))) ?>"><?= e((string) ($row['centru_cost'] ?? 'Altele')) ?></span></td>
                        <td><?= e((string) ($row['piese_utilizate'] ?? '-')) ?></td><td><?= e((string) ($row['atelier'] ?? '-')) ?></td><td><?= e((string) ($row['furnizor_piesa'] ?? '-')) ?></td>
                        <td><?= e($currency((float) $row['cost_manopera'])) ?></td><td><?= e($currency((float) $row['cost_piese'])) ?></td><td><strong><?= e($currency((float) $row['cost'])) ?></strong></td>
                    <?php endif; ?>
                    <td><?= !empty($row['fisier_original']) ? '<span class="maintenance-invoice-badge">' . e((string) $row['fisier_original']) . '</span>' : '-' ?></td>
                    <td><span class="maintenance-badge <?= e($statusClass((string) $row['status_interventie'])) ?>"><?= e($recordStatusLabels[$row['status_interventie']] ?? (string) $row['status_interventie']) ?></span></td>
                    <td><div class="maintenance-actions"><a class="btn" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => $section, 'view_id' => $row['id']])) ?>"><i class="bi bi-eye"></i></a><a class="btn" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => $section, 'edit_id' => $row['id']])) ?>"><i class="bi bi-pencil"></i></a><form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'delete_record'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) $row['id']) ?>"><input type="hidden" name="section" value="<?= e($section) ?>"><button class="btn danger" type="submit" data-confirm="Ștergi această înregistrare?"><i class="bi bi-trash"></i></button></form></div></td>
                </tr><?php endforeach; ?>
                <?php if ($records === []): ?><tr><td colspan="14" class="text-center text-muted py-5">Nu există înregistrări în această secțiune.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </article>
    <?php endif; ?>

    <?php if ($section === 'stock'): ?>
        <div class="maintenance-kpi-grid maintenance-stock-kpis">
            <?php foreach ([
                ['icon' => 'bi-cash-stack', 'tone' => 'green', 'title' => 'Valoare totală stoc', 'value' => $currency((float) ($stockKpis['valoare_totala'] ?? 0)), 'note' => 'valoare de achiziție'],
                ['icon' => 'bi-box-seam', 'tone' => 'blue', 'title' => 'Total piese', 'value' => (string) ((int) ($stockKpis['total_piese'] ?? 0)), 'note' => 'repere în catalog'],
                ['icon' => 'bi-exclamation-triangle', 'tone' => 'orange', 'title' => 'Piese sub stoc minim', 'value' => (string) ((int) ($stockKpis['sub_minim'] ?? 0)), 'note' => 'necesită aprovizionare'],
                ['icon' => 'bi-hourglass-split', 'tone' => 'purple', 'title' => 'Piese fără mișcare', 'value' => (string) ((int) ($stockKpis['fara_miscare'] ?? 0)), 'note' => 'în ultimele 180 zile'],
            ] as $kpi): ?><article class="maintenance-kpi-card tone-<?= e($kpi['tone']) ?>"><div class="maintenance-kpi-main"><span class="maintenance-kpi-icon"><i class="bi <?= e($kpi['icon']) ?>"></i></span><div><div class="maintenance-kpi-title"><?= e($kpi['title']) ?></div><div class="maintenance-kpi-value"><?= e($kpi['value']) ?></div><div class="maintenance-kpi-note"><?= e($kpi['note']) ?></div></div></div></article><?php endforeach; ?>
        </div>
        <form class="maintenance-filter-card maintenance-stock-filter" method="get">
            <input type="hidden" name="page" value="mentenanta"><input type="hidden" name="action" value="stock">
            <div class="maintenance-filter-field maintenance-search-field"><label>Căutare</label><input class="form-control" type="search" name="search" value="<?= e((string) ($filters['search'] ?? '')) ?>" placeholder="Cod, denumire sau OEM"></div>
            <div class="maintenance-filter-field"><label>Categorie</label><select class="form-select" name="categorie"><option value="">Toate</option><?php foreach ((array) ($stockFilterOptions['categories'] ?? []) as $option): ?><option value="<?= e((string) $option) ?>" <?= ($filters['categorie'] ?? '') === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option><?php endforeach; ?></select></div>
            <div class="maintenance-filter-field"><label>Tip vehicul</label><select class="form-select" name="vehicle_type"><option value="">Toate</option><?php foreach (['cap_tractor' => 'Cap Tractor', 'semiremorca' => 'Semiremorcă', 'camion' => 'Camion', 'autoutilitara' => 'Autoutilitară'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($filters['vehicle_type'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="maintenance-filter-field"><label>Status stoc</label><select class="form-select" name="stock_status"><option value="">Toate</option><option value="in_stock" <?= ($filters['stock_status'] ?? '') === 'in_stock' ? 'selected' : '' ?>>În stoc</option><option value="low" <?= ($filters['stock_status'] ?? '') === 'low' ? 'selected' : '' ?>>Stoc scăzut</option><option value="out" <?= ($filters['stock_status'] ?? '') === 'out' ? 'selected' : '' ?>>Stoc epuizat</option></select></div>
            <div class="maintenance-filter-field"><label>Furnizor</label><select class="form-select" name="furnizor"><option value="">Toți</option><?php foreach ((array) ($stockFilterOptions['suppliers'] ?? []) as $option): ?><option value="<?= e((string) $option) ?>" <?= ($filters['furnizor'] ?? '') === $option ? 'selected' : '' ?>><?= e((string) $option) ?></option><?php endforeach; ?></select></div>
            <div class="maintenance-filter-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Mai multe filtre</button><a class="btn btn-outline-secondary" href="<?= e($sectionUrl('stock')) ?>">Resetează</a></div>
        </form>
        <?php if ($availableTireCount > 0): ?>
            <div class="maintenance-stock-sync-note">
                <span><i class="bi bi-life-preserver"></i><strong><?= e((string) $availableTireCount) ?> anvelope disponibile</strong> sunt sincronizate automat din modulul Anvelope.</span>
                <a href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock'])) ?>">Gestionează toate anvelopele <i class="bi bi-arrow-right"></i></a>
            </div>
        <?php endif; ?>
        <article class="maintenance-card maintenance-table-card"><div class="table-responsive"><table class="table maintenance-table align-middle mb-0">
            <thead><tr><th>Cod piesă</th><th>Denumire piesă</th><th>Categorie</th><th>Tip vehicul compatibil</th><th>Stoc curent</th><th>Stoc minim</th><th>Preț achiziție</th><th>Valoare stoc</th><th>Furnizor</th><th>Stare</th><th>Garantie</th><th>Acțiuni</th></tr></thead>
            <tbody><?php foreach ($parts as $part): ?><tr>
                <td><strong><?= e((string) $part['cod_piesa']) ?></strong><?php if (!empty($part['cod_oem'])): ?><small>OEM / DOT: <?= e((string) $part['cod_oem']) ?></small><?php endif; ?></td>
                <td><?= e((string) $part['denumire']) ?><?php if (($part['inventory_type'] ?? '') === 'tire'): ?><small><i class="bi bi-arrow-repeat"></i> Sincronizată din Anvelope</small><?php endif; ?></td><td><?= e((string) $part['categorie']) ?></td><td><?= e(str_replace(',', ', ', (string) ($part['tipuri_vehicul'] ?? '-'))) ?></td>
                <td><strong><?= e(format_number_ro((float) $part['stoc_curent'], 2)) ?> <?= e((string) $part['unitate_masura']) ?></strong></td><td><?= e(format_number_ro((float) $part['stoc_minim'], 2)) ?></td>
                <td><?= e($currency((float) $part['pret_achizitie'])) ?></td><td><strong><?= e($currency((float) $part['valoare_stoc'])) ?></strong></td><td><?= trim((string) ($part['furnizor'] ?? '')) !== '' ? e((string) $part['furnizor']) : '-' ?></td>
                <td><span class="maintenance-badge <?= $part['stock_status'] === 'in_stock' ? 'maintenance-badge-success' : ($part['stock_status'] === 'low' ? 'maintenance-badge-warning' : 'maintenance-badge-danger') ?>"><?= $part['stock_status'] === 'in_stock' ? 'În stoc' : ($part['stock_status'] === 'low' ? 'Stoc scăzut' : 'Stoc epuizat') ?></span></td>
                <td><span class="maintenance-badge <?= e($warrantyBadgeClass((string) ($part['warranty_status'] ?? 'red'))) ?>"><?= e((string) ($part['warranty_label'] ?? 'Fara garantie')) ?></span></td>
                <td><div class="maintenance-actions"><button class="btn" type="button" data-bs-toggle="modal" data-bs-target="#partDetail<?= e((string) $part['id']) ?>"><i class="bi bi-eye"></i></button><?php if (($part['inventory_type'] ?? '') === 'tire'): ?><a class="btn" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_stock'])) ?>" title="Deschide Anvelope"><i class="bi bi-life-preserver"></i></a><?php endif; ?></div></td>
            </tr><?php endforeach; ?><?php if ($parts === []): ?><tr><td colspan="12" class="text-center text-muted py-5">Nu există piese pentru filtrele selectate.</td></tr><?php endif; ?></tbody>
        </table></div></article>
        <?php foreach ($parts as $part): ?><div class="modal fade" id="partDetail<?= e((string) $part['id']) ?>" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content maintenance-modal"><div class="modal-header"><h2 class="modal-title fs-5"><?= e((string) $part['denumire']) ?></h2><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="maintenance-detail-grid"><div><span>Cod piesă</span><strong><?= e((string) $part['cod_piesa']) ?></strong></div><div><span>Categorie</span><strong><?= e((string) $part['categorie']) ?></strong></div><div><span>Stoc</span><strong><?= e(format_number_ro((float) $part['stoc_curent'], 2)) ?> <?= e((string) $part['unitate_masura']) ?></strong></div><div><span>Locație</span><strong><?= e((string) ($part['locatie_depozit'] ?? '-')) ?></strong></div><div><span>Compatibilitate</span><strong><?= e(str_replace(',', ', ', (string) ($part['modele_vehicul'] ?? '-'))) ?></strong></div><div><span>Sisteme</span><strong><?= e(str_replace(',', ', ', (string) ($part['sisteme_componente'] ?? '-'))) ?></strong></div><div><span>Garantie</span><strong><span class="maintenance-badge <?= e($warrantyBadgeClass((string) ($part['warranty_status'] ?? 'red'))) ?>"><?= e((string) ($part['warranty_label'] ?? 'Fara garantie')) ?></span></strong></div><div><span>Garantie piesa</span><strong><?= trim((string) ($part['garantie_piesa'] ?? '')) !== '' ? e((string) $part['garantie_piesa']) : '-' ?></strong></div><div><span>Garantie manopera</span><strong><?= trim((string) ($part['garantie_manopera'] ?? '')) !== '' ? e((string) $part['garantie_manopera']) : '-' ?></strong></div></div></div></div></div></div><?php endforeach; ?>
    <?php endif; ?>

</div>

<?php if ($section !== 'stock'): ?>
<div class="modal fade" id="maintenanceScheduleModal" tabindex="-1" data-auto-open="<?= $editIntervention !== null ? '1' : '0' ?>">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content maintenance-modal">
        <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'save_intervention'])) ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) ($scheduleForm['id'] ?? '')) ?>">
            <div class="modal-header"><div><h2 class="modal-title fs-5"><?= $editIntervention !== null ? 'Editează intervenția' : 'Adaugă intervenție' ?></h2><p>Planificare înainte de înregistrarea finală.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-md-6"><label class="form-label">Vehicul <span>*</span></label><select class="form-select" name="vehicle_id" data-maintenance-vehicle-select required><option value="">Selectează vehiculul</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" data-vehicle-type="<?= e((string) ($vehicle['tip_vehicul'] ?? '')) ?>" <?= (int) ($scheduleForm['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Tip intervenție <span>*</span></label><select class="form-select" name="tip_interventie"><option value="intretinere" <?= ($scheduleForm['tip_interventie'] ?? '') === 'intretinere' ? 'selected' : '' ?>>Întreținere</option><option value="reparatie" <?= ($scheduleForm['tip_interventie'] ?? '') === 'reparatie' ? 'selected' : '' ?>>Reparație</option></select></div>
                <div class="col-md-6"><label class="form-label">Data programată <span>*</span></label><input class="form-control" type="date" name="data_programata" value="<?= e((string) $scheduleForm['data_programata']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Cost estimat</label><input class="form-control" type="number" name="cost_estimat" min="0" step="0.01" value="<?= e((string) $scheduleForm['cost_estimat']) ?>"></div>
                <div class="col-md-6"><label class="form-label">Furnizor / Service</label><input class="form-control" name="furnizor" value="<?= e((string) ($scheduleForm['furnizor'] ?? '')) ?>"></div>
                <div class="col-md-6"><label class="form-label">Șofer</label><select class="form-select" name="driver_id"><option value="">Fără șofer</option><?php foreach ($drivers as $driver): ?><option value="<?= e((string) $driver['id']) ?>" <?= (int) ($scheduleForm['driver_id'] ?? 0) === (int) $driver['id'] ? 'selected' : '' ?>><?= e((string) $driver['nume']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Client (opțional)</label><input class="form-control" name="client" value="<?= e((string) ($scheduleForm['client'] ?? '')) ?>"></div>
                <div class="col-md-6"><label class="form-label">Grupa componenta</label><select class="form-select" name="centru_cost" data-maintenance-cost-center-select><?php $renderCostCenterOptions($costCenterOptions, (string) ($scheduleForm['centru_cost'] ?? ''), true); ?></select></div>
                <div class="col-12"><label class="form-label">Descriere <span>*</span></label><textarea class="form-control" name="descriere" rows="3" required><?= e((string) $scheduleForm['descriere']) ?></textarea></div>
                <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status_interventie"><?php foreach ($scheduleStatusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($scheduleForm['status_interventie'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><div class="form-text">La „Finalizată”, intervenția este convertită automat în Întreținere sau Reparație.</div></div>
            </div></div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Anulează</button><button class="btn btn-primary" type="submit">Salvează intervenția</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if (in_array($section, ['maintenance', 'repairs'], true)): ?>
<div class="modal fade" id="maintenanceRecordModal" tabindex="-1" data-auto-open="<?= $editRecord !== null ? '1' : '0' ?>">
    <div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content maintenance-modal"><form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'save_record'])) ?>">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) ($recordForm['id'] ?? '')) ?>"><input type="hidden" name="record_type" value="<?= e($recordModalType) ?>">
        <div class="modal-header"><div><h2 class="modal-title fs-5"><?= $editRecord !== null ? 'Editează' : 'Adaugă' ?> <?= $recordModalType === 'reparatie' ? 'reparație' : 'întreținere' ?></h2><p>Costul este atribuit exclusiv vehiculului selectat.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-md-4"><label class="form-label">Vehicul <span>*</span></label><select class="form-select" name="vehicle_id" data-maintenance-vehicle-select required><option value="">Selectează</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" data-vehicle-type="<?= e((string) ($vehicle['tip_vehicul'] ?? '')) ?>" <?= (int) ($recordForm['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label"><?= $recordModalType === 'reparatie' ? 'Defecțiune' : 'Tip întreținere' ?> <span>*</span></label><input class="form-control" name="tip_interventie" value="<?= e((string) $recordForm['tip_interventie']) ?>" placeholder="<?= $recordModalType === 'reparatie' ? 'Ex: Alternator defect' : 'Ex: Schimb ulei și filtre' ?>" required></div>
            <div class="col-md-4"><label class="form-label">Data <span>*</span></label><input class="form-control" type="date" name="data_interventie" value="<?= e((string) $recordForm['data_interventie']) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Km</label><input class="form-control" type="number" name="km_interventie" min="0" value="<?= e((string) ($recordForm['km_interventie'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label">Grupa componenta</label><select class="form-select" name="centru_cost" data-maintenance-cost-center-select><?php $renderCostCenterOptions($costCenterOptions, (string) ($recordForm['centru_cost'] ?? ''), true); ?></select></div>
            <div class="col-md-3"><label class="form-label">Categorie tehnic&#259;</label><select class="form-select" name="technical_category_id" data-technical-category-select><option value="">F&#259;r&#259; categorie tehnic&#259;</option><?php foreach ($technicalCategories as $technicalCategory): ?><option value="<?= e((string) $technicalCategory['id']) ?>" <?= (int) ($recordForm['technical_category_id'] ?? 0) === (int) $technicalCategory['id'] ? 'selected' : '' ?>><?= e((string) ($technicalCategory['sort_order'] ?? '') . '. ' . (string) ($technicalCategory['name'] ?? '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Component&#259; tehnic&#259;</label><select class="form-select" name="technical_component_id" data-technical-component-select><option value="">Categorie intreaga</option><?php foreach ($technicalComponentsByCategory as $technicalCategoryId => $technicalComponents): ?><?php foreach ((array) $technicalComponents as $technicalComponent): ?><option value="<?= e((string) $technicalComponent['id']) ?>" data-category-id="<?= e((string) $technicalCategoryId) ?>" <?= (int) ($recordForm['technical_component_id'] ?? 0) === (int) $technicalComponent['id'] ? 'selected' : '' ?>><?= e((string) ($technicalComponent['name'] ?? '')) ?></option><?php endforeach; ?><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Stare dup&#259; interven&#539;ie (%)</label><input class="form-control" type="number" name="technical_health_percent" min="0" max="100" value="<?= e((string) ($recordForm['technical_health_percent'] ?? '')) ?>" placeholder="Ex: 92"></div>
            <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status_interventie"><?php foreach ($recordStatusLabels as $value => $label): ?><option value="<?= e($value) ?>" <?= ($recordForm['status_interventie'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label">Zile imobilizare</label><input class="form-control" type="number" name="zile_imobilizare" min="0" step="0.1" value="<?= e((string) ($recordForm['zile_imobilizare'] ?? '')) ?>"></div>
            <div class="col-12"><label class="form-label">Descriere</label><textarea class="form-control" name="descriere" rows="2"><?= e((string) ($recordForm['descriere'] ?? '')) ?></textarea></div>
            <div class="col-md-4"><label class="form-label">Piese utilizate</label><input class="form-control" name="piese_utilizate" value="<?= e((string) ($recordForm['piese_utilizate'] ?? '')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Piesă din stoc</label><select class="form-select" name="stock_part_id" <?= $editRecord !== null ? 'disabled' : '' ?>><option value="">Fără piesă din stoc</option><?php foreach ($availableStockParts as $part): ?><option value="<?= e((string) $part['id']) ?>"><?= e((string) $part['cod_piesa'] . ' - ' . (string) $part['denumire'] . ' (' . format_number_ro((float) $part['stoc_curent'], 2) . ')') ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Cantitate din stoc</label><input class="form-control" type="number" name="stock_part_quantity" min="0" step="0.01" <?= $editRecord !== null ? 'disabled' : '' ?>></div>
            <div class="col-md-4"><label class="form-label">Furnizor manoperă</label><input class="form-control" name="atelier" value="<?= e((string) ($recordForm['atelier'] ?? '')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Furnizor piesă</label><input class="form-control" name="furnizor_piesa" value="<?= e((string) ($recordForm['furnizor_piesa'] ?? '')) ?>"></div>
            <div class="col-md-4"><label class="form-label">Factură</label><input class="form-control" type="file" name="invoice_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></div>
            <?php if ($recordModalType === 'reparatie'): ?>
                <div class="col-md-4"><label class="form-label">Cost manoperă</label><input class="form-control maintenance-cost-input" type="number" name="cost_manopera" min="0" step="0.01" value="<?= e((string) ($recordForm['cost_manopera'] ?? '')) ?>"></div>
                <div class="col-md-4"><label class="form-label">Cost piese</label><input class="form-control maintenance-cost-input" type="number" name="cost_piese" min="0" step="0.01" value="<?= e((string) ($recordForm['cost_piese'] ?? '')) ?>"></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label">Cost total</label><input class="form-control" type="number" name="cost" min="0" step="0.01" value="<?= e((string) ($recordForm['cost'] ?? '')) ?>"></div>
            <div class="col-12"><label class="form-label">Observații</label><textarea class="form-control" name="observatii" rows="2"><?= e((string) ($recordForm['observatii'] ?? '')) ?></textarea></div>
        </div></div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Anulează</button><button class="btn btn-primary" type="submit">Salvează</button></div>
    </form></div></div>
</div>
<?php endif; ?>

<?php if ($section === 'stock'): ?>
<div class="modal fade" id="maintenancePartModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content maintenance-modal"><form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'save_part'])) ?>">
    <?= csrf_field() ?>
    <div class="modal-header"><div><h2 class="modal-title fs-5">Adaugă piesă</h2><p>Adaugă în stoc sau montează direct pe vehicul.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <section class="maintenance-form-section"><h3>Unde va fi utilizată piesa?</h3><div class="maintenance-choice-grid"><label class="maintenance-choice-card active"><input type="radio" name="usage_destination" value="stock" checked><span><i class="bi bi-box-seam"></i><strong>Adaugă în stoc</strong><small>Crește cantitatea disponibilă.</small></span></label><label class="maintenance-choice-card"><input type="radio" name="usage_destination" value="direct"><span><i class="bi bi-wrench-adjustable"></i><strong>Montează direct pe vehicul</strong><small>Nu intră în stocul disponibil.</small></span></label></div></section>
        <section class="maintenance-form-section"><h3>Date generale</h3><div class="row g-3"><div class="col-md-4"><label class="form-label">Cod piesă <span>*</span></label><input class="form-control" name="cod_piesa" required></div><div class="col-md-4"><label class="form-label">Denumire piesă <span>*</span></label><input class="form-control" name="denumire" required></div><div class="col-md-4"><label class="form-label">Categorie <span>*</span></label><select class="form-select" name="categorie" required><option value="">Selectează</option><?php foreach ($configuredCostCenters as $center): ?><option value="<?= e((string) $center) ?>"><?= e((string) $center) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Producător</label><input class="form-control" name="producator"></div><div class="col-md-4"><label class="form-label">Model / Cod OEM</label><input class="form-control" name="cod_oem"></div><div class="col-md-4"><label class="form-label">Unitate de măsură</label><select class="form-select" name="unitate_masura"><option value="buc">Bucată</option><option value="litru">Litru</option><option value="set">Set</option><option value="kg">Kilogram</option></select></div><div class="col-12"><label class="form-label">Descriere</label><textarea class="form-control" name="descriere" rows="2"></textarea></div></div></section>
        <section class="maintenance-form-section" data-part-stock-fields><h3>Date stoc</h3><div class="row g-3"><div class="col-md-3"><label class="form-label">Stoc inițial</label><input class="form-control" type="number" name="stoc_initial" min="0" step="0.01"></div><div class="col-md-3"><label class="form-label">Stoc minim</label><input class="form-control" type="number" name="stoc_minim" min="0" step="0.01"></div><div class="col-md-3"><label class="form-label">Preț achiziție</label><input class="form-control" type="number" name="pret_achizitie" min="0" step="0.01"></div><div class="col-md-3"><label class="form-label">Furnizor</label><input class="form-control" name="furnizor"></div><div class="col-md-6"><label class="form-label">Locație depozit</label><input class="form-control" name="locatie_depozit"></div></div></section>
        <section class="maintenance-form-section d-none" data-part-direct-fields><h3>Date montare</h3><div class="row g-3"><div class="col-md-4"><label class="form-label">Vehicul <span>*</span></label><select class="form-select" name="mount_vehicle_id"><option value="">Selectează vehiculul</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>"><?= e((string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Data montării</label><input class="form-control" type="date" name="mount_date" value="<?= e(date('Y-m-d')) ?>"></div><div class="col-md-4"><label class="form-label">Kilometri la montare</label><input class="form-control" type="number" name="mount_km" min="0"></div><div class="col-md-4"><label class="form-label">Montată de</label><input class="form-control" name="mounted_by"></div><div class="col-md-4"><label class="form-label">Intervenție asociată</label><select class="form-select" name="scheduled_intervention_id"><option value="">Fără intervenție asociată</option><?php foreach ($scheduledInterventions as $scheduled): ?><option value="<?= e((string) $scheduled['id']) ?>"><?= e(format_date_ro((string) $scheduled['data_programata']) . ' - ' . (string) $scheduled['nr_inmatriculare'] . ' - ' . (string) $scheduled['descriere']) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Cost</label><input class="form-control" type="number" name="cost" min="0" step="0.01"></div><div class="col-12"><label class="form-label">Observații</label><textarea class="form-control" name="mount_notes" rows="2"></textarea></div></div></section>
        <section class="maintenance-form-section"><h3>Compatibilitate</h3><div class="row g-3"><div class="col-md-4"><label class="form-label">Tipuri vehicul</label><?php foreach (['cap_tractor' => 'Cap Tractor', 'semiremorca' => 'Semiremorcă', 'camion' => 'Camion', 'autoutilitara' => 'Autoutilitară'] as $value => $label): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="tipuri_vehicul[]" value="<?= e($value) ?>"><span class="form-check-label"><?= e($label) ?></span></label><?php endforeach; ?></div><div class="col-md-4"><label class="form-label">Modele vehicul</label><?php foreach (['MAN TGX','DAF XF','Volvo FH','Mercedes Actros','Scania R'] as $model): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="modele_vehicul[]" value="<?= e($model) ?>"><span class="form-check-label"><?= e($model) ?></span></label><?php endforeach; ?></div><div class="col-md-4"><label class="form-label">Sisteme / componente</label><select class="form-select" name="sisteme_componente[]" multiple size="6"><?php foreach ($configuredCostCenters as $center): ?><option value="<?= e((string) $center) ?>"><?= e((string) $center) ?></option><?php endforeach; ?></select></div></div></section>
        <section class="maintenance-form-section"><h3>Plan de mentenanță</h3><div class="row g-3"><div class="col-md-3"><label class="form-label d-block">Piesă pentru mentenanță?</label><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="pentru_mentenanta" value="1"><label class="form-check-label">Da</label></div></div><div class="col-md-2"><label class="form-label">Interval înlocuire km</label><input class="form-control" type="number" name="interval_km"></div><div class="col-md-2"><label class="form-label">Interval luni</label><input class="form-control" type="number" name="interval_luni"></div><div class="col-md-2"><label class="form-label">Avertizare km</label><input class="form-control" type="number" name="avertizare_km"></div><div class="col-md-2"><label class="form-label">Avertizare zile</label><input class="form-control" type="number" name="avertizare_zile"></div></div></section>
        <section class="maintenance-form-section"><h3>Garantii</h3><div class="row g-3"><div class="col-md-6"><label class="form-label">Garantie piesa</label><input class="form-control" name="garantie_piesa" placeholder="Ex: 12 luni / pana la 25.06.2027"></div><div class="col-md-6"><label class="form-label">Garantie manopera</label><input class="form-control" name="garantie_manopera" placeholder="Ex: 6 luni / service inclus"></div></div></section>
        <section class="maintenance-form-section"><h3>Documente și status</h3><div class="row g-3"><div class="col-md-4"><label class="form-label">Upload factură</label><input class="form-control" type="file" name="invoice_document"></div><div class="col-md-4"><label class="form-label">Upload fișă tehnică</label><input class="form-control" type="file" name="technical_document"></div><div class="col-md-4"><label class="form-label">Upload imagine piesă</label><input class="form-control" type="file" name="part_image" accept="image/*"></div><div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="status_piesa"><option value="activa">Activă</option><option value="inactiva">Inactivă</option></select></div></div></section>
    </div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Anulează</button><button class="btn btn-primary" type="submit">Salvează piesa</button></div>
</form></div></div></div>
<?php endif; ?>

<?php if ($viewRecord !== null): ?>
<div class="modal fade" id="maintenanceViewModal" tabindex="-1" data-auto-open="1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content maintenance-modal"><div class="modal-header"><h2 class="modal-title fs-5"><?= e((string) $viewRecord['tip_interventie']) ?></h2><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="maintenance-detail-grid"><div><span>Vehicul</span><strong><?= e((string) $viewRecord['nr_inmatriculare']) ?></strong></div><div><span>Data</span><strong><?= e(format_date_ro((string) $viewRecord['data_interventie'])) ?></strong></div><div><span>Centru de cost</span><strong><?= e((string) $viewRecord['centru_cost']) ?></strong></div><div><span>Cost total</span><strong><?= e($currency((float) $viewRecord['cost'])) ?></strong></div><div><span>Furnizor</span><strong><?= e((string) ($viewRecord['atelier'] ?? '-')) ?></strong></div><div><span>Status</span><strong><?= e($recordStatusLabels[$viewRecord['status_interventie']] ?? (string) $viewRecord['status_interventie']) ?></strong></div><div class="wide"><span>Descriere</span><strong><?= e((string) ($viewRecord['descriere'] ?? '-')) ?></strong></div></div></div></div></div></div>
<?php endif; ?>

<?php $maintenanceScriptVersion = (string) @filemtime(BASE_PATH . '/assets/js/maintenance.js'); ?>
<script>
(() => {
    const categorySelects = document.querySelectorAll('[data-technical-category-select]');
    categorySelects.forEach((categorySelect) => {
        const form = categorySelect.closest('form');
        const componentSelect = form ? form.querySelector('[data-technical-component-select]') : null;
        if (!componentSelect) {
            return;
        }

        const syncComponents = () => {
            const selectedCategory = categorySelect.value;
            let hasSelectedVisibleOption = false;
            Array.from(componentSelect.options).forEach((option) => {
                const categoryId = option.dataset.categoryId || '';
                const visible = option.value === '' || (selectedCategory !== '' && categoryId === selectedCategory);
                option.hidden = !visible;
                option.disabled = !visible;
                if (visible && option.selected) {
                    hasSelectedVisibleOption = true;
                }
            });
            if (!hasSelectedVisibleOption) {
                componentSelect.value = '';
            }
        };

        categorySelect.addEventListener('change', syncComponents);
        syncComponents();
    });
})();
</script>
<script src="<?= e(url('assets/js/maintenance.js?v=' . $maintenanceScriptVersion)) ?>"></script>
