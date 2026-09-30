<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Istoric cheltuieli curse" (?page=istoric_cheltuieli_curse).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'istoric_cheltuieli_curse',
    'label'       => 'Istoric cheltuieli curse',
    'description' => 'Cheltuielile curselor, pe categorii și perioade',
    'section'     => 'operational',
    'icon'        => 'bi-graph-up-arrow',
    'order'       => 120,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'              => ['label' => 'Vizualizare'],
        'export'            => ['label' => 'Export CSV', 'group' => 'export'],
        'add_expense'       => ['label' => 'Adăugare cheltuială (+document)', 'group' => 'operare'],
        'manage_categories' => ['label' => 'Gestionare categorii', 'group' => 'configurare', 'admin_only' => true],
    ],
    'endpoints'   => [
        'export'           => 'export',
        'store_expense'    => 'add_expense',
        'store_category'   => 'manage_categories',
        'update_category'  => 'manage_categories',
        'archive_category' => 'manage_categories',
        'delete_category'  => 'manage_categories',
    ],
];
