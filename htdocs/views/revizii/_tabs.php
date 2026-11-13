<?php
/**
 * Ecranele detaliate existente ale modulului Revizii (Prezentare generală, Schimb ulei pe km SAS,
 * Plăcuțe frână, Anvelope, Istoric cu documente). Din Revizii V2 nu mai exista bara de tab-uri:
 * spatiul de lucru este pagina unica ?page=revizii; aici ramane doar legatura de intoarcere.
 * Asteapta: $activeTab.
 */
$legacyLabels = [
    'prezentare' => 'Prezentare generală (km SAS)',
    'schimb_ulei' => 'Schimb ulei — km echivalenți SAS',
    'placute_frana' => 'Plăcuțe frână — km SAS',
    'anvelope' => 'Anvelope — km SAS',
    'istoric' => 'Istoric revizii (km SAS) — documente și rapoarte',
];
?>
<nav class="rv-legacy-crumb" aria-label="Revizii">
    <a href="<?= e(build_query_url(['page' => 'revizii'])) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Revizii &amp; Mentenanță</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page"><?= e($legacyLabels[$activeTab ?? ''] ?? 'Vizualizare detaliată') ?></span>
</nav>
