<?php
declare(strict_types=1);

$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$filters = is_array($filters ?? null) ? $filters : [];
$driver = is_array($dashboard['driver'] ?? null) ? $dashboard['driver'] : null;
$kpis = is_array($dashboard['kpis'] ?? null) ? $dashboard['kpis'] : [];
$transportLabels = is_array($transportLabels ?? null) ? $transportLabels : DriverActivityHistoryModel::TRANSPORT_LABELS;
$driverOptions = is_array($driverOptions ?? null) ? $driverOptions : [];
$beneficiaryOptions = is_array($beneficiaryOptions ?? null) ? $beneficiaryOptions : [];
$driverId = (int) ($filters['driver_id'] ?? ($driver['id'] ?? 0));
$isCompare = !empty($isCompare);
// Dreptul „Date financiare” (Drepturi de acces). Fara el controllerul a scos deja
// salariul, diurna in lei, costul total, valoarea curselor si profitul din date.
$canFinancial = !empty($canFinancial);
$selectedDriverIds = array_map('intval', (array) ($filters['driver_ids'] ?? [$driverId]));

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
$activeDaysLabel = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    $days = max(0, (int) $value);
    return $days === 1 ? '1 zi' : $days . ' zile';
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
    'driver_ids' => $selectedDriverIds,
    'date_range' => (string) ($filters['date_range'] ?? ''),
    'vehicle_id' => (int) ($filters['vehicle_id'] ?? 0),
    'beneficiar_id' => (int) ($filters['beneficiar_id'] ?? 0),
    'transport_type' => (string) ($filters['transport_type'] ?? ''),
    'grouping' => (string) ($filters['grouping'] ?? 'daily'),
];
$exportQuery = $queryBase;
$resetUrl = build_query_url(['page' => 'istoric_activitati_sofer', 'driver_ids' => $selectedDriverIds]);
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
    $topBeneficiaries[$beneficiaryName] = ($topBeneficiaries[$beneficiaryName] ?? 0.0) + (float) ($trip['transported_tons'] ?? 0) + (float) ($trip['delivered_tons'] ?? 0);
}
arsort($topBeneficiaries);
$topBeneficiaries = array_slice($topBeneficiaries, 0, 3, true);

$dailyPreviewRows = array_slice((array) ($dashboard['dailyRows'] ?? []), 0, 3);

// Nota diurnelor: valoarea in lei dupa regula soferului din Contabilitate Personal.
$diurnaNoteFor = static function (array $k) use ($fmtMoney): string {
    $policy = (array) ($k['diurna_policy'] ?? []);
    if (($policy['status'] ?? '') === DriverDiurnaModel::STATUS_NONE && (int) ($k['diurne'] ?? 0) === 0) {
        return 'nu primeste diurna';
    }
    $parts = [];
    if (!isset($k['diurne_value'])) {
        return 'din ' . (int) ($k['diurne_trips'] ?? 0) . ' curse';
    }
    if ((float) ($k['diurne_value'] ?? 0) > 0) {
        $parts[] = $fmtMoney($k['diurne_value']);
    }
    if ((int) ($k['diurne_unvalued'] ?? 0) > 0) {
        $parts[] = (int) $k['diurne_unvalued'] . ' fara valoare / zi';
    }
    if ((int) ($k['diurne_missing'] ?? 0) > 0) {
        $parts[] = (int) $k['diurne_missing'] . ' curse fara ora';
    }

    return $parts === [] ? 'din ' . (int) ($k['diurne_trips'] ?? 0) . ' curse' : implode(' · ', $parts);
};
$diurneNote = $diurnaNoteFor($kpis);

// Distributie si Primar + Distributie livreaza (Tone livrate); Primar si
// Compresor transporta (Tone transportate). Cealalta coloana ramane "-".
$isDeliveryTrip = static fn (array $trip): bool => in_array((string) ($trip['transport_bucket'] ?? ''), ['distributie', 'primar_distributie'], true);
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
            <?php if ($isCompare): ?>
                <h1>Comparatie soferi (<?= e((string) count((array) ($dashboard['drivers'] ?? []))) ?>)</h1>
                <p>Activitatea soferilor selectati, alaturi, pentru aceeasi perioada si aceleasi filtre.</p>
            <?php else: ?>
                <h1>Istoric Activitati Sofer - <?= e((string) ($driver['nume'] ?? '')) ?></h1>
                <p>Profil analitic complet pentru perioada selectata. Alege mai multi soferi pentru comparatie.</p>
            <?php endif; ?>
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

    <?php if (!$isCompare && $driver === null): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <?= $driverOptions === []
                    ? 'Niciun sofer nu are activitate in perioada selectata. Schimba intervalul sau filtrele.'
                    : 'Nu exista soferi inregistrati pentru afisarea istoricului.' ?>
            </div>
        </div>
    <?php else: ?>
        <form class="driver-history-filter-card" method="get" action="<?= e(url('index.php')) ?>">
            <input type="hidden" name="page" value="istoric_activitati_sofer">
            <div class="driver-history-filter-grid">
                <div class="driver-history-filter-field">
                    <label for="driver_history_driver_toggle">Soferi</label>
                    <div class="driver-picker" data-driver-picker>
                        <button type="button" class="form-select driver-picker-toggle" id="driver_history_driver_toggle" data-driver-picker-toggle aria-expanded="false" aria-haspopup="true">
                            <span data-driver-picker-label>Alege soferi</span>
                        </button>
                        <div class="driver-picker-menu" data-driver-picker-menu hidden>
                            <input type="search" class="form-control form-control-sm" placeholder="Cauta sofer..." data-driver-picker-search aria-label="Cauta sofer">
                            <div class="driver-picker-quick">
                                <button type="button" data-driver-picker-active>Toti activii</button>
                                <button type="button" data-driver-picker-clear>Niciunul</button>
                                <span data-driver-picker-count></span>
                            </div>
                            <div class="driver-picker-list">
                                <?php foreach ($driverOptions as $option): ?>
                                    <?php
                                    $optionId = (int) ($option['id'] ?? 0);
                                    $optionActive = strtolower((string) ($option['status'] ?? '')) === 'activ';
                                    ?>
                                    <label class="driver-picker-option" data-driver-picker-option data-name="<?= e(mb_strtolower((string) ($option['nume'] ?? ''))) ?>">
                                        <input type="checkbox" name="driver_ids[]" value="<?= e((string) $optionId) ?>" data-active="<?= $optionActive ? '1' : '0' ?>" <?= in_array($optionId, $selectedDriverIds, true) ? 'checked' : '' ?>>
                                        <span><?= e((string) ($option['nume'] ?? '-')) ?></span>
                                        <?php if (!$optionActive): ?><small>inactiv</small><?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
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
                    <label for="driver_history_beneficiary">Beneficiar</label>
                    <select class="form-select" id="driver_history_beneficiary" name="beneficiar_id">
                        <option value="">Toti beneficiarii</option>
                        <?php foreach ($beneficiaryOptions as $beneficiary): ?>
                            <?php $beneficiaryId = (int) ($beneficiary['id'] ?? 0); ?>
                            <option value="<?= e((string) $beneficiaryId) ?>" <?= (int) ($filters['beneficiar_id'] ?? 0) === $beneficiaryId ? 'selected' : '' ?>>
                                <?= e((string) ($beneficiary['nume'] ?? '-')) ?>
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
            <?php if ((string) ($filters['date_range_invalid'] ?? '') !== ''): ?>
                <div class="alert alert-warning py-2 mt-3 mb-0">
                    Intervalul „<?= e((string) $filters['date_range_invalid']) ?>” nu a putut fi citit; se afiseaza <?= e((string) ($filters['date_range'] ?? '')) ?>. Foloseste formatul zz.ll.aaaa - zz.ll.aaaa.
                </div>
            <?php endif; ?>
        </form>

        <?php if ($isCompare): ?>
            <?php include __DIR__ . '/_compare.php'; ?>
        <?php else: ?>
        <?php /* Acelasi comutator ca in comparatie: sumar (KPI + grafice) sau toata activitatea. */ ?>
        <div class="driver-history-compare-views" data-compare-view="summary">
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
                        <div><dt>Data angajarii:</dt><dd><?= e($fmtDate($driver['data_angajare_calculata'] ?? ($driver['data_angajare'] ?? null))) ?></dd></div>
                        <div><dt>Data incetarii:</dt><dd><?= e(!empty($driver['data_incetare']) ? $fmtDate($driver['data_incetare']) : '-') ?></dd></div>
                        <div><dt>Zile active:</dt><dd><?= e($activeDaysLabel($driver['active_days'] ?? null)) ?></dd></div>
                    </dl>
                </div>
            </article>

            <?php include __DIR__ . '/_kpi_cards.php'; ?>
        </section>

        <?php /* Comutatorul sta chiar deasupra tabelului, ca in comparatie (nu sus, langa cardul soferului). */ ?>
        <div class="driver-history-view-switch" role="group" aria-label="Mod de afisare">
            <button type="button" data-compare-view-button="summary" aria-pressed="true">Sumar sofer</button>
            <button type="button" data-compare-view-button="trips" aria-pressed="false">Curse (<?= e((string) count((array) ($dashboard['trips'] ?? []))) ?>)</button>
        </div>

        <?php /* Acelasi tabel de sumar ca la comparatie, cu randul desfasurat al curselor. */ ?>
        <?php if ((array) ($comparison['drivers'] ?? []) !== []): ?>
            <?php $comparisonData = $comparison; ?>
            <?php include __DIR__ . '/_summary_table.php'; ?>
        <?php endif; ?>

        <section class="driver-history-chart-grid">
            <article class="driver-history-panel">
                <h2>Tone transportate / livrate pe tip de transport</h2>
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
                <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#driver-history-diurne" type="button" role="tab"><?= $canFinancial ? 'Diurne &amp; salariu' : 'Diurne' ?></button></li>
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
                        <?php /* Sortare + filtrare din antet, ca in lista din comparatie. */ ?>
                        <table class="table driver-history-table mb-0" data-column-filter>
                            <thead><tr><th>Data</th><th>Beneficiar</th><th>Vehicul</th><th>Tip transport</th><th>Total KM</th><th>KM nefacturabili</th><th>Tone transportate</th><th>Tone livrate</th><th>Durata cursa</th><th>Diurne</th><?php if ($canFinancial): ?><th>Valoare cursa</th><?php endif; ?><th title="Toate cheltuielile inregistrate pe cursa, inclusiv cele de refacturat">Cost cursa</th><?php if ($canFinancial): ?><th title="Refacturari trecute in Refacturat (bani recuperati)">Refacturat</th><?php endif; ?><th data-no-filter>Actiuni</th></tr></thead>
                            <tbody>
                            <?php
                            // Randul intreg deschide formularul cursei in Dispecer curse (ca iconita "Cursa").
                            $canOpenTrip = can_route('dispecer_curse');
                            ?>
                            <?php foreach ((array) ($dashboard['trips'] ?? []) as $row): ?>
                                <tr<?= $canOpenTrip ? ' class="driver-history-row-link" data-row-href="' . e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['id']])) . '" tabindex="0" title="Deschide cursa in Dispecer curse"' : '' ?>>
                                    <td><?= e($fmtDate($row['data_inceput'] ?? null)) ?></td>
                                    <td><?= e((string) ($row['beneficiary_label'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                                    <td><?= e((string) ($row['transport_label'] ?? '-')) ?></td>
                                    <td><?= e($fmtNumber($row['effective_km'] ?? 0, 0)) ?></td>
                                    <td><?= e($fmtNumber($row['non_billable_km'] ?? 0, 0)) ?></td>
                                    <td><?= $isDeliveryTrip($row) ? '-' : e($fmtNumber($row['transported_tons'] ?? 0, 2)) ?></td>
                                    <td><?= $isDeliveryTrip($row) ? e($fmtNumber($row['delivered_tons'] ?? 0, 2)) : '-' ?></td>
                                    <td><?= e($fmtDuration($row['duration_minutes_effective'] ?? 0)) ?></td>
                                    <td<?= ($row['diurne'] ?? null) === null ? ' title="Lipseste data/ora de inceput sau sfarsit, ori intervalul este inversat."' : '' ?>><?= ($row['diurne'] ?? null) === null ? '-' : e((string) (int) $row['diurne']) ?></td>
                                    <?php if ($canFinancial): ?><td><?= e($fmtMoney($row['total_facturare'] ?? 0)) ?></td><?php endif; ?>
                                    <td title="<?= e('Platite: ' . $fmtMoney((float) ($row['total_cheltuieli'] ?? 0) - (float) ($row['total_refacturare'] ?? 0)) . ' · de refacturat: ' . $fmtMoney($row['total_refacturare'] ?? 0)) ?>"><?= e($fmtMoney($row['total_cheltuieli'] ?? 0)) ?></td>
                                    <?php if ($canFinancial): ?><td><?= e($fmtMoney($row['total_refacturare_facturata'] ?? 0)) ?></td><?php endif; ?>
                                    <td>
                                        <div class="driver-history-row-actions">
                                            <a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['id']])) ?>" title="Cursa"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                            <a href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $row['vehicle_id']])) ?>" title="Vehicul"><i class="bi bi-truck" aria-hidden="true"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (($dashboard['trips'] ?? []) === []): ?><tr><td colspan="<?= $canFinancial ? 14 : 12 ?>" class="text-center text-muted py-4">Nu exista curse pentru filtrele selectate.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="driver-history-diurne" role="tabpanel">
                    <?php
                    $diurneRows = (array) ($dashboard['diurneRows'] ?? []);
                    $diurnaPolicy = (array) ($kpis['diurna_policy'] ?? ['status' => DriverDiurnaModel::STATUS_UNSET, 'rate' => null]);
                    $salaryMonths = (array) ($kpis['salary_months'] ?? []);
                    $diurnaSettingsUrl = build_query_url(['page' => 'contabilitate_personal', 'q' => (string) ($driver['nume'] ?? '')]);
                    $policyLabelAt = static fn (array $policy): string => DriverDiurnaModel::label($policy);
                    ?>
                    <div class="driver-history-consumption-grid">
                        <?php if ($canFinancial): ?><div><span>Diurna soferului</span><strong><?= e(DriverDiurnaModel::label($diurnaPolicy)) ?></strong></div><?php endif; ?>
                        <div><span>Diurne platite</span><strong><?= e((string) (int) ($kpis['diurne'] ?? 0)) ?></strong></div>
                        <?php if ($canFinancial): ?><div><span>Diurne in costul total</span><strong><?= e($fmtMoney($kpis['diurne_value_in_total'] ?? 0)) ?></strong></div><?php endif; ?>
                        <div><span>Curse cu diurna</span><strong><?= e((string) (int) ($kpis['diurne_trips'] ?? 0)) ?> din <?= e((string) count($diurneRows)) ?></strong></div>
                        <div><span>Zile lucrate</span><strong><?= e((string) (int) ($kpis['worked_days'] ?? 0)) ?></strong></div>
                        <?php if ($canFinancial): ?><div><span>Cost salarial</span><strong><?= e($fmtMoney($kpis['salary_cost'] ?? 0)) ?></strong></div><?php endif; ?>
                    </div>
                    <?php if ($canFinancial && (($diurnaPolicy['status'] ?? '') === DriverDiurnaModel::STATUS_UNSET || (int) ($kpis['diurne_unvalued'] ?? 0) > 0)): ?>
                        <div class="alert alert-warning py-2 small mt-3 mb-2">
                            Diurna acestui sofer nu este stabilita (primeste sau nu, si valoarea pe zi), deci diurnele nu au valoare in lei.
                            Se stabileste in <a href="<?= e($diurnaSettingsUrl) ?>">Contabilitate Personal</a> → meniul soferului → Diurnă.
                        </div>
                    <?php endif; ?>
                    <?php if ((int) ($kpis['diurne_not_eligible'] ?? 0) > 0): ?>
                        <div class="alert alert-secondary py-2 small mt-3 mb-2">
                            Soferul nu primeste diurna: <?= e((string) (int) $kpis['diurne_not_eligible']) ?> zile rezultate din curse nu se platesc.
                        </div>
                    <?php endif; ?>
                    <p class="text-muted small mb-2 mt-2">
                        Numarul de diurne vine din Dispecer curse: durata = de la „Data si ora inceput” la „Data si ora sfarsit”;
                        sub 12h = 0 diurne, 12h–35:59 = 1, apoi inca una la fiecare 24h. La cursele reluate cu alt sofer,
                        diurnele se impart pe soferi dupa timpul condus.
                        <?php if ($canFinancial): ?>
                        Daca soferul primeste diurna si cat valoreaza o zi se stabileste per sofer in Contabilitate Personal.
                        Valoarea diurnelor intra in costul total; la cursele pe care diurna este deja trecuta ca cheltuiala
                        (tip „Diurna” in Dispecer curse) conteaza cheltuiala inregistrata, iar valoarea calculata nu se mai adauga.
                        <?php endif; ?>
                    </p>
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Inceput</th><th>Sfarsit</th><th>Vehicul</th><th>Durata</th><th>Diurne cursa</th><th>Diurne sofer</th><?php if ($canFinancial): ?><th>Valoare</th><?php endif; ?><th>Observatii</th><th>Actiuni</th></tr></thead>
                            <tbody>
                            <?php foreach ($diurneRows as $row): ?>
                                <?php
                                $note = match ((string) $row['status']) {
                                    'lipsa' => 'Lipseste data sau ora de inceput / sfarsit.',
                                    'invalid' => 'Sfarsitul este inaintea inceputului.',
                                    default => (string) $row['split'] !== '' ? 'Impartit pe soferi - ' . $row['split'] : '',
                                };
                                if (!empty($row['segment_only'])) {
                                    $note = trim('Soferul a condus doar o faza. ' . $note);
                                }
                                $rowPolicy = (array) ($row['policy'] ?? []);
                                if (($rowPolicy['status'] ?? '') === DriverDiurnaModel::STATUS_NONE && $row['status'] === 'ok') {
                                    $note = trim('Nu primeste diurna. ' . $note);
                                }
                                if (($row['diurna_recorded'] ?? null) !== null) {
                                    $note = trim('Diurna e deja trecuta ca cheltuiala pe cursa (' . $fmtMoney($row['diurna_recorded']) . '); valoarea calculata nu se mai adauga. ' . $note);
                                }
                                $moment = static fn ($date, $time): string => ($date ? $fmtDate($date) : '-') . ($time ? ' ' . substr((string) $time, 0, 5) : '');
                                ?>
                                <tr>
                                    <td><?= e($moment($row['data_inceput'], $row['ora_inceput'])) ?></td>
                                    <td><?= e($moment($row['data_sfarsit'], $row['ora_sfarsit'])) ?></td>
                                    <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                                    <td><?= $row['minutes'] !== null ? e($fmtDuration($row['minutes'])) : '-' ?></td>
                                    <td><?= $row['trip_diurne'] !== null ? e((string) $row['trip_diurne']) : '-' ?></td>
                                    <td><strong class="<?= $row['status'] === 'invalid' ? 'text-danger' : '' ?>"><?= $row['diurne'] !== null ? e((string) $row['diurne']) : '-' ?></strong></td>
                                    <?php if ($canFinancial): ?><td title="<?= e($policyLabelAt($rowPolicy)) ?>" class="<?= ($row['diurna_recorded'] ?? null) !== null ? 'text-decoration-line-through text-muted' : '' ?>"><?= ($row['diurne_value'] ?? null) !== null ? e($fmtMoney($row['diurne_value'])) : '-' ?></td><?php endif; ?>
                                    <td><?= e($note !== '' ? $note : '-') ?></td>
                                    <td>
                                        <div class="driver-history-row-actions">
                                            <a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['id']])) ?>" title="Cursa"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($diurneRows === []): ?><tr><td colspan="<?= $canFinancial ? 9 : 8 ?>" class="text-center text-muted py-4">Nu exista curse pentru filtrele selectate.</td></tr><?php endif; ?>
                            </tbody>
                            <?php if ($diurneRows !== []): ?>
                                <tfoot><tr><th colspan="5" class="text-end">Total sofer</th><th><?= e((string) (int) ($kpis['diurne'] ?? 0)) ?></th><?php if ($canFinancial): ?><th><?= e($fmtMoney($kpis['diurne_value'] ?? 0)) ?></th><?php endif; ?><th colspan="2"></th></tr></tfoot>
                            <?php endif; ?>
                        </table>
                    </div>

                    <?php if ($canFinancial): ?>
                    <div class="driver-history-panel mt-3">
                        <h2>Salariu dupa zilele lucrate</h2>
                        <p class="text-muted small mb-2">
                            Zilele lucrate sunt zilele acoperite de cursele soferului din Dispecer curse (fiecare zi o singura data).
                            Salariu pe zi = salariul lunii din Contabilitate Personal / zilele lucratoare ale lunii (luni-vineri).
                            Zilele de weekend lucrate se adauga peste.
                        </p>
                        <div class="driver-history-table-wrap is-compact">
                            <table class="table driver-history-table mb-0">
                                <thead><tr><th>Luna</th><th>Zile lucrate</th><th>Zile lucratoare</th><th>Salariu lunar</th><th>Salariu / zi</th><th>Cost salarial</th></tr></thead>
                                <tbody>
                                <?php foreach ($salaryMonths as $month): ?>
                                    <tr>
                                        <td><?= e(date('m.Y', strtotime($month['month'] . '-01'))) ?></td>
                                        <td><?= e((string) (int) $month['worked_days']) ?></td>
                                        <td><?= e((string) (int) $month['working_days']) ?></td>
                                        <td><?= $month['salary'] !== null ? e($fmtMoney($month['salary'])) : '<span class="text-warning">nesetat</span>' ?></td>
                                        <td><?= $month['daily'] !== null ? e($fmtMoney($month['daily'])) : '-' ?></td>
                                        <td><strong><?= $month['cost'] !== null ? e($fmtMoney($month['cost'])) : '-' ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($salaryMonths === []): ?><tr><td colspan="6" class="text-center text-muted py-3">Nicio zi lucrata in perioada selectata.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="tab-pane fade" id="driver-history-fuel" role="tabpanel">
                    <div class="driver-history-table-wrap">
                        <table class="table driver-history-table mb-0">
                            <thead><tr><th>Data</th><th>Vehicul</th><th>Sofer</th><th>Litri combustibil</th><th>Pret combustibil</th><th>Cost combustibil</th><th>Kilometraj</th><th>Tip combustibil</th><th>Consum calculat</th><th>Statie</th><th>Actiuni</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['fuelRows'] ?? []) as $row): ?>
                                <?php $receiptUrl = trim((string) ($row['receipt_path'] ?? '')) !== '' ? build_query_url(['page' => 'carburanti', 'action' => 'receipt', 'fillup_id' => (int) $row['id']]) : ''; ?>
                                <tr>
                                    <td><?= e(format_datetime_ro((string) ($row['fillup_datetime'] ?? $row['data_alimentare'] ?? ''))) ?></td>
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
                                            <?php if ((int) ($row['linked_trip_id'] ?? 0) > 0): ?><a href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['linked_trip_id']])) ?>" title="Cursa"><i class="bi bi-signpost-2" aria-hidden="true"></i></a><?php endif; ?>
                                            <?php if ($receiptUrl !== ''): ?><a href="<?= e($receiptUrl) ?>" target="_blank" rel="noopener" title="Bon fiscal"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></a><?php endif; ?>
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
                            <thead><tr><th>Numar inmatriculare</th><th>Tip vehicul</th><th>Curse</th><th>Kilometri</th><th>Tone transportate</th><th>Tone livrate</th><th>Cost combustibil</th><th>Cost reparatii</th><th>Cost total</th><th>Procent utilizare</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['vehicleRows'] ?? []) as $row): ?>
                                <tr>
                                    <td><a href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $row['id']])) ?>"><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></a></td>
                                    <td><?= e(vehicle_type_label((string) ($row['tip_vehicul'] ?? ''))) ?></td>
                                    <td><?= e((string) ($row['trips'] ?? 0)) ?></td>
                                    <td><?= e($fmtNumber($row['kilometers'] ?? 0, 0)) ?> km</td>
                                    <td><?= e($fmtNumber($row['transported_tons'] ?? 0, 2)) ?> t</td>
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
                            <thead><tr><th>Data</th><th>Curse</th><th>Kilometri</th><th>Tone transportate</th><th>Tone livrate</th><th>Combustibil utilizat</th><th>Cost combustibil</th><th>Cost reparatii</th><th>Cost zilnic total</th><th>Ore condus</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) ($dashboard['dailyRows'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= e($fmtDate($row['date'] ?? null)) ?></td>
                                    <td><?= e((string) ($row['trips'] ?? 0)) ?></td>
                                    <td><?= e($fmtNumber($row['kilometers'] ?? 0, 0)) ?> km</td>
                                    <td><?= e($fmtNumber($row['transported_tons'] ?? 0, 2)) ?> t</td>
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
                <h2>Top beneficiari dupa tonaj</h2>
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
                    <?php $costPerKmKey = $canFinancial ? 'total_costs' : 'operational_costs'; ?>
                    <div><span><?= $canFinancial ? 'Cost total / km' : 'Cost operational / km' ?></span><strong><?= ((float) ($kpis['total_km'] ?? 0)) > 0 ? e($fmtMoney(((float) ($kpis[$costPerKmKey] ?? 0)) / (float) $kpis['total_km'])) : '-' ?></strong></div>
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
        </div>

        <?php endif; ?>

        <div class="driver-history-footer-note">
            <span>Toate valorile sunt calculate pentru perioada selectata: <?= e($fmtDate($filters['date_start'] ?? null)) ?> - <?= e($fmtDate($filters['date_end'] ?? null)) ?></span>
            <span>Ultima actualizare: <?= e(format_datetime_ro((string) ($dashboard['updatedAt'] ?? date('Y-m-d H:i:s')))) ?></span>
        </div>
    <?php endif; ?>
    </div>
</div>

<script type="application/json" id="driver-history-chart-data"><?= $chartsJson ?: '{}' ?></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<?php /* Comutatorul de afisare si randurile desfasurate: si la un sofer, si la comparatie. */ ?>
<link rel="stylesheet" href="<?= e(url('assets/css/driver-history-trips.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/driver-history-trips.css'))) ?>">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/ro.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/table-column-filter.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/table-column-filter.js'))) ?>"></script>
<script src="<?= e(url('assets/js/driver-activity-history.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/driver-activity-history.js'))) ?>"></script>
