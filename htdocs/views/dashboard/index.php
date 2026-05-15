<?php
$costMentenanta30Zile = (float) ($kpi['cost_mentenanta_30_zile'] ?? 0);
$vehicleFilterActive = ($dashboardFilters['vehicle_id'] ?? null) !== null;
?>

<div class="card border-0 shadow-sm mb-4 dashboard-filter-card">
    <div class="card-body">
        <div class="d-flex flex-column flex-xl-row justify-content-between gap-3 align-items-xl-end">
            <div>
                <p class="text-uppercase text-muted small fw-semibold mb-2">Filtre dashboard</p>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($periodOptions as $periodValue => $periodLabel): ?>
                        <?php $periodUrl = build_query_url([
                            'page' => 'dashboard',
                            'period' => $periodValue,
                            'vehicle_id' => $dashboardFilters['vehicle_id'],
                        ]); ?>
                        <a
                            class="btn btn-sm dashboard-period-link <?= $dashboardFilters['period'] === $periodValue ? 'active' : '' ?>"
                            href="<?= e($periodUrl) ?>"
                        >
                            <?= e($periodLabel) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <form class="row g-2 align-items-end" method="get">
                <input type="hidden" name="page" value="dashboard">
                <input type="hidden" name="period" value="<?= e($dashboardFilters['period']) ?>">

                <div class="col-12 col-md-auto">
                    <label class="form-label mb-1" for="dashboard_vehicle_id">Vehicul</label>
                    <select class="form-select" id="dashboard_vehicle_id" name="vehicle_id">
                        <option value="">Toate vehiculele</option>
                        <?php foreach ($vehicleOptions as $vehicle): ?>
                            <option
                                value="<?= e((string) $vehicle['id']) ?>"
                                <?= (int) ($vehicle['id'] ?? 0) === (int) ($dashboardFilters['vehicle_id'] ?? 0) ? 'selected' : '' ?>
                            >
                                <?= e($vehicle['nr_inmatriculare']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-auto">
                    <button class="btn btn-primary w-100" type="submit">Aplic&#259; filtre</button>
                </div>

                <div class="col-12 col-md-auto">
                    <a class="btn btn-outline-secondary w-100" href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">Reseteaz&#259;</a>
                </div>
            </form>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <span class="badge text-bg-light border px-3 py-2">Perioad&#259;: <?= e($dashboardFilters['period_label']) ?></span>
            <span class="badge text-bg-light border px-3 py-2">Vehicul: <?= e($dashboardFilters['vehicle_label']) ?></span>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-4 col-xxl-3">
        <div class="card kpi-card fancy-kpi kpi-total border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <p class="kpi-label mb-0">Total vehicule</p>
                    <span class="kpi-icon"><i class="bi bi-truck-front"></i></span>
                </div>
                <div class="kpi-value"><?= e((string) $kpi['total_vehicule']) ?></div>
                <p class="kpi-sub mb-0">
                    <?= $vehicleFilterActive ? 'Vehicul selectat' : '&Icirc;nregistrate &icirc;n sistem' ?>
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-4 col-xxl-3">
        <div class="card kpi-card fancy-kpi kpi-active border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <p class="kpi-label mb-0">Vehicule active</p>
                    <span class="kpi-icon"><i class="bi bi-check2-circle"></i></span>
                </div>
                <div class="kpi-value"><?= e((string) $kpi['vehicule_active']) ?></div>
                <p class="kpi-sub mb-0">
                    <?= $vehicleFilterActive ? 'Status pentru vehiculul selectat' : 'Disponibile pentru curse' ?>
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-4 col-xxl-3">
        <div class="card kpi-card fancy-kpi kpi-fuel border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <p class="kpi-label mb-0">Cost combustibil</p>
                    <span class="kpi-icon"><i class="bi bi-fuel-pump"></i></span>
                </div>
                <div class="kpi-value"><?= e(format_number_ro($kpi['cost_combustibil_luna'], 2)) ?> <small>lei</small></div>
                <p class="kpi-sub mb-0"><?= e($dashboardFilters['period_label']) ?></p>
                <p class="kpi-sub-secondary mb-0"><?= e($dashboardFilters['vehicle_label']) ?></p>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-6 col-xxl-3">
        <div class="card kpi-card fancy-kpi kpi-maintenance border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <p class="kpi-label mb-0">Cost mentenan&#539;&#259;</p>
                    <span class="kpi-icon"><i class="bi bi-tools"></i></span>
                </div>
                <div class="kpi-value"><?= e(format_number_ro($kpi['cost_mentenanta_luna'], 2)) ?> <small>lei</small></div>
                <p class="kpi-sub mb-0"><?= e($dashboardFilters['period_label']) ?></p>
                <p class="kpi-sub-secondary mb-0"><?= e($dashboardFilters['vehicle_label']) ?></p>
                <?php if ($dashboardFilters['period'] !== 'ultimele_30_zile'): ?>
                    <p class="kpi-sub-secondary mb-0">Referin&#539;&#259; 30 zile: <?= e(format_number_ro($costMentenanta30Zile, 2)) ?> lei</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6 col-xl-6 col-xxl-3">
        <div class="card kpi-card fancy-kpi kpi-expiry border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <p class="kpi-label mb-0">Documente care expir&#259;</p>
                    <span class="kpi-icon"><i class="bi bi-exclamation-triangle"></i></span>
                </div>
                <div class="kpi-value text-danger"><?= e((string) $kpi['documente_expira_30']) ?></div>
                <p class="kpi-sub mb-0">&Icirc;n urm&#259;toarele 30 de zile</p>
                <p class="kpi-sub-secondary mb-0"><?= e($dashboardFilters['vehicle_label']) ?></p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Documente care expir&#259; &icirc;n 30 de zile</h2>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente'])) ?>">Vezi toate</a>
            </div>
            <div class="card-body p-0">
                <?php if ($expiringDocuments === []): ?>
                    <div class="p-3 text-muted">Nu exist&#259; documente care expir&#259; &icirc;n urm&#259;toarele 30 de zile.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                            <tr>
                                <th class="px-3">Vehicul</th>
                                <th>Tip document</th>
                                <th>Num&#259;r document</th>
                                <th class="text-end px-3">Expirare</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($expiringDocuments as $document): ?>
                                <tr>
                                    <td class="px-3 fw-semibold"><?= e($document['nr_inmatriculare']) ?></td>
                                    <td><?= e($document['tip_document']) ?></td>
                                    <td><?= e($document['numar_document']) ?></td>
                                    <td class="text-end px-3"><?= expiry_badge_html($document['data_expirare']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">Activitate recent&#259;</h2>
            </div>
            <div class="card-body">
                <?php if ($recentActivity === []): ?>
                    <p class="text-muted mb-0">Nu exist&#259; activitate recent&#259; pentru filtrele selectate.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="fw-semibold"><?= e($activity['tip']) ?></div>
                                        <div class="small text-muted"><?= e($activity['descriere']) ?></div>
                                    </div>
                                    <small class="text-muted text-nowrap"><?= e(format_datetime_ro($activity['data_eveniment'])) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
