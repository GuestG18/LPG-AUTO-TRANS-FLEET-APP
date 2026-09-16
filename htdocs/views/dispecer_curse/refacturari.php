<?php
$historyRows = is_array($refacturareRows ?? null) ? $refacturareRows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$defaultFilters = is_array($defaultFilters ?? null) ? $defaultFilters : [];
$summary = is_array($refacturareSummary ?? null) ? $refacturareSummary : [];
$pagination = is_array($pagination ?? null) ? $pagination : [];
$plateOptions = is_array($plateOptions ?? null) ? $plateOptions : [];
$beneficiaryOptions = is_array($beneficiaryOptions ?? null) ? $beneficiaryOptions : [];

// Vehiculele grupate dupa capacitate, ca in selectorul din Configurare transport.
$selectedVehicleIds = array_map('intval', (array) ($filters['vehicle_ids'] ?? []));
$vehicleLabelById = [];
$vehicleCapacityGroups = [];
foreach ($plateOptions as $plateOption) {
    $vehicleOptionId = (int) ($plateOption['id'] ?? 0);
    if ($vehicleOptionId <= 0) {
        continue;
    }
    $vehicleName = trim((string) (($plateOption['marca'] ?? '') . ' ' . ($plateOption['model'] ?? '')));
    $vehicleLabelById[$vehicleOptionId] = trim((string) ($plateOption['nr_inmatriculare'] ?? '')) . ($vehicleName !== '' ? ' - ' . $vehicleName : '');

    $capacityValue = (float) ($plateOption['capacitate_transport'] ?? 0);
    $capacityKey = $capacityValue > 0 ? number_format($capacityValue, 2, '.', '') : 'fara';
    if (!isset($vehicleCapacityGroups[$capacityKey])) {
        $vehicleCapacityGroups[$capacityKey] = [
            'label' => $capacityValue > 0
                ? rtrim(rtrim(number_format($capacityValue, 2, '.', ''), '0'), '.') . ' tone'
                : 'Fără capacitate',
            'capacity' => $capacityValue,
            'vehicles' => [],
        ];
    }
    $vehicleCapacityGroups[$capacityKey]['vehicles'][] = $plateOption;
}
uasort($vehicleCapacityGroups, static fn (array $a, array $b): int => $b['capacity'] <=> $a['capacity']);

$selectedVehicleIds = array_values(array_filter($selectedVehicleIds, static fn (int $id): bool => isset($vehicleLabelById[$id])));
$selectedVehicleLabel = match (count($selectedVehicleIds)) {
    0 => 'Toate vehiculele',
    1 => $vehicleLabelById[$selectedVehicleIds[0]],
    default => count($selectedVehicleIds) . ' vehicule selectate',
};
$expenseEntryTypes = is_array($expenseEntryTypes ?? null) ? $expenseEntryTypes : (array) ($expenseTypes ?? []);
unset($expenseEntryTypes['motorina']);
$refacturareTypeLabels = [
    'taxe_drum' => 'Taxe drum',
    'diurna' => 'Diurnă',
    'service' => 'Reparații',
    'alte' => 'Alte cheltuieli',
];
$refacturareFilterTypes = [];
foreach ($expenseEntryTypes as $typeKey => $typeLabel) {
    $typeKey = (string) $typeKey;
    $refacturareFilterTypes[$typeKey] = $refacturareTypeLabels[$typeKey] ?? (string) $typeLabel;
}
$expenseEntryTypes = $refacturareFilterTypes;

$currentSort = (string) ($sort ?? 'date');
$currentDirection = (string) ($direction ?? 'desc');
$currentPageIndex = max(1, (int) ($pagination['page'] ?? 1));
$totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$perPage = max(5, (int) ($pagination['per_page'] ?? 10));
$totalRows = max(0, (int) ($pagination['total_rows'] ?? 0));
$returnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari']));

$filterBase = array_merge(
    ['page' => 'dispecer_curse', 'action' => 'refacturari'],
    $filters,
    ['per_page' => $perPage, 'sort' => $currentSort, 'dir' => $currentDirection]
);

$formatMoney = static function (mixed $value): string {
    return format_number_ro((float) $value, 2) . ' lei';
};

$formatDateTimeInline = static function (?string $date, ?string $time): string {
    $date = trim((string) $date);
    if ($date === '') {
        return '-';
    }

    $time = trim((string) $time);
    $timeLabel = $time !== '' ? substr($time, 0, 5) : '';

    return format_date_ro($date) . ($timeLabel !== '' ? ' ' . $timeLabel : '');
};

$buildRouteLabel = static function (array $row): string {
    $start = trim((string) ($row['loc_plecare'] ?? ''));
    if ($start === '') {
        $start = trim((string) ($row['loc_incarcare_nume'] ?? ''));
    }
    if ($start === '') {
        $start = trim((string) ($row['loc_aspirare'] ?? ''));
    }

    $end = trim((string) ($row['loc_livrare'] ?? ''));
    if ($end === '') {
        $end = trim((string) ($row['zona_distributie_nume'] ?? ''));
    }
    if ($end === '') {
        $end = trim((string) ($row['loc_livrare_cursa'] ?? ''));
    }

    if ($start !== '' && $end !== '' && mb_strtolower($start) !== mb_strtolower($end)) {
        return $start . ' - ' . $end;
    }

    if ($start !== '') {
        return $start;
    }

    return $end !== '' ? $end : '-';
};

$sortUrl = static function (string $sortKey) use ($filterBase, $currentSort, $currentDirection): string {
    $nextDirection = ($currentSort === $sortKey && $currentDirection === 'asc') ? 'desc' : 'asc';

    return build_query_url(array_merge($filterBase, [
        'sort' => $sortKey,
        'dir' => $nextDirection,
        'p' => 1,
    ]));
};

$sortIcon = static function (string $sortKey) use ($currentSort, $currentDirection): string {
    if ($currentSort !== $sortKey) {
        return 'bi-arrow-down-up';
    }

    return $currentDirection === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down';
};

$rangeStart = $totalRows === 0 ? 0 : (($currentPageIndex - 1) * $perPage) + 1;
$rangeEnd = min($totalRows, $currentPageIndex * $perPage);
?>

<div class="refacturare-dashboard" data-refacturare-dashboard>
    <div class="refacturare-page-header">
        <div class="refacturare-title-block">
            <h1>Refacturări curse</h1>
            <p>Monitorizează și gestionează refacturările curselor</p>
        </div>
        <a class="btn refacturare-back-btn" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            <span>Înapoi la Dispecer curse</span>
        </a>
    </div>

    <section class="refacturare-kpi-grid" aria-label="Indicatori refacturări">
        <article class="refacturare-kpi-card is-blue">
            <div class="refacturare-kpi-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
            <div>
                <p>Total refacturat</p>
                <strong><?= e($formatMoney($summary['total_amount'] ?? 0)) ?></strong>
                <span>conform filtrelor aplicate</span>
            </div>
        </article>

        <article class="refacturare-kpi-card is-orange">
            <div class="refacturare-kpi-icon"><i class="bi bi-clock" aria-hidden="true"></i></div>
            <div>
                <p>În așteptare</p>
                <strong><?= e($formatMoney($summary['pending_amount'] ?? 0)) ?></strong>
                <span><?= e((string) ((int) ($summary['pending_count'] ?? 0))) ?> <?= (int) ($summary['pending_count'] ?? 0) === 1 ? 'refacturare' : 'refacturări' ?></span>
            </div>
        </article>

        <article class="refacturare-kpi-card is-green">
            <div class="refacturare-kpi-icon"><i class="bi bi-file-earmark-check" aria-hidden="true"></i></div>
            <div>
                <p>Facturate</p>
                <strong><?= e($formatMoney($summary['invoiced_amount'] ?? 0)) ?></strong>
                <span><?= e((string) ((int) ($summary['invoiced_count'] ?? 0))) ?> <?= (int) ($summary['invoiced_count'] ?? 0) === 1 ? 'refacturare' : 'refacturări' ?></span>
            </div>
        </article>

        <article class="refacturare-kpi-card is-purple">
            <div class="refacturare-kpi-icon"><i class="bi bi-bar-chart" aria-hidden="true"></i></div>
            <div>
                <p>Nr. refacturări</p>
                <strong><?= e((string) ((int) ($summary['total_count'] ?? 0))) ?></strong>
                <span>conform filtrelor aplicate</span>
            </div>
        </article>
    </section>

    <section class="refacturare-panel refacturare-filter-panel">
        <header class="refacturare-panel-header">
            <h2><i class="bi bi-funnel" aria-hidden="true"></i> Filtre refacturări</h2>
        </header>

        <form method="get" class="refacturare-filter-form" data-refacturare-filter-form>
            <input type="hidden" name="page" value="dispecer_curse">
            <input type="hidden" name="action" value="refacturari">
            <input type="hidden" name="p" value="<?= e((string) $currentPageIndex) ?>" data-refacturare-page-input>
            <input type="hidden" name="per_page" value="<?= e((string) $perPage) ?>">
            <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
            <input type="hidden" name="dir" value="<?= e($currentDirection) ?>">

            <div class="refacturare-filter-field">
                <label class="form-label" for="ref_filter_data_start">Data de la</label>
                <input class="form-control" type="date" id="ref_filter_data_start" name="data_start" value="<?= e((string) ($filters['data_start'] ?? ($defaultFilters['data_start'] ?? ''))) ?>">
            </div>

            <div class="refacturare-filter-field">
                <label class="form-label" for="ref_filter_data_end">Data până la</label>
                <input class="form-control" type="date" id="ref_filter_data_end" name="data_end" value="<?= e((string) ($filters['data_end'] ?? ($defaultFilters['data_end'] ?? ''))) ?>">
            </div>

            <div class="refacturare-filter-field">
                <label class="form-label" for="ref_filter_vehicles_toggle">Vehicule</label>
                <div class="dropdown vehicle-multiselect-dropdown" data-ref-vehicle-dropdown>
                    <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle" type="button" id="ref_filter_vehicles_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <span class="vehicle-multiselect-label" data-ref-vehicle-label><?= e($selectedVehicleLabel) ?></span>
                    </button>
                    <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="ref_filter_vehicles_toggle">
                        <div class="tcv2-vehicle-menu-search"><input type="search" class="form-control form-control-sm" data-vehicle-menu-search placeholder="Caută vehicul..." aria-label="Caută vehicul"></div>
                        <div class="tcv2-vehicle-menu-empty text-muted small px-2 py-1" hidden>Niciun vehicul găsit.</div>
                        <?php foreach ($vehicleCapacityGroups as $capacityGroup): ?>
                            <?php
                                $capacityGroupHasSelection = false;
                                foreach ($capacityGroup['vehicles'] as $capacityGroupOption) {
                                    if (in_array((int) ($capacityGroupOption['id'] ?? 0), $selectedVehicleIds, true)) {
                                        $capacityGroupHasSelection = true;
                                        break;
                                    }
                                }
                            ?>
                            <div class="tcv2-vehicle-group<?= $capacityGroupHasSelection ? '' : ' is-collapsed' ?>" data-vehicle-group data-group-label="<?= e(mb_strtolower((string) $capacityGroup['label'])) ?>">
                                <div class="tcv2-vehicle-group-head" data-vehicle-group-head>
                                    <input class="form-check-input m-0" type="checkbox" data-vehicle-group-toggle aria-label="Selectează toate vehiculele: <?= e((string) $capacityGroup['label']) ?>">
                                    <span><?= e((string) $capacityGroup['label']) ?></span>
                                    <span class="tcv2-vehicle-group-count"><?= e((string) count($capacityGroup['vehicles'])) ?></span>
                                    <i class="bi bi-chevron-down tcv2-vehicle-group-chevron" aria-hidden="true"></i>
                                </div>
                                <?php foreach ($capacityGroup['vehicles'] as $vehicleOption): ?>
                                    <?php $vehicleOptionId = (int) ($vehicleOption['id'] ?? 0); ?>
                                    <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option">
                                        <input class="form-check-input m-0" type="checkbox" name="vehicle_ids[]" value="<?= e((string) $vehicleOptionId) ?>" <?= in_array($vehicleOptionId, $selectedVehicleIds, true) ? 'checked' : '' ?>>
                                        <span><?= e($vehicleLabelById[$vehicleOptionId] ?? '') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="refacturare-field-help">Poți alege mai multe vehicule sau o capacitate întreagă</div>
            </div>

            <div class="refacturare-filter-field">
                <label class="form-label" for="ref_filter_beneficiary">Beneficiar</label>
                <select class="form-select" id="ref_filter_beneficiary" name="beneficiar_id">
                    <option value="">Toți beneficiarii</option>
                    <?php foreach ($beneficiaryOptions as $beneficiaryOption): ?>
                        <?php $beneficiaryOptionId = (string) ((int) ($beneficiaryOption['id'] ?? 0)); ?>
                        <option value="<?= e($beneficiaryOptionId) ?>" <?= (string) ($filters['beneficiar_id'] ?? '') === $beneficiaryOptionId ? 'selected' : '' ?>>
                            <?= e((string) ($beneficiaryOption['nume'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="refacturare-filter-field">
                <label class="form-label" for="ref_filter_type">Tip refacturare</label>
                <select class="form-select" id="ref_filter_type" name="tip_refacturare">
                    <option value="">Toate tipurile</option>
                    <?php foreach ($expenseEntryTypes as $typeValue => $typeLabel): ?>
                        <option value="<?= e((string) $typeValue) ?>" <?= (string) ($filters['tip_refacturare'] ?? '') === (string) $typeValue ? 'selected' : '' ?>>
                            <?= e((string) $typeLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="refacturare-filter-field">
                <label class="form-label" for="ref_filter_status">Status factură</label>
                <select class="form-select" id="ref_filter_status" name="status_factura">
                    <option value="">Toate statusurile</option>
                    <option value="in_asteptare" <?= (string) ($filters['status_factura'] ?? '') === 'in_asteptare' ? 'selected' : '' ?>>În așteptare</option>
                    <option value="factura_emisa" <?= (string) ($filters['status_factura'] ?? '') === 'factura_emisa' ? 'selected' : '' ?>>Factura emisă</option>
                </select>
            </div>

            <div class="refacturare-filter-field">
                <label class="form-label" for="ref_filter_document">Document</label>
                <select class="form-select" id="ref_filter_document" name="document">
                    <option value="">Toate documentele</option>
                    <option value="cu_document" <?= (string) ($filters['document'] ?? '') === 'cu_document' ? 'selected' : '' ?>>Cu document</option>
                    <option value="fara_document" <?= (string) ($filters['document'] ?? '') === 'fara_document' ? 'selected' : '' ?>>Fără document</option>
                </select>
            </div>

            <div class="refacturare-filter-field refacturare-filter-search">
                <label class="form-label" for="ref_filter_q">Motiv / detalii</label>
                <div class="refacturare-search-control">
                    <input class="form-control" type="search" id="ref_filter_q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Caută după motiv sau detalii...">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
            </div>

            <div class="refacturare-filter-actions">
                <a class="btn refacturare-reset-btn" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari'])) ?>">
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                    <span>Resetează</span>
                </a>
            </div>
        </form>
    </section>

    <section class="refacturare-panel refacturare-history-panel">
        <header class="refacturare-panel-header refacturare-history-header">
            <h2><i class="bi bi-list-ul" aria-hidden="true"></i> Istoric refacturări</h2>
            <div class="refacturare-listed-total">
                Întrări: <strong><?= e((string) ((int) ($summary['total_count'] ?? 0))) ?></strong>
                <span aria-hidden="true">|</span>
                Total listat: <strong><?= e($formatMoney($summary['total_amount'] ?? 0)) ?></strong>
                <?php $missingDocumentCount = (int) ($summary['missing_document_count'] ?? 0); ?>
                <?php if ($missingDocumentCount > 0 && (string) ($filters['document'] ?? '') !== 'fara_document'): ?>
                    <span aria-hidden="true">|</span>
                    <a class="refacturare-missing-doc-link" href="<?= e(build_query_url(array_merge($filterBase, ['document' => 'fara_document', 'p' => 1]))) ?>">
                        <i class="bi bi-paperclip" aria-hidden="true"></i>
                        Fără document: <strong><?= e((string) $missingDocumentCount) ?></strong>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <form
            method="post"
            action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'bulk_refacturari'])) ?>"
            enctype="multipart/form-data"
            id="ref_bulk_form"
            class="refacturare-bulk-bar"
            data-refacturare-bulk-form
            hidden
        >
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
            <input type="hidden" name="bulk_action" value="" data-bulk-action-input>
            <input type="file" name="bulk_document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" data-bulk-document-input hidden>

            <div class="refacturare-bulk-summary">
                <strong data-bulk-count>0 selectate</strong>
                <span data-bulk-amount>0,00 lei</span>
            </div>
            <div class="refacturare-bulk-buttons">
                <button type="button" class="btn btn-sm refacturare-bulk-btn is-invoice" data-bulk-action="invoiced">
                    <i class="bi bi-check-circle" aria-hidden="true"></i> Marchează „Factura emisă”
                </button>
                <button type="button" class="btn btn-sm refacturare-bulk-btn is-revert" data-bulk-action="pending">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Readu în „În așteptare”
                </button>
                <button type="button" class="btn btn-sm refacturare-bulk-btn is-attach" data-bulk-action="attach">
                    <i class="bi bi-paperclip" aria-hidden="true"></i> Atașează document
                </button>
                <button type="button" class="btn btn-sm refacturare-bulk-btn is-clear" data-bulk-clear>
                    Anulează selecția
                </button>
            </div>
        </form>

        <div class="refacturare-table-wrap">
            <table class="table refacturare-table mb-0">
                <colgroup>
                    <col class="ref-col-select">
                    <col class="ref-col-date">
                    <col class="ref-col-race">
                    <col class="ref-col-type">
                    <col class="ref-col-amount">
                    <col class="ref-col-details">
                    <col class="ref-col-status">
                    <col class="ref-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th class="refacturare-select-cell">
                            <input class="form-check-input" type="checkbox" data-bulk-select-all aria-label="Selectează toate refacturările de pe pagină" <?= $historyRows === [] ? 'disabled' : '' ?>>
                        </th>
                        <th>
                            <a class="refacturare-sort-link" href="<?= e($sortUrl('date')) ?>">
                                Data <i class="bi <?= e($sortIcon('date')) ?>" aria-hidden="true"></i>
                            </a>
                        </th>
                        <th>Cursa / Vehicul</th>
                        <th>
                            <a class="refacturare-sort-link" href="<?= e($sortUrl('type')) ?>">
                                Tip <i class="bi <?= e($sortIcon('type')) ?>" aria-hidden="true"></i>
                            </a>
                        </th>
                        <th>
                            <a class="refacturare-sort-link" href="<?= e($sortUrl('amount')) ?>">
                                Suma <i class="bi <?= e($sortIcon('amount')) ?>" aria-hidden="true"></i>
                            </a>
                        </th>
                        <th>Motiv / detalii</th>
                        <th class="refacturare-status-column">Status factură</th>
                        <th class="refacturare-actions-column text-end">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($historyRows === []): ?>
                        <tr>
                            <td colspan="8" class="refacturare-empty-row">Nu există refacturări înregistrate pentru filtrele aplicate.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $historyRow): ?>
                            <?php
                                $historyRaceId = (int) ($historyRow['cursa_id'] ?? 0);
                                $historyExpenseId = (int) ($historyRow['id'] ?? 0);
                                $historyTypeKey = trim((string) ($historyRow['refacturare_tip_cheltuiala'] ?? ''));
                                if ($historyTypeKey === '') {
                                    $historyTypeKey = trim((string) ($historyRow['tip_cheltuiala'] ?? ''));
                                }
                                $historyTypeLabel = (string) ($refacturareTypeLabels[$historyTypeKey] ?? ($expenseTypes[$historyTypeKey] ?? ($historyTypeKey !== '' ? $historyTypeKey : '-')));
                                $historyAmount = (float) ($historyRow['refacturare_suma'] ?? 0);
                                $historyObs = trim((string) ($historyRow['refacturare_observatii'] ?? ''));
                                if ($historyObs === '') {
                                    $historyObs = trim((string) ($historyRow['observatii'] ?? ''));
                                }
                                $historyObsLines = preg_split('/\R/u', $historyObs) ?: [];
                                $historyPrimaryDetail = trim((string) ($historyObsLines[0] ?? ''));
                                $historyIsInvoiced = (int) ($historyRow['refacturare_facturata'] ?? 0) === 1;
                                $historyTaxDetails = json_decode((string) ($historyRow['refacturare_detalii'] ?? ''), true);
                                $historyTaxNotes = [];
                                if (is_array($historyTaxDetails)) {
                                    foreach (['taxa_acces' => 'Taxa acces', 'port' => 'Port', 'trece' => 'Trece'] as $taxKey => $taxLabel) {
                                        $taxRow = $historyTaxDetails[$taxKey] ?? null;
                                        if (!is_array($taxRow)) {
                                            continue;
                                        }
                                        $qty = is_numeric((string) ($taxRow['bucati'] ?? null)) ? (float) $taxRow['bucati'] : 0.0;
                                        $price = is_numeric((string) ($taxRow['pret'] ?? null)) ? (float) $taxRow['pret'] : 0.0;
                                        if ($qty <= 0 || $price <= 0) {
                                            continue;
                                        }
                                        $historyTaxNotes[] = $taxLabel . ': ' . format_number_ro($qty, 2) . ' × ' . format_number_ro($price, 2);
                                    }
                                }
                                // Taxele de drum inregistrate ca randuri separate isi poarta locatia pe rand.
                                $historyLocation = trim((string) (($historyRow['refacturare_locatie'] ?? '') ?: ($historyRow['locatie'] ?? '')));
                                if ($historyLocation !== '') {
                                    $historyPrimaryDetail = $historyLocation;
                                    $historyQty = (float) (($historyRow['refacturare_bucati'] ?? 0) ?: ($historyRow['bucati'] ?? 0));
                                    $historyUnitPrice = (float) (($historyRow['refacturare_pret_unitar'] ?? 0) ?: ($historyRow['pret_unitar'] ?? 0));
                                    if ($historyQty > 0 && $historyUnitPrice > 0) {
                                        $historyTaxNotes[] = format_number_ro($historyQty, 2) . ' buc × ' . format_number_ro($historyUnitPrice, 2);
                                    }
                                }
                                $historySecondaryDetail = $historyTaxNotes !== []
                                    ? implode(' | ', $historyTaxNotes)
                                    : trim(implode(' ', array_slice($historyObsLines, 1)));
                                $historyDate = trim((string) (($historyRow['refacturare_data'] ?? '') !== '' ? $historyRow['refacturare_data'] : ($historyRow['data_cheltuiala'] ?? '')));
                                $historyCreatedAt = trim((string) ($historyRow['created_at'] ?? ''));
                                $plate = trim((string) ($historyRow['nr_inmatriculare'] ?? '-'));
                                $vehicleName = trim((string) (($historyRow['marca'] ?? '') . ' ' . ($historyRow['model'] ?? '')));
                                $driverName = trim((string) ($historyRow['sofer_nume'] ?? ''));
                                $beneficiaryName = trim((string) ($historyRow['beneficiar_nume'] ?? ''));
                                $routeLabel = $buildRouteLabel($historyRow);
                                $departureLabel = $formatDateTimeInline(
                                    (string) (($historyRow['data_inceput'] ?? '') !== '' ? $historyRow['data_inceput'] : ($historyRow['data_cursa'] ?? '')),
                                    (string) ($historyRow['ora_inceput'] ?? '')
                                );
                                $metadataParts = [];
                                foreach ([$vehicleName, $driverName, $beneficiaryName, $routeLabel !== '-' ? $routeLabel : '', 'Plecare: ' . $departureLabel] as $metadataPart) {
                                    $metadataPart = trim($metadataPart);
                                    if ($metadataPart !== '' && $metadataPart !== '-') {
                                        $metadataParts[] = $metadataPart;
                                    }
                                }
                                $metadataLine = implode(' • ', $metadataParts);
                                $historyDocPath = trim((string) ($historyRow['refacturare_document_path'] ?? ''));
                                $historyDocName = trim((string) ($historyRow['refacturare_document_original_name'] ?? ''));
                                $historyDocUrl = $historyDocPath !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($historyDocPath)) : null;
                            ?>
                            <tr data-bulk-row>
                                <td class="refacturare-select-cell">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        form="ref_bulk_form"
                                        name="expense_ids[]"
                                        value="<?= e((string) $historyExpenseId) ?>"
                                        data-bulk-select
                                        data-amount="<?= e((string) $historyAmount) ?>"
                                        data-invoiced="<?= $historyIsInvoiced ? '1' : '0' ?>"
                                        data-has-document="<?= $historyDocUrl !== null ? '1' : '0' ?>"
                                        aria-label="Selectează refacturarea #<?= e((string) $historyExpenseId) ?>"
                                    >
                                </td>
                                <td>
                                    <div class="refacturare-date-main"><?= e(format_date_ro($historyDate)) ?></div>
                                    <?php if ($historyCreatedAt !== ''): ?>
                                        <div class="refacturare-date-sub"><?= e(format_datetime_ro($historyCreatedAt)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="refacturare-race-cell">
                                        <div class="refacturare-race-main">#<?= e((string) $historyRaceId) ?> - <?= e($plate) ?></div>
                                        <div class="refacturare-race-meta" title="<?= e($metadataLine) ?>"><?= e($metadataLine !== '' ? $metadataLine : '-') ?></div>
                                    </div>
                                </td>
                                <td class="refacturare-type-cell"><?= e($historyTypeLabel) ?></td>
                                <td class="refacturare-amount-cell"><?= e($formatMoney($historyAmount)) ?></td>
                                <td>
                                    <div class="refacturare-detail-cell">
                                        <div class="refacturare-detail-main" title="<?= e($historyPrimaryDetail) ?>"><?= e($historyPrimaryDetail !== '' ? $historyPrimaryDetail : '-') ?></div>
                                        <?php if ($historySecondaryDetail !== ''): ?>
                                            <div class="refacturare-detail-sub" title="<?= e($historySecondaryDetail) ?>"><?= e($historySecondaryDetail) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="refacturare-status-cell">
                                    <?php if ($historyIsInvoiced): ?>
                                        <span class="refacturare-status-badge is-invoiced">Factura emisă</span>
                                    <?php else: ?>
                                        <span class="refacturare-status-badge is-pending">În așteptare</span>
                                    <?php endif; ?>
                                    <?php if ($historyDocUrl !== null): ?>
                                        <a class="refacturare-doc-state has-document" href="<?= e($historyDocUrl) ?>" target="_blank" rel="noopener" title="<?= e($historyDocName !== '' ? $historyDocName : 'Document refacturare') ?>">
                                            <i class="bi bi-paperclip" aria-hidden="true"></i> Document atașat
                                        </a>
                                    <?php else: ?>
                                        <span class="refacturare-doc-state is-missing">
                                            <i class="bi bi-exclamation-circle" aria-hidden="true"></i> Fără document
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="refacturare-actions-cell text-end">
                                    <?php
                                        /*
                                         * Cele doua stari se trimit explicit (is_invoiced 1 sau 0), nu prin
                                         * reapasarea aceluiasi buton: altfel "Factura emisa" apasat a doua
                                         * oara anula marcarea, fara ca eticheta sa spuna asta.
                                         */
                                        $invoiceAction = build_query_url(['page' => 'dispecer_curse', 'action' => 'toggle_refacturare_facturata']);
                                        $menuId = 'ref_menu_' . $historyExpenseId;
                                    ?>
                                    <div class="refacturare-actions dropdown">
                                        <button
                                            class="btn refacturare-action-menu"
                                            type="button"
                                            id="<?= e($menuId) ?>"
                                            data-bs-toggle="dropdown"
                                            data-bs-boundary="viewport"
                                            aria-expanded="false"
                                            aria-label="Acțiuni pentru această refacturare"
                                        >
                                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end refacturare-action-list" aria-labelledby="<?= e($menuId) ?>">
                                            <li>
                                                <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $historyRaceId, 'expense_id' => $historyExpenseId])) ?>">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editează
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <?php if ($historyIsInvoiced): ?>
                                                    <span class="dropdown-item is-current" aria-disabled="true">
                                                        <i class="bi bi-check-circle-fill" aria-hidden="true"></i> Factura emisă (stare curentă)
                                                    </span>
                                                <?php else: ?>
                                                    <form method="post" action="<?= e($invoiceAction) ?>" class="refacturare-invoice-form">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="race_id" value="<?= e((string) $historyRaceId) ?>">
                                                        <input type="hidden" name="expense_id" value="<?= e((string) $historyExpenseId) ?>">
                                                        <input type="hidden" name="is_invoiced" value="1">
                                                        <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                                        <button type="submit" class="dropdown-item is-invoice" data-confirm="Confirmi că factura de refacturare a fost emisă?">
                                                            <i class="bi bi-check-circle" aria-hidden="true"></i> Marchează „Factura emisă”
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </li>
                                            <li>
                                                <?php if ($historyIsInvoiced): ?>
                                                    <form method="post" action="<?= e($invoiceAction) ?>" class="refacturare-invoice-form">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="race_id" value="<?= e((string) $historyRaceId) ?>">
                                                        <input type="hidden" name="expense_id" value="<?= e((string) $historyExpenseId) ?>">
                                                        <input type="hidden" name="is_invoiced" value="0">
                                                        <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                                        <button type="submit" class="dropdown-item is-revert" data-confirm="Readuci refacturarea în starea „În așteptare”? Suma revine în Total Refacturare.">
                                                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Readu în „În așteptare”
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="dropdown-item is-current" aria-disabled="true">
                                                        <i class="bi bi-hourglass-split" aria-hidden="true"></i> În așteptare (stare curentă)
                                                    </span>
                                                <?php endif; ?>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <footer class="refacturare-table-footer">
            <form method="get" class="refacturare-page-size-form">
                <?php foreach (array_merge($filterBase, ['p' => 1]) as $key => $value): ?>
                    <?php if ($key === 'per_page'): continue; endif; ?>
                    <?php foreach ((is_array($value) ? $value : [$value]) as $hiddenValue): ?>
                        <input type="hidden" name="<?= e((string) $key . (is_array($value) ? '[]' : '')) ?>" value="<?= e((string) $hiddenValue) ?>">
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <label for="ref_per_page">Afișează</label>
                <select class="form-select form-select-sm" id="ref_per_page" name="per_page" onchange="this.form.submit()">
                    <?php foreach ([10, 25, 50, 100] as $perPageOption): ?>
                        <option value="<?= e((string) $perPageOption) ?>" <?= $perPage === $perPageOption ? 'selected' : '' ?>><?= e((string) $perPageOption) ?></option>
                    <?php endforeach; ?>
                </select>
                <span>din <?= e((string) $totalRows) ?> rezultate</span>
            </form>

            <nav aria-label="Paginare refacturări">
                <ul class="pagination pagination-sm refacturare-pagination mb-0">
                    <li class="page-item <?= $currentPageIndex <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => max(1, $currentPageIndex - 1)]))) ?>" aria-label="Pagina anterioară">
                            <i class="bi bi-chevron-left" aria-hidden="true"></i>
                        </a>
                    </li>
                    <?php for ($p = max(1, $currentPageIndex - 2); $p <= min($totalPages, $currentPageIndex + 2); $p++): ?>
                        <li class="page-item <?= $p === $currentPageIndex ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => $p]))) ?>"><?= e((string) $p) ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $currentPageIndex >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => min($totalPages, $currentPageIndex + 1)]))) ?>" aria-label="Pagina următoare">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </footer>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var formEl = document.querySelector('[data-refacturare-filter-form]');
    if (!(formEl instanceof HTMLFormElement)) {
        return;
    }

    var pageInputEl = formEl.querySelector('[data-refacturare-page-input]');
    var searchInputEl = formEl.querySelector('input[name="q"]');
    var debounceTimer = null;

    var submitFilters = function () {
        if (pageInputEl instanceof HTMLInputElement) {
            pageInputEl.value = '1';
        }
        formEl.classList.add('is-refreshing');
        formEl.submit();
    };

    formEl.querySelectorAll('select, input[type="date"]').forEach(function (controlEl) {
        controlEl.addEventListener('change', submitFilters);
    });

    /*
     * Selector de vehicule (acelasi comportament ca in Configurare transport): cautare,
     * grupe de capacitate pliabile si bifarea unei grupe intregi. Filtrul se aplica la
     * inchiderea listei, doar daca selectia s-a schimbat.
     */
    var vehicleDropdownEl = formEl.querySelector('[data-ref-vehicle-dropdown]');
    if (vehicleDropdownEl instanceof HTMLElement) {
        var vehicleMenuEl = vehicleDropdownEl.querySelector('.vehicle-multiselect-menu');
        var vehicleLabelEl = vehicleDropdownEl.querySelector('[data-ref-vehicle-label]');
        var vehicleSearchEl = vehicleDropdownEl.querySelector('[data-vehicle-menu-search]');
        var vehicleChecks = Array.prototype.slice.call(vehicleDropdownEl.querySelectorAll('input[name="vehicle_ids[]"]'));

        var vehicleSelectionKey = function () {
            return vehicleChecks.filter(function (el) { return el.checked; }).map(function (el) { return el.value; }).join(',');
        };
        var initialVehicleSelection = vehicleSelectionKey();

        var refreshGroupToggle = function (groupEl) {
            var toggleEl = groupEl.querySelector('[data-vehicle-group-toggle]');
            var inputs = Array.prototype.slice.call(groupEl.querySelectorAll('input[name="vehicle_ids[]"]'));
            var checkedCount = inputs.filter(function (el) { return el.checked; }).length;
            toggleEl.checked = inputs.length > 0 && checkedCount === inputs.length;
            toggleEl.indeterminate = checkedCount > 0 && checkedCount < inputs.length;
        };

        var refreshVehicleLabel = function () {
            var selected = vehicleChecks.filter(function (el) { return el.checked; });
            if (selected.length === 0) {
                vehicleLabelEl.textContent = 'Toate vehiculele';
            } else if (selected.length === 1) {
                vehicleLabelEl.textContent = selected[0].closest('label').querySelector('span').textContent.trim();
            } else {
                vehicleLabelEl.textContent = selected.length + ' vehicule selectate';
            }
            vehicleDropdownEl.querySelectorAll('[data-vehicle-group]').forEach(refreshGroupToggle);
        };

        // Spatiile si cratimele se ignora, ca "285 NET" sa gaseasca si "B285NET" / "B-285-NET".
        var normalizeVehicleSearch = function (value) {
            return String(value || '').toLocaleLowerCase('ro-RO').replace(/[\s \-]+/g, '');
        };

        var filterVehicleMenu = function () {
            var query = normalizeVehicleSearch(vehicleSearchEl.value);
            vehicleMenuEl.classList.toggle('is-searching', query !== '');
            var visibleCount = 0;
            vehicleMenuEl.querySelectorAll('[data-vehicle-group]').forEach(function (groupEl) {
                var groupLabelMatches = query !== '' && normalizeVehicleSearch(groupEl.getAttribute('data-group-label')).indexOf(query) !== -1;
                var groupVisible = 0;
                groupEl.querySelectorAll('.vehicle-multiselect-option').forEach(function (optionEl) {
                    var isVisible = query === '' || groupLabelMatches || normalizeVehicleSearch(optionEl.textContent).indexOf(query) !== -1;
                    optionEl.hidden = !isVisible;
                    if (isVisible) {
                        groupVisible += 1;
                    }
                });
                groupEl.hidden = groupVisible === 0;
                visibleCount += groupVisible;
            });
            vehicleMenuEl.querySelector('.tcv2-vehicle-menu-empty').hidden = visibleCount > 0;
        };

        vehicleDropdownEl.addEventListener('change', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }
            if (target.hasAttribute('data-vehicle-group-toggle')) {
                target.closest('[data-vehicle-group]').querySelectorAll('input[name="vehicle_ids[]"]').forEach(function (inputEl) {
                    if (!inputEl.closest('[hidden]')) {
                        inputEl.checked = target.checked;
                    }
                });
            }
            refreshVehicleLabel();
        });

        vehicleDropdownEl.addEventListener('click', function (event) {
            var headEl = event.target instanceof Element ? event.target.closest('[data-vehicle-group-head]') : null;
            if (headEl && !(event.target instanceof HTMLInputElement)) {
                headEl.closest('[data-vehicle-group]').classList.toggle('is-collapsed');
            }
        });

        vehicleSearchEl.addEventListener('input', filterVehicleMenu);
        vehicleSearchEl.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        vehicleDropdownEl.addEventListener('shown.bs.dropdown', function () {
            vehicleSearchEl.focus({ preventScroll: true });
        });

        vehicleDropdownEl.addEventListener('hidden.bs.dropdown', function () {
            if (vehicleSearchEl.value !== '') {
                vehicleSearchEl.value = '';
                filterVehicleMenu();
            }
            if (vehicleSelectionKey() !== initialVehicleSelection) {
                submitFilters();
            }
        });

        refreshVehicleLabel();
    }

    if (searchInputEl instanceof HTMLInputElement) {
        searchInputEl.addEventListener('input', function () {
            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(submitFilters, 400);
        });

        searchInputEl.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(debounceTimer);
                submitFilters();
            }
        });
    }

    /*
     * Selectie multipla: bara de actiuni apare doar cand exista randuri bifate.
     * Actiunea aleasa merge intr-un camp ascuns, nu in butonul de submit, pentru ca
     * butoanele dezactivate la trimitere nu isi mai trimit valoarea.
     */
    var bulkFormEl = document.querySelector('[data-refacturare-bulk-form]');
    if (bulkFormEl instanceof HTMLFormElement) {
        var rowChecks = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-select]'));
        var selectAllEl = document.querySelector('[data-bulk-select-all]');
        var actionInputEl = bulkFormEl.querySelector('[data-bulk-action-input]');
        var documentInputEl = bulkFormEl.querySelector('[data-bulk-document-input]');
        var countEl = bulkFormEl.querySelector('[data-bulk-count]');
        var amountEl = bulkFormEl.querySelector('[data-bulk-amount]');
        var moneyFormatter = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        var selectedChecks = function () {
            return rowChecks.filter(function (checkEl) { return checkEl.checked; });
        };

        var refreshBulkBar = function () {
            var selected = selectedChecks();
            var total = selected.reduce(function (sum, checkEl) {
                return sum + (parseFloat(checkEl.getAttribute('data-amount') || '0') || 0);
            }, 0);

            bulkFormEl.hidden = selected.length === 0;
            countEl.textContent = selected.length + (selected.length === 1 ? ' selectată' : ' selectate');
            amountEl.textContent = moneyFormatter.format(total) + ' lei';

            var countBy = function (attr, value) {
                return selected.filter(function (checkEl) { return checkEl.getAttribute(attr) === value; }).length;
            };
            bulkFormEl.querySelector('[data-bulk-action="invoiced"]').disabled = countBy('data-invoiced', '0') === 0;
            bulkFormEl.querySelector('[data-bulk-action="pending"]').disabled = countBy('data-invoiced', '1') === 0;
            bulkFormEl.querySelector('[data-bulk-action="attach"]').disabled = countBy('data-has-document', '0') === 0;

            rowChecks.forEach(function (checkEl) {
                var rowEl = checkEl.closest('[data-bulk-row]');
                if (rowEl) {
                    rowEl.classList.toggle('is-selected', checkEl.checked);
                }
            });

            if (selectAllEl instanceof HTMLInputElement) {
                selectAllEl.checked = rowChecks.length > 0 && selected.length === rowChecks.length;
                selectAllEl.indeterminate = selected.length > 0 && selected.length < rowChecks.length;
            }
        };

        var submitBulk = function (action) {
            actionInputEl.value = action;
            bulkFormEl.classList.add('is-refreshing');
            bulkFormEl.submit();
        };

        rowChecks.forEach(function (checkEl) {
            checkEl.addEventListener('change', refreshBulkBar);
        });

        if (selectAllEl instanceof HTMLInputElement) {
            selectAllEl.addEventListener('change', function () {
                rowChecks.forEach(function (checkEl) { checkEl.checked = selectAllEl.checked; });
                refreshBulkBar();
            });
        }

        bulkFormEl.querySelector('[data-bulk-clear]').addEventListener('click', function () {
            rowChecks.forEach(function (checkEl) { checkEl.checked = false; });
            refreshBulkBar();
        });

        bulkFormEl.querySelectorAll('[data-bulk-action]').forEach(function (buttonEl) {
            buttonEl.addEventListener('click', function () {
                var action = buttonEl.getAttribute('data-bulk-action');
                var selected = selectedChecks();

                if (action === 'attach') {
                    documentInputEl.value = '';
                    documentInputEl.click();
                    return;
                }

                var targetCount = selected.filter(function (checkEl) {
                    return checkEl.getAttribute('data-invoiced') === (action === 'invoiced' ? '0' : '1');
                }).length;
                var message = action === 'invoiced'
                    ? 'Marchezi „Factura emisă” pentru ' + targetCount + ' refacturări?'
                    : 'Readuci ' + targetCount + ' refacturări în „În așteptare”?';
                var missingDocs = selected.filter(function (checkEl) { return checkEl.getAttribute('data-has-document') === '0'; }).length;
                if (action === 'invoiced' && missingDocs > 0) {
                    message += '\n\nAtenție: ' + missingDocs + ' dintre ele nu au încă documentul de refacturare atașat.';
                }

                if (window.confirm(message)) {
                    submitBulk(action);
                }
            });
        });

        documentInputEl.addEventListener('change', function () {
            if (!documentInputEl.files || documentInputEl.files.length === 0) {
                return;
            }
            var targetCount = selectedChecks().filter(function (checkEl) {
                return checkEl.getAttribute('data-has-document') === '0';
            }).length;
            var message = 'Atașezi „' + documentInputEl.files[0].name + '” la ' + targetCount + ' refacturări fără document?'
                + '\nRefacturările care au deja document nu se modifică.';
            if (window.confirm(message)) {
                submitBulk('attach');
            } else {
                documentInputEl.value = '';
            }
        });

        refreshBulkBar();
    }

    /*
     * Tabelul are overflow: auto, care ar decupa meniul de actiuni. Popper cu
     * strategy "fixed" il scoate din containerul care il taie.
     */
    if (window.bootstrap && window.bootstrap.Dropdown) {
        document.querySelectorAll('.refacturare-action-menu').forEach(function (toggleEl) {
            window.bootstrap.Dropdown.getOrCreateInstance(toggleEl, {
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, { strategy: 'fixed' });
                }
            });
        });
    }
});
</script>
