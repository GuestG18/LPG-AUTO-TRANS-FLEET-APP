<?php
/**
 * Pagina "Facturi" — lista globala a facturilor de cheltuieli de cursa,
 * cu asociere automata la cursa. Detaliul (preview + asociere) e in view.php.
 */
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summary = is_array($summary ?? null) ? $summary : [];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$drivers = is_array($drivers ?? null) ? $drivers : [];
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 20];
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [20, 50, 100];
$canCreate = (bool) ($canCreate ?? false);
$canImportSheet = (bool) ($canImportSheet ?? false);
$canLink = can('facturi', 'link');

$money = static fn(mixed $value, string $currency = 'RON'): string => $value === null || $value === ''
    ? '—'
    : format_number_ro((float) $value, 2) . ' ' . ($currency === 'RON' ? 'lei' : $currency);

$baseQuery = array_filter([
    'page' => 'facturi',
    'tip' => (string) ($filters['tip'] ?? ''),
    'status' => (string) ($filters['status'] ?? ''),
    'data_start' => (string) ($filters['data_start'] ?? ''),
    'data_end' => (string) ($filters['data_end'] ?? ''),
    'vehicle_id' => (int) ($filters['vehicle_id'] ?? 0) > 0 ? (string) $filters['vehicle_id'] : '',
    'driver_id' => (int) ($filters['driver_id'] ?? 0) > 0 ? (string) $filters['driver_id'] : '',
    'q' => (string) ($filters['q'] ?? ''),
    'pp' => (string) ((int) ($pagination['per_page'] ?? 20)),
], static fn($value, $key) => $key === 'page' || $value !== '', ARRAY_FILTER_USE_BOTH);
$pageUrl = static fn(int $page): string => build_query_url(array_merge($baseQuery, ['p' => (string) $page]));
$statusUrl = static fn(string $status): string => build_query_url(array_merge($baseQuery, ['status' => $status, 'p' => null]));

$statusFilterOptions = [
    'de_rezolvat' => 'De rezolvat (de verificat / neasociate / în procesare)',
    'asociata' => 'Asociate (automat + manual)',
] + InvoiceModel::STATUSES;

require __DIR__ . '/_status.php';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/facturi.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/facturi.css'))) ?>">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Facturi</h1>
        <p class="text-secondary mb-0 small">
            Facturile de cheltuieli de cursă. Fiecare se asociază automat cursei (după șofer la cazare / diurnă, după nr. auto la rest) și apare ca cheltuială pe cursă.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ((string) ($filters['tip'] ?? '') === 'cazare'): ?>
            <?php if ($canImportSheet): ?>
                <form method="post" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'import_sheet'])) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm" title="Importul rulează și automat, orar">
                        <i class="bi bi-cloud-download" aria-hidden="true"></i> Importă din Sheet
                    </button>
                </form>
            <?php endif; ?>
            <?php if (can('cazare')): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(build_query_url(['page' => 'cazare', 'vechi' => 1])) ?>" title="Adăugare / editare cazări fără factură, export CSV">
                    <i class="bi bi-house-heart" aria-hidden="true"></i> Registrul Cazare
                </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($canLink): ?>
            <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'rematch'])) ?>" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Reverifică asocierile
                </button>
            </form>
        <?php endif; ?>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#facturaUploadModal">
                <i class="bi bi-upload" aria-hidden="true"></i> Încarcă factură
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card h-100 facturi-stat">
            <div class="card-body">
                <div class="facturi-stat-label">Total cu TVA</div>
                <div class="facturi-stat-value"><?= e($money($summary['total_cu_tva'] ?? 0)) ?></div>
                <div class="facturi-stat-sub"><?= (int) ($summary['count'] ?? 0) ?> facturi · suma exclude respinsele</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a class="card h-100 facturi-stat text-decoration-none" href="<?= e($statusUrl('asociata')) ?>">
            <div class="card-body">
                <div class="facturi-stat-label">Asociate</div>
                <div class="facturi-stat-value text-success"><?= (int) ($summary['asociate'] ?? 0) ?></div>
                <div class="facturi-stat-sub">au cheltuiala pe cursă</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <a class="card h-100 facturi-stat text-decoration-none" href="<?= e($statusUrl('de_rezolvat')) ?>">
            <div class="card-body">
                <div class="facturi-stat-label">De rezolvat</div>
                <div class="facturi-stat-value text-warning"><?= (int) ($summary['de_verificat'] ?? 0) + (int) ($summary['neasociate'] ?? 0) + (int) ($summary['in_procesare'] ?? 0) ?></div>
                <div class="facturi-stat-sub">
                    <?= (int) ($summary['de_verificat'] ?? 0) ?> de verificat · <?= (int) ($summary['neasociate'] ?? 0) ?> fără cursă<?= (int) ($summary['in_procesare'] ?? 0) > 0 ? ' · ' . (int) $summary['in_procesare'] . ' în procesare' : '' ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <a class="card h-100 facturi-stat text-decoration-none" href="<?= e($statusUrl('respinsa')) ?>">
            <div class="card-body">
                <div class="facturi-stat-label">Respinse</div>
                <div class="facturi-stat-value text-secondary"><?= (int) ($summary['respinse'] ?? 0) ?></div>
                <div class="facturi-stat-sub">nu intră pe nicio cursă</div>
            </div>
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="facturi">
            <input type="hidden" name="pp" value="<?= (int) ($pagination['per_page'] ?? 20) ?>">
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="facturiTip">Tip</label>
                <select class="form-select form-select-sm" id="facturiTip" name="tip">
                    <option value="">Toate tipurile</option>
                    <?php foreach (InvoiceModel::TYPES as $typeKey => $typeConfig): ?>
                        <option value="<?= e($typeKey) ?>" <?= (string) ($filters['tip'] ?? '') === $typeKey ? 'selected' : '' ?>><?= e($typeConfig['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="facturiStatus">Status</label>
                <select class="form-select form-select-sm" id="facturiStatus" name="status">
                    <option value="">Toate</option>
                    <?php foreach ($statusFilterOptions as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>" <?= (string) ($filters['status'] ?? '') === (string) $key ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small mb-1" for="facturiDe">De la</label>
                <input type="date" class="form-control form-control-sm" id="facturiDe" name="data_start" value="<?= e((string) ($filters['data_start'] ?? '')) ?>">
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small mb-1" for="facturiPana">Până la</label>
                <input type="date" class="form-control form-control-sm" id="facturiPana" name="data_end" value="<?= e((string) ($filters['data_end'] ?? '')) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="facturiVehicul">Vehicul</label>
                <select class="form-select form-select-sm" id="facturiVehicul" name="vehicle_id">
                    <option value="">Toate</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= (int) $vehicle['id'] ?>" <?= (int) ($filters['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="facturiSofer">Șofer</label>
                <select class="form-select form-select-sm" id="facturiSofer" name="driver_id">
                    <option value="">Toți</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= (int) $driver['id'] ?>" <?= (int) ($filters['driver_id'] ?? 0) === (int) $driver['id'] ? 'selected' : '' ?>><?= e((string) $driver['nume']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-9 col-lg-1">
                <label class="form-label small mb-1" for="facturiCauta">Caută</label>
                <input type="search" class="form-control form-control-sm" id="facturiCauta" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Furnizor, nr.">
            </div>
            <div class="col-3 col-lg-1 d-grid">
                <button type="submit" class="btn btn-outline-primary btn-sm" title="Filtrează"><i class="bi bi-funnel" aria-hidden="true"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 facturi-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Tip</th>
                    <th>Furnizor</th>
                    <th>Nr. document</th>
                    <th class="text-end">Valoare</th>
                    <th>Nr. auto</th>
                    <th>Șofer</th>
                    <th>Cursă</th>
                    <th>Status</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="text-center text-secondary py-4">Nu există facturi pentru filtrele selectate.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $rowId = (int) $row['id'];
                $viewUrl = build_query_url(['page' => 'facturi', 'action' => 'view', 'id' => $rowId]);
                $plate = trim((string) ($row['nr_inmatriculare'] ?? ''));
                $plateExtracted = trim((string) ($row['nr_inmatriculare_extras'] ?? ''));
                $driverName = trim((string) ($row['sofer_nume'] ?? ''));
                $driverExtracted = trim((string) ($row['sofer_extras'] ?? ''));
                $status = (string) $row['status'];
                ?>
                <tr class="facturi-row" data-href="<?= e($viewUrl) ?>">
                    <td class="fw-semibold text-nowrap"><?= $row['data_document'] !== null ? e(format_date_ro((string) $row['data_document'])) : '<span class="text-secondary">—</span>' ?></td>
                    <td><?= e(InvoiceModel::TYPES[(string) $row['tip']]['label'] ?? (string) $row['tip']) ?></td>
                    <td><?= e(mb_strimwidth(trim((string) ($row['furnizor'] ?? '')) ?: '—', 0, 40, '…')) ?></td>
                    <td class="text-nowrap"><?= e(trim((string) ($row['numar_document'] ?? '')) ?: '—') ?></td>
                    <td class="text-end fw-semibold text-nowrap"><?= e($money($row['valoare_cu_tva'], (string) $row['moneda'])) ?></td>
                    <td class="text-nowrap">
                        <?php if ($plate !== ''): ?>
                            <?= e($plate) ?>
                        <?php elseif ($plateExtracted !== ''): ?>
                            <span class="text-warning-emphasis" title="Numărul de pe factură nu a fost găsit în Vehicule"><?= e($plateExtracted) ?> <i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($driverName !== ''): ?>
                            <?= e($driverName) ?>
                        <?php elseif ($driverExtracted !== ''): ?>
                            <span class="text-warning-emphasis" title="Numele de pe factură nu a fost găsit în Șoferi"><?= e($driverExtracted) ?> <i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($row['cursa_id'] !== null && $row['data_inceput'] !== null): ?>
                            <a class="text-decoration-none" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['cursa_id']])) ?>">#<?= (int) $row['cursa_id'] ?></a>
                            <div class="small text-secondary"><?= e(format_date_ro((string) $row['data_inceput'])) ?> - <?= e(format_date_ro((string) $row['data_sfarsit'])) ?></div>
                        <?php elseif ($status === 'de_verificat' && $row['match_candidates'] !== null): ?>
                            <span class="small text-secondary"><?= count(json_decode((string) $row['match_candidates'], true) ?: []) ?> curse posibile</span>
                        <?php else: ?>
                            <span class="text-secondary">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $invoiceStatusBadge($status) ?>
                        <?php if (in_array($status, ['de_verificat', 'neasociata'], true) && trim((string) ($row['match_reason'] ?? '')) !== ''): ?>
                            <div class="small text-secondary facturi-reason" title="<?= e((string) $row['match_reason']) ?>"><?= e(mb_strimwidth((string) $row['match_reason'], 0, 60, '…')) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($viewUrl) ?>" title="Deschide factura"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="small text-secondary">
            <?= (int) ($pagination['total_rows'] ?? 0) ?> facturi · pagina <?= (int) ($pagination['page'] ?? 1) ?> din <?= (int) ($pagination['total_pages'] ?? 1) ?>
            · pe pagină:
            <?php foreach ($perPageOptions as $option): ?>
                <a class="<?= (int) $pagination['per_page'] === (int) $option ? 'fw-semibold' : '' ?>" href="<?= e(build_query_url(array_merge($baseQuery, ['pp' => (string) $option]))) ?>"><?= (int) $option ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
            <ul class="pagination pagination-sm mb-0 flex-wrap">
                <?php for ($i = 1; $i <= (int) $pagination['total_pages']; $i++): ?>
                    <li class="page-item <?= (int) $pagination['page'] === $i ? 'active' : '' ?>"><a class="page-link" href="<?= e($pageUrl($i)) ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($canCreate): ?>
<div class="modal fade" id="facturaUploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'store'])) ?>">
            <?= csrf_field() ?>
            <?php foreach ($baseQuery as $key => $value): ?>
                <?php if ($key !== 'page'): ?>
                    <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="modal-header">
                <h5 class="modal-title">Încarcă factură</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small mb-1" for="facturaFisier">Fișier <span class="text-danger">*</span></label>
                    <input type="file" class="form-control form-control-sm" id="facturaFisier" name="document_upload" accept=".pdf,.jpg,.jpeg,.png" required>
                    <div class="form-text">PDF, JPG sau PNG, maxim 10 MB. Se păstrează în afara site-ului și se deschide doar din aplicație.</div>
                </div>
                <?php
                $fieldValues = ['tip' => (string) ($filters['tip'] ?? ''), 'moneda' => 'RON'];
                $fieldPrefix = 'facturaNoua';
                $fieldsDisabled = false;
                require __DIR__ . '/_fields.php';
                ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                <button type="submit" class="btn btn-primary">Salvează și asociază</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Click pe rand deschide detaliul (fara sa fure click-urile pe linkuri / butoane).
document.querySelectorAll('.facturi-row[data-href]').forEach(function (row) {
    row.addEventListener('click', function (event) {
        if (event.target.closest('a, button, form, input, select')) {
            return;
        }
        window.location.href = row.getAttribute('data-href');
    });
});
</script>
