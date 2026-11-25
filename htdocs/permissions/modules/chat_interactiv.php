<?php
declare(strict_types=1);

/**
 * Permisiunile paginii "Chat Interactiv" (?page=chat_interactiv) — LPG Connect, lumea 3D multiplayer.
 *
 * Cheile modulului si ale actiunilor sunt STABILE: sunt salvate in access_permissions.
 * Eticheta, descrierea, sectiunea si grupul se pot schimba oricand. Vezi permissions/README.md.
 *
 * Endpoint-urile de scriere isi verifica singure dreptul (ChatInteractivService): publicarea unei
 * noutati cere publish_news, atribuirea unei note / unui termen altui angajat cere assign_tasks.
 */
return [
    'key'         => 'chat_interactiv',
    'label'       => 'Chat Interactiv',
    'description' => 'LPG Connect: sediu virtual 3D cu panou informativ, note, termene și chat',
    'section'     => 'operational',
    'icon'        => 'bi-people',
    'order'       => 42,
    'scope'       => 'all',
    'groups'      => [
        'panou' => 'Panou informativ',
        'prezentare' => 'Prezentare',
    ],
    'actions'     => [
        'view'         => ['label' => 'Acces în sediul virtual (note proprii, chat)'],
        'publish_news' => ['label' => 'Publicare noutăți generale', 'group' => 'panou', 'default_admin' => true],
        'assign_tasks' => ['label' => 'Atribuire note și termene altor angajați', 'group' => 'panou', 'default_admin' => true],
        'share_screen' => ['label' => 'Partajare ecran pe monitorul din cameră (de la laptop)', 'group' => 'prezentare'],
    ],
];
