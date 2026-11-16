<?php
declare(strict_types=1);

/**
 * Cardul „Activitate vs. cheltuieli” din Tablou de bord.
 *
 * Separă cele două populații, din ACELAȘI calcul ca „Cost total operațional”
 * (unitățile motorului Cost operațional / km, fără TVA):
 *  - operațională: unități cu ≥1 cursă începută în perioadă (curse_dispecer.data_inceput,
 *    fără cele șterse; cursele deschise se numără, km / venitul lor sunt cei din fișă);
 *  - financiară: toate unitățile cu costuri, inclusiv cele fără curse (nu sunt marcate inactive).
 * Venitul vine doar din curse; cheltuielile nu se socotesc niciodată ca producție.
 *
 * @var array|null $activitySplit  DashboardController::buildActivitySplit()
 * @var string     $periodRangeLabel
 * @var string     $activitySplitUrl  pagina Cost operațional / km cu filtrele mapate
 */
$split = is_array($activitySplit ?? null) ? $activitySplit : null;
$money = static fn(float $value): string => format_number_ro($value, 2);
$moneyShort = static fn(float $value): string => format_number_ro($value, 0);
$perKm = static fn(?float $value): string => $value === null ? '–' : format_number_ro($value, 2);
?>
<article class="dashboard-metric-card dashboard-card-activity-split">
    <header class="dashboard-card-header">
        <div class="dashboard-card-title">
            <span class="dashboard-card-icon" aria-hidden="true"><i class="bi bi-diagram-2"></i></span>
            <h2>Activitate vs. cheltuieli</h2>
        </div>
        <a class="dashboard-card-link" href="<?= e($activitySplitUrl) ?>">
            <span>Vezi detalii</span>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </a>
    </header>

    <?php if ($split === null): ?>
        <p class="das-empty">Motorul Cost operațional / km nu este disponibil pentru această perioadă (lipsește configurarea sau dreptul de acces).</p>
    <?php elseif ($split['light_only']): ?>
        <p class="das-empty">
            Motorul Cost operațional / km modelează doar flota grea: pentru vehicule ușoare nu există curse cu venit.
            Carburantul pe vehicule ușoare / neasociate în perioadă:
            <strong><?= e($money((float) $split['other_vehicles_cost'])) ?> lei</strong>.
        </p>
    <?php else: ?>
        <?php
        $groups = $split['groups'];
        $operational = $split['operational'];
        $costOnly = $groups['cost_only'];
        ?>
        <p class="das-caption">Flota grea · curse după data începerii · <?= e($periodRangeLabel) ?> · fără TVA</p>

        <div class="das-groups" role="list">
            <div class="das-group is-ops" role="listitem">
                <span class="das-group-value" data-dashboard-count="das-both"><?= e((string) $groups['both']['units']) ?></span>
                <span class="das-group-label">Curse + cheltuieli</span>
            </div>
            <div class="das-group is-trips" role="listitem">
                <span class="das-group-value" data-dashboard-count="das-trips-only"><?= e((string) $groups['trips_only']['units']) ?></span>
                <span class="das-group-label">Doar curse</span>
            </div>
            <div class="das-group is-cost" role="listitem">
                <span class="das-group-value" data-dashboard-count="das-cost-only"><?= e((string) $costOnly['units']) ?></span>
                <span class="das-group-label">Doar cheltuieli <small>(fără curse)</small></span>
            </div>
            <?php if ($groups['idle']['units'] > 0): ?>
                <div class="das-group is-idle" role="listitem" title="Vehicule active fără curse și fără costuri alocate în perioadă">
                    <span class="das-group-value" data-dashboard-count="das-idle"><?= e((string) $groups['idle']['units']) ?></span>
                    <span class="das-group-label">Fără curse / costuri</span>
                </div>
            <?php endif; ?>
        </div>

        <section class="das-block" aria-label="Populația operațională">
            <h3>Operațional <small>— doar cele <?= e((string) $operational['units']) ?> vehicule cu curse</small></h3>
            <dl class="das-kpis">
                <div><dt>Curse</dt><dd data-dashboard-count="das-trips"><?= e(format_number_ro((float) $operational['trips'], 0)) ?></dd></div>
                <div><dt>Km</dt><dd data-dashboard-count="das-km"><?= e(format_number_ro((float) $operational['km'], 0)) ?></dd></div>
                <div><dt>Venit (lei)</dt><dd><span data-dashboard-money="das-revenue"><?= e($money((float) $operational['revenue'])) ?></span></dd></div>
                <div><dt>Cost (lei)</dt><dd><span data-dashboard-money="das-ops-cost"><?= e($money((float) $operational['cost'])) ?></span></dd></div>
                <div><dt>Venit / km</dt><dd><?= e($perKm($split['revenue_per_km'])) ?></dd></div>
                <div><dt>Cost / km</dt><dd><?= e($perKm($split['cost_per_km'])) ?></dd></div>
            </dl>
        </section>

        <section class="das-block" aria-label="Costuri în afara activității">
            <h3>Cheltuieli fără activitate de transport</h3>
            <ul class="das-costs">
                <li>
                    <span>Vehicule fără curse (<?= e((string) $costOnly['units']) ?>)</span>
                    <strong><span data-dashboard-money="das-cost-only"><?= e($money((float) $costOnly['cost'])) ?></span> lei</strong>
                </li>
                <?php if ((float) $split['company_cost'] != 0.0): ?>
                    <li><span>Nealocat pe vehicul (companie)</span><strong><?= e($money((float) $split['company_cost'])) ?> lei</strong></li>
                <?php endif; ?>
                <?php if ((float) $split['other_vehicles_cost'] != 0.0): ?>
                    <li><span>Vehicule ușoare / neasociate (carburant)</span><strong><?= e($money((float) $split['other_vehicles_cost'])) ?> lei</strong></li>
                <?php endif; ?>
                <li class="is-total">
                    <span>Total costuri flotă (= Cost total operațional)</span>
                    <strong><span data-dashboard-money="das-total"><?= e($money((float) $split['total_cost'])) ?></span> lei</strong>
                </li>
            </ul>

            <?php if ($split['cost_only_rows'] !== []): ?>
                <details class="das-details">
                    <summary>Vezi vehiculele fără curse și costurile lor</summary>
                    <ul class="das-vehicle-list">
                        <?php foreach ($split['cost_only_rows'] as $row): ?>
                            <li>
                                <span class="das-plate"><?= e((string) $row['label']) ?><?= !empty($row['semi_label']) ? ' + ' . e((string) $row['semi_label']) : '' ?></span>
                                <span class="das-cat"><?= e((string) $row['category_label']) ?></span>
                                <strong><?= e($moneyShort((float) $row['cost'])) ?> lei</strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</article>
