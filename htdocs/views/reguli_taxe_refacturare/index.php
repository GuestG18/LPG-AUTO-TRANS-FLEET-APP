<?php
/**
 * Pagina "Reguli taxe refacturare": pe ce rute se cere taxa acces, port, trecere sau
 * taxe drum. Formular (adaugare / editare) + lista regulilor + sugestii din istoric.
 */
$rules = is_array($rules ?? null) ? $rules : [];
$suggestions = is_array($suggestions ?? null) ? $suggestions : [];
$places = is_array($places ?? null) ? $places : ['zones' => [], 'locations' => []];
$feeTypes = is_array($feeTypes ?? null) ? $feeTypes : [];
$transportTypes = is_array($transportTypes ?? null) ? $transportTypes : [];
$formData = is_array($formData ?? null) ? $formData : [];
$formErrors = is_array($formErrors ?? null) ? $formErrors : [];
$editingId = (int) ($editingId ?? 0);
$canManage = !empty($canManage);

$pageUrl = build_query_url(['page' => 'reguli_taxe_refacturare']);
$field = static fn(string $key): string => (string) ($formData[$key] ?? '');
$invalid = static fn(string $key): string => isset($formErrors[$key]) ? ' is-invalid' : '';
$money = static fn(mixed $value): string => $value !== null && $value !== '' ? format_number_ro((float) $value, 2) . ' lei' : '-';
$isActiveForm = $formData === [] || !empty($formData['activ']);
$activeCount = count(array_filter($rules, static fn(array $rule): bool => (int) $rule['activ'] === 1));
$placeLabel = static fn(?string $name, ?string $beneficiary): string => (string) $name . ($beneficiary ? ' (' . $beneficiary . ')' : '');
?>

<div class="fee-rules-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="h4 mb-1">Reguli taxe refacturare</h2>
            <p class="text-muted mb-0">
                Pe ce rute se cere taxa acces, port, trecere sau taxe drum. Cand o cursa se potriveste unei reguli active
                si nu are taxa, operatorul o vede in panoul de aprobari la „Taxe de refacturat lipsa”.
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'refacturari'])) ?>">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            Refacturari curse
        </a>
    </div>

    <div class="row g-3 align-items-start">
        <?php if ($canManage): ?>
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm fee-rules-form-card" id="formular-regula">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h3 class="h6 mb-0"><?= $editingId > 0 ? 'Editeaza regula' : 'Adauga regula' ?></h3>
                        <?php if ($editingId > 0): ?>
                            <a class="small" href="<?= e($pageUrl) ?>">Anuleaza editarea</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (isset($formErrors['duplicate'])): ?>
                            <div class="alert alert-warning py-2 small"><?= e($formErrors['duplicate']) ?></div>
                        <?php endif; ?>
                        <form method="post" action="<?= e(build_query_url(['page' => 'reguli_taxe_refacturare', 'action' => $editingId > 0 ? 'update' : 'store'])) ?>" novalidate>
                            <?= csrf_field() ?>
                            <?php if ($editingId > 0): ?>
                                <input type="hidden" name="id" value="<?= e((string) $editingId) ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label" for="fee_rule_zone">Loc descarcare <span class="text-danger">*</span></label>
                                <select class="form-select<?= $invalid('zona_distributie_id') ?>" id="fee_rule_zone" name="zona_distributie_id" required>
                                    <option value="">-- Alege --</option>
                                    <?php foreach ($places['zones'] as $zoneId => $zoneLabel): ?>
                                        <option value="<?= e((string) $zoneId) ?>" <?= $field('zona_distributie_id') === (string) $zoneId ? 'selected' : '' ?>><?= e($zoneLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($formErrors['zona_distributie_id'])): ?><div class="invalid-feedback"><?= e($formErrors['zona_distributie_id']) ?></div><?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="fee_rule_loading">Loc incarcare</label>
                                <select class="form-select<?= $invalid('loc_incarcare_id') ?>" id="fee_rule_loading" name="loc_incarcare_id">
                                    <option value="">Oricare</option>
                                    <?php foreach ($places['locations'] as $locationId => $locationLabel): ?>
                                        <option value="<?= e((string) $locationId) ?>" <?= $field('loc_incarcare_id') === (string) $locationId ? 'selected' : '' ?>><?= e($locationLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">„Oricare” = taxa se cere indiferent de unde incarca (ex. orice cursa spre Lugoj).</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="fee_rule_transport">Tip transport</label>
                                <select class="form-select<?= $invalid('tip_transport') ?>" id="fee_rule_transport" name="tip_transport">
                                    <option value="">Oricare</option>
                                    <?php foreach ($transportTypes as $typeKey => $typeLabel): ?>
                                        <option value="<?= e($typeKey) ?>" <?= $field('tip_transport') === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-7">
                                    <label class="form-label" for="fee_rule_type">Taxa <span class="text-danger">*</span></label>
                                    <select class="form-select<?= $invalid('tip_cheltuiala') ?>" id="fee_rule_type" name="tip_cheltuiala" required>
                                        <option value="">-- Alege --</option>
                                        <?php foreach ($feeTypes as $typeKey => $typeLabel): ?>
                                            <option value="<?= e($typeKey) ?>" <?= $field('tip_cheltuiala') === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($formErrors['tip_cheltuiala'])): ?><div class="invalid-feedback"><?= e($formErrors['tip_cheltuiala']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-5">
                                    <label class="form-label" for="fee_rule_amount">Suma uzuala</label>
                                    <input class="form-control<?= $invalid('suma_uzuala') ?>" type="text" inputmode="decimal" id="fee_rule_amount" name="suma_uzuala" value="<?= e($field('suma_uzuala')) ?>" placeholder="lei">
                                    <?php if (isset($formErrors['suma_uzuala'])): ?><div class="invalid-feedback"><?= e($formErrors['suma_uzuala']) ?></div><?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="fee_rule_notes">Observatii</label>
                                <input class="form-control<?= $invalid('observatii') ?>" type="text" id="fee_rule_notes" name="observatii" maxlength="255" value="<?= e($field('observatii')) ?>" placeholder="ex. Pod Fetesti, dus-intors">
                                <?php if (isset($formErrors['observatii'])): ?><div class="invalid-feedback"><?= e($formErrors['observatii']) ?></div><?php endif; ?>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="fee_rule_active" name="activ" value="1" <?= $isActiveForm ? 'checked' : '' ?>>
                                <label class="form-check-label" for="fee_rule_active">Activa (se verifica pe curse)</label>
                            </div>

                            <button class="btn btn-primary w-100" type="submit">
                                <i class="bi <?= $editingId > 0 ? 'bi-check-lg' : 'bi-plus-lg' ?>" aria-hidden="true"></i>
                                <?= $editingId > 0 ? 'Salveaza modificarile' : 'Adauga regula' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12 <?= $canManage ? 'col-xl-8' : '' ?>">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h3 class="h6 mb-0">Reguli</h3>
                    <span class="small text-muted"><?= e((string) $activeCount) ?> active din <?= e((string) count($rules)) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 fee-rules-table">
                        <thead>
                        <tr>
                            <th>Ruta</th>
                            <th>Tip transport</th>
                            <th>Taxa</th>
                            <th class="text-end">Suma uzuala</th>
                            <th class="text-end" title="Curse din ultimele 180 de zile care se potrivesc regulii si cate dintre ele au taxa">Respectata</th>
                            <?php if ($canManage): ?><th class="text-end">Actiuni</th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rules === []): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Nu exista reguli. Adauga una din formular sau din sugestii.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rules as $rule): ?>
                            <?php
                            $ruleId = (int) $rule['id'];
                            $isActive = (int) $rule['activ'] === 1;
                            $matched = (int) $rule['trips_matched'];
                            $withFee = (int) $rule['trips_with_fee'];
                            $share = $matched > 0 ? $withFee / $matched : null;
                            ?>
                            <tr id="regula-<?= e((string) $ruleId) ?>" class="<?= $isActive ? '' : 'is-inactive' ?><?= $ruleId === $editingId ? ' table-primary' : '' ?>">
                                <td>
                                    <div class="fw-semibold">
                                        <?= e($rule['loc_incarcare_id'] !== null ? $placeLabel($rule['loc_nume'], $rule['loc_beneficiar']) : 'Orice loc') ?>
                                        <i class="bi bi-arrow-right text-muted" aria-hidden="true"></i>
                                        <?= e($placeLabel($rule['zona_nume'], $rule['zona_beneficiar'])) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= $rule['sursa'] === 'istoric' ? 'Din istoric' : 'Adaugata de ' . e((string) ($rule['created_by_name'] ?? '-')) ?>
                                        <?php if (!$isActive): ?> · <span class="badge text-bg-secondary">Inactiva</span><?php endif; ?>
                                        <?php if (trim((string) $rule['observatii']) !== ''): ?> · <?= e((string) $rule['observatii']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td><?= e($rule['tip_transport'] !== null ? ($transportTypes[$rule['tip_transport']] ?? $rule['tip_transport']) : 'Oricare') ?></td>
                                <td><span class="fee-rule-chip"><?= e($feeTypes[$rule['tip_cheltuiala']] ?? $rule['tip_cheltuiala']) ?></span></td>
                                <td class="text-end text-nowrap"><?= e($money($rule['suma_uzuala'])) ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($share === null): ?>
                                        <span class="text-muted small">fara curse</span>
                                    <?php else: ?>
                                        <span class="fee-rule-share <?= $share >= 0.8 ? 'is-good' : 'is-low' ?>"><?= e((string) $withFee) ?> / <?= e((string) $matched) ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canManage): ?>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'reguli_taxe_refacturare', 'edit' => $ruleId])) ?>#formular-regula" title="Editeaza" aria-label="Editeaza regula">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </a>
                                        <form class="d-inline" method="post" action="<?= e(build_query_url(['page' => 'reguli_taxe_refacturare', 'action' => 'toggle'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $ruleId) ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit" title="<?= $isActive ? 'Dezactiveaza' : 'Activeaza' ?>" aria-label="<?= $isActive ? 'Dezactiveaza regula' : 'Activeaza regula' ?>">
                                                <i class="bi <?= $isActive ? 'bi-pause-circle' : 'bi-play-circle' ?>" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        <form class="d-inline" method="post" action="<?= e(build_query_url(['page' => 'reguli_taxe_refacturare', 'action' => 'delete'])) ?>" data-fee-rule-delete>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $ruleId) ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Sterge" aria-label="Sterge regula">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Sugestii din istoric</h3>
                    <div class="small text-muted">Rute pe care aproape toate cursele din ultimele 180 de zile au o taxa, dar nu exista inca o regula.</div>
                </div>
                <?php if ($suggestions === []): ?>
                    <div class="card-body text-muted small">Nicio sugestie noua. Toate tiparele din istoric sunt acoperite de reguli.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($suggestions as $suggestion): ?>
                            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <div class="fw-semibold">
                                        <?= e($suggestion['loc_nume'] ?? 'Orice loc') ?>
                                        <i class="bi bi-arrow-right text-muted" aria-hidden="true"></i>
                                        <?= e($suggestion['zona_nume']) ?>
                                        · <span class="fee-rule-chip"><?= e($feeTypes[$suggestion['tip_cheltuiala']] ?? $suggestion['tip_cheltuiala']) ?></span>
                                    </div>
                                    <div class="small text-muted">
                                        <?= e($transportTypes[$suggestion['tip_transport']] ?? $suggestion['tip_transport']) ?>
                                        · <?= e((string) $suggestion['hits']) ?> din <?= e((string) $suggestion['total']) ?> curse au taxa
                                        <?php if ($suggestion['suma_uzuala'] !== null): ?> · uzual <?= e($money($suggestion['suma_uzuala'])) ?><?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url([
                                        'page' => 'reguli_taxe_refacturare',
                                        'zona_distributie_id' => $suggestion['zona_distributie_id'],
                                        'loc_incarcare_id' => $suggestion['loc_incarcare_id'] ?? '',
                                        'tip_transport' => $suggestion['tip_transport'],
                                        'tip_cheltuiala' => $suggestion['tip_cheltuiala'],
                                        'suma_uzuala' => $suggestion['suma_uzuala'] ?? '',
                                    ])) ?>#formular-regula">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Foloseste
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-fee-rule-delete]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm('Stergi regula? Cursele de pe aceasta ruta nu vor mai fi verificate pentru taxa.')) {
            event.preventDefault();
        }
    });
});
</script>
