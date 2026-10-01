<?php
/**
 * Detaliul unei facturi: preview (stanga), date editabile (dreapta),
 * asocierea la cursa cu candidatii si actiunile operatorului.
 */
$invoice = is_array($invoice ?? null) ? $invoice : [];
$candidates = is_array($candidates ?? null) ? $candidates : [];
$tripOptions = is_array($tripOptions ?? null) ? $tripOptions : [];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$drivers = is_array($drivers ?? null) ? $drivers : [];
$hasDocument = (bool) ($hasDocument ?? false);
$documentUrl = (string) ($documentUrl ?? '');
$canEdit = (bool) ($canEdit ?? false);
$canLink = (bool) ($canLink ?? false);
$canDelete = (bool) ($canDelete ?? false);
$canReject = (bool) ($canReject ?? false);
$legacyDocuments = is_array($legacyDocuments ?? null) ? $legacyDocuments : [];

$id = (int) $invoice['id'];
$status = (string) $invoice['status'];
$isAssociated = in_array($status, ['asociata_auto', 'asociata_manual'], true) && $invoice['cursa_id'] !== null;
$isLegacy = (string) $invoice['sursa'] === 'legacy_cazare';
$typeConfig = InvoiceModel::TYPES[(string) $invoice['tip']] ?? null;
$isImage = str_starts_with((string) ($invoice['document_mime'] ?? ''), 'image/');
$tripUrl = static fn(int $tripId): string => build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $tripId]);
$candidateIds = array_map(static fn(array $candidate): int => (int) ($candidate['id'] ?? 0), $candidates);

$tripLabel = static function (array $trip): string {
    $parts = ['#' . (int) $trip['id'], format_date_ro((string) $trip['data_inceput']) . ' - ' . format_date_ro((string) $trip['data_sfarsit'])];
    foreach (['nr_inmatriculare', 'sofer_nume', 'beneficiar'] as $key) {
        $value = trim((string) ($trip[$key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    return implode(' · ', $parts);
};

require __DIR__ . '/_status.php';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/facturi.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/facturi.css'))) ?>">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <a class="small text-decoration-none" href="<?= e(build_query_url(['page' => 'facturi'])) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Facturi</a>
        <h1 class="h4 mb-0">
            Factura #<?= $id ?>
            <span class="fs-6 align-middle"><?= $invoiceStatusBadge($status) ?></span>
        </h1>
        <div class="small text-secondary">
            <?= e($typeConfig['label'] ?? (string) $invoice['tip']) ?> · sursa: <?= e(InvoiceModel::SOURCES[(string) $invoice['sursa']] ?? (string) $invoice['sursa']) ?>
            · adăugată <?= e(format_date_ro(substr((string) $invoice['created_at'], 0, 10))) ?>
        </div>
    </div>
    <?php if ($canDelete): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#facturaDeleteModal">
            <i class="bi bi-trash3" aria-hidden="true"></i> Șterge
        </button>
    <?php endif; ?>
</div>

<?php if ($isLegacy): ?>
    <div class="alert alert-info small">
        Factura vine din registrul <strong>Cazare</strong> (cazare #<?= (int) $invoice['legacy_cazare_id'] ?>).
        Asocierea la cursă se poate face și de aici; <strong>datele</strong> (dată, șofer, sume, facturi atașate) se modifică în registrul Cazare.
        <a href="<?= e(build_query_url(['page' => 'cazare', 'vechi' => 1])) ?>">Deschide registrul Cazare</a>
        <?php if (count($legacyDocuments) > 1): ?>
            <div class="mt-2">Toate facturile atașate:
                <?php foreach ($legacyDocuments as $legacyDocument): ?>
                    <a class="ms-2" href="<?= e(build_query_url(['page' => 'cazare', 'action' => 'download_document', 'document_id' => (int) $legacyDocument['id']])) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-paperclip" aria-hidden="true"></i> <?= e(mb_strimwidth((string) $legacyDocument['original_name'], 0, 30, '…')) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="card facturi-preview-card">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <span class="small fw-semibold text-truncate" title="<?= e((string) ($invoice['document_original_name'] ?? '')) ?>">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i> <?= e((string) ($invoice['document_original_name'] ?? 'Document')) ?>
                </span>
                <?php if ($hasDocument): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e($documentUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Tab nou</a>
                <?php endif; ?>
            </div>
            <?php if (!$hasDocument): ?>
                <div class="card-body text-center text-secondary py-5">Factura nu are document atașat (sau fișierul lipsește de pe server).</div>
            <?php elseif ($isImage): ?>
                <div class="facturi-preview facturi-preview-image"><img src="<?= e($documentUrl) ?>" alt="Factura #<?= $id ?>"></div>
            <?php else: ?>
                <iframe class="facturi-preview" src="<?= e($documentUrl) ?>" title="Factura #<?= $id ?>"></iframe>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card mb-3">
            <div class="card-header py-2 fw-semibold">Asociere cursă</div>
            <div class="card-body">
                <?php if ($isAssociated): ?>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <a class="fw-semibold text-decoration-none" href="<?= e($tripUrl((int) $invoice['cursa_id'])) ?>">Cursa #<?= (int) $invoice['cursa_id'] ?></a>
                            <?php if ($invoice['data_inceput'] !== null): ?>
                                <div class="small text-secondary"><?= e(format_date_ro((string) $invoice['data_inceput'])) ?> - <?= e(format_date_ro((string) $invoice['data_sfarsit'])) ?></div>
                            <?php endif; ?>
                            <?php if ($invoice['curse_cheltuiala_id'] !== null): ?>
                                <div class="small text-success"><i class="bi bi-check2-circle" aria-hidden="true"></i> Cheltuiala e pe cursă (rândul #<?= (int) $invoice['curse_cheltuiala_id'] ?>). Refacturarea se completează din Dispecer curse.</div>
                            <?php endif; ?>
                        </div>
                        <?php if ($canLink): ?>
                            <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'unlink'])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $isLegacy ? 'Anulează asocierea; Cazare reverifică imediat automat' : 'Scoate cheltuiala de pe cursă' ?>"><i class="bi bi-scissors" aria-hidden="true"></i> Dezasociază</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (trim((string) ($invoice['match_reason'] ?? '')) !== ''): ?>
                    <div class="small mt-2 <?= $isAssociated ? 'text-secondary' : 'text-warning-emphasis' ?>">
                        <i class="bi bi-info-circle" aria-hidden="true"></i> <?= e((string) $invoice['match_reason']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($candidates !== []): ?>
                    <div class="small fw-semibold mt-3 mb-1">Curse candidate</div>
                    <div class="list-group list-group-flush facturi-candidates">
                        <?php foreach ($candidates as $candidate): ?>
                            <?php $candidateId = (int) ($candidate['id'] ?? 0); ?>
                            <div class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                                <div class="small">
                                    <a class="fw-semibold text-decoration-none" href="<?= e($tripUrl($candidateId)) ?>" target="_blank" rel="noopener">#<?= $candidateId ?></a>
                                    <?= e(format_date_ro((string) ($candidate['data_inceput'] ?? ''))) ?> - <?= e(format_date_ro((string) ($candidate['data_sfarsit'] ?? ''))) ?>
                                    <?php if (trim((string) ($candidate['nr_inmatriculare'] ?? '')) !== ''): ?> · <?= e((string) $candidate['nr_inmatriculare']) ?><?php endif; ?>
                                    <?php if (trim((string) ($candidate['sofer'] ?? '')) !== ''): ?> · <?= e((string) $candidate['sofer']) ?><?php endif; ?>
                                    <div class="text-secondary"><?= e((string) ($candidate['motiv'] ?? '')) ?></div>
                                </div>
                                <?php if ($canLink && !($isAssociated && (int) $invoice['cursa_id'] === $candidateId) && $status !== 'respinsa'): ?>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'confirm'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <input type="hidden" name="cursa_id" value="<?= $candidateId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-check-lg" aria-hidden="true"></i> Confirmă</button>
                                    </form>
                                <?php elseif ($isAssociated && (int) $invoice['cursa_id'] === $candidateId): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis">aleasă</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($canLink && $status !== 'respinsa'): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'choose'])) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <label class="form-label small fw-semibold mb-1" for="facturaAltaCursa">Alege altă cursă (±<?= InvoiceTripMatcher::MANUAL_PICK_WINDOW_DAYS ?> zile de la data facturii)</label>
                        <div class="d-flex gap-1">
                            <select class="form-select form-select-sm" id="facturaAltaCursa" name="cursa_id" required <?= $tripOptions === [] ? 'disabled' : '' ?>>
                                <option value=""><?= $tripOptions === [] ? ($invoice['data_document'] === null ? 'Completează data facturii' : 'Nicio cursă în interval') : 'Alege cursa…' ?></option>
                                <?php foreach ($tripOptions as $trip): ?>
                                    <?php if ($isAssociated && (int) $invoice['cursa_id'] === (int) $trip['id']) { continue; } ?>
                                    <option value="<?= (int) $trip['id'] ?>"><?= e($tripLabel($trip)) ?><?= in_array((int) $trip['id'], $candidateIds, true) ? ' ★' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap" <?= $tripOptions === [] ? 'disabled' : '' ?>>Asociază</button>
                        </div>
                    </form>

                    <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                        <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'rematch'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Rerulează asocierea</button>
                        </form>
                        <?php if ($canReject): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#facturaRejectBox" aria-expanded="false">
                                <i class="bi bi-x-octagon" aria-hidden="true"></i> Respinge
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($canReject): ?>
                    <div class="collapse mt-2" id="facturaRejectBox">
                        <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'reject'])) ?>" class="d-flex gap-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="text" class="form-control form-control-sm" name="motiv" maxlength="200" placeholder="Motiv (ex. duplicat, nu e a firmei)">
                            <button type="submit" class="btn btn-sm btn-danger text-nowrap">Respinge factura</button>
                        </form>
                        <div class="form-text">O factură respinsă nu mai apare pe nicio cursă și nu se mai asociază automat.</div>
                    </div>
                    <?php endif; ?>
                <?php elseif ($canLink && $status === 'respinsa'): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'rematch'])) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="small text-secondary mb-2">Factura este respinsă și nu apare pe nicio cursă.</div>
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Anulează respingerea și reasociază</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header py-2 fw-semibold">Datele facturii</div>
            <div class="card-body">
                <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'update'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <?php
                    $fieldValues = $invoice;
                    $fieldPrefix = 'factura' . $id;
                    $fieldsDisabled = !$canEdit;
                    require __DIR__ . '/_fields.php';
                    ?>
                    <?php if ($canEdit): ?>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="form-text m-0">Schimbarea tipului, datei, vehiculului sau șoferului refăce asocierea.</div>
                            <button type="submit" class="btn btn-primary btn-sm">Salvează</button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($canDelete): ?>
<div class="modal fade" id="facturaDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'delete'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="modal-header">
                <h5 class="modal-title">Ștergi factura #<?= $id ?>?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body small">
                Factura dispare din listă și cheltuiala ei este scoasă de pe cursă. Fișierul rămâne pe server.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Renunță</button>
                <button type="submit" class="btn btn-danger btn-sm">Șterge</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
