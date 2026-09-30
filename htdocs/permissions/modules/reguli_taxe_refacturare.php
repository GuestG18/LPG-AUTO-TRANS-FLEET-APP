<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Reguli taxe refacturare" (?page=reguli_taxe_refacturare).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'reguli_taxe_refacturare',
    'label'       => 'Reguli taxe refacturare',
    'description' => 'Rute pe care se cer taxe de acces / port / trecere',
    'section'     => 'operational',
    'icon'        => 'bi-signpost-split',
    'order'       => 100,
    'scope'       => 'all',
    'groups'      => [
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare reguli'],
        'manage' => ['label' => 'Adăugare / editare / ștergere reguli', 'group' => 'configurare', 'default_admin' => true],
    ],
    'endpoints'   => [
        'store'  => 'manage',
        'update' => 'manage',
        'toggle' => 'manage',
        'delete' => 'manage',
    ],
];
