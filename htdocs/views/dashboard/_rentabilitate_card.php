<?php
declare(strict_types=1);

/**
 * „Rentabilitate” în Tablou de bord: cele 4 carduri KPI din Rentabilitate Operațională, randate de aceeași
 * componentă ca pagina (assets/js/rentabilitate-kpi.js, mod static). Randat leneș de DashboardController::widget.
 * Filtrele sunt ale cardului (bara _card_filters.php, salvate în layout); nu ating pagina sau alte carduri.
 *
 * @var array  $ropKpi        ProfitabilityRegisterService::kpiSummary()
 * @var string $ropSourceUrl  pagina Rentabilitate cu aceleași filtre
 */
$ropData = [
    'kpi' => $ropKpi['kpi'],
    'optiuni' => $ropKpi['optiuni'],
    'aplicat' => $ropKpi['aplicat'],
    'mode' => $ropKpi['mode'],
    'from' => $ropKpi['from'],
    'to' => $ropKpi['to'],
    // navigarea din card cere indicatorii / defalcarile de la pagina Rentabilitate, cu scopul PROPRIU al cardului
    'endpoint' => build_query_url(['page' => 'rentabilitate']),
];
?>
<article class="dashboard-metric-card dash-source-card dash-rop-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
            <h2>Rentabilitate operațională</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($ropSourceUrl) ?>"><span>Deschide Rentabilitate</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <p class="das-caption">
        <?= e(format_date_ro((string) $ropKpi['from'])) ?> – <?= e(format_date_ro((string) $ropKpi['to'])) ?>
        · <?= $ropKpi['mode'] === 'soferi' ? 'perspectiva șoferi' : 'perspectiva vehicule' ?>
        · venitul = tariful curselor (nefacturat încă) · agregat ponderat: total costuri / total km
        <?php if (!empty($ropKpi['anulate'])): ?>
            · <span class="text-warning-emphasis">filtre fără activitate în perioadă ignorate</span>
        <?php endif; ?>
    </p>
    <div data-rop-kpi="<?= e(json_encode($ropData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) ?>"></div>
</article>
