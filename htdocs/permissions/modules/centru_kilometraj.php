<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Centru Kilometraj" (?page=centru_kilometraj).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'centru_kilometraj',
    'label'       => 'Centru Kilometraj',
    'description' => 'Kilometrajul flotei din SAS Fleet și CardOil Refill, cu T0 individual per vehicul',
    'section'     => 'operational',
    'icon'        => 'bi-speedometer2',
    'order'       => 72,
    'scope'       => 'all',
    'actions'     => [
        'view'     => ['label' => 'Vizualizare și actualizare citiri SAS'],
        'history'  => ['label' => 'Istoricul citirilor și al referințelor T0'],
        't0'       => ['label' => 'Setare / modificare T0 (păstrează versiunile anterioare)', 'default_admin' => true],
        'settings' => ['label' => 'Politica sursei oficiale pentru totaluri', 'default_admin' => true],
        'audit'    => ['label' => 'Jurnalul de audit al modulului', 'default_admin' => true],
    ],
    'endpoints'   => [
        'data'        => 'view',
        'sync'        => 'view',
        'history'     => 'history',
        't0_lookup'   => 't0',
        't0_save'     => 't0',
        'save_policy' => 'settings',
        'audit'       => 'audit',
    ],
];
