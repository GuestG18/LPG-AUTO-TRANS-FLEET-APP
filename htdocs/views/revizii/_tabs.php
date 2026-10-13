<?php
/**
 * Tab-urile modulului Revizii (Prezentare generală + categorii + Istoric revizii).
 * Asteapta: $activeTab ('prezentare', 'istoric' sau cheia unei categorii din ReviziiModel::TABS).
 */
$oilIcon = '<svg viewBox="0 0 32 20" aria-hidden="true"><path d="M3 7h6l2-2h6l3 2 9-3-7 10H8L5 11H3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M29 12c0 1.4-1 2.5-2 2.5s-2-1.1-2-2.5 2-3.5 2-3.5 2 2.1 2 3.5z" fill="currentColor"/></svg>';
$navTabs = [
    'prezentare' => ['label' => 'Prezentare generală', 'icon' => '<i class="bi bi-bar-chart-line"></i>', 'url' => build_query_url(['page' => 'revizii', 'tip' => 'prezentare'])],
    'schimb_ulei' => ['label' => 'Schimb ulei', 'icon' => '<span class="rv-oil">' . $oilIcon . '</span>', 'url' => build_query_url(['page' => 'revizii'])],
    'placute_frana' => ['label' => 'Plăcuțe frână', 'icon' => '<i class="bi bi-disc"></i>', 'url' => build_query_url(['page' => 'revizii', 'tip' => 'placute_frana'])],
    'anvelope' => ['label' => 'Anvelope', 'icon' => '<i class="bi bi-record-circle"></i>', 'url' => build_query_url(['page' => 'revizii', 'tip' => 'anvelope'])],
    'istoric' => ['label' => 'Istoric revizii', 'icon' => '<i class="bi bi-file-earmark-text"></i>', 'url' => build_query_url(['page' => 'revizii', 'tip' => 'istoric'])],
];
?>
<nav class="rv-tabs" aria-label="Revizii">
    <?php foreach ($navTabs as $key => $navTab): ?>
        <a class="rv-tab<?= $key === $activeTab ? ' active' : '' ?>" href="<?= e($navTab['url']) ?>"<?= $key === $activeTab ? ' aria-current="page"' : '' ?>><?= $navTab['icon'] ?> <?= e($navTab['label']) ?></a>
    <?php endforeach; ?>
</nav>
