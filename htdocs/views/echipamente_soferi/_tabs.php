<?php
/**
 * Navigația modulului de echipamente. Cele patru ecrane sunt concepte diferite:
 *   Echipamente șoferi  = ce au șoferii
 *   Echipamente TESA    = ce are personalul de birou
 *   Catalog echipamente = ce tipuri de articole există
 *   Stoc echipamente    = câte bucăți avem disponibile
 *
 * Catalogul și stocul sunt COMUNE celor două categorii de personal.
 * Se include cu $activeTab = 'soferi' | 'tesa' | 'catalog' | 'stoc'.
 */

$activeTab = (string) ($activeTab ?? 'soferi');
$canSeeCatalog = !function_exists('can') || can('echipamente_soferi', 'manage_catalog');

$tabs = [
    'soferi' => [
        'label' => 'Echipamente șoferi',
        'icon' => 'bi-person-badge',
        'url' => build_query_url(['page' => 'echipamente_soferi']),
        'visible' => true,
    ],
    'tesa' => [
        'label' => 'Echipamente TESA',
        'icon' => 'bi-briefcase',
        'url' => build_query_url(['page' => 'echipamente_soferi', 'action' => 'tesa']),
        'visible' => true,
    ],
    'catalog' => [
        'label' => 'Catalog echipamente',
        'icon' => 'bi-journal-text',
        'url' => build_query_url(['page' => 'echipamente_soferi', 'action' => 'catalog']),
        'visible' => $canSeeCatalog,
    ],
    'stoc' => [
        'label' => 'Stoc echipamente',
        'icon' => 'bi-boxes',
        'url' => build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc']),
        'visible' => true,
    ],
];
?>
<nav class="des-tabs" aria-label="Navigare modul echipamente">
    <?php foreach ($tabs as $key => $tab): ?>
        <?php if (!$tab['visible']) { continue; } ?>
        <a class="des-tab <?= $activeTab === $key ? 'is-active' : '' ?>"
           href="<?= e((string) $tab['url']) ?>"
           <?= $activeTab === $key ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= e((string) $tab['icon']) ?>" aria-hidden="true"></i>
            <span><?= e((string) $tab['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
