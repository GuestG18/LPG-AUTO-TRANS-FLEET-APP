<?php
declare(strict_types=1);

/**
 * „Carburant” în Tablou de bord: cardurile KPI din Carburanți (views/carburanti/_kpi_cards.php),
 * cu analiza extinsă (fuel-kpi-morph.js) și cardurile pe categorie. Randat leneș de
 * DashboardController::widget, pe filtrele dashboard-ului traduse în filtrele paginii.
 *
 * @var array  $fuelCard       FuelController::dashboardKpiData()
 * @var string $fuelSourceUrl
 */
$fuelData = (array) ($fuelCard['fuelData'] ?? []);
$kpis = (array) ($fuelData['kpis'] ?? []);
$filters = (array) ($fuelCard['filters'] ?? []);
$fuelVehicleGroups = (array) ($fuelCard['vehicleGroups'] ?? []);
require BASE_PATH . '/views/carburanti/_kpi_context.php';
// Pe dashboard, cardurile pe categorie deschid pagina Carburanți filtrată (nu filtrează dashboard-ul).
$fuelSplitUrl = static fn (string $class, bool $isActive): string => build_query_url([
    'page' => 'carburanti',
    'date_from' => (string) ($filters['date_from'] ?? ''),
    'date_to' => (string) ($filters['date_to'] ?? ''),
    'vehicles' => $filters['vehicles'] ?? [],
    'vehicle_class' => $isActive ? '' : $class,
]);
?>
<article class="dashboard-metric-card dash-source-card dash-fuel-card">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-fuel-pump"></i></span>
            <h2>Carburant</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($fuelSourceUrl) ?>"><span>Deschide Carburanți</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>
    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>
    <p class="das-caption">
        Alimentări CardOil · <?= e($periodLabel) ?> · după data alimentării<?= $selectedVehicles !== [] ? ' · ' . e(implode(', ', $selectedVehicles)) : '' ?><?= ($filters['vehicle_class'] ?? '') !== '' ? ' · vehicule ' . e((string) $filters['vehicle_class']) : '' ?>
        · toate vehiculele cu alimentări, cu sau fără curse
    </p>
    <div class="dash-fuel-body">
        <?php require BASE_PATH . '/views/carburanti/_kpi_cards.php'; ?>
    </div>
</article>
