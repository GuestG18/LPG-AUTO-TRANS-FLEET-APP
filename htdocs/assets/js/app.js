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

var fleetSidebarAutoHideClass = 'sidebar-auto-hide-open';
var fleetSidebarPeekClass = 'sidebar-peek';
var fleetSidebarPeekWidth = 250;

function fleetSidebarIsCollapsed() {
    return document.body.classList.contains('sidebar-collapsed');
}

function fleetSidebarIsPeeking() {
    return document.body.classList.contains(fleetSidebarPeekClass);
}

function openFleetSidebarPeek() {
    if (!fleetSidebarIsCollapsed()) {
        return;
    }
    document.body.classList.add(fleetSidebarPeekClass);
}

function closeFleetSidebarPeek() {
    document.body.classList.remove(fleetSidebarPeekClass);
}

function syncFleetSidebarToggleState() {
    document.querySelectorAll('[data-sidebar-toggle]').forEach(function (toggleButton) {
        toggleButton.setAttribute('aria-expanded', fleetSidebarIsCollapsed() ? 'false' : 'true');
        toggleButton.classList.toggle('is-collapsed', fleetSidebarIsCollapsed());
    });
}

function openFleetSidebarTemporarily() {
    document.body.classList.remove('sidebar-collapsed');
    document.body.classList.add(fleetSidebarAutoHideClass);
}

function closeFleetSidebar() {
    document.body.classList.add('sidebar-collapsed');
    document.body.classList.remove(fleetSidebarAutoHideClass);
}

function refreshFleetSidebarLayout() {
    syncFleetSidebarToggleState();
    scheduleFleetStickyDocumentTables();
    window.setTimeout(scheduleFleetStickyDocumentTables, 250);
}

document.addEventListener('click', function (event) {
    var toggleButton = event.target.closest('[data-sidebar-toggle]');

    if (!toggleButton) {
        return;
    }

    event.preventDefault();

    closeFleetSidebarPeek();

    if (fleetSidebarIsCollapsed()) {
        openFleetSidebarTemporarily();
    } else {
        closeFleetSidebar();
    }

    refreshFleetSidebarLayout();
});

function normalizeFleetSidebarSearchValue(value) {
    var text = String(value || '').trim().toLowerCase();

    if (typeof text.normalize === 'function') {
        text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    return text;
}

function getFleetSidebarSearchText(element) {
    if (!(element instanceof HTMLElement)) {
        return '';
    }

    return normalizeFleetSidebarSearchValue(element.textContent || '');
}

function setFleetSidebarSearchCollapseState(collapse, button, expanded) {
    if (!(collapse instanceof HTMLElement)) {
        if (button instanceof HTMLElement) {
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
        return;
    }

    collapse.classList.remove('collapsing');
    collapse.classList.add('collapse');
    collapse.classList.toggle('show', expanded);
    collapse.style.height = '';

    if (button instanceof HTMLElement) {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}

function restoreFleetSidebarSearchGroup(group, collapse, button) {
    group.hidden = false;

    group.querySelectorAll('.sidebar-submenu .nav-link').forEach(function (link) {
        if (link instanceof HTMLElement) {
            link.hidden = false;
        }
    });

    if (!group.hasAttribute('data-sidebar-search-initial-expanded')) {
        return;
    }

    setFleetSidebarSearchCollapseState(
        collapse,
        button,
        group.getAttribute('data-sidebar-search-initial-expanded') === '1'
    );
    group.removeAttribute('data-sidebar-search-initial-expanded');
}

function filterFleetSidebarSearchGroup(group, query) {
    var button = group.querySelector('.sidebar-parent-link');
    var collapse = group.querySelector('.collapse');
    var links = Array.prototype.slice.call(group.querySelectorAll('.sidebar-submenu .nav-link'));

    if (!(button instanceof HTMLElement) || !(collapse instanceof HTMLElement)) {
        return false;
    }

    if (!group.hasAttribute('data-sidebar-search-initial-expanded')) {
        group.setAttribute('data-sidebar-search-initial-expanded', collapse.classList.contains('show') ? '1' : '0');
    }

    var parentMatches = getFleetSidebarSearchText(button).indexOf(query) !== -1;
    var matchingLinks = links.filter(function (link) {
        return getFleetSidebarSearchText(link).indexOf(query) !== -1;
    });
    var groupMatches = parentMatches || matchingLinks.length > 0;

    group.hidden = !groupMatches;

    links.forEach(function (link) {
        if (link instanceof HTMLElement) {
            link.hidden = !(parentMatches || matchingLinks.indexOf(link) !== -1);
        }
    });

    if (groupMatches) {
        setFleetSidebarSearchCollapseState(collapse, button, true);
    }

    return groupMatches;
}

function initFleetSidebarSearch() {
    var search = document.querySelector('[data-sidebar-search]');
    var nav = document.querySelector('[data-sidebar-nav]');

    if (!(search instanceof HTMLElement) || !(nav instanceof HTMLElement)) {
        return;
    }

    var input = search.querySelector('[data-sidebar-search-input]');
    var clearButton = search.querySelector('[data-sidebar-search-clear]');
    var emptyState = search.querySelector('[data-sidebar-search-empty]');

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    function syncSearchState() {
        var query = normalizeFleetSidebarSearchValue(input.value);
        var hasQuery = query !== '';
        var matchCount = 0;

        Array.prototype.slice.call(nav.children).forEach(function (item) {
            if (!(item instanceof HTMLElement)) {
                return;
            }

            if (item.hasAttribute('data-sidebar-search-static')) {
                item.hidden = hasQuery;
                return;
            }

            if (item.classList.contains('sidebar-nav-group')) {
                if (!hasQuery) {
                    restoreFleetSidebarSearchGroup(
                        item,
                        item.querySelector('.collapse'),
                        item.querySelector('.sidebar-parent-link')
                    );
                    return;
                }

                if (filterFleetSidebarSearchGroup(item, query)) {
                    matchCount += 1;
                }
                return;
            }

            if (!item.classList.contains('nav-link')) {
                item.hidden = false;
                return;
            }

            var linkMatches = !hasQuery || getFleetSidebarSearchText(item).indexOf(query) !== -1;
            item.hidden = !linkMatches;

            if (hasQuery && linkMatches) {
                matchCount += 1;
            }
        });

        nav.classList.toggle('is-searching', hasQuery);

        if (clearButton instanceof HTMLButtonElement) {
            clearButton.hidden = !hasQuery;
        }

        if (emptyState instanceof HTMLElement) {
            emptyState.hidden = !hasQuery || matchCount > 0;
        }
    }

    input.addEventListener('input', syncSearchState);
    input.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || input.value === '') {
            return;
        }

        input.value = '';
        syncSearchState();
        event.stopPropagation();
    });

    if (clearButton instanceof HTMLButtonElement) {
        clearButton.addEventListener('click', function () {
            input.value = '';
            syncSearchState();
            input.focus();
        });
    }

    syncSearchState();
}

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
        hiddenThresholdMs: getFleetIdleRefreshSetting(config.hiddenThresholdMs, 15 * 60 * 1000),
        inactiveThresholdMs: getFleetIdleRefreshSetting(config.inactiveThresholdMs, 60 * 60 * 1000),
        refreshDelayMs: getFleetIdleRefreshSetting(config.refreshDelayMs, 1800)
    };
}

var fleetUnsavedState = {
    forms: [],
    fields: [],
    noticeAt: 0
};

// Inputurile ascunse au value === defaultValue (mod "default"), asa ca pentru
// ele pastram separat valoarea initiala; altfel campurile completate de
// pickere (data/ora, alegeri din modale) nu ar fi vazute ca nesalvate.
var fleetInitialHiddenValues = new WeakMap();

function isFleetHiddenInput(field) {
    return field instanceof HTMLInputElement && field.type === 'hidden';
}

function snapshotFleetHiddenValues(root) {
    if (!root || typeof root.querySelectorAll !== 'function') {
        return;
    }

    root.querySelectorAll('input[type="hidden"]').forEach(function (field) {
        if (!fleetInitialHiddenValues.has(field)) {
            fleetInitialHiddenValues.set(field, field.value);
        }
    });
}

function fleetHiddenInputHasUnsavedValue(field) {
    if (!fleetInitialHiddenValues.has(field)) {
        return field.value !== '';
    }

    return field.value !== fleetInitialHiddenValues.get(field);
}

function fleetIdleRefreshIsIgnored(element) {
    return !!(element && typeof element.closest === 'function' && element.closest('[data-idle-refresh-ignore]'));
}

function isFleetTrackedFormField(field) {
    if (field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement) {
        return true;
    }

    if (!(field instanceof HTMLInputElement)) {
        return false;
    }

    return ['submit', 'reset', 'button', 'image'].indexOf(field.type) === -1;
}

function fleetSelectHasUnsavedValue(select) {
    var selected = [];
    var defaults = [];

    Array.prototype.forEach.call(select.options, function (option) {
        if (option.selected) {
            selected.push(option.value);
        }

        if (option.defaultSelected) {
            defaults.push(option.value);
        }
    });

    if (defaults.length === 0 && !select.multiple && select.options.length > 0) {
        defaults.push(select.options[0].value);
    }

    return selected.join('|') !== defaults.join('|');
}

function fleetFieldHasUnsavedValue(field) {
    if (!isFleetTrackedFormField(field) || !field.isConnected || field.disabled) {
        return false;
    }

    if (fleetIdleRefreshIsIgnored(field)) {
        return false;
    }

    if (field instanceof HTMLSelectElement) {
        return fleetSelectHasUnsavedValue(field);
    }

    if (field instanceof HTMLInputElement) {
        if (field.type === 'hidden') {
            return fleetHiddenInputHasUnsavedValue(field);
        }

        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked !== field.defaultChecked;
        }

        if (field.type === 'file') {
            return !!(field.files && field.files.length > 0);
        }
    }

    return field.value !== field.defaultValue;
}

function fleetFormHasTrackedFields(form) {
    return Array.prototype.some.call(form.elements || [], isFleetTrackedFormField);
}

function noteFleetEngagedForm(target) {
    if (!(target instanceof HTMLElement) || fleetIdleRefreshIsIgnored(target)) {
        return;
    }

    var form = target.closest('form');

    if (form instanceof HTMLFormElement) {
        if (fleetFormHasTrackedFields(form) && fleetUnsavedState.forms.indexOf(form) === -1) {
            snapshotFleetHiddenValues(form);
            fleetUnsavedState.forms.push(form);
        }

        return;
    }

    if (isFleetTrackedFormField(target) && fleetUnsavedState.fields.indexOf(target) === -1) {
        fleetUnsavedState.fields.push(target);
    }
}

function forgetFleetEngagedForm(form) {
    var index = fleetUnsavedState.forms.indexOf(form);

    if (index !== -1) {
        fleetUnsavedState.forms.splice(index, 1);
    }

    form.querySelectorAll('input[type="hidden"]').forEach(function (field) {
        fleetInitialHiddenValues.set(field, field.value);
    });
}

function fleetHasUnsavedInput() {
    fleetUnsavedState.forms = fleetUnsavedState.forms.filter(function (form) {
        return form.isConnected;
    });
    fleetUnsavedState.fields = fleetUnsavedState.fields.filter(function (field) {
        return field.isConnected;
    });

    var hasDirtyForm = fleetUnsavedState.forms.some(function (form) {
        if (fleetIdleRefreshIsIgnored(form)) {
            return false;
        }

        return Array.prototype.some.call(form.elements, fleetFieldHasUnsavedValue);
    });

    if (hasDirtyForm) {
        return true;
    }

    return fleetUnsavedState.fields.some(fleetFieldHasUnsavedValue);
}

function showFleetIdleRefreshSkippedNotice() {
    var now = Date.now();

    if (now - fleetUnsavedState.noticeAt < 30000) {
        return;
    }

    fleetUnsavedState.noticeAt = now;

    var existingHint = document.querySelector('[data-fleet-idle-hint]');

    if (existingHint !== null) {
        existingHint.remove();
    }

    var hint = document.createElement('div');
    hint.className = 'fleet-idle-refresh-hint';
    hint.setAttribute('data-fleet-idle-hint', '1');
    hint.setAttribute('role', 'status');
    hint.innerHTML = [
        '<i class="bi bi-shield-check" aria-hidden="true"></i>',
        '<span>Reimprospatarea automata a fost oprita: ai date nesalvate in formular.</span>'
    ].join('');

    document.body.appendChild(hint);

    window.requestAnimationFrame(function () {
        hint.classList.add('is-visible');
    });

    window.setTimeout(function () {
        hint.classList.remove('is-visible');
        window.setTimeout(function () {
            hint.remove();
        }, 250);
    }, 6000);
}

function initFleetUnsavedInputTracking() {
    snapshotFleetHiddenValues(document);

    function noteEngagement(event) {
        if (event.isTrusted) {
            noteFleetEngagedForm(event.target);
        }
    }

    function forgetEngagement(event) {
        if (event.target instanceof HTMLFormElement) {
            forgetFleetEngagedForm(event.target);
        }
    }

    document.addEventListener('input', noteEngagement, true);
    document.addEventListener('change', noteEngagement, true);
    document.addEventListener('pointerdown', noteEngagement, true);
    document.addEventListener('keydown', noteEngagement, true);
    document.addEventListener('submit', forgetEngagement, true);
    document.addEventListener('reset', forgetEngagement, true);
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
    if (fleetIdleRefreshState.refreshing || fleetIdleRefreshWasJustHandled() || fleetHasUnsavedInput()) {
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
    initFleetUnsavedInputTracking();

    function checkFleetIdleReturn(event) {
        var config = getFleetIdleRefreshConfig();
        var now = Date.now();
        var hiddenFor = fleetIdleRefreshState.hiddenAt === null ? 0 : now - fleetIdleRefreshState.hiddenAt;
        var inactiveFor = now - fleetIdleRefreshState.lastActivityAt;

        if (
            hiddenFor >= config.hiddenThresholdMs
            || inactiveFor >= config.inactiveThresholdMs
        ) {
            if (fleetHasUnsavedInput()) {
                fleetIdleRefreshState.hiddenAt = null;
                fleetIdleRefreshState.lastActivityAt = now;
                showFleetIdleRefreshSkippedNotice();
                return;
            }

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

function dashboardOperationalCostPrefersReducedMotion() {
    return window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function syncDashboardOperationalStateInputs(expanded) {
    document.querySelectorAll('[data-dashboard-operational-state-input]').forEach(function (input) {
        if (input instanceof HTMLInputElement) {
            input.value = expanded ? '1' : '0';
        }
    });
}

function getDashboardOperationalDetailCards() {
    return Array.prototype.slice.call(document.querySelectorAll('[data-dashboard-operational-detail-card]')).filter(function (detailCard) {
        return detailCard instanceof HTMLElement;
    });
}

function getDashboardOperationalDetailRegion() {
    var region = document.querySelector('[data-dashboard-operational-detail-region]');

    return region instanceof HTMLElement ? region : null;
}

function clearDashboardOperationalTimer(element, key) {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    var timer = element.dataset[key];
    if (timer) {
        window.clearTimeout(parseInt(timer, 10));
        delete element.dataset[key];
    }
}

function setDashboardOperationalDetailCardsVisible(expanded, animate) {
    var region = getDashboardOperationalDetailRegion();
    var reduceMotion = dashboardOperationalCostPrefersReducedMotion();
    var detailCards = getDashboardOperationalDetailCards();

    if (!(region instanceof HTMLElement)) {
        return;
    }

    clearDashboardOperationalTimer(region, 'dashboardOperationalHideTimer');

    detailCards.forEach(function (detailCard) {
        detailCard.setAttribute('aria-hidden', expanded ? 'false' : 'true');
    });

    if (expanded) {
        region.hidden = false;
        region.setAttribute('aria-hidden', 'false');
        region.classList.remove('is-leaving');

        if (animate && !reduceMotion) {
            region.classList.remove('is-visible');
            window.requestAnimationFrame(function () {
                region.classList.add('is-visible');
            });
        } else {
            region.classList.add('is-visible');
        }

        return;
    }

    region.setAttribute('aria-hidden', 'true');
    region.classList.add('is-leaving');
    region.classList.remove('is-visible');

    if (!animate || reduceMotion) {
        region.hidden = true;
        region.classList.remove('is-leaving');
        return;
    }

    var hideTimer = window.setTimeout(function () {
        if (!region.classList.contains('is-visible')) {
            region.hidden = true;
        }

        region.classList.remove('is-leaving');
        delete region.dataset.dashboardOperationalHideTimer;
    }, 300);

    region.dataset.dashboardOperationalHideTimer = String(hideTimer);
}

function setDashboardOperationalSummaryVisible(card, visible, animate) {
    if (!(card instanceof HTMLElement)) {
        return;
    }

    var reduceMotion = dashboardOperationalCostPrefersReducedMotion();
    clearDashboardOperationalTimer(card, 'dashboardOperationalSummaryTimer');
    card.setAttribute('aria-hidden', visible ? 'false' : 'true');

    if (visible) {
        card.hidden = false;

        if (animate && !reduceMotion) {
            card.classList.add('is-leaving');
            window.requestAnimationFrame(function () {
                card.classList.remove('is-leaving');
            });
        } else {
            card.classList.remove('is-leaving');
        }

        return;
    }

    card.classList.add('is-leaving');

    if (!animate || reduceMotion) {
        card.hidden = true;
        card.classList.remove('is-leaving');
        return;
    }

    var hideTimer = window.setTimeout(function () {
        if (card.classList.contains('is-expanded')) {
            card.hidden = true;
        }

        card.classList.remove('is-leaving');
        delete card.dataset.dashboardOperationalSummaryTimer;
    }, 180);

    card.dataset.dashboardOperationalSummaryTimer = String(hideTimer);
}

function applyDashboardOperationalCostState(card, expanded, animateDetails) {
    var toggle = card.querySelector('[data-dashboard-operational-toggle]');
    var grid = card.closest('[data-dashboard-main-grid]');
    var stateValue = expanded ? 'true' : 'false';

    card.classList.toggle('is-expanded', expanded);
    card.setAttribute('aria-expanded', stateValue);

    if (grid instanceof HTMLElement) {
        grid.classList.toggle('is-operational-expanded', expanded);
    }

    if (toggle instanceof HTMLButtonElement) {
        toggle.setAttribute('aria-expanded', stateValue);
        toggle.setAttribute('aria-label', expanded ? 'Restrange cost total operational' : 'Extinde cost total operational');
    }

    setDashboardOperationalSummaryVisible(card, !expanded, animateDetails === true);
    setDashboardOperationalDetailCardsVisible(expanded, animateDetails === true);
    syncDashboardOperationalStateInputs(expanded);
}

function animateDashboardOperationalCostResize(card, applyState) {
    if (dashboardOperationalCostPrefersReducedMotion()) {
        applyState();
        return;
    }

    var firstRect = card.getBoundingClientRect();
    applyState();
    var lastRect = card.getBoundingClientRect();

    if (
        firstRect.width <= 0
        || firstRect.height <= 0
        || lastRect.width <= 0
        || lastRect.height <= 0
    ) {
        return;
    }

    var deltaX = firstRect.left - lastRect.left;
    var deltaY = firstRect.top - lastRect.top;
    var scaleX = firstRect.width / lastRect.width;
    var scaleY = firstRect.height / lastRect.height;
    var nearlySame = Math.abs(deltaX) < 0.5
        && Math.abs(deltaY) < 0.5
        && Math.abs(scaleX - 1) < 0.01
        && Math.abs(scaleY - 1) < 0.01;

    if (nearlySame) {
        return;
    }

    card.dataset.dashboardOperationalAnimating = '1';
    card.classList.add('is-resizing');
    card.style.transformOrigin = 'top left';
    card.style.transition = 'none';
    card.style.transform = 'translate(' + deltaX + 'px, ' + deltaY + 'px) scale(' + scaleX + ', ' + scaleY + ')';
    card.getBoundingClientRect();

    window.requestAnimationFrame(function () {
        card.style.transition = 'transform 300ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.18s ease, border-color 0.18s ease';
        card.style.transform = '';
    });

    var cleanedUp = false;
    var cleanup = function (event) {
        if (cleanedUp || (event && event.type === 'transitionend' && event.propertyName !== 'transform')) {
            return;
        }

        cleanedUp = true;
        card.classList.remove('is-resizing');
        card.style.transformOrigin = '';
        card.style.transition = '';
        delete card.dataset.dashboardOperationalAnimating;
        card.removeEventListener('transitionend', cleanup);
    };

    card.addEventListener('transitionend', cleanup);
    window.setTimeout(cleanup, 360);
}

function setDashboardOperationalCostExpanded(card, expanded, animate) {
    if (!(card instanceof HTMLElement)) {
        return;
    }

    if (card.classList.contains('is-expanded') === expanded) {
        syncDashboardOperationalStateInputs(expanded);
        setDashboardOperationalSummaryVisible(card, !expanded, false);
        setDashboardOperationalDetailCardsVisible(expanded, false);
        return;
    }

    applyDashboardOperationalCostState(card, expanded, animate);
}

function initDashboardOperationalCostCard() {
    var card = document.querySelector('[data-dashboard-operational-card]');
    var collapseButton = document.querySelector('[data-dashboard-operational-collapse]');

    if (!(card instanceof HTMLElement)) {
        return;
    }

    applyDashboardOperationalCostState(card, card.classList.contains('is-expanded'), false);

    card.addEventListener('click', function (event) {
        if (card.dataset.dashboardOperationalAnimating === '1') {
            event.preventDefault();
            return;
        }

        var target = event.target instanceof Element ? event.target : null;
        if (target === null) {
            return;
        }

        var toggle = target.closest('[data-dashboard-operational-toggle]');
        var interactive = target.closest('a, button, input, select, textarea, label, [data-bs-toggle]');
        if (interactive && toggle === null) {
            return;
        }

        event.preventDefault();
        setDashboardOperationalCostExpanded(card, !card.classList.contains('is-expanded'), true);
    });

    card.addEventListener('keydown', function (event) {
        if (event.target !== card || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }

        event.preventDefault();
        setDashboardOperationalCostExpanded(card, !card.classList.contains('is-expanded'), true);
    });

    if (collapseButton instanceof HTMLButtonElement) {
        collapseButton.addEventListener('click', function (event) {
            event.preventDefault();
            setDashboardOperationalCostExpanded(card, false, true);
        });
    }
}

function initDashboardApprovalTabs() {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-dashboard-approval-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-dashboard-approval-panel]'));

    if (tabs.length === 0 || panels.length === 0) {
        return;
    }

    function activate(tabKey) {
        tabs.forEach(function (tab) {
            if (!(tab instanceof HTMLButtonElement)) {
                return;
            }

            var isActive = tab.getAttribute('data-dashboard-approval-tab') === tabKey;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach(function (panel) {
            if (!(panel instanceof HTMLElement)) {
                return;
            }

            var isActive = panel.getAttribute('data-dashboard-approval-panel') === tabKey;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });
    }

    tabs.forEach(function (tab) {
        if (!(tab instanceof HTMLButtonElement)) {
            return;
        }

        tab.addEventListener('click', function () {
            activate(tab.getAttribute('data-dashboard-approval-tab') || '');
        });
    });

    document.querySelectorAll('.dashboard-approval-close').forEach(function (button) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        if (button.closest('[data-global-approval-drawer]')) {
            return;
        }

        button.addEventListener('click', function () {
            var panel = button.closest('.dashboard-approval-panel');
            if (panel instanceof HTMLElement) {
                panel.hidden = true;
            }
        });
    });
}

function initGlobalApprovalDrawer() {
    var drawer = document.querySelector('[data-global-approval-drawer]');
    if (!(drawer instanceof HTMLElement)) {
        return;
    }

    var toggle = drawer.querySelector('[data-global-approval-toggle]');
    var closeButtons = Array.prototype.slice.call(drawer.querySelectorAll('[data-global-approval-close]'));

    if (!(toggle instanceof HTMLButtonElement)) {
        return;
    }

    function setOpen(open) {
        drawer.classList.toggle('is-open', open);
        document.body.classList.toggle('global-approval-drawer-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute(
            'aria-label',
            open ? 'Inchide solicitarile de aprobare' : 'Deschide solicitarile de aprobare'
        );

        var icon = toggle.querySelector('i');
        if (icon instanceof HTMLElement) {
            icon.classList.toggle('bi-chevron-left', !open);
            icon.classList.toggle('bi-chevron-right', open);
        }
    }

    toggle.addEventListener('click', function () {
        setOpen(!drawer.classList.contains('is-open'));
    });

    closeButtons.forEach(function (closeButton) {
        if (!(closeButton instanceof HTMLButtonElement)) {
            return;
        }

        closeButton.addEventListener('click', function () {
            setOpen(false);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
            setOpen(false);
        }
    });

    document.addEventListener('click', function (event) {
        if (!drawer.classList.contains('is-open')) {
            return;
        }

        var target = event.target instanceof Element ? event.target : null;
        if (target && !drawer.contains(target)) {
            setOpen(false);
        }
    });
}

function parseApprovalCount(element) {
    if (!(element instanceof HTMLElement)) {
        return 0;
    }

    var raw = String(element.textContent || '').trim();
    var parsed = parseInt(raw, 10);
    return Number.isFinite(parsed) ? parsed : 0;
}

function setApprovalCount(element, value) {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    var nextValue = Math.max(0, value);
    element.textContent = String(nextValue);
    if (element.hasAttribute('data-approval-total-badge')) {
        element.hidden = nextValue <= 0;
    }
}

function decrementApprovalCounter(selector, root) {
    var scope = root instanceof HTMLElement ? root : document;
    scope.querySelectorAll(selector).forEach(function (element) {
        if (!(element instanceof HTMLElement)) {
            return;
        }

        setApprovalCount(element, parseApprovalCount(element) - 1);
    });
}

function refreshApprovalEmptyState(panel) {
    if (!(panel instanceof HTMLElement)) {
        return;
    }

    var hasCards = panel.querySelector('[data-approval-card]') !== null;
    var empty = panel.querySelector('[data-approval-empty]');
    if (!(empty instanceof HTMLElement) && !hasCards) {
        empty = document.createElement('div');
        empty.className = 'dashboard-approval-empty';
        empty.setAttribute('data-approval-empty', panel.getAttribute('data-dashboard-approval-panel') || '');
        empty.textContent = 'Nu exista solicitari in asteptare.';
        panel.insertBefore(empty, panel.firstChild);
    }
    if (empty instanceof HTMLElement) {
        empty.hidden = hasCards;
    }
}

function removeCancelledApprovalFromUi(approvalId) {
    var normalizedId = String(approvalId || '').trim();
    if (normalizedId === '') {
        return;
    }

    var selector = '[data-approval-card][data-approval-id="' + normalizedId.replace(/"/g, '\\"') + '"]';
    var removedStatuses = {};
    document.querySelectorAll(selector).forEach(function (card) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        var status = String(card.getAttribute('data-approval-status') || 'pending').trim() || 'pending';
        removedStatuses[status] = true;
        var panel = card.closest('[data-dashboard-approval-panel], .user-approval-card-stack');
        card.remove();
        if (panel instanceof HTMLElement) {
            refreshApprovalEmptyState(panel);
        }
    });

    Object.keys(removedStatuses).forEach(function (status) {
        decrementApprovalCounter('[data-approval-tab-count="' + status.replace(/"/g, '\\"') + '"]');
        decrementApprovalCounter('[data-user-approval-tab-count="' + status.replace(/"/g, '\\"') + '"]');
    });

    if (Object.prototype.hasOwnProperty.call(removedStatuses, 'pending')) {
        decrementApprovalCounter('[data-approval-total-count]');
        decrementApprovalCounter('[data-approval-total-badge]');
    }
}

function showApprovalReviewFeedback(host, message, tone) {
    if (!(host instanceof HTMLElement)) {
        return;
    }

    host.querySelectorAll('[data-approval-review-feedback]').forEach(function (existing) {
        existing.remove();
    });

    var feedback = document.createElement('div');
    feedback.className = 'dashboard-approval-feedback is-' + (tone === 'success' ? 'success' : 'error');
    feedback.setAttribute('data-approval-review-feedback', '');
    feedback.setAttribute('role', tone === 'success' ? 'status' : 'alert');

    var icon = document.createElement('i');
    icon.className = tone === 'success' ? 'bi bi-check-circle' : 'bi bi-exclamation-triangle';
    icon.setAttribute('aria-hidden', 'true');
    feedback.appendChild(icon);
    feedback.appendChild(document.createTextNode(String(message || 'Solicitarea nu a putut fi procesata.')));

    var actions = host.querySelector('.dashboard-approval-actions');
    if (actions instanceof HTMLElement) {
        actions.parentNode.insertBefore(feedback, actions);
        return;
    }

    host.insertBefore(feedback, host.firstChild);
}

function showApprovalPanelFeedback(panel, message, tone) {
    if (!(panel instanceof HTMLElement)) {
        return;
    }

    panel.querySelectorAll(':scope > [data-approval-review-feedback]').forEach(function (existing) {
        existing.remove();
    });

    var feedback = document.createElement('div');
    feedback.className = 'dashboard-approval-feedback is-' + (tone === 'success' ? 'success' : 'error');
    feedback.setAttribute('data-approval-review-feedback', '');
    feedback.setAttribute('role', tone === 'success' ? 'status' : 'alert');

    var icon = document.createElement('i');
    icon.className = tone === 'success' ? 'bi bi-check-circle' : 'bi bi-exclamation-triangle';
    icon.setAttribute('aria-hidden', 'true');
    feedback.appendChild(icon);
    feedback.appendChild(document.createTextNode(String(message || 'Solicitarea a fost actualizata.')));

    var header = panel.querySelector('.dashboard-approval-header');
    if (header instanceof HTMLElement && header.nextSibling) {
        panel.insertBefore(feedback, header.nextSibling);
        return;
    }

    panel.insertBefore(feedback, panel.firstChild);
}

function setApprovalReviewBusy(card, busy) {
    if (!(card instanceof HTMLElement)) {
        return;
    }

    card.dataset.approvalReviewBusy = busy ? '1' : '';
    card.classList.toggle('is-reviewing', busy);
    card.querySelectorAll('[data-approval-review-form] button').forEach(function (button) {
        if (button instanceof HTMLButtonElement) {
            button.disabled = busy;
        }
    });
}

function removeResolvedApprovalFromUi(approvalId, summary) {
    var normalizedId = String(approvalId || '').trim();
    if (normalizedId === '') {
        return;
    }

    var selector = '[data-approval-card][data-approval-id="' + normalizedId.replace(/"/g, '\\"') + '"]';
    var affectedPanels = [];
    var affectedTabKeys = {};

    document.querySelectorAll(selector).forEach(function (card) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        var tabKey = String(card.getAttribute('data-approval-tab-key') || '').trim();
        if (tabKey !== '') {
            affectedTabKeys[tabKey] = true;
        }

        var panel = card.closest('[data-dashboard-approval-panel], .user-approval-card-stack');
        card.remove();
        if (panel instanceof HTMLElement) {
            affectedPanels.push(panel);
        }
    });

    affectedPanels.forEach(refreshApprovalEmptyState);

    if (summary && typeof summary === 'object') {
        syncApprovalCountersFromSummary(summary);
        return;
    }

    Object.keys(affectedTabKeys).forEach(function (tabKey) {
        decrementApprovalCounter('[data-approval-tab-count="' + tabKey.replace(/"/g, '\\"') + '"]');
    });
    if (Object.keys(affectedTabKeys).length > 0) {
        decrementApprovalCounter('[data-approval-total-count]');
        decrementApprovalCounter('[data-approval-total-badge]');
    }
}

function approvalReviewErrorMessage(error) {
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }

    return 'Solicitarea nu a putut fi procesata. Reincarca si incearca din nou.';
}

function submitApprovalReviewForm(form) {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    var card = form.closest('[data-approval-card]');
    if (!(card instanceof HTMLElement)) {
        form.submit();
        return;
    }

    if (card.dataset.approvalReviewBusy === '1') {
        return;
    }

    var actionUrl = String(form.getAttribute('action') || '').trim();
    if (actionUrl === '') {
        showApprovalReviewFeedback(card, 'Actiune invalida. Reincarca pagina si incearca din nou.', 'error');
        return;
    }

    var panel = card.closest('.dashboard-approval-panel');
    setApprovalReviewBusy(card, true);

    fetch(actionUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new FormData(form)
    })
        .then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok || !payload.success) {
                    var error = new Error(payload.message || 'Solicitarea nu a putut fi procesata.');
                    error.payload = payload;
                    error.status = response.status;
                    throw error;
                }

                return payload;
            });
        })
        .then(function (payload) {
            showApprovalPanelFeedback(panel, payload.message || 'Solicitarea a fost procesata.', 'success');
            removeResolvedApprovalFromUi(payload.approval_id || form.querySelector('input[name="id"]')?.value, payload.summary || null);
        })
        .catch(function (error) {
            var message = approvalReviewErrorMessage(error);
            if (error && error.status === 409 && error.payload && String(error.payload.current_status || '').trim() !== 'pending') {
                showApprovalPanelFeedback(panel, message, 'error');
                removeResolvedApprovalFromUi(error.payload.approval_id || form.querySelector('input[name="id"]')?.value, error.payload.summary || null);
                return;
            }

            showApprovalReviewFeedback(card, message, 'error');
            setApprovalReviewBusy(card, false);
        });
}

function initApprovalReviewActions() {
    document.addEventListener('submit', function (event) {
        var form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-approval-review-form')) {
            return;
        }

        event.preventDefault();
        submitApprovalReviewForm(form);
    });
}

function userApprovalCancelMessageFromError(error) {
    var fallback = 'Solicitarea nu mai poate fi anulata deoarece statusul ei s-a modificat.';
    if (error && typeof error.message === 'string' && error.message.trim() !== '') {
        return error.message.trim();
    }

    return fallback;
}

function findUserApprovalCancelHost(button) {
    if (!(button instanceof HTMLElement)) {
        return null;
    }

    var host = button.closest('[data-approval-card], [data-approval-detail]');
    return host instanceof HTMLElement ? host : null;
}

function clearUserApprovalCancelFeedback(host) {
    if (!(host instanceof HTMLElement)) {
        return;
    }

    host.querySelectorAll('[data-user-approval-cancel-feedback]').forEach(function (feedback) {
        feedback.remove();
    });
}

function showUserApprovalCancelFeedback(host, message, tone) {
    if (!(host instanceof HTMLElement)) {
        return;
    }

    clearUserApprovalCancelFeedback(host);

    var feedback = document.createElement('div');
    feedback.className = 'user-approval-cancel-feedback is-' + (tone === 'success' ? 'success' : 'error');
    feedback.setAttribute('data-user-approval-cancel-feedback', '');
    feedback.setAttribute('role', tone === 'success' ? 'status' : 'alert');

    var icon = document.createElement('i');
    icon.className = tone === 'success' ? 'bi bi-check-circle' : 'bi bi-exclamation-triangle';
    icon.setAttribute('aria-hidden', 'true');
    feedback.appendChild(icon);
    feedback.appendChild(document.createTextNode(String(message || 'Solicitarea nu a putut fi anulata.')));

    var actions = host.querySelector('.fleet-approval-user-actions, .inactive-approval-detail-actions');
    if (actions instanceof HTMLElement) {
        actions.parentNode.insertBefore(feedback, actions);
        return;
    }

    host.appendChild(feedback);
}

function markApprovalDetailAsCancelled(approvalId, message) {
    var normalizedId = String(approvalId || '').trim();
    if (normalizedId === '') {
        return;
    }

    document.querySelectorAll('[data-approval-detail]').forEach(function (detail) {
        if (!(detail instanceof HTMLElement) || String(detail.getAttribute('data-approval-id') || '') !== normalizedId) {
            return;
        }

        detail.querySelectorAll('[data-user-approval-cancel]').forEach(function (button) {
            button.remove();
        });
        detail.querySelectorAll('[data-approval-detail-status]').forEach(function (statusElement) {
            if (!(statusElement instanceof HTMLElement)) {
                return;
            }

            statusElement.className = 'inactive-approval-status is-cancelled';
            statusElement.textContent = 'Anulata';
        });

        showUserApprovalCancelFeedback(detail, message || 'Solicitarea a fost anulata.', 'success');
    });
}

function syncApprovalCountersFromSummary(summary) {
    if (!summary || typeof summary !== 'object') {
        return;
    }

    var counts = summary.counts && typeof summary.counts === 'object' ? summary.counts : {};
    Object.keys(counts).forEach(function (status) {
        var value = parseInt(counts[status], 10);
        if (!Number.isFinite(value)) {
            return;
        }

        document.querySelectorAll('[data-approval-tab-count="' + status.replace(/"/g, '\\"') + '"], [data-user-approval-tab-count="' + status.replace(/"/g, '\\"') + '"]').forEach(function (element) {
            setApprovalCount(element, value);
        });
    });

    var total = parseInt(summary.total, 10);
    if (Number.isFinite(total)) {
        document.querySelectorAll('[data-approval-total-count], [data-approval-total-badge]').forEach(function (element) {
            setApprovalCount(element, total);
        });
    }
}

function applyUserApprovalStatusToHost(host, status) {
    if (!(host instanceof HTMLElement)) {
        return;
    }

    var normalizedStatus = String(status || '').trim();
    var statusLabels = {
        pending: 'In asteptare',
        approved: 'Aprobata',
        rejected: 'Respinsa',
        cancelled: 'Anulata'
    };
    var statusIcons = {
        pending: 'bi-hourglass-split',
        approved: 'bi-check-circle',
        rejected: 'bi-x-circle',
        cancelled: 'bi-slash-circle'
    };

    if (!Object.prototype.hasOwnProperty.call(statusLabels, normalizedStatus)) {
        return;
    }

    host.setAttribute('data-approval-status', normalizedStatus);
    host.querySelectorAll('[data-user-approval-cancel]').forEach(function (button) {
        button.remove();
    });
    host.querySelectorAll('.user-approval-status').forEach(function (statusElement) {
        if (!(statusElement instanceof HTMLElement)) {
            return;
        }

        statusElement.className = 'user-approval-status is-' + normalizedStatus;
        statusElement.textContent = '';

        var icon = document.createElement('i');
        icon.className = 'bi ' + statusIcons[normalizedStatus];
        icon.setAttribute('aria-hidden', 'true');
        statusElement.appendChild(icon);
        statusElement.appendChild(document.createTextNode(statusLabels[normalizedStatus]));
    });
    host.querySelectorAll('[data-approval-detail-status]').forEach(function (statusElement) {
        if (!(statusElement instanceof HTMLElement)) {
            return;
        }

        statusElement.className = 'inactive-approval-status is-' + normalizedStatus;
        statusElement.textContent = statusLabels[normalizedStatus];
    });
}

function syncApprovalRequestStatusFromPayload(approvalId, payload, message) {
    if (!payload || typeof payload !== 'object') {
        return;
    }

    var currentStatus = String(payload.current_status || '').trim();
    if (currentStatus === '') {
        return;
    }

    var normalizedId = String(approvalId || payload.approval_id || '').trim();
    if (normalizedId === '') {
        return;
    }

    document.querySelectorAll('[data-approval-card], [data-approval-detail]').forEach(function (host) {
        if (!(host instanceof HTMLElement) || String(host.getAttribute('data-approval-id') || '') !== normalizedId) {
            return;
        }

        applyUserApprovalStatusToHost(host, currentStatus);
        showUserApprovalCancelFeedback(host, message, 'error');
    });

    syncApprovalCountersFromSummary(payload.summary || null);
}

function setUserApprovalCancelBusy(button, busy) {
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    button.disabled = busy;
    button.classList.toggle('is-loading', busy);
}

function cancelUserApprovalRequest(button) {
    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    var approvalId = String(button.getAttribute('data-approval-id') || '').trim();
    var cancelUrl = String(button.getAttribute('data-cancel-url') || '').trim();
    var csrfToken = String(button.getAttribute('data-csrf-token') || '').trim();
    var host = findUserApprovalCancelHost(button);

    if (approvalId === '' || cancelUrl === '' || csrfToken === '') {
        showUserApprovalCancelFeedback(host, 'Solicitare invalida. Reincarca pagina si incearca din nou.', 'error');
        return;
    }

    if (!window.confirm('Sigur dorești să anulezi această solicitare?')) {
        return;
    }

    clearUserApprovalCancelFeedback(host);
    setUserApprovalCancelBusy(button, true);

    var formData = new FormData();
    formData.append('_token', csrfToken);
    formData.append('approval_id', approvalId);

    fetch(cancelUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
        .then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok || !payload.success) {
                    var error = new Error(payload.message || 'Solicitarea nu a putut fi anulata.');
                    error.payload = payload;
                    error.status = response.status;
                    throw error;
                }

                return payload;
            });
        })
        .then(function (payload) {
            var cancelledId = payload.approval_id || approvalId;
            var message = payload.message || 'Solicitarea a fost anulata.';
            window.dispatchEvent(new CustomEvent('inactiveApprovalRequestChanged', {
                detail: {
                    action: 'cancelled',
                    approval_id: cancelledId,
                    message: message,
                    summary: payload.summary || null
                }
            }));
            markApprovalDetailAsCancelled(cancelledId, message);
        })
        .catch(function (error) {
            setUserApprovalCancelBusy(button, false);
            var message = userApprovalCancelMessageFromError(error);
            if (error && error.payload && error.status === 409) {
                syncApprovalRequestStatusFromPayload(approvalId, error.payload, message);
                return;
            }

            showUserApprovalCancelFeedback(host, message, 'error');
        });
}

function initUserApprovalCancellation() {
    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (target === null) {
            return;
        }

        var button = target.closest('[data-user-approval-cancel]');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        event.preventDefault();
        cancelUserApprovalRequest(button);
    });
}

function initApprovalRequestStateSync() {
    window.addEventListener('inactiveApprovalRequestChanged', function (event) {
        var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
        if (detail.action !== 'cancelled') {
            return;
        }

        removeCancelledApprovalFromUi(detail.approval_id);
        syncApprovalCountersFromSummary(detail.summary || null);
    });
}

function initUserApprovalInfoPanel() {
    document.querySelectorAll('.user-approval-info-close').forEach(function (button) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        button.addEventListener('click', function () {
            var panel = button.closest('.user-approval-info-panel');
            if (panel instanceof HTMLElement) {
                panel.hidden = true;
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-date-display-input').forEach(initCustomDateField);

    try {
        window.localStorage.removeItem('fleet.sidebarCollapsed');
    } catch (error) {
    }

    var sidebar = document.querySelector('.sidebar');
    if (sidebar instanceof HTMLElement) {
        sidebar.addEventListener('mouseleave', function () {
            if (fleetSidebarIsPeeking()) {
                closeFleetSidebarPeek();
            }

            if (!document.body.classList.contains(fleetSidebarAutoHideClass)) {
                return;
            }

            closeFleetSidebar();
            refreshFleetSidebarLayout();
        });
    }

    // Reveal on left-edge hover: cand meniul e ascuns, apropierea mouse-ului de
    // marginea din stanga il face sa alunece la vedere; se ascunde cand pleci.
    var sidebarEdge = document.querySelector('[data-sidebar-edge]');
    if (sidebarEdge instanceof HTMLElement) {
        sidebarEdge.addEventListener('mouseenter', function () {
            openFleetSidebarPeek();
        });
    }

    document.addEventListener('mousemove', function (event) {
        if (!fleetSidebarIsPeeking()) {
            if (fleetSidebarIsCollapsed() && event.clientX <= 4) {
                openFleetSidebarPeek();
            }
            return;
        }

        if (event.clientX > fleetSidebarPeekWidth + 24) {
            closeFleetSidebarPeek();
        }
    }, { passive: true });

    syncFleetSidebarToggleState();
    initFleetSidebarSearch();
    initDashboardOperationalCostCard();
    initDashboardApprovalTabs();
    initGlobalApprovalDrawer();
    initApprovalReviewActions();
    initApprovalRequestStateSync();
    initUserApprovalCancellation();
    initUserApprovalInfoPanel();
    initFleetStickyDocumentTables();
    initFleetIdleRefreshLoader();
});

/**
 * Meniul de actiuni (trei puncte) din tabelul de solicitari aprobare.
 *
 * Nu foloseste dropdown-ul Bootstrap pentru ca tabelul are `overflow-x: auto`,
 * iar CSS-ul nu permite overflow-x auto impreuna cu overflow-y visible: meniul
 * pozitionat absolut este taiat de marginea containerului, indiferent de flip.
 * Solutia este mutarea meniului in <body> si pozitionarea lui `fixed` sub buton.
 */
(function initInactiveApprovalMenus() {
    var TOGGLE_SELECTOR = '[data-approval-menu-toggle]';
    var MENU_SELECTOR = '.inactive-approval-menu-list';
    var open = null;

    function closeMenu() {
        if (!open) {
            return;
        }

        var menu = open.menu;
        menu.classList.remove('show');
        menu.removeAttribute('style');
        if (open.placeholder && open.placeholder.parentNode) {
            open.placeholder.parentNode.replaceChild(menu, open.placeholder);
        }
        open.toggle.setAttribute('aria-expanded', 'false');
        open = null;
    }

    function positionMenu(menu, toggle) {
        var rect = toggle.getBoundingClientRect();
        var height = menu.offsetHeight;
        var width = menu.offsetWidth;
        var margin = 8;

        var top = rect.bottom + 4;
        if (top + height > window.innerHeight - margin) {
            var above = rect.top - height - 4;
            top = above >= margin ? above : Math.max(margin, window.innerHeight - height - margin);
        }

        var left = rect.right - width;
        if (left < margin) {
            left = margin;
        }
        if (left + width > window.innerWidth - margin) {
            left = Math.max(margin, window.innerWidth - width - margin);
        }

        menu.style.top = Math.round(top) + 'px';
        menu.style.left = Math.round(left) + 'px';
    }

    function openMenu(toggle) {
        var wrapper = toggle.closest('.inactive-approval-menu');
        var menu = wrapper ? wrapper.querySelector(MENU_SELECTOR) : null;
        if (!menu) {
            return;
        }

        var placeholder = document.createComment('inactive-approval-menu');
        menu.parentNode.replaceChild(placeholder, menu);
        document.body.appendChild(menu);

        menu.style.position = 'fixed';
        menu.style.margin = '0';
        menu.classList.add('show');
        toggle.setAttribute('aria-expanded', 'true');

        open = { menu: menu, toggle: toggle, placeholder: placeholder };
        positionMenu(menu, toggle);
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest(TOGGLE_SELECTOR);
        if (toggle) {
            event.preventDefault();
            var wasOpen = open && open.toggle === toggle;
            closeMenu();
            if (!wasOpen) {
                openMenu(toggle);
            }
            return;
        }

        if (open && !event.target.closest(MENU_SELECTOR)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    // Meniul este ancorat vizual de buton; daca pagina sau tabelul se misca sub el,
    // il inchidem in loc sa ramana suspendat langa alt rand.
    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);
})();
