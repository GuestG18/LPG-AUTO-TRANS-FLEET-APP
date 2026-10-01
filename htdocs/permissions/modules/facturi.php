<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Facturi" (?page=facturi).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'facturi',
    'label'       => 'Facturi',
    'description' => 'Facturile de cheltuieli de cursă (cazare, taxe, reparații…), asociate automat curselor',
    'section'     => 'contabilitate',
    'icon'        => 'bi-receipt',
    'order'       => 305,
    'scope'       => 'accountancy',
    'groups'      => [
        'operare'  => 'Operare',
        'stergere' => 'Ștergere',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare și deschidere documente'],
        'create' => ['label' => 'Încărcare factură', 'group' => 'operare'],
        'edit'   => ['label' => 'Editare date factură', 'group' => 'operare'],
        'link'   => ['label' => 'Asociere / dezasociere cursă, reverificare, respingere', 'group' => 'operare'],
        'delete' => ['label' => 'Ștergere factură', 'group' => 'stergere', 'sensitive' => true],
    ],
    'endpoints'   => [
        'store'   => 'create',
        'update'  => 'edit',
        'confirm' => 'link',
        'choose'  => 'link',
        'unlink'  => 'link',
        'rematch' => 'link',
        'reject'  => 'link',
        'delete'  => 'delete',
    ],
];
