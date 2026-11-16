<?php
declare(strict_types=1);

/**
 * „Alerte documente” în Tablou de bord: sumarul din lista Documente (views/module/_document_summary.php),
 * stare față de azi, cu filtrul de vehicul al paginii în card.
 *
 * @var array $documentSummary, $urgentDocuments, $documentQuickBase
 */
$documentSummaryColClass = 'col-6';
?>
<article class="dashboard-metric-card dash-source-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
            <h2>Alerte documente</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e(build_query_url($documentQuickBase)) ?>"><span>Vezi documentele</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <?php require BASE_PATH . '/views/module/_document_summary.php'; ?>
</article>
