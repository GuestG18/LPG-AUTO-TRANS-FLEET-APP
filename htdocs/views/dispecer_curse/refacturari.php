<?php
$selectedRaceIdValue = (int) ($selectedRaceId ?? 0);
$selectedRaceData = is_array($selectedRace ?? null) ? $selectedRace : null;
$historyRows = is_array($refacturareRows ?? null) ? $refacturareRows : [];
$expenseEntryTypes = is_array($expenseEntryTypes ?? null) ? $expenseEntryTypes : (array) ($expenseTypes ?? []);
unset($expenseEntryTypes['motorina']);
$historyCount = count($historyRows);
$historyTotal = 0.0;
foreach ($historyRows as $historyRow) {
    $historyTotal += (float) ($historyRow['refacturare_suma'] ?? 0);
}
$selectedRefacturareType = (string) ($formData['refacturare_tip_cheltuiala'] ?? '');
if (!isset($expenseEntryTypes[$selectedRefacturareType])) {
    $selectedRefacturareType = '';
}
$showRefacturareRoadTaxDetails = $selectedRefacturareType === 'taxe_drum';

$buildRaceLabel = static function (array $raceRow, array $transportTypes): string {
    $parts = [];
    $plate = trim((string) ($raceRow['nr_inmatriculare'] ?? ''));
    if ($plate !== '') {
        $parts[] = $plate;
    }

    $driver = trim((string) ($raceRow['sofer_nume'] ?? ''));
    if ($driver !== '') {
        $parts[] = $driver;
    }

    $beneficiary = trim((string) ($raceRow['beneficiar_nume'] ?? ''));
    if ($beneficiary !== '') {
        $parts[] = $beneficiary;
    }

    $transportType = trim((string) ($raceRow['tip_transport'] ?? ''));
    if ($transportType !== '') {
        $parts[] = (string) ($transportTypes[$transportType] ?? $transportType);
    }

    $startDate = trim((string) ($raceRow['data_inceput'] ?? ''));
    if ($startDate !== '') {
        $startTime = trim((string) ($raceRow['ora_inceput'] ?? ''));
        $parts[] = format_date_ro($startDate) . ($startTime !== '' ? ' ' . substr($startTime, 0, 5) : '');
    }

    if ($parts === []) {
        return 'Cursa';
    }

    return implode(' | ', $parts);
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h4 mb-0">Refacturari curse</h2>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la Dispecer curse</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <h3 class="h6 mb-0">Selecteaza cursa</h3>
    </div>
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="dispecer_curse">
            <input type="hidden" name="action" value="refacturari">
            <div class="col-12 col-xl-9">
                <label class="form-label" for="refacturare_race_id">Cursa</label>
                <select class="form-select <?= isset($formErrors['race_id']) ? 'is-invalid' : '' ?>" id="refacturare_race_id" name="race_id">
                    <option value="">-- Selecteaza cursa --</option>
                    <?php foreach ((array) ($raceOptions ?? []) as $raceOption): ?>
                        <?php $raceOptionId = (int) ($raceOption['id'] ?? 0); ?>
                        <?php if ($raceOptionId <= 0) { continue; } ?>
                        <option value="<?= e((string) $raceOptionId) ?>" <?= $selectedRaceIdValue === $raceOptionId ? 'selected' : '' ?>>
                            #<?= e((string) $raceOptionId) ?> - <?= e($buildRaceLabel($raceOption, $transportTypes)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($formErrors['race_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['race_id']) ?></div><?php endif; ?>
            </div>
            <div class="col-12 col-xl-3 d-grid">
                <button type="submit" class="btn btn-outline-primary">Incarca detalii cursa</button>
            </div>
        </form>

        <?php if ($selectedRaceData !== null): ?>
            <?php
                $selectedRaceFacturare = (float) ($selectedRaceData['total_facturare'] ?? 0);
                $selectedRaceRefacturare = (float) ($selectedRaceData['total_refacturare'] ?? 0);
                $selectedRaceRefacturareFacturata = (float) ($selectedRaceData['total_refacturare_facturata'] ?? 0);
                $selectedRaceRefacturareNefacturata = (float) ($selectedRaceData['total_refacturare_nefacturata'] ?? $selectedRaceRefacturare);
                $selectedRaceTransport = trim((string) ($selectedRaceData['tip_transport'] ?? ''));
                $selectedRaceTransportLabel = (string) ($transportTypes[$selectedRaceTransport] ?? ($selectedRaceTransport !== '' ? $selectedRaceTransport : '-'));
            ?>
            <div class="refacturare-race-summary mt-3">
                <div class="refacturare-race-summary-row">
                    <span class="refacturare-race-summary-label">Cursa selectata</span>
                    <span class="refacturare-race-summary-value">#<?= e((string) $selectedRaceIdValue) ?> - <?= e($buildRaceLabel($selectedRaceData, $transportTypes)) ?></span>
                </div>
                <div class="refacturare-race-summary-row">
                    <span class="refacturare-race-summary-label">Tip transport</span>
                    <span class="refacturare-race-summary-value"><?= e($selectedRaceTransportLabel) ?></span>
                </div>
                <div class="refacturare-race-summary-row">
                    <span class="refacturare-race-summary-label">Total facturare cursa</span>
                    <span class="refacturare-race-summary-value"><?= e(format_number_ro($selectedRaceFacturare, 2)) ?> lei</span>
                </div>
                <div class="refacturare-race-summary-row">
                    <span class="refacturare-race-summary-label">Total refacturare cursa</span>
                    <span class="refacturare-race-summary-value"><?= e(format_number_ro($selectedRaceRefacturare, 2)) ?> lei</span>
                </div>
                <div class="refacturare-race-summary-row">
                    <span class="refacturare-race-summary-label">Refacturare deja facturata</span>
                    <span class="refacturare-race-summary-value"><?= e(format_number_ro($selectedRaceRefacturareFacturata, 2)) ?> lei</span>
                </div>
                <div class="refacturare-race-summary-row">
                    <span class="refacturare-race-summary-label">Refacturare in asteptare factura</span>
                    <span class="refacturare-race-summary-value"><?= e(format_number_ro($selectedRaceRefacturareNefacturata, 2)) ?> lei</span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <h3 class="h6 mb-0">Adauga refacturare</h3>
    </div>
    <div class="card-body">
        <?php if ($selectedRaceData === null): ?>
            <div class="alert alert-warning mb-0">Selecteaza mai intai o cursa pentru a adauga refacturarea.</div>
        <?php else: ?>
            <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'store_refacturare'])) ?>" enctype="multipart/form-data" class="row g-3" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="race_id" value="<?= e((string) $selectedRaceIdValue) ?>">

                <div class="col-12 col-md-4">
                    <label class="form-label" for="refacturare_tip_cheltuiala">Tip refacturare <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($formErrors['refacturare_tip_cheltuiala']) ? 'is-invalid' : '' ?>" id="refacturare_tip_cheltuiala" name="refacturare_tip_cheltuiala" required>
                        <option value="" <?= $selectedRefacturareType === '' ? 'selected' : '' ?>>-- Selecteaza tipul --</option>
                        <?php foreach ($expenseEntryTypes as $typeValue => $typeLabel): ?>
                            <option value="<?= e((string) $typeValue) ?>" <?= $selectedRefacturareType === (string) $typeValue ? 'selected' : '' ?>>
                                <?= e((string) $typeLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Motorina se introduce separat in modulul Alimentari.</div>
                    <?php if (isset($formErrors['refacturare_tip_cheltuiala'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_tip_cheltuiala']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label" for="refacturare_suma">Suma refacturare <span class="text-danger">*</span></label>
                    <input type="number" class="form-control <?= isset($formErrors['refacturare_suma']) ? 'is-invalid' : '' ?>" id="refacturare_suma" name="refacturare_suma" min="0.01" step="0.01" value="<?= e((string) ($formData['refacturare_suma'] ?? '')) ?>" required>
                    <?php if (isset($formErrors['refacturare_suma'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_suma']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label" for="refacturare_data">Data refacturare <span class="text-danger">*</span></label>
                    <input type="date" class="form-control <?= isset($formErrors['refacturare_data']) ? 'is-invalid' : '' ?>" id="refacturare_data" name="refacturare_data" value="<?= e((string) ($formData['refacturare_data'] ?? date('Y-m-d'))) ?>" required>
                    <?php if (isset($formErrors['refacturare_data'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_data']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-4">
                    <label class="form-label" for="data_cheltuiala">Data cheltuiala (contabila) <span class="text-danger">*</span></label>
                    <input type="date" class="form-control <?= isset($formErrors['data_cheltuiala']) ? 'is-invalid' : '' ?>" id="data_cheltuiala" name="data_cheltuiala" value="<?= e((string) ($formData['data_cheltuiala'] ?? date('Y-m-d'))) ?>" required>
                    <?php if (isset($formErrors['data_cheltuiala'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_cheltuiala']) ?></div><?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label" for="refacturare_observatii">Motiv / detalii refacturare</label>
                    <textarea class="form-control <?= isset($formErrors['refacturare_observatii']) ? 'is-invalid' : '' ?>" id="refacturare_observatii" name="refacturare_observatii" rows="3"><?= e((string) ($formData['refacturare_observatii'] ?? '')) ?></textarea>
                    <?php if (isset($formErrors['refacturare_observatii'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_observatii']) ?></div><?php endif; ?>
                    <div class="form-text">Descrie clar pentru ce este refacturarea (ex: taxa acces, interventie client, alte costuri transferate).</div>
                </div>

                <div class="col-12 d-none" data-role="refacturare-road-tax-breakdown">
                    <div class="border rounded p-3">
                        <label class="form-label mb-2">Detalii refacturare taxe drum</label>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4"><strong>Taxa acces</strong></div>
                            <div class="col-6 col-md-4">
                                <input type="number" class="form-control form-control-sm <?= isset($formErrors['refacturare_taxa_acces_bucati']) ? 'is-invalid' : '' ?>" id="refacturare_taxa_acces_bucati" name="refacturare_taxa_acces_bucati" min="0" step="1" placeholder="Bucati" value="<?= e((string) ($formData['refacturare_taxa_acces_bucati'] ?? '')) ?>">
                                <?php if (isset($formErrors['refacturare_taxa_acces_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_taxa_acces_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input type="number" class="form-control form-control-sm <?= isset($formErrors['refacturare_taxa_acces_pret']) ? 'is-invalid' : '' ?>" id="refacturare_taxa_acces_pret" name="refacturare_taxa_acces_pret" min="0" step="0.01" placeholder="Pret / buc" value="<?= e((string) ($formData['refacturare_taxa_acces_pret'] ?? '')) ?>">
                                <?php if (isset($formErrors['refacturare_taxa_acces_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_taxa_acces_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4"><strong>Port</strong></div>
                            <div class="col-6 col-md-4">
                                <input type="number" class="form-control form-control-sm <?= isset($formErrors['refacturare_port_bucati']) ? 'is-invalid' : '' ?>" id="refacturare_port_bucati" name="refacturare_port_bucati" min="0" step="1" placeholder="Bucati" value="<?= e((string) ($formData['refacturare_port_bucati'] ?? '')) ?>">
                                <?php if (isset($formErrors['refacturare_port_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_port_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input type="number" class="form-control form-control-sm <?= isset($formErrors['refacturare_port_pret']) ? 'is-invalid' : '' ?>" id="refacturare_port_pret" name="refacturare_port_pret" min="0" step="0.01" placeholder="Pret / buc" value="<?= e((string) ($formData['refacturare_port_pret'] ?? '')) ?>">
                                <?php if (isset($formErrors['refacturare_port_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_port_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-12 col-md-4"><strong>Trece</strong></div>
                            <div class="col-6 col-md-4">
                                <input type="number" class="form-control form-control-sm <?= isset($formErrors['refacturare_trece_bucati']) ? 'is-invalid' : '' ?>" id="refacturare_trece_bucati" name="refacturare_trece_bucati" min="0" step="1" placeholder="Bucati" value="<?= e((string) ($formData['refacturare_trece_bucati'] ?? '')) ?>">
                                <?php if (isset($formErrors['refacturare_trece_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_trece_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input type="number" class="form-control form-control-sm <?= isset($formErrors['refacturare_trece_pret']) ? 'is-invalid' : '' ?>" id="refacturare_trece_pret" name="refacturare_trece_pret" min="0" step="0.01" placeholder="Pret / buc" value="<?= e((string) ($formData['refacturare_trece_pret'] ?? '')) ?>">
                                <?php if (isset($formErrors['refacturare_trece_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_trece_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <div class="form-text mt-2">Suma se calculeaza automat din: bucati x pret pentru fiecare linie.</div>
                    </div>
                </div>

                <div class="col-12 col-lg-6">
                    <label class="form-label" for="refacturare_document_upload">Document refacturare (upload)</label>
                    <input type="file" class="form-control <?= isset($formErrors['refacturare_document_upload']) ? 'is-invalid' : '' ?>" id="refacturare_document_upload" name="refacturare_document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                    <?php if (isset($formErrors['refacturare_document_upload'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['refacturare_document_upload']) ?></div><?php endif; ?>
                    <div class="form-text">Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.</div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Adauga refacturare</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h3 class="h6 mb-0">Istoric refacturari</h3>
        <div class="small text-muted">
            Intrari: <strong><?= e((string) $historyCount) ?></strong> |
            Total listat: <strong><?= e(format_number_ro($historyTotal, 2)) ?> lei</strong>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 120px;">Data</th>
                        <th style="min-width: 300px;">Cursa</th>
                        <th style="min-width: 170px;">Tip</th>
                        <th class="text-end" style="min-width: 140px;">Suma</th>
                        <th style="min-width: 280px;">Motiv / detalii</th>
                        <th style="min-width: 180px;">Document</th>
                        <th style="min-width: 180px;">Status factura</th>
                        <th class="text-end" style="min-width: 220px;">Actiuni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($historyRows === []): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Nu exista refacturari inregistrate.</td>
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
                                $historyTypeLabel = (string) ($expenseTypes[$historyTypeKey] ?? ($historyTypeKey !== '' ? $historyTypeKey : '-'));
                                $historyAmount = (float) ($historyRow['refacturare_suma'] ?? 0);
                                $historyDocPath = trim((string) ($historyRow['refacturare_document_path'] ?? ''));
                                $historyDocName = trim((string) ($historyRow['refacturare_document_original_name'] ?? ''));
                                $historyDocUrl = $historyDocPath !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($historyDocPath)) : null;
                                $historyObs = trim((string) ($historyRow['refacturare_observatii'] ?? ''));
                                $historyIsInvoiced = (int) ($historyRow['refacturare_facturata'] ?? 0) === 1;
                                $historyInvoicedAt = trim((string) ($historyRow['refacturare_facturata_at'] ?? ''));
                                $historyTaxDetails = json_decode((string) ($historyRow['refacturare_detalii'] ?? ''), true);
                                $historyTaxNotes = [];
                                if (is_array($historyTaxDetails)) {
                                    foreach (['taxa_acces' => 'Taxa acces', 'port' => 'Port', 'trece' => 'Trece'] as $taxKey => $taxLabel) {
                                        $taxRow = $historyTaxDetails[$taxKey] ?? null;
                                        if (!is_array($taxRow)) {
                                            continue;
                                        }
                                        $qty = is_numeric((string) ($taxRow['bucati'] ?? null)) ? (float) $taxRow['bucati'] : 0;
                                        $price = is_numeric((string) ($taxRow['pret'] ?? null)) ? (float) $taxRow['pret'] : 0;
                                        if ($qty <= 0 || $price <= 0) {
                                            continue;
                                        }
                                        $historyTaxNotes[] = $taxLabel . ': ' . format_number_ro($qty, 2) . ' x ' . format_number_ro($price, 2);
                                    }
                                }
                            ?>
                            <tr>
                                <td>
                                    <?= e(format_date_ro((string) ($historyRow['refacturare_data'] ?? ''))) ?>
                                    <?php if (!empty($historyRow['created_at'])): ?>
                                        <div class="small text-muted"><?= e(format_datetime_ro((string) $historyRow['created_at'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold">#<?= e((string) $historyRaceId) ?> - <?= e(trim((string) ($historyRow['nr_inmatriculare'] ?? '-'))) ?></div>
                                    <div class="small text-muted"><?= e($buildRaceLabel($historyRow, $transportTypes)) ?></div>
                                </td>
                                <td><?= e($historyTypeLabel) ?></td>
                                <td class="text-end fw-semibold"><?= e(format_number_ro($historyAmount, 2)) ?> lei</td>
                                <td>
                                    <?php if ($historyObs !== ''): ?>
                                        <div><?= nl2br(e($historyObs)) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                    <?php if ($historyTaxNotes !== []): ?>
                                        <div class="small text-muted mt-1"><?= e(implode(' | ', $historyTaxNotes)) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($historyDocUrl !== null): ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e($historyDocUrl) ?>" target="_blank" rel="noopener">
                                            <?= e($historyDocName !== '' ? $historyDocName : basename($historyDocPath)) ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($historyIsInvoiced): ?>
                                        <span class="badge text-bg-success">Factura emisa</span>
                                        <?php if ($historyInvoicedAt !== ''): ?>
                                            <div class="small text-muted mt-1"><?= e(format_datetime_ro($historyInvoicedAt)) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning text-dark">In asteptare</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $historyRaceId, 'expense_id' => $historyExpenseId])) ?>">
                                            Editeaza
                                        </a>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'toggle_refacturare_facturata'])) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="race_id" value="<?= e((string) $historyRaceId) ?>">
                                            <input type="hidden" name="expense_id" value="<?= e((string) $historyExpenseId) ?>">
                                            <input type="hidden" name="is_invoiced" value="<?= $historyIsInvoiced ? '0' : '1' ?>">
                                            <?php if ($historyIsInvoiced): ?>
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Anulezi marcarea facturii pentru aceasta refacturare?">
                                                    Anuleaza factura
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-sm btn-outline-success" data-confirm="Confirmi ca factura de refacturare a fost emisa?">
                                                    Factura emisa
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var refacturareTypeEl = document.getElementById('refacturare_tip_cheltuiala');
    var refacturareAmountEl = document.getElementById('refacturare_suma');
    var roadTaxBoxEl = document.querySelector('[data-role="refacturare-road-tax-breakdown"]');
    if (!(refacturareTypeEl instanceof HTMLSelectElement) || !(refacturareAmountEl instanceof HTMLInputElement) || !(roadTaxBoxEl instanceof HTMLElement)) {
        return;
    }

    var roadTaxFields = [
        { qty: document.getElementById('refacturare_taxa_acces_bucati'), price: document.getElementById('refacturare_taxa_acces_pret') },
        { qty: document.getElementById('refacturare_port_bucati'), price: document.getElementById('refacturare_port_pret') },
        { qty: document.getElementById('refacturare_trece_bucati'), price: document.getElementById('refacturare_trece_pret') }
    ];

    var parseNumber = function (value) {
        var normalized = String(value || '').trim().replace(',', '.');
        if (normalized === '') {
            return null;
        }
        var parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    };

    var formatAmount = function (value) {
        return (Math.round(value * 100) / 100).toFixed(2);
    };

    var calculateRoadTaxTotal = function () {
        var total = 0;
        roadTaxFields.forEach(function (field) {
            if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                return;
            }
            var qty = parseNumber(field.qty.value);
            var price = parseNumber(field.price.value);
            if (qty !== null && qty > 0 && price !== null && price > 0) {
                total += qty * price;
            }
        });
        return Math.round(total * 100) / 100;
    };

    var hasAnyRoadTaxInput = function () {
        for (var i = 0; i < roadTaxFields.length; i++) {
            var field = roadTaxFields[i];
            if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                continue;
            }
            if (String(field.qty.value || '').trim() !== '' || String(field.price.value || '').trim() !== '') {
                return true;
            }
        }
        return false;
    };

    var syncRoadTaxMode = function () {
        var isRoadTax = refacturareTypeEl.value === 'taxe_drum';
        roadTaxBoxEl.classList.toggle('d-none', !isRoadTax);

        if (!isRoadTax) {
            refacturareAmountEl.readOnly = false;
            return;
        }

        var total = calculateRoadTaxTotal();
        refacturareAmountEl.readOnly = true;
        if (total > 0) {
            refacturareAmountEl.value = formatAmount(total);
            return;
        }

        if (hasAnyRoadTaxInput()) {
            refacturareAmountEl.value = '';
        }
    };

    refacturareTypeEl.addEventListener('change', syncRoadTaxMode);
    roadTaxFields.forEach(function (field) {
        if (field.qty instanceof HTMLInputElement) {
            field.qty.addEventListener('input', syncRoadTaxMode);
        }
        if (field.price instanceof HTMLInputElement) {
            field.price.addEventListener('input', syncRoadTaxMode);
        }
    });

    syncRoadTaxMode();
});
</script>
