<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Șoferi" (?page=soferi).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'soferi',
    'label'       => 'Șoferi',
    'description' => 'Fișele șoferilor și alocarea vehiculelor',
    'section'     => 'soferi',
    'icon'        => 'bi-person-vcard',
    'order'       => 240,
    'scope'       => 'all',
    'groups'      => [
        'operare'  => 'Operare',
        'stergere' => 'Ștergere și recuperare',
        'export'   => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'           => ['label' => 'Vizualizare'],
        'create'         => ['label' => 'Adăugare șofer', 'group' => 'operare'],
        'edit'           => ['label' => 'Editare șofer (+ alocare vehicule)', 'group' => 'operare'],
        'delete'         => ['label' => 'Ștergere șofer', 'group' => 'stergere'],
        'end_employment' => ['label' => 'Încheiere colaborare (demisie / concediere)', 'group' => 'operare', 'default_accountancy' => true, 'sensitive' => true],
        'export'         => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'create'         => 'create',
        'store'          => 'create',
        'edit'           => 'edit',
        'update'         => 'edit',
        'delete'         => 'delete',
        'export'         => 'export',
        'end_employment' => 'end_employment',
    ],
];
