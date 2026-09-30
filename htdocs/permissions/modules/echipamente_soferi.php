<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Echipamente șoferi" (?page=echipamente_soferi).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'echipamente_soferi',
    'label'       => 'Echipamente șoferi',
    'description' => 'Echipamente predate șoferilor și personalului TESA',
    'section'     => 'soferi',
    'icon'        => 'bi-box-seam',
    'order'       => 260,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
        'stoc'        => 'Stocuri',
    ],
    'actions'     => [
        'view'               => ['label' => 'Vizualizare'],
        'manage_assignments' => ['label' => 'Predare / returnare / înlocuire echipament', 'group' => 'operare'],
        'manage_stock'       => ['label' => 'Gestionare stoc (intrări, ieșiri, praguri)', 'group' => 'stoc'],
        'manage_catalog'     => ['label' => 'Catalog echipamente', 'group' => 'configurare'],
        'export'             => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'catalog'        => 'manage_catalog',
        'save_catalog'   => 'manage_catalog',
        'delete_catalog' => 'manage_catalog',
        'predare'        => 'manage_assignments',
        'returnare'      => 'manage_assignments',
        'marcheaza'      => 'manage_assignments',
        'inlocuieste'    => 'manage_assignments',
        'stoc_intrare'   => 'manage_stock',
        'stoc_iesire'    => 'manage_stock',
        'stoc_setari'    => 'manage_stock',
        'export'         => 'export',
        'export_stoc'    => 'export',
    ],
];
