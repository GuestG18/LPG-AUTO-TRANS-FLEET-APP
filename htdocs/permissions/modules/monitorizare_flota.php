<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Monitorizare flotă" (?page=monitorizare_flota).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'monitorizare_flota',
    'label'       => 'Monitorizare flotă',
    'description' => 'Hartă 3D a flotei (MapLibre + OpenFreeMap)',
    'section'     => 'operational',
    'icon'        => 'bi-globe-europe-africa',
    'order'       => 41,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
