<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Sandbox Dashboard Flota" (?page=sas_dashboard_sandbox).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'sas_dashboard_sandbox',
    'label'       => 'Sandbox Dashboard Flota',
    'description' => 'Experiment: odometru și date CAN din GPS',
    'section'     => 'operational',
    'icon'        => 'bi-activity',
    'order'       => 60,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
