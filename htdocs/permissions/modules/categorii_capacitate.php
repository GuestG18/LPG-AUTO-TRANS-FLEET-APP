<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Categorii capacitate" (?page=categorii_capacitate).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'categorii_capacitate',
    'label'       => 'Categorii capacitate',
    'description' => 'Etichetele de grupare a vehiculelor după capacitate',
    'section'     => 'vehicule',
    'icon'        => 'bi-tags',
    'order'       => 180,
    'scope'       => 'admin',
    'groups'      => [
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare categorii & raport verificare'],
        'manage' => ['label' => 'Adăugare / editare / ștergere categorii', 'group' => 'configurare', 'default_admin' => true],
    ],
    'endpoints'   => [
        'store'  => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
    ],
];
