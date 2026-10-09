/**
 * Coloana "Diurna" din Desfasurator curse: modificarea numarului de diurne.
 * Operatorul trimite o cerere in panoul de aprobari (tab-ul "Diurne") si valoarea
 * ramane neschimbata pana la decizia adminului; adminul o aplica direct.
 * Server: dispecer_curse&action=request_diurna_change.
 */
(function () {
    'use strict';

    var modalEl = document.querySelector('[data-diurna-modal]');
    if (!(modalEl instanceof HTMLElement)) {
        return;
    }

    var form = modalEl.querySelector('[data-diurna-form]');
    var valueInput = modalEl.querySelector('[data-diurna-value]');
    var reasonInput = modalEl.querySelector('[data-diurna-reason]');
    var errorBox = modalEl.querySelector('[data-diurna-error]');
    var submitButton = modalEl.querySelector('[data-diurna-submit]');
    var driverSelect = modalEl.querySelector('[data-diurna-driver-select]');
    var isAdmin = modalEl.getAttribute('data-diurna-mode') === 'admin';
    var activeButton = null;
    // Soferii cursei: {id, name, computed, current, pending}. id 0 = toata cursa (un singur sofer).
    var activeDrivers = [];
    var activeDriver = null;

    function q(selector) {
        return modalEl.querySelector(selector);
    }

    function setText(selector, text) {
        var el = q(selector);
        if (el) {
            el.textContent = text;
        }
    }

    function showStep(done) {
        q('[data-diurna-edit-step]').hidden = done;
        q('[data-diurna-edit-footer]').hidden = done;
        q('[data-diurna-done-step]').hidden = !done;
        q('[data-diurna-done-footer]').hidden = !done;
    }

    function showError(message) {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    }

    // bootstrap se incarca in footer, dupa scripturile paginii: il cautam la click.
    function openModal() {
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            return;
        }
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
    }

    function readDrivers(button) {
        var list = [];
        try {
            list = JSON.parse(button.getAttribute('data-drivers') || '[]');
        } catch (error) {
            list = [];
        }
        if (Array.isArray(list) && list.length > 0) {
            return list.map(function (driver) {
                return {
                    id: parseInt(driver.id, 10) || 0,
                    name: String(driver.name || '-'),
                    computed: String(driver.computed),
                    current: String(driver.current),
                    pending: String(driver.pending || '')
                };
            });
        }
        // Un singur sofer: cererea ramane pe toata cursa, ca inainte.
        var current = button.getAttribute('data-current') || '0';
        return [{
            id: 0,
            name: button.getAttribute('data-driver') || '-',
            computed: button.getAttribute('data-computed') || current,
            current: current,
            pending: button.getAttribute('data-pending') || ''
        }];
    }

    // Valorile din fereastra urmeaza soferul ales in lista "Soferi".
    function showDriver(driver) {
        activeDriver = driver;
        setText('[data-diurna-computed]', driver.computed);
        setText('[data-diurna-current]', driver.current + (driver.current !== driver.computed ? ' (modificat)' : ''));

        var warning = q('[data-diurna-pending-warning]');
        if (driver.pending !== '') {
            var parts = driver.pending.split('|');
            setText('[data-diurna-pending-text]', 'Exista deja o cerere in asteptare: ' + parts[0] + ' diurne, de la ' + (parts[1] || '-') + '.'
                + (isAdmin ? ' Salvarea o va inlocui.' : ' Nu poti trimite alta pana la decizia administratorului.'));
            warning.hidden = false;
        } else {
            warning.hidden = true;
        }

        valueInput.value = driver.current;
        showError('');
        submitButton.disabled = driver.pending !== '' && !isAdmin;
    }

    driverSelect.addEventListener('change', function () {
        var driver = activeDrivers[parseInt(driverSelect.value, 10)] || activeDrivers[0];
        if (driver) {
            showDriver(driver);
        }
    });

    // preselectKey = id-ul soferului ales din dropdown-ul Diurna (0 = toata cursa).
    function openFor(button, preselectKey) {
        activeButton = button;
        var tripId = button.getAttribute('data-trip-id') || '';
        activeDrivers = readDrivers(button);

        setText('[data-diurna-trip-label]', '#' + tripId);
        driverSelect.innerHTML = '';
        activeDrivers.forEach(function (driver, index) {
            var option = document.createElement('option');
            option.value = String(index);
            option.textContent = driver.name;
            driverSelect.appendChild(option);
        });
        driverSelect.disabled = activeDrivers.length < 2;

        var preselectIndex = 0;
        if (preselectKey !== undefined && preselectKey !== null) {
            activeDrivers.forEach(function (driver, index) {
                if (driver.id === preselectKey) {
                    preselectIndex = index;
                }
            });
        }
        driverSelect.value = String(preselectIndex);

        reasonInput.value = '';
        showDriver(activeDrivers[preselectIndex]);
        showStep(false);
        openModal();
        window.setTimeout(function () {
            valueInput.focus();
            valueInput.select();
        }, 200);
    }

    // Dupa salvare: datele ferestrei (butonul-sursa ascuns din celula) primesc noua
    // valoare - aprobata = valoare efectiva, in asteptare = doar semnalata.
    function updateSourceData(button, payload) {
        if (activeDriver && activeDriver.id > 0) {
            var drivers = readDrivers(button);
            drivers.forEach(function (driver) {
                if (driver.id === activeDriver.id) {
                    if (payload.status === 'approved') {
                        driver.current = String(payload.solicitat);
                        driver.pending = '';
                    } else {
                        driver.pending = payload.solicitat + '|eu';
                    }
                }
            });
            button.setAttribute('data-drivers', JSON.stringify(drivers));
            return;
        }
        if (payload.status === 'approved') {
            button.setAttribute('data-current', String(payload.solicitat));
            button.setAttribute('data-pending', '');
        } else {
            button.setAttribute('data-pending', payload.solicitat + '|eu');
        }
    }

    // Dropdown-ul Diurna din celula: valoarea fiecarui sofer, totalul (suma valorilor
    // efective) si semnalul de cerere in asteptare, refacute din datele ferestrei.
    function refreshDiurnaDropdown(button) {
        var cell = button.closest('.cell-content');
        var dropdown = cell ? cell.querySelector('[data-diurna-dropdown]') : null;
        if (!(dropdown instanceof HTMLElement)) {
            return;
        }

        var drivers = readDrivers(button);
        var byId = {};
        var total = 0;
        var anyPending = false;
        drivers.forEach(function (driver) {
            byId[driver.id] = driver;
            total += parseInt(driver.current, 10) || 0;
            anyPending = anyPending || driver.pending !== '';
        });

        var items = [];
        try {
            items = JSON.parse(dropdown.getAttribute('data-summary-items') || '[]');
        } catch (error) {
            items = [];
        }
        items.forEach(function (item) {
            var driver = byId[parseInt(item.k, 10) || 0];
            if (!driver) {
                return;
            }
            item.v = parseInt(driver.current, 10) || 0;
            if (driver.current !== driver.computed) {
                item.a = 'Modificat manual: regula calculeaza ' + driver.computed + '.';
            } else {
                delete item.a;
            }
            if (driver.pending !== '') {
                var parts = driver.pending.split('|');
                item.p = 'Cerere in asteptare: ' + parts[0] + ' diurne (' + (parts[1] || '-') + ').';
            } else {
                delete item.p;
            }
        });
        dropdown.setAttribute('data-summary-items', JSON.stringify(items));

        var totalEl = dropdown.querySelector('[data-diurna-total]');
        if (totalEl) {
            totalEl.textContent = String(total);
        }
        var pendingEl = dropdown.querySelector('[data-diurna-pending]');
        if (anyPending && !pendingEl) {
            pendingEl = document.createElement('span');
            pendingEl.className = 'bi bi-hourglass-split dispatcher-diurna-pending';
            pendingEl.setAttribute('data-diurna-pending', '');
            pendingEl.setAttribute('aria-hidden', 'true');
            dropdown.insertBefore(pendingEl, totalEl ? totalEl.nextSibling : null);
        } else if (!anyPending && pendingEl) {
            pendingEl.remove();
        }

        // Pastreaza marcajul "(fara diurna)" folosit la sortare / filtrare.
        var cellValue = String(cell.getAttribute('data-cell-value') || '');
        cell.setAttribute('data-cell-value', cellValue.replace(/^\s*\d+/, String(total)) || String(total));

        // Popover-ul se reconstruieste din noile date la urmatoarea deschidere.
        var popover = document.getElementById(dropdown.getAttribute('data-popover-id') || '');
        if (popover) {
            if (window.bootstrap && window.bootstrap.Tooltip) {
                popover.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (tipEl) {
                    var tip = window.bootstrap.Tooltip.getInstance(tipEl);
                    if (tip) {
                        tip.dispose();
                    }
                });
            }
            popover.remove();
        }
    }

    function updateCell(button, payload) {
        updateSourceData(button, payload);
        refreshDiurnaDropdown(button);
    }

    // Cererea noua apare in "Solicitarile mele" la urmatoarea incarcare; numaratorii cresc acum.
    function bumpUserPendingCounters() {
        document.querySelectorAll('[data-approval-tab-count="pending"], [data-approval-total-count], [data-approval-total-badge]').forEach(function (el) {
            var value = parseInt(el.textContent, 10);
            el.textContent = String((Number.isFinite(value) ? value : 0) + 1);
            el.hidden = false;
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;

        // Creionul unui sofer din dropdown-ul Diurna: fereastra existenta, cu soferul
        // preselectat dupa id. Clicul nu ajunge la rand / fisa cursei.
        var driverEdit = target ? target.closest('[data-diurna-edit-driver]') : null;
        if (driverEdit instanceof HTMLElement) {
            event.preventDefault();
            event.stopPropagation();
            var tripId = driverEdit.getAttribute('data-diurna-trip') || '';
            var source = document.querySelector('[data-diurna-edit][data-trip-id="' + CSS.escape(tripId) + '"]');
            if (!(source instanceof HTMLElement)) {
                return;
            }
            if (typeof window.dispatcherCloseSummaryPopover === 'function') {
                window.dispatcherCloseSummaryPopover();
            }
            openFor(source, parseInt(driverEdit.getAttribute('data-diurna-edit-driver') || '0', 10) || 0);
            return;
        }

        var button = target ? target.closest('[data-diurna-edit]') : null;
        if (!(button instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        openFor(button);
    }, true);

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!(activeButton instanceof HTMLElement)) {
            return;
        }

        var raw = String(valueInput.value || '').trim();
        if (!/^\d{1,2}$/.test(raw) || parseInt(raw, 10) > 60) {
            showError('Introdu un numar intreg de diurne intre 0 si 60.');
            return;
        }
        if (!activeDriver || raw === String(parseInt(activeDriver.current, 10))) {
            showError('Valoarea este aceeasi cu cea actuala.');
            return;
        }

        var body = new FormData();
        body.append('_token', modalEl.getAttribute('data-diurna-csrf') || '');
        body.append('trip_id', activeButton.getAttribute('data-trip-id') || '');
        if (activeDriver.id > 0) {
            body.append('driver_id', String(activeDriver.id));
        }
        body.append('diurne', raw);
        body.append('motiv', String(reasonInput.value || '').trim());

        showError('');
        submitButton.disabled = true;

        fetch(modalEl.getAttribute('data-diurna-url') || '', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (payload) {
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'Cererea nu a putut fi trimisa.');
                    }
                    return payload;
                });
            })
            .then(function (payload) {
                updateCell(activeButton, payload);
                var approved = payload.status === 'approved';
                var icon = q('[data-diurna-done-icon]');
                icon.className = 'bi ' + (approved ? 'bi-check-circle-fill text-success' : 'bi-hourglass-split text-warning');
                setText('[data-diurna-done-title]', approved ? 'Diurnele au fost modificate' : 'Cerere trimisa spre aprobare');
                setText('[data-diurna-done-text]', payload.message || '');
                showStep(true);
                if (!approved) {
                    bumpUserPendingCounters();
                }
            })
            .catch(function (error) {
                showError(error && error.message ? error.message : 'Cererea nu a putut fi trimisa.');
            })
            .then(function () {
                submitButton.disabled = false;
            });
    });
})();
