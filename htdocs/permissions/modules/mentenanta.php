<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Mentenanță" (?page=mentenanta).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'mentenanta',
    'label'       => 'Mentenanță',
    'description' => 'Intervenții, întreținere, reparații și stocuri',
    'section'     => 'mentenanta',
    'icon'        => 'bi-wrench-adjustable',
    'order'       => 330,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
        'stoc'        => 'Stocuri',
    ],
    'actions'     => [
        'view'          => ['label' => 'Prezentare generală'],
        'interventions' => ['label' => 'Intervenții programate', 'group' => 'operare'],
        'maintenance'   => ['label' => 'Întreținere', 'group' => 'operare'],
        'repairs'       => ['label' => 'Reparații & facturi', 'group' => 'operare'],
        'auto_catalog'  => ['label' => 'Auto catalog (config componente)', 'group' => 'configurare'],
        'parts_stock'   => ['label' => 'Stoc piese', 'group' => 'stoc'],
        'tire_stock'    => ['label' => 'Stoc anvelope & config axe', 'group' => 'stoc'],
        'export'        => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'save_intervention'          => 'interventions',
        'delete_intervention'        => 'interventions',
        'save_part'                  => 'parts_stock',
        'save_auto_component_config' => 'auto_catalog',
        'export'                     => 'export',
        'export_v2'                  => 'export',
    ],
];
