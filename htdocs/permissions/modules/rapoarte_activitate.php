<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Rapoarte activitate utilizatori" (?page=rapoarte_activitate).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'rapoarte_activitate',
    'label'       => 'Rapoarte activitate utilizatori',
    'description' => 'Rapoarte de investigație pe acțiunile utilizatorilor, pe module și pagini',
    'section'     => 'administrare',
    'icon'        => 'bi-bar-chart-line',
    'order'       => 385,
    'scope'       => 'admin',
    // Jurnalul de audit al tuturor utilizatorilor: doar administratorul (controllerul verifica is_admin()).
    'admin_only'  => true,
    'groups'      => [
        'export' => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare rapoarte activitate'],
        'export' => ['label' => 'Export CSV / Excel / PDF', 'group' => 'export'],
    ],
    'endpoints'   => [
        'export' => 'export',
    ],
];
