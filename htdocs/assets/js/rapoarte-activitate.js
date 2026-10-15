/*
 * Rapoarte activitate utilizatori (?page=rapoarte_activitate).
 * Starea (filtre, tab, sortare, pagina, mod, coloane) traieste aici si in URL;
 * fiecare schimbare cere ?action=data si redeseneaza KPI-urile, graficele,
 * tab-urile si tabelul. Serverul filtreaza si pagineaza: aici ajunge doar pagina curenta.
 */
(function () {
    'use strict';

    var root = document.getElementById('arpRoot');
    var bootEl = document.getElementById('arpBoot');
    if (!root || !bootEl) {
        return;
    }

    var boot = {};
    try {
        boot = JSON.parse(bootEl.textContent || '{}');
    } catch (error) {
        boot = {};
    }

    var urls = boot.urls || {};
    var report = boot.report || null;
    var views = boot.views || [];

    var ACTIONS = [
        { key: 'create', label: 'Creare', icon: 'bi-plus-circle-fill', color: '#16a34a' },
        { key: 'update', label: 'Modificare', icon: 'bi-pencil-fill', color: '#1f6fff' },
        { key: 'delete', label: 'Ștergere', icon: 'bi-trash3-fill', color: '#ef4444' },
        { key: 'status', label: 'Status', icon: 'bi-circle-fill', color: '#f59e0b' },
        { key: 'login', label: 'Autentificare', icon: 'bi-shield-lock-fill', color: '#8b5cf6' },
        { key: 'restore', label: 'Restaurare', icon: 'bi-arrow-counterclockwise', color: '#94a3b8' }
    ];
    var ACTION = {};
    ACTIONS.forEach(function (a) { ACTION[a.key] = a; });
    var PLURAL = { create: 'Creări', update: 'Modificări', delete: 'Ștergeri', status: 'Status', login: 'Autentificări', restore: 'Restaurări' };
    var PALETTE = ['#3b82f6', '#8b5cf6', '#22c55e', '#f59e0b', '#f87171', '#94a3b8', '#06b6d4', '#ec4899', '#84cc16', '#64748b'];
    var FIELD_PALETTE = ['#3b82f6', '#8b5cf6', '#22c55e', '#f59e0b', '#ef4444', '#ef4444', '#06b6d4', '#94a3b8'];
    var HEAT = ['#f2f5f9', '#dce8ff', '#b4cdff', '#83acff', '#4f88ff', '#1f6fff'];

    var COLUMNS = [
        { key: 'date', label: 'Data', sort: 'ts' },
        { key: 'time', label: 'Ora', sort: 'ts' },
        { key: 'user', label: 'Utilizator', sort: 'user' },
        { key: 'role', label: 'Rol', sort: 'role' },
        { key: 'action', label: 'Tip acțiune', sort: 'action' },
        { key: 'module', label: 'Modul', sort: 'module', allOnly: true },
        { key: 'entity', label: 'Entitate / ID', sort: 'entity' },
        { key: 'field', label: 'Câmp modificat', sort: 'field' },
        { key: 'old', label: 'Valoare veche' },
        { key: 'new', label: 'Valoare nouă' },
        { key: 'desc', label: 'Descriere' },
        { key: 'ip', label: 'IP / Dispozitiv', sort: 'ip' }
    ];
    var DEFAULT_COLUMNS = COLUMNS.map(function (c) { return c.key; });

    var DEFAULT_FILTERS = {
        module: '', section: '', user: 0, role: '', actions: [], from: '', to: '',
        entity: '', field: '', sensitive: false, logins: true, q: '', tq: '',
        sort: 'ts', dir: 'desc', p: 1, pp: 10, group: 'none', trend: 14, heat: 'auto'
    };

    var bootState = boot.state || {};
    var state = {
        filters: Object.assign({}, DEFAULT_FILTERS, (report && report.filters) || {}),
        period: bootState.period || '30',
        mode: bootState.mode || 'table',
        columns: Array.isArray(bootState.columns) && bootState.columns.length ? bootState.columns : DEFAULT_COLUMNS.slice(),
        modulesLimit: 'all'
    };

    var charts = { donut: null, trend: null, results: null };
    var inflight = null;
    var $ = function (id) { return document.getElementById(id); };

    // ------------------------------------------------------------ utilitare

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmt(n) {
        return Number(n || 0).toLocaleString('ro-RO');
    }

    function pct(n) {
        var v = Number(n || 0);
        return (Math.round(v) === v ? v : v.toFixed(0)) + '%';
    }

    function isoDate(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    function presetRange(preset) {
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var start = new Date(today);
        var end = new Date(today);
        switch (preset) {
            case 'today': break;
            case 'yesterday': start.setDate(start.getDate() - 1); end.setDate(end.getDate() - 1); break;
            case '7': start.setDate(start.getDate() - 6); break;
            case 'month': start.setDate(1); break;
            case 'last_month': start = new Date(today.getFullYear(), today.getMonth() - 1, 1); end = new Date(today.getFullYear(), today.getMonth(), 0); break;
            case 'year': start = new Date(today.getFullYear(), 0, 1); break;
            default: start.setDate(start.getDate() - 30);
        }
        return [isoDate(start), isoDate(end)];
    }

    function shortDate(iso) {
        var months = ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Noi', 'Dec'];
        var p = String(iso).split('-');
        return p.length === 3 ? (parseInt(p[2], 10) + ' ' + months[parseInt(p[1], 10) - 1]) : iso;
    }

    function avatarClass(id) {
        return 'av-' + (Math.abs(Number(id) || 0) % 6);
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/);
        return ((parts[0] || '').charAt(0) + (parts[1] || '').charAt(0)).toUpperCase() || '?';
    }

    function toast(message, isError) {
        var el = $('arpToast');
        el.textContent = message;
        el.classList.toggle('is-error', !!isError);
        el.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(function () { el.hidden = true; }, 3800);
    }

    function query(extra) {
        var f = state.filters;
        var params = new URLSearchParams();
        var put = function (key, value, def) {
            if (value !== def && value !== '' && value !== null && value !== undefined) {
                params.set(key, value);
            }
        };
        put('module', f.module, '');
        put('section', f.section, '');
        put('user', String(f.user || 0), '0');
        put('role', f.role, '');
        if (f.actions.length) { params.set('actions', f.actions.join(',')); }
        params.set('from', f.from);
        params.set('to', f.to);
        put('entity', f.entity, '');
        put('field', f.field, '');
        if (f.sensitive) { params.set('sensitive', '1'); }
        if (!f.logins) { params.set('logins', '0'); }
        put('q', f.q, '');
        put('tq', f.tq, '');
        put('sort', f.sort, 'ts');
        put('dir', f.dir, 'desc');
        put('p', String(f.p), '1');
        put('pp', String(f.pp), '10');
        put('group', f.group, 'none');
        put('trend', String(f.trend), '14');
        put('heat', f.heat, 'auto');
        put('period', state.period, '');
        Object.keys(extra || {}).forEach(function (k) { params.set(k, extra[k]); });
        return params;
    }

    function withQuery(base, params) {
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + params.toString();
    }

    function syncUrl() {
        var params = query();
        if (state.mode !== 'table') { params.set('mode', state.mode); }
        if (state.columns.join(',') !== DEFAULT_COLUMNS.join(',')) { params.set('columns', state.columns.join(',')); }
        try {
            window.history.replaceState(null, '', withQuery(urls.page, params));
        } catch (error) { /* URL-ul e doar un confort */ }
    }

    // ------------------------------------------------------------ incarcare

    function load(resetPage) {
        if (resetPage) {
            state.filters.p = 1;
        }
        if (inflight) {
            inflight.abort();
        }
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        inflight = controller;
        root.classList.add('is-loading');
        $('arpLoading').hidden = false;

        fetch(withQuery(urls.data, query()), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        })
            .then(function (res) {
                return res.json().catch(function () { return { ok: false, message: 'Răspuns invalid de la server (' + res.status + ').' }; });
            })
            .then(function (json) {
                if (!json || !json.ok) {
                    throw new Error((json && json.message) || 'Nu am putut încărca datele.');
                }
                report = json.report;
                state.filters = Object.assign({}, state.filters, report.filters);
                render();
                syncUrl();
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') { return; }
                toast(error.message || 'Eroare la încărcarea raportului.', true);
                renderTableError(error.message || 'Eroare la încărcarea raportului.');
            })
            .then(function () {
                if (inflight === controller) {
                    inflight = null;
                    root.classList.remove('is-loading');
                    $('arpLoading').hidden = true;
                }
            });
    }

    // ------------------------------------------------------------- filtre UI

    function fillSelect(select, options, value, emptyLabel) {
        var html = emptyLabel !== null ? '<option value="">' + esc(emptyLabel) + '</option>' : '';
        options.forEach(function (o) {
            html += '<option value="' + esc(o.value) + '"' + (String(o.value) === String(value) ? ' selected' : '') + '>' + esc(o.label) + '</option>';
        });
        select.innerHTML = html;
        select.value = String(value === undefined || value === null ? '' : value);
        if (select.value !== String(value) && emptyLabel !== null) {
            select.value = '';
        }
    }

    function renderFilters() {
        var o = (report && report.options) || {};
        var f = state.filters;

        fillSelect($('arpModule'), (o.modules || []).map(function (m) { return { value: m.key, label: m.label }; }), f.module, 'Toate paginile');
        fillSelect($('arpSection'), (o.sections || []).map(function (s) { return { value: s, label: s }; }), f.section, 'Toate secțiunile');
        fillSelect($('arpUser'), (o.users || []).map(function (u) { return { value: u.id, label: u.name }; }), f.user || '', 'Toți utilizatorii');
        if (!f.user) { $('arpUser').value = ''; }
        fillSelect($('arpRole'), (o.roles || []).map(function (r) { return { value: r.key, label: r.label }; }), f.role, 'Toate rolurile');
        fillSelect($('arpField'), (o.fields || []).map(function (x) { return { value: x.key, label: x.label }; }), f.field, 'Toate câmpurile');

        $('arpFrom').value = f.from || '';
        $('arpTo').value = f.to || '';
        $('arpEntity').value = f.entity || '';
        $('arpQ').value = f.q || '';
        $('arpSensitive').checked = !!f.sensitive;
        $('arpLogins').checked = f.logins !== false;
        $('arpTq').value = f.tq || '';
        $('arpGroup').value = f.group || 'none';
        $('arpPerPage').value = String(f.pp || 10);
        $('arpTrendDays').value = String(f.trend);
        $('arpHeatMode').value = f.heat || 'auto';
        $('arpHeatMode').disabled = !f.module;

        renderChips();
        renderPeriod();
    }

    function renderChips() {
        var box = $('arpActionChips').querySelector('.arp-chips');
        var selected = state.filters.actions;
        box.innerHTML = ACTIONS.map(function (a) {
            var on = selected.length === 0 || selected.indexOf(a.key) >= 0;
            return '<button type="button" class="arp-chip c-' + a.key + (on ? '' : ' is-off') + '" data-action="' + a.key + '" aria-pressed="' + (on ? 'true' : 'false') + '">'
                + '<i class="bi ' + a.icon + '"></i>' + esc(a.label) + '</button>';
        }).join('');
    }

    function renderPeriod() {
        $('arpPreset').value = ['today', 'yesterday', '7', '30', 'month', 'last_month', 'year'].indexOf(state.period) >= 0 ? state.period : 'custom';
        root.querySelectorAll('.arp-pbtn').forEach(function (btn) {
            var p = btn.getAttribute('data-preset');
            var active = p === state.period || (p === 'custom' && ['7', '30'].indexOf(state.period) < 0);
            btn.classList.toggle('is-active', active);
        });
    }

    function readTextFilters() {
        var f = state.filters;
        f.entity = $('arpEntity').value.trim();
        f.q = $('arpQ').value.trim();
        f.from = $('arpFrom').value || f.from;
        f.to = $('arpTo').value || f.to;
    }

    // ------------------------------------------------------------------ KPI

    function renderKpis() {
        var k = report.kpis;
        var defs = [
            { key: 'total', label: 'Acțiuni totale', icon: 'bi-calendar2-check', color: '#1f6fff', note: true },
            { key: 'create', label: 'Creări', icon: 'bi-plus-lg', color: '#16a34a' },
            { key: 'update', label: 'Modificări', icon: 'bi-pencil', color: '#16a34a' },
            { key: 'delete', label: 'Ștergeri', icon: 'bi-trash3', color: '#ef4444' },
            { key: 'status', label: 'Schimbări status', icon: 'bi-people', color: '#3b82f6' }
        ];
        $('arpKpis').innerHTML = defs.map(function (d) {
            var item = k[d.key] || { value: 0, delta: null, spark: [] };
            var delta = '';
            if (item.delta !== null && item.delta !== undefined) {
                var cls = item.delta > 0 ? 'up' : (item.delta < 0 ? 'down' : 'flat');
                var arrow = item.delta > 0 ? 'bi-arrow-up' : (item.delta < 0 ? 'bi-arrow-down' : 'bi-dash');
                delta = '<span class="arp-delta ' + cls + '" title="Perioada anterioară: ' + fmt(item.prev) + '"><i class="bi ' + arrow + '"></i> ' + (item.delta > 0 ? '+' : '') + item.delta + '%</span>';
            }
            var max = Math.max.apply(null, (item.spark || [0]).concat([1]));
            var spark = (item.spark || []).map(function (v) {
                return v > 0
                    ? '<i style="height:' + Math.max(8, Math.round(v / max * 100)) + '%" title="' + fmt(v) + '"></i>'
                    : '<i style="height:2px;opacity:.25" title="0"></i>';
            }).join('');
            var note = '';
            if (d.note) {
                note = '<div class="arp-kpi-note">' + (item.delta !== null && item.delta !== undefined ? 'față de perioada anterioară' : 'fără activitate în perioada anterioară') + '</div>';
            }
            return '<div class="arp-card arp-kpi">'
                + '<span class="arp-kpi-icon k-' + d.key + '"><i class="bi ' + d.icon + '"></i></span>'
                + '<div class="arp-kpi-text"><div class="arp-kpi-label">' + esc(d.label) + '</div>'
                + '<div class="arp-kpi-value"><strong>' + fmt(item.value) + '</strong>' + delta + '</div>' + note + '</div>'
                + '<div class="arp-spark" style="color:' + d.color + '">' + spark + '</div>'
                + '</div>';
        }).join('');
    }

    // --------------------------------------------------------- panouri analiza

    function empty(message) {
        return '<div class="arp-empty"><i class="bi bi-inbox"></i>' + esc(message) + '</div>';
    }

    function renderModuleBars() {
        var data = report.modules;
        var items = data.items.slice(0, state.modulesLimit === '5' ? 5 : 8);
        $('arpModulesTitle').textContent = data.by === 'section' ? 'Activitate pe secțiuni — ' + data.module_label : 'Activitate pe modul';
        if (!items.length) {
            $('arpModuleBars').innerHTML = empty('Nicio activitate pentru filtrele alese.');
            return;
        }
        var max = items[0].count || 1;
        $('arpModuleBars').innerHTML = items.map(function (it, i) {
            var color = PALETTE[i % PALETTE.length];
            var active = data.by === 'module' && it.key === state.filters.module;
            return '<button type="button" class="arp-bar' + (active ? ' is-active' : '') + '" data-bar-' + data.by + '="' + esc(it.key) + '" title="' + esc(it.label) + ': ' + fmt(it.count) + ' acțiuni (' + pct(it.pct) + ')">'
                + '<span class="arp-dot" style="background:' + color + '"></span>'
                + '<span class="arp-bar-label">' + esc(it.label) + '</span>'
                + '<span class="arp-bar-track"><span class="arp-bar-fill" style="width:' + Math.max(2, it.count / max * 100) + '%;background:' + color + '"></span></span>'
                + '<span class="arp-num">' + fmt(it.count) + '</span>'
                + '<span class="arp-pct">' + pct(it.pct) + '</span></button>';
        }).join('');
    }

    function renderDonut() {
        var d = report.actions;
        $('arpDonutTotal').textContent = fmt(d.total);
        $('arpDonutLegend').innerHTML = d.items.length ? d.items.map(function (it) {
            return '<li><span class="arp-dot" style="background:' + ACTION[it.key].color + '"></span><span>' + esc(PLURAL[it.key] || it.label) + '</span>'
                + '<b class="arp-num">' + fmt(it.count) + '</b><em>(' + pct(it.pct) + ')</em></li>';
        }).join('') : '<li class="arp-cell-muted" style="display:block">Nicio acțiune.</li>';

        if (typeof Chart === 'undefined') { return; }
        var values = d.items.length ? d.items.map(function (it) { return it.count; }) : [1];
        var colors = d.items.length ? d.items.map(function (it) { return ACTION[it.key].color; }) : ['#eef1f5'];
        var labels = d.items.length ? d.items.map(function (it) { return PLURAL[it.key] || it.label; }) : ['Fără date'];
        if (charts.donut) {
            charts.donut.data.labels = labels;
            charts.donut.data.datasets[0].data = values;
            charts.donut.data.datasets[0].backgroundColor = colors;
            charts.donut.options.plugins.tooltip.enabled = d.items.length > 0;
            charts.donut.update();
            return;
        }
        charts.donut = new Chart($('arpDonut'), {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0, hoverOffset: 4 }] },
            options: {
                cutout: '66%',
                responsive: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: d.items.length > 0,
                        callbacks: {
                            label: function (ctx) {
                                var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0) || 1;
                                return ' ' + ctx.label + ': ' + fmt(ctx.parsed) + ' (' + Math.round(ctx.parsed / total * 100) + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function heatColor(value, max) {
        if (!value) { return HEAT[0]; }
        var level = Math.min(HEAT.length - 1, 1 + Math.floor((value / Math.max(1, max)) * (HEAT.length - 1.001)));
        return HEAT[level];
    }

    function renderHeat() {
        var h = report.heatmap;
        $('arpHeatTitle').textContent = 'Activitate pe utilizatori (top ' + Math.max(h.rows.length, 0) + ')';
        if (!h.rows.length || !h.columns.length) {
            $('arpHeat').style.gridTemplateColumns = '1fr';
            $('arpHeat').innerHTML = empty('Nicio activitate atribuită unui utilizator.');
            return;
        }
        var el = $('arpHeat');
        el.style.gridTemplateColumns = 'minmax(84px, 1.15fr) repeat(' + h.columns.length + ', minmax(0, 1fr))';
        var html = '<span></span>' + h.columns.map(function (c) {
            return '<span class="arp-heat-col" title="' + esc(c.label) + '">' + esc(c.label) + '</span>';
        }).join('');
        h.rows.forEach(function (r) {
            html += '<span class="arp-heat-name" title="' + esc(r.name) + '">' + esc(r.name) + '</span>';
            r.values.forEach(function (v, i) {
                var col = h.columns[i];
                html += '<button type="button" class="arp-heat-cell" style="background:' + heatColor(v, h.max) + '"'
                    + ' data-heat-user="' + r.user_id + '" data-heat-' + h.by + '="' + esc(col.key) + '"'
                    + ' title="' + esc(r.name) + ' · ' + esc(col.label) + ': ' + fmt(v) + ' acțiuni"></button>';
            });
        });
        html += '<div class="arp-heat-scale"><span>Mai puțină activitate</span>' + HEAT.slice(1).map(function (c) {
            return '<i style="background:' + c + '"></i>';
        }).join('') + '<span>Mai multă activitate</span></div>';
        el.innerHTML = html;
    }

    function lineDatasets(series) {
        return [
            { key: 'create', label: 'Creări' },
            { key: 'update', label: 'Modificări' },
            { key: 'delete', label: 'Ștergeri' },
            { key: 'status', label: 'Status' }
        ].map(function (s) {
            var color = ACTION[s.key].color;
            return {
                label: s.label,
                data: series[s.key] || [],
                borderColor: color,
                backgroundColor: color + '1f',
                fill: s.key === 'update' || s.key === 'create',
                tension: 0.35,
                pointRadius: 2.5,
                pointHoverRadius: 4,
                pointBackgroundColor: color,
                borderWidth: 1.8
            };
        });
    }

    function renderTrend() {
        var t = report.trend;
        $('arpTrendLegend').innerHTML = ['create', 'update', 'delete', 'status'].map(function (k) {
            return '<li><span class="arp-dot" style="background:' + ACTION[k].color + '"></span>' + esc(PLURAL[k]) + '</li>';
        }).join('');
        if (typeof Chart === 'undefined') { return; }
        var labels = t.labels.map(shortDate);
        if (charts.trend) {
            charts.trend.data.labels = labels;
            charts.trend.data.datasets = lineDatasets(t.series);
            charts.trend.update();
            return;
        }
        charts.trend = new Chart($('arpTrend'), {
            type: 'line',
            data: { labels: labels, datasets: lineDatasets(t.series) },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 300 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                var i = items[0].dataIndex;
                                return report.trend.step > 1 ? 'Săptămâna din ' + shortDate(report.trend.labels[i]) : shortDate(report.trend.labels[i]);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#475569', maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
                    y: { beginAtZero: true, grid: { color: '#eef1f5' }, border: { display: false }, ticks: { font: { size: 10 }, color: '#475569', precision: 0, maxTicksLimit: 5 } }
                }
            }
        });
    }

    // ------------------------------------------------------- coloana dreapta

    function renderAside() {
        var pages = report.topPages || [];
        var maxPage = pages.length ? pages[0].count : 1;
        $('arpTopPages').innerHTML = pages.length ? pages.slice(0, 6).map(function (it, i) {
            var color = PALETTE[i % PALETTE.length];
            return '<li data-bar-module="' + esc(it.key) + '" style="cursor:pointer" title="Deschide tab-ul ' + esc(it.label) + '">'
                + '<span class="arp-rank-n">' + (i + 1) + '</span>'
                + '<span class="arp-rank-main"><span>' + esc(it.label) + '</span><span class="arp-bar-track"><span class="arp-bar-fill" style="width:' + Math.max(3, it.count / maxPage * 100) + '%;background:' + color + '"></span></span></span>'
                + '<span class="arp-num">' + fmt(it.count) + '</span><span class="arp-pct">' + pct(it.pct) + '</span></li>';
        }).join('') : '<li class="arp-cell-muted" style="display:block">Nicio activitate.</li>';

        var users = report.topUsers || [];
        $('arpTopUsers').innerHTML = users.length ? users.map(function (u, i) {
            return '<li data-user-filter="' + u.user_id + '" style="cursor:pointer" title="Filtrează după ' + esc(u.name) + '">'
                + '<span class="arp-rank-n">' + (i + 1) + '</span>'
                + '<span class="arp-avatar ' + avatarClass(u.user_id) + '">' + esc(initials(u.name)) + '</span>'
                + '<span class="arp-rank-main"><span>' + esc(u.name) + '</span></span>'
                + '<span class="arp-num">' + fmt(u.count) + '</span><span class="arp-pct">' + pct(u.pct) + '</span></li>';
        }).join('') : '<li class="arp-cell-muted" style="display:block">Nicio activitate.</li>';

        var fields = report.fields || { items: [] };
        var maxField = fields.items.length ? fields.items[0].count : 1;
        var fieldHtml = fields.items.slice(0, 6).map(function (it, i) {
            var color = FIELD_PALETTE[i % FIELD_PALETTE.length];
            return '<li data-field-filter="' + esc(it.field) + '" style="cursor:pointer" title="Filtrează după câmpul ' + esc(it.label) + '">'
                + '<span class="arp-dot" style="background:' + color + '"></span><span>' + esc(it.label) + '</span>'
                + '<span class="arp-bar-track"><span class="arp-bar-fill" style="width:' + Math.max(4, it.count / maxField * 100) + '%;background:' + color + '"></span></span>'
                + '<span class="arp-num">' + fmt(it.count) + '</span><span class="arp-pct">' + pct(it.pct) + '</span></li>';
        }).join('');
        if (!fields.items.length) {
            fieldHtml = '<li class="arp-cell-muted" style="display:block">Nicio modificare de câmp înregistrată.</li>';
        }
        if (fields.unknown > 0) {
            fieldHtml += '<li class="arp-side-note" style="display:block" title="Acțiuni înregistrate înainte de capturarea câmpurilor">+ ' + fmt(fields.unknown) + ' modificări fără detaliu de câmp</li>';
        }
        $('arpFieldStats').innerHTML = fieldHtml;

        var risk = report.risk;
        var color = risk.level === 'low' ? '#16a34a' : (risk.level === 'medium' ? '#f59e0b' : '#dc2626');
        var c = 2 * Math.PI * 27;
        $('arpRisk').innerHTML = '<div class="arp-gauge" title="Scor calculat din ștergeri, autentificări eșuate, modificări sensibile și acțiuni în afara programului">'
            + '<svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="27" fill="none" stroke="#edf1f6" stroke-width="6"/>'
            + '<circle cx="32" cy="32" r="27" fill="none" stroke="' + color + '" stroke-width="6" stroke-linecap="round" stroke-dasharray="' + (c * risk.score / 100).toFixed(1) + ' ' + c.toFixed(1) + '"/></svg>'
            + '<div class="arp-gauge-text"><strong>' + risk.score + '</strong><span>/100</span></div></div>'
            + '<div><div class="arp-risk-level lv-' + risk.level + '"><i class="bi ' + (risk.level === 'low' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill') + '"></i>' + esc(risk.label) + '</div>'
            + '<p>' + esc(risk.message) + '</p></div>';
    }

    // ------------------------------------------------------------- tab-uri

    function renderTabs() {
        var tabs = report.tabs;
        var bar = $('arpTabs');
        var active = state.filters.module;
        var items = tabs.items.filter(function (t) { return t.count > 0 || t.key === active; });
        var first = items.length ? items[0] : null;
        var ordered = [];
        // Ca in macheta: modulul cel mai activ, apoi "Toate paginile", apoi restul.
        if (first) { ordered.push(first); }
        ordered.push({ key: '', label: 'Toate paginile', count: tabs.all });
        items.slice(1).forEach(function (t) { ordered.push(t); });

        bar.innerHTML = ordered.map(function (t) {
            var isActive = t.key === active;
            return '<button type="button" role="tab" class="arp-tab' + (isActive ? ' is-active' : '') + (t.count === 0 ? ' is-empty' : '') + '" data-tab="' + esc(t.key) + '" aria-selected="' + (isActive ? 'true' : 'false') + '">'
                + esc(t.label) + '<span class="arp-tab-count">' + fmt(t.count) + '</span></button>';
        }).join('');

        fitTabs();
    }

    // Tab-urile care nu incap pe latime trec in "Mai multe"; modulele fara activitate stau mereu acolo.
    function fitTabs() {
        var bar = $('arpTabs');
        var buttons = Array.prototype.slice.call(bar.querySelectorAll('.arp-tab'));
        buttons.forEach(function (b) { b.hidden = false; });
        var available = bar.parentElement.clientWidth - $('arpTabsMoreBtn').offsetWidth - 8;
        var used = 0;
        var overflow = [];
        buttons.forEach(function (b) {
            used += b.offsetWidth + 2;
            if (used > available && !b.classList.contains('is-active')) {
                b.hidden = true;
                overflow.push(b.getAttribute('data-tab'));
            }
        });
        var tabs = report.tabs.items;
        var moreItems = tabs.filter(function (t) { return overflow.indexOf(t.key) >= 0 || (t.count === 0 && t.key !== state.filters.module); });
        $('arpTabsMore').innerHTML = moreItems.length ? moreItems.map(function (t) {
            return '<button type="button" class="arp-dd-item' + (t.key === state.filters.module ? ' is-active' : '') + '" data-tab="' + esc(t.key) + '"><span>' + esc(t.label) + '</span><span class="arp-tab-count">' + fmt(t.count) + '</span></button>';
        }).join('') : '<div class="arp-dd-title">Toate modulele sunt afișate</div>';
    }

    // ---------------------------------------------------------------- tabel

    function visibleColumns() {
        var allTab = !state.filters.module;
        return COLUMNS.filter(function (c) {
            return state.columns.indexOf(c.key) >= 0 && (!c.allOnly || allTab);
        });
    }

    function renderHead() {
        var f = state.filters;
        var cols = visibleColumns();
        var html = cols.map(function (c) {
            var sorted = c.sort && f.sort === c.sort && (c.key !== 'date' || f.sort !== 'ts');
            if (c.key === 'time' && f.sort === 'ts') { sorted = true; }
            var icon = '';
            if (c.sort) {
                icon = sorted ? (f.dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') : 'bi-arrow-down-up';
                icon = '<i class="bi ' + icon + ' arp-sort"></i>';
            }
            return '<th' + (c.sort ? ' data-sort="' + c.sort + '"' : '') + (sorted ? ' class="is-sorted"' : '') + '>' + esc(c.label) + icon + '</th>';
        }).join('');
        html += '<th>Detalii</th><th class="arp-th-cols"><div class="arp-dd" data-arp-dd>'
            + '<button type="button" class="arp-kebab" data-arp-dd-toggle title="Coloane vizibile" aria-label="Coloane vizibile"><i class="bi bi-layout-three-columns"></i></button>'
            + '<div class="arp-dd-menu arp-dd-menu-right"><div class="arp-dd-title">Coloane vizibile</div>'
            + COLUMNS.map(function (c) {
                return '<label class="arp-col-check"><input type="checkbox" data-col="' + c.key + '"' + (state.columns.indexOf(c.key) >= 0 ? ' checked' : '') + '> ' + esc(c.label) + (c.allOnly ? ' <small class="arp-cell-muted">(Toate paginile)</small>' : '') + '</label>';
            }).join('')
            + '<div class="arp-dd-sep"></div><button type="button" class="arp-dd-item" data-col-reset>Restabilește coloanele</button></div></div></th>';
        $('arpThead').innerHTML = html;
    }

    function cell(c, r) {
        var unknown = '<span class="arp-cell-unknown" title="Informație indisponibilă în jurnal">n/d</span>';
        switch (c.key) {
            case 'date': return '<td>' + esc(r.date) + '</td>';
            case 'time': return '<td>' + esc(r.time) + '</td>';
            case 'user': return '<td><span class="arp-user"><span class="arp-avatar ' + avatarClass(r.user_id) + '">' + esc(r.initials) + '</span>' + esc(r.user_name) + '</span></td>';
            case 'role': return '<td class="arp-cell-muted">' + esc(r.role_label) + '</td>';
            case 'action':
                var a = ACTION[r.action] || ACTION.update;
                var cls = r.failed ? 'c-delete' : 'c-' + r.action;
                return '<td><span class="arp-badge ' + cls + '"><i class="bi ' + (r.failed ? 'bi-x-octagon-fill' : a.icon) + '"></i>' + esc(r.action_label) + '</span>'
                    + (r.sensitive ? '<i class="bi bi-exclamation-diamond-fill arp-sens" title="Acțiune sensibilă"></i>' : '') + '</td>';
            case 'module': return '<td><span class="arp-cell-trunc" title="' + esc(r.module_label + ' › ' + r.section) + '">' + esc(r.module_label) + '</span></td>';
            case 'entity':
                return '<td><span class="arp-cell-trunc" title="' + esc(r.entity + (r.entity_sub ? ' · ' + r.entity_sub : '')) + '">' + esc(r.entity) + '</span></td>';
            case 'field':
                if (!r.fields_known) { return '<td>' + unknown + '</td>'; }
                if (!r.field) { return '<td class="arp-cell-muted">-</td>'; }
                return '<td><span class="arp-cell-trunc" title="' + esc(r.fields_all) + '">' + esc(r.field) + '</span>' + (r.fields_more ? '<span class="arp-more" title="' + esc(r.fields_all) + '">+' + r.fields_more + '</span>' : '') + '</td>';
            case 'old':
                if (!r.fields_known) { return '<td>' + unknown + '</td>'; }
                return '<td><span class="arp-cell-trunc' + (r.old === 'nedisponibil' ? ' arp-cell-unknown' : '') + '" title="' + esc(r.old || '') + '">' + esc(r.old === null ? '-' : r.old) + '</span></td>';
            case 'new':
                if (!r.fields_known) { return '<td>' + unknown + '</td>'; }
                return '<td><span class="arp-cell-trunc" title="' + esc(r.new || '') + '">' + esc(r.new === null ? '-' : r.new) + '</span></td>';
            case 'desc': return '<td><span class="arp-cell-trunc arp-cell-desc" title="' + esc(r.description) + '">' + esc(r.description) + '</span></td>';
            case 'ip':
                var ip = r.ip || '';
                var dev = r.device || '';
                if (!ip && !dev) { return '<td class="arp-cell-unknown" title="IP-ul nu era înregistrat la momentul acțiunii">nu a fost înregistrat</td>'; }
                return '<td class="arp-cell-muted">' + esc(ip + (ip && dev ? ' / ' : '') + dev) + '</td>';
        }
        return '<td></td>';
    }

    function renderTable() {
        renderHead();
        var t = report.table;
        var cols = visibleColumns();
        var span = cols.length + 2;
        var body = $('arpTbody');
        if (!t.rows.length) {
            body.innerHTML = '<tr class="arp-table-empty"><td colspan="' + span + '"><i class="bi bi-inbox" style="font-size:22px;color:#c4cdd9;display:block"></i>Nicio acțiune pentru filtrele selectate.</td></tr>';
        } else {
            var lastGroup = null;
            body.innerHTML = t.rows.map(function (r) {
                var html = '';
                if (r.group_key !== undefined && r.group_key !== lastGroup) {
                    lastGroup = r.group_key;
                    html += '<tr class="arp-group-row"><td colspan="' + span + '">' + esc(r.group_label) + '<span>' + fmt(r.group_count) + ' acțiuni</span></td></tr>';
                }
                html += '<tr data-uid="' + esc(r.uid) + '">' + cols.map(function (c) { return cell(c, r); }).join('')
                    + '<td><button type="button" class="arp-vezi" data-detail="' + esc(r.uid) + '"><i class="bi bi-box-arrow-up-right"></i>Vezi</button></td>'
                    + '<td style="text-align:right"><button type="button" class="arp-kebab" data-row-menu="' + esc(r.uid) + '" aria-label="Acțiuni rând"><i class="bi bi-three-dots-vertical"></i></button></td></tr>';
                return html;
            }).join('');
        }

        var from = t.total ? (t.page - 1) * t.per_page + 1 : 0;
        var to = Math.min(t.total, t.page * t.per_page);
        $('arpShown').textContent = 'Afișate ' + fmt(from) + ' - ' + fmt(to) + ' din ' + fmt(t.total) + ' rezultate';
        renderPager(t.page, t.pages);
    }

    function renderTableError(message) {
        $('arpTbody').innerHTML = '<tr class="arp-table-empty"><td colspan="14"><i class="bi bi-exclamation-triangle" style="font-size:22px;color:#ef4444;display:block"></i>' + esc(message) + '</td></tr>';
    }

    function renderPager(page, pages) {
        var nums = [];
        var add = function (n) { if (nums.indexOf(n) < 0 && n >= 1 && n <= pages) { nums.push(n); } };
        add(1);
        for (var i = Math.max(1, page - 2); i <= Math.min(pages, Math.max(page + 2, 5)); i++) { add(i); }
        add(pages);
        nums.sort(function (a, b) { return a - b; });
        var html = '<button type="button" class="arp-page is-nav" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + ' aria-label="Pagina anterioară"><i class="bi bi-chevron-left"></i></button>';
        var prev = 0;
        nums.forEach(function (n) {
            if (n - prev > 1) { html += '<span class="arp-page-gap">...</span>'; }
            html += '<button type="button" class="arp-page' + (n === page ? ' is-active' : '') + '" data-page="' + n + '">' + n + '</button>';
            prev = n;
        });
        html += '<button type="button" class="arp-page is-nav" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + ' aria-label="Pagina următoare"><i class="bi bi-chevron-right"></i></button>';
        $('arpPager').innerHTML = html;
    }

    function renderResultsChart() {
        if (typeof Chart === 'undefined') { return; }
        var s = report.series;
        var labels = s.labels.map(shortDate);
        var datasets = ['create', 'update', 'delete', 'status', 'login', 'restore'].filter(function (k) { return s.series[k]; }).map(function (k) {
            return { label: PLURAL[k], data: s.series[k], backgroundColor: ACTION[k].color, borderRadius: 2, maxBarThickness: 26 };
        });
        if (charts.results) {
            charts.results.data.labels = labels;
            charts.results.data.datasets = datasets;
            charts.results.update();
            return;
        }
        charts.results = new Chart($('arpResultsChart'), {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true } },
                    y: { stacked: true, beginAtZero: true, grid: { color: '#eef1f5' }, ticks: { precision: 0, font: { size: 10 } } }
                }
            }
        });
    }

    function renderPivot() {
        var p = report.pivot;
        if (!p.rows.length) {
            $('arpPivotView').innerHTML = empty('Nicio acțiune pentru filtrele selectate.');
            return;
        }
        var max = 1;
        p.rows.forEach(function (r) { p.columns.forEach(function (c) { max = Math.max(max, r.values[c.key]); }); });
        var html = '<table class="arp-table" style="min-width:720px"><thead><tr><th>Utilizator</th><th>Rol</th>'
            + p.columns.map(function (c) { return '<th style="text-align:right">' + esc(PLURAL[c.key] || c.label) + '</th>'; }).join('')
            + '<th style="text-align:right">Total</th></tr></thead><tbody>';
        p.rows.forEach(function (r) {
            html += '<tr><td><span class="arp-user"><span class="arp-avatar ' + avatarClass(r.user_id) + '">' + esc(initials(r.name)) + '</span>' + esc(r.name) + '</span></td>'
                + '<td class="arp-cell-muted">' + esc(r.role || '—') + '</td>'
                + p.columns.map(function (c) {
                    var v = r.values[c.key];
                    var alpha = v ? (0.12 + 0.55 * v / max).toFixed(2) : 0;
                    return '<td class="arp-pv">' + (v ? '<b style="background:rgba(31,111,255,' + alpha + ')">' + fmt(v) + '</b>' : '<span class="arp-cell-muted">·</span>') + '</td>';
                }).join('')
                + '<td class="arp-pv"><strong>' + fmt(r.total) + '</strong></td></tr>';
        });
        html += '</tbody><tfoot><tr><td colspan="2">Total</td>' + p.columns.map(function (c) {
            return '<td class="arp-pv">' + fmt(p.totals[c.key]) + '</td>';
        }).join('') + '<td class="arp-pv">' + fmt(p.total) + '</td></tr></tfoot></table>';
        $('arpPivotView').innerHTML = html;
    }

    function renderMode() {
        root.querySelectorAll('.arp-seg-btn').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-mode') === state.mode);
        });
        $('arpTableWrap').hidden = state.mode !== 'table';
        $('arpChartView').hidden = state.mode !== 'chart';
        $('arpPivotView').hidden = state.mode !== 'pivot';
        $('arpGroup').disabled = state.mode !== 'table';
        if (state.mode === 'chart') { renderResultsChart(); }
        if (state.mode === 'pivot') { renderPivot(); }
    }

    function render() {
        if (!report) {
            return;
        }
        renderFilters();
        renderKpis();
        renderModuleBars();
        renderDonut();
        renderHeat();
        renderTrend();
        renderAside();
        renderTabs();
        renderTable();
        renderMode();
        if (report.truncated && report.truncated.length) {
            toast('Perioada este foarte mare: unele surse au fost limitate la primele 25.000 de înregistrări. Restrânge intervalul.', true);
        }
    }

    // ------------------------------------------------------------- detalii

    function openDrawer(html) {
        $('arpDrawerBody').innerHTML = html;
        $('arpBackdrop').hidden = false;
        var drawer = $('arpDrawer');
        drawer.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () { drawer.classList.add('is-open'); });
    }

    function closeDrawer() {
        var drawer = $('arpDrawer');
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        $('arpBackdrop').hidden = true;
    }

    function snapshotTable(items) {
        if (!items || !items.length) { return ''; }
        return '<table class="arp-diff"><tbody>' + items.map(function (it) {
            var value = it.value;
            if (value !== null && typeof value === 'object') { value = JSON.stringify(value); }
            return '<tr><th style="width:42%">' + esc(it.key) + '</th><td>' + esc(value === null || value === '' ? '—' : value) + '</td></tr>';
        }).join('') + '</tbody></table>';
    }

    function flatSnapshot(obj) {
        if (!obj) { return null; }
        return obj.map(function (it) {
            var value = it.value;
            if (value && typeof value === 'object') {
                value = Object.keys(value).map(function (k) { return k + ': ' + (typeof value[k] === 'object' && value[k] !== null ? JSON.stringify(value[k]) : value[k]); }).join('; ');
            }
            return { key: it.key, value: value };
        });
    }

    function showDetail(uid) {
        openDrawer('<div class="arp-empty"><span class="arp-spinner"></span>Se încarcă detaliile…</div>');
        fetch(urls.detail + '&uid=' + encodeURIComponent(uid), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json.ok) { throw new Error(json.message || 'Detaliile nu au putut fi încărcate.'); }
                var d = json.detail;
                var a = ACTION[d.action] || ACTION.update;
                var dt = d.ts.split(' ');
                var dp = dt[0].split('-');
                var html = '<div style="margin-bottom:12px"><span class="arp-badge ' + (d.failed ? 'c-delete' : 'c-' + d.action) + '"><i class="bi ' + a.icon + '"></i>' + esc(d.action_label) + '</span>'
                    + (d.sensitive ? ' <span class="arp-badge c-delete"><i class="bi bi-exclamation-diamond-fill"></i>Acțiune sensibilă</span>' : '') + '</div>';
                html += '<dl class="arp-dl">'
                    + '<dt>Utilizator</dt><dd><span class="arp-user"><span class="arp-avatar ' + avatarClass(d.user_id) + '">' + esc(d.initials) + '</span>' + esc(d.user_name) + '</span> <span class="arp-cell-muted">(' + esc(d.role_label) + ')</span></dd>'
                    + '<dt>Data și ora</dt><dd>' + esc(dp[2] + '.' + dp[1] + '.' + dp[0] + ' ' + (dt[1] || '')) + '</dd>'
                    + '<dt>Modul / Pagină</dt><dd>' + esc(d.module_label) + '</dd>'
                    + '<dt>Secțiune</dt><dd>' + esc(d.section) + '</dd>'
                    + '<dt>Entitate / ID</dt><dd>' + esc(d.entity) + (d.record_id ? ' <span class="arp-cell-muted">(ID ' + esc(d.record_id) + ')</span>' : '') + (d.entity_sub ? '<br><span class="arp-cell-muted">' + esc(d.entity_sub) + '</span>' : '') + '</dd>'
                    + '<dt>Descriere</dt><dd>' + esc(d.description) + '</dd>'
                    + '<dt>IP</dt><dd>' + (d.ip ? esc(d.ip) : '<span class="arp-cell-unknown">Informație indisponibilă în jurnal</span>') + '</dd>'
                    + '<dt>Dispozitiv</dt><dd' + (d.user_agent ? ' title="' + esc(d.user_agent) + '"' : '') + '>' + (d.device ? esc(d.device) : '<span class="arp-cell-unknown">Informație indisponibilă în jurnal</span>') + '</dd>'
                    + '<dt>Sursă jurnal</dt><dd class="arp-cell-muted">' + esc(d.source_label) + ' · ' + esc(d.uid) + '</dd>'
                    + '</dl>';
                if (d.link) {
                    html += '<a class="arp-btn arp-btn-outline-primary arp-drawer-link" href="' + esc(d.link) + '"><i class="bi bi-box-arrow-up-right"></i>' + esc(d.link_label) + '</a>';
                }
                html += '<h3>Câmpuri modificate</h3>';
                if (d.changes_full === null) {
                    html += '<div class="arp-note"><i class="bi bi-info-circle"></i> Informație indisponibilă în jurnal — câmpurile modificate nu au fost înregistrate pentru această acțiune.</div>';
                } else if (!d.changes_full.length) {
                    html += '<div class="arp-note">' + (d.action === 'login' ? 'Autentificarea nu modifică date.' : 'Acțiunea nu are modificări la nivel de câmp' + (d.action === 'create' || d.action === 'delete' ? ' (vezi instantaneul de mai jos).' : '.')) + '</div>';
                } else {
                    html += '<table class="arp-diff"><thead><tr><th>Câmp</th><th>Valoare veche</th><th>Valoare nouă</th></tr></thead><tbody>'
                        + d.changes_full.map(function (c) {
                            return '<tr><th>' + esc(c.field) + '</th><td class="old">' + esc(c.old) + '</td><td class="new">' + esc(c.new) + '</td></tr>';
                        }).join('') + '</tbody></table>';
                }
                var before = flatSnapshot(d.before);
                var after = flatSnapshot(d.after);
                if (before && before.length) {
                    html += '<details' + (d.action === 'delete' ? ' open' : '') + '><summary>Instantaneu înainte (' + before.length + ' câmpuri)</summary>' + snapshotTable(before) + '</details>';
                }
                if (after && after.length) {
                    html += '<details' + (d.action === 'create' ? ' open' : '') + '><summary>' + (d.source_label === 'cursa_audit_log' ? 'Detalii înregistrate' : 'Instantaneu după') + ' (' + after.length + ' câmpuri)</summary>' + snapshotTable(after) + '</details>';
                }
                openDrawer(html);
            })
            .catch(function (error) {
                openDrawer('<div class="arp-note" style="color:#b91c1c">' + esc(error.message) + '</div>');
            });
    }

    function showList(kind) {
        var html = '';
        if (kind === 'pages') {
            html = '<h3 style="margin-top:0">Activitate pe module (' + esc(report.period.from) + ' – ' + esc(report.period.to) + ')</h3><table class="arp-diff"><tbody>'
                + report.topPages.map(function (p) { return '<tr><th>' + esc(p.label) + '</th><td style="text-align:right">' + fmt(p.count) + '</td><td style="text-align:right">' + pct(p.pct) + '</td></tr>'; }).join('') + '</tbody></table>';
        } else if (kind === 'users') {
            html = '<h3 style="margin-top:0">Toți utilizatorii activi</h3><table class="arp-diff"><tbody>'
                + report.pivot.rows.map(function (r) { return '<tr><th>' + esc(r.name) + '</th><td>' + esc(r.role || '') + '</td><td style="text-align:right">' + fmt(r.total) + '</td></tr>'; }).join('') + '</tbody></table>';
        } else if (kind === 'fields') {
            html = '<h3 style="margin-top:0">Modificări pe câmpuri</h3><table class="arp-diff"><tbody>'
                + report.fields.items.map(function (f) { return '<tr><th>' + esc(f.label) + '</th><td style="text-align:right">' + fmt(f.count) + '</td><td style="text-align:right">' + pct(f.pct) + '</td></tr>'; }).join('') + '</tbody></table>'
                + (report.fields.unknown ? '<p class="arp-side-note">' + fmt(report.fields.unknown) + ' modificări au fost înregistrate fără detaliu de câmp (înainte de 15.10.2026 pentru Dispecer curse). Valorile lor nu se reconstruiesc.</p>' : '');
        } else {
            html = '<h3 style="margin-top:0">Scor de risc: ' + report.risk.score + '/100 — ' + esc(report.risk.label) + '</h3><table class="arp-diff"><thead><tr><th>Factor</th><th style="text-align:right">Acțiuni</th><th style="text-align:right">Penalizare</th></tr></thead><tbody>'
                + report.risk.factors.map(function (f) { return '<tr><th>' + esc(f.label) + '</th><td style="text-align:right">' + fmt(f.count) + '</td><td style="text-align:right">-' + f.penalty + '</td></tr>'; }).join('')
                + '</tbody></table><p class="arp-side-note">Scorul pornește de la 100 și scade proporțional cu ponderea fiecărui tip de acțiune în perioada și filtrele selectate.</p>';
        }
        openDrawer(html);
    }

    // ----------------------------------------------------- vizualizari salvate

    function currentConfig() {
        var f = state.filters;
        return {
            module: f.module, section: f.section, user: f.user, role: f.role, actions: f.actions,
            from: f.from, to: f.to, period: state.period, entity: f.entity, field: f.field,
            sensitive: f.sensitive ? 1 : 0, logins: f.logins ? 1 : 0, q: f.q, sort: f.sort, dir: f.dir,
            pp: f.pp, group: f.group, trend: f.trend, heat: f.heat, mode: state.mode, columns: state.columns
        };
    }

    function renderViewsMenu() {
        var html = '<div class="arp-dd-title">Salvează vizualizarea curentă</div>'
            + '<form class="arp-views-form" id="arpViewForm"><input type="text" class="arp-input" id="arpViewName" maxlength="120" placeholder="Nume vizualizare" required>'
            + '<button type="submit" class="arp-btn arp-btn-primary" style="height:28px;padding:0 10px">Salvează</button></form>'
            + '<div class="arp-dd-sep"></div><div class="arp-dd-title">Vizualizări salvate</div>';
        html += views.length ? views.map(function (v) {
            return '<div class="arp-view-row"><button type="button" class="arp-dd-item" data-view-load="' + v.id + '"><span>' + esc(v.name) + '</span></button>'
                + '<button type="button" class="arp-icon-btn" data-view-delete="' + v.id + '" title="Șterge vizualizarea" aria-label="Șterge vizualizarea"><i class="bi bi-trash3"></i></button></div>';
        }).join('') : '<div class="arp-dd-item" style="cursor:default;color:#94a3b8">Nicio vizualizare salvată.</div>';
        $('arpViewsMenu').innerHTML = html;
    }

    function postForm(url, data) {
        var body = new FormData();
        body.append('_token', boot.csrf || '');
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json(); })
            .then(function (json) {
                if (!json.ok) { throw new Error(json.message || 'Operațiunea a eșuat.'); }
                return json;
            });
    }

    function applyConfig(config) {
        var f = Object.assign({}, DEFAULT_FILTERS);
        Object.keys(config || {}).forEach(function (k) {
            if (Object.prototype.hasOwnProperty.call(f, k)) { f[k] = config[k]; }
        });
        f.actions = Array.isArray(config.actions) ? config.actions : [];
        f.sensitive = !!Number(config.sensitive);
        f.logins = config.logins === undefined ? true : !!Number(config.logins);
        f.user = Number(config.user || 0);
        f.pp = Number(config.pp || 10);
        f.trend = Number(config.trend === undefined ? 14 : config.trend);
        state.period = config.period || 'custom';
        if (state.period !== 'custom') {
            var range = presetRange(state.period);
            f.from = range[0];
            f.to = range[1];
        }
        state.filters = f;
        state.mode = config.mode || 'table';
        state.columns = Array.isArray(config.columns) && config.columns.length ? config.columns : DEFAULT_COLUMNS.slice();
        load(true);
    }

    // ------------------------------------------------------------- export

    function exportFile(format) {
        if (format === 'pdf' && report && report.table.total > 1500) {
            toast('Selecția are ' + fmt(report.table.total) + ' acțiuni; PDF-ul este limitat la 1.500. Restrânge filtrele sau folosește Excel.', true);
            return;
        }
        readTextFilters();
        window.location.href = withQuery(urls.export, query({ format: format }));
    }

    // ------------------------------------------------------------- evenimente

    function closeMenus(except) {
        root.querySelectorAll('[data-arp-dd].is-open').forEach(function (dd) {
            if (dd !== except) { dd.classList.remove('is-open'); }
        });
        var rowMenu = root.querySelector('.arp-row-menu');
        if (rowMenu) { rowMenu.remove(); }
    }

    function openRowMenu(button, uid) {
        closeMenus(null);
        var row = (report.table.rows || []).filter(function (r) { return r.uid === uid; })[0];
        if (!row) { return; }
        var menu = document.createElement('div');
        menu.className = 'arp-row-menu';
        menu.innerHTML = '<button type="button" class="arp-dd-item" data-detail="' + esc(uid) + '"><span><i class="bi bi-eye"></i> Vezi detalii</span></button>'
            + (row.user_id ? '<button type="button" class="arp-dd-item" data-user-filter="' + row.user_id + '"><span><i class="bi bi-person"></i> Doar acțiunile lui ' + esc(row.user_name) + '</span></button>' : '')
            + (row.record_id ? '<button type="button" class="arp-dd-item" data-entity-filter="' + esc(row.record_id) + '"><span><i class="bi bi-funnel"></i> Istoricul entității #' + esc(row.record_id) + '</span></button>' : '')
            + '<button type="button" class="arp-dd-item" data-copy="' + esc(uid) + '"><span><i class="bi bi-clipboard"></i> Copiază ID jurnal</span></button>';
        root.appendChild(menu);
        var rect = button.getBoundingClientRect();
        var left = Math.min(window.innerWidth - menu.offsetWidth - 10, rect.right - menu.offsetWidth);
        var top = rect.bottom + 4;
        if (top + menu.offsetHeight > window.innerHeight - 10) { top = rect.top - menu.offsetHeight - 4; }
        menu.style.left = Math.max(10, left) + 'px';
        menu.style.top = Math.max(10, top) + 'px';
    }

    function setModule(key) {
        state.filters.module = key || '';
        state.filters.section = '';
        state.filters.field = '';
        load(true);
    }

    var debounceTimer = null;
    function debounced(fn, ms) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fn, ms || 300);
    }

    root.addEventListener('click', function (event) {
        var t = event.target;
        var el;

        if ((el = t.closest('[data-arp-dd-toggle]'))) {
            var dd = el.closest('[data-arp-dd]');
            var willOpen = !dd.classList.contains('is-open');
            closeMenus(dd);
            dd.classList.toggle('is-open', willOpen);
            if (willOpen && dd.contains($('arpViewsMenu'))) {
                renderViewsMenu();
                setTimeout(function () { var n = $('arpViewName'); if (n) { n.focus(); } }, 0);
            }
            return;
        }
        if (!t.closest('.arp-dd-menu') && !t.closest('.arp-row-menu')) {
            closeMenus(null);
        }

        if ((el = t.closest('[data-tab]'))) {
            closeMenus(null);
            setModule(el.getAttribute('data-tab'));
            return;
        }
        if ((el = t.closest('[data-bar-module]'))) {
            setModule(el.getAttribute('data-bar-module'));
            return;
        }
        if ((el = t.closest('[data-bar-section]'))) {
            state.filters.section = el.getAttribute('data-bar-section');
            load(true);
            return;
        }
        if ((el = t.closest('[data-heat-user]'))) {
            state.filters.user = Number(el.getAttribute('data-heat-user'));
            if (el.hasAttribute('data-heat-module')) { state.filters.module = el.getAttribute('data-heat-module'); state.filters.section = ''; }
            if (el.hasAttribute('data-heat-section')) { state.filters.section = el.getAttribute('data-heat-section'); }
            load(true);
            return;
        }
        if ((el = t.closest('[data-user-filter]'))) {
            closeMenus(null);
            state.filters.user = Number(el.getAttribute('data-user-filter'));
            load(true);
            return;
        }
        if ((el = t.closest('[data-field-filter]'))) {
            state.filters.field = el.getAttribute('data-field-filter');
            load(true);
            return;
        }
        if ((el = t.closest('[data-entity-filter]'))) {
            closeMenus(null);
            state.filters.entity = '#' + el.getAttribute('data-entity-filter');
            load(true);
            return;
        }
        if ((el = t.closest('[data-copy]'))) {
            var text = el.getAttribute('data-copy');
            closeMenus(null);
            if (navigator.clipboard) { navigator.clipboard.writeText(text).then(function () { toast('ID copiat: ' + text); }); }
            return;
        }
        if ((el = t.closest('[data-detail]'))) {
            closeMenus(null);
            showDetail(el.getAttribute('data-detail'));
            return;
        }
        if ((el = t.closest('[data-row-menu]'))) {
            event.stopPropagation();
            openRowMenu(el, el.getAttribute('data-row-menu'));
            return;
        }
        if ((el = t.closest('[data-arp-close]')) || t === $('arpBackdrop')) {
            closeDrawer();
            return;
        }
        if ((el = t.closest('[data-arp-showall]'))) {
            showList(el.getAttribute('data-arp-showall'));
            return;
        }
        if ((el = t.closest('th[data-sort]'))) {
            var sort = el.getAttribute('data-sort');
            if (state.filters.sort === sort) {
                state.filters.dir = state.filters.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.filters.sort = sort;
                state.filters.dir = sort === 'ts' ? 'desc' : 'asc';
            }
            load(true);
            return;
        }
        if ((el = t.closest('[data-page]'))) {
            if (el.disabled) { return; }
            state.filters.p = Number(el.getAttribute('data-page'));
            load(false);
            var top = $('arpReport').getBoundingClientRect().top;
            if (top < 0) { $('arpReport').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            return;
        }
        if ((el = t.closest('.arp-chip'))) {
            var key = el.getAttribute('data-action');
            var sel = state.filters.actions.length ? state.filters.actions.slice() : ACTIONS.map(function (a) { return a.key; });
            var idx = sel.indexOf(key);
            if (idx >= 0) { sel.splice(idx, 1); } else { sel.push(key); }
            state.filters.actions = sel.length === ACTIONS.length || sel.length === 0 ? [] : sel;
            renderChips();
            debounced(function () { load(true); }, 350);
            return;
        }
        if (t.closest('#arpChipsCaret')) {
            var allOn = state.filters.actions.length === 0;
            state.filters.actions = allOn ? ['update'] : [];
            renderChips();
            debounced(function () { load(true); }, 200);
            return;
        }
        if ((el = t.closest('.arp-pbtn'))) {
            var preset = el.getAttribute('data-preset');
            if (preset === 'custom') {
                state.period = 'custom';
                renderPeriod();
                var from = $('arpFrom');
                from.focus();
                if (typeof from.showPicker === 'function') { try { from.showPicker(); } catch (e) { /* ignorat */ } }
                return;
            }
            var range = presetRange(preset);
            state.period = preset;
            state.filters.from = range[0];
            state.filters.to = range[1];
            load(true);
            return;
        }
        if ((el = t.closest('.arp-seg-btn'))) {
            state.mode = el.getAttribute('data-mode');
            renderMode();
            syncUrl();
            return;
        }
        if ((el = t.closest('[data-arp-export]'))) {
            exportFile(el.getAttribute('data-arp-export'));
            return;
        }
        if ((el = t.closest('[data-view-load]'))) {
            var id = Number(el.getAttribute('data-view-load'));
            var view = views.filter(function (v) { return v.id === id; })[0];
            closeMenus(null);
            if (view) { applyConfig(view.config); toast('Vizualizare încărcată: ' + view.name); }
            return;
        }
        if ((el = t.closest('[data-view-delete]'))) {
            var vid = el.getAttribute('data-view-delete');
            postForm(urls.viewDelete, { id: vid }).then(function (json) {
                views = json.views || [];
                renderViewsMenu();
                toast('Vizualizarea a fost ștearsă.');
            }).catch(function (error) { toast(error.message, true); });
            return;
        }
        if (t.closest('[data-col-reset]')) {
            state.columns = DEFAULT_COLUMNS.slice();
            renderTable();
            syncUrl();
            return;
        }
        if (t.closest('#arpReset')) {
            var r = presetRange('30');
            state.filters = Object.assign({}, DEFAULT_FILTERS, { from: r[0], to: r[1] });
            state.period = '30';
            state.mode = 'table';
            state.columns = DEFAULT_COLUMNS.slice();
            state.modulesLimit = 'all';
            $('arpModulesLimit').value = 'all';
            load(true);
        }
    });

    root.addEventListener('change', function (event) {
        var t = event.target;
        var f = state.filters;
        if (t.matches('input[data-col]')) {
            var key = t.getAttribute('data-col');
            var cols = state.columns.filter(function (c) { return c !== key; });
            if (t.checked) { cols.push(key); }
            state.columns = DEFAULT_COLUMNS.filter(function (c) { return cols.indexOf(c) >= 0; });
            renderTable();
            var dd = root.querySelector('.arp-th-cols [data-arp-dd]');
            if (dd) { dd.classList.add('is-open'); }
            syncUrl();
            return;
        }
        switch (t.id) {
            case 'arpModule': setModule(t.value); return;
            case 'arpSection': f.section = t.value; break;
            case 'arpUser': f.user = Number(t.value || 0); break;
            case 'arpRole': f.role = t.value; break;
            case 'arpField': f.field = t.value; break;
            case 'arpSensitive': f.sensitive = t.checked; break;
            case 'arpLogins': f.logins = t.checked; break;
            case 'arpFrom':
            case 'arpTo':
                if (!$('arpFrom').value || !$('arpTo').value) { return; }
                f.from = $('arpFrom').value;
                f.to = $('arpTo').value;
                state.period = 'custom';
                break;
            case 'arpPreset':
                if (t.value === 'custom') { state.period = 'custom'; renderPeriod(); return; }
                var range = presetRange(t.value);
                state.period = t.value;
                f.from = range[0];
                f.to = range[1];
                break;
            case 'arpGroup': f.group = t.value; break;
            case 'arpPerPage': f.pp = Number(t.value); break;
            case 'arpTrendDays': f.trend = Number(t.value); break;
            case 'arpHeatMode': f.heat = t.value; break;
            case 'arpModulesLimit': state.modulesLimit = t.value; renderModuleBars(); return;
            default: return;
        }
        readTextFilters();
        load(true);
    });

    $('arpFilters').addEventListener('submit', function (event) {
        // stopPropagation: garda globala anti-dublu-submit din app.js nu trebuie sa blocheze butonul.
        event.preventDefault();
        event.stopPropagation();
        readTextFilters();
        load(true);
    });

    $('arpTq').addEventListener('input', function () {
        var value = this.value.trim();
        debounced(function () {
            state.filters.tq = value;
            load(true);
        }, 350);
    });

    root.addEventListener('submit', function (event) {
        if (event.target.id !== 'arpViewForm') { return; }
        event.preventDefault();
        event.stopPropagation();
        var name = $('arpViewName').value.trim();
        if (!name) { return; }
        readTextFilters();
        postForm(urls.viewSave, { name: name, config: JSON.stringify(currentConfig()) }).then(function (json) {
            views = json.views || [];
            renderViewsMenu();
            toast('Vizualizarea „' + name + '” a fost salvată.');
        }).catch(function (error) { toast(error.message, true); });
    }, true);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeDrawer();
            closeMenus(null);
        }
    });

    document.addEventListener('click', function (event) {
        if (!root.contains(event.target)) { closeMenus(null); }
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { if (report) { fitTabs(); } }, 120);
    });

    // ------------------------------------------------------------- pornire

    if (!state.filters.from || !state.filters.to) {
        var initial = presetRange(state.period);
        state.filters.from = initial[0];
        state.filters.to = initial[1];
    }
    if (report) {
        render();
    } else {
        load(true);
    }
})();
