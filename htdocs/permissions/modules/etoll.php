<?php
declare(strict_types=1);

/**
 * Permisiunile paginii "Situație eToll" (?page=etoll).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'etoll',
    'label'       => 'Situație eToll',
    'description' => 'Sesiunile eToll cu anexa și desfășurătorul, alimentările portofelului și verificarea soldului; export pe lună, an sau interval',
    'section'     => 'contabilitate',
    'icon'        => 'bi-signpost-split',
    'order'       => 306,
    'scope'       => 'accountancy',
    'actions'     => [
        'view'   => ['label' => 'Vizualizare situație și deschidere documente'],
        'export' => ['label' => 'Export Excel și arhivă ZIP cu documentele'],
    ],
    'endpoints'   => [
        'export_xlsx' => 'export',
        'export_zip'  => 'export',
    ],
];
