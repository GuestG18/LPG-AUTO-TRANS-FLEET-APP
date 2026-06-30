<?php
$filters = is_array($filters ?? null) ? $filters : [];
$period = is_array($period ?? null) ? $period : [];
$rows = is_array($rows ?? null) ? $rows : [];
$kpis = is_array($kpis ?? null) ? $kpis : [];
$transportMetrics = is_array($transportMetrics ?? null) ? $transportMetrics : [];
$missingT0Vehicles = is_array($missingT0Vehicles ?? null) ? $missingT0Vehicles : [];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$t0Vehicles = is_array($t0Vehicles ?? null) ? $t0Vehicles : $vehicles;
$suppliers = is_array($suppliers ?? null) ? $suppliers : [];
$tripOptions = is_array($tripOptions ?? null) ? $tripOptions : [];
$transportLabels = is_array($transportLabels ?? null) ? $transportLabels : FuelModel::TRANSPORT_LABELS;
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0];
$editRecord = is_array($editRecord ?? null) ? $editRecord : null;

$monthNames = [
    1 => 'Ianuarie', 2 => 'Februarie', 3 => 'Martie', 4 => 'Aprilie',
    5 => 'Mai', 6 => 'Iunie', 7 => 'Iulie', 8 => 'August',
    9 => 'Septembrie', 10 => 'Octombrie', 11 => 'Noiembrie', 12 => 'Decembrie',
];
$month = (int) ($filters['month'] ?? date('n'));
$year = (int) ($filters['year'] ?? date('Y'));
$returnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'alimentari']));
$baseQuery = $_GET;
unset($baseQuery['p']);
$exportQuery = $baseQuery;
$exportQuery['page'] = 'alimentari';
$formatLiters = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' L';
$formatMoney = static fn (mixed $value): string => format_number_ro((float) $value, 2) . ' lei';
$formatConsumption = static fn (mixed $value): string => $value === null ? '-' : format_number_ro((float) $value, 2) . ' L/100km';
$formatDiff = static fn (mixed $value): string => $value === null ? '-' : (($value > 0 ? '+' : '') . format_number_ro((float) $value, 2) . ' L');
$jsonAttr = static function (array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return e(is_string($json) ? $json : '{}');
};
$recordPayload = static function (array $row): array {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'tip_inregistrare' => (string) ($row['tip_inregistrare'] ?? FuelModel::RECORD_REFUEL),
        'vehicle_id' => (int) ($row['vehicle_id'] ?? 0),
        'data_alimentare' => (string) ($row['data_alimentare'] ?? ''),
        'km_bord' => (string) ($row['km_bord'] ?? ''),
        'litri' => (string) ($row['litri'] ?? ''),
        'pret_litru' => (string) ($row['pret_litru'] ?? ''),
        'furnizor' => (string) ($row['furnizor'] ?? ''),
        'observatii' => (string) ($row['observatii'] ?? ''),
        'cursa_id' => (int) ($row['cursa_id'] ?? 0),
        'fuel_state' => (string) ($row['fuel_state'] ?? ''),
        'full_flag' => !empty($row['full_flag']),
        'factura_original' => (string) ($row['factura_original'] ?? ''),
        'trip' => [
            'id' => (int) ($row['cursa_id'] ?? 0),
            'interval' => (string) ($row['interval_label'] ?? '-'),
            'tip_transport_label' => (string) ($row['transport_label'] ?? '-'),
            'beneficiar' => (string) ($row['beneficiar_label'] ?? '-'),
            'sofer' => (string) ($row['driver_label'] ?? '-'),
            'traseu' => (string) ($row['route_label'] ?? '-'),
        ],
    ];
};
?>

<div class="fuel-page">
    <div class="fuel-page-header">
        <div class="fuel-title-wrap">
            <span class="fuel-title-icon"><i class="bi bi-droplet"></i></span>
            <div>
                <h1>Alimentare</h1>
                <p>Gestionare alimentări și consum combustibil</p>
            </div>
        </div>
        <div class="fuel-period-chip">
            <i class="bi bi-calendar3"></i>
            <span><?= e(format_date_ro((string) ($period['start'] ?? ''))) ?> - <?= e(format_date_ro((string) ($period['end'] ?? ''))) ?></span>
        </div>
    </div>

    <section class="fuel-kpi-grid" aria-label="Indicatori alimentare">
        <article class="fuel-kpi-card is-blue">
            <span class="fuel-kpi-icon"><i class="bi bi-fuel-pump"></i></span>
            <div><div class="fuel-kpi-title">Total litri alimentați</div><strong><?= e($formatLiters($kpis['total_liters'] ?? 0)) ?></strong><small>Luna <?= e((string) ($kpis['month_label'] ?? 'selectată')) ?></small></div>
        </article>
        <article class="fuel-kpi-card is-green">
            <span class="fuel-kpi-icon"><i class="bi bi-cash-stack"></i></span>
            <div><div class="fuel-kpi-title">Cost total alimentări</div><strong><?= e($formatMoney($kpis['total_cost'] ?? 0)) ?></strong><small>Luna <?= e((string) ($kpis['month_label'] ?? 'selectată')) ?></small></div>
        </article>
        <article class="fuel-kpi-card is-purple">
            <span class="fuel-kpi-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <div><div class="fuel-kpi-title">Consum mediu flotă</div><strong><?= e($formatConsumption($kpis['fleet_consumption'] ?? null)) ?></strong><small>Media flotei</small></div>
        </article>
        <article class="fuel-kpi-card is-cyan">
            <span class="fuel-kpi-icon"><i class="bi bi-truck-front"></i></span>
            <div><div class="fuel-kpi-title">Vehicule alimentate</div><strong><?= e((string) ((int) ($kpis['vehicles_refueled'] ?? 0))) ?></strong><small>Din <?= e((string) ((int) ($kpis['vehicles_total'] ?? 0))) ?> vehicule</small></div>
        </article>
        <article class="fuel-kpi-card is-orange">
            <span class="fuel-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span>
            <div><div class="fuel-kpi-title">Vehicule fără T0</div><strong><?= e((string) ((int) ($kpis['vehicles_missing_t0'] ?? 0))) ?></strong><small>Necesită T0</small></div>
        </article>
        <article class="fuel-kpi-card is-red">
            <span class="fuel-kpi-icon"><i class="bi bi-arrow-up-right"></i></span>
            <?php $normDiff = $kpis['norm_diff'] ?? null; ?>
            <div><div class="fuel-kpi-title">Diferență față de normă</div><strong class="<?= $normDiff !== null && $normDiff <= 0 ? 'text-success' : 'text-danger' ?>"><?= e($formatDiff($normDiff)) ?></strong><small><?= $normDiff !== null && $normDiff <= 0 ? 'Sub normă' : 'Peste normă' ?></small></div>
        </article>
    </section>

    <section class="fuel-transport-panel">
        <div class="fuel-section-title">
            <strong>Metrice per tip de transport</strong> <span>(luna selectată)</span>
            <button class="btn btn-sm btn-outline-secondary ms-auto" type="button"><i class="bi bi-bar-chart-line text-success"></i> Afișează detalii</button>
        </div>
        <div class="fuel-transport-grid">
            <?php foreach (['primar', 'distributie', 'primar_distributie', 'compresor'] as $transportKey): ?>
                <?php $metric = $transportMetrics[$transportKey] ?? ['label' => $transportLabels[$transportKey] ?? $transportKey, 'liters' => 0, 'consumption' => null, 'diff' => null]; ?>
                <article class="fuel-transport-card <?= e('is-' . $transportKey) ?>">
                    <h3><i class="bi bi-truck"></i><?= e((string) ($metric['label'] ?? '')) ?></h3>
                    <div class="fuel-mini-metrics">
                        <div><span>Litri alimentați</span><strong><?= e($formatLiters($metric['liters'] ?? 0)) ?></strong></div>
                        <div><span>Consum mediu</span><strong><?= e($formatConsumption($metric['consumption'] ?? null)) ?></strong></div>
                        <?php $metricDiff = $metric['diff'] ?? null; ?>
                        <div><span>Diferență față de normă</span><strong class="<?= $metricDiff !== null && $metricDiff <= 0 ? 'text-success' : 'text-danger' ?>"><?= e($formatDiff($metricDiff)) ?></strong></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="fuel-action-row">
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#fuelRefuelModal"><i class="bi bi-plus-lg"></i> Adaugă Alimentare</button>
        <button class="btn btn-fuel-purple" type="button" data-bs-toggle="modal" data-bs-target="#fuelT0Modal"><i class="bi bi-plus-lg"></i> Adaugă T0 manual</button>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($exportQuery, ['action' => 'export_excel']))) ?>"><i class="bi bi-file-earmark-spreadsheet text-success"></i> Export Excel</a>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($exportQuery, ['action' => 'export_pdf']))) ?>" target="_blank"><i class="bi bi-filetype-pdf text-danger"></i> Export PDF</a>
    </div>

    <form class="fuel-filter-card" method="get">
        <input type="hidden" name="page" value="alimentari">
        <div class="fuel-filter-grid">
            <div><label>Vehicul</label><select class="form-select" name="vehicle_id"><option value="0">Toate vehiculele</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" <?= (int) ($filters['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare']) ?></option><?php endforeach; ?></select></div>
            <div><label>Luna</label><select class="form-select" name="month"><?php foreach ($monthNames as $monthValue => $monthLabel): ?><option value="<?= e((string) $monthValue) ?>" <?= $month === $monthValue ? 'selected' : '' ?>><?= e($monthLabel) ?></option><?php endforeach; ?></select></div>
            <div><label>Anul</label><select class="form-select" name="year"><?php for ($yearOption = (int) date('Y') + 1; $yearOption >= (int) date('Y') - 5; $yearOption--): ?><option value="<?= e((string) $yearOption) ?>" <?= $year === $yearOption ? 'selected' : '' ?>><?= e((string) $yearOption) ?></option><?php endfor; ?></select></div>
            <div><label>Tip înregistrare</label><select class="form-select" name="record_type"><option value="">Toate</option><option value="alimentare" <?= ($filters['record_type'] ?? '') === 'alimentare' ? 'selected' : '' ?>>Alimentare Normală</option><option value="t0" <?= ($filters['record_type'] ?? '') === 't0' ? 'selected' : '' ?>>T0</option></select></div>
            <div><label>Furnizor</label><select class="form-select" name="supplier"><option value="">Toți furnizorii</option><?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier) ?>" <?= ($filters['supplier'] ?? '') === $supplier ? 'selected' : '' ?>><?= e($supplier) ?></option><?php endforeach; ?></select></div>
            <div><label>Tip transport</label><select class="form-select" name="transport_type"><option value="">Toate</option><?php foreach ($transportLabels as $transportKey => $transportLabel): ?><?php if ($transportKey === 'primar_tona') { continue; } ?><option value="<?= e((string) $transportKey) ?>" <?= ($filters['transport_type'] ?? '') === (string) $transportKey ? 'selected' : '' ?>><?= e((string) $transportLabel) ?></option><?php endforeach; ?></select></div>
            <div><label>Cursă asociată</label><select class="form-select" name="trip_filter"><option value="">Toate</option><option value="with_trip" <?= ($filters['trip_filter'] ?? '') === 'with_trip' ? 'selected' : '' ?>>Cu cursă asociată</option><option value="without_trip" <?= ($filters['trip_filter'] ?? '') === 'without_trip' ? 'selected' : '' ?>>Fără cursă asociată</option></select></div>
            <div class="fuel-filter-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Filtrează</button><a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'alimentari'])) ?>"><i class="bi bi-arrow-clockwise"></i> Resetează filtrele</a></div>
        </div>
    </form>

    <div class="fuel-alert-grid">
        <div class="fuel-info-banner"><i class="bi bi-info-circle"></i> T0 reprezintă starea inițială a combustibilului și NU este inclus în cheltuieli. Doar alimentările normale sunt incluse în costuri.</div>
        <div class="fuel-warning-banner"><i class="bi bi-exclamation-triangle"></i> <strong><?= e((string) count($missingT0Vehicles)) ?></strong> vehicule nu au T0 înregistrat pentru luna selectată. <button class="btn btn-sm btn-light" type="button" data-bs-toggle="modal" data-bs-target="#missingT0Modal">Vezi vehicule</button></div>
    </div>

    <section class="fuel-table-card">
        <div class="table-responsive">
            <table class="table fuel-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Nr. Înmatriculare</th><th>Data</th><th>Tip înregistrare</th><th>Interval cursă Dispecer</th><th>Tip transport</th><th>Beneficiar</th><th>Șofer</th><th>Km Bord</th><th>Litri</th><th>Preț/L (lei)</th><th>Total (lei)</th><th>Furnizor</th><th>Factură</th><th>Consum calculat (L/100km)</th><th>Consum normat (L/100km)</th><th>Diferență (L)</th><th>Observații</th><th>Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="18" class="text-center text-muted py-5">Nu există înregistrări pentru filtrele selectate.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $isT0 = (string) ($row['tip_inregistrare'] ?? '') === FuelModel::RECORD_T0;
                    $hasTrip = !$isT0 && (int) ($row['cursa_id'] ?? 0) > 0;
                    $invoiceUrl = trim((string) ($row['factura_stocata'] ?? '')) !== '' ? url('uploads/alimentari_facturi/' . rawurlencode((string) $row['factura_stocata'])) : '';
                    $diff = $row['diferenta_litri'] ?? null;
                    $payload = $recordPayload($row);
                    ?>
                    <tr class="<?= $isT0 ? 'is-t0-row' : '' ?>">
                        <td><strong><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></strong></td>
                        <td><?= e(format_date_ro((string) ($row['data_alimentare'] ?? ''))) ?></td>
                        <td><?= $isT0 ? '<span class="fuel-badge badge-t0">T0</span>' : '<span class="fuel-badge badge-normal">Alimentare normală</span>' ?></td>
                        <td><?= $hasTrip ? e((string) ($row['interval_label'] ?? '-')) : ($isT0 ? '-' : '<span class="fuel-badge badge-no-trip">Fără cursă asociată</span>') ?></td>
                        <td><?= e((string) ($row['transport_label'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['beneficiar_label'] ?? '-')) ?></td>
                        <td><?= e((string) ($row['driver_label'] ?? '-')) ?></td>
                        <td><?= e(format_number_ro((float) ($row['km_bord'] ?? 0), 0)) ?></td>
                        <td><?= $isT0 && !empty($row['full_flag']) ? '<span class="text-success fw-semibold">FULL</span>' : e(format_number_ro((float) ($isT0 ? ($row['fuel_state'] ?? 0) : ($row['litri'] ?? 0)), 2)) ?></td>
                        <td><?= $isT0 ? '-' : e(format_number_ro((float) ($row['pret_litru'] ?? 0), 2)) ?></td>
                        <td><?= $isT0 ? '-' : e(format_number_ro((float) ($row['cost_total'] ?? 0), 2)) ?></td>
                        <td><?= e((string) ($row['furnizor'] ?? '-')) ?></td>
                        <td><?= $invoiceUrl !== '' ? '<a class="fuel-doc-link" href="' . e($invoiceUrl) . '" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i></a>' : '-' ?></td>
                        <td><?= e($row['consum_calculat'] !== null ? format_number_ro((float) $row['consum_calculat'], 2) : '-') ?></td>
                        <td><?= e($row['consum_normat'] !== null ? format_number_ro((float) $row['consum_normat'], 2) : '-') ?></td>
                        <td class="<?= $diff !== null && (float) $diff <= 0 ? 'text-success' : 'text-danger' ?> fw-semibold"><?= e($diff !== null ? $formatDiff((float) $diff) : '-') ?></td>
                        <td><?= e((string) ($row['observatii'] ?? '-')) ?></td>
                        <td>
                            <div class="fuel-row-actions">
                                <button type="button" class="fuel-icon-btn" data-fuel-edit data-record="<?= $jsonAttr($payload) ?>"><i class="bi bi-pencil"></i></button>
                                <form method="post" action="<?= e(build_query_url(['page' => 'alimentari', 'action' => 'delete'])) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e((string) ($row['id'] ?? 0)) ?>"><input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                    <button class="fuel-icon-btn danger" type="submit" data-confirm="Ștergi această înregistrare?"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="fuel-table-summary">
            <div><span>Total litri alimentați (fără T0):</span><strong><?= e($formatLiters($kpis['total_liters'] ?? 0)) ?></strong></div>
            <div><span>Cost total alimentări:</span><strong><?= e($formatMoney($kpis['total_cost'] ?? 0)) ?></strong></div>
            <div><span>Consum mediu flotă:</span><strong><?= e($formatConsumption($kpis['fleet_consumption'] ?? null)) ?></strong></div>
            <div><span>Diferență totală față de normă:</span><strong class="<?= ($kpis['norm_diff'] ?? null) !== null && (float) $kpis['norm_diff'] <= 0 ? 'text-success' : 'text-danger' ?>"><?= e($formatDiff($kpis['norm_diff'] ?? null)) ?></strong></div>
            <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
                <nav class="fuel-pagination">
                    <?php for ($p = 1; $p <= (int) $pagination['total_pages']; $p++): ?>
                        <?php if ($p > 3 && $p < (int) $pagination['total_pages'] && abs($p - (int) $pagination['page']) > 1) { if ($p === 4) { echo '<span>...</span>'; } continue; } ?>
                        <a class="<?= (int) $pagination['page'] === $p ? 'active' : '' ?>" href="<?= e(build_query_url(array_merge($baseQuery, ['page' => 'alimentari', 'p' => $p]))) ?>"><?= e((string) $p) ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        </div>
    </section>

    <div class="fuel-legend">
        <strong>Legendă:</strong>
        <span><span class="fuel-badge badge-t0">T0</span> Stare inițială (nu este cheltuială)</span>
        <span><span class="fuel-badge badge-normal">Alimentare normală</span> Cheltuială combustibil</span>
        <span><span class="fuel-badge badge-no-trip">Fără cursă asociată</span> Nu există interval în Dispecer</span>
        <span><span class="fuel-badge badge-missing-t0">Lipsește T0</span> Necesită T0 pentru luna selectată</span>
    </div>
</div>

<div class="modal fade" id="fuelRefuelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <form method="post" action="<?= e(build_query_url(['page' => 'alimentari', 'action' => 'save'])) ?>" enctype="multipart/form-data" data-fuel-refuel-form>
            <?= csrf_field() ?><input type="hidden" name="return_url" value="<?= e($returnUrl) ?>"><input type="hidden" name="id" data-field="id"><input type="hidden" name="tip_inregistrare" value="alimentare"><input type="hidden" name="cursa_id" data-field="cursa_id">
            <div class="modal-header"><h5 class="modal-title">Adaugă Alimentare</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Vehicul</label><select class="form-select" name="vehicle_id" data-field="vehicle_id" required><option value="">Selectează vehicul</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>"><?= e((string) $vehicle['nr_inmatriculare']) ?> - <?= e((string) ($vehicle['marca'] ?? '')) ?> <?= e((string) ($vehicle['model'] ?? '')) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3 fuel-date-picker" data-role="fuel-date-picker">
                        <label class="form-label">Data alimentării</label>
                        <div class="fuel-date-input-wrap">
                            <input class="form-control" type="text" data-field="data_alimentare_display" data-role="fuel-date-display" placeholder="dd.mm.yyyy" autocomplete="off" readonly required>
                            <input type="hidden" name="data_alimentare" data-field="data_alimentare">
                            <button type="button" class="fuel-date-toggle" data-role="fuel-date-toggle" aria-label="Alege data alimentării"><i class="bi bi-calendar3"></i></button>
                        </div>
                        <div class="fuel-ride-calendar" data-role="ride-calendar">
                            <div class="fuel-calendar-empty"><i class="bi bi-calendar-event"></i> Selectează vehiculul ca să vezi zilele cu curse din Dispecer.</div>
                        </div>
                    </div>
                    <div class="col-md-3"><label class="form-label">Km Bord</label><input class="form-control" type="number" min="0" step="1" name="km_bord" data-field="km_bord" required><div class="form-text" data-role="refuel-km-hint"></div></div>
                    <div class="col-md-3"><label class="form-label">Litri</label><input class="form-control" type="number" min="0.01" step="0.01" name="litri" data-field="litri" required></div>
                    <div class="col-md-3"><label class="form-label">Preț/Liter</label><input class="form-control" type="number" min="0.01" step="0.01" name="pret_litru" data-field="pret_litru" required></div>
                    <div class="col-md-3"><label class="form-label">Total Cost</label><input class="form-control" type="number" data-field="total_cost_preview" readonly></div>
                    <div class="col-md-3"><label class="form-label">Furnizor</label><input class="form-control" name="furnizor" data-field="furnizor" list="fuelSupplierList"></div>
                    <div class="col-md-6"><label class="form-label">Factură</label><input class="form-control" type="file" name="factura_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"><div class="form-text" data-field="current_invoice"></div></div>
                    <div class="col-12"><div class="fuel-trip-detection" data-role="trip-card"><div class="fuel-trip-empty"><i class="bi bi-info-circle"></i> Selectează vehiculul și data alimentării pentru detectarea intervalului din Dispecer Curse.</div></div></div>
                    <div class="col-12"><label class="form-label">Observații</label><textarea class="form-control" rows="3" name="observatii" data-field="observatii"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button><button type="submit" class="btn btn-primary">Salvează alimentarea</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="fuelT0Modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" action="<?= e(build_query_url(['page' => 'alimentari', 'action' => 'save'])) ?>" data-fuel-t0-form>
            <?= csrf_field() ?><input type="hidden" name="return_url" value="<?= e($returnUrl) ?>"><input type="hidden" name="id" data-field="id"><input type="hidden" name="tip_inregistrare" value="t0">
            <div class="modal-header"><h5 class="modal-title">Adaugă T0 manual</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body">
                <div class="alert alert-info mb-3"><i class="bi bi-info-circle"></i> T0 este folosit doar pentru calculul consumului. Nu se include în cheltuieli.</div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Vehicul</label><select class="form-select" name="vehicle_id" data-field="vehicle_id" required><option value="">Selectează vehicul</option><?php foreach ($t0Vehicles as $vehicle): ?><option value="<?= e((string) $vehicle['id']) ?>" data-reservoir-capacity="<?= e((string) ($vehicle['capacitate_rezervor'] ?? '')) ?>"><?= e((string) $vehicle['nr_inmatriculare']) ?> - <?= e((string) ($vehicle['marca'] ?? '')) ?> <?= e((string) ($vehicle['model'] ?? '')) ?></option><?php endforeach; ?></select><div class="form-text">Sunt afișate doar vehiculele care au curse în Dispecer Curse.</div></div>
                    <div class="col-md-3 fuel-date-picker" data-role="fuel-date-picker">
                        <label class="form-label">Data T0</label>
                        <div class="fuel-date-input-wrap">
                            <input class="form-control" type="text" data-field="data_alimentare_display" data-role="fuel-date-display" placeholder="dd.mm.yyyy" autocomplete="off" readonly required>
                            <input type="hidden" name="data_alimentare" data-field="data_alimentare">
                            <button type="button" class="fuel-date-toggle" data-role="fuel-date-toggle" aria-label="Alege data T0"><i class="bi bi-calendar3"></i></button>
                        </div>
                        <div class="fuel-ride-calendar" data-role="ride-calendar">
                            <div class="fuel-calendar-empty"><i class="bi bi-calendar-event"></i> Selectează vehiculul ca să vezi zilele cu curse din Dispecer.</div>
                        </div>
                    </div>
                    <div class="col-md-3"><label class="form-label">Km Bord T0</label><input class="form-control" type="number" min="0" step="1" name="km_bord" data-field="km_bord" required><div class="form-text" data-role="t0-km-hint"></div></div>
                    <div class="col-md-3"><label class="form-label">Luna</label><input class="form-control" type="text" data-field="t0_month_preview" readonly></div>
                    <div class="col-md-3"><label class="form-label">An</label><input class="form-control" type="text" data-field="t0_year_preview" readonly></div>
                    <div class="col-md-4"><label class="form-label">Stare combustibil inițială</label><input class="form-control" type="number" min="0" step="0.01" name="fuel_state" data-field="fuel_state"><div class="form-text" data-role="t0-fuel-hint"></div></div>
                    <div class="col-md-2 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="full_flag" value="1" data-field="full_flag" id="fuel_t0_full"><label class="form-check-label" for="fuel_t0_full">FULL</label></div></div>
                    <div class="col-12"><label class="form-label">Observații</label><textarea class="form-control" rows="3" name="observatii" data-field="observatii"></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button><button type="submit" class="btn btn-fuel-purple">Salvează T0</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="missingT0Modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Vehicule fără T0</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
        <div class="modal-body">
            <?php if ($missingT0Vehicles === []): ?><p class="text-muted mb-0">Toate vehiculele cu curse în luna selectată au T0.</p><?php endif; ?>
            <div class="list-group">
                <?php foreach ($missingT0Vehicles as $vehicle): ?><div class="list-group-item d-flex justify-content-between align-items-center"><span><strong><?= e((string) $vehicle['nr_inmatriculare']) ?></strong><br><small><?= e((string) ($vehicle['marca'] ?? '')) ?> <?= e((string) ($vehicle['model'] ?? '')) ?></small></span><span class="fuel-badge badge-missing-t0">Lipsește T0</span></div><?php endforeach; ?>
            </div>
        </div>
    </div></div>
</div>

<datalist id="fuelSupplierList">
    <?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier) ?>"></option><?php endforeach; ?>
</datalist>

<?php
$fuelScriptVersion = (string) @filemtime(BASE_PATH . '/assets/js/fuel.js');
$editRecordJson = $editRecord !== null ? json_encode($recordPayload($editRecord), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null';
?>
<script>
window.FUEL_EDIT_RECORD = <?= $editRecordJson ?: 'null' ?>;
window.FUEL_CALENDAR_DEFAULT = <?= json_encode(['year' => $year, 'month' => $month], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(url('assets/js/fuel.js?v=' . $fuelScriptVersion)) ?>"></script>
