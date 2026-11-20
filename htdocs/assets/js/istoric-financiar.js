/* Istoric Financiar Vehicul (?page=istoric_financiar) — lista, filtre, dosar extins, tab-uri, formulare.
 * Toate valorile vin din backend; scriptul doar randeaza si trimite operatiile (action=op). */
(function () {
    'use strict';

    var CFG = window.IFV_CONFIG || {};
    var root = document.getElementById('ifvRoot');
    if (!root) { return; }

    var nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var params = new URLSearchParams(location.search);
    var state = {
        q: params.get('q') || '',
        tab: params.get('tab') || 'toate',
        status: params.get('status') || '',
        tara: params.get('tara') || '',
        perioada: params.get('perioada') || '',
        page: parseInt(params.get('p') || '1', 10) || 1,
        per_page: parseInt(params.get('per_page') || '10', 10) || 10,
        // categoria aleasa in cardul „Valoare initiala” (restrange doar tabelul; nu se pastreaza in URL)
        fin_node: '', fin_tip: ''
    };
    var rowsByKey = {};
    var open = {};          // key -> { detail, tab, cat, line, project }
    var listAbort = null;

    var TABS = [
        { key: 'detalii', label: 'Detalii', icon: 'bi-file-earmark-text' },
        { key: 'costuri', label: 'Costuri', icon: 'bi-layers' },
        { key: 'documente', label: 'Documente', icon: 'bi-file-earmark-richtext' },
        { key: 'istoric', label: 'Istoric', icon: 'bi-clock-history' },
        { key: 'valoare', label: 'Valoare & Amortizare', icon: 'bi-bar-chart-line' },
        { key: 'note', label: 'Note', icon: 'bi-chat-left-text' },
        { key: 'investitii', label: 'Investiții GPL', icon: 'bi-fuel-pump' }
    ];
    var COUNTRIES = {
        RO: ['România', 'v', ['#002b7f', '#fcd116', '#ce1126']], DE: ['Germania', 'h', ['#000', '#dd0000', '#ffce00']],
        NL: ['Olanda', 'h', ['#ae1c28', '#fff', '#21468b']], FR: ['Franța', 'v', ['#0055a4', '#fff', '#ef4135']],
        IT: ['Italia', 'v', ['#009246', '#fff', '#ce2b37']], BE: ['Belgia', 'v', ['#000', '#fdda24', '#ef3340']],
        AT: ['Austria', 'h', ['#ed2939', '#fff', '#ed2939']], HU: ['Ungaria', 'h', ['#ce2939', '#fff', '#477050']],
        BG: ['Bulgaria', 'h', ['#fff', '#00966e', '#d62612']], LU: ['Luxemburg', 'h', ['#ed2939', '#fff', '#00a1de']],
        PL: ['Polonia', 'h', ['#fff', '#dc143c']], CZ: ['Cehia', 'h', ['#fff', '#d7141a']], ES: ['Spania', 'h', ['#aa151b', '#f1bf00', '#f1bf00', '#aa151b']],
        LT: ['Lituania', 'h', ['#fdb913', '#006a44', '#c1272d']], EE: ['Estonia', 'h', ['#0072ce', '#000', '#fff']],
        IE: ['Irlanda', 'v', ['#169b62', '#fff', '#ff883e']], SE: ['Suedia', 'h', ['#006aa7', '#fecc00', '#006aa7']],
        DK: ['Danemarca', 'h', ['#c8102e', '#fff', '#c8102e']], MD: ['Moldova', 'v', ['#0046ae', '#ffd200', '#cc092f']],
        SK: ['Slovacia', 'h', ['#fff', '#0b4ea2', '#ee1c25']], SI: ['Slovenia', 'h', ['#fff', '#005da4', '#ed1c24']]
    };
    var STATUS_CLASS = { pus_in_functiune: 'green', anulat: 'red', fara_dosar: 'gray', in_analiza: 'blue' };
    var EVENT_LABELS = {
        achizitie: 'Achiziție', transfer_proprietate: 'Transfer proprietate', preluare: 'Preluare vehicul', sosire_ro: 'Sosire în România',
        receptie_tehnica: 'Recepție tehnică', inmatriculare: 'Înmatriculare (RAR)', adaptare_tehnica: 'Adaptare tehnică', punere_in_functiune: 'Punere în exploatare'
    };
    var STATUS_OPTIONS = [['in_analiza', 'În analiză'], ['contractat', 'Contractat'], ['in_plata', 'În plată'], ['in_transport', 'În transport'], ['receptionat', 'Recepționat'],
        ['inmatriculat', 'Înmatriculat'], ['in_adaptare', 'În adaptare'], ['pus_in_functiune', 'Finalizat (pus în exploatare)'], ['anulat', 'Anulat']];
    var VEHICLE_TYPES = [['cap_tractor', 'Cap tractor'], ['camion', 'Camion'], ['semiremorca', 'Semiremorcă'], ['semiremorca_primar', 'Semiremorcă primar'],
        ['semiremorca_distributie', 'Semiremorcă distribuție'], ['remorca', 'Remorcă'], ['autoutilitara', 'Autoutilitară'], ['autovehicul', 'Autovehicul'], ['altul', 'Alt tip']];
    var TREATMENTS = [['de_confirmat', 'De confirmat'], ['capitalizabil', 'Capitalizabil'], ['cheltuiala', 'Cheltuială de exploatare']];
    var PROJECT_CATEGORIES = [['adaptare_gpl', 'Adaptare GPL'], ['echipare_initiala', 'Echipare inițială'], ['modernizare', 'Modernizare'], ['extindere_capacitate', 'Extindere capacitate'], ['alte_investitii', 'Alte investiții']];
    var PROJECT_STATUSES = [['planificat', 'Planificat'], ['in_executie', 'În execuție'], ['finalizat', 'Finalizat'], ['anulat', 'Anulat']];

    // ------------------------------------------------------------------ utilitare
    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function lei(v, decimals) {
        if (v === null || v === undefined || v === '') { return '<span class="ifv-muted">—</span>'; }
        return (decimals ? nf2 : nf0).format(Number(v)) + (decimals ? '' : ' lei');
    }
    function date(v) {
        if (!v) { return '<span class="ifv-muted">—</span>'; }
        var m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? m[3] + '.' + m[2] + '.' + m[1] : esc(v);
    }
    function flag(code) {
        if (!code) { return ''; }
        var c = COUNTRIES[code];
        if (!c) { return '<span class="ifv-flag-code">' + esc(code) + '</span>'; }
        var bands = c[2], n = bands.length, parts = '';
        bands.forEach(function (color, i) {
            parts += c[1] === 'h'
                ? '<rect x="0" y="' + (16 / n * i) + '" width="24" height="' + (16 / n + .2) + '" fill="' + color + '"/>'
                : '<rect y="0" x="' + (24 / n * i) + '" height="16" width="' + (24 / n + .2) + '" fill="' + color + '"/>';
        });
        return '<svg class="ifv-flag" viewBox="0 0 24 16" aria-hidden="true">' + parts + '</svg>';
    }
    function countryName(code) { return code ? (COUNTRIES[code] ? COUNTRIES[code][0] : code) : ''; }
    function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }
    function toast(msg, isError) {
        var el = document.getElementById('ifvToast');
        el.textContent = msg;
        el.classList.toggle('is-error', !!isError);
        el.classList.add('is-visible');
        clearTimeout(toast.t);
        toast.t = setTimeout(function () { el.classList.remove('is-visible'); }, 3200);
    }
    function url(base, extra) {
        var u = new URL(base, location.href);
        Object.keys(extra || {}).forEach(function (k) { if (extra[k] !== '' && extra[k] !== null && extra[k] !== undefined) { u.searchParams.set(k, extra[k]); } });
        return u.toString();
    }
    function getJson(u, signal) {
        return fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: signal }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Răspuns invalid (' + r.status + ').' }; });
        });
    }
    function post(fields, files) {
        var fd = files instanceof FormData ? files : new FormData();
        fd.set('_token', CFG.csrf);
        Object.keys(fields).forEach(function (k) {
            if (fields[k] !== undefined && fields[k] !== null) { fd.set(k, fields[k]); }
        });
        return fetch(CFG.urls.op, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) {
            return r.json().catch(function () { return { ok: false, message: 'Răspuns invalid (' + r.status + ').' }; });
        });
    }
    function animateNumber(el, value, suffix) {
        var target = Number(value) || 0;
        var start = Number(el.dataset.v || 0);
        el.dataset.v = String(target);
        var t0 = performance.now(), dur = 520;
        function frame(t) {
            var p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
            el.textContent = nf0.format(Math.round(start + (target - start) * e)) + (suffix || '');
            if (p < 1) { requestAnimationFrame(frame); }
        }
        requestAnimationFrame(frame);
    }
    function modal(id) { return window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(document.getElementById(id)) : null; }

    // ------------------------------------------------------------------ meniu flotant (nu e taiat de tabel)
    var floatingMenu = null;
    function closeMenu() { if (floatingMenu) { floatingMenu.remove(); floatingMenu = null; } }
    function showMenu(anchor, items) {
        closeMenu();
        var menu = document.createElement('div');
        menu.className = 'dropdown-menu ifv-menu show';
        menu.style.position = 'fixed';
        menu.style.zIndex = 1080;
        items.filter(Boolean).forEach(function (item) {
            if (item === '-') { menu.insertAdjacentHTML('beforeend', '<div class="dropdown-divider"></div>'); return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'dropdown-item';
            b.innerHTML = '<i class="bi ' + item.icon + '"></i>' + esc(item.label);
            b.addEventListener('click', function (e) { e.stopPropagation(); closeMenu(); item.run(); });
            menu.appendChild(b);
        });
        document.body.appendChild(menu);
        var r = anchor.getBoundingClientRect();
        var w = menu.offsetWidth, h = menu.offsetHeight;
        menu.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w)) + 'px';
        menu.style.top = (r.bottom + h + 6 > window.innerHeight ? r.top - h - 4 : r.bottom + 4) + 'px';
        floatingMenu = menu;
    }
    document.addEventListener('click', function (e) { if (floatingMenu && !floatingMenu.contains(e.target)) { closeMenu(); } });
    window.addEventListener('scroll', closeMenu, true);

    // ------------------------------------------------------------------ lista
    var tbody = document.getElementById('ifvRows');
    var searchInput = document.getElementById('ifvSearch');
    var selStatus = document.getElementById('ifvStatus');
    var selTara = document.getElementById('ifvTara');
    var selPeriod = document.getElementById('ifvPerioada');
    searchInput.value = state.q;
    selStatus.value = state.status;
    selPeriod.value = state.perioada;

    function syncUrl() {
        var u = new URL(location.href);
        ['q', 'tab', 'status', 'tara', 'perioada'].forEach(function (k) { if (state[k] && !(k === 'tab' && state[k] === 'toate')) { u.searchParams.set(k, state[k]); } else { u.searchParams.delete(k); } });
        if (state.page > 1) { u.searchParams.set('p', state.page); } else { u.searchParams.delete('p'); }
        if (state.per_page !== 10) { u.searchParams.set('per_page', state.per_page); } else { u.searchParams.delete('per_page'); }
        history.replaceState(null, '', u.toString());
    }

    function loadList() {
        if (listAbort) { listAbort.abort(); }
        listAbort = new AbortController();
        tbody.classList.add('is-busy');
        syncUrl();
        return getJson(url(CFG.urls.list, { q: state.q, tab: state.tab, status: state.status, tara: state.tara, perioada: state.perioada, p: state.page, per_page: state.per_page,
            fin_node: state.fin_node, fin_tip: state.fin_tip }), listAbort.signal)
            .then(function (data) {
                tbody.classList.remove('is-busy');
                if (!data.ok) { tbody.innerHTML = emptyRow('bi-exclamation-triangle', data.message || 'Lista nu a putut fi încărcată.'); return; }
                renderKpis(data);
                renderRows(data.rows);
                renderPager(data);
                renderCountries(data.tari);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') { return; }
                tbody.classList.remove('is-busy');
                tbody.innerHTML = emptyRow('bi-wifi-off', 'Conexiunea a eșuat. Reîncearcă.');
            });
    }

    function renderKpis(data) {
        var k = data.kpis;
        animateNumber(root.querySelector('[data-kpi="vehicule"]'), k.vehicule, '');
        // cardul „Valoare initiala” isi tine singur valoarea (navigare interna); primeste totalul si scopul paginii
        if (valueCard) {
            valueCard.update(k.valoare_initiala, { dossiers: k.dosare, incomplete: k.dosare_incomplete },
                { q: state.q, tab: state.tab, status: state.status, tara: state.tara, perioada: state.perioada });
        }
        animateNumber(root.querySelector('[data-kpi="capitalizat"]'), k.capitalizat, ' lei');
        animateNumber(root.querySelector('[data-kpi="necapitalizabile"]'), k.necapitalizabile, ' lei');
        animateNumber(root.querySelector('[data-kpi="derulare"]'), k.derulare, '');
        var note = root.querySelector('[data-kpi="capitalizat_note"]');
        note.textContent = k.capitalizat_vs_initial !== null && k.capitalizat_vs_initial !== undefined
            ? (k.capitalizat_vs_initial > 0 ? '+' : '') + String(k.capitalizat_vs_initial).replace('.', ',') + '% vs. inițial' : '';
        ['toate', 'derulare', 'finalizate'].forEach(function (key) { root.querySelector('[data-count="' + key + '"]').textContent = data.counts[key]; });
        root.querySelectorAll('.ifv-seg-btn').forEach(function (b) { b.classList.toggle('is-active', b.dataset.tab === state.tab); });
    }

    // „Valoare initiala (total)”: categorie aleasa in card → tabelul arata doar dosarele cu costuri in ea;
    // o tranzactie deschide fisa ei (acelasi panou ca la clic pe rand in Costuri).
    var valueCardEl = document.getElementById('ifvValueCard');
    var valueCard = window.IfvValueCard && valueCardEl ? window.IfvValueCard.mount(valueCardEl, {
        endpoint: CFG.urls.kpiBreakdown,
        onScope: function (scope) {
            if (String(scope.fin_node || '') === String(state.fin_node) && String(scope.fin_tip || '') === String(state.fin_tip)) { return; }
            state.fin_node = scope.fin_node ? String(scope.fin_node) : '';
            state.fin_tip = scope.fin_tip || '';
            state.page = 1;
            loadList();
        },
        onLine: function (lineId, dossierKey, rowEl, newTab) { openLineSheet(dossierKey, lineId, rowEl, newTab); }
    }) : null;

    function renderCountries(list) {
        var current = state.tara;
        var html = '<option value="">Toate</option>';
        (list || []).forEach(function (code) { html += '<option value="' + esc(code) + '">' + esc(countryName(code)) + '</option>'; });
        if (current && (list || []).indexOf(current) === -1) { html += '<option value="' + esc(current) + '">' + esc(countryName(current)) + '</option>'; }
        selTara.innerHTML = html;
        selTara.value = current;
    }

    function emptyRow(icon, text) { return '<tr class="ifv-empty-row"><td colspan="10"><i class="bi ' + icon + '"></i>' + esc(text) + '</td></tr>'; }

    function dotClass(row) {
        if (row.status === 'pus_in_functiune') { return ''; }
        if (row.status === 'fara_dosar') { return ' is-gray'; }
        if (row.status === 'anulat') { return ' is-red'; }
        return ' is-amber';
    }

    function rowHtml(row) {
        var isOpen = !!open[row.key];
        return '<tr class="ifv-row' + (isOpen ? ' is-open' : '') + '" data-key="' + esc(row.key) + '">'
            + '<td><button type="button" class="ifv-expander" data-expand aria-expanded="' + isOpen + '" aria-label="Deschide dosarul"><i class="bi bi-chevron-right"></i></button></td>'
            + '<td><div class="ifv-veh"><span class="ifv-veh-dot' + dotClass(row) + '"></span><span class="ifv-veh-text"><span class="ifv-veh-nr' + (row.provizoriu ? ' is-provisional' : '') + '" title="' + esc(row.tip_label) + '">' + esc(row.nr) + '</span><span class="ifv-veh-model">' + esc(row.model || row.tip_label) + '</span></span></div></td>'
            + '<td class="ifv-vin">' + (row.vin ? esc(row.vin) : '<span class="ifv-muted">—</span>') + '</td>'
            + '<td>' + (row.tara ? '<span class="ifv-country">' + flag(row.tara) + esc(countryName(row.tara)) + '</span>' : '<span class="ifv-muted">—</span>') + '</td>'
            + '<td>' + date(row.data_achizitie) + '</td>'
            + '<td class="is-num">' + lei(row.pret_achizitie) + '</td>'
            + '<td class="is-num">' + lei(row.costuri_aducere) + '</td>'
            + '<td class="is-num">' + (row.cost_initial === null ? lei(null) : '<span class="ifv-strong">' + lei(row.cost_initial) + '</span>') + '</td>'
            + '<td class="is-center"><span class="ifv-status is-' + (STATUS_CLASS[row.status] || 'amber') + '">' + esc(row.status_label) + '</span></td>'
            + '<td class="is-right"><button type="button" class="ifv-row-menu" data-row-menu aria-label="Acțiuni"><i class="bi bi-three-dots"></i></button></td>'
            + '</tr>';
    }

    function renderRows(rows) {
        rowsByKey = {};
        if (!rows.length) {
            tbody.innerHTML = emptyRow('bi-inboxes', state.q || state.status || state.tara || state.perioada || state.tab !== 'toate' || state.fin_node
                ? 'Niciun vehicul sau dosar pentru filtrele alese.' : 'Nu există încă vehicule sau dosare de achiziție.');
            return;
        }
        var html = '';
        rows.forEach(function (row) {
            rowsByKey[row.key] = row;
            html += rowHtml(row);
            if (open[row.key]) { html += detailRowHtml(row.key, true); }
        });
        tbody.innerHTML = html;
        Object.keys(open).forEach(function (key) {
            if (!rowsByKey[key]) { delete open[key]; return; }
            renderDetail(key);
        });
    }

    function renderPager(data) {
        var pager = document.getElementById('ifvPager');
        if (data.total <= 10 && data.pages <= 1) { pager.innerHTML = data.total ? '<span>' + data.total + ' înregistrări</span>' : ''; return; }
        var from = (data.page - 1) * data.per_page + 1, to = Math.min(data.total, data.page * data.per_page);
        var btns = '<button type="button" data-page="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '><i class="bi bi-chevron-left"></i></button>';
        for (var p = 1; p <= data.pages; p++) {
            if (data.pages > 7 && p > 2 && p < data.pages - 1 && Math.abs(p - data.page) > 1) {
                if (p === 3 || p === data.pages - 2) { btns += '<button type="button" disabled>…</button>'; }
                continue;
            }
            btns += '<button type="button" data-page="' + p + '" class="' + (p === data.page ? 'is-active' : '') + '">' + p + '</button>';
        }
        btns += '<button type="button" data-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? ' disabled' : '') + '><i class="bi bi-chevron-right"></i></button>';
        pager.innerHTML = '<span>' + from + '–' + to + ' din ' + data.total + '</span><div class="ifv-pager-btns">' + btns + '</div>'
            + '<label>Pe pagină <select data-per-page><option>10</option><option>25</option><option>50</option></select></label>';
        pager.querySelector('[data-per-page]').value = String(data.per_page);
    }

    document.getElementById('ifvPager').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-page]');
        if (!b || b.disabled) { return; }
        state.page = parseInt(b.dataset.page, 10);
        loadList();
        root.querySelector('.ifv-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    document.getElementById('ifvPager').addEventListener('change', function (e) {
        if (e.target.matches('[data-per-page]')) { state.per_page = parseInt(e.target.value, 10); state.page = 1; loadList(); }
    });

    // filtre
    var onSearch = debounce(function () { state.q = searchInput.value.trim(); state.page = 1; loadList(); }, 300);
    searchInput.addEventListener('input', onSearch);
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); searchInput.focus(); searchInput.select(); }
    });
    selStatus.addEventListener('change', function () { state.status = selStatus.value; state.page = 1; loadList(); });
    selTara.addEventListener('change', function () { state.tara = selTara.value; state.page = 1; loadList(); });
    selPeriod.addEventListener('change', function () { state.perioada = selPeriod.value; state.page = 1; loadList(); });
    root.querySelectorAll('.ifv-seg-btn').forEach(function (b) {
        b.addEventListener('click', function () { state.tab = b.dataset.tab; state.page = 1; loadList(); });
    });
    root.querySelectorAll('[data-kpi-tab]').forEach(function (card) {
        card.addEventListener('click', function () {
            state.tab = card.dataset.kpiTab; state.page = 1;
            root.querySelectorAll('.ifv-kpi').forEach(function (c) { c.classList.toggle('is-active', c === card && state.tab !== 'toate'); });
            loadList().then(function () { root.querySelector('.ifv-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        });
    });
    root.addEventListener('click', function (e) {
        var action = e.target.closest('[data-ifv-action]');
        if (!action) { return; }
        var a = action.dataset.ifvAction;
        if (a === 'create-dossier') { formDossier(null); }
        if (a === 'refresh') { loadList(); }
        if (a === 'reset') {
            state = { q: '', tab: 'toate', status: '', tara: '', perioada: '', page: 1, per_page: state.per_page };
            searchInput.value = ''; selStatus.value = ''; selPeriod.value = '';
            loadList();
        }
        if (a === 'collapse-all') { Object.keys(open).forEach(collapse); }
    });

    // ------------------------------------------------------------------ randuri: extindere / meniu
    tbody.addEventListener('click', function (e) {
        var tr = e.target.closest('tr.ifv-row');
        if (!tr) { return; }
        var key = tr.dataset.key;
        if (e.target.closest('[data-row-menu]')) {
            e.stopPropagation();
            rowMenu(e.target.closest('[data-row-menu]'), rowsByKey[key]);
            return;
        }
        open[key] ? collapse(key) : expand(key);
    });

    function rowMenu(anchor, row) {
        var c = CFG.can || {};
        var hasDossier = !!row.acquisition_id;
        showMenu(anchor, [
            { icon: 'bi-folder2-open', label: open[row.key] ? 'Restrânge dosarul' : (hasDossier ? 'Deschide dosarul' : 'Deschide fișa financiară'), run: function () { open[row.key] ? collapse(row.key) : expand(row.key); } },
            !hasDossier && c.manage ? { icon: 'bi-plus-lg', label: 'Creează dosar de achiziție', run: function () { formDossier(null, row); } } : null,
            hasDossier && c.manage ? { icon: 'bi-receipt', label: 'Adaugă cost', run: function () { withDetail(row.key, function (d) { formCost(row.key, d, null); }); } } : null,
            hasDossier && c.manage ? { icon: 'bi-cash-coin', label: 'Înregistrează plată', run: function () { withDetail(row.key, function (d) { formPayment(row.key, d, null); }); } } : null,
            hasDossier && c.manage ? { icon: 'bi-flag', label: 'Înregistrează etapă', run: function () { withDetail(row.key, function (d) { formEvent(row.key, d, null); }); } } : null,
            row.vehicle_id && c.proiecte ? { icon: 'bi-fuel-pump', label: 'Proiect investiție nou', run: function () { withDetail(row.key, function (d) { formProject(row.key, d); }); } } : null,
            row.vehicle_id ? '-' : null,
            row.vehicle_id ? { icon: 'bi-box-arrow-up-right', label: 'Fișa vehiculului', run: function () { window.open(url(location.pathname, { page: 'vehicule', action: 'show', id: row.vehicle_id }), '_blank'); } } : null
        ]);
    }

    function detailRowHtml(key, openNow) {
        return '<tr class="ifv-detail-row" data-detail-for="' + esc(key) + '"><td colspan="10"><div class="ifv-collapse' + (openNow ? ' is-open' : '') + '"><div class="ifv-collapse-inner">'
            + '<div class="ifv-dossier" data-dossier="' + esc(key) + '"><div class="ifv-panel"><span class="ifv-spinner"></span> Se încarcă dosarul…</div></div>'
            + '</div></div></td></tr>';
    }

    function rowEl(key) { return tbody.querySelector('tr.ifv-row[data-key="' + CSS.escape(key) + '"]'); }
    function detailEl(key) { return tbody.querySelector('tr.ifv-detail-row[data-detail-for="' + CSS.escape(key) + '"]'); }

    function expand(key) {
        var row = rowsByKey[key], tr = rowEl(key);
        if (!row || !tr) { return; }
        open[key] = { detail: null, tab: row.acquisition_id ? 'costuri' : 'investitii', cat: '', line: null, project: null };
        tr.classList.add('is-open');
        tr.querySelector('[data-expand]').setAttribute('aria-expanded', 'true');
        tr.insertAdjacentHTML('afterend', detailRowHtml(key, false));
        requestAnimationFrame(function () { requestAnimationFrame(function () { var c = detailEl(key); if (c) { c.querySelector('.ifv-collapse').classList.add('is-open'); } }); });
        fetchDetail(key);
    }

    function collapse(key) {
        var tr = rowEl(key), d = detailEl(key);
        delete open[key];
        if (tr) { tr.classList.remove('is-open'); tr.querySelector('[data-expand]').setAttribute('aria-expanded', 'false'); }
        if (!d) { return; }
        var c = d.querySelector('.ifv-collapse');
        c.classList.remove('is-open');
        setTimeout(function () { if (!open[key]) { d.remove(); } }, 380);
    }

    function fetchDetail(key) {
        var row = rowsByKey[key];
        return getJson(url(CFG.urls.detail, row.acquisition_id ? { acquisition_id: row.acquisition_id } : { vehicle_id: row.vehicle_id })).then(function (data) {
            if (!open[key]) { return; }
            if (!data.ok) { setPanelHtml(key, '<div class="ifv-panel"><div class="ifv-note is-warn">' + esc(data.message || 'Dosarul nu a putut fi încărcat.') + '</div></div>'); return; }
            open[key].detail = data.detail;
            renderDetail(key);
        });
    }

    function withDetail(key, fn) {
        if (open[key] && open[key].detail) { fn(open[key].detail); return; }
        if (!open[key]) { expand(key); }
        var tries = 0, t = setInterval(function () {
            if (open[key] && open[key].detail) { clearInterval(t); fn(open[key].detail); }
            if (++tries > 40) { clearInterval(t); }
        }, 100);
    }

    function setPanelHtml(key, html) {
        var box = tbody.querySelector('[data-dossier="' + CSS.escape(key) + '"]');
        if (box) { box.innerHTML = html; }
        return box;
    }

    // ------------------------------------------------------------------ dosar extins
    function renderDetail(key) {
        var st = open[key];
        if (!st || !st.detail) { return; }
        var d = st.detail;
        var hasDossier = !!d.acquisition_id;
        var tabs = hasDossier ? TABS : TABS.filter(function (t) { return ['documente', 'istoric', 'investitii'].indexOf(t.key) !== -1; });
        if (!tabs.some(function (t) { return t.key === st.tab; })) { st.tab = tabs[0].key; }

        var head = '<div class="ifv-dossier-head"><div class="ifv-dossier-title"><i class="bi bi-truck"></i>'
            + (hasDossier ? 'Dosar achiziție – ' + esc(d.titlu) : esc(d.titlu) + ' – fișă financiară')
            + (hasDossier && d.dosar.vehicle_id === null ? '<small>provizoriu</small>' : '') + '</div>';
        if (hasDossier) {
            head += '<div class="ifv-steps">' + d.stadii.map(function (s, i) {
                var clickable = CFG.can.manage && d.dosar.status !== 'anulat';
                return '<button type="button" class="ifv-step is-' + s.state + (clickable ? ' is-clickable' : '') + '" data-stage="' + s.key + '" title="' + esc(s.hint || (s.data ? 'Din ' + s.data.split('-').reverse().join('.') : s.label)) + '">'
                    + '<span class="ifv-step-dot">' + (s.state === 'done' ? '<i class="bi bi-check-lg"></i>' : '') + '</span>'
                    + '<span class="ifv-step-label">' + (i + 1) + '. ' + esc(s.label) + '</span></button>';
            }).join('') + '</div>';
        } else if (CFG.can.manage) {
            head += '<span class="ifv-muted" style="margin-left:auto">Vehiculul nu are dosar de achiziție.</span><button type="button" class="ifv-btn-outline" data-act="create-for-vehicle"><i class="bi bi-plus-lg"></i>Creează dosar</button>';
        }
        head += '</div>';

        var tabsHtml = '<div class="ifv-tabs" role="tablist">' + tabs.map(function (t) {
            var badge = t.key === 'investitii' && d.proiecte.length ? ' <span class="ifv-tab-badge">' + d.proiecte.length + '</span>' : '';
            return '<button type="button" role="tab" class="ifv-tab' + (t.key === st.tab ? ' is-active' : '') + '" data-tab-key="' + t.key + '"><i class="bi ' + t.icon + '"></i>' + esc(t.label) + badge + '</button>';
        }).join('') + '<span class="ifv-tabs-fill"></span></div>';

        var box = setPanelHtml(key, head + tabsHtml + '<div class="ifv-panel" data-panel>' + panelHtml(key) + '</div>');
        bindDetail(key, box);
    }

    function panelHtml(key) {
        var st = open[key], d = st.detail;
        switch (st.tab) {
            case 'costuri': return costsHtml(st, d);
            case 'detalii': return detailsHtml(d);
            case 'documente': return documentsHtml(d);
            case 'istoric': return historyHtml(d);
            case 'valoare': return valueHtml(d);
            case 'note': return notesHtml(d);
            case 'investitii': return projectsHtml(st, d);
        }
        return '';
    }

    function refreshPanel(key) {
        var box = tbody.querySelector('[data-dossier="' + CSS.escape(key) + '"]');
        if (!box) { return; }
        var panel = box.querySelector('[data-panel]');
        panel.style.animation = 'none';
        panel.offsetHeight; // reporneste animatia de morph
        panel.style.animation = '';
        panel.innerHTML = panelHtml(key);
    }
    // Filtrare / extindere linie in Costuri: fara morph-ul intregului panou, doar o tranzitie scurta pe randuri.
    function refreshCosts(key) {
        var box = tbody.querySelector('[data-dossier="' + CSS.escape(key) + '"]');
        if (!box) { return; }
        box.querySelector('[data-panel]').innerHTML = panelHtml(key);
        var rows = box.querySelectorAll('[data-cost-rows]');
        rows.forEach(function (r) { r.classList.add('is-swapping'); });
        requestAnimationFrame(function () { requestAnimationFrame(function () { rows.forEach(function (r) { r.classList.remove('is-swapping'); }); }); });
    }

    // ---- Costuri
    // Arbore: Categorie principala → Subcategorie → Tranzactii, ca grile imbricate (fara chip-uri / bara laterala).
    // Totalurile fiecarui nod vin de pe server (d.categorii_financiare[].totaluri) — aici nu se aduna nimic.
    // Starea de expandare: st.openNodes {idNod: bool}; se pastreaza la re-randare (editare, filtrare, detaliu linie).
    function finNodes(d) {
        var byId = {};
        (d.categorii_financiare || []).forEach(function (n) { byId[n.id] = n; });
        return byId;
    }
    function finRootOf(byId, id) {
        var n = byId[id], guard = 0;
        while (n && n.parent_id !== null && byId[n.parent_id] && guard++ < 20) { n = byId[n.parent_id]; }
        return n || null;
    }
    function isAncestor(byId, ancestorId, id) {
        var n = byId[id], guard = 0;
        while (n && guard++ < 20) { if (String(n.id) === String(ancestorId)) { return true; } n = n.parent_id !== null ? byId[n.parent_id] : null; }
        return false;
    }
    function finVisibleNode(n, st) {
        // frunzele goale (ex. „Alte costuri directe”) nu incarca lista, decat daca sunt selectate
        return n.nivel === 0 || n.totaluri.linii > 0 || !n.frunza || String(st.cat) === String(n.id);
    }
    // In filtru: nodul selectat, stramosii lui si descendentii lui.
    function finInScope(byId, n, selected) {
        return !selected || isAncestor(byId, n.id, selected.id) || isAncestor(byId, selected.id, n.id);
    }
    function finOpenPath(st, byId, id) {
        st.openNodes = st.openNodes || {};
        var n = byId[id], guard = 0;
        while (n && guard++ < 20) { st.openNodes[n.id] = true; n = n.parent_id !== null ? byId[n.parent_id] : null; }
    }
    function costsHtml(st, d) {
        var lines = d.linii || [];
        var byId = finNodes(d);
        if (st.cat && !byId[st.cat]) { st.cat = ''; }
        var selected = st.cat ? byId[st.cat] : null;
        var roots = (d.categorii_financiare || []).filter(function (n) { return n.nivel === 0; });
        if (!st.openNodes) {
            // implicit: categoriile principale deschise, subcategoriile inchise
            st.openNodes = {};
            roots.forEach(function (r) { st.openNodes[r.id] = true; });
        }
        var all = d.sumar ? d.sumar.pret_achizitie + d.sumar.cheltuieli_asociate : 0;

        var cats = '<label class="ifv-select ifv-cat-filter" title="Filtrează după categorie / subcategorie"><span>Categorie:</span><select data-cat-select aria-label="Categorie">'
            + catOption('', 'Toate', all, !st.cat, 0);
        (d.categorii_financiare || []).forEach(function (n) {
            if (finVisibleNode(n, st)) { cats += catOption(n.id, n.denumire, n.totaluri.total, String(st.cat) === String(n.id), n.nivel); }
        });
        cats += '</select></label>';

        var inScope = lines.filter(function (l) { return !st.cat || (l.fin_cale || []).indexOf(Number(st.cat)) !== -1; });
        var active = inScope.filter(function (l) { return !l.anulat; }).length;
        var cancelled = inScope.length - active;
        var title = selected ? selected.denumire : 'Toate costurile';
        var count = '(' + active + ' înregistr' + (active === 1 ? 'are' : 'ări') + (cancelled ? ' · ' + cancelled + ' anulat' + (cancelled === 1 ? 'ă' : 'e') : '') + ')';

        var body;
        if (!roots.length) {
            body = txGridHtml(st, lines, null, 'Dosarul nu are încă înregistrări de cost.'); // schema fara arbore: grila simpla
        } else {
            var rootRows = roots.filter(function (r) { return finVisibleNode(r, st) && finInScope(byId, r, selected); })
                .map(function (r) { return nodeRowsHtml(st, d, byId, r, selected, lines, 6); }).join('');
            // Linii fara categorie cunoscuta: raman accesibile, nu dispar
            var orphans = st.cat ? [] : lines.filter(function (l) { return !l.fin_id || !byId[l.fin_id]; });
            if (orphans.length) { rootRows += orphanRowsHtml(st, orphans); }
            body = '<table class="ifv-tree ifv-tree-l0"><colgroup><col class="ifv-col-chev"><col><col class="ifv-col-num"><col class="ifv-col-num"><col class="ifv-col-num"><col class="ifv-col-act"></colgroup>'
                + '<thead><tr><th></th><th>Categorie principală</th><th class="is-num">Total</th><th class="is-num">Capitalizabil</th><th class="is-num">Necapitalizabil</th><th></th></tr></thead>'
                + '<tbody>' + (rootRows || '<tr><td colspan="6" class="ifv-grid-empty">Nicio înregistrare în această categorie.</td></tr>') + '</tbody></table>';
        }

        var grid = '<div><div class="ifv-grid-card"><div class="ifv-grid-head"><div class="ifv-grid-title"><h4>' + esc(title) + ' <small>' + count + '</small></h4>' + cats + '</div>'
            + (CFG.can.manage ? '<button type="button" class="ifv-btn-outline" data-act="add-cost"><i class="bi bi-plus-lg"></i>Adaugă înregistrare</button>' : '') + '</div>'
            + body + '</div></div>';

        var s = d.sumar || {};
        var v = d.valoare || {};
        var foot = '<div class="ifv-foot">'
            + footItem('bi-layers', 'Cost inițial total', lei(round2((s.pret_achizitie || 0) + (s.cheltuieli_asociate || 0))))
            + footItem('bi-calculator', 'Capitalizat (valoare de intrare)', lei(s.cost_initial_capitalizabil))
            + footItem('bi-coin', 'Cheltuieli necapitalizabile', lei(s.necapitalizabile))
            + footItem('bi-bar-chart-line', 'Valoare actuală (estimativă)', v.valoare_actuala !== null && v.valoare_actuala !== undefined ? lei(v.valoare_actuala) : null,
                'Nedisponibilă — amortizare neaprobată')
            + '</div>';
        return '<div class="ifv-costs">' + grid + foot + '</div>'
            + (s.incomplet && s.incomplet.length ? '<div class="ifv-note is-warn" style="margin-top:10px"><i class="bi bi-exclamation-triangle"></i> ' + s.incomplet.map(esc).join(' · ') + '</div>' : '');
    }

    // Un nod (categorie principala sau subcategorie): randul lui + continutul imbricat (subcategorii sau tranzactii).
    function nodeRowsHtml(st, d, byId, n, selected, lines, cols) {
        var t = n.totaluri;
        var isOpen = !!st.openNodes[n.id];
        var regionId = 'ifv-node-' + n.id + '-' + (d.acquisition_id || d.vehicle_id || 0);
        var children = (n.copii || []).map(function (id) { return byId[id]; })
            .filter(function (c) { return c && finVisibleNode(c, st) && finInScope(byId, c, selected); });
        var nodeLines = lines.filter(function (l) { return (l.fin_cale || []).indexOf(n.id) !== -1; });
        var cancelled = nodeLines.filter(function (l) { return l.anulat; }).length;
        var noRate = Object.keys(t.fara_curs || {}).map(function (c) { return nf2.format(t.fara_curs[c]) + ' ' + c; });
        var isRoot = n.nivel === 0;

        var row = '<tr class="ifv-node' + (isRoot ? ' is-root' : ' is-sub') + (isOpen ? ' is-open' : '') + (t.linii ? '' : ' is-empty') + '" data-node-row="' + n.id + '" title="' + esc(finTitle(n)) + '">'
            + '<td class="is-center"><button type="button" class="ifv-chev" data-node-toggle="' + n.id + '" aria-expanded="' + isOpen + '" aria-controls="' + regionId + '" aria-label="' + (isOpen ? 'Restrânge' : 'Extinde') + ' ' + esc(n.denumire) + '"><i class="bi bi-chevron-right"></i></button></td>'
            + '<td class="ifv-node-name">' + esc(n.denumire) + (cancelled ? ' <span class="ifv-muted ifv-node-note">· ' + cancelled + ' anulat' + (cancelled === 1 ? 'ă' : 'e') + '</span>' : '') + '</td>'
            + '<td class="is-num ifv-node-total">' + lei(t.total)
            + (noRate.length ? ' <i class="bi bi-exclamation-triangle ifv-warn-ico" title="Fără curs de schimb (nu intră în total): ' + esc(noRate.join(' · ')) + '"></i>' : '') + '</td>'
            + '<td class="is-num">' + (t.capitalizabil ? lei(t.capitalizabil) : '<span class="ifv-muted">—</span>') + '</td>'
            + '<td class="is-num">' + (t.necapitalizabil ? lei(t.necapitalizabil) : '<span class="ifv-muted">—</span>') + '</td>'
            + '<td class="is-center">' + (CFG.can.manage && n.activ ? '<button type="button" class="ifv-dots" data-act="add-cost" data-node="' + n.id + '" title="Adaugă înregistrare în ' + esc(n.denumire) + '" aria-label="Adaugă înregistrare în ' + esc(n.denumire) + '"><i class="bi bi-plus-lg"></i></button>' : '') + '</td></tr>';

        var inner;
        if (children.length) {
            inner = '<table class="ifv-tree ifv-tree-sub"><colgroup><col class="ifv-col-chev"><col><col class="ifv-col-num"><col class="ifv-col-num"><col class="ifv-col-num"><col class="ifv-col-act"></colgroup>'
                + '<tbody>' // fara antet propriu: coloanele sunt cele ale categoriei principale
                + children.map(function (c) { return nodeRowsHtml(st, d, byId, c, selected, lines, 6); }).join('') + '</tbody></table>';
        } else {
            inner = txGridHtml(st, nodeLines, n, 'Nicio înregistrare în această subcategorie.');
        }
        return row + '<tr class="ifv-node-nest' + (isRoot ? ' is-root' : '') + '"><td colspan="' + cols + '"><div class="ifv-collapse' + (isOpen ? ' is-open' : '') + '" id="' + regionId + '" role="region" aria-label="' + esc(n.denumire) + '">'
            + '<div class="ifv-collapse-inner"><div class="ifv-nest">' + inner + '</div></div></div></td></tr>';
    }

    function orphanRowsHtml(st, orphans) {
        var isOpen = st.openNodes.orfane !== false;
        return '<tr class="ifv-node is-root' + (isOpen ? ' is-open' : '') + '" data-node-row="orfane"><td class="is-center"><button type="button" class="ifv-chev" data-node-toggle="orfane" aria-expanded="' + isOpen + '" aria-label="Fără categorie"><i class="bi bi-chevron-right"></i></button></td>'
            + '<td class="ifv-node-name">Fără categorie</td><td></td><td></td><td></td><td></td></tr>'
            + '<tr class="ifv-node-nest is-root"><td colspan="6"><div class="ifv-collapse' + (isOpen ? ' is-open' : '') + '"><div class="ifv-collapse-inner"><div class="ifv-nest">'
            + txGridHtml(st, orphans, null, '') + '</div></div></div></td></tr>';
    }

    // Grila de tranzactii (nivelul 3): coloanele existente, fara „Subcategorie” (o da nivelul de deasupra).
    function txGridHtml(st, lines, n, emptyText) {
        var ordered = lines.filter(function (l) { return !l.anulat; }).concat(lines.filter(function (l) { return l.anulat; }));
        var rows = ordered.map(function (l) { return lineHtml(l, st.line === l.id); }).join('');
        if (!ordered.length) {
            rows = '<tr><td colspan="8" class="ifv-grid-empty">' + esc(emptyText)
                + (n && CFG.can.manage && n.activ ? ' <button type="button" class="ifv-link-btn" data-act="add-cost" data-node="' + n.id + '"><i class="bi bi-plus-lg"></i> Adaugă</button>' : '') + '</td></tr>';
        }
        return '<table class="ifv-grid ifv-tx"><colgroup><col style="width:9.6%"><col style="width:25.9%"><col style="width:16.4%"><col style="width:11.5%"><col style="width:8%"><col style="width:12.5%"><col style="width:11.5%"><col style="width:4.6%"></colgroup>'
            + '<thead><tr><th>Dată</th><th>Descriere</th><th>Furnizor</th><th class="is-num">Valoare (lei)</th><th class="is-num">TVA</th><th>Tratament</th><th>Document</th><th></th></tr></thead>'
            + '<tbody data-cost-rows>' + rows + '</tbody></table>';
    }

    function finTitle(n) {
        var t = n.totaluri;
        return n.denumire + ': ' + nf2.format(t.total) + ' lei · capitalizabil ' + nf2.format(t.capitalizabil) + ' · necapitalizabil ' + nf2.format(t.necapitalizabil)
            + (t.de_confirmat ? ' · de confirmat ' + nf2.format(t.de_confirmat) : '') + (t.neverificat ? ' · neverificat ' + nf2.format(t.neverificat) : '')
            + (t.platit ? ' · plătit (cu TVA) ' + nf2.format(t.platit) : '') + ' · ' + t.linii + ' înregistr' + (t.linii === 1 ? 'are' : 'ări');
    }
    function round2(v) { return Math.round(v * 100) / 100; }
    function catOption(value, label, total, selected, level) {
        var pad = level ? new Array(level + 1).join(' ') : '';
        return '<option value="' + esc(value) + '"' + (selected ? ' selected' : '') + '>' + pad + esc(label) + ' — ' + nf0.format(total || 0) + ' lei</option>';
    }
    function footItem(icon, label, value, naText) {
        return '<div class="ifv-foot-item"><i class="bi ' + icon + '"></i><div><span>' + esc(label) + '</span>'
            + (value === null ? '<b class="is-na">' + esc(naText) + '</b>' : '<b>' + value + '</b>') + '</div></div>';
    }
    function docLink(l) {
        var label = l.document_numar ? (l.document_tip ? l.document_tip + ' ' : '') + l.document_numar : (l.are_fisier ? 'Document' : '');
        if (!label) { return '<span class="ifv-doc is-none">—</span>'; }
        if (!l.are_fisier) { return '<span class="ifv-doc is-none" title="Fără fișier atașat"><i class="bi bi-file-earmark-text"></i><span style="text-decoration:none">' + esc(label) + '</span></span>'; }
        return '<a class="ifv-doc" href="' + esc(url(CFG.urls.file, { sursa: 'linie', id: l.id })) + '" data-preview="' + esc(label) + '"><i class="bi bi-file-earmark-text"></i><span>' + esc(label) + '</span><i class="bi bi-box-arrow-up-right"></i></a>';
    }
    function lineHtml(l, isOpen) {
        var desc = l.descriere || l.categorie_label;
        var html = '<tr class="ifv-line' + (isOpen ? ' is-open' : '') + (l.inclus_in ? ' is-included' : '') + (l.anulat ? ' is-cancelled' : '') + '" data-line="' + l.id + '">'
            + '<td>' + date(l.data) + '</td>'
            + '<td title="' + esc(desc) + '">' + esc(desc) + (l.anulat ? ' <span class="ifv-pill is-cancelled is-small">anulată</span>' : (l.status_verificare === 'estimat' ? ' <span class="ifv-pill is-estimat is-small">estimat</span>' : '')) + '</td>'
            + '<td title="' + esc(l.furnizor || '') + '">' + (l.furnizor ? esc(l.furnizor) : '<span class="ifv-muted">—</span>') + '</td>'
            + '<td class="is-num">' + (l.suma === null ? '<span title="Fără curs de schimb">' + nf2.format(l.suma_originala) + ' ' + esc(l.moneda) + '</span>' : nf2.format(l.suma)) + '</td>'
            + '<td class="is-num">' + (l.tva === null ? '—' : nf2.format(l.tva)) + '</td>'
            + '<td><span class="ifv-pill is-' + esc(l.tratament) + '">' + esc(l.tratament_label) + '</span></td>'
            + '<td>' + docLink(l) + '</td>'
            + '<td class="is-center"><button type="button" class="ifv-dots" data-line-menu aria-label="Acțiuni linie"><i class="bi bi-three-dots"></i></button></td></tr>';
        return html;
    }
    function kv(label, value) { return '<div class="ifv-kv"><span>' + esc(label) + '</span><b>' + esc(value == null || value === '' ? '—' : value) + '</b></div>'; }

    // ---- Detalii
    function detailsHtml(d) {
        var a = d.dosar, t = d.transport;
        var edit = CFG.can.manage;
        var box1 = '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-person-vcard"></i>Vânzător & vehicul</h5>'
            + (edit ? '<button type="button" class="ifv-btn-ghost" data-act="edit-dossier"><i class="bi bi-pencil"></i>Editează</button>' : '') + '</div><dl class="ifv-dl">'
            + dl('Cod dosar', a.cod_dosar) + dl('Tip vehicul', d.tip_label) + dl('Stare', a.stare === 'nou' ? 'Nou' : 'Rulat')
            + dl('VIN', a.vin) + dl('Marcă / model', [a.marca, a.model].filter(Boolean).join(' ') || (d.vehicul ? d.vehicul.marca + ' ' + d.vehicul.model : ''))
            + dl('An fabricație', a.an_fabricatie) + dl('Vânzător', a.vanzator_nume) + dl('Cod fiscal vânzător', a.vanzator_cod_fiscal)
            + dl('Țara vânzătorului', countryName(a.vanzator_tara)) + dl('Locația vehiculului', a.locatie_vehicul) + dl('Cumpărător', a.cumparator_entitate || 'Compania proprie')
            + dl('Vehicul în flotă', d.vehicul ? d.vehicul.nr_inmatriculare : 'Neînmatriculat (provizoriu)') + '</dl></div>';
        var box2 = '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-file-earmark-text"></i>Contract & achiziție</h5>'
            + (edit ? '<div style="display:flex;gap:6px"><button type="button" class="ifv-btn-ghost" data-act="status"><i class="bi bi-flag"></i>Status</button>'
                + (!d.vehicul ? '<button type="button" class="ifv-btn-ghost" data-act="link-vehicle"><i class="bi bi-link-45deg"></i>Leagă vehiculul</button>' : '') + '</div>' : '') + '</div><dl class="ifv-dl">'
            + dl('Status', ({ pus_in_functiune: 'Finalizat' })[a.status] || (STATUS_OPTIONS.filter(function (s) { return s[0] === a.status; })[0] || [0, a.status])[1])
            + dl('Modalitate', ({ plata_integrala: 'Plată integrală', plata_esalonata: 'Plată eșalonată', leasing_financiar: 'Leasing financiar', leasing_operational: 'Leasing operațional', credit: 'Credit', altul: 'Altă modalitate' })[a.modalitate_achizitie])
            + dl('Regim TVA', ({ normal: 'Normal', taxare_inversa: 'Taxare inversă', intracomunitar: 'Intracomunitar', import: 'Import', regim_marja: 'Regim marjă', scutit: 'Scutit', de_confirmat: 'De confirmat' })[a.regim_tva])
            + dl('Data contract', a.data_contract ? a.data_contract.split('-').reverse().join('.') : '') + dl('Data factură', a.data_factura ? a.data_factura.split('-').reverse().join('.') : '')
            + dl('Transfer proprietate', a.data_transfer_proprietate ? a.data_transfer_proprietate.split('-').reverse().join('.') : '')
            + dl('Preț contract', a.pret_achizitie !== null ? nf2.format(a.pret_achizitie) + ' ' + a.moneda : '') + dl('Curs original', a.curs_schimb ? String(a.curs_schimb).replace('.', ',') + (a.curs_data ? ' (' + a.curs_data.split('-').reverse().join('.') + (a.curs_sursa ? ', ' + a.curs_sursa : '') + ')' : '') : '')
            + dl('Țara achiziției / înmatriculării', [countryName(a.tara_achizitie), countryName(a.tara_inmatriculare)].filter(Boolean).join(' → '))
            + dl('Utilizare prevăzută', a.utilizare_prevazuta) + '</dl></div>';
        var transport = '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-truck"></i>Transport & aducere</h5>'
            + (edit ? '<button type="button" class="ifv-btn-ghost" data-act="transport"><i class="bi bi-pencil"></i>' + (t ? 'Editează' : 'Completează') + '</button>' : '') + '</div>'
            + (t ? '<dl class="ifv-dl">' + dl('Mod', t.modalitate === 'condus' ? 'Condus până la destinație' : 'Transportator extern')
                + (t.modalitate === 'condus' ? dl('Șofer', t.sofer || t.sofer_nume) + dl('Numere provizorii', t.numere_provizorii) + dl('Asigurare tranzit', t.asigurare_tranzit_ref)
                    : dl('Transportator', t.transportator) + dl('Comandă transport', t.comanda_transport) + dl('Servicii incluse în preț', t.servicii_incluse))
                + dl('Preluare', t.loc_preluare) + dl('Destinație', t.loc_destinatie) + dl('Plecare / sosire', [t.data_plecare, t.data_sosire].filter(Boolean).map(function (x) { return x.split('-').reverse().join('.'); }).join(' → '))
                + dl('Livrare confirmată', t.livrare_confirmata_la ? t.livrare_confirmata_la.split('-').reverse().join('.') : 'Neconfirmată') + '</dl>'
                : '<div class="ifv-note">Modul de aducere nu este încă înregistrat (condus sau prin transportator).</div>') + '</div>';
        var payments = '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-cash-coin"></i>Plăți</h5>'
            + (edit ? '<button type="button" class="ifv-btn-ghost" data-act="add-payment"><i class="bi bi-plus-lg"></i>Plată</button>' : '') + '</div>'
            + (d.plati.length ? '<table class="ifv-list"><thead><tr><th>Dată</th><th>Referință</th><th>Metodă</th><th class="is-num">Sumă</th></tr></thead><tbody>'
                + d.plati.map(function (p) { return '<tr><td>' + date(p.data_plata) + '</td><td>' + esc(p.referinta || '—') + '</td><td>' + esc(p.metoda || '—') + '</td><td class="is-num">' + nf2.format(p.suma) + ' ' + esc(p.moneda) + '</td></tr>'; }).join('')
                + '</tbody></table>' : '<div class="ifv-note">Nicio plată înregistrată.</div>')
            + (d.sumar ? '<div class="ifv-note" style="margin-top:8px">Total documente ' + nf2.format(d.sumar.total_document) + ' lei · plătit ' + nf2.format(d.sumar.platit) + ' lei · <b>sold ' + nf2.format(d.sumar.sold_neachitat) + ' lei</b></div>' : '')
            + '</div>';
        var events = '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-signpost-split"></i>Etape (separate)</h5>'
            + (edit ? '<button type="button" class="ifv-btn-ghost" data-act="add-event"><i class="bi bi-plus-lg"></i>Etapă</button>' : '') + '</div><table class="ifv-list"><tbody>'
            + Object.keys(EVENT_LABELS).map(function (k) { return '<tr><td>' + esc(EVENT_LABELS[k]) + '</td><td class="is-num">' + (d.evenimente[k] ? date(d.evenimente[k]) : '<span class="ifv-muted">neînregistrată</span>') + '</td></tr>'; }).join('')
            + '</tbody></table></div>';
        return '<div class="ifv-two"><div>' + box1 + box2 + '</div><div>' + transport + payments + events + '</div></div>';
    }
    function dl(label, value) { return '<div><dt>' + esc(label) + '</dt><dd>' + (value === null || value === undefined || value === '' ? '<span class="ifv-muted">—</span>' : esc(value)) + '</dd></div>'; }

    // ---- Documente
    function documentsHtml(d) {
        var docs = d.documente || [];
        var today = new Date().toISOString().slice(0, 10);
        var rows = docs.map(function (doc) {
            var href = doc.sursa === 'dosar' ? url(CFG.urls.file, { sursa: 'dosar', id: doc.id }) : (doc.url || '');
            var expired = doc.expira && doc.expira < today;
            return '<tr><td>' + esc(doc.tip) + '</td><td>' + esc(doc.numar || '—') + '</td><td>' + (doc.sursa === 'dosar' ? 'Dosar achiziție' : 'Documente vehicul' + (doc.proiect_id ? ' · proiect #' + doc.proiect_id : '')) + '</td>'
                + '<td>' + (doc.expira ? '<span style="color:' + (expired ? '#b42318' : 'inherit') + '">' + date(doc.expira) + (expired ? ' (expirat)' : '') + '</span>' : '<span class="ifv-muted">—</span>') + '</td>'
                + '<td>' + (doc.are_fisier && href ? '<a class="ifv-doc" href="' + esc(href) + '" data-preview="' + esc(doc.tip + ' ' + (doc.numar || '')) + '"><i class="bi bi-file-earmark-text"></i><span>' + esc(doc.fisier || 'Deschide') + '</span><i class="bi bi-box-arrow-up-right"></i></a>' : '<span class="ifv-muted">fără fișier</span>') + '</td></tr>';
        }).join('');
        return '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-folder2-open"></i>Documente (' + docs.length + ')</h5><div style="display:flex;gap:6px">'
            + (d.acquisition_id && CFG.can.manage ? '<button type="button" class="ifv-btn-outline" data-act="upload-doc"><i class="bi bi-upload"></i>Încarcă document</button>' : '')
            + (d.vehicle_id ? '<a class="ifv-btn-ghost" href="' + esc(url(CFG.urls.documente, {})) + '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Pagina Documente</a>' : '') + '</div></div>'
            + (docs.length ? '<table class="ifv-list"><thead><tr><th>Tip</th><th>Număr</th><th>Sursă</th><th>Expiră</th><th>Fișier</th></tr></thead><tbody>' + rows + '</tbody></table>'
                : '<div class="ifv-note">Nu există documente. Documentele dosarului se încarcă aici; certificatele cu valabilitate (ADR, ITP, IPROCHIM…) rămân în pagina Documente.</div>')
            + '</div>';
    }

    // ---- Istoric
    var AUDIT_LABELS = { creare: 'Creare', modificare: 'Modificare', status: 'Status', eveniment: 'Etapă', transport: 'Transport', plata: 'Plată', legare_vehicul: 'Vehicul legat',
        tratament_contabil: 'Tratament contabil', status_verificare: 'Verificare', tva: 'TVA' };
    function historyHtml(d) {
        var audit = (d.istoric || []).map(function (h) {
            var what = (AUDIT_LABELS[h.actiune] || h.actiune) + (h.camp && h.camp !== h.actiune ? ' · ' + h.camp : '');
            var change = h.valoare_veche !== null || h.valoare_noua !== null ? ': ' + (h.valoare_veche !== null ? esc(h.valoare_veche) + ' → ' : '') + esc(h.valoare_noua || '—') : '';
            return '<li><time>' + date(h.created_at) + ' ' + esc(String(h.created_at).slice(11, 16)) + '</time><b>' + esc(what) + '</b>' + change
                + (h.entitate === 'achizitie_cheltuiala' ? ' <span class="ifv-muted">(linia #' + h.entitate_id + ')</span>' : '')
                + (h.motiv ? '<div class="ifv-muted">„' + esc(h.motiv) + '”</div>' : '') + '<div class="ifv-muted">' + esc(h.utilizator || 'sistem') + '</div></li>';
        }).join('');
        var CLASS = { operational: 'Operațional', capital: 'Capital', achizitie: 'Achiziție', tva: 'TVA', finantare: 'Finanțare' };
        var fin = (d.istoric_financiar || []).map(function (r) {
            return '<tr><td>' + date(r.data) + '</td><td>' + esc(CLASS[r.clasa] || r.clasa) + '</td><td title="' + esc(r.descriere) + '" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(r.descriere || r.categorie) + '</td>'
                + '<td>' + (r.tratament_contabil ? '<span class="ifv-pill is-small is-' + esc(r.tratament_contabil) + '">' + esc(({ capitalizabil: 'Capitalizabil', cheltuiala: 'Chelt. exploatare', de_confirmat: 'De confirmat' })[r.tratament_contabil]) + '</span>' : '') + '</td>'
                + '<td class="is-num">' + nf2.format(r.suma) + '</td></tr>';
        }).join('');
        var totals = d.istoric_totaluri ? Object.keys(d.istoric_totaluri).map(function (k) { return esc(CLASS[k] || k) + ': <b>' + nf2.format(d.istoric_totaluri[k]) + ' lei</b>'; }).join(' · ') : '';
        return '<div class="ifv-two">'
            + (d.acquisition_id ? '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-clock-history"></i>Jurnalul dosarului</h5></div>' + (audit ? '<ul class="ifv-timeline">' + audit + '</ul>' : '<div class="ifv-note">Nicio modificare înregistrată.</div>') + '</div>' : '')
            + '<div class="ifv-box"' + (d.acquisition_id ? '' : ' style="grid-column:1/-1"') + '><div class="ifv-box-head"><h5><i class="bi bi-journal-text"></i>Istoric financiar vehicul</h5></div>'
            + (d.vehicle_id ? (fin ? '<div style="max-height:420px;overflow:auto"><table class="ifv-list"><thead><tr><th>Dată</th><th>Clasă</th><th>Descriere</th><th>Tratament</th><th class="is-num">Sumă (lei)</th></tr></thead><tbody>' + fin + '</tbody></table></div>'
                + '<div class="ifv-note" style="margin-top:8px">' + totals + '. Fiecare tranzacție apare o singură dată (sursă + id).</div>' : '<div class="ifv-note">Nicio tranzacție pe acest vehicul.</div>')
                : '<div class="ifv-note">Istoricul financiar apare după legarea dosarului de vehiculul înmatriculat.</div>')
            + '</div></div>';
    }

    // ---- Valoare & Amortizare
    function valueHtml(d) {
        var v = d.valoare || {};
        return '<div class="ifv-value-grid">'
            + valueBox('Cost inițial total', v.cost_initial, '') + valueBox('Capitalizat la achiziție', v.capitalizat_achizitie, 'is-green') + valueBox('Cheltuieli necapitalizabile', v.necapitalizabile, 'is-orange')
            + valueBox('Investiții capitalizate (proiecte)', v.investitii_capitalizate, 'is-green') + valueBox('Investiții cu tratament neconfirmat', v.investitii_neconfirmate, '')
            + valueBox('Valoare de intrare totală', v.valoare_intrare_totala, '') + '</div>'
            + '<div class="ifv-note is-warn" style="margin-top:12px"><i class="bi bi-info-circle"></i> <b>Valoare actuală: nedisponibilă.</b> ' + esc(v.motiv_valoare_actuala || '')
            + (v.proiecte_fara_durata ? ' ' + v.proiecte_fara_durata + ' proiect(e) fără durată utilă aprobată.' : '') + ' Nu se estimează valoarea de piață din suma cheltuielilor.</div>';
    }
    function valueBox(label, value, cls) { return '<div class="ifv-value ' + cls + '"><span>' + esc(label) + '</span><b>' + (value === null || value === undefined ? '<span class="ifv-muted">—</span>' : nf2.format(value) + ' lei') + '</b></div>'; }

    // ---- Note
    function notesHtml(d) {
        var a = d.dosar || {};
        return '<div class="ifv-box"><div class="ifv-box-head"><h5><i class="bi bi-chat-left-text"></i>Note dosar</h5>'
            + (CFG.can.manage ? '<button type="button" class="ifv-btn-outline" data-act="save-notes"><i class="bi bi-check2"></i>Salvează nota</button>' : '') + '</div>'
            + '<textarea class="ifv-textarea" data-notes' + (CFG.can.manage ? '' : ' readonly') + ' placeholder="Observații despre achiziție, negociere, condiții…">' + esc(a.observatii || '') + '</textarea>'
            + '<div class="ifv-note" style="margin-top:8px">Modificările notei rămân în jurnalul dosarului (tab Istoric).</div></div>';
    }

    // ---- Investiții GPL
    function projectsHtml(st, d) {
        var list = (d.proiecte || []).map(function (p) {
            var pr = p.proiect, t = p.totaluri, r = p.pregatire, isOpen = st.project === Number(pr.id);
            var budget = t.buget_planificat, pct = budget ? Math.min(100, t.cost_real / budget * 100) : 0;
            var html = '<div class="ifv-proj' + (isOpen ? ' is-open' : '') + '" data-project="' + pr.id + '"><div class="ifv-proj-head" data-project-toggle>'
                + '<span class="ifv-proj-icon"><i class="bi bi-fuel-pump"></i></span>'
                + '<div class="ifv-proj-name"><b>' + esc(pr.denumire) + '</b><span>' + esc(p.categorie_label) + ' · ' + esc((PROJECT_STATUSES.filter(function (s) { return s[0] === pr.status; })[0] || [0, pr.status])[1]) + (pr.data_start ? ' · din ' + pr.data_start.split('-').reverse().join('.') : '') + '</span></div>'
                + '<div class="ifv-proj-num"><span>Buget planificat</span><b>' + (budget !== null ? nf0.format(budget) + ' lei' : '—') + '</b></div>'
                + '<div class="ifv-proj-num"><span>Cost real (surse)</span><b>' + nf0.format(t.cost_real) + ' lei</b></div>'
                + '<div class="ifv-proj-num"><span>Diferență</span><b style="color:' + (t.diferenta_buget !== null && t.diferenta_buget < 0 ? '#b42318' : 'inherit') + '">' + (t.diferenta_buget !== null ? nf0.format(t.diferenta_buget) + ' lei' : '—') + '</b></div>'
                + '<span class="ifv-status is-' + (r.apt_legal ? 'green' : 'amber') + '" style="font-size:12.5px;height:24px">' + (r.apt_legal ? 'Apt GPL' : 'Neaprobat GPL') + '</span>'
                + '<i class="bi bi-chevron-down ifv-proj-chev"></i></div>';
            if (isOpen) {
                var sources = t.surse.map(function (s) {
                    return '<tr><td>' + date(s.data) + '</td><td>' + esc(({ mentenanta: 'Lucrare (Reparații)', cheltuiala: 'Cheltuială' })[s.sursa] || s.sursa) + ' #' + s.sursa_id + '</td><td>'
                        + (s.tratament_contabil ? '<span class="ifv-pill is-small is-' + esc(s.tratament_contabil) + '">' + esc(({ capitalizabil: 'Capitalizabil', cheltuiala: 'Chelt. exploatare', de_confirmat: 'De confirmat' })[s.tratament_contabil]) + '</span>' : '') + '</td><td class="is-num">' + nf2.format(s.suma) + '</td></tr>';
                }).join('');
                var pending = t.neinregistrate.map(function (n) { return '<li>' + esc(n.sursa === 'interventie' ? 'Intervenția #' + n.sursa_id + ' (' + n.status + ')' : 'Factura OCR #' + n.sursa_id + ' — netrimisă în Reparații') + '</li>'; }).join('');
                var stages = Object.keys(r.stadii).map(function (k) {
                    var s = r.stadii[k], done = !!s.confirmat_la;
                    return '<li class="' + (done ? 'is-done' : 'is-todo') + '"><i class="bi ' + (done ? 'bi-check-circle-fill' : 'bi-circle') + '"></i>' + esc(s.eticheta)
                        + (done ? ' <span class="ifv-muted">· ' + s.confirmat_la.split('-').reverse().join('.') + '</span>' : '')
                        + (CFG.can.proiecte ? '<button type="button" data-stage-confirm="' + k + '">' + (done ? 'Modifică' : 'Confirmă') + '</button>' : '') + '</li>';
                }).join('');
                var docs = Object.keys(r.documente).map(function (k) { var doc = r.documente[k]; return '<li class="' + (doc.stare === 'valabil' ? 'is-done' : 'is-todo') + '"><i class="bi ' + (doc.stare === 'valabil' ? 'bi-file-earmark-check' : 'bi-file-earmark-x') + '"></i>' + esc(k) + ' <span class="ifv-muted">· ' + (doc.stare === 'lipsa' ? 'lipsă' : doc.stare + (doc.data_expirare ? ' până la ' + doc.data_expirare.split('-').reverse().join('.') : '')) + '</span></li>'; }).join('');
                html += '<div class="ifv-proj-body"><div>'
                    + '<div style="display:flex;justify-content:space-between;font-size:12.5px"><span>Consum buget</span><span>' + (budget ? Math.round(t.cost_real / budget * 100) + '%' : 'fără buget') + '</span></div>'
                    + '<div class="ifv-budget"><i class="' + (budget && t.cost_real > budget ? 'is-over' : '') + '" style="width:' + pct + '%"></i></div>'
                    + '<div class="ifv-muted" style="font-size:12px;margin-bottom:8px">Piese ' + nf0.format(t.piese) + ' · manoperă ' + nf0.format(t.manopera) + ' · servicii externe ' + nf0.format(t.servicii_externe) + ' · alte ' + nf0.format(t.alte_costuri_lucrari) + ' lei</div>'
                    + (sources ? '<table class="ifv-list"><thead><tr><th>Dată</th><th>Sursă</th><th>Tratament</th><th class="is-num">Sumă (lei)</th></tr></thead><tbody>' + sources + '</tbody></table>' : '<div class="ifv-note">Nicio tranzacție legată încă. Legați intervenții, facturi OCR sau cheltuieli ale acestui vehicul.</div>')
                    + (pending ? '<div class="ifv-note" style="margin-top:8px"><b>Legate, dar neînregistrate (nu intră în total):</b><ul style="margin:4px 0 0 16px;padding:0">' + pending + '</ul></div>' : '')
                    + (t.avertismente.length ? '<div class="ifv-note is-warn" style="margin-top:8px">' + t.avertismente.map(esc).join('<br>') + '</div>' : '')
                    + '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px">'
                    + (CFG.can.proiecte ? '<button type="button" class="ifv-btn-ghost" data-proj-act="link"><i class="bi bi-link-45deg"></i>Leagă sursă</button><button type="button" class="ifv-btn-ghost" data-proj-act="edit"><i class="bi bi-pencil"></i>Buget / status</button><button type="button" class="ifv-btn-ghost" data-proj-act="life"><i class="bi bi-hourglass-split"></i>Durată utilă</button>' : '')
                    + (CFG.can.clasificare ? '<button type="button" class="ifv-btn-ghost" data-proj-act="treatment"><i class="bi bi-bank"></i>Tratament: ' + esc(({ capitalizabil: 'Capitalizabil', cheltuiala: 'Cheltuială', de_confirmat: 'De confirmat' })[pr.tratament_contabil]) + '</button>' : '')
                    + '</div></div><div>'
                    + '<div style="font-size:13px;font-weight:700;margin-bottom:4px">Pregătire pentru transport GPL</div><ul class="ifv-ready">' + stages + '</ul>'
                    + (docs ? '<div style="font-size:13px;font-weight:700;margin:8px 0 4px">Documente obligatorii (pagina Documente)</div><ul class="ifv-ready">' + docs + '</ul>' : '')
                    + '<div class="ifv-legal ' + (r.apt_legal ? 'is-ok' : 'is-no') + '"><i class="bi ' + (r.apt_legal ? 'bi-shield-check' : 'bi-shield-exclamation') + '"></i>'
                    + (r.apt_legal ? 'Aprobat pentru activitate, documente valabile.' : 'Neapt legal pentru GPL — finalizarea financiară nu ține loc de aprobare.') + '</div>'
                    + (pr.durata_utila_luni ? '<div class="ifv-muted" style="font-size:12px;margin-top:6px">Durată utilă aprobată: ' + pr.durata_utila_luni + ' luni</div>' : '')
                    + '</div></div>';
            }
            return html + '</div>';
        }).join('');
        return '<div class="ifv-box-head"><h5 style="font-size:14.5px;font-weight:700;margin:0"><i class="bi bi-fuel-pump" style="color:var(--ifv-primary)"></i> Proiecte de investiții pe vehicul</h5>'
            + (CFG.can.proiecte && d.vehicle_id ? '<button type="button" class="ifv-btn-outline" data-act="add-project"><i class="bi bi-plus-lg"></i>Proiect investiție</button>' : '') + '</div>'
            + (d.vehicle_id ? (list || '<div class="ifv-note">Niciun proiect de investiții (adaptare GPL, echipare, modernizare) pe acest vehicul. Proiectul rămâne pe vehiculul fizic, indiferent de cuplaj.</div>')
                : '<div class="ifv-note">Proiectele de investiții se leagă de vehiculul fizic: legați întâi dosarul de vehiculul înmatriculat.</div>');
    }

    // ------------------------------------------------------------------ evenimente in dosar
    function bindDetail(key, box) {
        if (!box || box.dataset.bound) { return; }
        box.dataset.bound = '1';
        box.addEventListener('change', function (e) {
            var sel = e.target.closest('[data-cat-select]');
            var st = open[key];
            if (!sel || !st || !st.detail) { return; }
            st.cat = sel.value; st.line = null;
            if (st.cat) { finOpenPath(st, finNodes(st.detail), st.cat); } // filtrul deschide ramura aleasa
            refreshCosts(key);
        });
        // Tastatura pe controalele de expandare: ← restrange, → extinde (Enter / Spatiu = butonul nativ).
        box.addEventListener('keydown', function (e) {
            var btn = e.target.closest && e.target.closest('[data-node-toggle]');
            if (!btn || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) { return; }
            var want = e.key === 'ArrowRight';
            if ((btn.getAttribute('aria-expanded') === 'true') !== want) { e.preventDefault(); toggleNode(key, btn.dataset.nodeToggle, want); }
        });
        box.addEventListener('click', function (e) {
            var st = open[key];
            if (!st || !st.detail) { return; }
            var d = st.detail;
            var t;
            if ((t = e.target.closest('[data-tab-key]'))) {
                st.tab = t.dataset.tabKey;
                box.querySelectorAll('.ifv-tab').forEach(function (b) { b.classList.toggle('is-active', b === t); });
                refreshPanel(key);
                return;
            }
            if ((t = e.target.closest('[data-preview]'))) { e.preventDefault(); preview(t.getAttribute('href'), t.dataset.preview); return; }
            if ((t = e.target.closest('[data-line-menu]'))) {
                e.stopPropagation();
                var lineId = Number(t.closest('[data-line]').dataset.line);
                lineMenu(t, key, d, lineById(d, lineId));
                return;
            }
            if ((t = e.target.closest('[data-line-act]'))) { lineAction(t.dataset.lineAct, key, d, lineById(d, st.line)); return; }
            if ((t = e.target.closest('[data-act="add-cost"][data-node]'))) { formCost(key, d, null, t.dataset.node); return; }
            if ((t = e.target.closest('[data-node-toggle]')) || (t = e.target.closest('tr[data-node-row]'))) {
                var nodeRow = t.closest('tr[data-node-row]');
                toggleNode(key, nodeRow.dataset.nodeRow, !nodeRow.classList.contains('is-open'));
                return;
            }
            if ((t = e.target.closest('tr[data-line]'))) {
                if (window.getSelection && String(window.getSelection()).trim() !== '') { return; } // selectie de text, nu clic
                openLineSheet(key, Number(t.dataset.line), t, e.ctrlKey || e.metaKey);
                return;
            }
            if ((t = e.target.closest('[data-stage]'))) { if (CFG.can.manage) { stageAction(t.dataset.stage, key, d); } return; }
            if ((t = e.target.closest('[data-project-toggle]'))) { var pid = Number(t.closest('[data-project]').dataset.project); st.project = st.project === pid ? null : pid; refreshPanel(key); return; }
            if ((t = e.target.closest('[data-stage-confirm]'))) { formProjectStage(key, d, Number(t.closest('[data-project]').dataset.project), t.dataset.stageConfirm); return; }
            if ((t = e.target.closest('[data-proj-act]'))) { projectAction(t.dataset.projAct, key, d, Number(t.closest('[data-project]').dataset.project)); return; }
            if ((t = e.target.closest('[data-act]'))) {
                switch (t.dataset.act) {
                    case 'add-cost': formCost(key, d, null); break;
                    case 'edit-dossier': formDossier(d); break;
                    case 'status': formStatus(key, d); break;
                    case 'link-vehicle': formLinkVehicle(key, d); break;
                    case 'transport': formTransport(key, d); break;
                    case 'add-payment': formPayment(key, d, null); break;
                    case 'add-event': formEvent(key, d, null); break;
                    case 'upload-doc': formUpload(key, d); break;
                    case 'add-project': formProject(key, d); break;
                    case 'create-for-vehicle': formDossier(null, rowsByKey[key]); break;
                    case 'save-notes':
                        submitOp(key, { op: 'update_dossier', acquisition_id: d.acquisition_id, observatii: box.querySelector('[data-notes]').value }, null, 'Nota a fost salvată.');
                        break;
                }
            }
        });
    }
    // Expandare pe loc (fara re-randare), ca tranzitia de inaltime sa ruleze; starea ramane in st.openNodes.
    function toggleNode(key, nodeId, openIt) {
        var st = open[key];
        if (!st) { return; }
        st.openNodes = st.openNodes || {};
        st.openNodes[nodeId] = openIt;
        var box = tbody.querySelector('[data-dossier="' + CSS.escape(key) + '"]');
        var row = box && box.querySelector('tr[data-node-row="' + CSS.escape(String(nodeId)) + '"]');
        if (!row) { return; }
        row.classList.toggle('is-open', openIt);
        var btn = row.querySelector('[data-node-toggle]');
        btn.setAttribute('aria-expanded', String(openIt));
        btn.setAttribute('aria-label', (openIt ? 'Restrânge ' : 'Extinde ') + row.querySelector('.ifv-node-name').firstChild.textContent);
        var nest = row.nextElementSibling;
        if (nest && nest.classList.contains('ifv-node-nest')) { nest.querySelector('.ifv-collapse').classList.toggle('is-open', openIt); }
    }
    // Fisa inregistrarii: acelasi panou ca fisa cursei din Dispecer curse (race-view-overlay.js), cu HTML-ul
    // randat pe server (?action=line_view&partial=1). Randul ramane marcat ca ultima fisa deschisa.
    function openLineSheet(key, lineId, rowEl, newTab) {
        var href = url(CFG.urls.lineView, { id: lineId });
        if (newTab) { window.open(href, '_blank', 'noopener'); return; }
        if (open[key]) {
            open[key].line = lineId;
            var box = tbody.querySelector('[data-dossier="' + CSS.escape(key) + '"]');
            if (box) { box.querySelectorAll('tr.ifv-line.is-open').forEach(function (r) { r.classList.toggle('is-open', Number(r.dataset.line) === lineId); }); }
            if (rowEl) { rowEl.classList.add('is-open'); }
        }
        if (window.RaceViewOverlay && typeof window.RaceViewOverlay.openUrl === 'function') {
            window.RaceViewOverlay.openUrl(href, rowEl);
        } else {
            window.location.href = href;
        }
    }
    // Butoanele din fisa: panoul se strange in rand, apoi se deschide formularul existent (aceleasi operatii).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-ifv-line-act]');
        if (!btn) { return; }
        var holder = btn.closest('[data-ifv-line-actions]');
        var key = holder ? holder.dataset.dossierKey : '';
        var lineId = holder ? Number(holder.dataset.lineId) : 0;
        e.preventDefault();
        var run = function () {
            var st = open[key];
            var line = st && st.detail ? lineById(st.detail, lineId) : null;
            if (line) { lineAction(btn.dataset.ifvLineAct, key, st.detail, line); }
            else { toast('Deschide dosarul înregistrării pentru această operație.', true); }
        };
        if (window.RaceViewOverlay && typeof window.RaceViewOverlay.close === 'function') { window.RaceViewOverlay.close(run); } else { run(); }
    });
    function lineById(d, id) { return (d.linii || []).filter(function (l) { return l.id === id; })[0] || null; }

    function lineMenu(anchor, key, d, line) {
        if (!line) { return; }
        open[key].line = line.id;
        var manage = CFG.can.manage && !line.anulat;
        showMenu(anchor, [
            manage ? { icon: 'bi-pencil', label: 'Editează / mută în altă subcategorie', run: function () { lineAction('edit', key, d, line); } } : null,
            CFG.can.clasificare && !line.anulat ? { icon: 'bi-bank', label: 'Tratament contabil', run: function () { lineAction('treatment', key, d, line); } } : null,
            manage ? { icon: 'bi-patch-check', label: 'Stare verificare', run: function () { lineAction('verify', key, d, line); } } : null,
            manage ? { icon: 'bi-percent', label: 'Deductibilitate TVA', run: function () { lineAction('vat', key, d, line); } } : null,
            manage ? { icon: 'bi-cash-coin', label: 'Înregistrează plată', run: function () { lineAction('pay', key, d, line); } } : null,
            line.are_fisier ? { icon: 'bi-eye', label: 'Previzualizează documentul', run: function () { preview(url(CFG.urls.file, { sursa: 'linie', id: line.id }), line.document_numar || 'Document'); } } : null,
            { icon: 'bi-window-stack', label: 'Fișa înregistrării', run: function () { openLineSheet(key, line.id, anchor.closest('tr[data-line]'), false); } },
            manage ? { icon: 'bi-x-circle', label: 'Anulează înregistrarea', run: function () { lineAction('cancel', key, d, line); } } : null
        ]);
    }

    function lineAction(act, key, d, line) {
        if (!line) { return; }
        if (act === 'edit') { formCost(key, d, line); }
        if (act === 'treatment') {
            openForm({
                title: 'Tratament contabil — ' + (line.descriere || line.categorie_label),
                hint: 'Tratamentul se confirmă de contabilitate. „Capitalizabil” cere temeiul; schimbarea rămâne în istoric.',
                fields: [{ name: 'tratament', label: 'Tratament', type: 'select', options: TREATMENTS, value: line.tratament, required: true },
                    { name: 'motiv', label: 'Temei / motiv', type: 'textarea', wide: true }],
                base: { op: 'classify', entity: 'achizitie_cheltuiala', entity_id: line.id, acquisition_id: d.acquisition_id },
                key: key, success: 'Tratamentul a fost actualizat.'
            });
        }
        if (act === 'verify') {
            openForm({ title: 'Stare verificare', fields: [{ name: 'status_verificare', label: 'Stare', type: 'select', options: [['estimat', 'Estimat'], ['confirmat', 'Confirmat (document)'], ['verificat_contabil', 'Verificat contabil']], value: line.status_verificare },
                { name: 'motiv', label: 'Observație', type: 'textarea', wide: true }],
                base: { op: 'verify_cost', line_id: line.id, acquisition_id: d.acquisition_id }, key: key, success: 'Starea a fost actualizată.' });
        }
        if (act === 'vat') {
            openForm({ title: 'Deductibilitate TVA', hint: 'Nu schimbă clasificarea sau tratamentul liniei.', fields: [
                { name: 'tva_deductibil', label: 'TVA deductibil', type: 'select', options: [['de_confirmat', 'De confirmat'], ['da', 'Da'], ['nu', 'Nu'], ['partial', 'Parțial']], value: line.tva_deductibil },
                { name: 'tva_recuperabil', label: 'TVA recuperabil (' + line.moneda + ')', type: 'number', step: '0.01' },
                { name: 'motiv', label: 'Observație', type: 'textarea', wide: true }],
                base: { op: 'cost_vat', line_id: line.id, acquisition_id: d.acquisition_id }, key: key, success: 'TVA actualizat.' });
        }
        if (act === 'pay') { formPayment(key, d, line); }
        if (act === 'cancel') {
            openForm({
                title: 'Anulează înregistrarea #' + line.id,
                hint: 'Linia rămâne în dosar și în istoric, dar iese din totalurile subcategoriei, ale categoriei principale și ale dosarului. Nu se șterge nimic.',
                fields: [{ name: 'motiv', label: 'Motivul anulării', type: 'textarea', wide: true, required: true }],
                submitLabel: 'Anulează înregistrarea',
                base: { op: 'cancel_cost', line_id: line.id, acquisition_id: d.acquisition_id }, key: key, success: 'Înregistrarea a fost anulată.'
            });
        }
    }

    function stageAction(stage, key, d) {
        var map = { vanzator: function () { formDossier(d); }, contract: function () { formDossier(d); }, plati: function () { formPayment(key, d, null); },
            transport: function () { formTransport(key, d); }, receptie: function () { formEvent(key, d, 'receptie_tehnica'); },
            inmatriculare: function () { formEvent(key, d, 'inmatriculare'); }, exploatare: function () { formEvent(key, d, 'punere_in_functiune'); } };
        if (map[stage]) { map[stage](); }
    }

    function projectAction(act, key, d, projectId) {
        var p = (d.proiecte || []).filter(function (x) { return Number(x.proiect.id) === projectId; })[0];
        if (!p) { return; }
        if (act === 'edit') {
            openForm({ title: 'Proiect — buget și status', hint: 'Costul real nu se introduce manual: rezultă din sursele legate.', fields: [
                { name: 'denumire', label: 'Denumire', value: p.proiect.denumire, wide: true, required: true },
                { name: 'buget_planificat', label: 'Buget planificat (lei)', type: 'number', step: '0.01', value: p.proiect.buget_planificat },
                { name: 'status', label: 'Status financiar', type: 'select', options: PROJECT_STATUSES, value: p.proiect.status },
                { name: 'data_start', label: 'Data start', type: 'date', value: p.proiect.data_start },
                { name: 'observatii', label: 'Observații', type: 'textarea', wide: true, value: p.proiect.observatii }],
                base: { op: 'update_project', project_id: projectId, acquisition_id: d.acquisition_id || '', vehicle_id: d.vehicle_id }, key: key, success: 'Proiectul a fost actualizat.' });
        }
        if (act === 'life') {
            openForm({ title: 'Durată utilă aprobată', hint: 'Se completează doar cu aprobarea contabilității. Amortizarea nu se calculează automat.', fields: [
                { name: 'luni', label: 'Durată utilă (luni)', type: 'number', value: p.proiect.durata_utila_luni, required: true },
                { name: 'motiv', label: 'Temei aprobare', type: 'textarea', wide: true, required: true }],
                base: { op: 'project_life', project_id: projectId, acquisition_id: d.acquisition_id || '', vehicle_id: d.vehicle_id }, key: key, success: 'Durata utilă a fost înregistrată.' });
        }
        if (act === 'treatment') {
            openForm({ title: 'Tratament contabil proiect', fields: [{ name: 'tratament', label: 'Tratament', type: 'select', options: TREATMENTS, value: p.proiect.tratament_contabil },
                { name: 'motiv', label: 'Temei', type: 'textarea', wide: true }],
                base: { op: 'classify', entity: 'proiect_investitie', entity_id: projectId, acquisition_id: d.acquisition_id || '', vehicle_id: d.vehicle_id }, key: key, success: 'Tratamentul proiectului a fost actualizat.' });
        }
        if (act === 'link') {
            openForm({ title: 'Leagă o sursă de proiect', hint: 'Sursa trebuie să fie pe același vehicul fizic. Intervenția / factura OCR leagă doar rândurile lor de investiție; reparațiile rămân cost operațional.', fields: [
                { name: 'sursa', label: 'Tip sursă', type: 'select', options: [['interventie', 'Intervenție (Mentenanță)'], ['mentenanta', 'Înregistrare Reparații / Întreținere'], ['ocr_reparatie', 'Factură Registru piese (OCR)'], ['cheltuiala', 'Cheltuială (pagina Cheltuieli)'], ['document', 'Document (pagina Documente)']], required: true },
                { name: 'sursa_id', label: 'ID sursă', type: 'number', required: true }],
                base: { op: 'project_link', project_id: projectId, acquisition_id: d.acquisition_id || '', vehicle_id: d.vehicle_id }, key: key, success: 'Sursa a fost legată.' });
        }
    }

    // ------------------------------------------------------------------ formulare
    var formState = null;
    function openForm(opts) {
        formState = opts;
        document.getElementById('ifvFormTitle').textContent = opts.title;
        var hint = document.getElementById('ifvFormHint');
        hint.hidden = !opts.hint;
        hint.textContent = opts.hint || '';
        document.getElementById('ifvFormError').hidden = true;
        document.getElementById('ifvFormSubmit').textContent = opts.submitLabel || 'Salvează';
        document.getElementById('ifvFormFields').innerHTML = opts.fields.map(fieldHtml).join('');
        if (opts.onRender) { opts.onRender(document.getElementById('ifvFormFields')); }
        var m = modal('ifvFormModal');
        if (m) { m.show(); }
        setTimeout(function () { var f = document.querySelector('#ifvFormFields input:not([type=hidden]), #ifvFormFields select'); if (f) { f.focus(); } }, 300);
    }
    function fieldHtml(f) {
        if (f.section) { return '<div class="ifv-form-section">' + esc(f.section) + '</div>'; }
        var id = 'ifvf_' + f.name;
        var v = f.value === null || f.value === undefined ? '' : f.value;
        var input;
        if (f.type === 'select') {
            input = '<select id="' + id + '" name="' + f.name + '"' + (f.required ? ' required' : '') + '>' + (f.placeholder !== false ? '<option value="">' + esc(f.placeholder || '—') + '</option>' : '')
                + f.options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(v) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>';
        } else if (f.type === 'textarea') {
            input = '<textarea id="' + id + '" name="' + f.name + '"' + (f.required ? ' required' : '') + '>' + esc(v) + '</textarea>';
        } else {
            input = '<input id="' + id + '" name="' + f.name + '" type="' + (f.type || 'text') + '"' + (f.type === 'file' ? '' : ' value="' + esc(v) + '"')
                + (f.step ? ' step="' + f.step + '"' : '') + (f.required ? ' required' : '') + (f.maxlength ? ' maxlength="' + f.maxlength + '"' : '') + (f.list ? ' list="' + f.list + '"' : '') + (f.accept ? ' accept="' + f.accept + '"' : '') + '>';
        }
        return '<div class="ifv-field' + (f.wide ? ' is-wide' : '') + '" data-field="' + f.name + '"><label for="' + id + '">' + esc(f.label) + (f.required ? ' <em>*</em>' : '') + '</label>' + input + (f.help ? '<small>' + esc(f.help) + '</small>' : '') + '</div>';
    }
    document.getElementById('ifvForm').addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation(); // garda globala anti-dublu-submit din app.js ignora preventDefault
        if (!formState) { return; }
        var form = e.target;
        var missing = Array.prototype.filter.call(form.querySelectorAll('[required]'), function (el) { return !el.value; });
        var err = document.getElementById('ifvFormError');
        if (missing.length) { err.hidden = false; err.textContent = 'Completează câmpurile obligatorii.'; missing[0].focus(); return; }
        var fd = new FormData(form);
        Array.from(fd.keys()).forEach(function (k) { var val = fd.get(k); if (val instanceof File && !val.name) { fd.delete(k); } });
        var btn = document.getElementById('ifvFormSubmit');
        btn.disabled = true;
        post(formState.base, fd).then(function (res) {
            btn.disabled = false;
            if (!res.ok) { err.hidden = false; err.textContent = res.message || 'Operația nu a putut fi salvată.'; return; }
            var m = modal('ifvFormModal');
            if (m) { m.hide(); }
            afterOp(formState, res);
        }).catch(function () { btn.disabled = false; err.hidden = false; err.textContent = 'Conexiunea a eșuat. Reîncearcă.'; });
    });

    function submitOp(key, fields, files, message) {
        return post(fields, files).then(function (res) {
            if (!res.ok) { toast(res.message || 'Operația nu a putut fi salvată.', true); return res; }
            afterOp({ key: key, success: message }, res);
            return res;
        });
    }

    function afterOp(opts, res) {
        toast(opts.success || 'Salvat.');
        if (opts.after) { opts.after(res); return; }
        var key = opts.key;
        if (key && open[key] && res.detail) {
            open[key].detail = res.detail;
            // inregistrarea noua / mutata: ii deschidem subcategoria (restul expandarilor raman cum erau)
            var savedId = res.result && res.result.line_id ? Number(res.result.line_id) : (opts.base && opts.base.op === 'update_cost' ? Number(opts.base.line_id) : null);
            var saved = savedId ? lineById(res.detail, savedId) : null;
            if (saved && saved.fin_id) { finOpenPath(open[key], finNodes(res.detail), saved.fin_id); }
            renderDetail(key);
        }
        loadList();
    }

    function formDossier(d, row) {
        var a = d ? d.dosar : {};
        var fields = [
            { section: 'Vehicul' },
            !d ? { name: 'tip_vehicul', label: 'Tip vehicul', type: 'select', options: VEHICLE_TYPES, required: true, value: row ? row.tip : '' } : null,
            !d ? { name: 'stare', label: 'Stare', type: 'select', options: [['rulat', 'Rulat'], ['nou', 'Nou']], required: true, placeholder: false } : { name: 'stare', label: 'Stare', type: 'select', options: [['rulat', 'Rulat'], ['nou', 'Nou']], value: a.stare, placeholder: false },
            { name: 'vin', label: 'VIN', value: a.vin || (row ? row.vin : ''), maxlength: 17 },
            { name: 'marca', label: 'Marcă', value: a.marca }, { name: 'model', label: 'Model', value: a.model },
            { name: 'an_fabricatie', label: 'An fabricație', type: 'number', value: a.an_fabricatie },
            { section: 'Vânzător' },
            { name: 'vanzator_nume', label: 'Vânzător', value: a.vanzator_nume }, { name: 'vanzator_cod_fiscal', label: 'Cod fiscal', value: a.vanzator_cod_fiscal },
            { name: 'vanzator_tara', label: 'Țara vânzătorului', type: 'select', options: countryOptions(), value: a.vanzator_tara },
            { name: 'locatie_vehicul', label: 'Locația vehiculului', value: a.locatie_vehicul },
            { section: 'Contract & finanțare' },
            !d ? { name: 'modalitate_achizitie', label: 'Modalitate achiziție', type: 'select', required: true, options: [['plata_integrala', 'Plată integrală'], ['plata_esalonata', 'Plată eșalonată'], ['leasing_financiar', 'Leasing financiar'], ['leasing_operational', 'Leasing operațional'], ['credit', 'Credit'], ['altul', 'Altă modalitate']] } : null,
            { name: 'tara_achizitie', label: 'Țara achiziției', type: 'select', options: countryOptions(), value: a.tara_achizitie },
            { name: 'tara_inmatriculare', label: 'Țara înmatriculării', type: 'select', options: countryOptions(), value: a.tara_inmatriculare || 'RO', placeholder: false },
            { name: 'data_contract', label: 'Data contract', type: 'date', value: a.data_contract }, { name: 'data_factura', label: 'Data factură', type: 'date', value: a.data_factura },
            { name: 'data_transfer_proprietate', label: 'Transfer proprietate', type: 'date', value: a.data_transfer_proprietate },
            { name: 'pret_achizitie', label: 'Preț contract', type: 'number', step: '0.01', value: a.pret_achizitie, help: 'Referință; totalurile se calculează din liniile de cost.' },
            { name: 'moneda', label: 'Monedă', value: a.moneda || 'RON', maxlength: 3 }, { name: 'curs_schimb', label: 'Curs original', type: 'number', step: '0.000001', value: a.curs_schimb },
            { name: 'curs_data', label: 'Data cursului', type: 'date', value: a.curs_data }, { name: 'curs_sursa', label: 'Sursa cursului', value: a.curs_sursa || '' },
            { name: 'regim_tva', label: 'Regim TVA', type: 'select', placeholder: false, value: a.regim_tva || 'de_confirmat', options: [['de_confirmat', 'De confirmat'], ['normal', 'Normal'], ['taxare_inversa', 'Taxare inversă'], ['intracomunitar', 'Intracomunitar'], ['import', 'Import'], ['regim_marja', 'Regim marjă'], ['scutit', 'Scutit']] },
            { name: 'utilizare_prevazuta', label: 'Utilizare prevăzută', value: a.utilizare_prevazuta, wide: true }
        ].filter(Boolean);
        var base = d ? { op: 'update_dossier', acquisition_id: d.acquisition_id } : { op: 'create_dossier' };
        if (!d && row && row.vehicle_id) { base.vehicle_id = row.vehicle_id; }
        var key = d ? Object.keys(open).filter(function (k) { return open[k].detail === d; })[0] : null;
        openForm({
            title: d ? 'Editează dosarul ' + a.cod_dosar : 'Dosar de achiziție nou' + (row ? ' — ' + row.nr : ''),
            hint: d ? null : 'Dosarul poate exista înainte de înmatriculare (cod provizoriu + VIN). Costurile se adaugă apoi pe linii, cu tratament „de confirmat”.',
            fields: fields, base: base, key: key,
            success: d ? 'Dosarul a fost actualizat.' : 'Dosarul a fost creat.',
            after: d ? null : function (res) {
                if (row && open[row.key]) { delete open[row.key]; }
                var newKey = 'a' + res.result.acquisition_id;
                loadList().then(function () {
                    if (rowsByKey[newKey]) { return; }
                    // Filtrele active ascund dosarul nou: le resetam ca sa-l aratam deschis.
                    state.q = ''; state.status = ''; state.tara = ''; state.perioada = ''; state.tab = 'toate'; state.page = 1;
                    searchInput.value = ''; selStatus.value = ''; selPeriod.value = '';
                    return loadList();
                }).then(function () {
                    if (rowsByKey[newKey]) { expand(newKey); var tr = rowEl(newKey); if (tr) { tr.scrollIntoView({ behavior: 'smooth', block: 'center' }); } }
                });
            }
        });
    }
    function countryOptions() { return Object.keys(COUNTRIES).sort().map(function (c) { return [c, COUNTRIES[c][0]]; }); }

    // Categorie principala → Subcategorie (frunza) → Tip cost. Subcategoriile depind de categoria aleasa,
    // tipurile de subcategorie. Totalurile NU se introduc: le recalculeaza serverul dupa salvare.
    function finLeaves(d, byId, rootId) {
        return (d.categorii_financiare || []).filter(function (n) { return n.frunza && n.activ && isAncestor(byId, rootId, n.id); });
    }
    function finLeafOptions(d, byId, rootId) {
        var root = byId[rootId];
        return finLeaves(d, byId, rootId).map(function (n) {
            // la mai mult de doua niveluri: calea relativa la categoria principala
            var label = n.denumire, p = n.parent_id !== null ? byId[n.parent_id] : null, guard = 0;
            while (p && p !== root && guard++ < 20) { label = p.denumire + ' › ' + label; p = p.parent_id !== null ? byId[p.parent_id] : null; }
            return [n.id, label];
        });
    }
    function finTypeOptions(d, leafId) {
        var list = d && d.categorii && d.categorii.length ? d.categorii : CFG.categories;
        var matching = list.filter(function (c) { return leafId && String(c.fin_id) === String(leafId); });
        return (matching.length ? matching : list).map(function (c) { return [c.cod, c.denumire]; });
    }
    function selectOptionsHtml(options, value, placeholder) {
        return (placeholder ? '<option value="">' + esc(placeholder) + '</option>' : '')
            + options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (String(o[0]) === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('');
    }
    function formCost(key, d, line, presetNode) {
        var byId = finNodes(d);
        var st = open[key] || {};
        // „+” de pe un rand de categorie / subcategorie preselecteaza nodul; altfel filtrul curent
        var picked = presetNode && byId[presetNode] ? byId[presetNode] : (st.cat && byId[st.cat] ? byId[st.cat] : null);
        var leaf = line ? line.fin_id : (picked && picked.frunza ? picked.id : null);
        var root = leaf && byId[leaf] ? finRootOf(byId, leaf) : (picked ? finRootOf(byId, picked.id) : null);
        var roots = (d.categorii_financiare || []).filter(function (n) {
            return n.nivel === 0 && ((n.activ && finLeaves(d, byId, n.id).length) || n === root);
        });
        root = root || roots[0] || null;
        var types = finTypeOptions(d, leaf);
        var fields = [
            { name: 'fin_root', label: 'Categorie principală', type: 'select', required: true, placeholder: false, value: root ? root.id : '', options: roots.map(function (n) { return [n.id, n.denumire]; }) },
            { name: 'financiar_categorie_id', label: 'Subcategorie', type: 'select', required: true, placeholder: 'Alege subcategoria', value: leaf || '', options: root ? finLeafOptions(d, byId, root.id) : [],
                help: line ? 'Mutarea schimbă doar subcategoria; tipul și clasificarea rămân.' : null },
            line ? null : { name: 'categorie', label: 'Tip cost', type: 'select', options: types, required: true, wide: true, value: types.length === 1 ? types[0][0] : '' },
            { name: 'descriere', label: 'Descriere', value: line ? line.descriere : '', wide: true },
            { name: 'furnizor', label: 'Furnizor', value: line ? line.furnizor : '' },
            { name: 'data_tranzactie', label: 'Data tranzacției', type: 'date', value: line ? line.data : new Date().toISOString().slice(0, 10) },
            { name: 'suma_neta', label: 'Valoare netă', type: 'number', step: '0.01', required: true, value: line ? line.suma_originala : '' },
            { name: 'tva', label: 'TVA', type: 'number', step: '0.01', value: line && line.tva !== null && line.curs === null ? line.tva : '' },
            { name: 'moneda', label: 'Monedă', value: line ? line.moneda : 'RON', maxlength: 3 },
            { name: 'curs_schimb', label: 'Curs (dacă nu e RON)', type: 'number', step: '0.000001', value: line ? line.curs : '' },
            line ? null : { name: 'tva_deductibil', label: 'TVA deductibil', type: 'select', placeholder: false, options: [['de_confirmat', 'De confirmat'], ['da', 'Da'], ['nu', 'Nu'], ['partial', 'Parțial']] },
            line ? null : { name: 'status_verificare', label: 'Stare', type: 'select', placeholder: false, options: [['estimat', 'Estimat'], ['confirmat', 'Confirmat (document)']] },
            { name: 'document_tip', label: 'Tip document', type: 'select', options: [['Factură', 'Factură'], ['Chitanță', 'Chitanță'], ['Bon fiscal', 'Bon fiscal'], ['Contract', 'Contract'], ['Proforma', 'Proforma'], ['Altul', 'Altul']], value: line ? line.document_tip : 'Factură' },
            { name: 'document_numar', label: 'Număr document', value: line ? line.document_numar : '' },
            { name: 'document', label: 'Fișier document (PDF / imagine)', type: 'file', accept: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx', wide: true },
            line ? null : { name: 'inclus_in_linie_id', label: 'Inclusă în linia (nu se adună)', type: 'select', options: (d.linii || []).filter(function (l) { return !l.inclus_in && !l.anulat; }).map(function (l) { return [l.id, '#' + l.id + ' ' + (l.descriere || l.categorie_label)]; }), placeholder: 'Nu — linie de sine stătătoare', wide: true },
            { name: 'observatii', label: 'Observații', type: 'textarea', value: line ? line.observatii : '', wide: true }
        ].filter(Boolean);
        openForm({
            title: line ? 'Editează înregistrarea #' + line.id : 'Adaugă înregistrare — ' + (d.dosar ? d.dosar.cod_dosar : ''),
            hint: line ? 'Clasificarea și tratamentul contabil se schimbă separat (cu istoric). Totalurile se recalculează automat.' : 'Totalul subcategoriei, al categoriei principale și al dosarului se recalculează automat. Tratamentul contabil pornește „de confirmat”.',
            fields: fields, key: key,
            onRender: function (box) {
                var rootSel = box.querySelector('[name="fin_root"]');
                var subSel = box.querySelector('[name="financiar_categorie_id"]');
                var typeSel = box.querySelector('[name="categorie"]');
                function syncTypes() {
                    if (!typeSel) { return; }
                    var opts = finTypeOptions(d, subSel.value);
                    var keep = opts.some(function (o) { return o[0] === typeSel.value; }) ? typeSel.value : (opts.length === 1 ? opts[0][0] : '');
                    typeSel.innerHTML = selectOptionsHtml(opts, keep, '—');
                }
                rootSel.addEventListener('change', function () {
                    var opts = finLeafOptions(d, byId, rootSel.value);
                    subSel.innerHTML = selectOptionsHtml(opts, opts.length === 1 ? opts[0][0] : '', 'Alege subcategoria');
                    syncTypes();
                });
                subSel.addEventListener('change', syncTypes);
            },
            base: line ? { op: 'update_cost', line_id: line.id, acquisition_id: d.acquisition_id } : { op: 'add_cost', acquisition_id: d.acquisition_id },
            success: line ? 'Înregistrarea a fost actualizată.' : 'Înregistrarea a fost adăugată.'
        });
    }

    function formPayment(key, d, line) {
        openForm({
            title: 'Înregistrează plată' + (line ? ' — ' + (line.descriere || line.categorie_label) : ''),
            fields: [
                { name: 'data_plata', label: 'Data plății', type: 'date', required: true, value: new Date().toISOString().slice(0, 10) },
                { name: 'suma', label: 'Sumă', type: 'number', step: '0.01', required: true },
                { name: 'moneda', label: 'Monedă', value: line ? line.moneda : 'RON', maxlength: 3 },
                { name: 'curs_schimb', label: 'Curs (dacă nu e RON)', type: 'number', step: '0.000001', value: line ? line.curs : '' },
                { name: 'metoda', label: 'Metodă', type: 'select', options: [['transfer_bancar', 'Transfer bancar'], ['card', 'Card'], ['numerar', 'Numerar'], ['leasing', 'Prin leasing'], ['altul', 'Altă metodă']] },
                { name: 'referinta', label: 'Referință (OP, extras)' },
                line ? null : { name: 'cheltuiala_linie_id', label: 'Pentru linia', type: 'select', options: (d.linii || []).filter(function (l) { return !l.anulat; }).map(function (l) { return [l.id, '#' + l.id + ' ' + (l.descriere || l.categorie_label)]; }), placeholder: 'Dosar (general)', wide: true }
            ].filter(Boolean),
            base: { op: 'add_payment', acquisition_id: d.acquisition_id, cheltuiala_linie_id: line ? line.id : undefined }, key: key, success: 'Plata a fost înregistrată.'
        });
    }

    function formEvent(key, d, type) {
        openForm({
            title: type ? EVENT_LABELS[type] : 'Înregistrează etapă',
            hint: 'Etapele sunt separate: recepția nu înseamnă punere în exploatare.',
            fields: [
                { name: 'tip', label: 'Etapă', type: 'select', required: true, value: type || '', options: Object.keys(EVENT_LABELS).map(function (k) { return [k, EVENT_LABELS[k]]; }) },
                { name: 'data', label: 'Data', type: 'date', required: true, value: (d.evenimente && type && d.evenimente[type]) || new Date().toISOString().slice(0, 10) },
                { name: 'document_ref', label: 'Document (PV, certificat)', wide: true },
                { name: 'observatii', label: 'Observații', type: 'textarea', wide: true }
            ],
            base: { op: 'record_event', acquisition_id: d.acquisition_id }, key: key, success: 'Etapa a fost înregistrată.'
        });
    }

    function formStatus(key, d) {
        openForm({ title: 'Status dosar', fields: [{ name: 'status', label: 'Status', type: 'select', options: STATUS_OPTIONS, value: d.dosar.status, required: true, placeholder: false },
            { name: 'motiv', label: 'Motiv', type: 'textarea', wide: true }],
            base: { op: 'set_status', acquisition_id: d.acquisition_id }, key: key, success: 'Statusul a fost schimbat.' });
    }

    function formLinkVehicle(key, d) {
        openForm({ title: 'Leagă dosarul de vehiculul înmatriculat', hint: 'VIN-ul vehiculului trebuie să corespundă celui din dosar.',
            fields: [{ name: 'vehicle_id', label: 'Vehicul', type: 'select', required: true, wide: true, options: CFG.vehicles.map(function (v) { return [v.id, v.nr_inmatriculare + (v.serie_sasiu ? ' · ' + v.serie_sasiu : '')]; }) }],
            base: { op: 'link_vehicle', acquisition_id: d.acquisition_id }, key: key, success: 'Vehiculul a fost legat.' });
    }

    function formTransport(key, d) {
        var t = d.transport || {};
        openForm({
            title: 'Transport & aducere',
            hint: 'A. condus până la destinație (șofer, numere provizorii, asigurare) sau B. transportator extern. Serviciile incluse în prețul transportatorului nu se mai adaugă separat.',
            fields: [
                { name: 'modalitate', label: 'Mod de aducere', type: 'select', required: true, value: t.modalitate || d.dosar.modalitate_livrare || '', options: [['condus', 'A. Condus până la destinație'], ['transportator', 'B. Transportator extern']] },
                { name: 'sofer_id', label: 'Șofer (A)', type: 'select', value: t.sofer_id, options: CFG.drivers.map(function (s) { return [s.id, s.nume]; }) },
                { name: 'sofer_nume', label: 'Alt șofer (nume)', value: t.sofer_nume }, { name: 'numere_provizorii', label: 'Numere provizorii (A)', value: t.numere_provizorii },
                { name: 'asigurare_tranzit_ref', label: 'Asigurare tranzit (A)', value: t.asigurare_tranzit_ref },
                { name: 'transportator', label: 'Transportator (B)', value: t.transportator }, { name: 'comanda_transport', label: 'Comandă transport (B)', value: t.comanda_transport },
                { name: 'servicii_incluse', label: 'Servicii incluse în preț (B, coduri)', value: t.servicii_incluse, help: 'ex. numere_provizorii, asigurare_tranzit' },
                { name: 'loc_preluare', label: 'Loc preluare / încărcare', value: t.loc_preluare }, { name: 'loc_destinatie', label: 'Destinație / livrare', value: t.loc_destinatie },
                { name: 'data_plecare', label: 'Data plecării', type: 'date', value: t.data_plecare }, { name: 'data_sosire', label: 'Data sosirii', type: 'date', value: t.data_sosire },
                { name: 'livrare_confirmata_la', label: 'Livrare confirmată la', type: 'date', value: t.livrare_confirmata_la },
                { name: 'observatii', label: 'Observații', type: 'textarea', wide: true, value: t.observatii }
            ],
            base: { op: 'save_transport', acquisition_id: d.acquisition_id }, key: key, success: 'Transportul a fost salvat.'
        });
    }

    function formUpload(key, d) {
        openForm({ title: 'Încarcă document în dosar', fields: [
            { name: 'tip', label: 'Tip document', type: 'select', required: true, options: [['Contract', 'Contract'], ['Factură', 'Factură'], ['CMR', 'CMR'], ['Certificat de înmatriculare', 'Certificat de înmatriculare'], ['Proces-verbal recepție', 'Proces-verbal recepție'], ['RAR', 'RAR'], ['Asigurare', 'Asigurare'], ['Altul', 'Altul']] },
            { name: 'numar', label: 'Număr' }, { name: 'document', label: 'Fișier', type: 'file', required: true, wide: true, accept: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx' }],
            base: { op: 'upload_document', acquisition_id: d.acquisition_id }, key: key, success: 'Documentul a fost încărcat.' });
    }

    function formProject(key, d) {
        openForm({ title: 'Proiect de investiții — ' + d.titlu, hint: 'Proiectul aparține vehiculului fizic. Costul real se calculează din sursele legate; tratamentul pornește „de confirmat”.', fields: [
            { name: 'denumire', label: 'Denumire', required: true, wide: true, value: 'Adaptare ' + (d.tip_label || '').toLowerCase() + ' pentru transport GPL' },
            { name: 'categorie', label: 'Categorie', type: 'select', required: true, options: PROJECT_CATEGORIES, value: 'adaptare_gpl' },
            { name: 'buget_planificat', label: 'Buget planificat (lei)', type: 'number', step: '0.01' },
            { name: 'data_start', label: 'Data start', type: 'date', value: new Date().toISOString().slice(0, 10) },
            { name: 'status', label: 'Status', type: 'select', options: PROJECT_STATUSES, value: 'planificat', placeholder: false },
            { name: 'observatii', label: 'Observații', type: 'textarea', wide: true }],
            base: { op: 'create_project', vehicle_id: d.vehicle_id, acquisition_id: d.acquisition_id || '' }, key: key, success: 'Proiectul a fost creat.',
            after: function (res) {
                if (open[key] && res.detail) { open[key].detail = res.detail; open[key].tab = 'investitii'; open[key].project = res.result.project_id; renderDetail(key); }
                loadList();
            } });
    }

    function formProjectStage(key, d, projectId, stage) {
        var labels = { lucrari_finalizate: 'Lucrări finalizate', echipamente_montate: 'Echipamente montate', inspectie_tehnica: 'Inspecție tehnică efectuată', documente_disponibile: 'Documente obligatorii disponibile', aprobat_activitate: 'Aprobat pentru activitatea vizată', pus_in_functiune: 'Pus în funcțiune' };
        var docs = (d.documente || []).filter(function (x) { return x.sursa === 'vehicul'; }).map(function (x) { return [x.id, x.tip + ' ' + (x.numar || '') + (x.expira ? ' · exp. ' + x.expira.split('-').reverse().join('.') : '')]; });
        openForm({ title: labels[stage], fields: [
            { name: 'data', label: 'Data confirmării', type: 'date', required: true, value: new Date().toISOString().slice(0, 10) },
            { name: 'document_id', label: 'Document justificativ (pagina Documente)', type: 'select', options: docs, placeholder: 'Fără document' },
            { name: 'observatii', label: 'Observații', type: 'textarea', wide: true }],
            base: { op: 'project_stage', project_id: projectId, stadiu: stage, acquisition_id: d.acquisition_id || '', vehicle_id: d.vehicle_id }, key: key, success: 'Stadiul a fost confirmat.' });
    }

    // ------------------------------------------------------------------ previzualizare
    function preview(href, title) {
        if (!href) { return; }
        var isInline = /\.(pdf|png|jpe?g|webp)(\?|$)/i.test(href) || href.indexOf('action=file') !== -1;
        var m = modal('ifvPreviewModal');
        if (!isInline || !m) { window.open(href, '_blank', 'noopener'); return; }
        document.getElementById('ifvPreviewTitle').textContent = title || 'Document';
        document.getElementById('ifvPreviewOpen').href = href;
        document.getElementById('ifvPreviewFrame').src = href;
        m.show();
    }
    document.getElementById('ifvPreviewModal').addEventListener('hidden.bs.modal', function () { document.getElementById('ifvPreviewFrame').src = 'about:blank'; });

    loadList();
})();
