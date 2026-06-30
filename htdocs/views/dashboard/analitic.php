<?php
$vehicleSelected = (string) ((int) (($filters['vehicle_ids'][0] ?? 0)));
$driverSelected = (string) ((int) (($filters['driver_ids'][0] ?? 0)));
$beneficiarySelected = (string) ((int) (($filters['beneficiary_ids'][0] ?? 0)));
$transportSelected = (string) ($filters['transport_types'][0] ?? '');
$capacitySelected = (string) ($filters['transport_capacities'][0] ?? '');
$statusSelected = (string) ($filters['statuses'][0] ?? '');
$formatDateRo = static function (string $isoDate): string {
    $isoDate = trim($isoDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $isoDate);
    if (!$date || $date->format('Y-m-d') !== $isoDate) {
        return '';
    }

    return $date->format('d/m/Y');
};
?>

<div
    class="dashboard-analytic-page"
    id="dashboard-analytic-page"
    data-endpoint="<?= e(build_query_url(['page' => 'dashboard_analytic_data'])) ?>"
    data-refresh-ms="30000"
>
    <div class="dashboard-analytic-hero mb-4">
        <div>
            <h2 class="dashboard-analytic-title mb-1">Dashboard Analitic</h2>
            <p class="dashboard-analytic-subtitle mb-0">Profitabilitate vehicule, performanta flota si performanta soferi</p>
        </div>
        <div class="dashboard-analytic-meta">
            <span class="badge text-bg-light border"><i class="bi bi-arrow-repeat me-1"></i> Refresh automat la 30 sec</span>
            <span class="badge text-bg-light border" id="dashboard-analytic-last-refresh">Ultima actualizare: -</span>
        </div>
    </div>

    <div class="card border-0 shadow-sm dashboard-analytic-filter-card mb-4">
        <div class="card-body">
            <form id="dashboard-analytic-filters" class="row g-3" autocomplete="off">
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_date_start_display">Perioada de la</label>
                    <input
                        type="hidden"
                        id="da_date_start"
                        name="date_start"
                        value="<?= e((string) ($filters['date_start'] ?? '')) ?>"
                    >
                    <div class="input-group">
                        <input
                            type="text"
                            class="form-control"
                            id="da_date_start_display"
                            value="<?= e($formatDateRo((string) ($filters['date_start'] ?? ''))) ?>"
                            placeholder="dd/mm/yyyy"
                            inputmode="numeric"
                            autocomplete="off"
                        >
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            id="da_date_start_picker_btn"
                            aria-label="Deschide calendar data start"
                        >
                            <i class="bi bi-calendar3"></i>
                        </button>
                    </div>
                    <input
                        type="date"
                        id="da_date_start_native"
                        class="dashboard-date-native-input"
                        value="<?= e((string) ($filters['date_start'] ?? '')) ?>"
                        tabindex="-1"
                        aria-hidden="true"
                    >
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_date_end_display">Perioada pana la</label>
                    <input
                        type="hidden"
                        id="da_date_end"
                        name="date_end"
                        value="<?= e((string) ($filters['date_end'] ?? '')) ?>"
                    >
                    <div class="input-group">
                        <input
                            type="text"
                            class="form-control"
                            id="da_date_end_display"
                            value="<?= e($formatDateRo((string) ($filters['date_end'] ?? ''))) ?>"
                            placeholder="dd/mm/yyyy"
                            inputmode="numeric"
                            autocomplete="off"
                        >
                        <button
                            class="btn btn-outline-secondary"
                            type="button"
                            id="da_date_end_picker_btn"
                            aria-label="Deschide calendar data final"
                        >
                            <i class="bi bi-calendar3"></i>
                        </button>
                    </div>
                    <input
                        type="date"
                        id="da_date_end_native"
                        class="dashboard-date-native-input"
                        value="<?= e((string) ($filters['date_end'] ?? '')) ?>"
                        tabindex="-1"
                        aria-hidden="true"
                    >
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_vehicle_id">Vehicul</label>
                    <select class="form-select" id="da_vehicle_id" name="vehicle_id">
                        <option value="">Toate</option>
                        <?php foreach ((array) ($filterOptions['vehicles'] ?? []) as $vehicle): ?>
                            <?php
                            $id = (int) ($vehicle['id'] ?? 0);
                            if ($id <= 0) {
                                continue;
                            }
                            ?>
                            <option value="<?= e((string) $id) ?>" <?= $vehicleSelected === (string) $id ? 'selected' : '' ?>>
                                <?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_driver_id">Sofer</label>
                    <select class="form-select" id="da_driver_id" name="driver_id">
                        <option value="">Toti</option>
                        <?php foreach ((array) ($filterOptions['drivers'] ?? []) as $driver): ?>
                            <?php
                            $id = (int) ($driver['id'] ?? 0);
                            if ($id <= 0) {
                                continue;
                            }
                            ?>
                            <option value="<?= e((string) $id) ?>" <?= $driverSelected === (string) $id ? 'selected' : '' ?>>
                                <?= e((string) ($driver['nume'] ?? 'Fara sofer')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_beneficiary_id">Beneficiar</label>
                    <select class="form-select" id="da_beneficiary_id" name="beneficiary_id">
                        <option value="">Toti</option>
                        <?php foreach ((array) ($filterOptions['beneficiaries'] ?? []) as $beneficiary): ?>
                            <?php
                            $id = (int) ($beneficiary['id'] ?? 0);
                            if ($id <= 0) {
                                continue;
                            }
                            ?>
                            <option value="<?= e((string) $id) ?>" <?= $beneficiarySelected === (string) $id ? 'selected' : '' ?>>
                                <?= e((string) ($beneficiary['nume'] ?? 'Fara beneficiar')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_transport_type">Tip transport</label>
                    <select class="form-select" id="da_transport_type" name="tip_transport">
                        <option value="">Toate</option>
                        <?php foreach ((array) ($filterOptions['transport_types'] ?? []) as $transportType): ?>
                            <?php $rawValue = trim((string) ($transportType['tip_transport'] ?? '')); ?>
                            <?php if ($rawValue === ''): continue; endif; ?>
                            <option value="<?= e($rawValue) ?>" <?= $transportSelected === $rawValue ? 'selected' : '' ?>>
                                <?= e((string) ($transportTypeLabels[$rawValue] ?? $rawValue)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_status">Status</label>
                    <select class="form-select" id="da_status" name="status">
                        <option value="">Toate</option>
                        <?php foreach ((array) ($filterOptions['statuses'] ?? []) as $status): ?>
                            <?php $rawValue = trim((string) ($status['status_facturare'] ?? '')); ?>
                            <?php if ($rawValue === ''): continue; endif; ?>
                            <option value="<?= e($rawValue) ?>" <?= $statusSelected === $rawValue ? 'selected' : '' ?>>
                                <?= e((string) ($statusLabels[$rawValue] ?? $rawValue)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="da_transport_capacity">Capacitate transport</label>
                    <select class="form-select" id="da_transport_capacity" name="capacitate_transport">
                        <option value="">Toate</option>
                        <?php foreach ((array) ($filterOptions['transport_capacities'] ?? []) as $capacityRow): ?>
                            <?php
                            $rawCapacity = $capacityRow['capacitate_transport'] ?? null;
                            if ($rawCapacity === null || $rawCapacity === '' || !is_numeric((string) $rawCapacity)) {
                                continue;
                            }
                            $capacityValue = number_format((float) $rawCapacity, 2, '.', '');
                            $capacityLabel = format_number_ro((float) $rawCapacity, 2) . ' t';
                            ?>
                            <option value="<?= e($capacityValue) ?>" <?= $capacitySelected === $capacityValue ? 'selected' : '' ?>>
                                <?= e($capacityLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i> Aplica filtre
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dashboard_analitic'])) ?>">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reseteaza
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div id="dashboard-analytic-loading" class="dashboard-analytic-loading d-none">
        <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
        <span>Se incarca datele dashboard-ului...</span>
    </div>

    <div id="dashboard-analytic-empty" class="card border-0 shadow-sm d-none">
        <div class="card-body text-center py-5">
            <p class="h5 mb-2">Nu exista curse pentru filtrele selectate.</p>
            <p class="text-muted mb-0">Adauga curse in Dispecer Curse sau ajusteaza filtrele pentru a vedea indicatorii.</p>
        </div>
    </div>

    <div id="dashboard-analytic-content">
        <div class="dashboard-analytic-kpi-grid mb-4">
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Total Curse</div>
                <div class="kpi-value" id="kpi_total_curse">0</div>
                <div class="kpi-breakdown" id="kpi_total_curse_breakdown"></div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Servicii</div>
                <div class="kpi-value" id="kpi_total_facturare">0 lei</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Total Refacturare</div>
                <div class="kpi-value" id="kpi_total_refacturare">0 lei</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Total Facturare</div>
                <div class="kpi-value" id="kpi_total_incasare">0 lei</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Total Cheltuieli</div>
                <div class="kpi-value" id="kpi_total_cheltuieli">0 lei</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Venit</div>
                <div class="kpi-value" id="kpi_profit_total">0 lei</div>
            </article>
            <article class="dashboard-analytic-kpi dashboard-analytic-kpi--km">
                <div class="kpi-card-header">
                    <div class="kpi-name">Total Km</div>
                    <div class="kpi-km-delta" id="kpi_total_km_delta"></div>
                </div>
                <div class="kpi-value-line">
                    <div class="kpi-value" id="kpi_total_km">0 km</div>
                    <span class="kpi-value-note">parcursi</span>
                </div>
                <div class="kpi-breakdown" id="kpi_total_km_breakdown"></div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Total tone transportare</div>
                <div class="kpi-value" id="kpi_tone_livrate">0 t</div>
                <div class="kpi-breakdown" id="kpi_tone_livrate_breakdown"></div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Tone primar</div>
                <div class="kpi-value" id="kpi_tone_primar">0 t</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Tone distributie</div>
                <div class="kpi-value" id="kpi_tone_distributie">0 t</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Venit / Km</div>
                <div class="kpi-value" id="kpi_profit_km">0 lei</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Venit/tona</div>
                <div class="kpi-value" id="kpi_venit_to">0 lei</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Km/tona</div>
                <div class="kpi-value" id="kpi_km_tona">0</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Tona/km</div>
                <div class="kpi-value" id="kpi_tona_km">0</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Grad Incarcare (Umplere)</div>
                <div class="kpi-value" id="kpi_grad_mediu">0%</div>
            </article>
            <article class="dashboard-analytic-kpi">
                <div class="kpi-name">Grad Utilizare</div>
                <div class="kpi-value" id="kpi_grad_utilizare_flota_percent">0%</div>
                <div class="kpi-subvalue" id="kpi_grad_utilizare_flota_details">0 zile active din 0 zile disponibile</div>
                <div class="kpi-note">Calculat pe baza curselor din Dispecer curse</div>
            </article>
        </div>

        <section class="dashboard-analytic-section mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="dashboard-analytic-section-title mb-0">Performanta Flota</h3>
            </div>
            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Evolutie Profit, Facturare si Refacturare</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_profit_evolution"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Km Facturati vs Km Nefacturati</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_km_billed_unbilled"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Distributie Tip Transport</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_transport_distribution"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="dashboard-analytic-section mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="dashboard-analytic-section-title mb-0">Profitabilitate Vehicule</h3>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-12 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Top Vehicule dupa Profit</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_vehicle_top_profit"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Profit/Km per Vehicul</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_vehicle_profit_km"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Vehicule cu risc</h4>
                        </div>
                        <div class="card-body">
                            <ul class="dashboard-analytic-alert-list mb-0" id="vehicle-risk-alerts"></ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive dashboard-analytic-table-wrap">
                        <table class="table table-striped table-hover mb-0 dashboard-analytic-data-table">
                            <thead>
                            <tr>
                                <th>Nr. Inmatriculare</th>
                                <th class="text-end">Curse</th>
                                <th class="text-end">Km Totali</th>
                                <th class="text-end">Tone Livrate</th>
                                <th class="text-end">Facturare</th>
                                <th class="text-end">Refacturare</th>
                                <th class="text-end">Cheltuieli</th>
                                <th class="text-end">Profit</th>
                                <th class="text-end">Venit/Km</th>
                                <th class="text-end">Cost/Km</th>
                                <th class="text-end">Profit/Km</th>
                                <th class="text-end">Km Nefacturati %</th>
                                <th class="text-end">Grad Incarcare (Umplere)</th>
                            </tr>
                            </thead>
                            <tbody id="vehicle-profitability-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section class="dashboard-analytic-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="dashboard-analytic-section-title mb-0">Performanta Soferi</h3>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-12 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Curse per Sofer</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_driver_rides"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Tone Livrate per Sofer</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_driver_tons"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white border-0 pb-0">
                            <h4 class="h6 mb-0">Driver Activity Matrix</h4>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-analytic-canvas-wrap" data-chart-wrapper>
                                <canvas id="chart_driver_matrix"></canvas>
                                <div class="dashboard-analytic-chart-empty d-none">Nu exista date pentru acest grafic.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive dashboard-analytic-table-wrap">
                        <table class="table table-striped table-hover mb-0 dashboard-analytic-data-table">
                            <thead>
                            <tr>
                                <th>Sofer</th>
                                <th class="text-end">Curse</th>
                                <th class="text-end">Km Totali</th>
                                <th class="text-end">Tone Livrate</th>
                                <th class="text-end">Facturare Generata</th>
                                <th class="text-end">Refacturare</th>
                                <th class="text-end">Profit Generat</th>
                                <th class="text-end">Tone/Cursa</th>
                                <th class="text-end">Km/Cursa</th>
                                <th class="text-end">Grad Incarcare (Umplere)</th>
                            </tr>
                            </thead>
                            <tbody id="driver-performance-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/dashboard-analitic.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/dashboard-analitic.js'))) ?>"></script>


