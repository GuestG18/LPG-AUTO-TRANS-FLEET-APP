/*
 * Fișa „Detalii șofer” (views/module/_driver_detail.php). Doar interacțiuni de prezentare:
 * - caruselul secțiunilor (o singură secțiune vizibilă, glisare orizontală, tastatură, swipe);
 * - panoul automat de documente (aceeași logică și aceleași atribute data-status-* ca înainte în show.php);
 * - căutarea în documente;
 * - schema vehiculului = exploratorul paginii Auto (vehicle-xray.js), încărcat leneș, o singură instanță,
 *   compactă în antet și mutată într-un dialog pentru detalii;
 * - figura de echipamente (aceleași imagini / puncte ca în Echipamente șoferi), inițializată la prima deschidere.
 * Singura scriere: mărimile vestimentare (action=driver_sizes_save), restul e doar citire.
 */
(function () {
    'use strict';

    var page = document.querySelector('[data-driver-detail]');
    if (!page) { return; }

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var storage = {
        get: function (key) { try { return window.localStorage.getItem(key); } catch (error) { return null; } },
        set: function (key, value) { try { window.localStorage.setItem(key, value); } catch (error) { /* doar o preferință */ } }
    };

    /* ------------------------------------------------------------------ carusel */
    var tabs = Array.prototype.slice.call(page.querySelectorAll('[data-dd-tab]'));
    var panels = {};
    page.querySelectorAll('[data-dd-panel]').forEach(function (panel) { panels[panel.dataset.ddPanel] = panel; });
    var order = tabs.map(function (tab) { return tab.dataset.ddTab; });
    var viewport = page.querySelector('[data-dd-viewport]');
    var track = page.querySelector('[data-dd-track]');
    var prevBtn = page.querySelector('[data-dd-prev]');
    var nextBtn = page.querySelector('[data-dd-next]');
    var tabsWrap = page.querySelector('[data-dd-tabs]');
    var current = null;
    var activateHooks = {};

    var syncHeight = function () {
        var panel = panels[current];
        if (panel) { viewport.style.height = panel.offsetHeight + 'px'; }
    };
    var panelObserver = window.ResizeObserver ? new window.ResizeObserver(syncHeight) : null;

    var activate = function (key, options) {
        options = options || {};
        if (!panels[key]) { return; }
        var index = order.indexOf(key);
        var previous = current;
        current = key;
        tabs.forEach(function (tab) {
            var on = tab.dataset.ddTab === key;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
        });
        Object.keys(panels).forEach(function (name) {
            var on = name === key;
            panels[name].classList.toggle('is-active', on);
            panels[name].setAttribute('aria-hidden', on ? 'false' : 'true');
            if ('inert' in panels[name]) { panels[name].inert = !on; }
        });
        track.style.transform = 'translateX(' + (-index * 100) + '%)';
        if (panelObserver) {
            panelObserver.disconnect();
            panelObserver.observe(panels[key]);
        }
        syncHeight();
        prevBtn.disabled = index <= 0;
        nextBtn.disabled = index >= order.length - 1;

        var tab = tabs[index];
        if (tab && tabsWrap.scrollWidth > tabsWrap.clientWidth) {
            tab.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reducedMotion || !previous ? 'auto' : 'smooth' });
        }
        if (options.focus && tab) { tab.focus(); }
        if (activateHooks[key]) { activateHooks[key](); }
        if (previous !== null && previous !== key && window.history && window.history.replaceState) {
            var params = new URLSearchParams(window.location.search);
            params.set('tab', key);
            window.history.replaceState(window.history.state, '', window.location.pathname + '?' + params.toString() + window.location.hash);
        }
    };
    var step = function (delta, focus) {
        var index = order.indexOf(current) + delta;
        if (index >= 0 && index < order.length) { activate(order[index], { focus: focus }); }
    };

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { activate(tab.dataset.ddTab); });
    });
    tabsWrap.addEventListener('keydown', function (event) {
        var map = { ArrowRight: 1, ArrowLeft: -1 };
        if (map[event.key]) { event.preventDefault(); step(map[event.key], true); return; }
        if (event.key === 'Home') { event.preventDefault(); activate(order[0], { focus: true }); }
        if (event.key === 'End') { event.preventDefault(); activate(order[order.length - 1], { focus: true }); }
    });
    prevBtn.addEventListener('click', function () { step(-1); });
    nextBtn.addEventListener('click', function () { step(1); });

    // Swipe orizontal pe conținut (touch); listele cu derulare proprie nu sunt afectate pe verticală.
    var touch = null;
    viewport.addEventListener('touchstart', function (event) {
        if (event.touches.length !== 1) { touch = null; return; }
        touch = { x: event.touches[0].clientX, y: event.touches[0].clientY };
    }, { passive: true });
    viewport.addEventListener('touchend', function (event) {
        if (!touch || !event.changedTouches.length) { return; }
        var dx = event.changedTouches[0].clientX - touch.x;
        var dy = event.changedTouches[0].clientY - touch.y;
        touch = null;
        if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) { step(dx < 0 ? 1 : -1); }
    }, { passive: true });

    // Scurtături din antet (număr vehicule, insigna de status, KPI documente).
    page.addEventListener('click', function (event) {
        var go = event.target.closest('[data-dd-goto]');
        if (!go) { return; }
        activate(go.dataset.ddGoto);
        var filter = go.dataset.ddStatusFilter;
        if (filter) {
            var button = panels.status && panels.status.querySelector('[data-status-doc-filter="' + filter + '"]');
            if (button) { button.click(); }
        }
        if (viewport.getBoundingClientRect().top < 0 || viewport.getBoundingClientRect().top > window.innerHeight * 0.7) {
            page.querySelector('[data-dd-nav]').scrollIntoView({ block: 'start', behavior: reducedMotion ? 'auto' : 'smooth' });
        }
    });
    window.addEventListener('resize', syncHeight);

    /* ------------------------------------------------- panoul automat de documente */
    // Aceeași logică ca blocul inline mutat din show.php (acordeon: un singur rând deschis).
    (function () {
        var boardEl = page.querySelector('[data-status-document-board]');
        if (!boardEl) { return; }
        var groupsEl = boardEl.querySelector('[data-status-doc-groups]');
        if (!groupsEl) { return; }

        var syncGroupHeight = function (groupEl) {
            var bodyEl = groupEl.querySelector('[data-status-doc-group-body]');
            if (bodyEl) { bodyEl.style.setProperty('--status-group-body-height', groupEl.classList.contains('is-open') ? bodyEl.scrollHeight + 'px' : '0px'); }
        };
        var syncBoardHeights = function () { boardEl.querySelectorAll('[data-status-doc-group]').forEach(syncGroupHeight); };
        var closeItem = function (itemEl) {
            var rowEl = itemEl.querySelector('[data-status-doc-row]');
            var detailEl = itemEl.querySelector('[data-status-doc-detail]');
            if (!detailEl) { return; }
            itemEl.classList.remove('is-expanded');
            detailEl.style.setProperty('--status-doc-detail-height', '0px');
            if (rowEl) { rowEl.setAttribute('aria-expanded', 'false'); }
            window.setTimeout(function () { if (!itemEl.classList.contains('is-expanded')) { detailEl.hidden = true; } }, 190);
        };
        var openItem = function (itemEl) {
            var rowEl = itemEl.querySelector('[data-status-doc-row]');
            var detailEl = itemEl.querySelector('[data-status-doc-detail]');
            if (!detailEl) { return; }
            boardEl.querySelectorAll('[data-status-doc-item].is-expanded').forEach(function (other) { if (other !== itemEl) { closeItem(other); } });
            detailEl.hidden = false;
            window.requestAnimationFrame(function () {
                itemEl.classList.add('is-expanded');
                detailEl.style.setProperty('--status-doc-detail-height', detailEl.scrollHeight + 'px');
                if (rowEl) { rowEl.setAttribute('aria-expanded', 'true'); }
                window.requestAnimationFrame(syncBoardHeights);
            });
        };

        boardEl.querySelectorAll('[data-status-doc-filter]').forEach(function (buttonEl) {
            buttonEl.addEventListener('click', function () {
                var value = buttonEl.getAttribute('data-status-doc-filter') === 'all' ? 'all' : 'problems';
                groupsEl.setAttribute('data-filter', value);
                boardEl.querySelectorAll('[data-status-doc-filter]').forEach(function (other) {
                    other.classList.toggle('is-active', other === buttonEl);
                    other.setAttribute('aria-pressed', other === buttonEl ? 'true' : 'false');
                });
                // „Toate documentele” deschide și grupul celor valabile, ca să fie vizibile.
                if (value === 'all') {
                    var validGroup = boardEl.querySelector('[data-status-doc-group="valid"]');
                    if (validGroup && !validGroup.classList.contains('is-open') && buttonEl.dataset.ddAutoOpen !== '0') {
                        validGroup.classList.add('is-open');
                        var toggle = validGroup.querySelector('[data-status-doc-group-toggle]');
                        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
                    }
                }
                window.requestAnimationFrame(syncBoardHeights);
            });
        });
        boardEl.querySelectorAll('[data-status-doc-group-toggle]').forEach(function (toggleEl) {
            toggleEl.addEventListener('click', function () {
                var groupEl = toggleEl.closest('[data-status-doc-group]');
                if (!groupEl) { return; }
                groupEl.classList.toggle('is-open');
                toggleEl.setAttribute('aria-expanded', groupEl.classList.contains('is-open') ? 'true' : 'false');
                syncGroupHeight(groupEl);
            });
        });
        boardEl.querySelectorAll('[data-status-doc-row]').forEach(function (rowEl) {
            rowEl.addEventListener('click', function () {
                var itemEl = rowEl.closest('[data-status-doc-item]');
                if (!itemEl) { return; }
                if (itemEl.classList.contains('is-expanded')) {
                    closeItem(itemEl);
                    window.requestAnimationFrame(syncBoardHeights);
                    return;
                }
                openItem(itemEl);
            });
        });
        syncBoardHeights();
        window.addEventListener('resize', syncBoardHeights);
        activateHooks.status = function () { window.requestAnimationFrame(syncBoardHeights); };
    })();

    /* ------------------------------------------------------- căutare documente */
    (function () {
        var input = page.querySelector('[data-dd-doc-search]');
        if (!input) { return; }
        var rows = Array.prototype.slice.call(page.querySelectorAll('[data-dd-doc-row]'));
        var empty = page.querySelector('[data-dd-doc-noresults]');
        var normalize = function (value) {
            return String(value || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
        };
        input.addEventListener('input', function () {
            var terms = normalize(input.value).split(' ').filter(Boolean);
            var shown = 0;
            rows.forEach(function (row) {
                var hay = row.dataset.search || '';
                var match = terms.every(function (term) { return hay.indexOf(term) !== -1; });
                row.hidden = !match;
                if (match) { shown++; }
            });
            if (empty) { empty.hidden = shown !== 0; }
        });
    })();

    /* --------------------------------------------- schema vehiculului (pagina Auto) */
    (function () {
        var dataEl = document.getElementById('ddVehicleData');
        var holder = page.querySelector('[data-dd-xray]');
        if (!dataEl || !holder) { return; }
        var data = {};
        try { data = JSON.parse(dataEl.textContent || '{}'); } catch (error) { window.console.error(error); return; }

        var slot = page.querySelector('[data-dd-xray-slot]');
        var emptyEl = page.querySelector('[data-dd-xray-empty]');
        var emptyText = page.querySelector('[data-dd-xray-empty-text]');
        var openBtn = page.querySelector('[data-dd-xray-open]');
        var dialog = document.querySelector('[data-dd-xray-dialog]');
        var dialogBody = dialog ? dialog.querySelector('[data-dd-xray-dialog-body]') : null;
        var dialogTitle = dialog ? dialog.querySelector('[data-dd-xray-dialog-title]') : null;
        var iconEl = holder.querySelector('[data-dd-unit-icon]');
        var vehicles = data.vehicles || [];
        var byId = {};
        vehicles.forEach(function (vehicle) { byId[vehicle.id] = vehicle; });
        var units = {};
        if (data.unit && data.unitVehicleId) { units[data.unitVehicleId] = data.unit; }
        var selectedId = vehicles.length ? vehicles[0].id : 0;
        var currentUnit = null;
        var explorer = null;
        var assetsPromise = null;
        var lastFocus = null;

        var loadAssets = function () {
            if (assetsPromise) { return assetsPromise; }
            assetsPromise = new Promise(function (resolve, reject) {
                if (data.css && !document.querySelector('link[data-dd-xray-css]')) {
                    var link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = data.css;
                    link.setAttribute('data-dd-xray-css', '');
                    document.head.appendChild(link);
                }
                if (window.FleetXray) { resolve(); return; }
                var scripts = data.scripts || [];
                var remaining = scripts.length;
                if (!remaining) { reject(new Error('Fără scripturi')); return; }
                scripts.forEach(function (src) {
                    var script = document.createElement('script');
                    script.src = src;
                    script.async = false; // execuție în ordine (geometrie → desen → explorator)
                    script.onload = function () { remaining--; if (remaining === 0) { resolve(); } };
                    script.onerror = function () { reject(new Error('Nu s-a putut încărca ' + src)); };
                    document.body.appendChild(script);
                });
            });

            return assetsPromise;
        };

        var showEmpty = function (text) {
            holder.hidden = true;
            openBtn.hidden = true;
            emptyEl.hidden = false;
            emptyText.textContent = text;
        };

        var mount = function (compact) {
            if (!currentUnit || !window.FleetXray) { return; }
            // Rădăcină nouă la fiecare montare: ascultătorii instanței vechi rămân pe nodul eliberat.
            var oldRoot = holder.querySelector('[data-fx-explorer]');
            var root = document.createElement('div');
            root.className = 'fx-explorer';
            root.setAttribute('data-fx-explorer', '');
            oldRoot.replaceWith(root);
            var panel = holder.querySelector('[data-fx-panel]');
            panel.innerHTML = '';
            panel.hidden = true;
            holder.classList.toggle('is-compact', compact);
            holder.dataset.ftUnit = currentUnit.key || '';
            holder.dataset.kind = currentUnit.kind || '';
            if (iconEl) { iconEl.innerHTML = (data.unitIcons || {})[currentUnit.kind] || ''; }
            explorer = window.FleetXray.mount(holder, currentUnit, {
                onTire: function (positionId) { if (explorer) { explorer.markTire(String(positionId)); } },
                tireLink: null
            });
        };

        var renderFacts = function (vehicle) {
            var set = function (name, value) {
                var el = page.querySelector('[data-dd-fact="' + name + '"]');
                if (el) { el.textContent = value; }
            };
            set('plate', vehicle.plate);
            set('make', vehicle.make || vehicle.type_label);
            set('type', vehicle.type_label + (vehicle.formula ? ' · ' + vehicle.formula : ''));
            set('partner', vehicle.partner ? '+ ' + vehicle.partner.plate : '');
            var partnerRow = page.querySelector('[data-dd-fact-partner-row]');
            if (partnerRow) { partnerRow.hidden = !vehicle.partner; }
            var icon = page.querySelector('[data-dd-fact-icon]');
            if (icon) { icon.className = 'bi ' + vehicle.icon; }
            var role = page.querySelector('[data-dd-vehicle-role]');
            if (role) { role.textContent = vehicle.is_primary ? '(principal)' : '(secundar)'; }
            var status = page.querySelector('[data-dd-vehicle-status]');
            if (status) {
                status.textContent = vehicle.status_label;
                status.className = 'dd-vstatus ' + (vehicle.status === 'activ' ? 'is-active' : 'is-inactive');
            }
            page.querySelectorAll('[data-dd-select-vehicle]').forEach(function (button) {
                if (!button.classList.contains('dd-vswitch')) { return; }
                var on = Number(button.dataset.ddSelectVehicle) === vehicle.id;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            page.querySelectorAll('[data-dd-vcard]').forEach(function (card) {
                card.classList.toggle('is-selected', Number(card.dataset.ddVcard) === vehicle.id);
            });
        };

        var show = function (vehicleId) {
            var vehicle = byId[vehicleId];
            if (!vehicle) { return; }
            selectedId = vehicleId;
            renderFacts(vehicle);
            if (!vehicle.scheme) {
                currentUnit = null;
                showEmpty('Schema nu este disponibilă pentru acest tip de vehicul.');
                return;
            }
            var withUnit = function (unit) {
                if (selectedId !== vehicleId) { return; } // între timp s-a ales alt vehicul
                if (!unit) { currentUnit = null; showEmpty('Schema nu este disponibilă pentru acest vehicul.'); return; }
                currentUnit = unit;
                emptyEl.hidden = true;
                holder.hidden = false;
                holder.removeAttribute('data-loading');
                openBtn.hidden = false;
                loadAssets().then(function () { mount(true); }).catch(function (error) {
                    window.console.error(error);
                    showEmpty('Schema nu a putut fi încărcată.');
                });
            };
            if (Object.prototype.hasOwnProperty.call(units, vehicleId)) { withUnit(units[vehicleId]); return; }
            holder.hidden = false;
            holder.setAttribute('data-loading', '');
            emptyEl.hidden = true;
            window.fetch(data.schemeUrl + '&vehicle_id=' + encodeURIComponent(vehicleId), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) { throw new Error((payload && payload.error) || 'Eroare'); }
                    units[vehicleId] = payload.unit || null;
                    withUnit(units[vehicleId]);
                })
                .catch(function (error) {
                    window.console.error(error);
                    holder.removeAttribute('data-loading');
                    if (selectedId === vehicleId) { showEmpty('Schema nu a putut fi încărcată.'); }
                });
        };

        var openDialog = function () {
            if (!currentUnit || !dialog || !explorer) { return; }
            lastFocus = document.activeElement;
            var vehicle = byId[selectedId];
            if (dialogTitle && vehicle) { dialogTitle.textContent = '· ' + vehicle.plate + (vehicle.partner ? ' + ' + vehicle.partner.plate : ''); }
            dialog.hidden = false;
            document.body.style.overflow = 'hidden';
            // Aceeași instanță, fără remontare: doar se mută și își arată antetul / legenda; scena se
            // remăsoară singură (ResizeObserver în vehicle-xray.js).
            dialogBody.appendChild(holder);
            holder.classList.remove('is-compact');
            var close = dialog.querySelector('.dd-xray-dialog-head [data-dd-xray-close]');
            if (close) { close.focus(); }
        };
        var closeDialog = function () {
            if (!dialog || dialog.hidden) { return; }
            dialog.hidden = true;
            document.body.style.overflow = '';
            slot.insertBefore(holder, slot.firstChild);
            holder.classList.add('is-compact');
            // Înapoi la vederea compactă fără remontare: ansamblul întreg, fără selecție / zoom / inspector.
            if (explorer) {
                if (explorer.setViewMode) { explorer.setViewMode('assembly'); }
                if (explorer.markTire) { explorer.markTire(null); }
                if (explorer.overview) { explorer.overview(); }
            }
            if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        };

        openBtn.addEventListener('click', openDialog);
        // În antet schema e doar o previzualizare: orice click pe ea deschide detaliile.
        slot.addEventListener('click', function (event) {
            if (!holder.classList.contains('is-compact') || holder.hidden || event.target.closest('[data-dd-xray-open]')) { return; }
            event.preventDefault();
            event.stopPropagation();
            openDialog();
        }, true);
        if (dialog) {
            dialog.querySelectorAll('[data-dd-xray-close]').forEach(function (button) { button.addEventListener('click', closeDialog); });
            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape' || dialog.hidden) { return; }
                // Esc închide întâi zoom-ul / meniurile exploratorului; dialogul doar dacă nu mai e nimic deschis.
                if (dialog.querySelector('.fx-zoom-back:not([hidden])') || dialog.querySelector('.fx-inspector:not([hidden])')) { return; }
                closeDialog();
            });
        }

        page.addEventListener('click', function (event) {
            var button = event.target.closest('[data-dd-select-vehicle]');
            if (!button) { return; }
            var id = Number(button.dataset.ddSelectVehicle);
            show(id);
            if (!button.classList.contains('dd-vswitch')) {
                page.querySelector('[data-dd-vehicle-card]').scrollIntoView({ block: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' });
            }
        });

        // Schema se montează după afișarea paginii (nu blochează primul desen).
        var start = function () { show(selectedId); };
        if ('requestIdleCallback' in window) { window.requestIdleCallback(start, { timeout: 600 }); } else { window.setTimeout(start, 60); }
    })();

    /* ------------------------------------------------------------ echipamente */
    (function () {
        var equip = page.querySelector('[data-dd-equip]');
        if (!equip) { return; }
        var figure = equip.querySelector('[data-dd-figure]');
        var images = Array.prototype.slice.call(figure.querySelectorAll('.dd-figure-img'));
        var dots = Array.prototype.slice.call(figure.querySelectorAll('[data-dd-zone]'));
        var items = Array.prototype.slice.call(equip.querySelectorAll('[data-dd-equip-item]'));
        var variantButtons = Array.prototype.slice.call(equip.querySelectorAll('[data-dd-variant]'));
        var VARIANT_KEY = 'dcx.variant.sofer'; // aceeași preferință M/F ca în Echipamente șoferi
        var ready = false;
        var selectedZone = '';

        var placeDots = function (variant) {
            dots.forEach(function (dot) {
                var pos = String(dot.dataset['pos' + variant.toUpperCase()] || '50,50').split(',');
                dot.style.left = pos[0] + '%';
                dot.style.top = pos[1] + '%';
            });
        };
        var setVariant = function (variant) {
            variant = variant === 'f' ? 'f' : 'm';
            figure.dataset.variant = variant;
            images.forEach(function (img) { img.classList.toggle('is-active', img.dataset.variant === variant); });
            variantButtons.forEach(function (button) {
                var on = button.dataset.ddVariant === variant;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            placeDots(variant);
        };
        var init = function () {
            if (ready) { return; }
            ready = true;
            images.forEach(function (img) { if (img.dataset.src) { img.src = img.dataset.src; } });
            setVariant(storage.get(VARIANT_KEY) || 'm');
        };
        variantButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setVariant(button.dataset.ddVariant);
                storage.set(VARIANT_KEY, button.dataset.ddVariant);
            });
        });

        var selectZone = function (zone, fromRow) {
            selectedZone = zone === selectedZone && !fromRow ? '' : zone;
            dots.forEach(function (dot) {
                dot.classList.toggle('is-selected', selectedZone !== '' && dot.dataset.ddZone === selectedZone);
                dot.classList.toggle('is-dim', selectedZone !== '' && dot.dataset.ddZone !== selectedZone);
            });
            if (fromRow) { return; }
            var first = null;
            items.forEach(function (item) {
                var on = selectedZone !== '' && item.dataset.zone === selectedZone;
                item.classList.toggle('is-selected', on);
                if (on && !first) { first = item; }
            });
            if (first) {
                var list = first.closest('[data-dd-equip-list]');
                if (list) { list.scrollTo({ top: first.offsetTop - list.offsetTop, behavior: reducedMotion ? 'auto' : 'smooth' }); }
            }
        };
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () { selectZone(dot.dataset.ddZone, false); });
        });

        items.forEach(function (item) {
            var row = item.querySelector('[data-dd-equip-row]');
            var detail = item.querySelector('.dd-equip-detail');
            row.addEventListener('click', function () {
                var open = !item.classList.contains('is-expanded');
                items.forEach(function (other) {
                    if (other !== item && other.classList.contains('is-expanded')) {
                        other.classList.remove('is-expanded');
                        other.querySelector('.dd-equip-detail').hidden = true;
                        other.querySelector('[data-dd-equip-row]').setAttribute('aria-expanded', 'false');
                    }
                    other.classList.toggle('is-selected', other === item && open);
                });
                item.classList.toggle('is-expanded', open);
                detail.hidden = !open;
                row.setAttribute('aria-expanded', open ? 'true' : 'false');
                // Rândul aprinde punctul lui de pe figură doar dacă articolul e la șofer și zona are punct.
                var zone = open ? (item.dataset.zone || '') : '';
                var hasDot = dots.some(function (dot) { return dot.dataset.ddZone === zone; });
                selectedZone = '';
                selectZone(hasDot ? zone : '', true);
            });
        });

        activateHooks.echipamente = init;
    })();

    /* ------------------------------------------------- mărimi vestimentare */
    // Conținutul vine din action=driver_sizes la prima deschidere; salvarea re-randează partiala.
    (function () {
        var panel = page.querySelector('[data-dd-sizes]');
        if (!panel) { return; }
        var content = panel.querySelector('[data-dd-sz-content]');
        var msg = panel.querySelector('[data-dd-sz-msg]');
        var startBtn = panel.querySelector('[data-dd-sz-start]');
        var cancelBtn = panel.querySelector('[data-dd-sz-cancel]');
        var saveBtn = panel.querySelector('[data-dd-sz-save]');
        var loaded = false;
        var msgTimer = null;

        var form = function () { return content.querySelector('[data-dd-sz-form]'); };
        var say = function (text, isError) {
            if (!msg) { return; }
            window.clearTimeout(msgTimer);
            msg.textContent = text || '';
            msg.classList.toggle('is-error', !!isError);
            if (text && !isError) { msgTimer = window.setTimeout(function () { msg.textContent = ''; }, 4000); }
        };
        var setEditing = function (on) {
            var f = form();
            if (!f) { return; }
            f.classList.toggle('is-editing', on);
            f.querySelectorAll('[data-dd-sz-edit]').forEach(function (select) { select.disabled = !on; });
            if (startBtn) { startBtn.hidden = on; }
            if (cancelBtn) { cancelBtn.hidden = !on; }
            if (saveBtn) { saveBtn.hidden = !on; }
        };
        var render = function (html) {
            content.innerHTML = html;
            setEditing(false);
            if (startBtn) { startBtn.disabled = !form() || !form().querySelector('[data-dd-sz-edit]'); }
            syncHeight();
        };
        var request = function (url, options) {
            options = options || {};
            options.credentials = 'same-origin';
            options.headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
            return fetch(url, options).then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'Răspuns invalid de la server.' }; });
            });
        };
        var load = function () {
            if (loaded) { return; }
            loaded = true;
            request(panel.dataset.url).then(function (data) {
                if (!data.ok) { throw new Error(data.error || data.message || 'Mărimile nu au putut fi citite.'); }
                render(data.html);
            }).catch(function (error) {
                loaded = false;
                content.innerHTML = '<div class="dd-empty"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span></span></div>';
                content.querySelector('span').textContent = error.message || 'Mărimile nu au putut fi citite.';
                syncHeight();
            });
        };

        if (startBtn) {
            startBtn.addEventListener('click', function () {
                say('');
                setEditing(true);
                var first = form() && form().querySelector('[data-dd-sz-edit]');
                if (first) { first.focus(); }
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                var f = form();
                if (f) {
                    f.querySelectorAll('[data-dd-sz-edit]').forEach(function (select) { select.value = select.dataset.initial || ''; });
                }
                say('');
                setEditing(false);
            });
        }
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var f = form();
                if (!f) { return; }
                var body = new FormData(f);
                f.classList.add('is-saving');
                saveBtn.disabled = true;
                request(panel.dataset.saveUrl, { method: 'POST', body: body }).then(function (data) {
                    if (!data.ok) { throw new Error(data.error || data.message || 'Mărimile nu au putut fi salvate.'); }
                    render(data.html);
                    say(data.message || 'Mărimile au fost salvate.');
                }).catch(function (error) {
                    f.classList.remove('is-saving');
                    say(error.message || 'Mărimile nu au putut fi salvate.', true);
                }).then(function () { saveBtn.disabled = false; });
            });
        }
        // Escape renunță la editare, Enter într-un select nu trimite nimic.
        content.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && cancelBtn && !cancelBtn.hidden) { cancelBtn.click(); }
        });

        activateHooks.marimi = load;
    })();

    /* ------------------------------------------------------------------ start */
    activate(page.dataset.initialTab || order[0]);
    // Înălțimea finală după încărcarea fonturilor / imaginilor.
    window.addEventListener('load', syncHeight);
})();
