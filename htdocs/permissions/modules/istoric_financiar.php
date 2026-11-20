<?php
declare(strict_types=1);

/**
 * Permisiunile paginii "Istoric Financiar Vehicul" (?page=istoric_financiar).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'istoric_financiar',
    'label'       => 'Istoric Financiar Vehicul',
    'description' => 'Dosare de achiziție, investiții (GPL), costuri și valoarea fiecărui vehicul',
    'section'     => 'contabilitate',
    'icon'        => 'bi-layers',
    'order'       => 318,
    'scope'       => 'accountancy',
    'groups'      => [
        'operare'    => 'Operare',
        'financiar'  => 'Financiar',
    ],
    'actions'     => [
        'view'        => ['label' => 'Vizualizare dosare și istoric financiar', 'sensitive' => true],
        'manage'      => ['label' => 'Dosare de achiziție: creare, costuri, plăți, evenimente, documente', 'group' => 'operare', 'default_admin' => true, 'sensitive' => true],
        'proiecte'    => ['label' => 'Proiecte de investiții (GPL): creare, legături, stadii', 'group' => 'operare', 'default_admin' => true, 'sensitive' => true],
        'clasificare' => ['label' => 'Tratament contabil (capitalizabil / cheltuială)', 'group' => 'financiar', 'default_admin' => true, 'sensitive' => true],
    ],
    'endpoints'   => [
        'op' => 'view',
    ],
];
