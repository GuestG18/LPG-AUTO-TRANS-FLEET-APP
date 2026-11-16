<?php
declare(strict_types=1);

/**
 * „Cheltuieli” în Tablou de bord: banda KPI din pagina Cheltuieli (views/cheltuieli/_kpi_band.php),
 * cu aceeași logică (valoarea documentului cu TVA + carburantul din Carburanți).
 *
 * @var array  $summary            ExpenseModel::getSummary()
 * @var string $expensesSourceUrl
 */
$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
?>
<article class="dashboard-metric-card dash-source-card dash-expenses-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
            <h2>Cheltuieli</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($expensesSourceUrl) ?>"><span>Deschide registrul</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <p class="das-caption">Registrul Cheltuieli · data cheltuielii · cu TVA, include carburantul · vehicule cu sau fără curse</p>
    <?php require BASE_PATH . '/views/cheltuieli/_kpi_band.php'; ?>
</article>
