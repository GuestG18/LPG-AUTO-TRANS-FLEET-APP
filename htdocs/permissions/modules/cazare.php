<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Cazare" (?page=cazare).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'cazare',
    'label'       => 'Cazare',
    'description' => 'Cazările șoferilor, asociate automat curselor',
    'section'     => 'contabilitate',
    'icon'        => 'bi-house-heart',
    'order'       => 310,
    'scope'       => 'accountancy',
    'groups'      => [
        'operare'  => 'Operare',
        'stergere' => 'Ștergere și recuperare',
        'export'   => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare'],
        'create' => ['label' => 'Adăugare cazare', 'group' => 'operare'],
        'edit'   => ['label' => 'Editare cazare', 'group' => 'operare'],
        'delete' => ['label' => 'Ștergere cazare', 'group' => 'stergere'],
        'link'   => ['label' => 'Asociere manuală la cursă / reverificare', 'group' => 'operare'],
        'export' => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'store'           => 'create',
        'import_sheet'    => 'create',
        'update'          => 'edit',
        'delete'          => 'delete',
        'bulk_delete'     => 'delete',
        'delete_document' => 'edit',
        'link'            => 'link',
        'unlink'          => 'link',
        'rematch'         => 'link',
        'export'          => 'export',
    ],
];
