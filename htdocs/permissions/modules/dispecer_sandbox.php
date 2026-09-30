<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Sandbox GPS curse" (?page=dispecer_sandbox).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'dispecer_sandbox',
    'label'       => 'Sandbox GPS curse',
    'description' => 'Experiment: curse cu date GPS live',
    'section'     => 'operational',
    'icon'        => 'bi-broadcast',
    'order'       => 50,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
