<?php
/**
 * Pagina "Cazare" — registru de cheltuieli de cazare cu asociere automata la cursa.
 *
 * Formularul are exact cele 4 campuri cerute (Data / Sofer / Total / Total cu TVA),
 * plus observatii optionale. Cursa nu se alege: o determina sistemul din perioada
 * curselor soferului. Alegerea manuala apare doar cand exista mai multe candidate.
 */
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summary = is_array($summary ?? null) ? $summary : [];
$drivers = is_array($drivers ?? null) ? $drivers : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 20];
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 20, 50, 100];

$canCreate = (bool) ($canCreate ?? false);
$canEdit = (bool) ($canEdit ?? false);
$canDelete = (bool) ($canDelete ?? false);
$canLink = (bool) ($canLink ?? false);
$canExport = (bool) ($canExport ?? false);

$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$show = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '-';

$baseQuery = array_filter([
    'page' => 'cazare',
    'data_start' => (string) ($filters['data_start'] ?? ''),
    'data_end' => (string) ($filters['data_end'] ?? ''),
    'sofer_id' => (int) ($filters['sofer_id'] ?? 0) > 0 ? (string) $filters['sofer_id'] : '',
    'status' => (string) ($filters['status'] ?? ''),
    'q' => (string) ($filters['q'] ?? ''),
    'pp' => (string) ((int) ($pagination['per_page'] ?? 20)),
], static fn($value, $key) => $key === 'page' || $value !== '', ARRAY_FILTER_USE_BOTH);

$pageUrl = static fn(int $page): string => build_query_url(array_merge($baseQuery, ['p' => (string) $page]));

$statusBadge = static function (string $status): string {
    return match ($status) {
        'asociat' => '<span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-link-45deg"></i> Asociat</span>',
        'ambiguu' => '<span class="badge bg-warning-subtle text-warning-emphasis"><i class="bi bi-question-diamond"></i> Ambiguu</span>',
        default => '<span class="badge bg-secondary-subtle text-secondary-emphasis"><i class="bi bi-hourglass-split"></i> Neasociat</span>',
    };
};

$raceLabel = static function (array $race): string {
    $parts = ['#' . (int) ($race['id'] ?? 0)];
    $parts[] = format_date_ro((string) ($race['data_inceput'] ?? '')) . ' - ' . format_date_ro((string) ($race['data_sfarsit'] ?? ''));

    $plate = trim((string) ($race['nr_inmatriculare'] ?? ''));
    if ($plate !== '') {
        $parts[] = $plate;
    }

    $beneficiary = trim((string) ($race['beneficiar'] ?? ''));
    if ($beneficiary !== '') {
        $parts[] = $beneficiary;
    }

    return implode(' · ', $parts);
};
?>
<link rel="stylesheet" href="<?= e(url('assets/css/cazare.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/cazare.css'))) ?>">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Cazare</h1>
        <p class="text-secondary mb-0 small">
            Introdu cazarea pe dată și șofer; cursa se caută automat în Dispecer curse, după perioada care conține data.
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canCreate): ?>
            <form method="post" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'import_sheet'])) ?>" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-cloud-download" aria-hidden="true"></i> Importă din Sheet
                </button>
            </form>
        <?php endif; ?>
        <?php if ($canLink): ?>
            <form method="post" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'rematch'])) ?>" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Reverifică asocierile
                </button>
            </form>
        <?php endif; ?>
        <?php if ($canExport): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        <?php endif; ?>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#cazareFormModal" data-cazare-new>
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă cazare
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card h-100 cazare-stat">
            <div class="card-body">
                <div class="cazare-stat-label">Total cu TVA</div>
                <div class="cazare-stat-value"><?= e($money($summary['total_cu_tva'] ?? 0)) ?></div>
                <div class="cazare-stat-sub">cost înregistrat pe curse</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 cazare-stat">
            <div class="card-body">
                <div class="cazare-stat-label">Total fără TVA</div>
                <div class="cazare-stat-value"><?= e($money($summary['total'] ?? 0)) ?></div>
                <div class="cazare-stat-sub"><?= (int) ($summary['count'] ?? 0) ?> înregistrări</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 cazare-stat">
            <div class="card-body">
                <div class="cazare-stat-label">Asociate</div>
                <div class="cazare-stat-value text-success"><?= (int) ($summary['asociate'] ?? 0) ?></div>
                <div class="cazare-stat-sub">legate de o cursă</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 cazare-stat">
            <div class="card-body">
                <div class="cazare-stat-label">În așteptare</div>
                <div class="cazare-stat-value text-warning"><?= (int) ($summary['neasociate'] ?? 0) + (int) ($summary['ambigue'] ?? 0) ?></div>
                <div class="cazare-stat-sub"><?= (int) ($summary['neasociate'] ?? 0) ?> fără cursă · <?= (int) ($summary['ambigue'] ?? 0) ?> ambigue</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="cazare">
            <input type="hidden" name="pp" value="<?= (int) ($pagination['per_page'] ?? 20) ?>">
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="cazareDataStart">De la</label>
                <input type="date" class="form-control form-control-sm" id="cazareDataStart" name="data_start" value="<?= e((string) ($filters['data_start'] ?? '')) ?>">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="cazareDataEnd">Până la</label>
                <input type="date" class="form-control form-control-sm" id="cazareDataEnd" name="data_end" value="<?= e((string) ($filters['data_end'] ?? '')) ?>">
            </div>
            <div class="col-12 col-lg-3">
                <label class="form-label small mb-1" for="cazareFiltruSofer">Șofer</label>
                <select class="form-select form-select-sm" id="cazareFiltruSofer" name="sofer_id">
                    <option value="">Toți șoferii</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= (int) $driver['id'] ?>" <?= (int) ($filters['sofer_id'] ?? 0) === (int) $driver['id'] ? 'selected' : '' ?>>
                            <?= e((string) $driver['nume']) ?><?= (string) $driver['status'] !== 'activ' ? ' (inactiv)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="cazareFiltruStatus">Status</label>
                <select class="form-select form-select-sm" id="cazareFiltruStatus" name="status">
                    <option value="">Toate</option>
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>" <?= (string) ($filters['status'] ?? '') === (string) $key ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1" for="cazareCauta">Caută</label>
                <input type="search" class="form-control form-control-sm" id="cazareCauta" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Șofer, observații">
            </div>
            <div class="col-12 col-lg-1 d-grid">
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel" aria-hidden="true"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 cazare-table">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Șofer</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Total cu TVA</th>
                    <th>Factură</th>
                    <th>Cursă asociată</th>
                    <th>Status</th>
                    <th class="text-end">Acțiuni</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="8" class="text-center text-secondary py-4">Nu există înregistrări de cazare pentru filtrele selectate.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $status = (string) $row['status'];
                $candidates = is_array($row['candidati'] ?? null) ? $row['candidati'] : [];
                $documents = is_array($row['documente'] ?? null) ? $row['documente'] : [];
                $rowId = (int) $row['id'];
                ?>
                <tr>
                    <td class="fw-semibold"><?= e(format_date_ro((string) $row['data'])) ?></td>
                    <td>
                        <?= e((string) $row['sofer_nume']) ?>
                        <?php if (trim((string) ($row['observatii'] ?? '')) !== ''): ?>
                            <div class="small text-secondary"><?= e(mb_substr(trim((string) $row['observatii']), 0, 90)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e($money($row['total'])) ?></td>
                    <td class="text-end fw-semibold"><?= e($money($row['total_cu_tva'])) ?></td>
                    <td>
                        <?php if ($documents === []): ?>
                            <span class="text-secondary small">—</span>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-1">
                                <?php foreach ($documents as $document): ?>
                                    <a
                                        class="cazare-doc-link"
                                        href="<?= e(build_query_url(['page' => 'cazare', 'action' => 'download_document', 'document_id' => (int) $document['id']])) ?>"
                                        target="_blank"
                                        rel="noopener"
                                        title="<?= e((string) $document['original_name']) ?>"
                                    >
                                        <i class="bi bi-paperclip" aria-hidden="true"></i>
                                        <span><?= e(mb_strimwidth((string) $document['original_name'], 0, 26, '…')) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($status === 'asociat' && $row['cursa_id'] !== null): ?>
                            <a class="text-decoration-none" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $row['cursa_id']])) ?>">
                                #<?= (int) $row['cursa_id'] ?>
                            </a>
                            <div class="small text-secondary">
                                <?= e(format_date_ro((string) $row['data_inceput'])) ?> - <?= e(format_date_ro((string) $row['data_sfarsit'])) ?>
                                <?php if (trim((string) ($row['nr_inmatriculare'] ?? '')) !== ''): ?>
                                    · <?= e((string) $row['nr_inmatriculare']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ((int) $row['asociere_manuala'] === 1): ?>
                                <span class="badge bg-info-subtle text-info-emphasis mt-1">asociere manuală</span>
                            <?php endif; ?>
                        <?php elseif ($status === 'ambiguu' && $canLink): ?>
                            <form method="post" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'link'])) ?>" class="d-flex gap-1 align-items-center">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                <select class="form-select form-select-sm" name="cursa_id" required>
                                    <option value="">Alege cursa…</option>
                                    <?php foreach ($candidates as $candidate): ?>
                                        <option value="<?= (int) $candidate['id'] ?>"><?= e($raceLabel($candidate)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Asociază">
                                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php elseif ($status === 'ambiguu'): ?>
                            <span class="text-secondary small"><?= count($candidates) ?> curse posibile</span>
                        <?php else: ?>
                            <span class="text-secondary small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $statusBadge($status) ?></td>
                    <td class="text-end text-nowrap">
                        <?php /* Doar alegerea manuala poate fi anulata: pentru randurile potrivite
                                 automat, motorul ar reasocia imediat aceeasi cursa. */ ?>
                        <?php if ($canLink && $status === 'asociat' && (int) $row['asociere_manuala'] === 1): ?>
                            <form method="post" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'unlink'])) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Anulează alegerea manuală a cursei">
                                    <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal"
                                data-bs-target="#cazareFormModal"
                                data-cazare-edit
                                data-id="<?= $rowId ?>"
                                data-data="<?= e((string) $row['data']) ?>"
                                data-sofer="<?= (int) $row['sofer_id'] ?>"
                                data-total="<?= e(number_format((float) $row['total'], 2, '.', '')) ?>"
                                data-total-tva="<?= e(number_format((float) $row['total_cu_tva'], 2, '.', '')) ?>"
                                data-observatii="<?= e((string) ($row['observatii'] ?? '')) ?>"
                                data-documente="<?= e(json_encode(array_map(static fn(array $d): array => [
                                    'id' => (int) $d['id'],
                                    'nume' => (string) $d['original_name'],
                                    'url' => build_query_url(['page' => 'cazare', 'action' => 'download_document', 'document_id' => (int) $d['id']]),
                                ], $documents), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                title="Editează"
                            >
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <form method="post" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'delete'])) ?>" class="d-inline" data-cazare-delete>
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $rowId ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Șterge">
                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-secondary">
                <?= (int) ($pagination['total_rows'] ?? 0) ?> înregistrări · pagina <?= (int) ($pagination['page'] ?? 1) ?> din <?= (int) ($pagination['total_pages'] ?? 1) ?>
            </div>
            <ul class="pagination pagination-sm mb-0">
                <?php for ($i = 1; $i <= (int) $pagination['total_pages']; $i++): ?>
                    <li class="page-item <?= (int) $pagination['page'] === $i ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e($pageUrl($i)) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<?php if ($canCreate || $canEdit): ?>
<div class="modal fade" id="cazareFormModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'store'])) ?>" data-cazare-form>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="" data-cazare-field="id">
            <div class="modal-header">
                <h5 class="modal-title" data-cazare-title>Adaugă cazare</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="cazareData">Data <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="cazareData" name="data" required data-cazare-field="data">
                        <div class="form-text">Cursa se caută după această dată, în perioada curselor șoferului.</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="cazareSofer">Șofer <span class="text-danger">*</span></label>
                        <select class="form-select" id="cazareSofer" name="sofer_id" required data-cazare-field="sofer_id">
                            <option value="">Alege șoferul…</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= (int) $driver['id'] ?>">
                                    <?= e((string) $driver['nume']) ?><?= (string) $driver['status'] !== 'activ' ? ' (inactiv)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="cazareTotal">Total (fără TVA) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" inputmode="decimal" class="form-control" id="cazareTotal" name="total" required data-cazare-field="total" placeholder="0,00">
                            <span class="input-group-text">lei</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="cazareTotalTva">Total cu TVA <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" inputmode="decimal" class="form-control" id="cazareTotalTva" name="total_cu_tva" required data-cazare-field="total_cu_tva" placeholder="0,00">
                            <span class="input-group-text">lei</span>
                        </div>
                        <div class="form-text">Această sumă intră ca și cost pe cursă.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="cazareDocument">Factură</label>
                        <input
                            type="file"
                            class="form-control"
                            id="cazareDocument"
                            name="document_upload"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                        >
                        <div class="form-text">PDF, JPG, PNG, WEBP, DOC sau DOCX. Maxim 5 MB. Factura apare și pe cursa asociată.</div>
                        <!-- Documentele deja atasate, populate din butonul de editare. -->
                        <div class="mt-2 d-none" data-cazare-docs-wrap>
                            <div class="form-label small mb-1">Facturi atașate</div>
                            <div class="d-flex flex-column gap-1" data-cazare-docs></div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="cazareObservatii">Observații</label>
                        <textarea class="form-control" id="cazareObservatii" name="observatii" rows="2" data-cazare-field="observatii"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                <button type="submit" class="btn btn-primary">Salvează</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<!-- Stergerea unui document nu poate sta in formularul principal (formularele nu se
     pot imbrica), deci butoanele din modal submit-eaza acest formular separat. -->
<form method="post" action="<?= e(build_query_url(['page' => 'cazare', 'action' => 'delete_document'])) ?>" class="d-none" id="cazareDeleteDocForm">
    <?= csrf_field() ?>
    <input type="hidden" name="document_id" value="" id="cazareDeleteDocId">
</form>
<?php endif; ?>

<?php if ($canDelete): ?>
<div class="modal fade" id="cazareDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ștergi cazarea?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Înregistrarea și cheltuiala corespunzătoare de pe cursă vor fi șterse definitiv.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                <button type="button" class="btn btn-danger" id="cazareDeleteConfirm" data-bs-dismiss="modal">Șterge</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
window.CAZARE_URLS = {
    store: <?= json_encode(build_query_url(['page' => 'cazare', 'action' => 'store']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    update: <?= json_encode(build_query_url(['page' => 'cazare', 'action' => 'update']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= e(url('assets/js/cazare.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/cazare.js'))) ?>"></script>
