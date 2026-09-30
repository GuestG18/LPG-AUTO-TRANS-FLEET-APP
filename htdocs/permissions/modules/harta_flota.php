<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Harta Flota" (?page=harta_flota).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'harta_flota',
    'label'       => 'Harta Flota',
    'description' => 'Monitorizare vehicule în timp real',
    'section'     => 'operational',
    'icon'        => 'bi-map',
    'order'       => 40,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
