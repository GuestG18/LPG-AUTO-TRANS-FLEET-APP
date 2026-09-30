<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Autorizații" (?page=autorizatii_vehicule).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'autorizatii_vehicule',
    'label'       => 'Autorizații',
    'description' => 'Autorizații de transport și zonele lor',
    'section'     => 'vehicule',
    'icon'        => 'bi-shield-check',
    'order'       => 210,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'         => ['label' => 'Vizualizare'],
        'manage'       => ['label' => 'Adăugare / editare / ștergere autorizații', 'group' => 'operare'],
        'manage_zones' => ['label' => 'Gestionare zone', 'group' => 'configurare'],
    ],
    'endpoints'   => [
        'store'       => 'manage',
        'update'      => 'manage',
        'delete'      => 'manage',
        'store_zone'  => 'manage_zones',
        'delete_zone' => 'manage_zones',
    ],
];
