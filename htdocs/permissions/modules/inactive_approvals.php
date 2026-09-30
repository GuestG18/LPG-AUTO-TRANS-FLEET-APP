<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Solicitari aprobare inactive" (?page=inactive_approvals).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'inactive_approvals',
    'label'       => 'Solicitari aprobare inactive',
    'description' => 'Aprobare / respingere solicitări pentru resurse inactive',
    'section'     => 'operational',
    'icon'        => 'bi-shield-exclamation',
    'order'       => 80,
    'scope'       => 'all',
    'groups'      => [
        'aprobare' => 'Aprobare',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare solicitari'],
        'review' => ['label' => 'Aprobare / respingere solicitari', 'group' => 'aprobare', 'default_admin' => true, 'sensitive' => true],
    ],
    'endpoints'   => [
        'approve' => 'review',
        'reject'  => 'review',
        'reopen'  => 'review',
    ],
];
