<?php
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$dashboardFilters = is_array($dashboardFilters ?? null) ? $dashboardFilters : [];
$periodOptions = is_array($periodOptions ?? null) ? $periodOptions : [];
$vehicleCategoryOptions = is_array($vehicleCategoryOptions ?? null) ? $vehicleCategoryOptions : [];
$vehicleOptions = is_array($vehicleOptions ?? null) ? $vehicleOptions : [];
$approvalSummary = is_array($approvalSummary ?? null) ? $approvalSummary : [];
$canReviewInactiveApprovals = !empty($canReviewInactiveApprovals);
$showDashboardApprovalPanel = false;

$vehicleStatus = is_array($dashboard['vehicle_status'] ?? null) ? $dashboard['vehicle_status'] : [];
$driverStatus = is_array($dashboard['driver_status'] ?? null) ? $dashboard['driver_status'] : [];
$fuelCost = is_array($dashboard['fuel_cost'] ?? null) ? $dashboard['fuel_cost'] : [];
$maintenanceCost = is_array($dashboard['maintenance_cost'] ?? null) ? $dashboard['maintenance_cost'] : [];
$operationalCost = is_array($dashboard['operational_cost'] ?? null) ? $dashboard['operational_cost'] : [];
$periodRange = is_array($dashboard['period_range'] ?? null) ? $dashboard['period_range'] : ($dashboardFilters['period_range'] ?? []);

$selectedPeriod = (string) ($dashboardFilters['period'] ?? 'luna_curenta');
$selectedVehicleCategory = (string) ($dashboardFilters['vehicle_category'] ?? 'toate');
$selectedVehicleId = $dashboardFilters['vehicle_id'] ?? null;
$selectedVehicleRegistration = $dashboardFilters['vehicle_registration'] ?? null;
$selectedVehicleSearch = $selectedVehicleRegistration !== null ? (string) $selectedVehicleRegistration : null;
$periodRangeLabel = (string) ($dashboardFilters['period_range_label'] ?? '');
$vehicleCategoryLabel = (string) ($dashboardFilters['vehicle_category_label'] ?? ($vehicleCategoryOptions[$selectedVehicleCategory] ?? 'Toate'));
$vehicleLabel = (string) ($dashboardFilters['vehicle_label'] ?? 'Toate vehiculele');
$dateStart = (string) ($periodRange['date_start'] ?? '');
$dateEnd = (string) ($periodRange['date_end'] ?? '');
$operationalRows = is_array($operationalCost['rows'] ?? null) ? $operationalCost['rows'] : [];
$operationalTotal = (float) ($operationalCost['total_value'] ?? ((float) ($fuelCost['total_value'] ?? 0) + (float) ($maintenanceCost['total_value'] ?? 0)));
$fuelHasData = (float) ($fuelCost['total_value'] ?? 0) > 0 || (float) ($fuelCost['total_quantity'] ?? 0) > 0;
$maintenanceHasData = (float) ($maintenanceCost['total_value'] ?? 0) > 0;
$operationalInitiallyExpanded = isset($_GET['operational_expanded']) && (string) $_GET['operational_expanded'] === '1';
$approvalCounts = is_array($approvalSummary['counts'] ?? null) ? $approvalSummary['counts'] : ['vehicle' => 0, 'driver' => 0];
$approvalVehicleRows = is_array($approvalSummary['vehicles'] ?? null) ? $approvalSummary['vehicles'] : [];
$approvalDriverRows = is_array($approvalSummary['drivers'] ?? null) ? $approvalSummary['drivers'] : [];
$approvalTotal = (int) ($approvalSummary['total'] ?? ((int) ($approvalCounts['vehicle'] ?? 0) + (int) ($approvalCounts['driver'] ?? 0)));
$dashboardReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dashboard']));

$vehicleDetailsUrl = build_query_url([
    'page' => 'vehicule',
    'status' => 'inactiv',
    'q' => $selectedVehicleSearch,
]);
$driverDetailsUrl = build_query_url([
    'page' => 'soferi',
    'status' => 'inactiv',
    'q' => $selectedVehicleSearch,
]);
$fuelDetailsUrl = build_query_url([
    'page' => 'carburanti',
    'date_from' => $dateStart,
    'date_to' => $dateEnd,
    'vehicle' => $selectedVehicleSearch,
]);
$maintenanceDetailsUrl = build_query_url([
    'page' => 'mentenanta',
    'date_from' => $dateStart,
    'date_to' => $dateEnd,
    'vehicle_id' => $selectedVehicleId,
]);

$formatCurrency = static fn(mixed $value): string => format_number_ro((float) $value, 2) . ' lei';
$formatLiters = static function (mixed $value): string {
    $value = (float) $value;
    $decimals = abs($value - round($value)) < 0.005 ? 0 : 2;

    return format_number_ro($value, $decimals) . ' L';
};
$formatDate = static fn(mixed $value): string => trim((string) $value) !== '' ? format_date_ro((string) $value) : '-';
$formatApprovalTime = static function (mixed $value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($raw);
        $now = new DateTimeImmutable('now');
        $seconds = max(0, $now->getTimestamp() - $date->getTimestamp());
        if ($seconds < 60) {
            return 'Acum cateva secunde';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return 'Acum ' . $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24 && $date->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'Acum ' . $hours . ' ore';
        }

        if ($date->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            return 'Ieri, ' . $date->format('H:i');
        }

        return format_datetime_ro($raw);
    } catch (Throwable) {
        return format_datetime_ro($raw);
    }
};
$approvalTone = static function (string $reasonKey): string {
    if (in_array($reasonKey, ['expired_documents', 'missing_documents', 'documents_mixed'], true)) {
        return 'danger';
    }
    if (in_array($reasonKey, ['repair', 'leave', 'medical_leave'], true)) {
        return 'warning';
    }

    return 'muted';
};
$approvalIcon = static function (string $reasonKey): string {
    return match ($reasonKey) {
        'repair' => 'bi-tools',
        'leave', 'medical_leave' => 'bi-calendar2-check',
        'manual_inactive' => 'bi-slash-circle',
        'missing_documents' => 'bi-file-earmark-excel',
        default => 'bi-file-earmark-x',
    };
};
$formatApprovalContext = static function (mixed $value): string {
    $context = trim((string) $value);
    if ($context === '' || $context === 'dispecer_curse') {
        return 'Dispecer curse';
    }

    return ucfirst(str_replace('_', ' ', $context));
};
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $letters .= mb_substr($part, 0, 1, 'UTF-8');
        if (mb_strlen($letters, 'UTF-8') >= 2) {
            break;
        }
    }

    return $letters !== '' ? mb_strtoupper($letters, 'UTF-8') : 'SO';
};
$vehicleReasonUrl = static function (string $reasonKey) use ($selectedVehicleId, $selectedVehicleSearch): string {
    if ($reasonKey === 'repair') {
        return build_query_url([
            'page' => 'mentenanta',
            'action' => 'repairs',
            'status' => 'in_lucru',
            'vehicle_id' => $selectedVehicleId,
        ]);
    }

    if (in_array($reasonKey, ['expired_documents', 'missing_documents'], true)) {
        return build_query_url([
            'page' => 'documente',
            'q' => $selectedVehicleSearch,
        ]);
    }

    return build_query_url([
        'page' => 'vehicule',
        'status' => 'inactiv',
        'q' => $selectedVehicleSearch,
    ]);
};
$driverReasonUrl = static function (string $reasonKey) use ($selectedVehicleSearch): string {
    if (in_array($reasonKey, ['leave', 'medical_leave'], true)) {
        return build_query_url(['page' => 'programare_concedii']);
    }

    if (in_array($reasonKey, ['expired_documents', 'missing_documents'], true)) {
        return build_query_url(['page' => 'documente_soferi']);
    }

    return build_query_url([
        'page' => 'soferi',
        'status' => 'inactiv',
        'q' => $selectedVehicleSearch,
    ]);
};
?>

<div class="dashboard-page">
    <header class="dashboard-page-heading">
        <h1>Dashboard</h1>
        <p>Privire de ansamblu asupra flotei</p>
    </header>

    <?php if (!empty($dashboardError)): ?>
        <div class="dashboard-inline-error" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span><?= e($dashboardError) ?></span>
        </div>
    <?php endif; ?>

    <section class="dashboard-filter-panel" aria-labelledby="dashboard-filter-title">
        <h2 id="dashboard-filter-title" class="visually-hidden">Filtre dashboard</h2>
        <form class="dashboard-filter-form" method="get">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="operational_expanded" value="<?= $operationalInitiallyExpanded ? '1' : '0' ?>" data-dashboard-operational-state-input>

            <div class="dashboard-filter-group dashboard-filter-period">
                <span class="dashboard-filter-label">Perioadă</span>
                <div class="dashboard-period-options" role="group" aria-label="Perioadă dashboard">
                    <?php foreach ($periodOptions as $periodValue => $periodLabel): ?>
                        <?php $periodId = 'dashboard_period_' . preg_replace('/[^a-z0-9_]+/i', '_', (string) $periodValue); ?>
                        <input
                            class="dashboard-period-radio"
                            type="radio"
                            id="<?= e($periodId) ?>"
                            name="period"
                            value="<?= e((string) $periodValue) ?>"
                            <?= $selectedPeriod === (string) $periodValue ? 'checked' : '' ?>
                        >
                        <label class="dashboard-period-option" for="<?= e($periodId) ?>">
                            <?= e((string) $periodLabel) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dashboard-filter-group dashboard-filter-category">
                <span class="dashboard-filter-label">Categorie vehicul</span>
                <div class="dashboard-period-options" role="group" aria-label="Categorie vehicul dashboard">
                    <?php foreach ($vehicleCategoryOptions as $categoryValue => $categoryLabel): ?>
                        <?php $categoryId = 'dashboard_vehicle_category_' . preg_replace('/[^a-z0-9_]+/i', '_', (string) $categoryValue); ?>
                        <input
                            class="dashboard-period-radio"
                            type="radio"
                            id="<?= e($categoryId) ?>"
                            name="vehicle_category"
                            value="<?= e((string) $categoryValue) ?>"
                            <?= $selectedVehicleCategory === (string) $categoryValue ? 'checked' : '' ?>
                        >
                        <label class="dashboard-period-option dashboard-category-option" for="<?= e($categoryId) ?>">
                            <?= e((string) $categoryLabel) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dashboard-filter-group">
                <label class="dashboard-filter-label" for="dashboard_vehicle_id">Selectează vehicul</label>
                <select class="dashboard-select" id="dashboard_vehicle_id" name="vehicle_id">
                    <option value="">Toate vehiculele</option>
                    <?php foreach ($vehicleOptions as $vehicle): ?>
                        <?php
                        $vehicleId = (int) ($vehicle['id'] ?? 0);
                        $vehicleType = strtolower(trim((string) ($vehicle['tip_vehicul'] ?? '')));
                        $vehicleOptionCategory = in_array($vehicleType, ['autoturism', 'autovehicul', 'autoutilitara'], true) ? 'usoare' : 'grele';
                        ?>
                        <option value="<?= e((string) $vehicleId) ?>" data-vehicle-category="<?= e($vehicleOptionCategory) ?>" <?= $selectedVehicleId !== null && (int) $selectedVehicleId === $vehicleId ? 'selected' : '' ?>>
                            <?= e((string) ($vehicle['nr_inmatriculare'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="dashboard-filter-actions">
                <button class="dashboard-apply-btn" type="submit">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    <span>Aplică filtre</span>
                </button>
                <a class="dashboard-reset-btn" href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                    <span>Resetează</span>
                </a>
            </div>

            <div class="dashboard-filter-chips" aria-label="Filtre active">
                <span class="dashboard-filter-chip">
                    <i class="bi bi-calendar2-week" aria-hidden="true"></i>
                    <span>Perioadă: <?= e($periodRangeLabel) ?></span>
                </span>
                <span class="dashboard-filter-chip">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                    <span>Categorie: <?= e($vehicleCategoryLabel) ?></span>
                </span>
                <span class="dashboard-filter-chip">
                    <i class="bi bi-truck-front" aria-hidden="true"></i>
                    <span>Vehicul: <?= e($vehicleLabel) ?></span>
                </span>
            </div>
        </form>
    </section>

    <div class="dashboard-approval-layout<?= $showDashboardApprovalPanel ? ' has-approval-panel' : '' ?>">
        <div class="dashboard-approval-content">
    <section
        class="dashboard-main-grid<?= $operationalInitiallyExpanded ? ' is-operational-expanded' : '' ?>"
        aria-label="Indicatori principali dashboard"
        data-dashboard-main-grid
    >
        <article class="dashboard-metric-card dashboard-card-vehicles">
            <header class="dashboard-card-header">
                <div class="dashboard-card-title">
                    <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-truck-front"></i></span>
                    <h2>Status vehicule</h2>
                </div>
                <a class="dashboard-card-link" href="<?= e($vehicleDetailsUrl) ?>">
                    <span>Vezi detalii</span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </header>

            <div class="dashboard-stat-row">
                <div class="dashboard-stat">
                    <span>Total vehicule</span>
                    <strong class="is-blue"><?= e((string) ((int) ($vehicleStatus['total'] ?? 0))) ?></strong>
                </div>
                <div class="dashboard-stat">
                    <span>Active</span>
                    <strong class="is-green"><?= e((string) ((int) ($vehicleStatus['active'] ?? 0))) ?></strong>
                </div>
                <div class="dashboard-stat">
                    <span>Inactive</span>
                    <strong class="is-red"><?= e((string) ((int) ($vehicleStatus['inactive'] ?? 0))) ?></strong>
                </div>
            </div>

            <div class="dashboard-reason-section">
                <h3>Vehicule inactive (pe motiv)</h3>
                <div class="dashboard-reason-grid">
                    <?php foreach (($vehicleStatus['reasons'] ?? []) as $reason): ?>
                        <?php
                        $reasonCount = (int) ($reason['count'] ?? 0);
                        if ($reasonCount <= 0 && empty($reason['show_when_zero'])) {
                            continue;
                        }
                        $reasonKey = (string) ($reason['key'] ?? 'other');
                        ?>
                        <a class="dashboard-reason-item tone-<?= e((string) ($reason['tone'] ?? 'muted')) ?>" href="<?= e($vehicleReasonUrl($reasonKey)) ?>">
                            <span class="dashboard-reason-icon"><i class="bi <?= e((string) ($reason['icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i></span>
                            <span class="dashboard-reason-label"><?= e((string) ($reason['label'] ?? 'Alt motiv')) ?></span>
                            <span class="dashboard-reason-count"><?= e((string) $reasonCount) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dashboard-info-strip">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>Vehiculele programate la reparații sunt marcate automat inactive.</span>
            </div>
        </article>

        <article class="dashboard-metric-card dashboard-card-drivers">
            <header class="dashboard-card-header">
                <div class="dashboard-card-title">
                    <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-person"></i></span>
                    <h2>Status șoferi</h2>
                </div>
                <a class="dashboard-card-link" href="<?= e($driverDetailsUrl) ?>">
                    <span>Vezi detalii</span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </header>

            <div class="dashboard-stat-row">
                <div class="dashboard-stat">
                    <span>Total șoferi</span>
                    <strong class="is-blue"><?= e((string) ((int) ($driverStatus['total'] ?? 0))) ?></strong>
                </div>
                <div class="dashboard-stat">
                    <span>Activi</span>
                    <strong class="is-green"><?= e((string) ((int) ($driverStatus['active'] ?? 0))) ?></strong>
                </div>
                <div class="dashboard-stat">
                    <span>Inactivi</span>
                    <strong class="is-red"><?= e((string) ((int) ($driverStatus['inactive'] ?? 0))) ?></strong>
                </div>
            </div>

            <div class="dashboard-reason-section">
                <h3>Șoferi inactivi (pe motiv)</h3>
                <div class="dashboard-reason-grid">
                    <?php foreach (($driverStatus['reasons'] ?? []) as $reason): ?>
                        <?php
                        $reasonCount = (int) ($reason['count'] ?? 0);
                        if ($reasonCount <= 0 && empty($reason['show_when_zero'])) {
                            continue;
                        }
                        $reasonKey = (string) ($reason['key'] ?? 'other');
                        ?>
                        <a class="dashboard-reason-item tone-<?= e((string) ($reason['tone'] ?? 'muted')) ?>" href="<?= e($driverReasonUrl($reasonKey)) ?>">
                            <span class="dashboard-reason-icon"><i class="bi <?= e((string) ($reason['icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i></span>
                            <span class="dashboard-reason-label"><?= e((string) ($reason['label'] ?? 'Alt motiv')) ?></span>
                            <span class="dashboard-reason-count"><?= e((string) $reasonCount) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>

        <article
            class="dashboard-metric-card dashboard-card-operational dashboard-operational-summary-card<?= $operationalInitiallyExpanded ? ' is-expanded' : '' ?>"
            role="button"
            tabindex="0"
            aria-expanded="<?= $operationalInitiallyExpanded ? 'true' : 'false' ?>"
            aria-controls="dashboard-operational-fuel-card dashboard-operational-maintenance-card"
            data-dashboard-operational-card
            <?= $operationalInitiallyExpanded ? 'hidden' : '' ?>
        >
            <header class="dashboard-card-header dashboard-operational-header" data-dashboard-operational-header>
                <div class="dashboard-card-title">
                    <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
                    <h2>Cost total operațional</h2>
                </div>
                <div class="dashboard-operational-actions">
                    <span class="dashboard-operational-badge" data-dashboard-operational-badge>Extins</span>
                    <button
                        class="dashboard-operational-toggle"
                        type="button"
                        aria-label="<?= $operationalInitiallyExpanded ? 'Restrânge cost total operațional' : 'Extinde cost total operațional' ?>"
                        aria-expanded="<?= $operationalInitiallyExpanded ? 'true' : 'false' ?>"
                        aria-controls="dashboard-operational-fuel-card dashboard-operational-maintenance-card"
                        data-dashboard-operational-toggle
                    >
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <div class="dashboard-money-total is-orange dashboard-operational-total">
                <?= e(format_number_ro($operationalTotal, 2)) ?> <small>lei</small>
            </div>
            <p class="dashboard-card-period">Total perioadă: <?= e($periodRangeLabel) ?></p>
            <p class="dashboard-operational-hint">Click pentru detalii</p>

            <div class="dashboard-operational-summary" aria-label="Defalcare cost operațional">
                <?php foreach ($operationalRows as $row): ?>
                    <div class="dashboard-operational-summary-row">
                        <span>
                            <i class="dashboard-operational-dot tone-<?= e((string) ($row['tone'] ?? 'orange')) ?>" aria-hidden="true"></i>
                            <?= e((string) ($row['label'] ?? '')) ?>
                        </span>
                        <strong><?= e($formatCurrency($row['value'] ?? 0)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

        </article>

        <section
            class="dashboard-operational-detail-region<?= $operationalInitiallyExpanded ? ' is-visible' : '' ?>"
            aria-hidden="<?= $operationalInitiallyExpanded ? 'false' : 'true' ?>"
            data-dashboard-operational-detail-region
            <?= $operationalInitiallyExpanded ? '' : 'hidden' ?>
        >
            <button
                class="dashboard-operational-collapse"
                type="button"
                aria-label="Restrange costuri operationale"
                data-dashboard-operational-collapse
            >
                <i class="bi bi-chevron-up" aria-hidden="true"></i>
            </button>
            <div class="dashboard-operational-detail-grid">
        <article
            id="dashboard-operational-fuel-card"
            class="dashboard-metric-card dashboard-card-fuel dashboard-operational-sibling-card"
            data-dashboard-operational-detail-card
        >
            <header class="dashboard-card-header">
                <div class="dashboard-card-title">
                    <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-fuel-pump"></i></span>
                    <h2>Cost total carburant</h2>
                </div>
                <a class="dashboard-card-link" href="<?= e($fuelDetailsUrl) ?>">
                    <span>Vezi detalii</span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </header>

            <div class="dashboard-money-total is-orange">
                <?= e(format_number_ro((float) ($fuelCost['total_value'] ?? 0), 2)) ?> <small>lei</small>
            </div>
            <p class="dashboard-card-period">Total perioad&#259;: <?= e($periodRangeLabel) ?></p>

            <?php if (!$fuelHasData): ?>
                <p class="dashboard-operational-empty">Nu exist&#259; aliment&#259;ri &icirc;n perioada selectat&#259;.</p>
            <?php endif; ?>

            <table class="dashboard-mini-table">
                <thead>
                <tr>
                    <th>Produs</th>
                    <th class="text-end">Cantitate</th>
                    <th class="text-end">Valoare</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($fuelCost['rows'] ?? []) as $row): ?>
                    <tr>
                        <td>
                            <span class="dashboard-product-dot tone-<?= e((string) ($row['tone'] ?? 'blue')) ?>"></span>
                            <?= e((string) ($row['label'] ?? '')) ?>
                        </td>
                        <td class="text-end"><?= e($formatLiters($row['quantity'] ?? 0)) ?></td>
                        <td class="text-end"><?= e($formatCurrency($row['value'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="dashboard-mini-total">
                    <td>Total</td>
                    <td class="text-end"><?= e($formatLiters($fuelCost['total_quantity'] ?? 0)) ?></td>
                    <td class="text-end"><?= e($formatCurrency($fuelCost['total_value'] ?? 0)) ?></td>
                </tr>
                </tbody>
            </table>
        </article>

        <article
            id="dashboard-operational-maintenance-card"
            class="dashboard-metric-card dashboard-card-maintenance dashboard-operational-sibling-card"
            data-dashboard-operational-detail-card
        >
            <header class="dashboard-card-header">
                <div class="dashboard-card-title">
                    <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-tools"></i></span>
                    <h2>Cost mentenan&#539;&#259;</h2>
                </div>
                <a class="dashboard-card-link" href="<?= e($maintenanceDetailsUrl) ?>">
                    <span>Vezi detalii</span>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
            </header>

            <div class="dashboard-money-total is-purple">
                <?= e(format_number_ro((float) ($maintenanceCost['total_value'] ?? 0), 2)) ?> <small>lei</small>
            </div>
            <p class="dashboard-card-period">Total perioad&#259;: <?= e($periodRangeLabel) ?></p>

            <?php if (!$maintenanceHasData): ?>
                <p class="dashboard-operational-empty">Nu exist&#259; costuri de mentenan&#539;&#259; &icirc;n perioada selectat&#259;.</p>
            <?php endif; ?>

            <table class="dashboard-mini-table">
                <thead>
                <tr>
                    <th>Categorie</th>
                    <th class="text-end">Valoare</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($maintenanceCost['rows'] ?? []) as $row): ?>
                    <tr>
                        <td>
                            <i class="bi <?= e((string) ($row['icon'] ?? 'bi-wrench')) ?>" aria-hidden="true"></i>
                            <?= e((string) ($row['label'] ?? '')) ?>
                        </td>
                        <td class="text-end"><?= e($formatCurrency($row['value'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="dashboard-mini-total">
                    <td>Total</td>
                    <td class="text-end"><?= e($formatCurrency($maintenanceCost['total_value'] ?? 0)) ?></td>
                </tr>
                </tbody>
            </table>
        </article>
            </div>
        </section>
    </section>

    <section class="dashboard-detail-grid" aria-label="Liste inactive dashboard">
        <article class="dashboard-detail-panel dashboard-panel-vehicles">
            <header class="dashboard-panel-header">
                <div>
                    <h2>Vehicule inactive</h2>
                    <span class="dashboard-count-badge"><?= e((string) ((int) ($vehicleStatus['inactive'] ?? 0))) ?></span>
                </div>
                <a href="<?= e($vehicleDetailsUrl) ?>">Vezi toate</a>
            </header>

            <div class="dashboard-panel-table-wrap">
                <table class="dashboard-panel-table">
                    <thead>
                    <tr>
                        <th>Vehicul</th>
                        <th>Motiv</th>
                        <th>De la</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (($vehicleStatus['inactive_rows'] ?? []) === []): ?>
                        <tr>
                            <td colspan="5" class="dashboard-empty-cell">Nu există vehicule inactive pentru filtrele selectate.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vehicleStatus['inactive_rows'] as $row): ?>
                            <?php
                            $vehicleId = (int) ($row['id'] ?? 0);
                            $plate = (string) ($row['nr_inmatriculare'] ?? '');
                            $menuId = 'dashboard_vehicle_actions_' . $vehicleId;
                            ?>
                            <tr>
                                <td>
                                    <span class="dashboard-vehicle-pill">
                                        <i class="bi bi-truck-front" aria-hidden="true"></i>
                                        <strong><?= e($plate) ?></strong>
                                    </span>
                                </td>
                                <td>
                                    <span class="dashboard-table-reason tone-<?= e((string) ($row['reason_tone'] ?? 'muted')) ?>">
                                        <i class="bi <?= e((string) ($row['reason_icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i>
                                        <?= e((string) ($row['reason'] ?? 'Alt motiv')) ?>
                                    </span>
                                </td>
                                <td><?= e($formatDate($row['date'] ?? '')) ?></td>
                                <td><span class="dashboard-status-badge"><i class="bi bi-circle-fill" aria-hidden="true"></i> Inactiv</span></td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="dashboard-menu-button" type="button" id="<?= e($menuId) ?>" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acțiuni vehicul <?= e($plate) ?>">
                                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end dashboard-action-menu" aria-labelledby="<?= e($menuId) ?>">
                                            <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId])) ?>">Vezi detalii</a></li>
                                            <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'edit', 'id' => $vehicleId])) ?>">Editează</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <a class="dashboard-panel-footer-link" href="<?= e($vehicleDetailsUrl) ?>">
                Vezi toate vehiculele inactive (<?= e((string) ((int) ($vehicleStatus['inactive'] ?? 0))) ?>)
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
        </article>

        <article class="dashboard-detail-panel dashboard-panel-drivers">
            <header class="dashboard-panel-header">
                <div>
                    <h2>Șoferi inactivi</h2>
                    <span class="dashboard-count-badge"><?= e((string) ((int) ($driverStatus['inactive'] ?? 0))) ?></span>
                </div>
                <a href="<?= e($driverDetailsUrl) ?>">Vezi toate</a>
            </header>

            <div class="dashboard-panel-table-wrap">
                <table class="dashboard-panel-table">
                    <thead>
                    <tr>
                        <th>Șofer</th>
                        <th>Motiv</th>
                        <th>De la</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (($driverStatus['inactive_rows'] ?? []) === []): ?>
                        <tr>
                            <td colspan="5" class="dashboard-empty-cell">Nu există șoferi inactivi pentru filtrele selectate.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($driverStatus['inactive_rows'] as $row): ?>
                            <?php
                            $driverId = (int) ($row['id'] ?? 0);
                            $driverName = (string) ($row['nume'] ?? '');
                            $menuId = 'dashboard_driver_actions_' . $driverId;
                            ?>
                            <tr>
                                <td>
                                    <span class="dashboard-driver-cell">
                                        <span class="dashboard-driver-avatar" aria-hidden="true"><?= e($initials($driverName)) ?></span>
                                        <strong><?= e($driverName !== '' ? $driverName : ('Șofer #' . $driverId)) ?></strong>
                                    </span>
                                </td>
                                <td>
                                    <span class="dashboard-table-reason tone-<?= e((string) ($row['reason_tone'] ?? 'muted')) ?>">
                                        <i class="bi <?= e((string) ($row['reason_icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i>
                                        <?= e((string) ($row['reason'] ?? 'Alt motiv')) ?>
                                    </span>
                                </td>
                                <td><?= e($formatDate($row['date'] ?? '')) ?></td>
                                <td><span class="dashboard-status-badge"><i class="bi bi-circle-fill" aria-hidden="true"></i> Inactiv</span></td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="dashboard-menu-button" type="button" id="<?= e($menuId) ?>" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acțiuni șofer <?= e($driverName) ?>">
                                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end dashboard-action-menu" aria-labelledby="<?= e($menuId) ?>">
                                            <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'soferi', 'action' => 'show', 'id' => $driverId])) ?>">Vezi detalii</a></li>
                                            <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'soferi', 'action' => 'edit', 'id' => $driverId])) ?>">Editează</a></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <a class="dashboard-panel-footer-link" href="<?= e($driverDetailsUrl) ?>">
                Vezi toți șoferii inactivi (<?= e((string) ((int) ($driverStatus['inactive'] ?? 0))) ?>)
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
            </a>
        </article>
    </section>
        </div>

        <?php if ($showDashboardApprovalPanel): ?>
            <?php
            $approvalTabs = [
                'vehicle' => [
                    'label' => 'Vehicule',
                    'count' => (int) ($approvalCounts['vehicle'] ?? 0),
                    'rows' => $approvalVehicleRows,
                ],
                'driver' => [
                    'label' => 'Soferi',
                    'count' => (int) ($approvalCounts['driver'] ?? 0),
                    'rows' => $approvalDriverRows,
                ],
            ];
            $activeApprovalTab = (int) ($approvalCounts['vehicle'] ?? 0) > 0 ? 'vehicle' : 'driver';
            ?>
            <aside class="dashboard-approval-panel" aria-labelledby="dashboard-approval-title">
                <header class="dashboard-approval-header">
                    <div>
                        <h2 id="dashboard-approval-title">Solicitari aprobare in asteptare</h2>
                        <span><?= e((string) $approvalTotal) ?> total</span>
                    </div>
                    <button class="dashboard-approval-close" type="button" aria-label="Ascunde panoul">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="dashboard-approval-tabs" role="tablist" aria-label="Solicitari aprobare inactive">
                    <?php foreach ($approvalTabs as $tabKey => $tab): ?>
                        <?php $isActiveTab = $tabKey === $activeApprovalTab; ?>
                        <button
                            class="dashboard-approval-tab<?= $isActiveTab ? ' is-active' : '' ?>"
                            type="button"
                            role="tab"
                            id="dashboard-approval-tab-<?= e($tabKey) ?>"
                            aria-controls="dashboard-approval-panel-<?= e($tabKey) ?>"
                            aria-selected="<?= $isActiveTab ? 'true' : 'false' ?>"
                            data-dashboard-approval-tab="<?= e($tabKey) ?>"
                        >
                            <?= e((string) $tab['label']) ?> (<?= e((string) ((int) $tab['count'])) ?>)
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php foreach ($approvalTabs as $tabKey => $tab): ?>
                    <?php
                    $isActiveTab = $tabKey === $activeApprovalTab;
                    $rowsForTab = is_array($tab['rows'] ?? null) ? $tab['rows'] : [];
                    ?>
                    <div
                        class="dashboard-approval-tab-panel<?= $isActiveTab ? ' is-active' : '' ?>"
                        role="tabpanel"
                        id="dashboard-approval-panel-<?= e($tabKey) ?>"
                        aria-labelledby="dashboard-approval-tab-<?= e($tabKey) ?>"
                        data-dashboard-approval-panel="<?= e($tabKey) ?>"
                        <?= $isActiveTab ? '' : 'hidden' ?>
                    >
                        <?php if ($rowsForTab === []): ?>
                            <div class="dashboard-approval-empty">
                                Nu exista solicitari in asteptare.
                            </div>
                        <?php else: ?>
                            <?php foreach ($rowsForTab as $approval): ?>
                                <?php
                                $approvalId = (int) ($approval['id'] ?? 0);
                                $reasonKey = (string) ($approval['inactive_reason'] ?? 'other');
                                $documents = is_array($approval['documents'] ?? null) ? $approval['documents'] : [];
                                $documentNames = is_array($approval['affected_document_names'] ?? null) ? $approval['affected_document_names'] : [];
                                $snapshot = json_decode((string) ($approval['snapshot_json'] ?? ''), true);
                                $snapshot = is_array($snapshot) ? $snapshot : [];
                                $detail = trim((string) ($snapshot['detail'] ?? ''));
                                $affectedDocuments = $documentNames !== []
                                    ? implode(', ', array_map('strval', $documentNames))
                                    : implode(', ', array_map(static fn(array $doc): string => (string) ($doc['document_name'] ?? ''), $documents));
                                $affectedDocuments = trim($affectedDocuments, " \t\n\r\0\x0B,");
                                ?>
                                <article class="dashboard-approval-card">
                                    <div class="dashboard-approval-card-meta">
                                        <span class="dashboard-approval-reason tone-<?= e($approvalTone($reasonKey)) ?>">
                                            <i class="bi <?= e($approvalIcon($reasonKey)) ?>" aria-hidden="true"></i>
                                            <?= e((string) ($approval['inactive_reason_label'] ?? 'Alt motiv')) ?>
                                        </span>
                                        <span><?= e($formatApprovalTime($approval['requested_at'] ?? '')) ?></span>
                                    </div>

                                    <h3><?= e((string) ($approval['resource_label'] ?? '-')) ?></h3>

                                    <dl class="dashboard-approval-card-list">
                                        <dt>Motiv:</dt>
                                        <dd><?= e((string) ($approval['inactive_reason_label'] ?? 'Alt motiv')) ?></dd>
                                        <?php if ($affectedDocuments !== ''): ?>
                                            <dt>Documente afectate:</dt>
                                            <dd><?= e($affectedDocuments) ?></dd>
                                        <?php endif; ?>
                                        <?php if ($detail !== ''): ?>
                                            <dt>Detaliu:</dt>
                                            <dd><?= e($detail) ?></dd>
                                        <?php endif; ?>
                                        <dt>Inactiv din:</dt>
                                        <dd><?= e($formatDate($approval['inactive_since'] ?? '')) ?></dd>
                                        <dt>Utilizat in:</dt>
                                        <dd><?= e($formatApprovalContext($approval['usage_context'] ?? '')) ?></dd>
                                    </dl>

                                    <div class="dashboard-approval-actions">
                                        <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reject'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                            <input type="hidden" name="return_url" value="<?= e($dashboardReturnUrl) ?>">
                                            <button class="dashboard-approval-action is-reject" type="submit">Respinge</button>
                                        </form>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'approve'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                            <input type="hidden" name="return_url" value="<?= e($dashboardReturnUrl) ?>">
                                            <button class="dashboard-approval-action is-approve" type="submit">Aproba</button>
                                        </form>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <a class="dashboard-approval-all-link" href="<?= e(build_query_url(['page' => 'inactive_approvals'])) ?>">
                    <span>Vezi toate solicitarile</span>
                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </aside>
        <?php endif; ?>
    </div>
</div>
