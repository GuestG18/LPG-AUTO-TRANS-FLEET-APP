<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Documente șoferi" (?page=documente_soferi).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'documente_soferi',
    'label'       => 'Documente șoferi',
    'description' => 'Permise, atestate, avize medicale',
    'section'     => 'soferi',
    'icon'        => 'bi-file-earmark-medical',
    'order'       => 250,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'stergere'    => 'Ștergere și recuperare',
        'configurare' => 'Configurare',
    ],
    'actions'     => [
        'view'         => ['label' => 'Vizualizare'],
        'create'       => ['label' => 'Adăugare document', 'group' => 'operare'],
        'edit'         => ['label' => 'Editare document', 'group' => 'operare'],
        'delete'       => ['label' => 'Ștergere document', 'group' => 'stergere'],
        'manage_types' => ['label' => 'Configurare tipuri documente', 'group' => 'configurare', 'admin_only' => true],
    ],
    'endpoints'   => [
        'create'                                     => 'create',
        'store'                                      => 'create',
        'edit'                                       => 'edit',
        'update'                                     => 'edit',
        'delete'                                     => 'delete',
        'add_driver_document_type_config'            => 'manage_types',
        'manage_driver_document_type_config'         => 'manage_types',
        'update_driver_document_type_expiry'         => 'manage_types',
        'delete_driver_document_type_config'         => 'manage_types',
        'add_driver_document_custom_field_config'    => 'manage_types',
        'delete_driver_document_custom_field_config' => 'manage_types',
    ],
];
