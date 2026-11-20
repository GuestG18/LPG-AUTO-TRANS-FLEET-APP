/*
 * MorphCard — navigarea interna a cardurilor financiare cu dimensiuni FIXE (o singura implementare, folosita de
 * cardurile KPI din Rentabilitate Operationala si de cardul „Valoare initiala (total)” din Istoric Financiar Vehicul).
 *
 * Cardul nu creste: in interiorul lui, o „scena” tine vederea curenta; la navigare vederea noua intra cu o
 * alunecare scurta + crossfade (inainte = din dreapta, inapoi = din stanga), cea veche iese si e scoasa.
 * Stiva de navigare si datele sunt ale consumatorului; aici sunt doar primitivele comune:
 *   swap(stage, html, dir)        → elementul vederii noi
 *   refill(listEl, html)          → inlocuieste continutul unei liste cu fade
 *   tween(el, from, to, fmt)      → valoarea „curge” spre noua valoare
 *   crumbs(parts)                 → firul de navigare (butoane data-goto="i", ultimul = nivelul curent)
 *   loadingHtml()                 → schelet pentru lista care se incarca
 * Stilurile comune: assets/css/morph-card.css (clase mc-*). Respecta prefers-reduced-motion.
 */
(function () {
    'use strict';

    if (window.MorphCard) { return; }

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var MS = 220;
    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // dir: 1 = inainte (din dreapta), -1 = inapoi (din stanga), 0 = pe loc (fara animatie)
    function swap(stage, html, dir, cls) {
        var view = document.createElement('div');
        view.className = 'mc-view' + (cls ? ' ' + cls : '');
        view.innerHTML = html;
        var old = stage.__mcView || null;
        stage.__mcView = view;
        if (!old || !old.parentNode || reduced || !dir) {
            stage.innerHTML = '';
            stage.appendChild(view);
            return view;
        }
        view.classList.add(dir > 0 ? 'mc-from-right' : 'mc-from-left');
        stage.appendChild(view);
        old.classList.add(dir > 0 ? 'mc-to-left' : 'mc-to-right');
        old.setAttribute('aria-hidden', 'true');
        old.inert = true;
        void view.offsetWidth;
        view.classList.remove('mc-from-right', 'mc-from-left');
        setTimeout(function () { if (old.parentNode) { old.parentNode.removeChild(old); } }, MS + 20);
        return view;
    }

    function refill(list, html, keepScroll) {
        var top = keepScroll ? list.scrollTop : 0;
        if (reduced) { list.innerHTML = html; list.scrollTop = top; return; }
        list.classList.add('mc-swapping');
        list.innerHTML = html;
        list.scrollTop = top;
        void list.offsetWidth;
        list.classList.remove('mc-swapping');
    }

    function tween(el, from, to, fmt) {
        if (!el) { return; }
        if (to === null || to === undefined) { el.textContent = '—'; return; }
        if (reduced || from === null || from === undefined || from === to) { el.textContent = fmt(to); return; }
        var t0 = null;
        var step = function (ts) {
            if (t0 === null) { t0 = ts; }
            var p = Math.min(1, (ts - t0) / MS), e = 1 - Math.pow(1 - p, 3);
            el.textContent = fmt(from + (to - from) * e);
            if (p < 1 && el.isConnected) { requestAnimationFrame(step); }
        };
        el.textContent = fmt(from);
        requestAnimationFrame(step);
    }

    // parts: [{label, goto}] — ultimul element e nivelul curent (fara buton)
    function crumbs(parts) {
        return parts.map(function (p, i) {
            return i === parts.length - 1
                ? '<span class="mc-crumb is-current">' + esc(p.label) + '</span>'
                : '<button type="button" class="mc-crumb" data-goto="' + p.goto + '">' + esc(p.label) + '</button>';
        }).join('<i class="bi bi-chevron-right"></i>');
    }

    function loadingHtml() { return '<div class="mc-loading"><span></span><span></span><span></span></div>'; }

    window.MorphCard = { swap: swap, refill: refill, tween: tween, crumbs: crumbs, loadingHtml: loadingHtml, esc: esc, reduced: reduced, MS: MS };
})();
