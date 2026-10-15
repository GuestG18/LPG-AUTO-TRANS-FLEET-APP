/**
 * „Km efectuati” din GPS (SAS) in formularul cursei si al fazei (Dispecer curse).
 *
 * Cand vehiculul + data/ora de inceput + data/ora de sfarsit sunt completate, cere
 * km-ii GPS ai intervalului (action=trip_gps_km) si ii pune, rotunjiti, in campul de
 * km efectuati al tipului de transport. Operatorul poate corecta valoarea: o noua
 * cerere pleaca doar cand se schimba vehiculul sau intervalul. La deschiderea unei
 * curse existente se completeaza doar daca campul este gol.
 *
 * Campul tinta, dupa tip: Primar / Primar+Distributie (si Primar tona, cand e
 * vizibil) -> km_totali; Compresor -> km_dislocare; restul -> km_cursa.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var url = script ? script.getAttribute('data-gps-km-url') : '';
    if (!url) {
        return;
    }

    function isoDate(value) {
        var text = String(value || '').trim();
        var ro = /^(\d{2})[\/.\-](\d{2})[\/.\-](\d{4})$/.exec(text);
        if (ro) {
            return ro[3] + '-' + ro[2] + '-' + ro[1];
        }
        return /^\d{4}-\d{2}-\d{2}$/.test(text) ? text : '';
    }

    function hhmm(value) {
        var match = /^(\d{1,2}):(\d{2})/.exec(String(value || '').trim());
        return match ? ('0' + match[1]).slice(-2) + ':' + match[2] : '';
    }

    function visible(element) {
        return !!element && element.offsetParent !== null;
    }

    function initForm(form) {
        var field = function (name) { return form.querySelector('[name="' + name + '"]'); };
        var timer = 0;
        var requestId = 0;
        var lastKey = null;
        var hint = null;

        function targetField() {
            var typeField = form.querySelector('[data-role="tip-transport"]') || field('tip_transport');
            var type = typeField ? String(typeField.value || '') : '';
            var kmTotal = form.querySelector('[data-role="km-totali"]');
            if (type === 'primar' || type === 'primar_distributie') {
                return kmTotal;
            }
            if (type === 'primar_tona') {
                // km_cursa sunt aici km-ii rutei: fara Km totali vizibil nu completam nimic.
                return visible(kmTotal) ? kmTotal : null;
            }
            if (type === 'compresor') {
                return form.querySelector('[data-role="km-dislocare"]');
            }
            return form.querySelector('[data-role="km"]');
        }

        function params() {
            var vehicle = field('vehicle_id');
            var values = {
                vehicle_id: vehicle ? String(vehicle.value || '') : '',
                data_inceput: isoDate(field('data_inceput') && field('data_inceput').value),
                ora_inceput: hhmm(field('ora_inceput') && field('ora_inceput').value),
                data_sfarsit: isoDate(field('data_sfarsit') && field('data_sfarsit').value),
                ora_sfarsit: hhmm(field('ora_sfarsit') && field('ora_sfarsit').value)
            };
            var complete = Object.keys(values).every(function (key) { return values[key] !== ''; });
            return complete ? values : null;
        }

        function keyOf(values) {
            return values ? [values.vehicle_id, values.data_inceput, values.ora_inceput, values.data_sfarsit, values.ora_sfarsit].join('|') : '';
        }

        function setHint(target, text, isError) {
            if (!hint) {
                hint = document.createElement('div');
                hint.className = 'form-text';
                hint.setAttribute('data-role', 'gps-km-hint');
            }
            if (!text || !target) {
                hint.remove();
                return;
            }
            hint.textContent = text;
            hint.classList.toggle('text-danger', !!isError);
            hint.classList.toggle('text-muted', !isError);
            target.insertAdjacentElement('afterend', hint);
        }

        function fetchKm(values) {
            var target = targetField();
            if (!target || target.readOnly || target.disabled) {
                return;
            }
            var current = ++requestId;
            var query = new URLSearchParams(values).toString();
            setHint(target, 'Se calculează km din GPS (SAS)…', false);
            fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + query, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    // Intre timp s-a schimbat vehiculul / intervalul: raspunsul vechi se ignora.
                    if (current !== requestId) { return; }
                    if (!data || !data.success) {
                        setHint(target, (data && data.error) || 'Datele SAS nu au putut fi citite; completează km manual.', true);
                        return;
                    }
                    var km = Math.round(Number(data.total_km) || 0);
                    target.value = String(km);
                    // Calculul cursei (total, cost/km) se reface ca la o tastare.
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                    setHint(target, 'Din GPS (SAS): ' + String(data.total_km).replace('.', ',') + ' km'
                        + (data.partial ? ' până acum (cursa nu s-a încheiat)' : '') + '. Poți corecta valoarea.', false);
                })
                .catch(function () {
                    if (current !== requestId) { return; }
                    setHint(target, 'Datele SAS nu au putut fi citite; completează km manual.', true);
                });
        }

        function check() {
            var values = params();
            var key = keyOf(values);
            if (key === lastKey) {
                return;
            }
            lastKey = key;
            if (values) {
                fetchKm(values);
            } else {
                setHint(targetField(), '', false);
            }
        }

        // Selectorul de data scrie in campuri ascunse fara evenimente proprii: verificam
        // intervalul dupa orice interactiune cu formularul (o cerere doar daca s-a schimbat).
        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(check, 600);
        }
        ['change', 'input', 'focusout', 'click', 'keyup'].forEach(function (type) {
            form.addEventListener(type, function (event) {
                if (event.target && event.target.getAttribute && event.target.getAttribute('data-role') === 'gps-km-hint') {
                    return;
                }
                schedule();
            });
        });

        // Deschiderea unei curse existente: km-ii salvati raman; un camp gol se completeaza.
        var initial = params();
        lastKey = keyOf(initial);
        var target = targetField();
        if (initial && target && visible(form) && String(target.value || '').trim() === '') {
            fetchKm(initial);
        }
    }

    function start() {
        document.querySelectorAll('form.dispatcher-race-form').forEach(initForm);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
