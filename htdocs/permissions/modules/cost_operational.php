<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Cost operațional / km" (?page=cost_operational).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'cost_operational',
    'label'       => 'Cost operațional / km',
    'description' => 'Costul real pe kilometru, pe elemente financiare',
    'section'     => 'contabilitate',
    'icon'        => 'bi-graph-up',
    'order'       => 320,
    'scope'       => 'accountancy',
    'groups'      => [
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'      => ['label' => 'Vizualizare analiză cost/km', 'sensitive' => true],
        'configure' => ['label' => 'Configurare elemente financiare & parametri', 'group' => 'configurare', 'default_admin' => true, 'sensitive' => true],
        'export'    => ['label' => 'Export raport CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'export' => 'export',
    ],
];
