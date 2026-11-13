/**
 * Carburanți — carduri KPI morph.
 *
 * Arhitectura (un singur sistem pentru toate cele 5 carduri):
 *   - CARDS: definitia fiecarui card (taburi, metrici, coloane, sub-metrici, actiuni). Doar date.
 *   - Data layer: fetchPart() + cache pe cheie (filtre normalizate + parte + grupare), promisiuni
 *     partajate (nicio cerere dubla), rezultate rezolvate tinute sincron pentru randare in morph.
 *   - Stare: global (filtrele paginii, doar citire) / local per card (perioada, grupare, vehicule,
 *     tip transport, tab, segment, punct, drill) + istoric pentru „Înapoi".
 *   - Morph: FLIP cu transform (translate + scale) pe card, contra-scalare pe continut, elemente
 *     partajate (icon, titlu, valoare, variatie) animate in coordonatele cardului.
 *   - Randare: panourile (taburile) sunt create o data si doar actualizate; graficul Chart.js e
 *     unul per card, creat lenes dupa animatie si apoi doar actualizat (fara destroy).
 *
 * Datele vin exclusiv din action=kpi_card_data (FuelController), cu aceleasi reguli ca KPI-urile
 * paginii. Nicio valoare nu e estimata in browser in afara rapoartelor documentate (ex. cost/km).
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-fkm-root]');
    if (!root) {
        return;
    }

    let config = {};
    try {
        config = JSON.parse(root.querySelector('[data-fkm-config]').textContent || '{}');
    } catch (error) {
        return;
    }
    const GLOBAL = Object.assign({ vehicles: [] }, config.global || {});
    const TRANSPORT_LABELS = config.transportLabels || {};
    const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    const motionOk = () => !reducedMotionQuery.matches;

    // ------------------------------------------------------------------ formatare
    const fmt = (value, decimals) => {
        if (value === null || value === undefined || !isFinite(value)) {
            return '–';
        }
        const negative = value < 0;
        const parts = Math.abs(value).toFixed(decimals).split('.');
        const int = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (negative ? '-' : '') + int + (parts[1] ? ',' + parts[1] : '');
    };
    const F = {
        l: (v) => fmt(v, 2) + ' L',
        lei: (v) => fmt(v, 2) + ' lei',
        pct: (v) => fmt(v, 2) + ' %',
        km: (v) => fmt(v, 0) + ' km',
        l100: (v) => fmt(v, 2) + ' L/100 km',
        price: (v) => fmt(v, 3) + ' lei/L',
        perKm: (v) => fmt(v, 2) + ' lei/km',
        int: (v) => fmt(v, 0),
        short: (v) => (Math.abs(v) >= 10000 ? fmt(v / 1000, 1) + 'k' : fmt(v, Math.abs(v) < 10 ? 1 : 0)),
    };
    const esc = (value) => String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const pad = (n) => String(n).padStart(2, '0');
    const toIso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const fromIso = (s) => {
        const p = String(s).split('-').map(Number);
        return new Date(p[0], (p[1] || 1) - 1, p[2] || 1);
    };
    const dateRo = (iso) => {
        const p = String(iso || '').split('-');
        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : '';
    };
    const MONTHS = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
    const periodLabel = (from, to) => {
        if (from === GLOBAL.date_from && to === GLOBAL.date_to && GLOBAL.period_label) {
            return GLOBAL.period_label;
        }
        const a = fromIso(from);
        const b = fromIso(to);
        const lastDay = new Date(a.getFullYear(), a.getMonth() + 1, 0);
        if (a.getDate() === 1 && toIso(b) === toIso(lastDay)) {
            return MONTHS[a.getMonth()] + ' ' + a.getFullYear();
        }
        return dateRo(from) + ' – ' + dateRo(to);
    };
    const rangeRo = (from, to) => (from === to ? dateRo(from) : dateRo(from).slice(0, 5) + '–' + dateRo(to));
    const sum = (rows, get) => rows.reduce((acc, row) => acc + (Number(get(row)) || 0), 0);
    const ratio = (a, b, mul) => (b > 0 && a !== null ? (a / b) * (mul || 1) : null);
    const accentRgb = (card) => getComputedStyle(card.el).getPropertyValue('--fkm-rgb').trim() || '37, 99, 235';

    // ------------------------------------------------------------------ easing (cubic-bezier .2,.8,.2,1)
    const EASING_CSS = 'cubic-bezier(.2, .8, .2, 1)';
    const bezier = (x1, y1, x2, y2) => {
        const a = (a1, a2) => 1 - 3 * a2 + 3 * a1;
        const b = (a1, a2) => 3 * a2 - 6 * a1;
        const c = (a1) => 3 * a1;
        const calc = (t, a1, a2) => ((a(a1, a2) * t + b(a1, a2)) * t + c(a1)) * t;
        const slope = (t, a1, a2) => 3 * a(a1, a2) * t * t + 2 * b(a1, a2) * t + c(a1);
        return (x) => {
            if (x <= 0 || x >= 1) {
                return x <= 0 ? 0 : 1;
            }
            let t = x;
            for (let i = 0; i < 6; i++) {
                const s = slope(t, x1, x2);
                if (s === 0) {
                    break;
                }
                t -= (calc(t, x1, x2) - x) / s;
            }
            return calc(t, y1, y2);
        };
    };
    const EASE = bezier(0.2, 0.8, 0.2, 1);

    // ------------------------------------------------------------------ definitiile cardurilor
    const TAB_META = {
        evo: { label: 'Evoluție', icon: 'bi-bar-chart-line' },
        breakdown: { label: 'Defalcare', icon: 'bi-pie-chart' },
        vehicles: { label: 'Vehicule', icon: 'bi-truck' },
        transport: { label: 'Tip transport', icon: 'bi-signpost-split' },
        ratio: { label: 'Raport AdBlue', icon: 'bi-percent' },
        can: { label: 'GPS / CAN', icon: 'bi-cpu' },
        fuel: { label: 'Tip carburant', icon: 'bi-fuel-pump' },
        drill: { label: 'Alimentări', icon: 'bi-list-ul' },
    };
    const fuelOf = (data, key) => (data.fuel_types || []).find((t) => t.key === key) || { liters: 0, value: 0, fillups: 0, price: null };
    const costPerKm = (s) => ratio(s.total_value, s.linked_km);
    const kmSourceLabel = (src) => (src === 'alimentari' ? 'odometru alimentări' : src === 'dispecer' ? 'km curse Dispecer' : 'fără km');
    const adblueFlag = (row, deviation) => {
        if (row.motorina < 300 || row.motorina_prev < 300 || !row.consum_adblue_prev) {
            return false;
        }
        return Math.abs(row.consum_adblue - row.consum_adblue_prev) / row.consum_adblue_prev >= deviation;
    };
    const deltaPp = (row) => (row.consum_adblue_prev !== null && row.motorina > 0 ? row.consum_adblue - row.consum_adblue_prev : null);

    const CARDS = {
        motorina: {
            title: 'Motorină consumată',
            tabs: ['evo', 'breakdown', 'vehicles', 'transport'],
            drillFuel: 'motorina',
            detailTab: 'fuel-fillups',
            metrics: [
                { id: 'liters', label: 'Litri motorină', get: (b) => b.motorina_liters, fmt: F.l, zero: true },
                { id: 'price', label: 'Cost mediu/litru', get: (b) => b.motorina_price, fmt: F.price, zero: false },
            ],
            header: (d) => ({ value: F.l(d.summary.motorina_liters), change: d.changes.motorina_liters, sub: 'Cost total: ' + F.lei(d.summary.total_value) }),
            side: { title: 'Defalcare consum', source: 'categories', value: (r) => r.motorina, fmt: F.l },
            breakdown: { value: (r) => r.motorina, fmt: F.l, meta: (r) => F.lei(r.total_value) + ' · ' + F.int(r.vehicles.length) + ' vehicule' },
            transport: { value: (r) => r.motorina, fmt: F.l },
            columns: [
                { id: 'motorina', label: 'Motorină', get: (r) => r.motorina, fmt: F.l },
                { id: 'motorina_value', label: 'Cost', get: (r) => r.motorina_value, fmt: F.lei },
                { id: 'motorina_fillups', label: 'Alim.', get: (r) => r.motorina_fillups, fmt: F.int },
                { id: 'consum_motorina', label: 'L/100 km', get: (r) => (r.consum_motorina > 0 ? r.consum_motorina : null), fmt: (v) => fmt(v, 2), tag: (r) => (r.consum_motorina > 0 && r.km_source === 'dispecer' ? 'Dispecer' : '') },
            ],
            defaultSort: { field: 'motorina', dir: -1 },
            footer: [
                { icon: 'bi-cash-stack', label: 'Cost motorină', value: (d) => F.lei(fuelOf(d, 'motorina').value), go: { card: 'cost', tab: 'fuel' } },
                { icon: 'bi-tag', label: 'Cost mediu/litru', value: (d) => F.price(fuelOf(d, 'motorina').price), go: { tab: 'evo', metric: 'price' } },
                { icon: 'bi-signpost-2', label: 'Km parcurși', value: (d) => F.km(d.summary.linked_km), hint: (d) => kmSourceLabel(d.summary.consumption_km_source), go: { card: 'consum', tab: 'vehicles', sort: { field: 'km', dir: -1 } } },
                { icon: 'bi-speedometer2', label: 'Consum mediu', value: (d) => F.l100(d.summary.motorina_avg_l100 || null), go: { card: 'consum' } },
            ],
            primary: { label: 'Analizează consumul', icon: 'bi-graph-up', go: { card: 'consum' } },
            info: '<p><strong>Litri de motorină alimentați</strong> în perioadă (CardOil + bonuri manuale), cu aceleași reguli ca pagina.</p><p>Alimentarea nu e consumul motorului: pentru consum (L/100 km) vezi cardul „Consum mediu Motorină”. Variația compară cu perioada precedentă de aceeași lungime.</p>',
        },
        adblue: {
            title: 'AdBlue consumat',
            tabs: ['evo', 'vehicles', 'ratio', 'transport'],
            drillFuel: 'adblue',
            detailTab: 'fuel-fillups',
            metrics: [
                { id: 'liters', label: 'Litri AdBlue', get: (b) => b.adblue_liters, fmt: F.l, zero: true },
                { id: 'versus', label: 'AdBlue vs motorină', get: (b) => b.adblue_liters, fmt: F.l, zero: true, dual: { label: 'Motorină (axa dreaptă)', get: (b) => b.motorina_liters, fmt: F.l } },
            ],
            header: (d) => ({ value: F.l(d.summary.adblue_liters), change: d.changes.adblue_liters, sub: 'Raport la motorină: ' + (d.summary.motorina_liters > 0 ? F.pct(d.summary.adblue_percent) : 'indisponibil') }),
            side: { title: 'Cele mai mari alimentări AdBlue', source: 'vehicles', value: (r) => r.adblue, fmt: F.l, limit: 6 },
            transport: { value: (r) => r.adblue, fmt: F.l },
            columns: [
                { id: 'adblue', label: 'AdBlue', get: (r) => r.adblue, fmt: F.l },
                { id: 'motorina', label: 'Motorină', get: (r) => r.motorina, fmt: F.l },
                { id: 'consum_adblue', label: 'Raport', get: (r) => (r.motorina > 0 ? r.consum_adblue : null), fmt: (v) => fmt(v, 2) + '%' },
                { id: 'delta', label: 'Δ față de prec.', get: deltaPp, fmt: (v) => (v > 0 ? '+' : '') + fmt(v, 2) + ' pp', flag: true },
            ],
            defaultSort: { field: 'adblue', dir: -1 },
            footer: [
                { icon: 'bi-percent', label: 'Raport AdBlue/motorină', value: (d) => (d.summary.motorina_liters > 0 ? F.pct(d.summary.adblue_percent) : 'indisponibil'), go: { card: 'adblue_pct' } },
                { icon: 'bi-cash-stack', label: 'Cost AdBlue', value: (d) => F.lei(fuelOf(d, 'adblue').value), go: { card: 'cost', tab: 'fuel' } },
                { icon: 'bi-receipt', label: 'Alimentări AdBlue', value: (d) => F.int(fuelOf(d, 'adblue').fillups), go: { drill: 'period' } },
                { icon: 'bi-truck', label: 'Vehicule cu AdBlue', value: (d) => F.int((d.vehicles || []).filter((v) => v.adblue > 0).length), go: { tab: 'vehicles' } },
            ],
            primary: { label: 'Analizează raportul', icon: 'bi-percent', go: { card: 'adblue_pct' } },
            info: '<p><strong>Litri de AdBlue alimentați</strong> în perioadă. Alimentarea nu înseamnă consum în aceeași zi: pe perioade scurte raportul față de motorină fluctuează după momentul plinului.</p><p>Semnalarea „de verificat” folosește pragul din Consumabile (abatere ≥ 40% față de perioada precedentă, cu cel puțin 300 L de motorină în ambele perioade). Nu indică singură un defect.</p>',
        },
        consum: {
            title: 'Consum mediu Motorină',
            tabs: ['evo', 'vehicles', 'transport', 'can'],
            drillFuel: 'motorina',
            detailTab: 'fuel-trips',
            metrics: [
                { id: 'l100', label: 'Consum (L/100 km)', get: (b) => b.l100, fmt: F.l100, zero: false, average: (d) => d.summary.motorina_avg_l100 },
            ],
            header: (d) => ({
                value: d.summary.linked_km > 0 ? F.l100(d.summary.motorina_avg_l100) : 'Indisponibil',
                change: d.changes.motorina_avg_l100,
                sub: d.summary.linked_km > 0 ? F.l(d.summary.consumption_liters) + ' / ' + F.km(d.summary.linked_km) + ' din ' + (d.summary.consumption_km_source === 'alimentari' ? 'alimentari' : 'dispecer') : 'Nu există km valizi în perioadă',
            }),
            side: { title: 'Consum pe categorie', source: 'categories', value: (r) => r.consum_motorina, fmt: F.l100, relative: true, meta: (r) => F.km(r.km) },
            transport: { value: (r) => r.consum_motorina, fmt: F.l100, relative: true, meta: (r) => F.l(r.motorina) + ' / ' + F.km(r.km) },
            columns: [
                { id: 'consum_motorina', label: 'L/100 km', get: (r) => (r.consum_motorina > 0 ? r.consum_motorina : null), fmt: (v) => fmt(v, 2) },
                { id: 'km', label: 'Km', get: (r) => (r.km > 0 ? r.km : null), fmt: F.int, tag: (r) => (r.km > 0 ? (r.km_source === 'dispecer' ? 'Dispecer' : 'odometru') : '') },
                { id: 'liters_for_average', label: 'Litri în medie', get: (r) => (r.liters_for_average > 0 ? r.liters_for_average : null), fmt: (v) => fmt(v, 2) },
                { id: 'cost_per_km', label: 'Lei/km', get: (r) => (r.cost_per_km > 0 ? r.cost_per_km : null), fmt: (v) => fmt(v, 2) },
            ],
            defaultSort: { field: 'consum_motorina', dir: -1 },
            footer: [
                { icon: 'bi-droplet-half', label: 'Motorină în medie', value: (d) => F.l(d.summary.consumption_liters), go: { tab: 'vehicles', sort: { field: 'liters_for_average', dir: -1 } } },
                { icon: 'bi-signpost-2', label: 'Km folosiți', value: (d) => F.km(d.summary.linked_km), hint: (d) => kmSourceLabel(d.summary.consumption_km_source), go: { tab: 'vehicles', sort: { field: 'km', dir: -1 } } },
                { icon: 'bi-cash-coin', label: 'Cost / km', value: (d) => F.perKm(costPerKm(d.summary)), go: { card: 'cost', tab: 'vehicles', sort: { field: 'cost_per_km', dir: -1 } } },
                { icon: 'bi-fuel-pump', label: 'Motorină alimentată', value: (d) => F.l(d.summary.motorina_liters), go: { card: 'motorina' } },
            ],
            primary: { label: 'Compară cu CAN', icon: 'bi-cpu', go: { tab: 'can' } },
            info: '<p><strong>Consum din alimentări</strong> = litri motorină ÷ km × 100. Km vin din odometrul declarat la pompă între alimentări consecutive (intervale plauzibile 4–120 L/100 km); fără ele, din km curselor asociate (Dispecer).</p><p>Consumul <strong>CAN</strong> (tabul GPS / CAN) e o sursă separată, raportată de calculatorul motorului pe km GPS; nu se amestecă în această medie.</p>',
        },
        adblue_pct: {
            title: 'Consum mediu AdBlue',
            tabs: ['evo', 'vehicles', 'breakdown'],
            drillFuel: '',
            detailTab: 'fuel-transport',
            metrics: [
                { id: 'pct', label: 'AdBlue / motorină', get: (b) => b.adblue_pct, fmt: F.pct, zero: false },
            ],
            header: (d) => ({
                value: d.summary.motorina_liters > 0 ? F.pct(d.summary.adblue_percent) : 'Indisponibil',
                change: d.changes.adblue_percent,
                sub: d.summary.motorina_liters > 0 ? F.l(d.summary.adblue_liters) + ' AdBlue / ' + F.l(d.summary.motorina_liters) + ' motorină' : 'Nu există motorină alimentată în perioadă',
            }),
            side: { title: 'Raport pe categorie', source: 'categories', value: (r) => r.consum_adblue, fmt: F.pct, relative: true, meta: (r) => F.l(r.adblue) + ' AdBlue' },
            breakdown: { value: (r) => r.consum_adblue, fmt: F.pct, relative: true, meta: (r) => F.l(r.adblue) + ' AdBlue / ' + F.l(r.motorina) + ' motorină' },
            columns: [
                { id: 'consum_adblue', label: 'Raport', get: (r) => (r.motorina > 0 ? r.consum_adblue : null), fmt: (v) => fmt(v, 2) + '%' },
                { id: 'consum_adblue_prev', label: 'Precedent', get: (r) => r.consum_adblue_prev, fmt: (v) => fmt(v, 2) + '%' },
                { id: 'delta', label: 'Δ', get: deltaPp, fmt: (v) => (v > 0 ? '+' : '') + fmt(v, 2) + ' pp', flag: true },
                { id: 'adblue', label: 'AdBlue', get: (r) => r.adblue, fmt: F.l },
            ],
            defaultSort: { field: 'consum_adblue', dir: -1 },
            footer: [
                { icon: 'bi-bag-fill', label: 'AdBlue', value: (d) => F.l(d.summary.adblue_liters), go: { card: 'adblue' } },
                { icon: 'bi-fuel-pump', label: 'Motorină', value: (d) => F.l(d.summary.motorina_liters), go: { card: 'motorina' } },
                { icon: 'bi-exclamation-triangle', label: 'De verificat', value: (d) => F.int((d.vehicles || []).filter((r) => adblueFlag(r, d.adblue_deviation)).length) + ' vehicule', go: { tab: 'vehicles', sort: { field: 'delta', dir: -1 } } },
                { icon: 'bi-clock-history', label: 'Perioada precedentă', value: (d) => (d.previous.motorina_liters > 0 ? F.pct(d.previous.adblue_percent) : 'indisponibil'), go: { tab: 'evo' } },
            ],
            primary: { label: 'Vehicule de verificat', icon: 'bi-exclamation-triangle', go: { tab: 'vehicles', sort: { field: 'delta', dir: -1 } } },
            info: '<p><strong>Litri AdBlue ÷ litri motorină × 100</strong>, pe aceleași alimentări. Fără motorină în perioadă raportul nu se poate calcula și apare „indisponibil”.</p><p>Raportul depinde de momentul alimentărilor; folosiți perioade mai lungi (ex. 3–6 luni) pentru tendințe.</p>',
        },
        cost: {
            title: 'Cost total carburant',
            tabs: ['evo', 'fuel', 'vehicles', 'transport'],
            drillFuel: '',
            detailTab: 'fuel-fillups',
            metrics: [
                { id: 'total', label: 'Cost total', get: (b) => b.value, fmt: F.lei, zero: true },
                { id: 'motorina', label: 'Motorină', get: (b) => b.motorina_value, fmt: F.lei, zero: true },
                { id: 'adblue', label: 'AdBlue', get: (b) => b.adblue_value, fmt: F.lei, zero: true },
            ],
            header: (d) => ({ value: F.lei(d.summary.total_value), change: d.changes.total_value, sub: F.int(d.summary.total_fillups) + ' alimentări · toate tipurile de carburant' }),
            side: { title: 'Cost pe tip de carburant', source: 'fuel', value: (r) => r.value, fmt: F.lei, meta: (r) => F.l(r.liters) + ' · ' + (r.price ? F.price(r.price) : '–') },
            transport: { value: (r) => r.total_value, fmt: F.lei, meta: (r) => (r.km > 0 ? F.perKm(r.total_value / r.km) : 'fără km') },
            columns: [
                { id: 'total_value', label: 'Cost', get: (r) => r.total_value, fmt: F.lei },
                { id: 'total_liters', label: 'Litri', get: (r) => r.total_liters, fmt: F.l },
                { id: 'motorina_price', label: 'Lei/L motorină', get: (r) => (r.motorina > 0 && r.motorina_value > 0 ? r.motorina_value / r.motorina : null), fmt: (v) => fmt(v, 3) },
                { id: 'cost_per_km', label: 'Lei/km', get: (r) => (r.cost_per_km > 0 ? r.cost_per_km : null), fmt: (v) => fmt(v, 2) },
            ],
            defaultSort: { field: 'total_value', dir: -1 },
            footer: [
                { icon: 'bi-tag', label: 'Lei/L motorină', value: (d) => F.price(fuelOf(d, 'motorina').price), go: { tab: 'fuel' } },
                { icon: 'bi-signpost-2', label: 'Cost / km', value: (d) => F.perKm(costPerKm(d.summary)), go: { tab: 'vehicles', sort: { field: 'cost_per_km', dir: -1 } } },
                { icon: 'bi-receipt', label: 'Alimentări', value: (d) => F.int(d.summary.total_fillups), go: { drill: 'period' } },
                { icon: 'bi-clock-history', label: 'Perioada precedentă', value: (d) => F.lei(d.previous.total_value), go: { tab: 'evo' } },
            ],
            primary: { label: 'Cost pe vehicul', icon: 'bi-truck', go: { tab: 'vehicles', sort: { field: 'total_value', dir: -1 } } },
            info: '<p><strong>Suma valorilor alimentărilor</strong> din perioadă, pentru toate tipurile de carburant — aceeași bază ca valoarea înregistrată pe fiecare alimentare (CardOil / bon manual), fără recalcul de TVA.</p><p>Cost/km = cost total ÷ km folosiți în consumul mediu (odometru sau Dispecer).</p>',
        },
    };

    // ------------------------------------------------------------------ data layer
    const pending = new Map();   // cheie -> Promise
    const resolved = new Map();  // cheie -> rezultat (citire sincrona)
    const CACHE_LIMIT = 40;

    const normFilters = (f) => ({
        date_from: f.date_from,
        date_to: f.date_to,
        vehicles: (f.vehicles || []).slice().sort(),
        transport_group: f.transport_group || '',
        fuel_type: f.fuel_type || '',
        brand: f.brand || '',
        vehicle_class: f.vehicle_class || '',
    });
    const keyOf = (filters, part, extra) => JSON.stringify([part, normFilters(filters), extra || {}]);

    function fetchPart(filters, part, extra) {
        const key = keyOf(filters, part, extra);
        if (pending.has(key)) {
            return pending.get(key);
        }
        const params = new URLSearchParams();
        const f = normFilters(filters);
        Object.keys(f).forEach((name) => {
            if (name === 'vehicles') {
                f.vehicles.forEach((v) => params.append('vehicles[]', v));
            } else if (f[name] !== '') {
                params.append(name, f[name]);
            }
        });
        params.append('part', part);
        Object.keys(extra || {}).forEach((name) => params.append(name, extra[name]));

        const promise = fetch(config.endpoint + '&' + params.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json().catch(() => ({ ok: false })).then((json) => {
                if (!response.ok || !json.ok) {
                    throw new Error(json.error || 'Datele nu au putut fi încărcate.');
                }
                return json;
            }))
            .then((json) => {
                resolved.set(key, json);
                if (resolved.size > CACHE_LIMIT) {
                    const oldest = resolved.keys().next().value;
                    resolved.delete(oldest);
                    pending.delete(oldest);
                }
                return json;
            });
        pending.set(key, promise);
        // O eroare nu ramane in cache: urmatoarea deschidere reincearca.
        promise.catch(() => pending.delete(key));
        return promise;
    }

    // ------------------------------------------------------------------ stare
    const cardList = [];
    const cards = {};
    const app = { expanded: null, anims: [], morphDone: Promise.resolve(), pop: null };

    const globalLocal = () => ({
        date_from: GLOBAL.date_from,
        date_to: GLOBAL.date_to,
        vehicles: (GLOBAL.vehicles || []).slice(),
        transport_group: GLOBAL.transport_group || '',
        fuel_type: GLOBAL.fuel_type || '',
        brand: GLOBAL.brand || '',
        vehicle_class: GLOBAL.vehicle_class || '',
        grouping: 'day',
    });
    const sameFilters = (a, b) => JSON.stringify(normFilters(a)) === JSON.stringify(normFilters(b));
    const isLocal = (c) => !sameFilters(c.local, globalLocal());
    const variantFilters = (c, variant) => {
        if (variant === 'vehOpts') {
            return Object.assign({}, c.local, { vehicles: (GLOBAL.vehicles || []).slice() });
        }
        if (variant === 'trOpts') {
            return Object.assign({}, c.local, { transport_group: GLOBAL.transport_group || '' });
        }
        return c.local;
    };
    const mainExtra = (c) => ({ grouping: c.local.grouping });
    const readVariant = (c, variant) => {
        const json = resolved.get(keyOf(variantFilters(c, variant), 'main', mainExtra(c)));
        return json ? json.data : null;
    };

    cardList.push(...Array.prototype.map.call(root.querySelectorAll('[data-fkm-card]'), (el) => {
        const key = el.getAttribute('data-fkm-card');
        const def = CARDS[key];
        const card = {
            key,
            def,
            el,
            inner: el.querySelector('[data-fkm-inner]'),
            panel: el.querySelector('[data-fkm-panel]'),
            expandBtn: el.querySelector('[data-fkm-expand]'),
            valueEl: el.querySelector('[data-fkm-value]'),
            subEl: el.querySelector('[data-fkm-sub]'),
            changeWrap: el.querySelector('[data-fkm-change-wrap]'),
            contextEl: el.querySelector('[data-fkm-context]'),
            built: false,
            local: globalLocal(),
            tab: def.tabs[0],
            metric: def.metrics[0].id,
            sort: Object.assign({}, def.defaultSort),
            search: '',
            segment: null,
            point: null,
            drill: null,
            history: [],
            token: 0,
            chart: null,
            panes: {},
            dirty: {},
        };
        card.original = {
            value: card.valueEl.textContent,
            sub: card.subEl.textContent,
            change: card.changeWrap.innerHTML,
        };
        cards[key] = card;
        return card;
    }).filter((c) => c.def));

    // ------------------------------------------------------------------ anunturi (aria-live)
    const live = document.createElement('div');
    live.className = 'fkm-sr';
    live.setAttribute('aria-live', 'polite');
    root.appendChild(live);
    const announce = (text) => {
        live.textContent = '';
        window.setTimeout(() => { live.textContent = text; }, 30);
    };

    // ------------------------------------------------------------------ MORPH (FLIP)
    const RADIUS = 16;
    const STEPS = 14;
    const DURATION = 340;

    const sharedRects = (c) => {
        const base = c.el.getBoundingClientRect();
        const out = {};
        c.el.querySelectorAll('[data-fkm-shared]').forEach((node) => {
            const r = node.getBoundingClientRect();
            out[node.getAttribute('data-fkm-shared')] = { node, x: r.left - base.left, y: r.top - base.top, w: r.width, h: r.height };
        });
        return out;
    };

    function stopAnimations() {
        app.anims.forEach((animation) => animation.cancel());
        app.anims = [];
        root.classList.remove('is-morphing');
        cardList.forEach((c) => { c.el.style.zIndex = ''; });
    }

    /**
     * Masoara (First), aplica schimbarea (mutate), masoara (Last), inverseaza si anima.
     * Daca o animatie e deja in curs, pozitiile vizuale curente devin punctul de plecare
     * (retargetare fara salt la clicuri rapide).
     */
    function morph(mutate, changing) {
        const firstRects = cardList.map((c) => c.el.getBoundingClientRect());
        const firstShared = new Map(changing.map((c) => [c, sharedRects(c)]));
        const firstHeight = root.getBoundingClientRect().height;
        stopAnimations();
        mutate();

        if (!motionOk()) {
            app.morphDone = Promise.resolve();
            return app.morphDone;
        }

        const lastRects = cardList.map((c) => c.el.getBoundingClientRect());
        const lastShared = new Map(changing.map((c) => [c, sharedRects(c)]));
        const lastHeight = root.getBoundingClientRect().height;
        root.classList.add('is-morphing');
        const anims = [];

        cardList.forEach((c, i) => {
            const f = firstRects[i];
            const l = lastRects[i];
            if (!l.width || !l.height || !f.width || !f.height) {
                return;
            }
            const dx = f.left - l.left;
            const dy = f.top - l.top;
            const sx = f.width / l.width;
            const sy = f.height / l.height;
            if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5 && Math.abs(sx - 1) < 0.004 && Math.abs(sy - 1) < 0.004) {
                return;
            }
            const cardFrames = [];
            const innerFrames = [];
            for (let k = 0; k <= STEPS; k++) {
                const t = k / STEPS;
                const p = EASE(t);
                const cx = sx + (1 - sx) * p;
                const cy = sy + (1 - sy) * p;
                cardFrames.push({
                    offset: t,
                    transform: 'translate(' + (dx * (1 - p)).toFixed(2) + 'px,' + (dy * (1 - p)).toFixed(2) + 'px) scale(' + cx.toFixed(4) + ',' + cy.toFixed(4) + ')',
                    borderRadius: (RADIUS / cx).toFixed(2) + 'px / ' + (RADIUS / cy).toFixed(2) + 'px',
                });
                innerFrames.push({ offset: t, transform: 'scale(' + (1 / cx).toFixed(4) + ',' + (1 / cy).toFixed(4) + ')' });
            }
            if (changing.indexOf(c) !== -1) {
                c.el.style.zIndex = '3';
            }
            anims.push(c.el.animate(cardFrames, { duration: DURATION, easing: 'linear' }));
            anims.push(c.inner.animate(innerFrames, { duration: DURATION, easing: 'linear' }));
        });

        // Elementele partajate: FLIP in coordonatele cardului (continutul e contra-scalat).
        changing.forEach((c) => {
            const before = firstShared.get(c);
            const after = lastShared.get(c);
            Object.keys(after).forEach((name) => {
                const a = after[name];
                const b = before[name];
                if (!b || !b.w || !a.w || !a.h) {
                    return;
                }
                const scale = name === 'icon' ? b.w / a.w : (name === 'value' || name === 'title' ? b.h / a.h : 1);
                const dx = b.x - a.x;
                const dy = b.y - a.y;
                if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5 && Math.abs(scale - 1) < 0.01) {
                    return;
                }
                anims.push(a.node.animate([
                    { transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + scale + ')' },
                    { transform: 'none' },
                ], { duration: DURATION, easing: EASING_CSS }));
            });
            if (c.el.classList.contains('is-expanded')) {
                anims.push(c.panel.animate([
                    { opacity: 0, transform: 'translateY(10px)' },
                    { opacity: 1, transform: 'none' },
                ], { duration: 260, delay: 110, easing: EASING_CSS, fill: 'backwards' }));
                c.el.querySelectorAll('.fkm-change-note, .fkm-info, .fkm-collapse').forEach((node) => {
                    anims.push(node.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 200, delay: 140, fill: 'backwards' }));
                });
            }
        });

        // Inaltimea grilei urmeaza cardurile; continutul de sub ea (inclusiv cardurile pe categorie)
        // e doar impins, niciodata acoperit.
        if (Math.abs(firstHeight - lastHeight) > 1) {
            anims.push(root.animate([{ height: firstHeight + 'px' }, { height: lastHeight + 'px' }], { duration: DURATION, easing: EASING_CSS }));
        }

        app.anims = anims;
        app.morphDone = Promise.all(anims.map((a) => a.finished)).then(() => {
            if (app.anims === anims) {
                app.anims = [];
                root.classList.remove('is-morphing');
                cardList.forEach((c) => { c.el.style.zIndex = ''; });
            }
        }, () => {});
        return app.morphDone;
    }

    // ------------------------------------------------------------------ expand / collapse
    function setExpandedDom(c) {
        build(c);
        root.classList.add('has-expanded');
        c.el.classList.add('is-expanded');
        c.panel.hidden = false;
        c.expandBtn.setAttribute('aria-expanded', 'true');
        renderChrome(c);
        // Date deja in cache: antet, subsol si panouri se pregatesc INAINTE de masurarea morph-ului.
        if (readVariant(c, 'main') && c.renderedKey !== mainKey(c)) {
            renderAll(c, true);
        } else if (readVariant(c, 'main')) {
            renderHeader(c, readVariant(c, 'main'));
        }
    }

    function setCollapsedDom(c) {
        c.el.classList.remove('is-expanded');
        c.panel.hidden = true;
        c.expandBtn.setAttribute('aria-expanded', 'false');
        // Cardul compact arata mereu cifrele globale ale paginii.
        c.valueEl.textContent = c.original.value;
        c.subEl.textContent = c.original.sub;
        c.subEl.title = c.original.sub;
        c.changeWrap.innerHTML = c.original.change;
        c.contextEl.hidden = true;
    }

    function expand(c, options) {
        closePop();
        const previous = app.expanded;
        if (previous === c) {
            if (options) {
                applyGo(c, options);
            }
            return;
        }
        if (options && options.inherit) {
            pushHistory(c);
            c.local = Object.assign({}, options.inherit);
            c.segment = null;
            c.point = null;
            c.drill = null;
        }
        if (options && options.tab) {
            c.tab = options.tab;
        }
        if (options && options.sort) {
            c.sort = Object.assign({}, options.sort);
        }
        if (options && options.metric) {
            c.metric = options.metric;
        }
        if (c.tab === 'drill' && !c.drill) {
            c.tab = c.def.tabs[0];
        }
        app.expanded = c;
        morph(() => {
            if (previous) {
                setCollapsedDom(previous);
            }
            setExpandedDom(c);
            showPane(c, c.tab, false, true);
        }, previous ? [previous, c] : [c]);
        c.expandBtn.focus({ preventScroll: true });
        announce('Analiză deschisă: ' + c.def.title);
        load(c);
        app.morphDone.then(() => {
            if (app.expanded !== c) {
                return;
            }
            // Antetul cardului trebuie sa ramana vizibil sub bara fixa de sus (topbar-ul cu decoratiuni).
            const rect = c.el.getBoundingClientRect();
            const topOffset = fixedTopOffset() + 12;
            if (rect.top < topOffset || rect.top > window.innerHeight * 0.55) {
                window.scrollBy({ top: rect.top - topOffset, behavior: motionOk() ? 'smooth' : 'auto' });
            }
        });
    }

    /** Inaltimea barei fixe/sticky de sus (daca exista), citita o data per apel. */
    function fixedTopOffset() {
        let node = document.elementFromPoint(window.innerWidth / 2, 2);
        while (node && node !== document.body) {
            const position = getComputedStyle(node).position;
            if (position === 'fixed' || position === 'sticky') {
                return Math.max(0, node.getBoundingClientRect().bottom);
            }
            node = node.parentElement;
        }
        return 0;
    }

    function collapse(c) {
        if (app.expanded !== c) {
            return;
        }
        closePop();
        app.expanded = null;
        morph(() => {
            setCollapsedDom(c);
            root.classList.remove('has-expanded');
        }, [c]);
        c.expandBtn.focus({ preventScroll: true });
        announce('Analiză închisă: ' + c.def.title);
    }

    // ------------------------------------------------------------------ scaffold (lenes, o data)
    function build(c) {
        if (c.built) {
            return;
        }
        c.built = true;
        const def = c.def;
        const tabs = def.tabs.concat(['drill']).map((id) => {
            const meta = TAB_META[id];
            return '<button type="button" class="fkm-tab" role="tab" id="fkm-tab-' + c.key + '-' + id + '" data-fkm-tab="' + id + '"'
                + ' aria-controls="fkm-pane-' + c.key + '-' + id + '" aria-selected="false" tabindex="-1"' + (id === 'drill' ? ' hidden' : '') + '>'
                + '<i class="bi ' + meta.icon + '" aria-hidden="true"></i>' + meta.label + '</button>';
        }).join('');
        const panes = def.tabs.concat(['drill']).map((id) => '<div class="fkm-pane" role="tabpanel" id="fkm-pane-' + c.key + '-' + id + '" aria-labelledby="fkm-tab-' + c.key + '-' + id + '" data-fkm-pane="' + id + '" hidden></div>').join('');
        c.panel.innerHTML = ''
            + '<div class="fkm-toolbar"><div class="fkm-tabs" role="tablist" aria-label="Secțiuni ' + esc(def.title) + '">' + tabs + '</div>'
            + '  <div class="fkm-toolbar-actions">'
            + '    <button type="button" class="fkm-link" data-fkm-back disabled><i class="bi bi-arrow-left" aria-hidden="true"></i>Înapoi</button>'
            + '    <button type="button" class="fkm-link" data-fkm-reset disabled title="Revine la filtrele paginii"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Resetare filtre card</button>'
            + '  </div></div>'
            + '<div class="fkm-filterbar">'
            + '  <div class="fkm-seg" role="group" aria-label="Grupare">'
            + '    <button type="button" data-fkm-group="day">Zilnic</button><button type="button" data-fkm-group="week">Săptămânal</button><button type="button" data-fkm-group="month">Lunar</button>'
            + '  </div>'
            + '  <button type="button" class="fkm-chip" data-fkm-pop="period" aria-haspopup="dialog"><i class="bi bi-calendar3" aria-hidden="true"></i><span data-fkm-chip-text></span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>'
            + '  <button type="button" class="fkm-chip" data-fkm-pop="vehicles" aria-haspopup="dialog"><i class="bi bi-truck" aria-hidden="true"></i><span data-fkm-chip-text></span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>'
            + '  <button type="button" class="fkm-chip" data-fkm-pop="transport" aria-haspopup="dialog"><i class="bi bi-signpost-split" aria-hidden="true"></i><span data-fkm-chip-text></span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>'
            + '</div>'
            + '<div class="fkm-notice" data-fkm-notice hidden></div>'
            + '<div class="fkm-stage" data-fkm-stage>' + panes + '</div>'
            + '<div class="fkm-footer">'
            + '  <div class="fkm-metrics" data-fkm-metrics></div>'
            + '  <div class="fkm-actions">'
            + '    <button type="button" class="fkm-btn" data-fkm-details><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>Vezi detalii</span></button>'
            + '    <button type="button" class="fkm-btn is-primary" data-fkm-primary><i class="bi ' + def.primary.icon + '" aria-hidden="true"></i>' + esc(def.primary.label) + '</button>'
            + '  </div>'
            + '</div>';
        c.stage = c.panel.querySelector('[data-fkm-stage]');
        c.panel.querySelectorAll('[data-fkm-pane]').forEach((pane) => { c.panes[pane.getAttribute('data-fkm-pane')] = pane; });
        c.metricsEl = c.panel.querySelector('[data-fkm-metrics]');
        c.noticeEl = c.panel.querySelector('[data-fkm-notice]');
        renderFooter(c, null);
        bindPanel(c);
    }

    // ------------------------------------------------------------------ incarcare date
    const mainKey = (c) => keyOf(c.local, 'main', mainExtra(c));

    function load(c) {
        const token = ++c.token;
        const main = readVariant(c, 'main');
        if (main) {
            if (c.renderedKey !== mainKey(c)) {
                renderAll(c);
            }
            return Promise.resolve();
        }
        setBusy(c, true);
        return fetchPart(c.local, 'main', mainExtra(c)).then((json) => {
            if (token !== c.token) {
                return; // raspuns depasit de o interactiune mai noua
            }
            c.notice = json.notice || '';
            setBusy(c, false);
            renderAll(c);
        }).catch((error) => {
            if (token !== c.token) {
                return;
            }
            setBusy(c, false);
            renderError(c, error.message);
        });
    }

    function setBusy(c, busy) {
        Object.keys(c.panes).forEach((id) => c.panes[id].classList.toggle('is-busy', busy && c.panes[id].childElementCount > 0));
        c.panel.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            const pane = c.panes[c.tab];
            if (pane && pane.childElementCount === 0) {
                pane.innerHTML = skeleton();
            }
        }
    }

    const skeleton = () => '<div class="fkm-evo"><div class="fkm-box"><div class="fkm-skeleton" style="height:16px;width:40%;margin-bottom:12px"></div><div class="fkm-skeleton" style="height:170px"></div></div>'
        + '<div class="fkm-box"><div class="fkm-skeleton" style="height:16px;width:60%;margin-bottom:14px"></div>'
        + '<div class="fkm-skeleton" style="height:22px;margin-bottom:10px"></div><div class="fkm-skeleton" style="height:22px;margin-bottom:10px"></div><div class="fkm-skeleton" style="height:22px"></div></div></div>';

    function renderError(c, message) {
        const pane = c.panes[c.tab];
        pane.innerHTML = '<div class="fkm-state is-error"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>' + esc(message || 'Datele nu au putut fi încărcate.') + '</div>'
            + '<button type="button" class="fkm-btn" data-fkm-retry><i class="bi bi-arrow-clockwise" aria-hidden="true"></i>Reîncearcă</button></div>';
    }

    function renderAll(c, skipPane) {
        const data = readVariant(c, 'main');
        if (!data) {
            return;
        }
        c.renderedKey = mainKey(c);
        renderChrome(c);
        renderHeader(c, data);
        renderFooter(c, data);
        Object.keys(c.panes).forEach((id) => { c.dirty[id] = true; });
        if (!skipPane) {
            renderPane(c, c.tab);
        }
    }

    // ------------------------------------------------------------------ antet, bara de filtre, subsol
    function changeHtml(value) {
        if (value === null || value === undefined) {
            return '<span class="fkm-change is-muted" data-fkm-shared="change" title="Fără date în perioada precedentă"><i class="bi bi-dash-lg" aria-hidden="true"></i> fără comparație</span>';
        }
        const good = value <= 0;
        return '<span class="fkm-change ' + (good ? 'is-good' : 'is-bad') + '" data-fkm-shared="change" title="Față de perioada precedentă de aceeași lungime">'
            + '<i class="bi ' + (good ? 'bi-arrow-down' : 'bi-arrow-up') + '" aria-hidden="true"></i> ' + fmt(Math.abs(value), 1) + '%</span>';
    }

    function renderHeader(c, data) {
        const h = c.def.header(data);
        c.valueEl.textContent = h.value;
        c.subEl.textContent = h.sub;
        c.subEl.title = h.sub;
        c.changeWrap.innerHTML = changeHtml(h.change) + '<span class="fkm-change-note">față de ' + esc(rangeRo(data.previous_range.from, data.previous_range.to)) + '</span>';
        const local = isLocal(c);
        c.contextEl.hidden = !local;
        c.contextEl.textContent = local ? 'Filtre card' : '';
    }

    function renderChrome(c) {
        if (!c.built) {
            return;
        }
        const p = c.panel;
        p.querySelectorAll('[data-fkm-group]').forEach((b) => b.setAttribute('aria-pressed', b.getAttribute('data-fkm-group') === c.local.grouping ? 'true' : 'false'));
        const chip = (name, text, local) => {
            const el = p.querySelector('[data-fkm-pop="' + name + '"]');
            el.querySelector('[data-fkm-chip-text]').textContent = text;
            el.classList.toggle('is-local', local);
        };
        chip('period', periodLabel(c.local.date_from, c.local.date_to), c.local.date_from !== GLOBAL.date_from || c.local.date_to !== GLOBAL.date_to);
        const vehicles = c.local.vehicles;
        const vehicleText = c.segment ? c.segment.label + ' (' + vehicles.length + ')' : (vehicles.length === 0 ? 'Toate vehiculele' : vehicles.length === 1 ? vehicles[0] : vehicles.length + ' vehicule');
        chip('vehicles', vehicleText, JSON.stringify(vehicles.slice().sort()) !== JSON.stringify((GLOBAL.vehicles || []).slice().sort()));
        chip('transport', 'Transport: ' + (c.local.transport_group ? TRANSPORT_LABELS[c.local.transport_group] || c.local.transport_group : 'Toate'), (c.local.transport_group || '') !== (GLOBAL.transport_group || ''));
        p.querySelector('[data-fkm-back]').disabled = c.history.length === 0;
        p.querySelector('[data-fkm-reset]').disabled = !isLocal(c) && !c.segment;
        const details = p.querySelector('[data-fkm-details]');
        details.querySelector('span').textContent = isLocal(c) ? 'Vezi detalii (filtre card)' : 'Vezi detalii';
        details.title = isLocal(c)
            ? 'Deschide tabul paginii cu filtrele acestui card (reîncarcă pagina cu aceste filtre)'
            : 'Deschide tabul corespunzător din pagină';
        const notices = [];
        if (c.notice) {
            notices.push(c.notice);
        }
        const data = readVariant(c, 'main');
        if (data && c.local.grouping === 'month' && data.series.length < 2) {
            notices.push('Perioada are o singură lună: alege „Ultimele 6 luni” din perioadă pentru o tendință lunară.');
        }
        c.noticeEl.hidden = notices.length === 0;
        c.noticeEl.textContent = notices.join(' ');
    }

    function renderFooter(c, data) {
        c.metricsEl.innerHTML = c.def.footer.map((m, i) => {
            const value = data ? m.value(data) : '…';
            const hint = data && m.hint ? m.hint(data) : m.label;
            return '<button type="button" class="fkm-metric" data-fkm-metric="' + i + '"' + (data ? '' : ' disabled') + ' title="' + esc(m.label + (data && m.hint ? ' · ' + m.hint(data) : '')) + '">'
                + '<i class="bi ' + m.icon + '" aria-hidden="true"></i><span><strong>' + esc(value) + '</strong><small>' + esc(hint) + '</small></span></button>';
        }).join('');
    }

    // ------------------------------------------------------------------ STATE D: panouri interne
    function showPane(c, id, push, instant) {
        if (!c.panes[id]) {
            return;
        }
        if (push && id !== c.tab) {
            pushHistory(c);
        }
        const oldPane = c.panes[c.tab];
        const newPane = c.panes[id];
        const order = c.def.tabs.concat(['drill']);
        const direction = order.indexOf(id) >= order.indexOf(c.tab) ? 1 : -1;
        const h0 = c.stage.offsetHeight;
        c.tab = id;
        Object.keys(c.panes).forEach((key) => { c.panes[key].hidden = key !== id; });
        c.panel.querySelectorAll('[data-fkm-tab]').forEach((tab) => {
            const active = tab.getAttribute('data-fkm-tab') === id;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
            if (tab.getAttribute('data-fkm-tab') === 'drill') {
                tab.hidden = !c.drill;
            }
        });
        renderPane(c, id);
        renderChrome(c);
        if (instant || !motionOk() || oldPane === newPane) {
            return;
        }
        const h1 = c.stage.offsetHeight;
        if (Math.abs(h1 - h0) > 2) {
            c.stage.style.overflow = 'hidden';
            const a = c.stage.animate([{ height: h0 + 'px' }, { height: h1 + 'px' }], { duration: 220, easing: EASING_CSS });
            a.finished.then(() => { c.stage.style.overflow = ''; }, () => { c.stage.style.overflow = ''; });
        }
        newPane.animate([
            { opacity: 0, transform: 'translateX(' + (14 * direction) + 'px)' },
            { opacity: 1, transform: 'none' },
        ], { duration: 220, easing: EASING_CSS });
    }

    function renderPane(c, id) {
        const data = readVariant(c, 'main');
        if (!c.panes[id]) {
            return;
        }
        if (!data) {
            // Schelet cu inaltimea finala, inainte de masurarea morph-ului (fara salt la sosirea datelor).
            if (c.panes[id].childElementCount === 0) {
                c.panes[id].innerHTML = skeleton();
            }
            return;
        }
        if (!c.dirty[id] && c.panes[id].childElementCount > 0) {
            if (id === 'evo') {
                afterMorph(() => renderChart(c, data));
            }
            return;
        }
        c.dirty[id] = false;
        const renderers = { evo: renderEvo, breakdown: renderBreakdown, vehicles: renderVehicles, transport: renderTransport, ratio: renderRatio, can: renderCan, fuel: renderFuel, drill: renderDrill };
        renderers[id](c, data, c.panes[id]);
    }

    /**
     * Ruleaza dupa animatia de morph (graficele nu se creeaza in timpul ei). Microtask-ul initial
     * lasa morph()-ul apelant sa-si inregistreze promisiunea inainte de a o citi.
     */
    const afterMorph = (fn) => Promise.resolve()
        .then(() => app.morphDone)
        .then(() => window.requestAnimationFrame(fn));

    /** Datele unei variante (vehicule / transport neselectate); o cere daca lipseste. */
    function withVariant(c, variant, pane, render) {
        const data = readVariant(c, variant);
        if (data) {
            return render(data);
        }
        const token = c.token;
        pane.classList.add('is-busy');
        fetchPart(variantFilters(c, variant), 'main', mainExtra(c)).then(() => {
            pane.classList.remove('is-busy');
            if (token === c.token) {
                c.dirty[pane.getAttribute('data-fkm-pane')] = true;
                if (c.tab === pane.getAttribute('data-fkm-pane')) {
                    renderPane(c, c.tab);
                }
            }
        }).catch((error) => {
            pane.classList.remove('is-busy');
            pane.querySelectorAll('.fkm-skeleton').forEach((node) => {
                node.outerHTML = '<div class="fkm-state is-error"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>' + esc(error.message) + '</div></div>';
            });
        });
        return null;
    }

    // ---- Evolutie
    function renderEvo(c, data, pane) {
        const def = c.def;
        // Scheletul (canvas inclus) se construieste o singura data; apoi doar se actualizeaza.
        if (!pane.querySelector('[data-fkm-canvas]')) {
            const metricButtons = def.metrics.length > 1
                ? '<div class="fkm-seg" role="group" aria-label="Indicator grafic">' + def.metrics.map((m) => '<button type="button" data-fkm-metric-id="' + m.id + '">' + esc(m.label) + '</button>').join('') + '</div>'
                : '';
            pane.innerHTML = '<div class="fkm-evo">'
                + '<div class="fkm-box"><div class="fkm-box-head"><h4 class="fkm-box-title" data-fkm-chart-title></h4>' + metricButtons + '</div>'
                + '<div class="fkm-chart-wrap"><canvas tabindex="0" role="img" data-fkm-canvas aria-label="Grafic ' + esc(def.title) + '. Folosește săgețile stânga/dreapta pentru a selecta un punct."></canvas></div>'
                + '<div class="fkm-legend" data-fkm-legend></div>'
                + '<div class="fkm-point" data-fkm-point hidden></div></div>'
                + '<div class="fkm-box" data-fkm-side></div>'
                + '</div>';
            if (c.chart) {
                c.chart.destroy(); // doar daca panoul a fost inlocuit (ex. dupa o eroare)
                c.chart = null;
            }
            c.canvas = pane.querySelector('[data-fkm-canvas]');
        }
        pane.querySelectorAll('[data-fkm-metric-id]').forEach((b) => b.setAttribute('aria-pressed', b.getAttribute('data-fkm-metric-id') === c.metric ? 'true' : 'false'));
        renderSide(c, pane.querySelector('[data-fkm-side]'));
        afterMorph(() => renderChart(c, readVariant(c, 'main') || data));
    }

    function currentMetric(c) {
        return c.def.metrics.find((m) => m.id === c.metric) || c.def.metrics[0];
    }

    function renderChart(c, data) {
        if (!c.canvas || !c.canvas.isConnected || !window.Chart || c.tab !== 'evo') {
            return;
        }
        const metric = currentMetric(c);
        const series = data.series || [];
        const prev = data.previous_series || [];
        const rgb = accentRgb(c);
        const values = series.map((b) => {
            const v = metric.get(b);
            return v === null || v === undefined ? null : Number(v);
        });
        const hasData = values.some((v) => v !== null && (metric.zero ? v !== 0 : true));
        const titleEl = c.panes.evo.querySelector('[data-fkm-chart-title]');
        titleEl.textContent = metric.label + ' · ' + ({ day: 'zilnic', week: 'săptămânal', month: 'lunar' }[c.local.grouping]);
        const pointRadius = values.map((_, i) => (i === c.point ? 5 : (values.length <= 35 ? 2.2 : 0)));
        const datasets = [{
            label: metric.label,
            data: values,
            borderColor: 'rgb(' + rgb + ')',
            backgroundColor: (ctx) => {
                const area = ctx.chart.chartArea;
                if (!area) {
                    return 'rgba(' + rgb + ',0.12)';
                }
                const g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                g.addColorStop(0, 'rgba(' + rgb + ',0.24)');
                g.addColorStop(1, 'rgba(' + rgb + ',0)');
                return g;
            },
            fill: true,
            tension: 0.32,
            spanGaps: true,
            borderWidth: 2.2,
            pointRadius,
            pointHoverRadius: 5,
            pointBackgroundColor: values.map((_, i) => (i === c.point ? 'rgb(' + rgb + ')' : '#ffffff')),
            pointBorderColor: 'rgb(' + rgb + ')',
            pointBorderWidth: 2,
            yAxisID: 'y',
        }];
        const meta = [{ fmt: metric.fmt }];
        const legend = ['<span><i></i>' + esc(metric.label) + '</span>'];
        if (prev.length) {
            datasets.push({
                label: 'Perioada precedentă',
                data: series.map((_, i) => (prev[i] ? metric.get(prev[i]) : null)),
                borderColor: '#94a3b8',
                borderDash: [5, 4],
                borderWidth: 1.5,
                pointRadius: 0,
                pointHoverRadius: 3,
                fill: false,
                tension: 0.32,
                spanGaps: true,
                yAxisID: 'y',
            });
            meta.push({ fmt: metric.fmt, prevSeries: prev });
            legend.push('<span><i class="is-prev"></i>Perioada precedentă (' + esc(rangeRo(data.previous_range.from, data.previous_range.to)) + ')</span>');
        }
        if (metric.average) {
            const avg = metric.average(data);
            if (avg > 0) {
                datasets.push({ label: 'Media perioadei', data: series.map(() => avg), borderColor: 'rgba(' + rgb + ',0.55)', borderDash: [2, 3], borderWidth: 1.2, pointRadius: 0, pointHoverRadius: 0, fill: false, yAxisID: 'y' });
                meta.push({ fmt: metric.fmt });
                legend.push('<span><i style="border-top-style:dotted"></i>Media perioadei: ' + esc(metric.fmt(avg)) + '</span>');
            }
        }
        if (metric.dual) {
            datasets.push({ label: metric.dual.label, data: series.map(metric.dual.get), borderColor: '#64748b', borderWidth: 1.6, pointRadius: 0, pointHoverRadius: 3, fill: false, tension: 0.32, yAxisID: 'y1' });
            meta.push({ fmt: metric.dual.fmt });
            legend.push('<span><i class="is-alt"></i>' + esc(metric.dual.label) + '</span>');
        }
        c.panes.evo.querySelector('[data-fkm-legend]').innerHTML = hasData ? legend.join('') : '';

        const options = chartOptions(c, series);
        options.scales.y1.display = !!metric.dual;
        options.scales.y.beginAtZero = !!metric.zero;
        if (!c.chart) {
            c.chart = new window.Chart(c.canvas.getContext('2d'), { type: 'line', data: { labels: [], datasets: [] }, options });
        } else {
            c.chart.options = options;
        }
        c.chartMeta = meta;
        c.chart.data.labels = series.map((b) => b.label);
        c.chart.data.datasets = datasets;
        c.chart.update(motionOk() ? undefined : 'none');
        renderPointDetail(c, data);
        const wrap = c.canvas.parentElement;
        let empty = wrap.querySelector('.fkm-state');
        if (!hasData) {
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'fkm-state';
                empty.style.cssText = 'position:absolute;inset:0;background:rgba(255,255,255,.86);';
                wrap.appendChild(empty);
            }
            empty.innerHTML = '<i class="bi bi-inbox" aria-hidden="true"></i><div>' + (metric.id === 'l100'
                ? 'Nu există intervale valide de consum (odometru sau km curse) în perioada aleasă.'
                : metric.id === 'pct' ? 'Raportul nu se poate calcula: nu există motorină alimentată în perioada aleasă.'
                    : 'Nu există alimentări în perioada aleasă.') + '</div>';
        } else if (empty) {
            empty.remove();
        }
    }

    function chartOptions(c, series) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: motionOk() ? { duration: 420, easing: 'easeOutCubic' } : false,
            interaction: { mode: 'index', intersect: false },
            layout: { padding: { top: 6, right: 4 } },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 9,
                    cornerRadius: 8,
                    titleFont: { weight: '700' },
                    callbacks: {
                        title: (items) => {
                            const b = series[items[0].dataIndex];
                            return b ? (b.label + (b.from !== b.to || c.local.grouping !== 'day' ? ' · ' + rangeRo(b.from, b.to) : ' ' + b.from.slice(0, 4))) : '';
                        },
                        label: (item) => {
                            const ds = item.dataset;
                            const m = (c.chartMeta || [])[item.datasetIndex] || { fmt: (v) => fmt(v, 2) };
                            if (item.raw === null || item.raw === undefined) {
                                return ds.label + ': –';
                            }
                            let label = ds.label;
                            if (m.prevSeries && m.prevSeries[item.dataIndex]) {
                                const pb = m.prevSeries[item.dataIndex];
                                label += ' (' + rangeRo(pb.from, pb.to) + ')';
                            }
                            return label + ': ' + m.fmt(item.raw);
                        },
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: '#64748b', font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
                y: { grid: { color: '#eef2f7' }, border: { display: false }, ticks: { color: '#64748b', font: { size: 10 }, maxTicksLimit: 5, callback: (v) => F.short(v) } },
                y1: { display: false, position: 'right', grid: { display: false }, border: { display: false }, ticks: { color: '#94a3b8', font: { size: 10 }, maxTicksLimit: 5, callback: (v) => F.short(v) } },
            },
            onClick: (event, elements, chart) => {
                const points = chart.getElementsAtEventForMode(event, 'index', { intersect: false }, false);
                if (points.length) {
                    selectPoint(c, points[0].index);
                }
            },
            onHover: (event, elements) => {
                event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
            },
        };
    }

    function selectPoint(c, index) {
        const data = readVariant(c, 'main');
        if (!data || !data.series[index]) {
            return;
        }
        c.point = c.point === index ? null : index;
        if (c.chart) {
            const rgb = accentRgb(c);
            const ds = c.chart.data.datasets[0];
            ds.pointRadius = ds.data.map((_, i) => (i === c.point ? 5 : (ds.data.length <= 35 ? 2.2 : 0)));
            ds.pointBackgroundColor = ds.data.map((_, i) => (i === c.point ? 'rgb(' + rgb + ')' : '#ffffff'));
            c.chart.update('none');
        }
        renderPointDetail(c, data);
    }

    function renderPointDetail(c, data) {
        const box = c.panes.evo && c.panes.evo.querySelector('[data-fkm-point]');
        if (!box) {
            return;
        }
        const b = c.point !== null ? data.series[c.point] : null;
        if (!b) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }
        const metric = currentMetric(c);
        const prev = data.previous_series[c.point];
        const parts = [
            '<strong>' + esc(b.label) + ' · ' + esc(rangeRo(b.from, b.to)) + '</strong>',
            esc(metric.label) + ': <strong>' + esc(metric.fmt(metric.get(b))) + '</strong>',
        ];
        if (prev) {
            parts.push('precedent: ' + esc(metric.fmt(metric.get(prev))));
        }
        if (c.key === 'consum') {
            parts.push(esc(F.l(b.l100_liters)) + ' / ' + esc(F.km(b.l100_km)));
        } else {
            parts.push(esc(F.l(b.motorina_liters)) + ' motorină', esc(F.lei(b.value)));
        }
        parts.push(esc(F.int(b.fillups)) + ' alimentări');
        box.innerHTML = '<button type="button" class="fkm-link" data-fkm-step="-1" aria-label="Punctul anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>'
            + parts.join('<span aria-hidden="true">·</span>')
            + '<button type="button" class="fkm-link" data-fkm-step="1" aria-label="Punctul următor"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>'
            + (b.fillups > 0 ? '<button type="button" class="fkm-link" data-fkm-drill-point><i class="bi bi-list-ul" aria-hidden="true"></i>Alimentările din interval</button>' : '');
        box.hidden = false;
    }

    // ---- bare orizontale (comune)
    function barsHtml(rows, opts) {
        if (!rows.length) {
            return '<div class="fkm-state"><i class="bi bi-inbox" aria-hidden="true"></i><div>' + esc(opts.empty || 'Nu există date pentru selecția curentă.') + '</div></div>';
        }
        const values = rows.map((r) => opts.value(r)).map((v) => (v === null || v === undefined ? null : Number(v)));
        const total = values.reduce((a, v) => a + (v || 0), 0);
        const max = Math.max.apply(null, values.map((v) => v || 0)) || 1;
        const selected = rows.some((r) => opts.isSelected && opts.isSelected(r));
        return '<div class="fkm-bars' + (selected ? ' has-selection' : '') + '">' + rows.map((r, i) => {
            const v = values[i];
            const share = opts.relative ? null : (total > 0 && v !== null ? (v / total) * 100 : null);
            const width = v === null ? 0 : (opts.relative ? (v / max) * 100 : (share || 0));
            const isSel = opts.isSelected && opts.isSelected(r);
            const clickable = !opts.isClickable || opts.isClickable(r);
            return '<button type="button" class="fkm-bar' + (isSel ? ' is-selected' : '') + '" data-fkm-bar="' + i + '"' + (clickable ? '' : ' disabled') + ' aria-pressed="' + (isSel ? 'true' : 'false') + '">'
                + '<i class="bi ' + (opts.icon ? opts.icon(r) : 'bi-circle-fill') + '" aria-hidden="true"></i>'
                + '<span class="fkm-bar-label">' + esc(opts.label(r)) + '</span>'
                + '<span class="fkm-bar-value">' + (v === null ? '<span class="fkm-na">indisponibil</span>' : esc(opts.fmt(v))) + '</span>'
                + '<span class="fkm-bar-pct">' + (share !== null ? esc(fmt(share, 1)) + '%' : '') + '</span>'
                + '<span class="fkm-bar-track"><span style="width:' + Math.max(0, Math.min(100, width)).toFixed(1) + '%"></span></span>'
                + (opts.meta ? '<span class="fkm-bar-meta">' + esc(opts.meta(r)) + '</span>' : '')
                + '</button>';
        }).join('') + '</div>';
    }
    const CATEGORY_ICON = { camion: 'bi-truck', cap_tractor: 'bi-truck-front', autoturism: 'bi-car-front', autoutilitara: 'bi-truck-flatbed', neinregistrate: 'bi-question-circle', semiremorca: 'bi-box', altele: 'bi-circle' };

    function renderSide(c, box) {
        const side = c.def.side;
        box.innerHTML = '<div class="fkm-box-head"><h4 class="fkm-box-title">' + esc(side.title) + '</h4></div><div data-fkm-side-body></div>';
        const body = box.querySelector('[data-fkm-side-body]');
        if (side.source === 'fuel') {
            const data = readVariant(c, 'main');
            const rows = (data.fuel_types || []).filter((r) => r.value > 0 || r.liters > 0);
            body.innerHTML = barsHtml(rows, { value: side.value, fmt: side.fmt, label: (r) => r.label, meta: side.meta, icon: () => 'bi-droplet-half', isClickable: () => true });
            body.querySelectorAll('[data-fkm-bar]').forEach((btn) => btn.addEventListener('click', () => showPane(c, 'fuel', true)));
            return;
        }
        const variant = side.source === 'categories' || side.source === 'vehicles' ? 'vehOpts' : 'main';
        const isSelected = (r) => (side.source === 'categories' ? !!(c.segment && c.segment.key === r.key) : c.local.vehicles.indexOf(r.vehicle_registration) !== -1);
        // Randurile fara valoare (ex. autoturisme pe benzina in defalcarea motorinei) nu aglomereaza panoul.
        const sideRows = (data) => {
            const rows = (side.source === 'categories' ? data.categories : data.vehicles).filter((r) => side.value(r) > 0 || isSelected(r));
            return side.source === 'vehicles' ? rows.sort((a, b) => side.value(b) - side.value(a)).slice(0, side.limit || 6) : rows;
        };
        const html = withVariant(c, variant, c.panes.evo, (data) => {
            const rows = sideRows(data);
            return barsHtml(rows, {
                value: side.value,
                fmt: side.fmt,
                relative: side.relative,
                meta: side.meta,
                label: (r) => r.label || r.vehicle_registration,
                icon: (r) => (side.source === 'categories' ? CATEGORY_ICON[r.key] || 'bi-circle' : 'bi-truck'),
                isSelected,
                empty: 'Nu există alimentări pentru această selecție.',
            });
        });
        body.innerHTML = html === null ? '<div class="fkm-skeleton" style="height:120px"></div>' : html;
        const data = readVariant(c, variant);
        if (!data) {
            return;
        }
        const rows = sideRows(data);
        body.querySelectorAll('[data-fkm-bar]').forEach((btn) => btn.addEventListener('click', () => {
            const row = rows[Number(btn.getAttribute('data-fkm-bar'))];
            if (side.source === 'categories') {
                toggleCategory(c, row);
            } else {
                focusVehicle(c, row.vehicle_registration);
            }
        }));
    }

    // ---- Defalcare (categorii)
    function renderBreakdown(c, data, pane) {
        const cfg = c.def.breakdown;
        const html = withVariant(c, 'vehOpts', pane, (opts) => '<p class="fkm-pane-note">Pe categorie de vehicul · clic pentru a analiza doar categoria în acest card</p>'
            + barsHtml(opts.categories, {
                value: cfg.value, fmt: cfg.fmt, relative: cfg.relative, meta: cfg.meta,
                label: (r) => r.label,
                icon: (r) => CATEGORY_ICON[r.key] || 'bi-circle',
                isSelected: (r) => !!(c.segment && c.segment.key === r.key),
            }));
        if (html === null) {
            pane.innerHTML = skeleton();
            return;
        }
        pane.innerHTML = html;
        const opts = readVariant(c, 'vehOpts');
        pane.querySelectorAll('[data-fkm-bar]').forEach((btn) => btn.addEventListener('click', () => toggleCategory(c, opts.categories[Number(btn.getAttribute('data-fkm-bar'))])));
    }

    // ---- Tip transport
    function renderTransport(c, data, pane) {
        const cfg = c.def.transport;
        const variant = (c.local.transport_group || '') !== (GLOBAL.transport_group || '') ? 'trOpts' : 'main';
        const html = withVariant(c, variant, pane, (src) => {
            const linked = sum(src.transport, (r) => r.motorina);
            const total = src.summary.motorina_liters;
            const coverage = total > 0 ? (linked / total) * 100 : null;
            return '<p class="fkm-pane-note">Doar alimentările asociate unei curse'
                + (coverage !== null ? ': ' + esc(F.l(linked)) + ' din ' + esc(F.l(total)) + ' motorină (' + esc(fmt(coverage, 1)) + '%)' : '') + '</p>'
                + barsHtml(src.transport, {
                    value: cfg.value, fmt: cfg.fmt, relative: cfg.relative, meta: cfg.meta,
                    label: (r) => TRANSPORT_LABELS[r.key] || r.label,
                    icon: () => 'bi-signpost-split',
                    isSelected: (r) => c.local.transport_group === r.key,
                    isClickable: (r) => r.key !== 'neasociat',
                    empty: 'Nicio alimentare nu e asociată unei curse în această selecție.',
                });
        });
        if (html === null) {
            pane.innerHTML = skeleton();
            return;
        }
        pane.innerHTML = html;
        const src = readVariant(c, variant);
        pane.querySelectorAll('[data-fkm-bar]').forEach((btn) => btn.addEventListener('click', () => {
            const row = src.transport[Number(btn.getAttribute('data-fkm-bar'))];
            applyLocal(c, { transport_group: c.local.transport_group === row.key ? (GLOBAL.transport_group || '') : row.key });
        }));
    }

    // ---- Tip carburant (cost)
    function renderFuel(c, data, pane) {
        const rows = (data.fuel_types || []).filter((r) => r.liters > 0 || r.value > 0);
        const prev = data.previous_fuel_types || [];
        if (!rows.length) {
            pane.innerHTML = '<div class="fkm-state"><i class="bi bi-inbox" aria-hidden="true"></i><div>Nu există alimentări în perioada aleasă.</div></div>';
            return;
        }
        pane.innerHTML = '<div class="fkm-table-wrap"><table class="fkm-table"><thead><tr><th></th><th>Carburant</th><th>Cost</th><th>Pondere</th><th>Litri</th><th>Lei/L</th><th>Alim.</th><th>Cost precedent</th></tr></thead><tbody>'
            + rows.map((r) => {
                const p = prev.find((x) => x.key === r.key);
                const share = data.summary.total_value > 0 ? (r.value / data.summary.total_value) * 100 : null;
                return '<tr><td><i class="bi bi-droplet-half" style="color:var(--fkm-accent)" aria-hidden="true"></i></td><td><strong>' + esc(r.label) + '</strong></td><td>' + esc(F.lei(r.value)) + '</td><td>' + (share !== null ? esc(fmt(share, 1)) + '%' : '–') + '</td><td>' + esc(F.l(r.liters)) + '</td><td>' + esc(r.price ? fmt(r.price, 3) : '–') + '</td><td>' + esc(F.int(r.fillups)) + '</td><td>' + esc(p ? F.lei(p.value) : '–') + '</td></tr>';
            }).join('')
            + '</tbody></table></div><p class="fkm-legend" style="margin:.45rem 0 0">Lei/L = valoare ÷ litri, inclusiv bonurile manuale (prețul CardOil pur e în graficul de preț din pagină).</p>';
    }

    // ---- Vehicule
    function renderVehicles(c, data, pane) {
        const html = withVariant(c, 'vehOpts', pane, (opts) => vehicleTableHtml(c, opts));
        if (html === null) {
            pane.innerHTML = skeleton();
            return;
        }
        const searchFocused = document.activeElement && document.activeElement.matches && document.activeElement.matches('[data-fkm-search]') && pane.contains(document.activeElement);
        pane.innerHTML = html;
        const search = pane.querySelector('[data-fkm-search]');
        search.value = c.search;
        if (searchFocused) {
            search.focus();
            search.setSelectionRange(c.search.length, c.search.length);
        }
    }

    function vehicleRows(c, opts) {
        const cols = c.def.columns;
        const col = cols.find((x) => x.id === c.sort.field);
        const getter = col ? col.get : (r) => r[c.sort.field];
        const q = c.search.trim().toUpperCase().replace(/\s+/g, '');
        const rows = opts.vehicles.filter((r) => !q || r.vehicle_key.indexOf(q) !== -1 || String(r.category_label).toUpperCase().indexOf(q) !== -1);
        rows.sort((a, b) => {
            const va = getter(a);
            const vb = getter(b);
            if (va === null || va === undefined) {
                return 1;
            }
            if (vb === null || vb === undefined) {
                return -1;
            }
            return (va - vb) * c.sort.dir;
        });
        return rows;
    }

    function vehicleTableHtml(c, opts) {
        const cols = c.def.columns;
        const rows = vehicleRows(c, opts);
        const selectedCount = c.local.vehicles.length;
        const head = '<tr><th><span class="fkm-sr">Selectează</span></th><th>Vehicul</th>' + cols.map((col) => {
            const sorted = c.sort.field === col.id ? (c.sort.dir < 0 ? 'descending' : 'ascending') : 'none';
            return '<th><button type="button" data-fkm-sort="' + col.id + '" aria-sort="' + sorted + '">' + esc(col.label) + '</button></th>';
        }).join('') + '</tr>';
        const body = rows.length ? rows.map((r) => {
            const sel = c.local.vehicles.indexOf(r.vehicle_registration) !== -1;
            const flagged = cols.some((col) => col.flag) && adblueFlag(r, opts.adblue_deviation);
            return '<tr class="' + (sel ? 'is-selected' : '') + '">'
                + '<td><input type="checkbox" class="form-check-input m-0" data-fkm-check="' + esc(r.vehicle_registration) + '"' + (sel ? ' checked' : '') + ' aria-label="Include ' + esc(r.vehicle_registration) + ' în analiză"></td>'
                + '<td><button type="button" class="fkm-plate" data-fkm-focus="' + esc(r.vehicle_registration) + '" title="Analizează doar acest vehicul">' + esc(r.vehicle_registration) + '</button><span class="fkm-tag">' + esc(r.category_label) + '</span></td>'
                + cols.map((col) => {
                    const v = col.get(r);
                    const tag = col.tag ? col.tag(r) : '';
                    const flag = col.flag && flagged ? '<span class="fkm-tag is-warn" title="Abatere ≥ ' + fmt(opts.adblue_deviation * 100, 0) + '% față de perioada precedentă — de verificat, nu indică singură un defect">de verificat</span>' : '';
                    return '<td>' + (v === null || v === undefined ? '<span class="fkm-na">–</span>' : esc(col.fmt(v))) + (tag ? '<span class="fkm-tag">' + esc(tag) + '</span>' : '') + flag + '</td>';
                }).join('')
                + '</tr>';
        }).join('') : '<tr><td colspan="' + (cols.length + 2) + '"><div class="fkm-state"><i class="bi bi-search" aria-hidden="true"></i><div>Niciun vehicul pentru căutarea sau selecția curentă.</div></div></td></tr>';
        return '<div class="fkm-list-tools">'
            + '<input type="search" class="fkm-search" data-fkm-search placeholder="Caută număr sau categorie…" aria-label="Caută vehicul">'
            + '<span class="fkm-legend">' + esc(F.int(opts.vehicles.length)) + ' vehicule · ' + (selectedCount ? esc(F.int(selectedCount)) + ' selectate în card' : 'toate incluse') + '</span>'
            + (selectedCount ? '<button type="button" class="fkm-link" data-fkm-clear-vehicles><i class="bi bi-x-circle" aria-hidden="true"></i>Toate vehiculele</button>' : '')
            + '</div>'
            + '<div class="fkm-table-wrap"><table class="fkm-table"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>';
    }

    // ---- Raport AdBlue pe vehicul
    function renderRatio(c, data, pane) {
        const html = withVariant(c, 'vehOpts', pane, (opts) => {
            const rows = opts.vehicles.filter((r) => r.motorina > 0).sort((a, b) => b.consum_adblue - a.consum_adblue);
            const flagged = rows.filter((r) => adblueFlag(r, opts.adblue_deviation)).length;
            return '<p class="fkm-pane-note">AdBlue ÷ motorină pe vehicul · ' + esc(F.int(flagged)) + ' cu abatere ≥ ' + esc(fmt(opts.adblue_deviation * 100, 0)) + '% față de perioada precedentă</p>'
                + barsHtml(rows, {
                    value: (r) => r.consum_adblue, fmt: (v) => fmt(v, 2) + '%', relative: true,
                    label: (r) => r.vehicle_registration + (adblueFlag(r, opts.adblue_deviation) ? '  ⚠ de verificat' : ''),
                    meta: (r) => F.l(r.adblue) + ' AdBlue / ' + F.l(r.motorina) + ' motorină' + (r.consum_adblue_prev !== null ? ' · precedent ' + fmt(r.consum_adblue_prev, 2) + '%' : ' · fără perioadă precedentă'),
                    icon: (r) => (adblueFlag(r, opts.adblue_deviation) ? 'bi-exclamation-triangle' : 'bi-truck'),
                    isSelected: (r) => c.local.vehicles.indexOf(r.vehicle_registration) !== -1,
                    empty: 'Nu există motorină alimentată în această selecție.',
                });
        });
        if (html === null) {
            pane.innerHTML = skeleton();
            return;
        }
        pane.innerHTML = '<div class="fkm-table-wrap" style="padding:.35rem">' + html + '</div>';
        const opts = readVariant(c, 'vehOpts');
        const rows = opts.vehicles.filter((r) => r.motorina > 0).sort((a, b) => b.consum_adblue - a.consum_adblue);
        pane.querySelectorAll('[data-fkm-bar]').forEach((btn) => btn.addEventListener('click', () => focusVehicle(c, rows[Number(btn.getAttribute('data-fkm-bar'))].vehicle_registration)));
    }

    // ---- GPS / CAN
    function renderCan(c, data, pane) {
        const token = c.token;
        const json = resolved.get(keyOf(c.local, 'can', {}));
        if (!json) {
            pane.innerHTML = skeleton();
            fetchPart(c.local, 'can', {}).then(() => {
                if (token === c.token && c.tab === 'can') {
                    c.dirty.can = true;
                    renderPane(c, 'can');
                }
            }).catch((error) => {
                if (token === c.token && c.tab === 'can') {
                    pane.innerHTML = '<div class="fkm-state is-error"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>' + esc(error.message) + '</div></div>';
                }
            });
            return;
        }
        // Doar vehiculele din analiza cardului (respecta marca, categoria, tipul de transport).
        const byKey = {};
        data.vehicles.forEach((r) => { byKey[r.vehicle_key] = r; });
        const rows = json.can.vehicles.filter((r) => byKey[r.vehicle_key]);
        const withCan = rows.filter((r) => r.can_l100 !== null);
        const canFuel = sum(withCan, (r) => r.can_fuel_l);
        const canKm = sum(withCan, (r) => r.can_gps_km);
        const pendingCount = sum(rows, (r) => r.pending);
        const head = '<tr><th></th><th>Vehicul</th><th>Alimentări (L/100)</th><th>CAN (L/100)</th><th>Diferență</th><th>Intervale cu CAN</th><th>Km GPS (CAN)</th></tr>';
        const body = rows.length ? rows.map((r) => {
            const v = byKey[r.vehicle_key];
            const fuelL100 = v && v.consum_motorina > 0 ? v.consum_motorina : null;
            const diff = fuelL100 !== null && r.can_l100 !== null ? fuelL100 - r.can_l100 : null;
            return '<tr><td></td><td><button type="button" class="fkm-plate" data-fkm-focus="' + esc(r.vehicle) + '">' + esc(r.vehicle) + '</button></td>'
                + '<td>' + (fuelL100 !== null ? esc(fmt(fuelL100, 2)) + '<span class="fkm-tag">' + esc(v.km_source === 'dispecer' ? 'Dispecer' : 'odometru') + '</span>' : '<span class="fkm-na">–</span>') + '</td>'
                + '<td>' + (r.can_l100 !== null ? esc(fmt(r.can_l100, 2)) : '<span class="fkm-na">–</span>') + '</td>'
                + '<td>' + (diff !== null ? esc((diff > 0 ? '+' : '') + fmt(diff, 2)) : '<span class="fkm-na">–</span>') + '</td>'
                + '<td>' + esc(r.with_can + ' / ' + r.intervals) + (r.pending ? '<span class="fkm-tag is-warn">' + esc(r.pending) + ' necitite</span>' : '') + '</td>'
                + '<td>' + esc(F.int(r.can_gps_km)) + '</td></tr>';
        }).join('') : '<tr><td colspan="7"><div class="fkm-state"><i class="bi bi-cpu" aria-hidden="true"></i><div>Nu există telemetrie CAN salvată pentru vehiculele din selecție.</div></div></td></tr>';
        pane.innerHTML = '<p class="fkm-pane-note">Două surse separate: alimentări (litri ÷ km odometru/Dispecer) și CAN (motorina raportată de motor ÷ km GPS, doar intervalele cu telemetrie plauzibilă). '
            + (canKm > 0 ? 'Flotă CAN: <strong>' + esc(F.l100((canFuel / canKm) * 100)) + '</strong>.' : '') + '</p>'
            + '<div class="fkm-table-wrap"><table class="fkm-table"><thead>' + head + '</thead><tbody>' + body + '</tbody></table></div>'
            + '<div class="fkm-list-tools" style="margin:.45rem 0 0"><span class="fkm-legend">Telemetria se citește din SAS în tabul „Verificare km (GPS/CAN)”' + (pendingCount ? ' — ' + esc(pendingCount) + ' intervale încă necitite' : '') + '.</span>'
            + '<button type="button" class="fkm-link" data-fkm-page-tab="fuel-kmcheck"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Deschide Verificare km</button></div>';
    }

    // ---- Drill: alimentarile dintr-un interval
    function renderDrill(c, data, pane) {
        if (!c.drill) {
            pane.innerHTML = '';
            return;
        }
        const filters = Object.assign({}, c.local, { date_from: c.drill.from, date_to: c.drill.to });
        if (c.def.drillFuel && !c.local.fuel_type) {
            filters.fuel_type = c.def.drillFuel;
        }
        const json = resolved.get(keyOf(filters, 'fillups', {}));
        const head = '<div class="fkm-list-tools"><button type="button" class="fkm-link" data-fkm-back-inline><i class="bi bi-arrow-left" aria-hidden="true"></i>Înapoi</button>'
            + '<strong class="fkm-box-title">Alimentări ' + esc(rangeRo(c.drill.from, c.drill.to)) + (filters.fuel_type ? ' · ' + esc(filters.fuel_type === 'adblue' ? 'AdBlue' : 'motorină') : '') + '</strong></div>';
        if (!json) {
            pane.innerHTML = head + skeleton();
            const token = c.token;
            fetchPart(filters, 'fillups', {}).then(() => {
                if (token === c.token && c.tab === 'drill') {
                    c.dirty.drill = true;
                    renderPane(c, 'drill');
                }
            }).catch((error) => {
                if (token === c.token) {
                    pane.innerHTML = head + '<div class="fkm-state is-error"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>' + esc(error.message) + '</div></div>';
                }
            });
            return;
        }
        const rows = json.fillups;
        pane.innerHTML = head + (rows.length ? '<div class="fkm-table-wrap"><table class="fkm-table"><thead><tr><th></th><th>Data</th><th>Vehicul</th><th>Tip</th><th>Litri</th><th>Valoare</th><th>Stație</th><th>Cursă</th></tr></thead><tbody>'
            + rows.map((r) => '<tr><td>' + (r.is_full ? '<span class="fkm-tag" title="Plin">FULL</span>' : '') + '</td><td>' + esc(dateRo(r.datetime.slice(0, 10)) + ' ' + r.datetime.slice(11, 16)) + '</td>'
                + '<td><button type="button" class="fkm-plate" data-fkm-focus="' + esc(r.vehicle) + '">' + esc(r.vehicle) + '</button></td>'
                + '<td>' + esc(r.fuel_type) + '</td><td>' + esc(F.l(r.liters)) + '</td><td>' + esc(F.lei(r.value)) + '</td>'
                + '<td style="text-align:left">' + esc(r.station || '–') + '</td><td>' + (r.trip_id ? esc('#' + r.trip_id + (r.transport ? ' · ' + (TRANSPORT_LABELS[r.transport === 'primar_tona' ? 'primar' : r.transport] || r.transport) : '')) : '<span class="fkm-na">neasociată</span>') + '</td></tr>').join('')
            + '</tbody></table></div>' + (rows.length >= 300 ? '<p class="fkm-legend" style="margin:.4rem 0 0">Primele 300 de alimentări; restul în tabul Alimentări.</p>' : '')
            : '<div class="fkm-state"><i class="bi bi-inbox" aria-hidden="true"></i><div>Nicio alimentare în interval pentru selecția cardului.</div></div>');
    }

    // ------------------------------------------------------------------ actiuni pe starea locala
    function snapshotState(c) {
        return { local: Object.assign({}, c.local, { vehicles: c.local.vehicles.slice() }), tab: c.tab, metric: c.metric, sort: Object.assign({}, c.sort), segment: c.segment, point: c.point, drill: c.drill };
    }
    function pushHistory(c) {
        c.history.push(snapshotState(c));
        if (c.history.length > 25) {
            c.history.shift();
        }
    }
    function restoreState(c, s) {
        const filtersChanged = !sameFilters(s.local, c.local) || s.local.grouping !== c.local.grouping;
        c.local = s.local;
        c.metric = s.metric;
        c.sort = s.sort;
        c.segment = s.segment;
        c.point = s.point;
        c.drill = s.drill;
        const target = s.tab === 'drill' && !c.drill ? c.def.tabs[0] : s.tab;
        Object.keys(c.panes).forEach((id) => { c.dirty[id] = true; });
        showPane(c, target, false);
        if (filtersChanged) {
            load(c);
        }
        renderChrome(c);
    }

    /** Schimba filtrele locale ale cardului (niciodata pe cele globale) si reincarca analiza. */
    function applyLocal(c, patch, segment) {
        pushHistory(c);
        c.local = Object.assign({}, c.local, patch);
        if (segment !== undefined) {
            c.segment = segment;
        } else if (patch.vehicles) {
            c.segment = null;
        }
        c.point = null;
        if (c.tab === 'drill') {
            c.drill = null;
            c.tab = c.def.tabs[0];
            showPane(c, c.tab, false);
        }
        renderChrome(c);
        load(c);
        announce('Analiza cardului a fost actualizată');
    }

    function toggleCategory(c, row) {
        if (!row) {
            return;
        }
        if (c.segment && c.segment.key === row.key) {
            applyLocal(c, { vehicles: (GLOBAL.vehicles || []).slice() }, null);
            return;
        }
        applyLocal(c, { vehicles: row.vehicles.slice() }, { key: row.key, label: row.label });
    }

    function focusVehicle(c, plate) {
        if (c.local.vehicles.length === 1 && c.local.vehicles[0] === plate) {
            return;
        }
        applyLocal(c, { vehicles: [plate] });
    }

    function resetCard(c) {
        pushHistory(c);
        c.local = Object.assign(globalLocal(), { grouping: c.local.grouping });
        c.segment = null;
        c.point = null;
        c.drill = null;
        if (c.tab === 'drill') {
            c.tab = c.def.tabs[0];
            showPane(c, c.tab, false);
        }
        renderChrome(c);
        load(c);
        announce('Filtrele cardului au revenit la filtrele paginii');
    }

    function openDrill(c, from, to) {
        pushHistory(c);
        c.drill = { from, to };
        c.dirty.drill = true;
        showPane(c, 'drill', false);
    }

    /** Navigarea contextuala: alt tab, alt indicator, drill sau alt card (cu contextul local). */
    function applyGo(c, go) {
        if (go.card && go.card !== c.key && cards[go.card]) {
            expand(cards[go.card], { inherit: Object.assign({}, c.local, { vehicles: c.local.vehicles.slice() }), tab: go.tab || null, sort: go.sort || null });
            return;
        }
        if (go.drill === 'period') {
            openDrill(c, c.local.date_from, c.local.date_to);
            return;
        }
        if (go.sort) {
            c.sort = Object.assign({}, go.sort);
            c.dirty.vehicles = true;
        }
        if (go.metric) {
            c.metric = go.metric;
            c.dirty.evo = true;
        }
        showPane(c, go.tab || c.tab, true);
    }

    function openPageTab(c, tabId) {
        const button = document.querySelector('#fuelTabs [data-bs-target="#' + tabId + '"]');
        if (!isLocal(c) && button && window.bootstrap) {
            window.bootstrap.Tab.getOrCreateInstance(button).show();
            button.scrollIntoView({ behavior: motionOk() ? 'smooth' : 'auto', block: 'start' });
            return;
        }
        // Context local diferit: navigare reala (istoric + buton Back), cu filtrele cardului.
        const params = new URLSearchParams();
        params.append('page', 'carburanti');
        const f = normFilters(c.local);
        Object.keys(f).forEach((name) => {
            if (name === 'vehicles') {
                f.vehicles.forEach((v) => params.append('vehicles[]', v));
            } else if (f[name] !== '') {
                params.append(name, f[name]);
            }
        });
        window.location.href = config.pageUrl + '?' + params.toString() + '#' + tabId;
    }

    // ------------------------------------------------------------------ popovere
    function closePop(restoreFocus) {
        if (!app.pop) {
            return;
        }
        const { el, anchor } = app.pop;
        el.remove();
        anchor.setAttribute('aria-expanded', 'false');
        app.pop = null;
        if (restoreFocus) {
            anchor.focus();
        }
    }

    function openPop(c, anchor, html, bind, role) {
        if (app.pop && app.pop.anchor === anchor) {
            closePop(true);
            return null;
        }
        closePop();
        const el = document.createElement('div');
        el.className = 'fkm-pop fkm-accent-' + (c.el.className.match(/fkm-accent-(\w+)/) || [])[1];
        el.setAttribute('role', role || 'dialog');
        el.innerHTML = html;
        document.body.appendChild(el);
        const r = anchor.getBoundingClientRect();
        const width = el.offsetWidth;
        const left = Math.max(16, Math.min(window.scrollX + r.left, window.scrollX + document.documentElement.clientWidth - width - 16));
        el.style.left = left + 'px';
        el.style.top = (window.scrollY + r.bottom + 6) + 'px';
        anchor.setAttribute('aria-expanded', 'true');
        app.pop = { el, anchor, card: c };
        el.addEventListener('click', (event) => event.stopPropagation());
        bind(el);
        const first = el.querySelector('input, button');
        if (first) {
            first.focus();
        }
        return el;
    }

    function menuPop(c, anchor) {
        const expanded = app.expanded === c;
        const items = [
            expanded ? ['collapse', 'bi-arrows-angle-contract', 'Restrânge analiza'] : ['open', 'bi-arrows-angle-expand', 'Deschide analiza'],
            ['details', 'bi-box-arrow-up-right', 'Vezi detalii în pagină'],
            ['copy', 'bi-clipboard', 'Copiază valoarea'],
        ];
        if (expanded && (isLocal(c) || c.segment)) {
            items.push(['reset', 'bi-arrow-counterclockwise', 'Resetare filtre card']);
        }
        openPop(c, anchor, items.map((i) => '<button type="button" class="fkm-pop-item" role="menuitem" data-act="' + i[0] + '"><i class="bi ' + i[1] + '" aria-hidden="true"></i>' + i[2] + '</button>').join(''), (el) => {
            el.addEventListener('keydown', (event) => {
                const buttons = Array.prototype.slice.call(el.querySelectorAll('button'));
                const i = buttons.indexOf(document.activeElement);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    buttons[(i + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length].focus();
                }
            });
            el.querySelectorAll('[data-act]').forEach((btn) => btn.addEventListener('click', () => {
                const act = btn.getAttribute('data-act');
                closePop();
                if (act === 'open') {
                    expand(c);
                } else if (act === 'collapse') {
                    collapse(c);
                } else if (act === 'details') {
                    openPageTab(c, detailTabFor(c));
                } else if (act === 'reset') {
                    resetCard(c);
                } else if (act === 'copy') {
                    const text = c.valueEl.textContent.trim();
                    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(
                        () => announce('Valoare copiată: ' + text),
                        () => announce('Copierea nu este disponibilă în acest browser'),
                    );
                    anchor.focus();
                }
            }));
        }, 'menu');
    }

    function infoPop(c, anchor) {
        openPop(c, anchor, '<div class="fkm-pop-text">' + c.def.info + '</div>', () => {}, 'dialog');
    }

    function periodPop(c, anchor) {
        const anchorDate = fromIso(GLOBAL.date_to);
        const monthsBack = (n) => {
            const from = new Date(anchorDate.getFullYear(), anchorDate.getMonth() - (n - 1), 1);
            return [toIso(from), GLOBAL.date_to];
        };
        const presets = [
            ['Perioada din filtrele paginii', [GLOBAL.date_from, GLOBAL.date_to]],
            ['Ultimele 3 luni', monthsBack(3)],
            ['Ultimele 6 luni', monthsBack(6)],
            ['Ultimele 12 luni', monthsBack(12)],
        ];
        const html = presets.map((p, i) => '<button type="button" class="fkm-pop-item' + (p[1][0] === c.local.date_from && p[1][1] === c.local.date_to ? ' is-active' : '') + '" data-preset="' + i + '"><i class="bi bi-calendar-range" aria-hidden="true"></i>' + esc(p[0]) + '<small style="margin-left:auto;color:#94a3b8">' + esc(periodLabel(p[1][0], p[1][1])) + '</small></button>').join('')
            + '<div class="fkm-pop-sep"></div><div class="fkm-pop-group">Interval personalizat</div>'
            + '<div class="fkm-pop-row"><input type="date" data-from value="' + esc(c.local.date_from) + '" aria-label="De la"><input type="date" data-to value="' + esc(c.local.date_to) + '" aria-label="Până la"></div>'
            + '<div class="fkm-pop-actions"><button type="button" class="fkm-btn is-primary" data-apply>Aplică intervalul</button></div>';
        openPop(c, anchor, html, (el) => {
            el.querySelectorAll('[data-preset]').forEach((btn) => btn.addEventListener('click', () => {
                const range = presets[Number(btn.getAttribute('data-preset'))][1];
                closePop(true);
                if (range[0] !== c.local.date_from || range[1] !== c.local.date_to) {
                    const longRange = (fromIso(range[1]) - fromIso(range[0])) / 86400000 > 92;
                    applyLocal(c, { date_from: range[0], date_to: range[1], grouping: longRange && c.local.grouping === 'day' ? 'month' : c.local.grouping });
                }
            }));
            el.querySelector('[data-apply]').addEventListener('click', () => {
                let from = el.querySelector('[data-from]').value;
                let to = el.querySelector('[data-to]').value;
                if (!from || !to) {
                    return;
                }
                if (from > to) {
                    [from, to] = [to, from];
                }
                closePop(true);
                applyLocal(c, { date_from: from, date_to: to });
            });
        });
    }

    function vehiclesPop(c, anchor) {
        const selected = new Set(c.local.vehicles);
        const groups = {};
        (config.vehicles || []).forEach((v) => { (groups[v.group] = groups[v.group] || []).push(v); });
        const list = Object.keys(groups).map((g) => '<div class="fkm-pop-group">' + esc(g) + '</div>' + groups[g].map((v) => '<label class="fkm-pop-check" data-plate="' + esc(v.plate.toUpperCase().replace(/\s+/g, '')) + '"><input type="checkbox" class="form-check-input m-0" value="' + esc(v.plate) + '"' + (selected.has(v.plate) ? ' checked' : '') + '><span>' + esc(v.plate) + '</span><small>' + esc(v.label) + '</small></label>').join('')).join('');
        const html = '<input type="search" class="fkm-search" style="width:100%" placeholder="Caută vehicul…" aria-label="Caută vehicul" data-q>'
            + '<div class="fkm-pop-list" style="margin-top:.35rem">' + (list || '<div class="fkm-pop-text">Nu există vehicule în flotă pentru filtrele paginii.</div>') + '</div>'
            + '<div class="fkm-pop-actions"><button type="button" class="fkm-link" data-all>Toate vehiculele</button><button type="button" class="fkm-btn is-primary" data-apply>Aplică</button></div>';
        openPop(c, anchor, html, (el) => {
            el.querySelector('[data-q]').addEventListener('input', (event) => {
                const q = event.target.value.toUpperCase().replace(/\s+/g, '');
                el.querySelectorAll('[data-plate]').forEach((row) => { row.hidden = q !== '' && row.getAttribute('data-plate').indexOf(q) === -1; });
            });
            el.querySelector('[data-all]').addEventListener('click', () => {
                closePop(true);
                applyLocal(c, { vehicles: [] });
            });
            el.querySelector('[data-apply]').addEventListener('click', () => {
                const plates = Array.prototype.map.call(el.querySelectorAll('input[type="checkbox"]:checked'), (i) => i.value);
                closePop(true);
                applyLocal(c, { vehicles: plates });
            });
        });
    }

    function transportPop(c, anchor) {
        const options = [['', 'Toate tipurile']].concat(['primar', 'distributie', 'compresor', 'primar_distributie'].map((k) => [k, TRANSPORT_LABELS[k] || k]));
        openPop(c, anchor, options.map((o) => '<button type="button" class="fkm-pop-item' + ((c.local.transport_group || '') === o[0] ? ' is-active' : '') + '" role="menuitemradio" aria-checked="' + ((c.local.transport_group || '') === o[0] ? 'true' : 'false') + '" data-value="' + o[0] + '"><i class="bi bi-signpost-split" aria-hidden="true"></i>' + esc(o[1]) + '</button>').join('')
            + '<div class="fkm-pop-text" style="font-size:.72rem">Tipul de transport vine din cursa asociată: cu un tip ales, alimentările neasociate unei curse nu intră în analiză.</div>', (el) => {
            el.querySelectorAll('[data-value]').forEach((btn) => btn.addEventListener('click', () => {
                closePop(true);
                const value = btn.getAttribute('data-value');
                if (value !== (c.local.transport_group || '')) {
                    applyLocal(c, { transport_group: value });
                }
            }));
        }, 'menu');
    }

    const detailTabFor = (c) => ({ transport: 'fuel-transport', can: 'fuel-kmcheck' }[c.tab] || c.def.detailTab);

    // ------------------------------------------------------------------ evenimente
    function bindPanel(c) {
        const p = c.panel;
        let checkTimer = null;
        // Niciun clic din panou nu ajunge la card (nu il restrange accidental).
        p.addEventListener('click', (event) => {
            event.stopPropagation();
            if (app.pop && !app.pop.anchor.contains(event.target)) {
                closePop();
            }
            const t = event.target.closest('button, [data-fkm-bar]');
            if (!t || t.disabled) {
                return;
            }
            if (t.hasAttribute('data-fkm-tab')) {
                showPane(c, t.getAttribute('data-fkm-tab'), true);
            } else if (t.hasAttribute('data-fkm-group')) {
                const g = t.getAttribute('data-fkm-group');
                if (g !== c.local.grouping) {
                    applyLocal(c, { grouping: g });
                }
            } else if (t.hasAttribute('data-fkm-pop')) {
                ({ period: periodPop, vehicles: vehiclesPop, transport: transportPop })[t.getAttribute('data-fkm-pop')](c, t);
            } else if (t.hasAttribute('data-fkm-back') || t.hasAttribute('data-fkm-back-inline')) {
                if (c.history.length) {
                    restoreState(c, c.history.pop());
                }
            } else if (t.hasAttribute('data-fkm-reset')) {
                resetCard(c);
            } else if (t.hasAttribute('data-fkm-metric')) {
                const data = readVariant(c, 'main');
                if (data) {
                    applyGo(c, c.def.footer[Number(t.getAttribute('data-fkm-metric'))].go);
                }
            } else if (t.hasAttribute('data-fkm-details')) {
                openPageTab(c, detailTabFor(c));
            } else if (t.hasAttribute('data-fkm-page-tab')) {
                openPageTab(c, t.getAttribute('data-fkm-page-tab'));
            } else if (t.hasAttribute('data-fkm-primary')) {
                applyGo(c, c.def.primary.go);
            } else if (t.hasAttribute('data-fkm-metric-id')) {
                if (t.getAttribute('data-fkm-metric-id') !== c.metric) {
                    pushHistory(c);
                    c.metric = t.getAttribute('data-fkm-metric-id');
                    c.panes.evo.querySelectorAll('[data-fkm-metric-id]').forEach((b) => b.setAttribute('aria-pressed', b === t ? 'true' : 'false'));
                    renderChart(c, readVariant(c, 'main'));
                    renderChrome(c);
                }
            } else if (t.hasAttribute('data-fkm-step')) {
                const data = readVariant(c, 'main');
                const next = Math.max(0, Math.min(data.series.length - 1, (c.point === null ? 0 : c.point) + Number(t.getAttribute('data-fkm-step'))));
                c.point = null;
                selectPoint(c, next);
            } else if (t.hasAttribute('data-fkm-drill-point')) {
                const b = readVariant(c, 'main').series[c.point];
                openDrill(c, b.from, b.to);
            } else if (t.hasAttribute('data-fkm-sort')) {
                const field = t.getAttribute('data-fkm-sort');
                c.sort = { field, dir: c.sort.field === field ? -c.sort.dir : -1 };
                c.dirty.vehicles = true;
                renderPane(c, 'vehicles');
                const again = c.panes.vehicles.querySelector('[data-fkm-sort="' + field + '"]');
                if (again) {
                    again.focus();
                }
            } else if (t.hasAttribute('data-fkm-focus')) {
                focusVehicle(c, t.getAttribute('data-fkm-focus'));
            } else if (t.hasAttribute('data-fkm-clear-vehicles')) {
                applyLocal(c, { vehicles: [] });
            } else if (t.hasAttribute('data-fkm-retry')) {
                load(c);
            }
        });
        // Bifele din lista de vehicule: selectie multipla, aplicata o data dupa o scurta pauza.
        p.addEventListener('change', (event) => {
            const box = event.target.closest('[data-fkm-check]');
            if (!box) {
                return;
            }
            box.closest('tr').classList.toggle('is-selected', box.checked);
            window.clearTimeout(checkTimer);
            checkTimer = window.setTimeout(() => {
                const plates = Array.prototype.map.call(c.panes.vehicles.querySelectorAll('[data-fkm-check]:checked'), (i) => i.getAttribute('data-fkm-check'));
                const hidden = c.local.vehicles.filter((v) => !c.panes.vehicles.querySelector('[data-fkm-check="' + CSS.escape(v) + '"]'));
                applyLocal(c, { vehicles: hidden.concat(plates) });
            }, 450);
        });
        p.addEventListener('input', (event) => {
            if (event.target.matches('[data-fkm-search]')) {
                c.search = event.target.value;
                c.dirty.vehicles = true;
                renderPane(c, 'vehicles');
            }
        });
        p.addEventListener('keydown', (event) => {
            const tab = event.target.closest('[data-fkm-tab]');
            if (tab && (event.key === 'ArrowRight' || event.key === 'ArrowLeft' || event.key === 'Home' || event.key === 'End')) {
                event.preventDefault();
                const tabs = Array.prototype.filter.call(p.querySelectorAll('[data-fkm-tab]'), (b) => !b.hidden);
                const i = tabs.indexOf(tab);
                const next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs[tabs.length - 1] : tabs[(i + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
                next.focus();
                showPane(c, next.getAttribute('data-fkm-tab'), true);
            }
            if (event.target.matches('[data-fkm-canvas]') && (event.key === 'ArrowRight' || event.key === 'ArrowLeft')) {
                event.preventDefault();
                const data = readVariant(c, 'main');
                if (data && data.series.length) {
                    const next = c.point === null ? (event.key === 'ArrowRight' ? 0 : data.series.length - 1) : Math.max(0, Math.min(data.series.length - 1, c.point + (event.key === 'ArrowRight' ? 1 : -1)));
                    c.point = null;
                    selectPoint(c, next);
                    const b = data.series[next];
                    announce(b.label + ': ' + currentMetric(c).fmt(currentMetric(c).get(b)));
                }
            }
        });
    }

    cardList.forEach((c) => {
        c.expandBtn.addEventListener('click', (event) => {
            event.stopPropagation();
            if (app.expanded === c) {
                collapse(c);
            } else {
                expand(c);
            }
        });
        c.el.querySelector('[data-fkm-collapse]').addEventListener('click', (event) => {
            event.stopPropagation();
            collapse(c);
        });
        c.el.querySelector('[data-fkm-menu]').addEventListener('click', (event) => {
            event.stopPropagation();
            menuPop(c, event.currentTarget);
        });
        c.el.querySelector('[data-fkm-info]').addEventListener('click', (event) => {
            event.stopPropagation();
            infoPop(c, event.currentTarget);
        });
        // Preincarcare la intentie (hover / focus pe card compact): deschiderea devine instantanee.
        let intent = null;
        const prefetch = () => {
            window.clearTimeout(intent);
            intent = window.setTimeout(() => {
                if (!readVariant(c, 'main')) {
                    fetchPart(c.local, 'main', mainExtra(c)).catch(() => {});
                }
            }, 160);
        };
        c.el.addEventListener('pointerenter', (event) => {
            if (event.pointerType === 'mouse' && app.expanded !== c) {
                prefetch();
            }
        });
        c.el.addEventListener('pointerleave', () => window.clearTimeout(intent));
        c.expandBtn.addEventListener('focus', () => {
            if (app.expanded !== c) {
                prefetch();
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (app.pop && !app.pop.el.contains(event.target) && !app.pop.anchor.contains(event.target)) {
            closePop();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (app.pop) {
            closePop(true);
            return;
        }
        if (app.expanded && (app.expanded.el.contains(document.activeElement) || document.activeElement === document.body)) {
            collapse(app.expanded);
        }
    });
    window.addEventListener('resize', () => closePop());

    // Tab din hash (#fuel-…): „Vezi detalii" cu filtrele cardului deschide direct tabul cerut.
    // Bootstrap se incarca in footer, dupa scripturile paginii: asteptam DOMContentLoaded.
    const openHashTab = () => {
        const hash = window.location.hash;
        const tabButton = /^#fuel-[a-z]+$/.test(hash) ? document.querySelector('#fuelTabs [data-bs-target="' + hash + '"]') : null;
        if (tabButton && window.bootstrap) {
            window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
            window.requestAnimationFrame(() => tabButton.scrollIntoView({ block: 'start' }));
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openHashTab);
    } else {
        openHashTab();
    }
})();
