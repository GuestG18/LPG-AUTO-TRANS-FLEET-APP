<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Revizii" (?page=revizii) — schimb ulei calculat din SasFleet.
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'revizii',
    'label'       => 'Revizii',
    'description' => 'Schimb ulei: km echivalenți din SasFleet (km + ore motor), cap tractor și semi-remorcă separat',
    'section'     => 'mentenanta',
    'icon'        => 'bi-droplet-half',
    'order'       => 335,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare și sincronizare SasFleet (inclusiv istoricul reviziilor și documentele atașate)'],
        'edit' => ['label' => 'Configurare intervale, înregistrare revizii, referință și verificare intervenții', 'default_admin' => true],
        'simulate' => ['label' => 'Simulare calcul revizie pe date istorice', 'default_admin' => true],
        'export' => ['label' => 'Istoric revizii: export Excel / PDF, generare și descărcare rapoarte'],
        'documents' => ['label' => 'Istoric revizii: adăugare și eliminare documente atașate', 'default_admin' => true],
    ],
    'endpoints'   => [
        'save_config' => 'edit',
        'record'      => 'edit',
        'sync'        => 'view',
        'simulate'    => 'simulate',
        // Istoric revizii (?tip=istoric)
        'istoric_data'            => 'view',
        'istoric_detail'          => 'view',
        'istoric_doc'             => 'view',
        'istoric_export'          => 'export',
        'istoric_report'          => 'export',
        'istoric_reports'         => 'export',
        'istoric_report_download' => 'export',
        'istoric_doc_upload'      => 'documents',
        'istoric_doc_delete'      => 'documents',
        'istoric_verify'          => 'edit',
        'istoric_reference'       => 'edit',
    ],
];
