<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Drepturi de acces" (?page=drepturi_acces).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'drepturi_acces',
    'label'       => 'Drepturi de acces',
    'description' => 'Pagini și acțiuni permise fiecărui utilizator',
    'section'     => 'administrare',
    'icon'        => 'bi-shield-lock',
    'order'       => 370,
    'scope'       => 'admin',
    // Controllerul cere rolul admin (require_admin_or_403): pagina nu se poate acorda altcuiva.
    'admin_only'  => true,
    'groups'      => [
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare'],
        'manage' => ['label' => 'Modificare drepturi & șabloane', 'group' => 'configurare'],
    ],
];
