<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Scadențar Leasing" (?page=scadentar_leasing).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'scadentar_leasing',
    'label'       => 'Scadențar Leasing',
    'description' => 'Contracte de leasing și ratele lor',
    'section'     => 'leasing',
    'icon'        => 'bi-calendar-check',
    'order'       => 230,
    'scope'       => 'all',
    'groups'      => [
        'operare'     => 'Operare',
        'facturare'   => 'Facturare',
        'stergere'    => 'Ștergere și recuperare',
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
        'documente'   => 'Documente',
    ],
    'actions'     => [
        'view'          => ['label' => 'Vizualizare scadențar'],
        'create'        => ['label' => 'Adăugare contract leasing', 'group' => 'operare'],
        'edit'          => ['label' => 'Editare contract leasing', 'group' => 'operare'],
        'mark_paid'     => ['label' => 'Marcare rată ca plătită', 'group' => 'facturare'],
        'documents'     => ['label' => 'Documente leasing', 'group' => 'documente'],
        'notifications' => ['label' => 'Setări notificări leasing', 'group' => 'configurare'],
        'close'         => ['label' => 'Închidere contract', 'group' => 'stergere'],
        'archive'       => ['label' => 'Arhivare contract', 'group' => 'stergere', 'sensitive' => true],
        'export'        => ['label' => 'Export Excel', 'group' => 'export'],
    ],
    'endpoints'   => [
        'store'                => 'create',
        'update'               => 'edit',
        'mark_paid'            => 'mark_paid',
        'update_notifications' => 'notifications',
        'upload_document'      => 'documents',
        'close'                => 'close',
        'archive'              => 'archive',
        'export'               => 'export',
    ],
];
