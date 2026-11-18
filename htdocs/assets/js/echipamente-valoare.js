/*
 * „Valoare echipamente alocate” — un card cu trei fețe (Echipamente șoferi / TESA).
 *
 *   1 total  --click pe valoare-->  2 pe persoană  --click pe persoană-->  3 detaliu
 *
 * Schimbarea feței urmează cardul „Cost total operațional” din Tablou de bord
 * (dashboard-live.js): fața veche se ascunde, înălțimea cardului se animă de la
 * vechea la noua valoare, iar fața nouă intră cu opacitate + translație (~300 ms).
 * Nivelul 3 refolosește silueta din Catalog echipamente (aceeași preferință M/F,
 * aceleași puncte de pe corp din anchors.json).
 *
 * Meniurile „…” ale articolelor, predarea etc. sunt cele din echipamente-soferi.js.
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-dcv]');
    if (!root) {
        return;
    }

    const card = root.querySelector('[data-dcv-card]');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const role = root.getAttribute('data-dcv-role') || 'sofer';
    const data = (function () {
        try {
            return JSON.parse((root.querySelector('[data-dcv-data]') || {}).textContent || '{}');
        } catch (error) {
            return {};
        }
    })();
    const owners = data.owners || {};
    const anchors = data.anchors || {};
    const nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    const nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const money = function (value) { return nf2.format(value || 0) + ' lei'; };
    const plural = function (n, one, many) { return nf0.format(n) + ' ' + (n === 1 ? one : many); };
    const store = {
        get: function (key) { try { return window.localStorage.getItem('dcx.' + key); } catch (e) { return null; } },
        set: function (key, value) { try { window.localStorage.setItem('dcx.' + key, value); } catch (e) { /* fără stocare */ } },
    };

    let level = 1;
    let currentOwner = null;
    let currentCat = '';

    // ------------------------------------------------------------------
    // Schimbarea feței
    // ------------------------------------------------------------------

    function face(n) {
        return card.querySelector('[data-dcv-face="' + n + '"]');
    }

    /**
     * Comun ambelor carduri (Valoare + În stoc): ascunde fețele celelalte, setează
     * nivelul și animă lățimea + înălțimea de la vechiul dreptunghi la cel nou, apoi
     * fața nouă intră cu opacitate/translație. Ca „Cost total operațional”.
     */
    /*
     * Animație FLIP comună celor 4 carduri. La prima schimbare de față dintr-o
     * interacțiune se notează poziția și mărimea TUTUROR cardurilor; după ce toate
     * schimbările sincrone s-au aplicat (microtask), fiecare card pornește din
     * dreptunghiul vechi (translate + lățime/înălțime) spre cel nou. Așa cardul
     * activ crește pe tot rândul, iar celelalte alunecă în banda compactă, fără salt.
     */
    const wsCards = Array.prototype.slice.call(root.querySelectorAll('[data-ws-card]'));
    let flipBefore = null;

    function captureLayout() {
        if (flipBefore || reduceMotion.matches) {
            return;
        }
        flipBefore = new Map();
        wsCards.forEach(function (node) { flipBefore.set(node, node.getBoundingClientRect()); });
        const before = flipBefore;
        Promise.resolve().then(function () {
            flipBefore = null;
            wsCards.forEach(function (node) {
                const a = before.get(node);
                const b = node.getBoundingClientRect();
                if (!a || !node.animate || (Math.abs(a.left - b.left) < 1 && Math.abs(a.top - b.top) < 1
                    && Math.abs(a.width - b.width) < 1 && Math.abs(a.height - b.height) < 1)) {
                    return;
                }
                node.animate([
                    { transform: 'translate(' + (a.left - b.left) + 'px,' + (a.top - b.top) + 'px)', width: a.width + 'px', height: a.height + 'px' },
                    { transform: 'none', width: b.width + 'px', height: b.height + 'px' },
                ], { duration: 300, easing: 'cubic-bezier(.22,.8,.24,1)' });
            });
        });
    }

    function syncOpenState() {
        let open = false;
        wsCards.forEach(function (node) {
            const isOpen = Array.prototype.some.call(node.attributes, function (a) {
                return /^data-dc[vsri]-level$/.test(a.name) && a.value !== '1';
            });
            node.toggleAttribute('data-open', isOpen);
            open = open || isOpen;
        });
        root.classList.toggle('has-open', open);
    }

    function morphCard(el, prefix, next, quiet) {
        captureLayout();
        el.querySelectorAll('[data-' + prefix + '-face]').forEach(function (node) {
            node.hidden = node.getAttribute('data-' + prefix + '-face') !== String(next);
        });
        el.setAttribute('data-' + prefix + '-level', String(next));
        syncOpenState();

        const target = el.querySelector('[data-' + prefix + '-face="' + next + '"]');
        if (!quiet && !reduceMotion.matches) {
            target.classList.remove('is-entering');
            void target.offsetWidth;
            target.classList.add('is-entering');
            window.setTimeout(function () { target.classList.remove('is-entering'); }, 520);
        }
        return target;
    }

    function go(next, quiet) {
        next = Number(next);
        if (next === level) {
            return;
        }
        level = next;
        // Un singur spațiu de lucru deschis: cardul de valoare închide „În stoc”.
        if (next > 1) {
            collapseOthers('value');
        }
        const target = morphCard(card, 'dcv', next, quiet);
        if (!quiet) {
            focusFace(target);
        }
    }

    function focusFace(target) {
        const focusable = target.querySelector('[data-dcv-search], [data-dcs-search], .dcv-back, .dcv-total');
        if (focusable) {
            focusable.focus({ preventScroll: true });
        }
    }

    let stockApi = null;
    let returnApi = null;
    let replaceApi = null;

    // ------------------------------------------------------------------
    // Nivel 2: căutare, sortare, doar cu echipamente
    // ------------------------------------------------------------------

    const list = root.querySelector('[data-dcv-list]');
    const people = list ? Array.prototype.slice.call(list.querySelectorAll('[data-dcv-owner]')) : [];
    const searchInput = root.querySelector('[data-dcv-search]');
    const onlyEquipped = root.querySelector('[data-dcv-only-equipped]');

    function normalize(text) {
        return (text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function refreshList() {
        const term = normalize(searchInput ? searchInput.value.trim() : '');
        let shown = 0;
        people.forEach(function (row) {
            const show = (term === '' || normalize(row.getAttribute('data-name')).indexOf(term) !== -1)
                && (!onlyEquipped || !onlyEquipped.checked || row.getAttribute('data-equipped') === '1');
            row.hidden = !show;
            shown += show ? 1 : 0;
        });
        const empty = root.querySelector('[data-dcv-list-empty]');
        if (empty && people.length > 0) {
            empty.hidden = shown > 0;
        }
    }

    function sortList(mode) {
        const sorted = people.slice().sort(function (a, b) {
            if (mode === 'nume') {
                return a.getAttribute('data-sort-name').localeCompare(b.getAttribute('data-sort-name'), 'ro');
            }
            return Number(b.getAttribute('data-sort-value')) - Number(a.getAttribute('data-sort-value'));
        });
        const empty = root.querySelector('[data-dcv-list-empty]');
        sorted.forEach(function (row) { list.insertBefore(row, empty); });
    }

    if (searchInput) {
        searchInput.addEventListener('input', refreshList);
    }
    if (onlyEquipped) {
        onlyEquipped.addEventListener('change', refreshList);
    }
    root.querySelectorAll('[data-dcv-sort]').forEach(function (radio) {
        radio.addEventListener('change', function () { sortList(radio.value); });
    });

    // ------------------------------------------------------------------
    // Nivel 3: persoana selectată
    // ------------------------------------------------------------------

    const l3 = face(3);
    const figure = l3.querySelector('.dcv-figure');
    const dotsBox = l3.querySelector('[data-dcv-dots]');
    const catsBox = l3.querySelector('[data-dcv-cats]');
    const itemRows = Array.prototype.slice.call(l3.querySelectorAll('.dcv-item'));
    const itemsEmpty = l3.querySelector('[data-dcv-items-empty]');
    const iconByCat = {};

    function variant() {
        const saved = store.get('variant.' + role);
        return saved === 'm' || saved === 'f' ? saved : (root.getAttribute('data-dcv-default-variant') || 'm');
    }

    function setVariant(v, remember) {
        figure.setAttribute('data-variant', v);
        figure.querySelectorAll('.dcv-figure-img').forEach(function (image) {
            image.classList.toggle('is-active', image.getAttribute('data-variant') === v);
        });
        l3.querySelectorAll('[data-dcv-variant]').forEach(function (button) {
            const active = button.getAttribute('data-dcv-variant') === v;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        if (remember) {
            store.set('variant.' + role, v);
        }
        placeDots();
    }

    function placeDots() {
        const map = anchors[role + '-' + (figure.getAttribute('data-variant') || 'm')] || {};
        const used = {};
        dotsBox.querySelectorAll('.dcv-dot').forEach(function (dot) {
            const zone = dot.getAttribute('data-zone');
            const at = map[zone] || map.alte || [50, 50];
            // Două categorii pe aceeași zonă: al doilea punct se decalează puțin.
            const n = used[zone] = (used[zone] || 0) + 1;
            dot.style.left = (at[0] + (n - 1) * 5) + '%';
            dot.style.top = (at[1] + (n - 1) * 3) + '%';
        });
    }

    function renderCats(owner) {
        catsBox.innerHTML = '';
        dotsBox.innerHTML = '';
        (owner.categorii || []).forEach(function (cat) {
            iconByCat[cat.key] = cat.icon;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'dcv-cat';
            button.setAttribute('role', 'tab');
            button.setAttribute('data-dcv-cat', cat.key);
            button.innerHTML = '<span class="dcv-cat-ico"><i class="bi ' + cat.icon + '"></i></span>'
                + '<span class="dcv-cat-name"></span>'
                + '<span class="dcv-cat-value">' + money(cat.valoare) + '</span>'
                + '<span class="dcv-cat-meta">' + plural(cat.bucati, 'buc.', 'buc.') + ' &nbsp;|&nbsp; '
                + String(cat.procent).replace('.', ',') + '%</span>'
                + '<span class="dcv-cat-bar"><i style="width:0"></i></span>';
            button.querySelector('.dcv-cat-name').textContent = cat.name;
            catsBox.appendChild(button);

            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'dcv-dot';
            dot.tabIndex = -1;
            dot.setAttribute('data-dcv-cat', cat.key);
            dot.setAttribute('data-zone', cat.anchor);
            dot.setAttribute('aria-label', cat.name);
            dot.title = cat.name + ' — ' + money(cat.valoare);
            dotsBox.appendChild(dot);
        });
        placeDots();
        // Barele cresc după ce fața a intrat, ca să se vadă proporția.
        window.setTimeout(function () {
            (owner.categorii || []).forEach(function (cat) {
                const bar = catsBox.querySelector('[data-dcv-cat="' + cat.key + '"] .dcv-cat-bar i');
                if (bar) {
                    bar.style.width = Math.max(2, cat.procent) + '%';
                }
            });
        }, reduceMotion.matches ? 0 : 120);
    }

    function applyCat(key) {
        currentCat = key;
        let shown = 0;
        itemRows.forEach(function (row) {
            const show = row.getAttribute('data-owner') === currentOwner && (key === '' || row.getAttribute('data-cat') === key);
            row.hidden = !show;
            shown += show ? 1 : 0;
        });
        catsBox.querySelectorAll('.dcv-cat').forEach(function (button) {
            const active = button.getAttribute('data-dcv-cat') === key;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        dotsBox.querySelectorAll('.dcv-dot').forEach(function (dot) {
            const match = dot.getAttribute('data-dcv-cat') === key;
            dot.classList.toggle('is-hot', key !== '' && match);
            dot.classList.toggle('is-dim', key !== '' && !match);
        });
        itemsEmpty.hidden = shown > 0;
        if (shown === 0) {
            l3.querySelector('[data-dcv-items-empty-text]').textContent = key === ''
                ? 'Nu are echipamente alocate.'
                : 'Nicio linie în această categorie.';
        }
        const scroller = l3.querySelector('[data-dcv-items-scroll]');
        if (scroller) {
            scroller.scrollTop = 0;
        }
    }

    function openOwner(id) {
        const owner = owners[String(id)];
        if (!owner) {
            return;
        }
        currentOwner = String(id);

        l3.querySelector('[data-dcv-crumb-name]').textContent = owner.nume;
        l3.querySelector('[data-dcv-initials]').textContent = owner.initiale;
        l3.querySelector('[data-dcv-name]').textContent = owner.nume;
        l3.querySelector('[data-dcv-meta]').textContent = owner.meta || '';
        l3.querySelector('[data-dcv-value]').textContent = money(owner.valoare) + (owner.pretLipsa > 0 ? ' *' : '');
        l3.querySelector('[data-dcv-value]').title = owner.pretLipsa > 0
            ? plural(owner.pretLipsa, 'linie fără preț', 'linii fără preț') + ' — valoare incompletă'
            : '';
        l3.querySelector('[data-dcv-articles]').textContent = plural(owner.articole, 'articol', 'articole');
        l3.querySelector('[data-dcv-pieces]').textContent = plural(owner.bucati, 'bucată', 'bucăți');

        const handover = l3.querySelector('[data-dcv-handover]');
        if (handover) {
            handover.setAttribute('data-des-sofer-id', currentOwner);
            handover.setAttribute('data-des-sofer', owner.nume);
        }

        renderCats(owner);
        itemRows.forEach(function (row) {
            const holder = row.querySelector('[data-cat-icon]');
            if (holder && holder.childElementCount === 0) {
                holder.innerHTML = '<i class="bi ' + (iconByCat[holder.getAttribute('data-cat-icon')] || 'bi-box-seam') + '"></i>';
            }
        });
        applyCat('');

        people.forEach(function (row) {
            row.classList.toggle('is-selected', row.getAttribute('data-dcv-owner') === currentOwner);
        });
        document.querySelectorAll('.des-driver-row').forEach(function (row) {
            row.classList.toggle('is-dcv-selected', row.getAttribute('data-des-row') === 'des-driver-' + currentOwner);
        });

        go(3);
    }

    // ------------------------------------------------------------------
    // Evenimente
    // ------------------------------------------------------------------

    root.addEventListener('click', function (event) {
        const goButton = event.target.closest('[data-dcv-go]');
        if (goButton) {
            go(goButton.getAttribute('data-dcv-go'));
            return;
        }
        const person = event.target.closest('[data-dcv-owner]');
        if (person) {
            openOwner(person.getAttribute('data-dcv-owner'));
            return;
        }
        const cat = event.target.closest('[data-dcv-cat]');
        if (cat && l3.contains(cat)) {
            const key = cat.getAttribute('data-dcv-cat');
            applyCat(currentCat === key ? '' : key);
            return;
        }
        const variantButton = event.target.closest('[data-dcv-variant]');
        if (variantButton) {
            setVariant(variantButton.getAttribute('data-dcv-variant'), true);
        }
    });

    // Punct pe siluetă <-> categorie: evidențiere la hover.
    l3.addEventListener('pointerover', function (event) {
        const node = event.target.closest('[data-dcv-cat]');
        if (!node || currentCat !== '') {
            return;
        }
        const key = node.getAttribute('data-dcv-cat');
        dotsBox.querySelectorAll('.dcv-dot').forEach(function (dot) {
            dot.classList.toggle('is-hot', dot.getAttribute('data-dcv-cat') === key);
        });
    });
    l3.addEventListener('pointerout', function (event) {
        if (currentCat === '' && event.target.closest('[data-dcv-cat]')) {
            dotsBox.querySelectorAll('.dcv-dot.is-hot').forEach(function (dot) { dot.classList.remove('is-hot'); });
        }
    });

    // Tabelul de sub card: click pe valoarea unei persoane -> nivelul 3 al aceleiași persoane.
    document.addEventListener('click', function (event) {
        const opener = event.target.closest('[data-dcv-open-owner]');
        if (!opener) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        openOwner(opener.getAttribute('data-dcv-open-owner'));
        const top = root.getBoundingClientRect().top + window.scrollY - 16;
        window.scrollTo({ top: top, behavior: reduceMotion.matches ? 'auto' : 'smooth' });
    }, true);

    // ------------------------------------------------------------------
    // Cardul „În stoc”: 1 total -> 2 categorii -> 3 detaliu (articolul selectat
    // doar filtrează nivelul 3, nu deschide o a patra față).
    // ------------------------------------------------------------------

    stockApi = (function () {
        const stockCard = root.querySelector('[data-dcs-card]');
        if (!stockCard) {
            return null;
        }
        let stockLevel = 1;
        let stockCat = '';
        const rows = Array.prototype.slice.call(stockCard.querySelectorAll('.dcs-row'));
        const search = stockCard.querySelector('[data-dcs-search]');

        function goStock(next, quiet) {
            next = Number(next);
            if (next === stockLevel) {
                return;
            }
            stockLevel = next;
            root.classList.toggle('is-stock-open', next > 1);
            // Cardul de valoare revine la totalul compact cât timp stocul e deschis.
            if (next > 1) {
                collapseOthers(api);
            }
            const target = morphCard(stockCard, 'dcs', next, quiet);
            if (!quiet) {
                focusFace(target);
            }
        }

        function selectArticle(detail, id) {
            const current = detail.getAttribute('data-article') || '';
            const next = current === id ? '' : id;
            detail.setAttribute('data-article', next);
            detail.querySelectorAll('[data-dcs-article]').forEach(function (node) {
                node.classList.toggle('is-active', node.getAttribute('data-dcs-article') === next);
            });
            detail.querySelectorAll('[data-dcs-metrics]').forEach(function (node) {
                node.hidden = node.getAttribute('data-dcs-metrics') !== next;
            });
            detail.querySelectorAll('[data-dcs-line]').forEach(function (row) {
                row.hidden = next !== '' && row.getAttribute('data-dcs-line') !== next;
            });
            const article = detail.querySelector('.dcs-article[data-dcs-article="' + next + '"]');
            if (article) {
                article.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduceMotion.matches ? 'auto' : 'smooth' });
            }
        }

        function openCategory(key) {
            stockCat = key;
            const row = stockCard.querySelector('.dcs-row[data-dcs-cat="' + key + '"]');
            stockCard.querySelector('[data-dcs-crumb]').textContent = row ? row.querySelector('.dcs-row-name span:last-child').textContent : key;
            stockCard.querySelectorAll('[data-dcs-detail]').forEach(function (detail) {
                const show = detail.getAttribute('data-dcs-detail') === key;
                detail.hidden = !show;
                if (show && detail.getAttribute('data-article')) {
                    detail.setAttribute('data-article', '');
                    selectArticle(detail, '');
                }
            });
            rows.forEach(function (r) { r.classList.toggle('is-selected', r.getAttribute('data-dcs-cat') === key); });
            goStock(3);
        }

        if (search) {
            search.addEventListener('input', function () {
                const term = normalize(search.value.trim());
                let shown = 0;
                rows.forEach(function (r) {
                    const show = term === '' || normalize(r.getAttribute('data-name')).indexOf(term) !== -1;
                    r.hidden = !show;
                    shown += show ? 1 : 0;
                });
                const empty = stockCard.querySelector('[data-dcs-list-empty]');
                if (empty && rows.length > 0) {
                    empty.hidden = shown > 0;
                }
            });
        }

        stockCard.addEventListener('click', function (event) {
            const goButton = event.target.closest('[data-dcs-go]');
            if (goButton) {
                goStock(goButton.getAttribute('data-dcs-go'));
                return;
            }
            const catRow = event.target.closest('.dcs-row[data-dcs-cat]');
            if (catRow) {
                openCategory(catRow.getAttribute('data-dcs-cat'));
                return;
            }
            const articleButton = event.target.closest('[data-dcs-article]');
            if (articleButton) {
                selectArticle(articleButton.closest('[data-dcs-detail]'), articleButton.getAttribute('data-dcs-article'));
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && stockLevel > 1 && !document.querySelector('.modal.show, .des-floating-menu.show')) {
                goStock(stockLevel - 1);
            }
        });

        const api = { go: goStock };
        return api;
    })();

    // ------------------------------------------------------------------
    // Cardurile „De returnat” (dcr) și „De înlocuit” (dci): aceeași structură —
    // 1 total -> 2 persoane -> 3 spațiu de lucru al persoanei. Categoria și linia
    // aleasă doar filtrează / selectează în nivelul 3. Butonul principal deschide
    // formularul EXISTENT (Returnează / Înlocuiește) pentru linia selectată.
    // ------------------------------------------------------------------

    function personWorkspace(p, opts) {
        const wsCard = root.querySelector('[data-' + p + '-card]');
        if (!wsCard) {
            return null;
        }
        const attr = function (name) { return 'data-' + p + '-' + name; };
        const sel = function (name, value) {
            return '[' + attr(name) + (value === undefined ? '' : '="' + value + '"') + ']';
        };
        const wsData = (function () {
            try {
                return JSON.parse((wsCard.querySelector(sel('data')) || {}).textContent || '{}');
            } catch (error) {
                return {};
            }
        })();
        const wsOwners = wsData.owners || {};
        const rows = Array.prototype.slice.call(wsCard.querySelectorAll('.' + p + '-row'));
        const lines = Array.prototype.slice.call(wsCard.querySelectorAll('.' + p + '-line'));
        const search = wsCard.querySelector(sel('search'));
        const figureEl = wsCard.querySelector('.' + p + '-figure');
        const dots = wsCard.querySelector(sel('dots'));
        const catsEl = wsCard.querySelector(sel('cats'));
        const action = wsCard.querySelector(sel('action'));
        let wsLevel = 1;
        let owner = null;
        let cat = '';
        let picked = null;

        function goWs(next, quiet) {
            next = Number(next);
            if (next === wsLevel) {
                return;
            }
            wsLevel = next;
            root.classList.toggle(opts.openClass, next > 1);
            if (next > 1) {
                collapseOthers(api);
            }
            const target = morphCard(wsCard, p, next, quiet);
            if (!quiet) {
                focusFace(target);
            }
        }

        function syncFigure() {
            const saved = store.get('variant.' + role);
            const v = saved === 'm' || saved === 'f' ? saved : (root.getAttribute('data-dcv-default-variant') || 'm');
            figureEl.setAttribute('data-variant', v);
            figureEl.querySelectorAll('.dcv-figure-img').forEach(function (image) {
                image.classList.toggle('is-active', image.getAttribute('data-variant') === v);
            });
            const map = anchors[role + '-' + v] || {};
            const used = {};
            dots.querySelectorAll('.dcv-dot').forEach(function (dot) {
                const zone = dot.getAttribute('data-zone');
                const at = map[zone] || map.alte || [50, 50];
                const n = used[zone] = (used[zone] || 0) + 1;
                dot.style.left = (at[0] + (n - 1) * 5) + '%';
                dot.style.top = (at[1] + (n - 1) * 3) + '%';
            });
        }

        function pick(line) {
            picked = line;
            lines.forEach(function (row) { row.classList.toggle('is-picked', row === line); });
            if (action) {
                action.disabled = !line;
                action.title = line ? opts.actionVerb + ': ' + line.querySelector('strong').textContent.trim() : 'Alege un articol din tabel';
            }
        }

        function applyCat(key) {
            cat = key;
            let first = null;
            lines.forEach(function (row) {
                const show = row.getAttribute('data-owner') === owner && (key === '' || row.getAttribute('data-cat') === key);
                row.hidden = !show;
                if (show && !first) {
                    first = row;
                }
            });
            catsEl.querySelectorAll('.dcv-cat').forEach(function (button) {
                const active = button.getAttribute(attr('cat')) === key;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            dots.querySelectorAll('.dcv-dot').forEach(function (dot) {
                const match = dot.getAttribute(attr('cat')) === key;
                dot.classList.toggle('is-hot', key !== '' && match);
                dot.classList.toggle('is-dim', key !== '' && !match);
            });
            if (!picked || picked.hidden) {
                pick(first);
            }
        }

        function openOwner(id) {
            const data = wsOwners[String(id)];
            if (!data) {
                return;
            }
            owner = String(id);
            picked = null;
            const setText = function (name, text) {
                wsCard.querySelectorAll(sel(name)).forEach(function (node) { node.textContent = text; });
            };
            setText('crumb', data.nume);
            setText('initials', data.initiale);
            setText('name', data.nume);
            setText('meta', [data.meta, data.inactiv ? 'inactiv' : ''].filter(Boolean).join(' · '));
            setText('m-total', plural(data.linii, 'alocare', 'alocări'));
            setText('m-articole', nf0.format(data.articole));
            setText('m-bucati', nf0.format(data.bucati));
            setText('m-valoare', money(data.valoare) + (data.pretLipsa > 0 ? ' *' : ''));
            setText('m-note', data.pretLipsa > 0 ? plural(data.pretLipsa, 'linie fără preț', 'linii fără preț') : opts.valueNote);
            setText('m-extra', opts.extraNote ? opts.extraNote(data) : '');

            catsEl.innerHTML = '';
            dots.innerHTML = '';
            (data.categorii || []).forEach(function (c) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'dcv-cat';
                button.setAttribute('role', 'tab');
                button.setAttribute(attr('cat'), c.key);
                button.innerHTML = '<span class="dcv-cat-ico"><i class="bi ' + c.icon + '"></i></span>'
                    + '<span class="dcv-cat-name"></span>'
                    + '<span class="dcv-cat-value">' + money(c.valoare) + '</span>'
                    + '<span class="dcv-cat-meta">' + plural(c.articole, 'articol', 'articole') + ' &nbsp;|&nbsp; ' + plural(c.bucati, 'buc.', 'buc.') + '</span>';
                button.querySelector('.dcv-cat-name').textContent = c.name;
                catsEl.appendChild(button);

                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'dcv-dot ' + opts.dotClass;
                dot.tabIndex = -1;
                dot.setAttribute(attr('cat'), c.key);
                dot.setAttribute('data-zone', c.anchor);
                dot.setAttribute('aria-label', c.name);
                dot.title = c.name + ' — ' + plural(c.bucati, 'buc.', 'buc.') + ' ' + opts.dotLabel;
                dots.appendChild(dot);
            });
            syncFigure();
            applyCat('');
            rows.forEach(function (r) { r.classList.toggle('is-selected', r.getAttribute(attr('owner')) === owner); });
            goWs(3);
        }

        if (search) {
            search.addEventListener('input', function () {
                const term = normalize(search.value.trim());
                let shown = 0;
                rows.forEach(function (r) {
                    const show = term === '' || normalize(r.getAttribute('data-name')).indexOf(term) !== -1;
                    r.hidden = !show;
                    shown += show ? 1 : 0;
                });
                const empty = wsCard.querySelector(sel('list-empty'));
                if (empty && rows.length > 0) {
                    empty.hidden = shown > 0;
                }
            });
        }

        wsCard.addEventListener('click', function (event) {
            const goButton = event.target.closest(sel('go'));
            if (goButton) {
                goWs(goButton.getAttribute(attr('go')));
                return;
            }
            const ownerRow = event.target.closest(sel('owner'));
            if (ownerRow) {
                openOwner(ownerRow.getAttribute(attr('owner')));
                return;
            }
            const catButton = event.target.closest(sel('cat'));
            if (catButton) {
                const key = catButton.getAttribute(attr('cat'));
                applyCat(cat === key ? '' : key);
                return;
            }
            if (event.target.closest(sel('action'))) {
                // Același formular ca acțiunea din meniul articolului (modalul existent).
                const trigger = picked && picked.querySelector(opts.rowTrigger);
                if (trigger) {
                    trigger.click();
                }
                return;
            }
            const line = event.target.closest('.' + p + '-line');
            if (line && !event.target.closest('[data-des-open]')) {
                pick(line);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && wsLevel > 1 && !document.querySelector('.modal.show, .des-floating-menu.show')) {
                goWs(wsLevel - 1);
            }
        });

        const api = { go: goWs };
        return api;
    }

    /** Un singur spațiu de lucru mare deschis: celelalte carduri revin la nivelul 1. */
    function collapseOthers(except) {
        if (except !== 'value' && level > 1) {
            go(1, true);
        }
        [stockApi, returnApi, replaceApi].forEach(function (other) {
            if (other && other !== except) {
                other.go(1, true);
            }
        });
    }

    returnApi = personWorkspace('dcr', {
        openClass: 'is-return-open',
        rowTrigger: '.dcr-row-return',
        actionVerb: 'Returnează',
        dotClass: 'is-orange',
        dotLabel: 'de returnat',
        valueNote: 'nu e pierdere',
    });

    replaceApi = personWorkspace('dci', {
        openClass: 'is-replace-open',
        rowTrigger: '.dci-row-replace',
        actionVerb: 'Înlocuiește',
        dotClass: 'is-red',
        dotLabel: 'de înlocuit',
        valueNote: 'cost estimat de înlocuire',
        extraNote: function (data) {
            return data.faraStoc > 0 ? plural(data.faraStoc, 'linie fără stoc', 'linii fără stoc') : 'stoc suficient';
        },
    });

    // ------------------------------------------------------------------
    // Grila celor 4 carduri: buton de închidere pe spațiul de lucru și comutare
    // directă din banda compactă (cardurile nedeschise).
    // ------------------------------------------------------------------

    const wsGo = {
        value: function (n) { go(n); },
        stock: stockApi ? stockApi.go : null,
        return: returnApi ? returnApi.go : null,
        replace: replaceApi ? replaceApi.go : null,
    };

    wsCards.forEach(function (node) {
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'dcv-ws-close-btn';
        close.setAttribute('aria-label', 'Închide și revino la cele patru carduri');
        close.title = 'Închide';
        close.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
        node.appendChild(close);
    });

    root.addEventListener('click', function (event) {
        const close = event.target.closest('.dcv-ws-close-btn');
        const node = event.target.closest('[data-ws-card]');
        if (!node) {
            return;
        }
        const open = wsGo[node.getAttribute('data-ws-card')];
        if (close) {
            if (open) {
                open(1);
            }
            return;
        }
        // Banda compactă: tot cardul e clicabil și deschide direct nivelul 2.
        if (root.classList.contains('has-open') && !node.hasAttribute('data-open')
            && !event.target.closest('[data-dcv-go], [data-dcs-go], [data-dcr-go], [data-dci-go]') && open) {
            open(2);
        }
    });

    setVariant(variant(), false);
})();
