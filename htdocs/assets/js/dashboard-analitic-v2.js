/**
 * Dashboard Analitic V2 - stratul interactiv.
 *
 * Serverul trimite randuri agregate (vehicule / soferi / beneficiari / sumar),
 * iar tot ce inseamna sortare, clasamente, comparatii si grafice se construieste
 * aici, ca sa nu fie nevoie de un request nou la fiecare interactiune.
 */
(function () {
    'use strict';

    var root = document.getElementById('da2-root');
    if (!root) {
        return;
    }

    var configEl = document.getElementById('da2-config');
    var config = {};
    try {
        config = JSON.parse(configEl ? configEl.textContent : '{}') || {};
    } catch (error) {
        config = {};
    }

    // ------------------------------------------------------------- formatare

    var nfInt = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var nf3 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 3, maximumFractionDigits: 3 });

    function num(value) {
        var parsed = Number(value);
        return isFinite(parsed) ? parsed : 0;
    }

    function fmt(value, kind) {
        var v = num(value);
        switch (kind) {
            case 'int': return nfInt.format(Math.round(v));
            case 'lei': return nf2.format(v) + ' lei';
            case 'lei3': return nf3.format(v) + ' lei';
            case 'pct': return nf2.format(v) + '%';
            case 'km': return nfInt.format(Math.round(v)) + ' km';
            case 'tone': return nf2.format(v) + ' t';
            default: return nf2.format(v);
        }
    }

    function fmtDateRo(iso) {
        if (!iso || iso.length < 10) {
            return iso || '-';
        }
        return iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4);
    }

    function toIso(date) {
        var m = String(date.getMonth() + 1);
        var d = String(date.getDate());
        return date.getFullYear() + '-' + (m.length < 2 ? '0' + m : m) + '-' + (d.length < 2 ? '0' + d : d);
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // -------------------------------------------------------- registru metrici

    var METRICS = {
        curse: { label: 'Curse', kind: 'int', better: 'high' },
        km_totali: { label: 'Km totali', kind: 'km', better: 'high' },
        km_facturati: { label: 'Km facturați', kind: 'km', better: 'high' },
        km_nefacturati: { label: 'Km nefacturați', kind: 'km', better: 'low' },
        km_nefacturati_percent: { label: 'Km nefacturați %', kind: 'pct', better: 'low' },
        km_salvati: { label: 'Km salvați', kind: 'km', better: 'high' },
        km_exces: { label: 'Km în exces', kind: 'km', better: 'low' },
        tone_livrate: { label: 'Tone livrate', kind: 'tone', better: 'high' },
        facturare: { label: 'Facturare', kind: 'lei', better: 'high' },
        refacturare: { label: 'Refacturare de încasat', kind: 'lei', better: 'high' },
        cheltuieli: { label: 'Cheltuieli', kind: 'lei', better: 'low' },
        profit: { label: 'Profit', kind: 'lei', better: 'high' },
        marja_percent: { label: 'Marjă %', kind: 'pct', better: 'high' },
        venit_km: { label: 'Venit / km', kind: 'lei3', better: 'high' },
        cost_km: { label: 'Cost / km', kind: 'lei3', better: 'low' },
        profit_km: { label: 'Profit / km', kind: 'lei3', better: 'high' },
        venit_tona: { label: 'Venit / tonă', kind: 'lei', better: 'high' },
        profit_tona: { label: 'Profit / tonă', kind: 'lei', better: 'high' },
        km_per_cursa: { label: 'Km / cursă', kind: 'num', better: 'high' },
        tone_per_cursa: { label: 'Tone / cursă', kind: 'num', better: 'high' },
        grad_incarcare: { label: 'Grad de încărcare', kind: 'pct', better: 'high' },
        grad_incarcare_efectiv: { label: 'Grad de încărcare efectiv', kind: 'pct', better: 'high' },
        grad_folosinta: { label: 'Grad de folosință', kind: 'pct', better: 'high' },
        zile_active: { label: 'Zile active', kind: 'int', better: 'high' },
        curse_per_zi_activa: { label: 'Curse / zi activă', kind: 'num', better: 'high' },
        km_per_zi_activa: { label: 'Km / zi activă', kind: 'num', better: 'high' },
        puncte_client: { label: 'Puncte client livrate', kind: 'int', better: 'high' },
        km_per_punct: { label: 'Km / punct client', kind: 'num', better: 'low' },
        tone_per_punct: { label: 'Tone / punct client', kind: 'num', better: 'high' }
    };

    function metricLabel(key) {
        return (METRICS[key] && METRICS[key].label) || key;
    }

    function metricKind(key) {
        return (METRICS[key] && METRICS[key].kind) || 'num';
    }

    function fmtMetric(key, value) {
        return fmt(value, metricKind(key));
    }

    var RANK_METRICS = [
        'profit', 'facturare', 'cheltuieli', 'marja_percent', 'km_totali', 'tone_livrate',
        'curse', 'profit_km', 'venit_km', 'cost_km', 'km_per_cursa', 'tone_per_cursa',
        'grad_incarcare', 'grad_folosinta', 'km_nefacturati_percent'
    ];

    var COMPARE_METRICS = [
        'curse', 'km_totali', 'tone_livrate', 'facturare', 'cheltuieli', 'profit',
        'marja_percent', 'profit_km', 'venit_km', 'cost_km', 'km_per_cursa', 'tone_per_cursa',
        'grad_incarcare', 'grad_folosinta'
    ];

    var EVOLUTION_METRICS = ['facturare', 'refacturare', 'cheltuieli', 'profit', 'km', 'tone', 'curse'];
    var EVOLUTION_LABELS = {
        facturare: 'Facturare', refacturare: 'Refacturare', cheltuieli: 'Cheltuieli',
        profit: 'Profit', km: 'Km', tone: 'Tone', curse: 'Curse'
    };
    var EVOLUTION_KINDS = {
        facturare: 'lei', refacturare: 'lei', cheltuieli: 'lei',
        profit: 'lei', km: 'km', tone: 'tone', curse: 'int'
    };

    var DIMENSION_LABELS = { vehicles: 'Vehicule', drivers: 'Șoferi', beneficiaries: 'Beneficiari' };
    var DIMENSION_SINGULAR = { vehicles: 'Vehicul', drivers: 'Șofer', beneficiaries: 'Beneficiar' };

    // ------------------------------------------------------------------ paleta

    var PALETTE = [
        '#2563eb', '#f97316', '#10b981', '#a855f7', '#ef4444', '#0ea5e9',
        '#eab308', '#14b8a6', '#ec4899', '#8b5cf6', '#22c55e', '#f43f5e'
    ];
    var SERIES_COLORS = {
        facturare: '#2563eb', refacturare: '#0ea5e9', cheltuieli: '#ef4444',
        profit: '#10b981', km: '#a855f7', tone: '#f97316', curse: '#64748b'
    };

    function color(index) {
        return PALETTE[index % PALETTE.length];
    }

    function alpha(hex, value) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + value + ')';
    }

    // ------------------------------------------------------------------- stare

    var state = {
        data: null,
        loading: false,
        tab: 'general',
        autoRefresh: false,
        evolutionType: 'line',
        evolutionCumulative: false,
        evolutionMetrics: { facturare: true, cheltuieli: true, profit: true },
        distributionMetric: 'curse',
        rankDimension: 'vehicles',
        rankMetric: 'profit',
        rankLimit: 10,
        scatterDimension: 'vehicles',
        compareDimension: 'vehicles',
        compareMetrics: { km_totali: true, tone_livrate: true, profit: true },
        compareSelection: { vehicles: [], drivers: [], beneficiaries: [] },
        compareSearch: '',
        matrixMetric: 'km',
        alertSeverity: '',
        kpiOpen: null,
        drawer: null,
        tableSort: {
            vehicles: { key: 'profit', dir: 'desc' },
            drivers: { key: 'profit', dir: 'desc' },
            beneficiaries: { key: 'profit', dir: 'desc' }
        },
        tableSearch: { vehicles: '', drivers: '', beneficiaries: '' }
    };

    var charts = {};
    var refreshTimer = null;
    var requestController = null;
    var reloadTimer = null;
    var drawerController = null;

    // ------------------------------------------------------------------ helperi

    function $(selector, scope) {
        return (scope || document).querySelector(selector);
    }

    function $$(selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    }

    function rowsFor(dimension) {
        if (!state.data) {
            return [];
        }
        return state.data[dimension] || [];
    }

    function sortRows(rows, key, dir) {
        var copy = rows.slice();
        copy.sort(function (a, b) {
            var av = a[key];
            var bv = b[key];
            if (typeof av === 'string' || typeof bv === 'string') {
                return String(av).localeCompare(String(bv), 'ro') * (dir === 'asc' ? 1 : -1);
            }
            return (num(av) - num(bv)) * (dir === 'asc' ? 1 : -1);
        });
        return copy;
    }

    // --------------------------------------------------------------- filtre UI

    var form = document.getElementById('da2-filters');

    function collectFilters() {
        var params = new URLSearchParams();
        params.set('page', 'dashboard_analytic_v2_data');

        var start = $('#da2-date-start').value;
        var end = $('#da2-date-end').value;
        if (start) {
            params.set('date_start', start);
        }
        if (end) {
            params.set('date_end', end);
        }

        $$('[data-ms]', form).forEach(function (ms) {
            var name = ms.getAttribute('data-name');
            $$('input[type="checkbox"]:checked', ms).forEach(function (input) {
                params.append(name + '[]', input.value);
            });
        });

        return params;
    }

    function updateMultiSelectSummaries() {
        $$('[data-ms]', form).forEach(function (ms) {
            var checked = $$('input[type="checkbox"]:checked', ms);
            var valueEl = $('[data-ms-value]', ms);
            if (checked.length === 0) {
                valueEl.textContent = 'Toate';
                ms.classList.remove('is-filtered');
            } else if (checked.length === 1) {
                valueEl.textContent = checked[0].parentNode.textContent.trim();
                ms.classList.add('is-filtered');
            } else {
                valueEl.textContent = checked.length + ' selectate';
                ms.classList.add('is-filtered');
            }
        });
    }

    function renderChips() {
        var container = document.getElementById('da2-chips');
        var chips = [];

        var start = $('#da2-date-start').value;
        var end = $('#da2-date-end').value;
        if (start || end) {
            chips.push({
                type: 'date',
                label: 'Perioadă: ' + (start ? fmtDateRo(start) : '…') + ' – ' + (end ? fmtDateRo(end) : '…')
            });
        }

        $$('[data-ms]', form).forEach(function (ms) {
            var label = ms.getAttribute('data-label');
            $$('input[type="checkbox"]:checked', ms).forEach(function (input) {
                chips.push({
                    type: 'ms',
                    name: ms.getAttribute('data-name'),
                    value: input.value,
                    label: label + ': ' + input.parentNode.textContent.trim()
                });
            });
        });

        container.innerHTML = chips.map(function (chip) {
            if (chip.type === 'date') {
                return '<span class="da2-chip da2-chip-static"><i class="bi bi-calendar3"></i>' + escapeHtml(chip.label) + '</span>';
            }
            return '<button type="button" class="da2-chip" data-chip-name="' + escapeHtml(chip.name) + '" data-chip-value="' + escapeHtml(chip.value) + '">' +
                escapeHtml(chip.label) + '<i class="bi bi-x"></i></button>';
        }).join('');
    }

    function syncUrl(params) {
        var url = new URL(window.location.href);
        var next = new URLSearchParams(params.toString());
        next.set('page', 'dashboard_analitic_v2');
        url.search = next.toString();
        window.history.replaceState({}, '', url.toString());
    }

    function setPreset(preset) {
        var today = new Date();
        var start;
        var end = today;

        if (preset === 'luna_curenta') {
            start = new Date(today.getFullYear(), today.getMonth(), 1);
        } else if (preset === 'luna_trecuta') {
            start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            end = new Date(today.getFullYear(), today.getMonth(), 0);
        } else if (preset === 'ultimele_30') {
            start = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 29);
        } else if (preset === 'trimestru') {
            start = new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1);
        } else if (preset === 'an') {
            start = new Date(today.getFullYear(), 0, 1);
        } else {
            return;
        }

        $('#da2-date-start').value = toIso(start);
        $('#da2-date-end').value = toIso(end);
        markActivePreset();
        scheduleReload(0);
    }

    function markActivePreset() {
        var start = $('#da2-date-start').value;
        var end = $('#da2-date-end').value;
        var today = new Date();
        var map = {
            luna_curenta: [toIso(new Date(today.getFullYear(), today.getMonth(), 1)), toIso(today)],
            luna_trecuta: [
                toIso(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
                toIso(new Date(today.getFullYear(), today.getMonth(), 0))
            ],
            ultimele_30: [toIso(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 29)), toIso(today)],
            trimestru: [toIso(new Date(today.getFullYear(), Math.floor(today.getMonth() / 3) * 3, 1)), toIso(today)],
            an: [toIso(new Date(today.getFullYear(), 0, 1)), toIso(today)]
        };

        $$('.da2-preset').forEach(function (button) {
            var range = map[button.getAttribute('data-preset')];
            button.classList.toggle('is-active', !!range && range[0] === start && range[1] === end);
        });
    }

    // ------------------------------------------------------------------ incarcare

    function scheduleReload(delay) {
        window.clearTimeout(reloadTimer);
        reloadTimer = window.setTimeout(load, delay === undefined ? 300 : delay);
    }

    function setLoading(isLoading) {
        state.loading = isLoading;
        document.getElementById('da2-loading').hidden = !isLoading;
        root.classList.toggle('is-loading', isLoading);
    }

    function showError(message) {
        var el = document.getElementById('da2-error');
        if (!message) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.hidden = false;
        el.innerHTML = '<i class="bi bi-exclamation-octagon"></i> ' + escapeHtml(message);
    }

    function load() {
        var params = collectFilters();
        updateMultiSelectSummaries();
        renderChips();
        markActivePreset();
        syncUrl(params);

        if (requestController) {
            requestController.abort();
        }
        requestController = new AbortController();

        setLoading(true);
        showError('');

        var url = new URL(config.endpoint || window.location.pathname, window.location.origin);
        url.search = params.toString();

        fetch(url.toString(), {
            signal: requestController.signal,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (payload) {
                return { ok: response.ok, payload: payload };
            });
        }).then(function (result) {
            state.data = result.payload;
            if (!result.ok || result.payload.error) {
                showError(result.payload.error || 'Serverul a returnat o eroare.');
            }
            renderAll();
            var stamp = new Date();
            document.getElementById('da2-last-refresh').textContent =
                'Actualizat ' + stamp.toLocaleTimeString('ro-RO');
        }).catch(function (error) {
            if (error.name === 'AbortError') {
                return;
            }
            showError('Nu s-au putut încărca datele: ' + error.message);
        }).then(function () {
            setLoading(false);
        });
    }

    // ---------------------------------------------------------------- randare

    function renderAll() {
        if (!state.data) {
            return;
        }
        renderPeriod();
        renderKpis();
        renderEvolutionToggles();
        renderEvolutionChart();
        renderTransportChart();
        renderKmChart();
        renderRankChart();
        renderScatterChart();
        renderCompare();
        renderSummary();
        renderEntityTable('vehicles');
        renderEntityTable('drivers');
        renderEntityTable('beneficiaries');
        renderAlerts();
    }

    function renderPeriod() {
        var period = state.data.period || {};
        var fleet = state.data.fleet || {};
        document.getElementById('da2-period-label').innerHTML =
            '<strong>' + escapeHtml(fmtDateRo(period.start)) + ' – ' + escapeHtml(fmtDateRo(period.end)) + '</strong>' +
            ' · ' + num(period.zile_calendaristice) + ' zile (' + num(period.zile_lucratoare) + ' lucrătoare)' +
            ' · ' + num(fleet.curse) + ' curse' +
            ' · ' + num(fleet.nr_vehicule) + ' vehicule' +
            ' · ' + num(fleet.nr_soferi) + ' șoferi' +
            ' · ' + num(fleet.nr_beneficiari) + ' beneficiari';
    }

    function meterHtml(percent, tone) {
        var value = Math.max(0, Math.min(100, num(percent)));
        return '<span class="da2-meter"><span class="da2-meter-fill da2-meter-' + tone + '" style="width:' + value.toFixed(1) + '%"></span></span>';
    }

    function toneForPercent(value) {
        var v = num(value);
        if (v >= 75) {
            return 'good';
        }
        if (v >= 50) {
            return 'mid';
        }
        return 'bad';
    }

    /** Defalcarea pe tipuri de transport, folosita in panourile de detaliu. */
    function bucketRows(field, kind) {
        var buckets = ((state.data.summary || {}).transport || []);
        return buckets.map(function (bucket) {
            return { label: bucket.label, value: num(bucket[field]), text: fmt(bucket[field], kind) };
        }).filter(function (row) {
            return row.value !== 0;
        });
    }

    function topEntityRows(dimension, key, kind, limit) {
        return sortRows(rowsFor(dimension), key, 'desc').slice(0, limit || 6).map(function (row) {
            return { label: row.nume, value: num(row[key]), text: fmt(row[key], kind) };
        });
    }

    /**
     * Definitia fiecarui KPI: valoarea afisata pe card + explicatia care se
     * deschide sub grila cand utilizatorul apasa cardul.
     */
    function kpiCards() {
        var f = state.data.fleet || {};
        var fara = num(f.curse) - num(f.curse_cu_capacitate);

        return [
            {
                key: 'curse',
                metric: 'curse',
                icon: 'bi-truck',
                name: 'Curse efectuate',
                value: fmt(f.curse, 'int'),
                note: fmt(f.curse_per_zi_activa, 'num') + ' curse / zi activă · ' + fmt(f.zile_active, 'int') + ' zile active',
                detail: {
                    intro: 'Câte curse au fost înregistrate în perioada selectată, după aplicarea filtrelor. Cursele șterse nu sunt numărate.',
                    formula: 'COUNT(curse) cu data de început în intervalul filtrat',
                    stats: [
                        { label: 'Zile-vehicul active', value: fmt(f.zile_active, 'int') },
                        { label: 'Curse / zi activă', value: fmt(f.curse_per_zi_activa, 'num') },
                        { label: 'Vehicule implicate', value: fmt(f.nr_vehicule, 'int') },
                        { label: 'Șoferi implicați', value: fmt(f.nr_soferi, 'int') },
                        { label: 'Beneficiari', value: fmt(f.nr_beneficiari, 'int') }
                    ],
                    breakdownTitle: 'Curse pe tip de transport',
                    breakdown: bucketRows('curse', 'int'),
                    actions: [
                        { type: 'evolution', metric: 'curse', label: 'Arată în evoluția zilnică' },
                        { type: 'tab', tab: 'vehicule', dimension: 'vehicles', sort: 'curse', label: 'Vezi vehiculele după curse' }
                    ]
                }
            },
            {
                key: 'facturare',
                metric: 'facturare',
                icon: 'bi-receipt',
                name: 'Facturare',
                value: fmt(f.facturare, 'lei'),
                note: 'Refacturare de încasat: ' + fmt(f.refacturare, 'lei'),
                detail: {
                    intro: 'Suma facturată beneficiarilor pentru cursele din perioadă, la care se adaugă refacturările deja emise pe factură.',
                    formula: 'SUM(total facturare cursă) + SUM(refacturări marcate ca facturate)',
                    stats: [
                        { label: 'Refacturare de încasat', value: fmt(f.refacturare, 'lei') },
                        { label: 'Total de încasat', value: fmt(f.total_incasare, 'lei') },
                        { label: 'Venit / km', value: fmt(f.venit_km, 'lei3') },
                        { label: 'Venit / tonă', value: fmt(f.venit_tona, 'lei') },
                        { label: 'Facturare / cursă', value: fmt(num(f.curse) > 0 ? num(f.facturare) / num(f.curse) : 0, 'lei') }
                    ],
                    breakdownTitle: 'Facturare pe tip de transport',
                    breakdown: bucketRows('facturare', 'lei'),
                    actions: [
                        { type: 'evolution', metric: 'facturare', label: 'Arată în evoluția zilnică' },
                        { type: 'tab', tab: 'beneficiari', dimension: 'beneficiaries', sort: 'facturare', label: 'Vezi beneficiarii după facturare' }
                    ]
                }
            },
            {
                key: 'cheltuieli',
                metric: 'cheltuieli',
                icon: 'bi-cash-stack',
                name: 'Cheltuieli',
                value: fmt(f.cheltuieli, 'lei'),
                note: 'Cost / km: ' + fmt(f.cost_km, 'lei3'),
                detail: {
                    intro: 'Cheltuielile atribuite curselor. Refacturările deja emise pe factură se scad, ca să nu fie numărate și ca venit, și ca și cost.',
                    formula: 'SUM(cheltuieli cursă) − SUM(refacturări facturate), minim 0 pe cursă',
                    stats: [
                        { label: 'Cost / km', value: fmt(f.cost_km, 'lei3') },
                        { label: 'Cheltuieli / cursă', value: fmt(num(f.curse) > 0 ? num(f.cheltuieli) / num(f.curse) : 0, 'lei') },
                        { label: 'Pondere din facturare', value: fmt(num(f.facturare) > 0 ? (num(f.cheltuieli) / num(f.facturare)) * 100 : 0, 'pct') }
                    ],
                    breakdownTitle: 'Cheltuieli pe tip de transport',
                    breakdown: bucketRows('cheltuieli', 'lei'),
                    actions: [
                        { type: 'evolution', metric: 'cheltuieli', label: 'Arată în evoluția zilnică' },
                        { type: 'tab', tab: 'vehicule', dimension: 'vehicles', sort: 'cheltuieli', label: 'Vezi vehiculele după cheltuieli' }
                    ]
                }
            },
            {
                key: 'profit',
                metric: 'profit',
                icon: 'bi-graph-up-arrow',
                name: 'Profit',
                value: fmt(f.profit, 'lei'),
                note: 'Marjă ' + fmt(f.marja_percent, 'pct') + ' · Profit/km ' + fmt(f.profit_km, 'lei3'),
                tone: num(f.profit) >= 0 ? 'good' : 'bad',
                detail: {
                    intro: 'Ce rămâne din facturare după cheltuieli. Refacturările încă neîncasate nu intră în profit, ci sunt urmărite separat.',
                    formula: 'Profit = Facturare − Cheltuieli',
                    stats: [
                        { label: 'Marjă', value: fmt(f.marja_percent, 'pct') },
                        { label: 'Profit / km', value: fmt(f.profit_km, 'lei3') },
                        { label: 'Profit / tonă', value: fmt(f.profit_tona, 'lei') },
                        { label: 'Profit / cursă', value: fmt(num(f.curse) > 0 ? num(f.profit) / num(f.curse) : 0, 'lei') }
                    ],
                    breakdownTitle: 'Profit pe tip de transport',
                    breakdown: bucketRows('profit', 'lei'),
                    actions: [
                        { type: 'evolution', metric: 'profit', label: 'Arată în evoluția zilnică' },
                        { type: 'tab', tab: 'vehicule', dimension: 'vehicles', sort: 'profit', label: 'Vezi vehiculele după profit' },
                        { type: 'rank', metric: 'profit', label: 'Clasament după profit' }
                    ]
                }
            },
            {
                key: 'km',
                metric: 'km',
                icon: 'bi-signpost-split',
                name: 'Km parcurși',
                value: fmt(f.km_totali, 'km'),
                note: 'Facturați ' + fmt(f.km_facturati, 'km') + ' · Nefacturați ' + fmt(f.km_nefacturati, 'km') +
                    ' (' + fmt(f.km_nefacturati_percent, 'pct') + ')',
                detail: {
                    intro: 'Km efectiv parcurși. Se iau km totali ai cursei, iar când aceștia lipsesc se folosesc km rutei tarifate.',
                    formula: 'km_totali, cu revenire pe km_cursa când km_totali lipsește sau este 0',
                    stats: [
                        { label: 'Km facturați', value: fmt(f.km_facturati, 'km') },
                        { label: 'Km nefacturați', value: fmt(f.km_nefacturati, 'km') + ' (' + fmt(f.km_nefacturati_percent, 'pct') + ')' },
                        { label: 'Km salvați față de rută', value: fmt(f.km_salvati, 'km') },
                        { label: 'Km în exces față de rută', value: fmt(f.km_exces, 'km') },
                        {
                            label: 'Km primar, cu mixt',
                            value: fmt(f.km_primar, 'km'),
                            hint: 'Km tarifați ai curselor primar, plus partea de primar a curselor mixte. Nu include compresor, deci nu se adună cu km distribuție până la total.'
                        },
                        {
                            label: 'Km distribuție, cu mixt',
                            value: fmt(f.km_distributie, 'km'),
                            hint: 'Km tarifați ai curselor de distribuție, plus partea de distribuție a curselor mixte.'
                        }
                    ],
                    breakdownTitle: 'Km pe tip de transport',
                    breakdown: bucketRows('km', 'km'),
                    breakdownNote: 'Categoriile de mai jos sunt exclusive și însumează exact totalul.',
                    actions: [
                        { type: 'evolution', metric: 'km', label: 'Arată în evoluția zilnică' },
                        { type: 'tab', tab: 'vehicule', dimension: 'vehicles', sort: 'km_totali', label: 'Vezi vehiculele după km' }
                    ]
                }
            },
            {
                key: 'tone',
                metric: 'tone',
                icon: 'bi-box-seam',
                name: 'Tone livrate',
                value: fmt(f.tone_livrate, 'tone'),
                note: 'Primar ' + fmt(f.tone_primar, 'tone') + ' · Distribuție ' + fmt(f.tone_distributie, 'tone'),
                detail: {
                    intro: 'Marfa livrată. Se folosește tona livrată acolo unde este completată; altfel se ia cantitatea încărcată, convertită în tone când este trecută în kilograme.',
                    formula: 'tona_livrata, cu revenire pe cantitate_incarcata (÷1000 când valoarea este în kg)',
                    stats: [
                        { label: 'Tone primar', value: fmt(f.tone_primar, 'tone'), hint: 'Curse primar km și primar tone.' },
                        { label: 'Tone distribuție, cu mixt', value: fmt(f.tone_distributie, 'tone'), hint: 'Curse de distribuție plus curse mixte primar + distribuție.' },
                        { label: 'Tone / cursă', value: fmt(f.tone_per_cursa, 'num') },
                        { label: 'Km / tonă', value: fmt(f.km_tona, 'num') },
                        { label: 'Puncte client livrate', value: fmt(f.puncte_client, 'int'), hint: 'Suma numărului de clienți deserviți, înregistrat pe fiecare cursă.' },
                        { label: 'Tone / punct client', value: fmt(f.tone_per_punct, 'num') }
                    ],
                    breakdownTitle: 'Tone pe tip de transport',
                    breakdown: bucketRows('tone', 'tone'),
                    breakdownNote: 'Categoriile de mai jos sunt exclusive și însumează exact totalul.',
                    actions: [
                        { type: 'evolution', metric: 'tone', label: 'Arată în evoluția zilnică' },
                        { type: 'tab', tab: 'soferi', dimension: 'drivers', sort: 'tone_livrate', label: 'Vezi șoferii după tone' }
                    ]
                }
            },
            {
                key: 'incarcare',
                icon: 'bi-fuel-pump',
                name: 'Grad de încărcare (umplere)',
                value: fmt(f.grad_incarcare, 'pct'),
                note: 'Efectiv ' + fmt(f.grad_incarcare_efectiv, 'pct') + ' pe ' + fmt(f.curse_cu_capacitate, 'int') +
                    ' din ' + fmt(f.curse, 'int') + ' curse cu capacitate configurată',
                meter: num(f.grad_incarcare),
                detail: {
                    intro: 'Cât de plin pleacă vehiculul: cantitatea încărcată raportată la capacitatea de transport a cursei. Răspunde la întrebarea „folosim tot spațiul pe care îl plimbăm?”.',
                    formula: 'AVG(cantitate încărcată ÷ capacitate transport × 100), plafonat la 100%',
                    stats: [
                        { label: 'Grad efectiv', value: fmt(f.grad_incarcare_efectiv, 'pct'), hint: 'doar cursele cu capacitate configurată; la compresor se folosește tona livrată' },
                        { label: 'Curse cu capacitate', value: fmt(f.curse_cu_capacitate, 'int') },
                        { label: 'Curse fără capacitate', value: fmt(fara, 'int'), hint: 'intră în medie ca 0% și trag cifra în jos' },
                        { label: 'Tone / cursă', value: fmt(f.tone_per_cursa, 'num') }
                    ],
                    breakdownTitle: 'Grad de încărcare pe tip de transport',
                    breakdown: bucketRows('grad_incarcare', 'pct'),
                    actions: [
                        { type: 'tab', tab: 'vehicule', dimension: 'vehicles', sort: 'grad_incarcare', label: 'Vezi vehiculele după încărcare' },
                        { type: 'rank', metric: 'grad_incarcare', label: 'Clasament după încărcare' }
                    ]
                }
            },
            {
                key: 'folosinta',
                icon: 'bi-calendar2-check',
                name: 'Grad de folosință',
                value: fmt(f.grad_folosinta, 'pct'),
                note: fmt(f.zile_active, 'int') + ' zile-vehicul active din ' + fmt(f.zile_disponibile, 'int') + ' disponibile',
                meter: num(f.grad_folosinta),
                detail: {
                    intro: 'Cât de des sunt folosite vehiculele: zilele în care un vehicul a avut cel puțin o cursă, raportate la zilele lucrătoare din perioadă. Răspunde la întrebarea „câte vehicule stau degeaba?”.',
                    formula: 'zile-vehicul active ÷ (vehicule active × zile lucrătoare din perioadă)',
                    stats: [
                        { label: 'Zile-vehicul active', value: fmt(f.zile_active, 'int') },
                        { label: 'Zile-vehicul disponibile', value: fmt(f.zile_disponibile, 'int') },
                        { label: 'Vehicule active', value: fmt(f.vehicule_active, 'int') },
                        { label: 'Zile lucrătoare în perioadă', value: fmt(f.zile_lucratoare, 'int'), hint: 'sâmbăta și duminica nu se numără' },
                        { label: 'Curse / zi activă', value: fmt(f.curse_per_zi_activa, 'num') }
                    ],
                    breakdownTitle: 'Cele mai folosite vehicule',
                    breakdown: topEntityRows('vehicles', 'grad_folosinta', 'pct', 6),
                    actions: [
                        { type: 'tab', tab: 'vehicule', dimension: 'vehicles', sort: 'grad_folosinta', label: 'Vezi vehiculele după folosință' },
                        { type: 'rank', metric: 'grad_folosinta', label: 'Clasament după folosință' }
                    ]
                }
            },
            {
                key: 'medii',
                icon: 'bi-rulers',
                name: 'Medii pe cursă',
                value: fmt(f.km_per_cursa, 'num') + ' km',
                note: fmt(f.tone_per_cursa, 'num') + ' tone / cursă · ' + fmt(f.km_tona, 'num') + ' km / tonă',
                detail: {
                    intro: 'Cât înseamnă o cursă medie în perioada și filtrele curente. Aceleași medii, dar pe client și pe tip de transport, sunt în tabul Raport sumar.',
                    formula: 'Km / cursă = km totali ÷ curse · Tone / cursă = tone livrate ÷ curse',
                    stats: [
                        { label: 'Km / cursă', value: fmt(f.km_per_cursa, 'num') },
                        { label: 'Tone / cursă', value: fmt(f.tone_per_cursa, 'num') },
                        { label: 'Km / tonă', value: fmt(f.km_tona, 'num') },
                        { label: 'Tone / km', value: fmt(f.tona_km, 'num') },
                        { label: 'Km / punct client', value: fmt(f.km_per_punct, 'num') }
                    ],
                    breakdownTitle: 'Km / cursă pe tip de transport',
                    breakdown: bucketRows('km_per_cursa', 'num'),
                    actions: [
                        { type: 'tab', tab: 'raport', label: 'Deschide raportul sumar' }
                    ]
                }
            }
        ];
    }

    function renderKpis() {
        var cards = kpiCards();

        document.getElementById('da2-kpis').innerHTML = cards.map(function (card) {
            var isOpen = state.kpiOpen === card.key;
            return '<button type="button" class="da2-kpi' + (card.tone ? ' da2-kpi-' + card.tone : '') +
                (isOpen ? ' is-open' : '') + '" data-kpi="' + escapeHtml(card.key) + '" aria-expanded="' + isOpen + '">' +
                '<span class="da2-kpi-head"><i class="bi ' + card.icon + '"></i>' + escapeHtml(card.name) +
                '<i class="bi bi-chevron-down da2-kpi-caret"></i></span>' +
                '<span class="da2-kpi-value">' + escapeHtml(card.value) + '</span>' +
                (card.meter !== undefined ? meterHtml(card.meter, toneForPercent(card.meter)) : '') +
                '<span class="da2-kpi-note">' + escapeHtml(card.note) + '</span>' +
                '</button>';
        }).join('');

        renderKpiDetail();
    }

    function renderKpiDetail() {
        var container = document.getElementById('da2-kpi-detail');
        if (!container) {
            return;
        }

        var card = kpiCards().filter(function (item) { return item.key === state.kpiOpen; })[0];
        if (!card) {
            container.classList.remove('is-open');
            container.innerHTML = '';
            return;
        }

        var d = card.detail;
        var maxValue = d.breakdown.reduce(function (max, row) { return Math.max(max, Math.abs(row.value)); }, 0) || 1;

        var stats = d.stats.map(function (stat) {
            return '<div class="da2-detail-stat"' + (stat.hint ? ' title="' + escapeHtml(stat.hint) + '"' : '') + '>' +
                '<span class="da2-detail-stat-label">' + escapeHtml(stat.label) +
                (stat.hint ? ' <i class="bi bi-info-circle"></i>' : '') + '</span>' +
                '<strong>' + escapeHtml(stat.value) + '</strong></div>';
        }).join('');

        var breakdown = d.breakdown.length
            ? d.breakdown.map(function (row) {
                var share = Math.min(100, (Math.abs(row.value) / maxValue) * 100);
                return '<div class="da2-detail-row">' +
                    '<span class="da2-detail-row-label">' + escapeHtml(row.label) + '</span>' +
                    '<span class="da2-detail-row-bar"><span style="width:' + share.toFixed(1) + '%"></span></span>' +
                    '<span class="da2-detail-row-value">' + escapeHtml(row.text) + '</span>' +
                    '</div>';
            }).join('')
            : '<p class="da2-empty">Nu există date pentru defalcare.</p>';

        var actions = d.actions.map(function (action) {
            return '<button type="button" class="da2-btn da2-btn-sm" data-detail-action="' + escapeHtml(action.type) + '"' +
                (action.metric ? ' data-metric="' + escapeHtml(action.metric) + '"' : '') +
                (action.tab ? ' data-target-tab="' + escapeHtml(action.tab) + '"' : '') +
                (action.dimension ? ' data-dimension="' + escapeHtml(action.dimension) + '"' : '') +
                (action.sort ? ' data-sort="' + escapeHtml(action.sort) + '"' : '') +
                '>' + escapeHtml(action.label) + '<i class="bi bi-arrow-right-short"></i></button>';
        }).join('');

        container.innerHTML =
            '<div class="da2-detail-inner">' +
                '<header class="da2-detail-head">' +
                    '<span class="da2-detail-title"><i class="bi ' + card.icon + '"></i>' + escapeHtml(card.name) + '</span>' +
                    '<span class="da2-detail-value">' + escapeHtml(card.value) + '</span>' +
                    '<button type="button" class="da2-detail-close" data-detail-close aria-label="Închide detaliul">' +
                        '<i class="bi bi-x-lg"></i></button>' +
                '</header>' +
                '<div class="da2-detail-body">' +
                    '<section class="da2-detail-about">' +
                        '<h3>Ce arată</h3><p>' + escapeHtml(d.intro) + '</p>' +
                        '<h3>Cum se calculează</h3><p class="da2-detail-formula">' + escapeHtml(d.formula) + '</p>' +
                    '</section>' +
                    '<section class="da2-detail-stats">' +
                        '<h3>Din ce se compune</h3>' +
                        '<div class="da2-detail-stat-grid">' + stats + '</div>' +
                    '</section>' +
                    '<section class="da2-detail-breakdown">' +
                        '<h3>' + escapeHtml(d.breakdownTitle) + '</h3>' + breakdown +
                        (d.breakdownNote ? '<p class="da2-detail-note">' + escapeHtml(d.breakdownNote) + '</p>' : '') +
                    '</section>' +
                '</div>' +
                '<footer class="da2-detail-actions">' + actions + '</footer>' +
            '</div>';

        // fortam un reflow ca tranzitia sa porneasca si cand se schimba direct alt card
        void container.offsetHeight;
        container.classList.add('is-open');
    }

    // --------------------------------------------------------------- grafice

    /**
     * Distruge graficul si ascunde mesajul de "fara date".
     * Mesajul este un overlay HTML, nu text desenat pe canvas: un canvas dintr-un
     * tab ascuns are dimensiune 0 si nu poate desena nimic.
     */
    function destroyChart(key, canvas) {
        if (charts[key]) {
            charts[key].destroy();
            delete charts[key];
        }
        if (canvas) {
            var overlay = canvas.parentNode.querySelector('.da2-chart-empty');
            if (overlay) {
                overlay.hidden = true;
            }
            canvas.hidden = false;
        }
    }

    function drawEmpty(canvas, message) {
        var wrap = canvas.parentNode;
        var overlay = wrap.querySelector('.da2-chart-empty');
        if (!overlay) {
            overlay = document.createElement('p');
            overlay.className = 'da2-chart-empty';
            wrap.appendChild(overlay);
        }
        overlay.textContent = message;
        overlay.hidden = false;
        canvas.hidden = true;
    }

    function baseOptions(extra) {
        var options = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
                tooltip: { padding: 10, boxPadding: 4 }
            }
        };
        return Object.assign(options, extra || {});
    }

    function renderEvolutionToggles() {
        var container = document.getElementById('da2-evolution-metrics');
        container.innerHTML = EVOLUTION_METRICS.map(function (key) {
            var active = !!state.evolutionMetrics[key];
            return '<button type="button" class="da2-metric-chip' + (active ? ' is-active' : '') +
                '" data-evolution-metric="' + key + '" style="--chip-color:' + SERIES_COLORS[key] + '">' +
                '<span class="da2-metric-dot"></span>' + EVOLUTION_LABELS[key] + '</button>';
        }).join('');
    }

    function cumulate(values) {
        var total = 0;
        return values.map(function (value) {
            total += num(value);
            return Math.round(total * 100) / 100;
        });
    }

    function renderEvolutionChart() {
        var daily = state.data.daily || { labels: [] };
        var canvas = document.getElementById('da2-chart-evolution');
        destroyChart('evolution', canvas);

        var active = EVOLUTION_METRICS.filter(function (key) {
            return state.evolutionMetrics[key];
        });

        if (!daily.labels.length || active.length === 0) {
            drawEmpty(canvas, active.length === 0 ? 'Selectează cel puțin o metrică.' : 'Nu există date în perioada selectată.');
            return;
        }

        var datasets = active.map(function (key) {
            var values = (daily[key] || []).map(num);
            if (state.evolutionCumulative) {
                values = cumulate(values);
            }
            var useMoneyAxis = ['facturare', 'refacturare', 'cheltuieli', 'profit'].indexOf(key) !== -1;
            return {
                label: EVOLUTION_LABELS[key],
                data: values,
                borderColor: SERIES_COLORS[key],
                backgroundColor: state.evolutionType === 'bar' ? alpha(SERIES_COLORS[key], 0.75) : alpha(SERIES_COLORS[key], 0.12),
                borderWidth: 2,
                fill: state.evolutionType === 'line' && active.length === 1,
                tension: 0.3,
                pointRadius: daily.labels.length > 45 ? 0 : 3,
                pointHoverRadius: 5,
                yAxisID: useMoneyAxis ? 'y' : 'y2',
                metricKey: key
            };
        });

        var needsSecond = datasets.some(function (d) { return d.yAxisID === 'y2'; });
        var needsFirst = datasets.some(function (d) { return d.yAxisID === 'y'; });

        charts.evolution = new Chart(canvas, {
            type: state.evolutionType,
            data: {
                labels: daily.labels.map(fmtDateRo),
                datasets: datasets
            },
            options: baseOptions({
                scales: {
                    y: {
                        display: needsFirst,
                        position: 'left',
                        title: { display: true, text: 'lei' },
                        ticks: { callback: function (value) { return nfInt.format(value); } }
                    },
                    y2: {
                        display: needsSecond,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'km / tone / curse' },
                        ticks: { callback: function (value) { return nfInt.format(value); } }
                    }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var key = context.dataset.metricKey;
                                return context.dataset.label + ': ' + fmt(context.parsed.y, EVOLUTION_KINDS[key]);
                            }
                        }
                    }
                }
            })
        });
    }

    function renderTransportChart() {
        var summary = state.data.summary || {};
        var rows = summary.transport || [];
        var canvas = document.getElementById('da2-chart-transport');
        destroyChart('transport', canvas);

        if (!rows.length) {
            drawEmpty(canvas, 'Nu există date în perioada selectată.');
            return;
        }

        var metric = state.distributionMetric;
        var kinds = { curse: 'int', km: 'km', tone: 'tone', profit: 'lei' };

        charts.transport = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: rows.map(function (row) { return row.label; }),
                datasets: [{
                    data: rows.map(function (row) { return num(row[metric]); }),
                    backgroundColor: rows.map(function (row, index) { return color(index); }),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: baseOptions({
                cutout: '58%',
                interaction: { mode: 'nearest', intersect: true },
                onClick: function (event, elements) {
                    if (!elements.length) {
                        return;
                    }
                    filterByBucket(rows[elements[0].index].key);
                },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var total = context.dataset.data.reduce(function (sum, v) { return sum + num(v); }, 0);
                                var value = num(context.parsed);
                                var share = total > 0 ? (value / total) * 100 : 0;
                                return context.label + ': ' + fmt(value, kinds[metric]) + ' (' + nf2.format(share) + '%)';
                            }
                        }
                    }
                }
            })
        });
    }

    /** Bucket-urile agrega mai multe valori din enum; le mapam inapoi la filtre. */
    function filterByBucket(bucket) {
        var map = {
            primar: ['primar', 'primar_tona'],
            distributie: ['distributie'],
            primar_distributie: ['primar_distributie'],
            compresor: ['compresor']
        };
        var values = map[bucket] || [bucket];
        var ms = $('[data-ms][data-name="transport_types"]', form);
        if (!ms) {
            return;
        }

        $$('input[type="checkbox"]', ms).forEach(function (input) {
            input.checked = values.indexOf(input.value) !== -1;
        });
        scheduleReload(0);
    }

    function renderKmChart() {
        var f = state.data.fleet || {};
        var canvas = document.getElementById('da2-chart-km');
        destroyChart('km', canvas);

        var facturati = num(f.km_facturati);
        var nefacturati = num(f.km_nefacturati);

        if (facturati + nefacturati === 0) {
            drawEmpty(canvas, 'Nu există km în perioada selectată.');
        } else {
            charts.km = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: ['Km'],
                    datasets: [
                        { label: 'Facturați', data: [facturati], backgroundColor: '#2563eb', borderRadius: 6 },
                        { label: 'Nefacturați', data: [nefacturati], backgroundColor: '#ef4444', borderRadius: 6 }
                    ]
                },
                options: baseOptions({
                    indexAxis: 'y',
                    scales: {
                        x: { stacked: true, ticks: { callback: function (v) { return nfInt.format(v); } } },
                        y: { stacked: true }
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    var total = facturati + nefacturati;
                                    var share = total > 0 ? (num(context.parsed.x) / total) * 100 : 0;
                                    return context.dataset.label + ': ' + fmt(context.parsed.x, 'km') + ' (' + nf2.format(share) + '%)';
                                }
                            }
                        }
                    }
                })
            });
        }

        document.getElementById('da2-km-stats').innerHTML = [
            { label: 'Km salvați', value: fmt(f.km_salvati, 'km'), tone: 'good' },
            { label: 'Km în exces', value: fmt(f.km_exces, 'km'), tone: 'bad' },
            { label: 'Km primar', value: fmt(f.km_primar, 'km'), tone: '' },
            { label: 'Km distribuție', value: fmt(f.km_distributie, 'km'), tone: '' }
        ].map(function (item) {
            return '<div class="da2-mini-stat' + (item.tone ? ' da2-mini-' + item.tone : '') + '">' +
                '<span>' + item.label + '</span><strong>' + escapeHtml(item.value) + '</strong></div>';
        }).join('');
    }

    function renderRankChart() {
        var select = document.getElementById('da2-rank-metric');
        if (!select.options.length) {
            select.innerHTML = RANK_METRICS.map(function (key) {
                return '<option value="' + key + '"' + (key === state.rankMetric ? ' selected' : '') + '>' +
                    escapeHtml(metricLabel(key)) + '</option>';
            }).join('');
        }

        var canvas = document.getElementById('da2-chart-rank');
        destroyChart('rank', canvas);

        var rows = sortRows(rowsFor(state.rankDimension), state.rankMetric, 'desc');
        if (state.rankLimit > 0) {
            rows = rows.slice(0, state.rankLimit);
        }

        if (!rows.length) {
            drawEmpty(canvas, 'Nu există date în perioada selectată.');
            return;
        }

        var metric = state.rankMetric;
        var horizontal = rows.length > 8;
        // Axa de categorii primeste indexul ca valoare, deci formatarea numerica
        // trebuie pusa doar pe axa de valori, altfel apar 0,1,2… in loc de nume.
        var valueTicks = { ticks: { callback: function (value) { return nfInt.format(value); } } };
        var scales = horizontal ? { x: valueTicks, y: {} } : { x: {}, y: valueTicks };

        charts.rank = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(function (row) { return row.nume; }),
                datasets: [{
                    label: metricLabel(metric),
                    data: rows.map(function (row) { return num(row[metric]); }),
                    backgroundColor: rows.map(function (row) {
                        return num(row[metric]) < 0 ? '#ef4444' : '#2563eb';
                    }),
                    borderRadius: 6,
                    maxBarThickness: 42
                }]
            },
            options: baseOptions({
                indexAxis: horizontal ? 'y' : 'x',
                interaction: { mode: 'nearest', intersect: true },
                onClick: function (event, elements) {
                    if (elements.length) {
                        toggleCompareEntity(state.rankDimension, rows[elements[0].index].nume, true);
                    }
                },
                scales: scales,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return metricLabel(metric) + ': ' + fmtMetric(metric, context.parsed[horizontal ? 'x' : 'y']);
                            }
                        }
                    }
                }
            })
        });
    }

    function renderScatterChart() {
        var canvas = document.getElementById('da2-chart-scatter');
        destroyChart('scatter', canvas);

        var rows = rowsFor(state.scatterDimension);
        if (!rows.length) {
            drawEmpty(canvas, 'Nu există date în perioada selectată.');
            return;
        }

        var maxCurse = rows.reduce(function (max, row) { return Math.max(max, num(row.curse)); }, 1);

        charts.scatter = new Chart(canvas, {
            type: 'bubble',
            data: {
                datasets: rows.map(function (row, index) {
                    return {
                        label: row.nume,
                        data: [{
                            x: num(row.km_totali),
                            y: num(row.profit),
                            r: 6 + (num(row.curse) / maxCurse) * 18
                        }],
                        backgroundColor: alpha(color(index), 0.65),
                        borderColor: color(index),
                        borderWidth: 1,
                        entityName: row.nume,
                        entityRow: row
                    };
                })
            },
            options: baseOptions({
                interaction: { mode: 'nearest', intersect: true },
                onClick: function (event, elements) {
                    if (elements.length) {
                        var dataset = charts.scatter.data.datasets[elements[0].datasetIndex];
                        toggleCompareEntity(state.scatterDimension, dataset.entityName, true);
                    }
                },
                scales: {
                    x: { title: { display: true, text: 'Km totali' }, ticks: { callback: function (v) { return nfInt.format(v); } } },
                    y: { title: { display: true, text: 'Profit (lei)' }, ticks: { callback: function (v) { return nfInt.format(v); } } }
                },
                plugins: {
                    legend: { display: rows.length <= 12, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var row = context.dataset.entityRow;
                                return [
                                    row.nume,
                                    'Km: ' + fmt(row.km_totali, 'km'),
                                    'Profit: ' + fmt(row.profit, 'lei'),
                                    'Curse: ' + fmt(row.curse, 'int'),
                                    'Grad încărcare: ' + fmt(row.grad_incarcare, 'pct')
                                ];
                            }
                        }
                    }
                }
            })
        });
    }

    // ------------------------------------------------------------- comparatie

    function currentSelection() {
        return state.compareSelection[state.compareDimension];
    }

    function toggleCompareEntity(dimension, name, activateTab) {
        var list = state.compareSelection[dimension];
        var index = list.indexOf(name);
        if (index === -1) {
            list.push(name);
        } else {
            list.splice(index, 1);
        }

        if (activateTab) {
            state.compareDimension = dimension;
            syncSegments();
            activateTabByName('comparatie');
        }
        renderCompare();
    }

    function renderCompare() {
        renderCompareMetrics();
        renderCompareList();
        renderCompareChart();
        renderRadarChart();
        renderCompareMixChart();
        renderCompareTable();
    }

    function renderCompareMetrics() {
        document.getElementById('da2-compare-metrics').innerHTML = COMPARE_METRICS.map(function (key, index) {
            var active = !!state.compareMetrics[key];
            return '<button type="button" class="da2-metric-chip' + (active ? ' is-active' : '') +
                '" data-compare-metric="' + key + '" style="--chip-color:' + color(index) + '">' +
                '<span class="da2-metric-dot"></span>' + escapeHtml(metricLabel(key)) + '</button>';
        }).join('');
    }

    function renderCompareList() {
        var rows = rowsFor(state.compareDimension);
        var selection = currentSelection();
        var search = state.compareSearch.toLowerCase();
        var container = document.getElementById('da2-compare-list');

        var visible = rows.filter(function (row) {
            return !search || String(row.nume).toLowerCase().indexOf(search) !== -1;
        });

        if (!visible.length) {
            container.innerHTML = '<p class="da2-empty">Nicio entitate disponibilă.</p>';
            return;
        }

        container.innerHTML = visible.map(function (row) {
            var checked = selection.indexOf(row.nume) !== -1;
            return '<label class="da2-compare-item' + (checked ? ' is-selected' : '') + '">' +
                '<input type="checkbox" data-compare-entity="' + escapeHtml(row.nume) + '"' + (checked ? ' checked' : '') + '>' +
                '<span class="da2-compare-name">' + escapeHtml(row.nume) + '</span>' +
                '<span class="da2-compare-meta">' + fmt(row.curse, 'int') + ' curse · ' + fmt(row.km_totali, 'km') + '</span>' +
                '</label>';
        }).join('');
    }

    function selectedRows() {
        var selection = currentSelection();
        var rows = rowsFor(state.compareDimension);
        return selection.map(function (name) {
            return rows.filter(function (row) { return row.nume === name; })[0];
        }).filter(Boolean);
    }

    function activeCompareMetrics() {
        return COMPARE_METRICS.filter(function (key) { return state.compareMetrics[key]; });
    }

    function renderCompareChart() {
        var canvas = document.getElementById('da2-chart-compare');
        destroyChart('compare', canvas);

        var rows = selectedRows();
        var metrics = activeCompareMetrics();

        if (!rows.length) {
            drawEmpty(canvas, 'Selectează cel puțin o entitate din listă.');
            return;
        }
        if (!metrics.length) {
            drawEmpty(canvas, 'Selectează cel puțin o metrică.');
            return;
        }

        // Entitatile sunt pe axa X, fiecare metrica este o serie cu axa ei proprie:
        // asa raman comparabile intre entitati, chiar daca unitatile difera (lei vs. km vs. tone).
        var scales = {};
        metrics.forEach(function (key, index) {
            scales['y' + index] = {
                type: 'linear',
                position: index % 2 === 0 ? 'left' : 'right',
                display: index < 2,
                grid: { drawOnChartArea: index === 0 },
                title: { display: index < 2, text: metricLabel(key) },
                ticks: { callback: function (value) { return nfInt.format(value); } }
            };
        });

        charts.compare = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(function (row) { return row.nume; }),
                datasets: metrics.map(function (key, index) {
                    return {
                        label: metricLabel(key),
                        data: rows.map(function (row) { return num(row[key]); }),
                        backgroundColor: alpha(color(index), 0.8),
                        borderColor: color(index),
                        borderWidth: 1,
                        borderRadius: 5,
                        yAxisID: 'y' + index,
                        metricKey: key
                    };
                })
            },
            options: baseOptions({
                interaction: { mode: 'index', intersect: false },
                scales: scales,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + fmtMetric(context.dataset.metricKey, context.parsed.y);
                            }
                        }
                    }
                }
            })
        });
    }

    function renderRadarChart() {
        var canvas = document.getElementById('da2-chart-radar');
        destroyChart('radar', canvas);

        var rows = selectedRows();
        var metrics = activeCompareMetrics();

        if (rows.length < 1 || metrics.length < 3) {
            drawEmpty(canvas, 'Alege cel puțin 3 metrici și o entitate.');
            return;
        }

        var maxima = metrics.map(function (key) {
            return rows.reduce(function (max, row) { return Math.max(max, Math.abs(num(row[key]))); }, 0) || 1;
        });

        charts.radar = new Chart(canvas, {
            type: 'radar',
            data: {
                labels: metrics.map(metricLabel),
                datasets: rows.map(function (row, index) {
                    return {
                        label: row.nume,
                        data: metrics.map(function (key, i) {
                            return Math.round((num(row[key]) / maxima[i]) * 1000) / 10;
                        }),
                        borderColor: color(index),
                        backgroundColor: alpha(color(index), 0.15),
                        borderWidth: 2,
                        pointRadius: 3,
                        rawRow: row
                    };
                })
            },
            options: baseOptions({
                interaction: { mode: 'nearest', intersect: true },
                scales: { r: { suggestedMin: 0, suggestedMax: 100, ticks: { stepSize: 25 } } },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var key = metrics[context.dataIndex];
                                var row = context.dataset.rawRow;
                                return context.dataset.label + ': ' + fmtMetric(key, row[key]) +
                                    ' (' + nf2.format(context.parsed.r) + '% din maxim)';
                            }
                        }
                    }
                }
            })
        });
    }

    function renderCompareMixChart() {
        var canvas = document.getElementById('da2-chart-compare-mix');
        destroyChart('compareMix', canvas);

        var rows = selectedRows();
        if (!rows.length) {
            drawEmpty(canvas, 'Selectează cel puțin o entitate din listă.');
            return;
        }

        var series = [
            { key: 'km_primar', label: 'Km primar', color: '#2563eb' },
            { key: 'km_distributie', label: 'Km distribuție', color: '#f97316' },
            { key: 'km_nefacturati', label: 'Km nefacturați', color: '#ef4444' }
        ];

        charts.compareMix = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(function (row) { return row.nume; }),
                datasets: series.map(function (item) {
                    return {
                        label: item.label,
                        data: rows.map(function (row) { return num(row[item.key]); }),
                        backgroundColor: item.color,
                        borderRadius: 5
                    };
                })
            },
            options: baseOptions({
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, ticks: { callback: function (v) { return nfInt.format(v); } } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + fmt(context.parsed.y, 'km');
                            }
                        }
                    }
                }
            })
        });
    }

    function renderCompareTable() {
        var table = document.getElementById('da2-compare-table');
        var rows = selectedRows();
        var metrics = activeCompareMetrics();

        if (!rows.length || !metrics.length) {
            table.innerHTML = '<tbody><tr><td class="da2-empty">Selectează entități și metrici pentru comparație.</td></tr></tbody>';
            return;
        }

        var averages = {};
        metrics.forEach(function (key) {
            averages[key] = rows.reduce(function (sum, row) { return sum + num(row[key]); }, 0) / rows.length;
        });

        var head = '<thead><tr><th class="da2-sticky-col">Metrică</th>' +
            rows.map(function (row) { return '<th class="da2-num">' + escapeHtml(row.nume) + '</th>'; }).join('') +
            '<th class="da2-num da2-col-avg">Media selecției</th></tr></thead>';

        var body = '<tbody>' + metrics.map(function (key) {
            var better = (METRICS[key] && METRICS[key].better) || 'high';
            return '<tr><th class="da2-sticky-col">' + escapeHtml(metricLabel(key)) + '</th>' +
                rows.map(function (row) {
                    var value = num(row[key]);
                    var delta = value - averages[key];
                    var good = better === 'high' ? delta > 0 : delta < 0;
                    var deltaClass = Math.abs(delta) < 0.005 ? 'da2-delta-flat' : (good ? 'da2-delta-up' : 'da2-delta-down');
                    var sign = delta > 0 ? '+' : '';
                    return '<td class="da2-num"><span class="da2-cell-value">' + escapeHtml(fmtMetric(key, value)) + '</span>' +
                        '<span class="da2-delta ' + deltaClass + '">' + sign + escapeHtml(fmtMetric(key, delta)) + '</span></td>';
                }).join('') +
                '<td class="da2-num da2-col-avg">' + escapeHtml(fmtMetric(key, averages[key])) + '</td></tr>';
        }).join('') + '</tbody>';

        table.innerHTML = head + body;
    }

    // ----------------------------------------------------------- raport sumar

    function renderSummary() {
        var summary = state.data.summary || {};
        renderSummaryHead(summary);
        renderSummaryTransport(summary);
        renderSummaryMatrix(summary);
        renderSummaryClients(summary);
    }

    function renderSummaryHead(summary) {
        var media = summary.media_client || {};
        var cards = [
            { name: 'Clienți activi', value: fmt(media.nr_clienti, 'int'), note: 'beneficiari cu cel puțin o cursă' },
            { name: 'Media km / client', value: fmt(media.km, 'km'), note: 'total km ÷ număr clienți' },
            { name: 'Media tone / client', value: fmt(media.tone, 'tone'), note: 'total tone ÷ număr clienți' },
            { name: 'Media curse / client', value: fmt(media.curse, 'num'), note: 'total curse ÷ număr clienți' },
            { name: 'Media facturare / client', value: fmt(media.facturare, 'lei'), note: 'total facturare ÷ număr clienți' },
            { name: 'Media profit / client', value: fmt(media.profit, 'lei'), note: 'total profit ÷ număr clienți' }
        ];

        document.getElementById('da2-report-head').innerHTML = cards.map(function (card) {
            return '<div class="da2-report-kpi"><span class="da2-report-kpi-name">' + escapeHtml(card.name) + '</span>' +
                '<span class="da2-report-kpi-value">' + escapeHtml(card.value) + '</span>' +
                '<span class="da2-report-kpi-note">' + escapeHtml(card.note) + '</span></div>';
        }).join('');
    }

    function renderSummaryTransport(summary) {
        var rows = summary.transport || [];
        var table = document.getElementById('da2-summary-transport');

        if (!rows.length) {
            table.innerHTML = '<tbody><tr><td class="da2-empty">Nu există date în perioada selectată.</td></tr></tbody>';
            return;
        }

        var columns = [
            { key: 'label', label: 'Tip transport', text: true },
            { key: 'curse', label: 'Curse', kind: 'int' },
            { key: 'nr_clienti', label: 'Clienți', kind: 'int' },
            { key: 'km', label: 'Km total', kind: 'km' },
            { key: 'tone', label: 'Tone total', kind: 'tone' },
            { key: 'km_per_cursa', label: 'Km / cursă', kind: 'num', highlight: true },
            { key: 'tone_per_cursa', label: 'Tone / cursă', kind: 'num', highlight: true },
            { key: 'km_per_client', label: 'Km / client', kind: 'km', highlight: true },
            { key: 'tone_per_client', label: 'Tone / client', kind: 'tone', highlight: true },
            { key: 'curse_per_client', label: 'Curse / client', kind: 'num' },
            { key: 'puncte_client', label: 'Puncte livrate', kind: 'int' },
            { key: 'km_per_punct', label: 'Km / punct', kind: 'num' },
            { key: 'tone_per_punct', label: 'Tone / punct', kind: 'num' },
            { key: 'grad_incarcare', label: 'Grad încărcare', kind: 'pct' },
            { key: 'facturare', label: 'Facturare', kind: 'lei' },
            { key: 'profit', label: 'Profit', kind: 'lei', tone: true }
        ];

        var totals = { label: 'TOTAL' };
        ['curse', 'km', 'tone', 'puncte_client', 'facturare', 'profit'].forEach(function (key) {
            totals[key] = rows.reduce(function (sum, row) { return sum + num(row[key]); }, 0);
        });
        totals.km_per_cursa = totals.curse > 0 ? totals.km / totals.curse : 0;
        totals.tone_per_cursa = totals.curse > 0 ? totals.tone / totals.curse : 0;
        totals.km_per_punct = totals.puncte_client > 0 ? totals.km / totals.puncte_client : 0;
        totals.tone_per_punct = totals.puncte_client > 0 ? totals.tone / totals.puncte_client : 0;

        var mediaClient = (state.data.summary || {}).media_client || {};
        totals.nr_clienti = num(mediaClient.nr_clienti);
        totals.km_per_client = totals.nr_clienti > 0 ? totals.km / totals.nr_clienti : 0;
        totals.tone_per_client = totals.nr_clienti > 0 ? totals.tone / totals.nr_clienti : 0;
        totals.curse_per_client = totals.nr_clienti > 0 ? totals.curse / totals.nr_clienti : 0;
        totals.grad_incarcare = num((state.data.fleet || {}).grad_incarcare);

        table.innerHTML =
            '<thead><tr>' + columns.map(function (col) {
                return '<th' + (col.text ? ' class="da2-sticky-col"' : ' class="da2-num' + (col.highlight ? ' da2-col-key' : '') + '"') + '>' +
                    escapeHtml(col.label) + '</th>';
            }).join('') + '</tr></thead>' +
            '<tbody>' + rows.map(function (row) {
                return '<tr>' + columns.map(function (col) {
                    return renderSummaryCell(row, col);
                }).join('') + '</tr>';
            }).join('') + '</tbody>' +
            '<tfoot><tr>' + columns.map(function (col) {
                return renderSummaryCell(totals, col, true);
            }).join('') + '</tr></tfoot>';
    }

    function renderSummaryCell(row, col, isFoot) {
        if (col.text) {
            return '<th class="da2-sticky-col">' + escapeHtml(row[col.key]) + '</th>';
        }
        var value = num(row[col.key]);
        var classes = 'da2-num' + (col.highlight ? ' da2-col-key' : '');
        if (col.tone) {
            classes += value < 0 ? ' da2-neg' : ' da2-pos';
        }
        return '<td class="' + classes + (isFoot ? ' da2-foot' : '') + '">' + escapeHtml(fmt(value, col.kind)) + '</td>';
    }

    function renderSummaryMatrix(summary) {
        var table = document.getElementById('da2-summary-matrix');
        var clients = summary.clients || [];
        var bucketLabels = summary.bucket_labels || {};
        var buckets = (summary.transport || []).map(function (row) { return row.key; });

        if (!clients.length || !buckets.length) {
            table.innerHTML = '<tbody><tr><td class="da2-empty">Nu există date în perioada selectată.</td></tr></tbody>';
            return;
        }

        var metric = state.matrixMetric;
        var kinds = { km: 'km', tone: 'tone', km_per_cursa: 'num', tone_per_cursa: 'num', curse: 'int' };
        var kind = kinds[metric] || 'num';
        var averages = summary.media_client_per_transport || {};

        var head = '<thead><tr><th class="da2-sticky-col">Client</th>' +
            buckets.map(function (bucket) {
                return '<th class="da2-num">' + escapeHtml(bucketLabels[bucket] || bucket) + '</th>';
            }).join('') +
            '<th class="da2-num da2-col-key">Total</th></tr></thead>';

        // valoarea maxima per coloana, pentru heatmap
        var maxima = {};
        buckets.forEach(function (bucket) {
            maxima[bucket] = clients.reduce(function (max, client) {
                var cell = client.buckets[bucket];
                return Math.max(max, cell ? num(cell[metric]) : 0);
            }, 0) || 1;
        });

        var body = '<tbody>' + clients.map(function (client) {
            var total = client.total || {};
            var totalValue = metric === 'km_per_cursa' ? num(total.km_per_cursa)
                : metric === 'tone_per_cursa' ? num(total.tone_per_cursa)
                    : num(total[metric]);

            return '<tr><th class="da2-sticky-col">' + escapeHtml(client.nume) + '</th>' +
                buckets.map(function (bucket) {
                    var cell = client.buckets[bucket];
                    if (!cell) {
                        return '<td class="da2-num da2-cell-empty">–</td>';
                    }
                    var value = num(cell[metric]);
                    var intensity = Math.min(1, value / maxima[bucket]);
                    return '<td class="da2-num da2-heat" style="--heat:' + intensity.toFixed(3) + '" title="' +
                        escapeHtml(client.nume + ' · ' + (bucketLabels[bucket] || bucket) + ': ' +
                            fmt(cell.curse, 'int') + ' curse, ' + fmt(cell.km, 'km') + ', ' + fmt(cell.tone, 'tone')) + '">' +
                        escapeHtml(fmt(value, kind)) + '</td>';
                }).join('') +
                '<td class="da2-num da2-col-key">' + escapeHtml(fmt(totalValue, kind)) + '</td></tr>';
        }).join('') + '</tbody>';

        var mediaRow = '<tfoot><tr><th class="da2-sticky-col">Media pe client</th>' +
            buckets.map(function (bucket) {
                var avg = averages[bucket] || {};
                var value = metric === 'curse' ? num(avg.curse)
                    : metric === 'km' ? num(avg.km)
                        : metric === 'tone' ? num(avg.tone)
                            : num(avg[metric]);
                return '<td class="da2-num da2-foot">' + escapeHtml(fmt(value, kind)) +
                    '<span class="da2-foot-note">' + fmt(avg.nr_clienti, 'int') + ' clienți</span></td>';
            }).join('') +
            (function () {
                var media = summary.media_client || {};
                var value = metric === 'curse' ? num(media.curse)
                    : metric === 'km' ? num(media.km)
                        : metric === 'tone' ? num(media.tone)
                            : num(media[metric]);
                return '<td class="da2-num da2-foot da2-col-key">' + escapeHtml(fmt(value, kind)) + '</td>';
            })() +
            '</tr></tfoot>';

        table.innerHTML = head + body + mediaRow;
    }

    function renderSummaryClients(summary) {
        var table = document.getElementById('da2-summary-clients');
        var clients = summary.clients || [];

        if (!clients.length) {
            table.innerHTML = '<tbody><tr><td class="da2-empty">Nu există date în perioada selectată.</td></tr></tbody>';
            return;
        }

        var columns = [
            { key: 'curse', label: 'Curse', kind: 'int' },
            { key: 'km', label: 'Km', kind: 'km' },
            { key: 'tone', label: 'Tone', kind: 'tone' },
            { key: 'km_per_cursa', label: 'Km / cursă', kind: 'num', highlight: true },
            { key: 'tone_per_cursa', label: 'Tone / cursă', kind: 'num', highlight: true },
            { key: 'puncte_client', label: 'Puncte livrate', kind: 'int' },
            { key: 'km_per_punct', label: 'Km / punct', kind: 'num' },
            { key: 'tone_per_punct', label: 'Tone / punct', kind: 'num' },
            { key: 'nr_tipuri', label: 'Tipuri transport', kind: 'int' },
            { key: 'facturare', label: 'Facturare', kind: 'lei' },
            { key: 'profit', label: 'Profit', kind: 'lei', tone: true }
        ];

        var media = summary.media_client || {};

        table.innerHTML =
            '<thead><tr><th class="da2-sticky-col">Client</th>' +
            columns.map(function (col) {
                return '<th class="da2-num' + (col.highlight ? ' da2-col-key' : '') + '">' + escapeHtml(col.label) + '</th>';
            }).join('') + '</tr></thead>' +
            '<tbody>' + clients.map(function (client) {
                return '<tr><th class="da2-sticky-col">' + escapeHtml(client.nume) + '</th>' +
                    columns.map(function (col) {
                        return renderSummaryCell(client.total, col);
                    }).join('') + '</tr>';
            }).join('') + '</tbody>' +
            '<tfoot><tr><th class="da2-sticky-col">Media pe client</th>' +
            columns.map(function (col) {
                var value = media[col.key];
                if (value === undefined) {
                    var sum = clients.reduce(function (total, client) { return total + num(client.total[col.key]); }, 0);
                    value = clients.length > 0 ? sum / clients.length : 0;
                }
                return '<td class="da2-num da2-foot' + (col.highlight ? ' da2-col-key' : '') + '">' +
                    escapeHtml(fmt(value, col.kind)) + '</td>';
            }).join('') + '</tr></tfoot>';
    }

    // -------------------------------------------------------- tabele entitati

    var TABLE_COLUMNS = {
        vehicles: [
            { key: 'nume', label: 'Vehicul', text: true },
            { key: 'curse', kind: 'int' },
            { key: 'km_totali', kind: 'km' },
            { key: 'km_nefacturati_percent', kind: 'pct', tone: 'low' },
            { key: 'tone_livrate', kind: 'tone' },
            { key: 'facturare', kind: 'lei' },
            { key: 'refacturare', kind: 'lei' },
            { key: 'cheltuieli', kind: 'lei' },
            { key: 'profit', kind: 'lei', tone: 'sign' },
            { key: 'marja_percent', kind: 'pct', tone: 'sign' },
            { key: 'venit_km', kind: 'lei3' },
            { key: 'cost_km', kind: 'lei3' },
            { key: 'profit_km', kind: 'lei3', tone: 'sign' },
            { key: 'km_per_cursa', kind: 'num' },
            { key: 'tone_per_cursa', kind: 'num' },
            { key: 'grad_incarcare', kind: 'pct', meter: true, sub: 'capacitate' },
            { key: 'grad_folosinta', kind: 'pct', meter: true, sub: 'zile' }
        ],
        drivers: [
            { key: 'nume', label: 'Șofer', text: true },
            { key: 'curse', kind: 'int' },
            { key: 'km_totali', kind: 'km' },
            { key: 'tone_livrate', kind: 'tone' },
            { key: 'facturare', kind: 'lei' },
            { key: 'refacturare', kind: 'lei' },
            { key: 'cheltuieli', kind: 'lei' },
            { key: 'profit', kind: 'lei', tone: 'sign' },
            { key: 'profit_km', kind: 'lei3', tone: 'sign' },
            { key: 'km_per_cursa', kind: 'num' },
            { key: 'tone_per_cursa', kind: 'num' },
            { key: 'grad_incarcare', kind: 'pct', meter: true, sub: 'capacitate' },
            { key: 'grad_folosinta', kind: 'pct', meter: true, sub: 'zile' },
            { key: 'nr_vehicule', label: 'Vehicule conduse', kind: 'int' }
        ],
        beneficiaries: [
            { key: 'nume', label: 'Beneficiar', text: true },
            { key: 'curse', kind: 'int' },
            { key: 'km_totali', kind: 'km' },
            { key: 'tone_livrate', kind: 'tone' },
            { key: 'facturare', kind: 'lei' },
            { key: 'refacturare', kind: 'lei' },
            { key: 'cheltuieli', kind: 'lei' },
            { key: 'profit', kind: 'lei', tone: 'sign' },
            { key: 'marja_percent', kind: 'pct', tone: 'sign' },
            { key: 'venit_km', kind: 'lei3' },
            { key: 'profit_km', kind: 'lei3', tone: 'sign' },
            { key: 'km_per_cursa', kind: 'num' },
            { key: 'tone_per_cursa', kind: 'num' },
            { key: 'puncte_client', kind: 'int' },
            { key: 'km_per_punct', kind: 'num' },
            { key: 'tone_per_punct', kind: 'num' },
            { key: 'grad_incarcare', kind: 'pct', meter: true, sub: 'capacitate' },
            { key: 'nr_vehicule', label: 'Vehicule', kind: 'int' },
            { key: 'nr_soferi', label: 'Șoferi', kind: 'int' }
        ]
    };

    function visibleRows(dimension) {
        var search = (state.tableSearch[dimension] || '').toLowerCase();
        var rows = rowsFor(dimension).filter(function (row) {
            return !search || String(row.nume).toLowerCase().indexOf(search) !== -1;
        });
        var sort = state.tableSort[dimension];
        return sortRows(rows, sort.key, sort.dir);
    }

    function renderEntityTable(dimension) {
        var table = $('[data-table="' + dimension + '"]');
        if (!table) {
            return;
        }

        var columns = TABLE_COLUMNS[dimension];
        var rows = visibleRows(dimension);
        var sort = state.tableSort[dimension];
        var selection = state.compareSelection[dimension];

        if (!rows.length) {
            table.innerHTML = '<tbody><tr><td class="da2-empty">Nu există date pentru filtrele curente.</td></tr></tbody>';
            return;
        }

        var head = '<thead><tr>' + columns.map(function (col) {
            var label = col.label || metricLabel(col.key);
            var isSorted = sort.key === col.key;
            return '<th class="' + (col.text ? 'da2-sticky-col' : 'da2-num') + (isSorted ? ' is-sorted' : '') +
                '" data-sort-key="' + col.key + '" data-sort-dim="' + dimension + '" role="button" tabindex="0">' +
                escapeHtml(label) +
                '<i class="bi ' + (isSorted ? (sort.dir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-arrow-down-up') + '"></i></th>';
        }).join('') + '</tr></thead>';

        var totals = computeTotals(dimension, rows);

        var body = '<tbody>' + rows.map(function (row) {
            var isSelected = selection.indexOf(row.nume) !== -1;
            return '<tr class="' + (isSelected ? 'is-selected' : '') + '" data-row-dim="' + dimension +
                '" data-row-name="' + escapeHtml(row.nume) + '">' +
                columns.map(function (col) { return renderEntityCell(row, col); }).join('') + '</tr>';
        }).join('') + '</tbody>';

        var foot = '<tfoot><tr>' + columns.map(function (col) {
            if (col.text) {
                return '<th class="da2-sticky-col">Total / medie (' + rows.length + ')</th>';
            }
            return '<td class="da2-num da2-foot">' + escapeHtml(fmt(totals[col.key], col.kind)) + '</td>';
        }).join('') + '</tr></tfoot>';

        table.innerHTML = head + body + foot;
    }

    /** Sumele se aduna, ratele se recalculeaza din sume (nu media mediilor). */
    function computeTotals(dimension, rows) {
        var totals = {};
        var sumKeys = [
            'curse', 'km_totali', 'km_facturati', 'km_nefacturati', 'km_primar', 'km_distributie',
            'tone_livrate', 'facturare', 'refacturare', 'cheltuieli', 'profit', 'puncte_client',
            'zile_active', 'nr_vehicule', 'nr_soferi'
        ];

        sumKeys.forEach(function (key) {
            totals[key] = rows.reduce(function (sum, row) { return sum + num(row[key]); }, 0);
        });

        var kmBase = totals.km_facturati > 0 ? totals.km_facturati : totals.km_totali;
        totals.venit_km = kmBase > 0 ? totals.facturare / kmBase : 0;
        totals.cost_km = kmBase > 0 ? totals.cheltuieli / kmBase : 0;
        totals.profit_km = kmBase > 0 ? totals.profit / kmBase : 0;
        totals.marja_percent = totals.facturare > 0 ? (totals.profit / totals.facturare) * 100 : 0;
        totals.km_per_cursa = totals.curse > 0 ? totals.km_totali / totals.curse : 0;
        totals.tone_per_cursa = totals.curse > 0 ? totals.tone_livrate / totals.curse : 0;
        totals.km_nefacturati_percent = totals.km_totali > 0 ? (totals.km_nefacturati / totals.km_totali) * 100 : 0;
        totals.km_per_punct = totals.puncte_client > 0 ? totals.km_totali / totals.puncte_client : 0;
        totals.tone_per_punct = totals.puncte_client > 0 ? totals.tone_livrate / totals.puncte_client : 0;

        // Mediile procentuale raman medii aritmetice pe entitati.
        ['grad_incarcare', 'grad_folosinta'].forEach(function (key) {
            var relevant = rows.filter(function (row) { return row[key] !== undefined; });
            totals[key] = relevant.length
                ? relevant.reduce(function (sum, row) { return sum + num(row[key]); }, 0) / relevant.length
                : 0;
        });

        return totals;
    }

    function renderEntityCell(row, col) {
        if (col.text) {
            return '<th class="da2-sticky-col">' +
                '<button type="button" class="da2-row-compare" data-row-compare title="Adaugă în comparație">' +
                    '<i class="bi bi-bar-chart-steps"></i></button>' +
                '<span class="da2-row-name">' + escapeHtml(row[col.key]) + '</span>' +
                '<i class="bi bi-chevron-right da2-row-open" title="Deschide detaliul"></i>' +
                '</th>';
        }

        var value = num(row[col.key]);
        var classes = 'da2-num';
        if (col.tone === 'sign') {
            classes += value < 0 ? ' da2-neg' : ' da2-pos';
        } else if (col.tone === 'low' && value > 20) {
            classes += ' da2-neg';
        }

        if (col.meter) {
            var sub = '';
            if (col.sub === 'zile' && row.zile_disponibile) {
                sub = '<span class="da2-cell-sub">' + num(row.zile_active) + '/' + num(row.zile_disponibile) + ' zile</span>';
            }
            if (col.sub === 'capacitate') {
                var withCapacity = num(row.curse_cu_capacitate);
                if (withCapacity === 0) {
                    return '<td class="da2-num da2-cell-empty" title="Nicio cursă nu are capacitate de transport configurată">fără capacitate</td>';
                }
                sub = '<span class="da2-cell-sub">efectiv ' + fmt(row.grad_incarcare_efectiv, 'pct') +
                    ' · ' + withCapacity + '/' + num(row.curse) + ' curse</span>';
            }
            return '<td class="' + classes + '"><span class="da2-cell-meter">' +
                '<span class="da2-cell-value">' + escapeHtml(fmt(value, col.kind)) + '</span>' +
                meterHtml(value, toneForPercent(value)) + sub + '</span></td>';
        }

        return '<td class="' + classes + '">' + escapeHtml(fmt(value, col.kind)) + '</td>';
    }

    // ------------------------------------------------- detaliu entitate (drawer)

    var ENTITY_TYPE_BY_DIMENSION = { vehicles: 'vehicul', drivers: 'sofer', beneficiaries: 'beneficiar' };

    function openEntityDrawer(dimension, name) {
        var row = rowsFor(dimension).filter(function (item) { return item.nume === name; })[0];
        if (!row) {
            return;
        }

        state.drawer = {
            dimension: dimension,
            name: name,
            type: ENTITY_TYPE_BY_DIMENSION[dimension],
            row: row,
            data: null,
            loading: true,
            error: '',
            tab: 'sumar'
        };

        var drawer = document.getElementById('da2-drawer');
        drawer.hidden = false;
        document.body.classList.add('da2-no-scroll');
        window.requestAnimationFrame(function () { drawer.classList.add('is-open'); });
        renderDrawer();

        if (drawerController) {
            drawerController.abort();
        }
        drawerController = new AbortController();

        var params = collectFilters();
        params.set('page', 'dashboard_analytic_v2_entity');
        params.set('entity_type', state.drawer.type);
        params.set('entity_id', String(row.id || 0));

        var url = new URL(config.entityEndpoint || window.location.pathname, window.location.origin);
        url.search = params.toString();

        fetch(url.toString(), {
            signal: drawerController.signal,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (payload) {
            if (!state.drawer || state.drawer.name !== name) {
                return;
            }
            state.drawer.loading = false;
            if (payload.error) {
                state.drawer.error = payload.error;
            } else {
                state.drawer.data = payload;
            }
            renderDrawer();
        }).catch(function (error) {
            if (error.name === 'AbortError' || !state.drawer) {
                return;
            }
            state.drawer.loading = false;
            state.drawer.error = 'Nu s-au putut încărca detaliile: ' + error.message;
            renderDrawer();
        });
    }

    function closeEntityDrawer() {
        var drawer = document.getElementById('da2-drawer');
        if (!drawer || drawer.hidden) {
            return;
        }

        drawer.classList.remove('is-open');
        document.body.classList.remove('da2-no-scroll');
        destroyChart('drawer');
        state.drawer = null;
        window.setTimeout(function () {
            if (!state.drawer) {
                drawer.hidden = true;
                document.getElementById('da2-drawer-content').innerHTML = '';
            }
        }, 220);
    }

    /** Poziția entității în flotă pe câteva metrici cheie. */
    function entityRanks(dimension, row) {
        return ['profit', 'km_totali', 'tone_livrate', 'grad_incarcare'].map(function (key) {
            var ordered = sortRows(rowsFor(dimension), key, 'desc');
            var position = 0;
            ordered.forEach(function (item, index) {
                if (item.nume === row.nume) {
                    position = index + 1;
                }
            });
            return { key: key, label: metricLabel(key), position: position, total: ordered.length };
        }).filter(function (rank) {
            return rank.position > 0;
        });
    }

    function drawerStatTiles(entity) {
        return [
            { label: 'Curse', value: fmt(entity.curse, 'int') },
            { label: 'Km totali', value: fmt(entity.km_totali, 'km') },
            { label: 'Tone livrate', value: fmt(entity.tone_livrate, 'tone') },
            { label: 'Facturare', value: fmt(entity.facturare, 'lei') },
            { label: 'Cheltuieli', value: fmt(entity.cheltuieli, 'lei') },
            { label: 'Profit', value: fmt(entity.profit, 'lei'), tone: num(entity.profit) < 0 ? 'bad' : 'good' },
            { label: 'Marjă', value: fmt(entity.marja_percent, 'pct') },
            { label: 'Profit / km', value: fmt(entity.profit_km, 'lei3') },
            { label: 'Venit / km', value: fmt(entity.venit_km, 'lei3') },
            { label: 'Cost / km', value: fmt(entity.cost_km, 'lei3') },
            { label: 'Km / cursă', value: fmt(entity.km_per_cursa, 'num') },
            { label: 'Tone / cursă', value: fmt(entity.tone_per_cursa, 'num') },
            { label: 'Km nefacturați', value: fmt(entity.km_nefacturati, 'km') + ' (' + fmt(entity.km_nefacturati_percent, 'pct') + ')' },
            { label: 'Grad de încărcare', value: fmt(entity.grad_incarcare, 'pct'), meter: num(entity.grad_incarcare) },
            { label: 'Grad de folosință', value: fmt(entity.grad_folosinta, 'pct'), meter: num(entity.grad_folosinta) },
            { label: 'Zile active', value: fmt(entity.zile_active, 'int') + ' / ' + fmt(entity.zile_disponibile, 'int') },
            { label: 'Refacturare de încasat', value: fmt(entity.refacturare, 'lei') },
            { label: 'Puncte client livrate', value: fmt(entity.puncte_client, 'int') }
        ];
    }

    /** Tabel compact cu bare proporționale, folosit pentru defalcările din drawer. */
    function breakdownTable(rows, nameLabel) {
        if (!rows.length) {
            return '<p class="da2-empty">Nu există date.</p>';
        }

        var maxKm = rows.reduce(function (max, row) { return Math.max(max, num(row.km_totali)); }, 0) || 1;

        return '<table class="da2-table da2-table-compact">' +
            '<thead><tr>' +
            '<th class="da2-sticky-col">' + escapeHtml(nameLabel) + '</th>' +
            '<th class="da2-num">Curse</th><th class="da2-num">Km</th><th></th>' +
            '<th class="da2-num">Tone</th><th class="da2-num">Facturare</th><th class="da2-num">Profit</th>' +
            '<th class="da2-num">Km / cursă</th><th class="da2-num">Încărcare</th>' +
            '</tr></thead><tbody>' +
            rows.map(function (row) {
                var share = (num(row.km_totali) / maxKm) * 100;
                return '<tr>' +
                    '<th class="da2-sticky-col">' + escapeHtml(row.nume) + '</th>' +
                    '<td class="da2-num">' + fmt(row.curse, 'int') + '</td>' +
                    '<td class="da2-num">' + fmt(row.km_totali, 'km') + '</td>' +
                    '<td class="da2-bar-cell"><span class="da2-detail-row-bar"><span style="width:' + share.toFixed(1) + '%"></span></span></td>' +
                    '<td class="da2-num">' + fmt(row.tone_livrate, 'tone') + '</td>' +
                    '<td class="da2-num">' + fmt(row.facturare, 'lei') + '</td>' +
                    '<td class="da2-num ' + (num(row.profit) < 0 ? 'da2-neg' : 'da2-pos') + '">' + fmt(row.profit, 'lei') + '</td>' +
                    '<td class="da2-num">' + fmt(row.km_per_cursa, 'num') + '</td>' +
                    '<td class="da2-num">' + fmt(row.grad_incarcare, 'pct') + '</td>' +
                    '</tr>';
            }).join('') +
            '</tbody></table>';
    }

    function tripsTable(trips, type) {
        if (!trips.length) {
            return '<p class="da2-empty">Nu există curse în perioada selectată.</p>';
        }

        // nu repetăm coloana entității pe care tocmai am deschis-o
        var columns = [
            { key: 'data', label: 'Data' },
            { key: 'tip_label', label: 'Tip' },
            { key: 'vehicul', label: 'Vehicul', skip: type === 'vehicul' },
            { key: 'sofer', label: 'Șofer', skip: type === 'sofer' },
            { key: 'beneficiar', label: 'Beneficiar', skip: type === 'beneficiar' },
            { key: 'ruta', label: 'Rută' },
            { key: 'km', label: 'Km', kind: 'km' },
            { key: 'km_nefacturati', label: 'Km nefact.', kind: 'km' },
            { key: 'tone', label: 'Tone', kind: 'tone' },
            { key: 'grad_incarcare', label: 'Încărcare', kind: 'pct' },
            { key: 'nr_clienti', label: 'Puncte', kind: 'int' },
            { key: 'facturare', label: 'Facturare', kind: 'lei' },
            { key: 'cheltuieli', label: 'Cheltuieli', kind: 'lei' },
            { key: 'profit', label: 'Profit', kind: 'lei', tone: true },
            { key: 'status_label', label: 'Status' }
        ].filter(function (col) { return !col.skip; });

        return '<table class="da2-table da2-table-compact">' +
            '<thead><tr>' + columns.map(function (col) {
                return '<th class="' + (col.kind ? 'da2-num' : '') + '">' + escapeHtml(col.label) + '</th>';
            }).join('') + '</tr></thead>' +
            '<tbody>' + trips.map(function (trip) {
                return '<tr>' + columns.map(function (col) {
                    if (col.key === 'data') {
                        return '<td>' + escapeHtml(fmtDateRo(trip.data)) + '</td>';
                    }
                    if (col.key === 'status_label') {
                        return '<td><span class="da2-status da2-status-' + escapeHtml(trip.status) + '">' +
                            escapeHtml(trip.status_label || '-') + '</span></td>';
                    }
                    if (!col.kind) {
                        return '<td class="da2-cell-text">' + escapeHtml(trip[col.key] || '-') + '</td>';
                    }
                    if (col.key === 'grad_incarcare' && trip.grad_incarcare === null) {
                        return '<td class="da2-num da2-cell-empty">–</td>';
                    }
                    var classes = 'da2-num' + (col.tone ? (num(trip[col.key]) < 0 ? ' da2-neg' : ' da2-pos') : '');
                    return '<td class="' + classes + '">' + escapeHtml(fmt(trip[col.key], col.kind)) + '</td>';
                }).join('') + '</tr>';
            }).join('') + '</tbody></table>';
    }

    function renderDrawer() {
        var container = document.getElementById('da2-drawer-content');
        var drawer = state.drawer;
        if (!container || !drawer) {
            return;
        }

        var kicker = { vehicul: 'Vehicul', sofer: 'Șofer', beneficiar: 'Beneficiar' }[drawer.type];
        var head =
            '<header class="da2-drawer-head">' +
                '<div>' +
                    '<span class="da2-drawer-kicker">' + escapeHtml(kicker) + '</span>' +
                    '<h2 id="da2-drawer-title">' + escapeHtml(drawer.name) + '</h2>' +
                '</div>' +
                '<button type="button" class="da2-detail-close" data-drawer-close aria-label="Închide detaliul">' +
                    '<i class="bi bi-x-lg"></i></button>' +
            '</header>';

        if (drawer.loading) {
            container.innerHTML = head +
                '<div class="da2-loading"><span class="da2-spinner"></span><span>Se încarcă detaliile…</span></div>';
            return;
        }

        if (drawer.error) {
            container.innerHTML = head + '<div class="da2-alert da2-alert-error">' + escapeHtml(drawer.error) + '</div>';
            return;
        }

        var data = drawer.data;
        var entity = data.entity;
        var period = data.period || {};

        var meta = escapeHtml(fmtDateRo(period.start) + ' – ' + fmtDateRo(period.end)) +
            ' · ' + fmt(entity.curse, 'int') + ' curse' +
            (entity.prima_cursa ? ' · prima ' + escapeHtml(fmtDateRo(entity.prima_cursa)) : '') +
            (entity.ultima_cursa ? ' · ultima ' + escapeHtml(fmtDateRo(entity.ultima_cursa)) : '');

        var ranks = entityRanks(drawer.dimension, drawer.row).map(function (rank) {
            var tone = rank.position <= Math.max(1, Math.ceil(rank.total / 3)) ? 'good'
                : (rank.position > rank.total - Math.ceil(rank.total / 3) ? 'bad' : 'mid');
            return '<span class="da2-rank da2-rank-' + tone + '">' + escapeHtml(rank.label) +
                ': <strong>locul ' + rank.position + '</strong> din ' + rank.total + '</span>';
        }).join('');

        var tiles = drawerStatTiles(entity).map(function (tile) {
            return '<div class="da2-drawer-tile' + (tile.tone ? ' da2-tile-' + tile.tone : '') + '">' +
                '<span>' + escapeHtml(tile.label) + '</span>' +
                '<strong>' + escapeHtml(tile.value) + '</strong>' +
                (tile.meter !== undefined ? meterHtml(tile.meter, toneForPercent(tile.meter)) : '') +
                '</div>';
        }).join('');

        var partners = data.by_partner.map(function (group) {
            return '<section class="da2-drawer-section">' +
                '<h3>Defalcare pe ' + escapeHtml(group.label.toLowerCase()) + ' (' + group.rows.length + ')</h3>' +
                '<div class="da2-table-wrap">' + breakdownTable(group.rows, group.label) + '</div>' +
                '</section>';
        }).join('');

        container.innerHTML = head +
            '<p class="da2-drawer-meta">' + meta + '</p>' +
            (ranks ? '<div class="da2-ranks">' + ranks + '</div>' : '') +
            '<div class="da2-drawer-body">' +
                '<section class="da2-drawer-section"><h3>Indicatori</h3>' +
                    '<div class="da2-drawer-tiles">' + tiles + '</div></section>' +
                '<section class="da2-drawer-section"><h3>Evoluție zilnică</h3>' +
                    '<div class="da2-chart"><canvas id="da2-drawer-chart"></canvas></div></section>' +
                '<section class="da2-drawer-section"><h3>Defalcare pe tip de transport</h3>' +
                    '<div class="da2-table-wrap">' + breakdownTable(data.by_transport, 'Tip transport') + '</div></section>' +
                partners +
                '<section class="da2-drawer-section">' +
                    '<h3>Curse (' + data.trips.length + (data.trips.length >= 500 ? ', primele 500' : '') + ')</h3>' +
                    '<div class="da2-table-wrap da2-trips-wrap">' + tripsTable(data.trips, drawer.type) + '</div></section>' +
            '</div>' +
            '<footer class="da2-drawer-actions">' +
                '<button type="button" class="da2-btn da2-btn-sm" data-drawer-action="compare">' +
                    '<i class="bi bi-bar-chart-steps"></i><span>Adaugă în comparație</span></button>' +
                '<button type="button" class="da2-btn da2-btn-sm" data-drawer-action="filter">' +
                    '<i class="bi bi-funnel"></i><span>Filtrează pagina pe ' + escapeHtml(drawer.name) + '</span></button>' +
                '<button type="button" class="da2-btn da2-btn-sm" data-drawer-action="export">' +
                    '<i class="bi bi-filetype-csv"></i><span>Export curse</span></button>' +
                '<button type="button" class="da2-btn da2-btn-sm da2-btn-ghost" data-drawer-close>' +
                    '<span>Închide</span></button>' +
            '</footer>';

        renderDrawerChart(data.daily);

        var closeButton = container.querySelector('[data-drawer-close]');
        if (closeButton) {
            closeButton.focus();
        }
    }

    function renderDrawerChart(daily) {
        var canvas = document.getElementById('da2-drawer-chart');
        if (!canvas) {
            return;
        }

        destroyChart('drawer', canvas);

        if (!daily || !daily.labels.length) {
            drawEmpty(canvas, 'Nu există curse în perioada selectată.');
            return;
        }

        charts.drawer = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: daily.labels.map(fmtDateRo),
                datasets: [
                    { type: 'bar', label: 'Km', data: daily.km, backgroundColor: alpha('#a855f7', 0.65), yAxisID: 'y2', borderRadius: 4 },
                    { type: 'line', label: 'Facturare', data: daily.facturare, borderColor: '#2563eb', backgroundColor: alpha('#2563eb', 0.1), tension: .3, yAxisID: 'y', pointRadius: 3 },
                    { type: 'line', label: 'Profit', data: daily.profit, borderColor: '#10b981', backgroundColor: alpha('#10b981', 0.1), tension: .3, yAxisID: 'y', pointRadius: 3 }
                ]
            },
            options: baseOptions({
                scales: {
                    y: { position: 'left', title: { display: true, text: 'lei' }, ticks: { callback: function (v) { return nfInt.format(v); } } },
                    y2: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'km' }, ticks: { callback: function (v) { return nfInt.format(v); } } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var kind = context.dataset.label === 'Km' ? 'km' : 'lei';
                                return context.dataset.label + ': ' + fmt(context.parsed.y, kind);
                            }
                        }
                    }
                }
            })
        });
    }

    function runDrawerAction(action) {
        var drawer = state.drawer;
        if (!drawer) {
            return;
        }

        if (action === 'compare') {
            var list = state.compareSelection[drawer.dimension];
            if (list.indexOf(drawer.name) === -1) {
                list.push(drawer.name);
            }
            state.compareDimension = drawer.dimension;
            syncSegments();
            renderCompare();
            renderEntityTable(drawer.dimension);
            closeEntityDrawer();
            activateTabByName('comparatie');
            return;
        }

        if (action === 'filter') {
            var fieldByType = { vehicul: 'vehicle_ids', sofer: 'driver_ids', beneficiar: 'beneficiary_ids' };
            var ms = $('[data-ms][data-name="' + fieldByType[drawer.type] + '"]', form);
            if (ms) {
                $$('input[type="checkbox"]', ms).forEach(function (input) {
                    input.checked = String(input.value) === String(drawer.row.id);
                });
                closeEntityDrawer();
                scheduleReload(0);
            }
            return;
        }

        if (action === 'export' && drawer.data) {
            var trips = drawer.data.trips;
            var lines = [['Data', 'Tip', 'Vehicul', 'Șofer', 'Beneficiar', 'Rută', 'Km', 'Km nefacturați',
                'Tone', 'Grad încărcare', 'Puncte client', 'Facturare', 'Refacturare', 'Cheltuieli', 'Profit', 'Status']];

            trips.forEach(function (trip) {
                lines.push([trip.data, trip.tip_label, trip.vehicul, trip.sofer, trip.beneficiar, trip.ruta,
                    trip.km, trip.km_nefacturati, trip.tone, trip.grad_incarcare, trip.nr_clienti,
                    trip.facturare, trip.refacturare, trip.cheltuieli, trip.profit, trip.status_label]);
            });

            downloadCsv('curse_' + drawer.name.replace(/[^\wăâîșțĂÂÎȘȚ -]/gi, '').replace(/\s+/g, '_') + '.csv', lines);
        }
    }

    // ------------------------------------------------------------------ alerte

    function renderAlerts() {
        var alerts = state.data.alerts || [];
        var filtered = state.alertSeverity
            ? alerts.filter(function (alert) { return alert.severity === state.alertSeverity; })
            : alerts;

        var badge = document.getElementById('da2-alerts-count');
        badge.hidden = alerts.length === 0;
        badge.textContent = alerts.length;

        var container = document.getElementById('da2-alerts');
        if (!filtered.length) {
            container.innerHTML = '<p class="da2-empty">Nicio alertă pentru filtrele curente.</p>';
            return;
        }

        var icons = { danger: 'bi-exclamation-octagon-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        var typeLabels = { vehicul: 'Vehicul', sofer: 'Șofer', beneficiar: 'Beneficiar', flota: 'Flotă' };

        container.innerHTML = filtered.map(function (alert) {
            return '<div class="da2-alert-item da2-alert-' + escapeHtml(alert.severity) + '">' +
                '<i class="bi ' + (icons[alert.severity] || 'bi-info-circle') + '"></i>' +
                '<span class="da2-alert-target">' + escapeHtml(typeLabels[alert.type] || alert.type) + ' ' +
                escapeHtml(alert.target) + '</span>' +
                '<span class="da2-alert-msg">' + escapeHtml(alert.message) + '</span>' +
                '<span class="da2-alert-value">' + escapeHtml(nf2.format(num(alert.value)) + ' ' + (alert.unit || '')) + '</span>' +
                '</div>';
        }).join('');
    }

    // ------------------------------------------------------------------ export

    function csvEscape(value) {
        var text = String(value === null || value === undefined ? '' : value);
        return '"' + text.replace(/"/g, '""') + '"';
    }

    function downloadCsv(name, lines) {
        var content = '﻿' + lines.map(function (line) {
            return line.map(csvEscape).join(';');
        }).join('\r\n');

        var blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    }

    function exportCurrentTab() {
        if (!state.data) {
            return;
        }

        var period = state.data.period || {};
        var suffix = (period.start || '') + '_' + (period.end || '');
        var lines = [];

        if (state.tab === 'raport') {
            var summary = state.data.summary || {};
            lines.push(['Medii pe tip de transport']);
            lines.push(['Tip transport', 'Curse', 'Clienți', 'Km', 'Tone', 'Km/cursă', 'Tone/cursă', 'Km/client', 'Tone/client', 'Curse/client', 'Facturare', 'Profit']);
            (summary.transport || []).forEach(function (row) {
                lines.push([row.label, row.curse, row.nr_clienti, row.km, row.tone, row.km_per_cursa,
                    row.tone_per_cursa, row.km_per_client, row.tone_per_client, row.curse_per_client, row.facturare, row.profit]);
            });
            lines.push([]);
            lines.push(['Totaluri pe client']);
            lines.push(['Client', 'Curse', 'Km', 'Tone', 'Km/cursă', 'Tone/cursă', 'Puncte livrate', 'Facturare', 'Profit']);
            (summary.clients || []).forEach(function (client) {
                var t = client.total;
                lines.push([client.nume, t.curse, t.km, t.tone, t.km_per_cursa, t.tone_per_cursa, t.puncte_client, t.facturare, t.profit]);
            });
            var media = summary.media_client || {};
            lines.push(['MEDIA PE CLIENT', media.curse, media.km, media.tone, media.km_per_cursa, media.tone_per_cursa, '', media.facturare, media.profit]);
            downloadCsv('raport_sumar_' + suffix + '.csv', lines);
            return;
        }

        var dimensionByTab = { vehicule: 'vehicles', soferi: 'drivers', beneficiari: 'beneficiaries', comparatie: state.compareDimension };
        var dimension = dimensionByTab[state.tab] || 'vehicles';
        var columns = TABLE_COLUMNS[dimension];
        var rows = state.tab === 'comparatie' && selectedRows().length ? selectedRows() : visibleRows(dimension);

        lines.push(columns.map(function (col) { return col.label || metricLabel(col.key); }));
        rows.forEach(function (row) {
            lines.push(columns.map(function (col) {
                return col.text ? row[col.key] : num(row[col.key]);
            }));
        });

        downloadCsv(dimension + '_' + suffix + '.csv', lines);
    }

    // ------------------------------------------------------------------ tab-uri

    function activateTabByName(name) {
        state.tab = name;
        $$('.da2-tab').forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-tab') === name);
        });
        $$('.da2-panel').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-panel') === name);
        });

        // Chart.js nu recalculeaza dimensiunile cat timp canvas-ul e ascuns.
        Object.keys(charts).forEach(function (key) {
            charts[key].resize();
        });
    }

    function syncSegments() {
        $$('[data-seg]').forEach(function (seg) {
            var key = seg.getAttribute('data-seg');
            $$('button', seg).forEach(function (button) {
                button.classList.toggle('is-active', String(state[key]) === button.getAttribute('data-value'));
            });
        });
    }

    // ------------------------------------------------------------------ evenimente

    document.getElementById('da2-tabs').addEventListener('click', function (event) {
        var tab = event.target.closest('.da2-tab');
        if (tab) {
            activateTabByName(tab.getAttribute('data-tab'));
        }
    });

    root.addEventListener('click', function (event) {
        // segmente (line/bar, dimensiuni, metrici de matrice etc.)
        var segButton = event.target.closest('[data-seg] button');
        if (segButton) {
            var seg = segButton.closest('[data-seg]');
            var key = seg.getAttribute('data-seg');
            var value = segButton.getAttribute('data-value');
            state[key] = value;
            syncSegments();
            onSegmentChange(key);
            return;
        }

        if (event.target.closest('[data-detail-close]')) {
            state.kpiOpen = null;
            renderKpis();
            return;
        }

        var detailAction = event.target.closest('[data-detail-action]');
        if (detailAction) {
            runDetailAction(detailAction);
            return;
        }

        var kpi = event.target.closest('[data-kpi]');
        if (kpi) {
            var cardKey = kpi.getAttribute('data-kpi');
            state.kpiOpen = state.kpiOpen === cardKey ? null : cardKey;
            renderKpis();

            // renderKpis reconstruieste grila, deci animam cardul nou, nu pe cel apasat
            var current = $('[data-kpi="' + cardKey + '"]');
            if (current) {
                pulse(current, event);
                window.setTimeout(function () { current.classList.remove('is-pressed'); }, 500);
            }
            if (state.kpiOpen) {
                scrollDetailIntoView();
            }
            return;
        }

        var evoChip = event.target.closest('[data-evolution-metric]');
        if (evoChip) {
            var evoKey = evoChip.getAttribute('data-evolution-metric');
            state.evolutionMetrics[evoKey] = !state.evolutionMetrics[evoKey];
            renderEvolutionToggles();
            renderEvolutionChart();
            return;
        }

        var cmpChip = event.target.closest('[data-compare-metric]');
        if (cmpChip) {
            var cmpKey = cmpChip.getAttribute('data-compare-metric');
            state.compareMetrics[cmpKey] = !state.compareMetrics[cmpKey];
            renderCompare();
            return;
        }

        var sortHeader = event.target.closest('[data-sort-key]');
        if (sortHeader) {
            var dim = sortHeader.getAttribute('data-sort-dim');
            var sortKey = sortHeader.getAttribute('data-sort-key');
            var current = state.tableSort[dim];
            if (current.key === sortKey) {
                current.dir = current.dir === 'asc' ? 'desc' : 'asc';
            } else {
                current.key = sortKey;
                current.dir = sortKey === 'nume' ? 'asc' : 'desc';
            }
            renderEntityTable(dim);
            return;
        }

        var row = event.target.closest('[data-row-name]');
        if (row) {
            var rowDim = row.getAttribute('data-row-dim');
            var rowName = row.getAttribute('data-row-name');

            // Ctrl / Cmd + click sau butonul dedicat comuta selectia de comparatie,
            // click simplu deschide detaliul entitatii.
            if (event.ctrlKey || event.metaKey || event.target.closest('[data-row-compare]')) {
                toggleCompareEntity(rowDim, rowName, false);
                renderEntityTable(rowDim);
            } else {
                openEntityDrawer(rowDim, rowName);
            }
            return;
        }

        var chip = event.target.closest('[data-chip-name]');
        if (chip) {
            var ms = $('[data-ms][data-name="' + chip.getAttribute('data-chip-name') + '"]', form);
            var input = ms && $('input[value="' + chip.getAttribute('data-chip-value') + '"]', ms);
            if (input) {
                input.checked = false;
                scheduleReload(0);
            }
            return;
        }

        var preset = event.target.closest('[data-preset]');
        if (preset) {
            setPreset(preset.getAttribute('data-preset'));
        }
    });

    /** Unda pleaca din punctul apasat; la navigare cu tastatura, din centru. */
    function pulse(element, event) {
        var rect = element.getBoundingClientRect();
        var x = event && event.clientX ? ((event.clientX - rect.left) / rect.width) * 100 : 50;
        var y = event && event.clientY ? ((event.clientY - rect.top) / rect.height) * 100 : 50;

        element.style.setProperty('--x', x.toFixed(1) + '%');
        element.style.setProperty('--y', y.toFixed(1) + '%');
        element.classList.remove('is-pressed');
        void element.offsetWidth;
        element.classList.add('is-pressed');
    }

    function scrollDetailIntoView() {
        var detail = document.getElementById('da2-kpi-detail');
        if (!detail) {
            return;
        }
        window.setTimeout(function () {
            var rect = detail.getBoundingClientRect();
            if (rect.bottom > window.innerHeight) {
                detail.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }, 260);
    }

    /** Butoanele din panoul de detaliu duc utilizatorul acolo unde poate acționa. */
    function runDetailAction(button) {
        var type = button.getAttribute('data-detail-action');

        if (type === 'evolution') {
            var metric = button.getAttribute('data-metric');
            state.evolutionMetrics[metric] = true;
            renderEvolutionToggles();
            renderEvolutionChart();
            activateTabByName('general');
            return;
        }

        if (type === 'rank') {
            state.rankMetric = button.getAttribute('data-metric');
            var select = document.getElementById('da2-rank-metric');
            if (select) {
                select.value = state.rankMetric;
            }
            activateTabByName('general');
            renderRankChart();
            document.getElementById('da2-chart-rank').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (type === 'tab') {
            var dimension = button.getAttribute('data-dimension');
            var sortKey = button.getAttribute('data-sort');
            if (dimension && sortKey) {
                state.tableSort[dimension] = { key: sortKey, dir: 'desc' };
                renderEntityTable(dimension);
            }
            activateTabByName(button.getAttribute('data-target-tab'));
        }
    }

    function onSegmentChange(key) {
        if (key === 'evolutionType') {
            renderEvolutionChart();
        } else if (key === 'distributionMetric') {
            renderTransportChart();
        } else if (key === 'rankDimension') {
            renderRankChart();
        } else if (key === 'scatterDimension') {
            renderScatterChart();
        } else if (key === 'compareDimension') {
            state.compareSearch = '';
            var searchInput = document.getElementById('da2-compare-search');
            if (searchInput) {
                searchInput.value = '';
            }
            renderCompare();
        } else if (key === 'matrixMetric') {
            renderSummaryMatrix(state.data.summary || {});
        } else if (key === 'alertSeverity') {
            renderAlerts();
        }
    }

    // multi-select: deschidere / inchidere / cautare / select all
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-ms-toggle]');
        $$('[data-ms]').forEach(function (ms) {
            var panel = $('[data-ms-panel]', ms);
            var isOwner = toggle && ms.contains(toggle);
            var clickedInside = ms.contains(event.target);
            if (isOwner) {
                var willOpen = panel.hidden;
                panel.hidden = !willOpen;
                $('[data-ms-toggle]', ms).setAttribute('aria-expanded', String(willOpen));
                ms.classList.toggle('is-open', willOpen);
            } else if (!clickedInside) {
                panel.hidden = true;
                ms.classList.remove('is-open');
                $('[data-ms-toggle]', ms).setAttribute('aria-expanded', 'false');
            }
        });
    });

    form.addEventListener('input', function (event) {
        var search = event.target.closest('[data-ms-search]');
        if (search) {
            var list = $('[data-ms-list]', search.closest('[data-ms]'));
            var needle = search.value.toLowerCase();
            $$('[data-ms-option]', list).forEach(function (option) {
                option.hidden = needle !== '' && option.getAttribute('data-text').indexOf(needle) === -1;
            });
            return;
        }

        if (event.target.type === 'date') {
            scheduleReload(500);
        }
    });

    form.addEventListener('change', function (event) {
        if (event.target.type === 'checkbox') {
            updateMultiSelectSummaries();
            renderChips();
            scheduleReload(150);
        }
    });

    form.addEventListener('click', function (event) {
        var all = event.target.closest('[data-ms-all]');
        var none = event.target.closest('[data-ms-none]');
        if (!all && !none) {
            return;
        }

        var ms = event.target.closest('[data-ms]');
        $$('[data-ms-option]', ms).forEach(function (option) {
            if (!option.hidden) {
                $('input', option).checked = !!all;
            }
        });
        updateMultiSelectSummaries();
        renderChips();
        scheduleReload(0);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        load();
    });

    document.getElementById('da2-reset-btn').addEventListener('click', function () {
        window.location.href = config.resetUrl || window.location.pathname;
    });

    document.getElementById('da2-refresh-btn').addEventListener('click', function () {
        load();
    });

    document.getElementById('da2-export-btn').addEventListener('click', exportCurrentTab);

    document.getElementById('da2-print-btn').addEventListener('click', function () {
        window.print();
    });

    document.getElementById('da2-auto-refresh').addEventListener('change', function (event) {
        state.autoRefresh = event.target.checked;
        window.clearInterval(refreshTimer);
        if (state.autoRefresh) {
            refreshTimer = window.setInterval(load, 60000);
        }
    });

    document.getElementById('da2-evolution-cumulative').addEventListener('change', function (event) {
        state.evolutionCumulative = event.target.checked;
        renderEvolutionChart();
    });

    document.getElementById('da2-rank-metric').addEventListener('change', function (event) {
        state.rankMetric = event.target.value;
        renderRankChart();
    });

    document.getElementById('da2-rank-limit').addEventListener('change', function (event) {
        state.rankLimit = parseInt(event.target.value, 10) || 0;
        renderRankChart();
    });

    document.getElementById('da2-compare-search').addEventListener('input', function (event) {
        state.compareSearch = event.target.value;
        renderCompareList();
    });

    document.getElementById('da2-compare-list').addEventListener('change', function (event) {
        var input = event.target.closest('[data-compare-entity]');
        if (input) {
            toggleCompareEntity(state.compareDimension, input.getAttribute('data-compare-entity'), false);
            renderEntityTable(state.compareDimension);
        }
    });

    document.getElementById('da2-compare-top').addEventListener('click', function () {
        var top = sortRows(rowsFor(state.compareDimension), 'km_totali', 'desc').slice(0, 5);
        state.compareSelection[state.compareDimension] = top.map(function (row) { return row.nume; });
        renderCompare();
        renderEntityTable(state.compareDimension);
    });

    document.getElementById('da2-compare-clear').addEventListener('click', function () {
        state.compareSelection[state.compareDimension] = [];
        renderCompare();
        renderEntityTable(state.compareDimension);
    });

    $$('[data-table-search]').forEach(function (input) {
        input.addEventListener('input', function (event) {
            var dimension = event.target.getAttribute('data-table-search');
            state.tableSearch[dimension] = event.target.value;
            renderEntityTable(dimension);
        });
    });

    window.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (state.drawer) {
                closeEntityDrawer();
                return;
            }
            $$('[data-ms]').forEach(function (ms) {
                $('[data-ms-panel]', ms).hidden = true;
                ms.classList.remove('is-open');
            });
        }
    });

    document.getElementById('da2-drawer').addEventListener('click', function (event) {
        if (event.target.closest('[data-drawer-close]')) {
            closeEntityDrawer();
            return;
        }

        var action = event.target.closest('[data-drawer-action]');
        if (action) {
            runDrawerAction(action.getAttribute('data-drawer-action'));
        }
    });

    // -------------------------------------------------------------------- init

    syncSegments();
    updateMultiSelectSummaries();
    renderChips();
    markActivePreset();
    load();
}());
