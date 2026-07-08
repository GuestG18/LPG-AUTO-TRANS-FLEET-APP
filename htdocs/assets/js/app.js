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
    scheduleFleetStickyDocumentTables();
    window.setTimeout(scheduleFleetStickyDocumentTables, 250);
});

var fleetStickyDocumentTables = [];
var fleetStickyDocumentTableFrame = null;

function getFleetStickyDocumentTableTop() {
    var topbar = document.querySelector('.topbar');

    if (!(topbar instanceof HTMLElement)) {
        return 0;
    }

    return Math.ceil(topbar.getBoundingClientRect().height);
}

function createFleetStickyDocumentTable(wrapper) {
    var table = wrapper.querySelector('table.module-list-table-documente');

    if (!(table instanceof HTMLTableElement) || !table.tHead || table.tHead.rows.length === 0) {
        return null;
    }

    var stickyHeader = document.createElement('div');
    stickyHeader.className = 'document-sticky-table-header';
    stickyHeader.setAttribute('aria-hidden', 'true');

    var clonedTable = table.cloneNode(false);
    clonedTable.className = table.className + ' document-sticky-table-clone-table';
    clonedTable.appendChild(table.tHead.cloneNode(true));
    stickyHeader.appendChild(clonedTable);
    document.body.appendChild(stickyHeader);

    wrapper.addEventListener('scroll', scheduleFleetStickyDocumentTables, { passive: true });

    return {
        wrapper: wrapper,
        table: table,
        stickyHeader: stickyHeader,
        clonedTable: clonedTable
    };
}

function syncFleetStickyDocumentTableWidths(state) {
    var sourceCells = state.table.tHead.rows[0].cells;
    var cloneCells = state.clonedTable.tHead.rows[0].cells;

    state.clonedTable.style.width = state.table.getBoundingClientRect().width + 'px';

    Array.prototype.forEach.call(sourceCells, function (sourceCell, index) {
        if (!cloneCells[index]) {
            return;
        }

        var width = sourceCell.getBoundingClientRect().width;
        cloneCells[index].style.width = width + 'px';
        cloneCells[index].style.minWidth = width + 'px';
        cloneCells[index].style.maxWidth = width + 'px';
    });
}

function syncFleetStickyDocumentTable(state, topOffset) {
    var wrapperRect = state.wrapper.getBoundingClientRect();
    var tableRect = state.table.getBoundingClientRect();
    var headRect = state.table.tHead.getBoundingClientRect();
    var shouldShow = tableRect.top < topOffset
        && tableRect.bottom > topOffset + headRect.height
        && wrapperRect.bottom > topOffset + headRect.height
        && wrapperRect.width > 0;

    if (!shouldShow) {
        state.stickyHeader.classList.remove('is-visible');
        return;
    }

    var left = Math.max(wrapperRect.left, 0);
    var right = Math.min(wrapperRect.right, window.innerWidth);
    var width = Math.max(0, right - left);
    var scrollLeft = state.wrapper.scrollLeft;
    var cloneCells = state.clonedTable.tHead.rows[0].cells;

    syncFleetStickyDocumentTableWidths(state);

    state.stickyHeader.style.top = topOffset + 'px';
    state.stickyHeader.style.left = left + 'px';
    state.stickyHeader.style.width = width + 'px';
    state.stickyHeader.style.height = headRect.height + 'px';
    state.clonedTable.style.transform = 'translateX(' + (-scrollLeft) + 'px)';

    Array.prototype.forEach.call(cloneCells, function (cell, index) {
        cell.style.transform = index < 2 ? 'translateX(' + scrollLeft + 'px)' : '';
    });

    state.stickyHeader.classList.add('is-visible');
}

function syncFleetStickyDocumentTables() {
    var topOffset = getFleetStickyDocumentTableTop();

    fleetStickyDocumentTables.forEach(function (state) {
        syncFleetStickyDocumentTable(state, topOffset);
    });
}

function scheduleFleetStickyDocumentTables() {
    if (fleetStickyDocumentTableFrame !== null || fleetStickyDocumentTables.length === 0) {
        return;
    }

    fleetStickyDocumentTableFrame = window.requestAnimationFrame(function () {
        fleetStickyDocumentTableFrame = null;
        syncFleetStickyDocumentTables();
    });
}

function initFleetStickyDocumentTables() {
    document.querySelectorAll('.module-list-table-wrap-documente').forEach(function (wrapper) {
        var state = createFleetStickyDocumentTable(wrapper);

        if (state !== null) {
            fleetStickyDocumentTables.push(state);
        }
    });

    if (fleetStickyDocumentTables.length === 0) {
        return;
    }

    window.addEventListener('scroll', scheduleFleetStickyDocumentTables, { passive: true });
    window.addEventListener('resize', scheduleFleetStickyDocumentTables);
    window.addEventListener('orientationchange', scheduleFleetStickyDocumentTables);
    scheduleFleetStickyDocumentTables();
}

var fleetIdleRefreshStorageKey = 'fleet.idleRefreshAt';
var fleetIdleRefreshState = {
    hiddenAt: null,
    lastActivityAt: Date.now(),
    refreshing: false
};

function getFleetIdleRefreshSetting(value, fallback) {
    var numericValue = Number(value);

    if (Number.isFinite(numericValue) && numericValue > 0) {
        return numericValue;
    }

    return fallback;
}

function getFleetIdleRefreshConfig() {
    var config = window.FLEET_IDLE_REFRESH_CONFIG || {};

    return {
        hiddenThresholdMs: getFleetIdleRefreshSetting(config.hiddenThresholdMs, 2 * 60 * 1000),
        inactiveThresholdMs: getFleetIdleRefreshSetting(config.inactiveThresholdMs, 20 * 60 * 1000),
        refreshDelayMs: getFleetIdleRefreshSetting(config.refreshDelayMs, 1800)
    };
}

function fleetIdleRefreshWasJustHandled() {
    try {
        var handledAt = Number(window.sessionStorage.getItem(fleetIdleRefreshStorageKey) || 0);

        return handledAt > 0 && (Date.now() - handledAt) < 5000;
    } catch (error) {
        return false;
    }
}

function markFleetIdleRefreshHandled() {
    try {
        window.sessionStorage.setItem(fleetIdleRefreshStorageKey, String(Date.now()));
    } catch (error) {
    }
}

function createFleetIdleRefreshOverlay() {
    var existingOverlay = document.querySelector('[data-fleet-idle-refresh]');

    if (existingOverlay instanceof HTMLElement) {
        return existingOverlay;
    }

    var overlay = document.createElement('div');
    overlay.className = 'fleet-idle-refresh-overlay';
    overlay.setAttribute('data-fleet-idle-refresh', '1');
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'assertive');
    overlay.innerHTML = [
        '<div class="fleet-idle-refresh-copy">',
        '<div class="fleet-idle-refresh-title">Reimprospatam aplicatia</div>',
        '<div class="fleet-idle-refresh-text">Ai revenit dupa inactivitate.</div>',
        '</div>',
        '<div class="fleet-idle-refresh-road" aria-hidden="true">',
        '<span class="fleet-idle-refresh-lane"></span>',
        '<i class="bi bi-truck fleet-idle-refresh-truck"></i>',
        '</div>'
    ].join('');

    document.body.appendChild(overlay);

    return overlay;
}

function startFleetIdleRefresh(event) {
    if (fleetIdleRefreshState.refreshing || fleetIdleRefreshWasJustHandled()) {
        return false;
    }

    fleetIdleRefreshState.refreshing = true;
    markFleetIdleRefreshHandled();

    if (event && typeof event.preventDefault === 'function' && event.cancelable) {
        event.preventDefault();
    }

    if (event && typeof event.stopPropagation === 'function') {
        event.stopPropagation();
    }

    var overlay = createFleetIdleRefreshOverlay();
    var config = getFleetIdleRefreshConfig();

    window.requestAnimationFrame(function () {
        overlay.classList.add('is-visible');
    });

    window.setTimeout(function () {
        window.location.reload();
    }, config.refreshDelayMs);

    return true;
}

function initFleetIdleRefreshLoader() {
    if (!(document.querySelector('.app-shell') instanceof HTMLElement)) {
        return;
    }

    fleetIdleRefreshState.lastActivityAt = Date.now();

    function checkFleetIdleReturn(event) {
        var config = getFleetIdleRefreshConfig();
        var now = Date.now();
        var hiddenFor = fleetIdleRefreshState.hiddenAt === null ? 0 : now - fleetIdleRefreshState.hiddenAt;
        var inactiveFor = now - fleetIdleRefreshState.lastActivityAt;

        if (
            hiddenFor >= config.hiddenThresholdMs
            || inactiveFor >= config.inactiveThresholdMs
        ) {
            startFleetIdleRefresh(event);
            return;
        }

        fleetIdleRefreshState.hiddenAt = null;
        fleetIdleRefreshState.lastActivityAt = now;
    }

    function noteFleetIdleActivity(event) {
        if (document.hidden || fleetIdleRefreshState.refreshing) {
            return;
        }

        checkFleetIdleReturn(event);
    }

    function noteFleetIdlePassiveActivity() {
        if (document.hidden || fleetIdleRefreshState.refreshing) {
            return;
        }

        checkFleetIdleReturn();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            fleetIdleRefreshState.hiddenAt = Date.now();
            return;
        }

        checkFleetIdleReturn();
    });

    window.addEventListener('focus', checkFleetIdleReturn);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            checkFleetIdleReturn();
        }
    });

    document.addEventListener('pointerdown', noteFleetIdleActivity, true);
    document.addEventListener('keydown', noteFleetIdleActivity, true);
    document.addEventListener('mousemove', noteFleetIdlePassiveActivity, { passive: true });
    document.addEventListener('touchstart', noteFleetIdlePassiveActivity, { passive: true });
    document.addEventListener('scroll', noteFleetIdlePassiveActivity, true);
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-date-display-input').forEach(initCustomDateField);
    syncFleetSidebarToggleState();
    initFleetStickyDocumentTables();
    initFleetIdleRefreshLoader();
});
