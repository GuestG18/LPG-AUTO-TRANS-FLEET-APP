<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Dashboard Analitic V2" (?page=dashboard_analitic_v2).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'dashboard_analitic_v2',
    'label'       => 'Dashboard Analitic V2',
    'description' => 'Versiunea nouă a rapoartelor analitice',
    'section'     => 'operational',
    'icon'        => 'bi-bar-chart-line',
    'order'       => 30,
    'scope'       => 'all',
    'routes'      => ['dashboard_analitic_v2', 'dashboard_analytic_v2_data', 'dashboard_analytic_v2_entity'],
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
