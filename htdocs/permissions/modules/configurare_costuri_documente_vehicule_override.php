<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Configurare costuri documente" (?page=configurare_costuri_documente_vehicule_override).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'configurare_costuri_documente_vehicule_override',
    'label'       => 'Configurare costuri documente',
    'description' => 'Costuri și valabilități implicite ale documentelor',
    'section'     => 'administrare',
    'icon'        => 'bi-gear',
    'order'       => 350,
    'scope'       => 'admin',
    // Controllerul cere rolul admin (require_admin_or_403): pagina nu se poate acorda altcuiva.
    'admin_only'  => true,
    'routes'      => ['configurare_costuri_documente_vehicule_override', 'configurare_costuri_documente_soferi'],
    'groups'      => [
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'   => ['label' => 'Vizualizare'],
        'manage' => ['label' => 'Editare costuri & validități', 'group' => 'configurare'],
    ],
    'endpoints'   => [
        'create' => 'manage',
        'store'  => 'manage',
        'edit'   => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
    ],
];
