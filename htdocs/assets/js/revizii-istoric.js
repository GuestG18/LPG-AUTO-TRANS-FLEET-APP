/* Revizii → Istoric revizii (page=revizii&tip=istoric).
   Filtrele, sortarea si paginarea se aplica pe server (?action=istoric_data); starea lor
   sta in URL, deci reincarcarea / deschiderea detaliilor nu o pierde. */
(function () {
    'use strict';

    var root = document.getElementById('rvHist');
    if (!root) { return; }
    var data = {};
    try {
        data = JSON.parse((document.getElementById('rvHistData') || {}).textContent || '{}');
    } catch (e) {
        data = {};
    }
    var can = data.can || {};
    var urls = data.urls || {};
    var CATEGORY_COLORS = { schimb_ulei: '#2f6fed', placute_frana: '#f59e0b', anvelope: '#22a55b' };
    var CATEGORY_ICONS = {
        schimb_ulei: '<svg viewBox="0 0 32 20" aria-hidden="true"><path d="M3 7h6l2-2h6l3 2 9-3-7 10H8L5 11H3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M29 12c0 1.4-1 2.5-2 2.5s-2-1.1-2-2.5 2-3.5 2-3.5 2 2.1 2 3.5z" fill="currentColor"/></svg>',
        placute_frana: '<i class="bi bi-disc"></i>',
        anvelope: '<i class="bi bi-record-circle"></i>'
    };
    var TRUCK = '<svg viewBox="0 0 40 22" aria-hidden="true"><rect x="1" y="3" width="23" height="13" rx="1.5" fill="currentColor" opacity=".9"/><path d="M26 7h7l5 5v4H26z" fill="currentColor"/><circle cx="8" cy="18" r="3" fill="#fff" stroke="currentColor" stroke-width="2"/><circle cx="31" cy="18" r="3" fill="#fff" stroke="currentColor" stroke-width="2"/></svg>';
    var MONTHS = ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    var form = document.getElementById('rvHistFilters');
    var body = document.getElementById('rvHistBody');
    var summary = document.getElementById('rvHistSummary');
    var countEl = document.getElementById('rvHistCount');
    var pager = document.getElementById('rvHistPager');
    var detailCard = document.getElementById('rvHistDetail');
    var detailBody = document.getElementById('rvHistDetailBody');
    var yearSelect = document.getElementById('rvHistYear');
    var toast = document.getElementById('rvHistToast');
    var fileInput = document.getElementById('rvHistFile');

    var state = {
        sort: data.sort || 'data',
        dir: data.dir || 'desc',
        page: ((data.initial || {}).tabel || {}).page || 1,
        selected: null,
        rows: []
    };
    var pending = null;
    var monthlyChart = null;
    var donutChart = null;

    // ------------------------------------------------------------ utilitare
    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function dateRo(value) { return (value || '').slice(0, 10).split('-').reverse().join('.'); }
    function dateTimeRo(value) { return value ? dateRo(value) + ' ' + String(value).slice(11, 16) : '—'; }
    function km(value) { return value === null || value === undefined ? '—' : Math.round(value).toLocaleString('ro-RO'); }
    function pad(n) { return String(n).padStart(2, '0'); }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function bytes(n) { return n >= 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
    function actionUrl(action, params) {
        var u = new URL(urls.base, window.location.href);
        u.searchParams.set('action', action);
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v !== '' && v !== null && v !== undefined) { u.searchParams.set(k, v); }
        });
        return u.toString();
    }
    function showToast(message, kind) {
        if (!toast) { return; }
        toast.textContent = message;
        toast.className = 'rv-hist-toast rv-hist-toast-' + (kind || 'ok');
        toast.hidden = false;
        clearTimeout(showToast.timer);
        showToast.timer = setTimeout(function () { toast.hidden = true; }, 4500);
    }
    function post(action, fields) {
        var fd = fields instanceof FormData ? fields : new FormData();
        if (!(fields instanceof FormData)) {
            Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        }
        fd.append('_token', data.token || '');
        return fetch(actionUrl(action), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } })
            .then(function (r) {
                return r.json().catch(function () { return { ok: false, message: 'Răspuns invalid de la server (' + r.status + ').' }; });
            });
    }

    // ------------------------------------------------------------ filtre <-> URL
    function filterValues() {
        return {
            q: form.q.value.trim(),
            de_la: form.de_la.value,
            pana_la: form.pana_la.value,
            categorie: form.categorie.value,
            vehicul: form.vehicul.value,
            status: form.status.value,
            operator: form.operator.value
        };
    }
    function queryParams(extra) {
        var params = filterValues();
        params.sort = state.sort;
        params.dir = state.dir;
        params.p = state.page;
        params.an = yearSelect ? yearSelect.value : '';
        Object.keys(extra || {}).forEach(function (k) { params[k] = extra[k]; });
        return params;
    }
    function syncUrl() {
        var u = new URL(urls.page, window.location.href);
        var params = queryParams();
        Object.keys(params).forEach(function (k) {
            // Perioada goala se pastreaza explicit (altfel serverul ar pune implicit anul curent).
            if (params[k] !== '' || k === 'de_la' || k === 'pana_la') { u.searchParams.set(k, params[k]); }
        });
        window.history.replaceState(null, '', u.toString());
    }

    function load(scope) {
        if (pending) { pending.abort(); }
        pending = new AbortController();
        root.classList.add('is-loading');
        syncUrl();
        var params = queryParams(scope === 'tabel' ? { scope: 'tabel' } : {});
        return fetch(actionUrl('istoric_data', params), { credentials: 'same-origin', signal: pending.signal, headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok) { throw new Error(res.message || 'Eroare'); }
                render(res);
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') { showToast(err.message || 'Istoricul nu a putut fi încărcat.', 'error'); }
            })
            .finally(function () { root.classList.remove('is-loading'); });
    }

    // ------------------------------------------------------------ desen
    function render(res) {
        if (res.kpi) { renderKpi(res.kpi); }
        if (res.tabel) { renderTable(res.tabel); }
        if (res.lunar) { renderMonthly(res.lunar); }
        if (res.categorii) { renderDonut(res.categorii); }
        if (res.rapoarte) { renderReports(document.getElementById('rvHistReports'), res.rapoarte); }
        updateQuickButtons();
    }

    function renderKpi(kpi) {
        ['total', 'finalizate', 'cu_documente', 'vehicule'].forEach(function (key) {
            var el = root.querySelector('[data-kpi="' + key + '"]');
            if (el) { el.textContent = kpi[key]; }
        });
        ['finalizate', 'cu_documente'].forEach(function (key) {
            var el = root.querySelector('[data-kpi-pct="' + key + '"]');
            if (el) { el.textContent = (kpi.total ? Math.round(kpi[key] / kpi.total * 100) + '%' : '—') + ' din total'; }
        });
    }

    function statusBadge(row) {
        if (row.status === 'verificat') {
            return '<span class="rv-badge rv-badge-info rv-badge-sm"><i class="bi bi-patch-check-fill"></i>Verificat</span>';
        }
        if (row.status === 'finalizat') {
            return '<span class="rv-badge rv-badge-ok rv-badge-sm"><i class="bi bi-check-circle-fill"></i>Finalizat</span>';
        }
        return '<span class="rv-badge rv-badge-soon rv-badge-sm" title="Ciclul încheiat avea zile fără telemetrie SasFleet: km consumați sunt o limită inferioară"><i class="bi bi-exclamation-circle-fill"></i>Date incomplete</span>';
    }
    function categoryCell(row) {
        return '<span class="rv-hist-cat rv-hist-cat-' + esc(row.categorie) + '">' + (CATEGORY_ICONS[row.categorie] || '') + '</span>' + esc(row.categorie_label);
    }
    function docsCell(row) {
        return '<span class="rv-hist-docs' + (row.documente ? '' : ' is-empty') + '"><i class="bi bi-paperclip"></i>' + row.documente + '</span>';
    }

    function renderTable(tabel) {
        state.rows = tabel.rows || [];
        state.page = tabel.page;
        var values = filterValues();
        var period = values.de_la || values.pana_la
            ? ' pentru perioada ' + (values.de_la ? dateRo(values.de_la) : '…') + ' – ' + (values.pana_la ? dateRo(values.pana_la) : '…')
            : ' în tot istoricul';
        summary.textContent = tabel.total + (tabel.total === 1 ? ' rezultat găsit' : ' rezultate găsite') + period;

        if (!state.rows.length) {
            body.innerHTML = '<tr><td colspan="13" class="rv-ov-empty-cell">' + (tabel.total === 0 && !hasFilters()
                ? 'Nicio intervenție înregistrată încă. Schimburile de ulei și înlocuirile înregistrate pe paginile categoriilor apar aici.'
                : 'Nicio intervenție pentru filtrele alese.') + '</td></tr>';
        } else {
            var offset = (tabel.page - 1) * tabel.per_page;
            body.innerHTML = state.rows.map(function (row, i) {
                var menu = [];
                menu.push('<li><button type="button" class="dropdown-item" data-act="detail"><i class="bi bi-eye me-2"></i>Detalii</button></li>');
                if (can.edit) {
                    menu.push(row.status === 'verificat'
                        ? '<li><button type="button" class="dropdown-item" data-act="unverify"><i class="bi bi-x-circle me-2"></i>Anulează verificarea</button></li>'
                        : '<li><button type="button" class="dropdown-item" data-act="verify"><i class="bi bi-patch-check me-2"></i>Marchează verificat</button></li>');
                    menu.push('<li><button type="button" class="dropdown-item" data-act="reference"><i class="bi bi-hash me-2"></i>Editează referința</button></li>');
                }
                if (can.documents) {
                    menu.push('<li><button type="button" class="dropdown-item" data-act="upload"><i class="bi bi-paperclip me-2"></i>Atașează document</button></li>');
                }
                if ((urls.categorie || {})[row.categorie]) {
                    menu.push('<li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="' + esc(urls.categorie[row.categorie]) + '"><i class="bi bi-box-arrow-up-right me-2"></i>Deschide ' + esc(row.categorie_label) + '</a></li>');
                }
                return '<tr class="rv-row' + (state.selected === row.id ? ' is-open' : '') + '" data-id="' + row.id + '" tabindex="0">' +
                    '<td>' + (offset + i + 1) + '</td>' +
                    '<td title="' + esc(dateTimeRo(row.data)) + '">' + esc(dateRo(row.data)) + '</td>' +
                    '<td><span class="rv-hist-veh" title="' + esc(row.vehicul_tip + (row.vehicul_inactiv ? ' · inactiv' : '')) + '">' + TRUCK + '<strong>' + esc(row.nr) + '</strong></span></td>' +
                    '<td class="rv-trunc">' + categoryCell(row) + '</td>' +
                    '<td class="rv-trunc" title="' + esc(row.componenta) + '">' + esc(row.componenta) + '</td>' +
                    '<td class="rv-trunc">' + esc(row.tip_interventie) + '</td>' +
                    '<td class="num">' + km(row.km) + '</td>' +
                    '<td class="rv-trunc" title="' + esc(row.referinta ? row.referinta : 'T0: ' + dateTimeRo(row.data)) + '">' + (row.referinta ? esc(row.referinta) : '—') + '</td>' +
                    '<td class="rv-trunc">' + (row.operator ? esc(row.operator) : '<span class="text-muted">Nespecificat</span>') + '</td>' +
                    '<td>' + statusBadge(row) + '</td>' +
                    '<td>' + docsCell(row) + '</td>' +
                    '<td class="rv-trunc"' + (row.observatii ? ' title="' + esc(row.observatii) + '"' : '') + '>' + (row.observatii ? esc(row.observatii) : '—') + '</td>' +
                    '<td><div class="rv-hist-actions"><button type="button" class="btn btn-sm rv-hist-detail-btn" data-act="detail">Detalii</button>' +
                    '<div class="dropdown"><button type="button" class="btn btn-sm rv-kebab" data-bs-toggle="dropdown" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false" aria-label="Mai multe acțiuni"><i class="bi bi-three-dots-vertical"></i></button>' +
                    '<ul class="dropdown-menu dropdown-menu-end">' + menu.join('') + '</ul></div></div></td>' +
                    '</tr>';
            }).join('');
        }

        var from = tabel.total ? (tabel.page - 1) * tabel.per_page + 1 : 0;
        var to = Math.min(tabel.total, tabel.page * tabel.per_page);
        countEl.textContent = tabel.total ? 'Afișează ' + from + '–' + to + ' din ' + tabel.total + ' intervenții' : '';
        renderPager(tabel.page, tabel.pages);
        root.querySelectorAll('.rv-hist-table th[data-sort]').forEach(function (th) {
            var active = th.getAttribute('data-sort') === state.sort;
            th.classList.toggle('is-sorted', active);
            th.setAttribute('aria-sort', active ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none');
            th.setAttribute('data-dir', active ? state.dir : '');
        });

        // Primul rand se deschide automat in panoul de detalii (ca in macheta), selectia ramane la schimbarea paginii.
        if (state.rows.length && (state.selected === null || !detailCard || detailCard.hidden)) {
            selectRow(state.selected && state.rows.some(function (r) { return r.id === state.selected; }) ? state.selected : state.rows[0].id, false);
        } else if (!state.rows.length && detailCard) {
            detailCard.hidden = true;
            state.selected = null;
        }
    }

    function hasFilters() {
        var v = filterValues();
        return v.q || v.categorie || v.vehicul || v.status || v.operator;
    }

    function renderPager(page, pages) {
        pager.innerHTML = '';
        if (pages <= 1) { return; }
        var add = function (label, target, opts) {
            var b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = label;
            if (opts.active) { b.className = 'active'; b.setAttribute('aria-current', 'page'); }
            if (opts.disabled) { b.disabled = true; }
            if (opts.aria) { b.setAttribute('aria-label', opts.aria); }
            b.addEventListener('click', function () { state.page = target; load('tabel'); });
            pager.appendChild(b);
        };
        add('<i class="bi bi-chevron-left"></i>', page - 1, { disabled: page <= 1, aria: 'Pagina anterioară' });
        var pagesShown = [];
        for (var p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || Math.abs(p - page) <= 1) { pagesShown.push(p); }
        }
        pagesShown.forEach(function (p, i) {
            if (i > 0 && p - pagesShown[i - 1] > 1) {
                var gap = document.createElement('span');
                gap.className = 'rv-pager-gap';
                gap.textContent = '…';
                pager.appendChild(gap);
            }
            add(String(p), p, { active: p === page });
        });
        add('<i class="bi bi-chevron-right"></i>', page + 1, { disabled: page >= pages, aria: 'Pagina următoare' });
    }

    // ------------------------------------------------------------ grafice
    function renderMonthly(lunar) {
        var canvas = document.getElementById('rvHistMonthly');
        if (!canvas || !window.Chart) { return; }
        var datasets = Object.keys(data.categorii || {}).map(function (tab) {
            return {
                label: data.categorii[tab],
                data: (lunar.serii || {})[tab] || new Array(12).fill(0),
                backgroundColor: CATEGORY_COLORS[tab],
                borderRadius: 2,
                maxBarThickness: 18
            };
        });
        if (monthlyChart) {
            monthlyChart.data.datasets = datasets;
            monthlyChart.update();
            return;
        }
        monthlyChart = new window.Chart(canvas, {
            type: 'bar',
            data: { labels: MONTHS, datasets: datasets },
            options: {
                maintainAspectRatio: false,
                animation: { duration: 300 },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 9 }, color: '#475569', maxRotation: 0, autoSkip: false } },
                    y: { stacked: true, beginAtZero: true, suggestedMax: 4, ticks: { precision: 0, font: { size: 10 }, color: '#475569' }, grid: { color: '#eef1f5' } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        callbacks: {
                            title: function (items) { return items[0].label + ' ' + lunar.an; },
                            label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y + (ctx.parsed.y === 1 ? ' intervenție' : ' intervenții'); },
                            footer: function (items) {
                                var total = items.reduce(function (s, i) { return s + i.parsed.y; }, 0);
                                return 'Total: ' + total;
                            }
                        }
                    }
                }
            }
        });
        monthlyChart.$an = lunar.an;
    }

    function renderDonut(counts) {
        var keys = Object.keys(data.categorii || {});
        var total = keys.reduce(function (s, k) { return s + (counts[k] || 0); }, 0);
        document.getElementById('rvHistDonutTotal').textContent = total;
        document.getElementById('rvHistDist').innerHTML = keys.map(function (k) {
            var n = counts[k] || 0;
            return '<li><span class="rv-dot" style="background:' + CATEGORY_COLORS[k] + '"></span><span>' + esc(data.categorii[k]) + '</span><strong>' + n + '</strong><span class="rv-ov-legend-pct">' + (total ? Math.round(n / total * 100) : 0) + '%</span></li>';
        }).join('');
        var canvas = document.getElementById('rvHistDonut');
        if (!canvas || !window.Chart) { return; }
        var values = keys.map(function (k) { return counts[k] || 0; });
        var dataset = {
            data: total ? values : [1],
            backgroundColor: total ? keys.map(function (k) { return CATEGORY_COLORS[k]; }) : ['#e2e8f0'],
            borderWidth: total ? 2 : 0,
            borderColor: '#fff'
        };
        if (donutChart) {
            donutChart.data.labels = total ? keys.map(function (k) { return data.categorii[k]; }) : ['—'];
            donutChart.data.datasets = [dataset];
            donutChart.options.plugins.tooltip.enabled = total > 0;
            donutChart.update();
            return;
        }
        donutChart = new window.Chart(canvas, {
            type: 'doughnut',
            data: { labels: total ? keys.map(function (k) { return data.categorii[k]; }) : ['—'], datasets: [dataset] },
            options: {
                cutout: '70%',
                responsive: false,
                animation: { duration: 300 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: total > 0,
                        callbacks: {
                            label: function (ctx) {
                                var sum = ctx.dataset.data.reduce(function (s, v) { return s + v; }, 0);
                                return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + (sum ? Math.round(ctx.parsed / sum * 100) : 0) + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ------------------------------------------------------------ rapoarte
    function renderReports(list, reports) {
        if (!list) { return; }
        if (!reports.length) {
            list.innerHTML = '<li class="rv-hist-reports-empty">Niciun raport generat încă. Folosește „Generează raport”.</li>';
            return;
        }
        list.innerHTML = reports.map(function (r) {
            var pdf = r.format === 'pdf';
            var href = actionUrl('istoric_report_download', { id: r.id });
            return '<li>' +
                '<span class="rv-hist-rep-icon ' + (pdf ? 'is-pdf' : 'is-xlsx') + '"><i class="bi ' + (pdf ? 'bi-file-earmark-pdf-fill' : 'bi-file-earmark-spreadsheet-fill') + '"></i></span>' +
                '<span class="rv-hist-rep-text"><strong title="' + esc(r.titlu) + '">' + esc(r.titlu) + '</strong>' +
                '<small>Generat la ' + esc(dateTimeRo(r.creat_la)) + ' · ' + r.nr + ' intervenții · ' + bytes(r.marime) + (r.autor ? ' · ' + esc(r.autor) : '') + '</small></span>' +
                '<a class="btn btn-sm rv-hist-rep-dl" href="' + esc(href) + '" aria-label="Descarcă ' + esc(r.titlu) + '"><i class="bi bi-download"></i></a>' +
                '</li>';
        }).join('');
    }

    var reportsModal = document.getElementById('rvReportsModal');
    if (reportsModal) {
        reportsModal.addEventListener('show.bs.modal', function () {
            var list = document.getElementById('rvReportsAll');
            list.innerHTML = '<li class="rv-hist-reports-empty">Se încarcă…</li>';
            fetch(actionUrl('istoric_reports'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (res) { renderReports(list, res.rapoarte || []); })
                .catch(function () { list.innerHTML = '<li class="rv-hist-reports-empty">Rapoartele nu au putut fi încărcate.</li>'; });
        });
    }

    var reportModal = document.getElementById('rvReportModal');
    var reportForm = document.getElementById('rvReportForm');
    if (reportModal && reportForm) {
        var fleet = document.getElementById('rvRepFleet');
        var vehiclesSel = document.getElementById('rvRepVehicles');
        fleet.addEventListener('change', function () { vehiclesSel.disabled = fleet.checked; });
        reportModal.addEventListener('show.bs.modal', function () {
            // Pornim de la filtrele din pagina; utilizatorul le poate schimba.
            var v = filterValues();
            var now = new Date();
            reportForm.de_la.value = v.de_la || now.getFullYear() + '-01-01';
            reportForm.pana_la.value = v.pana_la || iso(now);
            reportForm.querySelectorAll('input[name="categorii[]"]').forEach(function (cb) { cb.checked = !v.categorie || cb.value === v.categorie; });
            fleet.checked = !v.vehicul;
            vehiclesSel.disabled = fleet.checked;
            Array.prototype.forEach.call(vehiclesSel.options, function (o) { o.selected = o.value === v.vehicul; });
            document.getElementById('rvReportError').hidden = true;
        });
        reportForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var btn = document.getElementById('rvReportSubmit');
            var err = document.getElementById('rvReportError');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Se generează…';
            err.hidden = true;
            post('istoric_report', new FormData(reportForm))
                .then(function (res) {
                    if (!res.ok) {
                        err.textContent = res.message || 'Raportul nu a putut fi generat.';
                        err.hidden = false;
                        return;
                    }
                    renderReports(document.getElementById('rvHistReports'), res.rapoarte || []);
                    if (window.bootstrap) { window.bootstrap.Modal.getOrCreateInstance(reportModal).hide(); }
                    showToast(res.message);
                    if (res.download) { window.location.href = res.download; }
                })
                .catch(function () { err.textContent = 'Raportul nu a putut fi generat.'; err.hidden = false; })
                .finally(function () { btn.disabled = false; btn.innerHTML = '<i class="bi bi-gear"></i> Generează'; });
        });
    }

    // ------------------------------------------------------------ export direct
    root.querySelectorAll('[data-export]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var format = btn.getAttribute('data-export');
            var label = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Se generează…';
            fetch(actionUrl('istoric_export', Object.assign(filterValues(), { format: format })), { credentials: 'same-origin' })
                .then(function (r) {
                    var type = r.headers.get('Content-Type') || '';
                    if (!r.ok || type.indexOf('text/html') === 0) {
                        return r.text().then(function (t) {
                            var div = document.createElement('div');
                            div.innerHTML = t;
                            throw new Error((div.textContent || '').trim() || 'Exportul nu a putut fi generat.');
                        });
                    }
                    var name = 'Istoric_revizii.' + format;
                    var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
                    if (m) { name = m[1]; }
                    return r.blob().then(function (blob) {
                        var a = document.createElement('a');
                        a.href = URL.createObjectURL(blob);
                        a.download = name;
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
                    });
                })
                .catch(function (e) { showToast(e.message, 'error'); })
                .finally(function () { btn.disabled = false; btn.innerHTML = label; });
        });
    });

    // ------------------------------------------------------------ detalii
    function field(label, value, cls) {
        return '<div class="rv-hd-field' + (cls ? ' ' + cls : '') + '"><span>' + esc(label) + '</span><strong>' + value + '</strong></div>';
    }
    function docIcon(mime) {
        if (mime === 'application/pdf') { return '<i class="bi bi-file-earmark-pdf-fill rv-doc-pdf"></i>'; }
        return '<i class="bi bi-file-earmark-image-fill rv-doc-img"></i>';
    }

    function renderDetail(item) {
        var docs = item.documente_lista || [];
        var visibleDocs = 3;
        var docsHtml = docs.length ? docs.map(function (d, i) {
            var view = actionUrl('istoric_doc', { id: d.id });
            return '<span class="rv-hd-doc"' + (i >= visibleDocs ? ' data-extra-doc hidden' : '') + '>' +
                '<a href="' + esc(view) + '" target="_blank" rel="noopener" title="' + esc(d.nume + ' · ' + bytes(d.marime) + (d.incarcat_de ? ' · ' + d.incarcat_de : '') + ' · ' + dateTimeRo(d.incarcat_la)) + '">' + docIcon(d.mime) + '<span>' + esc(d.nume) + '</span></a>' +
                '<a class="rv-hd-doc-dl" href="' + esc(actionUrl('istoric_doc', { id: d.id, download: 1 })) + '" aria-label="Descarcă ' + esc(d.nume) + '"><i class="bi bi-download"></i></a>' +
                (can.documents ? '<button type="button" class="rv-hd-doc-rm" data-doc-remove="' + d.id + '" aria-label="Elimină ' + esc(d.nume) + '"><i class="bi bi-x"></i></button>' : '') +
                '</span>';
        }).join('') + (docs.length > visibleDocs ? '<button type="button" class="rv-hd-more" data-docs-more>+' + (docs.length - visibleDocs) + '</button>' : '')
            : '<span class="text-muted">Niciun document atașat.</span>';

        var extra = [];
        extra.push(field('T0 (început ciclu nou)', esc(dateTimeRo(item.data))));
        extra.push(field('Referință', item.referinta ? esc(item.referinta) : '—'));
        extra.push(field('Vehicul', esc(item.vehicul_tip) + (item.vehicul_inactiv ? ' <span class="rv-badge rv-badge-nodata rv-badge-sm">inactiv azi</span>' : '')));
        extra.push(field(item.remorca ? 'Cuplată la data intervenției cu' : 'Semi-remorcă la data intervenției', item.ansamblu ? esc(item.ansamblu.nr) + ' <small>(' + esc(item.ansamblu.tip) + ')</small>' : '—'));
        extra.push(field('Interval la data intervenției', km(item.interval_km) + ' km'));
        if (item.km_consumati !== null) {
            var cycle = km(item.km_consumati) + ' km';
            if (item.categorie === 'schimb_ulei' && item.km_efectuati !== null) {
                cycle += ' <small>(' + km(item.km_efectuati) + ' km SasFleet' + (item.ore_motor ? ' + ' + String(item.ore_motor).replace('.', ',') + ' h motor × ' + String(item.factor_km_ora).replace('.', ',') + ' km/h' : '') + ')</small>';
            }
            extra.push(field('Uzură ciclu încheiat', cycle + (item.date_complete ? '' : ' <i class="bi bi-exclamation-diamond rv-flag" title="Zile fără telemetrie în ciclul încheiat"></i>')));
        } else {
            extra.push(field('Uzură ciclu încheiat', '<span class="text-muted">— (primul T0 al componentei)</span>'));
        }
        extra.push(field('Înregistrat în aplicație', esc(dateTimeRo(item.inregistrat_la))));
        if (item.verificat_at) {
            extra.push(field('Verificat', esc(dateTimeRo(item.verificat_at)) + (item.verificat_de ? ' · ' + esc(item.verificat_de) : '')));
        }

        detailBody.innerHTML =
            '<div class="rv-hd-grid">' +
                '<div class="rv-hd-vehicle"><span class="rv-hd-truck">' + TRUCK + '</span><div><strong>' + esc(item.nr) + '</strong><small>' + esc(item.marca || item.vehicul_tip) + '</small></div></div>' +
                '<div class="rv-hd-fields">' +
                    field('Categorie', '<span class="rv-hist-cat rv-hist-cat-' + esc(item.categorie) + '">' + (CATEGORY_ICONS[item.categorie] || '') + '</span>' + esc(item.categorie_label)) +
                    field('Componentă', esc(item.componenta)) +
                    field('Tip intervenție', esc(item.tip_interventie)) +
                    field('Data', esc(dateRo(item.data)) + ' <small>' + esc(String(item.data).slice(11, 16)) + '</small>') +
                    field('Km la intervenție', km(item.km)) +
                    field('Operator', item.operator ? esc(item.operator) : '<span class="text-muted">Nespecificat</span>') +
                    field('Status', statusBadge(item)) +
                '</div>' +
                '<div class="rv-hd-side">' +
                    '<div class="rv-hd-docs-head"><span>Documente atașate</span>' + (can.documents ? '<button type="button" class="btn btn-link btn-sm p-0" data-act="upload"><i class="bi bi-plus-lg"></i> Adaugă</button>' : '') + '</div>' +
                    '<div class="rv-hd-docs">' + docsHtml + '</div>' +
                    '<div class="rv-hd-notes"><span>Observații</span><p>' + (item.observatii ? esc(item.observatii) : '<span class="text-muted">—</span>') + '</p></div>' +
                '</div>' +
            '</div>' +
            '<details class="rv-hd-extra"><summary>Mai multe detalii (T0, ansamblu, ciclu încheiat, audit)</summary><div class="rv-hd-extra-grid">' + extra.join('') + '</div></details>';
        detailCard.hidden = false;
        detailCard.setAttribute('data-id', item.id);
    }

    function selectRow(id, scroll) {
        state.selected = id;
        body.querySelectorAll('tr.rv-row').forEach(function (tr) { tr.classList.toggle('is-open', Number(tr.getAttribute('data-id')) === id); });
        return fetch(actionUrl('istoric_detail', { id: id }), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.ok || state.selected !== id) { return; }
                renderDetail(res.item);
                if (scroll) {
                    var rect = detailCard.getBoundingClientRect();
                    if (rect.top > window.innerHeight - 80 || rect.bottom < 0) { detailCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                }
            })
            .catch(function () { showToast('Detaliile nu au putut fi încărcate.', 'error'); });
    }

    // ------------------------------------------------------------ actiuni pe interventie
    var uploadTarget = null;
    function rowById(id) { return state.rows.filter(function (r) { return r.id === id; })[0] || null; }
    function afterChange(res) {
        if (!res.ok) { showToast(res.message || 'Operația nu a reușit.', 'error'); return; }
        showToast(res.message);
        if (res.item && state.selected === res.item.id) { renderDetail(res.item); }
        load(); // KPI-urile (documente / finalizate) si tabelul se schimba
    }
    function runAction(act, id) {
        if (act === 'detail') { selectRow(id, true); return; }
        if (act === 'verify' || act === 'unverify') {
            post('istoric_verify', { id: id, verificat: act === 'verify' ? '1' : '0' }).then(afterChange);
            return;
        }
        if (act === 'reference') {
            var row = rowById(id) || {};
            var modal = document.getElementById('rvRefModal');
            document.getElementById('rvRefFor').textContent = (row.nr || '') + ' · ' + (row.componenta || '') + ' · ' + dateTimeRo(row.data);
            document.getElementById('rvRefInput').value = row.referinta || '';
            modal.setAttribute('data-id', id);
            if (window.bootstrap) { window.bootstrap.Modal.getOrCreateInstance(modal).show(); }
            return;
        }
        if (act === 'upload' && fileInput) {
            uploadTarget = id;
            fileInput.value = '';
            fileInput.click();
        }
    }

    body.addEventListener('click', function (ev) {
        var tr = ev.target.closest('tr.rv-row');
        if (!tr) { return; }
        var id = Number(tr.getAttribute('data-id'));
        var actEl = ev.target.closest('[data-act]');
        if (actEl) {
            runAction(actEl.getAttribute('data-act'), id);
            return;
        }
        if (ev.target.closest('.dropdown, a, button')) { return; }
        selectRow(id, true);
    });
    body.addEventListener('keydown', function (ev) {
        var tr = ev.target.closest('tr.rv-row');
        if (tr && ev.target === tr && (ev.key === 'Enter' || ev.key === ' ')) {
            ev.preventDefault();
            selectRow(Number(tr.getAttribute('data-id')), true);
        }
    });

    if (detailCard) {
        detailCard.addEventListener('click', function (ev) {
            var id = Number(detailCard.getAttribute('data-id'));
            if (ev.target.closest('[data-docs-more]')) {
                detailCard.querySelectorAll('[data-extra-doc]').forEach(function (el) { el.hidden = false; });
                ev.target.closest('[data-docs-more]').remove();
                return;
            }
            var actEl = ev.target.closest('[data-act]');
            if (actEl) { runAction(actEl.getAttribute('data-act'), id); return; }
            var rm = ev.target.closest('[data-doc-remove]');
            if (rm) {
                // Confirmare in doi pasi (confirm() e blocat in unele browsere integrate).
                if (!rm.classList.contains('is-confirm')) {
                    rm.classList.add('is-confirm');
                    rm.innerHTML = 'Elimini?';
                    setTimeout(function () { if (rm.isConnected) { rm.classList.remove('is-confirm'); rm.innerHTML = '<i class="bi bi-x"></i>'; } }, 4000);
                    return;
                }
                post('istoric_doc_delete', { id: rm.getAttribute('data-doc-remove') }).then(afterChange);
            }
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            if (!fileInput.files.length || !uploadTarget) { return; }
            var fd = new FormData();
            fd.append('istoric_id', uploadTarget);
            Array.prototype.forEach.call(fileInput.files, function (f) { fd.append('documente[]', f); });
            showToast('Se încarcă ' + fileInput.files.length + (fileInput.files.length === 1 ? ' fișier…' : ' fișiere…'), 'info');
            var target = uploadTarget;
            post('istoric_doc_upload', fd).then(function (res) {
                if (res.ok && state.selected !== target) { selectRow(target, true); }
                afterChange(res);
            });
        });
    }

    var refForm = document.getElementById('rvRefForm');
    if (refForm) {
        refForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var modal = document.getElementById('rvRefModal');
            post('istoric_reference', { id: modal.getAttribute('data-id'), referinta: document.getElementById('rvRefInput').value })
                .then(function (res) {
                    if (res.ok && window.bootstrap) { window.bootstrap.Modal.getOrCreateInstance(modal).hide(); }
                    afterChange(res);
                });
        });
    }

    // ------------------------------------------------------------ filtre, sortare, perioade rapide
    function quickRange(kind) {
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        if (kind === '30') {
            var from = new Date(today);
            from.setDate(from.getDate() - 29);
            return [iso(from), iso(today)];
        }
        if (kind === '6m') {
            var start = new Date(today);
            start.setMonth(start.getMonth() - 6);
            start.setDate(start.getDate() + 1);
            return [iso(start), iso(today)];
        }
        return [today.getFullYear() + '-01-01', today.getFullYear() + '-12-31'];
    }
    function updateQuickButtons() {
        root.querySelectorAll('[data-quick]').forEach(function (btn) {
            var range = quickRange(btn.getAttribute('data-quick'));
            var active = form.de_la.value === range[0] && form.pana_la.value === range[1];
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }
    root.querySelectorAll('[data-quick]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var range = quickRange(btn.getAttribute('data-quick'));
            form.de_la.value = range[0];
            form.pana_la.value = range[1];
            state.page = 1;
            load();
        });
    });

    var searchTimer = null;
    form.q.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { state.page = 1; load(); }, 300);
    });
    ['de_la', 'pana_la', 'categorie', 'vehicul', 'status', 'operator'].forEach(function (name) {
        form[name].addEventListener('change', function () { state.page = 1; load(); });
    });
    document.getElementById('rvHistReset').addEventListener('click', function () {
        var range = quickRange('an');
        form.q.value = '';
        form.de_la.value = range[0];
        form.pana_la.value = range[1];
        ['categorie', 'vehicul', 'status', 'operator'].forEach(function (name) { form[name].value = ''; });
        state.sort = 'data';
        state.dir = 'desc';
        state.page = 1;
        if (yearSelect) { yearSelect.value = String(new Date().getFullYear()); }
        load();
    });
    if (yearSelect) {
        yearSelect.addEventListener('change', function () { load(); });
    }
    root.querySelectorAll('.rv-hist-table th[data-sort]').forEach(function (th) {
        th.tabIndex = 0;
        var toggle = function () {
            var key = th.getAttribute('data-sort');
            if (state.sort === key) {
                state.dir = state.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sort = key;
                state.dir = key === 'data' || key === 'km' || key === 'documente' ? 'desc' : 'asc';
            }
            state.page = 1;
            load('tabel');
        };
        th.addEventListener('click', toggle);
        th.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggle(); } });
    });

    // ------------------------------------------------------------ start: datele primei pagini vin randate de server
    render(data.initial || {});
})();
