<?php
declare(strict_types=1);

class DashboardController
{
    private DashboardModel $dashboardModel;
    private InactiveResourceApprovalModel $approvalModel;
    private PDO $db;
    /** Modurile de afișare ale panoului; primul e implicitul. */
    private const VIEW_MODES = ['carousel', 'grid'];
    private bool $savedLayoutLoaded = false;
    private ?array $savedLayoutCache = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->dashboardModel = new DashboardModel($db);
        $this->approvalModel = new InactiveResourceApprovalModel($db);
    }

    /** ?page=dashboard&action=... */
    public function handle(string $action): void
    {
        match ($action) {
            'widget' => $this->widget(),
            'save_layout' => $this->saveLayout(),
            default => $this->index(),
        };
    }

    public function index(): void
    {
        $periodOptions = $this->getPeriodOptions();
        $vehicleCategoryOptions = $this->getVehicleCategoryOptions();
        $vehicleOptions = $this->dashboardModel->getVehicleOptions();
        $filters = $this->resolveFilters($periodOptions, $vehicleCategoryOptions, $vehicleOptions);

        // Layout-ul utilizatorului: doar cardurile din el se calculează (cele ascunse nu cer nimic).
        $layout = $this->layoutForCurrentUser();
        $layoutView = $this->viewForCurrentUser();
        $inLayout = array_fill_keys(array_column($layout, 'id'), true);
        $needsOverview = isset($inLayout['fleet.vehicles']) || isset($inLayout['fleet.drivers']) || isset($inLayout['finance.operational_cost']);

        $dashboardError = null;
        try {
            $dashboard = $needsOverview
                ? $this->dashboardModel->getDashboardOverview($filters)
                : $this->dashboardModel->getEmptyDashboardOverview($filters);
        } catch (Throwable $exception) {
            error_log('[DashboardController][index] ' . $exception->getMessage());
            $dashboard = $this->dashboardModel->getEmptyDashboardOverview($filters);
            $dashboardError = 'Datele nu au putut fi încărcate. Încearcă din nou.';
        }

        $filters['period_range'] = $dashboard['period_range'] ?? $this->dashboardModel->getPeriodRangeForFilters($filters);
        $filters['period_range_label'] = $this->formatPeriodRangeLabel($filters['period_range']);
        $filters['vehicle_registration'] = $filters['vehicle_id'] !== null ? $filters['vehicle_label'] : null;

        // Cardul „Cost total operațional” (salarii, costuri, cheltuieli, documente, dotări) cere
        // dreptul „Date financiare” din Tablou de bord (implicit doar admin). Fără el nu se
        // calculează nimic, ca datele să nu ajungă nici în HTML, nici în reîmprospătarea live.
        $canFinancial = !function_exists('can') || can('dashboard', 'view_financial');
        $operationalCosts = null;
        $expenseBreakdown = null;
        $documentCosts = null;
        $equipmentCosts = null;
        $showCostCard = $canFinancial && isset($inLayout['finance.operational_cost']);
        $showActivitySplit = $canFinancial && isset($inLayout['finance.activity_split']);
        if ($showCostCard || $showActivitySplit) {
            // Cost total operațional = modelul complet din Cost operațional / km (fixe + variabile,
            // fără TVA). Modelul include salarii, deci cere același drept ca pagina Cost / km.
            // Fără drept sau fără motor, cardul rămâne pe carburant + mentenanță.
            // Același calcul alimentează și cardul „Activitate vs. cheltuieli” (o singură rulare).
            $canViewCostModel = !function_exists('can') || can('cost_operational');
            if ($canViewCostModel) {
                try {
                    $operationalCosts = (new DashboardOperationalCostService($this->db))->build($filters, $filters['period_range']);
                    if (empty($operationalCosts['available'])) {
                        $operationalCosts = null;
                    }
                } catch (Throwable $exception) {
                    error_log('[DashboardController][operational_costs] ' . $exception->getMessage());
                }
            }

        }
        if ($showCostCard) {
            // Cheltuielile din registrul page=cheltuieli (administrative / operaționale), cu logica
            // paginii: valoarea documentului (cu TVA) + carburantul. Informativ: nu se adună la
            // costul total, care conține deja carburantul și managementul (fără TVA).
            if (!function_exists('can') || can('cheltuieli')) {
                try {
                    $expenseBreakdown = (new ExpenseModel($this->db))->getCategoryTypeBreakdown([
                        'date_start' => (string) ($filters['period_range']['date_start'] ?? ''),
                        'date_end' => (string) ($filters['period_range']['date_end'] ?? ''),
                        'vehicul_id' => (int) ($filters['vehicle_id'] ?? 0),
                    ]);
                } catch (Throwable $exception) {
                    error_log('[DashboardController][cheltuieli] ' . $exception->getMessage());
                }
            }

            // Costul documentelor (vehicule + șoferi) din Configurare costuri, pe perioada filtrului.
            try {
                $documentCosts = $this->dashboardModel->getDocumentCostBreakdown($filters);
            } catch (Throwable $exception) {
                error_log('[DashboardController][document_costs] ' . $exception->getMessage());
            }

            try {
                $equipmentCosts = $this->dashboardModel->getEquipmentCostBreakdown($filters);
            } catch (Throwable $exception) {
                error_log('[DashboardController][equipment_costs] ' . $exception->getMessage());
            }
        }

        $canReviewInactiveApprovals = $this->canReviewInactiveApprovals();
        $approvalSummary = [
            'counts' => ['vehicle' => 0, 'driver' => 0, 'repair' => 0],
            'total' => 0,
            'vehicles' => [],
            'drivers' => [],
            'repairs' => [],
        ];
        if ($canReviewInactiveApprovals) {
            try {
                $approvalSummary = $this->approvalModel->getPendingSummary(3);
            } catch (Throwable $exception) {
                error_log('[DashboardController][inactive_approvals] ' . $exception->getMessage());
            }
        }

        $widgetData = $this->buildPageWidgetData($inLayout, $filters, $operationalCosts);

        render('dashboard/index.php', [
            'dashboardLayout' => $layout,
            'dashboardView' => $layoutView,
            'dashboardCatalog' => DashboardWidgetRegistry::clientCatalog(),
            'widgetData' => $widgetData,
            'pageTitle' => 'Dashboard',
            'currentPage' => 'dashboard',
            'dashboard' => $dashboard,
            'dashboardError' => $dashboardError,
            'dashboardFilters' => $filters,
            'periodOptions' => $periodOptions,
            'vehicleCategoryOptions' => $vehicleCategoryOptions,
            'vehicleOptions' => $vehicleOptions,
            'approvalSummary' => $approvalSummary,
            'canReviewInactiveApprovals' => $canReviewInactiveApprovals,
            'operationalCosts' => $operationalCosts,
            'expenseBreakdown' => $expenseBreakdown,
            'documentCosts' => $documentCosts,
            'equipmentCosts' => $equipmentCosts,
            'canViewFinancial' => $canFinancial,
        ]);
    }

    /**
     * Datele cardurilor „page” noi, calculate doar dacă sunt în layout și permise.
     * Fiecare apelează aceeași metodă de model ca pagina-sursă.
     */
    private function buildPageWidgetData(array $inLayout, array $filters, ?array $operationalCosts): array
    {
        $data = [];
        $vehicleId = $filters['vehicle_id'] !== null ? (int) $filters['vehicle_id'] : null;
        $allowed = static function (string $id) use ($inLayout): bool {
            $widget = DashboardWidgetRegistry::get($id);
            return isset($inLayout[$id]) && $widget !== null && DashboardWidgetRegistry::canAccess($widget);
        };

        // Intervenții și Documente se încarcă leneș (action=widget), cu filtrele din card.

        if ($allowed('finance.activity_split')) {
            $data['activity_split'] = $operationalCosts !== null ? $this->buildActivitySplit($operationalCosts) : null;
        }

        return $data;
    }

    /**
     * Populațiile operațională / financiară din unitățile motorului Cost operațional / km
     * (aceleași cifre ca în cardul „Cost total operațional”, nimic adunat de două ori):
     *  - cu curse        = unități cu ≥1 cursă începută în perioadă (data_inceput, curse neșterse);
     *  - doar cheltuieli = unități fără curse, dar cu costuri; NU sunt marcate inactive;
     *  - costurile fără vehicul (companie) și carburantul pe numere din afara flotei grele
     *    rămân rânduri separate, ca totalul să bată cu cardul de cost.
     */
    private function buildActivitySplit(array $costs): array
    {
        $groups = [
            'both' => ['units' => 0, 'trips' => 0, 'km' => 0, 'revenue' => 0.0, 'cost' => 0.0],
            'trips_only' => ['units' => 0, 'trips' => 0, 'km' => 0, 'revenue' => 0.0, 'cost' => 0.0],
            'cost_only' => ['units' => 0, 'trips' => 0, 'km' => 0, 'revenue' => 0.0, 'cost' => 0.0],
            'idle' => ['units' => 0, 'trips' => 0, 'km' => 0, 'revenue' => 0.0, 'cost' => 0.0],
        ];
        $costOnlyRows = [];
        foreach ((array) ($costs['units'] ?? []) as $unit) {
            $hasTrips = (int) $unit['trips'] > 0;
            $hasCost = abs((float) $unit['cost']) >= 0.005;
            $key = $hasTrips ? ($hasCost ? 'both' : 'trips_only') : ($hasCost ? 'cost_only' : 'idle');
            $groups[$key]['units']++;
            $groups[$key]['trips'] += (int) $unit['trips'];
            $groups[$key]['km'] += (int) $unit['km'];
            $groups[$key]['revenue'] += (float) $unit['revenue'];
            $groups[$key]['cost'] += (float) $unit['cost'];
            if ($key === 'cost_only') {
                $costOnlyRows[] = $unit;
            }
        }
        usort($costOnlyRows, static fn(array $a, array $b): int => $b['cost'] <=> $a['cost']);

        $extra = ['nealocat' => 0.0, 'alte_vehicule' => 0.0];
        foreach (['fix', 'variabil'] as $tip) {
            foreach ((array) ($costs['tips'][$tip] ?? []) as $row) {
                if (isset($extra[$row['code']])) {
                    $extra[$row['code']] += (float) $row['total'];
                }
            }
        }

        $operational = [
            'units' => $groups['both']['units'] + $groups['trips_only']['units'],
            'trips' => $groups['both']['trips'] + $groups['trips_only']['trips'],
            'km' => $groups['both']['km'] + $groups['trips_only']['km'],
            'revenue' => $groups['both']['revenue'] + $groups['trips_only']['revenue'],
            'cost' => $groups['both']['cost'] + $groups['trips_only']['cost'],
        ];

        return [
            'groups' => $groups,
            'operational' => $operational,
            'revenue_per_km' => $operational['km'] > 0 ? $operational['revenue'] / $operational['km'] : null,
            'cost_per_km' => $operational['km'] > 0 ? $operational['cost'] / $operational['km'] : null,
            'cost_only_rows' => $costOnlyRows,
            'company_cost' => $extra['nealocat'],
            'other_vehicles_cost' => $extra['alte_vehicule'],
            'total_cost' => (float) ($costs['total'] ?? 0),
            'light_only' => !empty($costs['light_only']),
        ];
    }

    /** ?page=dashboard&action=widget&id=… — fragmentul unui card „lazy” (randat de componenta-sursă). */
    private function widget(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $id = is_string($_GET['id'] ?? null) ? (string) $_GET['id'] : '';
        $widget = DashboardWidgetRegistry::get($id);
        if ($widget === null || ($widget['render'] ?? '') !== 'lazy') {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Card necunoscut.']);
            return;
        }
        if (!DashboardWidgetRegistry::canAccess($widget)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Nu ai acces la acest card.']);
            return;
        }

        $partial = match ($id) {
            'dispecer.insights' => BASE_PATH . '/views/dispecer_curse/_insights_cards.php',
            'dispecer.live_gps' => BASE_PATH . '/views/dispecer_curse/_live_gps_panel.php',
            'drivers.activity' => BASE_PATH . '/views/dashboard/_driver_activity_card.php',
            'carburanti.kpis' => BASE_PATH . '/views/dashboard/_fuel_card.php',
            'rentabilitate.kpis' => BASE_PATH . '/views/dashboard/_rentabilitate_card.php',
            'cheltuieli.kpis' => BASE_PATH . '/views/dashboard/_expenses_card.php',
            'stoc.piese' => BASE_PATH . '/views/dashboard/_stock_card.php',
            'consumabile.alerts' => BASE_PATH . '/views/dashboard/_consumabile_card.php',
            'facturi.summary' => BASE_PATH . '/views/dashboard/_invoices_card.php',
            'etoll.kpis' => BASE_PATH . '/views/dashboard/_etoll_card.php',
            'centralizator.kpis' => BASE_PATH . '/views/dashboard/_centralizator_card.php',
            'mentenanta.interventions' => BASE_PATH . '/views/dashboard/_interventions_card.php',
            'documente.alerts' => BASE_PATH . '/views/dashboard/_documents_card.php',
            default => null,
        };

        // Filtrele panoului (aceeași validare ca pagina) + filtrele alese în card (config-ul lui, validat).
        $vehicleOptions = $this->dashboardModel->getVehicleOptions();
        $filters = $this->resolveFilters($this->getPeriodOptions(), $this->getVehicleCategoryOptions(), $vehicleOptions);
        $periodRange = $this->dashboardModel->getPeriodRangeForFilters($filters);
        $panelStart = (string) $periodRange['date_start'];
        $panelEnd = (string) $periodRange['date_end'];
        $panelVehicleId = (int) ($filters['vehicle_id'] ?? 0);
        // Paginile Carburanti / eToll filtreaza dupa numarul de inmatriculare, nu dupa id.
        $panelPlate = $panelVehicleId > 0 ? (string) $filters['vehicle_label'] : '';
        $panelClass = in_array($filters['vehicle_category'], ['grele', 'usoare'], true) ? (string) $filters['vehicle_category'] : '';
        $cardConfig = DashboardWidgetRegistry::normalizeConfig($widget, $_GET);
        // Intervalul cardului: cel ales în card, altfel perioada panoului.
        $dateStart = (string) ($cardConfig['date_from'] ?? $panelStart);
        $dateEnd = (string) ($cardConfig['date_to'] ?? $panelEnd);
        $vehicleId = $panelVehicleId;
        $vehiclePlate = $panelPlate;
        $vehicleClass = $panelClass;
        // Bara de filtre din card (views/dashboard/_card_filters.php).
        $cfWidget = $id;
        $cfFields = (array) ($widget['filter_fields'] ?? []);
        $cfConfig = $cardConfig;
        $cfOptions = [];
        $cfExtraLabels = [];
        $cfShownFrom = $dateStart;
        $vehicleSelect = [];
        foreach ($vehicleOptions as $v) {
            $vehicleSelect[(int) $v['id']] = (string) $v['nr_inmatriculare'];
        }
        $pairs = static function (array $rows, string $valueKey, string $labelKey): array {
            $out = [];
            foreach ($rows as $row) {
                $out[(string) ($row[$valueKey] ?? '')] = (string) ($row[$labelKey] ?? '');
            }
            unset($out['']);
            return $out;
        };
        if ($partial === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Card fără componentă.']);
            return;
        }

        ob_start();
        try {
            switch ($id) {
                case 'drivers.activity':
                    $cardVehicle = (int) ($cardConfig['vehicle_id'] ?? $panelVehicleId);
                    $driverCard = (new DriverActivityHistoryController($this->db))->dashboardCardData(
                        $dateStart,
                        $dateEnd,
                        $cardVehicle,
                        $cardConfig['driver_ids'] ?? [],
                        (int) ($cardConfig['beneficiar_id'] ?? 0),
                        (string) ($cardConfig['transport_type'] ?? '')
                    );
                    $cfOptions = [
                        'driver_ids' => $pairs((array) $driverCard['options'], 'id', 'nume'),
                        'transport_type' => (array) $driverCard['transport_labels'],
                        'beneficiar_id' => $pairs((array) $driverCard['beneficiary_options'], 'id', 'nume'),
                        'vehicle_id' => $pairs((array) $driverCard['vehicle_options'], 'id', 'label'),
                    ];
                    $cfExtraLabels = ['driver_ids' => array_map('strval', (array) $driverCard['dropped'])];
                    $cfShownFrom = (string) $driverCard['filters']['date_start'];
                    $driverSourceUrl = build_query_url([
                        'page' => 'istoric_activitati_sofer',
                        'date_start' => $driverCard['filters']['date_start'],
                        'date_end' => $driverCard['filters']['date_end'],
                        'vehicle_id' => $cardVehicle > 0 ? $cardVehicle : null,
                        'beneficiar_id' => !empty($cardConfig['beneficiar_id']) ? $cardConfig['beneficiar_id'] : null,
                        'transport_type' => $cardConfig['transport_type'] ?? null,
                        'driver_ids' => $driverCard['is_all'] ? null : $driverCard['filters']['driver_ids'],
                    ]);
                    break;

                case 'rentabilitate.kpis':
                    // Aceeasi sursa ca pagina (list() → kpiBlock), cu filtrele PROPRII ale cardului (config din layout).
                    $ropFilters = [
                        'mode' => (string) ($cardConfig['mode'] ?? 'vehicule'),
                        'from' => $dateStart,
                        'to' => $dateEnd,
                        'tip' => (string) ($cardConfig['tip'] ?? ''),
                        'beneficiar' => (int) ($cardConfig['beneficiar'] ?? 0),
                        'vehicle_id' => (int) ($cardConfig['vehicle_id'] ?? 0),
                        'driver_id' => (int) ($cardConfig['driver_id'] ?? 0),
                    ];
                    $ropKpi = (new ProfitabilityRegisterService($this->db))->kpiSummary($ropFilters);
                    $ropList = static function (array $rows): array {
                        $out = [];
                        foreach ($rows as $row) {
                            $out[(string) $row['id']] = (string) $row['label'];
                        }
                        return $out;
                    };
                    $cfOptions = [
                        'mode' => ['soferi' => 'Șoferi'],
                        'tip' => (array) $ropKpi['optiuni']['tipuri'],
                        'beneficiar' => array_map('strval', (array) $ropKpi['optiuni']['beneficiari']),
                        'vehicle_id' => $ropList((array) $ropKpi['optiuni']['vehicule']),
                        'driver_id' => $ropList((array) $ropKpi['optiuni']['soferi']),
                    ];
                    $cfShownFrom = (string) $ropKpi['from'];
                    $ropApplied = (array) $ropKpi['aplicat'];
                    $ropSourceUrl = build_query_url([
                        'page' => 'rentabilitate',
                        'mode' => $ropKpi['mode'] === 'soferi' ? 'soferi' : null,
                        'from' => $ropKpi['from'],
                        'to' => $ropKpi['to'],
                        'tip' => $ropApplied['tip'] !== '' ? $ropApplied['tip'] : null,
                        'beneficiar' => $ropApplied['beneficiar'] ?: null,
                        'vehicle_id' => $ropApplied['vehicle_id'] ?: null,
                        'driver_id' => $ropApplied['driver_id'] ?: null,
                    ]);
                    break;

                case 'carburanti.kpis':
                    $fuelInput = [
                        'date_from' => $dateStart,
                        'date_to' => $dateEnd,
                        'vehicles' => $cardConfig['vehicles'] ?? ($panelPlate !== '' ? [$panelPlate] : []),
                        'fuel_type' => $cardConfig['fuel_type'] ?? '',
                        'brand' => $cardConfig['brand'] ?? '',
                        'vehicle_class' => $cardConfig['vehicle_class'] ?? $panelClass,
                        'transport_group' => $cardConfig['transport_group'] ?? '',
                    ];
                    $fuelCard = (new FuelController($this->db))->dashboardKpiData($fuelInput);
                    $fuelVehicleRows = (array) ($fuelCard['fuelData']['vehicle_options'] ?? []);
                    $brands = [];
                    foreach ($fuelVehicleRows as $row) {
                        $brand = trim((string) ($row['marca'] ?? ''));
                        if ($brand !== '') {
                            $brands[$brand] = $brand;
                        }
                    }
                    ksort($brands, SORT_NATURAL | SORT_FLAG_CASE);
                    $cfOptions = [
                        'vehicles' => $pairs($fuelVehicleRows, 'vehicle_registration', 'vehicle_registration'),
                        'fuel_type' => ['motorina' => 'Motorină', 'adblue' => 'AdBlue', 'benzina' => 'Benzină', 'gpl' => 'GPL'],
                        'brand' => $brands,
                        'vehicle_class' => ['grele' => 'Vehicule grele', 'usoare' => 'Vehicule ușoare', 'neinregistrate' => 'Neînregistrate'],
                        'transport_group' => ['primar' => 'Primar', 'distributie' => 'Distribuție', 'compresor' => 'Compresor', 'primar_distributie' => 'Primar + Distribuție'],
                    ];
                    $fuelFilters = (array) $fuelCard['filters'];
                    $fuelSourceUrl = build_query_url(['page' => 'carburanti', 'date_from' => $fuelFilters['date_from'], 'date_to' => $fuelFilters['date_to'],
                        'vehicles' => $fuelFilters['vehicles'] ?: null, 'fuel_type' => $fuelFilters['fuel_type'], 'brand' => $fuelFilters['brand'],
                        'vehicle_class' => $fuelFilters['vehicle_class'], 'transport_group' => $fuelFilters['transport_group']]);
                    break;

                case 'cheltuieli.kpis':
                    // Aceleasi chei ca ExpenseController::collectFilters.
                    $expenseModel = new ExpenseModel($this->db);
                    $expenseFilters = [
                        'date_start' => $dateStart, 'date_end' => $dateEnd,
                        'categorie' => (string) ($cardConfig['categorie'] ?? ''),
                        'alocare' => (string) ($cardConfig['alocare'] ?? ''),
                        'beneficiar_id' => (int) ($cardConfig['beneficiar_id'] ?? 0),
                        'vehicul_id' => (int) ($cardConfig['vehicul_id'] ?? $panelVehicleId),
                        'sofer_id' => (int) ($cardConfig['sofer_id'] ?? 0),
                        'tip_id' => (int) ($cardConfig['tip_id'] ?? 0),
                        'furnizor' => (string) ($cardConfig['furnizor'] ?? ''),
                        'q' => '',
                    ];
                    $summary = $expenseModel->getSummary($expenseFilters);
                    $vehicleRows = array_map(static fn(array $v): array => ['id' => $v['id'], 'label' => trim($v['nr_inmatriculare'] . ' ' . ($v['marca'] ?? '') . ' ' . ($v['model'] ?? ''))], $expenseModel->getVehicles());
                    $cfOptions = [
                        'categorie' => ExpenseModel::CATEGORIES,
                        'tip_id' => $pairs($expenseModel->getTypes(), 'id', 'nume'),
                        'alocare' => ['vehicul' => 'Pe vehicul', 'sofer' => 'Pe șofer', 'companie' => 'Companie'],
                        'beneficiar_id' => $pairs($expenseModel->getBeneficiaries(), 'id', 'nume'),
                        'vehicul_id' => $pairs($vehicleRows, 'id', 'label'),
                        'sofer_id' => $pairs($expenseModel->getDrivers(), 'id', 'nume'),
                        'furnizor' => $expenseModel->getSuppliers(),
                    ];
                    $expensesSourceUrl = build_query_url(array_filter($expenseFilters, static fn($v): bool => $v !== '' && $v !== 0) + ['page' => 'cheltuieli']);
                    break;

                case 'stoc.piese':
                    $stockKpis = (new MaintenanceModel($this->db))->getStockKpis();
                    $stockSourceUrl = build_query_url(['page' => 'mentenanta', 'action' => 'stock']);
                    break;

                case 'consumabile.alerts':
                    $cons = (new ConsumabileController($this->db))->dashboardAlerts();
                    break;

                case 'facturi.summary':
                    // Aceleasi chei ca InvoiceController::collectFilters.
                    $invoiceModel = new InvoiceModel($this->db);
                    $invoiceVehicle = (int) ($cardConfig['vehicle_id'] ?? $panelVehicleId);
                    $summary = $invoiceModel->getSummary([
                        'tip' => (string) ($cardConfig['tip'] ?? ''),
                        'status' => (string) ($cardConfig['status'] ?? ''),
                        'data_start' => $dateStart, 'data_end' => $dateEnd,
                        'vehicle_id' => $invoiceVehicle > 0 ? $invoiceVehicle : null,
                        'driver_id' => !empty($cardConfig['driver_id']) ? (int) $cardConfig['driver_id'] : null,
                        'q' => '',
                        'sursa' => (string) ($cardConfig['sursa'] ?? ''),
                    ]);
                    $cfOptions = [
                        'tip' => array_map(static fn(array $t): string => (string) $t['label'], InvoiceModel::TYPES),
                        'status' => ['de_rezolvat' => 'De rezolvat (de verificat / neasociate / în procesare)', 'asociata' => 'Asociate (automat + manual)'] + InvoiceModel::STATUSES,
                        'vehicle_id' => $pairs($invoiceModel->getVehicles(), 'id', 'nr_inmatriculare'),
                        'driver_id' => $pairs($invoiceModel->getDrivers(), 'id', 'nume'),
                        'sursa' => InvoiceModel::SOURCES,
                    ];
                    $invoiceQuery = array_filter([
                        'page' => 'facturi', 'data_start' => $dateStart, 'data_end' => $dateEnd,
                        'vehicle_id' => $invoiceVehicle > 0 ? (string) $invoiceVehicle : '',
                        'tip' => (string) ($cardConfig['tip'] ?? ''), 'driver_id' => (string) ($cardConfig['driver_id'] ?? ''),
                        'sursa' => (string) ($cardConfig['sursa'] ?? ''),
                    ], static fn($v): bool => $v !== '');
                    break;

                case 'centralizator.kpis':
                    // Centralizatorul lucreaza pe luna de facturare: cea aleasa in card, altfel luna in care se termina perioada panoului.
                    $cfMonth = (string) ($cardConfig['month'] ?? substr($panelEnd, 0, 7));
                    $cfInput = ['month' => $cfMonth];
                    foreach (['beneficiar_id', 'tip_activitate', 'tip_marfa', 'loc_incarcare_id', 'zona_distributie_id', 'ruta', 'vehicle_id'] as $key) {
                        $cfInput[$key] = $cardConfig[$key] ?? [];
                    }
                    if ($cfInput['vehicle_id'] === [] && $panelVehicleId > 0) {
                        $cfInput['vehicle_id'] = [(string) $panelVehicleId];
                    }
                    $cfReport = (new CentralizatorFacturareService($this->db))->getKpiReport($cfInput);
                    $lookups = (array) ($cfReport['lookups'] ?? []);
                    $cfOptions = [
                        'month' => $pairs(array_values((array) ($lookups['months'] ?? [])), 'value', 'label'),
                        'beneficiar_id' => $pairs((array) ($lookups['beneficiaries'] ?? []), 'id', 'nume'),
                        'tip_activitate' => array_diff_key((array) ($lookups['activity_types'] ?? []), ['' => true]),
                        'tip_marfa' => $pairs((array) ($lookups['cargo'] ?? []), 'value', 'label'),
                        'loc_incarcare_id' => $pairs((array) ($lookups['loading_locations'] ?? []), 'id', 'label'),
                        'zona_distributie_id' => $pairs((array) ($lookups['unloading_zones'] ?? []), 'id', 'label'),
                        'ruta' => $pairs((array) ($lookups['routes'] ?? []), 'value', 'label'),
                        'vehicle_id' => $pairs((array) ($lookups['vehicles'] ?? []), 'id', 'nr_inmatriculare'),
                    ];
                    $cfShownFrom = $cfMonth . '-01';
                    $cfQuery = array_filter($cfInput, static fn($v): bool => $v !== [] && $v !== '') + ['page' => 'centralizator_facturare'];
                    $cfSourceUrl = build_query_url($cfQuery);
                    $cfRefacturariUrl = $cfSourceUrl . '#cf_ref_table';
                    break;

                case 'etoll.kpis':
                    $etollQuery = [];
                    foreach (['nr', 'cap', 'cat', 'ben', 'tip', 'status'] as $key) {
                        if (!empty($cardConfig[$key])) {
                            $etollQuery[$key] = $cardConfig[$key];
                        }
                    }
                    if (empty($etollQuery['nr']) && $panelPlate !== '') {
                        $etollQuery['nr'] = [$panelPlate];
                    }
                    $etollCard = (new EtollStatementController($this->db))->dashboardKpiData($dateStart, $dateEnd, $etollQuery);
                    $cfOptions = (array) $etollCard['filterOptions'];
                    $etollSourceUrl = build_query_url(['page' => 'etoll', 'perioada' => 'interval', 'de_la' => $dateStart, 'pana_la' => $dateEnd] + $etollQuery);
                    break;

                case 'mentenanta.interventions':
                    $workflow = new InterventionWorkflowModel($this->db);
                    $ivVehicle = (int) ($cardConfig['vehicle_id'] ?? $panelVehicleId);
                    // Aceleași chei ca MaintenanceController::collectInterventionFilters; data doar dacă e aleasă în card (ca în pagină).
                    $ivFilters = [
                        'search' => '', 'vehicle_id' => $ivVehicle, 'tip' => (string) ($cardConfig['tip'] ?? ''), 'status' => '',
                        'date_from' => (string) ($cardConfig['date_from'] ?? ''), 'date_to' => (string) ($cardConfig['date_to'] ?? ''),
                        'responsabil' => (string) ($cardConfig['responsabil'] ?? ''), 'sursa' => (string) ($cardConfig['sursa'] ?? ''),
                    ];
                    $ivKpiValues = $workflow->kpis($ivFilters);
                    $responsibles = ['nealocat' => 'Nealocat'];
                    foreach ($workflow->getCoordinators() as $person) {
                        $responsibles[(string) ($person['id'] ?? '')] = (string) ($person['nume'] ?? '');
                    }
                    $cfOptions = [
                        'tip' => ['intretinere' => 'Întreținere', 'reparatie' => 'Reparație'],
                        'responsabil' => $responsibles,
                        'sursa' => ['km_service' => 'Din Km service', 'manual' => 'Manual'],
                        'vehicle_id' => $vehicleSelect,
                    ];
                    $cfShownFrom = (string) ($cardConfig['date_from'] ?? $panelStart);
                    $ivQuery = array_filter($ivFilters, static fn($v): bool => $v !== '' && $v !== 0) + ['page' => 'mentenanta', 'action' => 'interventions'];
                    $ivSourceUrl = build_query_url($ivQuery);
                    $ivKpiHref = static fn(string $status): string => build_query_url($ivQuery + ['status' => $status]);
                    break;

                case 'documente.alerts':
                    $docVehicle = (int) ($cardConfig['vehicle_id'] ?? $panelVehicleId);
                    $documentModel = new DocumentModel($this->db);
                    $documentSummary = $documentModel->getNotificationSummary($docVehicle > 0 ? $docVehicle : null);
                    $urgentDocuments = $documentModel->getUrgentDocuments($docVehicle > 0 ? $docVehicle : null, 5);
                    $documentQuickBase = ['page' => 'documente', 'action' => 'index', 'vehicle_id' => $docVehicle > 0 ? $docVehicle : ''];
                    $cfOptions = ['vehicle_id' => $vehicleSelect];
                    break;
            }
            require $partial;
            $html = (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            error_log('[DashboardController][widget:' . $id . '] ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Cardul nu a putut fi încărcat.']);
            return;
        }

        // Fișierele locale primesc versiunea (filemtime); bibliotecile externe (ex. Chart.js) rămân ca în pagina-sursă.
        $versioned = static fn(string $path): string => str_starts_with($path, 'https://')
            ? $path
            : url($path . '?v=' . (string) @filemtime(BASE_PATH . '/' . $path));
        echo json_encode([
            'ok' => true,
            'html' => $html,
            'css' => array_map($versioned, (array) ($widget['assets']['css'] ?? [])),
            'js' => array_map($versioned, (array) ($widget['assets']['js'] ?? [])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** POST ?page=dashboard&action=save_layout — {csrf, items:[{id,size}]} sau {csrf, reset:true}. */
    private function saveLayout(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Metodă nepermisă.']);
            return;
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];
        if (!verify_csrf_token((string) ($body['csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Token CSRF invalid. Reîncarcă pagina.']);
            return;
        }

        $reset = !empty($body['reset']);
        $items = $reset ? DashboardWidgetRegistry::defaultLayoutForUser() : DashboardWidgetRegistry::normalizeLayout($body['items'] ?? []);
        // Modul de afișare: carusel (pagini cu săgeți, implicit) sau grilă (toate cardurile, cu scroll).
        $view = !$reset && in_array($body['view'] ?? null, self::VIEW_MODES, true) ? (string) $body['view'] : self::VIEW_MODES[0];
        $isDefault = $reset || ($items === DashboardWidgetRegistry::defaultLayoutForUser() && $view === self::VIEW_MODES[0]);

        try {
            $this->ensureLayoutColumn();
            $stmt = $this->db->prepare('UPDATE utilizatori SET dashboard_layout = :v WHERE id = :id');
            $stmt->execute([
                ':v' => $isDefault ? null : json_encode(['v' => 1, 'items' => $items, 'view' => $view], JSON_UNESCAPED_UNICODE),
                ':id' => (int) (current_user()['id'] ?? 0),
            ]);
        } catch (Throwable $exception) {
            error_log('[DashboardController][save_layout] ' . $exception->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Configurația nu a putut fi salvată.']);
            return;
        }

        echo json_encode(['ok' => true, 'items' => $items, 'view' => $view], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Layout-ul salvat al utilizatorului curent, filtrat după drepturile de acum
     * (un drept retras ascunde cardul fără să strice restul). NULL / lipsă = implicit.
     *
     * @return list<array{id:string,size:string}>
     */
    private function layoutForCurrentUser(): array
    {
        $decoded = $this->savedLayout();
        if (!is_array($decoded) || !is_array($decoded['items'] ?? null)) {
            return DashboardWidgetRegistry::defaultLayoutForUser();
        }

        return DashboardWidgetRegistry::normalizeLayout($decoded['items']);
    }

    /** Modul de afișare salvat: 'carousel' (implicit) sau 'grid'. */
    private function viewForCurrentUser(): string
    {
        $view = $this->savedLayout()['view'] ?? null;

        return in_array($view, self::VIEW_MODES, true) ? (string) $view : self::VIEW_MODES[0];
    }

    /** JSON-ul din utilizatori.dashboard_layout (citit o dată pe cerere); null = implicit. */
    private function savedLayout(): ?array
    {
        if ($this->savedLayoutLoaded) {
            return $this->savedLayoutCache;
        }
        $this->savedLayoutLoaded = true;
        try {
            $stmt = $this->db->prepare('SELECT dashboard_layout FROM utilizatori WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) (current_user()['id'] ?? 0)]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable $exception) {
            return $this->savedLayoutCache = null; // coloana apare la prima salvare
        }
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return $this->savedLayoutCache = is_array($decoded) ? $decoded : null;
    }

    /** Vezi database/migrations/2026_11_16_000002_utilizatori_dashboard_layout.sql. */
    private function ensureLayoutColumn(): void
    {
        $exists = $this->db->query("SHOW COLUMNS FROM utilizatori LIKE 'dashboard_layout'")->fetch();
        if (!$exists) {
            $this->db->exec('ALTER TABLE utilizatori ADD COLUMN dashboard_layout TEXT NULL');
        }
    }

    private function canReviewInactiveApprovals(): bool
    {
        if (function_exists('can')) {
            return can('inactive_approvals', 'review');
        }

        return function_exists('is_admin') && is_admin();
    }

    private function resolveFilters(array $periodOptions, array $vehicleCategoryOptions, array $vehicleOptions): array
    {
        $period = isset($_GET['period']) && is_string($_GET['period'])
            ? trim($_GET['period'])
            : 'luna_curenta';

        if (!array_key_exists($period, $periodOptions)) {
            $period = 'luna_curenta';
        }

        $vehicleCategory = isset($_GET['vehicle_category']) && is_string($_GET['vehicle_category'])
            ? trim($_GET['vehicle_category'])
            : 'toate';

        if (!array_key_exists($vehicleCategory, $vehicleCategoryOptions)) {
            $vehicleCategory = 'toate';
        }

        $vehicleId = null;
        if (isset($_GET['vehicle_id']) && is_scalar($_GET['vehicle_id'])) {
            $rawVehicleId = trim((string) $_GET['vehicle_id']);
            if ($rawVehicleId !== '' && ctype_digit($rawVehicleId)) {
                $vehicleId = (int) $rawVehicleId;
            }
        }

        $vehicleLabel = "Toate vehiculele";
        $validVehicleIds = [];

        foreach ($vehicleOptions as $vehicle) {
            $currentVehicleId = (int) ($vehicle['id'] ?? 0);
            $validVehicleIds[] = $currentVehicleId;

            if ($vehicleId !== null && $currentVehicleId === $vehicleId) {
                $vehicleLabel = (string) $vehicle['nr_inmatriculare'];
            }
        }

        if ($vehicleId !== null && !in_array($vehicleId, $validVehicleIds, true)) {
            $vehicleId = null;
            $vehicleLabel = "Toate vehiculele";
        }

        // Perioada personalizata (butonul "Perioadă" din filtre): ambele capete valide,
        // altfel se revine la luna curenta.
        $dateFrom = null;
        $dateTo = null;
        if ($period === 'personalizat') {
            $dateFrom = $this->parseDate(is_string($_GET['date_from'] ?? null) ? trim($_GET['date_from']) : '');
            $dateTo = $this->parseDate(is_string($_GET['date_to'] ?? null) ? trim($_GET['date_to']) : '');
            if ($dateFrom === null || $dateTo === null) {
                $period = 'luna_curenta';
                $dateFrom = $dateTo = null;
            } elseif ($dateFrom > $dateTo) {
                [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            }
        }

        return [
            'period' => $period,
            'date_from' => $dateFrom?->format('Y-m-d'),
            'date_to' => $dateTo?->format('Y-m-d'),
            'period_label' => $periodOptions[$period],
            'vehicle_category' => $vehicleCategory,
            'vehicle_category_label' => $vehicleCategoryOptions[$vehicleCategory],
            'vehicle_id' => $vehicleId,
            'vehicle_label' => $vehicleLabel,
        ];
    }

    private function getPeriodOptions(): array
    {
        return [
            'luna_curenta' => "Luna curent\u{0103}",
            'ultimele_30_zile' => "Ultimele 30 de zile",
            'an_curent' => "Anul curent",
            'personalizat' => "Personalizat",
        ];
    }

    private function getVehicleCategoryOptions(): array
    {
        return [
            'toate' => 'Toate',
            'grele' => 'Vehicule grele',
            'usoare' => 'Vehicule ușoare',
        ];
    }

    private function formatPeriodRangeLabel(array $periodRange): string
    {
        $start = $this->parseDate((string) ($periodRange['date_start'] ?? ''));
        $end = $this->parseDate((string) ($periodRange['date_end'] ?? ''));

        if ($start === null || $end === null) {
            return '';
        }

        $months = [
            1 => 'ianuarie',
            2 => 'februarie',
            3 => 'martie',
            4 => 'aprilie',
            5 => 'mai',
            6 => 'iunie',
            7 => 'iulie',
            8 => 'august',
            9 => 'septembrie',
            10 => 'octombrie',
            11 => 'noiembrie',
            12 => 'decembrie',
        ];

        $startDay = (int) $start->format('j');
        $endDay = (int) $end->format('j');
        $startMonth = $months[(int) $start->format('n')] ?? $start->format('m');
        $endMonth = $months[(int) $end->format('n')] ?? $end->format('m');
        $startYear = $start->format('Y');
        $endYear = $end->format('Y');

        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $startDay . ' ' . $startMonth . ' ' . $startYear;
        }

        if ($startYear === $endYear && $startMonth === $endMonth) {
            return $startDay . ' – ' . $endDay . ' ' . $endMonth . ' ' . $endYear;
        }

        if ($startYear === $endYear) {
            return $startDay . ' ' . $startMonth . ' – ' . $endDay . ' ' . $endMonth . ' ' . $endYear;
        }

        return $startDay . ' ' . $startMonth . ' ' . $startYear . ' – ' . $endDay . ' ' . $endMonth . ' ' . $endYear;
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
