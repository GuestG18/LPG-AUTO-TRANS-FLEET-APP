<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Km pierduti" (?page=km_pierduti).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'km_pierduti',
    'label'       => 'Km pierduti',
    'description' => 'Km GPS reali comparați cu km acoperiți de curse',
    'section'     => 'operational',
    'icon'        => 'bi-graph-down-arrow',
    'order'       => 70,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare'],
    ],
];
