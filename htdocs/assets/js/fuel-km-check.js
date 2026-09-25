/**
 * Carburanti - tabul „Verificare km (GPS/CAN)".
 *
 * Randurile vin din pagina (date deja salvate); alimentarile fara date SAS se
 * completeaza esalonat abia cand tabul e deschis, cate 2 cereri in paralel
 * (SAS raspunde 503 la rafale).
 */
(function () {
    'use strict';

    var configEl = document.querySelector('[data-kmcheck-config]');
    var body = document.querySelector('[data-kmcheck-body]');
    if (!configEl || !body) {
        return;
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent || '{}');
    } catch (error) {
        return;
    }

    var rows = Array.isArray(config.rows) ? config.rows : [];
    var byId = {};
    rows.forEach(function (row) { byId[row.id] = row; });

    var summaryEl = document.querySelector('[data-kmcheck-summary]');
    var onlyIssuesEl = document.querySelector('[data-kmcheck-only-issues]');
    var loading = {};
    var started = false;
    var CONCURRENCY = 2;

    var nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 0, maximumFractionDigits: 1 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function isNum(value) {
        return value !== null && value !== undefined && value !== '' && isFinite(Number(value));
    }

    function signed(value, fmt, unit) {
        var n = Number(value);
        return (n > 0 ? '+' : '') + fmt.format(n) + (unit ? ' ' + unit : '');
    }

    function fmtDate(value) {
        // "2026-09-15 06:20:18" -> "15.09.2026 06:20"
        var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(value || '');
        return m ? m[3] + '.' + m[2] + '.' + m[1] + ' ' + m[4] + ':' + m[5] : (value || '-');
    }

    function empty(text) {
        return '<span class="fuel-kmcheck-muted">' + esc(text || '–') + '</span>';
    }

    function badge(verdict, html, title) {
        if (!verdict) {
            return html;
        }
        return '<span class="fuel-kmcheck-badge is-' + verdict + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' + html + '</span>';
    }

    function pendingCell(row) {
        if (loading[row.id]) {
            return '<span class="fuel-kmcheck-muted"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> SAS…</span>';
        }
        if (row.status === 'no_gps') {
            return empty('fără GPS');
        }
        if (row.status === 'error') {
            return '<span class="fuel-kmcheck-muted" title="' + esc(row.error || '') + '"><i class="bi bi-exclamation-circle"></i> eroare SAS</span>';
        }
        return empty(row.needs_fetch ? 'în așteptare' : '–');
    }

    // Coloanele de odometru absolut apar doar daca exista citiri de calibrare pentru vehiculele din lista.
    function hasCalibrations() {
        return rows.some(function (row) { return !!row.calibration; });
    }

    function renderHead() {
        var head = document.querySelector('[data-kmcheck-head]');
        if (!head) {
            return;
        }
        var calib = hasCalibrations();
        head.innerHTML =
            '<tr class="fuel-kmcheck-groups">' +
                '<th colspan="3"></th>' +
                '<th colspan="3" class="text-center">Km parcurși de la alimentarea precedentă</th>' +
                '<th colspan="3" class="text-center">Litri alimentați</th>' +
                '<th class="text-center">CAN</th>' +
                (calib ? '<th colspan="2" class="text-center">Odometru (cu citire de pe bord)</th>' : '') +
            '</tr>' +
            '<tr>' +
                '<th>Alimentare</th><th>Vehicul</th><th>Șofer</th>' +
                '<th class="text-end">Șofer <small>(odometru)</small></th>' +
                '<th class="text-end">GPS</th>' +
                '<th class="text-end">Diferență</th>' +
                '<th class="text-end">Pe bon</th>' +
                '<th class="text-end">În rezervor <small>(sondă)</small></th>' +
                '<th class="text-end">Diferență</th>' +
                '<th class="text-end">Motorină consumată</th>' +
                (calib ? '<th class="text-end">Odometru GPS</th><th class="text-end">Diferență</th>' : '') +
            '</tr>';
    }

    function shortDate(value) {
        // "2026-07-20 20:59:00" -> "20.07 20:59"
        var full = fmtDate(value);
        return full.length >= 16 ? full.slice(0, 5) + ' ' + full.slice(11, 16) : full;
    }

    function renderRow(row) {
        var hasData = row.status === 'ok' && !loading[row.id];
        var calib = hasCalibrations();
        var cells = [];

        cells.push('<td>' + esc(fmtDate(row.datetime)) + (row.is_full ? ' <span class="fuel-pill fuel-pill-full">Full</span>' : '') + '</td>');
        cells.push('<td class="fw-semibold">' + esc(row.vehicle) + '</td>');
        cells.push('<td>' + (row.driver ? esc(row.driver) : empty()) + '</td>');

        // --- Km: sofer (diferenta de odometru) vs GPS pe acelasi interval
        var declared;
        if (!row.prev_datetime) {
            declared = empty('prima alimentare');
        } else if (isNum(row.declared_km)) {
            declared = '<strong>' + esc(nf0.format(row.declared_km)) + ' km</strong>' +
                '<br><small class="fuel-kmcheck-muted" title="Odometrul tastat la pompă: alimentarea precedentă → aceasta">' +
                esc(nf0.format(row.prev_odometer)) + ' → ' + esc(nf0.format(row.odometer)) + '</small>' +
                (row.odometer_corrected ? ' <span class="fuel-pill fuel-pill-manual" title="Odometru corectat manual - nu mai e valoarea tastată de șofer">corectat</span>' : '');
        } else {
            declared = empty('odometru lipsă');
        }
        cells.push('<td class="text-end">' + declared + '</td>');

        var gpsKmCell;
        if (!row.prev_datetime) {
            gpsKmCell = empty();
        } else if (hasData && isNum(row.gps_km)) {
            var movement = row.gps_segments
                ? 'GPS a înregistrat mișcare între ' + fmtDate(row.gps_first_at) + ' și ' + fmtDate(row.gps_last_at) + ' (' + row.gps_segments + ' segmente)'
                : (row.gps_segments === 0 ? 'GPS nu a înregistrat nicio mișcare în interval' : '');
            gpsKmCell = '<strong>' + esc(nf0.format(row.gps_km)) + ' km</strong>' +
                '<br><small class="fuel-kmcheck-muted" title="' + esc('Km GPS între ora bonului precedent și ora acestui bon. ' + movement) + '">' +
                esc(shortDate(row.prev_datetime)) + ' → ' + esc(shortDate(row.datetime)) + '</small>';
        } else {
            gpsKmCell = pendingCell(row);
        }
        cells.push('<td class="text-end">' + gpsKmCell + '</td>');

        var kmDiff = '';
        if (hasData && isNum(row.km_diff)) {
            kmDiff = badge(row.km_verdict,
                esc(signed(row.km_diff, nf0, 'km')) + (isNum(row.km_diff_pct) ? ' <small>(' + esc(signed(row.km_diff_pct, nf0, '%')) + ')</small>' : ''),
                Number(row.km_diff) < 0 ? 'Șoferul a declarat mai puțini km decât a parcurs camionul după GPS' : 'Șoferul a declarat mai mulți km decât a parcurs camionul după GPS');
        } else if (hasData && row.km_verdict === 'bad') {
            kmDiff = badge('bad', 'odometru invalid', 'Odometrul tastat e mai mic decât la alimentarea precedentă sau lipsește');
        }
        cells.push('<td class="text-end">' + kmDiff + '</td>');

        // --- Litri: bon vs cresterea de nivel din rezervor
        cells.push('<td class="text-end"><strong>' + esc(nf0.format(row.liters)) + ' L</strong></td>');

        var tank;
        if (hasData && isNum(row.fuel_before) && isNum(row.fuel_after)) {
            tank = '<strong>' + esc(nf0.format(row.fuel_detected)) + ' L</strong>' +
                '<br><small class="fuel-kmcheck-muted" title="Nivelul din rezervor înainte → după alimentare">' +
                esc(nf0.format(row.fuel_before)) + ' → ' + esc(nf0.format(row.fuel_after)) + ' L</small>';
        } else if (hasData && isNum(row.fuel_detected)) {
            tank = empty('nivelul nu a crescut');
        } else {
            tank = hasData ? empty('fără sondă') : '';
        }
        cells.push('<td class="text-end">' + tank + '</td>');

        var fuelDiff = '';
        if (hasData && isNum(row.fuel_diff)) {
            fuelDiff = badge(row.fuel_verdict, esc(signed(row.fuel_diff, nf0, 'L')),
                Number(row.fuel_diff) > 0 ? 'Pe bon sunt mai mulți litri decât au intrat în rezervor' : 'În rezervor au intrat mai mulți litri decât sunt pe bon');
        }
        cells.push('<td class="text-end">' + fuelDiff + '</td>');

        // --- CAN: motorina arsa de motor de la alimentarea precedenta
        var can = '';
        if (hasData && isNum(row.can_fuel_l)) {
            can = '<strong>' + esc(nf0.format(row.can_fuel_l)) + ' L</strong>' +
                (isNum(row.can_l100) ? '<br><small class="fuel-kmcheck-muted">' + esc(nf1.format(row.can_l100)) + ' L/100 km</small>' : '');
        } else if (hasData) {
            can = empty('fără CAN');
        }
        cells.push('<td class="text-end" title="Motorină consumată de motor după CAN, de la alimentarea precedentă până la aceasta">' + can + '</td>');

        // --- Optional: odometru absolut, doar cu citiri de pe bord
        if (calib) {
            var gpsOdo = '';
            if (!row.calibration) {
                gpsOdo = empty('fără citire');
            } else if (hasData && isNum(row.gps_odometer)) {
                var calibAfter = row.calibration.reading_datetime > row.datetime;
                gpsOdo = '<strong>' + esc(nf0.format(row.gps_odometer)) + '</strong>' +
                    '<br><small class="fuel-kmcheck-muted">citire ' + esc(nf0.format(row.calibration.reading_km)) + ' din ' + esc(shortDate(row.calibration.reading_datetime)) +
                    ' ' + (calibAfter ? '−' : '+') + ' ' + esc(nf0.format(row.gps_km_since_calibration)) + ' km GPS</small>';
            } else {
                gpsOdo = pendingCell(row);
            }
            cells.push('<td class="text-end">' + gpsOdo + '</td>');
            cells.push('<td class="text-end">' + (hasData && isNum(row.odometer_diff)
                ? badge(row.odometer_verdict, esc(signed(row.odometer_diff, nf0, 'km')), 'Odometrul tastat de șofer minus odometrul GPS la ora bonului')
                : '') + '</td>');
        }

        var verdicts = [row.odometer_verdict, row.km_verdict, row.fuel_verdict];
        var worst = verdicts.indexOf('bad') !== -1 ? 'bad' : (verdicts.indexOf('warn') !== -1 ? 'warn' : '');
        return '<tr data-kmcheck-row="' + row.id + '"' + (worst ? ' class="fuel-kmcheck-row-' + worst + '"' : '') + '>' + cells.join('') + '</tr>';
    }

    function hasIssue(row) {
        return [row.odometer_verdict, row.km_verdict, row.fuel_verdict].some(function (v) { return v === 'warn' || v === 'bad'; });
    }

    function render() {
        var onlyIssues = onlyIssuesEl && onlyIssuesEl.checked;
        var visible = onlyIssues ? rows.filter(hasIssue) : rows;
        renderHead();
        if (!visible.length) {
            body.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">' +
                (onlyIssues ? 'Nicio diferență în alimentările verificate.' : 'Nu există alimentări cu motorină în perioada selectată.') + '</td></tr>';
        } else {
            body.innerHTML = visible.map(renderRow).join('');
        }
        renderSummary();
    }

    function renderSummary() {
        if (!summaryEl) {
            return;
        }
        var checked = 0, pending = 0, noGps = 0;
        var km = { ok: 0, warn: 0, bad: 0 };
        var odo = { ok: 0, warn: 0, bad: 0 };
        var fuel = { ok: 0, warn: 0, bad: 0 };
        var declaredTotal = 0, gpsTotal = 0;
        rows.forEach(function (row) {
            if (row.needs_fetch || loading[row.id]) {
                pending++;
            }
            if (row.status === 'no_gps') {
                noGps++;
            }
            if (row.status !== 'ok') {
                return;
            }
            checked++;
            if (row.km_verdict) { km[row.km_verdict]++; }
            if (row.odometer_verdict) { odo[row.odometer_verdict]++; }
            if (row.fuel_verdict) { fuel[row.fuel_verdict]++; }
            if (isNum(row.declared_km) && isNum(row.gps_km)) {
                declaredTotal += Number(row.declared_km);
                gpsTotal += Number(row.gps_km);
            }
        });

        function stat(label, value, tone) {
            return '<div class="fuel-kmcheck-stat' + (tone ? ' is-' + tone : '') + '"><span>' + esc(label) + '</span><strong>' + value + '</strong></div>';
        }

        summaryEl.innerHTML = [
            stat('Verificate cu GPS', esc(nf0.format(checked)) + ' / ' + esc(nf0.format(rows.length)) +
                (pending ? ' <small>(' + esc(nf0.format(pending)) + ' în lucru)</small>' : '') +
                (noGps ? ' <small>(' + esc(nf0.format(noGps)) + ' fără GPS)</small>' : '')),
            stat('Total km: șofer / GPS', gpsTotal > 0
                ? esc(nf0.format(declaredTotal)) + ' / ' + esc(nf0.format(gpsTotal)) + ' km <small>(' + esc(signed((declaredTotal - gpsTotal) / gpsTotal * 100, nf1, '%')) + ')</small>'
                : '–'),
            stat('Alimentări cu km suspecți', esc(nf0.format(km.bad)) + ' roșii · ' + esc(nf0.format(km.warn)) + ' galbene', km.bad ? 'bad' : (km.warn ? 'warn' : 'ok')),
            stat('Alimentări cu litri suspecți', esc(nf0.format(fuel.bad)) + ' roșii · ' + esc(nf0.format(fuel.warn)) + ' galbene', fuel.bad ? 'bad' : (fuel.warn ? 'warn' : 'ok'))
        ].concat(odo.ok + odo.warn + odo.bad
            ? [stat('Odometru vs GPS', esc(nf0.format(odo.bad)) + ' roșii · ' + esc(nf0.format(odo.warn)) + ' galbene', odo.bad ? 'bad' : (odo.warn ? 'warn' : 'ok'))]
            : []).join('');
    }

    function updateRow(row) {
        byId[row.id] = row;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].id === row.id) {
                rows[i] = row;
                break;
            }
        }
        var tr = body.querySelector('[data-kmcheck-row="' + row.id + '"]');
        if (tr && !(onlyIssuesEl && onlyIssuesEl.checked)) {
            tr.outerHTML = renderRow(row);
            renderSummary();
        } else {
            render();
        }
    }

    function fetchRow(id) {
        loading[id] = true;
        updateRow(byId[id]);
        var url = config.endpoint + (config.endpoint.indexOf('?') === -1 ? '?' : '&') + 'fillup_id=' + encodeURIComponent(id);
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                delete loading[id];
                if (payload && payload.row) {
                    payload.row.needs_fetch = false;
                    updateRow(payload.row);
                } else {
                    byId[id].status = 'error';
                    byId[id].error = (payload && payload.error) || 'Răspuns invalid.';
                    byId[id].needs_fetch = false;
                    updateRow(byId[id]);
                }
            })
            .catch(function (error) {
                delete loading[id];
                byId[id].status = 'error';
                byId[id].error = error.message;
                byId[id].needs_fetch = false;
                updateRow(byId[id]);
            });
    }

    function start() {
        if (started) {
            return;
        }
        started = true;
        var queue = rows.filter(function (row) { return row.needs_fetch; }).map(function (row) { return row.id; });
        function next() {
            var id = queue.shift();
            if (id === undefined) {
                return Promise.resolve();
            }
            return fetchRow(id).then(next);
        }
        for (var i = 0; i < CONCURRENCY; i++) {
            next();
        }
    }

    if (onlyIssuesEl) {
        onlyIssuesEl.addEventListener('change', render);
    }

    // Cererile catre SAS pornesc doar cand utilizatorul deschide tabul.
    var tabButton = document.getElementById('fuel-kmcheck-tab');
    if (tabButton) {
        tabButton.addEventListener('click', start);
        if (tabButton.classList.contains('active')) {
            start();
        }
        // Dupa salvarea unei calibrari revii direct pe tab (Bootstrap se incarca in footer).
        if (window.location.hash === '#fuel-kmcheck') {
            window.addEventListener('load', function () {
                if (window.bootstrap) {
                    window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
                }
                start();
            });
        }
    }

    render();
})();
