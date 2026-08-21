<?php
$invoices = is_array($invoices ?? null) ? $invoices : [];
$kpis = is_array($kpis ?? null) ? $kpis : ['facturi' => 0, 'articole' => 0, 'valoare' => 0.0, 'furnizori' => 0];
$intakeUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'intake']);
$deleteUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'delete']);
$sandboxUrl = build_query_url(['page' => 'dev_ocr_test']);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h5 mb-0"><i class="bi bi-boxes me-1" aria-hidden="true"></i>Tracker piese din facturi (OCR)</h1>
        <div class="text-muted small">Recepții de piese auto confirmate din facturi citite cu OCR — experiment separat de Stoc Piese.</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($sandboxUrl) ?>" title="Laborator OCR brut">
            <i class="bi bi-flask me-1" aria-hidden="true"></i>Sandbox OCR
        </a>
        <a class="btn btn-sm btn-primary" href="<?= e($intakeUrl) ?>">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Recepție nouă (OCR)
        </a>
    </div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-2">
            <div class="text-muted small">Facturi înregistrate</div>
            <div class="fs-4 fw-semibold"><?= e((string) $kpis['facturi']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-2">
            <div class="text-muted small">Articole (piese)</div>
            <div class="fs-4 fw-semibold"><?= e((string) $kpis['articole']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-2">
            <div class="text-muted small">Valoare totală articole</div>
            <div class="fs-4 fw-semibold"><?= e(format_number_ro($kpis['valoare'])) ?> <span class="fs-6 text-muted">lei</span></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-2">
            <div class="text-muted small">Furnizori distincți</div>
            <div class="fs-4 fw-semibold"><?= e((string) $kpis['furnizori']) ?></div>
        </div></div>
    </div>
</div>

<?php if ($invoices === []): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
            Nicio factură în tracker încă.<br>
            Apasă <strong>Recepție nouă (OCR)</strong> ca să înregistrezi prima factură de piese.
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:3%"></th>
                        <th>Factură</th>
                        <th>Data</th>
                        <th>Furnizor</th>
                        <th>CUI</th>
                        <th class="text-end">Articole</th>
                        <th class="text-end">Valoare articole</th>
                        <th class="text-end">Total factură</th>
                        <th>Fișier</th>
                        <th>Adăugat</th>
                        <th style="width:5%"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php
                    $invoiceId = (int) $invoice['id'];
                    $collapseId = 'ocr-inv-' . $invoiceId;
                    $storedFile = (string) ($invoice['fisier_stocat'] ?? '');
                    $lines = is_array($invoice['articole'] ?? null) ? $invoice['articole'] : [];
                    ?>
                    <tr data-bs-toggle="collapse" data-bs-target="#<?= e($collapseId) ?>" role="button" aria-expanded="false">
                        <td><i class="bi bi-chevron-down text-muted" aria-hidden="true"></i></td>
                        <td class="fw-semibold"><?= e((string) ($invoice['numar_factura'] ?? '') !== '' ? (string) $invoice['numar_factura'] : '(fără număr)') ?></td>
                        <td><?= e(format_date_ro($invoice['data_facturii'] ?? null)) ?></td>
                        <td><?= e((string) ($invoice['furnizor'] ?? '-')) ?></td>
                        <td><?= e((string) ($invoice['cui_furnizor'] ?? '-')) ?></td>
                        <td class="text-end"><span class="badge text-bg-secondary"><?= e((string) (int) ($invoice['numar_articole'] ?? 0)) ?></span></td>
                        <td class="text-end"><?= e(format_number_ro($invoice['valoare_articole'] ?? 0)) ?> <?= e((string) ($invoice['moneda'] ?? 'RON')) ?></td>
                        <td class="text-end"><?= $invoice['total_factura'] !== null ? e(format_number_ro($invoice['total_factura'])) . ' ' . e((string) ($invoice['moneda'] ?? 'RON')) : '<span class="text-muted">-</span>' ?></td>
                        <td>
                            <?php if ($storedFile !== ''): ?>
                                <a class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation()"
                                   href="<?= e(url('uploads/ocr_piese/' . rawurlencode($storedFile))) ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= e(format_datetime_ro($invoice['created_at'] ?? null)) ?>
                            <?php if (!empty($invoice['creat_de'])): ?><br><?= e((string) $invoice['creat_de']) ?><?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?= e($deleteUrl) ?>" onclick="event.stopPropagation()"
                                  onsubmit="return confirm('Ștergi această factură și toate articolele ei din tracker?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="invoice_id" value="<?= e((string) $invoiceId) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Șterge din tracker">
                                    <i class="bi bi-trash3" aria-hidden="true"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <tr class="collapse" id="<?= e($collapseId) ?>">
                        <td colspan="11" class="bg-light p-0">
                            <div class="p-3">
                                <?php if ($lines === []): ?>
                                    <span class="text-muted small">Fără articole.</span>
                                <?php else: ?>
                                    <table class="table table-sm table-bordered bg-white mb-1">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Denumire piesă</th>
                                                <th>Cod</th>
                                                <th>Categorie</th>
                                                <th>U.M.</th>
                                                <th class="text-end">Cantitate</th>
                                                <th class="text-end">Preț unitar</th>
                                                <th class="text-end">Valoare</th>
                                                <th>Sursă</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($lines as $line): ?>
                                            <tr>
                                                <td><?= e((string) $line['denumire']) ?></td>
                                                <td><?= e((string) ($line['cod_piesa'] ?? '') !== '' ? (string) $line['cod_piesa'] : '-') ?></td>
                                                <td><?= e((string) ($line['categorie'] ?? '') !== '' ? (string) $line['categorie'] : '-') ?></td>
                                                <td><?= e((string) ($line['unitate_masura'] ?? 'buc')) ?></td>
                                                <td class="text-end"><?= e(format_number_ro($line['cantitate'] ?? 0)) ?></td>
                                                <td class="text-end"><?= e(format_number_ro($line['pret_unitar'] ?? 0)) ?></td>
                                                <td class="text-end fw-semibold"><?= e(format_number_ro($line['valoare'] ?? 0)) ?></td>
                                                <td>
                                                    <?php if (!empty($line['din_ocr'])): ?>
                                                        <span class="badge text-bg-info text-dark">OCR</span>
                                                    <?php else: ?>
                                                        <span class="badge text-bg-secondary">manual</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                                <?php if (!empty($invoice['observatii'])): ?>
                                    <div class="small text-muted"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i><?= e((string) $invoice['observatii']) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
