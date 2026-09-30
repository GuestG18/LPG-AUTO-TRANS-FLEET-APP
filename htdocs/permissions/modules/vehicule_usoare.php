<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Vehicule ușoare" (?page=vehicule_usoare).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'vehicule_usoare',
    'label'       => 'Vehicule ușoare',
    'description' => 'Autoturisme și autoutilitare',
    'section'     => 'vehicule',
    'icon'        => 'bi-truck-front',
    'order'       => 160,
    'scope'       => 'all',
    'routes'      => ['vehicule_usoare', 'vehicule'],
    'groups'      => [
        'operare'  => 'Operare',
        'stergere' => 'Ștergere și recuperare',
        'export'   => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'     => ['label' => 'Vizualizare'],
        'create'   => ['label' => 'Adăugare vehicul', 'group' => 'operare'],
        'edit'     => ['label' => 'Editare vehicul', 'group' => 'operare'],
        'delete'   => ['label' => 'Ștergere vehicul', 'group' => 'stergere'],
        'export'   => ['label' => 'Export CSV', 'group' => 'export'],
        'coupling' => ['label' => 'Cuplare / decuplare remorcă', 'group' => 'operare'],
        'tires'    => ['label' => 'Anvelope & configurație axe', 'group' => 'operare'],
    ],
    'endpoints'   => [
        'create'             => 'create',
        'store'              => 'create',
        'edit'               => 'edit',
        'update'             => 'edit',
        'delete'             => 'delete',
        'export'             => 'export',
        'cupleaza'           => 'coupling',
        'decupleaza'         => 'coupling',
        'update_tire_layout' => 'tires',
        'add_tire'           => 'tires',
        'mount_tire'         => 'tires',
        'unmount_tire'       => 'tires',
        'move_tire'          => 'tires',
        'change_tire_status' => 'tires',
    ],
];
