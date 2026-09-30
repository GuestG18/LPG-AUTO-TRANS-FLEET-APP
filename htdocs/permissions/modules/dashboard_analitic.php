<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Dashboard Analitic" (?page=dashboard_analitic).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'dashboard_analitic',
    'label'       => 'Dashboard Analitic',
    'description' => 'Rapoarte și analize avansate',
    'section'     => 'operational',
    'icon'        => 'bi-bar-chart-line',
    'order'       => 20,
    'scope'       => 'all',
    'routes'      => ['dashboard_analitic', 'dashboard_analytic_data'],
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
