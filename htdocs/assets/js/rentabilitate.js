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
        mode: ['soferi', 'locatii'].indexOf(qs.get('mode')) >= 0 ? qs.get('mode') : 'vehicule',
        // Locatii: dimensiunea randurilor + filtrele de locatie (aplicabile in orice mod; -1 = fara loc)
        dim: ['origine', 'destinatie', 'depozit'].indexOf(qs.get('dim')) >= 0 ? qs.get('dim') : 'depozit',
        origine: qs.get('origine') || '', destinatie: qs.get('destinatie') || '', depozit: qs.get('depozit') || '',
        ruta: qs.get('ruta') || '', // rute exacte „loc:zona,loc:zona” (Loc încărcare → Zonă distribuție)
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
    var LOC_KEYS = ['origine', 'destinatie', 'depozit', 'ruta'];
    var DIM_LABELS = { origine: 'Loc încărcare', destinatie: 'Zonă distribuție', depozit: 'Depozit / Bază' };
    var MODE_UNITS = { vehicule: 'vehicule', soferi: 'șoferi', locatii: 'locații' };
    function locParams() { var o = {}; LOC_KEYS.forEach(function (k) { o[k] = state[k]; }); if (state.mode === 'locatii') { o.dim = state.dim; } return o; }
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
    var selStatus = document.getElementById('ropStatus');
    search.value = state.q;
    selStatus.value = state.status;
    function markSet() { [selTip, selStatus].forEach(function (s) { s.classList.toggle('is-set', !!s.value); }); if (multi) { multi.render(); } }

    root.querySelectorAll('.rop-mode-btn').forEach(function (b) {
        b.classList.toggle('is-active', b.dataset.mode === state.mode);
        b.addEventListener('click', function () {
            if (state.mode === b.dataset.mode) { return; }
            state.mode = b.dataset.mode; state.page = 1; state.sort = ''; open = {}; selected = {};
            root.querySelectorAll('.rop-mode-btn').forEach(function (x) { x.classList.toggle('is-active', x === b); });
            var tb = document.getElementById('ropRows');
            tb.classList.add('is-morph');
            syncModeUi();
            setTimeout(function () { load(); }, 160);
        });
    });
    var dimBar = document.getElementById('ropDims');
    function syncModeUi() {
        search.placeholder = { soferi: 'Caută șofer, vehicul, beneficiar...', locatii: 'Caută locație, vehicul, beneficiar...' }[state.mode] || 'Caută vehicul, șofer, beneficiar...';
        if (!dimBar) { return; }
        dimBar.hidden = state.mode !== 'locatii';
        dimBar.querySelectorAll('[data-dim]').forEach(function (b) { b.classList.toggle('is-active', b.dataset.dim === state.dim); b.setAttribute('aria-selected', String(b.dataset.dim === state.dim)); });
        var hint = document.getElementById('ropDimHint');
        if (hint && lastList && lastList.optiuni && lastList.optiuni.dimensiuni_info) { hint.textContent = lastList.optiuni.dimensiuni_info[state.dim] || ''; }
    }
    if (dimBar) {
        dimBar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-dim]');
            if (b && b.dataset.dim !== state.dim) { state.dim = b.dataset.dim; state.page = 1; state.sort = ''; open = {}; syncModeUi(); load(); }
            if (e.target.closest('[data-manage-locations]')) { openLocationManager(); }
        });
    }
    syncModeUi();
    search.addEventListener('input', debounce(function () { state.q = search.value.trim(); state.page = 1; load(); }, 300));
    selTip.addEventListener('change', function () { state.tip = selTip.value; state.page = 1; load(); });
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
            location.href = u(Object.assign({ action: 'export', mode: state.mode, q: state.q, tip: state.tip, beneficiar: state.beneficiar, status: state.status, from: state.from, to: state.to,
                vehicle_id: state.vehicle_id, driver_id: state.driver_id }, locParams()));
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
        var v = state.mode === 'vehicule', l = state.mode === 'locatii';
        head.innerHTML = '<tr><th><input type="checkbox" class="rop-check" id="ropAll" aria-label="Selectează tot"></th><th></th>'
            + sortTh('label', l ? DIM_LABELS[state.dim] : (v ? 'Vehicul' : 'Șofer'), '') + '<th>' + (v ? 'Șofer' : 'Vehicule') + '</th><th>Tip transport</th>'
            + sortTh('km', 'Km total')
            + sortTh('cost_vehicle_km', 'Cost vehicul<small>(lei/km)</small>') + sortTh('cost_driver_km', 'Cost șofer<small>(lei/km)</small>')
            + sortTh('cost_total_km', 'Cost total<small>(lei/km)</small>') + sortTh('revenue_km', 'Venit<small>(lei/km)</small>')
            + sortTh('result_km', (v || l ? 'Rezultat' : 'Contribuție') + '<small>(lei/km)</small>') + sortTh('coverage', 'Acoperire') + '<th>Acțiuni</th></tr>';
        var all = document.getElementById('ropAll');
        all.addEventListener('click', function (e) {
            e.stopPropagation();
            Object.keys(rowsByKey).forEach(function (k) { if (all.checked) { selected[k] = rowsByKey[k]; } else { delete selected[k]; } });
            tbody.querySelectorAll('.rop-row .rop-check').forEach(function (c) { c.checked = all.checked; });
            renderSelBar();
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
        ['mode', 'q', 'tip', 'beneficiar', 'status', 'from', 'to', 'sort', 'vehicle_id', 'driver_id', 'dim'].concat(LOC_KEYS).forEach(function (k) {
            var keep = state[k] && !(k === 'mode' && state[k] === 'vehicule') && !(k === 'dim' && state.mode !== 'locatii');
            if (keep) { url.searchParams.set(k, state[k]); } else { url.searchParams.delete(k); }
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
        return get('list', Object.assign({ mode: state.mode, q: state.q, tip: state.tip, beneficiar: state.beneficiar, status: state.status, p: state.page, per_page: state.per_page, sort: state.sort,
            vehicle_id: state.vehicle_id, driver_id: state.driver_id, keep: keep }, locParams()), abort.signal)
            .then(function (d) {
                tbody.classList.remove('is-busy', 'is-morph');
                if (!d.ok) { tbody.innerHTML = emptyRow('bi-exclamation-triangle', d.message || 'Eroare.'); if (kpis) { kpis.update(null, false); } return; }
                lastList = d;
                state.from = d.from; state.to = d.to; rangeLabel();
                applyServerFilters(d);
                syncModeUi();
                renderTypes(d.optiuni.tipuri);
                if (kpis) { kpis.update(d, false); }
                renderActive(d);
                renderQuality(d);
                Object.keys(open).forEach(function (k) { open[k].loc = null; }); // detaliul locatiei urmeaza filtrele
                renderRows(d.rows);
                renderPager(d);
            })
            .catch(function (err) { if (err && err.name === 'AbortError') { return; } tbody.classList.remove('is-busy', 'is-morph'); tbody.innerHTML = emptyRow('bi-wifi-off', 'Conexiunea a eșuat. Reîncearcă.'); });
    }

    // ------------------------------------------------------------------ starea comuna a filtrelor
    // Sursa de adevar: ce a aplicat serverul (d.aplicat). O selectie incompatibila (ex. vehicul fara curse de
    // Distributie) e scoasa de server si anuntata aici; bara de sus, cardurile si tabelul se aliniaza la ea.
    var FILTER_LABELS = { tip: 'Tip transport', beneficiar: 'Beneficiar', vehicle_id: 'Vehicul', driver_id: 'Șofer', origine: 'Loc încărcare', destinatie: 'Zonă distribuție', depozit: 'Depozit', ruta: 'Rute' };
    function applyServerFilters(d) {
        var a = d.aplicat || {};
        state.tip = a.tip || '';
        state.beneficiar = a.beneficiar ? String(a.beneficiar) : '';
        state.vehicle_id = a.vehicle_id ? String(a.vehicle_id) : '';
        state.driver_id = a.driver_id ? String(a.driver_id) : '';
        LOC_KEYS.forEach(function (k) { state[k] = a[k] ? String(a[k]) : ''; });
        if (a.dim) { state.dim = a.dim; }
        markSet(); syncUrl();
        if (d.anulate && d.anulate.length) {
            toast(d.anulate.map(function (k) { return FILTER_LABELS[k] || k; }).join(', ') + ': fără activitate pentru filtrele alese — selecția a fost scoasă.');
        }
    }
    function setFilters(patch, key) {
        Object.keys(patch).forEach(function (k) { state[k] = patch[k] == null ? '' : String(patch[k]); });
        if (patch.tip !== undefined) { selTip.value = state.tip; }
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
        if (a.tip) { chips.push(['tip', 'bi-truck', (o.tipuri || {})[a.tip] || a.tip]); }
        // filtrele cu selectie multipla: un cip pe filtru („Vehicule: B 1, B 2 +3”), x scoate toata selectia
        MULTI.forEach(function (m) {
            var ids = splitIds(a[m.key]);
            if (!ids.length) { return; }
            var names = ids.map(function (id) { return optionLabel(m, id, d); });
            var text = names.length === 1 ? names[0] : names.slice(0, 2).join(', ') + (names.length > 2 ? ' +' + (names.length - 2) : '');
            chips.push([m.key, m.icon, (ids.length > 1 || LOC_KEYS.indexOf(m.key) >= 0 ? m.label + ': ' : '') + text, names.join(', ')]);
        });
        if (state.status) { chips.push(['status', 'bi-flag', selStatus.options[selStatus.selectedIndex] ? selStatus.options[selStatus.selectedIndex].text : state.status]); }
        if (state.q) { chips.push(['q', 'bi-search', '„' + state.q + '”']); }
        // randul ramane mereu afisat (fara filtre: „toata flota”), ca tabelul sa nu sara cand apare / dispare un filtru
        activeBox.hidden = false;
        activeBox.classList.toggle('is-empty', chips.length === 0);
        activeBox.innerHTML = '<i class="bi bi-funnel"></i><b>Filtre active:</b>' + (chips.length ? chips.map(function (c) {
            return '<span class="rop-chip"' + (c[3] ? ' title="' + esc(c[3]) + '"' : '') + '><i class="bi ' + c[1] + '"></i>' + esc(c[2]) + '<button type="button" data-chip="' + c[0] + '" aria-label="Scoate filtrul ' + esc(c[2]) + '"><i class="bi bi-x"></i></button></span>';
        }).join('') + '<button type="button" class="rop-active-reset" data-chip-all><i class="bi bi-arrow-counterclockwise"></i>Resetează toate filtrele</button>'
            : '<span class="rop-active-none">Niciun filtru · ' + (state.mode === 'soferi' ? 'toți șoferii' : (state.mode === 'locatii' ? 'toate locațiile' : 'toată flota')) + '</span>');
    }
    activeBox.addEventListener('click', function (e) {
        var c = e.target.closest('[data-chip]');
        if (c) { var p = {}; p[c.dataset.chip] = ''; setFilters(p); return; }
        if (e.target.closest('[data-chip-all]')) { setFilters({ tip: '', beneficiar: '', vehicle_id: '', driver_id: '', status: '', q: '', origine: '', destinatie: '', depozit: '', ruta: '' }); }
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
            parts.push('<b>' + t.unitati + ' ' + MODE_UNITS[state.mode] + '</b> · ' + nf0.format(t.km) + ' km · venit ' + nf0.format(t.revenue)
                + ' lei · cost ' + nf0.format(t.cost) + ' lei · <b>' + (t.result < 0 ? '' : '+') + nf0.format(t.result) + ' lei</b> (' + (t.coverage !== null ? nf1.format(t.coverage) + '% acoperire' : '—') + ')');
        }
        parts.push('Venitul = tariful cursei (nefacturat încă), nu încasarea.');
        if (state.mode === 'locatii') {
            parts.push('Costul unei locații = costul / km al fiecărui vehicul × km-ii curselor lui din acea locație (costul vehiculelor fără curse și al semiremorcilor necuplate nu ține de nicio locație).');
        }
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
        var sub = r.is_semi || state.mode === 'locatii' ? r.tip_label : '';
        return '<tr class="rop-row' + (isOpen ? ' is-open' : '') + '" data-key="' + esc(r.key) + '">'
            + '<td><input type="checkbox" class="rop-check" aria-label="Selectează"' + (selected[r.key] ? ' checked' : '') + '></td>'
            + '<td><button type="button" class="rop-exp" data-exp aria-expanded="' + isOpen + '" aria-label="Detalii"><i class="bi bi-chevron-right"></i></button></td>'
            + '<td><div class="rop-veh"><span class="rop-dot is-' + esc(r.activitate) + '" title="' + esc({ activ: 'Activ în perioadă', fara_activitate: 'Fără curse în perioadă', inactiv: 'Inactiv' }[r.activitate]) + '"></span><span class="rop-veh-text"><b>' + esc(r.label) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span></div></td>'
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
            tbody.innerHTML = emptyRow('bi-inboxes', state.q || state.tip || state.beneficiar || state.status || state.origine || state.destinatie || state.depozit || state.ruta ? 'Niciun rezultat pentru filtrele alese.' : 'Nicio activitate în perioada aleasă.');
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
        renderSelBar();
    }

    function renderPager(d) {
        var el = document.getElementById('ropPager');
        if (d.total <= d.per_page) { el.innerHTML = '<span><b>' + d.total + '</b> ' + MODE_UNITS[state.mode] + ' · ' + dt(d.from) + ' – ' + dt(d.to) + '</span>'; return; }
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
        if (e.target.matches('.rop-check')) { e.stopPropagation(); if (e.target.checked) { selected[key] = r; } else { delete selected[key]; } renderSelBar(); return; }
        if (e.target.closest('[data-menu]')) { e.stopPropagation(); rowMenu(e.target.closest('[data-menu]'), r); return; }
        open[key] ? collapse(key) : expand(key);
    });

    function rowMenu(anchor, r) {
        if (state.mode === 'locatii') { return locationMenu(anchor, r); }
        var veh = state.mode === 'vehicule';
        showMenu(anchor, [
            { icon: 'bi-arrows-angle-expand', label: open[r.key] ? 'Restrânge detaliile' : 'Deschide detaliile', run: function () { open[r.key] ? collapse(r.key) : expand(r.key); } },
            veh ? { icon: 'bi-layers', label: 'Istoric Vehicul', href: link(CFG.urls.istoric, { q: r.label }) } : null,
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
        if (state.mode === 'locatii') { loadLocation(key); return; }
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
            var head = '<h5>' + (v.semiremorca ? 'Semiremorcă ' : '') + esc(v.nr) + (v.dosar ? ' · dosar ' + esc(v.dosar.cod) : '') + '<a href="' + esc(link(CFG.urls.istoric, { q: v.nr })) + '" target="_blank" rel="noopener">Deschide în Istoric Vehicul ↗</a></h5>';
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

    // ------------------------------------------------------------------ Locatii (Origine / Destinatie / Depozit)
    // Filtrul unei locatii pe dimensiunea curenta (-1 = fara loc) — la fel in modurile Vehicule / Soferi.
    function locationFilter(r) { var p = { origine: '', destinatie: '', depozit: '' }; p[state.dim] = r.id > 0 ? r.id : -1; return p; }
    function switchMode(mode, patch) {
        state.mode = mode; state.sort = ''; open = {}; selected = {};
        root.querySelectorAll('.rop-mode-btn').forEach(function (x) { x.classList.toggle('is-active', x.dataset.mode === mode); });
        syncModeUi();
        setFilters(patch);
    }
    function locationMenu(anchor, r) {
        showMenu(anchor, [
            { icon: 'bi-arrows-angle-expand', label: open[r.key] ? 'Restrânge detaliile' : 'Vehicule și rute', run: function () { open[r.key] ? collapse(r.key) : expand(r.key); } },
            { icon: 'bi-truck', label: 'Vehiculele din această locație', run: function () { switchMode('vehicule', locationFilter(r)); } },
            { icon: 'bi-person', label: 'Șoferii din această locație', run: function () { switchMode('soferi', locationFilter(r)); } },
            { icon: 'bi-funnel', label: 'Restrânge la această locație', run: function () { setFilters(locationFilter(r)); } },
            CFG.canEditLocations ? '-' : null,
            CFG.canEditLocations ? { icon: 'bi-geo-alt', label: 'Gestionează locațiile fizice', run: function () { openLocationManager(r.id); } } : null,
            CFG.canExport ? { icon: 'bi-download', label: 'Exportă rezultatele filtrate', run: function () { exportBtn && exportBtn.click(); } } : null
        ]);
    }
    function loadLocation(key) {
        var st = open[key], r = rowsByKey[key];
        if (st.loc) { renderLocation(key); return; }
        get('location_detail', Object.assign({ id: r.id, mode: 'locatii', tip: state.tip, beneficiar: state.beneficiar, vehicle_id: state.vehicle_id, driver_id: state.driver_id }, locParams()))
            .then(function (d) {
                if (!open[key]) { return; }
                if (!d.ok) { ws(key).innerHTML = '<div class="rop-note is-warn">' + esc(d.message) + '</div>'; return; }
                st.loc = d; renderLocation(key);
            });
    }
    // Incarcarea pe vehicul (Pe vehicule): tone incarcate / livrate, clienti, tone lipsa fata de capacitatea cursei.
    function tons(v) { return v ? nf2.format(v) : '<span class="rop-na">—</span>'; }
    function loadCells(x) {
        var fill = x.grad_umplere === null || x.grad_umplere === undefined ? '' : '<small class="rop-fill ' + (x.grad_umplere < 95 ? 'is-low' : '') + '">' + nf1.format(x.grad_umplere) + '% umplere</small>';
        var warn = x.cap_neconfirmata > 0 ? ' <i class="bi bi-exclamation-triangle rop-warn" title="' + esc('Capacitate neconfirmată pe ' + x.cap_neconfirmata + ' curse (instantaneul cursei; verifică în Management capacități)') + '"></i>' : '';
        var over = x.tone_peste > 0.005 ? '<small class="rop-over" title="Tone încărcate peste capacitatea cursei (nu compensează lipsa altor curse)">+' + nf2.format(x.tone_peste) + ' t peste</small>' : '';
        return '<td class="is-num">' + tons(x.tone_inc) + '</td><td class="is-num">' + tons(x.tone_liv) + '</td>'
            + '<td class="is-num">' + (x.clienti ? nf0.format(x.clienti) : '<span class="rop-na">—</span>') + '</td>'
            + '<td class="is-num rop-lipsa"><b class="' + (x.tone_lipsa > 0.005 ? 'is-neg' : '') + '">' + (x.capacitate > 0 ? nf2.format(x.tone_lipsa) : '<span class="rop-na">—</span>') + '</b>' + warn + fill + over + '</td>';
    }
    function locTable(title, first, second, rows, routes) {
        if (!rows.length) { return ''; }
        var load = !routes; // coloanele de incarcare: doar pe vehicule
        var body = rows.map(function (x) {
            return '<tr class="is-click" ' + (routes ? 'data-loc-route="' + x.origine + ':' + x.destinatie + '"' : 'data-loc-vehicle="' + x.id + '"') + '><td><b>' + esc(x.label) + '</b>' + (x.model ? ' <small class="rop-muted">' + esc(x.model) + '</small>' : '') + '</td>'
                + '<td>' + esc(x.drivers || '') + '</td><td class="is-num">' + x.trips + '</td><td class="is-num">' + nf0.format(x.km) + '</td>'
                + (load ? loadCells(x) : '')
                + '<td class="is-num">' + n2(x.cost_total_km) + '</td><td class="is-num">' + n2(x.revenue_km) + '</td>'
                + '<td class="is-num"><span class="rop-res ' + resClass(x.result_km) + '" style="height:24px;min-width:60px;font-size:13.5px">' + signed(x.result_km) + '</span></td>'
                + '<td class="is-num ' + (x.result < 0 ? 'rop-neg' : 'rop-pos') + '">' + signed(x.result) + '</td></tr>';
        }).join('');
        var foot = '';
        if (load && rows.length > 1) {
            // totalul vehiculelor (tone si capacitate insumate; umplerea = ponderata, nu medie de procente)
            var t = { trips: 0, km: 0, tone_inc: 0, tone_liv: 0, clienti: 0, capacitate: 0, tone_lipsa: 0, tone_peste: 0, cap_neconfirmata: 0, result: 0, cost: 0, revenue: 0 };
            rows.forEach(function (x) { Object.keys(t).forEach(function (k) { t[k] += Number(k === 'cost' ? x.cost_total : x[k]) || 0; }); });
            t.grad_umplere = t.capacitate > 0 ? (t.capacitate - t.tone_lipsa + t.tone_peste) / t.capacitate * 100 : null;
            foot = '<tfoot><tr><td><b>Total</b></td><td></td><td class="is-num">' + t.trips + '</td><td class="is-num">' + nf0.format(t.km) + '</td>' + loadCells(t)
                + '<td class="is-num">' + n2(t.km > 0 ? t.cost / t.km : null) + '</td><td class="is-num">' + n2(t.km > 0 ? t.revenue / t.km : null) + '</td>'
                + '<td class="is-num">' + (t.km > 0 ? signed((t.revenue - t.cost) / t.km) : '—') + '</td><td class="is-num ' + (t.result < 0 ? 'rop-neg' : 'rop-pos') + '"><b>' + signed(t.result) + '</b></td></tr></tfoot>';
        }
        return '<h5 class="rop-loc-h">' + esc(title) + '</h5><table class="rop-list' + (load ? ' rop-list-load' : '') + '"><thead><tr><th>' + esc(first) + '</th><th>' + esc(second) + '</th><th class="is-num">Curse</th><th class="is-num">Km</th>'
            + (load ? '<th class="is-num">Tone încărcate</th><th class="is-num">Tone livrate</th><th class="is-num" title="Puncte de livrare">Clienți</th>'
                + '<th class="is-num" title="Capacitatea cursei − tone încărcate, însumat pe cursele sub capacitate">Tone lipsă</th>' : '')
            + '<th class="is-num">Cost/km</th><th class="is-num">Venit/km</th><th class="is-num">Rezultat/km</th><th class="is-num">Rezultat total (lei)</th></tr></thead><tbody>' + body + '</tbody>' + foot + '</table>';
    }
    function renderLocation(key) {
        var st = open[key], d = st.loc, r = rowsByKey[key], box = ws(key);
        if (!box || !d) { return; }
        var sum = '<div class="rop-sum">'
            + sumItem('bi-geo-alt', DIM_LABELS[state.dim], esc(r.label), 'is-ident')
            + sumItem('bi-truck', 'Vehicule', String(d.vehicles.length))
            + sumItem('bi-signpost-split', 'Curse', nf0.format(r.trips))
            + sumItem('bi-speedometer2', 'Km', km(r.km))
            + sumItem('bi-cash-stack', 'Rezultat', lei(r.result), r.result < 0 ? 'is-bad' : 'is-good')
            + '</div>';
        box.innerHTML = sum
            + locTable('Rute (Loc încărcare → Zonă distribuție)', 'Rută', 'Vehicule', d.routes, true)
            + locTable('Pe vehicule', 'Vehicul', 'Beneficiari', d.vehicles, false)
            + '<div class="rop-note">Tone livrate = tonele înregistrate la livrare; unde nu s-au înregistrat separat, sunt egale cu cele încărcate. Tone lipsă = capacitatea cursei (instantaneul ei, nu fișa de azi a vehiculului) − tonele încărcate, doar pe cursele sub capacitate; supraîncărcarea apare separat („peste”) și nu compensează lipsa. Clic pe o rută: vehiculele acelei rute exacte. Clic pe un vehicul: îl deschide în modul Vehicule, restrâns la această locație. Rezultatul pe rută / vehicul = venitul curselor − costul / km al vehiculului × km-ii lor.</div>';
        box.onclick = function (e) {
            var t = e.target.closest('[data-loc-vehicle]');
            if (t) { var p = locationFilter(r); p.vehicle_id = t.dataset.locVehicle; switchMode('vehicule', p); return; }
            var rt = e.target.closest('[data-loc-route]');
            if (rt) { var pr = locationFilter(r); pr[state.dim] = ''; pr.ruta = rt.dataset.locRoute; switchMode('vehicule', pr); }
        };
    }

    // ---- Gestionarea locurilor fizice (strat de raportare: nu schimba tarifele sau configurarea transportului)
    var locModal = null;
    function post(payload) {
        return fetch(u({ action: 'save_location' }), { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CFG.csrf || '' }, body: JSON.stringify(payload) })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Răspuns invalid (' + r.status + ').' }; }); });
    }
    function openLocationManager(focusId) {
        if (!locModal) {
            locModal = document.createElement('div');
            locModal.className = 'rop-lm';
            locModal.innerHTML = '<div class="rop-lm-box" role="dialog" aria-modal="true" aria-labelledby="ropLmTitle">'
                + '<div class="rop-lm-head"><div><h3 id="ropLmTitle">Locații fizice</h3><p>Fiecare loc de încărcare și fiecare zonă de distribuție (definite per beneficiar) aparține unui singur loc fizic. '
                + 'Rapoartele pe Origine / Destinație / Depozit grupează după aceste locuri. Tarifele și configurarea transportului nu se schimbă.</p></div>'
                + '<button type="button" class="rop-lm-x" data-lm-close aria-label="Închide"><i class="bi bi-x-lg"></i></button></div>'
                + '<div class="rop-lm-tools"><label class="rop-search"><i class="bi bi-search"></i><input type="search" data-lm-q placeholder="Caută locație, beneficiar..." autocomplete="off"></label></div>'
                + '<div class="rop-lm-body" data-lm-body><span class="rop-spinner"></span> Se încarcă…</div></div>';
            document.body.appendChild(locModal);
            locModal.addEventListener('click', onLmClick);
            locModal.addEventListener('change', onLmChange);
            locModal.querySelector('[data-lm-q]').addEventListener('input', debounce(renderLm, 200));
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && locModal && locModal.classList.contains('is-open')) { closeLm(); } });
        }
        locModal.dataset.focus = focusId || '';
        locModal.classList.add('is-open');
        document.body.classList.add('rop-lm-lock');
        get('locations', {}).then(function (d) { if (d.ok) { locModal._data = d; renderLm(); } else { locModal.querySelector('[data-lm-body]').innerHTML = '<div class="rop-note is-warn">' + esc(d.message) + '</div>'; } });
    }
    function closeLm() {
        locModal.classList.remove('is-open');
        document.body.classList.remove('rop-lm-lock');
        if (locModal._dirty) { locModal._dirty = false; open = {}; load(); }
    }
    function renderLm() {
        var d = locModal._data, body = locModal.querySelector('[data-lm-body]');
        if (!d) { return; }
        var q = (locModal.querySelector('[data-lm-q]').value || '').trim().toLowerCase();
        var edit = !!d.canEdit, places = d.places;
        var options = places.map(function (p) { return '<option value="' + p.id + '">' + esc(p.nume) + '</option>'; }).join('');
        var sug = (d.suggestions || []).map(function (s) {
            return '<li><i class="bi bi-lightbulb"></i><span>' + esc(s.text) + '</span>' + (edit ? '<button type="button" class="rop-link-btn" data-lm-merge="' + s.from + '" data-lm-into="' + s.into + '">Unește</button>' : '') + '</li>';
        }).join('');
        var html = sug ? '<div class="rop-lm-sug"><b>Posibile dubluri</b> — nu se unesc automat (pot fi terminale diferite din același oraș):<ul>' + sug + '</ul></div>' : '';
        html += places.filter(function (p) {
            if (!q) { return true; }
            return (p.nume + ' ' + p.surse.map(function (s) { return s.nume + ' ' + s.beneficiar; }).join(' ')).toLowerCase().indexOf(q) >= 0;
        }).map(function (p) {
            var head = edit
                ? '<input type="text" class="rop-lm-name" data-lm-name="' + p.id + '" value="' + esc(p.nume) + '" maxlength="120" aria-label="Numele locației">'
                    + '<select data-lm-type="' + p.id + '" aria-label="Tip"><option value="punct"' + (p.tip === 'punct' ? ' selected' : '') + '>Punct fizic</option><option value="regiune"' + (p.tip === 'regiune' ? ' selected' : '') + '>Regiune</option></select>'
                : '<b>' + esc(p.nume) + '</b><span class="rop-badge is-gray">' + (p.tip === 'regiune' ? 'Regiune' : 'Punct fizic') + '</span>';
            var roles = p.roluri.length ? p.roluri.map(function (x) { return '<span class="rop-badge">' + esc(x) + '</span>'; }).join(' ') : '<span class="rop-badge is-gray">Fără curse</span>';
            var srcs = p.surse.map(function (s) {
                var move = edit ? '<select data-lm-move="' + s.sursa + ':' + s.sursa_id + '" aria-label="Mută la altă locație"><option value="">Mută la…</option><option value="0">+ Locație nouă (separată)</option>' + options.replace('<option value="' + p.id + '">', '<option value="' + p.id + '" disabled>') + '</select>' : '';
                return '<tr><td>' + esc(s.sursa_label) + '</td><td><b>' + esc(s.nume) + '</b>' + (s.activ ? '' : ' <span class="rop-badge is-gray">inactiv</span>') + (s.manual ? ' <i class="bi bi-hand-index rop-muted" title="Legat manual"></i>' : '') + '</td><td>' + esc(s.beneficiar) + '</td><td class="is-num">' + s.curse + '</td><td>' + move + '</td></tr>';
            }).join('');
            return '<section class="rop-lm-place' + (String(p.id) === locModal.dataset.focus ? ' is-focus' : '') + '" data-lm-place="' + p.id + '"><div class="rop-lm-ph">' + head + '<span class="rop-lm-trips">' + nf0.format(p.curse) + ' curse</span></div>'
                + '<div class="rop-lm-roles">' + roles + '</div>'
                + '<table class="rop-list"><thead><tr><th>Sursă</th><th>Nume în configurare</th><th>Beneficiar</th><th class="is-num">Curse</th><th>' + (edit ? 'Acțiune' : '') + '</th></tr></thead><tbody>' + srcs + '</tbody></table></section>';
        }).join('');
        body.innerHTML = html || '<div class="rop-note">Nicio locație.</div>';
        var f = body.querySelector('.is-focus');
        if (f) { f.scrollIntoView({ block: 'center' }); locModal.dataset.focus = ''; }
    }
    function lmSave(payload, msg) {
        post(payload).then(function (d) {
            if (!d.ok) { toast(d.message || 'Eroare.'); return; }
            locModal._data = d; locModal._dirty = true; renderLm(); toast(msg);
        });
    }
    function onLmClick(e) {
        if (e.target === locModal || e.target.closest('[data-lm-close]')) { closeLm(); return; }
        var m = e.target.closest('[data-lm-merge]');
        if (m) { lmSave({ op: 'merge', from: Number(m.dataset.lmMerge), into: Number(m.dataset.lmInto) }, 'Locațiile au fost unite.'); }
    }
    function onLmChange(e) {
        var t = e.target, id;
        if (t.matches('[data-lm-move]') && t.value !== '') {
            var parts = t.dataset.lmMove.split(':');
            lmSave({ op: 'move', sursa: parts[0], sursa_id: Number(parts[1]), tinta: Number(t.value) }, t.value === '0' ? 'Mutată într-o locație nouă.' : 'Mutată.');
            return;
        }
        if (t.matches('[data-lm-name]') || t.matches('[data-lm-type]')) {
            id = t.dataset.lmName || t.dataset.lmType;
            var sec = locModal.querySelector('[data-lm-place="' + id + '"]');
            lmSave({ op: 'update', id: Number(id), nume: sec.querySelector('[data-lm-name]').value, tip: sec.querySelector('[data-lm-type]').value }, 'Salvat.');
        }
    }

    // ------------------------------------------------------------------ filtre cu selectie multipla
    // Beneficiar / Vehicule / Șoferi / Loc încărcare / Zonă distribuție / Depozit: liste "3,5,8" in starea paginii
    // (aceeasi stare ca bara de sus, cardurile KPI si tabelul). Bifele se aplica la „Aplică” sau la inchidere.
    var MULTI = [
        { key: 'beneficiar', label: 'Beneficiar', plural: 'beneficiari', icon: 'bi-people' },
        { key: 'vehicle_id', label: 'Vehicule', plural: 'vehicule', icon: 'bi-truck' },
        { key: 'driver_id', label: 'Șoferi', plural: 'șoferi', icon: 'bi-person' },
        { key: 'origine', label: 'Loc încărcare', plural: 'locuri', icon: 'bi-box-arrow-right' },
        { key: 'destinatie', label: 'Zonă distribuție', plural: 'zone', icon: 'bi-geo-alt' },
        { key: 'depozit', label: 'Depozit', plural: 'depozite', icon: 'bi-building' },
        // rute exacte: doar perechile bifate (Contești → Sud), fara combinatiile locuri × zone
        { key: 'ruta', label: 'Rute', plural: 'rute', icon: 'bi-signpost-split' }
    ];
    function splitIds(v) { return String(v || '').split(',').filter(function (x) { return x !== '' && x !== '0'; }); }
    function optionsOf(m, d) {
        var o = (d && d.optiuni) || {};
        if (m.key === 'beneficiar') {
            var b = o.beneficiari || CFG.beneficiaries || {};
            return Object.keys(b).map(function (id) { return { id: id, label: String(b[id]) }; })
                .sort(function (x, y) { return x.label.localeCompare(y.label, 'ro'); });
        }
        if (m.key === 'vehicle_id') { return o.vehicule || []; }
        if (m.key === 'driver_id') { return o.soferi || []; }
        return (o.locatii_filtru || {})[m.key] || [];
    }
    function optionLabel(m, id, d) {
        var hit = optionsOf(m, d).filter(function (x) { return String(x.id) === String(id); })[0];
        if (hit) { return hit.label; }
        if (LOC_KEYS.indexOf(m.key) >= 0) { return ((d && d.optiuni && d.optiuni.locatii) || {})[id] || ('#' + id); }
        return '#' + id;
    }
    var multi = (function () {
        var box = document.getElementById('ropMulti');
        if (!box) { return null; }
        var openKey = null, draft = [], q = '';
        function render() {
            var d = lastList;
            box.innerHTML = MULTI.map(function (m) {
                var ids = splitIds(state[m.key]);
                var text = !ids.length ? m.label : (ids.length === 1 ? optionLabel(m, ids[0], d) : m.label + ' (' + ids.length + ')');
                return '<div class="rop-mf-item' + (openKey === m.key ? ' is-open' : '') + '" data-mf="' + m.key + '">'
                    + '<button type="button" class="rop-mf-btn' + (ids.length ? ' is-set' : '') + '" data-mf-toggle aria-haspopup="true" aria-expanded="' + (openKey === m.key) + '" title="' + esc(ids.length ? ids.map(function (id) { return optionLabel(m, id, d); }).join(', ') : m.label) + '">'
                    + '<i class="bi ' + m.icon + '"></i><span>' + esc(text) + '</span><i class="bi bi-chevron-down rop-caret"></i></button>'
                    + (openKey === m.key ? popHtml(m) : '') + '</div>';
            }).join('');
            var input = box.querySelector('[data-mf-q]');
            if (input) { input.value = q; }
        }
        function popHtml(m) {
            var opts = optionsOf(m, lastList), needle = q.trim().toLowerCase();
            var sel = {}; draft.forEach(function (id) { sel[id] = true; });
            // alese primele, apoi restul; cautarea filtreaza doar lista
            var shown = opts.filter(function (x) { return !needle || x.label.toLowerCase().indexOf(needle) >= 0; })
                .sort(function (x, y) { return (sel[y.id] ? 1 : 0) - (sel[x.id] ? 1 : 0); });
            var rows = shown.map(function (x) {
                return '<label class="rop-mf-opt"><input type="checkbox" class="rop-check" data-mf-id="' + esc(x.id) + '"' + (sel[x.id] ? ' checked' : '') + '><span>' + esc(x.label) + '</span></label>';
            }).join('');
            var hint = m.key === 'vehicle_id' || m.key === 'driver_id' ? 'Doar cele cu activitate în perioadă și în filtrele alese.' : '';
            return '<div class="rop-mf-pop" role="dialog" aria-label="' + esc(m.label) + '">'
                + '<label class="rop-search rop-mf-search"><i class="bi bi-search"></i><input type="search" data-mf-q placeholder="Caută ' + esc(m.plural) + '..." autocomplete="off"></label>'
                + '<div class="rop-mf-meta"><span><b>' + draft.length + '</b> din ' + opts.length + ' selectate</span>'
                + '<button type="button" data-mf-all>Selectează ' + (needle ? 'rezultatele' : 'tot') + '</button><button type="button" data-mf-none>Golește</button></div>'
                + '<div class="rop-mf-list">' + (rows || '<div class="rop-mf-empty">Nimic de ales în filtrele curente.</div>') + '</div>'
                + (hint ? '<div class="rop-mf-hint">' + esc(hint) + '</div>' : '')
                + '<div class="rop-mf-foot"><button type="button" class="rop-btn-outline rop-btn-sm" data-mf-cancel>Anulează</button><button type="button" class="rop-btn-primary rop-btn-sm" data-mf-apply>Aplică</button></div></div>';
        }
        function openPop(key) {
            openKey = key; q = '';
            draft = splitIds(state[key]);
            render();
            var input = box.querySelector('[data-mf-q]');
            if (input) { input.focus(); }
        }
        function close(apply) {
            if (!openKey) { return; }
            var key = openKey, next = draft.join(',');
            openKey = null; q = '';
            render();
            if (apply && next !== splitIds(state[key]).join(',')) {
                var p = {}; p[key] = next;
                setFilters(p, key === 'vehicle_id' || key === 'driver_id' ? key : '');
            }
        }
        function refreshList() {
            var m = MULTI.filter(function (x) { return x.key === openKey; })[0], pop = box.querySelector('.rop-mf-pop');
            if (!m || !pop) { return; }
            var tmp = document.createElement('div');
            tmp.innerHTML = popHtml(m);
            pop.querySelector('.rop-mf-list').replaceWith(tmp.querySelector('.rop-mf-list'));
            pop.querySelector('.rop-mf-meta').replaceWith(tmp.querySelector('.rop-mf-meta'));
        }
        box.addEventListener('click', function (e) {
            var t = e.target.closest('[data-mf-toggle]');
            if (t) { var key = t.closest('[data-mf]').dataset.mf; if (openKey === key) { close(true); } else { if (openKey) { close(true); } openPop(key); } return; }
            if (e.target.closest('[data-mf-apply]')) { close(true); return; }
            if (e.target.closest('[data-mf-cancel]')) { close(false); return; }
            if (e.target.closest('[data-mf-none]')) { draft = []; refreshList(); return; }
            if (e.target.closest('[data-mf-all]')) {
                Array.prototype.forEach.call(box.querySelectorAll('[data-mf-id]'), function (c) { var id = c.dataset.mfId; if (draft.indexOf(id) < 0) { draft.push(id); } });
                refreshList(); return;
            }
        });
        box.addEventListener('change', function (e) {
            var c = e.target.closest('[data-mf-id]');
            if (!c) { return; }
            var id = c.dataset.mfId, i = draft.indexOf(id);
            if (c.checked && i < 0) { draft.push(id); }
            if (!c.checked && i >= 0) { draft.splice(i, 1); }
            var meta = box.querySelector('.rop-mf-meta b');
            if (meta) { meta.textContent = String(draft.length); }
        });
        box.addEventListener('input', function (e) { if (e.target.matches('[data-mf-q]')) { q = e.target.value; refreshList(); } });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && openKey) {
                e.preventDefault();
                var key = openKey;
                close(false); // Esc = renunta la bifele nesalvate
                var btn = box.querySelector('[data-mf="' + key + '"] [data-mf-toggle]');
                if (btn) { btn.focus(); }
            }
            if (e.key === 'Enter' && e.target.matches('[data-mf-q]')) { e.preventDefault(); close(true); }
        });
        // clic in afara = aplica (ca un meniu obisnuit care se inchide)
        document.addEventListener('mousedown', function (e) { if (openKey && !box.contains(e.target)) { close(true); } });
        return { render: render };
    })();

    // ------------------------------------------------------------------ bara selectiei din tabel (bifele)
    // Bifele devin filtru (Vehicule → vehicle_id, Șoferi → driver_id, Locații → filtrul dimensiunii), deci
    // cardurile KPI, totalurile si exportul urmeaza selectia. Semiremorcile nu au activitate proprie (sunt ale tractorului).
    var selBar = document.getElementById('ropSelBar');
    function selectionFilter() {
        var rows = Object.keys(selected).map(function (k) { return selected[k]; }).filter(function (r) { return r && typeof r === 'object'; });
        var usable = rows.filter(function (r) { return !r.is_semi; });
        var key = state.mode === 'soferi' ? 'driver_id' : (state.mode === 'locatii' ? state.dim : 'vehicle_id');
        return { rows: rows, key: key, ids: usable.map(function (r) { return r.id; }), skipped: rows.length - usable.length };
    }
    function renderSelBar() {
        if (!selBar) { return; }
        var s = selectionFilter();
        selBar.hidden = s.rows.length === 0;
        if (!s.rows.length) { selBar.innerHTML = ''; return; }
        selBar.innerHTML = '<i class="bi bi-check2-square"></i><b>' + s.rows.length + ' ' + (s.rows.length === 1 ? 'rând selectat' : 'rânduri selectate') + '</b>'
            + (s.skipped ? '<span class="rop-muted">(' + s.skipped + ' semiremorci nu se pot filtra separat)</span>' : '')
            + '<button type="button" class="rop-btn-outline rop-btn-sm" data-sel="filter"' + (s.ids.length ? '' : ' disabled') + '><i class="bi bi-funnel"></i>Arată doar selecția</button>'
            + (CFG.canExport ? '<button type="button" class="rop-btn-outline rop-btn-sm" data-sel="export"' + (s.ids.length ? '' : ' disabled') + '><i class="bi bi-download"></i>Exportă selecția</button>' : '')
            + '<button type="button" class="rop-btn-outline rop-btn-sm" data-sel="clear"><i class="bi bi-x"></i>Golește selecția</button>';
    }
    if (selBar) {
        selBar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-sel]');
            if (!b || b.disabled) { return; }
            var s = selectionFilter(), patch = {};
            if (b.dataset.sel === 'clear') { selected = {}; tbody.querySelectorAll('.rop-check').forEach(function (c) { c.checked = false; }); var all = document.getElementById('ropAll'); if (all) { all.checked = false; } renderSelBar(); return; }
            patch[s.key] = s.ids.join(',');
            if (b.dataset.sel === 'filter') { selected = {}; setFilters(patch, s.key === 'driver_id' || s.key === 'vehicle_id' ? s.key : ''); return; }
            // exportul selectiei: filtrele curente + selectia (fara a schimba pagina)
            var params = Object.assign({ action: 'export', mode: state.mode, q: state.q, tip: state.tip, beneficiar: state.beneficiar, status: state.status, from: state.from, to: state.to,
                vehicle_id: state.vehicle_id, driver_id: state.driver_id }, locParams(), patch);
            location.href = u(params);
            toast('Exportul CSV pornește (' + s.ids.length + ' rânduri selectate).');
        });
    }

    rangeLabel();
    load();
})();
