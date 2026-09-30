<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Documente vehicule" (?page=documente).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'documente',
    'label'       => 'Documente vehicule',
    'description' => 'ITP, RCA, CASCO, rovinietă și alte documente ale vehiculelor',
    'section'     => 'vehicule',
    'icon'        => 'bi-file-earmark-text',
    'order'       => 190,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'stergere'    => 'Ștergere și recuperare',
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
    ],
    'actions'     => [
        'view'         => ['label' => 'Vizualizare'],
        'create'       => ['label' => 'Adăugare document', 'group' => 'operare'],
        'edit'         => ['label' => 'Editare document', 'group' => 'operare'],
        'delete'       => ['label' => 'Ștergere document', 'group' => 'stergere'],
        'export'       => ['label' => 'Export CSV', 'group' => 'export'],
        'manage_types' => ['label' => 'Configurare tipuri documente', 'group' => 'configurare', 'admin_only' => true],
    ],
    'endpoints'   => [
        'create'                              => 'create',
        'store'                               => 'create',
        'edit'                                => 'edit',
        'update'                              => 'edit',
        'delete'                              => 'delete',
        'export'                              => 'export',
        'add_document_type_config'            => 'manage_types',
        'manage_document_type_config'         => 'manage_types',
        'update_document_type_expiry'         => 'manage_types',
        'delete_document_type_config'         => 'manage_types',
        'add_document_custom_field_config'    => 'manage_types',
        'delete_document_custom_field_config' => 'manage_types',
    ],
];
