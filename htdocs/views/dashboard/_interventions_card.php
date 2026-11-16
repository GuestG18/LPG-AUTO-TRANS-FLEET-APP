<?php
declare(strict_types=1);

/**
 * „Intervenții pe status” în Tablou de bord: cardurile de status din Mentenanță → Intervenții
 * (views/maintenance/_interventions_v2_kpis.php), cu filtrele paginii în card (tip, responsabil,
 * sursă, vehicul, data programării). Statusul e dimensiunea cardurilor, deci nu e filtru.
 * Click pe un status deschide lista din pagină cu aceleași filtre.
 *
 * @var array    $ivKpiValues  InterventionWorkflowModel::kpis()
 * @var callable $ivKpiHref
 * @var string   $ivSourceUrl
 */
$ivActiveStatus = '';
?>
<article class="dashboard-metric-card dash-source-card iv2">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-tools"></i></span>
            <h2>Intervenții pe status</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($ivSourceUrl) ?>"><span>Vezi lista</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <?php require BASE_PATH . '/views/maintenance/_interventions_v2_kpis.php'; ?>
</article>
