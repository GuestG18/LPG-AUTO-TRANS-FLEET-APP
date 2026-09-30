<?php
declare(strict_types=1);

/**
 * Sectiunile (categoriile) in care sunt grupate modulele in "Drepturi de acces".
 * Un modul care declara o sectiune inexistenta aici apare totusi, intr-o sectiune
 * creata automat (eticheta = cheia). Vezi permissions/README.md.
 */
return [
    'operational'   => [
        'label' => 'Operațional',
        'icon'  => 'bi-speedometer2',
        'order' => 10,
    ],
    'vehicule'      => [
        'label' => 'Vehicule',
        'icon'  => 'bi-car-front',
        'order' => 20,
    ],
    'leasing'       => [
        'label' => 'Leasing',
        'icon'  => 'bi-calendar-check',
        'order' => 30,
    ],
    'soferi'        => [
        'label' => 'Șoferi',
        'icon'  => 'bi-person-vcard',
        'order' => 40,
    ],
    'contabilitate' => [
        'label' => 'Contabilitate',
        'icon'  => 'bi-wallet2',
        'order' => 50,
    ],
    'mentenanta'    => [
        'label' => 'Mentenanță',
        'icon'  => 'bi-wrench-adjustable',
        'order' => 60,
    ],
    'administrare'  => [
        'label' => 'Administrare',
        'icon'  => 'bi-gear-wide-connected',
        'order' => 70,
    ],
];
