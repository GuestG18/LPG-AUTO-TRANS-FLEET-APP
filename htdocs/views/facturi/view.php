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
$canPurge = (bool) ($canPurge ?? false);
$canReject = (bool) ($canReject ?? false);
$legacyDocuments = is_array($legacyDocuments ?? null) ? $legacyDocuments : [];
$ocrDetails = is_array($ocrDetails ?? null) ? $ocrDetails : null;
$scanSiblings = is_array($scanSiblings ?? null) ? $scanSiblings : [];
$isScan = (string) ($invoice['sursa'] ?? '') === 'scan';

// Scanare cu mai multe documente: preview-ul se deschide la prima pagina a acestei facturi.
$firstPage = preg_match('/^\s*(\d+)/', (string) ($invoice['document_pagini'] ?? ''), $pageMatch) === 1 ? (int) $pageMatch[1] : 0;
$previewUrl = $documentUrl . ($firstPage > 1 ? '#page=' . $firstPage : '');

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
    <?php if ($canDelete || $canPurge): ?>
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

<?php if ($isScan): ?>
    <div class="alert alert-light small d-flex flex-wrap gap-3 align-items-center py-2">
        <span><i class="bi bi-envelope-paper" aria-hidden="true"></i> Scanare primită pe email
            <?= $invoice['email_primit_la'] !== null ? 'la <strong>' . e(date('d.m.Y H:i', strtotime((string) $invoice['email_primit_la']))) . '</strong>' : '' ?></span>
        <?php if (trim((string) ($invoice['email_subiect'] ?? '')) !== ''): ?>
            <span>Subiect: <strong><?= e((string) $invoice['email_subiect']) ?></strong>
                <?php $subjectType = InvoiceModel::typeFromSubject((string) $invoice['email_subiect']); ?>
                <?php if ($subjectType !== null): ?>
                    <span class="badge bg-info-subtle text-info-emphasis" title="Categoria a fost aleasă după subiectul emailului">→ <?= e(InvoiceModel::TYPES[$subjectType]['label']) ?></span>
                <?php endif; ?>
            </span>
        <?php endif; ?>
        <?php if (!empty($invoice['document_pagini'])): ?>
            <span>Pagini în scanare: <strong><?= e((string) $invoice['document_pagini']) ?></strong></span>
        <?php endif; ?>
        <?php if ($scanSiblings !== []): ?>
            <span>Din aceeași scanare:
                <?php foreach ($scanSiblings as $sibling): ?>
                    <a class="ms-1" href="<?= e(build_query_url(['page' => 'facturi', 'action' => 'view', 'id' => (int) $sibling['id']])) ?>"
                       title="<?= e(InvoiceModel::TYPES[(string) $sibling['tip']]['label'] ?? '') ?> · <?= e((string) ($sibling['furnizor'] ?? '')) ?>">#<?= (int) $sibling['id'] ?><?= !empty($sibling['document_pagini']) ? ' (p. ' . e((string) $sibling['document_pagini']) . ')' : '' ?></a>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
        <?php if ($status === 'in_procesare'): ?>
            <span class="text-info-emphasis"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Se citește automat (în câteva minute). Reîncarcă pagina.</span>
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
                <iframe class="facturi-preview" src="<?= e($previewUrl) ?>" title="Factura #<?= $id ?>"></iframe>
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
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <?php // "duplicat al facturii #23" devine link catre factura respectiva (textul e escapat inainte). ?>
                        <?= preg_replace_callback(
                            '/facturii #(\d+)/',
                            static fn(array $m): string => 'facturii <a href="' . e(build_query_url(['page' => 'facturi', 'action' => 'view', 'id' => (int) $m[1]])) . '">#' . (int) $m[1] . '</a>',
                            e((string) $invoice['match_reason'])
                        ) ?>
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

        <?php if ($ocrDetails !== null): ?>
            <?php
            $read = is_array($ocrDetails['citit'] ?? null) ? $ocrDetails['citit'] : [];
            $readLabels = [
                'tip' => 'Tip', 'furnizor' => 'Furnizor', 'cui_furnizor' => 'CUI furnizor', 'numar_document' => 'Nr. document',
                'data_document' => 'Data', 'valoare_fara_tva' => 'Fără TVA', 'valoare_cu_tva' => 'Cu TVA', 'moneda' => 'Moneda',
                'nr_inmatriculare' => 'Nr. auto', 'sofer' => 'Șofer', 'pagini' => 'Pagini', 'incredere' => 'Încredere', 'observatii' => 'Observații',
            ];
            $confidence = (string) ($read['incredere'] ?? '');
            $usage = is_array($ocrDetails['usage'] ?? null) ? $ocrDetails['usage'] : [];
            ?>
            <div class="card mt-3">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <button class="btn btn-link p-0 fw-semibold text-decoration-none text-body" type="button" data-bs-toggle="collapse" data-bs-target="#facturaOcrPanel" aria-expanded="false">
                        <i class="bi bi-magic" aria-hidden="true"></i> Date citite automat
                    </button>
                    <?php if ($confidence !== ''): ?>
                        <span class="badge <?= $confidence === 'mare' ? 'bg-success-subtle text-success-emphasis' : ($confidence === 'medie' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-danger-subtle text-danger-emphasis') ?>">încredere <?= e($confidence) ?></span>
                    <?php endif; ?>
                </div>
                <div class="collapse" id="facturaOcrPanel">
                    <div class="card-body small">
                        <?php if ($read === []): ?>
                            <p class="text-secondary mb-2">Citirea nu a găsit nicio factură sau bon în scanare.</p>
                        <?php else: ?>
                            <p class="text-secondary mb-2">Valorile de mai jos sunt cele citite inițial; cele din „Datele facturii” pot fi corectate de operator.</p>
                            <dl class="row mb-2 facturi-ocr-values">
                                <?php foreach ($readLabels as $key => $label): ?>
                                    <?php $value = $read[$key] ?? null; ?>
                                    <dt class="col-5 text-secondary fw-normal"><?= e($label) ?></dt>
                                    <dd class="col-7 mb-1"><?= $value === null || $value === '' ? '<span class="text-secondary">—</span>' : e($key === 'tip' ? (InvoiceModel::TYPES[(string) $value]['label'] ?? (string) $value) : (string) $value) ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        <?php endif; ?>
                        <div class="text-secondary">
                            Model: <?= e((string) ($ocrDetails['model'] ?? '—')) ?>
                            <?= !empty($ocrDetails['citit_la']) ? ' · citit ' . e(date('d.m.Y H:i', strtotime((string) $ocrDetails['citit_la']))) : '' ?>
                            <?= (int) ($ocrDetails['documente'] ?? 0) > 1 ? ' · ' . (int) $ocrDetails['documente'] . ' documente în scanare' : '' ?>
                            <?= $usage !== [] ? ' · tokeni: ' . (int) ($usage['input_tokens'] ?? 0) . ' / ' . (int) ($usage['output_tokens'] ?? 0) : '' ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canDelete || $canPurge): ?>
<?php $purgeCount = 1 + count($scanSiblings); ?>
<div class="modal fade" id="facturaDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ștergi factura #<?= $id ?>?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body small">
                <?php if ($canDelete): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'delete'])) ?>" class="d-flex gap-3 align-items-start">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Șterge</div>
                            Factura dispare din listă și cheltuiala ei este scoasă de pe cursă. Fișierul rămâne pe server, iar aceeași scanare nu mai este preluată a doua oară.
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm text-nowrap">Șterge</button>
                    </form>
                <?php endif; ?>
                <?php if ($canPurge): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'facturi', 'action' => 'purge'])) ?>" class="d-flex gap-3 align-items-start<?= $canDelete ? ' border-top pt-3 mt-3' : '' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Șterge definitiv (pentru retestare)</div>
                            Șterge din baza de date<?= $purgeCount > 1 ? ' toate cele ' . $purgeCount . ' facturi din aceeași scanare' : ' factura' ?>, cheltuiala de pe cursă și fișierul scanat. Nu se poate anula.
                            Aceeași scanare poate fi preluată din nou: scoate eticheta <strong>Facturi/Procesat</strong> de pe email în Gmail sau scanează din nou.
                        </div>
                        <button type="submit" class="btn btn-outline-danger btn-sm text-nowrap">Șterge definitiv</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Renunță</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
