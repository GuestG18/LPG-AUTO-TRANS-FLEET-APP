<?php
/**
 * Fisa unei intrari Km service (?page=dispecer_curse&action=service_km_view&id=N), ca fisa cursei:
 * din lista Km service se deschide in panoul peste pagina (race-view-overlay.js, partial=1).
 * Arata perioada, interventia, alimentarile asociate in Carburanti si cheltuielile (cele adaugate
 * aici + cele venite din facturi asociate perioadei, ex. taxele eToll pe drumul la service).
 *
 * Asteapta: $entry, $expenses, $fillups, $expenseTypes, $expenseOld, $expenseErrors, $viewPartial.
 */
$serviceKmId = (int) ($entry['id'] ?? 0);
$canManage = !function_exists('can') || can('dispecer_curse', 'service_km');
$expenses = is_array($expenses ?? null) ? $expenses : [];
$fillups = is_array($fillups ?? null) ? $fillups : [];
$expenseOld = is_array($expenseOld ?? null) ? $expenseOld : [];
$expenseErrors = is_array($expenseErrors ?? null) ? $expenseErrors : [];
$intervention = is_array($entry['interventie'] ?? null) ? $entry['interventie'] : null;
$inService = !empty($entry['in_service']);

$dateTime = static function (?string $date, ?string $time = null): string {
    $date = trim((string) $date);
    if ($date === '' || $date === '0000-00-00') {
        return '';
    }
    $time = trim((string) $time);

    return format_date_ro($date) . ($time !== '' ? ' ' . substr($time, 0, 5) : '');
};
$money = static fn (float $value): string => format_number_ro($value, 2) . ' lei';

$vehicleText = trim((string) ($entry['nr_inmatriculare'] ?? ''));
$vehicleModel = trim((string) ($entry['marca'] ?? '') . ' ' . (string) ($entry['model'] ?? ''));
if ($vehicleModel !== '') {
    $vehicleText .= ' — ' . $vehicleModel;
}

$expenseTotal = array_sum(array_map(static fn (array $row): float => (float) $row['suma'], $expenses));
$fuelLiters = 0.0;
$fuelValue = 0.0;
foreach ($fillups as $fillup) {
    if ((string) $fillup['fuel_type'] !== 'adblue') {
        $fuelLiters += (float) $fillup['quantity_liters'];
    }
    $fuelValue += (float) $fillup['total_value'];
}

$priorityLabels = class_exists('InterventionWorkflowModel') ? InterventionWorkflowModel::PRIORITY_LABELS : [];
$sections = [
    'Date generale' => [
        'Vehicul' => $vehicleText,
        'Șofer' => trim((string) ($entry['sofer_nume'] ?? '')),
        'Service' => trim((string) ($entry['service_nume'] ?? '')),
        'Motiv / reparație' => trim((string) ($entry['motiv'] ?? '')),
        'Prioritate' => (string) ($priorityLabels[(string) ($entry['prioritate'] ?? '')] ?? ''),
    ],
    'Interval' => [
        'Plecare la service' => $dateTime($entry['data_plecare'] ?? null, $entry['ora_plecare'] ?? null),
        'Întoarcere' => $inService ? 'încă în service' : $dateTime($entry['data_intoarcere'] ?? null, $entry['ora_intoarcere'] ?? null),
        'Zile inactiv' => (string) ($entry['zile_service'] ?? '') . ($inService ? ' (până azi)' : ''),
        'Km service' => format_number_ro((float) ($entry['km'] ?? 0), 0) . ' km',
    ],
    'Costuri' => [
        'Cheltuieli' => count($expenses) > 0 ? $money($expenseTotal) . ' (' . count($expenses) . ')' : '—',
        'Alimentări' => count($fillups) > 0 ? $money($fuelValue) . ' · ' . format_number_ro($fuelLiters, 0) . ' L' : '—',
        'Total perioadă' => $money($expenseTotal + $fuelValue),
    ],
];
$notes = trim((string) ($entry['observatii'] ?? ''));
$formValue = static fn (string $key, string $default = ''): string => (string) ($expenseOld[$key] ?? $default);
$minDate = date('Y-m-d', (int) strtotime((string) $entry['data_plecare'] . ' -1 day'));
$maxDate = date('Y-m-d', (int) strtotime(((string) ($entry['data_intoarcere'] ?? '') ?: date('Y-m-d')) . ' +1 day'));
$defaultDate = $inService ? date('Y-m-d') : (string) $entry['data_plecare'];
$fuelTypes = ['motorina' => 'Motorină', 'adblue' => 'AdBlue', 'benzina' => 'Benzină', 'gpl' => 'GPL'];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3<?= !empty($viewPartial) ? ' race-view-head' : '' ?>">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <h2 class="h4 mb-0"><i class="bi bi-tools me-1" aria-hidden="true"></i>Km service #<?= e((string) $serviceKmId) ?></h2>
        <?php if ($inService): ?>
            <span class="badge rounded-pill text-bg-warning"><i class="bi bi-wrench-adjustable me-1" aria-hidden="true"></i>În service</span>
        <?php else: ?>
            <span class="badge rounded-pill bg-success-subtle text-success-emphasis">Revenit</span>
        <?php endif; ?>
        <span class="badge rounded-pill bg-light text-secondary border"><?= e((string) ($entry['nr_inmatriculare'] ?? '')) ?></span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canManage && !empty($viewPartial)): ?>
            <button type="button" class="btn btn-outline-primary" data-service-km-edit-id="<?= e((string) $serviceKmId) ?>" data-race-view-close>
                <i class="bi bi-pencil me-1" aria-hidden="true"></i><?= $inService ? 'Întoarcere / Editează' : 'Editează' ?>
            </button>
        <?php endif; ?>
        <?php if ($intervention !== null): ?>
            <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'intervention_id' => (int) $intervention['id']])) ?>">
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Intervenția #<?= (int) $intervention['id'] ?>
            </a>
        <?php endif; ?>
        <?php if (!empty($viewPartial)): ?>
            <button type="button" class="btn btn-outline-secondary" data-race-view-close aria-label="Închide fișa Km service">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse']) . '#service-km-panel') ?>">Înapoi la listă</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ($sections as $sectionTitle => $fields): ?>
        <?php $fields = array_filter($fields, static fn ($value): bool => trim((string) $value) !== ''); ?>
        <?php if ($fields === []) { continue; } ?>
        <div class="col-12 col-lg-6 col-xxl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold"><?= e($sectionTitle) ?></div>
                <div class="card-body py-2">
                    <dl class="row mb-0 small">
                        <?php foreach ($fields as $label => $value): ?>
                            <dt class="col-5 text-muted fw-normal py-1"><?= e($label) ?></dt>
                            <dd class="col-7 mb-0 py-1 fw-semibold"><?= e((string) $value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="col-12 col-lg-6 col-xxl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Intervenție &amp; observații</div>
            <div class="card-body small">
                <?php if ($intervention !== null): ?>
                    <div class="mb-2">
                        <a class="fw-semibold" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'intervention_id' => (int) $intervention['id']])) ?>">#<?= (int) $intervention['id'] ?></a>
                        <span class="iv2-badge st-<?= e((string) $intervention['status_key']) ?>"><?= e((string) $intervention['status_label']) ?></span>
                    </div>
                    <?php $components = array_filter((array) ($intervention['items'] ?? []), static fn (array $item): bool => (string) ($item['stare_evaluare'] ?? '') !== 'exclusa'); ?>
                    <?php if ($components !== []): ?>
                        <ul class="ps-3 mb-2">
                            <?php foreach ($components as $item): ?>
                                <li><?= e((string) $item['nume']) ?><?= trim((string) ($item['pozitie'] ?? '')) !== '' ? ' <span class="text-muted">(' . e((string) $item['pozitie']) . ')</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted mb-2">Fără intervenție în Mentenanță.</div>
                <?php endif; ?>
                <?= $notes !== '' ? nl2br(e($notes)) : '<span class="text-muted">Fără observații.</span>' ?>
                <hr class="my-2">
                <div class="text-muted">
                    Adăugată<?= trim((string) ($entry['created_by_name'] ?? '')) !== '' ? ' de ' . e((string) $entry['created_by_name']) : '' ?>
                    <?= e($dateTime(substr((string) ($entry['created_at'] ?? ''), 0, 10), substr((string) ($entry['created_at'] ?? ''), 11, 5))) ?>
                    <?php if (trim((string) ($entry['updated_at'] ?? '')) !== ''): ?>
                        <br>Ultima modificare<?= trim((string) ($entry['updated_by_name'] ?? '')) !== '' ? ' de ' . e((string) $entry['updated_by_name']) : '' ?>
                        <?= e($dateTime(substr((string) $entry['updated_at'], 0, 10), substr((string) $entry['updated_at'], 11, 5))) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3" id="service-km-expenses-<?= e((string) $serviceKmId) ?>">
    <?php if ($canManage): ?>
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Adaugă cheltuială</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'service_km_expense_store'])) ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="service_km_id" value="<?= e((string) $serviceKmId) ?>">
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="skc_tip_<?= e((string) $serviceKmId) ?>">Tip cheltuială <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm<?= isset($expenseErrors['tip']) ? ' is-invalid' : '' ?>" id="skc_tip_<?= e((string) $serviceKmId) ?>" name="tip" required>
                            <option value="">-- Selectează --</option>
                            <?php foreach ($expenseTypes as $typeKey => $typeLabel): ?>
                                <option value="<?= e($typeKey) ?>" <?= $formValue('tip') === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($expenseErrors['tip'])): ?><div class="invalid-feedback"><?= e((string) $expenseErrors['tip']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="skc_data_<?= e((string) $serviceKmId) ?>">Data <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm<?= isset($expenseErrors['data_cheltuiala']) ? ' is-invalid' : '' ?>" id="skc_data_<?= e((string) $serviceKmId) ?>" name="data_cheltuiala" required
                               min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>" value="<?= e($formValue('data_cheltuiala', $defaultDate)) ?>">
                        <?php if (isset($expenseErrors['data_cheltuiala'])): ?><div class="invalid-feedback"><?= e((string) $expenseErrors['data_cheltuiala']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="skc_suma_<?= e((string) $serviceKmId) ?>">Sumă (lei, cu TVA) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control form-control-sm<?= isset($expenseErrors['suma']) ? ' is-invalid' : '' ?>" id="skc_suma_<?= e((string) $serviceKmId) ?>" name="suma" min="0.01" step="0.01" inputmode="decimal" required value="<?= e($formValue('suma')) ?>">
                        <?php if (isset($expenseErrors['suma'])): ?><div class="invalid-feedback"><?= e((string) $expenseErrors['suma']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small mb-1" for="skc_locatie_<?= e((string) $serviceKmId) ?>">Furnizor / locație</label>
                        <input type="text" class="form-control form-control-sm" id="skc_locatie_<?= e((string) $serviceKmId) ?>" name="locatie" maxlength="190" placeholder="<?= e((string) ($entry['service_nume'] ?? '')) ?>" value="<?= e($formValue('locatie')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" for="skc_obs_<?= e((string) $serviceKmId) ?>">Observații</label>
                        <textarea class="form-control form-control-sm" id="skc_obs_<?= e((string) $serviceKmId) ?>" name="observatii" rows="2" maxlength="2000"><?= e($formValue('observatii')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1" for="skc_doc_<?= e((string) $serviceKmId) ?>">Document doveditor (upload)</label>
                        <input type="file" class="form-control form-control-sm<?= isset($expenseErrors['document']) ? ' is-invalid' : '' ?>" id="skc_doc_<?= e((string) $serviceKmId) ?>" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                        <div class="form-text">PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.<?= isset($expenseErrors['document']) ? '' : ' Data: în perioada de service (±1 zi).' ?></div>
                        <?php if (isset($expenseErrors['document'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseErrors['document']) ?></div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adaugă cheltuiala</button>
                        <span class="small text-muted ms-2">Motorina vine din Carburanți (alimentările de mai jos).</span>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12<?= $canManage ? ' col-xl-7' : '' ?>">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Cheltuieli (<?= count($expenses) ?>)</span>
                <span class="small text-muted">Total: <strong><?= e($money($expenseTotal)) ?></strong></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th class="ps-3">Data</th>
                            <th>Tip</th>
                            <th class="text-end">Sumă</th>
                            <th>Observații</th>
                            <th>Document</th>
                            <?php if ($canManage): ?><th class="pe-3"></th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($expenses === []): ?>
                            <tr><td colspan="<?= $canManage ? 6 : 5 ?>" class="text-center text-muted py-4">Nu există cheltuieli pentru această perioadă de service.</td></tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $expense): ?>
                                <?php
                                $docPath = (string) ($expense['document_path'] ?? '');
                                $docUrl = $docPath !== '' ? trip_expense_document_url($docPath) : null;
                                $invoiceId = $expense['factura_id'] !== null ? (int) $expense['factura_id'] : 0;
                                ?>
                                <tr>
                                    <td class="ps-3 text-nowrap"><?= e(format_date_ro((string) $expense['data_cheltuiala'])) ?></td>
                                    <td>
                                        <?= e((string) ($expenseTypes[(string) $expense['tip']] ?? $expense['tip'])) ?>
                                        <?php if (trim((string) ($expense['locatie'] ?? '')) !== ''): ?><div class="small text-muted"><?= e((string) $expense['locatie']) ?></div><?php endif; ?>
                                        <?php if ($invoiceId > 0): ?>
                                            <div><a class="badge bg-info-subtle text-info-emphasis text-decoration-none" href="<?= e(build_query_url(['page' => 'facturi', 'action' => 'view', 'id' => $invoiceId])) ?>" title="Asociată automat din Facturi (ex. sesiune eToll pe drumul la service)">Factura #<?= $invoiceId ?></a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap"><?= e($money((float) $expense['suma'])) ?></td>
                                    <td class="small"><?= e(trim((string) ($expense['observatii'] ?? '')) ?: '-') ?></td>
                                    <td>
                                        <?php if ($docUrl !== null): ?>
                                            <a class="btn btn-sm btn-outline-secondary text-truncate" style="max-width: 180px;" href="<?= e($docUrl) ?>" target="_blank" rel="noopener" title="<?= e((string) ($expense['document_original_name'] ?? '')) ?>"><?= e((string) (($expense['document_original_name'] ?? '') ?: basename($docPath))) ?></a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canManage): ?>
                                        <td class="pe-3 text-end">
                                            <?php if ($invoiceId === 0): ?>
                                                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'service_km_expense_delete'])) ?>" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="expense_id" value="<?= e((string) $expense['id']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Șterge cheltuiala" data-confirm="Ștergi cheltuiala de <?= e($money((float) $expense['suma'])) ?> din <?= e(format_date_ro((string) $expense['data_cheltuiala'])) ?>?"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Alimentări asociate (<?= count($fillups) ?>)</span>
        <a class="small" href="<?= e(build_query_url(['page' => 'carburanti', 'date_from' => date('Y-m-d', (int) strtotime((string) $entry['data_plecare'] . ' -1 day')), 'date_to' => date('Y-m-d', (int) strtotime(((string) ($entry['data_intoarcere'] ?? '') ?: date('Y-m-d')) . ' +1 day')), 'vehicle' => (string) ($entry['nr_inmatriculare'] ?? '')])) ?>">Deschide în Carburanți <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th class="ps-3">Data</th>
                    <th>Carburant</th>
                    <th class="text-end">Litri</th>
                    <th class="text-end">Valoare</th>
                    <th>Stație</th>
                    <th class="pe-3">Asociere</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($fillups === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nicio alimentare CardOil asociată (între plecare și întoarcere, ±12 h, sau manual din Carburanți).</td></tr>
                <?php else: ?>
                    <?php foreach ($fillups as $fillup): ?>
                        <tr>
                            <td class="ps-3 text-nowrap"><?= e(date('d.m.Y H:i', (int) strtotime((string) $fillup['fillup_datetime']))) ?></td>
                            <td><?= e((string) ($fuelTypes[(string) $fillup['fuel_type']] ?? $fillup['fuel_type'])) ?></td>
                            <td class="text-end"><?= e(format_number_ro((float) $fillup['quantity_liters'], 2)) ?></td>
                            <td class="text-end text-nowrap"><?= e($money((float) $fillup['total_value'])) ?></td>
                            <td class="small"><?= e(trim((string) ($fillup['station_name'] ?? '')) ?: '-') ?></td>
                            <td class="pe-3 small text-muted"><?= (string) $fillup['match_type'] === 'manual' ? 'manual' : 'automat' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
