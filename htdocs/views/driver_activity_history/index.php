<?php
declare(strict_types=1);

$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$filters = is_array($filters ?? null) ? $filters : [];
$driver = is_array($dashboard['driver'] ?? null) ? $dashboard['driver'] : null;
$kpis = is_array($dashboard['kpis'] ?? null) ? $dashboard['kpis'] : [];
$transportLabels = is_array($transportLabels ?? null) ? $transportLabels : DriverActivityHistoryModel::TRANSPORT_LABELS;
$driverOptions = is_array($driverOptions ?? null) ? $driverOptions : [];
$driverId = (int) ($filters['driver_id'] ?? ($driver['id'] ?? 0));

$fmtNumber = static function (mixed $value, int $decimals = 2): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return format_number_ro((float) $value, $decimals);
};
$fmtMoney = static function (mixed $value) use ($fmtNumber): string {
    return $fmtNumber((float) $value, 2) . ' lei';
};
$fmtPercent = static function (mixed $value) use ($fmtNumber): string {
    return $fmtNumber((float) $value, 1) . '%';
};
$fmtDuration = static function (mixed $minutes): string {
    $minutes = max(0, (int) $minutes);
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    return $hours . 'h ' . str_pad((string) $remaining, 2, '0', STR_PAD_LEFT) . 'm';
};
$fmtDate = static fn (mixed $value): string => format_date_ro((string) ($value ?? ''));

$fuelInvoiceUrl = static function (?string $stored): string {
    $stored = basename(trim((string) $stored));
    return $stored !== '' ? url('uploads/alimentari_facturi/' . rawurlencode($stored)) : '';
};
$maintenanceFileUrl = static function (?string $stored): string {
    $stored = basename(trim((string) $stored));
    if ($stored === '') {
        return '';
    }
    $maintenancePath = BASE_PATH . '/uploads/mentenanta_piese/' . $stored;
    if (is_file($maintenancePath)) {
        return url('uploads/mentenanta_piese/' . rawurlencode($stored));
    }
    return document_file_url($stored) ?? '';
};
$isPdf = static function (?string $file): bool {
    return strtolower(pathinfo((string) $file, PATHINFO_EXTENSION)) === 'pdf';
};

$queryBase = [
    'page' => 'istoric_activitati_sofer',
    'driver_id' => $driverId,
    'date_range' => (string) ($filters['date_range'] ?? ''),
    'vehicle_id' => (int) ($filters['vehicle_id'] ?? 0),
    'transport_type' => (string) ($filters['transport_type'] ?? ''),
    'grouping' => (string) ($filters['grouping'] ?? 'daily'),
];
$exportQuery = $queryBase;
$resetUrl = build_query_url(['page' => 'istoric_activitati_sofer', 'driver_id' => $driverId]);
$driverImage = $driver !== null ? driver_image_url((string) ($driver['poza_stocata'] ?? '')) : null;
$status = strtolower((string) ($driver['status'] ?? ''));
$statusClass = $status === 'activ' ? 'is-active' : 'is-inactive';
$chartsJson = json_encode($dashboard['charts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$topBeneficiaries = [];
foreach ((array) ($dashboard['trips'] ?? []) as $trip) {
    $beneficiaryName = trim((string) ($trip['beneficiary_label'] ?? ''));
    if ($beneficiaryName === '' || $beneficiaryName === '-') {
        continue;
    }
    $topBeneficiaries[$beneficiaryName] = ($topBeneficiaries[$beneficiaryName] ?? 0.0) + (float) ($trip['delivered_tons'] ?? 0);
}
arsort($topBeneficiaries);
$topBeneficiaries = array_slice($topBeneficiaries, 0, 3, true);

$dailyPreviewRows = array_slice((array) ($dashboard['dailyRows'] ?? []), 0, 3);
?>

<div class="driver-history-page" id="driver-history-page">
    <div class="driver-history-container">
    <div class="driver-history-header">
        <div>
            <div class="driver-history-breadcrumb">
                <span>Soferi</span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span>Istoric Activitati</span>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <span>Detalii istoric</span>
            </div>
            <h1>Istoric Activitati Sofer - <?= e((string) ($driver['nume'] ?? '')) ?></h1>
            <p>Profil analitic complet pentru perioada selectata.</p>
        </div>
        <div class="driver-history-actions">
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($exportQuery, ['action' => 'export_pdf']))) ?>" target="_blank" rel="noopener">
                <i class="bi bi-download" aria-hidden="true"></i>
                Exporta PDF
            </a>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($exportQuery, ['action' => 'export_excel']))) ?>">
                <i class="bi bi-arrows-fullscreen" aria-hidden="true"></i>
                Exporta Excel
            </a>
            <button type="button" class="btn btn-outline-secondary driver-history-icon-button" data-driver-history-focus-date aria-label="Calendar">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
            </button>
        </div>
    </div>

    <?php if ($driver === null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">Nu exista soferi inregistrati pentru afisarea istoricului.</div>
        </div>
    <?php else: ?>
        <form class="driver-history-filter-card" method="get" action="<?= e(url('index.php')) ?>">
            <input type="hidden" name="page" value="istoric_activitati_sofer">
            <input type="hidden" name="driver_id" value="<?= e((string) $driverId) ?>">
            <div class="driver-history-filter-grid">
                <div class="driver-history-filter-field is-wide">
                    <label for="driver_history_date_range">Interval de timp</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar3" aria-hidden="true"></i></span>
                        <input class="form-control" id="driver_history_date_range" name="date_range" value="<?= e((string) ($filters['date_range'] ?? '')) ?>" placeholder="01.06.2026 - 06.06.2026" autocomplete="off">
                    </div>
                </div>
                <div class="driver-history-filter-field">
                    <label for="driver_history_transport">Tip transport</label>
                    <select class="form-select" id="driver_history_transport" name="transport_type">
                        <option value="">Toate tipurile</option>
                        <?php foreach ($transportLabels as $key => $label): ?>
                            <option value="<?= e((string) $key) ?>" <?= (string) ($filters['transport_type'] ?? '') === (string) $key ? 'selected' : '' ?>>
                                <?= e((string) $label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="driver-history-filter-field">
                    <label for="driver_history_vehicle">Vehicul</label>
                    <select class="form-select" id="driver_history_vehicle" name="vehicle_id">
                        <option value="">Toate vehiculele</option>
                        <?php foreach ((array) ($dashboard['vehicleOptions'] ?? []) as $vehicle): ?>
                            <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                            <option value="<?= e((string) $vehicleId) ?>" <?= (int) ($filters['vehicle_id'] ?? 0) === $vehicleId ? 'selected' : '' ?>>
                                <?= e(trim((string) ($vehicle['nr_inmatriculare'] ?? '') . ' - ' . (string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="driver-history-filter-field">
                    <label for="driver_history_grouping">Grupare dupa</label>
                    <select class="form-select" id="driver_history_grouping" name="grouping">
                        <option value="daily" <?= (string) ($filters['grouping'] ?? '') === 'daily' ? 'selected' : '' ?>>Zilnic</option>
                        <option value="weekly" <?= (string) ($filters['grouping'] ?? '') === 'weekly' ? 'selected' : '' ?>>Saptamanal</option>
                        <option value="monthly" <?= (string) ($filters['grouping'] ?? '') === 'monthly' ? 'selected' : '' ?>>Lunar</option>
                    </select>
                </div>
                <div class="driver-history-filter-actions">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Filtreaza</button>
                    <a class="btn btn-outline-secondary" href="<?= e($resetUrl) ?>"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Reseteaza</a>
                </div>
            </div>
        </form>

        <section class="driver-history-kpi-shell">
            <article class="driver-history-driver-card">
                <div class="driver-history-photo">
                    <?php if ($driverImage !== null): ?>
                        <img src="<?= e($driverImage) ?>" alt="<?= e((string) ($driver['nume'] ?? 'Sofer')) ?>">
                    <?php else: ?>
                        <i class="bi bi-person-fill" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <div class="driver-history-driver-meta">
                    <div class="driver-history-driver-name">
                        <strong><?= e((string) ($driver['nume'] ?? '-')) ?></strong>
                        <span class="driver-history-status <?= e($statusClass) ?>"><?= e($status === 'activ' ? 'ACTIV' : 'INACTIV') ?></span>
                    </div>
                    <dl>
                        <div><dt>ID sofer:</dt><dd><?= e((string) ($driver['id'] ?? '-')) ?></dd></div>
                        <div><dt>Telefon:</dt><dd title="<?= e((string) ($driver['telefon'] ?? '-')) ?>"><?= e((string) ($driver['telefon'] ?? '-')) ?></dd></div>
                        <div><dt>Categorie permis:</dt><dd title="<?= e((string) ($driver['license_category'] ?? '-')) ?>"><?= e((string) ($driver['license_category'] ?? '-')) ?></dd></div>
                        <div><dt>Nr. permis:</dt><dd title="<?= e((string) ($driver['license_number'] ?? '-')) ?>"><?= e((string) ($driver['license_number'] ?? '-')) ?></dd></div>
                        <div><dt>Data angajarii:</dt><dd><?= e($fmtDate($driver['data_angajare'] ?? null)) ?></dd></div>
                    </dl>
                </div>
            </article>

            <?php
            $kpiCards = [
                ['icon' => 'bi-signpost-2', 'tone' => 'blue', 'label' => 'Total curse', 'value' => (string) (int) ($kpis['total_trips'] ?? 0), 'note' => 'curse'],
                ['icon' => 'bi-speedometer2', 'tone' => 'green', 'label' => 'Total kilometri', 'value' => $fmtNumber($kpis['total_km'] ?? 0, 0) . ' km', 'note' => 'parcursi'],
                ['icon' => 'bi-box-seam', 'tone' => 'purple', 'label' => 'Total tonaj', 'value' => $fmtNumber($kpis['total_loaded_tons'] ?? 0, 2) . ' t', 'note' => 'din care ' . $fmtNumber($kpis['total_delivered_tons'] ?? 0, 2) . ' t livrate'],
                ['icon' => 'bi-clock-history', 'tone' => 'orange', 'label' => 'Ore de condus', 'value' => $fmtDuration($kpis['driving_minutes'] ?? 0), 'note' => 'timp activ'],
                ['icon' => 'bi-fuel-pump', 'tone' => 'blue', 'label' => 'Consum total', 'value' => $fmtNumber($kpis['total_fuel_liters'] ?? 0, 2) . ' L', 'note' => ($kpis['average_consumption'] ?? null) !== null ? $fmtNumber($kpis['average_consumption'], 2) . ' L/100 km' : 'fara medie calculata'],
                ['icon' => 'bi-cash-coin', 'tone' => 'red', 'label' => 'Cost total', 'value' => $fmtMoney($kpis['total_costs'] ?? 0), 'note' => 'in perioada'],
            ];
            ?>
            <?php foreach ($kpiCards as $card): ?>
                <article class="driver-history-kpi-card">
                    <span class="driver-history-kpi-icon is-<?= e((string) $card['tone']) ?>"><i class="bi <?= e((string) $card['icon']) ?>" aria-hidden="true"></i></span>
                    <div>
                        <span><?= e((string) $card['label']) ?></span>
                        <strong><?= e((string) $card['value']) ?></strong>
                        <small><?= e((string) $card['note']) ?></small>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="driver-history-chart-grid">
            <article class="driver-history-panel">
                <h2>Tone incarcate vs. livrate</h2>
                <div class="driver-history-chart-wrap" data-chart-wrapper>
                    <canvas id="driver_history_tons_chart"></canvas>
                    <div class="driver-history-chart-empty">Nu exista date.</div>
                </div>
            </article>
            <article class="driver-history-panel">
                <h2>Evolutie kilometri</h2>
                <div class="driver-history-chart-wrap" data-chart-wrapper>
                    <canvas id="driver_history_km_chart"></canvas>
                    <div class="driver-history-chart-empty">Nu exista date.</div>
                </div>
            </article>
            <article class="driver-history-panel">
                <h2>Evolutie consum combustibil</h2>
                <div class="driver-history-chart-wrap" data-chart-wrapper>
                    <canvas id="driver_history_fuel_chart"></canvas>
                    <div class="driver-history-chart-empty">Nu exista date.</div>
                </div>
            </article>
            <article class="driver-history-panel">
                <h2>Distributie costuri</h2>
                <div class="driver-history-chart-wrap is-donut" data-chart-wrapper>
                    <canvas id="driver_history_cost_chart"></canvas>
                    <div class="driver-history-chart-empty">Nu exista date.</div>
                </div>
            </article>
        </section>

        <section class="driver-history-tabs">
            <ul class="nav nav-tabs" id="driverHistoryTabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#driver-history-trips" type="button" role="tab">Curse</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-history-fuel" type="button" role="tab">Alimentari</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-history-consumption" type="button" role="tab">Consum</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-history-repairs" type="button" role="tab">Reparatii</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-history-vehicles" type="button" role="tab">Vehicule</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-history-documents" type="button" role="tab">Documente</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-history-daily" type="button" role="tab">Activitate zilnica</button></li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="driver-history-trips" role="tabpanel">
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Data</th><th>Beneficiar</th><th>Vehicul</th><th>Tip transport</th><th>Total KM</th><th>KM nefacturabili</th><th>Tone incarcate</th><th>Tone livrate</th><th>Durata cursa</th><th>Cost cursa</th><th>Actiuni</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['trips'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= e($fmtDate($row['data_inceput'] ?? null)) ?></td>
                                    <td><?= e((string) ($row['beneficiary_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['transport_label'] ?? '-')) ?></td>
                                    <td><?= e($fmtNumber($row['effective_km'] ?? 0, 0)) ?></td>
                                    <td><?= e($fmtNumber($row['non_billable_km'] ?? 0, 0)) ?></td>
                                    <td><?= e($fmtNumber($row['loaded_tons'] ?? 0, 2)) ?></td>
                                    <td><?= e($fmtNumber($row['delivered_tons'] ?? 0, 2)) ?></td>
                                    <td><?= e($fmtDuration($row['duration_minutes_effective'] ?? 0)) ?></td>
                                    <td><?= e($fmtMoney($row['total_cheltuieli'] ?? 0)) ?></td>
                                    <td>
                                        <div class="driver-history-row-actions">
                                            <a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['id']])) ?>" title="Cursa"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                            <a href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $row['vehicle_id']])) ?>" title="Vehicul"><i class="bi bi-truck" aria-hidden="true"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($dashboard['trips'] ?? []) === []): ?><tr><td colspan="11" class="text-center text-muted py-4">Nu exista curse pentru filtrele selectate.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="driver-history-fuel" role="tabpanel">
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Data</th><th>Vehicul</th><th>Sofer</th><th>Litri combustibil</th><th>Pret combustibil</th><th>Cost combustibil</th><th>Kilometraj</th><th>Tip combustibil</th><th>Consum calculat</th><th>Observatii</th><th>Actiuni</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['fuelRows'] ?? []) as $row): ?>
                                <?php $invoiceUrl = $fuelInvoiceUrl((string) ($row['factura_stocata'] ?? '')); ?>
                                <tr>
                                    <td><?= e($fmtDate($row['data_alimentare'] ?? null)) ?></td>
                                    <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                                    <td><?= e((string) (($row['record_sofer_nume'] ?? '') !== '' ? $row['record_sofer_nume'] : ($driver['nume'] ?? '-'))) ?></td>
                                    <td><?= e($fmtNumber($row['litri'] ?? 0, 2)) ?> L</td>
                                    <td><?= ($row['pret_litru_calculat'] ?? null) !== null ? e($fmtMoney($row['pret_litru_calculat'])) : '-' ?></td>
                                    <td><?= e($fmtMoney($row['cost_total'] ?? 0)) ?></td>
                                    <td><?= e($fmtNumber($row['km_bord'] ?? 0, 0)) ?></td>
                                    <td><?= e((string) ($row['fuel_type'] ?? '-')) ?></td>
                                    <td><?= ($row['calculated_consumption'] ?? null) !== null ? e($fmtNumber($row['calculated_consumption'], 2)) . ' L/100km' : '-' ?></td>
                                    <td><?= e((string) ($row['observatii'] ?? '-')) ?></td>
                                    <td>
                                        <div class="driver-history-row-actions">
                                            <a href="<?= e(build_query_url(['page' => 'alimentari', 'edit_id' => (int) $row['id']])) ?>" title="Alimentare"><i class="bi bi-fuel-pump" aria-hidden="true"></i></a>
                                            <?php if ((int) ($row['linked_trip_id'] ?? 0) > 0): ?><a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['linked_trip_id']])) ?>" title="Cursa"><i class="bi bi-signpost-2" aria-hidden="true"></i></a><?php endif; ?>
                                            <?php if ($invoiceUrl !== ''): ?><a href="<?= e($invoiceUrl) ?>" target="_blank" rel="noopener" title="Factura"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></a><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($dashboard['fuelRows'] ?? []) === []): ?><tr><td colspan="11" class="text-center text-muted py-4">Nu exista alimentari asociate soferului in perioada selectata.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="driver-history-consumption" role="tabpanel">
                    <?php $consumption = is_array($dashboard['consumption'] ?? null) ? $dashboard['consumption'] : []; ?>
                    <div class="driver-history-consumption-grid">
                        <div><span>Combustibil total</span><strong><?= e($fmtNumber($consumption['total_fuel'] ?? 0, 2)) ?> L</strong></div>
                        <div><span>Consum mediu</span><strong><?= ($consumption['average_consumption'] ?? null) !== null ? e($fmtNumber($consumption['average_consumption'], 2)) . ' L/100km' : '-' ?></strong></div>
                        <div><span>Cost combustibil</span><strong><?= e($fmtMoney($consumption['fuel_cost'] ?? 0)) ?></strong></div>
                        <div><span>Consum maxim</span><strong><?= is_array($consumption['highest_consumption'] ?? null) ? e((string) $consumption['highest_consumption']['label'] . ' - ' . $fmtNumber($consumption['highest_consumption']['consumption'], 2)) : '-' ?></strong></div>
                        <div><span>Consum minim</span><strong><?= is_array($consumption['lowest_consumption'] ?? null) ? e((string) $consumption['lowest_consumption']['label'] . ' - ' . $fmtNumber($consumption['lowest_consumption']['consumption'], 2)) : '-' ?></strong></div>
                        <div><span>Cost pe kilometru</span><strong><?= ($consumption['cost_per_km'] ?? null) !== null ? e($fmtMoney($consumption['cost_per_km'])) . ' / km' : '-' ?></strong></div>
                    </div>
                    <div class="driver-history-split">
                        <div class="driver-history-panel">
                            <h2>Consum pe vehicul</h2>
                            <div class="driver-history-table-wrap is-compact">
                                <table class="table driver-history-table mb-0">
                                    <thead><tr><th>Vehicul</th><th>Combustibil</th><th>Km</th><th>Consum</th><th>Cost combustibil</th></tr></thead>
                                    <tbody>
                                    <?php foreach ((array) ($consumption['per_vehicle'] ?? []) as $row): ?>
                                        <tr><td><?= e((string) ($row['label'] ?? '-')) ?></td><td><?= e($fmtNumber($row['liters'] ?? 0, 2)) ?> L</td><td><?= e($fmtNumber($row['kilometers'] ?? 0, 0)) ?></td><td><?= ($row['consumption'] ?? null) !== null ? e($fmtNumber($row['consumption'], 2)) : '-' ?></td><td><?= e($fmtMoney($row['cost'] ?? 0)) ?></td></tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="driver-history-panel">
                            <h2>Consum pe tip de transport</h2>
                            <div class="driver-history-table-wrap is-compact">
                                <table class="table driver-history-table mb-0">
                                    <thead><tr><th>Tip transport</th><th>Combustibil</th><th>Cost combustibil</th></tr></thead>
                                    <tbody>
                                    <?php foreach ((array) ($consumption['per_transport_type'] ?? []) as $row): ?>
                                        <tr><td><?= e((string) ($row['label'] ?? '-')) ?></td><td><?= e($fmtNumber($row['liters'] ?? 0, 2)) ?> L</td><td><?= e($fmtMoney($row['cost'] ?? 0)) ?></td></tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="driver-history-repairs" role="tabpanel">
                    <div class="driver-history-panel driver-history-repair-analytics">
                        <h2>Analiza costurilor de reparatii</h2>
                        <div class="driver-history-table-wrap is-compact">
                            <table class="table driver-history-table mb-0">
                                <thead><tr><th>Categorie</th><th>Numar reparatii</th><th>Cost total</th><th>Procent</th></tr></thead>
                                <tbody>
                                <?php foreach ((array) ($dashboard['repairAnalytics'] ?? []) as $row): ?>
                                    <tr><td><?= e((string) ($row['label'] ?? '-')) ?></td><td><?= e((string) ($row['count'] ?? 0)) ?></td><td><?= e($fmtMoney($row['total_cost'] ?? 0)) ?></td><td><?= e($fmtPercent($row['percentage'] ?? 0)) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Data reparatie</th><th>Vehicul</th><th>Categorie principala</th><th>Subcategorie</th><th>Componenta</th><th>Tip reparatie</th><th>Furnizor piese</th><th>Furnizor manopera</th><th>Cost piese</th><th>Cost manopera</th><th>Cost total</th><th>Factura</th><th>PDF</th><th>Observatii</th><th>Actiuni</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['repairs'] ?? []) as $row): ?>
                                <?php $repairFileUrl = $maintenanceFileUrl((string) ($row['fisier_stocat'] ?? '')); ?>
                                <tr>
                                    <td><?= e($fmtDate($row['data_interventie'] ?? null)) ?></td>
                                    <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['main_category'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['subcategory'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['component_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['tip_interventie'] ?? '-')) ?></td>
                                    <td><?= e((string) (($row['furnizor_piesa'] ?? '') !== '' ? $row['furnizor_piesa'] : '-')) ?></td>
                                    <td><?= e((string) (($row['atelier'] ?? '') !== '' ? $row['atelier'] : '-')) ?></td>
                                    <td><?= e($fmtMoney($row['cost_piese'] ?? 0)) ?></td>
                                    <td><?= e($fmtMoney($row['cost_manopera'] ?? 0)) ?></td>
                                    <td><strong><?= e($fmtMoney($row['cost'] ?? 0)) ?></strong></td>
                                    <td><?= $repairFileUrl !== '' ? '<a href="' . e($repairFileUrl) . '" target="_blank" rel="noopener">' . e((string) ($row['fisier_original'] ?? 'Factura')) . '</a>' : '-' ?></td>
                                    <td><?= $repairFileUrl !== '' && $isPdf((string) ($row['fisier_stocat'] ?? '')) ? '<a href="' . e($repairFileUrl) . '" target="_blank" rel="noopener"><i class="bi bi-filetype-pdf" aria-hidden="true"></i></a>' : '-' ?></td>
                                    <td><?= e((string) (($row['observatii'] ?? '') !== '' ? $row['observatii'] : '-')) ?></td>
                                    <td>
                                        <div class="driver-history-row-actions">
                                            <a href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'repairs', 'view_id' => (int) $row['id']])) ?>" title="Reparatie"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                            <a href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $row['vehicle_id']])) ?>" title="Vehicul"><i class="bi bi-truck" aria-hidden="true"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($dashboard['repairs'] ?? []) === []): ?><tr><td colspan="15" class="text-center text-muted py-4">Nu exista reparatii asociate vehiculelor utilizate de sofer.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="driver-history-vehicles" role="tabpanel">
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Numar inmatriculare</th><th>Tip vehicul</th><th>Curse</th><th>Kilometri</th><th>Tone incarcate</th><th>Tone livrate</th><th>Cost combustibil</th><th>Cost reparatii</th><th>Cost total</th><th>Procent utilizare</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['vehicleRows'] ?? []) as $row): ?>
                                <tr>
                                    <td><a href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $row['id']])) ?>"><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></a></td>
                                    <td><?= e(vehicle_type_label((string) ($row['tip_vehicul'] ?? ''))) ?></td>
                                    <td><?= e((string) ($row['trips'] ?? 0)) ?></td>
                                    <td><?= e($fmtNumber($row['kilometers'] ?? 0, 0)) ?> km</td>
                                    <td><?= e($fmtNumber($row['loaded_tons'] ?? 0, 2)) ?> t</td>
                                    <td><?= e($fmtNumber($row['delivered_tons'] ?? 0, 2)) ?> t</td>
                                    <td><?= e($fmtMoney($row['fuel_cost'] ?? 0)) ?></td>
                                    <td><?= e($fmtMoney($row['repair_cost'] ?? 0)) ?></td>
                                    <td><strong><?= e($fmtMoney($row['total_cost'] ?? 0)) ?></strong></td>
                                    <td>
                                        <div class="driver-history-progress"><span style="width: <?= e((string) min(100, max(0, (float) ($row['usage_percentage'] ?? 0)))) ?>%"></span></div>
                                        <?= e($fmtPercent($row['usage_percentage'] ?? 0)) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="driver-history-documents" role="tabpanel">
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Sursa</th><th>Titular</th><th>Tip document</th><th>Numar</th><th>Data expirare</th><th>Fisier</th><th>Observatii</th><th>Actiuni</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['documents'] ?? []) as $row): ?>
                                <?php
                                $ownerType = (string) ($row['owner_type'] ?? '');
                                $documentPage = $ownerType === 'vehicle' ? 'documente' : 'documente_soferi';
                                ?>
                                <tr>
                                    <td><?= e($ownerType === 'vehicle' ? 'Documente vehicul' : 'Documente sofer') ?></td>
                                    <td><?= e((string) ($row['owner_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['tip_document'] ?? '-')) ?></td>
                                    <td><?= e((string) (($row['numar_document'] ?? '') !== '' ? $row['numar_document'] : '-')) ?></td>
                                    <td><?= e($fmtDate($row['data_expirare'] ?? null)) ?></td>
                                    <td><?= document_file_link_html((string) ($row['fisier_original'] ?? ''), (string) ($row['fisier_stocat'] ?? '')) ?></td>
                                    <td><?= e((string) (($row['observatii'] ?? '') !== '' ? $row['observatii'] : '-')) ?></td>
                                    <td>
                                        <div class="driver-history-row-actions">
                                            <a href="<?= e(build_query_url(['page' => $documentPage, 'action' => 'show', 'id' => (int) $row['id']])) ?>" title="Document"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></a>
                                            <?php if (!empty($row['fisier_stocat'])): ?><a href="<?= e(build_query_url(['page' => $documentPage, 'action' => 'preview', 'id' => (int) $row['id']])) ?>" title="PDF"><i class="bi bi-filetype-pdf" aria-hidden="true"></i></a><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($dashboard['documents'] ?? []) === []): ?><tr><td colspan="8" class="text-center text-muted py-4">Nu exista documente relevante pentru perioada selectata.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="driver-history-daily" role="tabpanel">
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Data</th><th>Curse</th><th>Kilometri</th><th>Tone incarcate</th><th>Tone livrate</th><th>Combustibil utilizat</th><th>Cost combustibil</th><th>Cost reparatii</th><th>Cost zilnic total</th><th>Ore condus</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['dailyRows'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= e($fmtDate($row['date'] ?? null)) ?></td>
                                    <td><?= e((string) ($row['trips'] ?? 0)) ?></td>
                                    <td><?= e($fmtNumber($row['kilometers'] ?? 0, 0)) ?> km</td>
                                    <td><?= e($fmtNumber($row['loaded_tons'] ?? 0, 2)) ?> t</td>
                                    <td><?= e($fmtNumber($row['delivered_tons'] ?? 0, 2)) ?> t</td>
                                    <td><?= e($fmtNumber($row['fuel_used'] ?? 0, 2)) ?> L</td>
                                    <td><?= e($fmtMoney($row['fuel_cost'] ?? 0)) ?></td>
                                    <td><?= e($fmtMoney($row['repair_cost'] ?? 0)) ?></td>
                                    <td><strong><?= e($fmtMoney($row['total_daily_cost'] ?? 0)) ?></strong></td>
                                    <td><?= e($fmtDuration($row['driving_minutes'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($dashboard['dailyRows'] ?? []) === []): ?><tr><td colspan="10" class="text-center text-muted py-4">Nu exista activitate zilnica pentru filtrele selectate.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="driver-history-secondary-grid">
            <article class="driver-history-panel">
                <h2>Top beneficiari dupa tonaj livrat</h2>
                <ol class="driver-history-ranked-list">
                    <?php foreach ($topBeneficiaries as $beneficiaryName => $tons): ?>
                        <li><span><?= e((string) $beneficiaryName) ?></span><strong><?= e($fmtNumber($tons, 2)) ?> t</strong></li>
                    <?php endforeach; ?>
                    <?php if ($topBeneficiaries === []): ?><li><span>Nu exista date</span><strong>-</strong></li><?php endif; ?>
                </ol>
            </article>
            <article class="driver-history-panel">
                <h2>Tipuri de transport</h2>
                <div class="driver-history-chart-wrap is-donut is-small" data-chart-wrapper>
                    <canvas id="driver_history_transport_chart"></canvas>
                    <div class="driver-history-chart-empty">Nu exista date.</div>
                </div>
            </article>
            <article class="driver-history-panel">
                <h2>Indicatori medii</h2>
                <div class="driver-history-metric-list">
                    <div><span>Consum mediu</span><strong><?= ($kpis['average_consumption'] ?? null) !== null ? e($fmtNumber($kpis['average_consumption'], 2)) . ' L/100km' : '-' ?></strong></div>
                    <div><span>Cost combustibil</span><strong><?= e($fmtMoney($kpis['fuel_cost'] ?? 0)) ?></strong></div>
                    <div><span>Cost total / km</span><strong><?= ((float) ($kpis['total_km'] ?? 0)) > 0 ? e($fmtMoney(((float) ($kpis['total_costs'] ?? 0)) / (float) $kpis['total_km'])) : '-' ?></strong></div>
                </div>
            </article>
            <article class="driver-history-panel">
                <h2>Activitati zilnice (rezumat)</h2>
                <div class="driver-history-table-wrap is-compact">
                    <table class="table driver-history-table driver-history-mini-table mb-0">
                        <thead><tr><th>Data</th><th>Curse</th><th>Km</th><th>Cost total</th></tr></thead>
                        <tbody>
                        <?php foreach ($dailyPreviewRows as $row): ?>
                            <tr>
                                <td><?= e($fmtDate($row['date'] ?? null)) ?></td>
                                <td><?= e((string) ($row['trips'] ?? 0)) ?></td>
                                <td><?= e($fmtNumber($row['kilometers'] ?? 0, 0)) ?></td>
                                <td><?= e($fmtMoney($row['total_daily_cost'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($dailyPreviewRows === []): ?><tr><td colspan="4" class="text-center text-muted py-3">Nu exista date.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        <div class="driver-history-footer-note">
            <span>Toate valorile sunt calculate pentru perioada selectata: <?= e($fmtDate($filters['date_start'] ?? null)) ?> - <?= e($fmtDate($filters['date_end'] ?? null)) ?></span>
            <span>Ultima actualizare: <?= e(format_datetime_ro((string) ($dashboard['updatedAt'] ?? date('Y-m-d H:i:s')))) ?></span>
        </div>
    <?php endif; ?>
    </div>
</div>

<script type="application/json" id="driver-history-chart-data"><?= $chartsJson ?: '{}' ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/driver-activity-history.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/driver-activity-history.js'))) ?>"></script>
