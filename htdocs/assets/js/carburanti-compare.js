/**
 * Carburanți - widgetul de comparație vehicule (tab "Comparație").
 *
 * Acelasi model ca Dashboard Analitic V2: serverul trimite randurile agregate,
 * iar selectia vehiculelor, metricile si graficele se construiesc aici cu Chart.js,
 * fara request nou la fiecare interactiune.
 */
(function () {
    'use strict';

    var root = document.getElementById('fuelCompareRoot');
    if (!root || typeof Chart === 'undefined') {
        return;
    }

    var configEl = document.getElementById('fuelCompareConfig');
    var config = {};
    try {
        config = JSON.parse(configEl ? configEl.textContent : '{}') || {};
    } catch (error) {
        config = {};
    }

    var rows = Array.isArray(config.rows) ? config.rows : [];
    var daily = Array.isArray(config.daily) ? config.daily : [];
    var fleetAvg = Number(config.fleetAvg) || 0;

    // ------------------------------------------------------------- formatare

    var nfInt = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var nf1 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
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
            case 'l100': return nf2.format(v) + ' L/100 km';
            case 'litri': return nf1.format(v) + ' L';
            case 'km': return nfInt.format(Math.round(v)) + ' km';
            case 'lei': return nf2.format(v) + ' lei';
            case 'lei3': return nf3.format(v) + ' lei';
            case 'pct': return nf2.format(v) + '%';
            default: return nf2.format(v);
        }
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // -------------------------------------------------------------- metrici

    var METRICS = {
        consum_motorina: { label: 'Consum motorină', kind: 'l100', better: 'low' },
        cost_km: { label: 'Cost / km', kind: 'lei3', better: 'low' },
        km: { label: 'Km parcurși', kind: 'km', better: 'high' },
        motorina: { label: 'Motorină', kind: 'litri', better: 'neutral' },
        adblue: { label: 'AdBlue', kind: 'litri', better: 'neutral' },
        consum_adblue: { label: 'AdBlue %', kind: 'pct', better: 'low' },
        cost: { label: 'Cost total', kind: 'lei', better: 'neutral' },
        pret_mediu: { label: 'Preț mediu', kind: 'lei3', better: 'low' },
        alimentari: { label: 'Alimentări', kind: 'int', better: 'neutral' }
    };

    var COMPARE_METRICS = [
        'consum_motorina', 'cost_km', 'km', 'motorina', 'adblue',
        'consum_adblue', 'cost', 'pret_mediu', 'alimentari'
    ];

    function metricLabel(key) {
        return (METRICS[key] && METRICS[key].label) || key;
    }

    function metricKind(key) {
        return (METRICS[key] && METRICS[key].kind) || 'num';
    }

    function fmtMetric(key, value) {
        return fmt(value, metricKind(key));
    }

    // -------------------------------------------------------------- culori

    var PALETTE = [
        '#2563eb', '#f97316', '#10b981', '#a855f7', '#ef4444', '#0ea5e9',
        '#eab308', '#14b8a6', '#ec4899', '#8b5cf6', '#22c55e', '#f43f5e'
    ];

    function color(index) {
        return PALETTE[index % PALETTE.length];
    }

    function alpha(hex, value) {
        var r = parseInt(hex.slice(1, 3), 16);
        var g = parseInt(hex.slice(3, 5), 16);
        var b = parseInt(hex.slice(5, 7), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + value + ')';
    }

    // --------------------------------------------------------------- stare

    var charts = {};
    var state = {
        view: 'bars',
        metrics: { consum_motorina: true, cost_km: true },
        selection: [],
        search: ''
    };

    function rowByName(name) {
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].nume === name) {
                return rows[i];
            }
        }
        return null;
    }

    function dailyByName(name) {
        for (var i = 0; i < daily.length; i++) {
            if (daily[i].nume === name) {
                return daily[i];
            }
        }
        return null;
    }

    function selectedRows() {
        return state.selection.map(rowByName).filter(Boolean);
    }

    function activeMetrics() {
        return COMPARE_METRICS.filter(function (key) {
            return state.metrics[key];
        });
    }

    function topByKm(limit) {
        return rows.slice().sort(function (a, b) {
            return num(b.km) - num(a.km);
        }).slice(0, limit).map(function (row) {
            return row.nume;
        });
    }

    // Selectia initiala: vehiculele din filtrul paginii, altfel primele 5 dupa km.
    (function initSelection() {
        var preselected = Array.isArray(config.preselected) ? config.preselected : [];
        var valid = preselected.filter(function (name) {
            return !!rowByName(name);
        });
        state.selection = valid.length ? valid : topByKm(5);
    }());

    // -------------------------------------------------------------- grafice

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
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
                tooltip: { padding: 10, boxPadding: 4 }
            }
        }, extra || {});
    }

    // Linia mediei flotei, desenata peste axa consumului (acolo unde are sens).
    var fleetAvgPlugin = {
        id: 'fuelFleetAverage',
        afterDatasetsDraw: function (chart, args, options) {
            var value = options && options.value;
            var scaleId = options && options.scaleId;
            if (!value || !scaleId || !chart.scales[scaleId]) {
                return;
            }
            var scale = chart.scales[scaleId];
            var y = scale.getPixelForValue(value);
            var area = chart.chartArea;
            if (!area || y < area.top || y > area.bottom) {
                return;
            }
            var ctx = chart.ctx;
            ctx.save();
            ctx.setLineDash([6, 6]);
            ctx.lineWidth = 2;
            ctx.strokeStyle = '#94a3b8';
            ctx.beginPath();
            ctx.moveTo(area.left, y);
            ctx.lineTo(area.right, y);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = '#64748b';
            ctx.font = '600 11px system-ui, sans-serif';
            ctx.textAlign = 'right';
            ctx.fillText('medie flotă ' + nf2.format(value), area.right - 4, y - 5);
            ctx.restore();
        }
    };

    // --------------------------------------------------------------- randare

    function render() {
        renderMetricChips();
        renderList();
        renderMainChart();
        renderRadar();
        renderMix();
        renderTable();
        syncSegments();
    }

    function syncSegments() {
        var seg = root.querySelector('[data-fuel-seg="view"]');
        if (!seg) {
            return;
        }
        Array.prototype.forEach.call(seg.querySelectorAll('button'), function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-value') === state.view);
        });
        document.getElementById('fuelCompareMetrics').hidden = state.view === 'evolution';
    }

    function renderMetricChips() {
        document.getElementById('fuelCompareMetrics').innerHTML = COMPARE_METRICS.map(function (key, index) {
            var active = !!state.metrics[key];
            return '<button type="button" class="da2-metric-chip' + (active ? ' is-active' : '') +
                '" data-fuel-metric="' + key + '" style="--chip-color:' + color(index) + '">' +
                '<span class="da2-metric-dot"></span>' + escapeHtml(metricLabel(key)) + '</button>';
        }).join('');
    }

    function renderList() {
        var container = document.getElementById('fuelCompareList');
        var search = state.search.toLowerCase();
        var visible = rows.filter(function (row) {
            return !search || String(row.nume).toLowerCase().indexOf(search) !== -1;
        });

        if (!visible.length) {
            container.innerHTML = '<p class="da2-empty">Niciun vehicul disponibil.</p>';
            return;
        }

        container.innerHTML = visible.map(function (row) {
            var checked = state.selection.indexOf(row.nume) !== -1;
            var consum = num(row.consum_motorina) > 0 ? fmt(row.consum_motorina, 'l100') : 'fără consum';
            return '<label class="da2-compare-item' + (checked ? ' is-selected' : '') + '">' +
                '<input type="checkbox" data-fuel-entity="' + escapeHtml(row.nume) + '"' + (checked ? ' checked' : '') + '>' +
                '<span class="da2-compare-name">' + escapeHtml(row.nume) + '</span>' +
                '<span class="da2-compare-meta">' + escapeHtml(consum) + ' · ' + escapeHtml(fmt(row.km, 'km')) + '</span>' +
                '</label>';
        }).join('');
    }

    function renderMainChart() {
        if (state.view === 'evolution') {
            renderEvolutionChart();
            return;
        }
        renderBarsChart();
    }

    function setNote(text) {
        var note = document.getElementById('fuelCompareNote');
        note.textContent = text || '';
        note.hidden = !text;
    }

    function renderBarsChart() {
        var canvas = document.getElementById('fuelCompareChart');
        destroyChart('main', canvas);

        var selected = selectedRows();
        var metrics = activeMetrics();

        if (!selected.length) {
            setNote('');
            drawEmpty(canvas, 'Selectează cel puțin un vehicul din listă.');
            return;
        }
        if (!metrics.length) {
            setNote('');
            drawEmpty(canvas, 'Selectează cel puțin o metrică.');
            return;
        }

        // Vehiculele sunt pe axa X, fiecare metrica are propria axa Y: asa raman
        // comparabile chiar daca unitatile difera (L/100 km vs. lei vs. km).
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

        var avgIndex = metrics.indexOf('consum_motorina');
        var noConsum = selected.filter(function (row) {
            return num(row.consum_motorina) <= 0;
        }).map(function (row) {
            return row.nume;
        });
        setNote(metrics.indexOf('consum_motorina') !== -1 && noConsum.length
            ? 'Fără consum calculabil (lipsesc km din odometru sau curse asociate): ' + noConsum.join(', ') + '.'
            : '');

        charts.main = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: selected.map(function (row) { return row.nume; }),
                datasets: metrics.map(function (key, index) {
                    return {
                        label: metricLabel(key),
                        data: selected.map(function (row) { return num(row[key]); }),
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
                scales: scales,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    fuelFleetAverage: avgIndex === -1 || fleetAvg <= 0
                        ? {}
                        : { value: fleetAvg, scaleId: 'y' + avgIndex },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + fmtMetric(context.dataset.metricKey, context.parsed.y);
                            }
                        }
                    }
                }
            }),
            plugins: [fleetAvgPlugin]
        });
    }

    function renderEvolutionChart() {
        var canvas = document.getElementById('fuelCompareChart');
        destroyChart('main', canvas);

        var selected = selectedRows();
        var withData = [];
        var withoutData = [];
        selected.forEach(function (row) {
            var series = dailyByName(row.nume);
            if (series && series.values && series.values.length) {
                withData.push(series);
            } else {
                withoutData.push(row.nume);
            }
        });

        if (!withData.length) {
            setNote(daily.length
                ? 'Seriile zilnice există doar pentru vehiculele alese în filtrul paginii. Bifează vehiculele acolo (cel puțin 2) și revino aici.'
                : 'Alege cel puțin 2 vehicule în filtrul paginii pentru a avea evoluția zilnică.');
            drawEmpty(canvas, 'Niciun vehicul selectat nu are serie zilnică.');
            return;
        }

        setNote(withoutData.length
            ? 'Fără serie zilnică (nu sunt în filtrul paginii): ' + withoutData.join(', ') + '.'
            : '');

        var labels = withData[0].labels || [];

        charts.main = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: withData.map(function (series, index) {
                    return {
                        label: series.nume + (num(series.average) > 0 ? ' (medie ' + nf2.format(series.average) + ')' : ''),
                        // Zilele fara alimentare/km vin cu 0: le trecem ca goluri,
                        // altfel linia cade la zero si ascunde trendul real.
                        data: (series.values || []).map(function (value) {
                            var parsed = num(value);
                            return parsed > 0 ? parsed : null;
                        }),
                        borderColor: color(index),
                        backgroundColor: alpha(color(index), 0.12),
                        borderWidth: 2,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        tension: 0.25,
                        spanGaps: true
                    };
                })
            },
            options: baseOptions({
                scales: {
                    y: {
                        title: { display: true, text: 'Consum motorină (L/100 km)' },
                        ticks: { callback: function (value) { return nf2.format(value); } }
                    }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    fuelFleetAverage: fleetAvg > 0 ? { value: fleetAvg, scaleId: 'y' } : {},
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + fmt(context.parsed.y, 'l100');
                            }
                        }
                    }
                }
            }),
            plugins: [fleetAvgPlugin]
        });
    }

    function renderRadar() {
        var canvas = document.getElementById('fuelCompareRadar');
        destroyChart('radar', canvas);

        var selected = selectedRows();
        var metrics = activeMetrics();

        if (!selected.length || metrics.length < 3) {
            drawEmpty(canvas, 'Alege cel puțin 3 metrici și un vehicul.');
            return;
        }

        var maxima = metrics.map(function (key) {
            return selected.reduce(function (max, row) {
                return Math.max(max, Math.abs(num(row[key])));
            }, 0) || 1;
        });

        charts.radar = new Chart(canvas, {
            type: 'radar',
            data: {
                labels: metrics.map(metricLabel),
                datasets: selected.map(function (row, index) {
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

    function renderMix() {
        var canvas = document.getElementById('fuelCompareMix');
        destroyChart('mix', canvas);

        var selected = selectedRows();
        if (!selected.length) {
            drawEmpty(canvas, 'Selectează cel puțin un vehicul din listă.');
            return;
        }

        var series = [
            { key: 'motorina', label: 'Motorină', color: '#2563eb' },
            { key: 'adblue', label: 'AdBlue', color: '#0891b2' }
        ];

        charts.mix = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: selected.map(function (row) { return row.nume; }),
                datasets: series.map(function (item) {
                    return {
                        label: item.label,
                        data: selected.map(function (row) { return num(row[item.key]); }),
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
                                return context.dataset.label + ': ' + fmt(context.parsed.y, 'litri');
                            }
                        }
                    }
                }
            })
        });
    }

    function renderTable() {
        var table = document.getElementById('fuelCompareTable');
        var selected = selectedRows();
        var metrics = activeMetrics();

        if (!selected.length || !metrics.length) {
            table.innerHTML = '<tbody><tr><td class="da2-empty">Selectează vehicule și metrici pentru comparație.</td></tr></tbody>';
            return;
        }

        var averages = {};
        metrics.forEach(function (key) {
            averages[key] = selected.reduce(function (sum, row) {
                return sum + num(row[key]);
            }, 0) / selected.length;
        });

        var head = '<thead><tr><th class="da2-sticky-col">Metrică</th>' +
            selected.map(function (row) {
                return '<th class="da2-num">' + escapeHtml(row.nume) + '</th>';
            }).join('') +
            '<th class="da2-num da2-col-avg">Media selecției</th></tr></thead>';

        var body = '<tbody>' + metrics.map(function (key) {
            var better = (METRICS[key] && METRICS[key].better) || 'high';
            return '<tr><th class="da2-sticky-col">' + escapeHtml(metricLabel(key)) + '</th>' +
                selected.map(function (row) {
                    var value = num(row[key]);
                    var delta = value - averages[key];
                    var deltaClass = 'da2-delta-flat';
                    if (better !== 'neutral' && Math.abs(delta) >= 0.005) {
                        deltaClass = (better === 'high' ? delta > 0 : delta < 0) ? 'da2-delta-up' : 'da2-delta-down';
                    }
                    var sign = delta > 0 ? '+' : '';
                    return '<td class="da2-num"><span class="da2-cell-value">' + escapeHtml(fmtMetric(key, value)) + '</span>' +
                        '<span class="da2-delta ' + deltaClass + '">' + sign + escapeHtml(fmtMetric(key, delta)) + '</span></td>';
                }).join('') +
                '<td class="da2-num da2-col-avg">' + escapeHtml(fmtMetric(key, averages[key])) + '</td></tr>';
        }).join('') + '</tbody>';

        table.innerHTML = head + body;
    }

    // -------------------------------------------------------------- evenimente

    root.addEventListener('click', function (event) {
        var segButton = event.target.closest('[data-fuel-seg] button');
        if (segButton) {
            state.view = segButton.getAttribute('data-value');
            syncSegments();
            renderMainChart();
            return;
        }

        var chip = event.target.closest('[data-fuel-metric]');
        if (chip) {
            var key = chip.getAttribute('data-fuel-metric');
            state.metrics[key] = !state.metrics[key];
            renderMetricChips();
            renderMainChart();
            renderRadar();
            renderTable();
        }
    });

    document.getElementById('fuelCompareList').addEventListener('change', function (event) {
        var input = event.target.closest('[data-fuel-entity]');
        if (!input) {
            return;
        }
        var name = input.getAttribute('data-fuel-entity');
        var index = state.selection.indexOf(name);
        if (index === -1) {
            state.selection.push(name);
        } else {
            state.selection.splice(index, 1);
        }
        render();
    });

    document.getElementById('fuelCompareSearch').addEventListener('input', function (event) {
        state.search = event.target.value;
        renderList();
    });

    document.getElementById('fuelCompareTop').addEventListener('click', function () {
        state.selection = topByKm(5);
        render();
    });

    document.getElementById('fuelCompareClear').addEventListener('click', function () {
        state.selection = [];
        render();
    });

    // Chart.js nu recalculeaza dimensiunile cat timp canvas-ul e ascuns (tab inactiv).
    document.addEventListener('shown.bs.tab', function () {
        Object.keys(charts).forEach(function (key) {
            charts[key].resize();
        });
    });

    render();
}());
