<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Carburanți" (?page=carburanti).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'carburanti',
    'label'       => 'Carburanți',
    'description' => 'Alimentări, consum și sincronizare CardOil',
    'section'     => 'operational',
    'icon'        => 'bi-fuel-pump',
    'order'       => 110,
    'scope'       => 'all',
    'groups'      => [
        'operare' => 'Operare',
    ],
    'actions'     => [
        'view'     => ['label' => 'Vizualizare (6 sub-tab-uri)'],
        'sync'     => ['label' => 'Sincronizare CardOil (API)', 'group' => 'operare', 'sensitive' => true],
        'link'     => ['label' => 'Asociere manuală alimentare ↔ cursă', 'group' => 'operare'],
        'set_full' => ['label' => 'Marcare Full / Parțial, T0 manual + corecție odometru', 'group' => 'operare'],
    ],
    'endpoints'   => [
        'sync_now'     => 'sync',
        'link_fillup'  => 'link',
        'set_full'     => 'set_full',
        'set_odometer' => 'set_full',
        'set_t0'       => 'set_full',
        'clear_t0'     => 'set_full',
    ],
];
