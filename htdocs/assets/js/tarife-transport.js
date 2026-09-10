/**
 * Administrare tarife transport — UI behaviour.
 *
 * DESIGN RULE
 *   This file contains NO pricing formula. Every commercial calculation lives in
 *   TransportPricingService (PHP). The script only wires the dialogs, formats
 *   numbers for display, and — where a live preview is needed — asks the backend
 *   through ?page=tarife_transport&action=preview.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var page = document.querySelector('.tt-page');
        if (!page) {
            return;
        }

        // ---------------------------------------------------------------
        // Beneficiary selector reloads the whole page context
        // ---------------------------------------------------------------
        var beneficiaryForm = document.getElementById('tt-beneficiary-form');
        var beneficiarySelect = document.getElementById('tt-beneficiary-select');
        if (beneficiaryForm && beneficiarySelect) {
            beneficiarySelect.addEventListener('change', function () {
                beneficiaryForm.submit();
            });
        }

        // ---------------------------------------------------------------
        // Modal plumbing
        // ---------------------------------------------------------------
        var editModal = document.getElementById('tt-edit-modal');
        var settingsModal = document.getElementById('tt-settings-modal');
        var lastFocused = null;

        function openModal(modal) {
            if (!modal) {
                return;
            }
            lastFocused = document.activeElement;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            var firstField = modal.querySelector('input:not([type="hidden"]), select, textarea');
            if (firstField) {
                window.setTimeout(function () { firstField.focus(); }, 40);
            }
        }

        function closeModal(modal) {
            if (!modal) {
                return;
            }
            modal.hidden = true;
            document.body.style.overflow = '';
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        function closeAllModals() {
            closeModal(editModal);
            closeModal(settingsModal);
        }

        document.addEventListener('click', function (event) {
            var closer = event.target.closest('[data-tt-close]');
            if (closer) {
                event.preventDefault();
                closeAllModals();
                return;
            }
            // Clicking the dimmed backdrop (but not the dialog) closes it.
            if (event.target.classList && event.target.classList.contains('tt-modal-backdrop')) {
                closeAllModals();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAllModals();
            }
        });

        // ---------------------------------------------------------------
        // Edit dialog
        // ---------------------------------------------------------------
        var fComponent = document.getElementById('tt-f-component');
        var fTransport = document.getElementById('tt-f-transport');
        var fRoute = document.getElementById('tt-f-route');
        var fValue = document.getElementById('tt-f-value');
        var fValidFrom = document.getElementById('tt-f-valid-from');
        var fFuelWeight = document.getElementById('tt-f-fuel-weight');
        var fReason = document.getElementById('tt-f-reason');

        var vTransport = document.getElementById('tt-v-transport');
        var vRoute = document.getElementById('tt-v-route');
        var vComponent = document.getElementById('tt-v-component');
        var vUnit = document.getElementById('tt-v-unit');
        var vCurrent = document.getElementById('tt-v-current');
        var vPreview = document.getElementById('tt-v-preview');
        var vRecommended = document.getElementById('tt-v-recommended');
        var rowRoute = document.getElementById('tt-row-route');
        var rowRecommended = document.getElementById('tt-row-recommended');
        var recommendNote = document.getElementById('tt-recommend-note');
        var subtitle = document.getElementById('tt-edit-subtitle');
        var hintUnit = document.getElementById('tt-hint-unit');

        var TRANSPORT_LABELS = {
            primar: 'Primar km',
            primar_tona: 'Primar tone',
            distributie: 'Distribuție',
            primar_distributie: 'P+D (Primar + Distribuție)',
            compresor: 'Compresor'
        };

        var UNIT_LABELS = {
            'lei/km': 'lei / km',
            'lei/tona': 'lei / tonă',
            'lei/ora': 'lei / oră',
            'lei/cursa': 'lei / cursă'
        };

        function parseNumber(raw) {
            if (raw === null || raw === undefined) {
                return null;
            }
            var text = String(raw).trim().replace(/\s+/g, '').replace(',', '.');
            if (text === '' || isNaN(Number(text))) {
                return null;
            }
            return Number(text);
        }

        /** Romanian display formatting — presentation only. */
        function formatRo(value, decimals) {
            if (value === null || value === undefined || isNaN(value)) {
                return '—';
            }
            return Number(value).toLocaleString('ro-RO', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        }

        function unitLabel(unit) {
            return UNIT_LABELS[unit] || unit || '';
        }

        function refreshPreview(unit) {
            var parsed = parseNumber(fValue ? fValue.value : '');
            if (!vPreview) {
                return;
            }
            vPreview.textContent = parsed === null
                ? '—'
                : formatRo(parsed, 2) + ' ' + unitLabel(unit);
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-tt-edit]');
            if (!trigger || !editModal) {
                return;
            }
            event.preventDefault();

            var component = trigger.getAttribute('data-component') || '';
            var transport = trigger.getAttribute('data-transport') || 'primar';
            var routeId = trigger.getAttribute('data-route-id') || '0';
            var label = trigger.getAttribute('data-label') || component;
            var unit = trigger.getAttribute('data-unit') || '';
            var current = parseNumber(trigger.getAttribute('data-current'));
            var recommended = parseNumber(trigger.getAttribute('data-recommended'));
            var context = trigger.getAttribute('data-context') || '';

            if (fComponent) { fComponent.value = component; }
            if (fTransport) { fTransport.value = transport; }
            if (fRoute) { fRoute.value = routeId; }
            if (fReason) { fReason.value = ''; }
            if (fFuelWeight) { fFuelWeight.value = ''; }

            if (vTransport) { vTransport.textContent = TRANSPORT_LABELS[transport] || transport; }
            if (vComponent) { vComponent.textContent = label; }
            if (vUnit) { vUnit.textContent = unitLabel(unit); }
            if (vCurrent) {
                vCurrent.textContent = current === null ? '—' : formatRo(current, 2) + ' ' + unitLabel(unit);
            }
            if (subtitle) { subtitle.textContent = context || label; }
            if (hintUnit) {
                hintUnit.textContent = 'Unitatea acestei componente: ' + unitLabel(unit)
                    + '. Se acceptă virgulă sau punct ca separator zecimal.';
            }

            var hasRoute = routeId && routeId !== '0';
            if (rowRoute) { rowRoute.hidden = !hasRoute; }
            if (vRoute) { vRoute.textContent = hasRoute ? context : '—'; }

            // A numeric recommendation exists only when a fuel weight is configured.
            if (rowRecommended && vRecommended) {
                if (recommended !== null) {
                    rowRecommended.hidden = false;
                    vRecommended.innerHTML = formatRo(recommended, 4) + ' ' + unitLabel(unit)
                        + ' <button type="button" class="tt-btn tt-btn-sm" style="height:22px;padding:0 7px;margin-left:6px;" data-tt-apply-recommended="'
                        + recommended + '">Preia</button>';
                    if (recommendNote) {
                        recommendNote.textContent = 'Valoarea recomandată este orientativă. Trebuie confirmată manual.';
                    }
                } else {
                    rowRecommended.hidden = true;
                    if (recommendNote) {
                        recommendNote.textContent = 'Nu există o sensibilitate la combustibil configurată pentru '
                            + 'această componentă, deci nu se propune o valoare numerică.';
                    }
                }
            }

            // The new value is intentionally left EMPTY: never auto-filled with
            // the recommendation, never auto-submitted.
            if (fValue) {
                fValue.value = '';
                fValue.setAttribute('data-unit', unit);
            }
            refreshPreview(unit);

            openModal(editModal);
        });

        // Apply a recommendation only on an explicit click.
        document.addEventListener('click', function (event) {
            var apply = event.target.closest('[data-tt-apply-recommended]');
            if (!apply || !fValue) {
                return;
            }
            event.preventDefault();
            var value = parseNumber(apply.getAttribute('data-tt-apply-recommended'));
            if (value !== null) {
                fValue.value = String(value).replace('.', ',');
                refreshPreview(fValue.getAttribute('data-unit'));
                fValue.focus();
            }
        });

        if (fValue) {
            fValue.addEventListener('input', function () {
                refreshPreview(fValue.getAttribute('data-unit'));
            });
        }

        // Effective-date shortcuts
        document.addEventListener('click', function (event) {
            var shortcut = event.target.closest('[data-tt-date]');
            if (!shortcut || !fValidFrom) {
                return;
            }
            event.preventDefault();
            fValidFrom.value = shortcut.getAttribute('data-tt-date');
        });

        // ---------------------------------------------------------------
        // Settings dialog
        // ---------------------------------------------------------------
        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-tt-open-settings]')) {
                event.preventDefault();
                openModal(settingsModal);
            }
        });

        // ---------------------------------------------------------------
        // "+ Tarif nou" — opens the dialog on the current tab's main component
        // ---------------------------------------------------------------
        var newTariffBtn = document.querySelector('[data-tt-new-tariff]');
        if (newTariffBtn) {
            newTariffBtn.addEventListener('click', function (event) {
                event.preventDefault();
                var firstEdit = document.querySelector('.tt-main [data-tt-edit]');
                if (firstEdit) {
                    firstEdit.click();
                    return;
                }
                window.alert('Nu există încă o componentă tarifară configurabilă pentru acest tab.');
            });
        }

        // ---------------------------------------------------------------
        // Client-side guard rails (the server re-validates everything)
        // ---------------------------------------------------------------
        var editForm = document.getElementById('tt-edit-form');
        if (editForm) {
            editForm.addEventListener('submit', function (event) {
                var parsed = parseNumber(fValue ? fValue.value : '');
                if (parsed === null || parsed < 0) {
                    event.preventDefault();
                    window.alert('Introdu o valoare numerică validă (≥ 0) pentru tarif.');
                    if (fValue) { fValue.focus(); }
                    return;
                }
                if (fValidFrom && !fValidFrom.value) {
                    event.preventDefault();
                    window.alert('Alege data de la care intră în vigoare tariful.');
                    fValidFrom.focus();
                }
            });
        }
    });
})();

/* ------------------------------------------------------------------
   Vehicule eligibile — popover "N vehicule" pe tabelele de rute.
   Comportament identic cu Configurare transport: toggle, pozitionare
   in viewport, cautare, inchidere pe Escape / click in afara,
   repozitionare la scroll si resize.
   ------------------------------------------------------------------ */
(function () {
    'use strict';

    var activeState = null;

    var closestElement = function (target, selector) {
        return target instanceof Element ? target.closest(selector) : null;
    };

    var positionPopover = function (triggerEl, layerEl) {
        var margin = 12;
        var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        var viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        var width = Math.min(Math.max(220, 304), Math.max(220, viewportWidth - margin * 2));
        var maxHeight = Math.min(460, Math.max(180, viewportHeight - margin * 2));

        layerEl.style.width = width + 'px';
        layerEl.style.maxHeight = maxHeight + 'px';

        var triggerRect = triggerEl.getBoundingClientRect();
        var layerRect = layerEl.getBoundingClientRect();
        var layerHeight = Math.min(layerRect.height || layerEl.scrollHeight || maxHeight, maxHeight);
        var left = triggerRect.left;

        if (left + width > viewportWidth - margin) {
            left = triggerRect.right - width;
        }
        left = Math.max(margin, Math.min(left, viewportWidth - margin - width));

        var top = triggerRect.bottom + 8;
        var topIfAbove = triggerRect.top - layerHeight - 8;
        if (top + layerHeight > viewportHeight - margin && topIfAbove >= margin) {
            top = topIfAbove;
        } else if (top + layerHeight > viewportHeight - margin) {
            top = Math.max(margin, viewportHeight - margin - layerHeight);
        }

        layerEl.style.left = Math.round(left) + 'px';
        layerEl.style.top = Math.round(top) + 'px';
    };

    var resetSearch = function (popoverEl) {
        var searchInput = popoverEl.querySelector('[data-dispatcher-vehicle-search]');
        if (searchInput instanceof HTMLInputElement) {
            searchInput.value = '';
        }
        popoverEl.querySelectorAll('[data-dispatcher-vehicle-item]').forEach(function (itemEl) {
            itemEl.hidden = false;
        });
        var emptyEl = popoverEl.querySelector('[data-dispatcher-vehicle-empty]');
        if (emptyEl instanceof HTMLElement) {
            emptyEl.hidden = true;
        }
    };

    var filterPopover = function (popoverEl) {
        var searchInput = popoverEl.querySelector('[data-dispatcher-vehicle-search]');
        var query = searchInput instanceof HTMLInputElement
            ? searchInput.value.trim().toLocaleLowerCase('ro-RO')
            : '';
        var visibleCount = 0;

        popoverEl.querySelectorAll('[data-dispatcher-vehicle-item]').forEach(function (itemEl) {
            var searchText = String(itemEl.dataset.vehicleSearch || itemEl.textContent || '').toLocaleLowerCase('ro-RO');
            var isVisible = query === '' || searchText.indexOf(query) !== -1;
            itemEl.hidden = !isVisible;
            if (isVisible) {
                visibleCount += 1;
            }
        });

        var emptyEl = popoverEl.querySelector('[data-dispatcher-vehicle-empty]');
        if (emptyEl instanceof HTMLElement) {
            emptyEl.hidden = visibleCount > 0;
        }
    };

    var closePopover = function (restoreFocus) {
        if (activeState === null) {
            return;
        }
        var previous = activeState;
        activeState = null;
        previous.button.setAttribute('aria-expanded', 'false');
        previous.button.classList.remove('is-open');
        var iconEl = previous.button.querySelector('i');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-up');
            iconEl.classList.add('bi-chevron-down');
        }
        resetSearch(previous.popover);
        previous.popover.hidden = true;
        previous.popover.style.left = '';
        previous.popover.style.top = '';
        previous.popover.style.visibility = '';
        if (restoreFocus) {
            previous.button.focus({ preventScroll: true });
        }
    };

    var openPopover = function (buttonEl) {
        var popoverId = String(buttonEl.dataset.popoverId || '');
        var popoverEl = popoverId !== '' ? document.getElementById(popoverId) : null;
        if (!(popoverEl instanceof HTMLElement)) {
            return;
        }

        closePopover(false);

        activeState = { button: buttonEl, popover: popoverEl };
        buttonEl.setAttribute('aria-expanded', 'true');
        buttonEl.classList.add('is-open');
        var iconEl = buttonEl.querySelector('i');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-down');
            iconEl.classList.add('bi-chevron-up');
        }

        resetSearch(popoverEl);
        popoverEl.style.visibility = 'hidden';
        popoverEl.hidden = false;
        positionPopover(buttonEl, popoverEl);
        popoverEl.style.visibility = '';

        var searchInput = popoverEl.querySelector('[data-dispatcher-vehicle-search]');
        if (searchInput instanceof HTMLInputElement) {
            searchInput.focus({ preventScroll: true });
        }
    };

    document.addEventListener('click', function (event) {
        var vehicleButton = closestElement(event.target, '[data-dispatcher-vehicle-toggle]');
        if (vehicleButton instanceof HTMLButtonElement) {
            event.preventDefault();
            if (activeState !== null && activeState.button === vehicleButton) {
                closePopover(false);
                return;
            }
            openPopover(vehicleButton);
            return;
        }

        if (closestElement(event.target, '[data-dispatcher-vehicle-popover]') === null) {
            closePopover(false);
        }
    });

    document.addEventListener('input', function (event) {
        var searchInput = closestElement(event.target, '[data-dispatcher-vehicle-search]');
        if (!(searchInput instanceof HTMLInputElement)) {
            return;
        }
        var popoverEl = searchInput.closest('[data-dispatcher-vehicle-popover]');
        if (popoverEl instanceof HTMLElement) {
            filterPopover(popoverEl);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && activeState !== null) {
            event.preventDefault();
            closePopover(true);
            return;
        }
        var vehicleButton = closestElement(event.target, '[data-dispatcher-vehicle-toggle]');
        if (vehicleButton instanceof HTMLButtonElement && event.key === 'ArrowDown') {
            event.preventDefault();
            openPopover(vehicleButton);
        }
    });

    var reposition = function () {
        if (activeState !== null) {
            positionPopover(activeState.button, activeState.popover);
        }
    };
    document.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);
})();

/* ------------------------------------------------------------------
   Editare multipla de tarife ("Editeaza toate" / tarifele unui rand).
   Deschide #tt-bulk-modal, populat din data-components (JSON) de pe
   butonul [data-tt-bulk-edit]. Campurile goale raman neschimbate.
   ------------------------------------------------------------------ */
(function () {
    'use strict';

    var bulkModal = document.getElementById('tt-bulk-modal');
    if (!bulkModal) {
        return;
    }

    var rowsBody = document.getElementById('tt-bulk-rows');
    var bfTransport = document.getElementById('tt-bf-transport');
    var bfRoute = document.getElementById('tt-bf-route');
    var bfValidFrom = document.getElementById('tt-bf-valid-from');
    var bfSubtitle = document.getElementById('tt-bulk-subtitle');
    var bulkForm = document.getElementById('tt-bulk-form');
    var lastFocused = null;

    var TRANSPORT_LABELS = {
        primar: 'Primar km',
        primar_tona: 'Primar tone',
        distributie: 'Distribuție',
        primar_distributie: 'P+D (Primar + Distribuție)',
        compresor: 'Compresor'
    };

    var parseNumber = function (raw) {
        if (raw === null || raw === undefined) {
            return null;
        }
        var text = String(raw).trim().replace(/\s+/g, '').replace(',', '.');
        if (text === '' || isNaN(Number(text))) {
            return null;
        }
        return Number(text);
    };

    var formatRo = function (value) {
        if (value === null || value === undefined || isNaN(value)) {
            return '—';
        }
        return Number(value).toLocaleString('ro-RO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    var openBulkModal = function () {
        lastFocused = document.activeElement;
        bulkModal.hidden = false;
        document.body.style.overflow = 'hidden';
        var firstField = rowsBody ? rowsBody.querySelector('input[name="bulk_value[]"]') : null;
        if (firstField) {
            window.setTimeout(function () { firstField.focus(); }, 40);
        }
    };

    var closeBulkModal = function () {
        if (bulkModal.hidden) {
            return;
        }
        bulkModal.hidden = true;
        document.body.style.overflow = '';
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    };

    var buildRow = function (item) {
        var tr = document.createElement('tr');

        var tdLabel = document.createElement('td');
        tdLabel.textContent = String(item.label || item.component || '');
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'bulk_component[]';
        hidden.value = String(item.component || '');
        tdLabel.appendChild(hidden);
        tr.appendChild(tdLabel);

        var tdUnit = document.createElement('td');
        tdUnit.className = 'tt-dash';
        tdUnit.textContent = String(item.unit || '');
        tr.appendChild(tdUnit);

        var tdCurrent = document.createElement('td');
        tdCurrent.className = 'tt-num';
        var strong = document.createElement('strong');
        strong.textContent = formatRo(parseNumber(item.current));
        tdCurrent.appendChild(strong);
        tr.appendChild(tdCurrent);

        var tdInput = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'text';
        input.inputMode = 'decimal';
        input.name = 'bulk_value[]';
        input.autocomplete = 'off';
        input.placeholder = 'neschimbat';
        input.style.width = '110px';
        tdInput.appendChild(input);
        tr.appendChild(tdInput);

        return tr;
    };

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-tt-bulk-edit]');
        if (trigger) {
            event.preventDefault();

            var components = [];
            try {
                components = JSON.parse(trigger.getAttribute('data-components') || '[]');
            } catch (err) {
                components = [];
            }
            if (!Array.isArray(components) || components.length === 0) {
                return;
            }

            if (rowsBody) {
                rowsBody.innerHTML = '';
                components.forEach(function (item) {
                    rowsBody.appendChild(buildRow(item));
                });
            }

            var transport = String(trigger.getAttribute('data-transport') || '');
            if (bfTransport) { bfTransport.value = transport; }
            if (bfRoute) { bfRoute.value = String(trigger.getAttribute('data-route-id') || '0'); }
            if (bfSubtitle) {
                var context = String(trigger.getAttribute('data-context') || '');
                var transportLabel = TRANSPORT_LABELS[transport] || transport;
                bfSubtitle.textContent = context !== '' ? transportLabel + ' · ' + context : transportLabel;
            }

            openBulkModal();
            return;
        }

        if (!bulkModal.hidden) {
            if (event.target.closest('[data-tt-close]') && event.target.closest('#tt-bulk-modal')) {
                event.preventDefault();
                closeBulkModal();
                return;
            }
            if (event.target === bulkModal) {
                closeBulkModal();
                return;
            }
        }

        var dateShortcut = event.target.closest('[data-tt-bulk-date]');
        if (dateShortcut && bfValidFrom) {
            event.preventDefault();
            bfValidFrom.value = dateShortcut.getAttribute('data-tt-bulk-date');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !bulkModal.hidden) {
            closeBulkModal();
        }
    });

    if (bulkForm) {
        bulkForm.addEventListener('submit', function (event) {
            var inputs = rowsBody ? rowsBody.querySelectorAll('input[name="bulk_value[]"]') : [];
            var filled = 0;
            var invalid = null;

            Array.prototype.forEach.call(inputs, function (input) {
                var raw = String(input.value || '').trim();
                if (raw === '') {
                    return;
                }
                var parsed = parseNumber(raw);
                if (parsed === null || parsed < 0) {
                    invalid = invalid || input;
                    return;
                }
                filled += 1;
            });

            if (invalid) {
                event.preventDefault();
                window.alert('Una dintre valorile introduse nu este un număr valid (≥ 0).');
                invalid.focus();
                return;
            }
            if (filled === 0) {
                event.preventDefault();
                window.alert('Completează cel puțin o valoare — câmpurile goale rămân neschimbate.');
                return;
            }
            if (bfValidFrom && !bfValidFrom.value) {
                event.preventDefault();
                window.alert('Alege data de la care intră în vigoare tarifele.');
                bfValidFrom.focus();
            }
        });
    }
})();
