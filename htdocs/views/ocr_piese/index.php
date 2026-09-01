<?php
$events = is_array($events ?? null) ? $events : [];
$totals = is_array($totals ?? null) ? $totals : ['piese' => 0.0, 'manopera' => 0.0, 'general' => 0.0];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$filters = is_array($filters ?? null) ? $filters : ['vehicle_id' => 0, 'q' => '', 'date_from' => '', 'date_to' => ''];
$totalCount = (int) ($totalCount ?? 0);
$perPage = (int) ($perPage ?? 10);
$currentPageNo = (int) ($currentPageNo ?? 1);
$expandEventId = (int) ($expandEventId ?? 0);
$totalPages = max(1, (int) ceil($totalCount / max(1, $perPage)));

$intakeUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'intake']);
$sandboxUrl = build_query_url(['page' => 'dev_ocr_test']);
$baseParams = [
    'page' => 'ocr_piese',
    'vehicul' => $filters['vehicle_id'] ?: null,
    'q' => $filters['q'] !== '' ? $filters['q'] : null,
    'de_la' => $filters['date_from'] !== '' ? $filters['date_from'] : null,
    'pana_la' => $filters['date_to'] !== '' ? $filters['date_to'] : null,
    'pe_pagina' => $perPage !== 10 ? $perPage : null,
];
$exportUrl = build_query_url(array_merge($baseParams, ['action' => 'export']));
$ajax = static fn (string $action): string => build_query_url(['page' => 'ocr_piese', 'action' => $action]);

$tipOptions = OcrPartsModel::TIP_LUCRARE_OPTIONS;
$warrantyOptions = OcrPartsModel::WARRANTY_OPTIONS_V2;
?>
<style>
    /* Registru facturi multi-vehicul - stil ERP compact, dupa mockup. */
    .rp-table { font-size: .875rem; }
    .rp-table > thead th {
        white-space: nowrap; font-weight: 600; padding: .65rem .5rem;
        position: sticky; top: 0; z-index: 2; background: #fff;
        box-shadow: inset 0 -1px 0 #dee2e6;
    }
    .rp-table > tbody > tr.rp-parent > td { padding: .7rem .5rem; vertical-align: middle; }
    .rp-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .rp-total-cell { font-weight: 700; }
    .rp-plate {
        display: inline-block; padding: .15rem .55rem; border: 1px solid #ced4da;
        border-radius: .375rem; background: #f8f9fa; font-weight: 600; font-size: .8rem;
    }
    .rp-veh-multi {
        border: 1px solid #9ec5fe; color: #0a58ca; background: #fff; font-weight: 600;
        font-size: .8rem; border-radius: .375rem; padding: .2rem .55rem;
    }
    .rp-vehicle-type { color: #6c757d; font-size: .78rem; margin-top: .15rem; }
    .rp-doc-box { line-height: 1.25; }
    .rp-doc-box .rp-doc { font-weight: 600; }
    .rp-doc-box .rp-supplier { color: #495057; font-size: .82rem; }
    tr.rp-parent.rp-open .rp-doc-wrap {
        border: 1px solid #cfe2ff; border-radius: .5rem; padding: .4rem 1.4rem .4rem .6rem;
        position: relative; background: #fff;
    }
    .rp-doc-pencil { position: absolute; top: .3rem; right: .4rem; color: #0d6efd; cursor: pointer; display: none; }
    tr.rp-parent.rp-open .rp-doc-pencil { display: inline-block; }
    tr.rp-parent .rp-edit { display: none; }
    tr.rp-parent.rp-open .rp-edit { display: inline-block; }
    tr.rp-parent.rp-open .rp-view-toggle { display: none; }
    .rp-input-sm {
        font-size: .82rem; padding: .25rem .45rem; height: auto;
        border: 1px solid #ced4da; border-radius: .375rem;
    }
    .rp-input-sm:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 .15rem rgba(13,110,253,.2); }
    tr.rp-detail > td { padding: 0 .5rem .9rem .5rem; background: #fff; border-top: 0; }
    .rp-detail-shell { border: 1px solid #cfe2ff; border-radius: .6rem; padding: .85rem; background: #fdfdfe; }
    .rp-detail-title { color: #0a58ca; font-weight: 600; font-size: .875rem; margin-bottom: .75rem; }
    .rp-veh-sidebar { width: 13.5rem; flex-shrink: 0; transition: width .15s ease; }
    .rp-veh-sidebar-title {
        font-weight: 600; font-size: .82rem; margin-bottom: .5rem;
        display: flex; align-items: center; justify-content: space-between; gap: .35rem;
    }
    .rp-veh-toggle {
        border: 1px solid #dee2e6; background: #fff; border-radius: .4rem;
        width: 1.55rem; height: 1.55rem; line-height: 1; color: #6c757d;
        display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .rp-veh-toggle:hover { border-color: #86b7fe; color: #0d6efd; }
    /* Sidebar restrans: doar o banda ingusta cu sageata de redeschidere. */
    .rp-veh-sidebar.rp-collapsed { width: 2.1rem; }
    .rp-veh-sidebar.rp-collapsed .rp-veh-title-text,
    .rp-veh-sidebar.rp-collapsed .rp-veh-list,
    .rp-veh-sidebar.rp-collapsed .rp-veh-add-wrap { display: none; }
    .rp-veh-sidebar.rp-collapsed .rp-veh-sidebar-title { justify-content: center; }
    .rp-veh-sidebar.rp-collapsed .rp-veh-collapsed-hint {
        display: block; writing-mode: vertical-rl; text-orientation: mixed;
        color: #6c757d; font-size: .72rem; margin: .5rem auto 0; user-select: none; cursor: pointer;
    }
    .rp-veh-collapsed-hint { display: none; }
    .rp-veh-card {
        border: 1px solid #dee2e6; border-radius: .5rem; padding: .5rem 1.6rem .5rem .65rem;
        margin-bottom: .5rem; cursor: pointer; background: #fff; font-size: .82rem;
        position: relative;
    }
    .rp-veh-card:hover { border-color: #9ec5fe; }
    .rp-veh-card-menu {
        position: absolute; top: .3rem; right: .3rem; border: 0; background: none;
        color: #adb5bd; padding: .05rem .2rem; border-radius: .25rem; line-height: 1; cursor: pointer;
    }
    .rp-veh-card-menu:hover { color: #0d6efd; background: #e7f1ff; }
    /* Dialog compact propriu (confirm() nativ e blocat in browserul embedded). */
    .rp-dialog-overlay {
        position: fixed; inset: 0; background: rgba(33, 37, 41, .35); z-index: 1080;
        display: flex; align-items: center; justify-content: center; padding: 1rem;
    }
    .rp-dialog {
        background: #fff; border-radius: .6rem; box-shadow: 0 1rem 3rem rgba(0,0,0,.25);
        width: 100%; max-width: 27rem; padding: 1rem 1.15rem; font-size: .875rem;
    }
    .rp-dialog-title { font-weight: 700; margin-bottom: .5rem; }
    .rp-dialog-body { margin-bottom: .85rem; color: #495057; }
    .rp-dialog-body .rp-dialog-summary {
        background: #f8f9fa; border: 1px solid #e9ecef; border-radius: .4rem;
        padding: .45rem .65rem; margin-top: .5rem; font-size: .82rem;
    }
    .rp-dialog-actions { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: flex-end; }
    .rp-dialog-actions .btn { font-size: .82rem; }
    .rp-veh-card.rp-selected { border-color: #0d6efd; background: #f0f6ff; box-shadow: 0 0 0 1px #0d6efd inset; }
    .rp-veh-card .rp-plate { margin-bottom: .1rem; }
    .rp-veh-card .rp-km { color: #495057; font-size: .78rem; }
    .rp-item-wrap { flex: 1 1 auto; min-width: 0; }
    .rp-sel-header { display: flex; flex-wrap: wrap; gap: 1rem; align-items: baseline; margin-bottom: .5rem; font-size: .85rem; }
    .rp-sel-header .rp-sel-name { font-weight: 700; }
    .rp-sel-totals { margin-left: auto; display: flex; gap: 1.25rem; }
    .rp-sel-totals strong { font-variant-numeric: tabular-nums; }
    .rp-item-scroll { overflow-x: auto; border: 1px solid #e9ecef; border-radius: .5rem; }
    .rp-item-table { width: 100%; font-size: .8rem; min-width: 1180px; border-collapse: collapse; }
    .rp-item-table th {
        font-weight: 600; color: #495057; padding: .45rem .4rem; white-space: nowrap;
        border-bottom: 1px solid #e9ecef; background: #f8f9fa; position: sticky; top: 0;
    }
    .rp-item-table td { padding: .3rem .4rem; vertical-align: middle; border-bottom: 1px solid #f1f3f5; }
    .rp-item-table input, .rp-item-table select {
        font-size: .8rem; padding: .25rem .4rem;
        border: 1px solid #dee2e6; border-radius: .375rem; background: #fff; width: 100%;
    }
    .rp-item-table input:focus, .rp-item-table select:focus {
        border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 .15rem rgba(13,110,253,.2);
    }
    .rp-item-table input:disabled, .rp-item-table select:disabled { background: #f1f3f5; color: #adb5bd; }
    .rp-item-tip { white-space: nowrap; font-weight: 600; font-size: .78rem; }
    .rp-item-tip .bi-box-seam { color: #198754; }
    .rp-item-tip .bi-wrench-adjustable { color: #0d6efd; }
    .rp-line-total { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600; }
    .rp-warranty select { background: #d1e7dd; border-color: #a3cfbb; color: #146c43; font-weight: 600; }
    .rp-warranty-labor select { background: #cfe2ff; border-color: #9ec5fe; color: #0a58ca; font-weight: 600; }
    .rp-warranty-date input.rp-manual { border-color: #ffc107; background: #fffbea; }
    .rp-dest-stoc select { background: #fff3cd; border-color: #ffda6a; color: #856404; }
    .rp-item-del { border: 0; background: none; color: #adb5bd; padding: .1rem .25rem; cursor: pointer; }
    .rp-item-del:hover { color: #dc3545; }
    .rp-item-del.rp-armed { color: #fff; background: #dc3545; border-radius: .25rem; }
    .rp-item-table select.rp-veh-missing {
        border-color: #dc3545 !important; box-shadow: 0 0 0 .12rem rgba(220,53,69,.15);
    }
    .rp-item-info { color: #adb5bd; }
    .rp-detail-footer {
        display: flex; flex-wrap: wrap; align-items: center; gap: 1.5rem;
        border: 1px solid #e9ecef; border-radius: .5rem; padding: .55rem .85rem;
        margin-top: .75rem; background: #fff; font-size: .85rem;
    }
    .rp-help-strip {
        display: flex; flex-wrap: wrap; gap: 1.5rem; justify-content: space-between;
        background: #f0f6ff; border: 1px solid #cfe2ff; border-radius: .5rem;
        padding: .45rem .85rem; margin-top: .5rem; font-size: .78rem; color: #495057;
    }
    /* Banda de potriviri sub randul-factura cand exista o cautare activa:
       arata direct articolele gasite, fara sa extinzi factura manual. */
    tr.rp-match-row > td {
        background: #fffdf3; border-top: 0; padding: .3rem .5rem .55rem 2.6rem; font-size: .78rem;
    }
    .rp-match-label { color: #856404; font-weight: 600; margin-right: .35rem; }
    .rp-match-chip {
        display: inline-flex; align-items: center; gap: .35rem; margin: .15rem .25rem .15rem 0;
        padding: .2rem .55rem; border: 1px solid #ffe69c; border-radius: 1rem;
        background: #fff; cursor: pointer; font-size: .78rem; color: #212529;
    }
    .rp-match-chip:hover { border-color: #0d6efd; background: #f0f6ff; }
    .rp-match-chip .rp-match-cod { color: #6c757d; }
    .rp-match-chip .rp-match-veh {
        background: #f8f9fa; border: 1px solid #dee2e6; border-radius: .3rem;
        padding: 0 .3rem; font-weight: 600; font-size: .72rem;
    }
    .rp-match-chip .rp-match-val { font-weight: 600; font-variant-numeric: tabular-nums; }
    .rp-match-more { color: #856404; font-size: .78rem; margin-left: .25rem; }
    @keyframes rp-item-flash-kf { 0% { background: #ffe69c; } 100% { background: transparent; } }
    tr.rp-item-flash td { animation: rp-item-flash-kf 1.8s ease-out; }
    .rp-expander { border: 1px solid #dee2e6; background: #fff; border-radius: .4rem; width: 1.8rem; height: 1.8rem; line-height: 1; }
    .rp-expander:hover { border-color: #86b7fe; color: #0d6efd; }
    .rp-summary { font-size: .85rem; color: #6c757d; }
    .rp-summary strong { color: #212529; }
    .rp-action-menu {
        position: fixed; z-index: 1060; min-width: 12rem;
        background: #fff; border: 1px solid rgba(0,0,0,.15); border-radius: .375rem;
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); padding: .25rem 0; font-size: .875rem;
    }
    .rp-action-menu .rp-menu-item {
        display: flex; align-items: center; gap: .5rem; width: 100%;
        padding: .35rem .85rem; border: 0; background: none; text-align: left;
        color: #212529; text-decoration: none; cursor: pointer;
    }
    .rp-action-menu .rp-menu-item:hover { background: #f8f9fa; }
    .rp-action-menu .rp-menu-sep { border-top: 1px solid #dee2e6; margin: .25rem 0; }
    .rp-obs-input { border: 0; border-bottom: 1px dashed #ced4da; border-radius: 0; padding: 0 .2rem; min-width: 8rem; font-size: .85rem; }
    .rp-obs-input:focus { outline: 0; border-bottom-color: #0d6efd; box-shadow: none; }
    @media (max-width: 991.98px) { .rp-detail-flex { flex-direction: column; } .rp-veh-sidebar { width: 100%; } }
</style>

<div class="mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <h1 class="h5 mb-0"><i class="bi bi-table me-1" aria-hidden="true"></i>Registru piese &amp; lucrări</h1>
        <span class="badge text-bg-light border text-primary fw-semibold">SANDBOX OCR</span>
    </div>
    <div class="text-muted small mt-1">Reparații / Înlocuiri / Îmbunătățiri per vehicul — click pe orice celulă pentru editare, ca în Excel.</div>
    <div class="d-flex gap-2 flex-wrap mt-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($sandboxUrl) ?>">Sandbox OCR</a>
        <button type="button" class="btn btn-sm btn-outline-primary" id="rp-add-event"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adaugă rând</button>
        <a class="btn btn-sm btn-primary" href="<?= e($intakeUrl) ?>"><i class="bi bi-magic me-1" aria-hidden="true"></i>Recepție factură (OCR)</a>
    </div>
</div>

<form method="get" action="<?= e(url('index.php')) ?>" id="rp-filter-form">
    <input type="hidden" name="page" value="ocr_piese">
    <?php if ($perPage !== 10): ?>
        <input type="hidden" name="pe_pagina" value="<?= (int) $perPage ?>">
    <?php endif; ?>
    <div class="row g-2 mb-3 align-items-end">
        <div class="col-6 col-lg-2">
            <label class="form-label small mb-1" for="rp-f-vehicul">Vehicul</label>
            <select class="form-select form-select-sm" id="rp-f-vehicul" name="vehicul">
                <option value="">Toate vehiculele</option>
                <?php foreach ($vehicles as $vehicle): ?>
                    <option value="<?= (int) $vehicle['id'] ?>" <?= (int) $vehicle['id'] === (int) $filters['vehicle_id'] ? 'selected' : '' ?>>
                        <?= e((string) $vehicle['nr_inmatriculare']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-lg-3">
            <label class="form-label small mb-1" for="rp-f-q">Caută în registru</label>
            <input type="search" class="form-control form-control-sm" id="rp-f-q" name="q"
                   value="<?= e((string) $filters['q']) ?>" placeholder="piesă, furnizor, factură, lucrare...">
        </div>
        <div class="col-12 col-lg-3">
            <label class="form-label small mb-1">Perioadă</label>
            <div class="input-group input-group-sm">
                <input type="date" class="form-control" name="de_la" value="<?= e((string) $filters['date_from']) ?>">
                <span class="input-group-text">-</span>
                <input type="date" class="form-control" name="pana_la" value="<?= e((string) $filters['date_to']) ?>">
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="d-flex flex-wrap gap-3 justify-content-lg-end align-items-center rp-summary">
                <span>Rânduri: <strong id="rp-sum-count"><?= e((string) $totalCount) ?></strong></span>
                <span>Total piese: <strong id="rp-sum-piese"><?= e(format_number_ro($totals['piese'])) ?> lei</strong></span>
                <span>Total manoperă: <strong id="rp-sum-manopera"><?= e(format_number_ro($totals['manopera'])) ?> lei</strong></span>
                <span>Total general: <strong id="rp-sum-general"><?= e(format_number_ro($totals['general'])) ?> lei</strong></span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e($exportUrl) ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Export CSV</a>
            </div>
        </div>
    </div>
</form>

<div id="rp-error" class="alert alert-danger d-none py-2"></div>

<?php
// Mod "rezultate cautare": la cautare activa, in loc de facturi extensibile
// afisam o lista plata - un rand per articol gasit - care scaleaza oricat.
$searchMode = $filters['q'] !== '';
$searchRows = [];
if ($searchMode) {
    $needle = $filters['q'];
    foreach ($events as $event) {
        $matched = array_values(array_filter($event['articole'] ?? [], static function (array $a) use ($needle): bool {
            foreach ([(string) $a['denumire'], (string) ($a['cod_piesa'] ?? ''), (string) ($a['vehicul'] ?? '')] as $haystack) {
                if ($haystack !== '' && mb_stripos($haystack, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }));
        if ($matched === []) {
            // Factura s-a potrivit doar pe antet (furnizor / document / observatii).
            $searchRows[] = ['event' => $event, 'item' => null];
        } else {
            foreach ($matched as $item) {
                $searchRows[] = ['event' => $event, 'item' => $item];
            }
        }
    }
}
?>

<div class="card">
<?php if ($searchMode): ?>
    <div class="px-3 pt-2 pb-1 small text-muted border-bottom">
        <i class="bi bi-search me-1" aria-hidden="true"></i>
        <strong><?= count(array_filter($searchRows, static fn (array $r): bool => $r['item'] !== null)) ?></strong> articole găsite
        în <strong><?= count($events) ?></strong> facturi pentru „<?= e($filters['q']) ?>" —
        click pe un rezultat pentru a deschide factura la articolul respectiv.
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover rp-table mb-0">
            <thead>
                <tr>
                    <th style="width:9%">Tip</th>
                    <th style="width:28%">Articol</th>
                    <th style="width:22%">Factură / Furnizor</th>
                    <th style="width:11%">Vehicul</th>
                    <th style="width:9%">Data</th>
                    <th style="width:10%">Tip lucrare</th>
                    <th style="width:9%" class="rp-num">Valoare (lei)</th>
                    <th style="width:2%"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($searchRows as $result): ?>
                <?php
                $event = $result['event'];
                $item = $result['item'];
                $openUrl = build_query_url([
                    'page' => 'ocr_piese',
                    'deschide' => (int) $event['id'],
                    'articol' => $item !== null ? (int) $item['id'] : null,
                ]);
                ?>
                <tr data-open-url="<?= e($openUrl) ?>" role="button" title="Deschide factura la acest articol">
                    <td class="rp-item-tip">
                        <?php if ($item === null): ?>
                            <i class="bi bi-receipt text-secondary me-1" aria-hidden="true"></i>Factură
                        <?php elseif ($item['tip'] === 'manopera'): ?>
                            <i class="bi bi-wrench-adjustable text-primary me-1" aria-hidden="true"></i>Manoperă
                        <?php else: ?>
                            <i class="bi bi-box-seam text-success me-1" aria-hidden="true"></i>Piesă
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($item === null): ?>
                            <span class="text-muted">(potrivire pe datele facturii)</span>
                        <?php else: ?>
                            <?= e((string) $item['denumire']) ?>
                            <?php if (($item['cod_piesa'] ?? '') !== ''): ?>
                                <span class="text-muted small">[<?= e((string) $item['cod_piesa']) ?>]</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e((string) (($event['document'] ?? '') !== '' ? $event['document'] : '—')) ?></div>
                        <div class="text-muted small"><?= e((string) ($event['furnizor'] ?? '')) ?></div>
                    </td>
                    <td>
                        <?php if ($item !== null && ($item['vehicul'] ?? '') !== ''): ?>
                            <span class="rp-plate"><?= e((string) $item['vehicul']) ?></span>
                        <?php elseif ($item !== null && $item['destinatie'] === 'stoc'): ?>
                            <span class="badge text-bg-warning text-dark">Stoc</span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_date_ro($event['data_interventie'] ?? null)) ?></td>
                    <td>
                        <?php if ($item !== null): ?>
                            <?= e($tipOptions[(string) $item['tip_lucrare']] ?? (string) $item['tip_lucrare']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="rp-num fw-semibold">
                        <?= $item !== null
                            ? e(format_number_ro((float) $item['cantitate'] * (float) $item['pret_unitar']))
                            : e(format_number_ro((float) ($event['total_piese'] ?? 0) + (float) ($event['total_manopera'] ?? 0))) ?>
                    </td>
                    <td class="text-muted"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($searchRows === []): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Niciun rezultat pentru „<?= e($filters['q']) ?>".</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover rp-table mb-0" id="rp-table">
            <thead>
                <tr>
                    <th style="width:3%"></th>
                    <th style="width:12%">Vehicule pe factură</th>
                    <th style="width:10%">Data</th>
                    <th style="width:22%">Document / Furnizor</th>
                    <th style="width:11%" class="rp-num">Piese (lei)</th>
                    <th style="width:11%" class="rp-num">Manoperă (lei)</th>
                    <th style="width:11%" class="rp-num">KM total</th>
                    <th style="width:11%" class="rp-num">Total (lei)</th>
                    <th style="width:6%" class="text-center">Acțiuni</th>
                </tr>
            </thead>
            <tbody id="rp-body">
            <?php foreach ($events as $event): ?>
                <?php
                $eventId = (int) $event['id'];
                $eventPiese = (float) ($event['total_piese'] ?? 0);
                $eventManopera = (float) ($event['total_manopera'] ?? 0);
                $eventVehicles = $event['vehicule'] ?? [];

                // KM afisat pe parinte: kilometrajul e citire de bord, nu se aduna.
                // Un singur vehicul -> KM-ul maxim al articolelor lui; altfel "-".
                $kmDisplay = null;
                if (count($eventVehicles) === 1) {
                    foreach ($event['articole'] ?? [] as $item) {
                        if ($item['km_bord'] !== null) {
                            $kmDisplay = max((int) ($kmDisplay ?? 0), (int) $item['km_bord']);
                        }
                    }
                }

                $childJson = json_encode([
                    'articole' => array_map(static fn (array $a): array => [
                        'id' => (int) $a['id'],
                        'tip' => (string) $a['tip'],
                        'denumire' => (string) $a['denumire'],
                        'cod_piesa' => (string) ($a['cod_piesa'] ?? ''),
                        'cantitate' => (float) $a['cantitate'],
                        'pret_unitar' => (float) $a['pret_unitar'],
                        'tip_lucrare' => (string) $a['tip_lucrare'],
                        'garantie_luni' => $a['garantie_luni'] !== null ? (int) $a['garantie_luni'] : null,
                        'garantie_pana_la' => $a['garantie_pana_la'],
                        'garantie_manuala' => (bool) $a['garantie_manuala'],
                        'destinatie' => (string) $a['destinatie'],
                        'vehicle_id' => $a['vehicle_id'] !== null ? (int) $a['vehicle_id'] : null,
                        'data_referinta' => $a['data_referinta'],
                        'km_bord' => $a['km_bord'] !== null ? (int) $a['km_bord'] : null,
                        'depozit' => (string) ($a['depozit'] ?? ''),
                        'cant_alocata' => $a['cant_alocata'] !== null ? (float) $a['cant_alocata'] : null,
                    ], $event['articole'] ?? []),
                    'vehicule' => array_map(static fn (array $v): array => [
                        'id' => (int) $v['vehicle_id'],
                        'nr' => (string) $v['nr_inmatriculare'],
                        'tip' => vehicle_type_label((string) ($v['tip_vehicul'] ?? '')),
                    ], $eventVehicles),
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                <tr class="rp-parent" data-event-id="<?= $eventId ?>"
                    data-children="<?= e((string) $childJson) ?>"
                    data-observatii="<?= e((string) ($event['observatii'] ?? '')) ?>"
                    <?php if (!empty($event['factura_fisier'])): ?>
                        data-invoice-url="<?= e(url('uploads/ocr_piese/' . rawurlencode((string) $event['factura_fisier']))) ?>"
                        data-invoice-label="<?= e((string) (($event['numar_factura'] ?? '') !== '' ? $event['numar_factura'] : 'fișier')) ?>"
                    <?php endif; ?>>
                    <td>
                        <button type="button" class="rp-expander" title="Extinde / restrânge" aria-expanded="false">
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </button>
                    </td>
                    <td class="rp-veh-cell">
                        <?php if (count($eventVehicles) === 0): ?>
                            <span class="text-muted">—</span>
                        <?php elseif (count($eventVehicles) === 1): ?>
                            <span class="rp-plate"><?= e((string) $eventVehicles[0]['nr_inmatriculare']) ?></span>
                            <div class="rp-vehicle-type"><?= e(vehicle_type_label((string) ($eventVehicles[0]['tip_vehicul'] ?? ''))) ?></div>
                        <?php else: ?>
                            <button type="button" class="rp-veh-multi rp-veh-multi-btn">
                                <?= count($eventVehicles) ?> vehicule <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="rp-view-toggle"><?= e(format_date_ro($event['data_interventie'] ?? null)) ?></span>
                        <input type="date" class="rp-input-sm rp-edit rp-parent-field" data-field="data_interventie"
                               value="<?= e((string) ($event['data_interventie'] ?? '')) ?>">
                    </td>
                    <td>
                        <div class="rp-doc-wrap">
                            <div class="rp-doc-box rp-doc-view">
                                <div class="rp-doc"><?= e((string) (($event['document'] ?? '') !== '' ? $event['document'] : '—')) ?></div>
                                <div class="rp-supplier"><?= e((string) ($event['furnizor'] ?? '')) ?></div>
                            </div>
                            <div class="rp-doc-editors d-none">
                                <input type="text" class="rp-input-sm w-100 mb-1 rp-parent-field" data-field="document" maxlength="120"
                                       placeholder="Document / factură" value="<?= e((string) ($event['document'] ?? '')) ?>">
                                <input type="text" class="rp-input-sm w-100 rp-parent-field" data-field="furnizor" maxlength="190"
                                       placeholder="Furnizor" value="<?= e((string) ($event['furnizor'] ?? '')) ?>">
                            </div>
                            <i class="bi bi-pencil rp-doc-pencil" title="Editează document / furnizor"></i>
                        </div>
                    </td>
                    <td class="rp-num rp-cell-piese"><?= e(format_number_ro($eventPiese)) ?></td>
                    <td class="rp-num rp-cell-manopera"><?= e(format_number_ro($eventManopera)) ?></td>
                    <td class="rp-num rp-cell-km" title="Kilometrajul este citire de bord — la mai multe vehicule vezi panoul extins">
                        <?= $kmDisplay !== null ? e(number_format((float) $kmDisplay, 0, ',', '.')) : '—' ?>
                    </td>
                    <td class="rp-num rp-total-cell rp-cell-general"><?= e(format_number_ro($eventPiese + $eventManopera)) ?></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-light border py-0 px-1 rp-menu-btn" title="Acțiuni">
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($events === []): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
            Nicio înregistrare<?= $filters['vehicle_id'] || $filters['date_from'] !== '' ? ' pentru filtrele curente' : '' ?>.<br>
            Adaugă un rând manual sau folosește <strong>Recepție factură (OCR)</strong>.
        </div>
    <?php endif; ?>
<?php endif; ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top">
        <div class="d-flex align-items-center gap-2 small text-muted">
            <span>Afișare</span>
            <select class="form-select form-select-sm" style="width:auto" id="rp-per-page">
                <?php foreach ([10, 25, 50, 100] as $size): ?>
                    <option value="<?= $size ?>" <?= $size === $perPage ? 'selected' : '' ?>><?= $size ?></option>
                <?php endforeach; ?>
            </select>
            <span>din <?= e((string) $totalCount) ?> înregistrări</span>
        </div>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $currentPageNo <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(build_query_url(array_merge($baseParams, ['pg' => max(1, $currentPageNo - 1)]))) ?>" aria-label="Anterior">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    </a>
                </li>
                <li class="page-item active"><span class="page-link"><?= e((string) $currentPageNo) ?></span></li>
                <li class="page-item <?= $currentPageNo >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(build_query_url(array_merge($baseParams, ['pg' => min($totalPages, $currentPageNo + 1)]))) ?>" aria-label="Următor">
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</div>

<form class="d-none" id="rp-token-form"><?= csrf_field() ?></form>

<script>
(function () {
    'use strict';

    var URLS = {
        eventAdd: <?= json_encode($ajax('event_add'), JSON_UNESCAPED_SLASHES) ?>,
        eventUpdate: <?= json_encode($ajax('event_update'), JSON_UNESCAPED_SLASHES) ?>,
        eventDelete: <?= json_encode($ajax('event_delete'), JSON_UNESCAPED_SLASHES) ?>,
        itemAdd: <?= json_encode($ajax('item_add'), JSON_UNESCAPED_SLASHES) ?>,
        itemUpdate: <?= json_encode($ajax('item_update'), JSON_UNESCAPED_SLASHES) ?>,
        itemDelete: <?= json_encode($ajax('item_delete'), JSON_UNESCAPED_SLASHES) ?>,
        vehicleAdd: <?= json_encode($ajax('vehicle_add'), JSON_UNESCAPED_SLASHES) ?>,
        vehicleRemove: <?= json_encode($ajax('vehicle_remove'), JSON_UNESCAPED_SLASHES) ?>
    };
    var SELECTED_VEHICLE = <?= (int) $filters['vehicle_id'] ?>;
    var EXPAND_EVENT = <?= $expandEventId ?>;
    var EXPAND_ITEM = <?= (int) ($expandItemId ?? 0) ?>;
    var WARRANTY_OPTIONS = <?= json_encode($warrantyOptions) ?>;
    var TIP_LUCRARE = <?= json_encode($tipOptions, JSON_UNESCAPED_UNICODE) ?>;
    var ALL_VEHICLES = <?= json_encode(array_map(
        static fn (array $v): array => ['id' => (int) $v['id'], 'nr' => (string) $v['nr_inmatriculare']],
        $vehicles
    ), JSON_UNESCAPED_UNICODE) ?>;
    var STOC_TAB = 'stoc';

    var errorBox = document.getElementById('rp-error');
    var pageTotals = {
        piese: <?= json_encode((float) $totals['piese']) ?>,
        manopera: <?= json_encode((float) $totals['manopera']) ?>
    };

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
        setTimeout(function () { errorBox.classList.add('d-none'); }, 6000);
    }

    function csrfToken() {
        var input = document.querySelector('#rp-token-form input[name="_token"]');
        return input ? input.value : '';
    }

    function postForm(url, data) {
        var formData = new FormData();
        formData.append('_token', csrfToken());
        Object.keys(data).forEach(function (key) {
            if (data[key] !== undefined && data[key] !== null) { formData.append(key, data[key]); }
        });
        return fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Răspuns neașteptat de la server (HTTP ' + response.status + ').');
                });
            })
            .then(function (payload) {
                if (!payload.ok) { throw new Error(payload.error || 'Operațiunea a eșuat.'); }
                return payload;
            });
    }

    function fmt(value) {
        return Number(value || 0).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtInt(value) { return Number(value || 0).toLocaleString('ro-RO'); }
    function dateRo(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? m[3] + '.' + m[2] + '.' + m[1] : '—';
    }

    // Starea per factura extinsa: articolele (mutabile) + tab-ul selectat.
    var state = {};

    function eventState(parentTr) {
        var id = parentTr.dataset.eventId;
        if (!state[id]) {
            var data = JSON.parse(parentTr.dataset.children || '{"articole":[],"vehicule":[]}');
            state[id] = { items: data.articole, vehicles: data.vehicule, selected: null };
        }
        return state[id];
    }

    function vehicleName(vehicleId) {
        var found = ALL_VEHICLES.filter(function (v) { return v.id === vehicleId; });
        return found.length ? found[0].nr : '—';
    }

    function hasStocTab(st) {
        return st.items.some(function (item) { return item.vehicle_id === null || item.destinatie === 'stoc'; });
    }

    function itemsForTab(st) {
        if (st.selected === STOC_TAB) {
            return st.items.filter(function (item) { return item.vehicle_id === null || item.destinatie === 'stoc'; });
        }
        return st.items.filter(function (item) { return item.vehicle_id === st.selected && item.destinatie !== 'stoc'; });
    }

    function vehicleKm(st, vehicleId) {
        var km = null;
        st.items.forEach(function (item) {
            if (item.vehicle_id === vehicleId && item.km_bord !== null) { km = Math.max(km || 0, item.km_bord); }
        });
        return km;
    }

    function vehicleTotals(items) {
        var piese = 0, manopera = 0;
        items.forEach(function (item) {
            var total = (item.cantitate || 0) * (item.pret_unitar || 0);
            if (item.tip === 'manopera') { manopera += total; } else { piese += total; }
        });
        return { piese: piese, manopera: manopera, general: piese + manopera };
    }

    // --- Totaluri factura + sumar pagina ---
    function applyEventTotals(parentTr, totals) {
        if (!totals) { return; }
        var oldPiese = parseFloat(parentTr.dataset.totalPiese || '0');
        var oldManopera = parseFloat(parentTr.dataset.totalManopera || '0');
        parentTr.dataset.totalPiese = totals.piese;
        parentTr.dataset.totalManopera = totals.manopera;
        parentTr.querySelector('.rp-cell-piese').textContent = fmt(totals.piese);
        parentTr.querySelector('.rp-cell-manopera').textContent = fmt(totals.manopera);
        parentTr.querySelector('.rp-cell-general').textContent = fmt(totals.general);

        pageTotals.piese += totals.piese - oldPiese;
        pageTotals.manopera += totals.manopera - oldManopera;
        document.getElementById('rp-sum-piese').textContent = fmt(pageTotals.piese) + ' lei';
        document.getElementById('rp-sum-manopera').textContent = fmt(pageTotals.manopera) + ' lei';
        document.getElementById('rp-sum-general').textContent = fmt(pageTotals.piese + pageTotals.manopera) + ' lei';
    }

    // --- Panoul extins ---
    function getDetailRow(parentTr) {
        // Banda de potriviri (cautare activa) sta intre parinte si panoul extins.
        var next = parentTr.nextElementSibling;
        if (next && next.classList.contains('rp-match-row')) { next = next.nextElementSibling; }
        return next && next.classList.contains('rp-detail') ? next : null;
    }

    function detailAnchor(parentTr) {
        var next = parentTr.nextElementSibling;
        return next && next.classList.contains('rp-match-row') ? next : parentTr;
    }

    function selInput(options, value, extraClass) {
        var select = document.createElement('select');
        if (extraClass) { select.className = extraClass; }
        options.forEach(function (opt) {
            var option = document.createElement('option');
            option.value = opt[0];
            option.textContent = opt[1];
            if (String(opt[0]) === String(value)) { option.selected = true; }
            select.appendChild(option);
        });
        return select;
    }

    function buildItemRow(parentTr, item) {
        var st = eventState(parentTr);
        var tr = document.createElement('tr');
        tr.dataset.itemId = item.id;
        var isLabor = item.tip === 'manopera';

        function td(child, cls) {
            var cell = document.createElement('td');
            if (cls) { cell.className = cls; }
            if (child) { cell.appendChild(child); }
            return cell;
        }
        function saveField(input, field, onDone) {
            postForm(URLS.itemUpdate, { item_id: item.id, field: field, value: input.value })
                .then(function (payload) {
                    item[field] = input.type === 'number' && payload.value !== null ? parseFloat(payload.value)
                        : (payload.value === null ? null : payload.value);
                    if (field === 'vehicle_id') { item.vehicle_id = payload.value !== null ? parseInt(payload.value, 10) : null; }
                    if (field === 'km_bord') { item.km_bord = payload.value !== null ? parseInt(payload.value, 10) : null; }
                    if (field === 'garantie_luni') { item.garantie_luni = payload.value !== null ? parseInt(payload.value, 10) : null; }
                    item.garantie_pana_la = payload.garantie_pana_la;
                    item.garantie_manuala = payload.garantie_manuala;
                    warrantyDateInput.value = item.garantie_pana_la || '';
                    warrantyDateInput.classList.toggle('rp-manual', item.garantie_manuala);
                    applyEventTotals(parentTr, payload.totals);
                    if (onDone) { onDone(payload); }
                })
                .catch(function (error) { showError(field + ': ' + error.message); });
        }

        // 1. Tip
        var tipCell = document.createElement('td');
        tipCell.className = 'rp-item-tip';
        tipCell.innerHTML = isLabor
            ? '<i class="bi bi-wrench-adjustable me-1" aria-hidden="true"></i>Manoperă'
            : '<i class="bi bi-box-seam me-1" aria-hidden="true"></i>Piesă';
        tr.appendChild(tipCell);

        // 2. Denumire
        var nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.maxLength = 255;
        nameInput.placeholder = isLabor ? 'Denumire lucrare...' : 'Denumire piesă...';
        nameInput.value = item.denumire || '';
        nameInput.style.minWidth = '11rem';
        tr.appendChild(td(nameInput));

        // 3. Cod
        var codeInput = document.createElement('input');
        codeInput.type = 'text';
        codeInput.maxLength = 80;
        if (isLabor) { codeInput.disabled = true; codeInput.placeholder = '--'; }
        else { codeInput.placeholder = 'Cod piesă...'; codeInput.value = item.cod_piesa || ''; }
        tr.appendChild(td(codeInput));

        // 4. Cant. / Norma
        var qtyInput = document.createElement('input');
        qtyInput.type = 'number';
        qtyInput.min = '0';
        qtyInput.step = isLabor ? '0.25' : '1';
        qtyInput.value = item.cantitate;
        qtyInput.style.width = '4.2rem';
        qtyInput.title = isLabor ? 'Normă (h)' : 'Cantitate';
        tr.appendChild(td(qtyInput, 'rp-num'));

        // 5. Pret unitar / Pret pe ora
        var priceInput = document.createElement('input');
        priceInput.type = 'number';
        priceInput.min = '0';
        priceInput.step = '0.01';
        priceInput.value = Number(item.pret_unitar || 0).toFixed(2);
        priceInput.style.width = '5.5rem';
        priceInput.title = isLabor ? 'Preț / h' : 'Preț unitar';
        tr.appendChild(td(priceInput, 'rp-num'));

        // 6. Total
        var totalCell = document.createElement('td');
        totalCell.className = 'rp-line-total';
        tr.appendChild(totalCell);
        function refreshLineTotal() {
            totalCell.textContent = fmt((parseFloat(qtyInput.value) || 0) * (parseFloat(priceInput.value) || 0));
        }
        refreshLineTotal();

        // 7. Tip lucrare (pe articol, nu pe factura)
        var tlOptions = Object.keys(TIP_LUCRARE).map(function (key) { return [key, TIP_LUCRARE[key]]; });
        var tlSelect = selInput(tlOptions, item.tip_lucrare);
        tr.appendChild(td(tlSelect));

        // 8. Garantie (durata)
        var wOptions = [['', '--']].concat(WARRANTY_OPTIONS.map(function (m) { return [m, m + ' luni']; }));
        var warrantySelect = selInput(wOptions, item.garantie_luni === null ? '' : item.garantie_luni);
        tr.appendChild(td(warrantySelect, isLabor ? 'rp-warranty-labor' : 'rp-warranty'));

        // 9. Garantie pana la (calculata automat, calendar pentru corectie manuala)
        var warrantyDateInput = document.createElement('input');
        warrantyDateInput.type = 'date';
        warrantyDateInput.value = item.garantie_pana_la || '';
        warrantyDateInput.classList.toggle('rp-manual', !!item.garantie_manuala);
        warrantyDateInput.title = 'Calculată automat din data montării/recepției; alege manual pentru corecție';
        tr.appendChild(td(warrantyDateInput, 'rp-warranty-date'));

        // 10. Destinatie
        var destSelect = selInput([['vehicul', 'Montează pe vehicul'], ['stoc', 'Trimite în stoc']], item.destinatie);
        if (isLabor) { destSelect.disabled = true; destSelect.value = 'vehicul'; }
        var destCell = td(destSelect);
        if (item.destinatie === 'stoc') { destCell.className = 'rp-dest-stoc'; }
        tr.appendChild(destCell);

        // 11. Vehicul — toata flota, cu vehiculele facturii primele; alegerea unui
        // vehicul care nu e pe factura il asociaza automat (serverul garanteaza).
        var vehSelect = document.createElement('select');
        vehSelect.className = 'rp-veh-select';
        var emptyVehOpt = document.createElement('option');
        emptyVehOpt.value = '';
        emptyVehOpt.textContent = '—';
        vehSelect.appendChild(emptyVehOpt);
        var onInvoiceIds = st.vehicles.map(function (v) { return v.id; });
        var groupOn = document.createElement('optgroup');
        groupOn.label = 'Pe factură';
        var groupOther = document.createElement('optgroup');
        groupOther.label = 'Alte vehicule';
        ALL_VEHICLES.forEach(function (vehicle) {
            var option = document.createElement('option');
            option.value = vehicle.id;
            option.textContent = vehicle.nr;
            if (String(vehicle.id) === String(item.vehicle_id)) { option.selected = true; }
            (onInvoiceIds.indexOf(vehicle.id) !== -1 ? groupOn : groupOther).appendChild(option);
        });
        if (groupOn.children.length) { vehSelect.appendChild(groupOn); }
        if (groupOther.children.length) { vehSelect.appendChild(groupOther); }
        if (item.destinatie === 'stoc') { vehSelect.disabled = true; }
        // Piesa "montata" dar fara vehicul ales: evidentiem campul obligatoriu.
        if (!isLabor && item.destinatie === 'vehicul' && item.vehicle_id === null) {
            vehSelect.classList.add('rp-veh-missing');
        }
        tr.appendChild(td(vehSelect));

        // 12. Data montarii / receptiei
        var dateRefInput = document.createElement('input');
        dateRefInput.type = 'date';
        dateRefInput.value = item.data_referinta || '';
        tr.appendChild(td(dateRefInput));

        // 13. KM bord / Depozit (contextual dupa destinatie)
        var kmDepCell = document.createElement('td');
        function buildKmDep() {
            kmDepCell.innerHTML = '';
            if (item.destinatie === 'stoc' && !isLabor) {
                var depInput = document.createElement('input');
                depInput.type = 'text';
                depInput.maxLength = 120;
                depInput.placeholder = 'Depozit principal';
                depInput.value = item.depozit || '';
                depInput.addEventListener('change', function () { saveField(depInput, 'depozit'); });
                kmDepCell.appendChild(depInput);
            } else {
                var kmInput = document.createElement('input');
                kmInput.type = 'number';
                kmInput.min = '0';
                kmInput.step = '1';
                kmInput.placeholder = 'KM bord';
                if (item.km_bord !== null) { kmInput.value = item.km_bord; }
                kmInput.addEventListener('change', function () {
                    saveField(kmInput, 'km_bord', function () { renderSidebar(parentTr); });
                });
                kmDepCell.appendChild(kmInput);
            }
        }
        buildKmDep();
        tr.appendChild(kmDepCell);

        // 14. Cant. montata / primita (alocare partiala)
        var allocInput = document.createElement('input');
        allocInput.type = 'number';
        allocInput.min = '0';
        allocInput.step = '0.01';
        allocInput.style.width = '4.5rem';
        allocInput.title = 'Cantitate montată / primită (pentru alocare parțială)';
        if (isLabor) { allocInput.disabled = true; allocInput.placeholder = '—'; }
        else if (item.cant_alocata !== null) { allocInput.value = item.cant_alocata; }
        tr.appendChild(td(allocInput, 'rp-num'));

        // 15. Actiuni
        var actionsCell = document.createElement('td');
        actionsCell.className = 'text-nowrap text-end';
        actionsCell.innerHTML = '<i class="bi bi-info-circle rp-item-info me-1" title="Total = cantitate × preț; garanția pornește din data montării/recepției (sau data facturii)"></i>'
            + '<button type="button" class="rp-item-del" title="Șterge"><i class="bi bi-trash3" aria-hidden="true"></i></button>';
        tr.appendChild(actionsCell);

        // --- Comportament ---
        nameInput.addEventListener('change', function () { saveField(nameInput, 'denumire'); });
        codeInput.addEventListener('change', function () { saveField(codeInput, 'cod_piesa'); });
        qtyInput.addEventListener('input', refreshLineTotal);
        priceInput.addEventListener('input', refreshLineTotal);
        qtyInput.addEventListener('change', function () {
            saveField(qtyInput, 'cantitate', function () { refreshLineTotal(); renderSelectedHeader(parentTr); });
        });
        priceInput.addEventListener('change', function () {
            saveField(priceInput, 'pret_unitar', function () { refreshLineTotal(); renderSelectedHeader(parentTr); });
        });
        tlSelect.addEventListener('change', function () { saveField(tlSelect, 'tip_lucrare'); });
        warrantySelect.addEventListener('change', function () { saveField(warrantySelect, 'garantie_luni'); });
        warrantyDateInput.addEventListener('change', function () { saveField(warrantyDateInput, 'garantie_pana_la'); });
        dateRefInput.addEventListener('change', function () { saveField(dateRefInput, 'data_referinta'); });
        // Destinatia ramane corectabila si dupa salvare, in ambele sensuri, cu
        // confirmare cand se anuleaza o alocare existenta. Serverul curata
        // atomic campurile devenite irelevante; aici oglindim starea.
        destSelect.addEventListener('change', function () {
            var newDest = destSelect.value;
            if (newDest === item.destinatie) { return; }

            function applyDestinationChange() {
                saveField(destSelect, 'destinatie', function () {
                    item.destinatie = newDest;
                    if (newDest === 'stoc') {
                        item.vehicle_id = null;
                        item.km_bord = null;
                    } else {
                        item.depozit = '';
                    }
                    refreshVehCell(parentTr);
                    renderDetail(parentTr);
                    if (newDest === 'vehicul') {
                        // Randul ramane in "Stoc / nealocate" pana alege vehiculul:
                        // ducem operatorul direct la campul obligatoriu.
                        var detail = getDetailRow(parentTr);
                        var pending = detail ? detail.querySelector('tr[data-item-id="' + item.id + '"] .rp-veh-select') : null;
                        if (pending) { pending.focus(); }
                    }
                });
            }

            var message;
            if (newDest === 'vehicul') {
                message = 'Articolul este înregistrat momentan în <strong>Stoc</strong>.<br>' +
                    'Schimbarea destinației va anula intrarea în stoc și va aloca piesa unui vehicul.<br><br>Continui?';
            } else {
                var currentVehicle = item.vehicle_id !== null ? vehicleName(item.vehicle_id) : null;
                message = currentVehicle
                    ? 'Articolul este alocat momentan vehiculului <strong>' + currentVehicle + '</strong>.<br>' +
                      'Schimbarea destinației va elimina această alocare și va crea o intrare în stoc.<br><br>Continui?'
                    : 'Articolul va fi trimis în <strong>Stoc</strong>.<br><br>Continui?';
            }

            rpDialog('Schimbare destinație', message, [
                {
                    label: 'Anulează', cls: 'btn-outline-secondary',
                    action: function () { destSelect.value = item.destinatie; }
                },
                { label: 'Schimbă destinația', cls: 'btn-primary', action: applyDestinationChange }
            ]);
        });
        vehSelect.addEventListener('change', function () {
            saveField(vehSelect, 'vehicle_id', function () {
                var chosenId = vehSelect.value !== '' ? parseInt(vehSelect.value, 10) : null;
                // Vehicul nou pe factura: serverul l-a asociat deja; oglindim in UI.
                if (chosenId !== null && !st.vehicles.some(function (v) { return v.id === chosenId; })) {
                    var info = ALL_VEHICLES.filter(function (v) { return v.id === chosenId; })[0];
                    st.vehicles.push({ id: chosenId, nr: info ? info.nr : '?', tip: '' });
                }
                refreshVehCell(parentTr);
                renderDetail(parentTr);
            });
        });
        allocInput.addEventListener('change', function () { saveField(allocInput, 'cant_alocata'); });

        [nameInput, codeInput].forEach(function (input) {
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); input.blur(); }
            });
        });

        var delBtn = actionsCell.querySelector('.rp-item-del');
        delBtn.addEventListener('click', function () {
            if (!delBtn.classList.contains('rp-armed')) {
                delBtn.classList.add('rp-armed');
                setTimeout(function () { delBtn.classList.remove('rp-armed'); }, 3000);
                return;
            }
            postForm(URLS.itemDelete, { item_id: item.id })
                .then(function (payload) {
                    st.items = st.items.filter(function (candidate) { return candidate.id !== item.id; });
                    applyEventTotals(parentTr, payload.totals);
                    renderDetail(parentTr);
                })
                .catch(function (error) { showError(error.message); });
        });

        return tr;
    }

    function renderSidebar(parentTr) {
        var detail = getDetailRow(parentTr);
        if (!detail) { return; }
        var st = eventState(parentTr);
        var list = detail.querySelector('.rp-veh-list');
        list.innerHTML = '';

        st.vehicles.forEach(function (vehicle) {
            var card = document.createElement('div');
            card.className = 'rp-veh-card' + (st.selected === vehicle.id ? ' rp-selected' : '');
            var km = vehicleKm(st, vehicle.id);
            card.innerHTML = '<span class="rp-plate">' + vehicle.nr + '</span>'
                + '<div class="rp-vehicle-type">' + (vehicle.tip || '') + '</div>'
                + '<div class="rp-km">KM: ' + (km !== null ? fmtInt(km) : '—') + '</div>'
                + '<button type="button" class="rp-veh-card-menu" title="Acțiuni vehicul"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>';
            card.addEventListener('click', function () {
                st.selected = vehicle.id;
                renderDetail(parentTr);
            });
            card.querySelector('.rp-veh-card-menu').addEventListener('click', function (event) {
                event.stopPropagation();
                openChoiceMenu(this, [
                    ['<i class="bi bi-x-circle me-1 text-danger" aria-hidden="true"></i>Elimină de pe factură', 'remove']
                ], function () {
                    startVehicleRemoval(parentTr, vehicle);
                });
            });
            list.appendChild(card);
        });

        if (hasStocTab(st)) {
            var stocCard = document.createElement('div');
            stocCard.className = 'rp-veh-card' + (st.selected === STOC_TAB ? ' rp-selected' : '');
            stocCard.innerHTML = '<span class="rp-plate"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Stoc</span>'
                + '<div class="rp-vehicle-type">Articole nealocate / trimise în stoc</div>';
            stocCard.addEventListener('click', function () {
                st.selected = STOC_TAB;
                renderDetail(parentTr);
            });
            list.appendChild(stocCard);
        }
    }

    function renderSelectedHeader(parentTr) {
        var detail = getDetailRow(parentTr);
        if (!detail) { return; }
        var st = eventState(parentTr);
        var items = itemsForTab(st);
        var totals = vehicleTotals(items);
        var nameEl = detail.querySelector('.rp-sel-name');

        if (st.selected === STOC_TAB) {
            nameEl.textContent = 'Stoc / nealocate';
        } else {
            var vehicle = st.vehicles.filter(function (v) { return v.id === st.selected; })[0];
            nameEl.textContent = vehicle ? vehicle.nr + (vehicle.tip ? ' (' + vehicle.tip + ')' : '') : '—';
        }
        detail.querySelector('.rp-sel-piese').textContent = fmt(totals.piese) + ' lei';
        detail.querySelector('.rp-sel-manopera').textContent = fmt(totals.manopera) + ' lei';
        detail.querySelector('.rp-sel-total').textContent = fmt(totals.general) + ' lei';

        var km = st.selected !== STOC_TAB ? vehicleKm(st, st.selected) : null;
        detail.querySelector('.rp-footer-km').textContent = km !== null ? fmtInt(km) : '—';
        detail.querySelector('.rp-footer-piese').textContent = fmt(totals.piese) + ' lei';
        detail.querySelector('.rp-footer-manopera').textContent = fmt(totals.manopera) + ' lei';
        detail.querySelector('.rp-footer-total').textContent = fmt(totals.general) + ' lei';
    }

    function renderItems(parentTr) {
        var detail = getDetailRow(parentTr);
        if (!detail) { return; }
        var st = eventState(parentTr);
        var body = detail.querySelector('.rp-items-body');
        body.innerHTML = '';
        itemsForTab(st).forEach(function (item) {
            body.appendChild(buildItemRow(parentTr, item));
        });
        if (!body.children.length) {
            var empty = document.createElement('tr');
            empty.innerHTML = '<td colspan="15" class="text-muted text-center py-3">Niciun articol pentru această selecție — folosește „+ Adaugă articol".</td>';
            body.appendChild(empty);
        }
    }

    function renderDetail(parentTr) {
        renderSidebar(parentTr);
        renderSelectedHeader(parentTr);
        renderItems(parentTr);
    }

    function refreshVehCell(parentTr) {
        // Reactualizeaza celula "Vehicule pe factura" dupa schimbari de alocare.
        var st = eventState(parentTr);
        var cell = parentTr.querySelector('.rp-veh-cell');
        if (st.vehicles.length === 0) {
            cell.innerHTML = '<span class="text-muted">—</span>';
        } else if (st.vehicles.length === 1) {
            cell.innerHTML = '<span class="rp-plate">' + st.vehicles[0].nr + '</span>'
                + '<div class="rp-vehicle-type">' + (st.vehicles[0].tip || '') + '</div>';
        } else {
            cell.innerHTML = '<button type="button" class="rp-veh-multi rp-veh-multi-btn">'
                + st.vehicles.length + ' vehicule <i class="bi bi-chevron-down" aria-hidden="true"></i></button>';
        }
    }

    function buildDetailRow(parentTr) {
        var st = eventState(parentTr);
        if (st.selected === null) {
            st.selected = st.vehicles.length ? st.vehicles[0].id : STOC_TAB;
        }

        var tr = document.createElement('tr');
        tr.className = 'rp-detail';
        var td = document.createElement('td');
        td.colSpan = 9;

        td.innerHTML =
            '<div class="rp-detail-shell">' +
            '  <div class="rp-detail-title"><i class="bi bi-truck me-1" aria-hidden="true"></i>Detalii pe vehicule și articole</div>' +
            '  <div class="d-flex gap-3 rp-detail-flex">' +
            '    <div class="rp-veh-sidebar">' +
            '      <div class="rp-veh-sidebar-title">' +
            '        <span class="rp-veh-title-text">Vehicule pe factură</span>' +
            '        <button type="button" class="rp-veh-toggle" title="Ascunde / arată lista de vehicule"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>' +
            '      </div>' +
            '      <span class="rp-veh-collapsed-hint" title="Arată lista de vehicule">Vehicule</span>' +
            '      <div class="rp-veh-list"></div>' +
            '      <div class="rp-veh-add-wrap">' +
            '        <button type="button" class="btn btn-sm btn-outline-primary w-100 rp-veh-add"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adaugă vehicul</button>' +
            '        <select class="rp-input-sm w-100 mt-1 d-none rp-veh-add-select"></select>' +
            '      </div>' +
            '    </div>' +
            '    <div class="rp-item-wrap">' +
            '      <div class="rp-sel-header">' +
            '        <span>Vehicul selectat: <span class="rp-sel-name"></span></span>' +
            '        <span class="rp-sel-totals">' +
            '          <span>Piese: <strong class="rp-sel-piese"></strong></span>' +
            '          <span>Manoperă: <strong class="rp-sel-manopera"></strong></span>' +
            '          <span>Total vehicul: <strong class="rp-sel-total"></strong></span>' +
            '        </span>' +
            '      </div>' +
            '      <div class="mb-2"><button type="button" class="btn btn-sm btn-outline-primary rp-item-add"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adaugă articol</button></div>' +
            '      <div class="rp-item-scroll"><table class="rp-item-table"><thead><tr>' +
            '        <th>Tip</th><th>Denumire</th><th>Cod</th><th>Cant.</th><th>Preț unitar</th><th class="text-end">Total</th>' +
            '        <th>Tip lucrare</th><th>Garanție</th><th>Garanție până la</th><th>Destinație</th><th>Vehicul</th>' +
            '        <th>Data montării / Recepției</th><th>KM bord / Depozit</th><th>Cant. mont./prim.</th><th></th>' +
            '      </tr></thead><tbody class="rp-items-body"></tbody></table></div>' +
            '    </div>' +
            '  </div>' +
            '  <div class="rp-detail-footer">' +
            '    <span><i class="bi bi-speedometer2 text-primary me-1" aria-hidden="true"></i>KM bord: <strong class="rp-footer-km"></strong></span>' +
            '    <span><i class="bi bi-box-seam text-primary me-1" aria-hidden="true"></i>Total piese (vehicul): <strong class="rp-footer-piese"></strong></span>' +
            '    <span><i class="bi bi-wrench-adjustable text-primary me-1" aria-hidden="true"></i>Total manoperă (vehicul): <strong class="rp-footer-manopera"></strong></span>' +
            '    <span><i class="bi bi-cash-coin text-primary me-1" aria-hidden="true"></i>Total vehicul: <strong class="rp-footer-total"></strong></span>' +
            '    <span class="d-flex align-items-center gap-1 flex-grow-1"><i class="bi bi-chat-left-text text-primary me-1" aria-hidden="true"></i>Observații:' +
            '      <input type="text" class="rp-obs-input flex-grow-1" maxlength="500" placeholder="—"></span>' +
            '  </div>' +
            '  <div class="rp-help-strip">' +
            '    <span><i class="bi bi-shield-check text-success me-1" aria-hidden="true"></i><strong>Garanție piesă</strong> = perioada în care furnizorul garantează piesa.</span>' +
            '    <span><i class="bi bi-shield-check text-primary me-1" aria-hidden="true"></i><strong>Garanție manoperă</strong> = perioada în care service-ul garantează lucrarea executată.</span>' +
            '  </div>' +
            '</div>';
        tr.appendChild(td);

        // Ascunde / arata sidebar-ul de vehicule (preferinta tinuta minte local).
        var sidebar = td.querySelector('.rp-veh-sidebar');
        var toggleBtn = td.querySelector('.rp-veh-toggle');
        var toggleIcon = toggleBtn.querySelector('i');
        function setSidebarCollapsed(collapsed, persist) {
            sidebar.classList.toggle('rp-collapsed', collapsed);
            toggleIcon.className = collapsed ? 'bi bi-chevron-right' : 'bi bi-chevron-left';
            if (persist) {
                try { localStorage.setItem('rp_veh_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) { /* privat/blocat */ }
            }
        }
        toggleBtn.addEventListener('click', function () {
            setSidebarCollapsed(!sidebar.classList.contains('rp-collapsed'), true);
        });
        td.querySelector('.rp-veh-collapsed-hint').addEventListener('click', function () {
            setSidebarCollapsed(false, true);
        });
        try {
            if (localStorage.getItem('rp_veh_sidebar_collapsed') === '1') { setSidebarCollapsed(true, false); }
        } catch (e) { /* localStorage indisponibil */ }

        // + Adauga articol: alegere Piesa / Manopera printr-un meniu mic.
        td.querySelector('.rp-item-add').addEventListener('click', function (event) {
            event.stopPropagation();
            openChoiceMenu(this, [
                ['<i class="bi bi-box-seam me-1" aria-hidden="true"></i>Piesă', 'piesa'],
                ['<i class="bi bi-wrench-adjustable me-1" aria-hidden="true"></i>Manoperă', 'manopera']
            ], function (tip) {
                var vehicleId = st.selected !== STOC_TAB ? st.selected : '';
                postForm(URLS.itemAdd, { event_id: parentTr.dataset.eventId, tip: tip, vehicle_id: vehicleId })
                    .then(function (payload) {
                        st.items.push({
                            id: payload.item_id, tip: tip, denumire: '', cod_piesa: '', cantitate: 1, pret_unitar: 0,
                            tip_lucrare: tip === 'manopera' ? 'reparatie' : 'inlocuire',
                            garantie_luni: null, garantie_pana_la: null, garantie_manuala: false,
                            destinatie: 'vehicul', vehicle_id: st.selected !== STOC_TAB ? st.selected : null,
                            data_referinta: parentTr.querySelector('[data-field="data_interventie"]').value || null,
                            km_bord: null, depozit: '', cant_alocata: null
                        });
                        applyEventTotals(parentTr, payload.totals);
                        renderDetail(parentTr);
                        var lastRow = td.querySelector('.rp-items-body tr:last-child input');
                        if (lastRow) { lastRow.focus(); }
                    })
                    .catch(function (error) { showError(error.message); });
            });
        });

        // + Adauga vehicul: selector inline (fara modal).
        var addBtn = td.querySelector('.rp-veh-add');
        var addSelect = td.querySelector('.rp-veh-add-select');
        addBtn.addEventListener('click', function () {
            addSelect.innerHTML = '<option value="">Alege vehiculul...</option>';
            ALL_VEHICLES.forEach(function (vehicle) {
                if (st.vehicles.some(function (v) { return v.id === vehicle.id; })) { return; }
                var option = document.createElement('option');
                option.value = vehicle.id;
                option.textContent = vehicle.nr;
                addSelect.appendChild(option);
            });
            addSelect.classList.toggle('d-none');
        });
        addSelect.addEventListener('change', function () {
            var vehicleId = parseInt(addSelect.value, 10);
            if (!vehicleId) { return; }
            postForm(URLS.vehicleAdd, { event_id: parentTr.dataset.eventId, vehicle_id: vehicleId })
                .then(function () {
                    var vehicle = ALL_VEHICLES.filter(function (v) { return v.id === vehicleId; })[0];
                    st.vehicles.push({ id: vehicleId, nr: vehicle ? vehicle.nr : '?', tip: '' });
                    st.selected = vehicleId;
                    addSelect.classList.add('d-none');
                    refreshVehCell(parentTr);
                    renderDetail(parentTr);
                })
                .catch(function (error) { showError(error.message); });
        });

        // Observatii inline.
        var obsInput = td.querySelector('.rp-obs-input');
        obsInput.value = parentTr.dataset.observatii || '';
        obsInput.addEventListener('change', function () {
            postForm(URLS.eventUpdate, { event_id: parentTr.dataset.eventId, field: 'observatii', value: obsInput.value })
                .then(function () { parentTr.dataset.observatii = obsInput.value; })
                .catch(function (error) { showError('Observații: ' + error.message); });
        });
        obsInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); obsInput.blur(); }
        });

        return tr;
    }

    function toggleEvent(parentTr, selectVehicleId) {
        var detail = getDetailRow(parentTr);
        var expander = parentTr.querySelector('.rp-expander i');
        if (detail && selectVehicleId === undefined) {
            detail.remove();
            parentTr.classList.remove('rp-open');
            expander.className = 'bi bi-chevron-right';
            parentTr.querySelector('.rp-expander').setAttribute('aria-expanded', 'false');
            parentTr.querySelector('.rp-doc-editors').classList.add('d-none');
            parentTr.querySelector('.rp-doc-view').classList.remove('d-none');
            return;
        }
        if (selectVehicleId !== undefined) {
            eventState(parentTr).selected = selectVehicleId;
        }
        if (!detail) {
            detailAnchor(parentTr).after(buildDetailRow(parentTr));
            parentTr.classList.add('rp-open');
            expander.className = 'bi bi-chevron-up';
            parentTr.querySelector('.rp-expander').setAttribute('aria-expanded', 'true');
        }
        renderDetail(parentTr);
    }

    // Init randuri parinte.
    document.querySelectorAll('tr.rp-parent').forEach(function (parentTr) {
        var piese = parentTr.querySelector('.rp-cell-piese').textContent;
        var manopera = parentTr.querySelector('.rp-cell-manopera').textContent;
        parentTr.dataset.totalPiese = String(parseFloat(piese.replace(/\./g, '').replace(',', '.')) || 0);
        parentTr.dataset.totalManopera = String(parseFloat(manopera.replace(/\./g, '').replace(',', '.')) || 0);

        parentTr.querySelectorAll('.rp-parent-field').forEach(function (input) {
            input.addEventListener('change', function () {
                var field = input.dataset.field;
                postForm(URLS.eventUpdate, { event_id: parentTr.dataset.eventId, field: field, value: input.value })
                    .then(function () {
                        if (field === 'data_interventie') {
                            parentTr.children[2].querySelector('.rp-view-toggle').textContent = dateRo(input.value);
                        } else if (field === 'document' || field === 'furnizor') {
                            parentTr.querySelector(field === 'document' ? '.rp-doc' : '.rp-supplier').textContent =
                                input.value !== '' ? input.value : (field === 'document' ? '—' : '');
                        }
                    })
                    .catch(function (error) { showError(field + ': ' + error.message); });
            });
        });

        parentTr.querySelector('.rp-doc-pencil').addEventListener('click', function (event) {
            event.stopPropagation();
            parentTr.querySelector('.rp-doc-editors').classList.toggle('d-none');
            parentTr.querySelector('.rp-doc-view').classList.toggle('d-none');
        });

        parentTr.querySelector('.rp-expander').addEventListener('click', function () { toggleEvent(parentTr); });
    });

    // --- Dialog compact propriu (confirm() nativ este blocat in acest browser) ---
    var activeDialog = null;
    function closeDialog() { if (activeDialog) { activeDialog.remove(); activeDialog = null; } }

    /**
     * rpDialog(titlu, corpHTML, [{label, cls, action(bodyEl)}]) -> element corp
     * Actiunea primeste corpul dialogului (pentru select-uri) si inchide singura
     * dialogul cand e cazul (return true = inchide).
     */
    function rpDialog(title, bodyHtml, buttons) {
        closeDialog();
        activeDialog = document.createElement('div');
        activeDialog.className = 'rp-dialog-overlay';
        var panel = document.createElement('div');
        panel.className = 'rp-dialog';
        panel.innerHTML = '<div class="rp-dialog-title"></div><div class="rp-dialog-body"></div><div class="rp-dialog-actions"></div>';
        panel.querySelector('.rp-dialog-title').textContent = title;
        panel.querySelector('.rp-dialog-body').innerHTML = bodyHtml;
        var actions = panel.querySelector('.rp-dialog-actions');
        buttons.forEach(function (button) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'btn btn-sm ' + (button.cls || 'btn-outline-secondary');
            el.textContent = button.label;
            el.addEventListener('click', function () {
                if (!button.action) { closeDialog(); return; }
                var shouldClose = button.action(panel.querySelector('.rp-dialog-body'), el);
                if (shouldClose !== false) { closeDialog(); }
            });
            actions.appendChild(el);
        });
        activeDialog.appendChild(panel);
        activeDialog.addEventListener('click', function (event) {
            if (event.target === activeDialog) { closeDialog(); }
        });
        document.body.appendChild(activeDialog);
        return panel;
    }

    // --- Eliminarea unui vehicul DE PE factura (nu din flota) ---
    function startVehicleRemoval(parentTr, vehicle) {
        var st = eventState(parentTr);
        var linked = st.items.filter(function (item) { return item.vehicle_id === vehicle.id; });
        var partCount = linked.filter(function (item) { return item.tip !== 'manopera'; }).length;
        var laborCount = linked.length - partCount;
        var linkedTotal = linked.reduce(function (sum, item) {
            return sum + (item.cantitate || 0) * (item.pret_unitar || 0);
        }, 0);

        function finishRemoval(payload, selectAfter) {
            st.vehicles = st.vehicles.filter(function (candidate) { return candidate.id !== vehicle.id; });
            if (st.selected === vehicle.id) {
                st.selected = selectAfter !== undefined ? selectAfter
                    : (st.vehicles.length ? st.vehicles[0].id : STOC_TAB);
            }
            applyEventTotals(parentTr, payload.totals);
            refreshVehCell(parentTr);
            renderDetail(parentTr);
        }

        function callRemove(mode, extra, selectAfter) {
            var data = { event_id: parentTr.dataset.eventId, vehicle_id: vehicle.id, mode: mode };
            Object.assign(data, extra || {});
            return postForm(URLS.vehicleRemove, data)
                .then(function (payload) { finishRemoval(payload, selectAfter); })
                .catch(function (error) { showError(error.message); });
        }

        // CAZUL 1: fara date asociate -> confirmare simpla.
        if (!linked.length) {
            rpDialog(
                'Eliminare vehicul de pe factură',
                'Elimini vehiculul <strong>' + vehicle.nr + '</strong> de pe această factură?' +
                '<div class="text-muted small mt-1">Vehiculul rămâne în flotă — se elimină doar legătura cu factura.</div>',
                [
                    { label: 'Anulează', cls: 'btn-outline-secondary' },
                    { label: 'Elimină', cls: 'btn-danger', action: function () { callRemove('remove'); } }
                ]
            );
            return;
        }

        // CAZUL 2: are date asociate -> rezolvare explicita.
        var summary = '<div class="rp-dialog-summary">'
            + partCount + ' piese<br>' + laborCount + ' lucrări<br>'
            + 'Total: <strong>' + fmt(linkedTotal) + ' lei</strong></div>';

        var buttons = [
            {
                label: 'Mută datele pe alt vehicul', cls: 'btn-outline-primary',
                action: function () {
                    var options = ALL_VEHICLES.filter(function (candidate) { return candidate.id !== vehicle.id; });
                    if (!options.length) { showError('Nu există alt vehicul disponibil.'); return true; }
                    var body = rpDialog(
                        'Mută datele pe alt vehicul',
                        'Articolele vehiculului <strong>' + vehicle.nr + '</strong> vor fi mutate pe:' +
                        '<select class="form-select form-select-sm mt-2 rp-dialog-target"></select>',
                        [
                            { label: 'Anulează', cls: 'btn-outline-secondary' },
                            {
                                label: 'Mută și elimină', cls: 'btn-primary',
                                action: function (bodyEl) {
                                    var target = parseInt(bodyEl.querySelector('.rp-dialog-target').value, 10);
                                    if (!target) { return false; }
                                    callRemove('reassign', { target_vehicle_id: target }, target).then(function () {
                                        // Mutarea client-side: articolele + vehiculul-tinta in lista.
                                        st.items.forEach(function (item) {
                                            if (item.vehicle_id === vehicle.id) { item.vehicle_id = target; }
                                        });
                                        if (!st.vehicles.some(function (candidate) { return candidate.id === target; })) {
                                            var info = ALL_VEHICLES.filter(function (candidate) { return candidate.id === target; })[0];
                                            st.vehicles.push({ id: target, nr: info ? info.nr : '?', tip: '' });
                                        }
                                        st.selected = target;
                                        refreshVehCell(parentTr);
                                        renderDetail(parentTr);
                                    });
                                }
                            }
                        ]
                    );
                    var select = body.querySelector('.rp-dialog-target');
                    options.forEach(function (candidate) {
                        var option = document.createElement('option');
                        option.value = candidate.id;
                        option.textContent = candidate.nr + (st.vehicles.some(function (v) { return v.id === candidate.id; }) ? ' (pe factură)' : '');
                        select.appendChild(option);
                    });
                    // Preferam un vehicul deja aflat pe factura.
                    var onInvoice = options.filter(function (candidate) {
                        return st.vehicles.some(function (v) { return v.id === candidate.id; });
                    });
                    if (onInvoice.length) { select.value = onInvoice[0].id; }
                    return false; // dialogul de alegere ramane deschis (a fost inlocuit)
                }
            }
        ];

        if (partCount > 0 && laborCount === 0) {
            buttons.push({
                label: 'Mută piesele în Stoc', cls: 'btn-outline-warning',
                action: function () {
                    callRemove('to_stock', {}, STOC_TAB).then(function () {
                        st.items.forEach(function (item) {
                            if (item.vehicle_id === vehicle.id) {
                                item.vehicle_id = null;
                                item.destinatie = 'stoc';
                                item.km_bord = null;
                            }
                        });
                        renderDetail(parentTr);
                    });
                }
            });
        }

        buttons.push({
            label: 'Șterge articolele asociate', cls: 'btn-outline-danger',
            action: function () {
                rpDialog(
                    'Confirmare ștergere',
                    'Acțiunea va șterge <strong>' + partCount + ' piese</strong> și <strong>' + laborCount
                        + ' lucrări</strong> asociate vehiculului <strong>' + vehicle.nr + '</strong> de pe această factură. Continui?',
                    [
                        { label: 'Anulează', cls: 'btn-outline-secondary' },
                        {
                            label: 'Șterge definitiv', cls: 'btn-danger',
                            action: function () {
                                callRemove('delete_items').then(function () {
                                    st.items = st.items.filter(function (item) { return item.vehicle_id !== vehicle.id; });
                                    renderDetail(parentTr);
                                });
                            }
                        }
                    ]
                );
                return false;
            }
        });
        buttons.push({ label: 'Anulează', cls: 'btn-outline-secondary' });

        rpDialog(
            'Vehiculul are date asociate',
            'Vehiculul <strong>' + vehicle.nr + '</strong> are date asociate acestei facturi.' + summary +
            '<div class="text-muted small mt-2">Alege cum rezolvi articolele înainte de eliminare. Vehiculul rămâne în flotă.</div>',
            buttons
        );
    }

    // --- Meniuri flotante (actiuni, lista vehicule, alegere tip articol) ---
    var floatMenu = null;
    function closeFloatMenu() { if (floatMenu) { floatMenu.remove(); floatMenu = null; } }

    function placeMenu(anchor) {
        document.body.appendChild(floatMenu);
        var rect = anchor.getBoundingClientRect();
        var menuRect = floatMenu.getBoundingClientRect();
        var left = Math.min(rect.left, window.innerWidth - menuRect.width - 8);
        var top = rect.bottom + 4;
        if (top + menuRect.height > window.innerHeight - 8) { top = rect.top - menuRect.height - 4; }
        floatMenu.style.left = Math.max(8, left) + 'px';
        floatMenu.style.top = Math.max(8, top) + 'px';
    }

    function openChoiceMenu(anchor, options, onPick) {
        closeFloatMenu();
        floatMenu = document.createElement('div');
        floatMenu.className = 'rp-action-menu';
        options.forEach(function (opt) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'rp-menu-item';
            item.innerHTML = opt[0];
            item.addEventListener('click', function () { closeFloatMenu(); onPick(opt[1]); });
            floatMenu.appendChild(item);
        });
        placeMenu(anchor);
    }

    function openVehicleListMenu(anchor, parentTr) {
        closeFloatMenu();
        var st = eventState(parentTr);
        floatMenu = document.createElement('div');
        floatMenu.className = 'rp-action-menu';
        st.vehicles.forEach(function (vehicle) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'rp-menu-item';
            item.innerHTML = '<span class="rp-plate">' + vehicle.nr + '</span> <span class="text-muted">' + (vehicle.tip || '') + '</span>';
            item.addEventListener('click', function () {
                closeFloatMenu();
                toggleEvent(parentTr, vehicle.id);
            });
            floatMenu.appendChild(item);
        });
        placeMenu(anchor);
    }

    function openActionMenu(menuBtn, parentTr) {
        closeFloatMenu();
        floatMenu = document.createElement('div');
        floatMenu.className = 'rp-action-menu';

        if (parentTr.dataset.invoiceUrl) {
            var link = document.createElement('a');
            link.className = 'rp-menu-item';
            link.href = parentTr.dataset.invoiceUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.innerHTML = '<i class="bi bi-file-earmark-text" aria-hidden="true"></i>Deschide factura (' + (parentTr.dataset.invoiceLabel || '') + ')';
            link.addEventListener('click', closeFloatMenu);
            floatMenu.appendChild(link);
            floatMenu.appendChild(Object.assign(document.createElement('div'), { className: 'rp-menu-sep' }));
        }

        var deleteItem = document.createElement('button');
        deleteItem.type = 'button';
        deleteItem.className = 'rp-menu-item text-danger';
        deleteItem.innerHTML = '<i class="bi bi-trash3" aria-hidden="true"></i>Șterge factura';
        deleteItem.addEventListener('click', function (event) {
            event.stopPropagation();
            if (!deleteItem.dataset.armed) {
                deleteItem.dataset.armed = '1';
                deleteItem.innerHTML = '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Sigur? Apasă din nou';
                return;
            }
            closeFloatMenu();
            postForm(URLS.eventDelete, { event_id: parentTr.dataset.eventId })
                .then(function () {
                    applyEventTotals(parentTr, { piese: 0, manopera: 0, general: 0 });
                    var detail = getDetailRow(parentTr);
                    if (detail) { detail.remove(); }
                    parentTr.remove();
                    var counter = document.getElementById('rp-sum-count');
                    counter.textContent = String(Math.max(0, parseInt(counter.textContent, 10) - 1));
                })
                .catch(function (error) { showError(error.message); });
        });
        floatMenu.appendChild(deleteItem);
        placeMenu(menuBtn);
    }

    document.addEventListener('click', function (event) {
        if (floatMenu && !floatMenu.contains(event.target)
            && !event.target.closest('.rp-menu-btn') && !event.target.closest('.rp-veh-multi-btn')
            && !event.target.closest('.rp-item-add')) {
            closeFloatMenu();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeFloatMenu(); closeDialog(); }
    });

    // In modul "rezultate cautare" tabelul registru lipseste - protejam initializarea.
    var registerTable = document.getElementById('rp-table');
    if (registerTable) {
        registerTable.addEventListener('click', function (event) {
            var menuBtn = event.target.closest('.rp-menu-btn');
            if (menuBtn) {
                var parentTr = menuBtn.closest('tr.rp-parent');
                if (floatMenu) { closeFloatMenu(); } else { openActionMenu(menuBtn, parentTr); }
                return;
            }
            var vehBtn = event.target.closest('.rp-veh-multi-btn');
            if (vehBtn) {
                var tr = vehBtn.closest('tr.rp-parent');
                if (floatMenu) { closeFloatMenu(); } else { openVehicleListMenu(vehBtn, tr); }
            }
        });
    }

    // Randurile din lista de rezultate: click -> registru cu factura deschisa la articol.
    document.querySelectorAll('tr[data-open-url]').forEach(function (row) {
        row.addEventListener('click', function () { window.location.href = row.dataset.openUrl; });
    });

    // --- Adauga factura noua ---
    document.getElementById('rp-add-event').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        postForm(URLS.eventAdd, { vehicle_id: SELECTED_VEHICLE })
            .then(function (payload) { window.location.href = payload.redirect; })
            .catch(function (error) { showError(error.message); btn.disabled = false; });
    });

    // --- Filtre ---
    var filterForm = document.getElementById('rp-filter-form');
    // Cautarea porneste la Enter, la butonul-lupa (submit nativ) si la
    // golirea campului cu X-ul nativ (evenimentul "search" in Chrome).
    var searchInput = document.getElementById('rp-f-q');
    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); filterForm.submit(); }
    });
    searchInput.addEventListener('search', function () { filterForm.submit(); });
    document.getElementById('rp-f-vehicul').addEventListener('change', function () { filterForm.submit(); });
    filterForm.querySelectorAll('input[type="date"]').forEach(function (input) {
        input.addEventListener('change', function () { filterForm.submit(); });
    });
    document.getElementById('rp-per-page').addEventListener('change', function () {
        var url = new URL(window.location.href);
        url.searchParams.set('pe_pagina', this.value);
        url.searchParams.delete('pg');
        window.location.href = url.toString();
    });

    // Auto-deschide factura ceruta (dupa salvare OCR / adaugare / click pe un
    // rezultat de cautare); cu &articol= selecteaza tab-ul si evidentiaza randul.
    if (EXPAND_EVENT > 0) {
        var target = document.querySelector('tr.rp-parent[data-event-id="' + EXPAND_EVENT + '"]');
        if (target) {
            var openTab;
            if (EXPAND_ITEM > 0) {
                var wanted = eventState(target).items.filter(function (item) { return item.id === EXPAND_ITEM; })[0];
                if (wanted) {
                    openTab = wanted.vehicle_id !== null && wanted.destinatie !== 'stoc' ? wanted.vehicle_id : STOC_TAB;
                }
            }
            toggleEvent(target, openTab);
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (EXPAND_ITEM > 0) {
                var detail = getDetailRow(target);
                var itemRow = detail ? detail.querySelector('tr[data-item-id="' + EXPAND_ITEM + '"]') : null;
                if (itemRow) {
                    itemRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    itemRow.classList.add('rp-item-flash');
                }
            }
        }
    }
})();
</script>
