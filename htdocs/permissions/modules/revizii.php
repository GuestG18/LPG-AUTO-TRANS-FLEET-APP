<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Revizii" (?page=revizii) — schimb ulei calculat din SasFleet.
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'revizii',
    'label'       => 'Revizii',
    'description' => 'Schimb ulei: km echivalenți din SasFleet (km + ore motor), cap tractor și semi-remorcă separat',
    'section'     => 'mentenanta',
    'icon'        => 'bi-droplet-half',
    'order'       => 335,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare și sincronizare SasFleet'],
        'edit' => ['label' => 'Configurare intervale și înregistrare revizii', 'default_admin' => true],
        'simulate' => ['label' => 'Simulare calcul revizie pe date istorice', 'default_admin' => true],
    ],
    'endpoints'   => [
        'save_config' => 'edit',
        'record'      => 'edit',
        'sync'        => 'view',
        'simulate'    => 'simulate',
    ],
];
