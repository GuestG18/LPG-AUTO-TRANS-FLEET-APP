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
    var isAdmin = modalEl.getAttribute('data-diurna-mode') === 'admin';
    var activeButton = null;

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

    function openFor(button) {
        activeButton = button;
        var tripId = button.getAttribute('data-trip-id') || '';
        var current = button.getAttribute('data-current') || '0';
        var computed = button.getAttribute('data-computed') || current;
        var pending = button.getAttribute('data-pending') || '';

        setText('[data-diurna-trip-label]', '#' + tripId);
        setText('[data-diurna-driver]', button.getAttribute('data-driver') || '-');
        setText('[data-diurna-computed]', computed);
        setText('[data-diurna-current]', current + (current !== computed ? ' (modificat)' : ''));

        var warning = q('[data-diurna-pending-warning]');
        if (pending !== '') {
            var parts = pending.split('|');
            setText('[data-diurna-pending-text]', 'Exista deja o cerere in asteptare: ' + parts[0] + ' diurne, de la ' + (parts[1] || '-') + '.'
                + (isAdmin ? ' Salvarea o va inlocui.' : ' Nu poti trimite alta pana la decizia administratorului.'));
            warning.hidden = false;
        } else {
            warning.hidden = true;
        }

        valueInput.value = current;
        reasonInput.value = '';
        showError('');
        submitButton.disabled = pending !== '' && !isAdmin;
        showStep(false);
        openModal();
        window.setTimeout(function () {
            valueInput.focus();
            valueInput.select();
        }, 200);
    }

    function updateCell(button, payload) {
        var cell = button.closest('.cell-content');
        if (!(cell instanceof HTMLElement)) {
            return;
        }

        cell.querySelectorAll('[data-diurna-pending]').forEach(function (el) {
            el.remove();
        });

        if (payload.status === 'approved') {
            var value = String(payload.solicitat);
            var text = cell.querySelector('.dispatcher-cell-text');
            if (text) {
                // Pastreaza marcajul "(fara diurna)" pentru soferii fara diurna.
                text.textContent = text.textContent.replace(/^\s*\d+/, value);
                cell.setAttribute('data-cell-value', text.textContent.trim());
            }
            button.setAttribute('data-current', value);
            button.setAttribute('data-pending', '');
            var adjusted = cell.querySelector('.diurna-adjusted-badge');
            if (String(payload.solicitat) !== String(payload.calculat)) {
                if (!adjusted) {
                    adjusted = document.createElement('span');
                    adjusted.className = 'diurna-adjusted-badge';
                    adjusted.setAttribute('aria-hidden', 'true');
                    adjusted.innerHTML = '<i class="bi bi-pencil-fill"></i>';
                    cell.insertBefore(adjusted, button);
                }
            } else if (adjusted) {
                adjusted.remove();
            }
            cell.title = 'Modificat manual: regula calculeaza ' + payload.calculat + ', aprobat ' + value + '.';
            return;
        }

        var badge = document.createElement('span');
        badge.className = 'diurna-pending-badge';
        badge.setAttribute('data-diurna-pending', '');
        badge.title = 'Cerere in asteptare: ' + payload.solicitat + ' diurne';
        badge.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i>';
        badge.appendChild(document.createTextNode(String(payload.solicitat)));
        cell.insertBefore(badge, button);
        button.setAttribute('data-pending', payload.solicitat + '|eu');
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
        var button = event.target instanceof Element ? event.target.closest('[data-diurna-edit]') : null;
        if (!(button instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        openFor(button);
    });

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
        if (raw === String(parseInt(activeButton.getAttribute('data-current') || '', 10))) {
            showError('Valoarea este aceeasi cu cea actuala.');
            return;
        }

        var body = new FormData();
        body.append('_token', modalEl.getAttribute('data-diurna-csrf') || '');
        body.append('trip_id', activeButton.getAttribute('data-trip-id') || '');
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
