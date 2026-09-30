<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Utilizatori" (?page=utilizatori).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'utilizatori',
    'label'       => 'Utilizatori',
    'description' => 'Conturile de acces în aplicație',
    'section'     => 'administrare',
    'icon'        => 'bi-people',
    'order'       => 340,
    'scope'       => 'admin',
    // Controllerul cere rolul admin (require_admin_or_403): pagina nu se poate acorda altcuiva.
    'admin_only'  => true,
    'groups'      => [
        'operare'  => 'Operare',
        'stergere' => 'Ștergere și recuperare',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare'],
        'create' => ['label' => 'Adăugare utilizator', 'group' => 'operare'],
        'edit'   => ['label' => 'Editare utilizator & rol', 'group' => 'operare'],
        'delete' => ['label' => 'Ștergere utilizator', 'group' => 'stergere'],
    ],
    'endpoints'   => [
        'create' => 'create',
        'store'  => 'create',
        'edit'   => 'edit',
        'update' => 'edit',
        'delete' => 'delete',
    ],
];
