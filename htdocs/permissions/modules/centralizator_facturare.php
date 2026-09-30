<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Centralizator Facturare" (?page=centralizator_facturare).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'centralizator_facturare',
    'label'       => 'Centralizator Facturare',
    'description' => 'Centralizarea curselor pentru facturare',
    'section'     => 'operational',
    'icon'        => 'bi-calendar-range',
    'order'       => 140,
    'scope'       => 'all',
    'routes'      => ['centralizator_facturare', 'istoric_activitate'],
    'groups'      => [
        'facturare' => 'Facturare',
    ],
    'actions'     => [
        'view'           => ['label' => 'Vizualizare'],
        'billing_status' => ['label' => 'Schimbare status facturare', 'group' => 'facturare'],
    ],
    'endpoints'   => [
        'update_status'      => 'billing_status',
        'bulk_update_status' => 'billing_status',
    ],
];
