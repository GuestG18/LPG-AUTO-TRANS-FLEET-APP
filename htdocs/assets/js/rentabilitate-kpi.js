/*
 * Cardurile KPI din Rentabilitate Operațională: Cost mediu / km, Venit mediu / km, Rezultat operațional / km,
 * Grad acoperire costuri — o singură componentă, folosită în pagină și în Tablou de bord.
 *
 * Cardul NU își schimbă dimensiunea: conținutul lui navighează intern (stivă de vederi), cu tranziții scurte:
 *   Toată flota (indicatorul) → Beneficiari → Tip transport → Vehicule / Șoferi
 * Fiecare alegere din listă schimbă SCOPUL de analiză:
 *  - în pagina Rentabilitate (sync): scopul = filtrele paginii (onScope → pagina reîncarcă lista o singură dată,
 *    apoi update() pe toate cardurile; tabelul, bara de sus și rezumatul de completitudine urmează aceeași stare);
 *  - în Tablou de bord (local): scopul e al cardului; componenta cere singură indicatorii (action=kpi) și nu
 *    atinge pagina sau alte carduri.
 * Valorile vin de pe server (ProfitabilityRegisterService::kpiBlock / kpiBreakdown — agregate ponderate,
 * aceleași rânduri ca tabelul); aici nu se calculează nimic financiar.
 */
(function () {
    'use strict';

    if (window.RopKpi || !window.MorphCard) { return; } // MorphCard (assets/js/morph-card.js) se incarca inainte

    var nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // Indicatorul fiecarui card: valoarea (numar), formatarea, culoarea, totalul din spate si „ponderea” relevanta.
    var CARDS = [
        { key: 'cost', title: 'Cost mediu / km', icon: 'bi-database', tone: 'blue',
            num: function (k) { return k.cost_km; }, fmt: function (v) { return nf2.format(v) + ' lei/km'; }, short: function (v) { return nf2.format(v); },
            sub: function (k) { return nf0.format(k.cost) + ' lei cost eligibil · ' + nf0.format(k.km) + ' km'; }, share: 'share_cost', shareLabel: 'din cost' },
        { key: 'venit', title: 'Venit mediu / km', icon: 'bi-bar-chart', tone: 'green',
            num: function (k) { return k.revenue_km; }, fmt: function (v) { return nf2.format(v) + ' lei/km'; }, short: function (v) { return nf2.format(v); },
            sub: function (k) { return nf0.format(k.revenue) + ' lei venit (tarif curse) · ' + nf0.format(k.km) + ' km'; }, share: 'share_revenue', shareLabel: 'din venit' },
        { key: 'rezultat', title: 'Rezultat operațional / km', icon: 'bi-graph-up-arrow', tone: 'red',
            num: function (k) { return k.result_km; }, fmt: function (v) { return (v > 0 ? '+' : '') + nf2.format(v) + ' lei/km'; }, short: function (v) { return (v > 0 ? '+' : '') + nf2.format(v); },
            sub: function (k) { return (k.result > 0 ? '+' : '') + nf0.format(k.result) + ' lei rezultat total'; }, share: 'share_km', shareLabel: 'din km',
            cls: function (v) { return v === null ? '' : (v < 0 ? 'is-neg' : 'is-pos'); } },
        { key: 'acoperire', title: 'Grad acoperire costuri', icon: 'bi-bullseye', tone: 'violet',
            num: function (k) { return k.coverage; }, fmt: function (v) { return nf1.format(v) + '%'; }, short: function (v) { return nf1.format(v) + '%'; },
            sub: function (k) { return 'venit ' + nf0.format(k.revenue) + ' / cost ' + nf0.format(k.cost) + ' lei'; }, share: 'share_km', shareLabel: 'din km',
            cls: function (v) { return v === null ? '' : (v < 100 ? 'is-neg' : 'is-pos'); } }
    ];
    // Nivelurile navigarii, in ordine; un nivel deja fixat (ex. tipul ales din bara de sus) se sare.
    var LEVELS = { beneficiar: { key: 'beneficiar', title: 'Beneficiari' }, tip: { key: 'tip', title: 'Tip transport' },
        vehicul: { key: 'vehicle_id', title: 'Vehicule' }, sofer: { key: 'driver_id', title: 'Șoferi' } };
    var SCOPE_KEYS = ['beneficiar', 'tip', 'vehicle_id', 'driver_id'];

    function isSet(v) { return v !== undefined && v !== null && v !== '' && v !== 0 && v !== '0'; }
    function completeness(k, mode) {
        var unit = mode === 'soferi' ? 'șoferi' : 'vehicule', short = [], long = [];
        if (k.unitati_incomplete > 0) {
            short.push(k.unitati_incomplete + '/' + k.unitati + ' ' + unit);
            long.push(k.unitati_incomplete + ' din ' + k.unitati + ' ' + unit + ' au date incomplete (cost estimat sau lipsă)');
        }
        if (k.elemente_lipsa > 0) {
            short.push(k.elemente_lipsa + ' elemente fără sursă');
            long.push(k.elemente_lipsa + ' elemente de cost fără sursă sau valoare (nu sunt tratate ca 0)');
        }
        return short.length ? { ok: false, text: 'Incomplet · ' + short.join(' · '), title: long.join('; ') + '.' } : { ok: true, text: 'Date complete', title: '' };
    }

    function mount(root, opts) {
        opts = opts || {};
        var local = opts.local || null;       // Tablou de bord: scop propriu
        var data = null;                      // ultimul raspuns (kpi, aplicat, optiuni, mode, from, to)
        var cache = {};                       // defalcari deja aduse (cheie: by + scop)
        var activeCard = null;                // cardul care navigheaza (doar unul odata)
        var cards = {};
        var labels = {};                      // eticheta valorilor alese (pentru „firul” de navigare)

        root.classList.add('rop-kpis');
        root.innerHTML = CARDS.map(function (c) {
            return '<article class="rop-kpi t-' + c.tone + '" data-kpi="' + c.key + '" aria-label="' + esc(c.title) + '">'
                + '<div class="mc-stage" data-stage></div></article>';
        }).join('');
        CARDS.forEach(function (c) {
            var el = root.querySelector('[data-kpi="' + c.key + '"]');
            cards[c.key] = { def: c, el: el, stage: el.querySelector('[data-stage]'), stack: [{ view: 'overview' }], shown: null, value: null };
        });

        // ---------------------------------------------------------------- scop
        function scope() {
            var a = (data && data.aplicat) || {};
            var s = { mode: data ? data.mode : 'vehicule', from: data ? data.from : '', to: data ? data.to : '' };
            SCOPE_KEYS.concat(['status', 'q']).forEach(function (k) { if (isSet(a[k])) { s[k] = a[k]; } });
            return s;
        }
        function scopeLabel(key, value) {
            if (labels[key + ':' + value]) { return labels[key + ':' + value]; }
            var o = (data && data.optiuni) || {};
            if (key === 'tip') { return (o.tipuri || {})[value] || value; }
            if (key === 'beneficiar') { return (o.beneficiari || {})[value] || ('#' + value); }
            var list = key === 'vehicle_id' ? o.vehicule : o.soferi;
            var hit = (list || []).filter(function (x) { return String(x.id) === String(value); })[0];
            return hit ? hit.label : '#' + value;
        }
        function scopePath() {
            var a = (data && data.aplicat) || {};
            return ['beneficiar', 'tip', 'vehicle_id', 'driver_id'].filter(function (k) { return isSet(a[k]); })
                .map(function (k) { return scopeLabel(k, a[k]); });
        }
        // Urmatorul nivel de explorat din scopul curent (null = nu mai exista niveluri cu date).
        function nextLevel() {
            var a = (data && data.aplicat) || {};
            if (!isSet(a.beneficiar)) { return 'beneficiar'; }
            if (!isSet(a.tip)) { return 'tip'; }
            if (!isSet(a.vehicle_id) && !isSet(a.driver_id)) { return data.mode === 'soferi' ? 'sofer' : 'vehicul'; }
            return null;
        }
        function setScope(patch, key) {
            if (local) {
                Object.keys(patch).forEach(function (k) { local.scope[k] = patch[k]; });
                fetchLocal();
            } else if (opts.onScope) {
                opts.onScope(patch, key);
            }
        }
        function fetchLocal() {
            update(null, true);
            var url = new URL(local.endpoint, location.href);
            url.searchParams.set('action', 'kpi');
            cache = {};
            Object.keys(local.scope).forEach(function (k) { if (isSet(local.scope[k])) { url.searchParams.set(k, local.scope[k]); } });
            fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.ok) { update(d, false); } else { update(null, false); } })
                .catch(function () { update(null, false); });
        }
        function breakdown(by) {
            var s = scope(), url = new URL(local ? local.endpoint : opts.endpoint, location.href);
            url.searchParams.set('action', 'kpi_breakdown');
            url.searchParams.set('by', by);
            Object.keys(s).forEach(function (k) { if (isSet(s[k])) { url.searchParams.set(k, s[k]); } });
            var key = url.toString();
            if (cache[key]) { return Promise.resolve(cache[key]); }
            return fetch(key, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.ok) { cache[key] = d; } return d; });
        }

        // ---------------------------------------------------------------- vederi
        function crumbsHtml(card, extra) {
            // firul: Toată flota › <valorile alese in card> › <lista curenta> (MorphCard.crumbs, data-goto = nivelul)
            var parts = [{ label: 'Toată flota', goto: 0 }];
            card.stack.forEach(function (e, i) {
                if (i === 0 || !e.set) { return; }
                var k = Object.keys(e.set)[0];
                parts.push({ label: scopeLabel(k, e.set[k]), goto: i });
            });
            if (extra) { parts.push({ label: extra }); }
            return MorphCard.crumbs(parts);
        }
        function headHtml(card, back) {
            var c = card.def;
            return '<div class="mc-head">'
                + (back ? '<button type="button" class="mc-back" data-back aria-label="Înapoi"><i class="bi bi-arrow-left"></i></button>'
                    : '<span class="rop-kpi-ico"><i class="bi ' + c.icon + '"></i></span>')
                + '<span class="rop-kpi-title">' + esc(c.title) + '</span>';
        }
        function overviewHtml(card) {
            var c = card.def, k = data && data.kpi, next = data ? nextLevel() : null;
            var path = data ? scopePath() : [];
            var v = k ? c.num(k) : null;
            var st = k ? completeness(k, data.mode) : null;
            var depth = card.stack.length > 1;
            return headHtml(card, depth)
                + (next ? '<button type="button" class="rop-kc-explore" data-explore title="Explorează pe ' + esc(LEVELS[next].title.toLowerCase()) + '">'
                    + esc(LEVELS[next].title) + '<i class="bi bi-chevron-right"></i></button>' : '')
                + '</div>'
                + '<button type="button" class="rop-kc-metric" data-explore' + (next ? '' : ' disabled') + ' aria-label="' + esc(c.title + (next ? ' — explorează pe ' + LEVELS[next].title.toLowerCase() : '')) + '">'
                + '<b class="rop-kpi-value ' + (c.cls ? c.cls(v) : '') + '" data-value>' + (v === null || v === undefined ? '—' : esc(c.fmt(v))) + '</b>'
                + '<span class="rop-kpi-sub" title="' + esc(k ? c.sub(k) : '') + '">' + (k ? (k.km > 0 || k.cost > 0 ? esc(c.sub(k)) : 'Fără activitate în selecție') : '&nbsp;') + '</span></button>'
                + '<div class="rop-kc-scope' + (path.length ? ' is-scoped' : '') + '">'
                + (path.length ? '<span class="rop-kc-dot" aria-hidden="true"></span><span class="rop-kc-path" title="' + esc(path.join(' › ')) + '">' + esc(path.join(' › ')) + '</span>'
                    + '<button type="button" class="rop-kc-clear" data-clear title="Înapoi la toată flota" aria-label="Înapoi la toată flota"><i class="bi bi-x"></i></button>'
                    : '<span class="rop-kc-path">' + (data && data.mode === 'soferi' ? 'Toți șoferii' : 'Toată flota') + (k ? ' · ' + k.unitati + (data.mode === 'soferi' ? ' șoferi' : ' vehicule') : '') + '</span>')
                + '</div>'
                + (st ? '<div class="rop-kpi-state ' + (st.ok ? 'is-ok' : 'is-warn') + '" title="' + esc(st.title) + '"><i class="bi ' + (st.ok ? 'bi-check-circle' : 'bi-exclamation-triangle') + '"></i><span>' + esc(st.text) + '</span></div>' : '');
        }
        function listHtml(card, entry) {
            var lvl = LEVELS[entry.view];
            var v = data && data.kpi ? card.def.num(data.kpi) : null;
            var canSwap = entry.view === 'vehicul' || entry.view === 'sofer';
            return headHtml(card, true)
                + '<span class="rop-kc-now" title="Valoarea în scopul curent">' + (v === null || v === undefined ? '—' : esc(card.def.short(v))) + '</span></div>'
                + '<nav class="mc-crumbs" aria-label="Navigare card">' + crumbsHtml(card, lvl.title) + '</nav>'
                + (canSwap ? '<div class="mc-seg" role="group" aria-label="Detaliere"><button type="button" data-swap="vehicul" class="' + (entry.view === 'vehicul' ? 'is-on' : '') + '">Vehicule</button>'
                    + '<button type="button" data-swap="sofer" class="' + (entry.view === 'sofer' ? 'is-on' : '') + '">Șoferi</button></div>' : '')
                + '<div class="mc-list" data-list role="list">' + MorphCard.loadingHtml() + '</div>'
;
        }
        function rowsHtml(card, d) {
            var c = card.def;
            if (!d || !d.ok) { return '<div class="mc-empty">' + esc((d && d.message) || 'Defalcarea nu a putut fi încărcată.') + '</div>'; }
            if (!d.rows.length) { return '<div class="mc-empty">Fără activitate de defalcat în scopul curent.</div>'; }
            var unit = data && data.mode === 'soferi' ? 'șoferi' : 'vehicule';
            return d.rows.map(function (r) {
                var v = c.num(r.kpi), share = r[c.share];
                var warn = r.kpi.unitati_incomplete > 0;
                return '<button type="button" class="mc-row" role="listitem" data-pick="' + esc(r.value) + '" data-label="' + esc(r.label) + '">'
                    + '<span class="mc-row-name" title="' + esc(r.label) + '">' + esc(r.label) + '</span>'
                    + '<span class="mc-row-meta" title="' + esc(nf0.format(r.kpi.km) + ' km' + (share !== null && share !== undefined ? ' · ' + nf1.format(share) + '% ' + c.shareLabel : '')) + '">'
                    + nf0.format(r.kpi.km) + ' km' + (share !== null && share !== undefined ? ' · ' + nf0.format(share) + '%' : '')
                    + (warn ? ' <i class="bi bi-exclamation-triangle" title="' + esc(r.kpi.unitati_incomplete + ' din ' + r.kpi.unitati + ' ' + unit + ' cu date incomplete') + '"></i>' : '') + '</span>'
                    + '<span class="mc-row-val ' + (c.cls ? c.cls(v) : '') + '">' + (v === null ? '—' : esc(c.short(v))) + '</span>'
                    + '<i class="bi bi-chevron-right mc-row-go"></i></button>';
            }).join('');
        }

        // Inlocuieste vederea cardului cu tranzitie (dir: 1 = inainte, -1 = inapoi, 0 = pe loc).
        // deferFill: lista se umple abia cand vine scopul nou (alegerea tocmai a schimbat filtrele).
        function show(card, dir, deferFill) {
            var entry = card.stack[card.stack.length - 1];
            var view = MorphCard.swap(card.stage, entry.view === 'overview' ? overviewHtml(card) : listHtml(card, entry), dir,
                'rop-kc-view ' + (entry.view === 'overview' ? 'is-overview' : 'is-list'));
            card.shown = view;
            card.el.classList.toggle('is-nav', card.stack.length > 1);
            if (entry.view !== 'overview' && !deferFill) { fillList(card, entry, view); }
            var focusEl = view.querySelector(entry.view === 'overview' ? '[data-explore]' : '[data-back]');
            if (card.focusNext && focusEl) { focusEl.focus({ preventScroll: true }); card.focusNext = false; }
        }
        function fillList(card, entry, view) {
            var list = view.querySelector('[data-list]');
            breakdown(entry.view).then(function (d) {
                if (card.shown === view) { MorphCard.refill(list, rowsHtml(card, d)); }
            }).catch(function () { if (card.shown === view) { MorphCard.refill(list, rowsHtml(card, null)); } });
        }
        // Valoarea indicatorului se schimba animat (numarul curge spre noua valoare).
        function tweenValue(card) {
            var el = card.shown && card.shown.querySelector('[data-value]');
            var c = card.def, k = data && data.kpi, to = k ? c.num(k) : null, from = card.value;
            card.value = to;
            if (!el) { return; }
            el.className = 'rop-kpi-value ' + (c.cls ? c.cls(to) : '');
            MorphCard.tween(el, from, to, c.fmt);
        }

        // ---------------------------------------------------------------- navigare
        function startNav(card) {
            var next = nextLevel();
            if (!next) { return; }
            CARDS.forEach(function (c) { var o = cards[c.key]; if (o !== card && o.stack.length > 1) { o.stack = [{ view: 'overview' }]; show(o, -1); } });
            activeCard = card;
            card.stack.push({ view: next });
            card.focusNext = true;
            show(card, 1);
        }
        function pick(card, value, label) {
            var entry = card.stack[card.stack.length - 1], lvl = LEVELS[entry.view], patch = {};
            patch[lvl.key] = value;
            labels[lvl.key + ':' + value] = label;
            // valoarea aleasa fixeaza scopul; cardul trece la urmatorul nivel cu date (sau la indicator, la capat)
            if (lvl.key === 'vehicle_id') { patch.driver_id = ''; }
            if (lvl.key === 'driver_id') { patch.vehicle_id = ''; }
            var set = {}; set[lvl.key] = value;
            var after = entry.view === 'beneficiar' ? (isSet(data.aplicat.tip) ? (data.mode === 'soferi' ? 'sofer' : 'vehicul') : 'tip')
                : entry.view === 'tip' ? (data.mode === 'soferi' ? 'sofer' : 'vehicul') : null;
            card.stack.push({ view: after || 'overview', set: set });
            card.focusNext = true;
            show(card, 1, true); // lista urmatoare se umple cand vine scopul nou (update → refreshInPlace)
            setScope(patch, lvl.key);
        }
        function goTo(card, index) {
            // inapoi la nivelul index: filtrele fixate dupa el se scot (fara resetarea celorlalte filtre)
            if (index >= card.stack.length - 1) { return; }
            var patch = {};
            card.stack.slice(index + 1).forEach(function (e) { if (e.set) { Object.keys(e.set).forEach(function (k) { patch[k] = ''; }); } });
            card.stack = card.stack.slice(0, index + 1);
            if (card.stack.length === 1) { activeCard = null; }
            card.focusNext = true;
            var changes = Object.keys(patch).length > 0;
            show(card, -1, changes);
            if (changes) { setScope(patch, ''); }
        }
        function clearScope(card) {
            CARDS.forEach(function (c) { cards[c.key].stack = [{ view: 'overview' }]; });
            activeCard = null;
            setScope({ beneficiar: '', tip: '', vehicle_id: '', driver_id: '' }, '');
            show(card, -1);
        }

        root.addEventListener('click', function (e) {
            var cardEl = e.target.closest('.rop-kpi');
            if (!cardEl) { return; }
            var card = cards[cardEl.dataset.kpi], t;
            if ((t = e.target.closest('[data-clear]'))) { clearScope(card); return; }
            if ((t = e.target.closest('[data-back]'))) { goTo(card, card.stack.length - 2); return; }
            if ((t = e.target.closest('[data-goto]'))) { goTo(card, Number(t.dataset.goto)); return; }
            if ((t = e.target.closest('[data-swap]'))) {
                var top = card.stack[card.stack.length - 1];
                if (top.view !== t.dataset.swap) { top.view = t.dataset.swap; show(card, 0); }
                return;
            }
            if ((t = e.target.closest('[data-pick]'))) { pick(card, t.dataset.pick, t.dataset.label); return; }
            if ((t = e.target.closest('[data-explore]'))) { startNav(card); }
        });
        root.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            var cardEl = e.target.closest && e.target.closest('.rop-kpi');
            var card = cardEl ? cards[cardEl.dataset.kpi] : null;
            if (card && card.stack.length > 1) { e.preventDefault(); goTo(card, card.stack.length - 2); }
        });

        // Scop nou, aceeasi vedere: textele se schimba pe loc, valoarea curge, lista se reumple (fara salt de vedere).
        function refreshInPlace(card) {
            var entry = card.stack[card.stack.length - 1], view = card.shown;
            card.el.classList.toggle('is-nav', card.stack.length > 1);
            if (!view) { show(card, 0); tweenValue(card); return; }
            if (entry.view === 'overview') {
                var prev = card.value;
                view.innerHTML = overviewHtml(card);
                var el = view.querySelector('[data-value]');
                if (el && prev !== null && prev !== undefined) { el.textContent = card.def.fmt(prev); }
                tweenValue(card);
                return;
            }
            var v = data && data.kpi ? card.def.num(data.kpi) : null;
            var now = view.querySelector('.rop-kc-now');
            if (now) { now.textContent = v === null || v === undefined ? '—' : card.def.short(v); }
            var crumbs = view.querySelector('.mc-crumbs');
            if (crumbs) { crumbs.innerHTML = crumbsHtml(card, LEVELS[entry.view].title); }
            tweenValue(card);
            fillList(card, entry, view);
        }

        // ---------------------------------------------------------------- date noi (scop schimbat)
        function update(d, loading) {
            root.classList.toggle('is-loading', !!loading);
            if (!d) { return; }
            var first = data === null;
            data = d;
            CARDS.forEach(function (c) {
                var card = cards[c.key];
                // stiva cardului ramane valabila doar daca filtrele fixate de ea sunt inca cele aplicate
                // (altfel scopul a fost schimbat din bara de sus / alt card / resetare → cardul revine la indicator)
                var valid = card.stack.every(function (e) {
                    return !e.set || Object.keys(e.set).every(function (k) { return String(d.aplicat[k] || '') === String(e.set[k]); });
                });
                if (!valid) {
                    card.stack = [{ view: 'overview' }];
                    if (activeCard === card) { activeCard = null; }
                    show(card, -1);
                    card.value = null; tweenValue(card);
                    return;
                }
                if (first) { show(card, 0); tweenValue(card); return; }
                refreshInPlace(card);
            });
        }

        if (local) { if (local.initial) { update(local.initial, false); } else { fetchLocal(); } }
        return { update: update };
    }

    // Instantele din Tablou de bord (data-rop-kpi='{json}'): fragmentul vine prin fetch, scriptul ruleaza o data,
    // deci le pornim si pe cele inserate ulterior. Scop propriu, initial = filtrele cardului de dashboard.
    function scan() {
        document.querySelectorAll('[data-rop-kpi]:not([data-rop-kpi-ready])').forEach(function (el) {
            el.setAttribute('data-rop-kpi-ready', '1');
            var d = null;
            try { d = JSON.parse(el.getAttribute('data-rop-kpi')); } catch (err) { d = null; }
            if (!d) { return; }
            var a = d.aplicat || {}, s = { mode: d.mode, from: d.from, to: d.to };
            SCOPE_KEYS.forEach(function (k) { if (isSet(a[k])) { s[k] = a[k]; } });
            mount(el, { local: { endpoint: d.endpoint, scope: s, initial: d } });
        });
    }
    window.RopKpi = { mount: mount, scan: scan };
    scan();
    if (typeof MutationObserver === 'function') {
        new MutationObserver(function () { scan(); }).observe(document.body, { childList: true, subtree: true });
    }
})();
