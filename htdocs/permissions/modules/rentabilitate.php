<?php
declare(strict_types=1);

/**
 * Permisiunile paginii "Rentabilitate Operațională" (?page=rentabilitate). Pagina este doar de citire.
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'rentabilitate',
    'label'       => 'Rentabilitate Operațională',
    'description' => 'Cost, venit și rezultat pe km pe vehicule, ansambluri, șoferi și beneficiari',
    'section'     => 'contabilitate',
    'icon'        => 'bi-graph-up-arrow',
    'order'       => 319,
    'scope'       => 'accountancy',
    'groups'      => [
        'export' => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare rentabilitate (costuri, venituri, șoferi)', 'sensitive' => true],
        'export' => ['label' => 'Export CSV', 'group' => 'export', 'default_admin' => true, 'sensitive' => true],
    ],
    'endpoints'   => [
        'export' => 'export',
    ],
];
