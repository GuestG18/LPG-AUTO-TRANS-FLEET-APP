/**
 * Situație eToll (?page=etoll): filtrele cu lista de bife, tab-urile sesiunilor, sortarea,
 * paginarea, selectia randurilor, exportul CSV si plierea alimentarilor.
 *
 * Filtrele se trimit la server doar la „Aplică filtre” (GET): bifele nu fac cereri. Sesiunile
 * perioadei vin deja in pagina (#etollData); tab-urile / sortarea / paginarea doar redeseneaza
 * pagina curenta a tabelului, fara cereri noi.
 */
(() => {
    'use strict';

    const page = document.getElementById('etollPage');
    const dataNode = document.getElementById('etollData');
    if (!page || !dataNode) {
        return;
    }
    let data;
    try {
        data = JSON.parse(dataNode.textContent || '{}');
    } catch (error) {
        data = { rows: [] };
    }
    const rows = Array.isArray(data.rows) ? data.rows : [];

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    const store = {
        get(key) {
            try { return window.localStorage.getItem(key); } catch (error) { return null; }
        },
        set(key, value) {
            try { window.localStorage.setItem(key, value); } catch (error) { /* stocare indisponibila */ }
        },
    };

    // ---------------------------------------------------------------------
    // Dropdown-urile filtrelor (un singur popup deschis)
    // ---------------------------------------------------------------------
    const form = document.getElementById('etollFilterForm');
    const dropdowns = [...page.querySelectorAll('.etoll-filter')];
    let openDropdown = null;

    const closeDropdown = (dropdown, focusTrigger = false) => {
        if (!dropdown) {
            return;
        }
        const pop = dropdown.querySelector('[data-ms-pop]');
        const trigger = dropdown.querySelector('[data-ms-trigger]');
        dropdown.classList.remove('is-open');
        pop.hidden = true;
        pop.classList.remove('is-right');
        trigger.setAttribute('aria-expanded', 'false');
        if (openDropdown === dropdown) {
            openDropdown = null;
        }
        if (focusTrigger) {
            trigger.focus();
        }
    };

    const openDropdownFor = (dropdown) => {
        if (openDropdown && openDropdown !== dropdown) {
            closeDropdown(openDropdown);
        }
        const pop = dropdown.querySelector('[data-ms-pop]');
        dropdown.classList.add('is-open');
        pop.hidden = false;
        dropdown.querySelector('[data-ms-trigger]').setAttribute('aria-expanded', 'true');
        openDropdown = dropdown;
        // Nu iesi din ecran in dreapta.
        const rect = pop.getBoundingClientRect();
        if (rect.right > document.documentElement.clientWidth - 8) {
            pop.classList.add('is-right');
        }
        const search = pop.querySelector('[data-ms-search]');
        (search || pop.querySelector('input:not([disabled])'))?.focus({ preventScroll: true });
    };

    const optionInputs = (dropdown) => [...dropdown.querySelectorAll('.etoll-filter__opt input[type="checkbox"]')];

    const refreshLabel = (dropdown) => {
        const target = dropdown.querySelector('[data-ms-value]');
        if (!target || !dropdown.hasAttribute('data-ms')) {
            return;
        }
        const checked = optionInputs(dropdown).filter((input) => input.checked);
        target.textContent = checked.length === 0 ? 'Toate'
            : checked.length === 1 ? (checked[0].dataset.label || checked[0].value) : `${checked.length} selectate`;
        dropdown.classList.toggle('is-active', checked.length > 0);
    };

    const applySearch = (dropdown, term) => {
        const needle = term.trim().toLowerCase();
        let visible = 0;
        dropdown.querySelectorAll('.etoll-filter__opt').forEach((option) => {
            option.hidden = needle !== '' && !(option.dataset.search || '').includes(needle);
            visible += option.hidden ? 0 : 1;
        });
        // Antetul de grupa ramane doar daca are optiuni vizibile dupa el.
        dropdown.querySelectorAll('[data-ms-group]').forEach((group) => {
            let next = group.nextElementSibling;
            let any = false;
            while (next && next.classList.contains('etoll-filter__opt')) {
                any = any || !next.hidden;
                next = next.nextElementSibling;
            }
            group.hidden = !any;
        });
        const empty = dropdown.querySelector('[data-ms-empty]');
        if (empty) {
            empty.hidden = visible > 0 || optionInputs(dropdown).length === 0;
        }
    };

    dropdowns.forEach((dropdown) => {
        const trigger = dropdown.querySelector('[data-ms-trigger]');
        const pop = dropdown.querySelector('[data-ms-pop]');
        if (!trigger || !pop) {
            return;
        }
        trigger.addEventListener('click', () => {
            if (dropdown.classList.contains('is-open')) {
                closeDropdown(dropdown);
            } else {
                openDropdownFor(dropdown);
            }
        });
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && !dropdown.classList.contains('is-open')) {
                event.preventDefault();
                openDropdownFor(dropdown);
            }
        });
        const search = pop.querySelector('[data-ms-search]');
        if (search) {
            search.addEventListener('input', () => applySearch(dropdown, search.value));
            search.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    // Enter in cautare nu trimite formularul: duce la prima optiune.
                    event.preventDefault();
                    optionInputs(dropdown).find((input) => !input.closest('.etoll-filter__opt').hidden)?.focus();
                } else if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    optionInputs(dropdown).find((input) => !input.closest('.etoll-filter__opt').hidden)?.focus();
                }
            });
        }
        pop.querySelector('[data-ms-all]')?.addEventListener('click', () => {
            optionInputs(dropdown).forEach((input) => {
                if (!input.closest('.etoll-filter__opt').hidden) {
                    input.checked = true;
                }
            });
            refreshLabel(dropdown);
        });
        pop.querySelector('[data-ms-clear]')?.addEventListener('click', () => {
            optionInputs(dropdown).forEach((input) => { input.checked = false; });
            refreshLabel(dropdown);
        });
        pop.addEventListener('change', (event) => {
            if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
                refreshLabel(dropdown);
            }
        });
        // Sageti sus/jos intre optiunile vizibile; Space bifeaza (nativ).
        pop.addEventListener('keydown', (event) => {
            if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'checkbox') {
                return;
            }
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    event.target.click();
                }
                return;
            }
            event.preventDefault();
            const visible = optionInputs(dropdown).filter((input) => !input.closest('.etoll-filter__opt').hidden);
            const index = visible.indexOf(event.target);
            if (event.key === 'ArrowUp' && index <= 0) {
                (search || trigger).focus();
                return;
            }
            visible[Math.min(visible.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1))]?.focus();
        });
        // Rotita in lista nu deruleaza pagina cand lista ajunge la capat.
        const list = pop.querySelector('.etoll-filter__list');
        list?.addEventListener('wheel', (event) => {
            const atTop = list.scrollTop <= 0 && event.deltaY < 0;
            const atBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 1 && event.deltaY > 0;
            if ((atTop || atBottom) && list.scrollHeight > list.clientHeight) {
                event.preventDefault();
            }
        }, { passive: false });
    });

    document.addEventListener('pointerdown', (event) => {
        if (openDropdown && !openDropdown.contains(event.target)) {
            closeDropdown(openDropdown);
        }
    });
    document.addEventListener('keydown', (event) => {
        // Escape cu un calendar deschis inchide doar calendarul (il inchide datetime-picker-field.js).
        if (event.key === 'Escape' && openDropdown?.querySelector('.dispatcher-datetime-popover:not([hidden])')) {
            return;
        }
        if (event.key === 'Escape' && openDropdown) {
            event.preventDefault();
            closeDropdown(openDropdown, true);
        }
    }, true); // capture: ruleaza inaintea handler-ului selectorului de data, cat calendarul e inca deschis
    page.addEventListener('focusout', (event) => {
        if (openDropdown && event.relatedTarget instanceof Node && !openDropdown.contains(event.relatedTarget)) {
            closeDropdown(openDropdown);
        }
    });

    // Perioada: luna / an / interval (campurile inactive nu se trimit).
    const periodBox = page.querySelector('[data-period]');
    if (periodBox) {
        const valueNode = periodBox.querySelector('[data-period-value]');
        const fmt = (iso) => (iso && /^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso.split('-').reverse().join('.') : '…');
        const monthEnd = (ym) => {
            const [y, m] = ym.split('-').map(Number);
            return `${ym}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
        };
        // „Închisă la”: selectorul data + ora din Dispecer (datetime-picker-field.js) scrie data (Y-m-d)
        // si ora (HH:MM) in campuri ascunse; valoarea trimisa e una singura, 2026-11-05T14:00.
        const closedPickers = ['inchisa_de', 'inchisa_pana'].map((key) => periodBox.querySelector(`[data-closed-picker="${key}"]`));
        const closedValue = (index) => {
            const picker = closedPickers[index];
            const date = picker?.querySelector('[data-dtp-date]')?.value || '';
            if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                return '';
            }
            const time = picker.querySelector('[data-dtp-time]')?.value || '';
            return `${date}T${/^\d{2}:\d{2}$/.test(time) ? time : (index === 0 ? '00:00' : '23:59')}`;
        };
        const writeClosedValues = () => closedPickers.forEach((picker, index) => {
            const target = periodBox.querySelector(`[data-closed-value="${index === 0 ? 'inchisa_de' : 'inchisa_pana'}"]`);
            if (target) {
                target.value = closedValue(index);
            }
        });
        const labelNode = periodBox.querySelector('#etollPeriodLabel');
        /** "2026-11-05T14:28" -> "05.11 14:28"; gol -> "…". */
        const fmtDateTime = (value) => {
            const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2})/.exec(value || '');
            return match ? `${match[3]}.${match[2]} ${match[4]}` : '…';
        };
        const sync = () => {
            const type = periodBox.querySelector('input[name="perioada"]:checked')?.value || 'luna';
            periodBox.querySelectorAll('[data-perioada]').forEach((field) => {
                const active = field.dataset.perioada === type;
                field.hidden = !active;
                field.querySelectorAll('input, select').forEach((input) => { input.disabled = !active; });
            });
            if (type === 'luna') {
                const ym = periodBox.querySelector('[name="luna"]').value;
                valueNode.textContent = /^\d{4}-\d{2}$/.test(ym) ? `${fmt(`${ym}-01`)} – ${fmt(monthEnd(ym))}` : '…';
            } else if (type === 'an') {
                valueNode.textContent = `Anul ${periodBox.querySelector('[name="an"]').value}`;
            } else {
                valueNode.textContent = `${fmt(periodBox.querySelector('[name="de_la"]').value)} – ${fmt(periodBox.querySelector('[name="pana_la"]').value)}`;
            }
            // Cu „Închisă la” ales, butonul arata intervalul de inchidere (perioada ramane in title).
            writeClosedValues();
            const closedFrom = closedValue(0);
            const closedTo = closedValue(1);
            const hasClosed = closedFrom !== '' || closedTo !== '';
            periodBox.title = hasClosed ? `Perioada: ${valueNode.textContent}` : '';
            if (hasClosed) {
                valueNode.textContent = `${fmtDateTime(closedFrom)} – ${fmtDateTime(closedTo)}`;
            }
            if (labelNode) {
                labelNode.textContent = hasClosed ? 'Perioadă · Închisă la' : 'Perioadă';
            }
            periodBox.classList.toggle('is-active', hasClosed);
        };
        periodBox.querySelector('[data-closed-clear]')?.addEventListener('click', () => {
            closedPickers.forEach((picker) => {
                picker?.querySelectorAll('[data-dtp-date], [data-dtp-time]').forEach((input) => { input.value = ''; });
            });
            window.FleetDateTimePicker?.sync(periodBox);
            sync();
        });
        // Palnia din antetul coloanei „Închisă la” deschide perioada, cu focus pe „De la”.
        page.querySelector('[data-closed-open]')?.addEventListener('click', (event) => {
            event.stopPropagation();
            openDropdownFor(periodBox);
            window.scrollTo({ top: Math.max(0, window.scrollY + periodBox.getBoundingClientRect().top - 90), behavior: 'smooth' });
            closedPickers[0]?.querySelector('[data-dtp-display]')?.focus({ preventScroll: true });
        });
        periodBox.addEventListener('change', sync);
        periodBox.addEventListener('input', sync);
        sync();
    }

    // Formularul: o singura cerere la „Aplica filtre”; campurile goale nu ajung in URL.
    form?.addEventListener('submit', () => {
        // Textul tastat in selector (fara Enter) se citeste inainte de trimitere.
        form.querySelectorAll('[data-closed-range] [data-dtp-display]').forEach((input) => input.dispatchEvent(new Event('change')));
        const closed = [...form.querySelectorAll('[data-closed-value]')];
        closed.forEach((input) => { input.value = ''; });
        form.querySelectorAll('[data-closed-picker]').forEach((picker, index) => {
            const date = picker.querySelector('[data-dtp-date]').value;
            const time = picker.querySelector('[data-dtp-time]').value;
            if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                closed[index].value = `${date}T${/^\d{2}:\d{2}$/.test(time) ? time : (index === 0 ? '00:00' : '23:59')}`;
            }
        });
        const [from, to] = closed.map((input) => input.value.slice(0, 10));
        // „Închisă la” se cauta in perioada: daca iese din ea, perioada devine Interval si o cuprinde.
        if (from || to) {
            const type = form.querySelector('input[name="perioada"]:checked')?.value || 'luna';
            let start;
            let end;
            if (type === 'luna') {
                const ym = form.querySelector('[name="luna"]').value;
                start = `${ym}-01`;
                const [y, m] = ym.split('-').map(Number);
                end = `${ym}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
            } else if (type === 'an') {
                const year = form.querySelector('[name="an"]').value;
                start = `${year}-01-01`;
                end = `${year}-12-31`;
            } else {
                start = form.querySelector('[name="de_la"]').value;
                end = form.querySelector('[name="pana_la"]').value;
            }
            const wantStart = from || start;
            const wantEnd = to || end;
            if (wantStart < start || wantEnd > end) {
                form.querySelector('input[name="perioada"][value="interval"]').checked = true;
                form.querySelector('[name="de_la"]').value = wantStart < start ? wantStart : start;
                form.querySelector('[name="pana_la"]').value = wantEnd > end ? wantEnd : end;
                form.querySelectorAll('[data-perioada]').forEach((field) => {
                    field.querySelectorAll('input, select').forEach((input) => { input.disabled = field.dataset.perioada !== 'interval'; });
                });
            }
        }
        closed.forEach((input) => { input.disabled = input.value === ''; }); // fara parametri goi in URL
        page.classList.add('is-loading');
    });

    // ---------------------------------------------------------------------
    // Previzualizarea PDF la hover pe link-urile Factură / Anexă (sesiuni si alimentari).
    // Acelasi vizualizator ca in Facturi: <iframe> cu PDF-ul servit inline. Iframe-urile deja
    // incarcate se pastreaza (ultimele 6), ca o a doua trecere sa nu mai descarce documentul.
    // ---------------------------------------------------------------------
    const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    if (canHover) {
        const preview = document.createElement('div');
        preview.className = 'etoll-pdf-preview';
        preview.hidden = true;
        preview.setAttribute('role', 'dialog');
        preview.setAttribute('aria-label', 'Previzualizare document');
        preview.innerHTML = `
            <div class="etoll-pdf-preview__head">
                <span class="etoll-pdf-preview__title" data-preview-title></span>
                <span class="etoll-pdf-preview__hint">Click pe link: se deschide în tab nou</span>
            </div>
            <div class="etoll-pdf-preview__body" data-preview-body>
                <div class="etoll-pdf-preview__loading" data-preview-loading><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă documentul…</div>
            </div>`;
        document.body.appendChild(preview);
        const previewBody = preview.querySelector('[data-preview-body]');
        const previewTitle = preview.querySelector('[data-preview-title]');
        const previewLoading = preview.querySelector('[data-preview-loading]');
        const frames = new Map(); // url -> {frame, loaded}
        const MAX_FRAMES = 6;
        let showTimer = 0;
        let hideTimer = 0;
        let currentLink = null;

        const place = (link) => {
            const rect = link.getBoundingClientRect();
            const width = Math.min(500, window.innerWidth - 24);
            const height = Math.min(660, window.innerHeight - 24);
            const gap = 10;
            // In dreapta link-ului daca incape, altfel in stanga; vertical centrat pe link, in ecran.
            let left = rect.right + gap;
            if (left + width > window.innerWidth - 12) {
                left = Math.max(12, rect.left - gap - width);
            }
            let top = rect.top + rect.height / 2 - height / 2;
            top = Math.min(Math.max(12, top), window.innerHeight - height - 12);
            preview.style.width = `${width}px`;
            preview.style.height = `${height}px`;
            preview.style.left = `${Math.round(left)}px`;
            preview.style.top = `${Math.round(top)}px`;
        };

        const frameFor = (url) => {
            let entry = frames.get(url);
            if (!entry) {
                const frame = document.createElement('iframe');
                frame.className = 'etoll-pdf-preview__frame';
                frame.title = 'Previzualizare PDF';
                frame.setAttribute('tabindex', '-1');
                entry = { frame, loaded: false };
                frame.addEventListener('load', () => {
                    entry.loaded = true;
                    if (frame.classList.contains('is-current')) {
                        previewLoading.hidden = true;
                    }
                });
                frame.src = `${url}#toolbar=0&navpanes=0&statusbar=0&view=FitH`;
                previewBody.appendChild(frame);
                frames.set(url, entry);
                if (frames.size > MAX_FRAMES) {
                    const [oldUrl, oldEntry] = frames.entries().next().value;
                    oldEntry.frame.remove();
                    frames.delete(oldUrl);
                }
            } else {
                // Cel mai recent folosit ajunge la coada (iese ultimul din cache).
                frames.delete(url);
                frames.set(url, entry);
            }
            return entry;
        };

        const show = (link) => {
            const url = link.getAttribute('href');
            if (!url) {
                return;
            }
            currentLink = link;
            const row = link.closest('tr');
            const plate = row?.querySelector('td:nth-child(3) strong')?.textContent || '';
            previewTitle.textContent = [link.dataset.previewTitle || link.textContent.trim(), plate].filter(Boolean).join(' · ');
            const entry = frameFor(url);
            frames.forEach(({ frame }) => frame.classList.toggle('is-current', frame === entry.frame));
            previewLoading.hidden = entry.loaded;
            place(link);
            preview.hidden = false;
        };
        const hide = () => {
            clearTimeout(showTimer);
            preview.hidden = true;
            currentLink = null;
        };
        const scheduleHide = () => {
            clearTimeout(showTimer);
            clearTimeout(hideTimer);
            hideTimer = window.setTimeout(hide, 220);
        };

        page.addEventListener('mouseover', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a.etoll-doc[href]') : null;
            if (!link) {
                return;
            }
            clearTimeout(hideTimer);
            if (link === currentLink && !preview.hidden) {
                return;
            }
            clearTimeout(showTimer);
            showTimer = window.setTimeout(() => show(link), preview.hidden ? 350 : 120);
        });
        page.addEventListener('mouseout', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a.etoll-doc[href]') : null;
            if (link && !(event.relatedTarget instanceof Node && link.contains(event.relatedTarget))) {
                scheduleHide();
            }
        });
        // Mouse-ul poate intra in previzualizare (derulare in PDF) fara s-o inchida.
        preview.addEventListener('mouseenter', () => clearTimeout(hideTimer));
        preview.addEventListener('mouseleave', scheduleHide);
        page.addEventListener('focusin', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a.etoll-doc[href]') : null;
            if (link && link.matches(':focus-visible')) {
                clearTimeout(hideTimer);
                show(link);
            }
        });
        page.addEventListener('focusout', (event) => {
            if (event.target instanceof Element && event.target.closest('a.etoll-doc[href]')) {
                scheduleHide();
            }
        });
        page.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('a.etoll-doc[href]')) {
                hide();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !preview.hidden) {
                hide();
            }
        });
        window.addEventListener('scroll', () => { if (!preview.hidden) hide(); }, { passive: true });
        window.addEventListener('resize', () => { if (!preview.hidden) hide(); });
    }

    // ---------------------------------------------------------------------
    // Tabelul sesiunilor
    // ---------------------------------------------------------------------
    const table = document.getElementById('etollTable');
    const body = table?.querySelector('[data-rows]');
    if (!table || !body) {
        return;
    }
    const params = new URLSearchParams(window.location.search);
    const state = {
        tab: ['closed', 'open', 'missing'].includes(params.get('tab')) ? params.get('tab') : 'closed',
        sort: ['closed', 'plate', 'ben', 'start', 'km', 'net', 'gross'].includes(params.get('sort')) ? params.get('sort') : 'closed',
        dir: params.get('dir') === 'desc' ? 'desc' : 'asc',
        size: [25, 50, 100, 200].includes(Number(store.get('etoll.pageSize'))) ? Number(store.get('etoll.pageSize')) : 50,
        page: 1,
    };
    const selected = new Set();
    const rowsById = new Map(rows.map((row) => [row.id, row]));
    const tabRows = {
        closed: rows.filter((row) => !row.open),
        open: rows.filter((row) => row.open),
        missing: rows.filter((row) => !row.open && row.check === 'lipsa'),
    };
    rows.forEach((row, index) => { row._i = index; });

    const sortValue = {
        closed: (row) => row.closedTs ?? row.startTs ?? 0,
        plate: (row) => row.plate,
        ben: (row) => row.ben || '￿',
        start: (row) => row.startTs ?? 0,
        km: (row) => row.km ?? -1,
        net: (row) => row.net ?? -1,
        gross: (row) => row.gross ?? 0,
    };
    const collator = new Intl.Collator('ro', { sensitivity: 'base', numeric: true });
    let sortedCache = null;
    const currentRows = () => {
        if (sortedCache && sortedCache.key === `${state.tab}|${state.sort}|${state.dir}`) {
            return sortedCache.rows;
        }
        const get = sortValue[state.sort];
        const sign = state.dir === 'desc' ? -1 : 1;
        const list = [...tabRows[state.tab]].sort((a, b) => {
            const va = get(a);
            const vb = get(b);
            const cmp = typeof va === 'string' ? collator.compare(va, vb) : va - vb;
            return cmp !== 0 ? cmp * sign : a._i - b._i; // stabila: ordinea serverului
        });
        sortedCache = { key: `${state.tab}|${state.sort}|${state.dir}`, rows: list };
        return list;
    };

    const docCell = (row) => {
        if (row.open) {
            return '<span class="etoll-muted">după închidere</span>';
        }
        return row.docs.map((doc) => (doc.url
            ? `<a class="etoll-doc" href="${esc(doc.url)}" target="_blank" rel="noopener" aria-label="${esc(doc.title)} (se deschide în tab nou)" data-preview-title="${esc(doc.title)}"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> ${esc(doc.label)}</a>`
            : `<span class="etoll-doc is-missing" title="${esc(doc.title)}: încă nedescărcat"><i class="bi bi-file-earmark-x" aria-hidden="true"></i> ${esc(doc.label)}</span>`)).join('');
    };
    const rowHtml = (row) => {
        const isSelected = selected.has(row.id);
        const ben = row.cursaId
            ? `${esc(row.ben || 'Fără beneficiar')} <a class="etoll-trip" href="${esc(row.cursaUrl)}" target="_blank" rel="noopener" title="Deschide cursa în Dispecer">#${row.cursaId}</a>`
            : '<span class="etoll-muted" title="Sesiunea nu e încă asociată unei curse">—</span>';
        const vatPending = row.net === null && !row.open ? ' title="TVA-ul se citește din documentul eToll la următoarea sincronizare"' : '';
        return `<tr class="${isSelected ? 'is-selected' : ''}" data-id="${esc(row.id)}">
            <td class="t-center"><input type="checkbox" class="form-check-input" aria-label="Selectează sesiunea ${esc(row.plate)} ${esc(row.closed)}" data-row-check ${isSelected ? 'checked' : ''}></td>
            <td class="t-nowrap">${esc(row.closed)}</td>
            <td class="t-nowrap t-trunc"><strong>${esc(row.plate)}</strong>${row.trailer ? ` <span class="etoll-muted" title="Remorcă">+ ${esc(row.trailer)}</span>` : ''}</td>
            <td class="t-trunc">${ben}</td>
            <td class="t-nowrap t-trunc"${row.spanTitle ? ` title="${esc(row.spanTitle)}"` : ''}>${esc(row.span)}${row.spanDays > 0 ? ` <sup class="etoll-plusday" aria-label="a doua zi">+${row.spanDays}</sup>` : ''}</td>
            <td class="t-trunc" title="${esc(row.routeFull)}">${esc(row.route)}</td>
            <td class="t-end">${esc(row.kmText)}</td>
            <td class="t-end t-nowrap"${vatPending}>${esc(row.netText)}</td>
            <td class="t-end t-nowrap"${row.vatRate !== null ? ` title="Cotă TVA ${esc(row.vatRate)}%"` : vatPending}>${esc(row.vatText)}</td>
            <td class="t-end t-nowrap">${row.open ? '<span class="etoll-muted" title="Consumul de până acum">' : ''}${esc(row.grossText)}${row.open ? '</span>' : ''}</td>
            <td class="t-nowrap">${esc(row.doc || '—')}</td>
            <td class="t-nowrap etoll-docs">${docCell(row)}</td>
            <td><span class="etoll-badge ${esc(row.checkClass)}" title="${esc(row.error || row.checkText)}">${esc(row.checkText)}</span></td>
        </tr>`;
    };

    const pagesNode = page.querySelector('[data-pages]');
    const rangeNode = page.querySelector('[data-range]');
    const selectedNode = page.querySelector('[data-selected-count]');
    const selectAll = table.querySelector('[data-select-all]');
    const downloadButton = page.querySelector('[data-download-selected]');

    const pageButtons = (current, total) => {
        const range = (a, b) => Array.from({ length: b - a + 1 }, (_, i) => a + i);
        if (total <= 7) {
            return range(1, total);
        }
        if (current <= 4) {
            return [...range(1, 5), '…', total];
        }
        if (current >= total - 3) {
            return [1, '…', ...range(total - 4, total)];
        }
        return [1, '…', current - 1, current, current + 1, '…', total];
    };

    const refreshSelection = () => {
        const list = tabRows[state.tab];
        const inTab = list.filter((row) => selected.has(row.id)).length;
        selectAll.checked = list.length > 0 && inTab === list.length;
        selectAll.indeterminate = inTab > 0 && inTab < list.length;
        selectAll.disabled = list.length === 0;
        selectedNode.hidden = selected.size === 0;
        selectedNode.textContent = `Selectate: ${selected.size} ${selected.size === 1 ? 'sesiune' : 'sesiuni'}`;
        if (downloadButton) {
            const withDocs = [...selected].filter((id) => rowsById.get(id)?.docs?.some((doc) => doc.url)).length;
            downloadButton.disabled = withDocs === 0;
            downloadButton.title = withDocs === 0 ? 'Bifează sesiuni cu documente' : `Documentele a ${withDocs} sesiuni, într-o arhivă ZIP`;
        }
    };

    const writeUrl = () => {
        const url = new URL(window.location.href);
        const set = (key, value, fallback) => (value === fallback ? url.searchParams.delete(key) : url.searchParams.set(key, value));
        set('tab', state.tab, 'closed');
        set('sort', state.sort, 'closed');
        set('dir', state.dir, 'asc');
        window.history.replaceState(window.history.state, '', url);
    };

    // Subsolul cu totaluri: tot setul filtrat al tab-ului (nu doar pagina), din totalurile serverului.
    const totalsFoot = table.querySelector('[data-totals]');
    const totalsData = data.totals || {};
    let totalScope = 'filtrate';
    const scopeButtons = [...table.querySelectorAll('[data-total-scope]')];
    if (!data.hasFilters || !totalsData.toate) {
        // Fara filtre, „Toate” = „Filtrate”: comutatorul ramane vizibil, dar inactiv.
        scopeButtons.forEach((button) => { button.disabled = button.dataset.totalScope === 'toate'; });
        const all = scopeButtons.find((button) => button.dataset.totalScope === 'toate');
        if (all) all.title = 'Nu sunt filtre aplicate: totalul cuprinde deja toate sesiunile perioadei';
    }
    const setText = (selector, text) => {
        const node = totalsFoot?.querySelector(selector);
        if (node && node.textContent !== text) node.textContent = text;
    };
    const renderTotals = () => {
        if (!totalsFoot) {
            return;
        }
        const t = totalsData[totalScope]?.[state.tab];
        if (!t) {
            totalsFoot.hidden = true;
            return;
        }
        totalsFoot.hidden = false;
        totalsFoot.classList.toggle('is-all', totalScope === 'toate');
        setText('[data-total-count]', `${t.n} ${t.n === 1 ? 'sesiune' : 'sesiuni'}`);
        setText('[data-total-scope-label]', totalScope === 'toate' ? 'toată perioada, fără filtre' : (data.hasFilters ? 'filtrate' : ''));
        const documented = t.documentate > 0;
        setText('[data-total-net]', documented ? t.faraText : '—');
        setText('[data-total-vat]', documented ? t.tvaText : '—');
        const note = t.n === 0 ? '' : (t.documentate === t.n ? 'documentat' : `doc. ${t.documentate}/${t.n}`);
        setText('[data-total-net-note]', note);
        setText('[data-total-vat-note]', note);
        setText('[data-total-gross]', t.sumaText);
        const netCell = totalsFoot.querySelector('[data-total-net]')?.closest('td');
        if (netCell) {
            netCell.title = t.documentate < t.n
                ? `Doar ${t.documentate} din ${t.n} sesiuni au TVA-ul citit din documentul eToll; restul nu sunt adunate ca 0.`
                : 'Din documentele eToll ale sesiunilor';
        }
        totalsFoot.querySelector('[data-total-vat]')?.closest('td')?.setAttribute('title', netCell?.title || '');
        scopeButtons.forEach((button) => {
            const active = button.dataset.totalScope === totalScope;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
    };
    scopeButtons.forEach((button) => button.addEventListener('click', () => {
        if (button.disabled) return;
        totalScope = button.dataset.totalScope;
        renderTotals();
    }));

    const render = () => {
        const list = currentRows();
        const pages = Math.max(1, Math.ceil(list.length / state.size));
        state.page = Math.min(Math.max(1, state.page), pages);
        const from = (state.page - 1) * state.size;
        const slice = list.slice(from, from + state.size);
        if (slice.length === 0) {
            const message = state.tab === 'open' ? 'Nicio sesiune în curs pentru filtrele selectate.'
                : state.tab === 'missing' ? 'Toate sesiunile filtrate au documentele.' : 'Nu există sesiuni pentru filtrele selectate.';
            body.innerHTML = `<tr><td colspan="13" class="etoll-empty">${esc(message)}${data.hasFilters ? ` <a href="${esc(data.resetUrl)}">Resetează filtrele</a>` : ''}</td></tr>`;
        } else {
            body.innerHTML = slice.map(rowHtml).join('');
        }
        rangeNode.textContent = list.length === 0 ? '0 din 0' : `${from + 1} – ${from + slice.length} din ${list.length}`;
        pagesNode.innerHTML = [
            `<button type="button" class="etoll-page-btn" data-page="${state.page - 1}" aria-label="Pagina anterioară" ${state.page <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left" aria-hidden="true"></i></button>`,
            ...pageButtons(state.page, pages).map((n) => (n === '…'
                ? '<span class="etoll-page-gap">…</span>'
                : `<button type="button" class="etoll-page-btn${n === state.page ? ' is-current' : ''}" data-page="${n}" ${n === state.page ? 'aria-current="page"' : ''}>${n}</button>`)),
            `<button type="button" class="etoll-page-btn" data-page="${state.page + 1}" aria-label="Pagina următoare" ${state.page >= pages ? 'disabled' : ''}><i class="bi bi-chevron-right" aria-hidden="true"></i></button>`,
        ].join('');
        table.querySelectorAll('[data-sort]').forEach((button) => {
            const active = button.dataset.sort === state.sort;
            button.classList.toggle('is-sorted', active);
            button.classList.toggle('is-desc', active && state.dir === 'desc');
            button.closest('th').setAttribute('aria-sort', active ? (state.dir === 'desc' ? 'descending' : 'ascending') : 'none');
        });
        refreshSelection();
        renderTotals();
    };

    page.querySelectorAll('[data-tab]').forEach((tab) => {
        const activate = () => {
            state.tab = tab.dataset.tab;
            state.page = 1;
            page.querySelectorAll('[data-tab]').forEach((other) => {
                const active = other === tab;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
                other.tabIndex = active ? 0 : -1;
            });
            writeUrl();
            render();
        };
        tab.addEventListener('click', activate);
        tab.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
                return;
            }
            const tabs = [...page.querySelectorAll('[data-tab]')];
            const next = tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            next.focus();
            next.click();
        });
        if (tab.dataset.tab === state.tab) {
            page.querySelectorAll('[data-tab]').forEach((other) => {
                other.classList.toggle('is-active', other === tab);
                other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
                other.tabIndex = other === tab ? 0 : -1;
            });
        }
    });

    table.querySelector('thead').addEventListener('click', (event) => {
        const button = event.target.closest('[data-sort]');
        if (!button) {
            return;
        }
        if (state.sort === button.dataset.sort) {
            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort = button.dataset.sort;
            state.dir = ['km', 'net', 'gross'].includes(state.sort) ? 'desc' : 'asc';
        }
        state.page = 1;
        writeUrl();
        render();
    });

    pagesNode.addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button || button.disabled) {
            return;
        }
        state.page = Number(button.dataset.page);
        render();
        table.closest('.etoll-card').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });

    const sizeSelect = page.querySelector('[data-page-size]');
    sizeSelect.value = String(state.size);
    sizeSelect.addEventListener('change', () => {
        state.size = Number(sizeSelect.value) || 50;
        state.page = 1;
        store.set('etoll.pageSize', String(state.size));
        render();
    });

    body.addEventListener('change', (event) => {
        const check = event.target.closest('[data-row-check]');
        if (!check) {
            return;
        }
        const tr = check.closest('tr');
        if (check.checked) {
            selected.add(tr.dataset.id);
        } else {
            selected.delete(tr.dataset.id);
        }
        tr.classList.toggle('is-selected', check.checked);
        refreshSelection();
    });
    selectAll.addEventListener('change', () => {
        tabRows[state.tab].forEach((row) => (selectAll.checked ? selected.add(row.id) : selected.delete(row.id)));
        body.querySelectorAll('tr[data-id]').forEach((tr) => {
            const on = selected.has(tr.dataset.id);
            tr.classList.toggle('is-selected', on);
            const box = tr.querySelector('[data-row-check]');
            if (box) box.checked = on;
        });
        refreshSelection();
    });

    // Documentele sesiunilor bifate: POST (id-urile pot fi multe), formular trimis direct (fara
    // evenimentul submit, ca garda anti-dublu-submit din app.js sa nu blocheze o a doua descarcare).
    downloadButton?.addEventListener('click', () => {
        const target = document.getElementById('etollSelectedForm');
        if (!target) {
            return;
        }
        target.querySelectorAll('input[name="sesiuni[]"]').forEach((input) => input.remove());
        [...selected].filter((id) => rowsById.get(id)?.docs?.some((doc) => doc.url)).forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'sesiuni[]';
            input.value = id;
            target.appendChild(input);
        });
        HTMLFormElement.prototype.submit.call(target);
    });

    // CSV: tab-ul curent, toate paginile, in ordinea afisata (separator ; si BOM pentru Excel RO).
    page.querySelector('[data-export-csv]')?.addEventListener('click', () => {
        const num = (value, decimals) => (value === null || value === undefined ? '' : Number(value).toFixed(decimals).replace('.', ','));
        const cell = (value) => {
            const text = String(value ?? '');
            return /[;"\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
        };
        const lines = [['Închisă la', 'Nr. auto', 'Remorcă', 'Beneficiar', 'Cursă', 'Început → sfârșit', 'Traseu', 'Km', 'Fără TVA', 'TVA', 'Cu TVA', 'Document', 'Verificare', 'ID sesiune']];
        currentRows().forEach((row) => lines.push([
            row.closed, row.plate, row.trailer, row.cursaId ? (row.ben || 'Fără beneficiar') : '', row.cursaId ?? '', row.spanTitle || row.span,
            row.routeFull || row.route, num(row.km, 0), num(row.net, 2), num(row.vat, 2), num(row.gross, 2), row.doc, row.checkText, row.id,
        ]));
        const blob = new Blob(['﻿' + lines.map((line) => line.map(cell).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `${data.csvName || 'Situatie_eToll'}_${state.tab === 'open' ? 'in_curs' : state.tab === 'missing' ? 'lipsa_documente' : 'inchise'}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    });

    render();

    // ---------------------------------------------------------------------
    // Alimentarile: pliere (continutul ramane in pagina)
    // ---------------------------------------------------------------------
    const topups = document.getElementById('etollTopups');
    const toggle = topups?.querySelector('[data-topups-toggle]');
    if (topups && toggle) {
        const setOpen = (open, remember = true) => {
            topups.classList.toggle('is-collapsed', !open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (remember) {
                store.set('etoll.topupsCollapsed', open ? '0' : '1');
            }
        };
        setOpen(store.get('etoll.topupsCollapsed') !== '1', false);
        toggle.addEventListener('click', () => setOpen(topups.classList.contains('is-collapsed')));
        page.querySelector('[data-open-topups]')?.addEventListener('click', (event) => {
            event.preventDefault();
            setOpen(true);
            topups.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
})();
