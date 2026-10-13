/**
 * Aprobari live (toate paginile, utilizator autentificat).
 *
 * - Administratorul: cererile noi apar intr-un popup cu Aproba / Respinge, la cateva
 *   secunde dupa ce operatorul le trimite.
 * - Operatorul: afla imediat decizia (notificare), iar pe Dispecer curse vede lista
 *   tab-ul "In asteptare aprobare" din Desfasurator curse actualizat live.
 *
 * Interogari scurte la PERIOD_VISIBLE_MS cat timp pagina e vizibila (serverul de pe VPS
 * serveste o cerere odata, deci nu tinem conexiuni deschise). Datele vin din
 * page=inactive_approvals&action=live.
 */
(function () {
    'use strict';

    var config = document.querySelector('[data-approval-live]');
    if (!(config instanceof HTMLElement)) {
        return;
    }

    var PERIOD_VISIBLE_MS = 5000;
    var PERIOD_HIDDEN_MS = 30000;

    var liveUrl = config.getAttribute('data-live-url') || '';
    var approveUrl = config.getAttribute('data-approve-url') || '';
    var rejectUrl = config.getAttribute('data-reject-url') || '';
    var dispatcherUrl = config.getAttribute('data-dispatcher-url') || '';
    var csrfToken = config.getAttribute('data-csrf') || '';
    var currentUserId = parseInt(config.getAttribute('data-user-id') || '0', 10) || 0;
    if (liveUrl === '' || currentUserId <= 0) {
        return;
    }

    var pollTimer = null;
    var polling = false;
    var stopped = false;
    var reviewQueue = [];
    var reviewCurrent = null;
    var reviewBusy = false;
    var reviewModal = null;
    var reviewModalEl = null;
    var baseTitle = document.title;
    var isDispatcherPage = /[?&]page=dispecer_curse(&|$)/.test(window.location.search);

    // ---- stocare locala (se poate sa lipseasca: mod privat, date sterse) --------------

    function storageGet(storage, key) {
        try {
            return JSON.parse(storage.getItem(key) || 'null');
        } catch (error) {
            return null;
        }
    }

    function storageSet(storage, key, value) {
        try {
            storage.setItem(key, JSON.stringify(value));
        } catch (error) {
        }
    }

    // "Mai tarziu" pe o cerere: nu mai apare in popup in aceasta sesiune (ramane in panou).
    var postponedKey = 'approvalLivePostponed:' + currentUserId;
    // Deciziile notificate deja (in toate taburile), ca sa nu se repete la fiecare pagina.
    var notifiedKey = 'approvalLiveNotified:' + currentUserId;

    function postponedIds() {
        var value = storageGet(window.sessionStorage, postponedKey);
        return value && typeof value === 'object' ? value : {};
    }

    function postpone(id) {
        var ids = postponedIds();
        ids[String(id)] = 1;
        storageSet(window.sessionStorage, postponedKey, ids);
    }

    function notifiedIds() {
        var value = storageGet(window.localStorage, notifiedKey);
        return value && typeof value === 'object' ? value : {};
    }

    function markNotified(id) {
        var ids = notifiedIds();
        ids[String(id)] = Date.now();
        // Pastram doar ultimele 200 de id-uri.
        var keys = Object.keys(ids);
        if (keys.length > 200) {
            keys.sort(function (a, b) { return ids[a] - ids[b]; }).slice(0, keys.length - 200).forEach(function (key) {
                delete ids[key];
            });
        }
        storageSet(window.localStorage, notifiedKey, ids);
    }

    // ---- utilitare DOM ------------------------------------------------------------------

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function injectStyles() {
        if (document.getElementById('approval-live-styles')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'approval-live-styles';
        style.textContent = [
            '.approval-live-toasts{position:fixed;top:16px;right:16px;z-index:1090;display:flex;flex-direction:column;gap:8px;max-width:min(420px,calc(100vw - 32px));}',
            '.approval-live-toast{background:#fff;border:1px solid rgba(0,0,0,.12);border-left:4px solid #0d6efd;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:12px 14px;font-size:.92rem;}',
            '.approval-live-toast.is-success{border-left-color:#198754;}',
            '.approval-live-toast.is-danger{border-left-color:#dc3545;}',
            '.approval-live-toast.is-warning{border-left-color:#fd7e14;}',
            '.approval-live-toast-head{display:flex;justify-content:space-between;gap:8px;font-weight:600;margin-bottom:4px;}',
            '.approval-live-toast-actions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;}',
            '.approval-live-details{display:grid;grid-template-columns:minmax(120px,auto) 1fr;gap:4px 12px;margin:0;font-size:.92rem;}',
            '.approval-live-details dt{font-weight:600;color:#495057;}',
            '.approval-live-details dd{margin:0;}',
            '.approval-live-type{display:inline-block;font-size:.78rem;font-weight:600;padding:2px 8px;border-radius:999px;background:#fff3cd;color:#8a5a00;margin-bottom:6px;}'
        ].join('');
        document.head.appendChild(style);
    }

    var toastContainer = null;

    function showToast(options) {
        injectStyles();
        if (toastContainer === null) {
            toastContainer = el('div', 'approval-live-toasts');
            toastContainer.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastContainer);
        }

        var toast = el('div', 'approval-live-toast is-' + (options.tone || 'info'));
        toast.setAttribute('role', 'status');
        var head = el('div', 'approval-live-toast-head');
        head.appendChild(el('span', '', options.title || ''));
        var close = el('button', 'btn-close btn-sm');
        close.type = 'button';
        close.setAttribute('aria-label', 'Inchide');
        head.appendChild(close);
        toast.appendChild(head);
        if (options.message) {
            toast.appendChild(el('div', '', options.message));
        }

        var actions = Array.isArray(options.actions) ? options.actions : [];
        if (actions.length > 0) {
            var actionsWrap = el('div', 'approval-live-toast-actions');
            actions.forEach(function (action) {
                var button = el(action.href ? 'a' : 'button', 'btn btn-sm ' + (action.className || 'btn-outline-primary'), action.label);
                if (action.href) {
                    button.href = action.href;
                } else {
                    button.type = 'button';
                    button.addEventListener('click', function () {
                        action.onClick();
                        remove();
                    });
                }
                actionsWrap.appendChild(button);
            });
            toast.appendChild(actionsWrap);
        }

        function remove() {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }

        close.addEventListener('click', remove);
        toastContainer.appendChild(toast);
        if (options.sticky !== true) {
            window.setTimeout(remove, options.timeout || 8000);
        }
    }

    // ---- popup administrator ------------------------------------------------------------

    function ensureReviewModal() {
        if (reviewModalEl !== null) {
            return reviewModal !== null;
        }
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return false;
        }

        injectStyles();
        reviewModalEl = el('div', 'modal fade');
        reviewModalEl.tabIndex = -1;
        reviewModalEl.setAttribute('aria-hidden', 'true');
        reviewModalEl.setAttribute('data-approval-live-modal', '');
        reviewModalEl.innerHTML = ''
            + '<div class="modal-dialog modal-dialog-centered modal-lg">'
            + '  <div class="modal-content">'
            + '    <div class="modal-header">'
            + '      <div>'
            + '        <h5 class="modal-title" data-live-title>Solicitare de aprobare</h5>'
            + '        <div class="small text-muted" data-live-counter></div>'
            + '      </div>'
            + '      <button type="button" class="btn-close" aria-label="Inchide" data-live-later-x></button>'
            + '    </div>'
            + '    <div class="modal-body" data-live-body></div>'
            + '    <div class="modal-footer">'
            + '      <button type="button" class="btn btn-outline-secondary me-auto" data-live-later>Mai tarziu</button>'
            + '      <button type="button" class="btn btn-outline-danger" data-live-reject>Respinge</button>'
            + '      <button type="button" class="btn btn-success" data-live-approve>Aproba</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(reviewModalEl);
        reviewModal = new bootstrap.Modal(reviewModalEl, { backdrop: 'static', keyboard: false });

        reviewModalEl.querySelector('[data-live-later]').addEventListener('click', postponeCurrent);
        reviewModalEl.querySelector('[data-live-later-x]').addEventListener('click', postponeCurrent);
        reviewModalEl.querySelector('[data-live-approve]').addEventListener('click', function () {
            decideCurrent('approved');
        });
        reviewModalEl.querySelector('[data-live-reject]').addEventListener('click', function () {
            decideCurrent('rejected');
        });

        return true;
    }

    function renderReview(item) {
        var body = reviewModalEl.querySelector('[data-live-body]');
        body.innerHTML = '';

        reviewModalEl.querySelector('[data-live-title]').textContent = item.blocks_trip
            ? 'Cursa asteapta aprobarea ta'
            : 'Solicitare de aprobare';
        var remaining = reviewQueue.length;
        reviewModalEl.querySelector('[data-live-counter]').textContent = remaining > 0
            ? 'Inca ' + remaining + (remaining === 1 ? ' solicitare' : ' solicitari') + ' dupa aceasta'
            : '';

        body.appendChild(el('span', 'approval-live-type', item.request_type_label || item.type_label || 'Aprobare'));
        body.appendChild(el('h5', 'mb-1', item.primary_label || ''));
        if (item.problem_title) {
            body.appendChild(el('div', 'text-danger fw-semibold mb-2', item.problem_title));
        }

        var operation = el('div', 'mb-3');
        operation.appendChild(el('span', 'fw-semibold', item.operation_title || ''));
        if (item.operation_url) {
            var link = el('a', 'ms-2', 'Vezi cursa');
            link.href = item.operation_url;
            link.target = '_blank';
            link.rel = 'noopener';
            operation.appendChild(link);
        }
        body.appendChild(operation);

        var details = el('dl', 'approval-live-details mb-3');
        (Array.isArray(item.detail_rows) ? item.detail_rows : []).forEach(function (row) {
            if (!row || !row.label) {
                return;
            }
            details.appendChild(el('dt', '', row.label));
            details.appendChild(el('dd', '', row.value || '-'));
        });
        body.appendChild(details);

        if (item.blocks_trip) {
            body.appendChild(el('div', 'alert alert-warning py-2 mb-2',
                'Cursa este salvata, dar nu apare in Desfasurator curse pana la aprobare. '
                + 'La respingere, operatorul o corecteaza.'));
        }
        if (item.scope_message) {
            body.appendChild(el('div', 'small text-muted mb-3', item.scope_message));
        }

        var noteLabel = el('label', 'form-label small fw-semibold', 'Nota (optional, obligatorie la respingere)');
        noteLabel.setAttribute('for', 'approval-live-note');
        body.appendChild(noteLabel);
        var note = el('textarea', 'form-control');
        note.id = 'approval-live-note';
        note.rows = 2;
        note.setAttribute('data-live-note', '');
        body.appendChild(note);
        body.appendChild(el('div', 'small text-danger mt-1 d-none', '')).setAttribute('data-live-error', '');
    }

    function setReviewBusy(busy) {
        reviewBusy = busy;
        reviewModalEl.querySelectorAll('.modal-footer button').forEach(function (button) {
            button.disabled = busy;
        });
    }

    function showReviewError(message) {
        var error = reviewModalEl.querySelector('[data-live-error]');
        if (error) {
            error.textContent = message;
            error.classList.toggle('d-none', message === '');
        }
    }

    function showNextReview() {
        if (reviewCurrent !== null || reviewQueue.length === 0 || !ensureReviewModal()) {
            updateTitle();
            return;
        }

        // Nu acoperim alt modal deschis (formular, confirmare): incercam la urmatoarea interogare.
        var otherModalOpen = Array.prototype.some.call(document.querySelectorAll('.modal.show'), function (modal) {
            return modal !== reviewModalEl;
        });
        if (otherModalOpen) {
            updateTitle();
            return;
        }

        reviewCurrent = reviewQueue.shift();
        renderReview(reviewCurrent);
        setReviewBusy(false);
        reviewModal.show();
        updateTitle();
    }

    function closeCurrentReview() {
        reviewCurrent = null;
        if (reviewModal !== null) {
            reviewModal.hide();
        }
        window.setTimeout(showNextReview, 400);
    }

    function postponeCurrent() {
        if (reviewCurrent === null || reviewBusy) {
            return;
        }
        postpone(reviewCurrent.id);
        closeCurrentReview();
    }

    function decideCurrent(decision) {
        if (reviewCurrent === null || reviewBusy) {
            return;
        }

        var noteField = reviewModalEl.querySelector('[data-live-note]');
        var note = noteField instanceof HTMLTextAreaElement ? noteField.value.trim() : '';
        if (decision === 'rejected' && note === '') {
            showReviewError('Scrie motivul respingerii, ca operatorul sa stie ce sa corecteze.');
            if (noteField) {
                noteField.focus();
            }
            return;
        }
        showReviewError('');

        var item = reviewCurrent;
        var formData = new FormData();
        formData.append('_token', csrfToken);
        formData.append('id', String(item.id));
        formData.append('review_note', note);
        formData.append('decision_source', 'popup_live');

        setReviewBusy(true);
        fetch(decision === 'approved' ? approveUrl : rejectUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Raspuns invalid de la server.' };
            }).then(function (payload) {
                return { ok: response.ok, status: response.status, payload: payload };
            });
        }).then(function (result) {
            if (result.ok && result.payload && result.payload.success) {
                showToast({
                    tone: decision === 'approved' ? 'success' : 'danger',
                    title: decision === 'approved' ? 'Aprobat' : 'Respins',
                    message: (item.primary_label || '') + (item.operation_title ? ' · ' + item.operation_title : '')
                });
                closeCurrentReview();
                schedulePoll(300);
                return;
            }

            if (result.status === 409) {
                showToast({ tone: 'warning', title: 'Solicitarea nu mai este in asteptare', message: 'A fost decisa intre timp.' });
                closeCurrentReview();
                schedulePoll(300);
                return;
            }

            setReviewBusy(false);
            showReviewError((result.payload && result.payload.message) || 'Decizia nu a putut fi salvata. Reincearca.');
        }).catch(function () {
            setReviewBusy(false);
            showReviewError('Conexiunea a esuat. Reincearca.');
        });
    }

    function updateReviewQueue(pending) {
        var postponed = postponedIds();
        var pendingIds = {};
        var candidates = pending.filter(function (item) {
            pendingIds[String(item.id)] = true;
            return item.requested_by_user_id !== currentUserId && !postponed[String(item.id)];
        });

        // Cererea afisata a fost decisa in alta parte (panou, email, alt administrator).
        if (reviewCurrent !== null && !pendingIds[String(reviewCurrent.id)] && !reviewBusy) {
            showToast({ tone: 'info', title: 'Solicitare decisa in alta parte', message: reviewCurrent.primary_label || '' });
            closeCurrentReview();
        }

        reviewQueue = candidates.filter(function (item) {
            return reviewCurrent === null || item.id !== reviewCurrent.id;
        });

        if (reviewCurrent !== null && reviewModalEl !== null) {
            var remaining = reviewQueue.length;
            reviewModalEl.querySelector('[data-live-counter]').textContent = remaining > 0
                ? 'Inca ' + remaining + (remaining === 1 ? ' solicitare' : ' solicitari') + ' dupa aceasta'
                : '';
        }

        showNextReview();
    }

    function updateTitle() {
        var waiting = reviewQueue.length + (reviewCurrent !== null ? 1 : 0);
        document.title = waiting > 0 && document.visibilityState !== 'visible'
            ? '(' + waiting + ') Aprobare ceruta · ' + baseTitle
            : baseTitle;
    }

    // ---- deciziile pentru solicitant ------------------------------------------------------

    function notifyDecisions(decisions) {
        var notified = notifiedIds();
        decisions.forEach(function (decision) {
            var key = String(decision.id);
            if (notified[key]) {
                return;
            }
            markNotified(decision.id);

            var approved = decision.status === 'approved';
            var tripLabel = decision.trip_id > 0 ? 'Cursa #' + decision.trip_id : (decision.primary_label || 'Solicitarea');
            var by = decision.reviewed_by_name ? ' de ' + decision.reviewed_by_name : '';
            var reason = String(decision.review_note || '').trim().replace(/[.\s]+$/, '');
            var message;
            var actions = [];

            if (decision.blocks_trip && approved) {
                message = tripLabel + ' a fost aprobata' + by + ' si a intrat in Desfasurator curse.';
                if (isDispatcherPage) {
                    actions.push({ label: 'Reincarca lista', className: 'btn-success', onClick: function () { window.location.reload(); } });
                } else if (dispatcherUrl !== '') {
                    actions.push({ label: 'Deschide Dispecer curse', className: 'btn-success', href: dispatcherUrl });
                }
            } else if (decision.blocks_trip) {
                message = tripLabel + ' a fost respinsa' + by + '. '
                    + (reason !== '' ? 'Motiv: ' + reason + '. ' : '')
                    + 'Corecteaza vehiculul / soferul si salveaza din nou.';
                if (decision.trip_id > 0 && dispatcherUrl !== '') {
                    actions.push({
                        label: 'Corecteaza cursa',
                        className: 'btn-danger',
                        href: dispatcherUrl + '&action=edit&id=' + encodeURIComponent(String(decision.trip_id)) + '#race-form'
                    });
                }
            } else {
                message = (decision.primary_label || 'Solicitarea') + (decision.problem_title ? ' · ' + decision.problem_title : '')
                    + (approved ? ' a fost aprobata' : ' a fost respinsa') + by + '.'
                    + (!approved && reason !== '' ? ' Motiv: ' + reason + '.' : '');
            }

            showToast({
                tone: approved ? 'success' : 'danger',
                title: approved ? 'Aprobare primita' : 'Solicitare respinsa',
                message: message,
                actions: actions,
                sticky: decision.blocks_trip === true
            });
        });
    }

    // ---- tab-ul "In asteptare aprobare" din Desfasurator curse (Dispecer curse) -----------

    var racesTabsCard = document.querySelector('[data-races-tabs]');
    var awaitingTabKey = 'dispatcherRacesTab:' + currentUserId;

    function selectRacesTab(name) {
        if (!(racesTabsCard instanceof HTMLElement)) {
            return;
        }
        racesTabsCard.querySelectorAll('[data-races-tab]').forEach(function (tab) {
            var active = tab.getAttribute('data-races-tab') === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        racesTabsCard.querySelectorAll('[data-races-pane], [data-races-tab-tools]').forEach(function (node) {
            var owner = node.getAttribute('data-races-pane') || node.getAttribute('data-races-tab-tools');
            node.classList.toggle('d-none', owner !== name);
        });
        storageSet(window.sessionStorage, awaitingTabKey, name);
    }

    if (racesTabsCard instanceof HTMLElement) {
        racesTabsCard.querySelectorAll('[data-races-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                selectRacesTab(tab.getAttribute('data-races-tab') || 'active');
            });
        });
        // Linkul din mesajul de dupa salvare (#curse-in-asteptare) sau tab-ul ales anterior.
        if (window.location.hash === '#curse-in-asteptare' || storageGet(window.sessionStorage, awaitingTabKey) === 'awaiting') {
            selectRacesTab('awaiting');
        }
    }

    function cell(row, className, text) {
        var td = el('td', className || '', text === undefined || text === null || text === '' ? '-' : text);
        row.appendChild(td);
        return td;
    }

    function renderAwaitingTrips(trips) {
        if (!(racesTabsCard instanceof HTMLElement)) {
            return;
        }
        var list = racesTabsCard.querySelector('[data-awaiting-trips-list]');
        var count = racesTabsCard.querySelector('[data-awaiting-trips-count]');
        if (!(list instanceof HTMLElement)) {
            return;
        }

        var rejectedCount = trips.filter(function (trip) { return trip.status === 'rejected'; }).length;
        if (count instanceof HTMLElement) {
            count.textContent = String(trips.length);
            count.classList.toggle('text-bg-light', trips.length === 0);
            count.classList.toggle('text-bg-warning', trips.length > 0 && rejectedCount === 0);
            count.classList.toggle('text-bg-danger', rejectedCount > 0);
            count.title = rejectedCount > 0 ? rejectedCount + ' respinse, de corectat' : '';
        }

        list.innerHTML = '';
        if (trips.length === 0) {
            var emptyRow = el('tr', '');
            var emptyCell = el('td', 'text-center text-muted py-4', 'Nicio cursa nu asteapta aprobarea.');
            emptyCell.colSpan = 11;
            emptyRow.appendChild(emptyCell);
            list.appendChild(emptyRow);
            return;
        }

        trips.forEach(function (trip) {
            var rejected = trip.status === 'rejected';
            var row = el('tr', rejected ? 'table-danger' : '');

            var plate = cell(row, 'fw-semibold', trip.vehicle);
            plate.appendChild(el('div', 'small text-muted fw-normal', '#' + trip.trip_id));
            cell(row, '', trip.driver);
            cell(row, '', trip.transport_type);
            cell(row, 'text-nowrap', trip.loading_date);
            cell(row, 'text-nowrap', trip.interval);
            cell(row, '', trip.route);
            cell(row, '', trip.beneficiary);

            var statusCell = el('td', '');
            statusCell.appendChild(el('span', 'badge ' + (rejected ? 'text-bg-danger' : 'text-bg-warning'), rejected ? 'Respinsa' : 'In asteptare'));
            row.appendChild(statusCell);

            // Motivul: fiecare resursa care a cerut aprobarea si, la respingere, decizia adminului.
            var reasonCell = el('td', 'dispatcher-awaiting-reason');
            (Array.isArray(trip.approvals) ? trip.approvals : []).forEach(function (approval) {
                var item = el('div', 'dispatcher-awaiting-reason-item');
                var head = el('div', '');
                head.appendChild(el('span', 'fw-semibold', (approval.type_label ? approval.type_label + ' ' : '') + (approval.label || '')));
                head.appendChild(document.createTextNode(approval.problem ? ': ' + approval.problem : ''));
                item.appendChild(head);
                if (approval.reason) {
                    item.appendChild(el('div', 'small text-muted', approval.reason));
                }
                if (approval.status === 'rejected') {
                    item.appendChild(el('div', 'dispatcher-awaiting-reason-note',
                        'Respins' + (approval.reviewed_by_name ? ' de ' + approval.reviewed_by_name : '')
                        + (approval.reviewed_at ? ' la ' + approval.reviewed_at : '')
                        + (approval.review_note ? ': ' + approval.review_note : '')));
                } else {
                    item.appendChild(el('div', 'small text-warning-emphasis', 'Asteapta decizia administratorului'));
                }
                reasonCell.appendChild(item);
            });
            row.appendChild(reasonCell);

            var sent = cell(row, 'small', trip.requested_by_name);
            if (trip.requested_at) {
                sent.appendChild(el('div', 'text-muted', trip.requested_at));
            }

            var actions = el('td', 'text-end text-nowrap');
            if (trip.edit_url) {
                var edit = el('a', 'btn btn-sm ' + (rejected ? 'btn-danger' : 'btn-outline-secondary'), rejected ? 'Corecteaza' : 'Deschide');
                edit.href = trip.edit_url + '#race-form';
                actions.appendChild(edit);
            }
            row.appendChild(actions);
            list.appendChild(row);
        });
    }

    // ---- interogarea periodica ------------------------------------------------------------

    function schedulePoll(delay) {
        if (stopped) {
            return;
        }
        if (pollTimer !== null) {
            window.clearTimeout(pollTimer);
        }
        pollTimer = window.setTimeout(poll, delay);
    }

    function poll() {
        pollTimer = null;
        if (polling || stopped) {
            return;
        }
        polling = true;

        fetch(liveUrl, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (response.status === 401 || response.status === 403) {
                // Fara drept pe modul / sesiune expirata: nu mai insistam.
                stopped = true;
                return null;
            }
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                return;
            }
            if (payload.can_review) {
                updateReviewQueue(Array.isArray(payload.pending) ? payload.pending : []);
            }
            notifyDecisions(Array.isArray(payload.decisions) ? payload.decisions : []);
            renderAwaitingTrips(Array.isArray(payload.awaiting_trips) ? payload.awaiting_trips : []);
        }).catch(function () {
        }).then(function () {
            polling = false;
            schedulePoll(document.visibilityState === 'visible' ? PERIOD_VISIBLE_MS : PERIOD_HIDDEN_MS);
        });
    }

    document.addEventListener('visibilitychange', function () {
        updateTitle();
        if (document.visibilityState === 'visible') {
            schedulePoll(200);
        }
    });

    // Bootstrap se incarca inaintea acestui script (footer), dar popup-ul il verifica la afisare.
    schedulePoll(600);
})();
