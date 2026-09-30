<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Cheltuieli" (?page=cheltuieli).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'cheltuieli',
    'label'       => 'Cheltuieli',
    'description' => 'Cheltuieli alocate pe vehicul, șofer sau companie',
    'section'     => 'contabilitate',
    'icon'        => 'bi-wallet2',
    'order'       => 300,
    'scope'       => 'accountancy',
    'routes'      => ['cheltuieli', 'cheltuieli_birou', 'cheltuieli_administrative'],
    'groups'      => [
        'operare'  => 'Operare',
        'stergere' => 'Ștergere și recuperare',
        'export'   => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare'],
        'create' => ['label' => 'Adăugare cheltuială', 'group' => 'operare'],
        'edit'   => ['label' => 'Editare cheltuială', 'group' => 'operare'],
        'delete' => ['label' => 'Ștergere cheltuială', 'group' => 'stergere'],
        'export' => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'store'  => 'create',
        'update' => 'edit',
        'delete' => 'delete',
        'export' => 'export',
    ],
];
