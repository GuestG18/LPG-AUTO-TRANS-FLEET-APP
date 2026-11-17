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

    function go(next) {
        next = Number(next);
        if (next === level) {
            return;
        }
        const fromRect = card.getBoundingClientRect();
        card.querySelectorAll('[data-dcv-face]').forEach(function (node) {
            node.hidden = node.getAttribute('data-dcv-face') !== String(next);
        });
        card.setAttribute('data-dcv-level', String(next));
        level = next;

        const target = face(next);
        if (reduceMotion.matches || !card.animate) {
            focusFace(target);
            return;
        }
        // Cardul crește / scade în lățime și înălțime (KPI compact -> listă -> detaliu);
        // cardurile de stare se mută odată cu el.
        const toRect = card.getBoundingClientRect();
        if (Math.abs(toRect.height - fromRect.height) > 1 || Math.abs(toRect.width - fromRect.width) > 1) {
            card.animate([
                { width: fromRect.width + 'px', height: fromRect.height + 'px' },
                { width: toRect.width + 'px', height: toRect.height + 'px' },
            ], {
                duration: 320,
                easing: 'cubic-bezier(.22,.8,.24,1)',
            });
        }
        target.classList.remove('is-entering');
        void target.offsetWidth;
        target.classList.add('is-entering');
        window.setTimeout(function () { target.classList.remove('is-entering'); }, 520);
        focusFace(target);
    }

    function focusFace(target) {
        const focusable = target.querySelector('[data-dcv-search], .dcv-back, .dcv-total');
        if (focusable) {
            focusable.focus({ preventScroll: true });
        }
    }

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

    setVariant(variant(), false);
})();
