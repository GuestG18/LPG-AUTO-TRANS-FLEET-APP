/* Revizii → Prezentare generală: donut-uri pe categorii, alerte, activitate recentă (filtre +
   paginare) si interventiile pe luni. Datele vin din #rvPageData (ReviziiService::buildOverview). */
(function () {
    'use strict';

    var data = {};
    try {
        data = JSON.parse((document.getElementById('rvPageData') || {}).textContent || '{}');
    } catch (e) {
        data = {};
    }
    var COLORS = { in_regula: '#2e9e5b', se_apropie: '#f5b301', necesita: '#e5484d', fara_date: '#94a3b8', neconfigurat: '#e2e8f0' };
    var CATEGORY_COLORS = { schimb_ulei: '#2563eb', placute_frana: '#f59e0b', anvelope: '#22a55b' };
    var STATUS_LABELS = { in_regula: 'În regulă', se_apropie: 'Se apropie de termen', necesita: 'Necesită intervenție', fara_date: 'Date insuficiente', neconfigurat: 'Neconfigurat' };

    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function dateRo(value) {
        return (value || '').slice(0, 10).split('-').reverse().join('.');
    }
    function parseDate(value) {
        return new Date(String(value).replace(' ', 'T'));
    }

    // ------------------------------------------------------------ donut-uri pe categorii
    if (window.Chart) {
        Object.keys(data.categorii || {}).forEach(function (tab) {
            var canvas = document.getElementById('rvDonut-' + tab);
            if (!canvas) { return; }
            var counts = data.categorii[tab].counts;
            var keys = ['in_regula', 'se_apropie', 'necesita', 'fara_date', 'neconfigurat'];
            var values = keys.map(function (k) { return counts[k] || 0; });
            var empty = values.every(function (v) { return v === 0; });
            new window.Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: keys.map(function (k) { return STATUS_LABELS[k]; }),
                    datasets: [{
                        data: empty ? [1] : values,
                        backgroundColor: empty ? ['#e2e8f0'] : keys.map(function (k) { return COLORS[k]; }),
                        borderWidth: 0
                    }]
                },
                options: {
                    cutout: '72%',
                    responsive: false,
                    animation: { duration: 400 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: !empty,
                            callbacks: {
                                label: function (ctx) {
                                    var total = counts.total || 0;
                                    var pct = total ? Math.round(ctx.parsed / total * 100) : 0;
                                    return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                                }
                            }
                        }
                    }
                }
            });
        });
    }

    // ------------------------------------------------------------ alerte: toate / primele 4
    var alertsToggle = document.getElementById('rvAlertsToggle');
    if (alertsToggle) {
        var label = alertsToggle.innerHTML;
        alertsToggle.addEventListener('click', function () {
            var expanded = alertsToggle.getAttribute('aria-expanded') === 'true';
            document.querySelectorAll('[data-extra-alert]').forEach(function (el) { el.hidden = expanded; });
            alertsToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            alertsToggle.innerHTML = expanded ? label : 'Arată doar primele <i class="bi bi-arrow-up"></i>';
        });
    }

    // ------------------------------------------------------------ activitate recenta
    var PAGE_SIZE = 7;
    var history = data.istoric || [];
    var body = document.getElementById('rvHistBody');
    var search = document.getElementById('rvHistSearch');
    var category = document.getElementById('rvHistCategory');
    var status = document.getElementById('rvHistStatus');
    var period = document.getElementById('rvHistPeriod');
    var pager = document.getElementById('rvHistPager');
    var count = document.getElementById('rvHistCount');
    var page = 1;

    function filtered() {
        var term = (search.value || '').trim().toLowerCase();
        var days = Number(period.value);
        var since = days > 0 ? new Date(Date.now() - days * 86400000) : null;
        return history.filter(function (h) {
            if (category.value && h.categorie !== category.value) { return false; }
            if (status.value && h.status !== status.value) { return false; }
            if (since && parseDate(h.data) < since) { return false; }
            if (term && (h.nr + ' ' + h.componenta + ' ' + h.categorie_label + ' ' + h.actiune + ' ' + h.observatii).toLowerCase().indexOf(term) === -1) { return false; }
            return true;
        });
    }

    function renderHistory() {
        var rows = filtered(); // deja ordonate de server: cele mai noi primele
        var pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
        page = Math.min(page, pages);
        var from = (page - 1) * PAGE_SIZE;
        var shown = rows.slice(from, from + PAGE_SIZE);
        if (!shown.length) {
            body.innerHTML = '<tr><td colspan="9" class="rv-ov-empty-cell">' + (history.length
                ? 'Nicio intervenție pentru filtrele alese.'
                : 'Nicio intervenție înregistrată încă. Reviziile și înlocuirile înregistrate pe paginile categoriilor apar aici.') + '</td></tr>';
        } else {
            body.innerHTML = shown.map(function (h, i) {
                var statusBadge = h.status === 'finalizat'
                    ? '<span class="rv-badge rv-badge-ok rv-badge-sm"><i class="bi bi-check-circle-fill"></i>Finalizat</span>'
                    : '<span class="rv-badge rv-badge-nodata rv-badge-sm" title="Ciclul anterior măsurat avea zile fără telemetrie SasFleet"><i class="bi bi-info-circle"></i>Date incomplete</span>';
                var url = (data.urls || {})[h.categorie] || '';
                return '<tr>' +
                    '<td>' + (from + i + 1) + '</td>' +
                    '<td title="' + esc(h.data.slice(0, 16)) + '">' + esc(dateRo(h.data)) + '</td>' +
                    '<td><strong>' + esc(h.nr) + '</strong></td>' +
                    '<td>' + esc(h.categorie_label) + '</td>' +
                    '<td>' + esc(h.componenta) + '</td>' +
                    '<td' + (h.observatii ? ' title="' + esc(h.observatii) + '"' : '') + '>' + esc(h.actiune) + (h.observatii ? ' <i class="bi bi-chat-left-text rv-flag-muted"></i>' : '') + '</td>' +
                    '<td>' + (h.operator ? esc(h.operator) : '<span class="text-muted">Nespecificat</span>') + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td>' + (url
                        ? '<div class="dropdown"><button type="button" class="btn btn-outline-secondary btn-sm rv-kebab" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acțiuni"><i class="bi bi-three-dots-vertical"></i></button>' +
                          '<ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="' + esc(url) + '"><i class="bi bi-box-arrow-up-right me-2"></i>Deschide ' + esc(h.categorie_label) + '</a></li></ul></div>'
                        : '') + '</td>' +
                    '</tr>';
            }).join('');
        }
        count.textContent = 'Afișează ' + shown.length + ' din ' + rows.length + ' intervenții';
        pager.innerHTML = '';
        if (pages > 1) {
            var add = function (labelHtml, target, opts) {
                var b = document.createElement('button');
                b.type = 'button';
                b.innerHTML = labelHtml;
                if (opts.active) { b.className = 'active'; b.setAttribute('aria-current', 'page'); }
                if (opts.disabled) { b.disabled = true; }
                if (opts.aria) { b.setAttribute('aria-label', opts.aria); }
                b.addEventListener('click', function () { page = target; renderHistory(); });
                pager.appendChild(b);
            };
            add('<i class="bi bi-chevron-left"></i>', page - 1, { disabled: page <= 1, aria: 'Pagina anterioară' });
            for (var p = 1; p <= pages; p++) { add(String(p), p, { active: p === page }); }
            add('<i class="bi bi-chevron-right"></i>', page + 1, { disabled: page >= pages, aria: 'Pagina următoare' });
        }
    }

    if (body) {
        [search, category, status, period].forEach(function (el) {
            el.addEventListener(el === search ? 'input' : 'change', function () { page = 1; renderHistory(); });
        });
        renderHistory();
    }

    // ------------------------------------------------------------ interventii pe luni
    var monthlyCanvas = document.getElementById('rvMonthly');
    var monthlyEmpty = document.getElementById('rvMonthlyEmpty');
    var chartPeriod = document.getElementById('rvChartPeriod');
    var monthlyChart = null;
    var MONTHS = ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function renderMonthly() {
        if (!monthlyCanvas || !window.Chart) { return; }
        var months = Number(chartPeriod.value) || 6;
        var now = new Date();
        var buckets = [];
        for (var i = months - 1; i >= 0; i--) {
            var d = new Date(now.getFullYear(), now.getMonth() - i, 1);
            buckets.push({ key: d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'), label: MONTHS[d.getMonth()] + ' ' + d.getFullYear() });
        }
        var index = {};
        buckets.forEach(function (b, i) { index[b.key] = i; });
        var tabs = Object.keys(data.categorii || {});
        var series = {};
        tabs.forEach(function (tab) { series[tab] = buckets.map(function () { return 0; }); });
        var total = 0;
        // Un rand din istoric = o interventie reala (fara join-uri care sa dubleze).
        history.forEach(function (h) {
            var key = String(h.data).slice(0, 7);
            if (index[key] === undefined || !series[h.categorie]) { return; }
            series[h.categorie][index[key]]++;
            total++;
        });
        monthlyEmpty.hidden = total > 0;
        monthlyCanvas.hidden = total === 0;
        if (monthlyChart) { monthlyChart.destroy(); monthlyChart = null; }
        if (!total) { return; }
        monthlyChart = new window.Chart(monthlyCanvas, {
            type: 'bar',
            data: {
                labels: buckets.map(function (b) { return b.label; }),
                datasets: tabs.map(function (tab) {
                    return { label: data.categorii[tab].label, data: series[tab], backgroundColor: CATEGORY_COLORS[tab] || '#94a3b8', borderRadius: 2, maxBarThickness: 34 };
                })
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'rectRounded', boxWidth: 10 } },
                    tooltip: {
                        callbacks: {
                            title: function (items) { return items[0].label; },
                            label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + (ctx.parsed.y === 1 ? ' intervenție' : ' intervenții'); }
                        }
                    }
                }
            }
        });
    }
    if (chartPeriod) {
        chartPeriod.addEventListener('change', renderMonthly);
        renderMonthly();
    }
})();
