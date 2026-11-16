<?php
declare(strict_types=1);

/**
 * „Facturare (Centralizator)” în Tablou de bord: cardurile KPI din Centralizator facturare
 * (views/centralizator_facturare/_kpi_cards.php), cu defalcarea pe tip de transport la hover și
 * comparația cu luna anterioară. Centralizatorul lucrează pe LUNA DE FACTURARE (regula fiecărui
 * beneficiar), deci cardul arată luna în care se termină perioada dashboard-ului.
 *
 * @var array  $cfReport       CentralizatorFacturareService::getKpiReport()
 * @var string $cfSourceUrl
 * @var string $cfRefacturariUrl
 */
$kpiCards = (array) ($cfReport['kpis']['cards'] ?? []);
$refKpi = (array) ($cfReport['kpis']['refacturari'] ?? []);
$cfFilters = (array) ($cfReport['filters'] ?? []);
require BASE_PATH . '/views/centralizator_facturare/_kpi_helpers.php';
?>
<article class="dashboard-metric-card dash-source-card dash-cf-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-calculator"></i></span>
            <h2>Facturare (Centralizator)</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($cfSourceUrl) ?>"><span>Deschide Centralizatorul</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <p class="das-caption">
        Luna de facturare <?= e((string) ($cfFilters['month_label'] ?? '')) ?> (regula de facturare a fiecărui beneficiar)
        <?= !empty($cfFilters['vehicle_id']) ? '· doar vehiculul selectat' : '' ?>
        · treci cu mouse-ul peste un card pentru defalcarea pe tip de transport
    </p>
    <div class="cf-page dash-cf">
        <div class="dash-cf-grid">
            <?php require BASE_PATH . '/views/centralizator_facturare/_kpi_cards.php'; ?>
        </div>
        <a class="dash-cf-ref" href="<?= e($cfRefacturariUrl) ?>" title="Detaliile refacturărilor se deschid în Centralizator">
            <i class="bi bi-receipt-cutoff" aria-hidden="true"></i>
            <span><?= e((string) ($refKpi['title'] ?? 'Total refacturări')) ?></span>
            <strong><?= e($fmtMoneyKpi($refKpi['value'] ?? 0)) ?></strong>
            <span class="cf-kpi-foot"><?= $comparisonMarkup($refKpi['comparison'] ?? null) ?></span>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </a>
    </div>
</article>
