/*
 * Catalog echipamente — vederea pe siluetă.
 *
 *  - comută vederile (siluetă / categorii / tabel complet), memorat per browser;
 *  - Masculin / Feminin per siluetă: schimbă DOAR imaginea, nu cere date noi;
 *  - desenează liniile dintre casete și punctele de pe corp (recalculat la resize);
 *  - casetă/punct/categorie din meniu -> spațiul de lucru al categoriei, cu
 *    morfare din casetă (FLIP pe transform + opacitate) și înapoi la închidere.
 *
 * Meniurile „…”, formularul de articol și ștergerea rămân cele din
 * echipamente-soferi.js (delegare pe document).
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-dcx]');
    if (!root) {
        return;
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const data = (function () {
        try {
            return JSON.parse((document.getElementById('dcx-data') || {}).textContent || '{}');
        } catch (error) {
            return {};
        }
    })();
    const categories = data.categories || {};
    const items = data.items || [];
    const nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    const nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const money = function (value) { return nf2.format(value || 0) + ' lei'; };

    const store = {
        get: function (key) { try { return window.localStorage.getItem('dcx.' + key); } catch (e) { return null; } },
        set: function (key, value) { try { window.localStorage.setItem('dcx.' + key, value); } catch (e) { /* fără stocare */ } },
    };

    const panes = root.querySelectorAll('[data-dcx-pane]');
    const viewButtons = root.querySelectorAll('[data-dcx-view-btn]');
    const ws = root.querySelector('[data-dcx-ws]');
    let currentView = 'silueta';
    let openKey = null;
    let originEl = null;
    let wsRole = '';

    // ------------------------------------------------------------------
    // Vederi
    // ------------------------------------------------------------------

    function setView(view, remember) {
        currentView = view;
        root.setAttribute('data-dcx-view', view);
        viewButtons.forEach(function (button) {
            const active = button.getAttribute('data-dcx-view-btn') === view;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (openKey === null) {
            panes.forEach(function (pane) { pane.hidden = pane.getAttribute('data-dcx-pane') !== view; });
        }
        if (view === 'silueta') {
            requestAnimationFrame(drawAllLinks);
        }
    }

    viewButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (openKey !== null) {
                closeWorkspace(true);
            }
            setView(button.getAttribute('data-dcx-view-btn'), true);
        });
    });

    // ------------------------------------------------------------------
    // Masculin / Feminin
    // ------------------------------------------------------------------

    function setVariant(stage, variant) {
        const role = stage.getAttribute('data-dcx-stage');
        stage.setAttribute('data-variant', variant);
        stage.querySelectorAll('[data-dcx-variant]').forEach(function (button) {
            const active = button.getAttribute('data-dcx-variant') === variant;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        let src = '';
        stage.querySelectorAll('.dcx-figure-img').forEach(function (image) {
            const active = image.getAttribute('data-variant') === variant;
            image.classList.toggle('is-active', active);
            if (active) {
                src = image.currentSrc || image.src;
            }
        });
        // Punctele de pe corp: fiecare imagine își are propriile coordonate (anchors.json).
        stage.querySelectorAll('.dcx-dot').forEach(function (dot) {
            const at = (dot.getAttribute('data-at-' + variant) || '').split(',');
            if (at.length === 2) {
                dot.style.left = at[0] + '%';
                dot.style.top = at[1] + '%';
            }
        });
        window.setTimeout(function () { drawLinks(stage); }, reduceMotion.matches ? 0 : 380);
        // Miniaturile categoriilor urmează silueta aleasă.
        if (src) {
            root.style.setProperty('--dcx-img-' + role, 'url("' + src + '")');
        }
        store.set('variant.' + role, variant);
    }

    root.querySelectorAll('[data-dcx-stage]').forEach(function (stage) {
        // Și la varianta implicită: variabila CSS primește URL-ul absolut al imaginii
        // (un url() relativ dintr-o variabilă s-ar rezolva față de foaia de stil).
        const saved = store.get('variant.' + stage.getAttribute('data-dcx-stage'));
        setVariant(stage, saved === 'f' || saved === 'm' ? saved : (stage.getAttribute('data-default-variant') || 'm'));
        stage.querySelectorAll('[data-dcx-variant]').forEach(function (button) {
            button.addEventListener('click', function () {
                setVariant(stage, button.getAttribute('data-dcx-variant'));
            });
        });
    });

    // ------------------------------------------------------------------
    // Linii casetă -> punct pe corp
    // ------------------------------------------------------------------

    function drawLinks(stage) {
        const body = stage.querySelector('.dcx-stage-body');
        const svg = stage.querySelector('.dcx-links');
        if (!body || !svg || body.offsetParent === null) {
            return;
        }
        const box = body.getBoundingClientRect();
        svg.setAttribute('viewBox', '0 0 ' + box.width + ' ' + box.height);
        const parts = [];
        stage.querySelectorAll('.dcx-card[data-dcx-key]').forEach(function (card) {
            const key = card.getAttribute('data-dcx-key');
            const dot = stage.querySelector('.dcx-dot[data-dcx-key="' + key + '"]');
            if (!dot || getComputedStyle(card).position !== 'absolute') {
                return;
            }
            const c = card.getBoundingClientRect();
            const d = dot.getBoundingClientRect();
            const left = card.getAttribute('data-slot').charAt(0) === 'L';
            const x1 = (left ? c.right : c.left) - box.left;
            const y1 = c.top + c.height / 2 - box.top;
            const x2 = d.left + d.width / 2 - box.left;
            const y2 = d.top + d.height / 2 - box.top;
            // Linie dreaptă casetă -> punct, ca în machetă.
            parts.push('<path class="dcx-link" data-dcx-key="' + key + '" d="M' + x1.toFixed(1) + ',' + y1.toFixed(1)
                + ' L' + x2.toFixed(1) + ',' + y2.toFixed(1) + '"/>');
        });
        svg.innerHTML = parts.join('');
    }

    function drawAllLinks() {
        root.querySelectorAll('[data-dcx-stage]').forEach(drawLinks);
    }

    let resizeFrame = 0;
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(function () {
            cancelAnimationFrame(resizeFrame);
            resizeFrame = requestAnimationFrame(drawAllLinks);
        });
        root.querySelectorAll('.dcx-stage-body').forEach(function (body) { observer.observe(body); });
    } else {
        window.addEventListener('resize', function () {
            cancelAnimationFrame(resizeFrame);
            resizeFrame = requestAnimationFrame(drawAllLinks);
        });
    }

    // Evidențiere: casetă + linie + punct, în aceeași siluetă.
    function setHot(stage, key, on) {
        if (!stage || !key) {
            return;
        }
        stage.querySelectorAll('[data-dcx-key="' + key + '"]').forEach(function (node) {
            node.classList.toggle('is-hot', on);
        });
    }

    root.querySelectorAll('[data-dcx-stage]').forEach(function (stage) {
        ['pointerover', 'focusin'].forEach(function (type) {
            stage.addEventListener(type, function (event) {
                const node = event.target.closest('[data-dcx-key]');
                if (node) {
                    setHot(stage, node.getAttribute('data-dcx-key'), true);
                }
            });
        });
        ['pointerout', 'focusout'].forEach(function (type) {
            stage.addEventListener(type, function (event) {
                const node = event.target.closest('[data-dcx-key]');
                if (node && !(event.relatedTarget && node.contains(event.relatedTarget))) {
                    setHot(stage, node.getAttribute('data-dcx-key'), false);
                }
            });
        });
    });

    // ------------------------------------------------------------------
    // Spațiul de lucru al categoriei
    // ------------------------------------------------------------------

    const rows = ws ? Array.prototype.slice.call(ws.querySelectorAll('.dcx-row')) : [];
    const details = ws ? Array.prototype.slice.call(ws.querySelectorAll('.dcx-row-detail')) : [];

    function matches(node, key, role) {
        const dest = node.getAttribute('data-dest');
        return (key === '*' || node.getAttribute('data-cat') === key)
            && (role === '' || dest === 'ambele' || dest === role);
    }

    function refreshWorkspace() {
        if (!ws || openKey === null) {
            return;
        }
        let visible = 0;
        rows.forEach(function (row) {
            const show = matches(row, openKey, wsRole);
            row.hidden = !show;
            visible += show ? 1 : 0;
        });
        details.forEach(function (detail) {
            const row = ws.querySelector('[data-dcx-row="' + detail.id + '"]');
            detail.hidden = !(row && !row.hidden && row.classList.contains('is-open'));
        });
        ws.querySelector('[data-dcx-ws-empty]').hidden = visible > 0;

        const totals = { articole: 0, stoc: 0, alocat: 0, valoare: 0 };
        items.forEach(function (item) {
            const fake = { getAttribute: function (name) { return name === 'data-dest' ? item.dest : item.cat; } };
            if (matches(fake, openKey, wsRole)) {
                totals.articole += 1;
                totals.stoc += item.stoc;
                totals.alocat += item.alocat;
                totals.valoare += item.valoare;
            }
        });
        ws.querySelectorAll('[data-dcx-kpi]').forEach(function (node) {
            const key = node.getAttribute('data-dcx-kpi');
            node.textContent = key === 'valoare' ? money(totals[key]) : nf0.format(totals[key]);
        });
    }

    function fillHeader(key) {
        const category = categories[key];
        const name = key === '*' ? 'Toate categoriile' : (category ? category.name : key);
        ws.querySelector('[data-dcx-ws-name]').textContent = name;
        const forWhom = !category ? 'șoferi și TESA'
            : (category.roles.length === 2 ? 'șoferi și TESA' : (category.roles[0] === 'tesa' ? 'TESA' : 'șoferi'));
        ws.querySelector('[data-dcx-ws-desc]').textContent = key === '*'
            ? 'Toate articolele din catalog, cu stocul și alocările lor.'
            : 'Articole de ' + name.toLowerCase() + ' pentru ' + forWhom + '. Valoarea este a stocului disponibil.';

        // Miniatura: copia celei din meniu (silueta mărită pe zona categoriei).
        const thumbHolder = ws.querySelector('[data-dcx-ws-thumb]');
        const navSel = '.dcx-nav-cat[data-dcx-open="' + key + '"] ';
        const source = root.querySelector(navSel + '.dcx-thumb, ' + navSel + '.dcx-nav-ico');
        thumbHolder.innerHTML = '';
        if (source) {
            const clone = source.cloneNode(true);
            if (clone.classList.contains('dcx-nav-ico')) {
                clone.className = 'dcx-thumb is-icon';
            }
            thumbHolder.appendChild(clone);
        }

        const stockLink = ws.querySelector('[data-dcx-link="stoc"]');
        const allocLink = ws.querySelector('[data-dcx-link="alocari"]');
        if (stockLink && data.stockUrl) {
            stockLink.href = key === '*' || !category ? data.stockUrl : data.stockUrl + '&categorie=' + encodeURIComponent(category.name);
        }
        if (allocLink && data.allocUrl) {
            allocLink.href = key === '*' || !category ? data.allocUrl : data.allocUrl + '&q=' + encodeURIComponent(category.name);
        }

        root.querySelectorAll('.dcx-nav-cat').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-dcx-open') === key);
        });
        root.querySelectorAll('.dcx-card[data-dcx-key]').forEach(function (card) {
            card.classList.toggle('is-active', card.getAttribute('data-dcx-key') === key);
        });
    }

    function setWsRole(role) {
        wsRole = role;
        ws.querySelectorAll('[data-dcx-ws-role]').forEach(function (button) {
            const active = button.getAttribute('data-dcx-ws-role') === role;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        refreshWorkspace();
    }

    /** Animă ws între dreptunghiul casetei și poziția lui finală. */
    function morph(fromRect, reverse) {
        const to = ws.getBoundingClientRect();
        if (!fromRect || reduceMotion.matches || !ws.animate || to.width === 0) {
            return Promise.resolve();
        }
        const dx = fromRect.left - to.left;
        const dy = fromRect.top - to.top;
        const sx = Math.max(fromRect.width / to.width, 0.05);
        const sy = Math.max(fromRect.height / to.height, 0.05);
        const start = { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + sx + ',' + sy + ')', opacity: 0.55, borderRadius: '16px' };
        const end = { transform: 'none', opacity: 1, borderRadius: '22px' };
        ws.classList.add('is-morphing');
        const animation = ws.animate(reverse ? [end, start] : [start, end], {
            duration: reverse ? 260 : 380,
            easing: 'cubic-bezier(.22,.8,.24,1)',
        });
        return animation.finished.catch(function () {}).then(function () {
            ws.classList.remove('is-morphing');
        });
    }

    function openWorkspace(key, origin, role) {
        if (!ws) {
            return;
        }
        const fromRect = origin ? origin.getBoundingClientRect() : null;
        const wasOpen = openKey !== null;
        openKey = key;
        originEl = origin && origin.closest('.dcx-pane') ? origin : null;
        fillHeader(key);
        setWsRole(role || '');

        // Fundalul dispare complet: nicio coloană din vederea anterioară nu rămâne vizibilă.
        panes.forEach(function (pane) { pane.hidden = true; });
        ws.hidden = false;
        ws.querySelector('[data-dcx-ws-scroll]').scrollTop = 0;

        const top = root.getBoundingClientRect().top + window.scrollY - 16;
        if (window.scrollY > top) {
            window.scrollTo({ top: top, behavior: reduceMotion.matches ? 'auto' : 'smooth' });
        }
        if (!wasOpen) {
            morph(fromRect, false);
        }
        ws.focus({ preventScroll: true });
    }

    function closeWorkspace(immediate) {
        if (!ws || openKey === null) {
            return;
        }
        const target = originEl;
        const finish = function () {
            ws.hidden = true;
            openKey = null;
            originEl = null;
            root.querySelectorAll('.dcx-nav-cat.is-active, .dcx-card.is-active').forEach(function (node) { node.classList.remove('is-active'); });
            panes.forEach(function (pane) { pane.hidden = pane.getAttribute('data-dcx-pane') !== currentView; });
            if (currentView === 'silueta') {
                drawAllLinks();
            }
            if (target && !immediate) {
                target.focus({ preventScroll: true });
            }
        };
        if (immediate || !target) {
            finish();
            return;
        }
        // Morfare inversă spre caseta din care a pornit (poziția ei din layout-ul ascuns).
        panes.forEach(function (pane) {
            if (pane.contains(target)) {
                pane.hidden = false;
                pane.style.position = 'absolute';
                pane.style.visibility = 'hidden';
                pane.style.width = '100%';
            }
        });
        const rect = target.getBoundingClientRect();
        panes.forEach(function (pane) {
            pane.style.position = '';
            pane.style.visibility = '';
            pane.style.width = '';
            pane.hidden = true;
        });
        morph(rect, true).then(finish);
    }

    root.addEventListener('click', function (event) {
        const opener = event.target.closest('[data-dcx-open]');
        if (opener && root.contains(opener)) {
            const key = opener.getAttribute('data-dcx-open');
            // Un punct de pe corp morfează din caseta lui, nu din punct.
            let origin = opener;
            if (opener.classList.contains('dcx-dot')) {
                const stage = opener.closest('[data-dcx-stage]');
                origin = stage.querySelector('.dcx-card[data-dcx-key="' + key + '"]') || opener;
            }
            const stageEl = opener.closest('[data-dcx-stage]');
            const role = opener.getAttribute('data-dcx-role') || (stageEl ? stageEl.getAttribute('data-dcx-stage') : '');
            openWorkspace(key, origin, role);
            return;
        }
        if (event.target.closest('[data-dcx-close]')) {
            closeWorkspace(false);
            return;
        }
        const roleButton = event.target.closest('[data-dcx-ws-role]');
        if (roleButton) {
            setWsRole(roleButton.getAttribute('data-dcx-ws-role'));
            return;
        }

        // Rândurile din spațiul de lucru: detaliile de stoc / alocări.
        const row = event.target.closest('.dcx-row');
        if (row && (event.target.closest('[data-dcx-row-toggle]') || !event.target.closest('[data-des-stop], a, button, input, select, label'))) {
            toggleRow(row);
        }

    });

    // „Editează” din spațiul de lucru refolosește formularul din tabelul complet.
    // Pe document: meniul „…” este mutat în <body> cât timp e deschis.
    document.addEventListener('click', function (event) {
        const editRef = event.target.closest('[data-des-catalog-edit-ref]');
        if (!editRef) {
            return;
        }
        const source = root.querySelector('[data-des-catalog-edit*="\\"id\\":' + editRef.getAttribute('data-des-catalog-edit-ref') + ',"]');
        if (source) {
            source.click();
        }
    });

    function toggleRow(row, force) {
        const detail = document.getElementById(row.getAttribute('data-dcx-row'));
        if (!detail) {
            return;
        }
        const open = typeof force === 'boolean' ? force : detail.hidden;
        detail.hidden = !open;
        row.classList.toggle('is-open', open);
        row.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    root.addEventListener('keydown', function (event) {
        const row = event.target.closest && event.target.closest('.dcx-row');
        if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            toggleRow(row);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openKey !== null && !document.querySelector('.modal.show, .des-floating-menu.show')) {
            closeWorkspace(false);
        }
    });

    // ------------------------------------------------------------------
    // Pornire
    // ------------------------------------------------------------------

    // Filtre: fiecare schimbare reîncarcă lista filtrată pe server.
    const filterForm = document.querySelector('[data-dcx-filter-form]');
    if (filterForm) {
        filterForm.querySelectorAll('select').forEach(function (select) {
            select.addEventListener('change', function () { filterForm.submit(); });
        });
    }

    // Galeria: clic pe un punct de pe siluetă -> produsele categoriei, în aceeași
    // zonă (caseta categoriei continuă să deschidă spațiul de lucru).
    // Eticheta (caseta categoriei) sau punctul de pe siluetă -> caruselul cu
    // articolele categoriei. Din carusel: „Vezi în tabel” (spațiul de lucru cu
    // rândul articolului deschis) și „Editează” (formularul existent).
    function openInTable(key, itemId) {
        const opener = root.querySelector('.dcx-nav-cat[data-dcx-open="' + key + '"]');
        if (!opener) {
            return;
        }
        opener.click();
        window.setTimeout(function () {
            const row = root.querySelector('.dcx-row[data-dcx-row="dcx-ws-panel-' + itemId + '"]');
            if (!row) {
                return;
            }
            if (row.getAttribute('aria-expanded') !== 'true') {
                row.click();
            }
            row.classList.add('is-flash');
            window.setTimeout(function () { row.classList.remove('is-flash'); }, 1600);
            row.scrollIntoView({ block: 'center', behavior: reduceMotion.matches ? 'auto' : 'smooth' });
        }, reduceMotion.matches ? 0 : 420);
    }

    root.addEventListener('click', function (event) {
        const trigger = event.target.closest('.dcx-stage .dcx-card[data-dcx-key], .dcx-dot[data-dcx-gallery]');
        if (!trigger || !window.EquipmentGallery) {
            return;
        }
        const stage = trigger.closest('[data-dcx-stage]');
        const body = stage ? stage.querySelector('.dcx-stage-body') : null;
        const key = trigger.getAttribute('data-dcx-gallery') || trigger.getAttribute('data-dcx-key');
        if (body && window.EquipmentGallery.open(body, key, {
            role: stage.getAttribute('data-dcx-stage'),
            returnFocus: trigger,
            actions: [
                { key: 'tabel', label: 'Vezi în tabel', icon: 'bi-table', primary: true },
                {
                    key: 'edit', label: 'Editează', icon: 'bi-pencil',
                    when: function (item) { return !!document.querySelector('[data-des-catalog-edit-ref="' + item.id + '"]'); },
                },
            ],
            onAction: function (action, item) {
                if (action === 'tabel') {
                    openInTable(key, item.id);
                } else if (action === 'edit') {
                    const edit = document.querySelector('[data-des-catalog-edit-ref="' + item.id + '"]');
                    if (edit) {
                        edit.click();
                    }
                }
            },
        })) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    // Catalogul se deschide mereu pe siluetă.
    setView('silueta', false);
    window.addEventListener('load', drawAllLinks);
    drawAllLinks();
})();
