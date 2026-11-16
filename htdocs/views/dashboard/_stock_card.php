<?php
declare(strict_types=1);

/**
 * „Stoc piese” în Tablou de bord: KPI-urile din Mentenanță → Stoc piese (views/maintenance/_stock_kpis.php).
 *
 * @var array  $stockKpis       MaintenanceModel::getStockKpis()
 * @var string $stockSourceUrl
 */
?>
<article class="dashboard-metric-card dash-source-card dash-stock-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
            <h2>Stoc piese</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($stockSourceUrl) ?>"><span>Deschide stocul</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/maintenance/_stock_kpis.php'; ?>
</article>
