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

    /** Bon - CAN: diferenta si verdictul (acelasi calcul pentru celula si pentru filtru). */
    function canCompare(row) {
        if (row.status !== 'ok' || !isNum(row.can_fuel_l) || !row.prev_datetime) {
            return null;
        }
        var diff = Number(row.liters) - Number(row.can_fuel_l);
        var abs = Math.abs(diff);
        return {
            diff: diff,
            verdict: abs <= Math.max(20, row.liters * 0.10) ? 'ok' : (abs <= Math.max(50, row.liters * 0.25) ? 'warn' : 'bad')
        };
    }

    function bonL100(row) {
        return row.status === 'ok' && row.prev_datetime && isNum(row.gps_km) && Number(row.gps_km) >= 5
            ? Number(row.liters) / Number(row.gps_km) * 100
            : null;
    }

    // ------------------------------------------------------------ filtre antet
    //
    // Acelasi comportament ca table-column-filter.js (click = sortare, palnie = lista de
    // valori cu cautare, in cascada), dar pe datele randurilor: tabelul se redeseneaza
    // pe masura ce vin datele SAS, deci modulul comun (care lucreaza pe DOM) nu se poate folosi.
    // Coloanele de diferenta se filtreaza pe culoare, nu pe fiecare valoare.

    var VERDICT_LABELS = { bad: 'Roșu (de verificat)', warn: 'Galben (de urmărit)', ok: 'Verde (în toleranță)' };
    var VERDICT_ORDER = { bad: 0, warn: 1, ok: 2 };

    function numText(value, fmt, unit) {
        return isNum(value) ? fmt.format(Number(value)) + (unit ? ' ' + unit : '') : '';
    }

    function verdictText(verdict) {
        return verdict ? VERDICT_LABELS[verdict] : '';
    }

    function columnDefs() {
        var defs = [
            { key: 'date', label: 'Alimentare', filter: function (r) { return fmtDate(r.datetime).slice(0, 10); }, sort: function (r) { return r.datetime; } },
            { key: 'vehicle', label: 'Vehicul', filter: function (r) { return r.vehicle; }, sort: function (r) { return r.vehicle; } },
            { key: 'driver', label: 'Șofer', filter: function (r) { return r.driver; }, sort: function (r) { return r.driver; } },
            { key: 'declared', label: 'Km șofer', filter: function (r) { return numText(r.declared_km, nf0, 'km'); }, sort: function (r) { return r.declared_km; } },
            { key: 'gps', label: 'Km GPS', filter: function (r) { return r.status === 'ok' ? numText(r.gps_km, nf0, 'km') : ''; }, sort: function (r) { return r.status === 'ok' ? r.gps_km : null; } },
            { key: 'km_diff', label: 'Diferență km', filter: function (r) { return verdictText(r.km_verdict); }, sort: function (r) { return r.km_diff; }, verdict: true },
            { key: 'liters', label: 'Litri pe bon', filter: function (r) { return numText(r.liters, nf0, 'L'); }, sort: function (r) { return r.liters; } },
            { key: 'tank', label: 'Litri în rezervor', filter: function (r) { return r.status === 'ok' ? numText(r.fuel_detected, nf0, 'L') : ''; }, sort: function (r) { return r.status === 'ok' ? r.fuel_detected : null; } },
            { key: 'fuel_diff', label: 'Diferență litri', filter: function (r) { return verdictText(r.fuel_verdict); }, sort: function (r) { return r.fuel_diff; }, verdict: true },
            { key: 'can', label: 'Consumat CAN', filter: function (r) { return r.status === 'ok' ? numText(r.can_fuel_l, nf0, 'L') : ''; }, sort: function (r) { return r.status === 'ok' ? r.can_fuel_l : null; } },
            { key: 'can_diff', label: 'Bon − CAN', filter: function (r) { var c = canCompare(r); return c ? verdictText(c.verdict) : ''; }, sort: function (r) { var c = canCompare(r); return c ? c.diff : null; }, verdict: true },
            { key: 'l100', label: 'L/100 km', filter: function (r) { return numText(bonL100(r), nf1); }, sort: bonL100 }
        ];
        if (hasCalibrations()) {
            defs.push(
                { key: 'gps_odo', label: 'Odometru GPS', filter: function (r) { return numText(r.gps_odometer, nf0); }, sort: function (r) { return r.gps_odometer; } },
                { key: 'odo_diff', label: 'Diferență odometru', filter: function (r) { return verdictText(r.odometer_verdict); }, sort: function (r) { return r.odometer_diff; }, verdict: true }
            );
        }
        return defs;
    }

    var filterState = { filters: {}, sortKey: null, sortDir: 1 };

    function defByKey(key) {
        var defs = columnDefs();
        for (var i = 0; i < defs.length; i++) {
            if (defs[i].key === key) {
                return defs[i];
            }
        }
        return null;
    }

    function matchesFilters(row, exceptKey) {
        return Object.keys(filterState.filters).every(function (key) {
            if (key === exceptKey) {
                return true;
            }
            var def = defByKey(key);
            return !def || filterState.filters[key].has(def.filter(row) || '(gol)');
        });
    }

    function compareValues(a, b) {
        var aEmpty = a === null || a === undefined || a === '';
        var bEmpty = b === null || b === undefined || b === '';
        if (aEmpty || bEmpty) {
            return aEmpty === bEmpty ? 0 : (aEmpty ? 1 : -1); // valorile goale raman la final
        }
        if (isNum(a) && isNum(b)) {
            return (Number(a) - Number(b)) * filterState.sortDir;
        }
        return String(a).localeCompare(String(b), 'ro') * filterState.sortDir;
    }

    function filteredRows() {
        var onlyIssues = onlyIssuesEl && onlyIssuesEl.checked;
        var list = rows.filter(function (row) {
            return (!onlyIssues || hasIssue(row)) && matchesFilters(row, null);
        });
        var def = filterState.sortKey ? defByKey(filterState.sortKey) : null;
        if (def) {
            list = list.slice().sort(function (a, b) { return compareValues(def.sort(a), def.sort(b)); });
        }
        return list;
    }

    var dropdown = document.createElement('div');
    dropdown.className = 'dispatcher-races-filter-dropdown';
    dropdown.hidden = true;
    document.body.appendChild(dropdown);
    var openKey = null;

    function closeDropdown() {
        dropdown.hidden = true;
        openKey = null;
    }

    function setFilterValue(key, value, checked) {
        var set = filterState.filters[key] || new Set();
        if (checked) {
            set.add(value);
        } else {
            set.delete(value);
        }
        if (set.size) {
            filterState.filters[key] = set;
        } else {
            delete filterState.filters[key];
        }
    }

    function openDropdown(key, anchor) {
        var def = defByKey(key);
        if (!def) {
            return;
        }
        openKey = key;
        var active = filterState.filters[key] || new Set();
        var onlyIssues = onlyIssuesEl && onlyIssuesEl.checked;
        var seen = {};
        var values = [];
        var sortOf = {};
        rows.forEach(function (row) {
            // cascada: doar valorile ramase dupa filtrele celorlalte coloane
            if ((onlyIssues && !hasIssue(row)) || !matchesFilters(row, key)) {
                return;
            }
            var value = def.filter(row) || '(gol)';
            if (!seen[value]) {
                seen[value] = true;
                values.push(value);
                sortOf[value] = def.verdict ? VERDICT_ORDER[Object.keys(VERDICT_LABELS).filter(function (k) { return VERDICT_LABELS[k] === value; })[0]] : def.sort(row);
            }
        });
        active.forEach(function (value) {
            if (!seen[value]) {
                seen[value] = true;
                values.push(value);
            }
        });
        values.sort(function (a, b) {
            if (active.has(a) !== active.has(b)) {
                return active.has(a) ? -1 : 1;
            }
            if (a === '(gol)' || b === '(gol)') {
                return a === '(gol)' ? 1 : -1;
            }
            var av = sortOf[a];
            var bv = sortOf[b];
            if (isNum(av) && isNum(bv)) {
                return Number(av) - Number(bv);
            }
            return String(av == null ? a : av).localeCompare(String(bv == null ? b : bv), 'ro');
        });

        dropdown.innerHTML =
            '<div class="races-filter-head"><strong>Filtru: ' + esc(def.label) + '</strong><a href="#" data-kc-reset>Toate</a></div>' +
            '<input type="search" class="form-control form-control-sm mt-1" placeholder="Caută valoare..." data-kc-search>' +
            '<div class="races-filter-list">' + values.map(function (value) {
                return '<label class="races-filter-option" data-value="' + esc(value) + '">' +
                    '<input type="checkbox" class="form-check-input m-0 mt-1"' + (active.has(value) ? ' checked' : '') + '>' +
                    '<span>' + esc(value) + '</span></label>';
            }).join('') + '</div>' +
            '<div class="races-filter-reset-all-wrap"><a href="#" class="races-filter-reset-all" data-kc-reset-all>' +
            '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează toate filtrele</a></div>';

        dropdown.hidden = false;
        var rect = anchor.getBoundingClientRect();
        var width = Math.min(320, Math.max(220, rect.width * 2));
        dropdown.style.width = width + 'px';
        dropdown.style.left = Math.max(8, Math.min(Math.round(rect.left), window.innerWidth - width - 8)) + 'px';
        dropdown.style.top = Math.round(rect.bottom + 4) + 'px';
        var box = dropdown.getBoundingClientRect();
        if (box.bottom > window.innerHeight - 8) {
            var above = Math.round(rect.top - box.height - 4);
            dropdown.style.top = (above >= 8 ? above : Math.max(8, window.innerHeight - box.height - 8)) + 'px';
        }
        dropdown.querySelector('[data-kc-search]').focus();
    }

    dropdown.addEventListener('click', function (event) {
        if (event.target.closest('[data-kc-reset]')) {
            event.preventDefault();
            delete filterState.filters[openKey];
            closeDropdown();
            render();
            return;
        }
        if (event.target.closest('[data-kc-reset-all]')) {
            event.preventDefault();
            filterState.filters = {};
            closeDropdown();
            render();
            return;
        }
        var option = event.target.closest('.races-filter-option');
        if (option && openKey) {
            event.preventDefault();
            var checkbox = option.querySelector('input');
            checkbox.checked = !checkbox.checked;
            setFilterValue(openKey, option.getAttribute('data-value'), checkbox.checked);
            render();
        }
    });
    dropdown.addEventListener('input', function (event) {
        if (!event.target.matches('[data-kc-search]')) {
            return;
        }
        var needle = event.target.value.toLowerCase();
        dropdown.querySelectorAll('.races-filter-option').forEach(function (option) {
            option.classList.toggle('d-none', option.textContent.toLowerCase().indexOf(needle) === -1);
        });
    });
    document.addEventListener('click', function (event) {
        if (!dropdown.hidden && !dropdown.contains(event.target) && !event.target.closest('[data-kc-filter]')) {
            closeDropdown();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeDropdown();
        }
    });
    window.addEventListener('scroll', function (event) {
        if (!dropdown.contains(event.target)) {
            closeDropdown();
        }
    }, { passive: true, capture: true });

    /** Antetul unei coloane, cu sageata de sortare si palnia de filtru (clasele din Desfasurator). */
    function headCell(key, labelHtml, extraClass) {
        var filtered = !!filterState.filters[key];
        var sorted = filterState.sortKey === key;
        var classes = ['races-sortable'];
        if (extraClass) { classes.push(extraClass); }
        if (filtered) { classes.push('races-filtered'); }
        if (sorted) { classes.push(filterState.sortDir === 1 ? 'races-sorted-asc' : 'races-sorted-desc'); }
        return '<th class="' + classes.join(' ') + '" data-kc-col="' + key + '" title="Click: sortează. Pâlnie: filtrează valorile coloanei.">' +
            '<div class="races-head-wrap"><span class="races-head-label">' + labelHtml + '</span>' +
            '<span class="races-head-controls"><span class="races-sort-arrow">' + (sorted ? (filterState.sortDir === 1 ? '▲' : '▼') : '↕') + '</span>' +
            '<button type="button" class="races-filter-btn" data-kc-filter="' + key + '" aria-label="Filtrează coloana"><i class="bi bi-funnel" aria-hidden="true"></i></button>' +
            '</span></div></th>';
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
                '<th colspan="3" class="text-center">Consum: bon față de CAN / GPS</th>' +
                (calib ? '<th colspan="2" class="text-center">Odometru (cu citire de pe bord)</th>' : '') +
            '</tr>' +
            '<tr>' +
                headCell('date', 'Alimentare') + headCell('vehicle', 'Vehicul') + headCell('driver', 'Șofer') +
                headCell('declared', 'Șofer <small>(odometru)</small>', 'text-end') +
                headCell('gps', 'GPS', 'text-end') +
                headCell('km_diff', 'Diferență', 'text-end') +
                headCell('liters', 'Pe bon', 'text-end') +
                headCell('tank', 'În rezervor <small>(sondă)</small>', 'text-end') +
                headCell('fuel_diff', 'Diferență', 'text-end') +
                headCell('can', 'Consumat <small>(CAN)</small>', 'text-end') +
                headCell('can_diff', 'Bon − CAN', 'text-end') +
                headCell('l100', 'L/100 km <small>(bon ÷ km GPS)</small>', 'text-end') +
                (calib ? headCell('gps_odo', 'Odometru GPS', 'text-end') + headCell('odo_diff', 'Diferență', 'text-end') : '') +
            '</tr>';
        head.closest('table').classList.add('has-column-filter');
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

        // --- Consum: motorina arsa de motor (CAN) de la alimentarea precedenta, fata de bon
        var can = '';
        if (hasData && isNum(row.can_fuel_l)) {
            can = '<strong>' + esc(nf0.format(row.can_fuel_l)) + ' L</strong>';
        } else if (hasData) {
            can = empty('fără CAN');
        }
        cells.push('<td class="text-end" title="Motorină consumată de motor după CAN, de la alimentarea precedentă până la aceasta">' + can + '</td>');

        // Bon - CAN: cu plin la fiecare alimentare, litrii pusi = litrii arsi de la plinul precedent.
        // La alimentari partiale diferenta e normala, de aceea nu intra in culoarea randului.
        var canDiffCell = '';
        var canCmp = hasData ? canCompare(row) : null;
        if (canCmp) {
            var canDiff = canCmp.diff;
            canDiffCell = badge(canCmp.verdict, esc(signed(canDiff, nf0, 'L')),
                (canDiff > 0 ? 'Pe bon sunt mai mulți litri decât a consumat motorul' : 'Motorul a consumat mai mult decât s-a pus acum') +
                ' de la alimentarea precedentă. Comparația e exactă doar dacă la ambele alimentări s-a făcut plinul.');
        }
        cells.push('<td class="text-end">' + canDiffCell + '</td>');

        // L/100 km din bon si km GPS, langa L/100 km dupa CAN.
        var l100Cell = '';
        var l100 = hasData ? bonL100(row) : null;
        if (l100 !== null) {
            l100Cell = '<strong>' + esc(nf1.format(l100)) + '</strong>' +
                (isNum(row.can_l100) ? '<br><small class="fuel-kmcheck-muted">CAN: ' + esc(nf1.format(row.can_l100)) + '</small>' : '');
        } else if (hasData && isNum(row.can_l100)) {
            l100Cell = '<small class="fuel-kmcheck-muted">CAN: ' + esc(nf1.format(row.can_l100)) + '</small>';
        }
        cells.push('<td class="text-end" title="Litrii de pe bon împărțiți la km GPS de la alimentarea precedentă, față de consumul măsurat de CAN pe aceiași km">' + l100Cell + '</td>');

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
        var visible = filteredRows();
        var filtered = onlyIssues || Object.keys(filterState.filters).length > 0;
        renderHead();
        if (!visible.length) {
            body.innerHTML = '<tr><td colspan="14" class="text-center text-muted py-4">' +
                (filtered ? 'Nicio alimentare nu corespunde filtrelor.' : 'Nu există alimentări cu motorină în perioada selectată.') + '</td></tr>';
        } else {
            body.innerHTML = visible.map(renderRow).join('');
        }
        // Cardurile de sus se recalculeaza pe randurile ramase dupa filtre.
        renderSummary(visible, filtered);
    }

    function renderSummary(list, filtered) {
        if (!summaryEl) {
            return;
        }
        list = list || rows;
        var checked = 0, pending = 0, noGps = 0;
        var km = { ok: 0, warn: 0, bad: 0 };
        var odo = { ok: 0, warn: 0, bad: 0 };
        var fuel = { ok: 0, warn: 0, bad: 0 };
        var declaredTotal = 0, gpsTotal = 0;
        // Totalurile de litri se aduna doar pe randurile care au AMBELE valori, ca sa fie comparabile.
        var tankBon = 0, tankTotal = 0, tankRows = 0;
        var canBon = 0, canTotal = 0, canRows = 0;
        list.forEach(function (row) {
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
            if (isNum(row.fuel_detected)) {
                tankBon += Number(row.liters);
                tankTotal += Number(row.fuel_detected);
                tankRows++;
            }
            if (isNum(row.can_fuel_l) && row.prev_datetime) {
                canBon += Number(row.liters);
                canTotal += Number(row.can_fuel_l);
                canRows++;
            }
        });

        function stat(label, value, tone) {
            return '<div class="fuel-kmcheck-stat' + (tone ? ' is-' + tone : '') + '"><span>' + esc(label) + '</span><strong>' + value + '</strong></div>';
        }

        summaryEl.innerHTML = [
            stat(filtered ? 'Verificate cu GPS (după filtre)' : 'Verificate cu GPS', esc(nf0.format(checked)) + ' / ' + esc(nf0.format(list.length)) +
                (pending ? ' <small>(' + esc(nf0.format(pending)) + ' în lucru)</small>' : '') +
                (noGps ? ' <small>(' + esc(nf0.format(noGps)) + ' fără GPS)</small>' : '')),
            stat('Total km: șofer / GPS', gpsTotal > 0
                ? esc(nf0.format(declaredTotal)) + ' / ' + esc(nf0.format(gpsTotal)) + ' km <small>(' + esc(signed((declaredTotal - gpsTotal) / gpsTotal * 100, nf1, '%')) + ')</small>'
                : '–'),
            stat('Total litri: bon / rezervor (sondă)', tankTotal > 0
                ? esc(nf0.format(tankBon)) + ' / ' + esc(nf0.format(tankTotal)) + ' L <small>(' + esc(signed((tankBon - tankTotal) / tankTotal * 100, nf1, '%')) +
                    (tankRows < checked ? ', ' + esc(nf0.format(tankRows)) + ' alim.' : '') + ')</small>'
                : '–'),
            stat('Total litri: bon / consumat CAN', canTotal > 0
                ? esc(nf0.format(canBon)) + ' / ' + esc(nf0.format(canTotal)) + ' L <small>(' + esc(signed((canBon - canTotal) / canTotal * 100, nf1, '%')) +
                    (canRows < checked ? ', ' + esc(nf0.format(canRows)) + ' alim.' : '') + ')</small>'
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
        var plain = !(onlyIssuesEl && onlyIssuesEl.checked) && !filterState.sortKey && !Object.keys(filterState.filters).length;
        if (tr && plain) {
            tr.outerHTML = renderRow(row);
            renderSummary(rows, false);
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

    var headEl = document.querySelector('[data-kmcheck-head]');
    if (headEl) {
        headEl.addEventListener('click', function (event) {
            var filterButton = event.target.closest('[data-kc-filter]');
            if (filterButton) {
                var key = filterButton.getAttribute('data-kc-filter');
                if (openKey === key && !dropdown.hidden) {
                    closeDropdown();
                } else {
                    openDropdown(key, filterButton);
                }
                return;
            }
            var th = event.target.closest('[data-kc-col]');
            if (!th) {
                return;
            }
            // Click: crescator, apoi descrescator, apoi ordinea initiala (cele mai noi primele).
            var col = th.getAttribute('data-kc-col');
            if (filterState.sortKey !== col) {
                filterState.sortKey = col;
                filterState.sortDir = 1;
            } else if (filterState.sortDir === 1) {
                filterState.sortDir = -1;
            } else {
                filterState.sortKey = null;
            }
            render();
        });
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
