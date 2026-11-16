/**
 * Tablou de bord configurabil (?page=dashboard).
 *
 *  - Cardurile „lazy” (ex. Km pierduți, GPS live) se cer la ?page=dashboard&action=widget doar
 *    când intră în ecran; fragmentul e chiar componenta paginii-sursă, iar modulul ei JS
 *    (dispatcher-insights.js / dispatcher-live.js) pornește singur pe el. Cardurile ascunse nu
 *    există în pagină, deci nu fac nicio cerere.
 *  - Meniul ⋯ al fiecărui card: dimensiune, mutare, ascundere, pagina-sursă.
 *  - „Adaugă card”: inserare rapidă a unui card ascuns.
 *  - „Configurare panou” (offcanvas Bootstrap): căutare, grupare pe modul, bife, ordine prin
 *    drag & drop sau săgeți, dimensiune, Aplică / Implicit.
 *  - Mod „Pagini” (carusel, implicit): cardurile se împart în pagini cât încap pe ecran, sub filtre;
 *    se trece de la una la alta cu săgețile din stânga / dreapta, punctele de sub filtre, tastele
 *    ← / → sau swipe. Filtrele rămân la vedere și reîncarcă cardurile paginii. Mod „Toate”: grila
 *    completă, cu scroll. Alegerea se salvează cu layout-ul.
 * Layout-ul se salvează per utilizator (POST save_layout); serverul păstrează doar cardurile
 * permise. Cifrele cardurilor rămân cele din modulele-sursă (dashboard-live.js le reîmprospătează).
 */
(function () {
    'use strict';

    var configEl = document.querySelector('[data-dash-config]');
    var grid = document.querySelector('[data-dash-grid]');
    if (!configEl || !grid) { return; }
    var cfg;
    try { cfg = JSON.parse(configEl.textContent || '{}'); } catch (e) { return; }

    var catalog = Array.isArray(cfg.catalog) ? cfg.catalog : [];
    var byId = {};
    catalog.forEach(function (w) { byId[w.id] = w; });
    var layout = (Array.isArray(cfg.layout) ? cfg.layout : []).filter(function (item) { return byId[item.id]; });
    var sizeLabels = cfg.sizeLabels || {};
    var view = cfg.view === 'grid' ? 'grid' : 'carousel';

    function esc(value) {
        var d = document.createElement('div');
        d.textContent = value == null ? '' : String(value);
        return d.innerHTML;
    }
    function layoutIds(list) { return list.map(function (i) { return i.id; }); }
    /** Copie a unei intrări de layout, cu setările cardului (ex. șoferul ales). */
    function cloneItem(i) {
        var copy = { id: i.id, size: i.size };
        if (i.config) { copy.config = JSON.parse(JSON.stringify(i.config)); }
        return copy;
    }
    function itemFor(id) {
        for (var k = 0; k < layout.length; k++) { if (layout[k].id === id) { return layout[k]; } }
        return null;
    }
    /** Setările salvate ale cardului, ca parametri ai cererii (driver_ids[]=…). */
    function configQuery(id) {
        var item = itemFor(id);
        var out = '';
        if (item && item.config) {
            Object.keys(item.config).forEach(function (key) {
                var value = item.config[key];
                if (Array.isArray(value)) {
                    value.forEach(function (v) { out += '&' + key + '[]=' + encodeURIComponent(v); });
                } else if (value !== null && value !== '') {
                    out += '&' + key + '=' + encodeURIComponent(value);
                }
            });
        }
        return out;
    }
    function sameSet(a, b) {
        if (a.length !== b.length) { return false; }
        var sa = a.slice().sort().join('|');
        return sa === b.slice().sort().join('|');
    }

    // ------------------------------------------------------------------ salvare
    var saving = false;
    function save(payload) {
        if (saving) { return Promise.reject(new Error('busy')); }
        saving = true;
        payload.csrf = cfg.csrf;
        if (!payload.view) { payload.view = view; }
        if (!payload.items && !payload.reset) { payload.items = layout.map(cloneItem); }
        return fetch(cfg.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false }; }).then(function (data) {
                saving = false;
                if (!r.ok || !data.ok) { throw new Error(data.error || 'Configurația nu a putut fi salvată.'); }
                return data;
            });
        }, function (err) { saving = false; throw err; });
    }

    /**
     * Aplică un layout nou: dacă setul de carduri e același, doar reordonează / redimensionează
     * pe loc; altfel reîncarcă pagina (cardurile noi trebuie calculate, cele scoase se opresc).
     */
    function applyLayout(next) {
        var previous = layoutIds(layout);
        return save({ items: next }).then(function (data) {
            var saved = Array.isArray(data.items) ? data.items : next;
            if (!sameSet(previous, layoutIds(saved))) {
                window.location.reload();
                return;
            }
            layout = saved;
            saved.forEach(function (item) {
                var el = grid.querySelector('[data-dash-widget="' + cssEscape(item.id) + '"]');
                if (!el) { return; }
                setSize(el, item.size);
                grid.appendChild(el);
            });
            notifyResize();
            carousel.repack();
        });
    }

    function cssEscape(value) {
        return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/"/g, '\\"');
    }
    function setSize(el, size) {
        el.className = el.className.replace(/\bdash-size-\w+\b/g, '').trim() + ' dash-size-' + size;
        el.setAttribute('data-dash-size', size);
    }
    function notifyResize() {
        // componentele-sursă își adaptează conținutul la lățime (ResizeObserver / resize)
        try { window.dispatchEvent(new Event('resize')); } catch (e) { /* vechi */ }
    }
    function flash(message, isError) {
        var toast = document.createElement('div');
        toast.className = 'dash-toast' + (isError ? ' is-error' : '');
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function () { toast.classList.add('is-out'); }, 2600);
        setTimeout(function () { toast.remove(); }, 3100);
    }

    // ------------------------------------------------------------------ carduri lazy
    var loadedScripts = {};
    document.querySelectorAll('script[src]').forEach(function (s) { loadedScripts[s.src.split('?')[0]] = true; });

    function loadCss(href) {
        var base = href.split('?')[0];
        var exists = Array.prototype.some.call(document.querySelectorAll('link[rel="stylesheet"]'), function (l) {
            return l.href.split('?')[0] === new URL(base, window.location.href).href;
        });
        if (exists) { return; }
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }
    function loadScript(src) {
        var key = new URL(src.split('?')[0], window.location.href).href;
        if (loadedScripts[key]) { return Promise.resolve(); }
        loadedScripts[key] = true;
        return new Promise(function (resolve) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = resolve;
            s.onerror = resolve;
            document.body.appendChild(s);
        });
    }

    // Înălțimea ultimei încărcări a fiecărui card lazy (doar în acest browser): scheletul o ia
    // de la început, ca paginile caruselului să nu se mute când sosesc datele.
    var HEIGHT_KEY = 'fleet.dashboard.cardHeights.v1';
    function readHeights() {
        try { return JSON.parse(localStorage.getItem(HEIGHT_KEY) || '{}') || {}; } catch (e) { return {}; }
    }
    function rememberHeight(id, px) {
        try {
            var all = readHeights();
            all[id] = Math.round(px);
            localStorage.setItem(HEIGHT_KEY, JSON.stringify(all));
        } catch (e) { /* stocare indisponibila: doar fara estimare */ }
    }
    (function applyRememberedHeights() {
        var known = readHeights();
        grid.querySelectorAll('[data-dash-lazy]').forEach(function (body) {
            var h = known[body.getAttribute('data-dash-lazy')];
            var skeleton = body.querySelector('.dash-widget-skeleton');
            if (skeleton && h > 0) { skeleton.style.minHeight = Math.min(h, 2400) + 'px'; }
        });
    })();

    function loadLazy(body) {
        if (body.getAttribute('data-dash-loading') === '1') { return; }
        body.setAttribute('data-dash-loading', '1');
        var id = body.getAttribute('data-dash-lazy');
        var query = filterQuery(byId[id]) + configQuery(id);
        body.__dashQuery = query;
        var url = cfg.widgetUrl + '&id=' + encodeURIComponent(id) + query;
        if (body.__dashAbort) { body.__dashAbort.abort(); }
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        body.__dashAbort = controller;
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined })
            .then(function (r) { return r.json().then(function (data) { if (!r.ok || !data.ok) { throw new Error(data.error || 'Eroare'); } return data; }); })
            .then(function (data) {
                (data.css || []).forEach(loadCss);
                body.innerHTML = data.html;
                body.removeAttribute('aria-busy');
                body.classList.remove('is-refreshing');
                carousel.schedule();

                body.classList.add('is-loaded');
                // scripturile pornesc după ce fragmentul e în pagină, în ordine
                return (data.js || []).reduce(function (chain, src) {
                    return chain.then(function () { return loadScript(src); });
                }, Promise.resolve());
            })
            .catch(function (err) {
                body.removeAttribute('data-dash-loading');
                if (err && err.name === 'AbortError') { return; }
                body.innerHTML = '<div class="dash-widget-error" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> '
                    + esc(err && err.message ? err.message : 'Cardul nu a putut fi încărcat.')
                    + ' <button type="button" class="btn btn-sm btn-outline-secondary" data-dash-retry>Reîncearcă</button></div>';
            });
    }

    /** Filtrele globale (din URL-ul curent, ținut la zi de dashboard-live.js) pe care cardul le acceptă. */
    function filterQuery(widget) {
        var accepted = widget && Array.isArray(widget.filters) ? widget.filters : [];
        if (!accepted.length) { return ''; }
        var current = new URLSearchParams(window.location.search);
        var keys = [];
        if (accepted.indexOf('period') !== -1) { keys.push('period', 'date_from', 'date_to'); }
        if (accepted.indexOf('vehicle_category') !== -1) { keys.push('vehicle_category'); }
        // Categoria însoțește vehiculul doar când e ales unul (serverul validează vehiculul pe categorie).
        if (accepted.indexOf('vehicle_id') !== -1 && current.get('vehicle_id')) { keys.push('vehicle_id', 'vehicle_category'); }
        var out = '';
        keys.filter(function (k, i) { return keys.indexOf(k) === i; }).forEach(function (k) {
            var v = current.get(k);
            // URL-ul fără perioadă = luna curentă (implicitul serverului): aceeași cerere, fără reîncărcare inutilă.
            if (k === 'period' && !v) { v = 'luna_curenta'; }
            if (v !== null && v !== '') { out += '&' + k + '=' + encodeURIComponent(v); }
        });
        return out;
    }

    // După ce dashboard-live.js a aplicat filtrele noi: cardurile lazy care folosesc filtre și
    // sunt deja încărcate se recer (cererea veche se anulează); celelalte nu se ating.
    document.addEventListener('dashboard:refreshed', function () {
        grid.querySelectorAll('[data-dash-lazy]').forEach(function (body) {
            var widget = byId[body.getAttribute('data-dash-lazy')];
            if (!widget || !widget.filters.length || !body.classList.contains('is-loaded') && body.getAttribute('data-dash-loading') !== '1') { return; }
            // Doar dacă s-au schimbat filtrele pe care cardul chiar le folosește.
            if (body.__dashQuery === filterQuery(widget) + configQuery(widget.id)) { return; }
            // Cardurile de pe alte pagini ale caruselului se reîncarcă abia când pagina lor se deschide.
            if (body.closest('.dash-widget.is-offpage')) { body.setAttribute('data-dash-stale', '1'); return; }
            body.removeAttribute('data-dash-loading');
            body.classList.add('is-refreshing');
            loadLazy(body);
        });
    });

    var lazyBodies = Array.prototype.slice.call(grid.querySelectorAll('[data-dash-lazy]'));
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    io.unobserve(entry.target);
                    loadLazy(entry.target);
                }
            });
        }, { rootMargin: '200px 0px' });
        lazyBodies.forEach(function (b) { io.observe(b); });
    } else {
        lazyBodies.forEach(loadLazy);
    }
    grid.addEventListener('click', function (event) {
        var retry = event.target.closest('[data-dash-retry]');
        if (retry) { loadLazy(retry.closest('[data-dash-lazy]')); }
    });

    // ------------------------------------------------------------------ filtrele din card
    // Fiecare card își poate avea filtrele paginii-sursă (ex. Activitate șoferi: șoferi, interval, tip
    // transport, beneficiar, vehicul). Se salvează în config-ul cardului (layout-ul utilizatorului),
    // serverul le validează cu regulile paginii, apoi se reîncarcă doar acel card.
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function monthBounds(ym) {
        var p = ym.split('-');
        var y = Number(p[0]);
        var m = Number(p[1]);
        var last = new Date(y, m, 0).getDate();
        return [ym + '-01', ym + '-' + pad(last)];
    }
    function shiftMonth(ym, delta) {
        var p = ym.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1 + delta, 1);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1);
    }
    /**
     * Config-ul cardului din formularul lui: toate câmpurile cu nume (bife „cheie[]” -> listă,
     * restul -> valoare), doar cele completate. Tipurile le validează serverul.
     */
    function readCardFilters(form) {
        var config = {};
        Array.prototype.forEach.call(form.querySelectorAll('[name]'), function (el) {
            var name = el.getAttribute('name');
            if (/\[\]$/.test(name)) {
                var key = name.slice(0, -2);
                if (el.checked) { (config[key] = config[key] || []).push(el.value); }
                return;
            }
            var value = (el.value || '').trim();
            if (value !== '') { config[name] = value; }
        });
        return config;
    }
    function applyCardFilters(form, config) {
        var id = form.getAttribute('data-widget');
        var body = form.closest('[data-dash-lazy]');
        var next = layout.map(cloneItem);
        next.forEach(function (item) {
            if (item.id !== id) { return; }
            if (config && Object.keys(config).length) { item.config = config; } else { delete item.config; }
        });
        form.classList.add('is-busy');
        applyLayout(next).then(function () {
            if (body) {
                body.removeAttribute('data-dash-loading');
                body.classList.add('is-refreshing');
                loadLazy(body);
            }
        }).catch(function (err) {
            form.classList.remove('is-busy');
            if (err.message !== 'busy') { flash(err.message, true); }
        });
    }
    function setDateFields(form, from, to) {
        form.querySelector('[name="date_from"]').value = from || '';
        form.querySelector('[name="date_to"]').value = to || '';
    }

    grid.addEventListener('change', function (event) {
        var form = event.target.closest('[data-dash-card-filters]');
        if (!form) { return; }
        var t = event.target;
        if (t.closest('[data-dash-dd]')) { return; } // listele cu bife se aplică la „Aplică” / închidere
        if (t.matches('[data-dash-date-mode]')) {
            var custom = form.querySelector('[data-dash-date-custom]');
            if (t.value === 'custom') { custom.hidden = false; return; } // așteaptă ambele date
            custom.hidden = true;
            if (t.value) { var b = monthBounds(t.value); setDateFields(form, b[0], b[1]); } else { setDateFields(form, '', ''); }
        } else if (t.matches('[data-dash-date-from], [data-dash-date-to]')) {
            var from = form.querySelector('[data-dash-date-from]').value;
            var to = form.querySelector('[data-dash-date-to]').value;
            if (!from || !to) { return; }
            setDateFields(form, from <= to ? from : to, from <= to ? to : from);
        }
        applyCardFilters(form, readCardFilters(form));
    });

    grid.addEventListener('click', function (event) {
        var form = event.target.closest('[data-dash-card-filters]');
        if (!form) { return; }
        // Câmpul „lună” (ex. luna de facturare): ‹ = luna anterioară, › = următoarea, dacă există în listă.
        var selStep = event.target.closest('[data-dash-select-step]');
        if (selStep) {
            var monthSel = selStep.parentNode.querySelector('select');
            var cur = /^\d{4}-\d{2}$/.test(monthSel.value) ? monthSel.value : (form.getAttribute('data-period-from') || '').slice(0, 7);
            if (!cur) { return; }
            var target = shiftMonth(cur, Number(selStep.getAttribute('data-dash-select-step')) > 0 ? -1 : 1);
            var exists = Array.prototype.some.call(monthSel.options, function (o) { return o.value === target; });
            if (!exists) { flash('Luna ' + target + ' nu are date în această pagină.', true); return; }
            monthSel.value = target;
            applyCardFilters(form, readCardFilters(form));
            return;
        }
        var step = event.target.closest('[data-dash-month-step]');
        if (step) {
            var sel = form.querySelector('[data-dash-date-mode]');
            // ‹ › pornesc de la luna afișată acum: luna aleasă, începutul intervalului sau perioada panoului.
            var base = /^\d{4}-\d{2}$/.test(sel.value) ? sel.value
                : (form.querySelector('[name="date_from"]').value || form.getAttribute('data-period-from') || new Date().toISOString().slice(0, 10)).slice(0, 7);
            var ym = shiftMonth(base, Number(step.getAttribute('data-dash-month-step')));
            var b = monthBounds(ym);
            setDateFields(form, b[0], b[1]);
            applyCardFilters(form, readCardFilters(form));
            return;
        }
        if (event.target.closest('[data-dash-card-reset]')) {
            applyCardFilters(form, null);
        }
    });

    // Lista de șoferi: meniu plutitor (fixed), ca să nu fie tăiat de card; se aplică la „Aplică” / închidere.
    var openDd = null;
    // Meniul deschis se mută în <body>: cardurile au transform la hover, iar un părinte cu transform
    // ar face position: fixed relativ la card (meniul ar fi tăiat și ar derula conținutul cardului).
    function ddMenu(dd) { return dd.__menu || dd.querySelector('[data-dash-dd-menu]'); }
    function ddChecked(dd) {
        return Array.prototype.map.call(ddMenu(dd).querySelectorAll('input[type="checkbox"]:checked'), function (i) { return i.value; });
    }
    function positionDd(dd) {
        var toggle = dd.querySelector('[data-dash-dd-toggle]');
        var menu = ddMenu(dd);
        var r = toggle.getBoundingClientRect();
        var width = Math.max(r.width, 280);
        menu.style.position = 'fixed';
        menu.style.width = width + 'px';
        menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8)) + 'px';
        var below = window.innerHeight - r.bottom - 12;
        menu.style.maxHeight = Math.max(220, Math.min(420, below > 240 ? below : r.top - 12)) + 'px';
        menu.style.top = (below > 240 ? r.bottom + 4 : Math.max(8, r.top - 4 - parseFloat(menu.style.maxHeight))) + 'px';
    }
    function ddCount(dd) {
        var n = ddChecked(dd).length;
        var el = ddMenu(dd).querySelector('[data-dash-dd-count]');
        if (el) { el.textContent = n ? n + ' alese' : 'toate'; }
    }
    function closeDd(apply) {
        if (!openDd) { return; }
        var dd = openDd;
        openDd = null;
        var menu = ddMenu(dd);
        menu.hidden = true;
        menu.removeAttribute('style');
        dd.appendChild(menu); // înapoi în formular, ca bifele să fie citite cu restul filtrelor
        dd.__menu = null;
        dd.querySelector('[data-dash-dd-toggle]').setAttribute('aria-expanded', 'false');
        var form = dd.closest('[data-dash-card-filters]');
        var before = dd.getAttribute('data-initial') || '';
        var now = ddChecked(dd).join(',');
        if (apply && form && before !== now) { applyCardFilters(form, readCardFilters(form)); }
    }
    grid.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-dash-dd-toggle]');
        if (toggle) {
            var dd = toggle.closest('[data-dash-dd]');
            if (openDd === dd) { closeDd(true); return; }
            closeDd(true);
            openDd = dd;
            dd.setAttribute('data-initial', ddChecked(dd).join(','));
            var menu = dd.querySelector('[data-dash-dd-menu]');
            dd.__menu = menu;
            document.body.appendChild(menu);
            menu.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            positionDd(dd);
            ddCount(dd);
            var search = menu.querySelector('[data-dash-dd-search]');
            if (search) { search.focus({ preventScroll: true }); }
        }
    });
    // Evenimentele meniului (aflat în <body> cât e deschis).
    document.addEventListener('click', function (event) {
        if (!openDd || !openDd.__menu || !openDd.__menu.contains(event.target)) { return; }
        if (event.target.closest('[data-dash-dd-apply]')) { closeDd(true); return; }
        if (event.target.closest('[data-dash-dd-clear]')) {
            openDd.__menu.querySelectorAll('input[type="checkbox"]').forEach(function (i) { i.checked = false; });
            closeDd(true);
        }
    });
    document.addEventListener('input', function (event) {
        if (!openDd || !openDd.__menu || !openDd.__menu.contains(event.target)) { return; }
        var search = event.target.closest('[data-dash-dd-search]');
        if (search) {
            var q = search.value.trim().toLowerCase();
            openDd.__menu.querySelectorAll('.dash-dd-option').forEach(function (o) {
                o.hidden = q !== '' && (o.getAttribute('data-name') || '').indexOf(q) === -1;
            });
        }
    });
    document.addEventListener('change', function (event) {
        if (openDd && openDd.__menu && openDd.__menu.contains(event.target)) { ddCount(openDd); }
    });
    document.addEventListener('mousedown', function (event) {
        if (openDd && !openDd.contains(event.target) && !(openDd.__menu && openDd.__menu.contains(event.target))) { closeDd(true); }
    });
    document.addEventListener('keydown', function (event) {
        if (openDd && event.key === 'Escape') { closeDd(false); }
    });
    window.addEventListener('resize', function () { if (openDd) { positionDd(openDd); } });
    document.addEventListener('scroll', function (event) {
        if (openDd && !(openDd.__menu && openDd.__menu.contains(event.target))) { positionDd(openDd); }
    }, true);

    // ------------------------------------------------------------------ carusel (mod „Pagini”)
    var carousel = (function () {
        var stage = document.querySelector('[data-dash-stage]');
        var pager = document.querySelector('[data-dash-pager]');
        var dotsBox = pager ? pager.querySelector('[data-dash-dots]') : null;
        var label = pager ? pager.querySelector('[data-dash-page-label]') : null;
        var arrows = stage ? Array.prototype.slice.call(stage.querySelectorAll('.dash-arrow')) : [];
        var scroller = document.querySelector('.dashboard-page');
        var pages = [];
        var current = 0;
        var anchorId = null;
        var timer = null;
        var GAP = 16;

        function widgets() {
            return Array.prototype.filter.call(grid.children, function (el) { return el.classList.contains('dash-widget'); });
        }
        function titleOf(el) {
            var w = byId[el.getAttribute('data-dash-widget')];
            return w ? w.title : '';
        }
        /** Cât loc vertical rămâne sub filtre (măsurat cu pagina derulată sus). */
        function availableHeight() {
            var top = grid.getBoundingClientRect().top + (scroller ? scroller.scrollTop : 0) + (window.scrollY || 0);
            var free = window.innerHeight - top - 24;
            // Pe ecrane mici filtrele ocupă aproape tot: atunci o pagină = un ecran (filtrele rămân deasupra, la scroll).
            return free < 360 ? Math.max(320, window.innerHeight - 150) : free;
        }
        /**
         * Rândurile grilei (cardurile cu același top) se adună pe pagini cât încap în înălțimea
         * disponibilă; un rând mai înalt decât ecranul are pagina lui. Toate cardurile se arată doar
         * pe durata măsurării, în același pas de execuție (browserul nu desenează între timp).
         */
        function measure() {
            var all = widgets();
            all.forEach(function (el) { el.classList.remove('is-offpage'); });
            var rows = [];
            var known = null;
            all.forEach(function (el) {
                // Cardurile lazy deja încărcate: se reține înălțimea lor reală (estimarea de la următoarea deschidere).
                var lazy = el.querySelector('[data-dash-lazy].is-loaded');
                if (lazy && lazy.offsetHeight > 0) {
                    known = known || readHeights();
                    var lid = lazy.getAttribute('data-dash-lazy');
                    if (Math.abs((known[lid] || 0) - lazy.offsetHeight) > 8) { known[lid] = lazy.offsetHeight; rememberHeight(lid, lazy.offsetHeight); }
                }
                var top = el.offsetTop;
                var bottom = top + el.offsetHeight;
                var row = rows.length ? rows[rows.length - 1] : null;
                if (row && Math.abs(row.top - top) < 4) {
                    row.items.push(el);
                    row.bottom = Math.max(row.bottom, bottom);
                } else {
                    rows.push({ top: top, bottom: bottom, items: [el] });
                }
            });
            var avail = availableHeight();
            var out = [];
            var page = null;
            rows.forEach(function (row) {
                var h = row.bottom - row.top;
                if (page && page.height + GAP + h <= avail) {
                    page.items = page.items.concat(row.items);
                    page.height += GAP + h;
                } else {
                    page = { items: row.items.slice(), height: h };
                    out.push(page);
                }
            });
            return out.map(function (pg) { return pg.items; });
        }

        function render(direction) {
            var all = widgets();
            if (view !== 'carousel' || pages.length === 0) {
                all.forEach(function (el) { el.classList.remove('is-offpage'); });
                if (pager) { pager.hidden = true; }
                arrows.forEach(function (a) { a.hidden = true; });
                if (stage) { stage.classList.remove('is-carousel'); }
                return;
            }
            var visible = pages[current] || [];
            all.forEach(function (el) { el.classList.toggle('is-offpage', visible.indexOf(el) === -1); });
            visible.forEach(function (el) {
                var stale = el.querySelector('[data-dash-lazy][data-dash-stale]');
                if (stale) {
                    stale.removeAttribute('data-dash-stale');
                    stale.removeAttribute('data-dash-loading');
                    stale.classList.add('is-refreshing');
                    loadLazy(stale);
                }
            });
            anchorId = visible.length ? visible[0].getAttribute('data-dash-widget') : null;
            var multi = pages.length > 1;
            if (stage) { stage.classList.add('is-carousel'); }
            if (pager) { pager.hidden = !multi; }
            arrows.forEach(function (a) {
                a.hidden = !multi;
                var step = Number(a.getAttribute('data-dash-step'));
                a.disabled = step < 0 ? current === 0 : current === pages.length - 1;
            });
            if (pager) {
                pager.querySelectorAll('.dash-pager-step').forEach(function (b) {
                    var step = Number(b.getAttribute('data-dash-step'));
                    b.disabled = step < 0 ? current === 0 : current === pages.length - 1;
                });
            }
            if (dotsBox) {
                dotsBox.innerHTML = pages.map(function (pg, i) {
                    var names = pg.map(titleOf).join(', ');
                    return '<button type="button" class="dash-dot' + (i === current ? ' is-active' : '') + '" role="tab" aria-selected="' + (i === current) + '"'
                        + ' data-dash-page="' + i + '" title="' + esc(names) + '" aria-label="Pagina ' + (i + 1) + ': ' + esc(names) + '"></button>';
                }).join('');
            }
            if (label) {
                label.innerHTML = '<strong>' + (current + 1) + ' / ' + pages.length + '</strong> <span>' + esc(visible.map(titleOf).join(' · ')) + '</span>';
            }
            if (direction) {
                grid.classList.remove('is-slide-next', 'is-slide-prev');
                void grid.offsetWidth; // repornește animația
                grid.classList.add(direction > 0 ? 'is-slide-next' : 'is-slide-prev');
            }
            notifyResize();
        }

        /** Recalculează paginile; pagina curentă = cea care conține cardul-ancoră (primul card văzut). */
        function relocate() {
            pages = measure();
            current = 0;
            if (anchorId) {
                pages.some(function (pg, i) {
                    if (pg.some(function (el) { return el.getAttribute('data-dash-widget') === anchorId; })) { current = i; return true; }
                    return false;
                });
            }
        }
        function repack() {
            if (view !== 'carousel') { render(0); return; }
            relocate();
            render(0);
        }
        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(repack, 120);
        }
        function go(index, direction) {
            if (view !== 'carousel' || !pages.length) { return; }
            var target = Math.max(0, Math.min(pages.length - 1, index));
            if (target === current) { render(0); return; }
            var dir = direction || (target > current ? 1 : -1);
            current = target;
            render(dir);
        }
        // Înălțimile se pot fi schimbat între timp (carduri încărcate, fețe întoarse): întâi se refac paginile.
        function step(delta) {
            if (view !== 'carousel') { return; }
            relocate();
            go(current + delta, delta);
        }
        function setView(next) {
            view = next === 'grid' ? 'grid' : 'carousel';
            document.querySelectorAll('[data-dash-view]').forEach(function (b) {
                var on = b.getAttribute('data-dash-view') === view;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            document.body.classList.toggle('dash-mode-carousel', view === 'carousel');
            // În modul „Pagini” filtrele stau pe un singur rând (mai mult loc pentru carduri).
            var root = document.querySelector('[data-dashboard-live-root]');
            if (root) { root.classList.toggle('is-compact-filters', view === 'carousel'); }
            repack();
        }

        document.addEventListener('click', function (event) {
            var stepBtn = event.target.closest('[data-dash-step]');
            if (stepBtn) { step(Number(stepBtn.getAttribute('data-dash-step'))); return; }
            var dot = event.target.closest('[data-dash-page]');
            if (dot) { var i = Number(dot.getAttribute('data-dash-page')); go(i, i > current ? 1 : -1); return; }
            var viewBtn = event.target.closest('[data-dash-view]');
            if (viewBtn) {
                var next = viewBtn.getAttribute('data-dash-view');
                if (next === view) { return; }
                setView(next);
                save({ view: view }).catch(function (err) { if (err.message !== 'busy') { flash(err.message, true); } });
            }
        });
        // ← / → schimbă pagina, dacă nu scrii într-un câmp și nu e deschis un meniu / panou.
        document.addEventListener('keydown', function (event) {
            if (view !== 'carousel' || event.altKey || event.ctrlKey || event.metaKey) { return; }
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') { return; }
            var t = event.target;
            if (t && (t.closest('input, select, textarea, [contenteditable="true"], .dash-menu, .offcanvas.show, .modal.show, [role="menu"], [role="slider"]'))) { return; }
            if (document.querySelector('.offcanvas.show, .modal.show, .dash-menu, .dash-dd-menu:not([hidden])')) { return; }
            event.preventDefault();
            step(event.key === 'ArrowRight' ? 1 : -1);
        });
        // Swipe orizontal pe telefon / tabletă.
        var touch = null;
        if (stage) {
            stage.addEventListener('touchstart', function (e) {
                if (e.touches.length === 1) { touch = { x: e.touches[0].clientX, y: e.touches[0].clientY }; }
            }, { passive: true });
            stage.addEventListener('touchend', function (e) {
                if (!touch || view !== 'carousel') { touch = null; return; }
                var dx = e.changedTouches[0].clientX - touch.x;
                var dy = e.changedTouches[0].clientY - touch.y;
                touch = null;
                if (Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 1.6) { step(dx < 0 ? 1 : -1); }
            }, { passive: true });
        }
        var resizeTimer = null;
        window.addEventListener('resize', function (e) {
            if (e && e.isTrusted === false) { return; } // propriul notifyResize nu reface paginile
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(repack, 150);
        });
        // Cardurile își schimbă înălțimea (fețe întoarse, liste încărcate): paginile se refac, ancorat.
        if ('ResizeObserver' in window) {
            var ro = new ResizeObserver(function () { if (view === 'carousel') { schedule(); } });
            widgets().forEach(function (el) { ro.observe(el); });
        }
        document.addEventListener('dashboard:refreshed', schedule);

        return { repack: repack, schedule: schedule, setView: setView };
    })();

    // ------------------------------------------------------------------ meniuri plutitoare
    // Meniurile sunt poziționate fixed pe body: nu le taie overflow-ul cardurilor.
    var openMenu = null;
    function closeMenu(restoreFocus) {
        if (!openMenu) { return; }
        var trigger = openMenu.trigger;
        openMenu.el.remove();
        trigger.setAttribute('aria-expanded', 'false');
        openMenu = null;
        if (restoreFocus) { trigger.focus(); }
    }
    function showMenu(trigger, html, onClick) {
        closeMenu(false);
        var el = document.createElement('div');
        el.className = 'dash-menu';
        el.setAttribute('role', 'menu');
        el.innerHTML = html;
        document.body.appendChild(el);
        var rect = trigger.getBoundingClientRect();
        var width = el.offsetWidth;
        var height = el.offsetHeight;
        var left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
        var top = rect.bottom + 6;
        if (top + height > window.innerHeight - 8 && rect.top - height - 6 > 8) { top = rect.top - height - 6; }
        el.style.left = left + 'px';
        el.style.top = Math.max(8, Math.min(top, window.innerHeight - height - 8)) + 'px';
        if (height > window.innerHeight - 16) { el.style.maxHeight = (window.innerHeight - 16) + 'px'; }
        trigger.setAttribute('aria-expanded', 'true');
        openMenu = { el: el, trigger: trigger };
        el.addEventListener('click', function (event) {
            var item = event.target.closest('[data-menu-action]');
            if (!item || item.disabled) { return; }
            onClick(item);
        });
        var first = el.querySelector('[role="menuitem"]:not([disabled]), [role="menuitemradio"]');
        if (first) { first.focus(); }
    }
    document.addEventListener('click', function (event) {
        if (openMenu && !openMenu.el.contains(event.target) && !openMenu.trigger.contains(event.target)) { closeMenu(false); }
    });
    document.addEventListener('keydown', function (event) {
        if (!openMenu) { return; }
        if (event.key === 'Escape') { closeMenu(true); return; }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            var items = Array.prototype.slice.call(openMenu.el.querySelectorAll('button:not([disabled]), a[role="menuitem"]'));
            var index = items.indexOf(document.activeElement);
            var next = items[(index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length];
            if (next) { event.preventDefault(); next.focus(); }
        }
    });
    window.addEventListener('resize', function () { closeMenu(false); });
    window.addEventListener('scroll', function () { closeMenu(false); }, true);

    // ⋯ pe card
    grid.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-dash-menu]');
        if (!trigger) { return; }
        if (openMenu && openMenu.trigger === trigger) { closeMenu(false); return; }
        var id = trigger.getAttribute('data-dash-menu');
        var widget = byId[id];
        var index = layoutIds(layout).indexOf(id);
        if (!widget || index === -1) { return; }
        var current = layout[index].size;
        var html = '';
        if (widget.sizes.length > 1) {
            html += '<div class="dash-menu-label">Dimensiune</div>';
            widget.sizes.forEach(function (size) {
                html += '<button type="button" role="menuitemradio" aria-checked="' + (size === current) + '" data-menu-action="size" data-size="' + size + '">'
                    + '<i class="bi ' + (size === current ? 'bi-check2' : 'bi-dot') + '" aria-hidden="true"></i>' + esc(sizeLabels[size] || size) + '</button>';
            });
            html += '<hr>';
        }
        html += '<button type="button" role="menuitem" data-menu-action="up"' + (index === 0 ? ' disabled' : '') + '><i class="bi bi-arrow-left-short" aria-hidden="true"></i>Mută mai devreme</button>'
            + '<button type="button" role="menuitem" data-menu-action="down"' + (index === layout.length - 1 ? ' disabled' : '') + '><i class="bi bi-arrow-right-short" aria-hidden="true"></i>Mută mai târziu</button>'
            + '<a role="menuitem" class="dash-menu-link" href="' + esc(trigger.getAttribute('data-source-url') || '#') + '"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Deschide pagina sursă</a>'
            + '<hr><button type="button" role="menuitem" class="is-danger" data-menu-action="hide"><i class="bi bi-eye-slash" aria-hidden="true"></i>Ascunde cardul</button>';
        showMenu(trigger, html, function (item) {
            var action = item.getAttribute('data-menu-action');
            var next = layout.map(cloneItem);
            if (action === 'size') {
                next[index].size = item.getAttribute('data-size');
            } else if (action === 'up' && index > 0) {
                next.splice(index - 1, 0, next.splice(index, 1)[0]);
            } else if (action === 'down' && index < next.length - 1) {
                next.splice(index + 1, 0, next.splice(index, 1)[0]);
            } else if (action === 'hide') {
                next.splice(index, 1);
            }
            closeMenu(true);
            applyLayout(next).catch(function (err) { flash(err.message, true); });
        });
    });

    // „Adaugă card”
    var addToggle = document.querySelector('[data-dash-add-toggle]');
    if (addToggle) {
        addToggle.addEventListener('click', function () {
            if (openMenu && openMenu.trigger === addToggle) { closeMenu(false); return; }
            var active = layoutIds(layout);
            var hidden = catalog.filter(function (w) { return active.indexOf(w.id) === -1; });
            var html = '';
            if (!hidden.length) {
                html = '<div class="dash-menu-empty">Toate cardurile disponibile sunt deja pe panou.</div>';
            } else {
                var lastModule = null;
                hidden.forEach(function (w) {
                    if (w.module !== lastModule) {
                        html += '<div class="dash-menu-label">' + esc(w.module) + '</div>';
                        lastModule = w.module;
                    }
                    html += '<button type="button" role="menuitem" data-menu-action="add" data-id="' + esc(w.id) + '">'
                        + '<i class="bi ' + esc(w.icon) + '" aria-hidden="true"></i><span><strong>' + esc(w.title) + '</strong><small>' + esc(w.time_label) + '</small></span></button>';
                });
            }
            showMenu(addToggle, html, function (item) {
                var next = layout.map(cloneItem);
                var w = byId[item.getAttribute('data-id')];
                next.push({ id: w.id, size: w.default_size });
                closeMenu(false);
                applyLayout(next).catch(function (err) { flash(err.message, true); });
            });
        });
    }

    carousel.setView(view);

    // ------------------------------------------------------------------ drawer configurare
    var drawer = document.querySelector('[data-dash-drawer]');
    var openBtn = document.querySelector('[data-dash-config-open]');
    if (!drawer || !openBtn) { return; }
    var orderList = drawer.querySelector('[data-dash-order]');
    var orderEmpty = drawer.querySelector('[data-dash-order-empty]');
    var catalogBox = drawer.querySelector('[data-dash-catalog]');
    var catalogEmpty = drawer.querySelector('[data-dash-catalog-empty]');
    var searchInput = drawer.querySelector('[data-dash-search]');
    var statusEl = drawer.querySelector('[data-dash-status]');
    var draft = [];

    function matchesSearch(w) {
        var q = (searchInput.value || '').trim().toLowerCase();
        if (!q) { return true; }
        return (w.title + ' ' + w.module + ' ' + w.description).toLowerCase().indexOf(q) !== -1;
    }

    function renderDrawer() {
        var ids = layoutIds(draft);
        orderList.innerHTML = draft.map(function (item, index) {
            var w = byId[item.id];
            var sizes = w.sizes.length > 1
                ? '<select class="form-select form-select-sm" data-draft-size="' + index + '" aria-label="Dimensiune ' + esc(w.title) + '">'
                    + w.sizes.map(function (s) { return '<option value="' + s + '"' + (s === item.size ? ' selected' : '') + '>' + esc(sizeLabels[s] || s) + '</option>'; }).join('')
                    + '</select>'
                : '<span class="dash-order-size">' + esc(sizeLabels[item.size] || item.size) + '</span>';
            return '<li class="dash-order-item" draggable="true" data-draft-index="' + index + '">'
                + '<span class="dash-order-grip" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>'
                + '<span class="dash-order-text"><strong>' + esc(w.title) + '</strong><small>' + esc(w.module) + ' · ' + esc(w.time_label) + '</small></span>'
                + sizes
                + '<span class="dash-order-moves">'
                + '<button type="button" class="dash-icon-btn" data-draft-move="-1" data-index="' + index + '"' + (index === 0 ? ' disabled' : '') + ' aria-label="Mută ' + esc(w.title) + ' mai sus"><i class="bi bi-arrow-up"></i></button>'
                + '<button type="button" class="dash-icon-btn" data-draft-move="1" data-index="' + index + '"' + (index === draft.length - 1 ? ' disabled' : '') + ' aria-label="Mută ' + esc(w.title) + ' mai jos"><i class="bi bi-arrow-down"></i></button>'
                + '<button type="button" class="dash-icon-btn" data-draft-remove="' + index + '" aria-label="Scoate ' + esc(w.title) + ' de pe panou"><i class="bi bi-x-lg"></i></button>'
                + '</span></li>';
        }).join('');
        orderEmpty.hidden = draft.length > 0;

        var groups = {};
        var order = [];
        catalog.forEach(function (w) {
            if (!matchesSearch(w)) { return; }
            if (!groups[w.module]) { groups[w.module] = []; order.push(w.module); }
            groups[w.module].push(w);
        });
        catalogBox.innerHTML = order.map(function (module) {
            return '<fieldset class="dash-catalog-group"><legend>' + esc(module) + '</legend>'
                + groups[module].map(function (w) {
                    var on = ids.indexOf(w.id) !== -1;
                    return '<label class="dash-catalog-item' + (on ? ' is-on' : '') + '">'
                        + '<input type="checkbox" class="form-check-input" data-draft-toggle="' + esc(w.id) + '"' + (on ? ' checked' : '') + '>'
                        + '<i class="bi ' + esc(w.icon) + '" aria-hidden="true"></i>'
                        + '<span><strong>' + esc(w.title) + '</strong><small>' + esc(w.description) + '</small>'
                        + '<em class="dash-time-chip is-' + esc(w.time) + '">' + esc(w.time_label) + '</em>'
                        + (on ? '' : '<em class="dash-hidden-chip">ascuns</em>')
                        + '</span></label>';
                }).join('') + '</fieldset>';
        }).join('');
        catalogEmpty.hidden = order.length > 0;
    }

    function openDrawer() {
        draft = layout.map(cloneItem);
        searchInput.value = '';
        statusEl.textContent = '';
        renderDrawer();
        if (window.bootstrap && bootstrap.Offcanvas) {
            bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
        }
    }
    openBtn.addEventListener('click', openDrawer);
    searchInput.addEventListener('input', renderDrawer);

    drawer.addEventListener('change', function (event) {
        var t = event.target;
        if (t.matches('[data-draft-toggle]')) {
            var id = t.getAttribute('data-draft-toggle');
            var at = layoutIds(draft).indexOf(id);
            if (t.checked && at === -1) { draft.push({ id: id, size: byId[id].default_size }); }
            if (!t.checked && at !== -1) { draft.splice(at, 1); }
            renderDrawer();
        } else if (t.matches('[data-draft-size]')) {
            draft[Number(t.getAttribute('data-draft-size'))].size = t.value;
        }
    });
    drawer.addEventListener('click', function (event) {
        var move = event.target.closest('[data-draft-move]');
        var remove = event.target.closest('[data-draft-remove]');
        if (move) {
            var i = Number(move.getAttribute('data-index'));
            var j = i + Number(move.getAttribute('data-draft-move'));
            if (j >= 0 && j < draft.length) {
                draft.splice(j, 0, draft.splice(i, 1)[0]);
                renderDrawer();
                var again = orderList.querySelector('[data-draft-move="' + move.getAttribute('data-draft-move') + '"][data-index="' + j + '"]');
                (again && !again.disabled ? again : orderList.querySelector('[data-index="' + j + '"]')).focus();
            }
        } else if (remove) {
            draft.splice(Number(remove.getAttribute('data-draft-remove')), 1);
            renderDrawer();
        }
    });

    // drag & drop nativ pe lista de ordine
    var dragIndex = null;
    orderList.addEventListener('dragstart', function (event) {
        var li = event.target.closest('[data-draft-index]');
        if (!li) { return; }
        dragIndex = Number(li.getAttribute('data-draft-index'));
        li.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        try { event.dataTransfer.setData('text/plain', String(dragIndex)); } catch (e) { /* IE */ }
    });
    orderList.addEventListener('dragover', function (event) {
        if (dragIndex === null) { return; }
        event.preventDefault();
        var li = event.target.closest('[data-draft-index]');
        orderList.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (el) { el.classList.remove('is-drop-before', 'is-drop-after'); });
        if (li) {
            var r = li.getBoundingClientRect();
            li.classList.add(event.clientY < r.top + r.height / 2 ? 'is-drop-before' : 'is-drop-after');
        }
    });
    orderList.addEventListener('drop', function (event) {
        if (dragIndex === null) { return; }
        event.preventDefault();
        var li = event.target.closest('[data-draft-index]');
        if (li) {
            var target = Number(li.getAttribute('data-draft-index'));
            var r = li.getBoundingClientRect();
            if (event.clientY >= r.top + r.height / 2) { target += 1; }
            var item = draft.splice(dragIndex, 1)[0];
            if (target > dragIndex) { target -= 1; }
            draft.splice(target, 0, item);
        }
        dragIndex = null;
        renderDrawer();
    });
    orderList.addEventListener('dragend', function () { dragIndex = null; renderDrawer(); });

    drawer.querySelector('[data-dash-apply]').addEventListener('click', function () {
        statusEl.textContent = 'Se salvează…';
        applyLayout(draft).then(function () {
            statusEl.textContent = '';
            if (window.bootstrap && bootstrap.Offcanvas) { bootstrap.Offcanvas.getOrCreateInstance(drawer).hide(); }
            flash('Panoul a fost salvat.');
        }).catch(function (err) { statusEl.textContent = err.message; });
    });
    drawer.querySelector('[data-dash-reset]').addEventListener('click', function () {
        statusEl.textContent = 'Se revine la implicit…';
        save({ reset: true }).then(function () { window.location.reload(); })
            .catch(function (err) { statusEl.textContent = err.message; });
    });
})();
