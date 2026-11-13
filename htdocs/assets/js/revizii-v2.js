/* Revizii & Mentenanță V2 — spatiul de lucru unic (filtre, KPI interactive, tabel expandabil,
   panou vehicul, executie revizie, operatiuni, istoric, configurare). Datele vin din
   ReviziiV2Service (#rv2Data); actiunile folosesc ?page=revizii&action=v2_*. */
(function () {
    'use strict';

    var root = document.getElementById('rv2');
    if (!root) { return; }
    var D = {};
    try { D = JSON.parse(document.getElementById('rv2Data').textContent || '{}'); } catch (e) { D = {}; }
    var W = D.workspace || { vehicles: [], labels: { status: {}, work: {}, item: {}, op_types: {}, categories: {} }, filters: {} };
    var CAN = D.can || {};
    var L = W.labels || {};
    var SEV = { neconfigurat: 0, in_termen: 1, fara_date: 2, se_apropie: 3, scadent: 4, depasit: 5 };
    var CAT_ICON = { fluide: 'bi-droplet-half', filtre: 'bi-funnel', gresare: 'bi-moisture', transmisie: 'bi-gear-wide-connected', franare: 'bi-disc', altele: 'bi-tools' };
    var FLUID_TYPES = { motor: ['ulei_motor'], transmisie: ['ulei_transmisie'], diferential: ['ulei_diferential'], servodirectie: ['ulei_servodirectie'], hidraulic_pompa: ['ulei_hidraulic'], racire: ['antigel'], frana: ['lichid_frana'] };
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var state = {
        kpi: {},            // ok / soon / due / cost (filtre din carduri)
        cyclePos: '',       // X1..Xn / Y (din expandarea „Secvență X / Y”)
        kpiOpen: '',        // cardul expandat
        fleet: '', type: '', status: '', comp: '', q: '', from: '', to: '', win: '',
        adv: { make: [], veh: [], cat: [], optype: [], crit: [], src: '', hours: '', conf: '', sched: '' },
        sort: { key: 'due', dir: 1 },
        page: 1, per: 25, view: 'list',
        expanded: {}, collapsed: {}, checked: {},
        selected: null,
        details: {}
    };
    try {
        var saved = JSON.parse(localStorage.getItem('rv2.prefs') || '{}');
        if (saved.per) { state.per = saved.per; }
        if (saved.view) { state.view = saved.view; }
    } catch (e) { /* stocarea poate lipsi */ }

    // ------------------------------------------------------------------ utilitare
    function $(id) { return document.getElementById(id); }
    function esc(v) {
        return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function num(v, d) {
        if (v === null || v === undefined || v === '' || isNaN(Number(v))) { return '—'; }
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }).format(Number(v));
    }
    function lei(v) { return v === null || v === undefined ? '—' : num(v, 2) + ' lei'; }
    function date(v, withTime) {
        if (!v) { return '—'; }
        var d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d)) { return '—'; }
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear() + (withTime ? ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) : '');
    }
    function localInput(d) {
        var x = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
        return x.toISOString().slice(0, 16);
    }
    function statusLabel(s) { return (L.status || {})[s] || s; }
    function badge(s, soft) { return '<span class="rv2-status rv2-status-' + esc(s) + (soft ? ' is-soft' : '') + '">' + esc(statusLabel(s)) + '</span>'; }
    function bar(pct, status) {
        var w = pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, Number(pct)));
        return '<div class="rv2-prog-bar rv2-tone-' + esc(status) + '"><span style="width:' + w + '%"></span></div>';
    }
    function uid() { return 'rv2-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10); }
    function toast(msg, kind) {
        var box = $('rv2Toast');
        var el = document.createElement('div');
        el.className = kind === 'err' ? 'is-err' : (kind === 'ok' ? 'is-ok' : '');
        el.textContent = msg;
        box.appendChild(el);
        setTimeout(function () { el.remove(); }, kind === 'err' ? 7000 : 3500);
    }
    function url(action, params) {
        var u = new URL(D.urls.base, window.location.href);
        u.searchParams.set('action', action);
        Object.keys(params || {}).forEach(function (k) { if (params[k] !== '' && params[k] !== null && params[k] !== undefined) { u.searchParams.set(k, params[k]); } });
        return u.toString();
    }
    function get(action, params) {
        return fetch(url(action, params), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(handle);
    }
    function post(action, body) {
        body = body || {};
        body._token = D.token;
        return fetch(url(action), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': D.token },
            body: JSON.stringify(body)
        }).then(handle);
    }
    function handle(r) {
        return r.json().catch(function () { return { ok: false, message: 'Răspuns invalid de la server (' + r.status + ').' }; }).then(function (j) {
            if (!j.ok) { throw new Error(j.message || 'Eroare.'); }
            return j;
        });
    }
    function modal(id) { return window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance($(id)) : null; }
    function drawer(id) { return window.bootstrap ? window.bootstrap.Offcanvas.getOrCreateInstance($(id)) : null; }
    function animateHeight(el, open) {
        if (reduced) { el.style.height = open ? 'auto' : '0px'; return; }
        var start = el.offsetHeight;
        el.style.height = 'auto';
        var end = open ? el.scrollHeight : 0;
        el.style.height = start + 'px';
        void el.offsetHeight;
        el.style.height = end + 'px';
        var done = function () { if (open) { el.style.height = 'auto'; } el.removeEventListener('transitionend', done); };
        el.addEventListener('transitionend', done);
    }
    function vehicleById(id) { return W.vehicles.filter(function (v) { return v.id === Number(id); })[0] || null; }
    function dueDate(v) {
        var best = v.next && v.next.data_estimata ? v.next.data_estimata : null;
        (v.ops || []).forEach(function (op) {
            if (op.zile_ramase !== null && op.zile_ramase !== undefined) {
                var d = new Date(Date.now() + op.zile_ramase * 86400000).toISOString().slice(0, 10);
                if (!best || d < best) { best = d; }
            }
        });
        return best;
    }

    // ------------------------------------------------------------------ filtre
    function opFilterActive() { return !!(state.comp || state.adv.cat.length || state.adv.optype.length || state.adv.crit.length); }
    function opMatch(op) {
        if (state.comp && op.comp !== state.comp) { return false; }
        if (state.adv.cat.length && state.adv.cat.indexOf(op.cat) < 0) { return false; }
        if (state.adv.optype.length && state.adv.optype.indexOf(op.tip) < 0) { return false; }
        if (state.adv.crit.length && !state.adv.crit.some(function (c) { return (op.crit || []).indexOf(c) >= 0; })) { return false; }
        return true;
    }
    function baseMatch(v) {
        if (state.fleet && v.familie !== state.fleet) { return false; }
        if (state.type && (!v.next || v.next.type !== state.type)) { return false; }
        if (state.status) {
            if (state.status === 'programata' || state.status === 'in_desfasurare') {
                if (!v.workflow || v.workflow.status !== state.status) { return false; }
            } else if (v.status !== state.status) { return false; }
        }
        if (state.q) {
            var q = state.q.toLowerCase();
            var hay = (v.nr + ' ' + v.marca + ' ' + v.model + ' ' + v.vin + ' ' + (v.plan ? v.plan.nume : '') + ' ' + (v.ops || []).map(function (o) { return o.comp + ' ' + o.op; }).join(' ')).toLowerCase();
            if (hay.replace(/\s+/g, '').indexOf(q.replace(/\s+/g, '')) < 0 && hay.indexOf(q) < 0) { return false; }
        }
        if (opFilterActive() && !(v.ops || []).some(opMatch)) { return false; }
        if (state.adv.make.length && state.adv.make.indexOf((v.marca + ' ' + v.model).trim()) < 0) { return false; }
        if (state.adv.veh.length && state.adv.veh.indexOf(String(v.id)) < 0) { return false; }
        if (state.adv.src) {
            var src = v.km && v.km.source ? v.km.source : 'none';
            if (src !== state.adv.src) { return false; }
        }
        if (state.adv.hours === 'da' && !(v.ore && v.ore.total !== null)) { return false; }
        if (state.adv.hours === 'nu' && v.ore && v.ore.total !== null) { return false; }
        if (state.adv.conf === 'da' && v.status === 'neconfigurat') { return false; }
        if (state.adv.conf === 'nu' && v.status !== 'neconfigurat') { return false; }
        if (state.adv.sched === 'da' && !v.workflow) { return false; }
        if (state.adv.sched === 'nu' && v.workflow) { return false; }
        if (state.from || state.to || state.win) {
            var d = dueDate(v);
            if (!d) { return false; }
            if (state.from && d < state.from) { return false; }
            if (state.to && d > state.to) { return false; }
            if (state.win) {
                var lim = new Date(Date.now() + Number(state.win) * 86400000).toISOString().slice(0, 10);
                if (d > lim) { return false; }
            }
        }
        return true;
    }
    function kpiMatch(v) {
        var sts = [];
        if (state.kpi.ok) { sts.push('in_termen'); }
        if (state.kpi.soon) { sts.push('se_apropie'); }
        if (state.kpi.due) { sts.push('scadent', 'depasit'); }
        if (sts.length && sts.indexOf(v.status) < 0) { return false; }
        if (state.kpi.cost && !(v.cost_luna && v.cost_luna.total > 0)) { return false; }
        if (state.cyclePos && (!v.next || v.next.label !== state.cyclePos)) { return false; }
        return true;
    }
    function baseList() { return W.vehicles.filter(baseMatch); }
    function visibleList() {
        var list = baseList().filter(kpiMatch);
        var k = state.sort.key, dir = state.sort.dir;
        list.sort(function (a, b) {
            var r = 0;
            if (k === 'due') {
                r = (SEV[b.status] || 0) - (SEV[a.status] || 0);
                if (r === 0) { r = rem(a) - rem(b); }
            } else if (k === 'nr') { r = a.nr.localeCompare(b.nr, 'ro'); }
            else if (k === 'model') { r = (a.marca + a.model).localeCompare(b.marca + b.model, 'ro'); }
            else if (k === 'km') { r = rem(a) - rem(b); }
            else if (k === 'pct') { r = pct(b) - pct(a); }
            else if (k === 'cost') { r = (b.cost_estimat || 0) - (a.cost_estimat || 0); }
            else if (k === 'ciclu') { r = ((b.secventa || {}).x_done || 0) - ((a.secventa || {}).x_done || 0); }
            if (r === 0) { r = a.nr.localeCompare(b.nr, 'ro'); }
            return r * dir;
        });
        return list;
    }
    function rem(v) {
        if (v.next && v.next.km_ramasi !== null && v.next.km_ramasi !== undefined) { return v.next.km_ramasi; }
        var min = Infinity;
        (v.ops || []).forEach(function (o) { if (o.km_ramasi !== null && o.km_ramasi !== undefined && o.km_ramasi < min) { min = o.km_ramasi; } });
        return min;
    }
    function pct(v) { return v.next && v.next.pct !== null && v.next.pct !== undefined ? v.next.pct : -1; }

    // ------------------------------------------------------------------ KPI
    var KPI = [
        { key: 'all', tone: 'blue', icon: 'bi-truck', label: 'Vehicule monitorizate' },
        { key: 'ok', tone: 'green', icon: 'bi-wrench-adjustable', label: 'Revizii în termen' },
        { key: 'soon', tone: 'amber', icon: 'bi-clock-history', label: 'Se apropie de termen' },
        { key: 'due', tone: 'red', icon: 'bi-exclamation-triangle', label: 'Revizii scadente' },
        { key: 'cycle', tone: 'purple', icon: 'bi-stack', label: 'Secvență X / Y' },
        { key: 'cost', tone: 'violet', icon: 'bi-coin', label: 'Costuri luna în curs' }
    ];
    function renderKpis() {
        var base = baseList();
        var total = W.vehicles.length;
        var filtered = base.length !== total;
        var count = function (fn) { return base.filter(fn).length; };
        var configured = base.filter(function (v) { return v.status !== 'neconfigurat'; });
        var ok = count(function (v) { return v.status === 'in_termen'; });
        var soon = count(function (v) { return v.status === 'se_apropie'; });
        var due = count(function (v) { return v.status === 'scadent' || v.status === 'depasit'; });
        var overdue = count(function (v) { return v.status === 'depasit'; });
        var nextY = count(function (v) { return v.next && v.next.type === 'Y'; });
        // „Se apropie de Y”: urmeaza ultima X dinaintea lui Y (din secventa calculata pe km).
        var approachingY = count(function (v) { return v.secventa && v.next && v.next.type === 'X' && v.secventa.x_before_y !== null && v.next.x_number >= v.secventa.x_before_y; });
        var cost = 0;
        base.forEach(function (v) { if (v.cost_luna) { cost += v.cost_luna.total || 0; } });
        var fam = { cap_tractor: 0, camion: 0, semiremorca: 0 };
        base.forEach(function (v) { fam[v.familie] = (fam[v.familie] || 0) + 1; });
        var soonDays = 0;
        base.forEach(function (v) { if (v.status === 'se_apropie') { (v.next.criteria || []).forEach(function (c) { if (c.tip === 'calendar' && c.measured) { soonDays = Math.max(soonDays, c.prag || 0); } }); } });
        var pctOf = function (n, d) { return d > 0 ? Math.round(n / d * 1000) / 10 : 0; };
        var vals = {
            all: { v: num(base.length), d: (filtered ? 'din ' + num(total) + ' în flotă · ' : '') + fam.cap_tractor + ' cap tractor · ' + fam.camion + ' camion · ' + fam.semiremorca + ' semi', p: 100 },
            ok: { v: num(ok), d: configured.length ? num(pctOf(ok, configured.length), 1) + '% din cele configurate (' + configured.length + ')' : 'Niciun vehicul configurat', p: pctOf(ok, configured.length) },
            soon: { v: num(soon), d: soonDays ? '≤ ' + soonDays + ' zile sau prag km / ore' : 'În pragul de avertizare', p: pctOf(soon, configured.length) },
            due: { v: num(due), d: overdue ? overdue + ' depășite · ' + (due - overdue) + ' scadente' : 'Depășite / urgente', p: pctOf(due, configured.length) },
            cycle: { v: num(approachingY + nextY), d: 'Următoarea Y: ' + nextY + ' vehicule', p: pctOf(nextY, configured.length) },
            cost: { v: lei(cost), d: 'Consumabile + manoperă (revizii finalizate)', p: 0 }
        };
        $('rv2Kpis').innerHTML = KPI.map(function (k) {
            var active = k.key === 'cycle' ? (state.kpiOpen === 'cycle' || !!state.cyclePos) : (k.key === 'cost' ? (state.kpi.cost || state.kpiOpen === 'cost') : !!state.kpi[k.key]);
            if ((k.key === 'soon' || k.key === 'due') && state.kpiOpen === k.key) { active = true; }
            return '<button type="button" class="rv2-kpi' + (active ? ' is-active' : '') + '" data-kpi="' + k.key + '" data-tone="' + k.tone + '" aria-pressed="' + (active ? 'true' : 'false') + '">'
                + '<div class="rv2-kpi-top"><span class="rv2-kpi-icon"><i class="bi ' + k.icon + '"></i></span><div class="min-w-0" style="min-width:0">'
                + '<div class="rv2-kpi-label">' + esc(k.label) + '</div><div class="rv2-kpi-value">' + vals[k.key].v + '</div></div></div>'
                + '<div class="rv2-kpi-desc" title="' + esc(vals[k.key].d) + '">' + esc(vals[k.key].d) + '</div>'
                + '<div class="rv2-kpi-bar"><span style="width:' + Math.max(0, Math.min(100, vals[k.key].p)) + '%"></span></div>'
                + '<i class="bi ' + k.icon + ' rv2-kpi-deco" aria-hidden="true"></i></button>';
        }).join('');
        renderKpiExpand(base);
    }
    function onKpi(key) {
        if (key === 'all') {
            state.kpi = {}; state.status = ''; state.cyclePos = ''; state.kpiOpen = '';
            $('rv2FStatus').value = '';
        } else if (key === 'cycle') {
            if (state.kpiOpen === 'cycle') { state.kpiOpen = ''; state.cyclePos = ''; } else { state.kpiOpen = 'cycle'; }
        } else if (key === 'cost') {
            if (state.kpiOpen === 'cost' || state.kpi.cost) { state.kpiOpen = ''; delete state.kpi.cost; } else { state.kpiOpen = 'cost'; state.kpi.cost = true; }
        } else {
            if (state.kpi[key]) { delete state.kpi[key]; if (state.kpiOpen === key) { state.kpiOpen = ''; } }
            else { state.kpi[key] = true; state.kpiOpen = (key === 'soon' || key === 'due') ? key : state.kpiOpen; }
        }
        state.page = 1;
        refresh();
    }
    function renderKpiExpand(base) {
        var wrap = $('rv2KpiExpand');
        var inner = $('rv2KpiExpandInner');
        var html = '';
        if (state.kpiOpen === 'cycle') {
            var configured = base.filter(function (v) { return v.secventa && v.next && v.next.km_target !== null; });
            var dist = {};
            configured.forEach(function (v) { dist[v.next.label] = (dist[v.next.label] || 0) + 1; });
            // Etichetele reale (X1..Xn, Y) — numarul de X rezulta din km, nu e fix.
            var labels = Object.keys(dist).filter(function (l) { return l !== 'Y'; }).sort(function (a, b) { return Number(a.slice(1)) - Number(b.slice(1)); });
            labels.push('Y');
            var maxCount = Math.max.apply(null, labels.map(function (l) { return dist[l] || 0; }).concat([1]));
            var merged = configured.filter(function (v) { return v.next.type === 'Y' && v.next.merged; }).length;
            var between = configured.filter(function (v) { return v.next.type === 'Y' && v.next.between; }).length;
            var lastX = configured.filter(function (v) { return v.next.type === 'X' && v.secventa.x_before_y !== null && v.next.x_number >= v.secventa.x_before_y; }).length;
            html = '<div class="rv2-kx-title"><span><i class="bi bi-stack me-2 text-primary"></i>Secvența X / Y — următoarea revizie, calculată din km (' + configured.length + ' vehicule cu țintă)</span>'
                + (state.cyclePos ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-cycle="">Toate</button>' : '') + '</div>'
                + (configured.length === 0 ? '<div class="rv2-note is-info"><i class="bi bi-info-circle"></i><span>Niciun vehicul nu are încă o țintă km (plan + referința ultimei revizii). Configurează din Configurare revizii → Planuri / Vehicule.</span></div>' : '')
                + '<div class="rv2-kx-seq">' + labels.map(function (l) {
                    var c = dist[l] || 0;
                    return '<button type="button" class="' + (l === 'Y' ? 'is-y' : '') + '" data-cycle="' + l + '" title="Filtrează: următoarea revizie ' + l + '"><b>' + c + '</b><span class="bar" style="height:' + Math.round(c / maxCount * 60 + 3) + 'px' + (state.cyclePos === l ? ';outline:2px solid #1f6feb' : '') + '"></span><small>' + l + '</small></button>';
                }).join('') + '</div>'
                + '<div class="rv2-kx-grid">'
                + '<button type="button" class="rv2-kx-cell is-y" data-cycle="Y"><strong>' + (dist.Y || 0) + '</strong><span>Urmează Revizia Y</span></button>'
                + '<div class="rv2-kx-cell"><strong>' + lastX + '</strong><span>Ultima X înainte de Y</span></div>'
                + '<div class="rv2-kx-cell"><strong>' + merged + '</strong><span>X și Y coincid → o singură Y</span></div>'
                + '<div class="rv2-kx-cell"><strong>' + between + '</strong><span>Y cade între două X</span></div>'
                + '<button type="button" class="rv2-kx-cell" data-set-type="Y"><strong>' + base.filter(function (v) { return v.next && v.next.type === 'Y'; }).length + '</strong><span>Filtrează tip revizie = Y</span></button>'
                + '</div>';
        } else if (state.kpiOpen === 'cost') {
            var rows = base.filter(function (v) { return v.cost_luna && v.cost_luna.total > 0; });
            var sum = { manopera: 0, materiale: 0, rev_X: 0, rev_Y: 0, operatii: 0, total: 0 };
            rows.forEach(function (v) { Object.keys(sum).forEach(function (k) { sum[k] += v.cost_luna[k] || 0; }); });
            rows.sort(function (a, b) { return b.cost_luna.total - a.cost_luna.total; });
            html = '<div class="rv2-kx-title"><span><i class="bi bi-coin me-2" style="color:#8b5cf6"></i>Costuri în luna curentă (revizii finalizate și operațiuni înregistrate)</span></div>'
                + '<div class="rv2-kx-grid mb-3">'
                + ['total:Total', 'rev_X:Revizii X', 'rev_Y:Revizii Y', 'operatii:Operațiuni independente', 'materiale:Consumabile / piese', 'manopera:Manoperă'].map(function (p) {
                    var kv = p.split(':');
                    return '<div class="rv2-kx-cell"><strong>' + lei(sum[kv[0]]) + '</strong><span>' + kv[1] + '</span></div>';
                }).join('') + '</div>'
                + (rows.length ? '<table class="rv2-kx-table"><thead><tr><th>Nr. auto</th><th>Revizii X</th><th>Revizii Y</th><th>Operațiuni</th><th>Materiale</th><th>Manoperă</th><th class="text-end">Total</th></tr></thead><tbody>'
                    + rows.map(function (v) {
                        var c = v.cost_luna;
                        return '<tr class="is-click" data-select="' + v.id + '"><td><b>' + esc(v.nr) + '</b></td><td>' + lei(c.rev_X || 0) + '</td><td>' + lei(c.rev_Y || 0) + '</td><td>' + lei(c.operatii || 0) + '</td><td>' + lei(c.materiale || 0) + '</td><td>' + lei(c.manopera || 0) + '</td><td class="text-end fw-bold">' + lei(c.total) + '</td></tr>';
                    }).join('') + '</tbody></table>'
                    : '<div class="text-muted small">Nicio cheltuială de mentenanță înregistrată luna aceasta pentru vehiculele filtrate.</div>');
        } else if (state.kpiOpen === 'soon' || state.kpiOpen === 'due') {
            var want = state.kpiOpen === 'soon' ? ['se_apropie'] : ['scadent', 'depasit'];
            var byComp = {};
            base.forEach(function (v) {
                (v.ops || []).forEach(function (op) { if (want.indexOf(op.status) >= 0) { (byComp[op.comp] = byComp[op.comp] || []).push(v.nr); } });
            });
            var comps = Object.keys(byComp).sort(function (a, b) { return byComp[b].length - byComp[a].length; });
            html = '<div class="rv2-kx-title"><span><i class="bi bi-diagram-2 me-2"></i>' + (state.kpiOpen === 'soon' ? 'Operațiuni care se apropie de termen' : 'Operațiuni scadente / depășite') + ' — pe componente</span></div>'
                + (comps.length ? '<div class="rv2-kx-grid">' + comps.map(function (c) {
                    return '<button type="button" class="rv2-kx-cell" data-set-comp="' + esc(c) + '" title="' + esc(byComp[c].join(', ')) + '"><strong>' + byComp[c].length + '</strong><span>' + esc(c) + '</span></button>';
                }).join('') + '</div>' : '<div class="text-muted small">Nicio operațiune individuală în această stare (starea poate veni din intervalul reviziei).</div>');
        }
        var open = html !== '';
        if (open) { inner.innerHTML = html; }
        if (open !== (wrap.dataset.open === '1')) {
            wrap.dataset.open = open ? '1' : '0';
            animateHeight(wrap, open);
        } else if (open) {
            wrap.style.height = 'auto';
        }
    }

    // ------------------------------------------------------------------ chips
    function chips() {
        var list = [];
        var add = function (label, clear) { list.push({ label: label, clear: clear }); };
        var kpiNames = { ok: 'În termen', soon: 'Se apropie de termen', due: 'Scadente / depășite', cost: 'Cu costuri luna aceasta' };
        Object.keys(state.kpi).forEach(function (k) { add('Card: ' + kpiNames[k], function () { delete state.kpi[k]; if (state.kpiOpen === k) { state.kpiOpen = ''; } }); });
        if (state.cyclePos) { add('Următoarea revizie: ' + state.cyclePos, function () { state.cyclePos = ''; }); }
        var fleetNames = { cap_tractor: 'Capete tractor', camion: 'Camioane', semiremorca: 'Semiremorci' };
        if (state.fleet) { add('Flotă: ' + fleetNames[state.fleet], function () { state.fleet = ''; $('rv2FFleet').value = ''; }); }
        if (state.type) { add('Revizia ' + state.type, function () { state.type = ''; $('rv2FType').value = ''; }); }
        if (state.status) { add('Status: ' + ($('rv2FStatus').selectedOptions[0] || {}).textContent, function () { state.status = ''; $('rv2FStatus').value = ''; }); }
        if (state.comp) { add('Componentă: ' + state.comp, function () { state.comp = ''; $('rv2FComp').value = ''; }); }
        if (state.q) { add('Căutare: „' + state.q + '”', function () { state.q = ''; $('rv2FSearch').value = ''; }); }
        if (state.from) { add('Scadență de la ' + date(state.from), function () { state.from = ''; $('rv2FFrom').value = ''; }); }
        if (state.to) { add('Scadență până la ' + date(state.to), function () { state.to = ''; $('rv2FTo').value = ''; }); }
        if (state.win) { add('Următoarele ' + state.win + ' zile', function () { state.win = ''; $('rv2FWindow').value = ''; }); }
        var advNames = { make: 'Model', veh: 'Vehicul', cat: 'Categorie', optype: 'Tip operațiune', crit: 'Criteriu' };
        ['make', 'veh', 'cat', 'optype', 'crit'].forEach(function (k) {
            state.adv[k].forEach(function (val) {
                var label = val;
                if (k === 'veh') { var v = vehicleById(val); label = v ? v.nr : val; }
                if (k === 'cat') { label = (L.categories || {})[val] || val; }
                if (k === 'optype') { label = (L.op_types || {})[val] || val; }
                add(advNames[k] + ': ' + label, function () { state.adv[k] = state.adv[k].filter(function (x) { return x !== val; }); syncAdvInputs(); });
            });
        });
        var single = { src: ['Sursă km', { can: 'CAN', gps: 'GPS', contor: 'Contor', none: 'Fără citire' }], hours: ['Ore motor', { da: 'cu ore SAS', nu: 'fără ore SAS' }], conf: ['Configurare', { da: 'configurate', nu: 'neconfigurate' }], sched: ['Programare', { da: 'programate', nu: 'neprogramate' }] };
        Object.keys(single).forEach(function (k) {
            if (state.adv[k]) { add(single[k][0] + ': ' + single[k][1][state.adv[k]], function () { state.adv[k] = ''; syncAdvInputs(); }); }
        });
        var box = $('rv2Chips');
        box.innerHTML = list.map(function (c, i) { return '<span class="rv2-chip">' + esc(c.label) + '<button type="button" data-chip="' + i + '" aria-label="Elimină filtrul">✕</button></span>'; }).join('')
            + (list.length > 1 ? '<button type="button" class="rv2-chip-reset" data-reset>Reset filtre</button>' : '');
        box._chips = list;
        var advCount = ['make', 'veh', 'cat', 'optype', 'crit'].reduce(function (s, k) { return s + state.adv[k].length; }, 0) + ['src', 'hours', 'conf', 'sched'].filter(function (k) { return state.adv[k]; }).length;
        $('rv2AdvCount').hidden = advCount === 0;
        $('rv2AdvCount').textContent = advCount;
    }

    // ------------------------------------------------------------------ tabel
    function cycleCell(v) {
        if (!v.secventa) { return '<span class="text-muted">—</span>'; }
        var s = v.secventa;
        var title = s.x_done + ' revizii X de la ultima Y' + (s.x_before_y !== null ? '; Y vine după a ' + s.x_before_y + '-a X (calculat din km)' : '; Y neprogramată');
        return '<span title="' + esc(title) + '">' + s.x_done + ' / ' + (s.x_before_y !== null ? s.x_before_y : '—') + '</span>';
    }
    function lastCell(v) {
        if (!v.last) { return '<span class="text-muted">—</span>'; }
        return esc(v.last.label) + ' · ' + date(v.last.data);
    }
    function nextCell(v) {
        if (!v.next) { return '<span class="text-muted">Fără plan</span>'; }
        var t = v.next.km_target !== null && v.next.km_target !== undefined ? num(v.next.km_target) + ' km' : (v.next.scadenta_data ? date(v.next.scadenta_data) : (v.next.configurat ? '—' : 'interval neconfigurat'));
        return '<span class="rv2-next"><b class="' + (v.next.type === 'Y' ? 'is-y' : '') + '">' + esc(v.next.label) + '</b> · ' + t + '</span>';
    }
    function remCell(v) {
        if (!v.next) { return '<span class="text-muted">—</span>'; }
        var n = v.next;
        if (n.km_ramasi !== null && n.km_ramasi !== undefined) {
            return n.km_ramasi < 0 ? '<span class="text-danger fw-semibold">−' + num(-n.km_ramasi) + ' km</span>' : num(n.km_ramasi) + ' km';
        }
        if (n.zile_ramase !== null && n.zile_ramase !== undefined) { return n.zile_ramase < 0 ? '<span class="text-danger fw-semibold">' + num(n.zile_ramase) + ' zile</span>' : num(n.zile_ramase) + ' zile'; }
        if (n.ore_ramase !== null && n.ore_ramase !== undefined) { return num(n.ore_ramase) + ' h'; }
        return '<span class="text-muted">—</span>';
    }
    function progCell(v) {
        if (!v.next || v.next.pct === null || v.next.pct === undefined) { return '<div class="rv2-prog rv2-tone-' + esc(v.status) + '"><span class="rv2-prog-pct">—</span>' + bar(0, 'neconfigurat') + '</div>'; }
        var crit = { km: 'km', ore: 'ore motor', calendar: 'calendar' }[v.next.dominant] || '';
        return '<div class="rv2-prog rv2-tone-' + esc(v.next.status) + '" title="Consumat din intervalul reviziei (' + crit + ')"><span class="rv2-prog-pct">' + num(v.next.pct, 1) + '%</span>' + bar(v.next.pct, v.next.status) + '</div>';
    }
    function statusCell(v) {
        var html = badge(v.status);
        if (v.workflow) {
            html += '<span class="rv2-wf' + (v.workflow.status === 'programata' ? ' is-prog' : '') + '"><i class="bi bi-calendar-check"></i> ' + esc(v.workflow.label)
                + (v.workflow.data_programata ? ' ' + date(v.workflow.data_programata) : '') + '</span>';
        }
        return html;
    }
    function renderTable() {
        var list = visibleList();
        var total = list.length;
        var pages = Math.max(1, Math.ceil(total / state.per));
        if (state.page > pages) { state.page = pages; }
        var slice = list.slice((state.page - 1) * state.per, state.page * state.per);
        $('rv2Count').textContent = 'Vehicule (' + total + (total !== W.vehicles.length ? ' din ' + W.vehicles.length : '') + ')';
        $('rv2Total').textContent = 'din ' + total + ' rezultate';
        var sortNames = { due: 'Scadență', nr: 'Nr. auto', model: 'Model', km: 'Km rămași', pct: 'Progres', cost: 'Cost estimat', ciclu: 'Secvență' };
        $('rv2SortBtn').innerHTML = 'Sortare: ' + sortNames[state.sort.key] + ' <i class="bi bi-arrow-' + (state.sort.dir === 1 ? 'up' : 'down') + '"></i>';
        var body = $('rv2Body');
        if (D.error) {
            body.innerHTML = '<div class="rv2-empty"><i class="bi bi-exclamation-octagon"></i>' + esc(D.error) + '</div>';
            return;
        }
        if (total === 0) {
            body.innerHTML = '<div class="rv2-empty"><i class="bi bi-search"></i>Niciun vehicul nu corespunde filtrelor active.<br><button type="button" class="btn btn-link btn-sm" data-reset>Reset filtre</button></div>';
        } else if (state.view === 'grid') {
            body.innerHTML = '<div class="rv2-grid">' + slice.map(function (v) {
                return '<div class="rv2-gcard' + (state.selected === v.id ? ' is-selected' : '') + '" data-select="' + v.id + '" tabindex="0">'
                    + '<div class="rv2-gcard-top"><span class="rv2-plate"><i class="bi bi-truck"></i>' + esc(v.nr) + '</span>' + badge(v.status) + '</div>'
                    + '<div class="small text-muted mb-1">' + esc(v.marca + ' ' + v.model) + ' · X de la ultima Y ' + cycleCell(v) + '</div>'
                    + '<div class="small mb-1">' + nextCell(v) + '</div>' + progCell(v)
                    + '<div class="d-flex justify-content-between small mt-1"><span>' + remCell(v) + '</span><span>' + lei(v.cost_estimat) + '</span></div></div>';
            }).join('') + '</div>';
        } else {
            var head = [['', ''], ['nr', 'Nr. auto'], ['model', 'Model'], ['ciclu', 'X / Y'], ['', 'Ultima revizie'], ['', 'Următoarea revizie'], ['km', 'Km rămași'], ['pct', 'Progres'], ['due', 'Status'], ['cost', 'Cost estimat'], ['', '']];
            var allChecked = slice.length > 0 && slice.every(function (v) { return state.checked[v.id]; });
            var html = '<div class="rv2-table-wrap"><table class="rv2-table"><thead><tr>' + head.map(function (h, i) {
                if (i === 0) { return '<th style="width:40px"><input type="checkbox" class="rv2-check" data-check-all ' + (allChecked ? 'checked' : '') + ' aria-label="Selectează pagina"></th>'; }
                return '<th' + (h[0] ? ' class="is-sortable" data-sort="' + h[0] + '"' : '') + '>' + h[1] + (state.sort.key === h[0] && h[0] ? ' <i class="bi bi-arrow-' + (state.sort.dir === 1 ? 'up' : 'down') + ' small"></i>' : '') + '</th>';
            }).join('') + '</tr></thead><tbody>';
            slice.forEach(function (v) {
                var open = !!state.expanded[v.id];
                html += '<tr class="rv2-row' + (state.selected === v.id ? ' is-selected' : '') + '" data-select="' + v.id + '">'
                    + '<td><input type="checkbox" class="rv2-check" data-check="' + v.id + '" ' + (state.checked[v.id] ? 'checked' : '') + ' aria-label="Selectează ' + esc(v.nr) + '"></td>'
                    + '<td><span class="rv2-plate"><i class="bi bi-truck"></i>' + esc(v.nr) + '</span></td>'
                    + '<td>' + esc(v.marca + ' ' + v.model) + '</td>'
                    + '<td>' + cycleCell(v) + '</td>'
                    + '<td>' + lastCell(v) + '</td>'
                    + '<td>' + nextCell(v) + '</td>'
                    + '<td>' + remCell(v) + '</td>'
                    + '<td>' + progCell(v) + '</td>'
                    + '<td>' + statusCell(v) + '</td>'
                    + '<td>' + lei(v.cost_estimat) + '</td>'
                    + '<td><button type="button" class="rv2-caret' + (open ? ' is-open' : '') + '" data-expand="' + v.id + '" aria-expanded="' + open + '" aria-label="Componente"><i class="bi bi-chevron-down"></i></button></td></tr>'
                    + '<tr class="rv2-detail-row"' + (open ? '' : ' hidden') + ' data-detail-row="' + v.id + '"><td colspan="11"><div class="rv2-detail" style="height:' + (open ? 'auto' : '0') + '" data-detail="' + v.id + '">'
                    + (open ? detailHtml(v) : '') + '</div></td></tr>';
            });
            html += '</tbody></table></div>';
            var checkedIds = Object.keys(state.checked).filter(function (k) { return state.checked[k]; });
            if (checkedIds.length) {
                html = '<div class="d-flex align-items-center gap-2 px-3 py-2 border-top border-bottom bg-light small"><b>' + checkedIds.length + ' selectate</b>'
                    + '<button type="button" class="btn btn-sm btn-outline-primary" data-bulk="filter">Arată doar selecția</button>'
                    + (CAN.edit ? '<button type="button" class="btn btn-sm btn-outline-primary" data-bulk="schedule">Programează revizia</button>' : '')
                    + '<button type="button" class="btn btn-sm btn-link" data-bulk="clear">Golește selecția</button></div>' + html;
            }
            body.innerHTML = html;
        }
        renderPager(pages);
        $('rv2ExpandAll').innerHTML = slice.length && slice.every(function (v) { return state.expanded[v.id]; }) ? '<i class="bi bi-arrows-collapse"></i> Restrânge tot' : '<i class="bi bi-arrows-expand"></i> Expandă tot';
        $('rv2ExpandAll').hidden = state.view === 'grid';
    }
    function renderPager(pages) {
        var p = state.page;
        var btns = ['<button type="button" data-page="' + (p - 1) + '"' + (p <= 1 ? ' disabled' : '') + ' aria-label="Pagina anterioară"><i class="bi bi-chevron-left"></i></button>'];
        var from = Math.max(1, p - 2), to = Math.min(pages, from + 4);
        from = Math.max(1, to - 4);
        for (var i = from; i <= to; i++) { btns.push('<button type="button" data-page="' + i + '" class="' + (i === p ? 'is-on' : '') + '">' + i + '</button>'); }
        btns.push('<button type="button" data-page="' + (p + 1) + '"' + (p >= pages ? ' disabled' : '') + ' aria-label="Pagina următoare"><i class="bi bi-chevron-right"></i></button>');
        $('rv2Pager').innerHTML = btns.join('');
    }

    function opRow(v, op) {
        var y = op.sect === 'Y';
        var crit = (op.crit || []).map(function (c) { return { km: 'Km', ore: 'Ore', calendar: 'Calendar' }[c]; }).join(' / ') || (op.mode === 'la_y' ? 'Ciclu (Y)' : '—');
        var mode = op.mode === 'revizie' ? '<span class="rv2-pill is-gray" title="Fără interval propriu: intervalul reviziei, măsurat de la ultima execuție a operațiunii">cu revizia</span> ' : '';
        var mini = op.pct === null || op.pct === undefined ? '<span class="text-muted small">—</span>'
            : '<span class="rv2-mini rv2-tone-' + esc(op.status) + '">' + bar(op.pct, op.status) + '<small>' + num(op.pct, 0) + '%</small></span>';
        return '<tr class="is-op' + (y ? ' is-y' : '') + '" data-op="' + v.id + ':' + op.id + '">'
            + '<td><span class="rv2-comp"><i class="bi ' + (CAT_ICON[op.cat] || 'bi-tools') + '"></i>' + esc(op.comp) + (op.de_verificat ? ' <i class="bi bi-exclamation-circle text-warning" title="Denumire de verificat (posibil duplicat în catalog)"></i>' : '') + (op.exceptie ? ' <span class="rv2-pill is-warn" title="Excepție pe vehicul">excepție</span>' : '') + '</span></td>'
            + '<td>' + esc(op.op) + '</td>'
            + '<td>' + esc((L.op_types || {})[op.tip] || op.tip) + '</td>'
            + '<td class="text-truncate" style="max-width:180px" title="' + esc(op.factori) + '">' + esc(op.factori || '—') + '</td>'
            + '<td>' + crit + '</td>'
            + '<td>' + mode + esc(op.interval) + '</td>'
            + '<td>' + esc(op.next_due) + '</td>'
            + '<td>' + mini + '</td>'
            + '<td>' + badge(op.status) + '</td>'
            + '<td class="text-end"><div class="dropdown"><button type="button" class="btn btn-sm btn-link text-secondary p-0 px-1" data-bs-toggle="dropdown" aria-label="Acțiuni" data-stop><i class="bi bi-three-dots-vertical"></i></button><ul class="dropdown-menu dropdown-menu-end">'
            + (CAN.edit ? '<li><button class="dropdown-item" type="button" data-act="record-op" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-check2-square me-2"></i>Înregistrează operațiune</button></li>'
                + '<li><button class="dropdown-item" type="button" data-act="schedule" data-v="' + v.id + '"><i class="bi bi-calendar-plus me-2"></i>' + (v.workflow ? 'Replanifică lucrarea' : 'Programează') + '</button></li>'
                + '<li><button class="dropdown-item" type="button" data-act="note-op" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-chat-left-text me-2"></i>Adaugă observație (inspecție)</button></li>' : '')
            + '<li><button class="dropdown-item" type="button" data-act="comp" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-info-circle me-2"></i>Detalii componentă</button></li>'
            + '<li><button class="dropdown-item" type="button" data-act="history-op" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-clock-history me-2"></i>Vizualizează istoric</button></li>'
            + (CAN.configure ? '<li><button class="dropdown-item" type="button" data-act="edit-rule" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-sliders me-2"></i>Editează regula</button></li>' : '')
            + '</ul></div></td></tr>';
    }
    function sectHtml(v, key, title, ops, pillClass, collapsedDefault) {
        var ck = v.id + ':' + key;
        var collapsed = state.collapsed[ck] !== undefined ? state.collapsed[ck] : collapsedDefault;
        if (!ops.length) { return ''; }
        return '<button type="button" class="rv2-sect-head' + (collapsed ? ' is-collapsed' : '') + '" data-sect="' + ck + '" aria-expanded="' + !collapsed + '"><i class="bi bi-chevron-down"></i>' + esc(title)
            + '<span class="rv2-pill ' + pillClass + '">' + ops.length + ' operațiuni</span></button>'
            + '<div class="rv2-sect-body" data-sect-body="' + ck + '" style="height:' + (collapsed ? '0' : 'auto') + '"><table class="rv2-sub"><thead><tr><th>Componentă</th><th>Operațiune</th><th>Tip</th><th>Factori de uzură</th><th>Criterii</th><th>Interval configurat</th><th>Următoarea scadență</th><th>Progres</th><th>Status</th><th></th></tr></thead><tbody>'
            + ops.map(function (op) { return opRow(v, op); }).join('') + '</tbody></table></div>';
    }
    function detailHtml(v) {
        if (!v.plan) {
            return '<div class="rv2-detail-inner"><div class="rv2-note is-info"><i class="bi bi-info-circle"></i><span>Niciun plan de mentenanță nu se aplică acestui vehicul ('
                + esc(v.marca + ' ' + v.model) + '). ' + (CAN.configure ? '<a href="#" data-act="cfg-vehicle" data-v="' + v.id + '">Configurează planul</a>.' : 'Cere configurarea planului.') + '</span></div>'
                + legacyLinks(v) + '</div>';
        }
        var ops = v.ops || [];
        var filtered = opFilterActive();
        var shown = filtered ? ops.filter(opMatch) : ops;
        var x = shown.filter(function (o) { return o.sect === 'X'; });
        var y = shown.filter(function (o) { return o.sect === 'Y'; });
        var isY = v.next.type === 'Y';
        var html = '<div class="rv2-detail-inner">';
        html += '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2"><div class="small fw-semibold"><i class="bi bi-diagram-3 me-1 text-muted"></i>Componente &amp; Operațiuni – Revizia '
            + esc(v.next.label) + (isY ? ' (X + operațiuni Y)' : '') + ' <span class="text-muted fw-normal">· plan „' + esc(v.plan.nume) + '”</span>'
            + (filtered ? ' <span class="rv2-pill is-warn">filtrate: ' + shown.length + ' din ' + ops.length + '</span>' : '') + '</div>'
            + '<div class="d-flex gap-2">' + (CAN.edit ? '<button type="button" class="btn btn-sm btn-primary" data-act="execute" data-v="' + v.id + '"><i class="bi bi-clipboard-check"></i> ' + (v.workflow ? 'Continuă lucrarea' : 'Înregistrează revizia') + '</button>' : '')
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="history-v" data-v="' + v.id + '"><i class="bi bi-clock-history"></i> Istoric</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="explain" data-v="' + v.id + '"><i class="bi bi-calculator"></i> Calcul</button></div></div>';
        if (v.info) { html += '<div class="rv2-note is-info"><i class="bi bi-signpost-split"></i><span>' + esc(v.info) + '</span></div>'; }
        (v.next.notes || []).forEach(function (n) { html += '<div class="rv2-note is-info"><i class="bi bi-info-circle"></i><span>' + esc(n) + '</span></div>'; });
        if (!v.next.configurat) {
            html += '<div class="rv2-note"><i class="bi bi-sliders"></i><span>Planul nu are intervale la nivel de revizie (km / ore / luni): starea reviziei rămâne „Neconfigurat”; operațiunile cu interval propriu sunt evaluate separat.</span></div>';
        }
        if (isY) {
            html += sectHtml(v, 'X', 'Revizia X — Operațiuni de bază', x, '', false);
            html += sectHtml(v, 'Y', 'Revizia Y — Operațiuni suplimentare', y, 'is-y', false);
        } else {
            html += sectHtml(v, 'X', 'Revizia ' + v.next.label + ' — Operațiuni', x, '', false);
            html += sectHtml(v, 'Y', 'Incluse în Revizia Y — monitorizate separat', y, 'is-y', true);
        }
        if (!shown.length) { html += '<div class="text-muted small py-2">Nicio operațiune aplicabilă' + (filtered ? ' pentru filtrele active' : ' (verifică șabloanele planului și tipul vehiculului)') + '.</div>'; }
        return html + '</div>';
    }
    function legacyLinks(v) {
        var lg = D.urls.legacy;
        var trailer = v.familie === 'semiremorca';
        return '<div class="small mt-2 text-muted">Monitorizare km SAS existentă: '
            + (trailer ? '' : '<a href="' + esc(lg.schimb_ulei) + '">Schimb ulei</a> · ') + '<a href="' + esc(lg.placute_frana) + '">Plăcuțe frână</a> · <a href="' + esc(lg.anvelope) + '">Anvelope</a></div>';
    }
    function toggleExpand(id, force) {
        id = Number(id);
        var open = force !== undefined ? force : !state.expanded[id];
        if (open) { state.expanded[id] = true; } else { delete state.expanded[id]; }
        var row = root.querySelector('[data-detail-row="' + id + '"]');
        var box = root.querySelector('[data-detail="' + id + '"]');
        var caret = root.querySelector('[data-expand="' + id + '"]');
        if (!row || !box) { return; }
        if (caret) { caret.classList.toggle('is-open', open); caret.setAttribute('aria-expanded', open); }
        if (open) {
            row.hidden = false;
            box.innerHTML = detailHtml(vehicleById(id));
            box.style.height = '0px';
            animateHeight(box, true);
        } else {
            animateHeight(box, false);
            setTimeout(function () { if (!state.expanded[id]) { row.hidden = true; box.innerHTML = ''; } }, reduced ? 0 : 250);
        }
    }

    // ------------------------------------------------------------------ panou lateral
    function ring(p, status) {
        var r = 34, c = 2 * Math.PI * r;
        var v = p === null || p === undefined ? 0 : Math.max(0, Math.min(100, p));
        return '<div class="rv2-ring rv2-tone-' + esc(status) + '" style="--c:var(--c)"><svg viewBox="0 0 82 82"><circle class="bg" cx="41" cy="41" r="' + r + '"/><circle class="fg" cx="41" cy="41" r="' + r + '" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + (c * (1 - v / 100)).toFixed(1) + '"/></svg><span>' + (p === null || p === undefined ? '—' : num(p, 1) + '%') + '</span></div>';
    }
    function renderPanel() {
        var panel = $('rv2Panel');
        var v = state.selected !== null ? vehicleById(state.selected) : null;
        if (!v) {
            panel.innerHTML = '<div class="rv2-panel-empty"><i class="bi bi-cursor"></i>Selectează un vehicul din tabel pentru secvența X / Y, următoarea revizie și acțiuni.</div>';
            return;
        }
        var det = state.details[v.id];
        var img = v.poza ? '<img class="rv2-veh-img" src="' + esc(v.poza) + '" alt="" onerror="this.outerHTML=\'<div class=&quot;rv2-veh-img&quot;><i class=&quot;bi bi-truck&quot;></i></div>\'">' : '<div class="rv2-veh-img"><i class="bi bi-truck"></i></div>';
        var html = '<div class="rv2-veh">' + img + '<div style="min-width:0"><div class="rv2-veh-nr">' + esc(v.nr) + ' <span class="rv2-active">Activ</span></div>'
            + '<div class="rv2-veh-meta">' + esc(v.marca + ' ' + v.model) + (v.an ? ' · ' + v.an : '') + '</div>'
            + '<div class="rv2-veh-meta text-muted small">' + (v.vin ? 'VIN: ' + esc(v.vin) : 'VIN necompletat') + '</div></div>'
            + '<div class="rv2-veh-actions">' + (CAN.configure ? '<button type="button" class="btn btn-sm" data-act="cfg-vehicle" data-v="' + v.id + '" title="Plan, referințe, excepții"><i class="bi bi-pencil-square"></i></button>' : '')
            + '<div class="dropdown"><button type="button" class="btn btn-sm" data-bs-toggle="dropdown" aria-label="Mai multe"><i class="bi bi-three-dots-vertical"></i></button><ul class="dropdown-menu dropdown-menu-end">'
            + '<li><button class="dropdown-item" type="button" data-act="history-v" data-v="' + v.id + '"><i class="bi bi-clock-history me-2"></i>Istoric vehicul</button></li>'
            + '<li><button class="dropdown-item" type="button" data-act="explain" data-v="' + v.id + '"><i class="bi bi-calculator me-2"></i>Simulare calcul</button></li>'
            + (CAN.edit && v.plan ? '<li><button class="dropdown-item" type="button" data-act="record-op" data-v="' + v.id + '"><i class="bi bi-check2-square me-2"></i>Înregistrează operațiune</button></li>' : '')
            + '</ul></div></div></div>';

        var km = v.km || {};
        html += '<div class="rv2-psec"><div class="rv2-psec-title">Date curente</div><div class="rv2-stats" style="margin-top:0">'
            + '<div>Odometru<b>' + (km.value !== null && km.value !== undefined ? num(km.value) + ' km' : '—') + '</b></div>'
            + '<div>Sursă<b>' + (km.source ? esc(String(km.source).toUpperCase()) : '—') + (km.stale && km.source ? ' <i class="bi bi-exclamation-triangle text-warning" title="Date învechite"></i>' : '') + '</b></div>'
            + '<div>Citire<b>' + (km.at ? date(km.at, true) : (km.source === 'contor' ? 'contor' : '—')) + '</b></div>'
            + '<div>Km / zi<b>' + (v.km_zi ? num(v.km_zi) : '—') + '</b></div></div>'
            + (km.stale && km.source ? '<div class="rv2-note"><i class="bi bi-clock"></i><span>Ultima citire validă este veche: se afișează ultima valoare cunoscută.</span></div>' : '')
            + (km.note ? '<div class="small text-muted mt-1">' + esc(km.note) + '</div>' : '') + '</div>';

        if (v.secventa) {
            var c = v.secventa;
            var seq = c.sequence || [];
            var doneCount = seq.filter(function (s) { return s.state === 'done'; }).length;
            var doneW = seq.length > 1 ? Math.min(100, (doneCount / (seq.length - 1)) * 86) : 0;
            html += '<div class="rv2-psec"><div class="rv2-psec-title">Secvență revizii X / Y <span class="rv2-pill is-gray">' + c.y_done + ' Y efectuate</span></div>'
                + (seq.length ? '<div class="rv2-cycle"><div class="rv2-cycle-fill" style="width:' + doneW + '%"></div>' + seq.map(function (s) {
                    var cls = 'rv2-step' + (s.state === 'done' ? ' is-done' : '') + (s.state === 'next' ? ' is-next' : '') + (s.type === 'Y' ? ' is-y' : '');
                    var tip = (s.state === 'done' ? 'Efectuată' : (s.state === 'next' ? 'Următoarea' : 'Planificată')) + (s.km ? ' · ' + num(s.km) + ' km' : '') + (s.date ? ' · ' + date(s.date) : '') + (s.merged ? ' · X + Y într-o singură revizie' : '');
                    return '<div class="' + cls + '" title="' + esc(tip) + '"><span class="rv2-step-dot">' + (s.state === 'done' ? '<i class="bi bi-check-lg"></i>' : (s.type === 'Y' ? 'Y' : '')) + '</span>' + esc(s.label)
                        + '<small class="text-muted fw-normal" style="font-size:.66rem">' + (s.km ? num(Math.round(s.km / 1000)) + 'k' : '—') + '</small></div>';
                }).join('') + '</div>' : '<div class="small text-muted">Secvența nu se poate calcula: lipsesc intervalele km sau referințele.</div>')
                + '<div class="rv2-cycle-cap">Următoarea: Revizia ' + esc(v.next.label) + (v.next.type === 'Y' ? ' (X + Y)' : '') + (v.next.km_target !== null ? ' la ' + num(v.next.km_target) + ' km' : '')
                + (c.y_target !== null && v.next.type !== 'Y' ? ' · Y la ' + num(c.y_target) + ' km' + (c.x_before_y !== null ? ' (după încă ' + Math.max(0, c.x_before_y - c.x_done) + ' X)' : '') : '') + '</div>'
                + (v.info ? '<div class="rv2-note is-info mt-2"><i class="bi bi-signpost-split"></i><span>' + esc(v.info) + '</span></div>' : '')
                + (c.notes || []).map(function (t) { return '<div class="rv2-note mt-2"><i class="bi bi-exclamation-circle"></i><span>' + esc(t) + '</span></div>'; }).join('') + '</div>';

            var n = v.next;
            var main = n.km_target !== null && n.km_target !== undefined ? num(n.km_target) + ' km' : (n.scadenta_data ? date(n.scadenta_data) : '—');
            var left = n.km_ramasi !== null && n.km_ramasi !== undefined ? num(n.km_ramasi) + ' km' : (n.zile_ramase !== null && n.zile_ramase !== undefined ? num(n.zile_ramase) + ' zile' : (n.ore_ramase !== null && n.ore_ramase !== undefined ? num(n.ore_ramase) + ' h' : '—'));
            html += '<div class="rv2-psec"><div class="rv2-next-box"><div><div class="lbl">Următoarea revizie <span class="rv2-pill' + (n.type === 'Y' ? ' is-y' : '') + '">' + esc(n.label) + '</span></div>'
                + '<div class="val">' + main + '</div><div class="sub">' + (n.type === 'Y' ? (n.merged ? 'X și Y coincid' : (n.between ? 'Y înaintea următoarei X' : 'Revizia Y')) : (c.y_km_ramasi !== null ? 'Y peste ' + num(c.y_km_ramasi) + ' km' : 'Y neprogramată')) + (n.data_estimata ? ' · estimat ' + date(n.data_estimata) : '') + '</div>'
                + '<div class="rv2-tone-' + esc(n.status) + '">' + bar(n.pct, n.status) + '</div></div>'
                + '<div class="rv2-next-left"><div class="sub">' + (n.km_ramasi !== null && n.km_ramasi !== undefined ? 'Km rămași' : 'Rămas') + '</div><div class="val">' + left + '</div>' + badge(n.status, true) + '</div>'
                + ring(n.pct, n.status) + '</div>';
            if ((n.criteria || []).length) {
                html += '<div class="rv2-crit">' + n.criteria.map(function (cr) {
                    var name = { km: 'Km', ore: 'Ore motor', calendar: 'Calendar' }[cr.tip];
                    var val = !cr.measured ? 'nemăsurat' : (cr.tip === 'calendar' ? (cr.remaining >= 0 ? cr.remaining + ' zile' : 'depășit ' + (-cr.remaining) + ' zile') : num(cr.remaining) + ' ' + cr.unit);
                    return '<div class="rv2-tone-' + esc(cr.status) + '" title="Interval ' + num(cr.interval) + ' ' + esc(cr.unit) + (cr.pct !== null ? ' · ' + num(cr.pct, 1) + '%' : '') + '">' + name + '<b style="color:var(--c)">' + val + '</b></div>';
                }).join('') + '</div><div class="small text-muted mt-1">Procentul = consumat din intervalul criteriului ' + ({ km: 'km', ore: 'ore motor', calendar: 'calendar' }[n.dominant] || '') + ' de la ultima revizie; starea = prima limită atinsă.</div>';
            }
            html += '</div>';
        } else {
            html += '<div class="rv2-psec"><div class="rv2-note is-info"><i class="bi bi-info-circle"></i><span>Vehiculul nu are plan de mentenanță aplicabil. ' + (CAN.configure ? '<a href="#" data-act="cfg-vehicle" data-v="' + v.id + '">Configurează</a>' : '') + '</span></div></div>';
        }

        // Costul de la ultima Y (revizii X / Y si parțiale din perioada curentă a secvenței).
        var cycleCost = null;
        if (det && v.secventa) {
            cycleCost = 0;
            (det.lucrari || []).forEach(function (o) { if ((o.status === 'finalizata' || o.status === 'partiala') && Number(o.nr_y) === v.secventa.y_done + 1) { cycleCost += Number(o.cost_total || 0); } });
        }
        var ore = v.ore || {};
        html += '<div class="rv2-stats"><div>Ultima revizie<b>' + (v.last ? esc(v.last.label) + ' · ' + date(v.last.data) : '—') + '</b></div>'
            + '<div title="Ore cu motorul pornit înregistrate de SAS' + (ore.since ? ' din ' + date(ore.since) : '') + '">Total ore motor<b>' + (ore.total !== null && ore.total !== undefined ? num(ore.total) + ' h' : '—') + '</b></div>'
            + '<div title="Ralanti = parte din orele motor (nu se adună)">Ore ralanti<b>' + (ore.idle !== null && ore.idle !== undefined ? num(ore.idle) + ' h' + (ore.total ? ' <small class="text-muted">(' + num(ore.idle / ore.total * 100, 1) + '%)</small>' : '') : '—') + '</b></div>'
            + '<div title="Revizii de la ultima Y">Cost de la ultima Y<b>' + (cycleCost === null ? (det ? '—' : '…') : lei(cycleCost)) + '</b></div></div>';

        if (v.plan) {
            var list, title, cls = '';
            if (v.next.type === 'Y') {
                list = v.ops.filter(function (o) { return o.sect === 'Y'; });
                title = 'Operațiuni Revizia Y (suplimentare)';
                cls = ' is-y';
            } else {
                list = v.ops.filter(function (o) { return SEV[o.status] >= 3; });
                title = 'Operațiuni scadente / care se apropie';
            }
            html += '<div class="rv2-psec"><div class="rv2-psec-title">' + title + ' <span class="rv2-pill' + cls + '">' + list.length + ' operațiuni</span></div>'
                + (list.length ? '<ul class="rv2-oplist">' + list.map(function (o) {
                    return '<li data-act="comp" data-v="' + v.id + '" data-o="' + o.id + '"><i class="bi ' + (CAT_ICON[o.cat] || 'bi-tools') + '"></i>' + esc(o.comp) + badge(o.status, true) + '</li>';
                }).join('') + '</ul>' : '<div class="small text-muted">Nicio operațiune scadentă independent de revizie.</div>') + '</div>';
        }
        html += '<div class="rv2-psec"><div class="rv2-psec-title">Uzură monitorizată pe km SAS <span class="small text-muted fw-normal">ecrane existente</span></div>';
        if (!det) {
            html += '<div class="rv2-skel"></div><div class="rv2-skel" style="width:70%"></div>';
        } else if (!(det.uzura || []).length) {
            html += '<div class="small text-muted">Nicio componentă urmărită.</div>';
        } else {
            html += '<div class="rv2-legacy">' + det.uzura.map(function (u) {
                var st = { in_regula: 'in_termen', se_apropie: 'se_apropie', necesita: 'scadent', fara_date: 'fara_date', neconfigurat: 'neconfigurat' }[u.status] || 'fara_date';
                return '<a href="' + esc(u.url) + '"><span><i class="bi bi-box-arrow-up-right me-1 text-muted"></i>' + esc(u.nume) + (u.km_ramasi !== null && u.km_ramasi !== undefined ? ' · ' + num(u.km_ramasi) + ' km rămași' : '') + '</span>' + badge(st, true) + '</a>';
            }).join('') + '</div>';
        }
        html += '</div>';
        if (CAN.edit) {
            html += v.plan ? '<button type="button" class="rv2-btn-primary rv2-record" data-act="execute" data-v="' + v.id + '"><i class="bi bi-check2-circle"></i> ' + (v.workflow ? 'Continuă lucrarea (' + esc(v.workflow.label) + ')' : 'Înregistrează revizia') + '</button>'
                : '<button type="button" class="rv2-btn-primary rv2-record" disabled title="Configurează întâi planul"><i class="bi bi-check2-circle"></i> Înregistrează revizia</button>';
        }
        panel.innerHTML = html;
    }
    function select(id) {
        id = Number(id);
        state.selected = id;
        root.querySelectorAll('[data-select].is-selected').forEach(function (el) { el.classList.remove('is-selected'); });
        root.querySelectorAll('[data-select="' + id + '"]').forEach(function (el) { el.classList.add('is-selected'); });
        renderPanel();
        if (!state.details[id]) {
            get('v2_vehicle', { id: id }).then(function (j) {
                state.details[id] = j;
                if (state.selected === id) { renderPanel(); }
            }).catch(function (e) { toast(e.message, 'err'); });
        }
        if (window.innerWidth < 1400) {
            var p = $('rv2Panel');
            if (p.getBoundingClientRect().top > window.innerHeight) { /* panoul e sub tabel pe ecrane mici; utilizatorul derulează */ }
        }
    }

    // ------------------------------------------------------------------ clopotel
    function renderBell() {
        var urgent = W.vehicles.filter(function (v) { return v.status === 'scadent' || v.status === 'depasit' || v.status === 'se_apropie'; })
            .sort(function (a, b) { return (SEV[b.status] || 0) - (SEV[a.status] || 0); });
        var due = urgent.filter(function (v) { return v.status !== 'se_apropie'; }).length;
        $('rv2BellCount').hidden = due === 0;
        $('rv2BellCount').textContent = due > 99 ? '99+' : due;
        $('rv2BellMenu').innerHTML = '<h6 class="dropdown-header">Revizii scadente și apropiate</h6>'
            + (urgent.length ? urgent.slice(0, 30).map(function (v) {
                return '<button class="dropdown-item d-flex justify-content-between gap-2" type="button" data-select="' + v.id + '" data-scroll><span><b>' + esc(v.nr) + '</b> · ' + esc(v.next ? 'Revizia ' + v.next.label : '') + '</span>' + badge(v.status, true) + '</button>';
            }).join('') : '<div class="px-3 py-2 small text-muted">Nicio revizie scadentă sau apropiată.</div>')
            + '<div class="dropdown-divider"></div><a class="dropdown-item small" href="' + esc(D.urls.notificari) + '"><i class="bi bi-envelope me-2"></i>Reguli de notificare (email / SMS)</a>';
    }

    function renderSync() {
        var s = W.sync || {};
        var el = $('rv2Sync');
        el.dataset.state = s.state || 'indisponibil';
        $('rv2SyncText').textContent = 'SasFleet: ' + (s.at ? date(s.at, true) : 'fără date');
        el.title = { actual: 'Date actuale', intarziat: 'Date întârziate', indisponibil: 'Date indisponibile sau învechite' }[s.state] + ' · odometru: ' + (s.odometru_at ? date(s.odometru_at, true) : '—') + ' · ore motor: ' + (s.ore_at ? date(s.ore_at, true) : '—');
    }

    function refresh() {
        renderKpis();
        chips();
        renderTable();
        renderPanel();
    }

    // ------------------------------------------------------------------ opțiuni filtre
    function fillOptions() {
        var f = W.filters || {};
        $('rv2FComp').innerHTML = '<option value="">Toate</option>' + (f.componente || []).map(function (c) { return '<option>' + esc(c) + '</option>'; }).join('');
        $('rv2AMake').innerHTML = (f.marci || []).map(function (c) { return '<option>' + esc(c) + '</option>'; }).join('');
        $('rv2AVeh').innerHTML = W.vehicles.map(function (v) { return '<option value="' + v.id + '">' + esc(v.nr) + '</option>'; }).join('');
        $('rv2ACat').innerHTML = Object.keys(L.categories || {}).map(function (k) { return '<option value="' + k + '">' + esc(L.categories[k]) + '</option>'; }).join('');
        $('rv2AOpType').innerHTML = Object.keys(L.op_types || {}).map(function (k) { return '<option value="' + k + '">' + esc(L.op_types[k]) + '</option>'; }).join('');
        $('rv2SimVeh').innerHTML = W.vehicles.map(function (v) { return '<option value="' + v.id + '">' + esc(v.nr + ' · ' + v.marca + ' ' + v.model) + '</option>'; }).join('');
    }
    function syncAdvInputs() {
        var setMulti = function (id, vals) { Array.prototype.forEach.call($(id).options, function (o) { o.selected = vals.indexOf(o.value) >= 0; }); };
        setMulti('rv2AMake', state.adv.make);
        setMulti('rv2AVeh', state.adv.veh);
        setMulti('rv2ACat', state.adv.cat);
        setMulti('rv2AOpType', state.adv.optype);
        setMulti('rv2ACrit', state.adv.crit);
        $('rv2ASrc').value = state.adv.src;
        $('rv2AHours').value = state.adv.hours;
        $('rv2AConf').value = state.adv.conf;
        $('rv2ASched').value = state.adv.sched;
    }
    function resetFilters() {
        state.kpi = {}; state.cyclePos = ''; state.kpiOpen = '';
        state.fleet = state.type = state.status = state.comp = state.q = state.from = state.to = state.win = '';
        state.adv = { make: [], veh: [], cat: [], optype: [], crit: [], src: '', hours: '', conf: '', sched: '' };
        ['rv2FFleet', 'rv2FType', 'rv2FStatus', 'rv2FComp', 'rv2FSearch', 'rv2FFrom', 'rv2FTo', 'rv2FWindow'].forEach(function (id) { $(id).value = ''; });
        syncAdvInputs();
        state.page = 1;
        refresh();
    }

    // ------------------------------------------------------------------ reincarcare date
    function reload(keepDetails) {
        return get('v2_data').then(function (j) {
            delete j.ok;
            W = j;
            L = W.labels || L;
            if (!keepDetails) { state.details = {}; }
            renderSync();
            renderBell();
            refresh();
            if (state.selected !== null && !state.details[state.selected]) { select(state.selected); }
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    // ------------------------------------------------------------------ executie revizie
    var exec = { form: null };
    function openExecution(vehicleId, scheduleOnly) {
        $('rv2ExecBody').innerHTML = '<div class="rv2-skel"></div><div class="rv2-skel"></div><div class="rv2-skel"></div>';
        $('rv2ExecSum').innerHTML = '';
        modal('rv2ExecModal').show();
        get('v2_workorder', { vehicle_id: vehicleId }).then(function (j) {
            exec.form = j;
            exec.scheduleOnly = !!scheduleOnly;
            renderExecution();
        }).catch(function (e) {
            $('rv2ExecBody').innerHTML = '<div class="alert alert-warning">' + esc(e.message) + '</div>';
        });
    }
    function recipientsFor(item) {
        var stock = exec.form.stoc || { recipiente: [] };
        var sys = item.regula && item.regula.sistem ? item.regula.sistem : item.sistem;
        if (!sys) { return []; }
        var types = FLUID_TYPES[sys] || [];
        return (stock.recipiente || []).filter(function (r) { return types.indexOf(r.tip_fluid) >= 0; });
    }
    function renderExecution() {
        var f = exec.form;
        var v = f.vehicle;
        var isNew = !f.id;
        var closed = ['finalizata', 'partiala', 'anulata'].indexOf(f.status) >= 0;
        $('rv2ExecTitle').innerHTML = (exec.scheduleOnly ? 'Programează' : 'Înregistrează') + ' Revizia <span class="rv2-pill' + (f.tip === 'Y' ? ' is-y' : '') + '">' + esc(f.label) + '</span> · ' + esc(v.nr)
            + ' <span class="small text-muted fw-normal">' + (f.km_tinta ? 'țintă ' + num(f.km_tinta) + ' km' : 'fără țintă km') + (f.id ? ' · lucrarea #' + f.id + ' · ' + esc((L.work || {})[f.status] || f.status) : ' · lucrare nouă') + '</span>';
        var now = localInput(new Date());
        var html = '';
        if (f.info) { html += '<div class="rv2-note is-info"><i class="bi bi-signpost-split"></i><span>' + esc(f.info) + '</span></div>'; }
        if (f.tip === 'Y') { html += '<div class="rv2-note is-info"><i class="bi bi-info-circle"></i><span>Revizia Y este un singur eveniment: include operațiunile Reviziei X plus operațiunile suplimentare Y. După finalizarea completă, următoarea Y se calculează de la km-ul acestei revizii, iar X-urile după politica planului.</span></div>'; }
        html += '<div class="row g-2 mb-3">'
            + '<div class="col-md-2"><label class="form-label" for="rv2EDp">Data programată</label><input type="date" class="form-control" id="rv2EDp" value="' + esc(f.data_programata || '') + '"></div>'
            + '<div class="col-md-3"><label class="form-label" for="rv2EDe">Data execuției</label><input type="datetime-local" class="form-control" id="rv2EDe" max="' + now + '" value="' + esc(f.data_executie ? String(f.data_executie).replace(' ', 'T').slice(0, 16) : '') + '"></div>'
            + '<div class="col-md-2"><label class="form-label" for="rv2EKm">Km la execuție</label><input type="text" inputmode="decimal" class="form-control" id="rv2EKm" value="' + esc(f.km_executie !== null && f.km_executie !== undefined ? Math.round(f.km_executie) : '') + '"></div>'
            + '<div class="col-md-1"><label class="form-label" for="rv2EKs">Sursă</label><select class="form-select" id="rv2EKs">' + ['can', 'gps', 'contor', 'manual'].map(function (s) { return '<option value="' + s + '"' + ((f.km_sursa || 'manual') === s ? ' selected' : '') + '>' + s.toUpperCase() + '</option>'; }).join('') + '</select></div>'
            + '<div class="col-md-1"><label class="form-label" for="rv2EOre">Ore motor</label><input type="text" inputmode="decimal" class="form-control" id="rv2EOre" value="' + esc(f.ore_executie || '') + '" placeholder="opțional"></div>'
            + '<div class="col-md-2"><label class="form-label" for="rv2ETeh">Tehnician</label><input type="text" class="form-control" id="rv2ETeh" maxlength="120" value="' + esc(f.tehnician || '') + '"></div>'
            + '<div class="col-md-1"><label class="form-label" for="rv2ELab">Manoperă (lei)</label><input type="text" inputmode="decimal" class="form-control" id="rv2ELab" value="' + esc(f.cost_manopera || '') + '"></div>'
            + '</div>';
        html += '<div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-semibold">Operațiuni (' + f.items.length + ')</div><div class="d-flex gap-2">'
            + '<button type="button" class="btn btn-sm btn-outline-success" data-exec-all="completed"><i class="bi bi-check-all"></i> Marchează toate efectuate</button></div></div>';
        html += '<div class="rv2-exec-scroll"><table class="rv2-exec-table"><thead><tr><th>Operațiune</th><th>Tip</th><th style="width:130px">Rezultat</th><th>Motiv</th><th>Cant. est.</th><th style="width:90px">Cant. reală</th><th style="width:200px">Din stoc (recipient)</th><th style="width:100px">Cost mat. (lei)</th><th>Observații</th></tr></thead><tbody>';
        var groups = [['X', f.tip === 'Y' ? 'Revizia X — Operațiuni de bază' : 'Revizia ' + f.label + ' — Operațiuni'], ['Y', 'Revizia Y — Operațiuni suplimentare'], ['S', 'Scadente în afara reviziei (incluse în aceeași lucrare)']];
        groups.forEach(function (g) {
            var items = f.items.filter(function (it) { return it.sectiune === g[0]; });
            if (!items.length) { return; }
            html += '<tr class="rv2-exec-group' + (g[0] === 'Y' ? ' is-y' : '') + '"><td colspan="9">' + esc(g[1]) + ' (' + items.length + ')</td></tr>';
            items.forEach(function (it) {
                var key = it.id ? 'i' + it.id : 'o' + it.operatie_id;
                var recs = recipientsFor(it);
                var locked = !!it.executie_id;
                html += '<tr data-item="' + key + '" class="' + (g[0] === 'Y' ? 'is-y' : (g[0] === 'S' ? 'is-s' : '')) + (it.status === 'completed' ? ' is-done' : (['na', 'deferred', 'blocked'].indexOf(it.status) >= 0 ? ' is-skip' : '')) + '">'
                    + '<td><b>' + esc(it.componenta) + '</b><div class="small text-muted">' + esc(it.operatie) + (it.interval && it.interval !== '—' ? ' · ' + esc(it.interval) : '') + '</div>' + (it.urgenta ? badge(it.urgenta, true) : '') + '</td>'
                    + '<td>' + esc((L.op_types || {})[it.tip_operatie] || it.tip_operatie) + '</td>'
                    + '<td><select class="form-select" data-f="status"' + (locked ? ' disabled' : '') + '>' + Object.keys(L.item || {}).map(function (s) { return '<option value="' + s + '"' + (it.status === s ? ' selected' : '') + '>' + esc(L.item[s]) + '</option>'; }).join('') + '</select></td>'
                    + '<td><input type="text" class="form-control" data-f="motiv" maxlength="255" value="' + esc(it.motiv || '') + '" placeholder="obligatoriu la amânare / n.a."' + (locked ? ' disabled' : '') + '></td>'
                    + '<td class="small">' + (it.cantitate_estimata !== null && it.cantitate_estimata !== undefined ? num(it.cantitate_estimata, 1) + ' ' + esc(it.um || '') : '—') + '</td>'
                    + '<td><input type="text" inputmode="decimal" class="form-control" data-f="cantitate_reala" value="' + esc(it.cantitate_reala || '') + '"' + (locked ? ' disabled' : '') + '></td>'
                    + '<td>' + (recs.length || it.recipient_id ? '<select class="form-select" data-f="recipient_id"' + (locked ? ' disabled' : '') + '><option value="">— fără scădere din stoc —</option>' + recs.map(function (r) {
                        return '<option value="' + r.id + '" data-produs="' + r.produs_id + '"' + (Number(it.recipient_id) === Number(r.id) ? ' selected' : '') + '>' + esc(r.cod + ' · ' + r.produs + (r.vascozitate ? ' ' + r.vascozitate : '') + ' (' + num(r.cantitate_curenta, 1) + ' ' + (r.um || 'L') + ')') + '</option>';
                    }).join('') + '</select>' : '<span class="small text-muted">' + (it.regula && it.regula.sistem || it.sistem ? 'Niciun recipient cu stoc compatibil' : '—') + '</span>') + '</td>'
                    + '<td><input type="text" inputmode="decimal" class="form-control" data-f="cost_material" value="' + esc(it.cost_material || '') + '"' + (locked ? ' disabled' : '') + '></td>'
                    + '<td><input type="text" class="form-control" data-f="observatii" maxlength="500" value="' + esc(it.observatii || '') + '"' + (locked ? ' disabled' : '') + '></td></tr>';
            });
        });
        html += '</tbody></table></div>';
        html += '<div class="row g-2 mt-2"><div class="col-md-8"><label class="form-label" for="rv2EObs">Observații</label><textarea class="form-control" id="rv2EObs" rows="2" maxlength="4000">' + esc(f.observatii || '') + '</textarea></div>'
            + '<div class="col-md-4"><label class="form-label">Documente</label><div id="rv2EDocs">' + ((f.documente || []).map(function (d) { return '<div class="small"><a target="_blank" href="' + esc(url('v2_doc', { id: d.id })) + '"><i class="bi bi-paperclip"></i> ' + esc(d.nume_original) + '</a></div>'; }).join('') || '<div class="small text-muted">Niciun document.</div>')
            + (CAN.documents ? (f.id ? '<input type="file" class="form-control form-control-sm mt-1" id="rv2EFile" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic">' : '<div class="small text-muted mt-1">Documentele se pot atașa după salvarea lucrării.</div>') : '') + '</div></div></div>';
        html += '<div class="small text-muted mt-2">Stocul se scade doar la finalizare, o singură dată (cheie idempotentă pe linie). Completarea și inspecția nu resetează intervalul operațiunii.</div>';
        $('rv2ExecBody').innerHTML = html;
        $('rv2ExecCancel').hidden = isNew || closed;
        $('rv2ExecFinalize').hidden = exec.scheduleOnly || closed;
        $('rv2ExecSave').hidden = exec.scheduleOnly || closed;
        $('rv2ExecSchedule').hidden = closed;
        execSummary();
    }
    function execPayload(status) {
        var f = exec.form;
        var items = [];
        $('rv2ExecBody').querySelectorAll('tr[data-item]').forEach(function (tr) {
            var key = tr.getAttribute('data-item');
            var it = { };
            if (key.charAt(0) === 'i') { it.id = Number(key.slice(1)); } else { it.operatie_id = Number(key.slice(1)); }
            tr.querySelectorAll('[data-f]').forEach(function (el) { it[el.getAttribute('data-f')] = el.value; });
            var rec = tr.querySelector('[data-f="recipient_id"]');
            if (rec && rec.value) { it.produs_id = rec.selectedOptions[0].getAttribute('data-produs'); }
            items.push(it);
        });
        return {
            id: f.id || null, vehicle_id: f.vehicle_id, status: status,
            data_programata: $('rv2EDp').value, data_executie: $('rv2EDe').value, km_executie: $('rv2EKm').value, km_sursa: $('rv2EKs').value,
            ore_executie: $('rv2EOre').value, tehnician: $('rv2ETeh').value, cost_manopera: $('rv2ELab').value, observatii: $('rv2EObs').value, items: items
        };
    }
    function execSummary() {
        var counts = {};
        var mat = 0;
        $('rv2ExecBody').querySelectorAll('tr[data-item]').forEach(function (tr) {
            var s = tr.querySelector('[data-f="status"]').value;
            counts[s] = (counts[s] || 0) + 1;
            var c = parseFloat(String(tr.querySelector('[data-f="cost_material"]').value || '0').replace(',', '.'));
            if (!isNaN(c)) { mat += c; }
            tr.classList.toggle('is-done', s === 'completed');
            tr.classList.toggle('is-skip', ['na', 'deferred', 'blocked'].indexOf(s) >= 0);
        });
        var lab = parseFloat(String(($('rv2ELab') || {}).value || '0').replace(',', '.')) || 0;
        var total = 0;
        Object.keys(counts).forEach(function (k) { total += counts[k]; });
        var open = (counts.pending || 0) + (counts.in_progress || 0);
        $('rv2ExecSum').innerHTML = '<span>Efectuate <b>' + (counts.completed || 0) + '/' + total + '</b></span>'
            + (counts.deferred || counts.blocked ? '<span class="text-warning">Amânate/blocate <b>' + ((counts.deferred || 0) + (counts.blocked || 0)) + '</b> → revizie parțială (referințele reviziei nu se mută)</span>' : '')
            + (open ? '<span class="text-muted">Fără rezultat <b>' + open + '</b></span>' : '')
            + '<span>Materiale <b>' + lei(mat) + '</b></span><span>Total <b>' + lei(mat + lab) + '</b></span>';
    }
    function saveExecution(status) {
        var payload = execPayload(status);
        if (status === 'programata' && !payload.data_programata) { toast('Alege data programată.', 'err'); return Promise.reject(); }
        return post('v2_workorder_save', payload).then(function (j) {
            exec.form.id = j.id;
            return j.id;
        });
    }
    function finalizeExecution() {
        var payload = execPayload(null);
        var deferred = payload.items.filter(function (i) { return i.status === 'deferred' || i.status === 'blocked'; }).length;
        var msg = deferred ? 'Revizia va fi înregistrată ca PARȚIALĂ (' + deferred + ' operațiuni amânate / blocate): referințele reviziei nu se mută. Continui?'
            : 'Finalizezi Revizia ' + exec.form.label + '? Operațiunile efectuate își resetează intervalele, stocul se scade, iar următoarele ținte X / Y se recalculează din km. Acțiunea nu se poate anula din pagină.';
        if (!window.confirm(msg)) { return; }
        var btn = $('rv2ExecFinalize');
        btn.disabled = true;
        var chain = exec.form.id ? Promise.resolve(exec.form.id) : post('v2_workorder_save', Object.assign({}, payload, { status: 'in_desfasurare' })).then(function (j) { exec.form.id = j.id; return j.id; });
        chain.then(function (id) {
            payload.id = id;
            return post('v2_workorder_finalize', payload);
        }).then(function (j) {
            toast(j.status === 'finalizata' ? 'Revizia a fost finalizată — țintele X / Y s-au recalculat.' : 'Revizie parțială înregistrată (referințele reviziei nu se mută).', 'ok');
            modal('rv2ExecModal').hide();
            reload();
        }).catch(function (e) {
            if (e) { toast(e.message, 'err'); }
            if (exec.form.id) {
                // Lucrarea a fost creata: formularul arata de acum liniile cu id.
                get('v2_workorder', { vehicle_id: exec.form.vehicle_id, id: exec.form.id }).then(function (j) { exec.form = j; renderExecution(); });
            }
        }).then(function () { btn.disabled = false; });
    }

    // ------------------------------------------------------------------ operatiune independenta
    var opForm = {};
    function openOperation(vehicleId, operationId, type) {
        var v = vehicleById(vehicleId);
        if (!v || !v.plan) { toast('Vehiculul nu are plan de mentenanță.', 'err'); return; }
        opForm = { vehicle: v, key: uid() };
        var ops = v.ops || [];
        var op = ops.filter(function (o) { return o.id === Number(operationId); })[0] || ops[0];
        $('rv2OpTitle').textContent = 'Înregistrează operațiune · ' + v.nr;
        var html = '<div class="row g-2">'
            + '<div class="col-md-6"><label class="form-label" for="rv2OOp">Operațiune</label><select class="form-select" id="rv2OOp">' + ops.map(function (o) { return '<option value="' + o.id + '"' + (op && o.id === op.id ? ' selected' : '') + '>' + esc(o.op) + '</option>'; }).join('') + '</select></div>'
            + '<div class="col-md-3"><label class="form-label" for="rv2OType">Tip execuție</label><select class="form-select" id="rv2OType">' + Object.keys(L.op_types || {}).map(function (k) { return '<option value="' + k + '">' + esc(L.op_types[k]) + '</option>'; }).join('') + '</select></div>'
            + '<div class="col-md-3"><label class="form-label" for="rv2ODate">Data</label><input type="datetime-local" class="form-control" id="rv2ODate" value="' + localInput(new Date()) + '" max="' + localInput(new Date()) + '"></div>'
            + '<div class="col-md-3"><label class="form-label" for="rv2OKm">Km</label><input type="text" inputmode="decimal" class="form-control" id="rv2OKm" value="' + (v.km && v.km.value ? Math.round(v.km.value) : '') + '"></div>'
            + '<div class="col-md-2"><label class="form-label" for="rv2OKs">Sursă km</label><select class="form-select" id="rv2OKs">' + ['can', 'gps', 'contor', 'manual'].map(function (s) { return '<option value="' + s + '"' + ((v.km && v.km.source) === s ? ' selected' : '') + '>' + s.toUpperCase() + '</option>'; }).join('') + '</select></div>'
            + '<div class="col-md-2"><label class="form-label" for="rv2OOre">Ore motor</label><input type="text" inputmode="decimal" class="form-control" id="rv2OOre" placeholder="opțional"></div>'
            + '<div class="col-md-2"><label class="form-label" for="rv2OQty">Cantitate</label><input type="text" inputmode="decimal" class="form-control" id="rv2OQty"></div>'
            + '<div class="col-md-1"><label class="form-label" for="rv2OUm">UM</label><input type="text" class="form-control" id="rv2OUm" maxlength="10" value="L"></div>'
            + '<div class="col-md-2"><label class="form-label" for="rv2OCost">Cost (lei)</label><input type="text" inputmode="decimal" class="form-control" id="rv2OCost"></div>'
            + '<div class="col-md-6"><label class="form-label" for="rv2ORec">Din stoc (recipient)</label><select class="form-select" id="rv2ORec"><option value="">— fără scădere din stoc —</option></select></div>'
            + '<div class="col-md-6"><label class="form-label" for="rv2OTeh">Tehnician</label><input type="text" class="form-control" id="rv2OTeh" maxlength="120"></div>'
            + '<div class="col-12"><label class="form-label" for="rv2OObs">Observații</label><textarea class="form-control" id="rv2OObs" rows="2" maxlength="500"></textarea></div>'
            + '<div class="col-12"><div class="rv2-note is-info" id="rv2OHint"></div></div></div>';
        $('rv2OpBody').innerHTML = html;
        var sync = function () {
            var o = ops.filter(function (x) { return x.id === Number($('rv2OOp').value); })[0];
            var t = $('rv2OType').value;
            $('rv2OHint').innerHTML = '<i class="bi bi-info-circle"></i><span>' + (t === 'completare' || t === 'inspectie'
                ? 'O ' + esc((L.op_types || {})[t].toLowerCase()) + ' NU resetează intervalul operațiunii' + (t === 'completare' ? ' (contează doar la rata de completare L / 1000 km).' : '.')
                : 'Execuția resetează referința operațiunii (data, km, ore). Dacă vehiculul are o lucrare deschisă cu aceeași operațiune, linia ei se marchează efectuată cu această execuție — fără dublare.') + '</span>';
            var rec = $('rv2ORec');
            var types = o && o.sistem ? (FLUID_TYPES[o.sistem] || []) : [];
            var det = state.details[v.id];
            rec.disabled = !types.length;
            rec.innerHTML = '<option value="">— fără scădere din stoc —</option>';
            if (types.length) {
                var load = det && det._stoc ? Promise.resolve(det._stoc) : get('v2_workorder', { vehicle_id: v.id }).then(function (j) { if (det) { det._stoc = j.stoc; } return j.stoc; }).catch(function () { return { recipiente: [] }; });
                load.then(function (stoc) {
                    rec.innerHTML = '<option value="">— fără scădere din stoc —</option>' + (stoc.recipiente || []).filter(function (r) { return types.indexOf(r.tip_fluid) >= 0; }).map(function (r) {
                        return '<option value="' + r.id + '" data-produs="' + r.produs_id + '">' + esc(r.cod + ' · ' + r.produs + (r.vascozitate ? ' ' + r.vascozitate : '') + ' (' + num(r.cantitate_curenta, 1) + ' ' + (r.um || 'L') + ')') + '</option>';
                    }).join('');
                });
            }
        };
        $('rv2OOp').addEventListener('change', function () {
            var o = ops.filter(function (x) { return x.id === Number($('rv2OOp').value); })[0];
            if (o) { $('rv2OType').value = o.tip; }
            sync();
        });
        $('rv2OType').addEventListener('change', sync);
        $('rv2OType').value = type || (op ? op.tip : 'inlocuire');
        sync();
        modal('rv2OpModal').show();
    }
    function saveOperation() {
        var rec = $('rv2ORec');
        var payload = {
            vehicle_id: opForm.vehicle.id, operatie_id: $('rv2OOp').value, tip_executie: $('rv2OType').value, data_executie: $('rv2ODate').value,
            km: $('rv2OKm').value, km_sursa: $('rv2OKs').value, ore: $('rv2OOre').value, cantitate: $('rv2OQty').value, um: $('rv2OUm').value,
            cost: $('rv2OCost').value, recipient_id: rec.value, produs_id: rec.value ? rec.selectedOptions[0].getAttribute('data-produs') : '',
            tehnician: $('rv2OTeh').value, observatii: $('rv2OObs').value, idempotency_key: opForm.key
        };
        var btn = $('rv2OpSave');
        btn.disabled = true;
        post('v2_operation_record', payload).then(function () {
            toast('Operațiunea a fost înregistrată.', 'ok');
            modal('rv2OpModal').hide();
            reload();
        }).catch(function (e) { toast(e.message, 'err'); }).then(function () { btn.disabled = false; });
    }

    // ------------------------------------------------------------------ detaliu componenta
    function openComponent(vehicleId, operationId) {
        var v = vehicleById(vehicleId);
        var op = (v.ops || []).filter(function (o) { return o.id === Number(operationId); })[0];
        if (!op) { return; }
        $('rv2CompTitle').innerHTML = '<i class="bi ' + (CAT_ICON[op.cat] || 'bi-tools') + ' me-2"></i>' + esc(op.comp) + ' · ' + esc(v.nr);
        var body = $('rv2CompBody');
        var draw = function (det) {
            var execs = det ? (det.executii || []).filter(function (e) { return Number(e.operatie_id) === op.id; }) : null;
            var base = op.baseline || {};
            var cost = 0;
            (execs || []).forEach(function (e) { if (!e.anulat_la) { cost += Number(e.cost || 0); } });
            var rate = det && det.rate_completare ? det.rate_completare[op.id] : null;
            var fluid = det ? (det.fluide || []).filter(function (s) { return s.sistem === op.sistem; })[0] : null;
            var html = '<div class="d-flex flex-wrap gap-2 mb-3">'
                + (CAN.edit ? '<button type="button" class="btn btn-sm btn-primary" data-act="record-op" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-check2-square"></i> Înregistrează operațiune</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-primary" data-act="schedule" data-v="' + v.id + '"><i class="bi bi-calendar-plus"></i> ' + (v.workflow ? 'Replanifică' : 'Programează') + '</button>'
                    + '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="note-op" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-chat-left-text"></i> Adaugă observație</button>' : '')
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="history-op" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-clock-history"></i> Istoric</button>'
                + (CAN.configure ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="edit-rule" data-v="' + v.id + '" data-o="' + op.id + '"><i class="bi bi-sliders"></i> Editează regula</button>' : '') + '</div>';
            html += '<dl class="rv2-kv mb-3">'
                + '<dt>Componentă</dt><dd>' + esc(op.comp) + (op.de_verificat ? ' <span class="rv2-pill is-warn">denumire de verificat</span>' : '') + '</dd>'
                + '<dt>Operațiune</dt><dd>' + esc(op.op) + ' · ' + esc((L.op_types || {})[op.tip] || op.tip) + '</dd>'
                + '<dt>Vehicul</dt><dd>' + esc(v.nr + ' · ' + v.marca + ' ' + v.model) + '</dd>'
                + '<dt>Plan</dt><dd>' + esc(v.plan ? v.plan.nume : '—') + ' · secțiunea ' + (op.sect === 'Y' ? 'Revizia Y (suplimentar)' : 'Revizia X') + (op.exceptie ? ' · <span class="rv2-pill is-warn">excepție pe vehicul</span>' : '') + '</dd>'
                + '<dt>Interval</dt><dd>' + esc(op.interval) + (op.mode === 'revizie' ? ' <span class="small text-muted">(intervalul reviziei, de la ultima execuție a operațiunii)</span>' : '') + '</dd>'
                + '<dt>Factori de uzură</dt><dd>' + esc(op.factori || '—') + '</dd>'
                + '<dt>Ultima execuție (referință)</dt><dd>' + (base.data ? date(base.data, true) + (base.km !== null && base.km !== undefined ? ' · ' + num(base.km) + ' km' : '') + ' <span class="small text-muted">(' + (base.sursa === 'executie' ? 'execuție înregistrată' : 'referință la preluare') + ')</span>' : 'Nicio referință — criteriile nu se pot măsura.') + '</dd>'
                + '<dt>Odometru curent</dt><dd>' + (v.km && v.km.value ? num(v.km.value) + ' km · ' + esc(String(v.km.source).toUpperCase()) + (v.km.at ? ' · ' + date(v.km.at, true) : '') : '—') + '</dd>'
                + '<dt>Ore motor / ralanti (SAS)</dt><dd>' + (v.ore && v.ore.total !== null ? num(v.ore.total) + ' h / ' + num(v.ore.idle) + ' h' + (v.ore.since ? ' <span class="small text-muted">din ' + date(v.ore.since) + '</span>' : '') : 'nemăsurat') + '</dd>'
                + '</dl>';
            html += '<h6 class="fw-bold">Criterii de mentenanță</h6>';
            html += (op.criteria || []).length ? '<table class="rv2-hist-table mb-3"><thead><tr><th>Criteriu</th><th>Interval</th><th>Consumat</th><th>Rămas</th><th>%</th><th>Stare</th></tr></thead><tbody>' + op.criteria.map(function (c) {
                return '<tr><td>' + ({ km: 'Km', ore: 'Ore motor', calendar: 'Calendar' }[c.tip]) + '</td><td>' + num(c.interval) + ' ' + (c.tip === 'calendar' ? 'luni' : esc(c.unit)) + '</td><td>' + (c.measured ? num(c.used) + ' ' + (c.tip === 'calendar' ? 'zile' : esc(c.unit)) : 'nemăsurat') + '</td><td>'
                    + (c.measured ? num(c.remaining) + ' ' + (c.tip === 'calendar' ? 'zile' : esc(c.unit)) + (c.due_date ? ' (' + date(c.due_date) + ')' : '') : '—') + '</td><td>' + (c.pct !== null ? num(c.pct, 1) + '%' : '—') + '</td><td>' + badge(c.status, true) + '</td></tr>';
            }).join('') + '</tbody></table>' : '<div class="small text-muted mb-3">' + (op.mode === 'la_y' ? 'Se efectuează la Revizia Y (fără interval propriu și fără țintă km Y).' : 'Niciun criteriu configurat.') + '</div>';
            (op.notes || []).forEach(function (n) { html += '<div class="rv2-note is-info"><i class="bi bi-info-circle"></i><span>' + esc(n) + '</span></div>'; });
            if (op.sistem) {
                html += '<h6 class="fw-bold mt-3">Consumabile</h6><dl class="rv2-kv mb-3"><dt>Cantitate planificată</dt><dd>' + (op.cantitate !== null ? num(op.cantitate, 1) + ' ' + esc(op.um || 'L') : 'neconfigurată în plan') + '</dd>'
                    + '<dt>Sistem vehicul</dt><dd>' + (fluid ? esc(fluid.denumire) + (fluid.capacitate_nominala ? ' · capacitate ' + num(fluid.capacitate_nominala, 1) + ' ' + esc(fluid.um) : '') + (fluid.produs ? ' · ' + esc(fluid.produs) + (fluid.vascozitate ? ' ' + esc(fluid.vascozitate) : '') + (fluid.specificatie ? ' · ' + esc(fluid.specificatie) : '') : '') : (det ? 'Sistemul nu e configurat în Consumabile' : '…')) + '</dd>'
                    + '<dt>Rata de completare</dt><dd>' + (rate ? num(rate.cantitate, 1) + ' L de la ultimul schimb' + (rate.per_1000km !== null ? ' · ' + num(rate.per_1000km, 2) + ' L / 1000 km' : '') + (rate.per_100h !== null ? ' · ' + num(rate.per_100h, 2) + ' L / 100 h' : '') : 'Nicio completare înregistrată') + '</dd></dl>'
                    + '<div class="small text-muted mb-3">Compatibilitatea produsului se verifică după sistemul vehiculului și omologări, nu doar după vâscozitate. <a href="' + esc(D.urls.consumabile) + '">Consumabile</a></div>';
            }
            html += '<h6 class="fw-bold mt-3">Intervenții anterioare <span class="small text-muted fw-normal">' + (execs ? execs.length : '') + (execs && cost ? ' · cost total ' + lei(cost) : '') + '</span></h6>';
            if (!execs) { html += '<div class="rv2-skel"></div><div class="rv2-skel"></div>'; }
            else if (!execs.length) { html += '<div class="small text-muted">Nicio execuție înregistrată.</div>'; }
            else {
                html += '<table class="rv2-hist-table"><thead><tr><th>Data</th><th>Tip</th><th>Km</th><th>Cantitate</th><th>Cost</th><th>Tehnician</th><th>Origine</th></tr></thead><tbody>' + execs.map(function (e) {
                    return '<tr' + (e.anulat_la ? ' class="text-decoration-line-through text-muted" title="Anulată: ' + esc(e.anulat_motiv) + '"' : '') + '><td>' + date(e.data_executie, true) + '</td><td>' + esc((L.op_types || {})[e.tip_executie] || e.tip_executie) + (Number(e.reseteaza) ? '' : ' <span class="rv2-pill is-gray">fără reset</span>') + '</td><td>' + num(e.km) + '</td><td>' + (e.cantitate ? num(e.cantitate, 1) + ' ' + esc(e.um || '') : '—') + '</td><td>' + lei(e.cost) + '</td><td>' + esc(e.tehnician || '—') + '</td><td>'
                        + (e.lucrare_id ? 'Revizia ' + esc(e.lucrare_tip) + ' #' + e.lucrare_id : 'Independentă') + (CAN.configure && !e.lucrare_id && !e.anulat_la ? ' <button type="button" class="btn btn-link btn-sm p-0 text-danger" data-act="cancel-exec" data-e="' + e.id + '">anulează</button>' : '') + '</td></tr>';
                }).join('') + '</tbody></table>';
            }
            body.innerHTML = html;
        };
        draw(state.details[v.id] || null);
        drawer('rv2CompDrawer').show();
        if (!state.details[v.id]) {
            get('v2_vehicle', { id: v.id }).then(function (j) { state.details[v.id] = j; draw(j); }).catch(function (e) { toast(e.message, 'err'); });
        }
    }

    // ------------------------------------------------------------------ istoric
    var hist = { filters: {} };
    function openHistory(filters) {
        hist.filters = Object.assign({}, filters || {});
        var body = $('rv2HistBody');
        var opsAll = {};
        W.vehicles.forEach(function (v) { (v.ops || []).forEach(function (o) { opsAll[o.id] = o.op; }); });
        body.innerHTML = '<div class="row g-2 mb-2">'
            + '<div class="col-md-4"><select class="form-select form-select-sm" data-h="vehicle_id"><option value="">Toate vehiculele</option>' + W.vehicles.map(function (v) { return '<option value="' + v.id + '">' + esc(v.nr) + '</option>'; }).join('') + '</select></div>'
            + '<div class="col-md-4"><select class="form-select form-select-sm" data-h="operatie_id"><option value="">Toate operațiunile</option>' + Object.keys(opsAll).map(function (k) { return '<option value="' + k + '">' + esc(opsAll[k]) + '</option>'; }).join('') + '</select></div>'
            + '<div class="col-md-2"><select class="form-select form-select-sm" data-h="tip"><option value="">X și Y</option><option value="X">Revizia X</option><option value="Y">Revizia Y</option></select></div>'
            + '<div class="col-md-2"><select class="form-select form-select-sm" data-h="status"><option value="">Toate</option><option value="finalizata">Finalizate</option><option value="partiala">Parțiale</option><option value="anulata">Anulate</option></select></div>'
            + '<div class="col-md-3"><input type="date" class="form-control form-control-sm" data-h="de_la" aria-label="De la"></div>'
            + '<div class="col-md-3"><input type="date" class="form-control form-control-sm" data-h="pana_la" aria-label="Până la"></div>'
            + '<div class="col-md-3"><input type="text" class="form-control form-control-sm" data-h="tehnician" placeholder="Tehnician"></div>'
            + '<div class="col-md-3 d-flex gap-1">' + (CAN.export ? '<a class="btn btn-sm btn-outline-success flex-grow-1" id="rv2HExport" href="#"><i class="bi bi-file-earmark-excel"></i> Excel</a>' : '')
            + '<a class="btn btn-sm btn-outline-secondary" href="' + esc(D.urls.legacy.istoric) + '" title="Istoricul km SAS (ulei / plăcuțe / anvelope) cu documente și rapoarte"><i class="bi bi-folder2-open"></i></a></div></div>'
            + '<div id="rv2HList"><div class="rv2-skel"></div><div class="rv2-skel"></div></div>';
        body.querySelectorAll('[data-h]').forEach(function (el) {
            var k = el.getAttribute('data-h');
            if (hist.filters[k]) { el.value = hist.filters[k]; }
            el.addEventListener('change', function () { hist.filters[k] = el.value; loadHistory(); });
        });
        drawer('rv2HistDrawer').show();
        loadHistory();
    }
    function loadHistory() {
        var exp = $('rv2HExport');
        if (exp) { exp.href = url('v2_history_export', hist.filters); }
        $('rv2HList').innerHTML = '<div class="rv2-skel"></div><div class="rv2-skel"></div>';
        get('v2_history', hist.filters).then(function (j) {
            var rows = j.rows || [];
            $('rv2HList').innerHTML = rows.length ? '<table class="rv2-hist-table"><thead><tr><th>Data</th><th>Nr. auto</th><th>Revizie / operațiune</th><th>Stare</th><th>Km</th><th>Tehnician</th><th class="text-end">Cost</th></tr></thead><tbody>' + rows.map(function (r) {
                var what = r.kind === 'lucrare' ? '<span class="rv2-pill' + (r.tip === 'Y' ? ' is-y' : '') + '">' + esc(r.label) + '</span> ' + r.efectuate + '/' + r.total_operatii + ' op.' + (Number(r.documente) ? ' <i class="bi bi-paperclip"></i>' : '')
                    : esc(r.label) + ': ' + esc(r.operatie) + (r.cantitate ? ' · ' + num(r.cantitate, 1) + ' ' + esc(r.um || '') : '');
                var st = { finalizata: 'in_termen', partiala: 'se_apropie', anulata: 'neconfigurat' }[r.status];
                return '<tr' + (r.kind === 'lucrare' ? ' class="is-click" data-hist="' + r.id + '"' : '') + '><td>' + date(r.data_executie, true) + '</td><td><b>' + esc(r.nr) + '</b></td><td>' + what + '</td><td><span class="rv2-status is-soft rv2-status-' + st + '">' + esc((L.work || {})[r.status] || r.status) + '</span></td><td>' + num(r.km_executie) + '</td><td>' + esc(r.tehnician || '—') + '</td><td class="text-end">' + lei(r.cost_total) + '</td></tr>';
            }).join('') + '</tbody></table>' : '<div class="rv2-empty"><i class="bi bi-inbox"></i>Nicio înregistrare pentru filtrele alese.</div>';
        }).catch(function (e) { $('rv2HList').innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }
    function openHistoryDetail(id) {
        $('rv2HList').innerHTML = '<div class="rv2-skel"></div>';
        get('v2_history_detail', { id: id }).then(function (o) {
            var snap = o.snapshot || {};
            var html = '<button type="button" class="btn btn-sm btn-link px-0 mb-2" data-hist-back><i class="bi bi-arrow-left"></i> Înapoi la listă</button>'
                + '<h6 class="fw-bold">Revizia ' + esc(o.label) + ' · ' + esc(o.vehicul ? o.vehicul.nr_inmatriculare : '') + ' · ' + esc((L.work || {})[o.status] || o.status) + '</h6>'
                + '<dl class="rv2-kv mb-3"><dt>Data execuției</dt><dd>' + date(o.data_executie, true) + '</dd><dt>Km / ore</dt><dd>' + num(o.km_executie) + ' km (' + esc(String(o.km_sursa || '').toUpperCase()) + ')' + (o.ore_executie ? ' · ' + num(o.ore_executie, 1) + ' h' : '') + '</dd>'
                + '<dt>Secvență</dt><dd>' + (o.km_tinta ? 'țintă ' + num(o.km_tinta) + ' km · ' : '') + (Number(o.completa) ? 'revizie completă (a mutat referințele)' : 'nu a mutat referințele') + '</dd>'
                + '<dt>Plan (instantaneu)</dt><dd>' + esc(snap.plan ? snap.plan.nume : '—') + (snap.sabloane ? ' · ' + snap.sabloane.map(function (s) { return esc(s.nume) + ' v' + s.versiune; }).join(', ') : '') + '</dd>'
                + '<dt>Tehnician</dt><dd>' + esc(o.tehnician || '—') + '</dd><dt>Costuri</dt><dd>manoperă ' + lei(o.cost_manopera) + ' · materiale ' + lei(o.cost_materiale) + ' · total <b>' + lei(o.cost_total) + '</b></dd>'
                + (o.observatii ? '<dt>Observații</dt><dd>' + esc(o.observatii) + '</dd>' : '') + (o.anulat_motiv ? '<dt>Motiv anulare</dt><dd>' + esc(o.anulat_motiv) + '</dd>' : '') + '</dl>'
                + '<table class="rv2-hist-table"><thead><tr><th>Secț.</th><th>Operațiune</th><th>Rezultat</th><th>Cantitate</th><th>Produs</th><th>Cost</th><th>Motiv / obs.</th></tr></thead><tbody>' + o.items.map(function (it) {
                    return '<tr><td>' + esc(it.sectiune) + '</td><td>' + esc(it.componenta) + '<div class="small text-muted">' + esc(it.operatie) + ' · ' + esc(it.interval || '') + '</div></td><td>' + esc((L.item || {})[it.status] || it.status) + '</td><td>' + (it.cantitate_reala ? num(it.cantitate_reala, 1) + ' ' + esc(it.um || '') : '—') + '</td><td>' + esc(it.produs ? it.produs.denumire + (it.produs.vascozitate ? ' ' + it.produs.vascozitate : '') : '—') + '</td><td>' + lei(it.cost_material) + '</td><td class="small">' + esc([it.motiv, it.observatii].filter(Boolean).join(' · ')) + '</td></tr>';
                }).join('') + '</tbody></table>'
                + ((o.documente || []).length ? '<h6 class="fw-bold mt-3">Documente</h6>' + o.documente.map(function (d) { return '<div><a target="_blank" href="' + esc(url('v2_doc', { id: d.id })) + '"><i class="bi bi-paperclip"></i> ' + esc(d.nume_original) + '</a></div>'; }).join('') : '')
                + '<div class="small text-muted mt-3">Lista de operațiuni este instantaneul de la momentul lucrării; modificările ulterioare ale șabloanelor nu o schimbă.</div>';
            $('rv2HList').innerHTML = html;
        }).catch(function (e) { toast(e.message, 'err'); loadHistory(); });
    }

    // ------------------------------------------------------------------ simulare
    function openExplain(vehicleId) {
        if (vehicleId) { $('rv2SimVeh').value = vehicleId; }
        $('rv2SimOut').innerHTML = '';
        modal('rv2SimModal').show();
        runExplain();
    }
    function runExplain() {
        var id = $('rv2SimVeh').value;
        if (!id) { return; }
        $('rv2SimOut').innerHTML = '<div class="rv2-skel"></div><div class="rv2-skel"></div><div class="rv2-skel"></div>';
        get('v2_explain', { id: id }).then(function (j) {
            var v = j.vehicle;
            $('rv2SimOut').innerHTML = '<div class="d-flex align-items-center gap-2 mb-2"><b>' + esc(v.nr) + '</b>' + badge(v.status) + (v.next ? '<span class="rv2-pill' + (v.next.type === 'Y' ? ' is-y' : '') + '">Revizia ' + esc(v.next.label) + '</span>' : '') + '</div>'
                + '<ol class="rv2-steps-list">' + j.steps.map(function (s) { return '<li><b>' + esc(s.titlu) + '</b>' + esc(s.text) + '</li>'; }).join('') + '</ol>';
        }).catch(function (e) { $('rv2SimOut').innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }

    // ------------------------------------------------------------------ configurare
    var cfg = { data: null, sec: 'planuri', edit: null };
    function openConfig(sec, opts) {
        cfg.sec = sec || 'planuri';
        cfg.focus = opts || null;
        modal('rv2CfgModal').show();
        if (cfg.data) { renderConfig(); }
        $('rv2CfgBody').innerHTML = cfg.data ? $('rv2CfgBody').innerHTML : '<div class="rv2-skel"></div><div class="rv2-skel"></div>';
        get('v2_config').then(function (j) { cfg.data = j; renderConfig(); }).catch(function (e) { $('rv2CfgBody').innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
    }
    function cfgSave(entity, data) {
        return post('v2_config_save', { entity: entity, data: data }).then(function (j) {
            cfg.data = j.config;
            toast('Configurarea a fost salvată.', 'ok');
            reload();
            return j;
        }).catch(function (e) { toast(e.message, 'err'); throw e; });
    }
    function opName(id) { var o = (cfg.data.operatii || []).filter(function (x) { return Number(x.id) === Number(id); })[0]; return o ? o.operatie : '#' + id; }
    function tplById(id) { return (cfg.data.sabloane || []).filter(function (t) { return Number(t.id) === Number(id); })[0] || null; }
    function opt(value, label, sel) { return '<option value="' + esc(value) + '"' + (String(sel) === String(value) ? ' selected' : '') + '>' + esc(label) + '</option>'; }
    function renderConfig() {
        $('rv2CfgNav').querySelectorAll('[data-sec]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-sec') === cfg.sec); });
        var fn = { planuri: cfgPlans, operatii: cfgOperations, sabloane: cfgTemplates, vehicule: cfgVehicles, setari: cfgSettings, import: cfgImport }[cfg.sec];
        $('rv2CfgBody').innerHTML = fn();
        bindConfig();
    }
    function ro() { return CAN.configure ? '' : ' disabled'; }
    function cfgPlans() {
        var d = cfg.data;
        if (cfg.edit && cfg.edit.type === 'plan') { return cfgPlanForm(cfg.edit.id); }
        var counts = {};
        d.vehicule.forEach(function (v) { if (v.plan_id) { counts[v.plan_id] = (counts[v.plan_id] || 0) + 1; } });
        return '<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold m-0">Planuri de mentenanță</h6>' + (CAN.configure ? '<button type="button" class="btn btn-sm btn-primary" data-cfg-new="plan"><i class="bi bi-plus-lg"></i> Plan nou</button>' : '') + '</div>'
            + '<p class="rv2-cfg-help">Planul se aplică automat celui mai specific grup (tip → marcă → model) sau explicit pe vehicul. Revizia X urmează intervalul ei km; Revizia Y devine scadentă la pragul / intervalul ei km, oricâte X au fost — numărul de X dintre două Y rezultă din km. Nicio valoare nu este presetată: completează-le din documentația producătorului sau regulile firmei.</p>'
            + (d.planuri.length ? '<div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th>Plan</th><th>Se aplică</th><th>Interval X</th><th>Revizia Y (km)</th><th>Toleranță / după Y</th><th>Praguri</th><th>Vehicule</th><th>Activ</th><th></th></tr></thead><tbody>' + d.planuri.map(function (p) {
                var iv = [p.interval_km ? num(p.interval_km) + ' km' : '', p.interval_ore ? num(p.interval_ore) + ' h' : '', p.interval_luni ? p.interval_luni + ' luni' : ''].filter(Boolean).join(p.logica === 'and' ? ' și ' : ' / ') || '<span class="text-warning">neconfigurat</span>';
                return '<tr><td><b>' + esc(p.nume) + '</b></td><td>' + esc([d.familii[p.tip_vehicul] || 'orice tip', p.marca || 'orice marcă', p.model || 'orice model'].join(' · ')) + '</td><td>' + iv + '</td><td>' + (p.sablon_y_id ? [p.y_prag_km ? 'prima la ' + num(p.y_prag_km) : '', p.y_interval_km ? 'la fiecare ' + num(p.y_interval_km) : ''].filter(Boolean).join(', ') || '<span class="text-warning">neconfigurată</span>' : 'fără Y') + '</td><td class="small">' + (p.y_toleranta_km ? num(p.y_toleranta_km) + ' km' : '10% din X') + ' · ' + (p.politica_x === 'grila' ? 'grilă' : 'reset') + '</td><td class="small">' + [p.prag_km ? num(p.prag_km) + ' km' : '', p.prag_ore ? p.prag_ore + ' h' : '', p.prag_zile ? p.prag_zile + ' zile' : ''].filter(Boolean).join(' · ') + '</td><td>' + (counts[p.id] || 0) + '</td><td>' + (Number(p.activ) ? 'da' : 'nu') + '</td><td><button type="button" class="btn btn-sm btn-outline-primary" data-cfg-edit="plan" data-id="' + p.id + '">' + (CAN.configure ? 'Editează' : 'Vezi') + '</button></td></tr>';
            }).join('') + '</tbody></table></div>' : '<div class="rv2-note is-info"><i class="bi bi-info-circle"></i><span>Nu există planuri. Toate vehiculele apar „Neconfigurat” până la crearea primului plan.</span></div>');
    }
    function cfgPlanForm(id) {
        var d = cfg.data;
        var p = id ? d.planuri.filter(function (x) { return Number(x.id) === Number(id); })[0] : (cfg.edit && cfg.edit.tmp) || { nume: '', tip_vehicul: '', marca: '', model: '', sablon_x_id: (d.sabloane.filter(function (t) { return t.tip === 'X'; })[0] || {}).id, sablon_y_id: (d.sabloane.filter(function (t) { return t.tip === 'Y'; })[0] || {}).id, logica: 'or', politica_x: 'reset', sursa_km: 'auto', activ: 1, reguli: [] };
        var rules = {};
        (p.reguli || []).forEach(function (r) { rules[r.operatie_id] = r; });
        var x = tplById(p.sablon_x_id);
        var y = tplById(p.sablon_y_id);
        var xOps = x ? x.operatii : [];
        var yOps = y ? y.operatii.filter(function (o) { return xOps.indexOf(o) < 0; }) : [];
        var marci = Object.keys(d.marci || {});
        var models = p.marca && d.marci[p.marca] ? d.marci[p.marca] : [];
        var html = '<button type="button" class="btn btn-sm btn-link px-0" data-cfg-back><i class="bi bi-arrow-left"></i> Planuri</button><h6 class="fw-bold">' + (id ? 'Plan: ' + esc(p.nume) : 'Plan nou') + '</h6><div class="row g-2 mb-3" id="rv2PlanForm">'
            + '<div class="col-md-4"><label class="form-label">Nume</label><input class="form-control form-control-sm" data-p="nume" value="' + esc(p.nume) + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Tip vehicul</label><select class="form-select form-select-sm" data-p="tip_vehicul"' + ro() + '>' + opt('', 'Oricare', p.tip_vehicul || '') + Object.keys(d.familii).map(function (k) { return opt(k, d.familii[k], p.tip_vehicul); }).join('') + '</select></div>'
            + '<div class="col-md-3"><label class="form-label">Marcă</label><input class="form-control form-control-sm" list="rv2Makes" data-p="marca" value="' + esc(p.marca || '') + '" placeholder="oricare"' + ro() + '><datalist id="rv2Makes">' + marci.map(function (m) { return '<option value="' + esc(m) + '">'; }).join('') + '</datalist></div>'
            + '<div class="col-md-3"><label class="form-label">Model</label><input class="form-control form-control-sm" list="rv2Models" data-p="model" value="' + esc(p.model || '') + '" placeholder="oricare"' + ro() + '><datalist id="rv2Models">' + models.map(function (m) { return '<option value="' + esc(m) + '">'; }).join('') + '</datalist></div>'
            + '<div class="col-md-3"><label class="form-label">Șablon Revizia X</label><select class="form-select form-select-sm" data-p="sablon_x_id"' + ro() + '>' + d.sabloane.filter(function (t) { return t.tip === 'X'; }).map(function (t) { return opt(t.id, t.nume + ' (v' + t.versiune + ')', p.sablon_x_id); }).join('') + '</select></div>'
            + '<div class="col-md-3"><label class="form-label">Șablon Revizia Y</label><select class="form-select form-select-sm" data-p="sablon_y_id"' + ro() + '>' + opt('', 'Fără Y', p.sablon_y_id || '') + d.sabloane.filter(function (t) { return t.tip === 'Y'; }).map(function (t) { return opt(t.id, t.nume + ' (v' + t.versiune + ')', p.sablon_y_id); }).join('') + '</select></div>'
            + '<div class="col-md-2"><label class="form-label">Sursă km</label><select class="form-select form-select-sm" data-p="sursa_km"' + ro() + '>' + opt('auto', 'CAN → GPS (auto)', p.sursa_km) + opt('can', 'Doar CAN', p.sursa_km) + opt('gps', 'Doar GPS', p.sursa_km) + '</select></div>'
            + '<div class="col-md-2"><label class="form-label">Activ</label><select class="form-select form-select-sm" data-p="activ"' + ro() + '>' + opt(1, 'Da', Number(p.activ)) + opt(0, 'Nu', Number(p.activ)) + '</select></div>'
            + '<div class="col-12"><div class="fw-semibold small mt-2">Revizia X — interval (de la ultima revizie finalizată), prima limită atinsă</div></div>'
            + '<div class="col-md-2"><label class="form-label">Km</label><input class="form-control form-control-sm" data-p="interval_km" value="' + esc(p.interval_km || '') + '" placeholder="—"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Ore motor</label><input class="form-control form-control-sm" data-p="interval_ore" value="' + esc(p.interval_ore || '') + '" placeholder="—"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Luni</label><input class="form-control form-control-sm" data-p="interval_luni" value="' + esc(p.interval_luni || '') + '" placeholder="—"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Logică</label><select class="form-select form-select-sm" data-p="logica"' + ro() + '>' + opt('or', 'SAU (prima limită)', p.logica) + opt('and', 'ȘI (toate)', p.logica) + '</select></div>'
            + '<div class="col-12"><div class="fw-semibold small mt-2">Revizia Y — pe km (numărul de X dintre două Y rezultă din km)</div></div>'
            + '<div class="col-md-2"><label class="form-label">Prima Y la (km odometru)</label><input class="form-control form-control-sm" data-p="y_prag_km" value="' + esc(p.y_prag_km || '') + '" placeholder="opțional"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Y recurent la fiecare (km)</label><input class="form-control form-control-sm" data-p="y_interval_km" value="' + esc(p.y_interval_km || '') + '" placeholder="—"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label" title="Dacă ținta Y e la cel mult atâția km după X, se face o singură Revizie Y">Toleranță X ↔ Y (km)</label><input class="form-control form-control-sm" data-p="y_toleranta_km" value="' + esc(p.y_toleranta_km || '') + '" placeholder="10% din X"' + ro() + '></div>'
            + '<div class="col-md-4"><label class="form-label">X-urile după o Y</label><select class="form-select form-select-sm" data-p="politica_x"' + ro() + '>' + opt('reset', 'De la Y + interval X (reset)', p.politica_x) + opt('grila', 'Pe grila inițială de km (Y nu mută grila)', p.politica_x) + '</select></div>'
            + '<div class="col-12"><div class="fw-semibold small mt-2">Alerte (prag înainte de scadență; gol = 10% din interval)</div></div>'
            + '<div class="col-md-2"><label class="form-label">Prag km</label><input class="form-control form-control-sm" data-p="prag_km" value="' + esc(p.prag_km || '') + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Prag ore</label><input class="form-control form-control-sm" data-p="prag_ore" value="' + esc(p.prag_ore || '') + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Prag zile</label><input class="form-control form-control-sm" data-p="prag_zile" value="' + esc(p.prag_zile || '') + '" placeholder="' + esc(d.setari.prag_zile_implicit) + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Cost estimat X (lei)</label><input class="form-control form-control-sm" data-p="cost_estimat_x" value="' + esc(p.cost_estimat_x || '') + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Cost estimat Y (lei)</label><input class="form-control form-control-sm" data-p="cost_estimat_y" value="' + esc(p.cost_estimat_y || '') + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Responsabili</label><input class="form-control form-control-sm" data-p="responsabili" value="' + esc(p.responsabili || '') + '" placeholder="nume / rol"' + ro() + '></div>'
            + '</div>';
        var ruleRow = function (oid, sect) {
            var r = rules[oid] || { activ: 1 };
            var o = (d.operatii || []).filter(function (z) { return Number(z.id) === Number(oid); })[0] || {};
            return '<tr data-rule="' + oid + '"' + (sect === 'Y' ? ' style="background:#f5f0ff"' : '') + '><td><input type="checkbox" class="form-check-input" data-r="activ"' + (Number(r.activ) ? ' checked' : '') + ro() + '></td><td>' + esc(o.operatie || opName(oid)) + (Number(o.de_verificat) ? ' <i class="bi bi-exclamation-circle text-warning" title="De verificat"></i>' : '') + '<div class="small text-muted">' + esc(o.aplicabil || '') + '</div></td><td>' + sect + '</td>'
                + '<td><input class="form-control" data-r="interval_km" value="' + esc(r.interval_km || '') + '" placeholder="' + (sect === 'Y' ? 'la Y' : 'cu revizia') + '"' + ro() + '></td>'
                + '<td><input class="form-control" data-r="interval_ore" value="' + esc(r.interval_ore || '') + '"' + ro() + '></td>'
                + '<td><input class="form-control" data-r="interval_luni" value="' + esc(r.interval_luni || '') + '"' + ro() + '></td>'
                + '<td><select class="form-select" data-r="logica"' + ro() + '>' + opt('or', 'SAU', r.logica || 'or') + opt('and', 'ȘI', r.logica) + '</select></td>'
                + '<td><input class="form-control" data-r="coef_ralanti_km" value="' + esc(r.coef_ralanti_km || '') + '" title="Km echivalenți = km + ore ralanti × coeficient (estimare, doar dacă e completat)"' + ro() + '></td>'
                + '<td><input class="form-control" data-r="cantitate" value="' + esc(r.cantitate || '') + '"' + ro() + '></td>'
                + '<td><input class="form-control" data-r="um" value="' + esc(r.um || (o.sistem_fluid ? 'L' : '')) + '" style="width:54px"' + ro() + '></td>'
                + '<td><select class="form-select" data-r="produs_id"' + ro() + '>' + opt('', '—', r.produs_id || '') + (d.produse || []).map(function (pr) { return opt(pr.id, pr.denumire + (pr.vascozitate ? ' ' + pr.vascozitate : ''), r.produs_id); }).join('') + '</select></td>'
                + '<td><input class="form-control" data-r="cost_estimat" value="' + esc(r.cost_estimat || '') + '"' + ro() + '></td></tr>';
        };
        html += '<div class="fw-semibold small mb-1">Reguli pe operațiuni <span class="text-muted fw-normal">— fără interval propriu, operațiunile X urmează intervalul reviziei (măsurat de la propria execuție), iar cele Y se fac la Revizia Y / ținta km Y.</span></div>'
            + '<div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th>Aplic.</th><th>Operațiune</th><th>Secț.</th><th>Km</th><th>Ore</th><th>Luni</th><th>Logică</th><th title="km per oră de ralanti">Coef. ralanti</th><th>Cantitate</th><th>UM</th><th>Produs</th><th>Cost est.</th></tr></thead><tbody>'
            + xOps.map(function (o) { return ruleRow(o, 'X'); }).join('') + yOps.map(function (o) { return ruleRow(o, 'Y'); }).join('') + '</tbody></table></div>'
            + (CAN.configure ? '<div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-cfg-back>Renunță</button><button type="button" class="btn btn-sm btn-primary" data-cfg-save-plan="' + (id || '') + '">Salvează planul</button></div>' : '');
        return html;
    }
    function cfgOperations() {
        var d = cfg.data;
        if (cfg.edit && cfg.edit.type === 'op') { return cfgOperationForm(cfg.edit.id); }
        return '<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold m-0">Catalog operațiuni (' + d.operatii.length + ')</h6>' + (CAN.configure ? '<button type="button" class="btn btn-sm btn-primary" data-cfg-new="op"><i class="bi bi-plus-lg"></i> Operațiune nouă</button>' : '') + '</div>'
            + '<p class="rv2-cfg-help">Intrările marcate <i class="bi bi-exclamation-circle text-warning"></i> pot dubla alte denumiri (ex. „Filtru cabină” / „Filtru habitaclu/polen”, „Filtru centrifugal” / „(hârtie)”): verifică-le cu atelierul și dezactivează duplicatele. Prezența în catalog nu înseamnă că piesa există pe fiecare vehicul.</p>'
            + '<div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th>Componentă</th><th>Operațiune</th><th>Tip</th><th>Categorie</th><th>Sistem fluid</th><th>Se aplică</th><th>Criterii candidate</th><th>Activ</th><th></th></tr></thead><tbody>'
            + d.operatii.map(function (o) {
                return '<tr' + (Number(o.activ) ? '' : ' class="text-muted"') + '><td>' + esc(o.componenta) + (Number(o.de_verificat) ? ' <i class="bi bi-exclamation-circle text-warning" title="De verificat"></i>' : '') + '</td><td>' + esc(o.operatie) + '</td><td>' + esc(d.tipuri[o.tip_operatie] || o.tip_operatie) + '</td><td>' + esc(d.categorii[o.categorie] || o.categorie) + '</td><td>' + esc(o.sistem_fluid || '—') + '</td><td class="small">' + esc(o.aplicabil) + '</td><td class="small">' + esc([o.criterii_candidate, o.valori_candidate].filter(Boolean).join(' · ') || '—') + '</td><td>' + (Number(o.activ) ? 'da' : 'nu') + '</td><td><button type="button" class="btn btn-sm btn-outline-primary" data-cfg-edit="op" data-id="' + o.id + '">' + (CAN.configure ? 'Editează' : 'Vezi') + '</button></td></tr>';
            }).join('') + '</tbody></table></div>';
    }
    function cfgOperationForm(id) {
        var d = cfg.data;
        var o = id ? d.operatii.filter(function (x) { return Number(x.id) === Number(id); })[0] : { componenta: '', operatie: '', tip_operatie: 'inlocuire', categorie: 'filtre', aplicabil: 'cap_tractor,camion', activ: 1, de_verificat: 0 };
        var applies = String(o.aplicabil || '').split(',');
        return '<button type="button" class="btn btn-sm btn-link px-0" data-cfg-back><i class="bi bi-arrow-left"></i> Catalog</button><h6 class="fw-bold">' + (id ? 'Operațiune: ' + esc(o.operatie) : 'Operațiune nouă') + '</h6><div class="row g-2" id="rv2OpForm">'
            + '<div class="col-md-4"><label class="form-label">Componentă</label><input class="form-control form-control-sm" data-o="componenta" value="' + esc(o.componenta) + '"' + ro() + '></div>'
            + '<div class="col-md-5"><label class="form-label">Operațiune</label><input class="form-control form-control-sm" data-o="operatie" value="' + esc(o.operatie) + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Tip operațiune</label><select class="form-select form-select-sm" data-o="tip_operatie"' + ro() + '>' + Object.keys(d.tipuri).map(function (k) { return opt(k, d.tipuri[k], o.tip_operatie); }).join('') + '</select></div>'
            + '<div class="col-md-3"><label class="form-label">Categorie</label><select class="form-select form-select-sm" data-o="categorie"' + ro() + '>' + Object.keys(d.categorii).map(function (k) { return opt(k, d.categorii[k], o.categorie); }).join('') + '</select></div>'
            + '<div class="col-md-3"><label class="form-label">Sistem fluid (stoc)</label><select class="form-select form-select-sm" data-o="sistem_fluid"' + ro() + '>' + opt('', '—', o.sistem_fluid || '') + ['motor', 'transmisie', 'diferential', 'servodirectie', 'hidraulic_pompa', 'racire', 'frana'].map(function (k) { return opt(k, k, o.sistem_fluid); }).join('') + '</select></div>'
            + '<div class="col-md-6"><label class="form-label">Se aplică la</label><div>' + Object.keys(d.familii).map(function (k) { return '<label class="form-check form-check-inline small"><input type="checkbox" class="form-check-input" data-o-apl="' + k + '"' + (applies.indexOf(k) >= 0 ? ' checked' : '') + ro() + '> ' + esc(d.familii[k]) + '</label>'; }).join('') + '</div></div>'
            + '<div class="col-md-6"><label class="form-label">Factori de uzură (ce contribuie la uzură — nu decid scadența)</label><input class="form-control form-control-sm" data-o="factori_uzura" value="' + esc(o.factori_uzura || '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Lubrifiant (gresare)</label><input class="form-control form-control-sm" data-o="lubrifiant" value="' + esc(o.lubrifiant || '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Puncte de gresare</label><input class="form-control form-control-sm" data-o="puncte_gresare" value="' + esc(o.puncte_gresare || '') + '"' + ro() + '></div>'
            + '<div class="col-md-4"><label class="form-label">Criterii candidate (import)</label><input class="form-control form-control-sm" data-o="criterii_candidate" value="' + esc(o.criterii_candidate || '') + '"' + ro() + '></div>'
            + '<div class="col-md-4"><label class="form-label">Valori candidate (import, inactive)</label><input class="form-control form-control-sm" data-o="valori_candidate" value="' + esc(o.valori_candidate || '') + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Cheie Reparații Auto</label><input class="form-control form-control-sm" data-o="component_key" value="' + esc(o.component_key || '') + '" placeholder="ex. 2-5"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Ordine</label><input class="form-control form-control-sm" data-o="ordine" value="' + esc(o.ordine || 500) + '"' + ro() + '></div>'
            + '<div class="col-md-12"><label class="form-label">Descriere</label><textarea class="form-control form-control-sm" rows="2" data-o="descriere"' + ro() + '>' + esc(o.descriere || '') + '</textarea></div>'
            + '<div class="col-md-12"><label class="form-check form-check-inline small"><input type="checkbox" class="form-check-input" data-o="de_verificat"' + (Number(o.de_verificat) ? ' checked' : '') + ro() + '> De verificat (posibil duplicat)</label><label class="form-check form-check-inline small"><input type="checkbox" class="form-check-input" data-o="activ"' + (Number(o.activ) ? ' checked' : '') + ro() + '> Activă</label></div>'
            + '</div>' + (CAN.configure ? '<div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-cfg-back>Renunță</button><button type="button" class="btn btn-sm btn-primary" data-cfg-save-op="' + (id || '') + '">Salvează</button></div>' : '');
    }
    function cfgTemplates() {
        var d = cfg.data;
        if (cfg.edit && cfg.edit.type === 'tpl') { return cfgTemplateForm(cfg.edit.id, cfg.edit.tip); }
        return '<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold m-0">Șabloane de revizie</h6>' + (CAN.configure ? '<div class="d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary" data-cfg-new="tpl" data-tip="X">+ Șablon X</button><button type="button" class="btn btn-sm btn-outline-primary" data-cfg-new="tpl" data-tip="Y">+ Șablon Y</button></div>' : '') + '</div>'
            + '<p class="rv2-cfg-help">Revizia Y moștenește automat toate operațiunile șablonului X ales și adaugă operațiunile proprii (nu le copiază). Versiunea crește la fiecare modificare; lucrările deja create păstrează instantaneul.</p>'
            + '<div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th>Șablon</th><th>Tip</th><th>Moștenește</th><th>Operațiuni proprii</th><th>Versiune</th><th>Activ</th><th></th></tr></thead><tbody>'
            + d.sabloane.map(function (t) {
                var parent = t.parinte_id ? tplById(t.parinte_id) : null;
                return '<tr><td><b>' + esc(t.nume) + '</b></td><td><span class="rv2-pill' + (t.tip === 'Y' ? ' is-y' : '') + '">' + t.tip + '</span></td><td>' + esc(parent ? parent.nume + ' (' + parent.operatii.length + ' op.)' : '—') + '</td><td>' + t.operatii.length + '</td><td>v' + t.versiune + '</td><td>' + (Number(t.activ) ? 'da' : 'nu') + '</td><td><button type="button" class="btn btn-sm btn-outline-primary" data-cfg-edit="tpl" data-id="' + t.id + '">' + (CAN.configure ? 'Editează' : 'Vezi') + '</button></td></tr>';
            }).join('') + '</tbody></table></div>';
    }
    function cfgTemplateForm(id, tip) {
        var d = cfg.data;
        var t = id ? tplById(id) : { nume: '', tip: tip || 'X', parinte_id: tip === 'Y' ? (d.sabloane.filter(function (s) { return s.tip === 'X'; })[0] || {}).id : null, operatii: [], activ: 1 };
        var parent = t.parinte_id ? tplById(t.parinte_id) : null;
        var inherited = parent ? parent.operatii : [];
        return '<button type="button" class="btn btn-sm btn-link px-0" data-cfg-back><i class="bi bi-arrow-left"></i> Șabloane</button><h6 class="fw-bold">' + (id ? 'Șablon: ' + esc(t.nume) : 'Șablon nou ' + t.tip) + '</h6>'
            + '<div class="row g-2 mb-2"><div class="col-md-5"><label class="form-label">Nume</label><input class="form-control form-control-sm" id="rv2TName" value="' + esc(t.nume) + '"' + ro() + '></div>'
            + (t.tip === 'Y' ? '<div class="col-md-5"><label class="form-label">Moștenește șablonul X</label><select class="form-select form-select-sm" id="rv2TParent"' + ro() + '>' + d.sabloane.filter(function (s) { return s.tip === 'X'; }).map(function (s) { return opt(s.id, s.nume, t.parinte_id); }).join('') + '</select></div>' : '')
            + '<div class="col-md-2"><label class="form-label">Activ</label><select class="form-select form-select-sm" id="rv2TActive"' + ro() + '>' + opt(1, 'Da', Number(t.activ)) + opt(0, 'Nu', Number(t.activ)) + '</select></div></div>'
            + '<div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th></th><th>Componentă</th><th>Operațiune</th><th>Tip</th><th>Se aplică</th></tr></thead><tbody>'
            + d.operatii.filter(function (o) { return Number(o.activ); }).map(function (o) {
                var inh = t.tip === 'Y' && inherited.indexOf(Number(o.id)) >= 0;
                return '<tr' + (inh ? ' class="text-muted"' : '') + '><td><input type="checkbox" class="form-check-input" data-tpl-op="' + o.id + '"' + (inh || t.operatii.indexOf(Number(o.id)) >= 0 ? ' checked' : '') + (inh ? ' disabled title="Moștenită din X"' : ro()) + '></td><td>' + esc(o.componenta) + '</td><td>' + esc(o.operatie) + (inh ? ' <span class="rv2-pill is-gray">din X</span>' : '') + '</td><td>' + esc(d.tipuri[o.tip_operatie] || '') + '</td><td class="small">' + esc(o.aplicabil) + '</td></tr>';
            }).join('') + '</tbody></table></div>'
            + (CAN.configure ? '<div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-cfg-back>Renunță</button><button type="button" class="btn btn-sm btn-primary" data-cfg-save-tpl="' + (id || '') + '" data-tip="' + t.tip + '">Salvează șablonul</button></div>' : '');
    }
    function cfgVehicles() {
        var d = cfg.data;
        if (cfg.edit && cfg.edit.type === 'veh') { return cfgVehicleForm(cfg.edit.id); }
        return '<h6 class="fw-bold">Vehicule: plan, referințe, excepții</h6><p class="rv2-cfg-help">La preluare declari ultima revizie (data / km), ultima Revizie Y (km) și câte X s-au făcut de la ea (doar pentru etichete). Țintele următoare se calculează din km. După prima revizie finalizată în aplicație, referințele vin din istoric; corecțiile cer motiv și se auditează.</p>'
            + '<div class="mb-2"><input type="search" class="form-control form-control-sm" id="rv2CfgVehQ" placeholder="Caută nr. auto…" value="' + esc(cfg.vehQ || '') + '"></div>'
            + '<div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th>Nr. auto</th><th>Model</th><th>Plan</th><th>X de la ultima Y</th><th>Ultima revizie</th><th>Ultima Y</th><th>Excepții</th><th></th></tr></thead><tbody>'
            + d.vehicule.filter(function (v) { return !cfg.vehQ || v.nr.toLowerCase().replace(/\s/g, '').indexOf(cfg.vehQ.toLowerCase().replace(/\s/g, '')) >= 0; }).map(function (v) {
                var a = v.asignare || {};
                return '<tr><td><b>' + esc(v.nr) + '</b></td><td>' + esc(v.marca + ' ' + v.model) + '</td><td>' + (v.plan ? esc(v.plan) + ' <span class="rv2-pill is-gray">' + (v.match === 'vehicul' ? 'explicit' : 'automat') + '</span>' : '<span class="text-warning">niciun plan</span>') + '</td><td>' + (a.x_de_la_ultima_y !== undefined ? a.x_de_la_ultima_y : '—') + '</td><td class="small">' + (a.referinta_data ? date(a.referinta_data) + (a.referinta_km ? ' · ' + num(a.referinta_km) + ' km' : '') : '—') + '</td><td class="small">' + (a.ultima_y_km ? num(a.ultima_y_km) + ' km' : '—') + '</td><td>' + (v.exceptii.length || '—') + '</td><td><button type="button" class="btn btn-sm btn-outline-primary" data-cfg-edit="veh" data-id="' + v.id + '">' + (CAN.configure ? 'Editează' : 'Vezi') + '</button></td></tr>';
            }).join('') + '</tbody></table></div>';
    }
    function cfgVehicleForm(id) {
        var d = cfg.data;
        var v = d.vehicule.filter(function (x) { return Number(x.id) === Number(id); })[0];
        var a = v.asignare || {};
        var ex = {};
        v.exceptii.forEach(function (e) { ex[e.operatie_id] = e; });
        var plan = d.planuri.filter(function (p) { return Number(p.id) === Number(a.plan_id || v.plan_id); })[0];
        var opsIds = [];
        if (plan) {
            var x = tplById(plan.sablon_x_id), y = tplById(plan.sablon_y_id);
            opsIds = (x ? x.operatii : []).concat(y ? y.operatii : []).filter(function (o, i, arr) { return arr.indexOf(o) === i; });
        }
        var live = vehicleById(v.id);
        return '<button type="button" class="btn btn-sm btn-link px-0" data-cfg-back><i class="bi bi-arrow-left"></i> Vehicule</button><h6 class="fw-bold">' + esc(v.nr) + ' · ' + esc(v.marca + ' ' + v.model) + '</h6>'
            + (live && live.last && live.last.sursa === 'revizie' ? '<div class="rv2-note"><i class="bi bi-shield-exclamation"></i><span>Vehiculul are revizii finalizate în aplicație: referințele vin din istoric. Corecția referințelor de preluare cere motiv și se auditează.</span></div>' : '')
            + '<div class="row g-2 mb-3"><div class="col-md-4"><label class="form-label">Plan</label><select class="form-select form-select-sm" data-va="plan_id"' + ro() + '>' + opt('', 'Automat (după tip / marcă / model)', a.plan_id || '') + d.planuri.map(function (p) { return opt(p.id, p.nume, a.plan_id); }).join('') + '</select></div>'
            + '<div class="col-md-2"><label class="form-label">X de la ultima Y</label><input type="number" min="0" max="100" class="form-control form-control-sm" data-va="x_de_la_ultima_y" value="' + esc(a.x_de_la_ultima_y || 0) + '"' + ro() + '></div>'
            + '<div class="col-md-2"><label class="form-label">Revizii Y efectuate</label><input type="number" min="0" class="form-control form-control-sm" data-va="y_efectuate" value="' + esc(a.y_efectuate || 0) + '"' + ro() + '></div>'
            + '<div class="col-md-4"><label class="form-label">Data ultimei revizii (referință)</label><input type="datetime-local" class="form-control form-control-sm" data-va="referinta_data" value="' + esc(a.referinta_data ? String(a.referinta_data).replace(' ', 'T').slice(0, 16) : '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Km la referință</label><input class="form-control form-control-sm" data-va="referinta_km" value="' + esc(a.referinta_km || '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Ore motor la referință</label><input class="form-control form-control-sm" data-va="referinta_ore" value="' + esc(a.referinta_ore || '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Data ultimei Y</label><input type="datetime-local" class="form-control form-control-sm" data-va="ultima_y_data" value="' + esc(a.ultima_y_data ? String(a.ultima_y_data).replace(' ', 'T').slice(0, 16) : '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Km la ultima Y</label><input class="form-control form-control-sm" data-va="ultima_y_km" value="' + esc(a.ultima_y_km || '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label" title="Doar pentru politica „grilă”; gol = km la referință">Originea grilei X (km)</label><input class="form-control form-control-sm" data-va="origine_grila_km" value="' + esc(a.origine_grila_km || '') + '"' + ro() + '></div>'
            + '<div class="col-md-3"><label class="form-label">Motiv / observații</label><input class="form-control form-control-sm" data-va="motiv" maxlength="500" value=""' + ro() + '></div></div>'
            + (opsIds.length ? '<div class="fw-semibold small mb-1">Excepții pe operațiuni (gol = moștenește planul)</div><div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th>Operațiune</th><th>Aplicabilă</th><th>Km</th><th>Ore</th><th>Luni</th><th>Cantitate</th><th>Motiv</th></tr></thead><tbody>'
                + opsIds.map(function (oid) {
                    var e = ex[oid] || {};
                    var act = e.activ === null || e.activ === undefined ? '' : String(Number(e.activ));
                    return '<tr data-ex="' + oid + '"' + ((cfg.focusOp && Number(cfg.focusOp) === Number(oid)) ? ' style="background:#fff7e8"' : '') + '><td>' + esc(opName(oid)) + '</td><td><select class="form-select" data-e="activ"' + ro() + '>' + opt('', 'din plan', act) + opt('1', 'da', act) + opt('0', 'nu se aplică', act) + '</select></td>'
                        + '<td><input class="form-control" data-e="interval_km" value="' + esc(e.interval_km || '') + '"' + ro() + '></td><td><input class="form-control" data-e="interval_ore" value="' + esc(e.interval_ore || '') + '"' + ro() + '></td><td><input class="form-control" data-e="interval_luni" value="' + esc(e.interval_luni || '') + '"' + ro() + '></td>'
                        + '<td><input class="form-control" data-e="cantitate" value="' + esc(e.cantitate || '') + '"' + ro() + '></td><td><input class="form-control" data-e="motiv" value="' + esc(e.motiv || '') + '"' + ro() + '></td></tr>';
                }).join('') + '</tbody></table></div>' : '<div class="small text-muted">Alege sau creează un plan ca să poți defini excepții pe operațiuni.</div>')
            + (CAN.configure ? '<div class="d-flex justify-content-end gap-2 mt-3"><button type="button" class="btn btn-sm btn-outline-secondary" data-cfg-back>Renunță</button><button type="button" class="btn btn-sm btn-primary" data-cfg-save-veh="' + v.id + '">Salvează</button></div>' : '');
    }
    function cfgSettings() {
        var s = cfg.data.setari;
        return '<h6 class="fw-bold">Alerte și surse de date</h6><div class="row g-3" id="rv2SetForm">'
            + '<div class="col-md-4"><label class="form-label">Date învechite după (ore)</label><input class="form-control form-control-sm" data-s="date_vechi_ore" value="' + esc(s.date_vechi_ore) + '"' + ro() + '><div class="rv2-cfg-help">Peste acest prag citirea CAN / GPS e marcată învechită; în modul auto se trece la GPS.</div></div>'
            + '<div class="col-md-4"><label class="form-label">Prag implicit zile (calendar)</label><input class="form-control form-control-sm" data-s="prag_zile_implicit" value="' + esc(s.prag_zile_implicit) + '"' + ro() + '><div class="rv2-cfg-help">Se folosește când planul nu are prag de zile (ex. 30 / 14 / 7).</div></div>'
            + '<div class="col-md-4"><label class="form-label">Sursă km implicită (vehicule fără plan)</label><select class="form-select form-select-sm" data-s="sursa_km_implicita"' + ro() + '>' + opt('auto', 'CAN → GPS (auto)', s.sursa_km_implicita) + opt('can', 'Doar CAN', s.sursa_km_implicita) + opt('gps', 'Doar GPS', s.sursa_km_implicita) + '</select></div></div>'
            + '<div class="rv2-note is-info mt-3"><i class="bi bi-info-circle"></i><span>Surse: <b>Km CAN</b> = odometrul de bord din raportul web SAS; <b>Km GPS</b> = kmIndex SAS (fallback declarat, nu se adună cu CAN); <b>ore motor</b> = timp cu motorul pornit din segmentele SAS (include ralantiul); <b>ralanti</b> = parte din orele motor. Semi-remorcile folosesc contorul de km al vehiculului. Asignarea furnizorilor se face în Centru Kilometraj.</span></div>'
            + '<div class="rv2-note is-info"><i class="bi bi-envelope"></i><span>Destinatarii notificărilor (email / SMS) și raportul săptămânal se configurează în <a href="' + esc(D.urls.notificari) + '">Notificări</a>. Pragurile km / ore / zile ale fiecărui plan se setează în Planuri.</span></div>'
            + (CAN.configure ? '<div class="d-flex justify-content-end mt-3"><button type="button" class="btn btn-sm btn-primary" data-cfg-save-set>Salvează</button></div>' : '');
    }
    function cfgImport() {
        var d = cfg.data;
        return '<h6 class="fw-bold">Import operațiuni din Excel</h6><p class="rv2-cfg-help">Copiază tabelul din Excel (cu antet) și lipește-l mai jos. Coloane recunoscute: Componentă, Tip operațiune, Operațiune, Km, Ore, Interval, Model, Tip revizie. „Km / Ore / Interval” devin doar criterii CANDIDATE (nu praguri active). Previzualizarea e obligatorie; nimic nu se scrie până la „Aplică”.</p>'
            + '<textarea class="form-control font-monospace small" rows="8" id="rv2ImpText" placeholder="Componentă&#9;Tip operațiune&#9;Operațiune&#9;Km&#9;Ore&#9;Interval&#9;Model&#9;Tip revizie"' + ro() + '>' + esc(cfg.impText || '') + '</textarea>'
            + '<div class="row g-2 mt-2 align-items-end"><div class="col-md-4"><label class="form-label">Rândurile „X” intră în</label><select class="form-select form-select-sm" id="rv2ImpX">' + d.sabloane.filter(function (t) { return t.tip === 'X'; }).map(function (t) { return opt(t.id, t.nume, ''); }).join('') + '</select></div>'
            + '<div class="col-md-4"><label class="form-label">Rândurile „Y” intră în</label><select class="form-select form-select-sm" id="rv2ImpY">' + opt('', '—', '') + d.sabloane.filter(function (t) { return t.tip === 'Y'; }).map(function (t) { return opt(t.id, t.nume, ''); }).join('') + '</select></div>'
            + '<div class="col-md-4 d-flex gap-2">' + (CAN.configure ? '<button type="button" class="btn btn-sm btn-outline-primary" data-imp="preview">Previzualizare</button><button type="button" class="btn btn-sm btn-primary" data-imp="apply" disabled id="rv2ImpApply">Aplică</button>' : '') + '</div></div>'
            + '<div id="rv2ImpOut" class="mt-3"></div>';
    }
    function bindConfig() {
        var body = $('rv2CfgBody');
        var q = $('rv2CfgVehQ');
        if (q) { q.addEventListener('input', function () { cfg.vehQ = q.value; var pos = q.selectionStart; renderConfig(); var n = $('rv2CfgVehQ'); n.focus(); n.setSelectionRange(pos, pos); }); }
        var tplParent = $('rv2TParent');
        if (tplParent && cfg.edit && cfg.edit.type === 'tpl') {
            tplParent.addEventListener('change', function () {
                var t = cfg.edit.id ? tplById(cfg.edit.id) : null;
                if (t) { t.parinte_id = Number(tplParent.value); }
                renderConfig();
            });
        }
        var planX = body.querySelector('[data-p="sablon_x_id"]'), planY = body.querySelector('[data-p="sablon_y_id"]');
        [planX, planY].forEach(function (sel) {
            if (!sel) { return; }
            sel.addEventListener('change', function () {
                // Regulile se recalculeaza pe noile sabloane, pastrand ce s-a completat.
                var data = planData();
                var id = cfg.edit.id;
                var p = id ? cfg.data.planuri.filter(function (x) { return Number(x.id) === Number(id); })[0] : (cfg.edit.tmp = cfg.edit.tmp || {});
                Object.assign(p, data);
                p.reguli = data.reguli;
                renderConfig();
            });
        });
    }
    function planData() {
        var body = $('rv2CfgBody');
        var data = {};
        body.querySelectorAll('[data-p]').forEach(function (el) { data[el.getAttribute('data-p')] = el.value; });
        data.reguli = [];
        body.querySelectorAll('tr[data-rule]').forEach(function (tr) {
            var r = { operatie_id: tr.getAttribute('data-rule') };
            tr.querySelectorAll('[data-r]').forEach(function (el) { r[el.getAttribute('data-r')] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
            data.reguli.push(r);
        });
        return data;
    }

    // ------------------------------------------------------------------ evenimente
    root.addEventListener('click', function (ev) {
        var t = ev.target;
        var kpi = t.closest('[data-kpi]');
        if (kpi) { onKpi(kpi.getAttribute('data-kpi')); return; }
        var cyc = t.closest('[data-cycle]');
        if (cyc) { var val = cyc.getAttribute('data-cycle'); state.cyclePos = state.cyclePos === val ? '' : val; state.page = 1; refresh(); return; }
        var setType = t.closest('[data-set-type]');
        if (setType) { state.type = setType.getAttribute('data-set-type'); $('rv2FType').value = state.type; state.page = 1; refresh(); return; }
        var setComp = t.closest('[data-set-comp]');
        if (setComp) { state.comp = setComp.getAttribute('data-set-comp'); $('rv2FComp').value = state.comp; state.page = 1; refresh(); return; }
        var chip = t.closest('[data-chip]');
        if (chip) { var c = $('rv2Chips')._chips[Number(chip.getAttribute('data-chip'))]; if (c) { c.clear(); state.page = 1; refresh(); } return; }
        if (t.closest('[data-reset]')) { resetFilters(); return; }
        var page = t.closest('[data-page]');
        if (page && !page.disabled) { state.page = Number(page.getAttribute('data-page')); renderTable(); return; }
        var sort = t.closest('[data-sort]');
        if (sort) { var k = sort.getAttribute('data-sort'); if (state.sort.key === k) { state.sort.dir *= -1; } else { state.sort = { key: k, dir: 1 }; } renderTable(); return; }
        var view = t.closest('[data-view]');
        if (view) {
            state.view = view.getAttribute('data-view');
            root.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('is-on', b === view); });
            try { localStorage.setItem('rv2.prefs', JSON.stringify({ per: state.per, view: state.view })); } catch (e) { /* ignorat */ }
            renderTable();
            return;
        }
        var sect = t.closest('[data-sect]');
        if (sect) {
            var key = sect.getAttribute('data-sect');
            var bodyEl = root.querySelector('[data-sect-body="' + key + '"]');
            var collapsed = !sect.classList.contains('is-collapsed');
            state.collapsed[key] = collapsed;
            sect.classList.toggle('is-collapsed', collapsed);
            sect.setAttribute('aria-expanded', !collapsed);
            if (bodyEl) { animateHeight(bodyEl, !collapsed); }
            return;
        }
        var bulk = t.closest('[data-bulk]');
        if (bulk) { onBulk(bulk.getAttribute('data-bulk')); return; }
        if (t.matches('[data-check-all]')) {
            var list = visibleList().slice((state.page - 1) * state.per, state.page * state.per);
            list.forEach(function (v) { if (t.checked) { state.checked[v.id] = true; } else { delete state.checked[v.id]; } });
            renderTable();
            return;
        }
        if (t.matches('[data-check]')) {
            var cid = t.getAttribute('data-check');
            if (t.checked) { state.checked[cid] = true; } else { delete state.checked[cid]; }
            renderTable();
            return;
        }
        var exp = t.closest('[data-expand]');
        if (exp) { toggleExpand(exp.getAttribute('data-expand')); return; }
        if (handleAct(t)) { if (t.closest('a[data-act]')) { ev.preventDefault(); } return; }
        if (t.closest('[data-stop]') || t.closest('.dropdown-menu')) { return; }
        var opRowEl = t.closest('[data-op]');
        if (opRowEl) { var parts = opRowEl.getAttribute('data-op').split(':'); openComponent(parts[0], parts[1]); return; }
        var sel = t.closest('[data-select]');
        if (sel) {
            select(sel.getAttribute('data-select'));
            if (sel.hasAttribute('data-scroll') || window.innerWidth < 1400) {
                var target = window.innerWidth < 1400 ? $('rv2Panel') : root.querySelector('tr[data-select="' + sel.getAttribute('data-select') + '"]');
                if (target) { target.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'nearest' }); }
            }
        }
    });
    function handleAct(t) {
        var a = t.closest('[data-act]');
        if (!a) { return false; }
        var act = a.getAttribute('data-act');
        var vid = a.getAttribute('data-v');
        var oid = a.getAttribute('data-o');
        if (a.tagName === 'A') { /* evita navigarea #*/ }
        var hideDrawer = function () { var dr = window.bootstrap && window.bootstrap.Offcanvas.getInstance($('rv2CompDrawer')); if (dr) { dr.hide(); } };
        switch (act) {
            case 'execute': openExecution(vid, false); break;
            case 'schedule': hideDrawer(); openExecution(vid, true); break;
            case 'record-op': hideDrawer(); openOperation(vid, oid, null); break;
            case 'note-op': hideDrawer(); openOperation(vid, oid, 'inspectie'); break;
            case 'comp': openComponent(vid, oid); break;
            case 'history-v': openHistory({ vehicle_id: vid }); break;
            case 'history-op': hideDrawer(); openHistory({ vehicle_id: vid, operatie_id: oid }); break;
            case 'explain': openExplain(vid); break;
            case 'edit-rule': hideDrawer(); cfg.edit = { type: 'veh', id: Number(vid) }; cfg.focusOp = oid; openConfig('vehicule'); break;
            case 'cfg-vehicle': cfg.edit = { type: 'veh', id: Number(vid) }; cfg.focusOp = null; openConfig('vehicule'); break;
            case 'cancel-exec':
                var reason = window.prompt('Motivul anulării execuției (se auditează):');
                if (reason) {
                    post('v2_execution_cancel', { id: a.getAttribute('data-e'), motiv: reason }).then(function () { toast('Execuția a fost anulată.', 'ok'); hideDrawer(); reload(); }).catch(function (e) { toast(e.message, 'err'); });
                }
                break;
            default: return false;
        }
        if (a.tagName === 'A') { /* link-urile cu data-act nu navigheaza */ }
        return true;
    }
    document.addEventListener('click', function (ev) {
        // Actiunile din drawere / modale (in afara #rv2).
        var t = ev.target;
        // Meniul „Configurare revizii” sta in antetul paginii (#rv2); restul actiunilor sunt in modale / drawere.
        if (root.contains(t) && !t.closest('[data-cfg], [data-open-history]')) { return; }
        if (t.closest('#rv2CompDrawer') && handleAct(t)) { ev.preventDefault(); return; }
        var hist = t.closest('[data-hist]');
        if (hist) { openHistoryDetail(hist.getAttribute('data-hist')); return; }
        if (t.closest('[data-hist-back]')) { loadHistory(); return; }
        var cfgOpen = t.closest('[data-cfg]');
        if (cfgOpen) { cfg.edit = null; openConfig(cfgOpen.getAttribute('data-cfg')); return; }
        if (t.closest('[data-open-history]')) { openHistory({}); return; }
        var execAll = t.closest('[data-exec-all]');
        if (execAll) { $('rv2ExecBody').querySelectorAll('tr[data-item] [data-f="status"]:not(:disabled)').forEach(function (s) { if (s.value === 'pending' || s.value === 'in_progress') { s.value = 'completed'; } }); execSummary(); return; }
        // configurare
        var nav = t.closest('#rv2CfgNav [data-sec]');
        if (nav) { cfg.edit = null; cfg.sec = nav.getAttribute('data-sec'); renderConfig(); return; }
        if (t.closest('[data-cfg-back]')) { cfg.edit = null; get('v2_config').then(function (j) { cfg.data = j; renderConfig(); }); return; }
        var cnew = t.closest('[data-cfg-new]');
        if (cnew) { cfg.edit = { type: cnew.getAttribute('data-cfg-new'), id: null, tip: cnew.getAttribute('data-tip') }; renderConfig(); return; }
        var cedit = t.closest('[data-cfg-edit]');
        if (cedit) { cfg.edit = { type: cedit.getAttribute('data-cfg-edit'), id: Number(cedit.getAttribute('data-id')) }; renderConfig(); return; }
        var sp = t.closest('[data-cfg-save-plan]');
        if (sp) {
            var data = planData();
            data.id = sp.getAttribute('data-cfg-save-plan') || null;
            sp.disabled = true;
            cfgSave('plan', data).then(function () { cfg.edit = null; renderConfig(); }).catch(function () { }).then(function () { sp.disabled = false; });
            return;
        }
        var so = t.closest('[data-cfg-save-op]');
        if (so) {
            var od = { id: so.getAttribute('data-cfg-save-op') || null, aplicabil: [] };
            $('rv2CfgBody').querySelectorAll('[data-o]').forEach(function (el) { od[el.getAttribute('data-o')] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
            $('rv2CfgBody').querySelectorAll('[data-o-apl]').forEach(function (el) { if (el.checked) { od.aplicabil.push(el.getAttribute('data-o-apl')); } });
            cfgSave('operatie', od).then(function () { cfg.edit = null; renderConfig(); }).catch(function () { });
            return;
        }
        var st = t.closest('[data-cfg-save-tpl]');
        if (st) {
            var td = { id: st.getAttribute('data-cfg-save-tpl') || null, tip: st.getAttribute('data-tip'), nume: $('rv2TName').value, activ: $('rv2TActive').value, parinte_id: $('rv2TParent') ? $('rv2TParent').value : null, operatii: [] };
            $('rv2CfgBody').querySelectorAll('[data-tpl-op]:not(:disabled)').forEach(function (el) { if (el.checked) { td.operatii.push(el.getAttribute('data-tpl-op')); } });
            cfgSave('sablon', td).then(function () { cfg.edit = null; renderConfig(); }).catch(function () { });
            return;
        }
        var sv = t.closest('[data-cfg-save-veh]');
        if (sv) {
            var vd = { vehicle_id: sv.getAttribute('data-cfg-save-veh'), exceptii: [] };
            $('rv2CfgBody').querySelectorAll('[data-va]').forEach(function (el) { vd[el.getAttribute('data-va')] = el.value; });
            $('rv2CfgBody').querySelectorAll('tr[data-ex]').forEach(function (tr) {
                var e = { operatie_id: tr.getAttribute('data-ex') };
                tr.querySelectorAll('[data-e]').forEach(function (el) { e[el.getAttribute('data-e')] = el.value; });
                vd.exceptii.push(e);
            });
            cfgSave('vehicul', vd).then(function () { cfg.edit = null; renderConfig(); }).catch(function () { });
            return;
        }
        if (t.closest('[data-cfg-save-set]')) {
            var sd = {};
            $('rv2CfgBody').querySelectorAll('[data-s]').forEach(function (el) { sd[el.getAttribute('data-s')] = el.value; });
            cfgSave('setari', sd).catch(function () { });
            return;
        }
        var imp = t.closest('[data-imp]');
        if (imp) {
            cfg.impText = $('rv2ImpText').value;
            if (imp.getAttribute('data-imp') === 'preview') {
                post('v2_import_preview', { text: cfg.impText }).then(function (j) {
                    $('rv2ImpOut').innerHTML = '<div class="small mb-2"><b>' + j.new + '</b> noi · <b>' + j.existing + '</b> existente (doar criterii candidate) · <b class="' + (j.errors ? 'text-danger' : '') + '">' + j.errors + '</b> erori</div>'
                        + '<div class="rv2-cfg-scroll"><table class="rv2-cfg-table"><thead><tr><th>Rând</th><th>Componentă</th><th>Operațiune</th><th>Tip</th><th>Revizie</th><th>Criterii candidate</th><th>Valori (inactive)</th><th>Rezultat</th></tr></thead><tbody>'
                        + j.rows.map(function (r) { return '<tr' + (r.status === 'eroare' ? ' class="table-danger"' : '') + '><td>' + r.rand + '</td><td>' + esc(r.componenta) + '</td><td>' + esc(r.operatie) + '</td><td>' + esc(r.tip || '?') + '</td><td>' + r.revizie + '</td><td>' + esc(r.criterii || '—') + '</td><td>' + esc(r.valori || '—') + '</td><td>' + (r.status === 'eroare' ? esc(r.erori.join(' ')) : r.status) + '</td></tr>'; }).join('')
                        + '</tbody></table></div>';
                    $('rv2ImpApply').disabled = j.errors > 0 || (j.new + j.existing) === 0;
                }).catch(function (e) { toast(e.message, 'err'); });
            } else if (window.confirm('Aplici importul? Operațiunile noi intră în catalog (marcate „de verificat”) și în șabloanele alese; nu se activează niciun interval.')) {
                post('v2_import_apply', { text: cfg.impText, sablon_x_id: $('rv2ImpX').value, sablon_y_id: $('rv2ImpY').value }).then(function (j) {
                    toast('Import aplicat: ' + j.create + ' operațiuni noi, ' + j.update + ' actualizate.', 'ok');
                    cfg.impText = '';
                    return get('v2_config').then(function (c) { cfg.data = c; renderConfig(); reload(); });
                }).catch(function (e) { toast(e.message, 'err'); });
            }
        }
    });
    document.addEventListener('change', function (ev) {
        var t = ev.target;
        if (t.closest && t.closest('#rv2ExecBody')) {
            if (t.id === 'rv2EFile' && t.files.length && exec.form && exec.form.id) {
                var fd = new FormData();
                fd.append('_token', D.token);
                fd.append('lucrare_id', exec.form.id);
                fd.append('fisier', t.files[0]);
                fetch(url('v2_doc_upload'), { method: 'POST', credentials: 'same-origin', body: fd }).then(handle).then(function () {
                    toast('Documentul a fost atașat.', 'ok');
                    return get('v2_workorder', { vehicle_id: exec.form.vehicle_id, id: exec.form.id }).then(function (j) { var keep = execPayload(null); exec.form = j; renderExecution(); restoreExec(keep); });
                }).catch(function (e) { toast(e.message, 'err'); });
                return;
            }
            execSummary();
        }
    });
    document.addEventListener('input', function (ev) {
        if (ev.target.closest && ev.target.closest('#rv2ExecBody') && (ev.target.getAttribute('data-f') === 'cost_material' || ev.target.id === 'rv2ELab')) { execSummary(); }
    });
    function restoreExec(p) {
        ['data_programata:rv2EDp', 'data_executie:rv2EDe', 'km_executie:rv2EKm', 'km_sursa:rv2EKs', 'ore_executie:rv2EOre', 'tehnician:rv2ETeh', 'cost_manopera:rv2ELab', 'observatii:rv2EObs'].forEach(function (m) {
            var kv = m.split(':');
            if (p[kv[0]] !== undefined && $(kv[1])) { $(kv[1]).value = p[kv[0]]; }
        });
    }
    function onBulk(kind) {
        var ids = Object.keys(state.checked).filter(function (k) { return state.checked[k]; });
        if (kind === 'clear') { state.checked = {}; renderTable(); return; }
        if (kind === 'filter') { state.adv.veh = ids; syncAdvInputs(); state.page = 1; refresh(); return; }
        if (kind === 'schedule') {
            var day = window.prompt('Data programată pentru ' + ids.length + ' vehicule (AAAA-LL-ZZ):', new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10));
            if (!day) { return; }
            var ok = 0, fail = [];
            var chain = Promise.resolve();
            ids.forEach(function (id) {
                var v = vehicleById(id);
                chain = chain.then(function () {
                    if (!v || !v.plan || v.workflow) { fail.push((v ? v.nr : id) + (v && v.workflow ? ' (are deja lucrare)' : ' (fără plan)')); return null; }
                    return post('v2_workorder_save', { vehicle_id: id, status: 'programata', data_programata: day, items: [] }).then(function () { ok++; }).catch(function (e) { fail.push(v.nr + ': ' + e.message); });
                });
            });
            chain.then(function () {
                toast(ok + ' revizii programate' + (fail.length ? '; neprogramate: ' + fail.join(', ') : '.'), fail.length ? 'err' : 'ok');
                state.checked = {};
                reload();
            });
        }
    }

    // butoane din modale
    $('rv2ExecSave').addEventListener('click', function () { saveExecution('in_desfasurare').then(function () { toast('Progresul a fost salvat.', 'ok'); modal('rv2ExecModal').hide(); reload(); }).catch(function (e) { if (e) { toast(e.message, 'err'); } }); });
    $('rv2ExecSchedule').addEventListener('click', function () { saveExecution('programata').then(function () { toast('Revizia a fost programată.', 'ok'); modal('rv2ExecModal').hide(); reload(); }).catch(function (e) { if (e) { toast(e.message, 'err'); } }); });
    $('rv2ExecFinalize').addEventListener('click', finalizeExecution);
    $('rv2ExecCancel').addEventListener('click', function () {
        var reason = window.prompt('Motivul anulării lucrării:');
        if (!reason) { return; }
        post('v2_workorder_cancel', { id: exec.form.id, motiv: reason }).then(function () { toast('Lucrarea a fost anulată.', 'ok'); modal('rv2ExecModal').hide(); reload(); }).catch(function (e) { toast(e.message, 'err'); });
    });
    $('rv2OpSave').addEventListener('click', saveOperation);
    $('rv2SimBtn').addEventListener('click', function () { openExplain(state.selected); });
    $('rv2SimRun').addEventListener('click', runExplain);
    $('rv2SyncBtn').addEventListener('click', function () {
        var btn = $('rv2SyncBtn');
        btn.classList.add('is-spinning');
        btn.disabled = true;
        var fd = new FormData();
        fd.append('_token', D.token);
        fetch(url('sync'), { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Sincronizarea nu a răspuns.' }; }); })
            .then(function (j) { toast(j.message || (j.ok ? 'Sincronizare efectuată.' : 'Sincronizarea nu s-a putut face.'), j.ok ? 'ok' : 'err'); })
            .catch(function () { toast('SasFleet indisponibil: se afișează ultimele date valide.', 'err'); })
            .then(function () { return reload(); })
            .then(function () { btn.classList.remove('is-spinning'); btn.disabled = false; });
    });

    // filtre
    var debounce;
    $('rv2FSearch').addEventListener('input', function () { clearTimeout(debounce); debounce = setTimeout(function () { state.q = $('rv2FSearch').value.trim(); state.page = 1; refresh(); }, 220); });
    [['rv2FFleet', 'fleet'], ['rv2FType', 'type'], ['rv2FStatus', 'status'], ['rv2FComp', 'comp'], ['rv2FFrom', 'from'], ['rv2FTo', 'to'], ['rv2FWindow', 'win']].forEach(function (p) {
        $(p[0]).addEventListener('change', function () { state[p[1]] = $(p[0]).value; state.page = 1; refresh(); });
    });
    [['rv2AMake', 'make'], ['rv2AVeh', 'veh'], ['rv2ACat', 'cat'], ['rv2AOpType', 'optype'], ['rv2ACrit', 'crit']].forEach(function (p) {
        $(p[0]).addEventListener('change', function () { state.adv[p[1]] = Array.prototype.filter.call($(p[0]).options, function (o) { return o.selected; }).map(function (o) { return o.value; }); state.page = 1; refresh(); });
    });
    [['rv2ASrc', 'src'], ['rv2AHours', 'hours'], ['rv2AConf', 'conf'], ['rv2ASched', 'sched']].forEach(function (p) {
        $(p[0]).addEventListener('change', function () { state.adv[p[1]] = $(p[0]).value; state.page = 1; refresh(); });
    });
    $('rv2Reset').addEventListener('click', resetFilters);
    $('rv2AdvBtn').addEventListener('click', function () {
        var adv = $('rv2Adv');
        var open = adv.dataset.open !== '1';
        adv.dataset.open = open ? '1' : '0';
        $('rv2AdvBtn').classList.toggle('is-on', open);
        $('rv2AdvBtn').setAttribute('aria-expanded', open);
        animateHeight(adv, open);
    });
    $('rv2PerPage').value = String(state.per);
    $('rv2PerPage').addEventListener('change', function () {
        state.per = Number($('rv2PerPage').value); state.page = 1;
        try { localStorage.setItem('rv2.prefs', JSON.stringify({ per: state.per, view: state.view })); } catch (e) { /* ignorat */ }
        renderTable();
    });
    $('rv2SortBtn').addEventListener('click', function () {
        var order = ['due', 'km', 'pct', 'nr', 'cost'];
        if (state.sort.dir === 1) { state.sort.dir = -1; } else { state.sort = { key: order[(order.indexOf(state.sort.key) + 1) % order.length], dir: 1 }; }
        renderTable();
    });
    $('rv2ExpandAll').addEventListener('click', function () {
        var slice = visibleList().slice((state.page - 1) * state.per, state.page * state.per);
        var all = slice.every(function (v) { return state.expanded[v.id]; });
        slice.forEach(function (v) { if (all) { delete state.expanded[v.id]; } else { state.expanded[v.id] = true; } });
        renderTable();
    });
    root.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.target.matches('[data-select].rv2-gcard')) { select(ev.target.getAttribute('data-select')); }
    });

    // ------------------------------------------------------------------ pornire
    if (D.error) { $('rv2Error').hidden = false; $('rv2Error').textContent = D.error; }
    root.querySelectorAll('[data-view]').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-view') === state.view); });
    fillOptions();
    renderSync();
    renderBell();
    // Prima selectie: vehiculul cel mai urgent (ca in macheta, panoul nu ramane gol).
    var first = visibleList()[0];
    if (first && first.status !== 'neconfigurat') { state.selected = first.id; }
    refresh();
    if (state.selected !== null) { select(state.selected); }
    // Legaturi vechi spre tab-uri (?tip=... necunoscut) ajung aici: ?filtru=componenta poate preselecta o componenta.
    var params = new URLSearchParams(window.location.search);
    if (params.get('vehicul')) { select(params.get('vehicul')); }
})();
