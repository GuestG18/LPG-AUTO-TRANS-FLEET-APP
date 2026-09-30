<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Foști angajați" (?page=fosti_angajati).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'fosti_angajati',
    'label'       => 'Foști angajați',
    'description' => 'Istoricul angajaților plecați și reangajări',
    'section'     => 'contabilitate',
    'icon'        => 'bi-person-dash',
    'order'       => 290,
    'scope'       => 'accountancy',
    'groups'      => [
        'operare'   => 'Operare',
        'export'    => 'Export & rapoarte',
        'documente' => 'Documente',
    ],
    'actions'     => [
        'view'             => ['label' => 'Vizualizare'],
        'edit_termination' => ['label' => 'Editare date încetare', 'group' => 'operare'],
        'rehire'           => ['label' => 'Reangajare', 'group' => 'operare'],
        'history_sheet'    => ['label' => 'Fișă istoric (print)', 'group' => 'documente'],
        'export'           => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'update_termination' => 'edit_termination',
        'rehire'             => 'rehire',
        'history_sheet'      => 'history_sheet',
        'export'             => 'export',
    ],
];
