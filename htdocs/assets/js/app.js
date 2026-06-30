document.addEventListener('click', function (event) {
    var target = event.target;

    if (!target.matches('[data-confirm]')) {
        return;
    }

    var message = target.getAttribute('data-confirm') || 'Sigur doresti sa continui?';
    var confirmed = window.confirm(message);

    if (!confirmed) {
        event.preventDefault();
        event.stopPropagation();
    }
});

function disableSubmitControl(control) {
    if (!(control instanceof HTMLButtonElement) && !(control instanceof HTMLInputElement)) {
        return;
    }

    control.disabled = true;
}

function openNativeDatePicker(input) {
    if (!(input instanceof HTMLInputElement) || input.type !== 'date') {
        return;
    }

    if (input.disabled || input.readOnly) {
        return;
    }

    try {
        input.focus({ preventScroll: true });
    } catch (error) {
    }

    try {
        if (typeof input.showPicker === 'function') {
            input.showPicker();
        }
    } catch (error) {
        // Older browsers will keep their default date input behavior.
    }
}

document.addEventListener('click', function (event) {
    var target = event.target;

    if (target instanceof HTMLInputElement && target.type === 'date') {
        openNativeDatePicker(target);
    }
});

function isValidIsoDate(value) {
    var raw = (value || '').toString().trim();
    var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);

    if (!match) {
        return false;
    }

    var year = Number(match[1]);
    var month = Number(match[2]);
    var day = Number(match[3]);
    var date = new Date(year, month - 1, day);

    return date.getFullYear() === year
        && date.getMonth() === (month - 1)
        && date.getDate() === day;
}

function padDatePart(value) {
    return String(value).padStart(2, '0');
}

function formatIsoDateToDisplay(value) {
    if (!isValidIsoDate(value)) {
        return '';
    }

    return value.slice(8, 10) + '/' + value.slice(5, 7) + '/' + value.slice(0, 4);
}

function buildIsoDate(year, month, day) {
    var date = new Date(year, month - 1, day);

    if (
        date.getFullYear() !== year
        || date.getMonth() !== (month - 1)
        || date.getDate() !== day
    ) {
        return null;
    }

    return String(year) + '-' + padDatePart(month) + '-' + padDatePart(day);
}

function parseDisplayDateToIso(value) {
    var raw = (value || '').toString().trim();

    if (raw === '') {
        return '';
    }

    if (isValidIsoDate(raw)) {
        return raw;
    }

    var match = raw.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/);
    if (!match) {
        return null;
    }

    return buildIsoDate(
        Number(match[3]),
        Number(match[2]),
        Number(match[1])
    );
}

function syncDateFieldState(displayInput, pickerInput, button) {
    pickerInput.disabled = displayInput.disabled;
    pickerInput.readOnly = displayInput.readOnly;
    button.disabled = displayInput.disabled || displayInput.readOnly;
}

function syncDateFieldFromDisplay(displayInput, pickerInput, options) {
    var config = options || {};
    var normalized = parseDisplayDateToIso(displayInput.value);

    if (normalized === null) {
        if (config.markInvalid) {
            displayInput.classList.add('is-invalid');
        }
        return false;
    }

    displayInput.classList.remove('is-invalid');
    pickerInput.value = normalized;

    if (config.normalizeDisplay && normalized !== '') {
        displayInput.value = formatIsoDateToDisplay(normalized);
    }

    return true;
}

function initCustomDateField(displayInput) {
    if (!(displayInput instanceof HTMLInputElement)) {
        return;
    }

    var pickerId = displayInput.getAttribute('data-date-picker-id') || '';
    if (pickerId === '') {
        return;
    }

    var pickerInput = document.getElementById(pickerId);
    if (!(pickerInput instanceof HTMLInputElement) || pickerInput.type !== 'date') {
        return;
    }

    var button = document.querySelector('[data-date-picker-target="' + pickerId + '"]');
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    if (displayInput.value.trim() === '' && isValidIsoDate(pickerInput.value)) {
        displayInput.value = formatIsoDateToDisplay(pickerInput.value);
    } else {
        syncDateFieldFromDisplay(displayInput, pickerInput, { normalizeDisplay: true, markInvalid: false });
    }

    syncDateFieldState(displayInput, pickerInput, button);

    displayInput.addEventListener('input', function () {
        if (displayInput.value.trim() === '') {
            pickerInput.value = '';
            displayInput.classList.remove('is-invalid');
            return;
        }

        syncDateFieldFromDisplay(displayInput, pickerInput, {
            normalizeDisplay: false,
            markInvalid: false
        });
    });

    displayInput.addEventListener('blur', function () {
        syncDateFieldFromDisplay(displayInput, pickerInput, {
            normalizeDisplay: true,
            markInvalid: true
        });
    });

    button.addEventListener('click', function (event) {
        event.preventDefault();
        syncDateFieldFromDisplay(displayInput, pickerInput, {
            normalizeDisplay: false,
            markInvalid: false
        });
        openNativeDatePicker(pickerInput);
    });

    pickerInput.addEventListener('change', function () {
        displayInput.value = formatIsoDateToDisplay(pickerInput.value);
        displayInput.classList.remove('is-invalid');
    });

    if (typeof MutationObserver === 'function') {
        var observer = new MutationObserver(function () {
            syncDateFieldState(displayInput, pickerInput, button);
            if (displayInput.value.trim() === '' && pickerInput.value !== '') {
                displayInput.value = formatIsoDateToDisplay(pickerInput.value);
            }
        });

        observer.observe(displayInput, {
            attributes: true,
            attributeFilter: ['disabled', 'readonly', 'class', 'value']
        });
    }
}

document.addEventListener('submit', function (event) {
    var form = event.target;

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    var dateInputsAreValid = true;
    form.querySelectorAll('.js-date-display-input').forEach(function (displayInput) {
        var pickerId = displayInput.getAttribute('data-date-picker-id') || '';
        var pickerInput = pickerId !== '' ? document.getElementById(pickerId) : null;
        if (!(pickerInput instanceof HTMLInputElement)) {
            return;
        }

        if (!syncDateFieldFromDisplay(displayInput, pickerInput, {
            normalizeDisplay: true,
            markInvalid: true
        })) {
            dateInputsAreValid = false;
        }
    });

    if (!dateInputsAreValid) {
        event.preventDefault();
        event.stopPropagation();
        return;
    }

    var method = (form.getAttribute('method') || 'get').toLowerCase();
    if (method !== 'post') {
        return;
    }

    if (form.dataset.submitting === '1') {
        event.preventDefault();
        event.stopPropagation();
        return;
    }

    form.dataset.submitting = '1';

    if (event.submitter instanceof HTMLElement) {
        disableSubmitControl(event.submitter);
    }

    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(disableSubmitControl);
});

var fleetSidebarStorageKey = 'fleet.sidebarCollapsed';

function fleetSidebarIsCollapsed() {
    return document.body.classList.contains('sidebar-collapsed');
}

function syncFleetSidebarToggleState() {
    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (toggleButton) {
        toggleButton.setAttribute('aria-expanded', fleetSidebarIsCollapsed() ? 'false' : 'true');
        toggleButton.classList.toggle('is-collapsed', fleetSidebarIsCollapsed());
    });
}

document.addEventListener('click', function (event) {
    var toggleButton = event.target.closest('[data-sidebar-toggle]');

    if (!toggleButton) {
        return;
    }

    event.preventDefault();
    document.body.classList.toggle('sidebar-collapsed');
    try {
        window.localStorage.setItem(fleetSidebarStorageKey, fleetSidebarIsCollapsed() ? '1' : '0');
    } catch (error) {
    }
    syncFleetSidebarToggleState();
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-date-display-input').forEach(initCustomDateField);
    syncFleetSidebarToggleState();
});
