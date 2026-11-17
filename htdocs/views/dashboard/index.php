<?php
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$dashboardFilters = is_array($dashboardFilters ?? null) ? $dashboardFilters : [];
$periodOptions = is_array($periodOptions ?? null) ? $periodOptions : [];
$vehicleCategoryOptions = is_array($vehicleCategoryOptions ?? null) ? $vehicleCategoryOptions : [];
$vehicleOptions = is_array($vehicleOptions ?? null) ? $vehicleOptions : [];
$approvalSummary = is_array($approvalSummary ?? null) ? $approvalSummary : [];
$operationalCosts = is_array($operationalCosts ?? null) ? $operationalCosts : null;
$expenseBreakdown = is_array($expenseBreakdown ?? null) ? $expenseBreakdown : null;
$documentCosts = is_array($documentCosts ?? null) ? $documentCosts : null;
$equipmentCosts = is_array($equipmentCosts ?? null) ? $equipmentCosts : null;
$canViewFinancial = !empty($canViewFinancial);
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
$periodFieldLabel = (static function (string $period, string $start, string $end): string {
    $months = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start) ?: null;
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end) ?: null;
    if ($period === 'ultimele_30_zile') {
        return 'Ultimele 30 de zile';
    }
    if ($startDate === null || $endDate === null) {
        return 'Alege perioada';
    }
    if ($period === 'an_curent') {
        return 'Anul ' . $startDate->format('Y');
    }
    if ($start === $end) {
        return $startDate->format('d.m.Y');
    }
    $fullMonth = $startDate->format('d') === '01'
        && $startDate->format('Y-m') === $endDate->format('Y-m')
        && ($period === 'luna_curenta' || $endDate->format('d') === $endDate->format('t'));
    if ($fullMonth) {
        return $months[(int) $startDate->format('n') - 1] . ' ' . $startDate->format('Y');
    }

    return $startDate->format('d.m.Y') . ' – ' . $endDate->format('d.m.Y');
})($selectedPeriod, $dateStart, $dateEnd);
$operationalRows = is_array($operationalCost['rows'] ?? null) ? $operationalCost['rows'] : [];
$operationalTotal = (float) ($operationalCost['total_value'] ?? ((float) ($fuelCost['total_value'] ?? 0) + (float) ($maintenanceCost['total_value'] ?? 0)));
$approvalCounts = is_array($approvalSummary['counts'] ?? null) ? $approvalSummary['counts'] : ['vehicle' => 0, 'driver' => 0, 'repair' => 0];
$approvalVehicleRows = is_array($approvalSummary['vehicles'] ?? null) ? $approvalSummary['vehicles'] : [];
$approvalDriverRows = is_array($approvalSummary['drivers'] ?? null) ? $approvalSummary['drivers'] : [];
$approvalTotal = (int) ($approvalSummary['total'] ?? ((int) ($approvalCounts['vehicle'] ?? 0) + (int) ($approvalCounts['driver'] ?? 0) + (int) ($approvalCounts['repair'] ?? 0)));
$vehicleCategoryIcon = match ($selectedVehicleCategory) {
    'grele' => 'bi-truck',
    'usoare' => 'bi-car-front',
    default => 'bi-truck-front',
};
$dashboardLiveCssVersion = (string) @filemtime(BASE_PATH . '/assets/css/dashboard-live.css');
$dashboardLiveJsVersion = (string) @filemtime(BASE_PATH . '/assets/js/dashboard-live.js');
$dashboardReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dashboard']));

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

// Contoarele din card numara unitati de flota (ansamblul cap tractor + semiremorca = 1),
// asa ca lista deschisa la click foloseste acelasi criteriu prin filtrul unitate_flota.
$vehicleUnitUrl = static function (string $unit) use ($selectedVehicleId, $selectedVehicleCategory, $selectedVehicleSearch): string {
    $vehicleTypes = match ($selectedVehicleCategory) {
        'grele' => ['camion', 'cap_tractor', 'semiremorca_primar', 'semiremorca_distributie'],
        'usoare' => ['autovehicul', 'autoutilitara'],
        default => null,
    };

    // Cu un singur vehicul selectat nu exista ce grupa, iar filtrul de ansamblu ar
    // ascunde chiar semiremorca aleasa - se filtreaza direct pe statusul randului.
    if ($selectedVehicleId !== null) {
        return build_query_url([
            'page' => 'vehicule',
            'status' => match ($unit) {
                'active' => 'activ',
                'inactive' => 'inactiv',
                default => null,
            },
            'q' => $selectedVehicleSearch,
        ]);
    }

    return build_query_url([
        'page' => 'vehicule',
        'unitate_flota' => $unit,
        'tip_vehicul' => $vehicleTypes,
        'q' => $selectedVehicleSearch,
    ]);
};
$driverStatusUrl = static fn(?string $status): string => build_query_url([
    'page' => 'soferi',
    'status' => $status,
    'q' => $selectedVehicleSearch,
]);
// Legaturile "Vezi detalii" / "Vezi toate" afiseaza acelasi numar ca badge-ul de inactive,
// deci trebuie sa deschida exact aceeasi lista ca si contorul Inactive.
$vehicleDetailsUrl = $vehicleUnitUrl('inactive');

// Panoul configurabil: cardurile din layout-ul utilizatorului (DashboardWidgetRegistry).
$dashboardLayout = is_array($dashboardLayout ?? null) ? $dashboardLayout : [];
$dashboardCatalog = is_array($dashboardCatalog ?? null) ? $dashboardCatalog : [];
$widgetData = is_array($widgetData ?? null) ? $widgetData : [];
$costPeriodMonth = $dateStart !== '' ? substr($dateStart, 0, 7) : null;
// „Vezi pagina sursă” din meniul cardului: filtrele dashboard-ului traduse în parametrii paginii.
$widgetSourceUrls = [
    'fleet.vehicles' => $vehicleDetailsUrl,
    'fleet.drivers' => $driverDetailsUrl,
    'finance.operational_cost' => build_query_url(['page' => 'cost_operational', 'period' => $costPeriodMonth, 'vehicle_id' => $selectedVehicleId]),
    'finance.activity_split' => build_query_url(['page' => 'cost_operational', 'period' => $costPeriodMonth, 'vehicle_id' => $selectedVehicleId]),
    'mentenanta.interventions' => build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'vehicle_id' => $selectedVehicleId]),
    'documente.alerts' => build_query_url(['page' => 'documente', 'vehicle_id' => $selectedVehicleId]),
    'drivers.activity' => build_query_url(['page' => 'istoric_activitati_sofer', 'date_start' => $dateStart, 'date_end' => $dateEnd, 'vehicle_id' => $selectedVehicleId]),
    'carburanti.kpis' => build_query_url(['page' => 'carburanti', 'date_from' => $dateStart, 'date_to' => $dateEnd, 'vehicles' => $selectedVehicleSearch !== null ? [$selectedVehicleSearch] : null]),
    'cheltuieli.kpis' => build_query_url(['page' => 'cheltuieli', 'date_start' => $dateStart, 'date_end' => $dateEnd, 'vehicul_id' => $selectedVehicleId]),
    'facturi.summary' => build_query_url(['page' => 'facturi', 'data_start' => $dateStart, 'data_end' => $dateEnd, 'vehicle_id' => $selectedVehicleId]),
    'etoll.kpis' => build_query_url(['page' => 'etoll', 'perioada' => 'interval', 'de_la' => $dateStart, 'pana_la' => $dateEnd]),
    'stoc.piese' => build_query_url(['page' => 'mentenanta', 'action' => 'stock']),
    'centralizator.kpis' => build_query_url(['page' => 'centralizator_facturare', 'month' => $dateEnd !== '' ? substr($dateEnd, 0, 7) : null, 'vehicle_id' => $selectedVehicleId !== null ? [(string) $selectedVehicleId] : null]),
    'consumabile.alerts' => build_query_url(['page' => 'mentenanta', 'action' => 'stock', 'tab' => 'consumabile']),
    'dispecer.insights' => build_query_url(['page' => 'dispecer_curse']),
    'dispecer.live_gps' => build_query_url(['page' => 'dispecer_curse']),
];
$dashboardGridConfig = [
    'catalog' => $dashboardCatalog,
    'layout' => $dashboardLayout,
    'view' => in_array($dashboardView ?? 'carousel', ['carousel', 'grid'], true) ? (string) ($dashboardView ?? 'carousel') : 'carousel',
    'sizeLabels' => DashboardWidgetRegistry::SIZE_LABELS,
    'saveUrl' => build_query_url(['page' => 'dashboard', 'action' => 'save_layout']),
    'widgetUrl' => build_query_url(['page' => 'dashboard', 'action' => 'widget']),
    'csrf' => csrf_token(),
];
$dashboardGridCssVersion = (string) @filemtime(BASE_PATH . '/assets/css/dashboard-grid.css');
$dashboardGridJsVersion = (string) @filemtime(BASE_PATH . '/assets/js/dashboard-grid.js');

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

<link rel="stylesheet" href="<?= e(url('assets/css/dashboard-live.css?v=' . $dashboardLiveCssVersion)) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/dashboard-grid.css?v=' . $dashboardGridCssVersion)) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/ro.js"></script>

<div class="dashboard-page<?= ($dashboardView ?? 'carousel') !== 'grid' ? ' is-compact-filters' : '' ?>" data-dashboard-live-root>
    <header class="dashboard-page-heading dash-heading">
        <div>
            <h1>Dashboard</h1>
            <p>Privire de ansamblu asupra flotei</p>
        </div>
        <div class="dash-toolbar" data-dash-toolbar>
            <div class="dash-view-toggle" role="group" aria-label="Mod de afișare">
                <button type="button" class="dash-view-btn" data-dash-view="carousel" aria-pressed="false" title="Pagini cu săgeți (fără scroll)">
                    <i class="bi bi-collection" aria-hidden="true"></i><span>Pagini</span>
                </button>
                <button type="button" class="dash-view-btn" data-dash-view="grid" aria-pressed="false" title="Toate cardurile, una sub alta">
                    <i class="bi bi-grid-3x2-gap" aria-hidden="true"></i><span>Toate</span>
                </button>
            </div>
            <div class="dash-add">
                <button type="button" class="dash-toolbar-btn" data-dash-add-toggle aria-haspopup="menu" aria-expanded="false">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i><span>Adaugă card</span>
                </button>
            </div>
            <button type="button" class="dash-toolbar-btn is-primary" data-dash-config-open aria-controls="dashConfigDrawer">
                <i class="bi bi-sliders" aria-hidden="true"></i><span>Configurare panou</span>
            </button>
        </div>
    </header>

    <div data-dashboard-live="error">
    <?php if (!empty($dashboardError)): ?>
        <div class="dashboard-inline-error" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span><?= e($dashboardError) ?></span>
        </div>
    <?php endif; ?>
    </div>

    <section class="dashboard-filter-panel" aria-labelledby="dashboard-filter-title">
        <h2 id="dashboard-filter-title" class="visually-hidden">Filtre dashboard</h2>
        <form class="dashboard-filter-form" method="get" data-dashboard-live-form>
            <input type="hidden" name="page" value="dashboard">

            <?php /* Același câmp ca în Carburanți: calendar de interval (flatpickr) cu scurtături. */ ?>
            <div class="dashboard-filter-group dashboard-filter-period">
                <label class="dashboard-filter-label" for="dashboard_period_display">Perioadă</label>
                <div class="fuel-date-input dashboard-date-input">
                    <input type="text" class="form-control dashboard-select" id="dashboard_period_display"
                           value="<?= e($periodFieldLabel) ?>" placeholder="Alege perioada" readonly
                           data-dashboard-period-display>
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                </div>
                <input type="hidden" name="period" value="<?= e($selectedPeriod) ?>" data-dashboard-period-input>
                <input type="hidden" name="date_from" value="<?= e($dateStart) ?>" data-dashboard-date-from>
                <input type="hidden" name="date_to" value="<?= e($dateEnd) ?>" data-dashboard-date-to>
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
                <a class="dashboard-reset-btn" data-dashboard-live-reset href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                    <span>Resetează</span>
                </a>
            </div>

            <div class="dashboard-filter-chips-row">
            <div class="dashboard-filter-chips" aria-label="Filtre active" aria-live="polite" data-dashboard-live="chips">
                <button
                    type="button"
                    class="dashboard-filter-chip dashboard-period-chip<?= $selectedPeriod === 'personalizat' ? ' is-custom' : '' ?>"
                    data-dashboard-period-picker
                    data-date-start="<?= e($dateStart) ?>"
                    data-date-end="<?= e($dateEnd) ?>"
                    aria-haspopup="dialog"
                    aria-expanded="false"
                    title="Alege perioada"
                >
                    <i class="bi bi-calendar2-week" aria-hidden="true"></i>
                    <span>Perioadă: <?= e($periodRangeLabel) ?></span>
                    <i class="bi bi-chevron-down dashboard-period-chip-caret" aria-hidden="true"></i>
                </button>
                <span class="dashboard-filter-chip">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                    <span>Categorie: <?= e($vehicleCategoryLabel) ?></span>
                </span>
                <span class="dashboard-filter-chip">
                    <i class="bi bi-truck-front" aria-hidden="true"></i>
                    <span>Vehicul: <?= e($vehicleLabel) ?></span>
                </span>
            </div>
            </div>
        </form>
        <?php
        /*
         * Modul „Pagini”: fiecare card cu filtrele paginii-sursă e o secțiune proprie, iar filtrele lui
         * se mută aici (assets/js/dashboard-grid.js) și se văd doar cât e activă secțiunea lui.
         * Secțiunile comune folosesc formularul de mai sus. Starea filtrelor rămâne în config-ul cardului.
         */
        ?>
        <div class="dash-section-filters" data-dash-section-filters hidden>
            <div class="dash-section-title"><i class="bi bi-funnel" aria-hidden="true"></i> <span data-dash-section-title></span></div>
            <div class="dash-section-forms" data-dash-section-forms></div>
            <p class="dash-section-loading" data-dash-section-loading hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă filtrele…</p>
        </div>
    </section>

    <div class="dashboard-approval-layout<?= $showDashboardApprovalPanel ? ' has-approval-panel' : '' ?>">
        <div class="dashboard-approval-content">
    <?php if ($dashboardLayout === []): ?>
        <div class="dash-empty-layout">
            <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
            <p>Niciun card pe panou. Adaugă carduri din <strong>Configurare panou</strong> sau din <strong>Adaugă card</strong>.</p>
        </div>
    <?php endif; ?>
    <?php /* Caruselul: paginile se calculează în browser (câte rânduri de carduri încap pe ecran). */ ?>
    <nav class="dash-pager" data-dash-pager aria-label="Pagini tablou de bord" hidden>
        <button type="button" class="dash-pager-step" data-dash-step="-1" aria-label="Pagina anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <div class="dash-pager-dots" data-dash-dots role="tablist"></div>
        <span class="dash-pager-label" data-dash-page-label aria-live="polite"></span>
        <button type="button" class="dash-pager-step" data-dash-step="1" aria-label="Pagina următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
    </nav>
    <div class="dash-stage" data-dash-stage>
    <?php /* Săgețile stau „lipite” la mijlocul ecranului cât timp cardurile sunt la vedere (position: sticky). */ ?>
    <div class="dash-arrows">
        <button type="button" class="dash-arrow is-prev" data-dash-step="-1" aria-label="Pagina anterioară" hidden><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <button type="button" class="dash-arrow is-next" data-dash-step="1" aria-label="Pagina următoare" hidden><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
    </div>
    <section
        class="dashboard-main-grid dash-grid"
        aria-label="Carduri tablou de bord"
        data-dashboard-main-grid
        data-dash-grid
    >
    <?php foreach ($dashboardLayout as $layoutItem): ?>
        <?php
        $widgetId = (string) $layoutItem['id'];
        $widgetDef = DashboardWidgetRegistry::get($widgetId);
        if ($widgetDef === null) {
            continue;
        }
        $widgetSize = (string) $layoutItem['size'];
        $widgetLazy = ($widgetDef['render'] ?? 'page') === 'lazy';
        $widgetTime = (string) $widgetDef['time'];
        $widgetTimeLabel = $widgetTime === 'period' ? $periodRangeLabel : (DashboardWidgetRegistry::TIME_LABELS[$widgetTime] ?? '');
        $widgetIgnored = [];
        if ($selectedVehicleCategory !== 'toate' && !in_array('vehicle_category', $widgetDef['filters'], true)) {
            $widgetIgnored[] = 'categoria';
        }
        if ($selectedVehicleId !== null && !in_array('vehicle_id', $widgetDef['filters'], true)) {
            $widgetIgnored[] = 'vehiculul';
        }
        $widgetSourceUrl = $widgetSourceUrls[$widgetId] ?? build_query_url($widgetDef['link']);
        ?>
        <div class="dash-widget dash-size-<?= e($widgetSize) ?>" data-dash-widget="<?= e($widgetId) ?>" data-dash-size="<?= e($widgetSize) ?>">
            <div class="dash-widget-bar">
                <span class="dash-widget-module"><i class="bi <?= e((string) $widgetDef['icon']) ?>" aria-hidden="true"></i><?= e((string) $widgetDef['module']) ?></span>
                <span class="dash-widget-meta" data-dashboard-live="bar:<?= e($widgetId) ?>">
                    <span class="dash-widget-time is-<?= e($widgetTime) ?>" title="Intervalul de timp al acestui card"><?php if ($widgetTime === 'live' || $widgetTime === 'mixed'): ?><span class="dash-live-dot" aria-hidden="true"></span><?php endif; ?><?= e($widgetTimeLabel) ?></span>
                    <?php if ($widgetIgnored !== []): ?>
                        <span class="dash-widget-ignored" title="Cardul nu folosește aceste filtre globale">ignoră <?= e(implode(' și ', $widgetIgnored)) ?></span>
                    <?php endif; ?>
                </span>
                <button type="button" class="dash-widget-menu-btn" data-dash-menu="<?= e($widgetId) ?>" data-source-url="<?= e($widgetSourceUrl) ?>"
                        aria-haspopup="menu" aria-expanded="false" aria-label="Acțiuni pentru cardul <?= e((string) $widgetDef['title']) ?>">
                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                </button>
            </div>
            <?php if ($widgetLazy): ?>
                <div class="dash-widget-body" data-dash-lazy="<?= e($widgetId) ?>" aria-busy="true">
                    <div class="dash-widget-skeleton">
                        <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        <span>Se încarcă <?= e((string) $widgetDef['title']) ?>…</span>
                    </div>
                </div>
            <?php else: ?>
                <div class="dash-widget-body"<?= in_array($widgetId, ['fleet.vehicles', 'fleet.drivers', 'finance.operational_cost'], true) ? '' : ' data-dashboard-live="w:' . e($widgetId) . '"' ?>>
                <?php if ($widgetId === 'fleet.vehicles'): ?>
                <article class="dashboard-metric-card dashboard-card-vehicles" data-dashboard-live="vehicles" data-dashboard-flip-card>
                    <header class="dashboard-card-header">
                        <div class="dashboard-card-title">
                            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi <?= e($vehicleCategoryIcon) ?>"></i></span>
                            <h2>Status vehicule</h2>
                            <?php if ($selectedVehicleCategory !== 'toate'): ?>
                                <span class="dashboard-category-tag"><?= e($vehicleCategoryLabel) ?></span>
                            <?php endif; ?>
                        </div>
                        <a class="dashboard-card-link" href="<?= e($vehicleDetailsUrl) ?>">
                            <span>Vezi detalii</span>
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    </header>

                    <div class="dashboard-card-face" data-dashboard-summary-face>
                    <div class="dashboard-stat-row">
                        <a class="dashboard-stat" href="<?= e($vehicleUnitUrl('toate')) ?>" title="Vezi toate vehiculele pe tip" data-dashboard-active-open="total">
                            <span>Total vehicule</span>
                            <strong class="is-blue" data-dashboard-count="vehicles.total"><?= e((string) ((int) ($vehicleStatus['total'] ?? 0))) ?></strong>
                        </a>
                        <a class="dashboard-stat" href="<?= e($vehicleUnitUrl('active')) ?>" title="Vezi vehiculele active pe tip" data-dashboard-active-open="active">
                            <span>Active</span>
                            <strong class="is-green" data-dashboard-count="vehicles.active"><?= e((string) ((int) ($vehicleStatus['active'] ?? 0))) ?></strong>
                        </a>
                        <a class="dashboard-stat" href="<?= e($vehicleUnitUrl('inactive')) ?>" title="Vezi vehiculele inactive si documentele cu probleme" data-dashboard-inactive-open="all">
                            <span>Inactive</span>
                            <strong class="is-red" data-dashboard-count="vehicles.inactive"><?= e((string) ((int) ($vehicleStatus['inactive'] ?? 0))) ?></strong>
                        </a>
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
                                <a class="dashboard-reason-item tone-<?= e((string) ($reason['tone'] ?? 'muted')) ?>" href="<?= e($vehicleReasonUrl($reasonKey)) ?>"<?= $reasonCount > 0 ? ' data-dashboard-inactive-open="' . e($reasonKey) . '"' : '' ?>>
                                    <span class="dashboard-reason-icon"><i class="bi <?= e((string) ($reason['icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i></span>
                                    <span class="dashboard-reason-label"><?= e((string) ($reason['label'] ?? 'Alt motiv')) ?></span>
                                    <span class="dashboard-reason-count" data-dashboard-count="vehicles.reason.<?= e($reasonKey) ?>"><?= e((string) $reasonCount) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="dashboard-info-strip">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <span>Vehiculele programate la reparații sunt marcate automat inactive.</span>
                    </div>
                    </div>

                    <?php $faceKind = 'vehicles'; $faceStatus = $vehicleStatus; require __DIR__ . '/_inactive_face.php'; ?>
                    <?php $typeFaceMode = 'active'; require __DIR__ . '/_type_face.php'; ?>
                    <?php $typeFaceMode = 'total'; require __DIR__ . '/_type_face.php'; ?>
                </article>

                <?php elseif ($widgetId === 'fleet.drivers'): ?>
                <article class="dashboard-metric-card dashboard-card-drivers" data-dashboard-live="drivers" data-dashboard-flip-card>
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

                    <div class="dashboard-card-face" data-dashboard-summary-face>
                    <div class="dashboard-stat-row">
                        <a class="dashboard-stat" href="<?= e($driverStatusUrl(null)) ?>" title="Vezi toti soferii">
                            <span>Total șoferi</span>
                            <strong class="is-blue" data-dashboard-count="drivers.total"><?= e((string) ((int) ($driverStatus['total'] ?? 0))) ?></strong>
                        </a>
                        <a class="dashboard-stat" href="<?= e($driverStatusUrl('activ')) ?>" title="Vezi soferii activi">
                            <span>Activi</span>
                            <strong class="is-green" data-dashboard-count="drivers.active"><?= e((string) ((int) ($driverStatus['active'] ?? 0))) ?></strong>
                        </a>
                        <a class="dashboard-stat" href="<?= e($driverStatusUrl('inactiv')) ?>" title="Vezi soferii inactivi si documentele cu probleme" data-dashboard-inactive-open="all">
                            <span>Inactivi</span>
                            <strong class="is-red" data-dashboard-count="drivers.inactive"><?= e((string) ((int) ($driverStatus['inactive'] ?? 0))) ?></strong>
                        </a>
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
                                <a class="dashboard-reason-item tone-<?= e((string) ($reason['tone'] ?? 'muted')) ?>" href="<?= e($driverReasonUrl($reasonKey)) ?>"<?= $reasonCount > 0 ? ' data-dashboard-inactive-open="' . e($reasonKey) . '"' : '' ?>>
                                    <span class="dashboard-reason-icon"><i class="bi <?= e((string) ($reason['icon'] ?? 'bi-circle')) ?>" aria-hidden="true"></i></span>
                                    <span class="dashboard-reason-label"><?= e((string) ($reason['label'] ?? 'Alt motiv')) ?></span>
                                    <span class="dashboard-reason-count" data-dashboard-count="drivers.reason.<?= e($reasonKey) ?>"><?= e((string) $reasonCount) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    </div>

                    <?php $faceKind = 'drivers'; $faceStatus = $driverStatus; require __DIR__ . '/_inactive_face.php'; ?>
                </article>

                <?php elseif ($widgetId === 'finance.operational_cost'): ?>
                    <?php require __DIR__ . '/_operational_card.php'; ?>
                <?php elseif ($widgetId === 'finance.activity_split'): ?>
                    <?php
                    $activitySplit = $widgetData['activity_split'] ?? null;
                    $activitySplitUrl = $widgetSourceUrl;
                    require __DIR__ . '/_activity_split_card.php';
                    ?>
                <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </section>
    </div>

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
                'repair' => [
                    'label' => 'Reparatii',
                    'count' => (int) ($approvalCounts['repair'] ?? 0),
                    'rows' => is_array($approvalSummary['repairs'] ?? null) ? $approvalSummary['repairs'] : [],
                ],
            ];
            $activeApprovalTab = 'vehicle';
            foreach ($approvalTabs as $tabKey => $tab) {
                if ((int) ($tab['count'] ?? 0) > 0) {
                    $activeApprovalTab = $tabKey;
                    break;
                }
            }
            ?>
            <aside class="dashboard-approval-panel" aria-labelledby="dashboard-approval-title">
                <header class="dashboard-approval-header">
                    <div>
                        <h2 id="dashboard-approval-title">Solicitari aprobare in asteptare</h2>
                        <span><span data-approval-total-count><?= e((string) $approvalTotal) ?></span> total</span>
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
                            <?= e((string) $tab['label']) ?> (<span data-approval-tab-count="<?= e($tabKey) ?>"><?= e((string) ((int) $tab['count'])) ?></span>)
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
                            <div class="dashboard-approval-empty" data-approval-empty="<?= e($tabKey) ?>">
                                Nu exista solicitari in asteptare.
                            </div>
                        <?php else: ?>
                            <?php foreach ($rowsForTab as $approval): ?>
                                <?php
                                $approvalId = (int) ($approval['id'] ?? 0);
                                $reasonKey = (string) ($approval['inactive_reason'] ?? 'other');
                                $context = is_array($approval['approval_context'] ?? null) ? $approval['approval_context'] : [];
                                $summaryRows = is_array($context['summary_rows'] ?? null) ? $context['summary_rows'] : [];
                                $detailRows = is_array($context['detail_rows'] ?? null) ? $context['detail_rows'] : [];
                                $requestTypeLabel = trim((string) ($context['request_type_label'] ?? ''));
                                if ($requestTypeLabel === '') {
                                    $requestTypeLabel = (string) ($approval['inactive_reason_label'] ?? 'Alt motiv');
                                }
                                $primaryLabel = trim((string) ($context['primary_label'] ?? $approval['resource_label'] ?? '-'));
                                $problemTitle = trim((string) ($context['problem_title'] ?? $approval['inactive_reason_label'] ?? 'Alt motiv'));
                                $operationTitle = trim((string) ($context['operation_title'] ?? ''));
                                $operationUrl = trim((string) ($context['operation_url'] ?? ''));
                                $operationLinkLabel = trim((string) ($context['operation_link_label'] ?? ''));
                                $scopeMessage = trim((string) ($context['scope_message'] ?? ''));
                                ?>
                                <article class="dashboard-approval-card" data-approval-card data-approval-id="<?= e((string) $approvalId) ?>" data-approval-status="pending" data-approval-tab-key="<?= e($tabKey) ?>">
                                    <div class="dashboard-approval-card-meta">
                                        <span class="dashboard-approval-reason tone-<?= e($approvalTone($reasonKey)) ?>">
                                            <i class="bi <?= e($approvalIcon($reasonKey)) ?>" aria-hidden="true"></i>
                                            <?= e($requestTypeLabel) ?>
                                        </span>
                                        <span><?= e($formatApprovalTime($approval['requested_at'] ?? '')) ?></span>
                                    </div>

                                    <h3><?= e($primaryLabel) ?></h3>
                                    <p class="dashboard-approval-problem"><?= e($problemTitle) ?></p>

                                    <?php if ($operationTitle !== ''): ?>
                                        <div class="dashboard-approval-operation">
                                            <span><?= e($operationTitle) ?></span>
                                            <?php if ($operationUrl !== '' && $operationLinkLabel !== ''): ?>
                                                <a href="<?= e($operationUrl) ?>"><?= e($operationLinkLabel) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($summaryRows !== []): ?>
                                        <dl class="dashboard-approval-card-list dashboard-approval-summary-list">
                                            <?php foreach ($summaryRows as $row): ?>
                                                <?php
                                                $rowLabel = trim((string) ($row['label'] ?? ''));
                                                $rowValue = trim((string) ($row['value'] ?? ''));
                                                if ($rowLabel === '' || $rowValue === '') {
                                                    continue;
                                                }
                                                ?>
                                                <dt><?= e($rowLabel) ?>:</dt>
                                                <dd><?= e($rowValue) ?></dd>
                                            <?php endforeach; ?>
                                        </dl>
                                    <?php endif; ?>

                                    <?php if ($detailRows !== []): ?>
                                        <details class="dashboard-approval-details">
                                            <summary>Vezi detalii</summary>
                                            <dl class="dashboard-approval-card-list">
                                                <?php foreach ($detailRows as $row): ?>
                                                    <?php
                                                    $rowLabel = trim((string) ($row['label'] ?? ''));
                                                    $rowValue = trim((string) ($row['value'] ?? ''));
                                                    if ($rowLabel === '' || $rowValue === '') {
                                                        continue;
                                                    }
                                                    ?>
                                                    <dt><?= e($rowLabel) ?>:</dt>
                                                    <dd><?= e($rowValue) ?></dd>
                                                <?php endforeach; ?>
                                            </dl>
                                        </details>
                                    <?php endif; ?>

                                    <?php if ($scopeMessage !== ''): ?>
                                        <p class="dashboard-approval-scope"><?= e($scopeMessage) ?></p>
                                    <?php endif; ?>

                                    <div class="dashboard-approval-actions">
                                        <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reject'])) ?>" data-approval-review-form data-approval-decision="rejected">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                            <input type="hidden" name="return_url" value="<?= e($dashboardReturnUrl) ?>">
                                            <input type="hidden" name="decision_source" value="popup">
                                            <button class="dashboard-approval-action is-reject" type="submit">Respinge</button>
                                        </form>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'approve'])) ?>" data-approval-review-form data-approval-decision="approved">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                            <input type="hidden" name="return_url" value="<?= e($dashboardReturnUrl) ?>">
                                            <input type="hidden" name="decision_source" value="popup">
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

<div class="offcanvas offcanvas-end dash-drawer" tabindex="-1" id="dashConfigDrawer" aria-labelledby="dashConfigDrawerTitle" data-dash-drawer>
    <div class="offcanvas-header">
        <div>
            <h2 class="offcanvas-title" id="dashConfigDrawerTitle">Configurare panou</h2>
            <p class="dash-drawer-sub">Alege cardurile, ordinea și dimensiunea. Se salvează pentru contul tău.</p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body">
        <label class="dash-drawer-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" placeholder="Caută card..." aria-label="Caută card" data-dash-search autocomplete="off">
        </label>
        <section class="dash-drawer-section" aria-labelledby="dashOrderTitle">
            <h3 id="dashOrderTitle">Pe panou <small>(trage sau folosește săgețile pentru ordine)</small></h3>
            <ol class="dash-order-list" data-dash-order></ol>
            <p class="dash-drawer-empty" data-dash-order-empty hidden>Niciun card selectat.</p>
        </section>
        <section class="dash-drawer-section" aria-labelledby="dashCatalogTitle">
            <h3 id="dashCatalogTitle">Carduri disponibile <small>(pe modulul de origine)</small></h3>
            <div data-dash-catalog></div>
            <p class="dash-drawer-empty" data-dash-catalog-empty hidden>Niciun card nu se potrivește căutării.</p>
        </section>
    </div>
    <div class="dash-drawer-footer">
        <button type="button" class="btn btn-outline-secondary" data-dash-reset><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Implicit</button>
        <span class="dash-drawer-status" data-dash-status role="status" aria-live="polite"></span>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Anulează</button>
        <button type="button" class="btn btn-primary" data-dash-apply>Aplică</button>
    </div>
</div>

<script type="application/json" data-dash-config><?= json_encode($dashboardGridConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(url('assets/js/dashboard-live.js?v=' . $dashboardLiveJsVersion)) ?>" defer></script>
<script src="<?= e(url('assets/js/dashboard-grid.js?v=' . $dashboardGridJsVersion)) ?>" defer></script>
