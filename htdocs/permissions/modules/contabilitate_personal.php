<?php
declare(strict_types=1);

/**
 * Permisiunile modulului "Contabilitate Personal" (?page=contabilitate_personal).
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 */
return [
    'key'         => 'contabilitate_personal',
    'label'       => 'Contabilitate Personal',
    'description' => 'Personal, pontaj lunar, salarii și documente',
    'section'     => 'contabilitate',
    'icon'        => 'bi-person-badge',
    'order'       => 280,
    'scope'       => 'accountancy',
    'groups'      => [
        'operare'     => 'Operare',
        'configurare' => 'Configurare',
        'export'      => 'Export & rapoarte',
        'documente'   => 'Documente',
        'salarii'     => 'Salarizare',
    ],
    'actions'     => [
        'view'              => ['label' => 'Vizualizare listă personal'],
        'manage_staff'      => ['label' => 'Adăugare / editare personal', 'group' => 'operare'],
        'salaries'          => ['label' => 'Salarii & istoric salarial', 'group' => 'salarii', 'sensitive' => true],
        'payroll_calculate' => ['label' => 'Calcul salarii (profil, sporuri/rețineri, calculează luna)', 'group' => 'salarii', 'sensitive' => true],
        'payroll_confirm'   => ['label' => 'Confirmare contabilă a calculului salarial', 'group' => 'salarii', 'sensitive' => true],
        'payroll_reopen'    => ['label' => 'Redeschidere calcul salarial confirmat', 'group' => 'salarii', 'default_admin' => true, 'sensitive' => true],
        'payroll_config'    => ['label' => 'Configurare salarii și contribuții (reguli fiscale)', 'group' => 'configurare', 'default_admin' => true, 'sensitive' => true],
        'documents'         => ['label' => 'Documente angajați', 'group' => 'documente'],
        'config_types'      => ['label' => 'Configurare tipuri & documente obligatorii', 'group' => 'configurare'],
        'end_activity'      => ['label' => 'Încheiere activitate', 'group' => 'operare'],
        'export'            => ['label' => 'Export CSV', 'group' => 'export'],
    ],
    'endpoints'   => [
        'store_staff'        => 'manage_staff',
        'update_staff'       => 'manage_staff',
        'delete_staff'       => 'manage_staff',
        'update_salary'      => 'salaries',
        'update_diurna'      => 'salaries',
        'store_document'     => 'documents',
        'delete_document'    => 'documents',
        'store_type'         => 'config_types',
        'update_type'        => 'config_types',
        'add_requirement'    => 'config_types',
        'delete_requirement' => 'config_types',
        'end_activity'       => 'end_activity',
        'export'             => 'export',
    ],
];
