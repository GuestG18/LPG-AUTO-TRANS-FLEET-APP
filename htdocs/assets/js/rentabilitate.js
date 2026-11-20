/* Rentabilitate Operationala (?page=rentabilitate) — tabel compact, filtre live, randuri extinse cu tab-uri.
 * Doar citire: valorile vin din backend (action=list / vehicle / costs / trips / ...). */
(function () {
    'use strict';

    var CFG = window.ROP_CONFIG || {};
    var root = document.getElementById('ropRoot');
    if (!root) { return; }

    var nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var nf3 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    var qs = new URLSearchParams(location.search);
    var state = {
        mode: qs.get('mode') === 'soferi' ? 'soferi' : 'vehicule',
        q: qs.get('q') || '', tip: qs.get('tip') || '', beneficiar: qs.get('beneficiar') || '', status: qs.get('status') || '',
        from: qs.get('from') || CFG.from, to: qs.get('to') || CFG.to, page: parseInt(qs.get('p') || '1', 10) || 1,
        per_page: parseInt(qs.get('per_page') || '25', 10) || 25, sort: qs.get('sort') || '',
        // filtrele cardurilor KPI (aceeasi stare ca bara de sus; serverul o valideaza si o intoarce in `aplicat`)
        vehicle_id: qs.get('vehicle_id') || '', driver_id: qs.get('driver_id') || ''
    };
    var keepFilter = ''; // filtrul schimbat ultimul: castiga cand vehiculul si soferul ales nu au lucrat impreuna
    var rowsByKey = {};
    var open = {};
    var selected = {};
    var abort = null;
    var lastList = null;

    var CAT_ICONS = { combustibil: 'bi-fuel-pump', reparatii: 'bi-wrench-adjustable', anvelope: 'bi-record-circle', taxe: 'bi-file-earmark-text', amortizare: 'bi-stack', sofer: 'bi-person-badge', alte: 'bi-three-dots',
        // componentele de cost ale soferului (modul Soferi)
        salariu_soferi: 'bi-person-badge', diurna: 'bi-cash-coin', analize_soferi: 'bi-clipboard2-pulse', cursuri_soferi: 'bi-mortarboard', ssm_su: 'bi-shield-check' };
    var CAT_ADD = { reparatii: ['Adaugă reparație', 'reparatii'], combustibil: ['Alimentări', 'carburanti'], taxe: ['Documente', 'documente'], sofer: ['Contabilitate personal', 'contabilitate'],
        salariu_soferi: ['Contabilitate personal', 'contabilitate'], diurna: ['Contabilitate personal', 'contabilitate'] };
    var VEHICLE_TABS = [
        { key: 'costuri', label: 'Costuri', icon: 'bi-layers' }, { key: 'activitate', label: 'Activitate', icon: 'bi-bezier2' },
        { key: 'beneficiari', label: 'Beneficiari', icon: 'bi-people' }, { key: 'investitii', label: 'Investiții', icon: 'bi-box-seam' },
        { key: 'documente', label: 'Documente', icon: 'bi-file-earmark-text' }, { key: 'soferi', label: 'Șoferi / Simulare', icon: 'bi-person-gear' }
    ];
    var DRIVER_TABS = [
        { key: 'costuri', label: 'Costuri', icon: 'bi-layers' }, { key: 'activitate', label: 'Curse', icon: 'bi-bezier2' },
        { key: 'vehicule', label: 'Vehicule', icon: 'bi-truck' }, { key: 'beneficiari', label: 'Beneficiari', icon: 'bi-people' },
        { key: 'istoric', label: 'Istoric activitate', icon: 'bi-clock-history' }
    ];

    // ------------------------------------------------------------------ utilitare
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function n2(v) { return v === null || v === undefined ? '<span class="rop-na">—</span>' : nf2.format(v); }
    function lei(v) { return v === null || v === undefined ? '—' : nf2.format(v) + ' lei'; }
    function km(v) { return v === null || v === undefined ? '—' : nf0.format(Math.round(v)) + ' km'; }
    function pct(v) { return v === null || v === undefined ? '<span class="rop-na">—</span>' : nf1.format(v) + '%'; }
    function signed(v) { return v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + nf2.format(v); }
    function dt(v) { var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '.' + m[2] + '.' + m[1] : '—'; }
    function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }
    function u(extra) {
        var url = new URL(CFG.urls.base, location.href);
        Object.keys(extra).forEach(function (k) { if (extra[k] !== '' && extra[k] !== null && extra[k] !== undefined) { url.searchParams.set(k, extra[k]); } });
        return url.toString();
    }
    function link(base, extra) {
        var url = new URL(base, location.href);
        Object.keys(extra || {}).forEach(function (k) { url.searchParams.set(k, extra[k]); });
        return url.toString();
    }
    function get(action, params, signal) {
        return fetch(u(Object.assign({ action: action, from: state.from, to: state.to }, params || {})), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: signal })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Răspuns invalid (' + r.status + ').' }; }); });
    }
    function toast(msg) {
        var el = document.getElementById('ropToast');
        el.textContent = msg; el.classList.add('is-visible');
        clearTimeout(toast.t); toast.t = setTimeout(function () { el.classList.remove('is-visible'); }, 3000);
    }
    function emptyRow(icon, text, cols) { return '<tr class="rop-state"><td colspan="' + (cols || 13) + '"><i class="bi ' + icon + '"></i>' + esc(text) + '</td></tr>'; }
    function resClass(v) { return v === null || v === undefined ? '' : (v < 0 ? 'is-neg' : 'is-pos'); }

    // meniu flotant (nu e taiat de tabel)
    var floating = null;
    function closeMenu() { if (floating) { floating.remove(); floating = null; } }
    function showMenu(anchor, items) {
        closeMenu();
        var m = document.createElement('div');
        m.className = 'dropdown-menu rop-menu rop-floating show';
        items.filter(Boolean).forEach(function (it) {
            if (it === '-') { m.insertAdjacentHTML('beforeend', '<div class="dropdown-divider"></div>'); return; }
            var el = document.createElement(it.href ? 'a' : 'button');
            el.className = 'dropdown-item';
            if (it.href) { el.href = it.href; el.target = '_blank'; el.rel = 'noopener'; } else { el.type = 'button'; }
            el.innerHTML = '<i class="bi ' + it.icon + '"></i>' + esc(it.label);
            el.addEventListener('click', function (e) { e.stopPropagation(); closeMenu(); if (it.run) { it.run(); } });
            m.appendChild(el);
        });
        document.body.appendChild(m);
        var r = anchor.getBoundingClientRect(), w = m.offsetWidth, h = m.offsetHeight;
        m.style.left = Math.max(8, Math.min(innerWidth - w - 8, r.right - w)) + 'px';
        m.style.top = (r.bottom + h + 6 > innerHeight ? r.top - h - 4 : r.bottom + 4) + 'px';
        floating = m;
    }
    document.addEventListener('click', function (e) { if (floating && !floating.contains(e.target)) { closeMenu(); } });
    window.addEventListener('scroll', closeMenu, true);

    // ------------------------------------------------------------------ bara de instrumente
    var search = document.getElementById('ropSearch');
    var selTip = document.getElementById('ropTip');
    var selBenef = document.getElementById('ropBenef');
    var selStatus = document.getElementById('ropStatus');
    search.value = state.q;
    selStatus.value = state.status;
    var benefOptions = '<option value="">Beneficiar</option>';
    Object.keys(CFG.beneficiaries || {}).sort(function (a, b) { return String(CFG.beneficiaries[a]).localeCompare(CFG.beneficiaries[b]); })
        .forEach(function (id) { benefOptions += '<option value="' + esc(id) + '">' + esc(CFG.beneficiaries[id]) + '</option>'; });
    selBenef.innerHTML = benefOptions;
    selBenef.value = state.beneficiar;
    function markSet() { [selTip, selBenef, selStatus].forEach(function (s) { s.classList.toggle('is-set', !!s.value); }); }

    root.querySelectorAll('.rop-mode-btn').forEach(function (b) {
        b.classList.toggle('is-active', b.dataset.mode === state.mode);
        b.addEventListener('click', function () {
            if (state.mode === b.dataset.mode) { return; }
            state.mode = b.dataset.mode; state.page = 1; state.sort = ''; open = {}; selected = {};
            root.querySelectorAll('.rop-mode-btn').forEach(function (x) { x.classList.toggle('is-active', x === b); });
            var tb = document.getElementById('ropRows');
            tb.classList.add('is-morph');
            search.placeholder = state.mode === 'soferi' ? 'Caută șofer, vehicul, beneficiar...' : 'Caută vehicul, șofer, beneficiar...';
            setTimeout(function () { load(); }, 160);
        });
    });
    search.addEventListener('input', debounce(function () { state.q = search.value.trim(); state.page = 1; load(); }, 300));
    selTip.addEventListener('change', function () { state.tip = selTip.value; state.page = 1; load(); });
    selBenef.addEventListener('change', function () { state.beneficiar = selBenef.value; state.page = 1; load(); });
    selStatus.addEventListener('change', function () { state.status = selStatus.value; state.page = 1; load(); });

    // interval
    var rangeBtn = document.getElementById('ropRangeBtn'), rangePop = document.getElementById('ropRangePop');
    function rangeLabel() { document.getElementById('ropRangeLabel').textContent = dt(state.from) + ' – ' + dt(state.to); }
    rangeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var show = rangePop.hidden;
        rangePop.hidden = !show;
        rangeBtn.setAttribute('aria-expanded', String(show));
        document.getElementById('ropFrom').value = state.from;
        document.getElementById('ropTo').value = state.to;
    });
    document.addEventListener('click', function (e) { if (!rangePop.hidden && !document.getElementById('ropRange').contains(e.target)) { rangePop.hidden = true; } });
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    rangePop.addEventListener('click', function (e) {
        var p = e.target.closest('[data-preset]');
        if (!p) { return; }
        var now = new Date(), from;
        if (p.dataset.preset === 'luna') { from = new Date(now.getFullYear(), now.getMonth(), 1); }
        if (p.dataset.preset === '3luni') { from = new Date(now.getFullYear(), now.getMonth() - 2, 1); }
        if (p.dataset.preset === 'an') { from = new Date(now.getFullYear(), 0, 1); }
        if (p.dataset.preset === '12luni') { from = new Date(now.getFullYear(), now.getMonth() - 11, 1); }
        if (p.dataset.preset === 'tot') { from = new Date(now.getFullYear() - 6, 0, 1); }
        applyRange(iso(from), iso(now));
    });
    document.getElementById('ropRangeApply').addEventListener('click', function () {
        var f = document.getElementById('ropFrom').value, t = document.getElementById('ropTo').value;
        if (f && t) { applyRange(f <= t ? f : t, f <= t ? t : f); }
    });
    function applyRange(f, t) {
        state.from = f; state.to = t; state.page = 1; rangePop.hidden = true;
        Object.keys(open).forEach(function (k) { open[k].data = {}; open[k].summary = null; });
        rangeLabel(); load();
    }

    var exportBtn = document.getElementById('ropExport');
    if (exportBtn) {
        exportBtn.addEventListener('click', function () {
            location.href = u({ action: 'export', mode: state.mode, q: state.q, tip: state.tip, beneficiar: state.beneficiar, status: state.status, from: state.from, to: state.to,
                vehicle_id: state.vehicle_id, driver_id: state.driver_id });
            toast('Exportul CSV pornește (filtrele curente).');
        });
    }

    // ------------------------------------------------------------------ lista
    var head = document.getElementById('ropHead');
    var tbody = document.getElementById('ropRows');
    function sortTh(key, html, cls) {
        var cur = state.sort.replace('-', '') === key ? (state.sort.charAt(0) === '-' ? ' <i class="bi bi-arrow-down-short"></i>' : ' <i class="bi bi-arrow-up-short"></i>') : '';
        return '<th class="' + (cls || 'is-num') + '" data-sort="' + key + '">' + html + cur + '</th>';
    }
    function renderHead() {
        var v = state.mode === 'vehicule';
        head.innerHTML = '<tr><th><input type="checkbox" class="rop-check" id="ropAll" aria-label="Selectează tot"></th><th></th>'
            + sortTh('label', v ? 'Vehicul' : 'Șofer', '') + '<th>' + (v ? 'Șofer' : 'Vehicule') + '</th><th>Tip transport</th>'
            + sortTh('km', 'Km total')
            + sortTh('cost_vehicle_km', 'Cost vehicul<small>(lei/km)</small>') + sortTh('cost_driver_km', 'Cost șofer<small>(lei/km)</small>')
            + sortTh('cost_total_km', 'Cost total<small>(lei/km)</small>') + sortTh('revenue_km', 'Venit<small>(lei/km)</small>')
            + sortTh('result_km', (v ? 'Rezultat' : 'Contribuție') + '<small>(lei/km)</small>') + sortTh('coverage', 'Acoperire') + '<th>Acțiuni</th></tr>';
        var all = document.getElementById('ropAll');
        all.addEventListener('click', function (e) {
            e.stopPropagation();
            Object.keys(rowsByKey).forEach(function (k) { if (all.checked) { selected[k] = true; } else { delete selected[k]; } });
            tbody.querySelectorAll('.rop-row .rop-check').forEach(function (c) { c.checked = all.checked; });
        });
    }
    head.addEventListener('click', function (e) {
        var th = e.target.closest('th[data-sort]');
        if (!th) { return; }
        var k = th.dataset.sort;
        state.sort = state.sort === k ? '-' + k : (state.sort === '-' + k ? '' : k);
        state.page = 1; load();
    });

    function syncUrl() {
        var url = new URL(location.href);
        ['mode', 'q', 'tip', 'beneficiar', 'status', 'from', 'to', 'sort', 'vehicle_id', 'driver_id'].forEach(function (k) {
            if (state[k] && !(k === 'mode' && state[k] === 'vehicule')) { url.searchParams.set(k, state[k]); } else { url.searchParams.delete(k); }
        });
        if (state.page > 1) { url.searchParams.set('p', state.page); } else { url.searchParams.delete('p'); }
        history.replaceState(null, '', url.toString());
    }

    function load() {
        if (abort) { abort.abort(); }
        abort = new AbortController();
        markSet(); syncUrl(); renderHead();
        tbody.classList.add('is-busy');
        if (kpis) { kpis.update(null, true); }
        var keep = keepFilter; keepFilter = '';
        return get('list', { mode: state.mode, q: state.q, tip: state.tip, beneficiar: state.beneficiar, status: state.status, p: state.page, per_page: state.per_page, sort: state.sort,
            vehicle_id: state.vehicle_id, driver_id: state.driver_id, keep: keep }, abort.signal)
            .then(function (d) {
                tbody.classList.remove('is-busy', 'is-morph');
                if (!d.ok) { tbody.innerHTML = emptyRow('bi-exclamation-triangle', d.message || 'Eroare.'); if (kpis) { kpis.update(null, false); } return; }
                lastList = d;
                state.from = d.from; state.to = d.to; rangeLabel();
                applyServerFilters(d);
                renderTypes(d.optiuni.tipuri);
                if (kpis) { kpis.update(d, false); }
                renderActive(d);
                renderQuality(d);
                renderRows(d.rows);
                renderPager(d);
            })
            .catch(function (err) { if (err && err.name === 'AbortError') { return; } tbody.classList.remove('is-busy', 'is-morph'); tbody.innerHTML = emptyRow('bi-wifi-off', 'Conexiunea a eșuat. Reîncearcă.'); });
    }

    // ------------------------------------------------------------------ starea comuna a filtrelor
    // Sursa de adevar: ce a aplicat serverul (d.aplicat). O selectie incompatibila (ex. vehicul fara curse de
    // Distributie) e scoasa de server si anuntata aici; bara de sus, cardurile si tabelul se aliniaza la ea.
    var FILTER_LABELS = { tip: 'Tip transport', beneficiar: 'Beneficiar', vehicle_id: 'Vehicul', driver_id: 'Șofer' };
    function applyServerFilters(d) {
        var a = d.aplicat || {};
        state.tip = a.tip || '';
        state.beneficiar = a.beneficiar ? String(a.beneficiar) : '';
        state.vehicle_id = a.vehicle_id ? String(a.vehicle_id) : '';
        state.driver_id = a.driver_id ? String(a.driver_id) : '';
        selBenef.value = state.beneficiar;
        markSet(); syncUrl();
        if (d.anulate && d.anulate.length) {
            toast(d.anulate.map(function (k) { return FILTER_LABELS[k] || k; }).join(', ') + ': fără activitate pentru filtrele alese — selecția a fost scoasă.');
        }
    }
    function setFilters(patch, key) {
        Object.keys(patch).forEach(function (k) { state[k] = patch[k] == null ? '' : String(patch[k]); });
        if (patch.tip !== undefined) { selTip.value = state.tip; }
        if (patch.beneficiar !== undefined) { selBenef.value = state.beneficiar; }
        if (patch.status !== undefined) { selStatus.value = state.status; }
        if (patch.q !== undefined) { search.value = state.q; }
        keepFilter = key || '';
        state.page = 1;
        load();
    }
    // Cardurile KPI navigheaza intern (beneficiari → tip → vehicule / soferi); fiecare alegere schimba
    // filtrele paginii (aceeasi stare), deci tabelul, bara de sus si celelalte carduri urmeaza.
    var kpis = window.RopKpi ? window.RopKpi.mount(document.getElementById('ropKpis'), {
        endpoint: CFG.urls.base,
        onScope: function (patch, key) { setFilters(patch, key); }
    }) : null;

    // Filtrele active (toate sursele: bara de sus, carduri, cautare) — x scoate filtrul, la fel ca din locul lui.
    var activeBox = document.getElementById('ropActive');
    function renderActive(d) {
        var a = d.aplicat || {}, o = d.optiuni || {}, chips = [];
        var find = function (list, id) { return (list || []).filter(function (x) { return String(x.id) === String(id); })[0]; };
        if (a.tip) { chips.push(['tip', 'bi-truck', (o.tipuri || {})[a.tip] || a.tip]); }
        if (a.beneficiar) { chips.push(['beneficiar', 'bi-people', (o.beneficiari || {})[a.beneficiar] || ('#' + a.beneficiar)]); }
        if (a.vehicle_id) { var v = find(o.vehicule, a.vehicle_id); chips.push(['vehicle_id', 'bi-box', v ? v.label : '#' + a.vehicle_id]); }
        if (a.driver_id) { var s = find(o.soferi, a.driver_id); chips.push(['driver_id', 'bi-person', s ? s.label : '#' + a.driver_id]); }
        if (state.status) { chips.push(['status', 'bi-flag', selStatus.options[selStatus.selectedIndex] ? selStatus.options[selStatus.selectedIndex].text : state.status]); }
        if (state.q) { chips.push(['q', 'bi-search', '„' + state.q + '”']); }
        // randul ramane mereu afisat (fara filtre: „toata flota”), ca tabelul sa nu sara cand apare / dispare un filtru
        activeBox.hidden = false;
        activeBox.classList.toggle('is-empty', chips.length === 0);
        activeBox.innerHTML = '<i class="bi bi-funnel"></i><b>Filtre active:</b>' + (chips.length ? chips.map(function (c) {
            return '<span class="rop-chip"><i class="bi ' + c[1] + '"></i>' + esc(c[2]) + '<button type="button" data-chip="' + c[0] + '" aria-label="Scoate filtrul ' + esc(c[2]) + '"><i class="bi bi-x"></i></button></span>';
        }).join('') + '<button type="button" class="rop-active-reset" data-chip-all><i class="bi bi-arrow-counterclockwise"></i>Resetează toate filtrele</button>'
            : '<span class="rop-active-none">Niciun filtru · ' + (state.mode === 'soferi' ? 'toți șoferii' : 'toată flota') + '</span>');
    }
    activeBox.addEventListener('click', function (e) {
        var c = e.target.closest('[data-chip]');
        if (c) { var p = {}; p[c.dataset.chip] = ''; setFilters(p); return; }
        if (e.target.closest('[data-chip-all]')) { setFilters({ tip: '', beneficiar: '', vehicle_id: '', driver_id: '', status: '', q: '' }); }
    });

    function renderTypes(types) {
        var cur = state.tip, html = '<option value="">Tip transport</option>';
        Object.keys(types || {}).forEach(function (k) { html += '<option value="' + esc(k) + '">' + esc(types[k]) + '</option>'; });
        if (cur && !(types || {})[cur]) { html += '<option value="' + esc(cur) + '">' + esc(cur) + '</option>'; }
        selTip.innerHTML = html; selTip.value = cur; markSet();
    }

    function renderQuality(d) {
        var box = document.getElementById('ropQuality');
        // aceleasi cifre ca in cardurile KPI (d.kpi: cost eligibil = vehicul + sofer, agregat ponderat)
        var t = d.kpi || d.totals, missing = d.lipsa || [];
        var parts = [];
        if (t.km > 0) {
            parts.push('<b>' + (state.mode === 'vehicule' ? (t.unitati) + ' vehicule' : (t.unitati) + ' șoferi') + '</b> · ' + nf0.format(t.km) + ' km · venit ' + nf0.format(t.revenue)
                + ' lei · cost ' + nf0.format(t.cost) + ' lei · <b>' + (t.result < 0 ? '' : '+') + nf0.format(t.result) + ' lei</b> (' + (t.coverage !== null ? nf1.format(t.coverage) + '% acoperire' : '—') + ')');
        }
        parts.push('Venitul = tariful cursei (nefacturat încă), nu încasarea.');
        if (missing.length) {
            parts.push('<b>Cost parțial / Date incomplete:</b> ' + missing.slice(0, 6).map(function (m) { return esc(m.nume); }).join(', ') + (missing.length > 6 ? ' și încă ' + (missing.length - 6) : '') + ' fără sursă sau valoare (nu sunt tratate ca 0).');
        }
        box.innerHTML = '<i class="bi bi-info-circle"></i><div>' + parts.join(' ') + '</div>';
        box.hidden = false;
    }

    function rowHtml(r) {
        var isOpen = !!open[r.key], v = state.mode === 'vehicule';
        var typePill = r.is_semi ? '<span class="rop-pill t-semi">Semiremorcă</span>' : (r.transport ? '<span class="rop-pill t-' + esc(r.transport) + '">' + esc(r.transport_label) + '</span>' : '<span class="rop-na">—</span>');
        var warn = r.incomplet && r.incomplet.length ? ' <i class="bi bi-exclamation-triangle rop-warn" title="' + esc('Date incomplete: ' + r.incomplet.join('; ')) + '"></i>' : '';
        var person = v ? (r.driver_count > 1 ? 'bi-people' : 'bi-person') : 'bi-truck';
        return '<tr class="rop-row' + (isOpen ? ' is-open' : '') + '" data-key="' + esc(r.key) + '">'
            + '<td><input type="checkbox" class="rop-check" aria-label="Selectează"' + (selected[r.key] ? ' checked' : '') + '></td>'
            + '<td><button type="button" class="rop-exp" data-exp aria-expanded="' + isOpen + '" aria-label="Detalii"><i class="bi bi-chevron-right"></i></button></td>'
            + '<td><div class="rop-veh"><span class="rop-dot is-' + esc(r.activitate) + '" title="' + esc({ activ: 'Activ în perioadă', fara_activitate: 'Fără curse în perioadă', inactiv: 'Inactiv' }[r.activitate]) + '"></span><span class="rop-veh-text"><b>' + esc(r.label) + '</b>' + (r.is_semi ? '<small>' + esc(r.tip_label) + '</small>' : '') + '</span></div></td>'
            + '<td><div class="rop-person"><i class="bi ' + person + '"></i><span title="' + esc(r.drivers) + '">' + esc(r.drivers) + '</span></div></td>'
            + '<td>' + typePill + '</td>'
            + '<td class="is-num">' + km(r.km) + '</td>'
            + '<td class="is-num">' + n2(r.cost_vehicle_km) + '</td>'
            + '<td class="is-num">' + n2(r.cost_driver_km) + '</td>'
            + '<td class="is-num"><span class="rop-bold">' + n2(r.cost_total_km) + '</span>' + warn + '</td>'
            + '<td class="is-num">' + n2(r.revenue_km) + '</td>'
            + '<td class="is-num">' + (r.result_km === null ? '<span class="rop-na">—</span>' : '<span class="rop-res ' + resClass(r.result_km) + '">' + signed(r.result_km) + '</span>') + '</td>'
            + '<td class="is-num">' + (r.coverage === null ? '<span class="rop-na">—</span>' : '<span class="rop-cov ' + (r.coverage < 100 ? 'is-neg' : 'is-pos') + '">' + nf1.format(r.coverage) + '%</span>') + '</td>'
            + '<td><button type="button" class="rop-act" data-menu aria-label="Acțiuni"><i class="bi bi-three-dots"></i></button></td></tr>';
    }

    function renderRows(rows) {
        rowsByKey = {};
        if (!rows.length) {
            tbody.innerHTML = emptyRow('bi-inboxes', state.q || state.tip || state.beneficiar || state.status ? 'Niciun rezultat pentru filtrele alese.' : 'Nicio activitate în perioada aleasă.');
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            rowsByKey[r.key] = r;
            html += rowHtml(r);
            if (open[r.key]) { html += detailShell(r.key, true); }
        });
        tbody.innerHTML = html;
        Object.keys(open).forEach(function (k) { if (!rowsByKey[k]) { delete open[k]; } else { loadSummary(k); } });
    }

    function renderPager(d) {
        var el = document.getElementById('ropPager');
        if (d.total <= d.per_page) { el.innerHTML = '<span><b>' + d.total + '</b> ' + (state.mode === 'vehicule' ? 'vehicule' : 'șoferi') + ' · ' + dt(d.from) + ' – ' + dt(d.to) + '</span>'; return; }
        var b = '<button type="button" data-page="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>';
        for (var p = 1; p <= d.pages; p++) { b += '<button type="button" data-page="' + p + '" class="' + (p === d.page ? 'is-active' : '') + '">' + p + '</button>'; }
        b += '<button type="button" data-page="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';
        el.innerHTML = '<span>' + ((d.page - 1) * d.per_page + 1) + '–' + Math.min(d.total, d.page * d.per_page) + ' din <b>' + d.total + '</b></span><div class="rop-pager-btns">' + b + '</div>'
            + '<label>Pe pagină <select data-pp><option>10</option><option>25</option><option>50</option><option>100</option></select></label>';
        el.querySelector('[data-pp]').value = String(d.per_page);
    }
    document.getElementById('ropPager').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-page]');
        if (b && !b.disabled) { state.page = parseInt(b.dataset.page, 10); load(); }
    });
    document.getElementById('ropPager').addEventListener('change', function (e) { if (e.target.matches('[data-pp]')) { state.per_page = parseInt(e.target.value, 10); state.page = 1; load(); } });

    tbody.addEventListener('click', function (e) {
        var tr = e.target.closest('tr.rop-row');
        if (!tr) { return; }
        var key = tr.dataset.key, r = rowsByKey[key];
        if (e.target.matches('.rop-check')) { e.stopPropagation(); if (e.target.checked) { selected[key] = true; } else { delete selected[key]; } return; }
        if (e.target.closest('[data-menu]')) { e.stopPropagation(); rowMenu(e.target.closest('[data-menu]'), r); return; }
        open[key] ? collapse(key) : expand(key);
    });

    function rowMenu(anchor, r) {
        var veh = state.mode === 'vehicule';
        showMenu(anchor, [
            { icon: 'bi-arrows-angle-expand', label: open[r.key] ? 'Restrânge detaliile' : 'Deschide detaliile', run: function () { open[r.key] ? collapse(r.key) : expand(r.key); } },
            veh ? { icon: 'bi-layers', label: 'Istoric Financiar Vehicul', href: link(CFG.urls.istoric, { q: r.label }) } : null,
            veh ? { icon: 'bi-truck', label: 'Fișa vehiculului', href: link(location.pathname, { page: 'vehicule', action: 'show', id: r.id }) } : { icon: 'bi-person-vcard', label: 'Fișa șoferului', href: link(location.pathname, { page: 'soferi', action: 'show', id: r.id }) },
            { icon: 'bi-funnel', label: 'Doar acest rând', run: function () { search.value = r.label; state.q = r.label; state.page = 1; load(); } },
            '-',
            CFG.canExport ? { icon: 'bi-download', label: 'Exportă rezultatele filtrate', run: function () { exportBtn && exportBtn.click(); } } : null
        ]);
    }

    // ------------------------------------------------------------------ rand extins
    function detailShell(key, isOpen) {
        return '<tr class="rop-detail" data-detail="' + esc(key) + '"><td colspan="13"><div class="rop-collapse' + (isOpen ? ' is-open' : '') + '"><div class="rop-collapse-inner"><div class="rop-ws" data-ws="' + esc(key) + '"><span class="rop-spinner"></span> Se încarcă…</div></div></div></td></tr>';
    }
    function rowEl(k) { return tbody.querySelector('tr.rop-row[data-key="' + CSS.escape(k) + '"]'); }
    function detEl(k) { return tbody.querySelector('tr.rop-detail[data-detail="' + CSS.escape(k) + '"]'); }
    function ws(k) { return tbody.querySelector('[data-ws="' + CSS.escape(k) + '"]'); }

    function expand(key) {
        var tr = rowEl(key);
        if (!tr) { return; }
        open[key] = { tab: 'costuri', cat: null, line: null, data: {}, summary: null, sim: null, benef: null, tripsPage: 1 };
        tr.classList.add('is-open');
        tr.querySelector('[data-exp]').setAttribute('aria-expanded', 'true');
        tr.insertAdjacentHTML('afterend', detailShell(key, false));
        requestAnimationFrame(function () { requestAnimationFrame(function () { var d = detEl(key); if (d) { d.querySelector('.rop-collapse').classList.add('is-open'); } }); });
        loadSummary(key);
    }
    function collapse(key) {
        var tr = rowEl(key), d = detEl(key);
        delete open[key];
        if (tr) { tr.classList.remove('is-open'); tr.querySelector('[data-exp]').setAttribute('aria-expanded', 'false'); }
        if (d) { d.querySelector('.rop-collapse').classList.remove('is-open'); setTimeout(function () { if (!open[key]) { d.remove(); } }, 380); }
    }

    function loadSummary(key) {
        var st = open[key], r = rowsByKey[key];
        if (!st || !r) { return; }
        if (st.summary) { renderWs(key); return; }
        get(state.mode === 'vehicule' ? 'vehicle' : 'driver', { id: r.id }).then(function (d) {
            if (!open[key]) { return; }
            if (!d.ok) { ws(key).innerHTML = '<div class="rop-note is-warn">' + esc(d.message) + '</div>'; return; }
            st.summary = d;
            if (state.mode === 'vehicule' && !st.cat) {
                var first = (d.categorii || []).filter(function (c) { return c.total > 0; })[0];
                st.cat = first ? first.cod : 'combustibil';
            }
            renderWs(key);
        });
    }

    function sumItem(icon, label, value, cls) { return '<div class="rop-sum-item ' + (cls || '') + '"><i class="bi ' + icon + '"></i><div><span>' + esc(label) + '</span><b>' + value + '</b></div></div>'; }

    function renderWs(key) {
        var st = open[key], s = st.summary, r = rowsByKey[key], box = ws(key);
        if (!box || !s) { return; }
        var veh = state.mode === 'vehicule', html = '';
        if (veh) {
            var drv = s.drivers.length === 1 ? s.drivers[0] : null;
            var drvLabel = drv ? esc(drv.nume) : (s.drivers.length ? s.drivers.length + ' șoferi' : (s.is_semi ? 'Fără șofer (semiremorcă)' : 'Fără curse'));
            var drvBadge = drv ? '<span class="rop-badge' + (drv.status === 'activ' ? '' : ' is-gray') + '">' + (drv.status === 'activ' ? 'Activ' : 'Inactiv') + '</span>' : '';
            var resCls = s.result_km === null ? '' : (s.result_km < 0 ? 'is-down' : 'is-good');
            html += '<div class="rop-sum">'
                + '<div class="rop-sum-item is-ident"><i class="bi bi-truck"></i><div><b>' + esc(s.vehicle.nr) + '</b><span>' + esc(s.vehicle.model || s.vehicle.tip_label)
                + (s.semis.length ? ' · + ' + s.semis.map(function (x) { return esc(x.nr); }).join(', ') : '') + '</span></div></div>'
                + '<div class="rop-sum-item is-ident"><i class="bi ' + (s.drivers.length > 1 ? 'bi-people' : 'bi-person') + '"></i><div><b>' + drvLabel + '</b>' + drvBadge + '</div></div>'
                + '<span class="rop-sum-sep"></span>'
                + sumItem('bi-truck', 'Cost vehicul', s.cost_vehicle_km === null ? '—' : nf2.format(s.cost_vehicle_km) + ' lei/km')
                + sumItem('bi-person', 'Cost șofer', s.cost_driver_km === null ? '—' : nf2.format(s.cost_driver_km) + ' lei/km')
                + sumItem('bi-layers', 'Cost total', s.cost_total_km === null ? '—' : nf2.format(s.cost_total_km) + ' lei/km')
                + sumItem('bi-graph-up-arrow', 'Venit', s.revenue_km === null ? '—' : nf2.format(s.revenue_km) + ' lei/km', 'is-up')
                + sumItem(s.result_km !== null && s.result_km < 0 ? 'bi-arrow-down' : 'bi-arrow-up', 'Rezultat', s.result_km === null ? '—' : signed(s.result_km) + ' lei/km', resCls)
                + sumItem('bi-bullseye', 'Acoperire', s.coverage === null ? '—' : nf1.format(s.coverage) + '%', s.coverage !== null && s.coverage < 100 ? 'is-bad' : 'is-good')
                + '</div>';
        } else {
            var row = s.row || {};
            html += '<div class="rop-sum">'
                + '<div class="rop-sum-item is-ident"><i class="bi bi-person"></i><div><b>' + esc(s.driver.nume) + '</b><span class="rop-badge' + (s.driver.status === 'activ' ? '' : ' is-gray') + '">' + (s.driver.status === 'activ' ? 'Activ' : 'Inactiv') + '</span></div></div>'
                + '<div class="rop-sum-item is-ident"><i class="bi bi-truck"></i><div><b>' + (s.vehicule.length ? s.vehicule.map(function (x) { return esc(x.nr); }).join(', ') : 'Fără curse') + '</b><span>' + nf0.format(row.km || 0) + ' km · ' + (row.trips || 0) + ' curse</span></div></div>'
                + '<span class="rop-sum-sep"></span>'
                + sumItem('bi-person-badge', 'Cost personal', row.cost_driver_km === null || row.cost_driver_km === undefined ? '—' : nf2.format(row.cost_driver_km) + ' lei/km')
                + sumItem('bi-truck', 'Cost vehicul', row.cost_vehicle_km == null ? '—' : nf2.format(row.cost_vehicle_km) + ' lei/km')
                + sumItem('bi-graph-up-arrow', 'Venit', row.revenue_km == null ? '—' : nf2.format(row.revenue_km) + ' lei/km', 'is-up')
                + sumItem(row.result_km < 0 ? 'bi-arrow-down' : 'bi-arrow-up', 'Contribuție', row.result_km == null ? '—' : signed(row.result_km) + ' lei/km', row.result_km == null ? '' : (row.result_km < 0 ? 'is-down' : 'is-good'))
                + sumItem('bi-hourglass-split', 'Cost neocupat', lei(s.neocupat))
                + '</div>';
        }
        var tabs = veh ? (s.is_semi ? VEHICLE_TABS.filter(function (t) { return ['costuri', 'investitii', 'documente'].indexOf(t.key) !== -1; }) : VEHICLE_TABS) : DRIVER_TABS;
        if (!tabs.some(function (t) { return t.key === st.tab; })) { st.tab = 'costuri'; }
        html += '<div class="rop-tabs" role="tablist">' + tabs.map(function (t) {
            var label = t.key === 'costuri' ? 'Costuri (' + (veh ? (s.cost_total_km === null ? '—' : nf2.format(veh && s.is_semi ? s.cost_vehicle_km : s.cost_vehicle_km)) : (s.row && s.row.cost_driver_km != null ? nf2.format(s.row.cost_driver_km) : '—')) + ' lei/km)' : t.label;
            return '<button type="button" role="tab" class="rop-tab' + (t.key === st.tab ? ' is-active' : '') + '" data-tab="' + t.key + '"><i class="bi ' + t.icon + '"></i>' + esc(label) + '</button>';
        }).join('') + '</div><div class="rop-panel" data-panel>' + '<span class="rop-spinner"></span> Se încarcă…' + '</div>';
        if (s.incomplet && s.incomplet.length) {
            html += '<div class="rop-note is-warn"><i class="bi bi-exclamation-triangle"></i> <b>Cost parțial / Date incomplete:</b> ' + s.incomplet.map(esc).join(' · ') + '</div>';
        }
        box.innerHTML = html;
        bindWs(key, box);
        renderPanel(key);
    }

    function setPanel(key, html) {
        var box = ws(key);
        if (!box) { return null; }
        var p = box.querySelector('[data-panel]');
        p.style.animation = 'none'; p.offsetHeight; p.style.animation = '';
        p.innerHTML = html;
        return p;
    }

    function cached(key, name, action, params, cb) {
        var st = open[key];
        if (st.data[name]) { cb(st.data[name]); return; }
        setPanel(key, '<span class="rop-spinner"></span> Se încarcă…');
        get(action, params).then(function (d) {
            if (!open[key]) { return; }
            if (!d.ok) { setPanel(key, '<div class="rop-note is-warn">' + esc(d.message || 'Eroare.') + '</div>'); return; }
            st.data[name] = d; cb(d);
        });
    }

    function renderPanel(key) {
        var st = open[key], r = rowsByKey[key], veh = state.mode === 'vehicule';
        if (!st || !r) { return; }
        if (st.tab === 'costuri') { renderCosts(key); } // vehicul si sofer: acelasi arbore Categorie → Inregistrari
        if (st.tab === 'activitate') { renderTrips(key); }
        if (st.tab === 'beneficiari') { cached(key, 'benef', 'beneficiaries', { id: r.id, scope: veh ? 'vehicul' : 'sofer' }, function (d) { setPanel(key, benefHtml(key, d.rows)); }); }
        if (st.tab === 'investitii') { cached(key, 'inv', 'investments', { id: r.id }, function (d) { setPanel(key, investHtml(d.rows)); }); }
        if (st.tab === 'documente') { cached(key, 'docs', 'documents', { id: r.id }, function (d) { setPanel(key, docsHtml(d.rows)); }); }
        if (st.tab === 'soferi') { cached(key, 'sim', 'drivers', { id: r.id }, function (d) { setPanel(key, simHtml(key, d)); }); }
        if (st.tab === 'vehicule') { setPanel(key, driverVehiclesHtml(st.summary)); }
        if (st.tab === 'istoric') { setPanel(key, driverHistoryHtml(st.summary)); }
    }

    // ---- Costuri (vehicul)
    // Arbore, ca in Istoric Financiar Vehicul → Costuri: Categorie → (Element de cost, cand motorul il da: ex. Impozit,
    // RCA la Taxe) → Inregistrari. Totalul categoriei = cel din rezumatul serverului (s.categorii[].total); inregistrarile
    // se incarca la prima deschidere a categoriei (action=costs, ca inainte). Fara bara laterala de categorii.
    // Clic pe o inregistrare → fisa ei in panoul peste pagina (ca fisa cursei din Dispecer curse).
    function catData(st, cod) { return st.data['cost_' + cod]; }
    function renderCosts(key) {
        var st = open[key], s = st.summary;
        if (!st.openCats) {
            st.openCats = {}; st.openSubs = {};
            var firstCat = st.cat || ((s.categorii || []).filter(function (c) { return c.total > 0; })[0] || {}).cod;
            if (firstCat) { st.openCats[firstCat] = true; } // implicit: prima categorie cu cost, ca inainte
        }
        var filter = st.catFilter || '';
        var cats = s.categorii.filter(function (c) { return !filter || c.cod === filter; });
        var totalVeh = s.categorii.reduce(function (a, c) { return a + (c.total || 0); }, 0);
        var allLabel = s.km > 0 ? nf2.format(totalVeh / s.km) + ' lei/km' : nf2.format(totalVeh) + ' lei';
        var opts = '<option value="">Toate — ' + allLabel + '</option>'
            + s.categorii.map(function (c) { return '<option value="' + c.cod + '"' + (c.cod === filter ? ' selected' : '') + '>' + esc(c.label) + ' — ' + perKm(c) + '</option>'; }).join('');
        var head = '<div class="rop-grid-head"><div class="rop-grid-title"><h4>' + esc(filter ? cats[0].label : 'Toate costurile') + ' <small>(' + (filter ? perKm(cats[0]) : allLabel) + ')</small></h4>'
            + '<label class="rop-cat-filter"><span>Categorie:</span><select data-cat-filter aria-label="Categorie">' + opts + '</select></label></div>'
            + (CAT_ADD[filter] ? addLink(filter, true) : '') + '</div>';
        var rows = cats.map(function (c) { return catRowsHtml(key, st, s, c, totalVeh); }).join('');
        setPanel(key, '<div class="rop-costs"><div class="rop-grid-wrap">' + head
            + '<table class="rop-tree rop-tree-l0"><colgroup><col class="rop-col-chev"><col><col class="rop-col-num"><col class="rop-col-num"><col class="rop-col-num"><col class="rop-col-act"></colgroup>'
            + '<thead><tr><th></th><th>Categorie</th><th class="is-num">Total (lei)</th><th class="is-num">Lei/km</th><th class="is-num">Pondere</th><th></th></tr></thead><tbody>'
            + (rows || '<tr><td colspan="6" class="rop-empty">' + (state.mode === 'soferi' ? 'Nu există cost de personal înregistrat pentru acest șofer (fără salariu configurat sau pontaj).' : 'Nicio categorie de cost.') + '</td></tr>')
            + '</tbody></table></div>' + (state.mode === 'soferi' ? driverAllocHtml(s) : '') + '</div>');
        // categoriile deschise (implicit sau din starea pastrata) isi aduc inregistrarile
        cats.forEach(function (c) { if (st.openCats[c.cod]) { loadCat(key, c.cod); } });
    }
    function perKm(c) { return c.per_km === null ? nf2.format(c.total) + ' lei' : nf2.format(c.per_km) + ' lei/km'; }
    function addLink(cod, labelled) {
        var a = CAT_ADD[cod];
        if (!a) { return ''; }
        return labelled ? '<a class="rop-link-btn" href="' + esc(CFG.urls[a[1]]) + '" target="_blank" rel="noopener"><i class="bi bi-plus-lg"></i>' + esc(a[0]) + '</a>'
            : '<a class="rop-dots" href="' + esc(CFG.urls[a[1]]) + '" target="_blank" rel="noopener" title="' + esc(a[0]) + '" aria-label="' + esc(a[0]) + '"><i class="bi bi-plus-lg"></i></a>';
    }
    function catRowsHtml(key, st, s, c, totalVeh) {
        var isOpen = !!st.openCats[c.cod];
        var region = 'rop-cat-' + key + '-' + c.cod;
        return '<tr class="rop-node is-root' + (isOpen ? ' is-open' : '') + (c.total ? '' : ' is-empty') + '" data-node="' + c.cod + '"'
            + (c.lipsa.length ? ' title="' + esc('Fără sursă (nu sunt tratate ca 0): ' + c.lipsa.join(', ')) + '"' : '') + '>'
            + '<td class="is-c"><button type="button" class="rop-chev" data-node-toggle="' + c.cod + '" aria-expanded="' + isOpen + '" aria-controls="' + esc(region) + '" aria-label="' + (isOpen ? 'Restrânge ' : 'Extinde ') + esc(c.label) + '"><i class="bi bi-chevron-right"></i></button></td>'
            + '<td class="rop-node-name"><i class="bi ' + (CAT_ICONS[c.cod] || 'bi-tag') + ' c-' + c.cod + '"></i>' + esc(c.label)
            + (c.lipsa.length ? ' <i class="bi bi-exclamation-triangle rop-warn-ico"></i>' : '') + '</td>'
            + '<td class="is-num rop-node-total">' + nf2.format(c.total) + '</td>'
            + '<td class="is-num">' + (c.per_km === null ? '<span class="rop-na">—</span>' : nf3.format(c.per_km)) + '</td>'
            + '<td class="is-num">' + (totalVeh > 0 ? nf1.format(c.total / totalVeh * 100) + '%' : '<span class="rop-na">—</span>') + '</td>'
            + '<td class="is-c">' + addLink(c.cod, false) + '</td></tr>'
            + '<tr class="rop-node-nest is-root"><td colspan="6"><div class="rop-collapse' + (isOpen ? ' is-open' : '') + '" id="' + esc(region) + '" role="region" aria-label="' + esc(c.label) + '">'
            + '<div class="rop-collapse-inner"><div class="rop-nest" data-cat-body="' + c.cod + '">' + catBodyHtml(st, s, c) + '</div></div></div></td></tr>';
    }
    function loadCat(key, cod) {
        var st = open[key];
        if (!st || catData(st, cod) || st.loading && st.loading[cod]) { return; }
        st.loading = st.loading || {};
        st.loading[cod] = true;
        get(state.mode === 'soferi' ? 'driver_costs' : 'costs', { id: rowsByKey[key].id, cat: cod }).then(function (d) {
            if (!open[key]) { return; }
            st.loading[cod] = false;
            st.data['cost_' + cod] = d.ok ? d : { rows: [], total: 0, error: d.message || 'Eroare.' };
            refreshCat(key, cod);
        });
    }
    function refreshCat(key, cod) {
        var st = open[key], box = ws(key);
        var body = box && box.querySelector('[data-cat-body="' + cod + '"]');
        var c = st && st.summary.categorii.filter(function (x) { return x.cod === cod; })[0];
        if (!body || !c) { return; }
        body.innerHTML = catBodyHtml(st, st.summary, c);
        body.style.animation = 'none'; body.offsetHeight; body.style.animation = 'rop-morph .28s ease';
    }
    // Continutul unei categorii: grupat pe elementul de cost (detalii.Element) cand exista, altfel direct inregistrarile.
    function catBodyHtml(st, s, c) {
        var d = catData(st, c.cod);
        var note = c.lipsa.length ? '<div class="rop-note is-warn rop-nest-note"><i class="bi bi-exclamation-triangle"></i> Elemente fără sursă în această categorie (nu sunt tratate ca 0): ' + c.lipsa.map(esc).join(', ') + '</div>' : '';
        if (!d) { return '<div class="rop-empty rop-nest-load"><span class="rop-spinner"></span> Se încarcă înregistrările…</div>'; }
        if (d.error || !d.rows.length) { return txGridHtml(st, s, c.cod, d, null) + note; }
        var groups = [], byEl = {};
        d.rows.forEach(function (l, i) {
            var el = l.detalii && l.detalii.Element ? String(l.detalii.Element) : '';
            if (!byEl[el]) { byEl[el] = { el: el, idx: [], total: 0 }; groups.push(byEl[el]); }
            byEl[el].idx.push(i); byEl[el].total += l.suma;
        });
        if (groups.length === 1 && groups[0].el === '') { return txGridHtml(st, s, c.cod, d, null) + note; }
        groups.sort(function (a, b) { return (a.el === '') - (b.el === '') || b.total - a.total; });
        var km = s.km;
        var rows = groups.map(function (g) {
            var sub = c.cod + '|' + g.el, isOpen = !!st.openSubs[sub];
            var region = 'rop-sub-' + c.cod + '-' + groups.indexOf(g);
            return '<tr class="rop-node is-sub' + (isOpen ? ' is-open' : '') + '" data-node="' + esc(sub) + '">'
                + '<td class="is-c"><button type="button" class="rop-chev" data-node-toggle="' + esc(sub) + '" aria-expanded="' + isOpen + '" aria-controls="' + esc(region) + '" aria-label="' + (isOpen ? 'Restrânge ' : 'Extinde ') + esc(g.el || 'Alte înregistrări') + '"><i class="bi bi-chevron-right"></i></button></td>'
                + '<td class="rop-node-name">' + esc(g.el || 'Alte înregistrări') + ' <span class="rop-na rop-node-count">· ' + g.idx.length + '</span></td>'
                + '<td class="is-num">' + nf2.format(g.total) + '</td>'
                + '<td class="is-num">' + (km > 0 ? nf3.format(g.total / km) : '<span class="rop-na">—</span>') + '</td>'
                + '<td class="is-num">' + (c.total > 0 ? nf1.format(g.total / c.total * 100) + '%' : '<span class="rop-na">—</span>') + '</td><td></td></tr>'
                + '<tr class="rop-node-nest"><td colspan="6"><div class="rop-collapse' + (isOpen ? ' is-open' : '') + '" id="' + esc(region) + '" role="region"><div class="rop-collapse-inner"><div class="rop-nest">'
                + txGridHtml(st, s, c.cod, d, g.idx) + '</div></div></div></td></tr>';
        }).join('');
        return '<table class="rop-tree rop-tree-sub"><colgroup><col class="rop-col-chev"><col><col class="rop-col-num"><col class="rop-col-num"><col class="rop-col-num"><col class="rop-col-act"></colgroup><tbody>'
            + rows + '</tbody></table>' + note;
    }

    function docCell(l) {
        if (l.link === 'mentenanta') {
            var label = l.document || ('Fișa #' + l.source_id);
            return l.are_fisier ? '<a class="rop-doc" href="' + esc(link(CFG.urls.mentenantaDoc, { id: l.source_id })) + '" target="_blank" rel="noopener"><span>' + esc(label) + '</span><i class="bi bi-box-arrow-up-right"></i></a>'
                : '<a class="rop-doc' + (l.document ? '' : ' is-none') + '" href="' + esc(CFG.urls.reparatii) + '" target="_blank" rel="noopener" title="' + (l.document ? 'Factura din Reparații (fără fișier atașat)' : 'Fără document — deschide Reparații') + '"><span>' + esc(label) + '</span><i class="bi bi-box-arrow-up-right"></i></a>';
        }
        if (l.link === 'carburanti') { return '<a class="rop-doc" href="' + esc(CFG.urls.carburanti) + '" target="_blank" rel="noopener"><span>CardOil #' + l.source_id + '</span><i class="bi bi-box-arrow-up-right"></i></a>'; }
        if (l.link === 'contabilitate_personal') { return '<a class="rop-doc" href="' + esc(CFG.urls.contabilitate) + '" target="_blank" rel="noopener"><span>Personal</span><i class="bi bi-box-arrow-up-right"></i></a>'; }
        if (l.link === 'documente') { return '<a class="rop-doc" href="' + esc(CFG.urls.documente) + '" target="_blank" rel="noopener"><span>Documente</span><i class="bi bi-box-arrow-up-right"></i></a>'; }
        return '<span class="rop-doc is-none"><span>Calcul</span></span>';
    }

    // Grila de inregistrari (nivelul de jos): aceleasi coloane ca inainte. idx = indicii randurilor din d.rows (null = toate).
    function txGridHtml(st, s, cod, d, idx) {
        var kmBase = s.km;
        var cols = '<colgroup><col style="width:10%"><col style="width:19%"><col style="width:16%"><col style="width:9%"><col style="width:9%"><col style="width:9.5%"><col style="width:7%"><col style="width:17.5%"><col style="width:4%"></colgroup>';
        var headRow = '<thead><tr><th>Data</th><th>Descriere</th><th>Furnizor / sursă</th><th>Pe</th><th class="is-num">Cost (lei)</th><th class="is-num">Km la interv.</th><th class="is-num">Cost/km</th><th>Document</th><th></th></tr></thead>';
        if (d.error) { return '<table class="rop-grid">' + cols + headRow + '<tbody><tr><td colspan="9" class="rop-empty">' + esc(d.error) + '</td></tr></tbody></table>'; }
        var list = idx || d.rows.map(function (_, i) { return i; });
        if (!list.length) { return '<table class="rop-grid">' + cols + headRow + '<tbody><tr><td colspan="9" class="rop-empty">Nicio înregistrare în această categorie pentru perioada aleasă.</td></tr></tbody></table>'; }
        var body = list.map(function (i) {
            var l = d.rows[i];
            var ref = cod + ':' + i;
            return '<tr class="rop-grid-row' + (st.line === ref ? ' is-open' : '') + '" data-line="' + esc(ref) + '"><td>' + dt(l.data) + '</td><td title="' + esc(l.descriere) + '">' + esc(l.descriere) + (l.estimat ? '<span class="rop-est">estimat</span>' : '') + '</td>'
                + '<td title="' + esc(l.sursa) + '">' + esc(l.sursa) + '</td><td title="' + esc(l.pe) + '">' + esc(l.pe) + '</td>'
                + '<td class="is-num">' + nf2.format(l.suma) + '</td><td class="is-num">' + (l.km ? nf0.format(l.km) : '<span class="rop-na">—</span>') + '</td>'
                + '<td class="is-num">' + (kmBase > 0 ? nf3.format(l.suma / kmBase) : '—') + '</td><td>' + docCell(l) + '</td>'
                + '<td class="is-c"><button type="button" class="rop-dots" data-line-menu="' + esc(ref) + '" aria-label="Acțiuni"><i class="bi bi-three-dots"></i></button></td></tr>';
        }).join('');
        return '<table class="rop-grid">' + cols + headRow + '<tbody data-cost-rows>' + body + '</tbody></table>';
    }

    // Expandare pe loc (tranzitia de inaltime ruleaza); categoria isi aduce inregistrarile la prima deschidere.
    function toggleNode(key, nodeId, openIt) {
        var st = open[key], box = ws(key);
        if (!st || !box) { return; }
        var isCat = nodeId.indexOf('|') === -1;
        (isCat ? st.openCats : st.openSubs)[nodeId] = openIt;
        var row = box.querySelector('tr.rop-node[data-node="' + CSS.escape(nodeId) + '"]');
        if (!row) { return; }
        row.classList.toggle('is-open', openIt);
        var btn = row.querySelector('[data-node-toggle]');
        btn.setAttribute('aria-expanded', String(openIt));
        btn.setAttribute('aria-label', (openIt ? 'Restrânge ' : 'Extinde ') + row.querySelector('.rop-node-name').textContent.replace(/·.*$/, '').trim());
        var nest = row.nextElementSibling;
        if (nest) { nest.querySelector('.rop-collapse').classList.toggle('is-open', openIt); }
        if (isCat && openIt) { loadCat(key, nodeId); }
    }

    // Fisa inregistrarii (server: action=line_view, aceeasi recalculare ca action=costs) in panoul Dispecer curse.
    function lineSheetUrl(key, ref) {
        var p = ref.split(':');
        var q = { id: rowsByKey[key].id, cat: p[0], i: p[1], from: state.from, to: state.to };
        if (state.mode === 'soferi') { q.scope = 'sofer'; }
        return link(CFG.urls.lineView, q);
    }
    function openLineSheet(key, ref, rowEl, newTab) {
        var href = lineSheetUrl(key, ref);
        if (newTab) { window.open(href, '_blank', 'noopener'); return; }
        var st = open[key], box = ws(key);
        if (st) { st.line = ref; }
        if (box) { box.querySelectorAll('tr.rop-grid-row.is-open').forEach(function (r) { r.classList.remove('is-open'); }); }
        if (rowEl) { rowEl.classList.add('is-open'); }
        if (window.RaceViewOverlay && typeof window.RaceViewOverlay.openUrl === 'function') { window.RaceViewOverlay.openUrl(href, rowEl); }
        else { window.location.href = href; }
    }

    // ---- Costuri (sofer): sub arbore ramane alocarea costului pe vehicule (dupa km-ii reali ai soferului)
    function driverAllocHtml(s) {
        var row = s.row || {};
        return '<div class="rop-box rop-alloc"><h5>Alocare pe vehicule<a href="' + esc(CFG.urls.contabilitate) + '" target="_blank" rel="noopener">Contabilitate personal ↗</a></h5>'
            + '<table class="rop-list"><thead><tr><th>Vehicul</th><th class="is-num">Km</th><th class="is-num">Curse</th><th class="is-num">Cost vehicul/km</th></tr></thead><tbody>'
            + (s.vehicule.map(function (v) { return '<tr><td>' + esc(v.nr) + '</td><td class="is-num">' + nf0.format(v.km) + '</td><td class="is-num">' + v.trips + '</td><td class="is-num">' + (v.cost_vehicle_km === null ? '—' : nf2.format(v.cost_vehicle_km)) + '</td></tr>'; }).join('') || '<tr><td colspan="4" class="rop-empty">Fără curse în perioadă.</td></tr>')
            + '</tbody></table><div class="rop-note">Costul șoferului se împarte vehiculelor după km-ii lui reali pe fiecare; lunile fără km rămân „cost neocupat” (' + lei(s.neocupat) + ').</div>'
            + (row.incomplet && row.incomplet.length ? '<div class="rop-note is-warn">' + row.incomplet.map(esc).join(' · ') + '</div>' : '') + '</div>';
    }
    function driverVehiclesHtml(s) {
        return '<table class="rop-list"><thead><tr><th>Vehicul</th><th class="is-num">Km</th><th class="is-num">Curse</th><th class="is-num">Venit (lei)</th><th class="is-num">Venit/km</th><th class="is-num">Cost vehicul/km</th></tr></thead><tbody>'
            + (s.vehicule.map(function (v) { return '<tr><td>' + esc(v.nr) + '</td><td class="is-num">' + nf0.format(v.km) + '</td><td class="is-num">' + v.trips + '</td><td class="is-num">' + nf2.format(v.revenue) + '</td><td class="is-num">' + (v.km > 0 ? nf2.format(v.revenue / v.km) : '—') + '</td><td class="is-num">' + (v.cost_vehicle_km === null ? '—' : nf2.format(v.cost_vehicle_km)) + '</td></tr>'; }).join('')
                || '<tr><td colspan="6" class="rop-empty">Fără curse în perioadă.</td></tr>') + '</tbody></table>';
    }
    function driverHistoryHtml(s) {
        return '<table class="rop-list"><thead><tr><th>Luna</th><th class="is-num">Curse</th><th class="is-num">Km</th><th class="is-num">Venit (lei)</th><th class="is-num">Cost personal (lei)</th><th>Sursa costului</th></tr></thead><tbody>'
            + (s.luni.map(function (m) { return '<tr><td>' + esc(m.month) + '</td><td class="is-num">' + m.trips + '</td><td class="is-num">' + nf0.format(m.km) + '</td><td class="is-num">' + nf2.format(m.revenue) + '</td><td class="is-num">' + nf2.format(m.cost) + '</td><td>' + esc({ personal_luna: 'Pontaj finalizat', salariu_configurat: 'Salariu configurat (estimat)', lipsa: 'Lipsă' }[m.sursa] || m.sursa) + '</td></tr>'; }).join('')
                || '<tr><td colspan="6" class="rop-empty">Fără activitate în perioadă.</td></tr>') + '</tbody></table>';
    }

    // ---- Activitate (curse)
    function renderTrips(key) {
        var st = open[key], r = rowsByKey[key];
        var name = 'trips_' + st.tripsPage;
        cached(key, name, 'trips', { id: r.id, scope: state.mode === 'vehicule' ? 'vehicul' : 'sofer', p: st.tripsPage }, function (d) { setPanel(key, tripsTable(d.rows, true) + subPager(d, 'trips')); });
    }
    function tripsTable(rows, withLink) {
        if (!rows.length) { return '<div class="rop-note">Nicio cursă în perioada aleasă.</div>'; }
        return '<table class="rop-list"><thead><tr><th>Cursa</th><th>Data</th><th>Șofer</th><th>Vehicul / ansamblu</th><th>Tip</th><th>Beneficiar</th><th class="is-num">Km</th><th class="is-num">Tone</th><th class="is-num">Durată</th><th class="is-num">Venit</th><th class="is-num">Cost atribuit</th><th class="is-num">Rezultat</th></tr></thead><tbody>'
            + rows.map(function (t) {
                return '<tr><td>' + (withLink ? '<a href="' + esc(link(CFG.urls.trip, { id: t.cursa_id })) + '" target="_blank" rel="noopener">#' + t.cursa_id + ' ↗</a>' : '#' + t.cursa_id) + '</td><td>' + dt(t.data) + '</td><td>' + esc(t.sofer) + '</td><td>' + esc(t.vehicul) + '</td>'
                    + '<td><span class="rop-pill t-' + esc(t.tip_cod) + '" style="height:20px;font-size:12px;padding:0 9px">' + esc(t.tip) + '</span></td><td>' + esc(t.beneficiar) + '</td>'
                    + '<td class="is-num">' + nf0.format(t.km) + (t.km_facturat !== t.km ? ' <span class="rop-na" title="km facturabili">(' + nf0.format(t.km_facturat) + ')</span>' : '') + '</td>'
                    + '<td class="is-num">' + (t.tone ? nf2.format(t.tone) : '—') + '</td><td class="is-num">' + (t.durata ? Math.floor(t.durata / 60) + 'h ' + (t.durata % 60) + 'm' : '—') + '</td>'
                    + '<td class="is-num">' + nf2.format(t.venit) + '</td><td class="is-num">' + (t.cost === null ? '—' : nf2.format(t.cost)) + '</td>'
                    + '<td class="is-num ' + (t.rezultat === null ? '' : (t.rezultat < 0 ? 'rop-neg' : 'rop-pos')) + '">' + (t.rezultat === null ? '—' : signed(t.rezultat)) + '</td></tr>';
            }).join('') + '</tbody></table><div class="rop-note">Venit = tariful cursei (status „' + esc(rows[0].status_facturare) + '”), nu încasarea. Cost atribuit = cost total/km al vehiculului în perioadă × km reali ai cursei; între paranteze, km facturabili.</div>';
    }
    function subPager(d, kind) {
        if (d.pages <= 1) { return ''; }
        var b = '';
        for (var p = 1; p <= d.pages && p <= 30; p++) { b += '<button type="button" data-sub="' + kind + '" data-p="' + p + '" class="' + (p === d.page ? 'is-active' : '') + '">' + p + '</button>'; }
        return '<div class="rop-subpager">' + b + '</div>';
    }

    // ---- Beneficiari
    function benefHtml(key, rows) {
        var st = open[key];
        if (!rows.length) { return '<div class="rop-note">Nicio cursă în perioada aleasă.</div>'; }
        var body = rows.map(function (b) {
            var isSel = st.benef === b.beneficiar_id;
            var html = '<tr class="is-click' + (isSel ? ' is-sel' : '') + '" data-benef="' + b.beneficiar_id + '"><td><i class="bi ' + (isSel ? 'bi-chevron-down' : 'bi-chevron-right') + '"></i> ' + esc(b.nume) + '</td><td class="is-num">' + b.trips + '</td><td class="is-num">' + nf0.format(b.km) + '</td>'
                + '<td class="is-num">' + nf2.format(b.revenue) + '</td><td class="is-num">' + n2(b.cost_km) + '</td><td class="is-num">' + n2(b.revenue_km) + '</td>'
                + '<td class="is-num"><span class="rop-res ' + resClass(b.result_km) + '" style="height:24px;min-width:60px;font-size:13.5px">' + signed(b.result_km) + '</span></td><td class="is-num ' + (b.result < 0 ? 'rop-neg' : 'rop-pos') + '">' + signed(b.result) + '</td></tr>';
            if (isSel) { html += '<tr class="is-sub"><td colspan="8" data-benef-trips="' + b.beneficiar_id + '"><span class="rop-spinner"></span> Se încarcă cursele…</td></tr>'; }
            return html;
        }).join('');
        return '<table class="rop-list"><thead><tr><th>Beneficiar</th><th class="is-num">Curse</th><th class="is-num">Km</th><th class="is-num">Venit (lei)</th><th class="is-num">Cost/km</th><th class="is-num">Venit/km</th><th class="is-num">Rezultat/km</th><th class="is-num">Rezultat total (lei)</th></tr></thead><tbody>' + body + '</tbody></table>'
            + '<div class="rop-note">Venitul respectă regula de tarifare a fiecărei curse (Primar km / tone, Distribuție, Primar + Distribuție, Compresor) și e raportat la km reali. Costul beneficiarului = cost/km al vehiculului × km-ii lui.</div>';
    }

    // ---- Investitii
    function investHtml(rows) {
        return rows.map(function (v) {
            var s = v.sumar, val = v.valoare || {};
            var done = (v.stadii || []).filter(function (x) { return x.state === 'done'; }).length;
            var head = '<h5>' + (v.semiremorca ? 'Semiremorcă ' : '') + esc(v.nr) + (v.dosar ? ' · dosar ' + esc(v.dosar.cod) : '') + '<a href="' + esc(link(CFG.urls.istoric, { q: v.nr })) + '" target="_blank" rel="noopener">Deschide în Istoric Financiar ↗</a></h5>';
            var acq = v.dosar ? '<table class="rop-list"><tbody>'
                + '<tr><td>Valoare de achiziție (preț + costuri aducere)</td><td class="is-num"><b>' + lei(val.cost_initial) + '</b></td></tr>'
                + '<tr><td>Capitalizat la achiziție (confirmat)</td><td class="is-num">' + lei(s ? s.cost_initial_capitalizabil : null) + '</td></tr>'
                + '<tr><td>Tratament contabil neconfirmat</td><td class="is-num">' + lei(s ? s.tratament_neconfirmat : null) + '</td></tr>'
                + '<tr><td>Stadiu dosar</td><td class="is-num">' + done + ' / 7 etape' + (s && s.data_punere_in_functiune ? ' · în exploatare din ' + dt(s.data_punere_in_functiune) : ' · nepus în exploatare') + '</td></tr>'
                + '</tbody></table>' : '<div class="rop-note">Fără dosar de achiziție — valoarea de intrare nu este documentată.</div>';
            var proj = v.proiecte.length ? '<table class="rop-list" style="margin-top:8px"><thead><tr><th>Proiect</th><th>Categorie</th><th>Tratament</th><th class="is-num">Buget</th><th class="is-num">Cost real</th><th>Stare</th></tr></thead><tbody>'
                + v.proiecte.map(function (p) {
                    return '<tr><td>' + esc(p.denumire) + '</td><td>' + esc(p.categorie) + '</td><td>' + esc({ capitalizabil: 'Capitalizabil', cheltuiala: 'Cheltuială', de_confirmat: 'De confirmat' }[p.tratament]) + '</td>'
                        + '<td class="is-num">' + lei(p.buget) + '</td><td class="is-num">' + lei(p.cost_real) + '</td><td>' + (p.pus_in_functiune ? 'Pus în funcțiune' : 'În lucru') + ' · ' + (p.apt_legal ? '<span class="rop-badge">Apt GPL</span>' : '<span class="rop-badge is-amber">Neaprobat</span>') + '</td></tr>'
                        + (p.surse.length ? '<tr class="is-sub"><td colspan="6">Surse: ' + p.surse.map(function (x) { return esc(x.sursa) + ' #' + x.sursa_id + ' (' + nf2.format(x.suma) + ' lei)'; }).join(' · ') + '</td></tr>' : '');
                }).join('') + '</tbody></table>' : '';
            var cap = v.capital_mentenanta.length ? '<div class="rop-note">Investiții capitalizate din Reparații: ' + v.capital_mentenanta.map(function (x) { return dt(x.data) + ' ' + esc(x.descriere) + ' — ' + nf2.format(x.suma) + ' lei'; }).join(' · ') + ' (excluse din costul operațional).</div>' : '';
            var dep = '<div class="rop-note is-warn"><i class="bi bi-info-circle"></i> Amortizare: ' + (val.motiv_valoare_actuala ? esc(val.motiv_valoare_actuala) : 'nu există informații verificate.') + ' Nu intră în costul/km până la aprobare.</div>';
            return '<div class="rop-box" style="margin-bottom:10px">' + head + acq + proj + cap + dep + '</div>';
        }).join('');
    }

    // ---- Documente
    function docsHtml(rows) {
        var STARE = { valabil: ['Valabil', ''], expira_curand: ['Expiră curând', ' is-amber'], expirat: ['Expirat', ' is-red'], fara_expirare: ['Fără expirare', ' is-gray'] };
        return rows.map(function (v) {
            return '<div class="rop-box" style="margin-bottom:10px"><h5>' + (v.semiremorca ? 'Semiremorcă ' : '') + esc(v.nr) + '<a href="' + esc(CFG.urls.documente) + '" target="_blank" rel="noopener">Pagina Documente ↗</a></h5>'
                + (v.documente.length ? '<table class="rop-list"><thead><tr><th>Tip</th><th>Număr</th><th>Expiră</th><th>Stare</th><th>Fișier</th></tr></thead><tbody>' + v.documente.map(function (d) {
                    var s = STARE[d.stare] || [d.stare, ''];
                    return '<tr><td>' + esc(d.tip) + '</td><td>' + esc(d.numar) + '</td><td>' + dt(d.expira) + '</td><td><span class="rop-badge' + s[1] + '">' + s[0] + '</span></td><td>' + (d.url ? '<a href="' + esc(d.url) + '" target="_blank" rel="noopener">Deschide ↗</a>' : '<span class="rop-na">—</span>') + '</td></tr>';
                }).join('') + '</tbody></table>' : '<div class="rop-note">Niciun document înregistrat.</div>')
                + (v.lipsa.length ? '<div class="rop-note is-warn">Documente obligatorii lipsă (configurare): ' + v.lipsa.map(esc).join(', ') + '</div>' : '') + '</div>';
        }).join('');
    }

    // ---- Soferi / simulare
    function simHtml(key, d) {
        var st = open[key];
        st.simData = d;
        if (st.simRevenue === undefined) { st.simRevenue = d.revenue_km; }
        var sel = d.rows.filter(function (x) { return x.driver_id === st.sim; })[0] || null;
        var drvKm = sel ? sel.sim_driver_km : null;
        var combined = d.vehicle_cost_km !== null && drvKm !== null ? d.vehicle_cost_km + drvKm : null;
        var projected = combined !== null && st.simRevenue !== null ? st.simRevenue - combined : null;
        var cards = '<div class="rop-sim">'
            + '<div><span>Cost vehicul / km <span class="rop-tag is-hist">istoric</span></span><b>' + n2(d.vehicle_cost_km) + '</b></div>'
            + '<div class="' + (sel ? 'is-preview' : '') + '"><span>Cost șofer / km <span class="rop-tag is-sim">simulare</span></span><b>' + (sel ? n2(drvKm) + ' <small style="font-size:12px;font-weight:600">' + esc(sel.nume) + '</small>' : '<span class="rop-na" style="font-size:13px">alegeți un șofer</span>') + '</b></div>'
            + '<div><span>Venit / km (ipoteză, editabil)</span><input type="number" step="0.01" data-sim-rev value="' + (st.simRevenue === null ? '' : Number(st.simRevenue).toFixed(2)) + '"></div>'
            + '<div class="' + (sel ? 'is-preview' : '') + '"><span>Rezultat proiectat / km</span><b class="' + (projected === null ? '' : (projected < 0 ? 'rop-neg' : 'rop-pos')) + '">' + (projected === null ? '—' : signed(projected)) + '</b></div></div>';
        var rows = d.rows.map(function (x) {
            var comb = d.vehicle_cost_km !== null && x.sim_driver_km !== null ? d.vehicle_cost_km + x.sim_driver_km : null;
            var proj = comb !== null && st.simRevenue !== null ? st.simRevenue - comb : null;
            return '<tr class="is-click' + (st.sim === x.driver_id ? ' is-sel' : '') + '" data-sim="' + x.driver_id + '"><td>' + esc(x.nume) + (x.status !== 'activ' ? ' <span class="rop-badge is-gray">inactiv</span>' : '') + '</td>'
                + '<td>' + esc(x.eligibilitate) + (x.documente_expirate ? ' <span class="rop-badge is-red">' + x.documente_expirate + ' doc. expirate</span>' : '') + '</td>'
                + '<td class="is-num">' + (x.istoric ? n2(x.istoric.cost_km) + ' <span class="rop-tag is-hist">ist.</span>' : '<span class="rop-na">—</span>') + '</td>'
                + '<td class="is-num">' + (x.sim_driver_km === null ? '<span class="rop-na">' + esc(x.cost_sursa) + '</span>' : n2(x.sim_driver_km) + ' <span class="rop-tag is-sim">sim.</span>') + '</td>'
                + '<td class="is-num">' + n2(comb) + '</td><td class="is-num ' + (proj === null ? '' : (proj < 0 ? 'rop-neg' : 'rop-pos')) + '">' + (proj === null ? '—' : signed(proj)) + '</td></tr>';
        }).join('');
        return cards + '<table class="rop-list"><thead><tr><th>Șofer</th><th>Eligibilitate (date existente)</th><th class="is-num">Cost șofer/km istoric</th><th class="is-num">Cost șofer/km simulat</th><th class="is-num">Vehicul + șofer/km</th><th class="is-num">Rezultat proiectat/km</th></tr></thead><tbody>' + rows + '</tbody></table>'
            + '<div class="rop-note"><i class="bi bi-shield-check"></i> ' + esc(d.nota) + ' Schimbarea reală a asignării se face în <a href="' + esc(CFG.urls.soferi) + '" target="_blank" rel="noopener">pagina Șoferi</a>, cu fluxul ei de aprobare. Km lunari ai vehiculului: ' + nf0.format(d.km_luna) + '.</div>';
    }

    // ------------------------------------------------------------------ evenimente in zona extinsa
    function bindWs(key, box) {
        if (box.dataset.bound) { return; }
        box.dataset.bound = '1';
        box.addEventListener('click', function (e) {
            var st = open[key];
            if (!st) { return; }
            var t;
            if ((t = e.target.closest('[data-tab]'))) {
                st.tab = t.dataset.tab;
                box.querySelectorAll('.rop-tab').forEach(function (b) { b.classList.toggle('is-active', b === t); });
                renderPanel(key); return;
            }
            if ((t = e.target.closest('[data-line-menu]'))) {
                e.stopPropagation();
                var ref = t.dataset.lineMenu, parts = ref.split(':'), l = (catData(st, parts[0]) || { rows: [] }).rows[Number(parts[1])];
                var rowOf = t.closest('tr.rop-grid-row');
                showMenu(t, [{ icon: 'bi-window-stack', label: 'Fișa înregistrării', run: function () { openLineSheet(key, ref, rowOf, false); } },
                    l && l.link === 'mentenanta' ? { icon: 'bi-wrench', label: 'Deschide în Reparații', href: CFG.urls.reparatii } : null,
                    l && l.link === 'carburanti' ? { icon: 'bi-fuel-pump', label: 'Deschide în Carburanți', href: CFG.urls.carburanti } : null,
                    l && l.link === 'contabilitate_personal' ? { icon: 'bi-person-badge', label: 'Contabilitate personal', href: CFG.urls.contabilitate } : null,
                    { icon: 'bi-sliders', label: 'Configurarea elementelor de cost', href: CFG.urls.costKm }]);
                return;
            }
            if (e.target.closest('a, select, label')) { return; }
            if ((t = e.target.closest('tr.rop-grid-row'))) {
                if (window.getSelection && String(window.getSelection()).trim() !== '') { return; } // selectie de text, nu clic
                openLineSheet(key, t.dataset.line, t, e.ctrlKey || e.metaKey); return;
            }
            if ((t = e.target.closest('[data-node-toggle]')) || (t = e.target.closest('tr.rop-node'))) {
                var nodeRow = t.closest('tr.rop-node');
                toggleNode(key, nodeRow.dataset.node, !nodeRow.classList.contains('is-open'));
                return;
            }
            if ((t = e.target.closest('[data-benef]'))) {
                var b = Number(t.dataset.benef);
                st.benef = st.benef === b ? null : b;
                setPanel(key, benefHtml(key, st.data.benef.rows));
                if (st.benef !== null) {
                    get('trips', { id: rowsByKey[key].id, scope: state.mode === 'vehicule' ? 'vehicul' : 'sofer', beneficiar: b }).then(function (d) {
                        var cell = ws(key) && ws(key).querySelector('[data-benef-trips="' + b + '"]');
                        if (cell) { cell.innerHTML = d.ok ? tripsTable(d.rows, true) + (d.total > d.rows.length ? '<div class="rop-note">Primele ' + d.rows.length + ' din ' + d.total + ' curse — toate în tab-ul Activitate.</div>' : '') : esc(d.message); }
                    });
                }
                return;
            }
            if ((t = e.target.closest('[data-sim]'))) { var id = Number(t.dataset.sim); st.sim = st.sim === id ? null : id; setPanel(key, simHtml(key, st.simData)); return; }
            if ((t = e.target.closest('[data-sub="trips"]'))) { st.tripsPage = Number(t.dataset.p); renderTrips(key); return; }
        });
        box.addEventListener('change', function (e) {
            var st = open[key];
            if (!st || !e.target.matches('[data-cat-filter]')) { return; }
            st.catFilter = e.target.value;
            if (st.catFilter) { st.openCats[st.catFilter] = true; } // filtrul deschide categoria aleasa
            renderCosts(key);
        });
        // Tastatura pe controalele de expandare: → extinde, ← restrange (Enter / Spatiu = butonul nativ).
        box.addEventListener('keydown', function (e) {
            var btn = e.target.closest && e.target.closest('[data-node-toggle]');
            if (!btn || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) { return; }
            var want = e.key === 'ArrowRight';
            if ((btn.getAttribute('aria-expanded') === 'true') !== want) { e.preventDefault(); toggleNode(key, btn.dataset.nodeToggle, want); }
        });
        box.addEventListener('input', debounce(function (e) {
            var st = open[key];
            if (st && e.target.matches('[data-sim-rev]')) {
                st.simRevenue = e.target.value === '' ? null : Number(e.target.value);
                setPanel(key, simHtml(key, st.simData));
                var inp = ws(key).querySelector('[data-sim-rev]');
                if (inp) { inp.focus(); }
            }
        }, 500));
    }

    rangeLabel();
    load();
})();
