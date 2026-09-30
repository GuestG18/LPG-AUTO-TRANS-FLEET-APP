<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Administrare tarife transport" (?page=tarife_transport).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'tarife_transport',
    'label'       => 'Administrare tarife transport',
    'description' => 'Tarife versionate și monitorizarea prețului motorinei',
    'section'     => 'operational',
    'icon'        => 'bi-tags',
    'order'       => 130,
    'scope'       => 'admin',
    'groups'      => [
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare tarife & monitorizare motorină'],
        'manage' => ['label' => 'Modificare tarife (versionare)', 'group' => 'configurare', 'default_admin' => true, 'sensitive' => true],
    ],
    'endpoints'   => [
        'store_version'       => 'manage',
        'store_versions_bulk' => 'manage',
        'delete_version'      => 'manage',
        'save_settings'       => 'manage',
        'apply_reprice'       => 'manage',
    ],
];
