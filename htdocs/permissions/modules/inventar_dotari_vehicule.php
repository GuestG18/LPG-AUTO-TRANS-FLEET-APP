<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Inventar dotări" (?page=inventar_dotari_vehicule).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'inventar_dotari_vehicule',
    'label'       => 'Inventar dotări',
    'description' => 'Dotările alocate fiecărui vehicul',
    'section'     => 'vehicule',
    'icon'        => 'bi-box-seam',
    'order'       => 200,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'               => ['label' => 'Vizualizare inventar'],
        'manage_assignments' => ['label' => 'Alocare / ștergere dotări', 'group' => 'operare'],
        'manage_catalog'     => ['label' => 'Catalog dotări', 'group' => 'configurare'],
        'manage_rules'       => ['label' => 'Reguli dotări', 'group' => 'configurare'],
        'export'             => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'save_catalog'               => 'manage_catalog',
        'delete_catalog'             => 'manage_catalog',
        'add_rule'                   => 'manage_rules',
        'delete_rule'                => 'manage_rules',
        'create_assignment'          => 'manage_assignments',
        'update_assignment'          => 'manage_assignments',
        'delete_assignment'          => 'manage_assignments',
        'delete_vehicle_assignments' => 'manage_assignments',
        'export'                     => 'export',
    ],
];
