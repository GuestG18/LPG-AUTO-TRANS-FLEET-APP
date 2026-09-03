<?php
$historyRows = is_array($refacturareRows ?? null) ? $refacturareRows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$defaultFilters = is_array($defaultFilters ?? null) ? $defaultFilters : [];
$summary = is_array($refacturareSummary ?? null) ? $refacturareSummary : [];
$pagination = is_array($pagination ?? null) ? $pagination : [];
$plateOptions = is_array($plateOptions ?? null) ? $plateOptions : [];
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
                <label class="form-label" for="ref_filter_plate">Nr. înmatriculare</label>
                <select class="form-select" id="ref_filter_plate" name="nr_inmatriculare">
                    <option value="">Toate numerele</option>
                    <?php foreach ($plateOptions as $plateOption): ?>
                        <?php
                            $plate = trim((string) ($plateOption['nr_inmatriculare'] ?? ''));
                            if ($plate === '') {
                                continue;
                            }
                            $vehicleName = trim((string) (($plateOption['marca'] ?? '') . ' ' . ($plateOption['model'] ?? '')));
                        ?>
                        <option value="<?= e($plate) ?>" <?= (string) ($filters['nr_inmatriculare'] ?? '') === $plate ? 'selected' : '' ?>>
                            <?= e($plate . ($vehicleName !== '' ? ' - ' . $vehicleName : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="refacturare-field-help">Lista se actualizează automat din cursele disponibile</div>
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
            </div>
        </header>

        <div class="refacturare-table-wrap">
            <table class="table refacturare-table mb-0">
                <colgroup>
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
                            <td colspan="7" class="refacturare-empty-row">Nu există refacturări înregistrate pentru filtrele aplicate.</td>
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
                            ?>
                            <tr>
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
                    <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
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
