/*
 * Fișa „Detalii vehicul” — doar comportament de interfață:
 * carusel categorii (săgeți, tastatură, rotiță doar peste zona de navigare, swipe),
 * secțiuni restrângibile, căutare live în documente, tabel cu ~3 rânduri vizibile.
 * Nu face cereri către server; formularele (cuplare / decuplare) rămân POST-uri normale.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-vehicle-detail]');
    if (!root) {
        return;
    }

    var vehicleId = root.getAttribute('data-vehicle-id') || '0';
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function store(key, value) {
        try {
            if (value === null) {
                window.sessionStorage.removeItem(key);
            } else {
                window.sessionStorage.setItem(key, value);
            }
        } catch (e) { /* stocare indisponibilă: doar nu ținem minte */ }
    }

    function read(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function normalize(value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /* ---------------- secțiuni restrângibile ---------------- */
    var sections = {};
    var afterExpand = {};

    function setCollapsed(section, collapsed, remember) {
        var key = section.getAttribute('data-vd-section');
        section.classList.toggle('is-collapsed', collapsed);
        section.querySelectorAll('[data-vd-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        });
        var body = section.querySelector('.vd-collapse');
        if (body) {
            if (collapsed) {
                body.setAttribute('inert', '');
            } else {
                body.removeAttribute('inert');
            }
        }
        if (remember !== false) {
            store('vd:collapsed:' + key, collapsed ? '1' : null);
        }
        if (!collapsed && afterExpand[key]) {
            window.requestAnimationFrame(afterExpand[key]);
        }
    }

    root.querySelectorAll('[data-vd-section]').forEach(function (section) {
        var key = section.getAttribute('data-vd-section');
        sections[key] = section;

        if (read('vd:collapsed:' + key) === '1') {
            setCollapsed(section, true, false);
        }

        section.querySelectorAll('[data-vd-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.stopPropagation();
                setCollapsed(section, !section.classList.contains('is-collapsed'));
            });
        });

        var area = section.querySelector('[data-vd-toggle-area]');
        if (area) {
            area.addEventListener('click', function (event) {
                if (event.target.closest('button, a, input, select, label')) {
                    return;
                }
                setCollapsed(section, !section.classList.contains('is-collapsed'));
            });
        }
    });

    function expand(key) {
        var section = sections[key];
        if (section && section.classList.contains('is-collapsed')) {
            setCollapsed(section, false);
        }
    }

    /* ---------------- carusel „Informații vehicul” ---------------- */
    var carousel = root.querySelector('[data-vd-carousel]');
    var tabs = carousel ? Array.prototype.slice.call(carousel.querySelectorAll('[data-vd-tab]')) : [];
    var tabsTrack = carousel ? carousel.querySelector('[data-vd-tabs]') : null;
    var prevBtn = carousel ? carousel.querySelector('[data-vd-prev]') : null;
    var nextBtn = carousel ? carousel.querySelector('[data-vd-next]') : null;
    var current = 0;
    var panelsEl = carousel ? carousel.querySelector('[data-vd-panels]') : null;
    var track = carousel ? carousel.querySelector('[data-vd-track]') : null;

    // Pista de panouri: translateX pe categoria curentă (+ offset în timpul swipe-ului),
    // înălțimea containerului urmează panoul activ.
    function positionTrack(dragPx, instant) {
        if (!track || !panelsEl) {
            return;
        }
        track.classList.toggle('is-instant', !!instant);
        panelsEl.classList.toggle('is-instant', !!instant);
        track.style.transform = 'translate3d(calc(' + (-current * 100) + '% + ' + (dragPx || 0) + 'px), 0, 0)';
        var panel = track.children[current];
        if (panel) {
            panelsEl.style.height = panel.offsetHeight + 'px';
        }
        if (instant) {
            void track.offsetWidth;
            track.classList.remove('is-instant');
            panelsEl.classList.remove('is-instant');
        }
    }

    function centerTab(tab) {
        if (!tabsTrack || !tab) {
            return;
        }
        // scroll doar pe pista orizontală (scrollIntoView ar mișca și pagina pe verticală)
        var target = tab.offsetLeft - (tabsTrack.clientWidth - tab.offsetWidth) / 2;
        tabsTrack.scrollTo({ left: Math.max(0, target), behavior: reduceMotion ? 'auto' : 'smooth' });
    }

    function activate(index, options) {
        options = options || {};
        if (!tabs.length) {
            return;
        }
        index = Math.max(0, Math.min(tabs.length - 1, index));
        current = index;

        tabs.forEach(function (tab, i) {
            var on = i === index;
            var key = tab.getAttribute('data-vd-tab');
            var panel = carousel.querySelector('[data-vd-panel="' + key + '"]');
            var dot = carousel.querySelector('[data-vd-dot="' + key + '"]');
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.setAttribute('tabindex', on ? '0' : '-1');
            if (dot) {
                dot.classList.toggle('is-active', on);
            }
            if (panel) {
                panel.classList.toggle('is-active', on);
                if (on) {
                    panel.removeAttribute('aria-hidden');
                    panel.removeAttribute('inert');
                } else {
                    panel.setAttribute('aria-hidden', 'true');
                    panel.setAttribute('inert', '');
                }
            }
        });

        positionTrack(0, !!options.instant);

        if (prevBtn) {
            prevBtn.disabled = index === 0;
        }
        if (nextBtn) {
            nextBtn.disabled = index === tabs.length - 1;
        }
        centerTab(tabs[index]);
        if (options.focus) {
            tabs[index].focus({ preventScroll: true });
        }
        store('vd:tab:' + vehicleId, tabs[index].getAttribute('data-vd-tab'));
    }

    function indexOfKey(key) {
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].getAttribute('data-vd-tab') === key) {
                return i;
            }
        }
        return -1;
    }

    if (carousel && tabs.length) {
        var saved = indexOfKey(read('vd:tab:' + vehicleId) || '');
        activate(saved >= 0 ? saved : 0, { instant: true });

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () {
                activate(i);
            });
        });
        if (prevBtn) {
            prevBtn.addEventListener('click', function () { activate(current - 1); });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () { activate(current + 1); });
        }

        // tastatură (model WAI-ARIA tabs)
        tabsTrack.addEventListener('keydown', function (event) {
            var handled = true;
            switch (event.key) {
                case 'ArrowRight': activate(current + 1, { focus: true }); break;
                case 'ArrowLeft': activate(current - 1, { focus: true }); break;
                case 'Home': activate(0, { focus: true }); break;
                case 'End': activate(tabs.length - 1, { focus: true }); break;
                default: handled = false;
            }
            if (handled) {
                event.preventDefault();
            }
        });

        /*
         * Rotița verticală schimbă categoria DOAR peste bara de categorii; peste câmpuri
         * pagina derulează normal. Scroll-ul orizontal (touchpad lateral, Shift + rotiță)
         * schimbă categoria oriunde în secțiune.
         *  - rotiță de mouse (pași mari): fiecare „clic” = o categorie, cu o pauză scurtă
         *    ca un spin rapid să nu sară peste toate categoriile;
         *  - touchpad (pași mici, continui): un gest = o categorie, inerția gestului
         *    e consumată de carusel;
         *  - la prima / ultima categorie, un gest NOU în direcția fără categorii nu e
         *    oprit, deci pagina derulează normal pe verticală.
         */
        var STEP_COOLDOWN = 230;
        var GESTURE_GAP = 140;
        var lastMove = 0;
        var lastEvent = 0;
        var accum = 0;
        var gestureMoved = false;

        function step(dir, now) {
            lastMove = now;
            gestureMoved = true;
            accum = 0;
            activate(current + dir);
        }

        function onWheel(event) {
            if (event.ctrlKey) {
                return; // zoom
            }
            // Shift + rotiță = scroll orizontal (Windows / Linux)
            var shiftWheel = event.shiftKey && event.deltaX === 0 && event.deltaY !== 0;
            var horizontal = shiftWheel || Math.abs(event.deltaX) > Math.abs(event.deltaY);
            var inNav = !!event.target.closest('[data-vd-wheel-zone], .vd-dots, .vd-hint');
            if (!horizontal && !inNav) {
                return; // rotița verticală peste câmpuri = derulare normală a paginii
            }
            if (horizontal && event.target.closest('.vd-history, .table-responsive')) {
                return; // tabelele cu scroll orizontal propriu
            }
            var delta = horizontal ? (shiftWheel ? event.deltaY : event.deltaX) : event.deltaY;
            if (delta === 0) {
                return;
            }
            if (horizontal) {
                // fără scroll orizontal al paginii / gest „înapoi” al browserului
                event.preventDefault();
            }
            if (event.deltaMode === 1) {
                delta *= 33;
            } else if (event.deltaMode === 2) {
                delta *= 400;
            }

            var now = window.performance ? window.performance.now() : Date.now();
            if (now - lastEvent > GESTURE_GAP) {
                accum = 0;
                gestureMoved = false;
            }
            lastEvent = now;

            var dir = delta > 0 ? 1 : -1;
            var atEdge = (dir > 0 && current >= tabs.length - 1) || (dir < 0 && current <= 0);
            if (atEdge && !gestureMoved) {
                return; // margine: pagina derulează normal
            }
            event.preventDefault();
            if (atEdge) {
                return; // coada gestului care a ajuns la margine
            }

            if (Math.abs(delta) >= 50) {
                // rotiță de mouse
                if (now - lastMove >= STEP_COOLDOWN) {
                    step(dir, now);
                }
                return;
            }

            // touchpad
            if (gestureMoved) {
                return;
            }
            if (accum !== 0 && (accum > 0) !== (dir > 0)) {
                accum = 0;
            }
            accum += delta;
            if (Math.abs(accum) >= 45) {
                step(dir, now);
            }
        }

        carousel.addEventListener('wheel', onWheel, { passive: false });

        // swipe pe conținut: panoul urmează degetul, apoi se fixează pe categorie
        var drag = null;
        if (panelsEl && track) {
            panelsEl.addEventListener('touchstart', function (event) {
                if (event.touches.length !== 1 || event.target.closest('.vd-history, .table-responsive, select, input, textarea')) {
                    drag = null;
                    return;
                }
                drag = { x: event.touches[0].clientX, y: event.touches[0].clientY, t: Date.now(), axis: null, dx: 0 };
            }, { passive: true });

            panelsEl.addEventListener('touchmove', function (event) {
                if (!drag) {
                    return;
                }
                var dx = event.touches[0].clientX - drag.x;
                var dy = event.touches[0].clientY - drag.y;
                if (drag.axis === null) {
                    if (Math.abs(dx) < 8 && Math.abs(dy) < 8) {
                        return;
                    }
                    drag.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
                    if (drag.axis === 'x') {
                        track.classList.add('is-dragging');
                    }
                }
                if (drag.axis !== 'x') {
                    return;
                }
                // rezistență la margini
                if ((dx > 0 && current === 0) || (dx < 0 && current === tabs.length - 1)) {
                    dx = dx / 3;
                }
                drag.dx = dx;
                positionTrack(dx, false);
            }, { passive: true });

            var endDrag = function () {
                if (!drag) {
                    return;
                }
                var d = drag;
                drag = null;
                if (d.axis !== 'x') {
                    return;
                }
                track.classList.remove('is-dragging');
                var velocity = Math.abs(d.dx) / Math.max(1, Date.now() - d.t);
                var threshold = panelsEl.clientWidth * 0.18;
                if (Math.abs(d.dx) > threshold || (velocity > 0.45 && Math.abs(d.dx) > 30)) {
                    activate(current + (d.dx < 0 ? 1 : -1));
                } else {
                    positionTrack(0, false);
                }
            };
            panelsEl.addEventListener('touchend', endDrag, { passive: true });
            panelsEl.addEventListener('touchcancel', endDrag, { passive: true });
        }

        afterExpand.info = function () {
            positionTrack(0, true);
            centerTab(tabs[current]);
        };
    }

    // „Cuplează / Schimbă” din banda Cuplare / alocare → categoria Cuplare
    root.querySelectorAll('[data-vd-goto]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var idx = indexOfKey(btn.getAttribute('data-vd-goto'));
            if (idx < 0) {
                return;
            }
            expand('info');
            activate(idx);
            var section = sections.info;
            if (section) {
                section.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
            }
            var select = carousel.querySelector('[data-vd-panel="cuplare"] [data-vd-coupling-select]:not(:disabled)');
            if (select) {
                window.setTimeout(function () { select.focus({ preventScroll: true }); }, reduceMotion ? 0 : 350);
            }
        });
    });

    /* ---------------- documente ---------------- */
    var docsSection = sections.docs;
    var searchInput = root.querySelector('[data-vd-doc-search]');
    var scrollBox = root.querySelector('[data-vd-doc-scroll]');
    var rows = Array.prototype.slice.call(root.querySelectorAll('[data-vd-doc-row]'));
    var emptyRow = root.querySelector('[data-vd-doc-empty]');
    var emptyText = root.querySelector('[data-vd-doc-empty-text]');
    var countEl = root.querySelector('[data-vd-doc-count]');
    var VISIBLE_ROWS = 3;

    function fitDocsHeight() {
        if (!scrollBox || (docsSection && docsSection.classList.contains('is-collapsed'))) {
            return;
        }
        var head = scrollBox.querySelector('thead');
        var visible = rows.filter(function (row) { return !row.hidden; });
        if (!visible.length && emptyRow && !emptyRow.hidden) {
            visible = [emptyRow];
        }
        var total = head ? head.offsetHeight : 0;
        visible.slice(0, VISIBLE_ROWS).forEach(function (row) { total += row.offsetHeight; });
        if (total > 0) {
            // + bara orizontală, dacă tabelul e mai lat decât containerul (mobil)
            var hBar = scrollBox.offsetHeight - scrollBox.clientHeight;
            scrollBox.style.maxHeight = (total + Math.max(0, hBar) + 1) + 'px';
        }
    }

    function applySearch() {
        var term = normalize(searchInput ? searchInput.value : '');
        var shown = 0;
        rows.forEach(function (row) {
            var match = term === '' || (row.getAttribute('data-search') || '').indexOf(term) !== -1;
            row.hidden = !match;
            if (match) {
                shown++;
            }
        });
        if (emptyRow) {
            emptyRow.hidden = shown > 0;
            if (emptyText && rows.length) {
                emptyText.textContent = 'Niciun document nu corespunde căutării „' + (searchInput ? searchInput.value.trim() : '') + '”.';
            }
        }
        if (countEl) {
            countEl.textContent = term === ''
                ? rows.length + (rows.length === 1 ? ' document' : ' documente')
                : shown + ' din ' + rows.length;
        }
        if (scrollBox) {
            scrollBox.scrollTop = 0;
        }
        fitDocsHeight();
    }

    if (searchInput) {
        searchInput.addEventListener('input', applySearch);
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && searchInput.value !== '') {
                searchInput.value = '';
                applySearch();
            }
        });
        // valoarea restaurată de browser la „Înapoi”
        if (searchInput.value !== '') {
            applySearch();
        }
    }

    root.querySelectorAll('[data-vd-chip-search]').forEach(function (chip) {
        chip.addEventListener('click', function (event) {
            event.stopPropagation();
            if (!searchInput) {
                return;
            }
            var label = chip.getAttribute('data-vd-chip-search') || '';
            searchInput.value = searchInput.value === label ? '' : label;
            expand('docs');
            applySearch();
        });
    });

    var moreChip = root.querySelector('[data-vd-chip-more]');
    if (moreChip) {
        var moreLabel = moreChip.textContent;
        moreChip.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = moreChip.getAttribute('aria-expanded') !== 'true';
            root.querySelectorAll('.vd-chip.is-extra').forEach(function (chip) { chip.hidden = !open; });
            moreChip.setAttribute('aria-expanded', open ? 'true' : 'false');
            moreChip.textContent = open ? 'Mai puține' : moreLabel;
        });
    }

    afterExpand.docs = fitDocsHeight;
    fitDocsHeight();
    window.addEventListener('load', fitDocsHeight);
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(fitDocsHeight);
    }
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () {
            fitDocsHeight();
            if (tabs.length) {
                positionTrack(0, true);
                centerTab(tabs[current]);
            }
        }, 120);
    });
    if (tabs.length) {
        window.addEventListener('load', function () { positionTrack(0, true); });
    }
})();
