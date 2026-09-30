<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Istoric activități șofer" (?page=istoric_activitati_sofer).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'istoric_activitati_sofer',
    'label'       => 'Istoric activități șofer',
    'description' => 'Cursele, diurnele și costurile fiecărui șofer',
    'section'     => 'soferi',
    'icon'        => 'bi-clock-history',
    'order'       => 270,
    'scope'       => 'all',
    'groups'      => [
        'export'    => 'Export & rapoarte',
        'financiar' => 'Date financiare',
    ],
    'actions'     => [
        'view'           => ['label' => 'Vizualizare'],
        'view_financial' => ['label' => 'Date financiare (salariu, valoare curse, profit, cost total)', 'group' => 'financiar', 'default_admin' => true, 'sensitive' => true],
        'export_excel'   => ['label' => 'Export Excel', 'group' => 'export'],
        'export_pdf'     => ['label' => 'Export PDF', 'group' => 'export'],
    ],
];
