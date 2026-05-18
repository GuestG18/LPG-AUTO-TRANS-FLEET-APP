<?php
$isEdit = $mode === 'edit';
$formAction = $isEdit
    ? build_query_url(['page' => $moduleKey, 'action' => 'update', 'id' => $recordId])
    : build_query_url(['page' => $moduleKey, 'action' => 'store']);
$hasFileField = false;
$vehicleKmBordById = is_array($vehicleKmBordById ?? null) ? $vehicleKmBordById : [];
$driverVehicleById = is_array($driverVehicleById ?? null) ? $driverVehicleById : [];
$fuelConsumptionSummary = is_array($fuelConsumptionSummary ?? null) ? $fuelConsumptionSummary : null;

foreach ($module['form_fields'] as $fieldMeta) {
    if (($fieldMeta['type'] ?? 'text') === 'file') {
        $hasFileField = true;
        break;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0"><?= e($isEdit ? 'Editeaza ' . $module['singular'] : 'Adauga ' . $module['singular']) ?></h2>
    <a class="btn btn-outline-secondary" href="<?= e($backUrl ?? build_query_url(['page' => $moduleKey])) ?>">Inapoi la lista</a>
</div>

<?php if ($moduleKey === 'alimentari'): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="small text-uppercase text-muted fw-semibold mb-1">Consum mediu curent</div>
            <?php if ($fuelConsumptionSummary === null): ?>
                <div class="text-muted">L/100km apare dupa cel putin 2 alimentari pentru acelasi vehicul, cu Km alimentare crescator.</div>
            <?php else: ?>
                <div class="h4 mb-1"><?= e(format_number_ro((float) ($fuelConsumptionSummary['average_l_per_100km'] ?? 0), 2)) ?> L/100km</div>
                <div class="small text-muted">
                    Distanta analizata: <?= e(format_number_ro((float) ($fuelConsumptionSummary['total_distance_km'] ?? 0), 0)) ?> km |
                    Combustibil: <?= e(format_number_ro((float) ($fuelConsumptionSummary['total_fuel_liters'] ?? 0), 2)) ?> L
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'vehicule'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const vehicleTypeEl = document.getElementById('field_tip_vehicul');
    const axleFormulaEl = document.getElementById('field_formula_axelor');

    if (!(vehicleTypeEl instanceof HTMLSelectElement) || !(axleFormulaEl instanceof HTMLInputElement)) {
        return;
    }

    const defaultsByType = {
        autovehicul: '2x2',
        camion: '4x2',
        cap_tractor: '4x2',
        semiremorca: '3 axe'
    };

    const placeholderByType = {
        autovehicul: 'Autoturism: 2x2',
        camion: 'Camion: 4x2, 6x2, 6x4, 8x4',
        cap_tractor: 'Cap tractor: 4x2, 6x2, 6x4',
        semiremorca: 'Semi-remorca: 2 axe, 3 axe, 4 axe, 6 axe'
    };

    let userEditedFormula = axleFormulaEl.value.trim() !== '';

    axleFormulaEl.addEventListener('input', function () {
        userEditedFormula = axleFormulaEl.value.trim() !== '';
    });

    const refreshAxleFormula = function () {
        const selectedType = vehicleTypeEl.value || 'autovehicul';
        axleFormulaEl.placeholder = placeholderByType[selectedType] || 'Ex: 4x2, 6x4, 3 axe';

        if (!userEditedFormula) {
            axleFormulaEl.value = defaultsByType[selectedType] || '4x2';
        }
    };

    vehicleTypeEl.addEventListener('change', refreshAxleFormula);
    refreshAxleFormula();
});
</script>
<?php endif; ?>

<?php if ($moduleKey === 'alimentari'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const vehicleSelectEl = document.getElementById('field_vehicle_id');
    const kmBordEl = document.getElementById('field_km_bord');
    const driverSelectEl = document.getElementById('field_driver_id');

    if (!(vehicleSelectEl instanceof HTMLSelectElement) || !(kmBordEl instanceof HTMLInputElement)) {
        return;
    }

    const readSelectedVehicleKmBord = function () {
        const selectedOption = vehicleSelectEl.selectedOptions.length > 0 ? vehicleSelectEl.selectedOptions[0] : null;
        if (!(selectedOption instanceof HTMLOptionElement)) {
            return null;
        }

        const rawKm = selectedOption.getAttribute('data-km-bord');
        if (rawKm === null || rawKm.trim() === '') {
            return null;
        }

        const parsedKm = Number(rawKm);
        if (!Number.isFinite(parsedKm)) {
            return null;
        }

        return Math.max(0, Math.trunc(parsedKm));
    };

    const syncKmBordFromSelectedVehicle = function (forceOverwrite) {
        const selectedKmBord = readSelectedVehicleKmBord();
        if (selectedKmBord === null) {
            return;
        }

        const currentValue = kmBordEl.value.trim();
        if (!forceOverwrite && currentValue !== '') {
            return;
        }

        kmBordEl.value = String(selectedKmBord);
        kmBordEl.dispatchEvent(new Event('input', { bubbles: true }));
    };

    syncKmBordFromSelectedVehicle(false);
    vehicleSelectEl.addEventListener('change', function () {
        syncKmBordFromSelectedVehicle(true);
    });

    if (!(driverSelectEl instanceof HTMLSelectElement)) {
        return;
    }

    const driverOptions = Array.from(driverSelectEl.options).filter(function (option) {
        return option.value !== '';
    });

    const selectedVehicleId = function () {
        const raw = vehicleSelectEl.value.trim();
        if (raw === '') {
            return '';
        }
        return raw;
    };

    const syncDriverOptionsForVehicle = function () {
        const vehicleId = selectedVehicleId();
        let hasVisibleSelectedDriver = false;

        driverOptions.forEach(function (option) {
            const assignedVehicleId = (option.getAttribute('data-assigned-vehicle-id') || '').trim();
            const visible = vehicleId !== '' && assignedVehicleId !== '' && assignedVehicleId === vehicleId;

            option.hidden = !visible;
            option.disabled = !visible;
            if (visible && option.selected) {
                hasVisibleSelectedDriver = true;
            }
        });

        if (!hasVisibleSelectedDriver) {
            driverSelectEl.value = '';
        }
    };

    syncDriverOptionsForVehicle();
    vehicleSelectEl.addEventListener('change', function () {
        syncDriverOptionsForVehicle();
    });
});
</script>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="post" action="<?= e($formAction) ?>" <?= $hasFileField ? 'enctype="multipart/form-data"' : '' ?> novalidate>
            <?= csrf_field() ?>
            <?php if ($moduleKey === 'documente' && !empty($keepDocumentVehicleContext)): ?>
                <input type="hidden" name="keep_vehicle_context" value="1">
            <?php endif; ?>

            <div class="row g-3">
                <?php foreach ($module['form_fields'] as $field => $meta): ?>
                    <?php
                    $type = $meta['type'] ?? 'text';
                    $required = (bool) ($meta['required'] ?? false);
                    if (isset($meta['required_on'])) {
                        $required = $meta['required_on'] === ($isEdit ? 'edit' : 'create');
                    }

                    $value = $formData[$field] ?? ($meta['default'] ?? '');
                    $error = $errors[$field] ?? null;
                    $id = 'field_' . $field;
                    $help = $meta['help'] ?? null;
                    $placeholder = $meta['placeholder'] ?? null;
                    $storedField = $meta['stored_field'] ?? null;
                    $originalField = $meta['original_field'] ?? null;
                    $removeField = $meta['remove_field'] ?? null;
                    $previewType = $meta['preview_type'] ?? 'document';
                    ?>
                    <div class="col-12 col-md-6">
                        <label for="<?= e($id) ?>" class="form-label">
                            <?= e($meta['label']) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>

                        <?php if ($type === 'textarea'): ?>
                            <textarea
                                id="<?= e($id) ?>"
                                name="<?= e($field) ?>"
                                rows="3"
                                class="form-control <?= $error ? 'is-invalid' : '' ?>"
                                <?= $placeholder ? 'placeholder="' . e((string) $placeholder) . '"' : '' ?>
                            ><?= e((string) $value) ?></textarea>

                        <?php elseif ($type === 'select'): ?>
                            <select
                                id="<?= e($id) ?>"
                                name="<?= e($field) ?>"
                                class="form-select <?= $error ? 'is-invalid' : '' ?>"
                            >
                                <option value=""><?= e((string) ($placeholder ?? '-- Selecteaza --')) ?></option>
                                <?php foreach (($selectOptions[$field] ?? []) as $optionValue => $optionLabel): ?>
                                    <?php
                                    $optionExtraAttributes = '';
                                    if ($moduleKey === 'alimentari' && $field === 'vehicle_id') {
                                        $kmBordForOption = $vehicleKmBordById[(string) $optionValue] ?? null;
                                        if ($kmBordForOption !== null && is_numeric((string) $kmBordForOption)) {
                                            $optionExtraAttributes = ' data-km-bord="' . e((string) max(0, (int) $kmBordForOption)) . '"';
                                        }
                                    }
                                    if ($moduleKey === 'alimentari' && $field === 'driver_id') {
                                        $assignedVehicleId = $driverVehicleById[(string) $optionValue] ?? null;
                                        if ($assignedVehicleId !== null && is_numeric((string) $assignedVehicleId)) {
                                            $optionExtraAttributes = ' data-assigned-vehicle-id="' . e((string) max(0, (int) $assignedVehicleId)) . '"';
                                        }
                                    }
                                    ?>
                                    <option value="<?= e((string) $optionValue) ?>"<?= $optionExtraAttributes ?> <?= (string) $value === (string) $optionValue ? 'selected' : '' ?>>
                                        <?= e((string) $optionLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($type === 'password'): ?>
                            <input
                                type="password"
                                id="<?= e($id) ?>"
                                name="<?= e($field) ?>"
                                class="form-control <?= $error ? 'is-invalid' : '' ?>"
                                <?= isset($meta['minlength']) ? 'minlength="' . e((string) $meta['minlength']) . '"' : '' ?>
                            >
                            <?php if ($isEdit): ?>
                                <div class="form-text">Lasa gol daca nu doresti schimbarea parolei.</div>
                            <?php endif; ?>

                        <?php elseif ($type === 'file'): ?>
                            <input
                                type="file"
                                id="<?= e($id) ?>"
                                name="<?= e($field) ?>"
                                class="form-control <?= $error ? 'is-invalid' : '' ?>"
                                <?= isset($meta['accept']) ? 'accept="' . e((string) $meta['accept']) . '"' : '' ?>
                            >
                            <?php if (!empty($meta['help'])): ?>
                                <div class="form-text"><?= e((string) $meta['help']) ?></div>
                            <?php endif; ?>

                            <?php if ($isEdit && is_string($storedField) && $storedField !== '' && !empty($formData[$storedField])): ?>
                                <?php
                                $existingOriginal = is_string($originalField) && $originalField !== '' ? (string) ($formData[$originalField] ?? '') : '';
                                $existingStored = (string) ($formData[$storedField] ?? '');
                                $removeFieldName = is_string($removeField) && $removeField !== '' ? $removeField : 'sterge_fisier';
                                ?>
                                <div class="mt-2 d-flex flex-column gap-2">
                                    <?php if ($previewType === 'vehicle_photo'): ?>
                                        <div><?= vehicle_image_preview_html($existingOriginal, $existingStored) ?></div>
                                    <?php else: ?>
                                        <div>
                                            <?= document_file_link_html($existingOriginal, $existingStored) ?>
                                        </div>
                                        <div class="border rounded p-2 bg-light-subtle">
                                            <?= document_preview_html($existingOriginal, $existingStored) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-check">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            value="1"
                                            id="<?= e($removeFieldName) ?>"
                                            name="<?= e($removeFieldName) ?>"
                                            <?= (string) ($formData[$removeFieldName] ?? '') === '1' ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label" for="<?= e($removeFieldName) ?>">
                                            Sterge fisierul existent la salvare
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <input
                                type="<?= e($type === 'number' ? 'number' : $type) ?>"
                                id="<?= e($id) ?>"
                                name="<?= e($field) ?>"
                                value="<?= e((string) $value) ?>"
                                class="form-control <?= $error ? 'is-invalid' : '' ?>"
                                <?= $placeholder ? 'placeholder="' . e((string) $placeholder) . '"' : '' ?>
                                <?= isset($meta['step']) ? 'step="' . e((string) $meta['step']) . '"' : '' ?>
                                <?= isset($meta['min']) ? 'min="' . e((string) $meta['min']) . '"' : '' ?>
                                <?= isset($meta['max']) ? 'max="' . e((string) $meta['max']) . '"' : '' ?>
                                <?= isset($meta['maxlength']) ? 'maxlength="' . e((string) $meta['maxlength']) . '"' : '' ?>
                                <?= isset($meta['minlength']) ? 'minlength="' . e((string) $meta['minlength']) . '"' : '' ?>
                            >
                        <?php endif; ?>

                        <?php if ($help && $type !== 'file'): ?>
                            <div class="form-text"><?= e((string) $help) ?></div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="invalid-feedback d-block"><?= e($error) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Salveaza</button>
                <a class="btn btn-outline-secondary" href="<?= e($backUrl ?? build_query_url(['page' => $moduleKey])) ?>">Renunta</a>
            </div>
        </form>
    </div>
</div>
