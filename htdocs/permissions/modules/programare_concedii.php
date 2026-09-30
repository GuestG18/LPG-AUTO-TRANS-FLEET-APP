<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Programare concedii" (?page=programare_concedii).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'programare_concedii',
    'label'       => 'Programare concedii',
    'description' => 'Cereri de concediu și disponibilitatea personalului',
    'section'     => 'operational',
    'icon'        => 'bi-calendar2-week',
    'order'       => 150,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
        'aprobare'    => 'Aprobare',
    ],
    'actions'     => [
        'view'            => ['label' => 'Vizualizare'],
        'manage_requests' => ['label' => 'Creare / editare / ștergere cereri', 'group' => 'operare'],
        'approve'         => ['label' => 'Aprobare / respingere cereri', 'group' => 'aprobare'],
        'manage_rules'    => ['label' => 'Reguli de disponibilitate', 'group' => 'configurare', 'admin_only' => true],
    ],
    'endpoints'   => [
        'store'         => 'manage_requests',
        'update'        => 'manage_requests',
        'delete'        => 'manage_requests',
        'update_status' => 'approve',
        'store_rule'    => 'manage_rules',
        'delete_rule'   => 'manage_rules',
    ],
];
