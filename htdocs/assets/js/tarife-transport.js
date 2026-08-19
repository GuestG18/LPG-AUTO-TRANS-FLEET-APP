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
