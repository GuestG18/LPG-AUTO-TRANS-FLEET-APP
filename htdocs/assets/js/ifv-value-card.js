/*
 * Cardul „Valoare inițială (total)” din Istoric Financiar Vehicul — dimensiuni FIXE, navigare internă
 * (aceeași componentă de morphing ca în Rentabilitate: assets/js/morph-card.js):
 *   Valoare inițială → categorii principale → subcategorii (copiii din arbore) → tipuri de cost → tranzacții.
 * Totalurile vin de la server (?page=istoric_financiar&action=kpi_breakdown — FinancialCategoryTree peste liniile
 * dosarelor, aceleași reguli ca suma dosarului); aici nu se calculează nimic financiar.
 *
 * În pagină (onScope dat): alegerea unei categorii / unui tip restrânge tabelul de vehicule la dosarele cu costuri
 * în ea (restul filtrelor paginii rămân); înapoi / „Valoare inițială” scot restrângerea. O tranzacție deschide fișa
 * ei (panoul din Dispecer curse). Fără onScope (ex. Tablou de bord): navigarea e doar a cardului.
 */
(function () {
    'use strict';

    if (window.IfvValueCard || !window.MorphCard) { return; }
    var MC = window.MorphCard, esc = MC.esc;
    var nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function lei(v) { return nf0.format(Math.round(v)) + ' lei'; }
    function dt(v) { var m = String(v || '').match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? m[3] + '.' + m[2] + '.' + m[1] : ''; }

    function mount(el, opts) {
        opts = opts || {};
        var stage = el.querySelector('[data-stage]');
        var stack = [{ level: 'total' }];   // {level, node, tip, label, scroll}
        var total = null, shownTotal = null, meta = null, filters = {};
        var current = null;                 // ultima defalcare primita (pentru nivelul curent)
        var reqId = 0;

        function top() { return stack[stack.length - 1]; }
        function query(entry) {
            var u = new URL(opts.endpoint, location.href);
            u.searchParams.set('action', 'kpi_breakdown');
            Object.keys(filters).forEach(function (k) { if (filters[k] !== '' && filters[k] != null) { u.searchParams.set(k, filters[k]); } });
            if (entry.node) { u.searchParams.set('node', entry.node); }
            if (entry.tip) { u.searchParams.set('tip', entry.tip); }
            return fetch(u.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); });
        }

        // ---------------------------------------------------------------- vederi
        function totalHtml() {
            var st = meta ? (meta.incomplete > 0
                ? '<span class="ifv-mc-state is-warn" title="Dosare cu date incomplete (fără curs, tratament neconfirmat etc.)"><i class="bi bi-exclamation-triangle"></i>' + meta.incomplete + '/' + meta.dossiers + ' dosare incomplete</span>'
                : '<span class="ifv-mc-state is-ok"><i class="bi bi-check-circle"></i>' + meta.dossiers + ' dosare · date complete</span>') : '';
            return '<button type="button" class="ifv-mc-total" data-explore aria-label="Valoare inițială (total) — explorează pe categorii">'
                + '<span class="ifv-kpi-icon is-indigo"><i class="bi bi-layers" aria-hidden="true"></i></span>'
                + '<span class="ifv-kpi-body"><span class="ifv-kpi-label">Valoare inițială (total)</span>'
                + '<span class="ifv-kpi-value" data-value>' + (shownTotal === null ? '—' : lei(shownTotal)) + '</span>' + st + '</span>'
                + '<span class="ifv-mc-go" aria-hidden="true">Categorii<i class="bi bi-chevron-right"></i></span></button>';
        }
        // Firul complet (titlu, la hover) si cel compact afisat: nivelurile anterioare se strang in „…”
        // (merge la nivelul de dinainte; inapoi / Esc fac acelasi lucru), nivelul curent ramane lizibil.
        function fullPath(extra) {
            var parts = ['Valoare inițială'];
            stack.forEach(function (e, i) { if (i > 0 && e.label && i < stack.length - 1) { parts.push(e.label); } });
            return parts.concat([extra]).join(' › ');
        }
        function crumbParts(extra) {
            if (stack.length <= 2) { return [{ label: 'Total', goto: 0 }, { label: extra }]; }
            return [{ label: 'Total', goto: 0 }, { label: '…', goto: stack.length - 2 }, { label: extra }];
        }
        function listHtml(entry) {
            return '<div class="mc-head ifv-mc-head"><button type="button" class="mc-back" data-back aria-label="Înapoi"><i class="bi bi-arrow-left"></i></button>'
                + '<nav class="mc-crumbs" aria-label="Navigare card" data-crumbs title="' + esc(fullPath(entry.label || 'Categorii')) + '">' + MC.crumbs(crumbParts(entry.label || 'Categorii')) + '</nav>'
                + '<b class="ifv-mc-amount" data-amount title="Totalul nivelului curent">…</b><span class="ifv-mc-warn" data-warn hidden></span></div>'
                + '<div class="mc-list ifv-mc-list" data-list role="list">' + MC.loadingHtml() + '</div>';
        }
        function rowsHtml(d) {
            if (!d || !d.ok) { return '<div class="mc-empty">' + esc((d && d.message) || 'Defalcarea nu a putut fi încărcată.') + '</div>'; }
            if (!d.randuri.length) { return '<div class="mc-empty">Fără costuri în această categorie pentru filtrele paginii.</div>'; }
            if (d.nivel === 'tranzactii') {
                return d.randuri.map(function (r) {
                    var amount = r.suma === null ? nf2.format(r.suma_originala) + ' ' + r.moneda : lei(r.suma);
                    var tip = [r.descriere, dt(r.data), r.furnizor || '—', (r.suma === null ? '' : nf2.format(r.suma) + ' lei') + (r.moneda !== 'RON' ? ' (' + nf2.format(r.suma_originala) + ' ' + r.moneda + ')' : ''),
                        'Tratament: ' + r.tratament_label, r.document ? 'Document: ' + r.document : 'Fără document', 'Dosar: ' + r.dosar, r.nota].filter(Boolean).join('\n');
                    return '<button type="button" class="mc-row ifv-mc-line' + (r.in_valoare ? '' : ' is-out') + '" role="listitem" data-line="' + r.id + '" data-dossier="' + esc(r.dosar_key) + '" title="' + esc(tip) + '">'
                        + '<span class="mc-row-name">' + esc(r.descriere) + '</span>'
                        + '<span class="mc-row-meta"><span class="ifv-mc-dot is-' + esc(r.tratament) + '"></span>' + esc(dt(r.data).replace(/\.(\d{2})(\d{2})$/, '.$2')) + '</span>'
                        + '<span class="mc-row-val">' + esc(amount) + '</span><i class="bi ' + (r.are_fisier ? 'bi-file-earmark-text' : 'bi-box-arrow-up-right') + ' mc-row-go"></i></button>';
                }).join('');
            }
            return d.randuri.map(function (r) {
                var warn = r.fara_curs && Object.keys(r.fara_curs).length;
                var info = r.label + ' — ' + lei(r.total) + (r.share === null || r.share === undefined ? '' : ' · ' + nf1.format(r.share) + '% din nivelul de deasupra') + ' · ' + r.count + ' tranzacții';
                return '<button type="button" class="mc-row" role="listitem" data-kind="' + r.kind + '" data-id="' + esc(r.id) + '" data-label="' + esc(r.label) + '" title="' + esc(info) + '">'
                    + '<span class="mc-row-name">' + esc(r.label) + '</span>'
                    + '<span class="mc-row-meta">' + (r.share === null || r.share === undefined ? r.count + ' tr.' : nf1.format(r.share) + '%')
                    + (warn ? ' <i class="bi bi-exclamation-triangle" title="Sume fără curs de schimb (nu intră în total)"></i>' : '') + '</span>'
                    + '<span class="mc-row-val">' + lei(r.total) + '</span><i class="bi bi-chevron-right mc-row-go"></i></button>';
            }).join('');
        }

        function show(dir) {
            var entry = top();
            var view = MC.swap(stage, entry.level === 'total' ? totalHtml() : listHtml(entry), dir, entry.level === 'total' ? 'ifv-mc-view is-total' : 'ifv-mc-view is-list');
            el.classList.toggle('is-nav', stack.length > 1);
            if (entry.level !== 'total') { fill(entry, view); }
            var f = view.querySelector(entry.level === 'total' ? '[data-explore]' : '[data-back]');
            if (el.__focusNext && f) { f.focus({ preventScroll: true }); el.__focusNext = false; }
            return view;
        }
        function fill(entry, view) {
            var id = ++reqId;
            query(entry).then(function (d) {
                if (id !== reqId || !view.isConnected) { return; }
                current = d;
                if (!d.ok && stack.length > 1) {
                    // categoria nu mai are costuri cu filtrele noi ale paginii → inapoi la total, fara restrangere
                    stack = [{ level: 'total' }]; setScope(); show(-1); return;
                }
                entry.label = entry.label || (d.radacina_sarita ? 'Categorii' : d.titlu);
                var amount = view.querySelector('[data-amount]');
                amount.textContent = lei(d.total);
                var warn = view.querySelector('[data-warn]');
                var r = d.reconciliere || {};
                var noRate = Object.keys(r.fara_curs || {});
                if (Math.abs(r.diferenta || 0) >= 0.01 || noRate.length) {
                    // nereconciliere: se arata explicit, nu se ajusteaza
                    warn.hidden = false;
                    warn.innerHTML = '<i class="bi bi-exclamation-triangle"></i>';
                    warn.title = (Math.abs(r.diferenta || 0) >= 0.01 ? 'Diferență între valoarea inițială (' + nf2.format(d.valoare_initiala) + ' lei) și suma categoriilor (' + nf2.format(r.total_categorii) + ' lei): ' + nf2.format(r.diferenta) + ' lei. ' : '')
                        + (noRate.length ? 'Sume fără curs de schimb, neincluse: ' + noRate.map(function (c) { return nf2.format(r.fara_curs[c]) + ' ' + c; }).join(', ') + '.' : '');
                }
                var nav = view.querySelector('[data-crumbs]');
                nav.innerHTML = MC.crumbs(crumbParts(entry.label));
                nav.title = fullPath(entry.label);
                var list = view.querySelector('[data-list]');
                MC.refill(list, rowsHtml(d));
                if (entry.scroll) { list.scrollTop = entry.scroll; }
            }).catch(function () { if (id === reqId && view.isConnected) { MC.refill(view.querySelector('[data-list]'), rowsHtml(null)); } });
        }
        // restrangerea tabelului = categoria / tipul de pe nivelul curent (nimic la total si la lista de sus)
        function setScope() {
            if (!opts.onScope) { return; }
            var e = top();
            opts.onScope(e.level === 'total' || !e.node ? { fin_node: '', fin_tip: '' } : { fin_node: e.node, fin_tip: e.tip || '' });
        }

        // ---------------------------------------------------------------- navigare
        function remember() { var l = el.querySelector('.mc-view:last-child [data-list]'); if (l) { top().scroll = l.scrollTop; } }
        el.addEventListener('click', function (e) {
            var t;
            if ((t = e.target.closest('[data-back]'))) { goTo(stack.length - 2); return; }
            if ((t = e.target.closest('[data-goto]'))) { goTo(Number(t.dataset.goto)); return; }
            if ((t = e.target.closest('[data-line]'))) {
                if (opts.onLine) { opts.onLine(Number(t.dataset.line), t.dataset.dossier, t, e.ctrlKey || e.metaKey); }
                return;
            }
            if ((t = e.target.closest('[data-kind]'))) {
                remember();
                var entry = t.dataset.kind === 'tip'
                    ? { level: 'list', node: top().node || (current && current.node), tip: t.dataset.id, label: t.dataset.label }
                    : { level: 'list', node: Number(t.dataset.id), label: t.dataset.label };
                stack.push(entry);
                el.__focusNext = true;
                show(1);
                setScope();
                return;
            }
            if ((t = e.target.closest('[data-explore]'))) {
                stack.push({ level: 'list', label: '' });
                el.__focusNext = true;
                show(1);
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
            setScope();
        }

        // ---------------------------------------------------------------- date din pagina
        // value: totalul flotei (KPI-ul paginii), m: {dossiers, incomplete}, f: filtrele paginii (scopul defalcarii)
        function update(value, m, f) {
            var changed = JSON.stringify(f || {}) !== JSON.stringify(filters);
            filters = f || {};
            meta = m || meta;
            var from = shownTotal;
            total = value; shownTotal = value;
            if (!stage.__mcView) { show(0); return; }
            if (top().level === 'total') {
                stage.__mcView.innerHTML = totalHtml();
                MC.tween(stage.__mcView.querySelector('[data-value]'), from, value, lei);
            } else if (changed) {
                fill(top(), stage.__mcView); // filtrele paginii s-au schimbat: nivelul curent se recalculeaza pe loc
            }
        }
        function reset() { if (stack.length > 1) { stack = [{ level: 'total' }]; show(-1); } }
        return { update: update, reset: reset, isNavigating: function () { return stack.length > 1; } };
    }

    window.IfvValueCard = { mount: mount };
})();
