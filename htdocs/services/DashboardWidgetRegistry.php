<?php
declare(strict_types=1);

/**
 * Registrul cardurilor din Tablou de bord (?page=dashboard).
 *
 * Fiecare intrare descrie un card EXISTENT din aplicație (componenta reutilizată, nu o copie):
 *  - module / source      pagina de origine și componenta (partial / modul JS) refolosită;
 *  - requires             drepturile cerute, TOATE trebuie să treacă can(); verificate și în
 *                         configurare, și la randare, și la salvarea layout-ului;
 *  - filters              ce filtre globale ale dashboard-ului se aplică (period, vehicle_category,
 *                         vehicle_id); restul sunt ignorate de card și marcate ca atare;
 *  - time                 semantica timpului: period | current | live | month_to_date | rolling_30;
 *  - sizes / default_size dimensiuni permise în grila de 12 coloane (s=4, m=6, l=8, xl=12);
 *  - render               'page' = randat de server odată cu pagina (doar dacă e în layout) și
 *                         reîmprospătat de dashboard-live.js la schimbarea filtrelor;
 *                         'lazy' = fragment cerut la ?page=dashboard&action=widget când cardul
 *                         intră în ecran; modulul JS al paginii-sursă pornește singur pe el;
 *  - refresh              none | on_filter | interval (doar cardurile live își fac singure polling);
 *  - population           operational (vehicule cu curse) | financial (vehicule cu costuri) |
 *                         both | fleet (stare flotă) | none;
 *  - link                 pagina-sursă (filtrele se traduc în parametrii ei la randare).
 *
 * Layout-ul unui utilizator păstrează doar id + dimensiune (utilizatori.dashboard_layout), nu date.
 */
final class DashboardWidgetRegistry
{
    public const SIZES = ['s', 'm', 'l', 'xl'];

    public const SIZE_LABELS = ['s' => 'Mic', 'm' => 'Mediu', 'l' => 'Mare', 'xl' => 'Lățime completă'];

    public const TIME_LABELS = [
        'period' => 'Perioada filtrată',
        'current' => 'Stare curentă',
        'live' => 'Live',
        'month_to_date' => 'Luna curentă',
        'rolling_30' => 'Ultimele 30 de zile',
        'mixed' => 'Live + luna curentă',
        'billing_month' => 'Luna de facturare',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitions = null;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $defs = [
            'fleet.vehicles' => [
                'module' => 'Vehicule',
                'title' => 'Status vehicule',
                'description' => 'Vehicule active / inactive, pe motiv și pe tip (unitate cap tractor + semiremorcă = 1).',
                'icon' => 'bi-truck-front',
                'source' => 'views/dashboard/index.php (DashboardModel::getVehicleDashboardStatus)',
                'requires' => [],
                'filters' => ['vehicle_category', 'vehicle_id'],
                'time' => 'current',
                'sizes' => ['s', 'm'],
                'default_size' => 's',
                'render' => 'page',
                'refresh' => 'on_filter',
                'population' => 'fleet',
                'link' => ['page' => 'vehicule'],
            ],
            'fleet.drivers' => [
                'module' => 'Șoferi',
                'title' => 'Status șoferi',
                'description' => 'Șoferi activi / inactivi, cu motivele de inactivitate (concediu, documente).',
                'icon' => 'bi-person',
                'source' => 'views/dashboard/index.php (DashboardModel::getDriverDashboardStatus)',
                'requires' => [],
                'filters' => ['vehicle_category', 'vehicle_id'],
                'time' => 'current',
                'sizes' => ['s', 'm'],
                'default_size' => 's',
                'render' => 'page',
                'refresh' => 'on_filter',
                'population' => 'fleet',
                'link' => ['page' => 'soferi'],
            ],
            'finance.operational_cost' => [
                'module' => 'Cost operațional / km',
                'title' => 'Cost total operațional',
                'description' => 'Costuri fixe + variabile fără TVA (motorul Cost operațional / km), cheltuieli, documente, dotări.',
                'icon' => 'bi-cash-coin',
                'source' => 'views/dashboard/_operational_card.php (DashboardOperationalCostService)',
                'requires' => [['dashboard', 'view_financial']],
                'filters' => ['period', 'vehicle_category', 'vehicle_id'],
                'time' => 'period',
                'sizes' => ['s', 'm', 'l'],
                'default_size' => 's',
                'render' => 'page',
                'refresh' => 'on_filter',
                'population' => 'financial',
                'link' => ['page' => 'cost_operational'],
            ],
            'finance.activity_split' => [
                'module' => 'Cost operațional / km',
                'title' => 'Activitate vs. cheltuieli',
                'description' => 'Vehicule cu curse în Dispecer vs. vehicule doar cu costuri, cu venitul separat de cheltuieli.',
                'icon' => 'bi-diagram-2',
                'source' => 'views/dashboard/_activity_split_card.php (unitățile din OperationalCostService)',
                'requires' => [['dashboard', 'view_financial'], ['cost_operational', 'view']],
                'filters' => ['period', 'vehicle_category', 'vehicle_id'],
                'time' => 'period',
                'sizes' => ['m', 'l', 'xl'],
                'default_size' => 'm',
                'render' => 'page',
                'refresh' => 'on_filter',
                'population' => 'both',
                'link' => ['page' => 'cost_operational'],
            ],
            'drivers.activity' => [
                'module' => 'Istoric activități șofer',
                'title' => 'Activitate șoferi',
                'description' => 'Cardurile KPI din Istoric activități șofer (curse, km, tonaj, ore de condus, consum, cost, salariu, diurne, profit) pe totalul șoferilor cu curse.',
                'icon' => 'bi-person-badge',
                'source' => 'views/driver_activity_history/_kpi_cards.php (DriverActivityHistoryController::dashboardCardData)',
                'requires' => [['istoric_activitati_sofer', 'view']],
                'filters' => ['period', 'vehicle_id'],
                'time' => 'period',
                'sizes' => ['l', 'xl'],
                'default_size' => 'xl',
                'render' => 'lazy',
                'refresh' => 'on_filter',
                'population' => 'operational',
                // A doua față a cardurilor (defalcarea pe tip de transport) e stilizată aici.
                'assets' => ['css' => ['assets/css/driver-history-trips.css'], 'js' => []],
                // Filtrele paginii Istoric activități șofer, alese în card și păstrate în layout-ul utilizatorului
                // (gol = ce dă panoul: toți șoferii cu curse, perioada și vehiculul de sus).
                'config' => [
                    'driver_ids' => 'int_list',
                    'date_from' => 'date',
                    'date_to' => 'date',
                    'transport_type' => ['enum', array_keys(DriverActivityHistoryModel::TRANSPORT_LABELS)],
                    'beneficiar_id' => 'int',
                    'vehicle_id' => 'int',
                ],
                'filter_fields' => [
                    ['key' => 'driver_ids', 'label' => 'Șoferi', 'type' => 'multi', 'all' => 'Toți șoferii cu curse'],
                    ['key' => 'period', 'label' => 'Interval de timp', 'type' => 'period', 'all' => 'Perioada panoului'],
                    ['key' => 'transport_type', 'label' => 'Tip transport', 'type' => 'select', 'all' => 'Toate tipurile'],
                    ['key' => 'beneficiar_id', 'label' => 'Beneficiar', 'type' => 'select', 'all' => 'Toți beneficiarii'],
                    ['key' => 'vehicle_id', 'label' => 'Vehicul', 'type' => 'select', 'all' => 'Toate vehiculele'],
                ],
                'link' => ['page' => 'istoric_activitati_sofer'],
            ],
            'carburanti.kpis' => [
                'module' => 'Carburanți',
                'title' => 'Carburant: consum, cost, pe categorie',
                'description' => 'Cele 5 carduri KPI din Carburanți (cu analiza extinsă la click) și costul pe vehicule grele / ușoare / neînregistrate.',
                'icon' => 'bi-fuel-pump',
                'source' => 'views/carburanti/_kpi_cards.php + assets/js/fuel-kpi-morph.js (FuelController::dashboardKpiData)',
                'requires' => [['carburanti', 'view']],
                'filters' => ['period', 'vehicle_category', 'vehicle_id'],
                'time' => 'period',
                'sizes' => ['l', 'xl'],
                'default_size' => 'xl',
                'render' => 'lazy',
                'refresh' => 'on_filter',
                'population' => 'financial',
                // Chart.js: aceeași versiune ca pagina Carburanți (graficul „Evoluție” din analiza extinsă).
                'assets' => ['css' => ['assets/css/fuel-kpi-morph.css'], 'js' => ['https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', 'assets/js/fuel-kpi-morph.js']],
                // Filtrele paginii Carburanți.
                'config' => [
                    'date_from' => 'date',
                    'date_to' => 'date',
                    'vehicles' => ['str_list', '/^[\p{L}\p{N} \-]{2,20}$/u'],
                    'fuel_type' => ['enum', ['motorina', 'adblue', 'benzina', 'gpl']],
                    'brand' => 'str',
                    'vehicle_class' => ['enum', ['grele', 'usoare', 'neinregistrate']],
                    'transport_group' => ['enum', ['primar', 'distributie', 'compresor', 'primar_distributie']],
                ],
                'filter_fields' => [
                    ['key' => 'period', 'label' => 'Perioadă', 'type' => 'period', 'all' => 'Perioada panoului'],
                    ['key' => 'vehicles', 'label' => 'Vehicule', 'type' => 'multi', 'all' => 'Toate vehiculele'],
                    ['key' => 'fuel_type', 'label' => 'Carburant', 'type' => 'select', 'all' => 'Toate tipurile'],
                    ['key' => 'brand', 'label' => 'Marcă', 'type' => 'select', 'all' => 'Toate mărcile'],
                    ['key' => 'vehicle_class', 'label' => 'Categorie vehicul', 'type' => 'select', 'all' => 'Ca filtrul de sus'],
                    ['key' => 'transport_group', 'label' => 'Tip transport', 'type' => 'select', 'all' => 'Toate'],
                ],
                'link' => ['page' => 'carburanti'],
            ],
            'cheltuieli.kpis' => [
                'module' => 'Cheltuieli',
                'title' => 'Cheltuieli: total, administrative, operaționale',
                'description' => 'Banda KPI din registrul Cheltuieli (valoarea documentelor cu TVA + carburantul).',
                'icon' => 'bi-receipt',
                'source' => 'views/cheltuieli/_kpi_band.php (ExpenseModel::getSummary)',
                'requires' => [['cheltuieli', 'view']],
                'filters' => ['period', 'vehicle_id'],
                'time' => 'period',
                'sizes' => ['m', 'l', 'xl'],
                'default_size' => 'm',
                'render' => 'lazy',
                'refresh' => 'on_filter',
                'population' => 'financial',
                'assets' => ['css' => [], 'js' => []],
                // Filtrele registrului Cheltuieli.
                'config' => [
                    'date_from' => 'date',
                    'date_to' => 'date',
                    'categorie' => ['enum', ['administrativa', 'operationala']],
                    'alocare' => ['enum', ['vehicul', 'sofer', 'companie']],
                    'beneficiar_id' => 'int',
                    'vehicul_id' => 'int',
                    'sofer_id' => 'int',
                    'tip_id' => 'int',
                    'furnizor' => 'str',
                ],
                'filter_fields' => [
                    ['key' => 'period', 'label' => 'Perioadă', 'type' => 'period', 'all' => 'Perioada panoului'],
                    ['key' => 'categorie', 'label' => 'Categorie', 'type' => 'select', 'all' => 'Toate categoriile'],
                    ['key' => 'tip_id', 'label' => 'Tip cheltuială', 'type' => 'select', 'all' => 'Toate tipurile'],
                    ['key' => 'alocare', 'label' => 'Alocare', 'type' => 'select', 'all' => 'Toate alocările'],
                    ['key' => 'beneficiar_id', 'label' => 'Beneficiar', 'type' => 'select', 'all' => 'Toți beneficiarii'],
                    ['key' => 'vehicul_id', 'label' => 'Vehicul', 'type' => 'select', 'all' => 'Toate vehiculele'],
                    ['key' => 'sofer_id', 'label' => 'Șofer', 'type' => 'select', 'all' => 'Toți șoferii'],
                    ['key' => 'furnizor', 'label' => 'Furnizor', 'type' => 'text', 'all' => 'Orice furnizor'],
                ],
                'link' => ['page' => 'cheltuieli'],
            ],
            'facturi.summary' => [
                'module' => 'Facturi',
                'title' => 'Facturi: asociate, de rezolvat, respinse',
                'description' => 'Cardurile de sumar din pagina Facturi; click deschide lista filtrată pe status.',
                'icon' => 'bi-file-earmark-ruled',
                'source' => 'views/facturi/_summary_cards.php (InvoiceModel::getSummary)',
                'requires' => [['facturi', 'view']],
                'filters' => ['period', 'vehicle_id'],
                'time' => 'period',
                'sizes' => ['m', 'l', 'xl'],
                'default_size' => 'l',
                'render' => 'lazy',
                'refresh' => 'on_filter',
                'population' => 'financial',
                'assets' => ['css' => ['assets/css/facturi.css'], 'js' => []],
                // Filtrele paginii Facturi.
                'config' => [
                    'date_from' => 'date',
                    'date_to' => 'date',
                    'tip' => ['enum', array_keys(InvoiceModel::TYPES)],
                    'status' => ['enum', array_merge(['de_rezolvat', 'asociata'], array_keys(InvoiceModel::STATUSES))],
                    'vehicle_id' => 'int',
                    'driver_id' => 'int',
                    'sursa' => ['enum', array_keys(InvoiceModel::SOURCES)],
                ],
                'filter_fields' => [
                    ['key' => 'period', 'label' => 'Data documentului', 'type' => 'period', 'all' => 'Perioada panoului'],
                    ['key' => 'tip', 'label' => 'Tip', 'type' => 'select', 'all' => 'Toate tipurile'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'select', 'all' => 'Toate statusurile'],
                    ['key' => 'vehicle_id', 'label' => 'Vehicul', 'type' => 'select', 'all' => 'Toate vehiculele'],
                    ['key' => 'driver_id', 'label' => 'Șofer', 'type' => 'select', 'all' => 'Toți șoferii'],
                    ['key' => 'sursa', 'label' => 'Sursă', 'type' => 'select', 'all' => 'Toate sursele'],
                ],
                'link' => ['page' => 'facturi'],
            ],
            'etoll.kpis' => [
                'module' => 'Situație eToll',
                'title' => 'eToll: sume, sesiuni, alimentări',
                'description' => 'Totalurile din Situație eToll pe sesiunile închise în perioadă.',
                'icon' => 'bi-signpost-split',
                'source' => 'views/etoll/_kpis.php (EtollStatementController::dashboardKpiData)',
                'requires' => [['etoll', 'view']],
                'filters' => ['period', 'vehicle_id'],
                'time' => 'period',
                'sizes' => ['l', 'xl'],
                'default_size' => 'xl',
                'render' => 'lazy',
                'refresh' => 'on_filter',
                'population' => 'financial',
                'assets' => ['css' => ['assets/css/etoll.css'], 'js' => []],
                // Filtrele paginii Situație eToll (selecții multiple; „0” = fără).
                'config' => [
                    'date_from' => 'date',
                    'date_to' => 'date',
                    'nr' => ['str_list', '/^[A-Z0-9]{2,15}$/'],
                    'cap' => ['str_list', '/^(0|\d{1,4}(\.\d{1,2})?)$/'],
                    'cat' => ['str_list', '/^\d{1,10}$/'],
                    'ben' => ['str_list', '/^\d{1,10}$/'],
                    'tip' => ['enum_list', array_merge(array_keys(EtollStatementModel::TRANSPORT_TYPES), [EtollStatementModel::NONE])],
                    'status' => ['enum_list', array_keys(EtollStatementModel::STATUSES)],
                ],
                'filter_fields' => [
                    ['key' => 'period', 'label' => 'Perioadă', 'type' => 'period', 'all' => 'Perioada panoului'],
                    ['key' => 'nr', 'label' => 'Vehicule', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'cap', 'label' => 'Capacitate reală', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'cat', 'label' => 'Categorie capacitate', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'ben', 'label' => 'Beneficiari', 'type' => 'multi', 'all' => 'Toți'],
                    ['key' => 'tip', 'label' => 'Tip transport', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'status', 'label' => 'Status', 'type' => 'multi', 'all' => 'Toate'],
                ],
                'link' => ['page' => 'etoll'],
            ],
            'stoc.piese' => [
                'module' => 'Stoc piese',
                'title' => 'Stoc piese: valoare, sub minim, fără mișcare',
                'description' => 'KPI-urile din Mentenanță → Stoc piese (inclusiv anvelopele în stoc).',
                'icon' => 'bi-box-seam',
                'source' => 'views/maintenance/_stock_kpis.php (MaintenanceModel::getStockKpis)',
                'requires' => [['mentenanta', 'view'], ['mentenanta', 'parts_stock']],
                'filters' => [],
                'time' => 'current',
                'sizes' => ['m', 'l', 'xl'],
                'default_size' => 'm',
                'render' => 'lazy',
                'refresh' => 'none',
                'population' => 'none',
                'assets' => ['css' => [], 'js' => []],
                'link' => ['page' => 'mentenanta', 'action' => 'stock'],
            ],
            'consumabile.alerts' => [
                'module' => 'Consumabile & fluide',
                'title' => 'Alerte consumabile & fluide',
                'description' => 'Alertele active din tabul Consumabile (stoc, fluide, AdBlue) și recipientele care expiră.',
                'icon' => 'bi-droplet-half',
                'source' => 'views/maintenance/_consumabile_alerts.php (ConsumabileController::dashboardAlerts)',
                'requires' => [['mentenanta', 'view'], ['mentenanta', 'consumabile_view']],
                'filters' => [],
                'time' => 'current',
                'sizes' => ['m', 'l'],
                'default_size' => 'm',
                'render' => 'lazy',
                'refresh' => 'none',
                'population' => 'none',
                'assets' => ['css' => ['assets/css/consumabile.css'], 'js' => []],
                'link' => ['page' => 'mentenanta', 'action' => 'stock', 'tab' => 'consumabile'],
            ],
            'centralizator.kpis' => [
                'module' => 'Centralizator facturare',
                'title' => 'Facturare: km, curse, tone, refacturări',
                'description' => 'Cardurile KPI din Centralizator facturare (cu defalcarea pe tip de transport și comparația cu luna anterioară) + totalul refacturărilor.',
                'icon' => 'bi-calculator',
                'source' => 'views/centralizator_facturare/_kpi_cards.php (CentralizatorFacturareService::getKpiReport)',
                'requires' => [['centralizator_facturare', 'view']],
                'filters' => ['period', 'vehicle_id'],
                'time' => 'billing_month',
                'sizes' => ['m', 'l', 'xl'],
                'default_size' => 'xl',
                'render' => 'lazy',
                'refresh' => 'on_filter',
                'population' => 'operational',
                'assets' => ['css' => ['assets/css/centralizator-facturare.css'], 'js' => []],
                // Filtrele paginii Centralizator facturare (luna de facturare + selecțiile multiple).
                'config' => [
                    'month' => 'month',
                    'beneficiar_id' => 'int_list',
                    'tip_activitate' => ['enum_list', ['primar', 'primar_tona', 'distributie', 'compresor']],
                    'tip_marfa' => ['str_list', '/^[\p{L}\p{N} _.,:\/+()-]{1,80}$/u'],
                    'loc_incarcare_id' => 'int_list',
                    'zona_distributie_id' => 'int_list',
                    'ruta' => ['str_list', '/^\d{1,10}:\d{1,10}$/'],
                    'vehicle_id' => 'int_list',
                ],
                'filter_fields' => [
                    ['key' => 'month', 'label' => 'Luna de facturare', 'type' => 'month', 'all' => 'Luna perioadei panoului'],
                    ['key' => 'beneficiar_id', 'label' => 'Beneficiari', 'type' => 'multi', 'all' => 'Toți beneficiarii'],
                    ['key' => 'tip_activitate', 'label' => 'Tip activitate', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'tip_marfa', 'label' => 'Tip marfă', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'loc_incarcare_id', 'label' => 'Loc încărcare', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'zona_distributie_id', 'label' => 'Zonă distribuție', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'ruta', 'label' => 'Rută', 'type' => 'multi', 'all' => 'Toate'],
                    ['key' => 'vehicle_id', 'label' => 'Vehicule', 'type' => 'multi', 'all' => 'Toate vehiculele'],
                ],
                'link' => ['page' => 'centralizator_facturare'],
            ],
            'mentenanta.interventions' => [
                'module' => 'Intervenții',
                'title' => 'Intervenții pe status',
                'description' => 'De verificat, programate, în service, în așteptare, finalizate — click deschide lista filtrată.',
                'icon' => 'bi-tools',
                'source' => 'views/maintenance/_interventions_v2_kpis.php (InterventionWorkflowModel::kpis)',
                'requires' => [['mentenanta', 'view'], ['mentenanta', 'interventions']],
                'filters' => ['vehicle_id'],
                'time' => 'current',
                'sizes' => ['m', 'l', 'xl'],
                'default_size' => 'm',
                'render' => 'lazy',
                'assets' => ['css' => ['assets/css/interventii-v2.css'], 'js' => []],
                'refresh' => 'on_filter',
                'population' => 'fleet',
                // Filtrele paginii Intervenții (statusul e chiar dimensiunea cardurilor, deci nu se filtrează).
                'config' => [
                    'date_from' => 'date',
                    'date_to' => 'date',
                    'tip' => ['enum', ['intretinere', 'reparatie']],
                    'responsabil' => ['pattern', '/^(nealocat|\d{1,10})$/'],
                    'sursa' => ['enum', ['km_service', 'manual']],
                    'vehicle_id' => 'int',
                ],
                'filter_fields' => [
                    ['key' => 'period', 'label' => 'Data programării', 'type' => 'period', 'all' => 'Oricând (ca în pagină)'],
                    ['key' => 'tip', 'label' => 'Tip', 'type' => 'select', 'all' => 'Toate tipurile'],
                    ['key' => 'responsabil', 'label' => 'Responsabil', 'type' => 'select', 'all' => 'Toți'],
                    ['key' => 'sursa', 'label' => 'Sursă', 'type' => 'select', 'all' => 'Toate'],
                    ['key' => 'vehicle_id', 'label' => 'Vehicul', 'type' => 'select', 'all' => 'Ca filtrul de sus'],
                ],
                'link' => ['page' => 'mentenanta', 'action' => 'interventions'],
            ],
            'documente.alerts' => [
                'module' => 'Documente',
                'title' => 'Alerte documente',
                'description' => 'Documente expirate, care expiră în 7 / 30 de zile și fără fișier, plus cele mai urgente.',
                'icon' => 'bi-file-earmark-text',
                'source' => 'views/module/_document_summary.php (DocumentModel::getNotificationSummary)',
                'requires' => [['documente', 'view']],
                'filters' => ['vehicle_id'],
                'time' => 'current',
                'sizes' => ['m', 'l', 'xl'],
                'default_size' => 'm',
                'render' => 'lazy',
                'assets' => ['css' => [], 'js' => []],
                'refresh' => 'on_filter',
                'population' => 'fleet',
                // Pagina Documente calculează alertele pe vehicul (stare față de azi).
                'config' => [
                    'vehicle_id' => 'int',
                ],
                'filter_fields' => [
                    ['key' => 'vehicle_id', 'label' => 'Vehicul', 'type' => 'select', 'all' => 'Ca filtrul de sus'],
                ],
                'link' => ['page' => 'documente'],
            ],
            'dispecer.insights' => [
                'module' => 'Dispecer curse',
                'title' => 'Km pierduți · în desfășurare · alimentări neasociate',
                'description' => 'Cardurile din dreapta formularului Adaugă cursă (SAS + CardOil), cu panourile lor de lucru.',
                'icon' => 'bi-graph-down-arrow',
                'source' => 'views/dispecer_curse/_insights_cards.php + assets/js/dispatcher-insights.js',
                'requires' => [['dispecer_curse', 'view']],
                'filters' => [],
                'time' => 'mixed',
                'sizes' => ['s', 'm', 'l'],
                'default_size' => 's',
                'render' => 'lazy',
                'refresh' => 'none',
                'population' => 'operational',
                'assets' => [
                    'css' => ['assets/css/dispatcher-insights.css'],
                    'js' => ['assets/js/dispatcher-insights.js'],
                ],
                'link' => ['page' => 'dispecer_curse'],
            ],
            'dispecer.live_gps' => [
                'module' => 'Dispecer curse',
                'title' => 'Curse în desfășurare (GPS live)',
                'description' => 'Vehiculele în mișcare acum (SAS) și dacă au cursă deschisă în Dispecer. Se reîmprospătează la 60 s.',
                'icon' => 'bi-broadcast',
                'source' => 'views/dispecer_curse/_live_gps_panel.php + assets/js/dispatcher-live.js',
                'requires' => [['dispecer_curse', 'view']],
                'filters' => [],
                'time' => 'live',
                'sizes' => ['l', 'xl'],
                'default_size' => 'xl',
                'render' => 'lazy',
                'refresh' => 'interval',
                'population' => 'operational',
                'assets' => [
                    'css' => [],
                    'js' => ['assets/js/dispatcher-live.js'],
                ],
                'link' => ['page' => 'dispecer_curse'],
            ],
        ];

        foreach ($defs as $id => &$def) {
            $def['id'] = $id;
        }
        unset($def);

        return self::$definitions = $defs;
    }

    /**
     * Layout-ul implicit = ce arăta dashboard-ul înainte (3 carduri) + cardurile ieftine noi.
     * Cardurile grele (SAS live, Km pierduți) se adaugă la cerere.
     *
     * @return list<array{id:string,size:string}>
     */
    public static function defaultLayout(): array
    {
        return [
            ['id' => 'fleet.vehicles', 'size' => 's'],
            ['id' => 'fleet.drivers', 'size' => 's'],
            ['id' => 'finance.operational_cost', 'size' => 's'],
            ['id' => 'finance.activity_split', 'size' => 'm'],
            ['id' => 'mentenanta.interventions', 'size' => 'm'],
            ['id' => 'documente.alerts', 'size' => 'm'],
            ['id' => 'drivers.activity', 'size' => 'xl'],
        ];
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function canAccess(array $widget): bool
    {
        if (!function_exists('can')) {
            return true;
        }
        if (!can('dashboard', 'view')) {
            return false;
        }
        foreach ((array) ($widget['requires'] ?? []) as $requirement) {
            if (!can((string) $requirement[0], (string) ($requirement[1] ?? 'view'))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, array<string, mixed>> cardurile pe care utilizatorul curent le poate vedea */
    public static function accessible(): array
    {
        return array_filter(self::all(), [self::class, 'canAccess']);
    }

    /**
     * Păstrează doar cardurile cunoscute și permise, fără dubluri, cu dimensiuni valide.
     *
     * @param mixed $items
     * @return list<array{id:string,size:string,config?:array}>
     */
    public static function normalizeLayout(mixed $items): array
    {
        $accessible = self::accessible();
        $out = [];
        $seen = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? '') : (is_string($item) ? $item : '');
            if ($id === '' || isset($seen[$id]) || !isset($accessible[$id])) {
                continue;
            }
            $widget = $accessible[$id];
            $size = is_array($item) ? (string) ($item['size'] ?? '') : '';
            if (!in_array($size, (array) $widget['sizes'], true)) {
                $size = (string) $widget['default_size'];
            }
            $seen[$id] = true;
            $entry = ['id' => $id, 'size' => $size];
            $config = self::normalizeConfig($widget, is_array($item) ? ($item['config'] ?? null) : null);
            if ($config !== []) {
                $entry['config'] = $config;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Setările unui card (ex. șoferul ales în „Activitate șoferi”): doar cheile declarate în
     * 'config' ale cardului, cu tipul lor. Se salvează identificatori, nu date.
     */
    public static function normalizeConfig(array $widget, mixed $config): array
    {
        $out = [];
        $allowed = (array) ($widget['config'] ?? []);
        if (!is_array($config) || $allowed === []) {
            return $out;
        }
        foreach ($allowed as $key => $type) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            if ($type === 'int_list') {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) $value), static fn(int $v): bool => $v > 0)));
                if ($ids !== []) {
                    $out[$key] = array_slice($ids, 0, 50);
                }
            } elseif ($type === 'int') {
                if (is_scalar($value) && (int) $value > 0) {
                    $out[$key] = (int) $value;
                }
            } elseif ($type === 'date') {
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if ($date !== false && $date->format('Y-m-d') === $value) {
                    $out[$key] = $value;
                }
            } elseif ($type === 'month') {
                $month = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m', $value) : false;
                if ($month !== false && $month->format('Y-m') === $value) {
                    $out[$key] = $value;
                }
            } elseif ($type === 'str') {
                // Text liber scurt (ex. furnizor): fără caractere de control; pagina-sursă îl validează din nou.
                $text = is_scalar($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value) ?? '') : '';
                if ($text !== '') {
                    $out[$key] = mb_substr($text, 0, 80);
                }
            } elseif (is_array($type) && ($type[0] ?? '') === 'enum') {
                if (is_scalar($value) && in_array((string) $value, array_map('strval', (array) $type[1]), true)) {
                    $out[$key] = (string) $value;
                }
            } elseif (is_array($type) && ($type[0] ?? '') === 'pattern') {
                if (is_scalar($value) && preg_match((string) $type[1], (string) $value) === 1) {
                    $out[$key] = (string) $value;
                }
            } elseif (is_array($type) && ($type[0] ?? '') === 'enum_list') {
                $allowed = array_map('strval', (array) $type[1]);
                $list = array_values(array_unique(array_filter(array_map('strval', (array) $value), static fn(string $v): bool => in_array($v, $allowed, true))));
                if ($list !== []) {
                    $out[$key] = $list;
                }
            } elseif (is_array($type) && ($type[0] ?? '') === 'str_list') {
                // Listă de coduri (ex. nr. eToll, rute „12:34”, mărfuri): fiecare trebuie să respecte tiparul.
                $list = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string) $v), (array) $value),
                    static fn(string $v): bool => $v !== '' && preg_match((string) $type[1], $v) === 1)));
                if ($list !== []) {
                    $out[$key] = array_slice($list, 0, 80);
                }
            }
        }
        // Un interval are nevoie de ambele capete; inversat -> se îndreaptă.
        if (isset($out['date_from']) !== isset($out['date_to'])) {
            unset($out['date_from'], $out['date_to']);
        } elseif (isset($out['date_from']) && $out['date_from'] > $out['date_to']) {
            [$out['date_from'], $out['date_to']] = [$out['date_to'], $out['date_from']];
        }

        return $out;
    }

    /** Layout-ul implicit, filtrat după drepturile utilizatorului curent. */
    public static function defaultLayoutForUser(): array
    {
        return self::normalizeLayout(self::defaultLayout());
    }

    /** Metadatele trimise interfeței de configurare (fără căi interne de fișiere). */
    public static function clientCatalog(): array
    {
        $catalog = [];
        foreach (self::accessible() as $id => $widget) {
            $catalog[] = [
                'id' => $id,
                'module' => $widget['module'],
                'title' => $widget['title'],
                'description' => $widget['description'],
                'icon' => $widget['icon'],
                'sizes' => array_values($widget['sizes']),
                'default_size' => $widget['default_size'],
                'time' => $widget['time'],
                'time_label' => self::TIME_LABELS[$widget['time']] ?? '',
                'filters' => array_values($widget['filters']),
                'render' => $widget['render'],
                // Cardurile cu filtrele paginii-sursă formează o secțiune proprie în modul „Pagini”.
                'has_filters' => !empty($widget['filter_fields']),
                'config_keys' => array_keys((array) ($widget['config'] ?? [])),
            ];
        }

        return $catalog;
    }
}
