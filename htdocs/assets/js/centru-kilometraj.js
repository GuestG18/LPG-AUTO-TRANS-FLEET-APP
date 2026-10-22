/**
 * Centru Kilometraj (?page=centru_kilometraj).
 *
 * Deseneaza KPI-urile, grupele de capacitate, cardurile cu cele doua odometre
 * (SAS Fleet + CardOil Refill) per vehicul fizic, panoul T0 si istoricul, din
 * JSON-ul #kmPageData (OdometerCenterService::buildDashboard).
 *
 * Animatie: fiecare odometru are cheia "<vehicle_id>:<sas|cardoil>". La o
 * reimprospatare se ruleaza DOAR odometrele a caror valoare s-a schimbat; nu se
 * simuleaza nicio crestere intre citiri si nu se anima la prima afisare.
 */
(function () {
    'use strict';

    const root = document.getElementById('kmCenter');
    const dataNode = document.getElementById('kmPageData');
    if (!root || !dataNode) {
        return;
    }

    let page;
    try {
        page = JSON.parse(dataNode.textContent || '{}');
    } catch (error) {
        page = {};
    }

    const DIGITS = 7;
    const REFRESH_MS = 60000;
    const STORAGE_KEY = 'kmc.prefs.v1';
    const PALETTE = [
        ['#e5484d', '#fff1f2'], ['#1a73e8', '#eef5ff'], ['#8b3fd9', '#f6effd'], ['#16a34a', '#ecfdf3'],
        ['#0e9aa7', '#e8f8f9'], ['#ea7a0c', '#fff4e8'], ['#d6336c', '#fdf0f5'], ['#4f46e5', '#eef0ff'],
    ];
    const TYPE_ORDER = ['ansamblu', 'cap_tractor', 'camion', 'semiremorca', 'autoutilitara', 'autovehicul'];
    const TYPE_PLURAL = {
        ansamblu: 'Ansambluri', cap_tractor: 'Cap tractoare', camion: 'Camioane', semiremorca: 'Semiremorci',
        autoutilitara: 'Autoutilitare', autovehicul: 'Autoturisme',
    };
    const TYPE_ICON = {
        ansamblu: 'bi-truck', cap_tractor: 'bi-truck-front', camion: 'bi-truck', semiremorca: 'bi-truck-flatbed',
        autoutilitara: 'bi-bus-front', autovehicul: 'bi-car-front',
    };
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const state = {
        data: page.dashboard || { units: [], categories: [], kpis: {} },
        perms: page.permissions || {},
        filters: { q: '', category: '', provider: '', status: 'activ', type: '', capacity: '', group: 'category' },
        view: 'grid',
        collapsed: new Set(),
        shownKm: new Map(),
        panel: null,
        syncing: false,
    };

    // ------------------------------------------------------------------ utilitare

    const $ = (selector, scope) => (scope || root).querySelector(selector);
    const $$ = (selector, scope) => Array.from((scope || root).querySelectorAll(selector));

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    const kmFormat = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    const pctFormat = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 });
    function fmtKm(value) {
        return value === null || value === undefined ? '—' : kmFormat.format(Math.round(Number(value)));
    }
    function signedKm(value) {
        if (value === null || value === undefined) {
            return '—';
        }
        return (value >= 0 ? '+ ' : '− ') + fmtKm(Math.abs(value)) + ' km';
    }
    function parseDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? null : date;
    }
    const pad = (n) => String(n).padStart(2, '0');
    function fmtDate(value) {
        const d = parseDate(value);
        return d ? pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() : '—';
    }
    function fmtDT(value) {
        const d = parseDate(value);
        return d ? fmtDate(value) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) : '—';
    }
    function ago(value) {
        const d = parseDate(value);
        if (!d) {
            return '—';
        }
        const minutes = Math.max(0, Math.round((Date.now() - d.getTime()) / 60000));
        if (minutes < 1) {
            return 'acum';
        }
        if (minutes < 60) {
            return minutes + ' min. în urmă';
        }
        const hours = Math.round(minutes / 60);
        if (hours < 48) {
            return hours + (hours === 1 ? ' oră' : ' ore') + ' în urmă';
        }
        return Math.round(hours / 24) + ' zile în urmă';
    }
    function typeGroup(type) {
        return String(type || '').startsWith('semiremorca') ? 'semiremorca' : type;
    }
    function capacityLabel(value) {
        return value === null || value === undefined ? null : pctFormat.format(value) + ' t';
    }
    function categoryById(id) {
        return (state.data.categories || []).find((c) => c.id === id) || null;
    }

    function loadPrefs() {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            const prefs = raw ? JSON.parse(raw) : null;
            if (prefs && typeof prefs === 'object') {
                Object.assign(state.filters, prefs.filters || {});
                state.filters.q = '';
                state.view = prefs.view === 'list' ? 'list' : 'grid';
                state.collapsed = new Set(Array.isArray(prefs.collapsed) ? prefs.collapsed : []);
            }
        } catch (error) {
            // fara localStorage pagina merge cu valorile implicite
        }
    }
    function savePrefs() {
        try {
            const { q, ...filters } = state.filters;
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ filters, view: state.view, collapsed: Array.from(state.collapsed) }));
        } catch (error) {
            // ignorat
        }
    }

    function toast(message, isError) {
        const node = $('[data-kmc-toast]');
        node.textContent = message;
        node.classList.toggle('kmc-toast--err', !!isError);
        node.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => { node.hidden = true; }, 5000);
    }

    async function post(url, fields) {
        const body = new FormData();
        body.append('_token', page.token || '');
        Object.entries(fields || {}).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => body.append(key + '[]', item));
            } else if (value !== undefined && value !== null) {
                body.append(key, value);
            }
        });
        const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        let json;
        try {
            json = await response.json();
        } catch (error) {
            throw new Error('Răspuns invalid de la server (' + response.status + ').');
        }
        if (!response.ok && !json.stats) {
            throw new Error(json.message || ('Eroare ' + response.status));
        }
        return json;
    }

    async function getJson(url) {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const json = await response.json();
        if (!response.ok || !json.ok) {
            throw new Error(json.message || ('Eroare ' + response.status));
        }
        return json;
    }

    // ------------------------------------------------------------------ derivate

    function componentsOf(unit) {
        return unit.components || [];
    }
    function sourceFresh(source) {
        if (!source.available) {
            return null;
        }
        return source.freshness === 'actual' || source.freshness === 'eveniment';
    }
    function unitFreshness(unit) {
        let available = 0;
        let error = false;
        let stale = false;
        componentsOf(unit).forEach((comp) => {
            ['sas', 'cardoil'].forEach((p) => {
                const s = comp.sources[p];
                if (!s.available || !s.current) {
                    return;
                }
                available++;
                if (s.freshness === 'eroare') {
                    error = true;
                } else if (!sourceFresh(s)) {
                    stale = true;
                }
            });
        });
        if (available === 0) {
            return { cls: '', text: 'Fără surse de kilometraj' };
        }
        if (error) {
            return { cls: 'kmc-dot--err', text: 'Eroare SAS (ultima citire păstrată)' };
        }
        return stale ? { cls: 'kmc-dot--warn', text: 'Date neactualizate' } : { cls: 'kmc-dot--ok', text: 'Date actuale' };
    }
    function unitAnomalies(unit) {
        return componentsOf(unit).reduce((n, comp) => n + (comp.anomalies || []).length, 0);
    }
    function unitLabel(unit) {
        return componentsOf(unit).map((c) => c.label).filter(Boolean).join(' + ');
    }

    function unitMatches(unit) {
        const f = state.filters;
        const comps = componentsOf(unit);
        if (f.q) {
            const needle = f.q.toLowerCase().replace(/\s+/g, '');
            const category = categoryById(unit.category_id);
            const hay = comps.map((c) => [c.plate, c.label, c.type_label, c.garage].join(' ')).join(' ')
                + ' ' + (category ? category.name : '') + ' ' + (unit.kind === 'ansamblu' ? 'ansamblu' : '');
            if (!hay.toLowerCase().replace(/\s+/g, '').includes(needle)) {
                return false;
            }
        }
        if (f.category) {
            if (f.category === 'none' ? unit.category_id !== null : String(unit.category_id) !== f.category) {
                return false;
            }
        }
        if (f.provider) {
            const has = (p) => comps.some((c) => c.sources[p].available);
            const ok = {
                sas: has('sas'), cardoil: has('cardoil'),
                both: comps.some((c) => c.sources.sas.available && c.sources.cardoil.available),
                none: !has('sas') && !has('cardoil'),
            }[f.provider];
            if (!ok) {
                return false;
            }
        }
        if (f.status) {
            if (f.status === 'activ' || f.status === 'inactiv') {
                if (unit.status !== f.status) {
                    return false;
                }
            } else if (f.status === 'stale') {
                const fr = unitFreshness(unit);
                if (fr.cls !== 'kmc-dot--warn' && fr.cls !== 'kmc-dot--err') {
                    return false;
                }
            } else if (f.status === 'anomaly') {
                if (unitAnomalies(unit) === 0) {
                    return false;
                }
            } else if (f.status === 'no_t0') {
                if (!comps.some((c) => c.monitored && !c.t0.configured)) {
                    return false;
                }
            }
        }
        if (f.type && typeGroup(unit.type) !== f.type) {
            return false;
        }
        if (f.capacity) {
            const cap = unit.capacity_t === null ? 'none' : String(unit.capacity_t);
            if (cap !== f.capacity) {
                return false;
            }
        }
        return true;
    }

    function buildGroups(units) {
        const mode = state.filters.group;
        const byPlate = (a, b) => componentsOf(a)[0].plate.localeCompare(componentsOf(b)[0].plate, 'ro', { numeric: true });
        const groups = [];
        if (mode === 'none') {
            groups.push({ key: 'all', title: 'Toate vehiculele', sub: '', units: units.slice().sort(byPlate), icon: 'bi-truck', color: 1 });
            return groups;
        }
        if (mode === 'category') {
            const categories = (state.data.categories || []).slice().sort((a, b) => a.order - b.order || a.name.localeCompare(b.name, 'ro'));
            categories.forEach((category, index) => {
                const members = units.filter((u) => u.category_id === category.id).sort(byPlate);
                if (members.length) {
                    groups.push({ key: 'c' + category.id, title: category.name, sub: '', units: members, color: index, categoryGroup: true });
                }
            });
        }
        const rest = mode === 'category' ? units.filter((u) => u.category_id === null) : units;
        TYPE_ORDER.forEach((type) => {
            const members = rest.filter((u) => typeGroup(u.type) === type).sort(byPlate);
            if (members.length) {
                groups.push({
                    key: (mode === 'category' ? 'fara-' : 't-') + type,
                    title: TYPE_PLURAL[type],
                    sub: mode === 'category' ? 'fără categorie de capacitate' : '',
                    units: members,
                    icon: TYPE_ICON[type],
                    color: mode === 'category' ? null : TYPE_ORDER.indexOf(type),
                });
            }
        });
        groups.forEach((group) => {
            if (!group.icon) {
                const types = group.units.map((u) => typeGroup(u.type));
                const dominant = TYPE_ORDER.find((t) => types.includes(t)) || 'camion';
                group.icon = TYPE_ICON[dominant];
            }
        });
        return groups;
    }

    // ------------------------------------------------------------------ odometru

    function digitsOf(km) {
        const text = String(Math.max(0, Math.floor(Number(km) || 0)));
        return text.padStart(Math.max(DIGITS, text.length), '0').split('').map(Number);
    }

    function drumsHtml(km, large) {
        if (km === null || km === undefined) {
            const dashes = new Array(DIGITS).fill('<span class="kmc-drum"><span class="kmc-drum__strip"><span>–</span></span></span>').join('');
            return '<span class="kmc-drums kmc-drums--off' + (large ? ' kmc-drums--lg' : '') + '" aria-hidden="true">' + dashes + '</span>';
        }
        const strip = Array.from({ length: 20 }, (_, i) => '<span>' + (i % 10) + '</span>').join('');
        const drums = digitsOf(km).map((d) => '<span class="kmc-drum" data-d="' + d + '"><span class="kmc-drum__strip" style="transform:translateY(calc(var(--h) * -' + d + '))">' + strip + '</span></span>').join('');
        return '<span class="kmc-drums' + (large ? ' kmc-drums--lg' : '') + '" role="img" aria-label="' + esc(fmtKm(km)) + ' km">' + drums + '</span>';
    }

    /** Ruleaza tamburii unui odometru de la valoarea veche la cea noua (doar cifrele schimbate). */
    function rollOdometer(node, fromKm, toKm) {
        const drums = $$('.kmc-drum', node);
        const from = digitsOf(fromKm);
        const to = digitsOf(toKm);
        if (from.length !== to.length || drums.length !== to.length) {
            // Se schimba numarul de cifre: se afiseaza direct valoarea noua.
            const box = node.querySelector('.kmc-drums');
            if (box) {
                box.outerHTML = drumsHtml(toKm);
            }
            return;
        }
        const increasing = toKm >= fromKm;
        drums.forEach((drum, i) => {
            const strip = drum.firstElementChild;
            const a = from[i];
            const b = to[i];
            if (a === b) {
                return;
            }
            if (reduceMotion) {
                strip.style.transform = 'translateY(calc(var(--h) * -' + b + '))';
                return;
            }
            const start = increasing ? a : a + 10;
            const target = increasing ? (b >= a ? b : b + 10) : (b <= a ? b + 10 : b);
            drum.classList.remove('is-rolling');
            strip.style.transform = 'translateY(calc(var(--h) * -' + start + '))';
            void strip.offsetHeight;
            drum.style.setProperty('--kmc-roll', (850 + (to.length - i) * 70) + 'ms');
            drum.classList.add('is-rolling');
            strip.style.transform = 'translateY(calc(var(--h) * -' + target + '))';
            const settle = () => {
                drum.classList.remove('is-rolling');
                strip.style.transform = 'translateY(calc(var(--h) * -' + b + '))';
                strip.removeEventListener('transitionend', settle);
            };
            strip.addEventListener('transitionend', settle);
        });
        node.classList.remove('is-updated');
        void node.offsetWidth;
        node.classList.add('is-updated');
        node.dataset.animatedAt = String(Date.now());
    }

    function odoHtml(comp, provider) {
        const source = comp.sources[provider];
        const key = comp.id + ':' + provider;
        const isSas = provider === 'sas';
        const name = isSas ? 'Odometru SAS' : 'Odometru CardOil';
        const icon = isSas ? 'bi-patch-check-fill' : 'bi-fuel-pump-fill';
        const current = source.current;
        const title = isSas
            ? 'Odometru GPS SAS Fleet (kmIndex la sfârșitul ultimei deplasări). Nu e calibrat pe bord.'
            : 'Odometrul tastat la pompă la ultima alimentare CardOil.';
        if (!source.available || !current) {
            const reason = source.reason || 'Date indisponibile pentru această sursă';
            return '<div class="kmc-odo kmc-odo--' + provider + ' kmc-odo--off" data-odo-key="' + key + '" title="' + esc(reason) + '">'
                + '<div class="kmc-odo__label"><i class="bi ' + icon + '" aria-hidden="true"></i>' + name + '</div>'
                + '<div class="kmc-odo__row">' + drumsHtml(null) + '<span class="kmc-odo__unit">km</span></div>'
                + '<div class="kmc-odo__na">Date indisponibile pentru această sursă</div>'
                + '</div>';
        }
        const anomalies = (source.anomalies || []).length;
        const flag = anomalies ? '<i class="bi bi-exclamation-diamond-fill kmc-odo__flag" title="' + esc(source.anomalies.map((a) => a.note).join('\n')) + '"></i>' : '';
        let src;
        if (isSas) {
            const dot = { actual: 'kmc-dot--ok', eroare: 'kmc-dot--err' }[source.freshness] || 'kmc-dot--warn';
            const checkedTitle = source.freshness === 'eroare'
                ? 'Ultima verificare SAS a eșuat: ' + (source.last_error || '')
                : 'Verificat în SAS: ' + fmtDT(source.last_checked_at) + (current.measured_at < (source.last_checked_at || '') ? ' — nicio deplasare de la ultima citire' : '');
            src = '<div class="kmc-odo__src" title="' + esc(checkedTitle) + '"><span class="kmc-dot ' + dot + '"></span>'
                + (source.freshness === 'eroare' ? 'Eroare SAS' : 'verificat ' + esc(ago(source.last_checked_at))) + '</div>';
        } else {
            const dot = source.freshness === 'eveniment' ? 'kmc-dot--event' : 'kmc-dot--warn';
            src = '<div class="kmc-odo__src" title="' + esc(source.freshness === 'eveniment' ? 'Citire pe eveniment (alimentare), nu live.' : 'Sincronizarea CardOil e veche: ' + fmtDT(source.last_checked_at)) + '">'
                + '<span class="kmc-dot ' + dot + '"></span>alimentare ' + esc(ago(current.measured_at)) + (current.corrected ? ' · corectat' : '') + '</div>';
        }
        return '<div class="kmc-odo kmc-odo--' + provider + '" data-odo-key="' + key + '" data-km="' + current.km + '" title="' + esc(title) + '">'
            + '<div class="kmc-odo__label"><i class="bi ' + icon + '" aria-hidden="true"></i>' + name + flag + '</div>'
            + '<div class="kmc-odo__row">' + drumsHtml(state.shownKm.has(key) ? state.shownKm.get(key) : current.km) + '<span class="kmc-odo__unit">km</span></div>'
            + '<div class="kmc-odo__meta">' + (isSas ? 'Ultima citire:' : 'Ultima alimentare:') + '<br><b>' + esc(fmtDT(current.measured_at)) + '</b></div>'
            + src
            + '</div>';
    }

    /** Dupa randare: odometrele cu valoare noua se rulesc de la valoarea afisata anterior. */
    function animateChanged() {
        const seen = new Set();
        $$('[data-odo-key][data-km]').forEach((node) => {
            const key = node.dataset.odoKey;
            const km = Number(node.dataset.km);
            seen.add(key);
            const previous = state.shownKm.get(key);
            if (previous !== undefined && previous !== km) {
                requestAnimationFrame(() => rollOdometer(node, previous, km));
            }
            state.shownKm.set(key, km);
        });
        // Odometrele ascunse de filtre isi pastreaza ultima valoare afisata.
        allComponents().forEach((comp) => {
            ['sas', 'cardoil'].forEach((p) => {
                const key = comp.id + ':' + p;
                const current = comp.sources[p].current;
                if (!seen.has(key) && current && !state.shownKm.has(key)) {
                    state.shownKm.set(key, current.km);
                }
            });
        });
    }

    function allComponents() {
        return (state.data.units || []).flatMap(componentsOf);
    }

    // ------------------------------------------------------------------ carduri

    function photoHtml(comps) {
        const one = (comp) => comp.photo
            ? '<img src="' + esc(comp.photo) + '" alt="' + esc(comp.plate) + '" loading="lazy">'
            : '<i class="bi ' + (TYPE_ICON[typeGroup(comp.type)] || 'bi-truck') + '" aria-hidden="true"></i>';
        if (comps.length > 1) {
            if (comps[0].photo && !comps[1].photo) {
                return '<div class="kmc-photo">' + one(comps[0]) + '</div>';
            }
            return '<div class="kmc-photo kmc-photo--duo">' + comps.map((c) => '<div title="' + esc(c.plate) + '">' + one(c) + '</div>').join('') + '</div>';
        }
        return '<div class="kmc-photo">' + one(comps[0]) + (comps[0].photo ? '' : '<small>fără poză</small>') + '</div>';
    }

    function plateHtml(plate) {
        return '<span class="kmc-plate"><span>' + esc(plate) + '</span></span>';
    }

    function t0Row(unit) {
        const comps = componentsOf(unit).filter((c) => c.monitored);
        const dates = comps.map((c) => c.t0.requested_at).filter(Boolean);
        const partial = comps.some((c) => c.t0.partial);
        const label = dates.length ? 'T0: ' + fmtDate(dates.sort()[dates.length - 1]) + (partial ? ' (parțial)' : '') : (comps.length ? 'T0 nesetat' : 'Fără surse');
        if (state.perms.t0 && comps.length) {
            return '<button type="button" class="kmc-link kmc-meta-row" data-kmc-open="t0" data-unit="' + esc(unit.key) + '"><i class="bi bi-calendar3"></i>' + esc(label) + '</button>';
        }
        return '<span class="kmc-meta-row"><i class="bi bi-calendar3"></i>' + esc(label) + '</span>';
    }

    function metaHtml(unit) {
        const fr = unitFreshness(unit);
        const anomalies = unitAnomalies(unit);
        const actions = [];
        if (anomalies && state.perms.history) {
            actions.push('<button type="button" class="kmc-anomaly-badge" data-kmc-open="history" data-unit="' + esc(unit.key) + '" title="Vezi anomaliile în istoric"><i class="bi bi-exclamation-diamond"></i> ' + anomalies + '</button>');
        }
        if (state.perms.history) {
            actions.push('<button type="button" class="kmc-iconbtn" style="width:28px;height:28px" data-kmc-open="history" data-unit="' + esc(unit.key) + '" title="Istoric citiri" aria-label="Istoric citiri"><i class="bi bi-clock-history"></i></button>');
        }
        return '<div class="kmc-card__meta">'
            + '<span class="kmc-status' + (unit.status !== 'activ' ? ' kmc-status--inactiv' : '') + '">' + (unit.status === 'activ' ? 'Activ' : 'Inactiv') + '</span>'
            + t0Row(unit)
            + '<span class="kmc-meta-row"><span class="kmc-dot ' + fr.cls + '"></span>' + esc(fr.text) + '</span>'
            + (actions.length ? '<div class="kmc-card__actions">' + actions.join('') + '</div>' : '')
            + '</div>';
    }

    function sinceHtml(comp) {
        const sas = comp.sources.sas.distance.km;
        const card = comp.sources.cardoil.distance.km;
        if (!comp.monitored) {
            return '<div class="kmc-since kmc-since--none"><div class="kmc-since__label">De la T0:</div><div class="kmc-since__value">Fără surse</div></div>';
        }
        if (!comp.t0.configured) {
            return '<div class="kmc-since kmc-since--none"><div class="kmc-since__label">De la T0:</div><div class="kmc-since__value">T0 nesetat</div></div>';
        }
        const diff = comp.comparison && comp.comparison.comparable
            ? '<div class="kmc-since__src" title="' + esc(comp.comparison.note) + '">Δ SAS − CardOil: ' + esc(signedKm(comp.comparison.diff_km)) + '</div>' : '';
        if (comp.official.km !== null) {
            const src = comp.official.source === 'sas' ? 'SAS Fleet' : 'CardOil Refill';
            return '<div class="kmc-since"><div class="kmc-since__label">De la T0:</div><div class="kmc-since__value">' + esc(signedKm(comp.official.km))
                + '</div><div class="kmc-since__src">Sursă oficială: ' + src + '</div>' + diff + '</div>';
        }
        const line = (label, km, note) => '<div class="kmc-since__line"><span>' + label + '</span>' + (km !== null ? '<b>' + esc(signedKm(km)) + '</b>' : '<span class="kmc-since__src" title="' + esc(note || '') + '">—</span>') + '</div>';
        return '<div class="kmc-since"><div class="kmc-since__label">De la T0:</div>'
            + line('SAS', sas, comp.sources.sas.distance.note)
            + line('CardOil', card, comp.sources.cardoil.distance.note)
            + '<div class="kmc-since__src">' + (state.data.policy.code ? esc(comp.official.reason || '') : 'Sursa oficială neconfigurată') + '</div>' + diff + '</div>';
    }

    function vehicleCard(unit) {
        const comp = componentsOf(unit)[0];
        const category = categoryById(unit.category_id);
        const chips = [];
        if (comp.capacity_t !== null) {
            chips.push('<span title="Capacitate tehnică reală"><i class="bi bi-box-seam"></i>' + esc(capacityLabel(comp.capacity_t)) + '</span>');
        }
        chips.push('<span><i class="bi ' + (TYPE_ICON[typeGroup(comp.type)] || 'bi-truck') + '"></i>' + esc(comp.type_label) + '</span>');
        if (category && state.filters.group !== 'category') {
            chips.push('<span class="kmc-chip-box" title="Categoria de capacitate">' + esc(category.name) + '</span>');
        }
        return '<article class="kmc-card" data-unit="' + esc(unit.key) + '">'
            + '<div class="kmc-card__top">' + photoHtml([comp])
            + '<div><h3 class="kmc-card__name" title="' + esc(comp.label) + '">' + esc(comp.label || comp.plate) + '</h3>'
            + '<div class="kmc-plates">' + plateHtml(comp.plate) + '</div>'
            + '<div class="kmc-chips">' + chips.join('') + '</div></div>'
            + metaHtml(unit) + '</div>'
            + '<div class="kmc-card__body"><div class="kmc-odos">' + odoHtml(comp, 'sas') + odoHtml(comp, 'cardoil') + '</div>' + sinceHtml(comp) + '</div>'
            + '</article>';
    }

    function subHtml(comp, role) {
        const isTrailer = role === 'trailer';
        return '<div class="kmc-sub' + (isTrailer ? ' kmc-sub--trailer' : '') + '">'
            + '<div class="kmc-sub__title"><i class="bi ' + (isTrailer ? 'bi-truck-flatbed' : 'bi-truck-front-fill') + '"></i>'
            + (isTrailer ? 'Semiremorcă' : 'Cap tractor') + ' – ' + esc(comp.plate) + (comp.label ? ' <span>(' + esc(comp.label) + ')</span>' : '') + '</div>'
            + '<div class="kmc-odos">' + odoHtml(comp, 'sas') + odoHtml(comp, 'cardoil') + '</div></div>';
    }

    function assemblyTotal(tractor, trailer) {
        const part = (comp, label) => {
            if (!comp.monitored) {
                return '<span>' + label + ': <span class="kmc-muted">fără citiri proprii</span></span>';
            }
            if (!comp.t0.configured) {
                return '<span>' + label + ': <span class="kmc-muted">T0 nesetat</span></span>';
            }
            if (comp.official.km !== null) {
                return '<span>' + label + ': <b>' + esc(signedKm(comp.official.km)) + '</b> <span class="kmc-muted">(' + (comp.official.source === 'sas' ? 'SAS' : 'CardOil') + ')</span></span>';
            }
            const s = comp.sources.sas.distance.km;
            const c = comp.sources.cardoil.distance.km;
            return '<span>' + label + ': SAS <b>' + esc(signedKm(s)) + '</b> · CardOil <b>' + esc(signedKm(c)) + '</b></span>';
        };
        return '<div class="kmc-total" title="Km per componentă: nu se adună între cap tractor și semiremorcă și nici între surse.">'
            + '<span class="kmc-dot kmc-dot--ok"></span><strong>De la T0:</strong>'
            + part(tractor, 'Cap tractor') + '<span class="kmc-total__sep"></span>' + part(trailer, 'Semiremorcă')
            + '</div>';
    }

    function assemblyCard(unit) {
        const [tractor, trailer] = componentsOf(unit);
        const category = categoryById(unit.category_id);
        const name = [tractor.label, trailer.label].filter(Boolean).join(' + ') || tractor.plate;
        const chips = [];
        if (unit.capacity_t !== null) {
            chips.push('<span title="Capacitatea tehnică reală a semiremorcii"><i class="bi bi-box-seam"></i>' + esc(capacityLabel(unit.capacity_t)) + '</span>');
        }
        chips.push('<span><i class="bi bi-link-45deg"></i>Ansamblu</span>');
        if (category && state.filters.group !== 'category') {
            chips.push('<span class="kmc-chip-box">' + esc(category.name) + '</span>');
        }
        return '<article class="kmc-card kmc-card--assembly" data-unit="' + esc(unit.key) + '">'
            + '<div class="kmc-card__top">' + photoHtml([tractor, trailer])
            + '<div><h3 class="kmc-card__name" title="' + esc(name) + '">' + esc(name) + '</h3>'
            + '<div class="kmc-plates">' + plateHtml(tractor.plate) + '<span class="kmc-plate-plus">+</span>' + plateHtml(trailer.plate) + '</div>'
            + '<div class="kmc-chips"><span><i class="bi bi-truck-front"></i>Cap tractor</span><span><i class="bi bi-truck-flatbed"></i>' + esc(trailer.type_label) + '</span>' + chips.join('') + '</div></div>'
            + metaHtml(unit) + '</div>'
            + '<div class="kmc-subs">' + subHtml(tractor, 'tractor') + subHtml(trailer, 'trailer') + '</div>'
            + assemblyTotal(tractor, trailer)
            + '</article>';
    }

    function listRows(unit) {
        return componentsOf(unit).map((comp, index) => {
            const cell = (p) => {
                const s = comp.sources[p];
                if (!s.available || !s.current) {
                    return '<td class="kmc-num"><span class="kmc-muted" title="' + esc(s.reason || '') + '">indisponibil</span></td>';
                }
                return '<td class="kmc-num"><span class="' + (p === 'sas' ? 'kmc-sas' : 'kmc-card-oil') + '">' + esc(fmtKm(s.current.km)) + ' km</span><small>' + esc(fmtDT(s.current.measured_at)) + '</small></td>';
            };
            const dist = (p) => '<td class="kmc-num">' + (comp.sources[p].distance.km !== null ? esc(signedKm(comp.sources[p].distance.km)) : '—') + '</td>';
            const first = index === 0;
            const category = categoryById(unit.category_id);
            return '<tr data-unit="' + esc(unit.key) + '">'
                + '<td>' + (first ? '' : '<i class="bi bi-arrow-return-right kmc-muted"></i> ') + '<strong>' + esc(comp.plate) + '</strong><small>' + esc(comp.label) + ' · ' + esc(comp.type_label) + '</small></td>'
                + '<td>' + (first ? esc(category ? category.name : '—') : '') + '<small>' + esc(capacityLabel(comp.capacity_t) || '') + '</small></td>'
                + cell('sas') + cell('cardoil')
                + '<td>' + (comp.t0.requested_at ? esc(fmtDT(comp.t0.requested_at)) : '<span class="kmc-muted">—</span>') + '</td>'
                + dist('sas') + dist('cardoil')
                + '<td class="kmc-num">' + (comp.official.km !== null ? '<b>' + esc(signedKm(comp.official.km)) + '</b>' : '—') + '</td>'
                + '<td>' + (first ? metaActions(unit) : '') + '</td>'
                + '</tr>';
        }).join('');
    }

    function metaActions(unit) {
        const parts = [];
        if (state.perms.t0 && componentsOf(unit).some((c) => c.monitored)) {
            parts.push('<button type="button" class="kmc-btn kmc-btn--sm" data-kmc-open="t0" data-unit="' + esc(unit.key) + '">T0</button>');
        }
        if (state.perms.history) {
            parts.push('<button type="button" class="kmc-btn kmc-btn--sm" data-kmc-open="history" data-unit="' + esc(unit.key) + '">Istoric</button>');
        }
        return '<div class="kmc-card__actions">' + parts.join('') + '</div>';
    }

    // ------------------------------------------------------------------ randare

    function renderKpis() {
        const k = state.data.kpis || {};
        const policy = state.data.policy || { code: '', options: {} };
        let totalValue;
        let totalSub;
        if (k.official_km !== null && k.official_km !== undefined) {
            totalValue = '<div class="kmc-kpi__value">' + esc(fmtKm(k.official_km)) + ' <small>km</small></div>';
            totalSub = 'sursă oficială: ' + esc(policy.label) + (k.official_excluded ? ' · ' + k.official_excluded + ' excluse' : '');
        } else {
            totalValue = '<div class="kmc-kpi__value kmc-kpi__value--muted">Politică neconfigurată</div>';
            totalSub = 'SAS ' + esc(fmtKm(k.per_source_km ? k.per_source_km.sas : 0)) + ' km · CardOil ' + esc(fmtKm(k.per_source_km ? k.per_source_km.cardoil : 0)) + ' km';
        }
        if (state.perms.settings) {
            const options = Object.entries(policy.options || {}).map(([code, label]) => '<option value="' + esc(code) + '"' + (code === policy.code ? ' selected' : '') + '>' + esc(code === '' ? 'Sursă oficială: neconfigurată' : 'Sursă oficială: ' + label) + '</option>').join('');
            totalSub += '<br><select data-kmc-policy aria-label="Politica sursei oficiale">' + options + '</select>';
        }
        const card = (icon, tone, label, value, sub, title) => '<div class="kmc-kpi' + (tone === 'road' ? ' kmc-kpi--wrap' : '') + '"' + (title ? ' title="' + esc(title) + '"' : '') + '><span class="kmc-kpi__icon kmc-kpi__icon--' + tone + '"><i class="bi ' + icon + '"></i></span>'
            + '<div class="kmc-kpi__body"><div class="kmc-kpi__label">' + label + '</div>' + value + '<div class="kmc-kpi__sub">' + sub + '</div></div></div>';
        $('[data-kmc-kpis]').innerHTML = [
            card('bi-truck', 'blue', 'Vehicule monitorizate', '<div class="kmc-kpi__value">' + (k.monitored || 0) + '</div>', 'din ' + (k.active || 0) + ' active', 'Vehicule fizice active cu cel puțin o sursă de kilometraj (fiecare o singură dată, și în ansamblu).'),
            card('bi-arrow-repeat', 'green', 'Cu T0 configurat', '<div class="kmc-kpi__value">' + (k.with_t0 || 0) + '</div>', pctFormat.format(k.with_t0_pct || 0) + '%' + (k.with_t0_partial ? ' · ' + k.with_t0_partial + ' parțial' : ''), 'Cel puțin o sursă are referință validă la T0.'),
            card('bi-signpost-split', 'road', 'Total km de la T0', totalValue, totalSub, 'Suma km de la T0 pe vehicule fizice, după politica sursei oficiale. Sursele nu se adună între ele.'),
            card('bi-exclamation-triangle', 'amber', 'Fără T0', '<div class="kmc-kpi__value kmc-kpi__value--amber">' + (k.without_t0 || 0) + '</div>', pctFormat.format(k.without_t0_pct || 0) + '%'),
            card('bi-exclamation-octagon', 'red', 'Date neactualizate', '<div class="kmc-kpi__value kmc-kpi__value--red">' + (k.stale || 0) + '</div>', k.stale_oldest_hours !== null && k.stale_oldest_hours !== undefined ? '<i class="bi bi-clock"></i> cea mai veche: ' + k.stale_oldest_hours + ' ore' : 'toate sursele la zi'),
            card('bi-info-circle', 'purple', 'Anomalii', '<div class="kmc-kpi__value kmc-kpi__value--purple">' + (k.anomalies || 0) + '</div>', 'necesită verificare (30 zile)'),
        ].join('');
    }

    function renderLastSync() {
        const sync = state.data.sync || {};
        const sas = sync.sas_last_checked_at || null;
        const card = sync.cardoil ? sync.cardoil.last_success_at : null;
        const latest = [sas, card].filter(Boolean).sort().pop() || null;
        const fresh = sas && (Date.now() - parseDate(sas).getTime()) < 2 * 3600 * 1000;
        const node = $('[data-kmc-lastsync]');
        node.querySelector('.kmc-dot').className = 'kmc-dot ' + (fresh ? 'kmc-dot--ok' : 'kmc-dot--warn');
        node.querySelector('strong').textContent = latest ? fmtDT(latest).replace(' ', ', ') : '—';
        node.title = 'SAS Fleet: ' + (sas ? fmtDT(sas) : 'nesincronizat') + '\nCardOil Refill: ' + (card ? fmtDT(card) : '—');
    }

    function fillFilterOptions() {
        const units = state.data.units || [];
        const catSelect = $('[data-kmc-filter="category"]');
        const keep = catSelect.value || state.filters.category;
        catSelect.innerHTML = '<option value="">Toate categoriile</option>'
            + (state.data.categories || []).slice().sort((a, b) => a.order - b.order).map((c) => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('')
            + '<option value="none">Fără categorie</option>';
        catSelect.value = keep;

        const typeSelect = $('[data-kmc-filter="type"]');
        const types = TYPE_ORDER.filter((t) => units.some((u) => typeGroup(u.type) === t));
        typeSelect.innerHTML = '<option value="">Toate vehiculele</option>' + types.map((t) => '<option value="' + t + '">' + TYPE_PLURAL[t] + '</option>').join('');
        typeSelect.value = state.filters.type;

        const capSelect = $('[data-kmc-filter="capacity"]');
        const caps = Array.from(new Set(units.map((u) => u.capacity_t).filter((c) => c !== null))).sort((a, b) => b - a);
        capSelect.innerHTML = '<option value="">Capacitate reală</option>' + caps.map((c) => '<option value="' + c + '">' + esc(capacityLabel(c)) + '</option>').join('') + '<option value="none">Fără capacitate proprie</option>';
        capSelect.value = state.filters.capacity;

        ['provider', 'status', 'group'].forEach((name) => { $('[data-kmc-filter="' + name + '"]').value = state.filters[name]; });
        $$('[data-kmc-view]').forEach((b) => b.classList.toggle('is-active', b.dataset.kmcView === state.view));
    }

    function renderGroups() {
        const units = (state.data.units || []).filter(unitMatches);
        const container = $('[data-kmc-groups]');
        if (!units.length) {
            container.innerHTML = '<div class="kmc-empty"><i class="bi bi-search"></i> Niciun vehicul pentru filtrele alese.</div>';
            return;
        }
        container.innerHTML = buildGroups(units).map((group) => {
            const colorIndex = group.color === null ? null : group.color % PALETTE.length;
            const style = colorIndex === null ? '' : ' style="--accent:' + PALETTE[colorIndex][0] + ';--accent-bg:' + PALETTE[colorIndex][1] + '"';
            const physical = group.units.reduce((n, u) => n + componentsOf(u).length, 0);
            const assemblies = group.units.filter((u) => u.kind === 'ansamblu').length;
            const badge = assemblies
                ? assemblies + (assemblies === 1 ? ' ansamblu' : ' ansambluri') + (group.units.length > assemblies ? ' · ' + (group.units.length - assemblies) + ' vehicule' : '')
                : physical + (physical === 1 ? ' vehicul' : ' vehicule');
            const caps = group.units.map((u) => u.capacity_t).filter((c) => c !== null);
            const capText = caps.length
                ? 'Capacitate reală: ' + (Math.min(...caps) === Math.max(...caps) ? capacityLabel(caps[0]) : capacityLabel(Math.min(...caps)) + ' – ' + capacityLabel(Math.max(...caps)))
                    + (caps.length < group.units.length ? ' · ' + (group.units.length - caps.length) + ' fără capacitate' : '')
                : 'Fără capacitate proprie';
            const collapsed = state.collapsed.has(group.key);
            const wide = group.units.some((u) => u.kind === 'ansamblu');
            let body;
            if (state.view === 'list') {
                body = '<div class="kmc-group__body kmc-group__body--list"><div class="kmc-table-wrap"><table class="kmc-table"><thead><tr>'
                    + '<th>Vehicul</th><th>Categorie / cap.</th><th class="kmc-num">Odometru SAS</th><th class="kmc-num">Odometru CardOil</th><th>T0</th>'
                    + '<th class="kmc-num">SAS de la T0</th><th class="kmc-num">CardOil de la T0</th><th class="kmc-num">Oficial</th><th></th></tr></thead><tbody>'
                    + group.units.map(listRows).join('') + '</tbody></table></div></div>';
            } else {
                body = '<div class="kmc-group__body' + (wide ? ' kmc-group__body--wide' : '') + '">'
                    + group.units.map((u) => (u.kind === 'ansamblu' ? assemblyCard(u) : vehicleCard(u))).join('') + '</div>';
            }
            return '<section class="kmc-group' + (collapsed ? ' is-collapsed' : '') + '" data-group="' + esc(group.key) + '"' + style + '>'
                + '<header class="kmc-group__head" data-kmc-collapse="' + esc(group.key) + '" role="button" tabindex="0" aria-expanded="' + (!collapsed) + '">'
                + '<i class="bi ' + group.icon + ' kmc-group__icon" aria-hidden="true"></i>'
                + '<h2 class="kmc-group__title">' + esc(group.title) + (group.sub ? ' <span>(' + esc(group.sub) + ')</span>' : '') + '</h2>'
                + '<span class="kmc-group__badge">' + esc(badge) + '</span>'
                + '<span class="kmc-group__cap"><i class="bi bi-boxes"></i>' + esc(capText) + '</span>'
                + '<i class="bi bi-chevron-down kmc-group__chev" aria-hidden="true"></i>'
                + '</header>' + body + '</section>';
        }).join('');
        if (state.panel) {
            const card = container.querySelector('[data-unit="' + CSS.escape(state.panel.unitKey) + '"].kmc-card');
            if (card) {
                card.classList.add('is-selected');
            }
        }
    }

    function renderAll() {
        renderKpis();
        renderLastSync();
        renderGroups();
        animateChanged();
    }

    /** Aplica o stare noua (dupa sincronizare / reimprospatare / salvare T0). */
    function applyDashboard(dashboard) {
        if (!dashboard) {
            return;
        }
        state.data = dashboard;
        fillFilterOptions();
        renderAll();
        if (state.panel) {
            renderPanel();
        }
    }

    // ------------------------------------------------------------------ panou T0 / istoric

    function findUnit(key) {
        return (state.data.units || []).find((u) => u.key === key) || null;
    }

    function defaultT0(unit) {
        const existing = componentsOf(unit).map((c) => c.t0.requested_at).filter(Boolean).sort().pop();
        if (existing) {
            const d = parseDate(existing);
            return { date: d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()), time: pad(d.getHours()) + ':' + pad(d.getMinutes()) };
        }
        const now = new Date();
        return { date: now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-01', time: '00:00' };
    }

    function openPanel(unitKey, tab) {
        const unit = findUnit(unitKey);
        if (!unit) {
            return;
        }
        const t0 = defaultT0(unit);
        state.panel = { unitKey, tab: tab === 'history' ? 'history' : 't0', comp: 0, lookup: null, lookupT0: null, date: t0.date, time: t0.time, busy: false, error: null, history: {} };
        root.dataset.panel = 'open';
        $('[data-kmc-panel]').hidden = false;
        $$('.kmc-card.is-selected').forEach((c) => c.classList.remove('is-selected'));
        const card = root.querySelector('.kmc-card[data-unit="' + CSS.escape(unitKey) + '"]');
        if (card) {
            card.classList.add('is-selected');
        }
        renderPanel();
        if (state.panel.tab === 'history') {
            loadHistory();
        }
    }

    function closePanel() {
        state.panel = null;
        root.dataset.panel = 'closed';
        const panel = $('[data-kmc-panel]');
        panel.hidden = true;
        panel.innerHTML = '';
        $$('.kmc-card.is-selected').forEach((c) => c.classList.remove('is-selected'));
    }

    function hitHtml(provider, result) {
        const isSas = provider === 'sas';
        const title = isSas ? 'Citire istorică SAS Fleet' : 'Citire istorică CardOil Refill';
        const icon = isSas ? 'bi-broadcast' : 'bi-fuel-pump';
        if (!result || result.status === 'fara_sursa') {
            return '<div class="kmc-hit kmc-hit--off kmc-hit--' + provider + '"><span class="kmc-hit__icon"><i class="bi ' + icon + '"></i></span><div><b>' + title + '</b>Date indisponibile pentru această sursă'
                + (result && result.note ? '<div class="kmc-hit__note">' + esc(result.note) + '</div>' : '') + '</div></div>';
        }
        const reading = result.reading;
        const ok = result.status === 'ok';
        return '<div class="kmc-hit kmc-hit--' + provider + (ok ? '' : ' kmc-hit--warn') + '"><span class="kmc-hit__icon"><i class="bi ' + icon + '"></i></span><div><b>' + title + '</b>'
            + (reading ? esc(fmtDT(reading.measured_at)) + '<br>Kilometraj: <span class="kmc-hit__km">' + esc(fmtKm(reading.km)) + ' km</span>' : 'Nicio citire')
            + (ok ? '' : '<div><span class="kmc-pill kmc-pill--warn">' + (result.status === 'in_afara_tolerantei' ? 'în afara toleranței — nu se salvează' : 'indisponibil') + '</span></div>')
            + '<div class="kmc-hit__note">' + esc(result.note || '') + '</div></div></div>';
    }

    function activeBaselineHtml(comp) {
        const parts = ['sas', 'cardoil'].map((p) => {
            const b = comp.sources[p].baseline;
            if (!b) {
                return null;
            }
            const name = p === 'sas' ? 'SAS' : 'CardOil';
            return b.status === 'ok'
                ? name + ': ' + fmtKm(b.km) + ' km la ' + fmtDT(b.measured_at)
                : name + ': fără citire validă';
        }).filter(Boolean);
        if (!comp.t0.requested_at) {
            return '<p class="kmc-note">T0 activ: nesetat.</p>';
        }
        return '<p class="kmc-note">T0 activ: <b>' + esc(fmtDT(comp.t0.requested_at)) + '</b> (versiunea ' + esc(comp.t0.version) + ') · ' + esc(parts.join(' · ')) + '</p>';
    }

    function renderPanel() {
        const panel = $('[data-kmc-panel]');
        const p = state.panel;
        const unit = p ? findUnit(p.unitKey) : null;
        if (!unit) {
            closePanel();
            return;
        }
        const comps = componentsOf(unit);
        const main = comps[0];
        const title = (p.tab === 't0' ? 'Setează T0 – ' : 'Istoric – ') + comps.map((c) => c.plate).join(' + ');
        const tabs = '<div class="kmc-tabs" role="tablist">'
            + '<button type="button" data-kmc-tab="t0" class="' + (p.tab === 't0' ? 'is-active' : '') + '"><i class="bi bi-table"></i>Citiri</button>'
            + (state.perms.history ? '<button type="button" data-kmc-tab="history" class="' + (p.tab === 'history' ? 'is-active' : '') + '"><i class="bi bi-list-ul"></i>Istoric</button>' : '')
            + '</div>';

        const vehicle = '<div class="kmc-panel__veh">' + photoHtml(comps.length > 1 ? comps : [main])
            + '<div><h3>' + esc(unitLabel(unit) || main.plate) + '</h3><div class="kmc-plates">' + comps.map((c) => plateHtml(c.plate) + ' <small class="kmc-muted">' + esc(c.type_label) + '</small>').join('') + '</div>'
            + '<div class="kmc-chips" style="margin-top:6px">' + (unit.capacity_t !== null ? '<span><i class="bi bi-box-seam"></i>' + esc(capacityLabel(unit.capacity_t)) + '</span>' : '') + '<span>' + (unit.kind === 'ansamblu' ? 'Ansamblu' : esc(main.type_label)) + '</span></div>'
            + '<div>' + comps.map((c) => ['sas', 'cardoil'].map((prov) => '<span class="kmc-srcchip kmc-srcchip--' + (c.sources[prov].available ? prov : 'off') + '" title="' + esc(c.plate + ': ' + (c.sources[prov].available ? 'sursă disponibilă' : (c.sources[prov].reason || 'indisponibil'))) + '"><i class="bi ' + (prov === 'sas' ? 'bi-broadcast' : 'bi-fuel-pump') + '"></i>' + (prov === 'sas' ? 'SAS Fleet' : 'CardOil Refill') + (comps.length > 1 ? ' · ' + esc(c.plate) : '') + '</span>').join('')).join('') + '</div>'
            + '</div></div>';

        let body;
        let foot = '';
        if (p.tab === 't0') {
            const canEdit = state.perms.t0;
            const results = comps.map((comp, i) => {
                const lookup = p.lookup ? p.lookup[comp.id] : null;
                return '<div class="kmc-result' + (i === 1 ? ' kmc-result--trailer' : '') + '">'
                    + '<div class="kmc-result__title"><i class="bi ' + (i === 1 ? 'bi-truck-flatbed' : 'bi-truck-front-fill') + '"></i>' + (comps.length > 1 ? (i === 0 ? 'Cap tractor' : 'Semiremorcă') : esc(comp.type_label)) + ' – ' + esc(comp.plate) + '</div>'
                    + (lookup ? '<div class="kmc-result__cols">' + hitHtml('sas', lookup.sas) + hitHtml('cardoil', lookup.cardoil) + '</div>' : activeBaselineHtml(comp))
                    + '</div>';
            }).join('');
            const okCount = p.lookup ? comps.reduce((n, c) => n + ['sas', 'cardoil'].filter((prov) => p.lookup[c.id] && p.lookup[c.id][prov] && p.lookup[c.id][prov].status === 'ok').length, 0) : 0;
            body = vehicle
                + (canEdit
                    ? '<p class="kmc-step">1. Selectează data și ora</p><div class="kmc-dt"><label><i class="bi bi-calendar3"></i><input type="date" data-kmc-t0-date value="' + esc(p.date) + '" max="' + new Date().toISOString().slice(0, 10) + '"></label>'
                        + '<label><i class="bi bi-clock"></i><input type="time" data-kmc-t0-time value="' + esc(p.time) + '"></label></div>'
                        + '<p class="kmc-step">2. Preia citirile istorice din ambele API</p>'
                        + '<button type="button" class="kmc-btn kmc-btn--primary kmc-btn--block' + (p.busy === 'lookup' ? ' is-busy' : '') + '" data-kmc-lookup' + (p.busy ? ' disabled' : '') + '><i class="bi ' + (p.busy === 'lookup' ? 'bi-arrow-repeat' : 'bi-search') + '"></i>' + (p.busy === 'lookup' ? 'Caut în SAS și CardOil…' : 'Caută citiri istorice') + '</button>'
                    : '<p class="kmc-note">Nu ai dreptul să modifici T0.</p>')
                + results
                + (p.lookup && okCount === 0 ? '<div class="kmc-alert">Nicio sursă nu are o citire validă la acest T0. Alege alt moment (de preferat când vehiculul stătea parcat).</div>' : '')
                + (p.error ? '<div class="kmc-alert kmc-alert--err">' + esc(p.error) + '</div>' : '')
                + (canEdit && p.lookup ? '<input type="text" class="kmc-reason" data-kmc-reason maxlength="255" placeholder="Motiv (opțional), ex. început de lună, schimb anvelope">' : '')
                + '<p class="kmc-note">SAS: citirea de la sfârșitul ultimei deplasări înainte de T0; acceptată dacă vehiculul stătea parcat sau în toleranța de ' + esc(state.data.settings.sas_toleranta_minute) + ' min. CardOil: ultima alimentare înainte de T0, maxim ' + esc(state.data.settings.cardoil_varsta_max_ore) + ' ore. Nicio valoare nu se interpolează. Versiunile T0 anterioare rămân în istoric.</p>';
            foot = '<button type="button" class="kmc-btn" data-kmc-close>Anulează</button>'
                + (canEdit ? '<button type="button" class="kmc-btn kmc-btn--primary' + (p.busy === 'save' ? ' is-busy' : '') + '" data-kmc-save' + (!p.lookup || okCount === 0 || p.busy ? ' disabled' : '') + '>' + (p.busy === 'save' ? '<i class="bi bi-arrow-repeat"></i>' : '') + 'Salvează T0</button>' : '');
        } else {
            const compTabs = comps.length > 1
                ? '<div class="kmc-comptabs">' + comps.map((c, i) => '<button type="button" data-kmc-comp="' + i + '" class="' + (p.comp === i ? 'is-active' : '') + '">' + (i === 0 ? 'Cap tractor' : 'Semiremorcă') + ' · ' + esc(c.plate) + '</button>').join('') + '</div>'
                : '';
            const comp = comps[p.comp] || main;
            body = compTabs + historyHtml(comp, p.history[comp.id]);
        }

        panel.innerHTML = '<div class="kmc-panel__head"><h2 title="' + esc(title) + '">' + esc(title) + '</h2>' + tabs
            + '<button type="button" class="kmc-close" data-kmc-close aria-label="Închide">&times;</button></div>'
            + '<div class="kmc-panel__body">' + body + '</div>'
            + (foot ? '<div class="kmc-panel__foot">' + foot + '</div>' : '');
    }

    function historyHtml(comp, entry) {
        if (!entry || entry.loading) {
            return '<p class="kmc-note"><i class="bi bi-arrow-repeat"></i> Se încarcă istoricul…</p>';
        }
        if (entry.error) {
            return '<div class="kmc-alert kmc-alert--err">' + esc(entry.error) + '</div>';
        }
        const h = entry.data;
        const statusPill = (status) => ({ ok: '<span class="kmc-pill kmc-pill--ok">valid</span>', in_afara_tolerantei: '<span class="kmc-pill kmc-pill--warn">în afara toleranței</span>' }[status] || '<span class="kmc-pill">' + esc(status) + '</span>');

        const versions = h.baselines.length
            ? '<table class="kmc-table"><thead><tr><th>Ver.</th><th>T0 cerut</th><th>Sursă</th><th>Citire la</th><th class="kmc-num">km</th><th>Stare</th><th>De</th></tr></thead><tbody>'
                + h.baselines.map((b) => '<tr class="' + (Number(b.is_active) === 1 ? '' : 'is-old') + '"><td>v' + esc(b.baseline_version) + (Number(b.is_active) === 1 ? ' <span class="kmc-pill kmc-pill--ok">activ</span>' : '') + '</td><td>' + esc(fmtDT(b.requested_t0_at)) + '</td><td>' + (b.provider_code === 'sas' ? 'SAS' : 'CardOil') + '</td><td>' + esc(fmtDT(b.measured_t0_at)) + '</td><td class="kmc-num">' + esc(b.baseline_value_km !== null ? fmtKm(b.baseline_value_km) : '—') + '</td><td title="' + esc(b.status_note || '') + '">' + statusPill(b.retrieval_status) + '</td><td>' + esc(b.created_by_name || '—') + '<small>' + esc(fmtDT(b.created_at)) + '</small></td></tr>').join('')
                + '</tbody></table>'
            : '<p class="kmc-note">Nicio referință T0 salvată.</p>';

        const series = (rows, provider) => {
            if (!rows.length) {
                return '<p class="kmc-note">' + esc(comp.sources[provider].reason || 'Date indisponibile pentru această sursă.') + '</p>';
            }
            const shown = rows.slice(0, entry.all && entry.all[provider] ? rows.length : 25);
            let previousKm = null;
            const body = shown.map((r, i) => {
                const next = shown[i + 1];
                const delta = next && !r.anomaly && !next.anomaly ? r.km - next.km : null;
                previousKm = r.km;
                return '<tr class="' + (r.anomaly ? 'is-anomaly' : '') + '"><td>' + esc(fmtDT(r.measured_at)) + '</td><td class="kmc-num">' + esc(fmtKm(r.km)) + '</td><td class="kmc-num">' + (delta !== null ? esc('+' + fmtKm(delta)) : '') + '</td>'
                    + (provider === 'cardoil' ? '<td>' + esc(r.fuel_type || '') + (r.corrected ? ' <span class="kmc-pill">corectat</span>' : '') + '</td>' : '')
                    + '<td>' + (r.anomaly ? '<span class="kmc-pill kmc-pill--anom" title="' + esc(r.anomaly) + '">anomalie</span> <small>' + esc(r.anomaly) + '</small>' : '') + '</td></tr>';
            }).join('');
            const more = rows.length > shown.length ? '<button type="button" class="kmc-link" data-kmc-more="' + provider + '">Arată toate (' + rows.length + ')</button>' : '';
            void previousKm;
            return '<table class="kmc-table"><thead><tr><th>Măsurat la sursă</th><th class="kmc-num">Odometru</th><th class="kmc-num">Δ km</th>' + (provider === 'cardoil' ? '<th>Carburant</th>' : '') + '<th></th></tr></thead><tbody>' + body + '</tbody></table>' + more;
        };

        const diff = comp.comparison && comp.comparison.comparable
            ? '<div class="kmc-alert ' + (comp.comparison.alert ? '' : 'kmc-alert--ok') + '">Diferență SAS − CardOil: <b>' + esc(signedKm(comp.comparison.diff_km)) + '</b>' + (comp.comparison.diff_pct !== null ? ' (' + esc(pctFormat.format(comp.comparison.diff_pct)) + '% din distanța SAS)' : '') + '. ' + esc(comp.comparison.note) + ' Diagnostic, nu km în plus.</div>'
            : '';
        const since = '<p class="kmc-note">De la T0 — SAS: <b>' + esc(signedKm(comp.sources.sas.distance.km)) + '</b> · CardOil: <b>' + esc(signedKm(comp.sources.cardoil.distance.km)) + '</b></p>';

        const audit = h.audit
            ? '<h4><i class="bi bi-journal-text"></i>Jurnal de audit</h4>' + (h.audit.length
                ? '<table class="kmc-table"><tbody>' + h.audit.map((a) => '<tr><td>' + esc(fmtDT(a.created_at)) + '</td><td>' + esc(a.action) + '</td><td>' + esc(a.user_name || '—') + '</td><td><small>' + esc(a.reason || '') + '</small></td></tr>').join('') + '</tbody></table>'
                : '<p class="kmc-note">Nicio modificare.</p>')
            : '';

        const fuelLink = state.perms.fuel ? ' <a class="kmc-link" href="' + esc(page.urls.fuel) + '">Corectezi în Carburanți</a>' : '';
        return '<div class="kmc-hist">'
            + '<h4><i class="bi bi-flag"></i>Referințe T0 (toate versiunile)</h4>' + versions + since + diff
            + '<h4 style="color:var(--kmc-blue)"><i class="bi bi-broadcast"></i>Citiri SAS Fleet <small class="kmc-muted">(odometru GPS, ultimele ' + esc(h.days) + ' zile)</small></h4>' + series(h.sas, 'sas')
            + '<h4 style="color:var(--kmc-purple)"><i class="bi bi-fuel-pump"></i>Citiri CardOil Refill <small class="kmc-muted">(pe eveniment: alimentări)</small></h4>'
            + '<p class="kmc-note">Fiecare rând e o alimentare: odometrul tastat la pompă. Între alimentări nu există citiri.' + fuelLink + '</p>' + series(h.cardoil, 'cardoil')
            + audit + '</div>';
    }

    async function loadHistory() {
        const p = state.panel;
        const unit = p ? findUnit(p.unitKey) : null;
        if (!unit) {
            return;
        }
        const comp = componentsOf(unit)[p.comp];
        if (p.history[comp.id] && !p.history[comp.id].error) {
            return;
        }
        p.history[comp.id] = { loading: true };
        renderPanel();
        try {
            const json = await getJson(page.urls.history + '&vehicle_id=' + encodeURIComponent(comp.id) + '&days=30');
            p.history[comp.id] = { data: json.history, all: {} };
        } catch (error) {
            p.history[comp.id] = { error: error.message };
        }
        if (state.panel === p) {
            renderPanel();
        }
    }

    function panelT0() {
        const p = state.panel;
        return p.date && p.time ? p.date + ' ' + p.time : '';
    }

    async function lookup() {
        const p = state.panel;
        const unit = findUnit(p.unitKey);
        p.busy = 'lookup';
        p.error = null;
        renderPanel();
        try {
            const json = await post(page.urls.t0_lookup, { t0: panelT0(), vehicle_ids: componentsOf(unit).map((c) => c.id) });
            if (!json.ok) {
                throw new Error(json.message || 'Căutarea a eșuat.');
            }
            p.lookup = json.results;
            p.lookupT0 = panelT0();
        } catch (error) {
            p.error = error.message;
            p.lookup = null;
        }
        p.busy = false;
        if (state.panel === p) {
            renderPanel();
        }
    }

    async function saveT0() {
        const p = state.panel;
        const unit = findUnit(p.unitKey);
        if (p.lookupT0 !== panelT0()) {
            p.error = 'Data sau ora s-au schimbat după căutare: caută din nou citirile.';
            p.lookup = null;
            renderPanel();
            return;
        }
        const reasonNode = $('[data-kmc-reason]');
        p.busy = 'save';
        renderPanel();
        try {
            const json = await post(page.urls.t0_save, {
                t0: p.lookupT0,
                reason: reasonNode ? reasonNode.value : '',
                vehicle_ids: componentsOf(unit).filter((c) => c.monitored).map((c) => c.id),
            });
            if (!json.ok) {
                throw new Error(json.message || 'Salvarea a eșuat.');
            }
            toast(json.message);
            p.busy = false;
            p.lookup = null;
            applyDashboard(json.dashboard);
        } catch (error) {
            p.busy = false;
            p.error = error.message;
            renderPanel();
        }
    }

    // ------------------------------------------------------------------ sincronizare

    async function refreshData(quiet) {
        try {
            const json = await getJson(page.urls.data);
            applyDashboard(json.dashboard);
        } catch (error) {
            if (!quiet) {
                toast(error.message, true);
            }
        }
    }

    async function syncNow() {
        if (state.syncing) {
            return;
        }
        state.syncing = true;
        const button = $('[data-kmc-sync]');
        const label = button.querySelector('span');
        button.disabled = true;
        button.classList.add('is-busy');
        let last = null;
        let checked = 0;
        let fresh = 0;
        try {
            for (let round = 0; round < 20; round++) {
                const json = await post(page.urls.sync, {});
                last = json.stats || {};
                checked += last.updated || 0;
                fresh += last.new_readings || 0;
                if (last.locked || last.aborted || !last.pending) {
                    break;
                }
                label.textContent = 'Actualizez… ' + last.pending + ' rămase';
                await refreshData(true);
            }
            await refreshData(true);
            const failed = !!(last && (last.aborted || last.locked));
            toast(failed || checked === 0
                ? (last && last.message ? last.message : 'Datele au fost actualizate.')
                : checked + ' vehicule verificate în SAS, ' + fresh + ' citiri noi. CardOil se actualizează din sincronizarea alimentărilor.', failed);
        } catch (error) {
            toast(error.message, true);
        } finally {
            state.syncing = false;
            button.disabled = false;
            button.classList.remove('is-busy');
            label.textContent = 'Actualizează datele';
        }
    }

    // ------------------------------------------------------------------ evenimente

    function bind() {
        $$('[data-kmc-filter]').forEach((input) => {
            const handler = () => {
                state.filters[input.dataset.kmcFilter] = input.value.trim();
                savePrefs();
                renderGroups();
                animateChanged();
            };
            input.addEventListener(input.tagName === 'INPUT' ? 'input' : 'change', handler);
        });
        $$('[data-kmc-view]').forEach((button) => button.addEventListener('click', () => {
            state.view = button.dataset.kmcView;
            $$('[data-kmc-view]').forEach((b) => b.classList.toggle('is-active', b === button));
            savePrefs();
            renderGroups();
            animateChanged();
        }));
        $('[data-kmc-toggle-all]').addEventListener('click', () => {
            const keys = $$('[data-group]').map((g) => g.dataset.group);
            const collapseAll = keys.some((k) => !state.collapsed.has(k));
            keys.forEach((k) => (collapseAll ? state.collapsed.add(k) : state.collapsed.delete(k)));
            savePrefs();
            renderGroups();
            animateChanged();
        });
        $('[data-kmc-sync]').addEventListener('click', syncNow);
        $('[data-kmc-info]').addEventListener('click', () => {
            const box = $('[data-kmc-infobox]');
            box.hidden = !box.hidden;
        });

        root.addEventListener('click', (event) => {
            const collapse = event.target.closest('[data-kmc-collapse]');
            if (collapse) {
                const key = collapse.dataset.kmcCollapse;
                state.collapsed.has(key) ? state.collapsed.delete(key) : state.collapsed.add(key);
                collapse.parentElement.classList.toggle('is-collapsed');
                collapse.setAttribute('aria-expanded', String(!state.collapsed.has(key)));
                savePrefs();
                return;
            }
            const open = event.target.closest('[data-kmc-open]');
            if (open) {
                openPanel(open.dataset.unit, open.dataset.kmcOpen);
                return;
            }
            if (event.target.closest('[data-kmc-close]')) {
                closePanel();
                return;
            }
            const tab = event.target.closest('[data-kmc-tab]');
            if (tab && state.panel) {
                state.panel.tab = tab.dataset.kmcTab;
                renderPanel();
                if (state.panel.tab === 'history') {
                    loadHistory();
                }
                return;
            }
            const comp = event.target.closest('[data-kmc-comp]');
            if (comp && state.panel) {
                state.panel.comp = Number(comp.dataset.kmcComp);
                renderPanel();
                loadHistory();
                return;
            }
            const more = event.target.closest('[data-kmc-more]');
            if (more && state.panel) {
                const unit = findUnit(state.panel.unitKey);
                const entry = state.panel.history[componentsOf(unit)[state.panel.comp].id];
                entry.all[more.dataset.kmcMore] = true;
                renderPanel();
                return;
            }
            if (event.target.closest('[data-kmc-lookup]')) {
                lookup();
                return;
            }
            if (event.target.closest('[data-kmc-save]')) {
                saveT0();
                return;
            }
            if (state.panel && root.dataset.panel === 'open' && event.target === root) {
                closePanel();
            }
        });
        root.addEventListener('keydown', (event) => {
            const collapse = event.target.closest('[data-kmc-collapse]');
            if (collapse && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                collapse.click();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && state.panel) {
                closePanel();
            }
        });
        root.addEventListener('change', async (event) => {
            const p = state.panel;
            if (event.target.matches('[data-kmc-t0-date]') && p) {
                p.date = event.target.value;
                p.lookup = null;
                renderPanel();
            } else if (event.target.matches('[data-kmc-t0-time]') && p) {
                p.time = event.target.value;
                p.lookup = null;
                renderPanel();
            } else if (event.target.matches('[data-kmc-policy]')) {
                try {
                    const json = await post(page.urls.save_policy, { policy: event.target.value });
                    if (!json.ok) {
                        throw new Error(json.message);
                    }
                    toast(json.message);
                    applyDashboard(json.dashboard);
                } catch (error) {
                    toast(error.message, true);
                    renderKpis();
                }
            }
        });

        // Reimprospatare din baza de date (fara apeluri API): cron-ul sau alt utilizator a adus citiri noi.
        setInterval(() => {
            if (document.visibilityState === 'visible' && !state.syncing && !(state.panel && state.panel.busy)) {
                refreshData(true);
            }
        }, REFRESH_MS);
    }

    loadPrefs();
    fillFilterOptions();
    renderAll();
    bind();

    // Pentru testele de acceptanta din browser (animatie independenta per sursa).
    window.KmCenter = { applyDashboard, state, rollOdometer };
}());
