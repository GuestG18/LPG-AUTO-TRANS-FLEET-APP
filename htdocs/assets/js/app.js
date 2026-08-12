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
    initDashboardOperationalCostCard();
    initDashboardApprovalTabs();
    initGlobalApprovalDrawer();
    initApprovalRequestStateSync();
    initUserApprovalCancellation();
    initUserApprovalInfoPanel();
    initFleetStickyDocumentTables();
    initFleetIdleRefreshLoader();
});
