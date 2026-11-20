<?php
/**
 * Fisa unei inregistrari de cost (?page=istoric_financiar&action=line_view&id=N), in acelasi panou ca fisa
 * cursei din Dispecer curse: clic pe rand in Costuri → panoul creste din rand (race-view-overlay.js, partial=1).
 * Butoanele (Editeaza / muta, Tratament, Verificare, TVA, Plata, Anuleaza) inchid panoul si deschid formularele
 * existente din istoric-financiar.js (data-ifv-line-act). Doar citire aici — nicio suma nu se calculeaza in view.
 *
 * Asteapta: $linie, $extra, $dosar, $vehicul, $plati, $incluse, $istoric, $permissions, $viewPartial.
 */
$line = $linie;
$lineId = (int) $line['id'];
$cancelled = !empty($line['anulat']);
$canManage = !empty($permissions['manage']) && !$cancelled;
$canClassify = !empty($permissions['clasificare']) && !$cancelled;
$dossierKey = 'a' . (int) $dosar['id'];

$money = static fn ($value, string $currency = 'lei'): string => $value === null ? '' : format_number_ro((float) $value, 2) . ' ' . $currency;
$dateRo = static fn (?string $value): string => trim((string) $value) === '' ? '' : format_date_ro(substr((string) $value, 0, 10));
$dateTimeRo = static fn (?string $value): string => trim((string) $value) === '' ? '' : format_date_ro(substr((string) $value, 0, 10)) . ' ' . substr((string) $value, 11, 5);

$classifications = ['pret_achizitie' => 'Preț de achiziție', 'cost_direct_atribuibil' => 'Cost direct atribuibil', 'echipare_initiala' => 'Echipare inițială', 'cheltuiala_perioada' => 'Cheltuială a perioadei', 'tva' => 'TVA'];
$verifications = ['estimat' => 'Estimat', 'confirmat' => 'Confirmat', 'verificat_contabil' => 'Verificat contabil'];
$deductible = ['da' => 'Da', 'nu' => 'Nu', 'partial' => 'Parțial', 'de_confirmat' => 'De confirmat'];
$treatmentBadge = ['capitalizabil' => 'bg-success-subtle text-success-emphasis', 'cheltuiala' => 'bg-warning-subtle text-warning-emphasis', 'de_confirmat' => 'bg-light text-secondary border'][$line['tratament']] ?? 'bg-light text-secondary border';
$fieldLabels = ['suma_neta' => 'Valoare netă', 'tva' => 'TVA', 'moneda' => 'Monedă', 'curs_schimb' => 'Curs', 'descriere' => 'Descriere', 'furnizor' => 'Furnizor',
    'data_tranzactie' => 'Data tranzacției', 'document_numar' => 'Număr document', 'document_tip' => 'Tip document', 'document_fisier' => 'Fișier document',
    'financiar_categorie_id' => 'Subcategorie', 'tratament_contabil' => 'Tratament contabil', 'status_verificare' => 'Stare verificare',
    'tva_deductibil' => 'TVA deductibil', 'observatii' => 'Observații', 'anulat_la' => 'Anulare', 'cui_furnizor' => 'CUI furnizor'];
$actionLabels = ['creare' => 'Creare', 'modificare' => 'Modificare', 'anulare' => 'Anulare', 'tva' => 'TVA', 'tratament' => 'Tratament contabil', 'clasificare' => 'Clasificare', 'status_verificare' => 'Verificare'];
// Valorile codificate din istoric (tratament / verificare / TVA deductibil), pe intelesul utilizatorului.
$valueLabels = ['capitalizabil' => 'Capitalizabil', 'cheltuiala' => 'Chelt. exploatare', 'de_confirmat' => 'De confirmat',
    'estimat' => 'Estimat', 'confirmat' => 'Confirmat', 'verificat_contabil' => 'Verificat contabil', 'da' => 'Da', 'nu' => 'Nu', 'partial' => 'Parțial'];
$actionLabels += ['tratament_contabil' => 'Tratament contabil', 'tva_deductibil' => 'TVA deductibil', 'natura_cost' => 'Natura costului'];
$auditValue = static fn ($value): string => trim((string) $value) === '' ? '-' : ($valueLabels[(string) $value] ?? (string) $value);

$net = $line['suma'];
$vat = $line['tva'];
$vehicleText = $vehicul !== null ? trim($vehicul['nr_inmatriculare'] . ' — ' . trim(($vehicul['marca'] ?? '') . ' ' . ($vehicul['model'] ?? ''))) : '';
$documentText = trim(($line['document_tip'] ? $line['document_tip'] . ' ' : '') . (string) ($line['document_numar'] ?? ''));
$fileUrl = $line['are_fisier'] ? build_query_url(['page' => 'istoric_financiar', 'action' => 'file', 'sursa' => 'linie', 'id' => $lineId]) : '';

$sections = [
    'Date generale' => [
        'Dosar' => (string) $dosar['cod_dosar'],
        'Vehicul' => $vehicleText,
        'Categorie' => (string) ($line['fin_cale_text'] ?: $line['grup']),
        'Tip cost' => (string) $line['categorie_label'],
        'Descriere' => (string) ($line['descriere'] ?? ''),
        'Data tranzacției' => $dateRo($line['data']),
    ],
    'Furnizor & document' => [
        'Furnizor' => (string) ($line['furnizor'] ?? ''),
        'CUI' => (string) ($extra['cui_furnizor'] ?? ''),
        'Document' => $documentText,
        'Sursă' => $line['cheltuiala_id'] ? 'Pagina Cheltuieli #' . $line['cheltuiala_id'] : (string) ($line['sursa'] ?: 'manual'),
        'Inclusă în' => $line['inclus_in'] ? 'Linia #' . $line['inclus_in'] . ' (nu se adună)' : '',
    ],
    'Valoare' => [
        'Valoare netă' => $net !== null ? $money($net) : $money($line['suma_originala'], (string) $line['moneda']) . ' (fără curs)',
        'Sumă originală' => $line['moneda'] !== 'RON' ? $money($line['suma_originala'], (string) $line['moneda']) . ($line['curs'] ? ' × ' . format_number_ro($line['curs'], 4) : '') : '',
        'TVA' => $vat !== null ? $money($vat) . ($extra['cota_tva'] !== null && $extra['cota_tva'] !== '' ? ' (' . format_number_ro((float) $extra['cota_tva'], 0) . '%)' : '') : '',
        'Total cu TVA' => $net !== null && $vat !== null ? $money($net + $vat) : '',
        'TVA deductibil' => $deductible[$line['tva_deductibil']] ?? (string) $line['tva_deductibil'],
        'TVA recuperabil' => $extra['tva_recuperabil'] !== null ? $money($extra['tva_recuperabil'], (string) $line['moneda']) : '',
    ],
    'Clasificare financiară' => [
        'Clasificare' => $classifications[$line['clasificare']] ?? (string) $line['clasificare'],
        'Tratament contabil' => (string) $line['tratament_label'],
        'Stare verificare' => $verifications[$line['status_verificare']] ?? (string) $line['status_verificare'],
        'Plătit pe linie' => $money($line['platit'], (string) $line['moneda']),
    ],
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3<?= !empty($viewPartial) ? ' race-view-head' : '' ?>">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <h2 class="h4 mb-0">Înregistrare #<?= e((string) $lineId) ?></h2>
        <span class="badge rounded-pill <?= e($treatmentBadge) ?>"><?= e((string) $line['tratament_label']) ?></span>
        <?php if ($line['status_verificare'] === 'estimat'): ?>
            <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis">estimat</span>
        <?php endif; ?>
        <?php if ($cancelled): ?>
            <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis">anulată</span>
        <?php endif; ?>
        <span class="badge rounded-pill bg-light text-secondary border"><i class="bi bi-eye me-1" aria-hidden="true"></i>doar vizualizare</span>
    </div>
    <div class="d-flex flex-wrap gap-2" data-ifv-line-actions data-line-id="<?= e((string) $lineId) ?>" data-dossier-key="<?= e($dossierKey) ?>">
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-outline-primary" data-ifv-line-act="edit"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Editează / mută</button>
        <?php endif; ?>
        <?php if ($canClassify): ?>
            <button type="button" class="btn btn-outline-primary" data-ifv-line-act="treatment"><i class="bi bi-bank me-1" aria-hidden="true"></i>Tratament contabil</button>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-outline-primary" data-ifv-line-act="verify"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Verificare</button>
            <button type="button" class="btn btn-outline-primary" data-ifv-line-act="vat"><i class="bi bi-percent me-1" aria-hidden="true"></i>TVA</button>
            <button type="button" class="btn btn-outline-primary" data-ifv-line-act="pay"><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Plată</button>
            <button type="button" class="btn btn-outline-danger" data-ifv-line-act="cancel"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Anulează</button>
        <?php endif; ?>
        <?php if (!empty($viewPartial)): ?>
            <button type="button" class="btn btn-outline-secondary" data-race-view-close aria-label="Închide fișa înregistrării">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'istoric_financiar'])) ?>">Înapoi la listă</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($cancelled): ?>
    <div class="alert alert-danger py-2 small mb-3">
        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Anulată <?= e($dateTimeRo($line['anulat_la'])) ?><?= !empty($extra['anulat_de_nume']) ? ' de ' . e((string) $extra['anulat_de_nume']) : '' ?>:
        <?= e((string) ($line['anulat_motiv'] ?? '')) ?> — nu intră în totalurile subcategoriei, ale categoriei și ale dosarului.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <?php foreach ($sections as $sectionTitle => $fields): ?>
        <?php $fields = array_filter($fields, static fn ($value): bool => trim((string) $value) !== ''); ?>
        <?php if ($fields === []) { continue; } ?>
        <div class="col-12 col-lg-6 col-xxl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><?= e($sectionTitle) ?></div>
                <div class="card-body py-2">
                    <dl class="row mb-0 small">
                        <?php foreach ($fields as $label => $value): ?>
                            <dt class="col-5 text-muted fw-normal py-1"><?= e($label) ?></dt>
                            <?php if ($label === 'Document' && $fileUrl !== ''): ?>
                                <dd class="col-7 mb-0 py-1 fw-semibold"><a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i><?= e((string) $value) ?> <i class="bi bi-box-arrow-up-right small" aria-hidden="true"></i></a></dd>
                            <?php else: ?>
                                <dd class="col-7 mb-0 py-1 fw-semibold"><?= e((string) $value) ?></dd>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="col-12 col-lg-6 col-xxl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Observații</div>
            <div class="card-body small">
                <?php if (trim((string) ($line['observatii'] ?? '')) !== ''): ?>
                    <?= nl2br(e((string) $line['observatii'])) ?>
                <?php else: ?>
                    <span class="text-muted">Fără observații.</span>
                <?php endif; ?>
                <hr class="my-2">
                <div class="text-muted">
                    Creată<?= !empty($extra['creat_de']) ? ' de ' . e((string) $extra['creat_de']) : '' ?> <?= e($dateTimeRo($extra['created_at'] ?? null)) ?>
                    <?php if (!empty($extra['updated_at']) && $extra['updated_at'] !== $extra['created_at']): ?>
                        <br>Ultima modificare<?= !empty($extra['modificat_de']) ? ' de ' . e((string) $extra['modificat_de']) : '' ?> <?= e($dateTimeRo($extra['updated_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($incluse !== []): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Linii incluse în această înregistrare (<?= e((string) count($incluse)) ?>) <span class="text-muted fw-normal small">— informative, nu se adună</span></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th class="ps-3">Data</th><th>Descriere</th><th>Tip cost</th><th class="text-end pe-3">Valoare</th></tr></thead>
                <tbody>
                <?php foreach ($incluse as $inc): ?>
                    <tr>
                        <td class="ps-3"><?= e($dateRo($inc['data']) ?: '-') ?></td>
                        <td><?= e((string) ($inc['descriere'] ?: $inc['categorie_label'])) ?></td>
                        <td><?= e((string) $inc['categorie_label']) ?></td>
                        <td class="text-end pe-3"><?= e($inc['suma'] !== null ? $money($inc['suma']) : $money($inc['suma_originala'], (string) $inc['moneda'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">Plăți (<?= e((string) count($plati)) ?>)</div>
    <div class="card-body p-0">
        <?php if ($plati === []): ?>
            <div class="px-3 py-2 small text-muted">Nicio plată înregistrată pe această linie.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th class="ps-3">Data</th><th class="text-end">Sumă</th><th>Metodă</th><th class="pe-3">Referință</th></tr></thead>
                    <tbody>
                    <?php foreach ($plati as $payment): ?>
                        <tr>
                            <td class="ps-3"><?= e($dateRo($payment['data_plata'])) ?></td>
                            <td class="text-end"><?= e($money($payment['suma'], (string) $payment['moneda'])) ?><?= $payment['moneda'] !== 'RON' && $payment['curs_schimb'] !== null ? ' <span class="text-muted small">× ' . e(format_number_ro((float) $payment['curs_schimb'], 4)) . '</span>' : '' ?></td>
                            <td><?= e(str_replace('_', ' ', (string) ($payment['metoda'] ?? '')) ?: '-') ?></td>
                            <td class="pe-3"><?= e((string) ($payment['referinta'] ?? '') ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Istoric modificări (<?= e((string) count($istoric)) ?>)</div>
    <div class="card-body p-0">
        <?php if ($istoric === []): ?>
            <div class="px-3 py-2 small text-muted">Fără modificări înregistrate.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th class="ps-3">Data</th><th>Acțiune</th><th>Câmp</th><th>Valoare veche</th><th>Valoare nouă</th><th>Motiv</th><th class="pe-3">Utilizator</th></tr></thead>
                    <tbody>
                    <?php foreach ($istoric as $entry): ?>
                        <tr>
                            <td class="ps-3 text-nowrap"><?= e($dateTimeRo($entry['created_at'])) ?></td>
                            <td><?= e($actionLabels[$entry['actiune']] ?? (string) $entry['actiune']) ?></td>
                            <td><?= e($fieldLabels[$entry['camp']] ?? (string) ($entry['camp'] ?? '')) ?></td>
                            <td class="text-muted"><?= e($auditValue($entry['valoare_veche'])) ?></td>
                            <td><?= e($auditValue($entry['valoare_noua'])) ?></td>
                            <td><?= e((string) ($entry['motiv'] ?? '') ?: '-') ?></td>
                            <td class="pe-3"><?= e((string) ($entry['utilizator'] ?? '') ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
