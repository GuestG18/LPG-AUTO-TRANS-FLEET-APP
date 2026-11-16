<?php
declare(strict_types=1);

/**
 * „eToll” în Tablou de bord: totalurile din Situație eToll (views/etoll/_kpis.php), pe intervalul
 * dashboard-ului (sesiunile închise în perioadă) și, opțional, pe vehiculul ales.
 *
 * @var array  $etollCard       EtollStatementController::dashboardKpiData()
 * @var string $etollSourceUrl
 */
$totals = (array) ($etollCard['totals'] ?? []);
$summary = (array) ($etollCard['summary'] ?? []);
$openSessions = (array) ($etollCard['openSessions'] ?? []);
$selectedPlates = (array) ($etollCard['selectedPlates'] ?? []);
$topupCount = (int) ($etollCard['topupCount'] ?? 0);
$money = static fn(mixed $value): string => $value === null || $value === '' ? '—' : format_number_ro((float) $value, 2) . ' lei';
$etollTopupsHref = $etollSourceUrl . '#etollTopups';
?>
<article class="dashboard-metric-card dash-source-card dash-etoll-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-signpost-split"></i></span>
            <h2>eToll</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($etollSourceUrl) ?>"><span>Deschide Situație eToll</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <p class="das-caption">Sesiunile închise în <?= e((string) ($etollCard['period']['eticheta'] ?? '')) ?> · alimentările portofelului nu depind de vehicul</p>
    <?php require BASE_PATH . '/views/etoll/_kpis.php'; ?>
</article>
