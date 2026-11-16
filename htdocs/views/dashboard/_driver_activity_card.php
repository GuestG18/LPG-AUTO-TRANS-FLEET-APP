<?php
declare(strict_types=1);

/**
 * „Activitate șoferi” în Tablou de bord: cardurile KPI din Istoric activitati sofer
 * (views/driver_activity_history/_kpi_cards.php), cu filtrele paginii direct în card:
 * Șoferi (mai mulți), Interval de timp (perioada panoului / o lună cu ‹ › / interval), Tip transport,
 * Beneficiar, Vehicul. Alegerile se salvează în layout-ul utilizatorului (config-ul cardului) și se
 * validează pe server cu regulile paginii. Fără șofer ales: totalul șoferilor cu curse (ca la
 * comparația din pagină); cu un singur șofer: exact cardurile lui din pagină.
 *
 * @var array  $driverCard  DriverActivityHistoryController::dashboardCardData()
 * @var string $driverSourceUrl
 * Filtrele cardului: views/dashboard/_card_filters.php (variabilele $cf* puse de DashboardController::widget).
 */
$kpis = (array) ($driverCard['kpis'] ?? []);
$filters = (array) ($driverCard['filters'] ?? []);
$canFinancial = !empty($driverCard['can_financial']);
$driverCount = (int) ($driverCard['driver_count'] ?? 0);
$activeCount = (int) ($driverCard['active_count'] ?? 0);
$isAll = !empty($driverCard['is_all']);
$driver = is_array($driverCard['driver'] ?? null) ? $driverCard['driver'] : null;
// Un singur șofer = pagina în modul individual; altfel totalurile selecției („șoferii comparați”).
$isCompare = $driver === null;
$driverOptions = (array) ($driverCard['options'] ?? []);
$droppedById = (array) ($driverCard['dropped'] ?? []); // id => nume: aleși, dar fără curse cu filtrele de acum
$droppedNames = array_values($droppedById);
require BASE_PATH . '/views/driver_activity_history/_kpi_helpers.php';
$diurneNote = $diurnaNoteFor($kpis);
$periodLabel = $fmtDate($filters['date_start'] ?? null) . ' – ' . $fmtDate($filters['date_end'] ?? null);

?>
<article class="dashboard-metric-card dash-source-card dash-driver-activity">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
            <h2>Activitate șoferi</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($driverSourceUrl) ?>"><span>Deschide pagina</span><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </header>

    <?php require BASE_PATH . '/views/dashboard/_card_filters.php'; ?>

    <p class="das-caption">
        <?php if ($driver !== null): ?>
            <?= e((string) ($driver['nume'] ?? '')) ?> · <?= e($periodLabel) ?>
        <?php elseif (!$isAll && $driverCount === 0): ?>
            Șoferii aleși · <?= e($periodLabel) ?>
        <?php elseif (!$isAll): ?>
            Totalul celor <?= e((string) $driverCount) ?> șoferi aleși · <?= e($periodLabel) ?>
        <?php else: ?>
            Totalul celor <?= e((string) $driverCount) ?> șoferi cu curse în <?= e($periodLabel) ?>
        <?php endif; ?>
        · curse care se suprapun cu perioada (regula paginii Istoric activități șofer)
    </p>
    <?php if ($droppedNames !== []): ?>
        <p class="das-empty"><i class="bi bi-info-circle" aria-hidden="true"></i>
            <?= e(implode(', ', $droppedNames)) ?> nu <?= count($droppedNames) > 1 ? 'au' : 'are' ?> curse cu aceste filtre; alege alți șoferi sau „Toți șoferii cu curse”.
        </p>
    <?php endif; ?>
    <?php if ($driverCount === 0): ?>
        <?php if ($droppedNames === []): ?>
            <p class="das-empty">Niciun șofer cu curse cu aceste filtre.</p>
        <?php endif; ?>
    <?php else: ?>
        <?php require BASE_PATH . '/views/driver_activity_history/_kpi_cards.php'; ?>
    <?php endif; ?>
</article>
