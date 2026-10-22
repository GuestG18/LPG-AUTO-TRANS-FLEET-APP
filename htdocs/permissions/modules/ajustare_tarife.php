<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Ajustare tarife combustibil & curs BNR" (?page=ajustare_tarife).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'ajustare_tarife',
    'label'       => 'Ajustare tarife combustibil & curs BNR',
    'description' => 'Curs EUR/RON BNR, motorină lei/EUR, prag de ajustare, tarife simulate și aplicarea manuală',
    'section'     => 'operational',
    'icon'        => 'bi-currency-exchange',
    'order'       => 131,
    'scope'       => 'admin',
    'groups'      => [
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'      => ['label' => 'Vizualizare indicatori, grafice și tarife simulate'],
        'sync'      => ['label' => 'Sincronizare manuală BNR și salvare simulare', 'default_admin' => true],
        'configure' => ['label' => 'Setări, referințe salvate și ponderi confirmate', 'group' => 'configurare', 'default_admin' => true, 'sensitive' => true],
        'apply'     => ['label' => 'Aplicare tarife contractuale (versiuni noi de tarif)', 'group' => 'configurare', 'default_admin' => true, 'sensitive' => true],
    ],
    'endpoints'   => [
        'series'          => 'view',
        'auto_sync'       => 'view',
        'compare'         => 'view',
        'approval'        => 'view',
        'approvals'       => 'view',
        'sync_bnr'        => 'sync',
        'save_snapshot'   => 'sync',
        'save_settings'   => 'configure',
        'save_baseline'   => 'configure',
        'save_test_months' => 'configure',
        'compare_periods' => 'view',
        'save_period_baseline' => 'configure',
        'apply'           => 'apply',
    ],
];
