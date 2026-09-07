/**
 * Modulul de echipamente — interacțiunile paginilor „Echipamente șoferi” și
 * „Stoc echipamente” (ambele folosesc aceleași primitive vizuale).
 *
 * Responsabilități:
 *   1. rânduri colapsabile (șofer sau articol de stoc);
 *   2. filtre aplicate la schimbare, fără buton separat;
 *   3. modale alimentate din data-atributele butonului apăsat, inclusiv
 *      verificarea de stoc pentru înlocuire.
 *
 * Regula de stoc pe care o aplică interfața: disponibilul se citește PE MĂRIME.
 * Bocancii de 42 nu rezolvă o înlocuire de 43, oricât de mare ar fi totalul.
 */
(function () {
    'use strict';

    const page = document.querySelector('[data-des-page], [data-des-stock-page], [data-des-catalog-page]');
    if (!page) {
        return;
    }

    const isStockPage = page.hasAttribute('data-des-stock-page');
    const searchTerm = (page.getAttribute('data-des-search') || '').trim();

    /** Disponibilitatea din stoc, indexată după articol din catalog. */
    const stockByCatalog = (function () {
        const holder = document.getElementById('des-stock-data');
        const map = new Map();
        if (!holder) {
            return map;
        }
        try {
            JSON.parse(holder.textContent || '[]').forEach(function (item) {
                map.set(String(item.catalog_id), item);
            });
        } catch (error) {
            // Fără date de stoc rămân active doar operațiunile care nu depind de el.
        }
        return map;
    })();

    // ----------------------------------------------------------------------
    // 1. Rânduri colapsabile
    // ----------------------------------------------------------------------

    function togglePanel(row, force) {
        const panel = document.getElementById(row.getAttribute('data-des-row'));
        if (!panel) {
            return;
        }
        const open = typeof force === 'boolean' ? force : panel.hasAttribute('hidden');
        panel.hidden = !open;
        row.classList.toggle('is-open', open);
        row.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    page.querySelectorAll('[data-des-row]').forEach(function (row) {
        row.addEventListener('click', function (event) {
            if (event.target.closest('[data-des-stop], a, button, input, select, label')) {
                return;
            }
            togglePanel(row);
        });

        row.addEventListener('keydown', function (event) {
            if ((event.key !== 'Enter' && event.key !== ' ') || event.target !== row) {
                return;
            }
            event.preventDefault();
            togglePanel(row);
        });
    });

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-des-expand]');
        if (button) {
            const row = page.querySelector('[data-des-row="' + button.getAttribute('data-des-expand') + '"]');
            if (!row) {
                return;
            }
            togglePanel(row, true);
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // ----------------------------------------------------------------------
    // 2. Filtre
    // ----------------------------------------------------------------------

    const filterForm = page.querySelector('[data-des-filters], #desStockFilters');
    if (filterForm) {
        filterForm.querySelectorAll('select').forEach(function (control) {
            control.addEventListener('change', function () {
                filterForm.submit();
            });
        });
    }

    // ----------------------------------------------------------------------
    // 2b. ActionMenu: meniul „…” ca strat plutitor, comun întregului modul
    // ----------------------------------------------------------------------

    /*
     * Problema rezolvată aici: meniul era randat în interiorul tabelului, care
     * are propriul overflow, deci se tăia și obliga utilizatorul să deruleze
     * tabelul ca să ajungă la acțiuni.
     *
     * Soluția: la deschidere meniul este mutat în <body> (portal) și poziționat
     * `fixed` din getBoundingClientRect() al butonului — în jos dacă are loc, în
     * sus dacă nu, și deplasat pe orizontală ca să rămână în fereastră. La
     * închidere se întoarce exact unde era în DOM.
     */
    const actionMenu = (function () {
        const MARGIN = 10;
        const GAP = 4;
        let openMenu = null;
        let openTrigger = null;
        let anchorNode = null;

        function position(fromScroll) {
            if (!openMenu || !openTrigger) {
                return;
            }

            const rect = openTrigger.getBoundingClientRect();

            // Doar la derulare: dacă butonul a ieșit din fereastră, meniul nu are
            // voie să rămână plutind rupt de rândul lui. La deschidere poziția
            // se calculează oricum, chiar dacă butonul e la marginea ecranului.
            if (fromScroll === true && (rect.bottom < 0 || rect.top > window.innerHeight)) {
                close();
                return;
            }

            openMenu.classList.remove('is-scrollable');
            openMenu.style.maxHeight = '';
            openMenu.style.top = '0px';
            openMenu.style.left = '0px';

            const height = openMenu.offsetHeight;
            const width = openMenu.offsetWidth;
            const below = window.innerHeight - rect.bottom - MARGIN;
            const above = rect.top - MARGIN;

            let top;
            if (height + GAP <= below) {
                top = rect.bottom + GAP;
            } else if (height + GAP <= above) {
                top = rect.top - height - GAP;
            } else {
                // Nu încape întreg în niciuna dintre direcții: alege partea mai
                // generoasă și derulează DOAR meniul, nu tabelul de dedesubt.
                const space = Math.max(below, above) - GAP;
                openMenu.style.maxHeight = space + 'px';
                openMenu.classList.add('is-scrollable');
                top = below >= above ? rect.bottom + GAP : rect.top - space - GAP;
            }

            // Meniurile sunt aliniate la dreapta butonului, dar nu ies din ecran.
            let left = rect.right - width;
            if (left + width > window.innerWidth - MARGIN) {
                left = window.innerWidth - MARGIN - width;
            }
            if (left < MARGIN) {
                left = MARGIN;
            }

            // Ultima gardă: meniul rămâne integral în fereastră, oricât de jos
            // sau de sus ar fi butonul care l-a deschis.
            const maxTop = window.innerHeight - openMenu.offsetHeight - MARGIN;
            openMenu.style.top = Math.max(MARGIN, Math.min(top, Math.max(MARGIN, maxTop))) + 'px';
            openMenu.style.left = left + 'px';
        }

        function close() {
            if (!openMenu) {
                return;
            }

            openMenu.classList.remove('show', 'des-floating-menu', 'is-scrollable');
            openMenu.removeAttribute('style');
            if (anchorNode && anchorNode.parentNode) {
                anchorNode.parentNode.insertBefore(openMenu, anchorNode);
                anchorNode.parentNode.removeChild(anchorNode);
            }
            if (openTrigger) {
                openTrigger.setAttribute('aria-expanded', 'false');
            }

            openMenu = null;
            openTrigger = null;
            anchorNode = null;
        }

        function open(trigger) {
            const container = trigger.closest('.dropdown');
            const menu = container ? container.querySelector('.dropdown-menu') : null;
            if (!menu) {
                return;
            }

            close();

            anchorNode = document.createComment('des-action-menu');
            menu.parentNode.insertBefore(anchorNode, menu);
            document.body.appendChild(menu);
            menu.classList.add('show', 'des-floating-menu');

            openMenu = menu;
            openTrigger = trigger;
            trigger.setAttribute('aria-expanded', 'true');
            position();
        }

        function isOpenFor(trigger) {
            return openTrigger === trigger;
        }

        return { open: open, close: close, position: position, isOpenFor: isOpenFor };
    })();

    // Bootstrap ar deschide meniul în interiorul tabelului; îl scoatem din joc
    // pentru butoanele modulului și îl înlocuim cu stratul plutitor de mai sus.
    document.querySelectorAll('.des-menu-btn[data-bs-toggle="dropdown"]').forEach(function (trigger) {
        trigger.removeAttribute('data-bs-toggle');
        trigger.setAttribute('aria-haspopup', 'true');
        trigger.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('.des-menu-btn');

        if (trigger) {
            event.preventDefault();
            event.stopPropagation();
            if (actionMenu.isOpenFor(trigger)) {
                actionMenu.close();
            } else {
                actionMenu.open(trigger);
            }
            return;
        }

        // Clic pe o acțiune din meniu: acțiunea se execută, meniul se închide.
        // Clic în afara meniului: doar se închide.
        actionMenu.close();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            actionMenu.close();
        }
    });

    // La derulare meniul rămâne lipit de butonul lui; dacă butonul iese din
    // fereastră (a fost derulat tabelul), meniul se închide singur.
    ['scroll', 'resize'].forEach(function (type) {
        window.addEventListener(type, function () {
            actionMenu.position(true);
        }, true);
    });

    // ----------------------------------------------------------------------
    // 3. Modale
    // ----------------------------------------------------------------------

    const modals = {
        handover: document.getElementById('desHandoverModal'),
        replace: document.getElementById('desReplaceModal'),
        return: document.getElementById('desReturnModal'),
        mark: document.getElementById('desMarkModal'),
        details: document.getElementById('desDetailsModal'),
        'stock-in': document.getElementById('desStockInModal'),
        'stock-out': document.getElementById('desStockOutModal'),
        'stock-settings': document.getElementById('desStockSettingsModal')
    };

    const stockPageUrl = page.getAttribute('data-des-stock-url') || '';

    const markCopy = {
        deteriorat: {
            titlu: 'Marchează ca deteriorat',
            subtitlu: 'Articolul trece în „necesită înlocuire”.',
            efect: 'Articolul nu revine în stocul disponibil dacă este returnat în această stare.'
        },
        de_inlocuit: {
            titlu: 'Marchează pentru înlocuire',
            subtitlu: 'Articolul rămâne în uz, dar intră în lista de înlocuiri.',
            efect: 'Va apărea în indicatorul „De înlocuit” și la totalul „De returnat” al șoferului.'
        },
        pierdut: {
            titlu: 'Marchează ca pierdut',
            subtitlu: 'Articolul iese din evidența activă a șoferului.',
            efect: 'Nu se întoarce în stoc și rămâne în istoricul mișcărilor.'
        },
        bun: {
            titlu: 'Readu articolul în stare bună',
            subtitlu: 'Anulează marcajul de deteriorare sau de înlocuire.',
            efect: 'Articolul revine la statusul normal de utilizare.'
        }
    };

    function setText(modal, key, value) {
        modal.querySelectorAll('[data-des-text="' + key + '"]').forEach(function (node) {
            node.textContent = value;
        });
    }

    function field(modal, name) {
        return modal.querySelector('[data-des-field="' + name + '"]');
    }

    function setCallout(node, tone, title, message, link) {
        if (!node) {
            return;
        }
        node.classList.remove('is-success', 'is-danger', 'is-neutral');
        node.classList.add('is-' + tone);

        const icon = node.querySelector('i');
        if (icon) {
            icon.className = 'bi ' + (tone === 'success'
                ? 'bi-check-circle'
                : tone === 'danger' ? 'bi-exclamation-octagon' : 'bi-info-circle');
        }

        const strong = node.querySelector('strong');
        const span = node.querySelector('span');
        if (strong) {
            strong.textContent = title;
        }
        if (span) {
            span.textContent = message;
        }

        // Scurtătura „Vezi stoc” apare doar când chiar lipsește marfa.
        const existing = node.querySelector('[data-des-stock-link]');
        if (existing) {
            existing.remove();
        }
        if (link && stockPageUrl) {
            const anchor = document.createElement('a');
            anchor.setAttribute('data-des-stock-link', '');
            anchor.className = 'des-callout-link';
            anchor.href = stockPageUrl;
            anchor.textContent = 'Vezi stoc';
            const body = node.querySelector('div');
            if (body) {
                body.appendChild(anchor);
            }
        }
    }

    /** Afișează doar câmpurile cerute de logica articolului selectat. */
    function applyItemLogic(modal, option) {
        const needsSize = option ? option.getAttribute('data-marime') === '1' : false;
        const needsIdentifier = option ? option.getAttribute('data-identificator') === '1' : false;
        const isService = option ? option.getAttribute('data-logic') === 'service_asset' : false;
        const serialized = option ? option.getAttribute('data-serializat') === '1' : false;

        const toggles = {
            marime: needsSize,
            identificator: needsIdentifier,
            operator: isService,
            serie: serialized && !isService,
            sim: isService
        };

        Object.keys(toggles).forEach(function (key) {
            modal.querySelectorAll('[data-des-when="' + key + '"]').forEach(function (node) {
                node.hidden = !toggles[key];
            });
        });
    }

    function currentSize(modal) {
        const input = modal.querySelector('input[name="marime"]');
        return input && !input.closest('[hidden]') ? input.value.trim() : '';
    }

    /**
     * Disponibilul relevant pentru selecția curentă: pe mărimea cerută dacă
     * articolul are mărimi, altfel totalul liber.
     */
    function availability(info, size) {
        if (!info) {
            return { free: 0, other: 0, sized: false };
        }
        if (!info.necesita_marime || !size) {
            return { free: Number(info.liber) || 0, other: 0, sized: false };
        }

        const bySize = info.pe_marime || {};
        const free = Number(bySize[size]) || 0;
        let other = 0;
        Object.keys(bySize).forEach(function (key) {
            if (key !== size) {
                other += Number(bySize[key]) || 0;
            }
        });

        return { free: free, other: other, sized: true };
    }

    /** Regula de înlocuire: fără stoc utilizabil, operațiunea se blochează. */
    function refreshStockHint(modal, mode) {
        const select = field(modal, 'catalog');
        const hint = modal.querySelector('[data-des-stock-hint]');
        const submit = modal.querySelector('[data-des-submit]');
        if (!select) {
            return;
        }

        const option = select.selectedOptions[0] || null;
        applyItemLogic(modal, option);

        if (!select.value) {
            setCallout(hint, 'neutral', 'Selectează un articol', 'Disponibilitatea din stoc apare aici.');
            if (submit) {
                submit.disabled = false;
            }
            return;
        }

        const info = stockByCatalog.get(String(select.value));
        const size = currentSize(modal);
        const state = availability(info, size);
        const location = info ? info.locatie : '';
        const quantityInput = modal.querySelector('input[name="cantitate"]');
        const requested = quantityInput ? Math.max(1, parseInt(quantityInput.value, 10) || 1) : 1;
        const sizeLabel = state.sized ? ' pentru mărimea ' + size : '';

        if (mode === 'stock-in') {
            setCallout(
                hint,
                'neutral',
                'Stoc curent: ' + state.free + ' buc.' + sizeLabel,
                location ? 'Gestiune implicită: ' + location : ''
            );
            return;
        }

        if (mode === 'stock-out') {
            setCallout(
                hint,
                state.free >= requested ? 'success' : 'danger',
                'Disponibil: ' + state.free + ' buc.' + sizeLabel,
                state.free >= requested
                    ? 'Ieșirea scade stocul cu ' + requested + ' buc.'
                    : 'Cantitatea cerută depășește stocul disponibil.'
            );
            return;
        }

        const usable = info ? info.utilizabil : false;

        if (state.free >= requested && usable) {
            setCallout(
                hint,
                'success',
                state.free + ' disponibile în stoc' + sizeLabel,
                'Înlocuirea se face din gestiunea „' + location + '” și scade stocul cu ' + requested + ' buc.'
            );
            if (submit) {
                submit.disabled = false;
            }
            return;
        }

        if (state.free > 0 && !usable) {
            setCallout(
                hint,
                'danger',
                'Stoc indisponibil',
                'Bucățile din gestiune sunt marcate ca neutilizabile la înlocuire. Este necesară aprovizionarea.',
                true
            );
        } else if (state.free > 0) {
            setCallout(
                hint,
                'danger',
                'Stoc insuficient: ' + state.free + ' buc.' + sizeLabel,
                'Ai cerut ' + requested + ' buc. Reduce cantitatea sau aprovizionează.',
                true
            );
        } else if (state.sized && state.other > 0) {
            setCallout(
                hint,
                'danger',
                '0 disponibile pentru mărimea ' + size,
                state.other + ' bucăți disponibile în alte mărimi. Este necesară aprovizionarea pe mărimea cerută.',
                true
            );
        } else {
            setCallout(
                hint,
                'danger',
                'Stoc indisponibil',
                'Este necesară aprovizionarea.',
                true
            );
        }

        if (submit) {
            submit.disabled = mode === 'replace';
        }
    }

    function openModal(name, trigger) {
        const modal = modals[name];
        if (!modal || typeof bootstrap === 'undefined') {
            return;
        }

        const data = function (key) {
            return trigger ? (trigger.getAttribute('data-des-' + key) || '') : '';
        };

        if (name === 'handover') {
            const form = modal.querySelector('form');
            if (form) {
                form.reset();
            }
            const driver = field(modal, 'driver');
            const catalog = field(modal, 'catalog');
            if (driver && data('sofer-id')) {
                driver.value = data('sofer-id');
            }
            if (catalog && data('catalog')) {
                catalog.value = data('catalog');
            }
            refreshStockHint(modal, 'handover');
        }

        if (name === 'replace') {
            const allocation = field(modal, 'alocare');
            const catalog = field(modal, 'catalog');
            if (allocation) {
                allocation.value = data('alocare');
            }
            if (catalog && data('catalog')) {
                catalog.value = data('catalog');
            }
            const quantity = modal.querySelector('input[name="cantitate"]');
            if (quantity) {
                quantity.value = data('cantitate') || '1';
            }
            const size = modal.querySelector('input[name="marime"]');
            if (size) {
                size.value = data('marime');
            }
            setText(modal, 'denumire', data('denumire'));
            setText(modal, 'sofer', data('sofer'));
            setText(modal, 'stare', data('stare') || '—');
            refreshStockHint(modal, 'replace');
        }

        if (name === 'return') {
            const allocation = field(modal, 'alocare');
            if (allocation) {
                allocation.value = data('alocare');
            }
            setText(modal, 'denumire', data('denumire'));
            setText(modal, 'sofer', data('sofer'));

            const returnable = data('returnabil') === '1';
            setCallout(
                modal.querySelector('[data-des-returnable-hint]'),
                returnable ? 'success' : 'neutral',
                returnable ? 'Articol returnabil' : 'Articol nereturnabil',
                returnable
                    ? 'Alegând „revine în stoc”, stocul disponibil crește cu cantitatea returnată.'
                    : 'Articolul se consumă prin folosire: se închide alocarea, dar stocul nu crește.'
            );
        }

        if (name === 'mark') {
            const key = data('marcaj');
            const copy = markCopy[key] || markCopy.deteriorat;
            const allocation = field(modal, 'alocare');
            const mark = field(modal, 'marcaj');
            if (allocation) {
                allocation.value = data('alocare');
            }
            if (mark) {
                mark.value = key;
            }
            setText(modal, 'titlu', copy.titlu);
            setText(modal, 'subtitlu', copy.subtitlu);
            setText(modal, 'denumire', data('denumire'));
            setText(modal, 'sofer', data('sofer'));
            setCallout(modal.querySelector('[data-des-mark-hint]'), 'neutral', 'Efect', copy.efect);
        }

        if (name === 'details') {
            [
                'denumire', 'sofer', 'categorie', 'logic', 'cantitate', 'marime',
                'identificator', 'predare', 'termen', 'cost', 'stare', 'status', 'observatii'
            ].forEach(function (key) {
                setText(modal, key, data(key) || '—');
            });
            setText(modal, 'returnabil', data('returnabil') === '1' ? 'Da' : 'Nu');
        }

        if (name === 'stock-in' || name === 'stock-out') {
            const form = modal.querySelector('form');
            if (form) {
                form.reset();
            }

            const catalog = field(modal, 'catalog');
            if (catalog && data('catalog')) {
                catalog.value = data('catalog');
            }
            const location = field(modal, 'locatie');
            if (location) {
                location.value = data('locatie');
            }

            // Mărimea trebuie setată după ce logica articolului a făcut vizibil câmpul.
            applyItemLogic(modal, catalog ? catalog.selectedOptions[0] : null);
            const size = modal.querySelector('input[name="marime"]');
            if (size) {
                size.value = data('marime');
            }

            if (name === 'stock-out') {
                const unit = field(modal, 'unitate');
                const unitLine = modal.querySelector('[data-des-unit-line]');
                const unitId = data('unitate');
                if (unit) {
                    unit.value = unitId;
                }
                if (unitLine) {
                    unitLine.hidden = unitId === '';
                }
                setText(modal, 'unitate', data('eticheta') || '—');

                // Pe o unitate anume cantitatea este fixă: o bucată identificată.
                const quantity = field(modal, 'cantitate');
                if (quantity) {
                    quantity.value = '1';
                    quantity.readOnly = unitId !== '';
                }
            }

            refreshStockHint(modal, name);
        }

        if (name === 'stock-settings') {
            const stock = field(modal, 'stoc');
            if (stock) {
                stock.value = data('stoc');
            }
            const threshold = field(modal, 'prag');
            if (threshold) {
                threshold.value = data('prag');
            }
            const cost = field(modal, 'cost');
            if (cost) {
                cost.value = data('cost');
            }
            const usable = field(modal, 'utilizabil');
            if (usable) {
                usable.checked = data('utilizabil') === '1';
            }
            const notes = field(modal, 'observatii');
            if (notes) {
                notes.value = data('observatii');
            }
            setText(modal, 'denumire', data('denumire'));
        }

        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    // Delegare pe document: meniurile de acțiuni sunt mutate în <body> cât timp
    // sunt deschise, deci nu mai sunt descendenți ai paginii.
    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-des-open]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        openModal(trigger.getAttribute('data-des-open'), trigger);
    });

    // Recalculează mesajul de stoc când se schimbă articolul, mărimea sau cantitatea.
    ['handover', 'replace', 'stock-in', 'stock-out'].forEach(function (name) {
        const modal = modals[name];
        if (!modal) {
            return;
        }

        const refresh = function () {
            refreshStockHint(modal, name);
        };

        const select = field(modal, 'catalog');
        if (select) {
            select.addEventListener('change', refresh);
        }
        ['cantitate', 'marime'].forEach(function (inputName) {
            const input = modal.querySelector('input[name="' + inputName + '"]');
            if (input) {
                input.addEventListener('input', refresh);
            }
        });
    });

    // ----------------------------------------------------------------------
    // 3b. Secțiunile de echipament se pot plia individual
    // ----------------------------------------------------------------------

    // Pliază doar conținutul secțiunii; secțiunile rămân în aceeași coloană
    // verticală, nu se rearanjează una lângă alta.
    page.addEventListener('click', function (event) {
        const head = event.target.closest('[data-des-section]');
        if (!head || !page.contains(head)) {
            return;
        }

        const body = document.getElementById(head.getAttribute('data-des-section'));
        const section = head.closest('.des-section');
        if (!body || !section) {
            return;
        }

        const collapsed = section.classList.toggle('is-collapsed');
        body.hidden = collapsed;
        head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });

    // ----------------------------------------------------------------------
    // 4. Catalog: formularul de articol
    // ----------------------------------------------------------------------

    // Bootstrap se încarcă în footer, adică DUPĂ scripturile paginii, deci
    // prezența lui se verifică la click, nu la inițializare.
    (function () {
        const catalogModal = document.getElementById('desCatalogModal');
        const deleteModal = document.getElementById('desCatalogDeleteModal');
        if (!catalogModal) {
            return;
        }

        const defaults = {
            id: '', denumire: '', categorie: 'PPE', grupa: 'fizic', destinatie: 'ambele', tip_logic: 'stare',
            returnabil: 0, urmareste_stare: 1, inlocuire_periodica: 0, durata_standard_luni: 0,
            urmareste_expirare: 0, necesita_marime: 0, necesita_identificator: 0, serializat: 0,
            cost_implicit: 0, cost_lunar: '', prag_minim_stoc: 0,
            locatie_implicita: 'Depozit principal', activ: 1
        };

        function fill(values) {
            Object.keys(values).forEach(function (key) {
                const control = catalogModal.querySelector('[data-des-field="' + key + '"]');
                if (!control) {
                    return;
                }
                if (control.type === 'checkbox') {
                    control.checked = Number(values[key]) === 1;
                } else if (key === 'durata_standard_luni' && Number(values[key]) === 0) {
                    control.value = '';
                } else {
                    control.value = values[key];
                }
            });
            setText(catalogModal, 'titlu', values.id ? 'Editează articolul' : 'Articol nou');
        }

        document.addEventListener('click', function (event) {
            const create = event.target.closest('[data-des-catalog-new]');
            const edit = event.target.closest('[data-des-catalog-edit]');
            const remove = event.target.closest('[data-des-catalog-delete]');

            if (!create && !edit && !remove) {
                return;
            }
            event.preventDefault();

            if (typeof bootstrap === 'undefined') {
                return;
            }

            if (create) {
                fill(defaults);
                bootstrap.Modal.getOrCreateInstance(catalogModal).show();
                return;
            }

            if (edit) {
                try {
                    fill(Object.assign({}, defaults, JSON.parse(edit.getAttribute('data-des-catalog-edit'))));
                } catch (error) {
                    // Articolul rămâne needitat dacă datele nu pot fi citite.
                    return;
                }
                bootstrap.Modal.getOrCreateInstance(catalogModal).show();
                return;
            }

            if (remove && deleteModal) {
                const idField = deleteModal.querySelector('[data-des-field="id"]');
                if (idField) {
                    idField.value = remove.getAttribute('data-des-catalog-delete');
                }
                setText(deleteModal, 'denumire', remove.getAttribute('data-des-denumire') || '');
                bootstrap.Modal.getOrCreateInstance(deleteModal).show();
            }
        });
    })();

    // ----------------------------------------------------------------------
    // 5. Căutare: rezultatele se deschid singure și se evidențiază
    // ----------------------------------------------------------------------

    /** Diacriticele nu trebuie să împiedice potrivirea: „vesta” găsește „vestă”. */
    function normalize(text) {
        return text
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[șş]/g, 's')
            .replace(/[țţ]/g, 't');
    }

    /** Evidențiază termenul în textul deja randat, fără a atinge markup-ul. */
    function highlight(root, term) {
        const needle = normalize(term);
        if (needle === '') {
            return;
        }

        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                if (!node.nodeValue || node.nodeValue.trim() === '') {
                    return NodeFilter.FILTER_REJECT;
                }
                const parent = node.parentElement;
                if (!parent || parent.closest('script, style, mark, .des-menu-btn, .dropdown-menu, .modal')) {
                    return NodeFilter.FILTER_REJECT;
                }
                return normalize(node.nodeValue).includes(needle)
                    ? NodeFilter.FILTER_ACCEPT
                    : NodeFilter.FILTER_REJECT;
            }
        });

        const targets = [];
        while (walker.nextNode()) {
            targets.push(walker.currentNode);
        }

        targets.forEach(function (node) {
            const text = node.nodeValue;
            const haystack = normalize(text);
            const fragment = document.createDocumentFragment();
            let cursor = 0;
            let index = haystack.indexOf(needle);

            // Normalizarea păstrează lungimea caracterelor, deci indecșii din
            // varianta normalizată sunt valabili și pe textul original.
            while (index !== -1) {
                if (index > cursor) {
                    fragment.appendChild(document.createTextNode(text.slice(cursor, index)));
                }
                const mark = document.createElement('mark');
                mark.className = 'des-mark';
                mark.textContent = text.slice(index, index + needle.length);
                fragment.appendChild(mark);
                cursor = index + needle.length;
                index = haystack.indexOf(needle, cursor);
            }

            if (cursor < text.length) {
                fragment.appendChild(document.createTextNode(text.slice(cursor)));
            }
            node.parentNode.replaceChild(fragment, node);
        });
    }

    if (searchTerm !== '') {
        // Căutarea trebuie să răspundă direct cu ce am găsit: rândurile care
        // conțin rezultate se deschid singure, nu mai cer un clic în plus.
        page.querySelectorAll('[data-des-row]').forEach(function (row) {
            togglePanel(row, true);
        });

        page.querySelectorAll('.des-table').forEach(function (table) {
            highlight(table, searchTerm);
        });

        const firstRow = page.querySelector('[data-des-row]');
        if (firstRow) {
            firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    // Pe pagina de stoc rândurile extinse rămân deschise după o operațiune,
    // dacă utilizatorul revine prin ancora #stoc.
    if (isStockPage && window.location.hash === '#stoc') {
        const first = page.querySelector('[data-des-row]');
        if (first) {
            first.scrollIntoView({ block: 'start' });
        }
    }
})();
