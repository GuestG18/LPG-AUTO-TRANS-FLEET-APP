<?php
declare(strict_types=1);

/**
 * Catalogul de permisiuni al aplicatiei.
 *
 * Sursa unica de adevar pentru pagina "Drepturi de acces". Fiecare pagina are:
 *   - group      : gruparea din meniu / matrice
 *   - label      : denumirea afisata
 *   - icon       : iconita Bootstrap
 *   - scope      : comportamentul IMPLICIT (legacy) pentru utilizatorii inca neconfigurati
 *                    'all'         -> orice utilizator autentificat (comportamentul curent)
 *                    'accountancy' -> rol admin sau contabilitate (require_accountancy_or_403)
 *                    'admin'       -> doar admin (require_admin_or_403 / admin_only)
 *   - routes     : (optional) rutele reale ?page=... care mapeaza pe aceasta cheie
 *   - actions    : capabilitatile granulare. 'view' este intotdeauna implicit.
 *                    fiecare actiune: label + optional 'admin' => true (azi restrictionata la admin)
 *                    + optional 'sensitive' => true (actiune sensibila, evidentiata in UI)
 *
 * Nota: 'view' guverneaza accesul la pagina (meniu + router). Restul actiunilor
 * sunt stocate si expuse prin can($page, $action) pentru aplicare granulara.
 */

return [
    'groups' => [
        'operational'  => ['label' => 'Operațional',   'icon' => 'bi-speedometer2'],
        'vehicule'     => ['label' => 'Vehicule',       'icon' => 'bi-car-front'],
        'leasing'      => ['label' => 'Leasing',        'icon' => 'bi-calendar-check'],
        'soferi'       => ['label' => 'Șoferi',         'icon' => 'bi-person-vcard'],
        'contabilitate'=> ['label' => 'Contabilitate',  'icon' => 'bi-wallet2'],
        'mentenanta'   => ['label' => 'Mentenanță',     'icon' => 'bi-wrench-adjustable'],
        'administrare' => ['label' => 'Administrare',   'icon' => 'bi-gear-wide-connected'],
    ],

    'pages' => [
        // ---------------- Operațional ----------------
        'dashboard' => [
            'group' => 'operational', 'label' => 'Tablou de bord', 'icon' => 'bi-house-door', 'scope' => 'all',
            'actions' => ['view' => ['label' => 'Vizualizare']],
        ],
        'dashboard_analitic' => [
            'group' => 'operational', 'label' => 'Dashboard Analitic', 'icon' => 'bi-bar-chart-line', 'scope' => 'all',
            'routes' => ['dashboard_analitic', 'dashboard_analytic_data'],
            'actions' => ['view' => ['label' => 'Vizualizare']],
        ],
        'inactive_approvals' => [
            'group' => 'operational', 'label' => 'Solicitari aprobare inactive', 'icon' => 'bi-shield-exclamation', 'scope' => 'all',
            'actions' => [
                'view' => ['label' => 'Vizualizare solicitari'],
                'review' => ['label' => 'Aprobare / respingere solicitari', 'admin' => true, 'sensitive' => true],
            ],
        ],
        'dispecer_curse' => [
            'group' => 'operational', 'label' => 'Dispecer curse', 'icon' => 'bi-truck', 'scope' => 'all',
            'actions' => [
                'view'              => ['label' => 'Vizualizare listă & KPI'],
                'create'            => ['label' => 'Creare cursă'],
                'edit'              => ['label' => 'Editare cursă'],
                'delete'            => ['label' => 'Ștergere cursă'],
                'delete_bulk'       => ['label' => 'Ștergere în masă'],
                'billing_status'    => ['label' => 'Schimbare status facturare'],
                'expenses'          => ['label' => 'Cheltuieli cursă & documente'],
                'refacturari_view'  => ['label' => 'Refacturări — vizualizare'],
                'refacturari_manage'=> ['label' => 'Creare / marcare refacturare'],
                'deleted_view'      => ['label' => 'Curse șterse — vizualizare', 'admin' => true],
                'restore'           => ['label' => 'Restaurare cursă ștearsă', 'admin' => true],
                'config'            => ['label' => 'Configurare (locații, zone, rute, catalog, beneficiari)', 'admin' => true],
            ],
        ],
        'carburanti' => [
            'group' => 'operational', 'label' => 'Carburanți', 'icon' => 'bi-fuel-pump', 'scope' => 'all',
            'actions' => [
                'view'    => ['label' => 'Vizualizare (6 sub-tab-uri)'],
                'sync'    => ['label' => 'Sincronizare CardOil (API)', 'sensitive' => true],
                'link'    => ['label' => 'Asociere manuală alimentare ↔ cursă'],
                'set_full'=> ['label' => 'Marcare Full / Parțial'],
            ],
        ],
        'istoric_cheltuieli_curse' => [
            'group' => 'operational', 'label' => 'Istoric cheltuieli curse', 'icon' => 'bi-graph-up-arrow', 'scope' => 'all',
            'actions' => [
                'view'             => ['label' => 'Vizualizare'],
                'export'           => ['label' => 'Export CSV'],
                'add_expense'      => ['label' => 'Adăugare cheltuială (+document)'],
                'manage_categories'=> ['label' => 'Gestionare categorii', 'admin' => true],
            ],
        ],
        'centralizator_facturare' => [
            'group' => 'operational', 'label' => 'Centralizator Facturare', 'icon' => 'bi-calendar-range', 'scope' => 'all',
            'routes' => ['centralizator_facturare', 'istoric_activitate'],
            'actions' => [
                'view'           => ['label' => 'Vizualizare'],
                'billing_status' => ['label' => 'Schimbare status facturare'],
            ],
        ],
        'programare_concedii' => [
            'group' => 'operational', 'label' => 'Programare concedii', 'icon' => 'bi-calendar2-week', 'scope' => 'all',
            'actions' => [
                'view'            => ['label' => 'Vizualizare'],
                'manage_requests' => ['label' => 'Creare / editare / ștergere cereri'],
                'approve'         => ['label' => 'Aprobare / respingere cereri'],
                'manage_rules'    => ['label' => 'Reguli de disponibilitate', 'admin' => true],
            ],
        ],

        // ---------------- Vehicule ----------------
        'vehicule_usoare' => [
            'group' => 'vehicule', 'label' => 'Vehicule ușoare', 'icon' => 'bi-truck-front', 'scope' => 'all',
            'actions' => [
                'view'     => ['label' => 'Vizualizare'],
                'create'   => ['label' => 'Adăugare vehicul'],
                'edit'     => ['label' => 'Editare vehicul'],
                'delete'   => ['label' => 'Ștergere vehicul'],
                'export'   => ['label' => 'Export CSV'],
                'coupling' => ['label' => 'Cuplare / decuplare remorcă'],
                'tires'    => ['label' => 'Anvelope & configurație axe'],
            ],
        ],
        'vehicule_grele' => [
            'group' => 'vehicule', 'label' => 'Vehicule grele', 'icon' => 'bi-truck', 'scope' => 'all',
            'actions' => [
                'view'     => ['label' => 'Vizualizare'],
                'create'   => ['label' => 'Adăugare vehicul'],
                'edit'     => ['label' => 'Editare vehicul'],
                'delete'   => ['label' => 'Ștergere vehicul'],
                'export'   => ['label' => 'Export CSV'],
                'coupling' => ['label' => 'Cuplare / decuplare remorcă'],
                'tires'    => ['label' => 'Anvelope & configurație axe'],
            ],
        ],
        'documente' => [
            'group' => 'vehicule', 'label' => 'Documente vehicule', 'icon' => 'bi-file-earmark-text', 'scope' => 'all',
            'actions' => [
                'view'         => ['label' => 'Vizualizare'],
                'create'       => ['label' => 'Adăugare document'],
                'edit'         => ['label' => 'Editare document'],
                'delete'       => ['label' => 'Ștergere document'],
                'export'       => ['label' => 'Export CSV'],
                'manage_types' => ['label' => 'Configurare tipuri documente', 'admin' => true],
            ],
        ],
        'inventar_dotari_vehicule' => [
            'group' => 'vehicule', 'label' => 'Inventar dotări', 'icon' => 'bi-box-seam', 'scope' => 'all',
            'actions' => [
                'view'               => ['label' => 'Vizualizare inventar'],
                'manage_assignments' => ['label' => 'Alocare / ștergere dotări'],
                'manage_catalog'     => ['label' => 'Catalog dotări'],
                'manage_rules'       => ['label' => 'Reguli dotări'],
                'export'             => ['label' => 'Export CSV'],
            ],
        ],
        'autorizatii_vehicule' => [
            'group' => 'vehicule', 'label' => 'Autorizații', 'icon' => 'bi-shield-check', 'scope' => 'all',
            'actions' => [
                'view'         => ['label' => 'Vizualizare'],
                'manage'       => ['label' => 'Adăugare / editare / ștergere autorizații'],
                'manage_zones' => ['label' => 'Gestionare zone'],
            ],
        ],
        'stare_tehnica' => [
            'group' => 'vehicule', 'label' => 'Stare tehnică', 'icon' => 'bi-heart-pulse', 'scope' => 'all',
            'actions' => ['view' => ['label' => 'Vizualizare']],
        ],

        // ---------------- Șoferi ----------------
        // ---------------- Leasing ----------------
        'scadentar_leasing' => [
            'group' => 'leasing', 'label' => 'ScadenÈ›ar Leasing', 'icon' => 'bi-calendar-check', 'scope' => 'all',
            'actions' => [
                'view'          => ['label' => 'Vizualizare scadenÈ›ar'],
                'create'        => ['label' => 'AdÄƒugare contract leasing'],
                'edit'          => ['label' => 'Editare contract leasing'],
                'mark_paid'     => ['label' => 'Marcare ratÄƒ ca plÄƒtitÄƒ'],
                'documents'     => ['label' => 'Documente leasing'],
                'notifications' => ['label' => 'SetÄƒri notificÄƒri leasing'],
                'close'         => ['label' => 'ÃŽnchidere contract'],
                'archive'       => ['label' => 'Arhivare contract', 'sensitive' => true],
                'export'        => ['label' => 'Export Excel'],
            ],
        ],

        'soferi' => [
            'group' => 'soferi', 'label' => 'Șoferi', 'icon' => 'bi-person-vcard', 'scope' => 'all',
            'actions' => [
                'view'   => ['label' => 'Vizualizare'],
                'create' => ['label' => 'Adăugare șofer'],
                'edit'   => ['label' => 'Editare șofer (+ alocare vehicule)'],
                'delete' => ['label' => 'Ștergere șofer'],
                'export' => ['label' => 'Export CSV'],
            ],
        ],
        'documente_soferi' => [
            'group' => 'soferi', 'label' => 'Documente șoferi', 'icon' => 'bi-file-earmark-medical', 'scope' => 'all',
            'actions' => [
                'view'         => ['label' => 'Vizualizare'],
                'create'       => ['label' => 'Adăugare document'],
                'edit'         => ['label' => 'Editare document'],
                'delete'       => ['label' => 'Ștergere document'],
                'manage_types' => ['label' => 'Configurare tipuri documente', 'admin' => true],
            ],
        ],
        'istoric_activitati_sofer' => [
            'group' => 'soferi', 'label' => 'Istoric activități șofer', 'icon' => 'bi-clock-history', 'scope' => 'all',
            'actions' => [
                'view'        => ['label' => 'Vizualizare'],
                'export_excel'=> ['label' => 'Export Excel'],
                'export_pdf'  => ['label' => 'Export PDF'],
            ],
        ],

        // ---------------- Contabilitate ----------------
        'contabilitate_personal' => [
            'group' => 'contabilitate', 'label' => 'Contabilitate Personal', 'icon' => 'bi-person-badge', 'scope' => 'accountancy',
            'actions' => [
                'view'         => ['label' => 'Vizualizare listă personal'],
                'manage_staff' => ['label' => 'Adăugare / editare personal'],
                'salaries'     => ['label' => 'Salarii & istoric salarial', 'sensitive' => true],
                'documents'    => ['label' => 'Documente angajați'],
                'config_types' => ['label' => 'Configurare tipuri & documente obligatorii'],
                'end_activity' => ['label' => 'Încheiere activitate'],
                'export'       => ['label' => 'Export CSV'],
            ],
        ],
        'fosti_angajati' => [
            'group' => 'contabilitate', 'label' => 'Foști angajați', 'icon' => 'bi-person-dash', 'scope' => 'accountancy',
            'actions' => [
                'view'            => ['label' => 'Vizualizare'],
                'edit_termination'=> ['label' => 'Editare date încetare'],
                'rehire'          => ['label' => 'Reangajare'],
                'history_sheet'   => ['label' => 'Fișă istoric (print)'],
                'export'          => ['label' => 'Export CSV'],
            ],
        ],
        'cheltuieli_birou' => [
            'group' => 'contabilitate', 'label' => 'Cheltuieli Birou', 'icon' => 'bi-receipt', 'scope' => 'accountancy',
            'actions' => [
                'view'   => ['label' => 'Vizualizare'],
                'create' => ['label' => 'Adăugare cheltuială'],
                'edit'   => ['label' => 'Editare cheltuială'],
                'delete' => ['label' => 'Ștergere cheltuială'],
                'export' => ['label' => 'Export CSV'],
            ],
        ],
        'cheltuieli_administrative' => [
            'group' => 'contabilitate', 'label' => 'Cheltuieli Administrative', 'icon' => 'bi-file-earmark-ruled', 'scope' => 'accountancy',
            'actions' => [
                'view'   => ['label' => 'Vizualizare'],
                'create' => ['label' => 'Adăugare cheltuială'],
                'edit'   => ['label' => 'Editare cheltuială'],
                'delete' => ['label' => 'Ștergere cheltuială'],
                'export' => ['label' => 'Export CSV'],
            ],
        ],

        // ---------------- Mentenanță ----------------
        'mentenanta' => [
            'group' => 'mentenanta', 'label' => 'Mentenanță', 'icon' => 'bi-wrench-adjustable', 'scope' => 'all',
            'actions' => [
                'view'         => ['label' => 'Prezentare generală'],
                'interventions'=> ['label' => 'Intervenții programate'],
                'maintenance'  => ['label' => 'Întreținere'],
                'repairs'      => ['label' => 'Reparații & facturi'],
                'auto_catalog' => ['label' => 'Auto catalog (config componente)'],
                'parts_stock'  => ['label' => 'Stoc piese'],
                'tire_stock'   => ['label' => 'Stoc anvelope & config axe'],
                'export'       => ['label' => 'Export CSV'],
            ],
        ],

        // ---------------- Administrare ----------------
        'utilizatori' => [
            'group' => 'administrare', 'label' => 'Utilizatori', 'icon' => 'bi-people', 'scope' => 'admin',
            'actions' => [
                'view'   => ['label' => 'Vizualizare'],
                'create' => ['label' => 'Adăugare utilizator'],
                'edit'   => ['label' => 'Editare utilizator & rol'],
                'delete' => ['label' => 'Ștergere utilizator'],
            ],
        ],
        'configurare_costuri_documente_vehicule_override' => [
            'group' => 'administrare', 'label' => 'Configurare costuri documente', 'icon' => 'bi-gear', 'scope' => 'admin',
            'routes' => ['configurare_costuri_documente_vehicule_override', 'configurare_costuri_documente_soferi'],
            'actions' => [
                'view'   => ['label' => 'Vizualizare'],
                'manage' => ['label' => 'Editare costuri & validități'],
            ],
        ],
        'notificari' => [
            'group' => 'administrare', 'label' => 'Notificări', 'icon' => 'bi-bell', 'scope' => 'admin',
            'actions' => [
                'view'         => ['label' => 'Vizualizare'],
                'manage_rules' => ['label' => 'Creare / editare reguli'],
                'toggle'       => ['label' => 'Activare / dezactivare reguli'],
                'send_test'    => ['label' => 'Trimitere email de test'],
            ],
        ],
        'drepturi_acces' => [
            'group' => 'administrare', 'label' => 'Drepturi de acces', 'icon' => 'bi-shield-lock', 'scope' => 'admin',
            'actions' => [
                'view'   => ['label' => 'Vizualizare'],
                'manage' => ['label' => 'Modificare drepturi & șabloane'],
            ],
        ],
        'activitate_utilizatori' => [
            'group' => 'administrare', 'label' => 'Activitate utilizatori', 'icon' => 'bi-person-lines-fill', 'scope' => 'admin',
            'actions' => [
                'view'   => ['label' => 'Vizualizare jurnal activitate'],
                'export' => ['label' => 'Export CSV'],
            ],
        ],
    ],
];
