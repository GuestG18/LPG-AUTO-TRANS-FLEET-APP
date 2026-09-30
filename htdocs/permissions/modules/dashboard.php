<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Tablou de bord" (?page=dashboard).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'dashboard',
    'label'       => 'Tablou de bord',
    'description' => 'Prezentare generală și indicatori principali',
    'section'     => 'operational',
    'icon'        => 'bi-house-door',
    'order'       => 10,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
