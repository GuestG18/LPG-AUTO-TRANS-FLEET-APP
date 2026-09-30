<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Notificări" (?page=notificari).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'notificari',
    'label'       => 'Notificări',
    'description' => 'Reguli de notificare pe email',
    'section'     => 'administrare',
    'icon'        => 'bi-bell',
    'order'       => 360,
    'scope'       => 'admin',
    // Controllerul cere rolul admin (require_admin_or_403): pagina nu se poate acorda altcuiva.
    'admin_only'  => true,
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'         => ['label' => 'Vizualizare'],
        'manage_rules' => ['label' => 'Creare / editare reguli', 'group' => 'configurare'],
        'toggle'       => ['label' => 'Activare / dezactivare reguli', 'group' => 'configurare'],
        'send_test'    => ['label' => 'Trimitere email de test', 'group' => 'operare'],
    ],
    'endpoints'   => [
        'create'    => 'manage_rules',
        'store'     => 'manage_rules',
        'edit'      => 'manage_rules',
        'update'    => 'manage_rules',
        'delete'    => 'manage_rules',
        'toggle'    => 'toggle',
        'send_test' => 'send_test',
        'run_test'  => 'send_test',
    ],
];
