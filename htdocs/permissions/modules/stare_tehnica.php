<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Stare tehnică" (?page=stare_tehnica).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'stare_tehnica',
    'label'       => 'Stare tehnică',
    'description' => 'Starea componentelor și alerte tehnice',
    'section'     => 'vehicule',
    'icon'        => 'bi-heart-pulse',
    'order'       => 220,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
