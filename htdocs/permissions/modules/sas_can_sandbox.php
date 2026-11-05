<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Sandbox Km CAN" (?page=sas_can_sandbox).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'sas_can_sandbox',
    'label'       => 'Sandbox Km CAN',
    'description' => 'Experiment: km din CAN (raportul web SAS) cu credențiale introduse manual',
    'section'     => 'operational',
    'icon'        => 'bi-cpu',
    'order'       => 61,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare și interogare SAS', 'default_admin' => true],
    ],
    'endpoints'   => [
        'fetch' => 'view',
    ],
];
