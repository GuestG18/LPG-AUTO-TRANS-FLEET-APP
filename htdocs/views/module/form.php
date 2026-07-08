<?php
$isEdit = $mode === 'edit';
$formAction = $isEdit
    ? build_query_url(['page' => $moduleKey, 'action' => 'update', 'id' => $recordId])
    : build_query_url(['page' => $moduleKey, 'action' => 'store']);
$hasFileField = false;
$vehicleLayoutOptionsByType = is_array($vehicleLayoutOptionsByType ?? null) ? $vehicleLayoutOptionsByType : [];
$documentTypeOptionsByVehicleType = is_array($documentTypeOptionsByVehicleType ?? null) ? $documentTypeOptionsByVehicleType : [];
$documentVehicleTypeByVehicleId = is_array($documentVehicleTypeByVehicleId ?? null) ? $documentVehicleTypeByVehicleId : [];
$documentTypeOptionsByVehicleId = is_array($documentTypeOptionsByVehicleId ?? null) ? $documentTypeOptionsByVehicleId : [];
$documentValidityDaysByVehicleIdAndType = is_array($documentValidityDaysByVehicleIdAndType ?? null) ? $documentValidityDaysByVehicleIdAndType : [];
$documentExpiryRequirementByVehicleType = is_array($documentExpiryRequirementByVehicleType ?? null) ? $documentExpiryRequirementByVehicleType : [];
$vehicleDocumentCustomFieldsByVehicleType = is_array($vehicleDocumentCustomFieldsByVehicleType ?? null) ? $vehicleDocumentCustomFieldsByVehicleType : [];
$vehicleDocumentCustomFieldValues = is_array($vehicleDocumentCustomFieldValues ?? null) ? $vehicleDocumentCustomFieldValues : [];
$vehicleDocumentCustomFieldErrors = is_array($vehicleDocumentCustomFieldErrors ?? null) ? $vehicleDocumentCustomFieldErrors : [];
$documentTypeOptionsByDriverId = is_array($documentTypeOptionsByDriverId ?? null) ? $documentTypeOptionsByDriverId : [];
$documentValidityDaysByDriverIdAndType = is_array($documentValidityDaysByDriverIdAndType ?? null) ? $documentValidityDaysByDriverIdAndType : [];
$driverDocumentExpiryRequirementByType = is_array($driverDocumentExpiryRequirementByType ?? null) ? $driverDocumentExpiryRequirementByType : [];
$driverDocumentCustomFieldsByType = is_array($driverDocumentCustomFieldsByType ?? null) ? $driverDocumentCustomFieldsByType : [];
$driverDocumentCustomFieldValues = is_array($driverDocumentCustomFieldValues ?? null) ? $driverDocumentCustomFieldValues : [];
$driverDocumentCustomFieldErrors = is_array($driverDocumentCustomFieldErrors ?? null) ? $driverDocumentCustomFieldErrors : [];
$vehicleFormulaOptions = [];

$formatModuleDateForDisplay = static function (mixed $value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    foreach (['Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y'] as $format) {
        $dateTime = DateTime::createFromFormat($format, $raw);
        if ($dateTime instanceof DateTime && $dateTime->format($format) === $raw) {
            return $dateTime->format('d/m/Y');
        }
    }

    return $raw;
};

$normalizeModuleDateForPicker = static function (mixed $value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    foreach (['Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y'] as $format) {
        $dateTime = DateTime::createFromFormat($format, $raw);
        if ($dateTime instanceof DateTime && $dateTime->format($format) === $raw) {
            return $dateTime->format('Y-m-d');
        }
    }

    return '';
};

foreach ($vehicleLayoutOptionsByType as $optionsByType) {
    if (!is_array($optionsByType)) {
        continue;
    }

    foreach ($optionsByType as $optionValue => $optionLabel) {
        $optionKey = (string) $optionValue;
        if (!isset($vehicleFormulaOptions[$optionKey])) {
            $vehicleFormulaOptions[$optionKey] = (string) $optionLabel;
        }
    }
}

foreach ($module['form_fields'] as $fieldMeta) {
    if (($fieldMeta['type'] ?? 'text') === 'file') {
        $hasFileField = true;
        break;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0"><?= e($isEdit ? 'Editeaza ' . $module['singular'] : 'Adauga ' . $module['singular']) ?></h2>
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary" href="<?= e($backUrl ?? build_query_url(['page' => $moduleKey])) ?>">Inapoi la lista</a>
    </div>
</div>

<?php if ($moduleKey === 'vehicule'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const vehicleTypeEl = document.getElementById('field_tip_vehicul');
    const axleFormulaEl = document.getElementById('field_formula_axelor');

    if (!(vehicleTypeEl instanceof HTMLSelectElement) || !(axleFormulaEl instanceof HTMLSelectElement)) {
        return;
    }

    const optionsByType = <?= json_encode($vehicleLayoutOptionsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const defaultsByType = {
        autovehicul: '4x2',
        autoutilitara: '4x2',
        camion: '4x2',
        cap_tractor: '4x2',
        semiremorca: '3 axe',
        semiremorca_primar: '3 axe',
        semiremorca_distributie: '3 axe'
    };
    const getAllowedValuesForType = function (vehicleType) {
        const typedOptions = optionsByType[vehicleType];
        if (typedOptions && typeof typedOptions === 'object') {
            return Object.keys(typedOptions);
        }
        return [];
    };
    const baseOptionLabelsByValue = {};
    Array.from(axleFormulaEl.options).forEach(function (option) {
        if (option.value !== '') {
            baseOptionLabelsByValue[option.value] = option.textContent;
        }
    });

    const refreshAxleFormula = function () {
        const selectedType = vehicleTypeEl.value || 'autovehicul';
        const typedOptions = optionsByType[selectedType] && typeof optionsByType[selectedType] === 'object'
            ? optionsByType[selectedType]
            : {};
        const allowedValues = getAllowedValuesForType(selectedType);

        Array.from(axleFormulaEl.options).forEach(function (option) {
            if (option.value === '') {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            if (Object.prototype.hasOwnProperty.call(typedOptions, option.value)) {
                option.textContent = String(typedOptions[option.value]);
            } else if (Object.prototype.hasOwnProperty.call(baseOptionLabelsByValue, option.value)) {
                option.textContent = String(baseOptionLabelsByValue[option.value]);
            }

            const isAllowed = allowedValues.includes(option.value);
            option.hidden = !isAllowed;
            option.disabled = !isAllowed;
        });

        const currentValue = axleFormulaEl.value;
        if (currentValue !== '' && allowedValues.includes(currentValue)) {
            return;
        }

        const preferredDefault = defaultsByType[selectedType] || '';
        if (preferredDefault !== '' && allowedValues.includes(preferredDefault)) {
            axleFormulaEl.value = preferredDefault;
            return;
        }

        axleFormulaEl.value = allowedValues.length > 0 ? allowedValues[0] : '';
    };

    if (axleFormulaEl.value === '') {
        const initialType = vehicleTypeEl.value || 'autovehicul';
        const defaultValue = defaultsByType[initialType] || '';
        if (defaultValue !== '') {
            axleFormulaEl.value = defaultValue;
        }
    }

    vehicleTypeEl.addEventListener('change', refreshAxleFormula);
    refreshAxleFormula();
});
</script>
<?php endif; ?>

<?php if (in_array($moduleKey, ['documente', 'configurare_costuri_documente_vehicule_override'], true)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const vehicleSelectEl = document.getElementById('field_vehicle_id');
    const documentTypeFieldId = <?= json_encode($moduleKey === 'documente' ? 'field_tip_document' : 'field_document_type', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const documentTypeSelectEl = document.getElementById(documentTypeFieldId);
    const moduleKey = <?= json_encode($moduleKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const isDocumentModule = moduleKey === 'documente';
    const isCostOverrideModule = moduleKey === 'configurare_costuri_documente_vehicule_override';
    const validityDaysInputEl = document.getElementById('field_validity_days');
    const expiryDateInputEl = document.getElementById('field_data_expirare');
    const expiryDateFieldEl = document.querySelector('[data-field="data_expirare"]');

    if (!(vehicleSelectEl instanceof HTMLSelectElement) || !(documentTypeSelectEl instanceof HTMLSelectElement)) {
        return;
    }

    const optionsByVehicleType = <?= json_encode($documentTypeOptionsByVehicleType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const vehicleTypeByVehicleId = <?= json_encode($documentVehicleTypeByVehicleId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const optionsByVehicleId = <?= json_encode($documentTypeOptionsByVehicleId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const validityDaysByVehicleIdAndType = <?= json_encode($documentValidityDaysByVehicleIdAndType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const expiryRequirementByVehicleType = <?= json_encode($documentExpiryRequirementByVehicleType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const getOptionsForSelectedVehicle = function (vehicleId) {
        if (isDocumentModule) {
            const vehicleType = vehicleTypeByVehicleId[vehicleId] || '';
            return optionsByVehicleType[vehicleType] && typeof optionsByVehicleType[vehicleType] === 'object'
                ? optionsByVehicleType[vehicleType]
                : {};
        }

        if (isCostOverrideModule) {
            return optionsByVehicleId[vehicleId] && typeof optionsByVehicleId[vehicleId] === 'object'
                ? optionsByVehicleId[vehicleId]
                : {};
        }

        return {};
    };

    const selectedDocumentRequiresExpiry = function () {
        if (!isDocumentModule) {
            return true;
        }

        const vehicleId = (vehicleSelectEl.value || '').trim();
        const documentType = (documentTypeSelectEl.value || '').trim();
        if (vehicleId === '' || documentType === '') {
            return true;
        }

        const vehicleType = vehicleTypeByVehicleId[vehicleId] || '';
        const requirementsForType = expiryRequirementByVehicleType[vehicleType] && typeof expiryRequirementByVehicleType[vehicleType] === 'object'
            ? expiryRequirementByVehicleType[vehicleType]
            : {};

        if (!Object.prototype.hasOwnProperty.call(requirementsForType, documentType)) {
            return true;
        }

        return requirementsForType[documentType] !== false;
    };

    const syncExpiryDateFieldForSelectedDocument = function () {
        if (!isDocumentModule || !(expiryDateInputEl instanceof HTMLInputElement)) {
            return;
        }

        const requiresExpiry = selectedDocumentRequiresExpiry();
        expiryDateInputEl.required = requiresExpiry;
        expiryDateInputEl.disabled = !requiresExpiry;

        if (!requiresExpiry) {
            expiryDateInputEl.value = '';
        }

        if (expiryDateFieldEl instanceof HTMLElement) {
            expiryDateFieldEl.classList.toggle('d-none', !requiresExpiry);
        }
    };

    const syncValidityDaysForSelectedDocument = function () {
        if (!isCostOverrideModule || !(validityDaysInputEl instanceof HTMLInputElement)) {
            return;
        }

        const vehicleId = (vehicleSelectEl.value || '').trim();
        const documentType = (documentTypeSelectEl.value || '').trim();
        if (vehicleId === '' || documentType === '') {
            return;
        }

        const validityByType = validityDaysByVehicleIdAndType[vehicleId] && typeof validityDaysByVehicleIdAndType[vehicleId] === 'object'
            ? validityDaysByVehicleIdAndType[vehicleId]
            : {};
        const rawValidity = validityByType[documentType] ?? null;
        const parsedValidity = Number(rawValidity);

        if (Number.isFinite(parsedValidity) && parsedValidity > 0) {
            validityDaysInputEl.value = String(Math.trunc(parsedValidity));
            return;
        }

        if (validityDaysInputEl.value.trim() === '') {
            validityDaysInputEl.value = '365';
        }
    };

    const renderDocumentTypeOptions = function () {
        const previousValue = documentTypeSelectEl.value || '';
        const vehicleId = (vehicleSelectEl.value || '').trim();
        const options = getOptionsForSelectedVehicle(vehicleId);

        while (documentTypeSelectEl.firstChild) {
            documentTypeSelectEl.removeChild(documentTypeSelectEl.firstChild);
        }

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        if (Object.keys(options).length > 0) {
            placeholderOption.textContent = '-- Selecteaza --';
        } else {
            placeholderOption.textContent = isCostOverrideModule
                ? 'Vehiculul selectat nu are documente adaugate.'
                : 'Nu exista documente configurate pentru tipul vehiculului selectat.';
        }
        documentTypeSelectEl.appendChild(placeholderOption);

        Object.entries(options).forEach(function ([optionValue, optionLabel]) {
            const optionEl = document.createElement('option');
            optionEl.value = String(optionValue);
            optionEl.textContent = String(optionLabel);
            documentTypeSelectEl.appendChild(optionEl);
        });

        if (previousValue !== '' && Object.prototype.hasOwnProperty.call(options, previousValue)) {
            documentTypeSelectEl.value = previousValue;
        } else {
            documentTypeSelectEl.value = '';
        }

        documentTypeSelectEl.disabled = vehicleId === '' || Object.keys(options).length === 0;
        syncValidityDaysForSelectedDocument();
        syncExpiryDateFieldForSelectedDocument();
    };

    vehicleSelectEl.addEventListener('change', renderDocumentTypeOptions);
    documentTypeSelectEl.addEventListener('change', function () {
        syncValidityDaysForSelectedDocument();
        syncExpiryDateFieldForSelectedDocument();
    });
    renderDocumentTypeOptions();
});
</script>
<?php endif; ?>

<?php if ($moduleKey === 'documente'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var vehicleSelectEl = document.getElementById('field_vehicle_id');
    var documentTypeSelectEl = document.getElementById('field_tip_document');
    var customFieldsWrapperEl = document.querySelector('[data-vehicle-document-custom-fields]');
    var customFieldsBodyEl = document.querySelector('[data-vehicle-document-custom-fields-body]');

    if (!(vehicleSelectEl instanceof HTMLSelectElement) || !(documentTypeSelectEl instanceof HTMLSelectElement) || !(customFieldsWrapperEl instanceof HTMLElement) || !(customFieldsBodyEl instanceof HTMLElement)) {
        return;
    }

    var vehicleTypeByVehicleId = <?= json_encode($documentVehicleTypeByVehicleId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var customFieldsByVehicleType = <?= json_encode($vehicleDocumentCustomFieldsByVehicleType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var customFieldValues = <?= json_encode($vehicleDocumentCustomFieldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var customFieldErrors = <?= json_encode($vehicleDocumentCustomFieldErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function getCustomFieldValue(fieldKey) {
        return Object.prototype.hasOwnProperty.call(customFieldValues, fieldKey)
            ? String(customFieldValues[fieldKey] || '')
            : '';
    }

    function isCheckboxTruthy(value) {
        var normalized = (value || '').toString().trim().toLowerCase();
        return normalized === '1' || normalized === 'true' || normalized === 'on' || normalized === 'yes' || normalized === 'da';
    }

    function normalizeDateValueForPicker(rawValue) {
        var value = (rawValue || '').toString().trim();
        if (value === '') {
            return '';
        }

        if (typeof parseDisplayDateToIso === 'function') {
            var normalized = parseDisplayDateToIso(value);
            return normalized === null ? '' : normalized;
        }

        return value;
    }

    function createFormGroup(fieldConfig) {
        var fieldKey = String(fieldConfig.key || '');
        var fieldLabel = String(fieldConfig.label || '');
        var fieldType = String(fieldConfig.type || 'text');
        var showWhenChecked = String(fieldConfig.show_when_checked || '');
        var currentValue = getCustomFieldValue(fieldKey);
        var currentError = Object.prototype.hasOwnProperty.call(customFieldErrors, fieldKey)
            ? String(customFieldErrors[fieldKey] || '')
            : '';

        var col = document.createElement('div');
        col.className = 'col-12';
        col.setAttribute('data-vehicle-custom-field-key', fieldKey);
        if (showWhenChecked !== '') {
            col.setAttribute('data-show-when-checked', showWhenChecked);
        }

        if (fieldType === 'checkbox') {
            var hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'custom_field_values[' + fieldKey + ']';
            hiddenInput.value = '0';
            col.appendChild(hiddenInput);

            var checkboxWrapper = document.createElement('div');
            checkboxWrapper.className = 'form-check mt-1';

            var checkboxInput = document.createElement('input');
            checkboxInput.type = 'checkbox';
            checkboxInput.id = 'vehicle_custom_field_' + fieldKey;
            checkboxInput.name = 'custom_field_values[' + fieldKey + ']';
            checkboxInput.value = '1';
            checkboxInput.className = 'form-check-input';
            checkboxInput.setAttribute('data-vehicle-custom-checkbox-key', fieldKey);
            checkboxInput.checked = isCheckboxTruthy(currentValue);
            if (currentError !== '') {
                checkboxInput.classList.add('is-invalid');
            }

            var checkboxLabel = document.createElement('label');
            checkboxLabel.className = 'form-check-label';
            checkboxLabel.setAttribute('for', 'vehicle_custom_field_' + fieldKey);
            checkboxLabel.textContent = fieldLabel;

            checkboxInput.addEventListener('change', function () {
                customFieldValues[fieldKey] = checkboxInput.checked ? '1' : '0';
                refreshConditionalFields();
            });

            checkboxWrapper.appendChild(checkboxInput);
            checkboxWrapper.appendChild(checkboxLabel);
            col.appendChild(checkboxWrapper);
        } else {
            var label = document.createElement('label');
            label.className = 'form-label';
            label.setAttribute('for', 'vehicle_custom_field_' + fieldKey);
            label.textContent = fieldLabel;
            col.appendChild(label);
        }

        if (fieldType === 'date') {
            var dateField = document.createElement('div');
            dateField.className = 'input-group fleet-date-field';

            var displayInput = document.createElement('input');
            displayInput.type = 'text';
            displayInput.id = 'vehicle_custom_field_' + fieldKey;
            displayInput.name = 'custom_field_values[' + fieldKey + ']';
            displayInput.className = 'form-control js-date-display-input';
            if (currentError !== '') {
                displayInput.classList.add('is-invalid');
            }
            displayInput.placeholder = 'dd/mm/yyyy';
            displayInput.setAttribute('inputmode', 'numeric');
            displayInput.setAttribute('autocomplete', 'off');
            if (showWhenChecked !== '') {
                displayInput.required = true;
                displayInput.setAttribute('data-vehicle-custom-required', '1');
            }

            var pickerId = 'vehicle_custom_field_' + fieldKey + '_picker';
            displayInput.setAttribute('data-date-picker-id', pickerId);

            var pickerValue = normalizeDateValueForPicker(currentValue);
            if (pickerValue !== '') {
                displayInput.value = typeof formatIsoDateToDisplay === 'function'
                    ? formatIsoDateToDisplay(pickerValue)
                    : currentValue;
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-secondary js-date-picker-button';
            button.setAttribute('data-date-picker-target', pickerId);
            button.setAttribute('aria-label', 'Deschide calendarul');
            button.innerHTML = '<i class="bi bi-calendar3" aria-hidden="true"></i>';

            var pickerInput = document.createElement('input');
            pickerInput.type = 'date';
            pickerInput.id = pickerId;
            pickerInput.className = 'fleet-date-picker-native';
            pickerInput.tabIndex = -1;
            pickerInput.setAttribute('aria-hidden', 'true');
            pickerInput.value = pickerValue;

            dateField.appendChild(displayInput);
            dateField.appendChild(button);
            dateField.appendChild(pickerInput);
            col.appendChild(dateField);

            displayInput.addEventListener('input', function () {
                customFieldValues[fieldKey] = displayInput.value;
            });
            displayInput.addEventListener('blur', function () {
                customFieldValues[fieldKey] = displayInput.value;
            });
            pickerInput.addEventListener('change', function () {
                if (pickerInput.value !== '') {
                    displayInput.value = typeof formatIsoDateToDisplay === 'function'
                        ? formatIsoDateToDisplay(pickerInput.value)
                        : pickerInput.value;
                } else {
                    displayInput.value = '';
                }
                customFieldValues[fieldKey] = displayInput.value;
            });
        } else if (fieldType !== 'checkbox') {
            var input = document.createElement('input');
            input.type = 'text';
            input.id = 'vehicle_custom_field_' + fieldKey;
            input.name = 'custom_field_values[' + fieldKey + ']';
            input.value = currentValue;
            input.className = 'form-control';
            if (currentError !== '') {
                input.classList.add('is-invalid');
            }

            if (fieldType === 'number') {
                input.setAttribute('inputmode', 'decimal');
                input.placeholder = 'Ex: 123';
            }

            input.addEventListener('input', function () {
                customFieldValues[fieldKey] = input.value;
            });

            col.appendChild(input);
        }

        if (currentError !== '') {
            var errorEl = document.createElement('div');
            errorEl.className = 'invalid-feedback d-block';
            errorEl.textContent = currentError;
            col.appendChild(errorEl);
        }

        return col;
    }

    function refreshConditionalFields() {
        customFieldsBodyEl.querySelectorAll('[data-vehicle-custom-field-key]').forEach(function (fieldGroupEl) {
            var showWhenChecked = fieldGroupEl.getAttribute('data-show-when-checked') || '';
            if (showWhenChecked === '') {
                fieldGroupEl.classList.remove('d-none');
                fieldGroupEl.querySelectorAll('input, select, textarea').forEach(function (controlEl) {
                    controlEl.disabled = false;
                });
                return;
            }

            var checkboxInputEl = customFieldsBodyEl.querySelector('[data-vehicle-custom-checkbox-key="' + showWhenChecked + '"]');
            var shouldShow = checkboxInputEl instanceof HTMLInputElement && checkboxInputEl.checked;
            fieldGroupEl.classList.toggle('d-none', !shouldShow);

            fieldGroupEl.querySelectorAll('input, select, textarea').forEach(function (controlEl) {
                if (!(controlEl instanceof HTMLElement)) {
                    return;
                }

                controlEl.disabled = !shouldShow;
                if (controlEl.getAttribute('data-vehicle-custom-required') === '1') {
                    controlEl.required = shouldShow;
                }
            });
        });
    }

    function renderCustomFields() {
        var vehicleId = (vehicleSelectEl.value || '').trim();
        var vehicleType = vehicleTypeByVehicleId[vehicleId] || '';
        var documentType = (documentTypeSelectEl.value || '').trim();
        var fieldsByType = vehicleType !== '' && customFieldsByVehicleType[vehicleType] && typeof customFieldsByVehicleType[vehicleType] === 'object'
            ? customFieldsByVehicleType[vehicleType]
            : {};
        var fieldConfigs = documentType !== '' && Array.isArray(fieldsByType[documentType])
            ? fieldsByType[documentType]
            : [];

        customFieldsBodyEl.innerHTML = '';

        if (fieldConfigs.length === 0) {
            customFieldsWrapperEl.classList.add('d-none');
            return;
        }

        fieldConfigs.forEach(function (fieldConfig) {
            var fieldGroupEl = createFormGroup(fieldConfig);
            customFieldsBodyEl.appendChild(fieldGroupEl);

            if (typeof initCustomDateField === 'function') {
                fieldGroupEl.querySelectorAll('.js-date-display-input').forEach(function (displayInput) {
                    initCustomDateField(displayInput);
                });
            }
        });

        customFieldsWrapperEl.classList.remove('d-none');
        refreshConditionalFields();
    }

    vehicleSelectEl.addEventListener('change', renderCustomFields);
    documentTypeSelectEl.addEventListener('change', renderCustomFields);
    renderCustomFields();
});
</script>
<?php endif; ?>

<?php if (in_array($moduleKey, ['documente_soferi', 'configurare_costuri_documente_soferi'], true)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const driverSelectEl = document.getElementById('field_driver_id');
    const moduleKey = <?= json_encode($moduleKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const isDriverDocumentModule = moduleKey === 'documente_soferi';
    const isDriverCostModule = moduleKey === 'configurare_costuri_documente_soferi';
    const documentTypeFieldId = isDriverDocumentModule ? 'field_tip_document' : 'field_document_type';
    const documentTypeSelectEl = document.getElementById(documentTypeFieldId);
    const validityDaysInputEl = document.getElementById('field_validity_days');
    const expiryDateInputEl = document.getElementById('field_data_expirare');
    const expiryDateFieldEl = document.querySelector('[data-field="data_expirare"]');

    if (!(driverSelectEl instanceof HTMLSelectElement) || !(documentTypeSelectEl instanceof HTMLSelectElement)) {
        return;
    }

    const optionsByDriverId = <?= json_encode($documentTypeOptionsByDriverId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const validityDaysByDriverIdAndType = <?= json_encode($documentValidityDaysByDriverIdAndType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const expiryRequirementByDriverType = <?= json_encode($driverDocumentExpiryRequirementByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const selectedDriverDocumentRequiresExpiry = function () {
        if (!isDriverDocumentModule) {
            return true;
        }

        const documentType = (documentTypeSelectEl.value || '').trim();
        if (documentType === '' || !Object.prototype.hasOwnProperty.call(expiryRequirementByDriverType, documentType)) {
            return true;
        }

        return expiryRequirementByDriverType[documentType] !== false;
    };

    const syncExpiryDateFieldForSelectedDriverDocument = function () {
        if (!isDriverDocumentModule || !(expiryDateInputEl instanceof HTMLInputElement)) {
            return;
        }

        const requiresExpiry = selectedDriverDocumentRequiresExpiry();
        expiryDateInputEl.required = requiresExpiry;
        expiryDateInputEl.disabled = !requiresExpiry;

        if (!requiresExpiry) {
            expiryDateInputEl.value = '';
        }

        if (expiryDateFieldEl instanceof HTMLElement) {
            expiryDateFieldEl.classList.toggle('d-none', !requiresExpiry);
        }
    };

    const syncValidityDaysForSelectedDocument = function () {
        if (!isDriverCostModule || !(validityDaysInputEl instanceof HTMLInputElement)) {
            return;
        }

        const driverId = (driverSelectEl.value || '').trim();
        const documentType = (documentTypeSelectEl.value || '').trim();
        if (driverId === '' || documentType === '') {
            return;
        }

        const validityByType = validityDaysByDriverIdAndType[driverId] && typeof validityDaysByDriverIdAndType[driverId] === 'object'
            ? validityDaysByDriverIdAndType[driverId]
            : {};
        const rawValidity = validityByType[documentType] ?? null;
        const parsedValidity = Number(rawValidity);

        if (Number.isFinite(parsedValidity) && parsedValidity > 0) {
            validityDaysInputEl.value = String(Math.trunc(parsedValidity));
            return;
        }

        if (validityDaysInputEl.value.trim() === '') {
            validityDaysInputEl.value = '365';
        }
    };

    const renderDocumentTypeOptions = function () {
        const previousValue = documentTypeSelectEl.value || '';
        const driverId = (driverSelectEl.value || '').trim();
        const options = optionsByDriverId[driverId] && typeof optionsByDriverId[driverId] === 'object'
            ? optionsByDriverId[driverId]
            : {};

        while (documentTypeSelectEl.firstChild) {
            documentTypeSelectEl.removeChild(documentTypeSelectEl.firstChild);
        }

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        if (Object.keys(options).length > 0) {
            placeholderOption.textContent = '-- Selecteaza --';
        } else {
            placeholderOption.textContent = isDriverCostModule
                ? 'Soferul selectat nu are documente adaugate.'
                : 'Nu exista tipuri de document configurate pentru soferul selectat.';
        }
        documentTypeSelectEl.appendChild(placeholderOption);

        Object.entries(options).forEach(function ([optionValue, optionLabel]) {
            const optionEl = document.createElement('option');
            optionEl.value = String(optionValue);
            optionEl.textContent = String(optionLabel);
            documentTypeSelectEl.appendChild(optionEl);
        });

        if (previousValue !== '' && Object.prototype.hasOwnProperty.call(options, previousValue)) {
            documentTypeSelectEl.value = previousValue;
        } else {
            documentTypeSelectEl.value = '';
        }

        documentTypeSelectEl.disabled = driverId === '' || Object.keys(options).length === 0;
        syncValidityDaysForSelectedDocument();
        syncExpiryDateFieldForSelectedDriverDocument();
        documentTypeSelectEl.dispatchEvent(new Event('change', { bubbles: true }));
    };

    driverSelectEl.addEventListener('change', renderDocumentTypeOptions);
    documentTypeSelectEl.addEventListener('change', function () {
        syncValidityDaysForSelectedDocument();
        syncExpiryDateFieldForSelectedDriverDocument();
    });
    renderDocumentTypeOptions();
});
</script>
<?php endif; ?>

<?php if ($moduleKey === 'documente_soferi'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var documentTypeSelectEl = document.getElementById('field_tip_document');
    var customFieldsWrapperEl = document.querySelector('[data-driver-document-custom-fields]');
    var customFieldsBodyEl = document.querySelector('[data-driver-document-custom-fields-body]');

    if (!(documentTypeSelectEl instanceof HTMLSelectElement) || !(customFieldsWrapperEl instanceof HTMLElement) || !(customFieldsBodyEl instanceof HTMLElement)) {
        return;
    }

    var customFieldsByType = <?= json_encode($driverDocumentCustomFieldsByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var customFieldValues = <?= json_encode($driverDocumentCustomFieldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var customFieldErrors = <?= json_encode($driverDocumentCustomFieldErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function getCustomFieldValue(fieldKey) {
        return Object.prototype.hasOwnProperty.call(customFieldValues, fieldKey)
            ? String(customFieldValues[fieldKey] || '')
            : '';
    }

    function isCheckboxTruthy(value) {
        var normalized = (value || '').toString().trim().toLowerCase();
        return normalized === '1' || normalized === 'true' || normalized === 'on' || normalized === 'yes' || normalized === 'da';
    }

    function normalizeDateValueForPicker(rawValue) {
        var value = (rawValue || '').toString().trim();
        if (value === '') {
            return '';
        }

        if (typeof parseDisplayDateToIso === 'function') {
            var normalized = parseDisplayDateToIso(value);
            return normalized === null ? '' : normalized;
        }

        return value;
    }

    function createFormGroup(fieldConfig) {
        var fieldKey = String(fieldConfig.key || '');
        var fieldLabel = String(fieldConfig.label || '');
        var fieldType = String(fieldConfig.type || 'text');
        var showWhenChecked = String(fieldConfig.show_when_checked || '');
        var currentValue = getCustomFieldValue(fieldKey);
        var currentError = Object.prototype.hasOwnProperty.call(customFieldErrors, fieldKey)
            ? String(customFieldErrors[fieldKey] || '')
            : '';

        var col = document.createElement('div');
        col.className = 'col-12';
        col.setAttribute('data-driver-custom-field-key', fieldKey);
        if (showWhenChecked !== '') {
            col.setAttribute('data-show-when-checked', showWhenChecked);
        }

        if (fieldType === 'checkbox') {
            var hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'custom_field_values[' + fieldKey + ']';
            hiddenInput.value = '0';
            col.appendChild(hiddenInput);

            var checkboxWrapper = document.createElement('div');
            checkboxWrapper.className = 'form-check mt-1';

            var checkboxInput = document.createElement('input');
            checkboxInput.type = 'checkbox';
            checkboxInput.id = 'driver_custom_field_' + fieldKey;
            checkboxInput.name = 'custom_field_values[' + fieldKey + ']';
            checkboxInput.value = '1';
            checkboxInput.className = 'form-check-input';
            checkboxInput.setAttribute('data-driver-custom-checkbox-key', fieldKey);
            checkboxInput.checked = isCheckboxTruthy(currentValue);
            if (currentError !== '') {
                checkboxInput.classList.add('is-invalid');
            }

            var checkboxLabel = document.createElement('label');
            checkboxLabel.className = 'form-check-label';
            checkboxLabel.setAttribute('for', 'driver_custom_field_' + fieldKey);
            checkboxLabel.textContent = fieldLabel;

            checkboxInput.addEventListener('change', function () {
                customFieldValues[fieldKey] = checkboxInput.checked ? '1' : '0';
                refreshConditionalFields();
            });

            checkboxWrapper.appendChild(checkboxInput);
            checkboxWrapper.appendChild(checkboxLabel);
            col.appendChild(checkboxWrapper);
        } else {
            var label = document.createElement('label');
            label.className = 'form-label';
            label.setAttribute('for', 'driver_custom_field_' + fieldKey);
            label.textContent = fieldLabel;
            col.appendChild(label);
        }

        if (fieldType === 'date') {
            var dateField = document.createElement('div');
            dateField.className = 'input-group fleet-date-field';

            var displayInput = document.createElement('input');
            displayInput.type = 'text';
            displayInput.id = 'driver_custom_field_' + fieldKey;
            displayInput.name = 'custom_field_values[' + fieldKey + ']';
            displayInput.className = 'form-control js-date-display-input';
            if (currentError !== '') {
                displayInput.classList.add('is-invalid');
            }
            displayInput.placeholder = 'dd/mm/yyyy';
            displayInput.setAttribute('inputmode', 'numeric');
            displayInput.setAttribute('autocomplete', 'off');
            if (showWhenChecked !== '') {
                displayInput.required = true;
                displayInput.setAttribute('data-driver-custom-required', '1');
            }

            var pickerId = 'driver_custom_field_' + fieldKey + '_picker';
            displayInput.setAttribute('data-date-picker-id', pickerId);

            var pickerValue = normalizeDateValueForPicker(currentValue);
            if (pickerValue !== '') {
                displayInput.value = typeof formatIsoDateToDisplay === 'function'
                    ? formatIsoDateToDisplay(pickerValue)
                    : currentValue;
            } else {
                displayInput.value = currentValue;
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-secondary js-date-picker-button';
            button.setAttribute('data-date-picker-target', pickerId);
            button.setAttribute('aria-label', 'Deschide calendarul');
            button.innerHTML = '<i class="bi bi-calendar3" aria-hidden="true"></i>';

            var pickerInput = document.createElement('input');
            pickerInput.type = 'date';
            pickerInput.id = pickerId;
            pickerInput.className = 'fleet-date-picker-native';
            pickerInput.tabIndex = -1;
            pickerInput.setAttribute('aria-hidden', 'true');
            pickerInput.value = pickerValue;

            dateField.appendChild(displayInput);
            dateField.appendChild(button);
            dateField.appendChild(pickerInput);
            col.appendChild(dateField);

            displayInput.addEventListener('input', function () {
                customFieldValues[fieldKey] = displayInput.value;
            });
            displayInput.addEventListener('blur', function () {
                customFieldValues[fieldKey] = displayInput.value;
            });
            pickerInput.addEventListener('change', function () {
                if (pickerInput.value !== '') {
                    displayInput.value = typeof formatIsoDateToDisplay === 'function'
                        ? formatIsoDateToDisplay(pickerInput.value)
                        : pickerInput.value;
                } else {
                    displayInput.value = '';
                }
                customFieldValues[fieldKey] = displayInput.value;
            });
        } else if (fieldType !== 'checkbox') {
            var input = document.createElement('input');
            input.type = fieldType === 'number' ? 'text' : 'text';
            input.id = 'driver_custom_field_' + fieldKey;
            input.name = 'custom_field_values[' + fieldKey + ']';
            input.value = currentValue;
            input.className = 'form-control';
            if (currentError !== '') {
                input.classList.add('is-invalid');
            }

            if (fieldType === 'number') {
                input.setAttribute('inputmode', 'decimal');
                input.placeholder = 'Ex: 123';
            }

            input.addEventListener('input', function () {
                customFieldValues[fieldKey] = input.value;
            });

            col.appendChild(input);
        }

        if (currentError !== '') {
            var errorEl = document.createElement('div');
            errorEl.className = 'invalid-feedback d-block';
            errorEl.textContent = currentError;
            col.appendChild(errorEl);
        }

        return col;
    }

    function refreshConditionalFields() {
        customFieldsBodyEl.querySelectorAll('[data-driver-custom-field-key]').forEach(function (fieldGroupEl) {
            var showWhenChecked = fieldGroupEl.getAttribute('data-show-when-checked') || '';
            if (showWhenChecked === '') {
                fieldGroupEl.classList.remove('d-none');
                return;
            }

            var checkboxInputEl = customFieldsBodyEl.querySelector('[data-driver-custom-checkbox-key="' + showWhenChecked + '"]');
            var shouldShow = checkboxInputEl instanceof HTMLInputElement && checkboxInputEl.checked;
            fieldGroupEl.classList.toggle('d-none', !shouldShow);

            fieldGroupEl.querySelectorAll('input, select, textarea').forEach(function (controlEl) {
                if (!(controlEl instanceof HTMLElement)) {
                    return;
                }

                controlEl.disabled = !shouldShow;
                if (controlEl.getAttribute('data-driver-custom-required') === '1') {
                    controlEl.required = shouldShow;
                }
            });
        });
    }

    function renderCustomFields() {
        var documentType = (documentTypeSelectEl.value || '').trim();
        var fieldConfigs = documentType !== '' && Array.isArray(customFieldsByType[documentType])
            ? customFieldsByType[documentType]
            : [];

        customFieldsBodyEl.innerHTML = '';

        if (fieldConfigs.length === 0) {
            customFieldsWrapperEl.classList.add('d-none');
            return;
        }

        fieldConfigs.forEach(function (fieldConfig) {
            var fieldGroupEl = createFormGroup(fieldConfig);
            customFieldsBodyEl.appendChild(fieldGroupEl);

            if (typeof initCustomDateField === 'function') {
                fieldGroupEl.querySelectorAll('.js-date-display-input').forEach(function (displayInput) {
                    initCustomDateField(displayInput);
                });
            }
        });

        customFieldsWrapperEl.classList.remove('d-none');
        refreshConditionalFields();
    }

    documentTypeSelectEl.addEventListener('change', renderCustomFields);
    renderCustomFields();
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-role="module-form-multiselect"]').forEach(function (dropdownEl) {
        const labelEl = dropdownEl.querySelector('.module-form-multiselect-label');
        const checkboxEls = dropdownEl.querySelectorAll('.module-form-multiselect-input');

        if (!(labelEl instanceof HTMLElement)) {
            return;
        }

        const defaultLabel = labelEl.dataset.defaultLabel || '-- Selecteaza --';
        const summarySingular = labelEl.dataset.summarySingular || 'optiune selectata';
        const summaryPlural = labelEl.dataset.summaryPlural || 'optiuni selectate';

        const refreshLabel = function () {
            const selectedLabels = [];

            checkboxEls.forEach(function (checkboxEl) {
                if (!(checkboxEl instanceof HTMLInputElement) || !checkboxEl.checked) {
                    return;
                }

                const optionLabel = checkboxEl.closest('label')?.querySelector('span')?.textContent?.trim();
                if (optionLabel) {
                    selectedLabels.push(optionLabel);
                }
            });

            if (selectedLabels.length === 0) {
                labelEl.textContent = defaultLabel;
                labelEl.removeAttribute('title');
                return;
            }

            const joinedLabels = selectedLabels.join(', ');
            labelEl.textContent = selectedLabels.length === 1
                ? selectedLabels[0]
                : selectedLabels.length + ' ' + summaryPlural;
            labelEl.setAttribute('title', joinedLabels);
        };

        checkboxEls.forEach(function (checkboxEl) {
            checkboxEl.addEventListener('change', refreshLabel);
        });

        refreshLabel();
    });
});
</script>

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
                    <div class="col-12 col-md-6" data-field="<?= e((string) $field) ?>">
                        <label for="<?= e($id) ?>" class="form-label">
                            <?= e($meta['label']) ?>
                            <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>

                        <?php if ($moduleKey === 'vehicule' && $field === 'formula_axelor'): ?>
                            <?php
                            $currentFormulaValue = trim((string) $value);
                            $knownFormulaValue = $currentFormulaValue === '' || array_key_exists($currentFormulaValue, $vehicleFormulaOptions);
                            ?>
                            <select
                                id="<?= e($id) ?>"
                                name="<?= e($field) ?>"
                                class="form-select <?= $error ? 'is-invalid' : '' ?>"
                            >
                                <option value=""><?= e((string) ($placeholder ?? '-- Selecteaza formula axelor --')) ?></option>
                                <?php foreach ($vehicleFormulaOptions as $optionValue => $optionLabel): ?>
                                    <option value="<?= e((string) $optionValue) ?>" <?= (string) $currentFormulaValue === (string) $optionValue ? 'selected' : '' ?>>
                                        <?= e((string) $optionLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$knownFormulaValue): ?>
                                    <option value="<?= e($currentFormulaValue) ?>" selected>
                                        <?= e($currentFormulaValue) ?> (valoare existenta)
                                    </option>
                                <?php endif; ?>
                            </select>

                        <?php elseif ($type === 'textarea'): ?>
                            <textarea
                                id="<?= e($id) ?>"
                                name="<?= e($field) ?>"
                                rows="3"
                                class="form-control <?= $error ? 'is-invalid' : '' ?>"
                                <?= $placeholder ? 'placeholder="' . e((string) $placeholder) . '"' : '' ?>
                            ><?= e((string) $value) ?></textarea>

                        <?php elseif ($type === 'multiselect'): ?>
                            <?php
                            $selectedValues = is_array($value) ? $value : (trim((string) $value) === '' ? [] : [(string) $value]);
                            $selectedMap = [];
                            foreach ($selectedValues as $selectedValue) {
                                if (is_scalar($selectedValue)) {
                                    $selectedMap[(string) $selectedValue] = true;
                                }
                            }
                            $multiselectOptions = $selectOptions[$field] ?? [];
                            $selectedLabels = [];
                            foreach ($multiselectOptions as $optionValue => $optionLabel) {
                                if (isset($selectedMap[(string) $optionValue])) {
                                    $selectedLabels[] = (string) $optionLabel;
                                }
                            }
                            $summarySingular = (string) ($meta['summary_singular'] ?? 'optiune selectata');
                            $summaryPlural = (string) ($meta['summary_plural'] ?? 'optiuni selectate');
                            $defaultMultiselectLabel = (string) ($placeholder ?? '-- Selecteaza --');
                            if (count($selectedLabels) === 1) {
                                $buttonLabel = $selectedLabels[0];
                            } elseif (count($selectedLabels) > 1) {
                                $buttonLabel = count($selectedLabels) . ' ' . $summaryPlural;
                            } else {
                                $buttonLabel = $defaultMultiselectLabel;
                            }
                            ?>
                            <div class="dropdown vehicle-multiselect-dropdown" data-role="module-form-multiselect">
                                <button
                                    class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= $error ? 'is-invalid' : '' ?>"
                                    type="button"
                                    id="<?= e($id) ?>_toggle"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false"
                                >
                                    <span
                                        class="vehicle-multiselect-label module-form-multiselect-label"
                                        data-default-label="<?= e($defaultMultiselectLabel) ?>"
                                        data-summary-singular="<?= e($summarySingular) ?>"
                                        data-summary-plural="<?= e($summaryPlural) ?>"
                                    ><?= e($buttonLabel) ?></span>
                                </button>
                                <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="<?= e($id) ?>_toggle">
                                    <?php foreach ($multiselectOptions as $optionValue => $optionLabel): ?>
                                        <?php $checkboxId = $id . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $optionValue); ?>
                                        <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option" for="<?= e($checkboxId) ?>">
                                            <input
                                                class="form-check-input m-0 module-form-multiselect-input"
                                                type="checkbox"
                                                id="<?= e($checkboxId) ?>"
                                                name="<?= e($field) ?>[]"
                                                value="<?= e((string) $optionValue) ?>"
                                                <?= isset($selectedMap[(string) $optionValue]) ? 'checked' : '' ?>
                                            >
                                            <span><?= e((string) $optionLabel) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

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
                                    <?php elseif ($previewType === 'driver_photo'): ?>
                                        <div><?= driver_image_preview_html($existingOriginal, $existingStored) ?></div>
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

                        <?php elseif ($type === 'date'): ?>
                            <?php
                            $dateDisplayValue = $formatModuleDateForDisplay($value);
                            $datePickerValue = $normalizeModuleDateForPicker($value);
                            $datePickerId = $id . '_picker';
                            ?>
                            <div class="input-group fleet-date-field">
                                <input
                                    type="text"
                                    id="<?= e($id) ?>"
                                    name="<?= e($field) ?>"
                                    value="<?= e($dateDisplayValue) ?>"
                                    class="form-control js-date-display-input <?= $error ? 'is-invalid' : '' ?>"
                                    placeholder="<?= e((string) ($placeholder ?? 'dd/mm/yyyy')) ?>"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    data-date-picker-id="<?= e($datePickerId) ?>"
                                >
                                <button
                                    type="button"
                                    class="btn btn-outline-secondary js-date-picker-button"
                                    data-date-picker-target="<?= e($datePickerId) ?>"
                                    aria-label="Deschide calendarul"
                                >
                                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                                </button>
                                <input
                                    type="date"
                                    id="<?= e($datePickerId) ?>"
                                    class="fleet-date-picker-native"
                                    value="<?= e($datePickerValue) ?>"
                                    tabindex="-1"
                                    aria-hidden="true"
                                >
                            </div>

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

                    <?php if ($moduleKey === 'documente' && $field === 'data_expirare'): ?>
                        <div class="col-12 col-md-6 offset-md-6 d-none" data-vehicle-document-custom-fields>
                            <div class="border rounded p-3 bg-light-subtle h-100">
                                <div class="mb-3">
                                    <div class="fw-semibold">Campuri personalizate pentru tipul de document</div>
                                    <div class="text-muted small">Aceste campuri se completeaza in functie de vehiculul si tipul de document selectat.</div>
                                </div>
                                <div class="row g-3" data-vehicle-document-custom-fields-body></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($moduleKey === 'documente_soferi' && $field === 'data_expirare'): ?>
                        <div class="col-12 col-md-6 offset-md-6 d-none" data-driver-document-custom-fields>
                            <div class="border rounded p-3 bg-light-subtle h-100">
                                <div class="mb-3">
                                    <div class="fw-semibold">Campuri personalizate pentru tipul de document</div>
                                    <div class="text-muted small">Aceste campuri se completeaza in functie de tipul de document selectat.</div>
                                </div>
                                <div class="row g-3" data-driver-document-custom-fields-body></div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Salveaza</button>
                <a class="btn btn-outline-secondary" href="<?= e($backUrl ?? build_query_url(['page' => $moduleKey])) ?>">Renunta</a>
            </div>
        </form>
    </div>
</div>
