<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Activitate utilizatori" (?page=activitate_utilizatori).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'activitate_utilizatori',
    'label'       => 'Activitate utilizatori',
    'description' => 'Jurnalul acțiunilor utilizatorilor',
    'section'     => 'administrare',
    'icon'        => 'bi-person-lines-fill',
    'order'       => 380,
    'scope'       => 'admin',
    // Controllerul cere rolul admin (require_admin_or_403): pagina nu se poate acorda altcuiva.
    'admin_only'  => true,
    'groups'      => [
        'export' => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare jurnal activitate'],
        'export' => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'export' => 'export',
    ],
];
