/*
 * Cardurile KPI din Istoric Financiar Vehicul — dimensiuni FIXE, navigare internă (componenta comună de morphing,
 * assets/js/morph-card.js, aceeași ca în Rentabilitate). O singură implementare pentru cele 5 carduri:
 *   Total vehicule          → tipuri de vehicul → vehicule (deschide rândul din tabel)
 *   Valoare inițială        → categorii → subcategorii → tipuri de cost → tranzacții (deschide fișa)
 *   Valoare capitalizată    → la fel, doar liniile cu tratament „capitalizabil”
 *   Cheltuieli necapitaliz. → la fel, doar liniile cu tratament „cheltuială de exploatare”
 *   Dosare în derulare      → etape (statusul dosarului) → dosare (deschide rândul din tabel)
 * Totalurile vin de la server (?page=istoric_financiar&action=kpi_breakdown&card=…); aici nu se calculează nimic financiar.
 * În pagină (onScope dat): nivelul ales restrânge tabelul (doar un card odată); înapoi la total redă scopul de dinainte.
 */
(function () {
    'use strict';

    if (window.IfvKpiCard || !window.MorphCard) { return; }
    var MC = window.MorphCard, esc = MC.esc;
    var nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function lei(v) { return nf0.format(Math.round(v)) + ' lei'; }

    function stateHtml(cls, icon, text, title) {
        return '<span class="ifv-mc-state ' + cls + '"' + (title ? ' title="' + esc(title) + '"' : '') + '><i class="bi ' + icon + '"></i>' + esc(text) + '</span>';
    }
    var FIN_KEYS = ['fin_node', 'fin_tip', 'fin_masura'];
    function finScope(measure) {
        return function (entry) { return entry.node ? { fin_node: entry.node, fin_tip: entry.tip || '', fin_masura: measure } : { fin_node: '', fin_tip: '', fin_masura: '' }; };
    }
    var CARDS = {
        vehicule: {
            label: 'Total vehicule', icon: 'bi-truck', iconCls: 'is-blue', watermark: 'bi-truck-front', unit: 'count', unitLabel: 'vehicule', go: 'Tipuri', first: 'Tipuri',
            tone: ['#2f6fe4', '#e8f0fe', '#bcd2fb'], keys: ['tip_vehicul'],
            meta: function (k) { return stateHtml('is-muted', 'bi-folder2', (k.vehicule - k.vehicule_fara_dosar) + ' cu dosar · ' + k.vehicule_fara_dosar + ' fără dosar'); },
            scope: function (entry) { return { tip_vehicul: entry.grup || '' }; }
        },
        valoare_initiala: {
            label: 'Valoare inițială (total)', icon: 'bi-layers', iconCls: 'is-indigo', unit: 'lei', go: 'Categorii', first: 'Categorii',
            tone: ['#4b46d9', '#ecebff', '#c9c6fb'], keys: FIN_KEYS, scope: finScope('total'),
            meta: function (k) {
                return k.dosare_incomplete > 0
                    ? stateHtml('is-warn', 'bi-exclamation-triangle', k.dosare_incomplete + '/' + k.dosare + ' dosare incomplete', 'Dosare cu date incomplete (fără curs, tratament neconfirmat etc.)')
                    : stateHtml('is-ok', 'bi-check-circle', k.dosare + ' dosare · date complete');
            }
        },
        capitalizat: {
            label: 'Valoare capitalizată', icon: 'bi-bar-chart-line', iconCls: 'is-green', unit: 'lei', go: 'Categorii', first: 'Categorii',
            tone: ['#16a34a', '#dcf5e4', '#a9e3bd'], keys: FIN_KEYS, scope: finScope('capitalizabil'),
            meta: function (k) {
                return k.capitalizat_vs_initial === null || k.capitalizat_vs_initial === undefined ? ''
                    : '<span class="ifv-kpi-note">' + (k.capitalizat_vs_initial > 0 ? '+' : '') + String(k.capitalizat_vs_initial).replace('.', ',') + '% vs. inițial</span>';
            }
        },
        necapitalizabile: {
            label: 'Cheltuieli necapitalizabile', icon: 'bi-coin', iconCls: 'is-orange', unit: 'lei', go: 'Categorii', first: 'Categorii',
            tone: ['#d97706', '#ffedd5', '#fbd3a0'], keys: FIN_KEYS, scope: finScope('necapitalizabil'),
            meta: function (k) {
                return k.tratament_neconfirmat > 0
                    ? stateHtml('is-warn', 'bi-hourglass-split', lei(k.tratament_neconfirmat) + ' de confirmat', 'Costuri cu tratament contabil încă neconfirmat (nu sunt nici capitalizate, nici cheltuieli)')
                    : stateHtml('is-ok', 'bi-check-circle', 'tratament confirmat');
            }
        },
        derulare: {
            label: 'Dosare în derulare', icon: 'bi-bullseye', iconCls: 'is-blue', unit: 'count', unitLabel: 'dosare', go: 'Etape', first: 'Etape',
            tone: ['#2f6fe4', '#e8f0fe', '#bcd2fb'], keys: ['tab', 'status'],
            meta: function (k) { return stateHtml('is-muted', 'bi-folder2-open', 'din ' + k.dosare + ' dosare'); },
            // lista de etape arata fila „In derulare”; o etapa = statusul ei (bara de sus il arata)
            scope: function (entry) { return { tab: 'derulare', status: entry.grup || '' }; }
        }
    };

    function mount(el, opts) {
        var key = opts.card, cfg = CARDS[key];
        var stage = el.querySelector('[data-stage]');
        var stack = [{ level: 'total' }];   // {level, node, tip, grup, label, scroll}
        var kpis = null, shown = null, filters = {};
        var rows = [], reqId = 0;
        el.style.setProperty('--mc-c', cfg.tone[0]);
        el.style.setProperty('--mc-soft', cfg.tone[1]);
        el.style.setProperty('--mc-line-c', cfg.tone[2]);

        function top() { return stack[stack.length - 1]; }
        function fmt(v) { return cfg.unit === 'lei' ? lei(v) : nf0.format(Math.round(v)); }
        function amountText(v) { return cfg.unit === 'lei' ? lei(v) : nf0.format(v) + ' ' + cfg.unitLabel; }
        function query(entry) {
            var u = new URL(opts.endpoint, location.href);
            u.searchParams.set('action', 'kpi_breakdown');
            u.searchParams.set('card', key);
            Object.keys(filters).forEach(function (k) { if (filters[k] !== '' && filters[k] != null) { u.searchParams.set(k, filters[k]); } });
            ['node', 'tip', 'grup'].forEach(function (k) { if (entry[k]) { u.searchParams.set(k, entry[k]); } });
            return fetch(u.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
        }

        // ---------------------------------------------------------------- vederi
        function totalHtml() {
            var v = kpis ? kpis[key] : null;
            return '<button type="button" class="ifv-mc-total" data-explore aria-label="' + esc(cfg.label + ' — explorează pe ' + cfg.go.toLowerCase()) + '">'
                + '<span class="ifv-kpi-icon ' + cfg.iconCls + '"><i class="bi ' + cfg.icon + '" aria-hidden="true"></i></span>'
                + '<span class="ifv-kpi-body"><span class="ifv-kpi-label">' + esc(cfg.label) + '</span>'
                + '<span class="ifv-kpi-value" data-value>' + (v === null || v === undefined ? '—' : fmt(v)) + '</span>' + (kpis ? cfg.meta(kpis) : '') + '</span>'
                + (cfg.watermark ? '<i class="bi ' + cfg.watermark + ' ifv-kpi-watermark" aria-hidden="true"></i>' : '')
                + '<span class="ifv-mc-go" aria-hidden="true" title="' + esc('Explorează pe ' + cfg.go.toLowerCase()) + '"><i class="bi bi-chevron-right"></i></span></button>';
        }
        // firul complet la hover; afisat compact: Total › … › nivelul curent
        function fullPath(extra) {
            var parts = [cfg.label];
            stack.forEach(function (e, i) { if (i > 0 && e.label && i < stack.length - 1) { parts.push(e.label); } });
            return parts.concat([extra]).join(' › ');
        }
        function crumbParts(extra) {
            if (stack.length <= 2) { return [{ label: 'Total', goto: 0 }, { label: extra }]; }
            return [{ label: 'Total', goto: 0 }, { label: '…', goto: stack.length - 2 }, { label: extra }];
        }
        function listHtml(entry) {
            var label = entry.label || cfg.first;
            return '<div class="mc-head ifv-mc-head"><button type="button" class="mc-back" data-back aria-label="Înapoi"><i class="bi bi-arrow-left"></i></button>'
                + '<nav class="mc-crumbs" aria-label="Navigare card" data-crumbs title="' + esc(fullPath(label)) + '">' + MC.crumbs(crumbParts(label)) + '</nav>'
                + '<b class="ifv-mc-amount" data-amount title="Totalul nivelului curent">…</b><span class="ifv-mc-warn" data-warn hidden></span></div>'
                + '<div class="mc-list ifv-mc-list" data-list role="list">' + MC.loadingHtml() + '</div>';
        }
        function rowsHtml(d) {
            if (!d || !d.ok) { return '<div class="mc-empty">' + esc((d && d.message) || 'Defalcarea nu a putut fi încărcată.') + '</div>'; }
            rows = d.randuri;
            if (!rows.length) { return '<div class="mc-empty">Nimic de defalcat pentru filtrele paginii.</div>'; }
            return rows.map(function (r, i) {
                var val = r.value_text || (r.value === null || r.value === undefined ? '—' : (r.unit === 'lei' ? lei(r.value) : nf0.format(r.value)));
                var warn = r.fara_curs && Object.keys(r.fara_curs).length;
                var go = r.drill ? 'bi-chevron-right' : (r.kind === 'linie' ? (r.are_fisier ? 'bi-file-earmark-text' : 'bi-box-arrow-up-right') : 'bi-arrow-down-right');
                return '<button type="button" class="mc-row' + (r.kind === 'linie' ? ' ifv-mc-line' : '') + (r.out ? ' is-out' : '') + '" role="listitem" data-i="' + i + '" title="' + esc(r.info || r.label) + '">'
                    + '<span class="mc-row-name">' + esc(r.label) + '</span>'
                    + '<span class="mc-row-meta">' + (r.dot ? '<span class="ifv-mc-dot is-' + esc(r.dot) + '"></span>' : '') + esc(r.meta || '')
                    + (warn ? ' <i class="bi bi-exclamation-triangle" title="Sume fără curs de schimb (nu intră în total)"></i>' : '') + '</span>'
                    + '<span class="mc-row-val">' + esc(val) + '</span><i class="bi ' + go + ' mc-row-go"></i></button>';
            }).join('');
        }

        function show(dir) {
            var entry = top();
            var view = MC.swap(stage, entry.level === 'total' ? totalHtml() : listHtml(entry), dir, entry.level === 'total' ? 'ifv-mc-view is-total' : 'ifv-mc-view is-list');
            el.classList.toggle('is-nav', stack.length > 1);
            if (entry.level !== 'total') { fill(entry, view); }
            var f = view.querySelector(entry.level === 'total' ? '[data-explore]' : '[data-back]');
            if (el.__focusNext && f) { f.focus({ preventScroll: true }); el.__focusNext = false; }
        }
        function fill(entry, view) {
            var id = ++reqId;
            query(entry).then(function (d) {
                if (id !== reqId || !view.isConnected) { return; }
                if (!d.ok && stack.length > 1) { // nivelul nu mai are date cu filtrele noi ale paginii → inapoi la total
                    stack = [{ level: 'total' }]; scope(); show(-1); return;
                }
                entry.label = entry.label || (d.radacina_sarita ? cfg.first : d.titlu);
                view.querySelector('[data-amount]').textContent = amountText(d.total);
                var r = d.reconciliere || {}, noRate = Object.keys(r.fara_curs || {}), warn = view.querySelector('[data-warn]');
                if (Math.abs(r.diferenta || 0) >= 0.01 || noRate.length) {
                    // nereconciliere: se arata explicit, nu se ajusteaza
                    warn.hidden = false;
                    warn.innerHTML = '<i class="bi bi-exclamation-triangle"></i>';
                    warn.title = (Math.abs(r.diferenta || 0) >= 0.01 ? 'Diferență între totalul cardului (' + nf2.format(d.kpi) + ' lei) și suma categoriilor (' + nf2.format(r.total_categorii) + ' lei): ' + nf2.format(r.diferenta) + ' lei. ' : '')
                        + (noRate.length ? 'Sume fără curs de schimb, neincluse: ' + noRate.map(function (c) { return nf2.format(r.fara_curs[c]) + ' ' + c; }).join(', ') + '.' : '');
                }
                var nav = view.querySelector('[data-crumbs]');
                nav.innerHTML = MC.crumbs(crumbParts(entry.label));
                nav.title = fullPath(entry.label);
                entry.node = entry.node || d.node || null; // radacina sarita: nodul curent pentru tipuri
                var list = view.querySelector('[data-list]');
                MC.refill(list, rowsHtml(d));
                if (entry.scroll) { list.scrollTop = entry.scroll; }
            }).catch(function () { if (id === reqId && view.isConnected) { MC.refill(view.querySelector('[data-list]'), rowsHtml(null)); } });
        }
        // scopul tabelului dat de nivelul curent (null = inapoi la total: pagina reda scopul de dinainte)
        function scope() {
            if (!opts.onScope) { return; }
            var e = top();
            opts.onScope(key, e.level === 'total' ? null : cfg.scope(e), cfg.keys);
        }

        // ---------------------------------------------------------------- navigare
        function remember() { var l = el.querySelector('.mc-view:last-child [data-list]'); if (l) { top().scroll = l.scrollTop; } }
        el.addEventListener('click', function (e) {
            var t;
            if ((t = e.target.closest('[data-back]'))) { goTo(stack.length - 2); return; }
            if ((t = e.target.closest('[data-goto]'))) { goTo(Number(t.dataset.goto)); return; }
            if ((t = e.target.closest('[data-i]'))) {
                var r = rows[Number(t.dataset.i)];
                if (!r) { return; }
                if (r.kind === 'linie') { if (opts.onLine) { opts.onLine(Number(r.id), r.dosar_key, t, e.ctrlKey || e.metaKey); } return; }
                if (r.kind === 'rand') { if (opts.onRow) { opts.onRow(r.row_key, r.label); } return; }
                remember();
                var cur = top();
                stack.push(r.kind === 'tip' ? { level: 'list', node: cur.node, tip: r.id, label: r.label }
                    : r.kind === 'grup' ? { level: 'list', grup: r.id, label: r.label }
                        : { level: 'list', node: Number(r.id), label: r.label });
                el.__focusNext = true;
                show(1);
                scope();
                return;
            }
            if ((t = e.target.closest('[data-explore]'))) {
                if (opts.onStart) { opts.onStart(key); }
                stack.push({ level: 'list', label: '' });
                el.__focusNext = true;
                show(1);
                scope();
            }
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && stack.length > 1) { e.preventDefault(); goTo(stack.length - 2); }
        });
        function goTo(index) {
            if (index < 0 || index >= stack.length - 1) { return; }
            stack = stack.slice(0, index + 1);
            el.__focusNext = true;
            show(-1);
            scope();
        }

        // ---------------------------------------------------------------- date din pagina
        // k: KPI-urile paginii (valoarea cardului + detaliile nivelului 0), f: filtrele paginii (scopul defalcarii)
        function update(k, f) {
            var changed = JSON.stringify(f || {}) !== JSON.stringify(filters);
            filters = f || {};
            kpis = k;
            var from = shown;
            shown = k ? k[key] : null;
            if (!stage.__mcView) { show(0); return; }
            if (top().level === 'total') {
                stage.__mcView.innerHTML = totalHtml();
                MC.tween(stage.__mcView.querySelector('[data-value]'), from, shown, fmt);
            } else if (changed) {
                fill(top(), stage.__mcView); // filtrele paginii s-au schimbat: nivelul curent se recalculeaza pe loc
            }
        }
        // alt card a preluat scopul tabelului: acesta revine la total, fara sa mai atinga filtrele
        function reset() { if (stack.length > 1) { stack = [{ level: 'total' }]; show(-1); } }
        return { update: update, reset: reset, isNavigating: function () { return stack.length > 1; } };
    }

    window.IfvKpiCard = { mount: mount, cards: Object.keys(CARDS) };
})();
