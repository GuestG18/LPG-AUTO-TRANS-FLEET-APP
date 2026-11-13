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
    'description' => 'Revizii & Mentenanță: planuri X / Y (ciclu 5 + 1), scadențe pe km / ore / calendar, execuție, istoric; plus ecranele km SAS (ulei, plăcuțe, anvelope)',
    'section'     => 'mentenanta',
    'icon'        => 'bi-droplet-half',
    'order'       => 335,
    'scope'       => 'all',
    'actions'     => [
        'view' => ['label' => 'Vizualizare și sincronizare SasFleet (inclusiv istoricul reviziilor și documentele atașate)'],
        'edit' => ['label' => 'Configurare intervale, înregistrare revizii, referință și verificare intervenții', 'default_admin' => true],
        'simulate' => ['label' => 'Simulare calcul revizie pe date istorice', 'default_admin' => true],
        'export' => ['label' => 'Istoric revizii și raportul săptămânal: export Excel / PDF, generare și descărcare rapoarte'],
        'documents' => ['label' => 'Istoric revizii: adăugare și eliminare documente atașate', 'default_admin' => true],
        'delete_history' => ['label' => 'Istoric revizii: ștergere intervenții (T0 revine la starea anterioară)', 'default_admin' => true],
        'configure' => ['label' => 'Revizii V2: planuri de mentenanță, catalog operațiuni, șabloane X / Y, import, corecții de ciclu și anulări de execuții', 'default_admin' => true],
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
        // Raportul saptamanal (butonul „Descarcă Excel” din email)
        'raport_export'           => 'export',
        'istoric_doc_upload'      => 'documents',
        'istoric_doc_delete'      => 'documents',
        'istoric_verify'          => 'edit',
        'istoric_reference'       => 'edit',
        'istoric_delete'          => 'delete_history',
        // Revizii & Mentenanta V2 (pagina unica)
        'v2_data'               => 'view',
        'v2_vehicle'            => 'view',
        'v2_explain'            => 'view',
        'v2_workorder'          => 'view',
        'v2_history'            => 'view',
        'v2_history_detail'     => 'view',
        'v2_doc'                => 'view',
        'v2_config'             => 'view',
        'v2_history_export'     => 'export',
        'v2_workorder_save'     => 'edit',
        'v2_workorder_finalize' => 'edit',
        'v2_workorder_cancel'   => 'edit',
        'v2_operation_record'   => 'edit',
        'v2_doc_upload'         => 'documents',
        'v2_execution_cancel'   => 'configure',
        'v2_config_save'        => 'configure',
        'v2_import_preview'     => 'configure',
        'v2_import_apply'       => 'configure',
    ],
];
