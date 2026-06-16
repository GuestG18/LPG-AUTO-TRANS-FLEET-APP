<?php
$documentTypeRows = is_array($documentTypeRows ?? null) ? $documentTypeRows : [];
$customFieldsByVehicleType = is_array($customFieldsByVehicleType ?? null) ? $customFieldsByVehicleType : [];
$customFieldTypeOptions = is_array($customFieldTypeOptions ?? null) ? $customFieldTypeOptions : [
    'text' => 'Text',
    'number' => 'Numeric',
    'date' => 'Data',
    'checkbox' => 'Checkbox',
];
$backUrl = (string) ($backUrl ?? build_query_url(['page' => 'configurare_costuri_documente_vehicule_override']));
$search = trim((string) ($search ?? ''));
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h4 mb-1">Gestionare tipuri documente</h2>
        <div class="text-muted small">Controleaza documentele disponibile in formular, data de expirare si campurile personalizate pentru fiecare tip.</div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e($backUrl) ?>">Inapoi la configurare</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="get" action="<?= e(url('index.php')) ?>" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="page" value="configurare_costuri_documente_vehicule_override">
            <input type="hidden" name="action" value="manage_document_type_config">
            <div class="col-12 col-md-8 col-xl-6">
                <label class="form-label" for="document_type_config_search">Cautare</label>
                <input
                    type="search"
                    class="form-control"
                    id="document_type_config_search"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="Cauta dupa tip document sau tip vehicul"
                >
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary">Cauta</button>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'manage_document_type_config'])) ?>">Reseteaza</a>
            </div>
        </form>

        <?php if ($documentTypeRows === []): ?>
            <div class="text-muted"><?= $search !== '' ? 'Nu exista tipuri de document pentru cautarea curenta.' : 'Nu exista tipuri de document configurate.' ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Tip vehicul</th>
                            <th>Tip document</th>
                            <th>Data expirare</th>
                            <th>Campuri personalizate</th>
                            <th class="text-end">Actiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documentTypeRows as $row): ?>
                            <?php
                            $id = (int) ($row['id'] ?? 0);
                            $requiresExpiry = (int) ($row['requires_expiry'] ?? 1) === 1;
                            $vehicleType = (string) ($row['vehicle_type'] ?? '');
                            $documentType = (string) ($row['document_type'] ?? '');
                            $customFieldsForVehicleType = is_array($customFieldsByVehicleType[$vehicleType] ?? null) ? $customFieldsByVehicleType[$vehicleType] : [];
                            $customFields = is_array($customFieldsForVehicleType[$documentType] ?? null) ? $customFieldsForVehicleType[$documentType] : [];
                            $checkboxFields = array_values(array_filter($customFields, static function (array $customField): bool {
                                return (string) ($customField['type'] ?? 'text') === 'checkbox';
                            }));
                            $hasCustomDateFields = array_filter($customFields, static function (array $customField): bool {
                                return (string) ($customField['type'] ?? 'text') === 'date';
                            }) !== [];
                            $customFieldLabelsByKey = [];
                            foreach ($customFields as $customField) {
                                $customFieldLabelsByKey[(string) ($customField['key'] ?? '')] = (string) ($customField['label'] ?? '');
                            }
                            ?>
                            <tr>
                                <td><?= e(vehicle_type_label($vehicleType)) ?></td>
                                <td class="fw-semibold"><?= e($documentType) ?></td>
                                <td>
                                    <?php if ($requiresExpiry): ?>
                                        <span class="badge text-bg-primary">Obligatorie</span>
                                    <?php elseif ($hasCustomDateFields): ?>
                                        <span class="badge text-bg-info">Pe campuri</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Fara expirare</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-3">
                                        <?php if ($customFields === []): ?>
                                            <div class="text-muted small">Nu exista campuri personalizate pentru acest tip de document.</div>
                                        <?php else: ?>
                                            <div class="d-flex flex-column gap-2">
                                                <?php foreach ($customFields as $customField): ?>
                                                    <?php
                                                    $fieldKey = (string) ($customField['key'] ?? '');
                                                    $fieldLabel = (string) ($customField['label'] ?? '');
                                                    $fieldType = (string) ($customField['type'] ?? 'text');
                                                    $fieldTypeLabel = (string) ($customFieldTypeOptions[$fieldType] ?? $fieldType);
                                                    $showWhenChecked = (string) ($customField['show_when_checked'] ?? '');
                                                    $showWhenCheckedLabel = $showWhenChecked !== '' ? (string) ($customFieldLabelsByKey[$showWhenChecked] ?? '') : '';
                                                    ?>
                                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded px-3 py-2 bg-light-subtle">
                                                        <div class="d-flex flex-column gap-1">
                                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                                <span class="fw-semibold"><?= e($fieldLabel) ?></span>
                                                                <span class="badge text-bg-secondary"><?= e($fieldTypeLabel) ?></span>
                                                            </div>
                                                            <?php if ($showWhenCheckedLabel !== ''): ?>
                                                                <div class="small text-muted">
                                                                    Se afiseaza doar cand este bifat: <span class="fw-semibold"><?= e($showWhenCheckedLabel) ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <form
                                                            method="post"
                                                            action="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'delete_document_custom_field_config'])) ?>"
                                                            class="m-0"
                                                            onsubmit="return confirm('Stergi acest camp personalizat? Valorile deja salvate raman pe documentele existente, dar campul nu va mai aparea la adaugare sau editare.');"
                                                        >
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="document_type_id" value="<?= e((string) $id) ?>">
                                                            <input type="hidden" name="custom_field_key" value="<?= e($fieldKey) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">Sterge campul</button>
                                                        </form>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>

                                        <form
                                            method="post"
                                            action="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'add_document_custom_field_config'])) ?>"
                                            class="row g-2 align-items-end"
                                            data-vehicle-custom-field-config-form
                                        >
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="document_type_id" value="<?= e((string) $id) ?>">
                                            <div class="col-12 col-xl-4">
                                                <label class="form-label small mb-1" for="doc_custom_field_label_<?= e((string) $id) ?>">Eticheta camp</label>
                                                <input
                                                    type="text"
                                                    class="form-control form-control-sm"
                                                    id="doc_custom_field_label_<?= e((string) $id) ?>"
                                                    name="doc_custom_field_label"
                                                    maxlength="120"
                                                    placeholder="Ex: Serie document"
                                                    required
                                                >
                                            </div>
                                            <div class="col-12 col-md-4 col-xl-3">
                                                <label class="form-label small mb-1" for="doc_custom_field_type_<?= e((string) $id) ?>">Format</label>
                                                <select
                                                    class="form-select form-select-sm"
                                                    id="doc_custom_field_type_<?= e((string) $id) ?>"
                                                    name="doc_custom_field_type"
                                                    data-vehicle-custom-field-type
                                                >
                                                    <?php foreach ($customFieldTypeOptions as $fieldTypeValue => $fieldTypeLabel): ?>
                                                        <option value="<?= e((string) $fieldTypeValue) ?>"><?= e((string) $fieldTypeLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-8 col-xl-3">
                                                <label class="form-label small mb-1" for="doc_custom_field_show_when_checked_<?= e((string) $id) ?>">Afisare conditionata</label>
                                                <select
                                                    class="form-select form-select-sm"
                                                    id="doc_custom_field_show_when_checked_<?= e((string) $id) ?>"
                                                    name="doc_custom_field_show_when_checked"
                                                    data-vehicle-custom-field-rule
                                                >
                                                    <option value="">Afiseaza mereu</option>
                                                    <?php foreach ($checkboxFields as $checkboxField): ?>
                                                        <option value="<?= e((string) ($checkboxField['key'] ?? '')) ?>">
                                                            <?= e((string) ($checkboxField['label'] ?? '')) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text small mb-0">Optional: arata campul doar daca bifa selectata este activata in formular.</div>
                                            </div>
                                            <div class="col-12 col-xl-2 d-grid">
                                                <button type="submit" class="btn btn-sm btn-primary">Adauga camp</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-end gap-2">
                                        <form method="post" action="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'update_document_type_expiry'])) ?>" class="m-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                                            <input type="hidden" name="requires_expiry" value="<?= $requiresExpiry ? '0' : '1' ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <?= $requiresExpiry ? 'Fara data expirare' : 'Cere data expirare' ?>
                                            </button>
                                        </form>

                                        <form
                                            method="post"
                                            action="<?= e(build_query_url(['page' => 'configurare_costuri_documente_vehicule_override', 'action' => 'delete_document_type_config'])) ?>"
                                            class="m-0"
                                            onsubmit="return confirm('Stergi acest tip de document din lista configurata? Documentele deja salvate raman in sistem.');"
                                        >
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Sterge</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-vehicle-custom-field-config-form]').forEach(function (formEl) {
        var typeSelectEl = formEl.querySelector('[data-vehicle-custom-field-type]');
        var ruleSelectEl = formEl.querySelector('[data-vehicle-custom-field-rule]');

        if (!(typeSelectEl instanceof HTMLSelectElement) || !(ruleSelectEl instanceof HTMLSelectElement)) {
            return;
        }

        var syncRuleState = function () {
            var shouldDisable = typeSelectEl.value === 'checkbox' || ruleSelectEl.options.length <= 1;
            if (shouldDisable) {
                ruleSelectEl.value = '';
            }

            ruleSelectEl.disabled = shouldDisable;
        };

        typeSelectEl.addEventListener('change', syncRuleState);
        syncRuleState();
    });
});
</script>
