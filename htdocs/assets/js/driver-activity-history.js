(function () {
    'use strict';

    var dataNode = document.getElementById('driver-history-chart-data');
    var chartData = {};

    if (dataNode) {
        try {
            chartData = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            chartData = {};
        }
    }

    var hasValues = function (values) {
        return Array.isArray(values) && values.some(function (value) {
            return Number(value) > 0;
        });
    };

    var setEmptyState = function (canvasId, values) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) {
            return false;
        }

        var wrapper = canvas.closest('[data-chart-wrapper]');
        var isEmpty = !hasValues(values);

        if (wrapper) {
            wrapper.classList.toggle('is-empty', isEmpty);
        }

        return !isEmpty;
    };

    var defaultGrid = {
        color: 'rgba(226, 232, 240, 0.9)',
        drawBorder: false
    };

    var defaultTicks = {
        color: '#475569',
        font: {
            size: 11,
            weight: '600'
        }
    };

    /*
     * Graficele stau intr-un comutator (un singur grafic vizibil). Cel ascuns nu se
     * construieste la incarcare, ci prima data cand e ales (Chart.js nu poate masura
     * un canvas ascuns, iar asa pagina porneste cu un singur grafic).
     */
    var pendingCharts = {};

    var createChart = function (canvasId, values, configFactory) {
        if (!setEmptyState(canvasId, values) || typeof Chart === 'undefined') {
            return;
        }

        var canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }

        var pane = canvas.closest('[data-chart-pane]');
        if (pane && pane.hidden) {
            pendingCharts[canvasId] = function () {
                new Chart(canvas, configFactory(canvas));
            };
            return;
        }

        new Chart(canvas, configFactory(canvas));
    };

    document.querySelectorAll('[data-chart-switcher]').forEach(function (switcher) {
        var storageKey = 'driver-history-chart:' + switcher.getAttribute('data-chart-switcher');
        var title = switcher.querySelector('[data-chart-switcher-title]');
        var tabs = switcher.querySelectorAll('[data-chart-tab]');
        var panes = switcher.querySelectorAll('[data-chart-pane]');

        var select = function (key, remember) {
            var pane = switcher.querySelector('[data-chart-pane="' + key + '"]');
            if (!pane) {
                return;
            }
            panes.forEach(function (item) {
                item.hidden = item !== pane;
            });
            tabs.forEach(function (tab) {
                tab.setAttribute('aria-selected', tab.getAttribute('data-chart-tab') === key ? 'true' : 'false');
            });
            if (title) {
                title.textContent = pane.getAttribute('data-chart-title') || '';
            }
            var canvas = pane.querySelector('canvas');
            if (canvas && pendingCharts[canvas.id]) {
                var build = pendingCharts[canvas.id];
                delete pendingCharts[canvas.id];
                build();
            }
            switcher.dispatchEvent(new CustomEvent('chartswitch', { detail: { key: key } }));
            if (remember) {
                try {
                    window.localStorage.setItem(storageKey, key);
                } catch (error) {
                    // localStorage poate lipsi in modurile restrictive ale browserului.
                }
            }
        };

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                select(tab.getAttribute('data-chart-tab'), true);
            });
        });

        // Graficul ales ultima data; se aplica inainte de construirea graficelor.
        try {
            var saved = window.localStorage.getItem(storageKey);
            if (saved) {
                select(saved, false);
            }
        } catch (error) {
            // Fara localStorage ramane primul grafic.
        }
    });

    var tons = chartData.tons || {};
    createChart(
        'driver_history_tons_chart',
        (tons.transported || []).concat(tons.delivered || []),
        function () {
            return {
                type: 'bar',
                data: {
                    labels: tons.labels || [],
                    datasets: [
                        {
                            label: 'Transportate',
                            data: tons.transported || [],
                            backgroundColor: '#0d6efd',
                            borderRadius: 5,
                            maxBarThickness: 28
                        },
                        {
                            label: 'Livrate',
                            data: tons.delivered || [],
                            backgroundColor: '#22c55e',
                            borderRadius: 5,
                            maxBarThickness: 28
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                color: '#334155',
                                font: { size: 11, weight: '700' }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: defaultTicks },
                        y: { beginAtZero: true, grid: defaultGrid, ticks: defaultTicks }
                    }
                }
            };
        }
    );

    var kilometers = chartData.kilometers_timeline || {};
    createChart(
        'driver_history_km_chart',
        kilometers.values || [],
        function () {
            return {
                type: 'bar',
                data: {
                    labels: kilometers.labels || [],
                    datasets: [{
                        label: 'Km parcursi',
                        data: kilometers.values || [],
                        backgroundColor: '#0d6efd',
                        borderRadius: 5,
                        maxBarThickness: 34
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: defaultTicks },
                        y: { beginAtZero: true, grid: defaultGrid, ticks: defaultTicks }
                    }
                }
            };
        }
    );

    var fuel = chartData.fuel_timeline || {};
    createChart(
        'driver_history_fuel_chart',
        fuel.values || [],
        function (canvas) {
            var context = canvas.getContext('2d');
            var gradient = context.createLinearGradient(0, 0, 0, canvas.clientHeight || 220);
            gradient.addColorStop(0, 'rgba(34, 197, 94, 0.28)');
            gradient.addColorStop(1, 'rgba(34, 197, 94, 0.03)');

            return {
                type: 'line',
                data: {
                    labels: fuel.labels || [],
                    datasets: [{
                        label: 'Consum combustibil',
                        data: fuel.values || [],
                        borderColor: '#16a34a',
                        backgroundColor: gradient,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#16a34a',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: defaultTicks },
                        y: { beginAtZero: true, grid: defaultGrid, ticks: defaultTicks }
                    }
                }
            };
        }
    );

    var createDoughnut = function (canvasId, data, colors) {
        createChart(canvasId, data.values || [], function () {
            return {
                type: 'doughnut',
                data: {
                    labels: data.labels || [],
                    datasets: [{
                        data: data.values || [],
                        backgroundColor: colors,
                        borderColor: '#ffffff',
                        borderWidth: 2,
                        hoverOffset: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                color: '#334155',
                                font: { size: 11, weight: '700' }
                            }
                        }
                    }
                }
            };
        });
    };

    createDoughnut('driver_history_cost_chart', chartData.cost_distribution || {}, [
        '#0d6efd',
        '#fb5f72',
        '#f59e0b',
        '#7c3aed',
        '#16a34a'
    ]);

    createDoughnut('driver_history_transport_chart', chartData.transport_distribution || {}, [
        '#0d6efd',
        '#22c55e',
        '#f59e0b',
        '#7c3aed'
    ]);

    /*
     * Comparatie: graficele trebuie sa ramana lizibile si cu 50+ soferi.
     * - Kilometri / Consum / Costuri: bare orizontale ordonate, un rand pe sofer (~40 px),
     *   valoarea scrisa permanent la capatul barei, intr-o zona care se deruleaza.
     * - Evolutie kilometri: cate o linie pe sofer; soferul activ iese in fata, ceilalti se
     *   estompeaza, iar click pe o zi fixeaza valorile zilei in panoul de alaturi.
     * Cautarea, sortarea si evidentierea sunt doar vizuale: filtrul global nu se schimba.
     * Tooltip-ul aduce doar context suplimentar; valorile principale sunt mereu vizibile.
     */
    var compare = chartData.compare || null;
    var compareSwitcher = document.querySelector('[data-chart-switcher="compare"]');
    if (compare && compareSwitcher && typeof Chart !== 'undefined') {
        var drivers = compare.drivers || [];
        var ROW_HEIGHT = 40;
        var LABEL_FONT = '700 12px ' + Chart.defaults.font.family;
        var measureContext = document.createElement('canvas').getContext('2d');

        // Format romanesc: 5.820 / 8.420,50 (fara Intl, ca si numerele de 4 cifre sa aiba punct).
        var fmt = function (value, decimals) {
            var number = Number(value) || 0;
            var parts = Math.abs(number).toFixed(decimals).split('.');
            return (number < 0 ? '-' : '') + parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (parts[1] ? ',' + parts[1] : '');
        };
        var fold = function (text) {
            return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        };
        var rgba = function (hex, alpha) {
            var value = parseInt(hex.slice(1), 16);
            return 'rgba(' + (value >> 16) + ', ' + ((value >> 8) & 255) + ', ' + (value & 255) + ', ' + alpha + ')';
        };
        var textWidth = function (text) {
            measureContext.font = LABEL_FONT;
            return measureContext.measureText(text).width;
        };
        var byName = function (a, b) {
            return drivers[a].localeCompare(drivers[b], 'ro');
        };

        // Starea comuna tuturor graficelor: soferul fixat prin click si soferii gasiti la cautare.
        var focus = { pinned: null, matches: [] };
        var hasFocus = function () {
            return focus.pinned !== null || focus.matches.length > 0;
        };
        var isActive = function (index) {
            return focus.pinned === index || focus.matches.indexOf(index) !== -1;
        };
        var views = {};
        var refreshAll = function () {
            Object.keys(views).forEach(function (key) { views[key].refresh(); });
        };
        var togglePinned = function (index) {
            focus.pinned = focus.pinned === index ? null : index;
            refreshAll();
        };

        /*
         * Bare orizontale ordonate. options: datasets [{label, values, color}], total(i)
         * (null = fara date), label(i) (textul permanent), tooltip(i) (linii suplimentare),
         * showItems (componentele barei in tooltip) si legend (doar la costuri: 5 componente).
         */
        var rankedChart = function (key, canvasId, options) {
            var canvas = document.getElementById(canvasId);
            if (!canvas) {
                return;
            }
            var pane = canvas.closest('[data-chart-pane]');
            var holder = pane.querySelector('[data-rank-canvas]');
            var scroller = pane.querySelector('[data-rank-scroll]');
            /*
             * Pe grafic apar doar soferii cu valoare (> 0). Ceilalti (ex. curse fara km
             * completati) nu au ce bara sa arate: sunt numiti in nota de sub grafic, ca sa
             * nu dispara fara urma. In tabelul de sumar raman, cu activitatea lor.
             */
            var included = [];
            var excluded = [];
            drivers.forEach(function (name, index) {
                var value = options.total(index);
                (value !== null && value > 0 ? included : excluded).push(index);
            });
            var state = { sort: 'desc', order: included.slice() };
            var valueWidth = Math.max.apply(null, included.map(function (index) { return textWidth(options.label(index)); }).concat([40])) + 14;
            if (excluded.length && scroller) {
                var note = document.createElement('p');
                note.className = 'driver-history-chart-note';
                var names = excluded.slice().sort(byName).map(function (index) { return drivers[index]; });
                note.textContent = options.missingText + ' (' + names.length + '): ' + names.join(', ') + '.';
                scroller.insertAdjacentElement('afterend', note);
            }

            var sortOrder = function () {
                state.order = included.slice().sort(function (a, b) {
                    if (state.sort === 'name') {
                        return byName(a, b);
                    }
                    var first = options.total(a);
                    var second = options.total(b);
                    // Soferii fara date raman la final, indiferent de sens.
                    if (first === null || second === null) {
                        return first === second ? byName(a, b) : (first === null ? 1 : -1);
                    }
                    return (state.sort === 'asc' ? first - second : second - first) || byName(a, b);
                });
            };
            var datasetValues = function (values) {
                return state.order.map(function (index) { return Number(values[index]) || 0; });
            };
            // Numele lungi se scurteaza pe axa; numele intreg e in tooltip.
            var shortName = function (name, chartWidth) {
                var limit = Math.max(90, Math.min(230, chartWidth * 0.34));
                if (textWidth(name) <= limit) {
                    return name;
                }
                var cut = name;
                while (cut.length > 3 && textWidth(cut + '…') > limit) {
                    cut = cut.slice(0, -1);
                }
                return cut.trim() + '…';
            };
            var chart = function () {
                return Chart.getChart(canvas);
            };

            var valueLabels = {
                id: 'rankValueLabels',
                afterDatasetsDraw: function (instance) {
                    var metas = instance.getSortedVisibleDatasetMetas();
                    var context = instance.ctx;
                    context.save();
                    context.font = LABEL_FONT;
                    context.textBaseline = 'middle';
                    context.textAlign = 'left';
                    state.order.forEach(function (driverIndex, row) {
                        var end = instance.scales.x.getPixelForValue(0);
                        var y = null;
                        metas.forEach(function (meta) {
                            var element = meta.data[row];
                            if (element) {
                                end = Math.max(end, element.x);
                                y = element.y;
                            }
                        });
                        if (y === null) {
                            return;
                        }
                        var missing = options.total(driverIndex) === null;
                        context.fillStyle = missing || (hasFocus() && !isActive(driverIndex)) ? '#94a3b8' : '#0f172a';
                        context.fillText(options.label(driverIndex), end + 6, y);
                    });
                    context.restore();
                }
            };

            createChart(canvasId, drivers.map(function (name, index) { return options.total(index) || 0; }), function () {
                sortOrder();
                holder.style.height = (state.order.length * ROW_HEIGHT + 12) + 'px';
                return {
                    type: 'bar',
                    data: {
                        labels: state.order.map(function (index) { return drivers[index]; }),
                        datasets: options.datasets.map(function (dataset) {
                            return {
                                label: dataset.label,
                                data: datasetValues(dataset.values),
                                backgroundColor: function (context) {
                                    var index = state.order[context.dataIndex];
                                    return hasFocus() && !isActive(index) ? rgba(dataset.color, 0.22) : dataset.color;
                                },
                                hoverBackgroundColor: dataset.color,
                                borderRadius: 4,
                                barPercentage: 0.72,
                                categoryPercentage: 0.9,
                                maxBarThickness: 24,
                                stack: 'total'
                            };
                        })
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 250 },
                        layout: { padding: { top: 4, bottom: 4, right: valueWidth } },
                        interaction: { mode: 'index', axis: 'y', intersect: false },
                        onClick: function (event, elements) {
                            if (elements.length) {
                                togglePinned(state.order[elements[0].index]);
                            }
                        },
                        onHover: function (event, elements) {
                            canvas.style.cursor = elements.length ? 'pointer' : 'default';
                        },
                        plugins: {
                            legend: options.legend
                                ? { position: 'top', align: 'start', labels: { boxWidth: 10, boxHeight: 10, color: '#334155', font: { size: 11, weight: '700' } } }
                                : { display: false },
                            tooltip: {
                                displayColors: !!options.showItems,
                                callbacks: {
                                    title: function (items) {
                                        return items.length ? drivers[state.order[items[0].dataIndex]] : '';
                                    },
                                    label: function (item) {
                                        return options.showItems ? ' ' + item.dataset.label + ': ' + fmt(item.raw, 2) + ' lei' : undefined;
                                    },
                                    afterBody: function (items) {
                                        return items.length ? options.tooltip(state.order[items[0].dataIndex]) : [];
                                    }
                                }
                            }
                        },
                        scales: {
                            x: { stacked: true, beginAtZero: true, grid: defaultGrid, border: { display: false }, ticks: { display: false } },
                            y: {
                                stacked: true,
                                grid: { display: false },
                                border: { display: false },
                                ticks: {
                                    autoSkip: false,
                                    color: function (context) {
                                        var index = state.order[context.index];
                                        return hasFocus() && isActive(index) ? '#0b55f4' : '#334155';
                                    },
                                    font: function (context) {
                                        return { size: 12, weight: hasFocus() && isActive(state.order[context.index]) ? '900' : '700' };
                                    },
                                    callback: function (value, index) {
                                        return shortName(drivers[state.order[index]] || '', this.chart.width);
                                    }
                                }
                            }
                        }
                    },
                    plugins: [valueLabels]
                };
            });

            views[key] = {
                ranked: true,
                refresh: function () {
                    var instance = chart();
                    if (instance) {
                        instance.update('none');
                    }
                },
                getSort: function () { return state.sort; },
                setSort: function (mode) {
                    state.sort = mode;
                    var instance = chart();
                    if (!instance) {
                        return;
                    }
                    sortOrder();
                    instance.data.labels = state.order.map(function (index) { return drivers[index]; });
                    instance.data.datasets.forEach(function (dataset, position) {
                        dataset.data = datasetValues(options.datasets[position].values);
                    });
                    instance.update();
                },
                // Aduce soferul in zona vizibila a listei derulabile.
                reveal: function (index) {
                    var instance = chart();
                    if (!instance || !scroller) {
                        return;
                    }
                    if (state.order.indexOf(index) === -1) {
                        return;
                    }
                    var y = instance.scales.y.getPixelForValue(state.order.indexOf(index));
                    scroller.scrollTo({ top: Math.max(0, y - scroller.clientHeight / 2), behavior: 'smooth' });
                }
            };
        };

        var km = compare.km || [];
        var trips = compare.trips || [];
        var liters = compare.fuel_liters || [];
        var period = compare.period || '';

        rankedChart('km', 'driver_compare_km_chart', {
            datasets: [{ label: 'Km', values: km, color: '#0d6efd' }],
            total: function (index) { return Number(km[index]) || 0; },
            missingText: 'Fara km inregistrati in perioada (curse fara km completati)',
            label: function (index) { return fmt(km[index], 0) + ' km'; },
            tooltip: function (index) {
                var count = Number(trips[index]) || 0;
                return [
                    'Total km: ' + fmt(km[index], 0) + ' km',
                    'Curse: ' + count,
                    'Medie / cursa: ' + (count > 0 ? fmt((Number(km[index]) || 0) / count, 0) + ' km' : '-'),
                    'Perioada: ' + period
                ];
            }
        });

        var consumption = compare.consumption || [];
        rankedChart('consumption', 'driver_compare_consumption_chart', {
            datasets: [{ label: 'L/100 km', values: consumption, color: '#0d6efd' }],
            total: function (index) { return consumption[index] === null || consumption[index] === undefined ? null : Number(consumption[index]); },
            missingText: 'Fara consum calculat (lipsesc alimentarile sau km)',
            label: function (index) {
                return consumption[index] === null || consumption[index] === undefined ? 'fara date' : fmt(consumption[index], 2) + ' L/100 km';
            },
            tooltip: function (index) {
                return [
                    'Consum mediu: ' + (consumption[index] === null || consumption[index] === undefined ? 'fara alimentari sau km' : fmt(consumption[index], 2) + ' L/100 km'),
                    'Combustibil: ' + fmt(liters[index], 2) + ' L',
                    'Kilometri: ' + fmt(km[index], 0) + ' km',
                    'Perioada: ' + period
                ];
            }
        });

        // Salariul si diurnele lipsesc pentru cei fara dreptul „Date financiare” (vin sterse de pe server).
        var costParts = [
            { label: 'Carburant', values: compare.fuel_cost, color: '#0d6efd' },
            { label: 'Reparatii', values: compare.repair_cost, color: '#fb5f72' },
            { label: 'Costuri curse', values: compare.trip_cost, color: '#f59e0b' },
            { label: 'Salariu', values: compare.salary_cost, color: '#7c3aed' },
            { label: 'Diurne', values: compare.diurne_cost, color: '#16a34a' }
        ].filter(function (part) { return Array.isArray(part.values); });
        var costTotal = function (index) {
            return costParts.reduce(function (sum, part) { return sum + (Number(part.values[index]) || 0); }, 0);
        };
        rankedChart('cost', 'driver_compare_cost_chart', {
            datasets: costParts,
            legend: true,
            showItems: true,
            total: costTotal,
            missingText: 'Fara costuri in perioada',
            label: function (index) { return fmt(costTotal(index), 2) + ' lei'; },
            tooltip: function (index) {
                var distance = Number(km[index]) || 0;
                return [
                    '',
                    'Total: ' + fmt(costTotal(index), 2) + ' lei',
                    'Cost / km: ' + (distance > 0 ? fmt(costTotal(index) / distance, 2) + ' lei' : '-'),
                    'Perioada: ' + period
                ];
            }
        });

        // Evolutie kilometri: cate o linie pe sofer, peste tot intervalul filtrat.
        var timeline = compare.timeline || {};
        var series = timeline.series || [];
        var dates = timeline.labels || [];
        var linePalette = ['#0d6efd', '#16a34a', '#f59e0b', '#7c3aed', '#e11d48', '#0891b2', '#ea580c', '#475569', '#db2777', '#65a30d'];
        var timelineCanvas = document.getElementById('driver_compare_timeline_chart');
        var timelinePanel = compareSwitcher.querySelector('[data-timeline-panel]');
        var timelineState = { hover: null, date: null };
        // Soferii fara niciun km in perioada nu au linie (ar fi doar o dreapta pe 0).
        var hasKm = series.map(function (item) { return (item.values || []).some(function (value) { return Number(value) > 0; }); });
        var withoutKm = drivers.filter(function (name, index) { return !hasKm[index]; });
        var timelineChart = function () {
            return timelineCanvas ? Chart.getChart(timelineCanvas) : null;
        };

        var styleTimeline = function (instance) {
            var hovering = timelineState.hover !== null;
            var emphasis = hovering || hasFocus();
            instance.data.datasets.forEach(function (dataset, index) {
                var on = hovering ? index === timelineState.hover : isActive(index);
                var color = linePalette[index % linePalette.length];
                dataset.borderColor = !emphasis ? rgba(color, 0.7) : (on ? color : 'rgba(148, 163, 184, 0.22)');
                dataset.borderWidth = emphasis && on ? 3 : 1.5;
                dataset.pointRadius = emphasis && on ? 3 : 0;
                dataset.pointBackgroundColor = color;
                // Ordinea mai mica se deseneaza deasupra.
                dataset.order = emphasis && on ? 0 : 1;
            });
        };

        var renderTimelinePanel = function () {
            if (!timelinePanel) {
                return;
            }
            if (timelineState.date === null) {
                timelinePanel.innerHTML = '<p class="driver-history-timeline-hint">Click pe o zi din grafic pentru a fixa aici km fiecarui sofer.</p>';
                if (withoutKm.length) {
                    var hintNote = document.createElement('p');
                    hintNote.className = 'driver-history-chart-note';
                    hintNote.textContent = 'Fara km in perioada (' + withoutKm.length + '): ' + withoutKm.join(', ') + '.';
                    timelinePanel.appendChild(hintNote);
                }
                return;
            }
            var day = timelineState.date;
            var rows = series.map(function (item, index) {
                return { index: index, value: Number((item.values || [])[day]) || 0 };
            }).filter(function (row) { return hasKm[row.index]; });
            var active = rows.filter(function (row) { return row.value > 0; }).sort(function (a, b) { return b.value - a.value || byName(a.index, b.index); });
            var idle = rows.filter(function (row) { return row.value <= 0; }).sort(function (a, b) { return byName(a.index, b.index); });
            var total = active.reduce(function (sum, row) { return sum + row.value; }, 0);
            var escape = function (text) {
                var node = document.createElement('span');
                node.textContent = text;
                return node.innerHTML;
            };
            var rowHtml = function (row) {
                var classes = 'driver-history-timeline-row' + (hasFocus() && isActive(row.index) ? ' is-active' : '');
                return '<button type="button" class="' + classes + '" data-timeline-driver="' + row.index + '" title="' + escape(drivers[row.index] || '') + '">'
                    + '<i style="background:' + linePalette[row.index % linePalette.length] + '"></i>'
                    + '<span>' + escape(drivers[row.index] || '') + '</span><strong>' + fmt(row.value, 0) + ' km</strong></button>';
            };
            timelinePanel.innerHTML = '<div class="driver-history-timeline-panel-head">'
                + '<div><small>Data selectata</small><strong>' + escape(dates[day] || '') + '</strong></div>'
                + '<button type="button" class="btn btn-sm btn-link" data-timeline-clear>Deselecteaza</button></div>'
                + '<p class="driver-history-timeline-total">Total: <strong>' + fmt(total, 0) + ' km</strong> · ' + active.length + ' soferi cu km</p>'
                + '<div class="driver-history-timeline-rows">' + active.map(rowHtml).join('')
                + (idle.length ? '<p class="driver-history-timeline-idle">Fara km in aceasta zi (' + idle.length + ')</p>' + idle.map(rowHtml).join('') : '')
                + '</div>'
                + (withoutKm.length ? '<p class="driver-history-chart-note">Fara km in perioada (' + withoutKm.length + '): ' + escape(withoutKm.join(', ')) + '.</p>' : '');
        };

        var selectionLine = {
            id: 'timelineSelection',
            beforeDatasetsDraw: function (instance) {
                if (timelineState.date === null) {
                    return;
                }
                var x = instance.scales.x.getPixelForValue(timelineState.date);
                var area = instance.chartArea;
                var context = instance.ctx;
                context.save();
                context.fillStyle = 'rgba(13, 110, 253, 0.08)';
                context.fillRect(x - 10, area.top, 20, area.bottom - area.top);
                context.strokeStyle = 'rgba(13, 110, 253, 0.55)';
                context.setLineDash([4, 3]);
                context.beginPath();
                context.moveTo(x, area.top);
                context.lineTo(x, area.bottom);
                context.stroke();
                context.restore();
            }
        };

        /*
         * Hover pe evolutie: cel mai apropiat punct cu km (> 0), pe o raza de 40 px. Zilele
         * fara curse (0 km) sunt comune tuturor soferilor, deci nu indica niciun sofer.
         */
        Chart.Interaction.modes.driverNearest = function (chart, event) {
            var position = Chart.helpers.getRelativePosition(event, chart);
            var best = null;
            var bestDistance = 40 * 40;
            chart.getSortedVisibleDatasetMetas().forEach(function (meta) {
                var values = chart.data.datasets[meta.index].data;
                meta.data.forEach(function (point, index) {
                    if (!(Number(values[index]) > 0)) {
                        return;
                    }
                    var distance = Math.pow(point.x - position.x, 2) + Math.pow(point.y - position.y, 2);
                    if (distance < bestDistance) {
                        bestDistance = distance;
                        best = { element: point, datasetIndex: meta.index, index: index };
                    }
                });
            });
            return best ? [best] : [];
        };

        createChart('driver_compare_timeline_chart', [].concat.apply([], series.map(function (item) { return item.values || []; })), function () {
            return {
                type: 'line',
                data: {
                    labels: dates,
                    datasets: series.map(function (item, index) {
                        var color = linePalette[index % linePalette.length];
                        return {
                            label: item.label,
                            data: item.values,
                            borderColor: rgba(color, 0.7),
                            backgroundColor: color,
                            borderWidth: 1.5,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            // Monoton: curba nu coboara sub zero intre zilele fara curse.
                            cubicInterpolationMode: 'monotone',
                            fill: false,
                            hidden: !hasKm[index]
                        };
                    })
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    interaction: { mode: 'driverNearest', intersect: false },
                    onHover: function (event, elements) {
                        var next = event.type === 'mouseout' || !elements.length ? null : elements[0].datasetIndex;
                        if (next !== timelineState.hover) {
                            timelineState.hover = next;
                            var instance = timelineChart();
                            styleTimeline(instance);
                            instance.update('none');
                        }
                        timelineCanvas.style.cursor = 'pointer';
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: function (items) { return items.length ? items[0].label : ''; },
                                label: function (item) { return ' ' + item.dataset.label + ': ' + fmt(item.raw, 0) + ' km'; },
                                afterBody: function () { return ['', 'Click pentru a fixa ziua']; }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: Object.assign({}, defaultTicks, { maxRotation: 0, autoSkipPadding: 12 }) },
                        y: { beginAtZero: true, grid: defaultGrid, ticks: Object.assign({}, defaultTicks, { callback: function (value) { return fmt(value, 0); } }) }
                    }
                },
                plugins: [selectionLine]
            };
        });

        views.timeline = {
            ranked: false,
            refresh: function () {
                var instance = timelineChart();
                if (instance) {
                    styleTimeline(instance);
                    instance.update('none');
                }
                renderTimelinePanel();
            },
            reveal: function (index) {
                var row = timelinePanel && timelinePanel.querySelector('[data-timeline-driver="' + index + '"]');
                if (row) {
                    row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }
        };
        if (timelineCanvas) {
            // Click oriunde pe grafic (si pe eticheta datei): ziua cea mai apropiata se fixeaza.
            timelineCanvas.addEventListener('click', function (event) {
                var instance = timelineChart();
                if (!instance || !dates.length) {
                    return;
                }
                var position = Chart.helpers.getRelativePosition(event, instance);
                var day = Math.round(instance.scales.x.getValueForPixel(position.x));
                timelineState.date = Math.max(0, Math.min(dates.length - 1, day));
                // Click chiar pe linia unui sofer: soferul ramane si evidentiat.
                if (timelineState.hover !== null) {
                    focus.pinned = timelineState.hover;
                }
                refreshAll();
            });
            timelineCanvas.addEventListener('mouseleave', function () {
                var instance = timelineChart();
                if (instance && timelineState.hover !== null) {
                    timelineState.hover = null;
                    styleTimeline(instance);
                    instance.update('none');
                }
            });
        }
        if (timelinePanel) {
            timelinePanel.addEventListener('click', function (event) {
                if (event.target.closest('[data-timeline-clear]')) {
                    timelineState.date = null;
                    refreshAll();
                    return;
                }
                var row = event.target.closest('[data-timeline-driver]');
                if (row) {
                    togglePinned(parseInt(row.getAttribute('data-timeline-driver'), 10));
                }
            });
        }

        // Uneltele din antet: cautare, sortare, resetare - pentru graficul afisat.
        var tools = compareSwitcher.querySelector('[data-chart-tools]');
        var searchInput = compareSwitcher.querySelector('[data-chart-search]');
        var searchStatus = compareSwitcher.querySelector('[data-chart-search-status]');
        var sortSelect = compareSwitcher.querySelector('[data-chart-sort]');
        var visiblePane = compareSwitcher.querySelector('[data-chart-pane]:not([hidden])');
        var currentKey = visiblePane ? visiblePane.getAttribute('data-chart-pane') : 'km';

        var syncTools = function () {
            var view = views[currentKey];
            var pane = compareSwitcher.querySelector('[data-chart-pane="' + currentKey + '"]');
            var ranked = !!(view && view.ranked);
            if (sortSelect) {
                sortSelect.hidden = !ranked;
                if (ranked) {
                    sortSelect.value = view.getSort();
                    var descOption = sortSelect.querySelector('[data-chart-sort-desc]');
                    if (descOption && pane) {
                        descOption.textContent = pane.getAttribute('data-chart-sort-label') || 'Descrescator';
                    }
                }
            }
            if (tools) {
                tools.hidden = !view;
            }
        };
        var revealFirstMatch = function () {
            var view = views[currentKey];
            var target = focus.matches.length ? focus.matches[0] : focus.pinned;
            if (view && target !== null && target !== undefined) {
                view.reveal(target);
            }
        };

        compareSwitcher.addEventListener('chartswitch', function (event) {
            currentKey = event.detail.key;
            syncTools();
            refreshAll();
            revealFirstMatch();
        });
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var query = fold(searchInput.value).trim();
                focus.matches = query === '' ? [] : drivers.reduce(function (found, name, index) {
                    if (fold(name).indexOf(query) !== -1) {
                        found.push(index);
                    }
                    return found;
                }, []);
                if (searchStatus) {
                    searchStatus.textContent = query === '' ? '' : (focus.matches.length ? focus.matches.length + (focus.matches.length === 1 ? ' sofer gasit' : ' soferi gasiti') : 'Niciun sofer gasit');
                }
                refreshAll();
                revealFirstMatch();
            });
        }
        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                var view = views[currentKey];
                if (view && view.ranked) {
                    view.setSort(sortSelect.value);
                }
            });
        }
        var resetButton = compareSwitcher.querySelector('[data-chart-reset]');
        if (resetButton) {
            resetButton.addEventListener('click', function () {
                focus.pinned = null;
                focus.matches = [];
                timelineState.date = null;
                timelineState.hover = null;
                if (searchInput) {
                    searchInput.value = '';
                }
                if (searchStatus) {
                    searchStatus.textContent = '';
                }
                refreshAll();
            });
        }
        syncTools();
    }

    // Selectorul de soferi: lista cu bife. Un sofer = istoricul lui; doi sau
    // mai multi = comparatie. Se aplica la "Filtreaza", ca restul filtrelor.
    var picker = document.querySelector('[data-driver-picker]');
    if (picker) {
        var toggle = picker.querySelector('[data-driver-picker-toggle]');
        var menu = picker.querySelector('[data-driver-picker-menu]');
        var search = picker.querySelector('[data-driver-picker-search]');
        var label = picker.querySelector('[data-driver-picker-label]');
        var counter = picker.querySelector('[data-driver-picker-count]');
        var boxes = Array.prototype.slice.call(picker.querySelectorAll('input[type="checkbox"]'));
        var selectionKey = function () {
            return boxes.filter(function (box) { return box.checked; }).map(function (box) { return box.value; }).join(',');
        };
        var initialSelection = selectionKey();

        var refreshLabel = function () {
            var checked = boxes.filter(function (box) { return box.checked; });
            if (checked.length === 0) {
                label.textContent = 'Alege soferi';
            } else if (checked.length === 1) {
                label.textContent = checked[0].parentElement.querySelector('span').textContent;
            } else {
                label.textContent = checked.length + ' soferi - comparatie';
            }
            counter.textContent = checked.length > 0 ? checked.length + ' selectati' : '';
        };

        var selectionChanged = function () {
            refreshLabel();
            // Vehiculele din filtru sunt ale soferilor alesi: la alta selectie filtrul de vehicul se goleste.
            var vehicleSelect = document.getElementById('driver_history_vehicle');
            if (vehicleSelect && selectionKey() !== initialSelection) {
                vehicleSelect.value = '';
            }
        };

        var setOpen = function (open) {
            menu.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && search) {
                search.focus();
            }
        };

        toggle.addEventListener('click', function () { setOpen(menu.hidden); });
        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                setOpen(false);
            }
        });
        picker.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
                toggle.focus();
            }
        });
        boxes.forEach(function (box) { box.addEventListener('change', selectionChanged); });

        if (search) {
            search.addEventListener('input', function () {
                var term = search.value.trim().toLowerCase();
                picker.querySelectorAll('[data-driver-picker-option]').forEach(function (option) {
                    option.hidden = term !== '' && (option.getAttribute('data-name') || '').indexOf(term) === -1;
                });
            });
            // Enter in cautare nu trimite formularul.
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
        }

        picker.querySelector('[data-driver-picker-active]').addEventListener('click', function () {
            boxes.forEach(function (box) { box.checked = box.getAttribute('data-active') === '1'; });
            selectionChanged();
        });
        picker.querySelector('[data-driver-picker-clear]').addEventListener('click', function () {
            boxes.forEach(function (box) { box.checked = false; });
            selectionChanged();
        });

        var pickerForm = picker.closest('form');
        if (pickerForm) {
            pickerForm.addEventListener('submit', function (event) {
                if (!boxes.some(function (box) { return box.checked; })) {
                    event.preventDefault();
                    setOpen(true);
                    counter.textContent = 'Alege cel putin un sofer';
                }
            });
        }

        refreshLabel();
    }

    // "Interval de timp": calendar de interval (flatpickr, ca in Carburanti), cu
    // scurtaturi de luna. Campul ramane editabil de mana; serverul citeste datele
    // din text indiferent de separator.
    var rangeInput = document.getElementById('driver_history_date_range');
    var rangePicker = null;
    if (rangeInput && window.flatpickr) {
        var locale = Object.assign({}, (window.flatpickr.l10ns && window.flatpickr.l10ns.ro) || {}, { rangeSeparator: ' - ' });
        var initialDates = (rangeInput.value.match(/\d{1,2}\.\d{1,2}\.\d{4}/g) || []).slice(0, 2);
        rangePicker = window.flatpickr(rangeInput, {
            mode: 'range',
            locale: locale,
            dateFormat: 'd.m.Y',
            allowInput: true,
            defaultDate: initialDates,
            onReady: function (selectedDates, dateStr, fp) {
                var presets = document.createElement('div');
                presets.className = 'fuel-fp-presets';
                [['Luna aceasta', 0], ['Luna trecută', 1]].forEach(function (preset) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = preset[0];
                    button.addEventListener('click', function () {
                        var now = new Date();
                        var start = new Date(now.getFullYear(), now.getMonth() - preset[1], 1);
                        var end = preset[1] === 0 ? now : new Date(now.getFullYear(), now.getMonth() - preset[1] + 1, 0);
                        fp.setDate([start, end], true);
                        fp.close();
                    });
                    presets.appendChild(button);
                });
                fp.calendarContainer.appendChild(presets);
            },
            onClose: function (selectedDates, dateStr, fp) {
                // O singura zi aleasa inseamna intervalul acelei zile.
                if (selectedDates.length === 1) {
                    fp.setDate([selectedDates[0], selectedDates[0]], true);
                }
            }
        });
    }

    var openRangePicker = function () {
        if (rangePicker) {
            rangePicker.open();
        } else if (rangeInput) {
            rangeInput.focus();
            rangeInput.select();
        }
    };

    var dateFocusButton = document.querySelector('[data-driver-history-focus-date]');
    if (dateFocusButton) {
        dateFocusButton.addEventListener('click', openRangePicker);
    }

    // Iconita de calendar din camp deschide si ea calendarul.
    var rangeIcon = rangeInput ? rangeInput.parentElement.querySelector('.input-group-text') : null;
    if (rangeIcon) {
        rangeIcon.style.cursor = 'pointer';
        rangeIcon.addEventListener('click', openRangePicker);
    }
}());

/*
 * Butonul „Coloane” (vizibilitate si ordine), la fel ca in Desfasuratorul din
 * Dispecer curse. Se aplica tabelelor <table data-column-manager="cheie">; butonul
 * se pune in elementul [data-column-manager-slot="cheie"]. Alegerea se pastreaza in
 * browser (localStorage), per tabel. Prima coloana (Sofer) nu se poate ascunde.
 * Coloanele sunt identificate dupa eticheta, deci o coloana care lipseste pentru
 * un utilizator fara dreptul „Date financiare” e pur si simplu ignorata.
 */
(function () {
    var tables = Array.prototype.slice.call(document.querySelectorAll('table[data-column-manager]'));

    tables.forEach(function (table) {
        var managerKey = table.getAttribute('data-column-manager');
        var slot = document.querySelector('[data-column-manager-slot="' + managerKey + '"]');
        var headRow = table.tHead && table.tHead.rows[0];
        if (!slot || !headRow) {
            return;
        }
        var storageKey = 'fleet.columns.' + managerKey;

        // data-col (pus de filtrul din antet) sau pozitia initiala -> eticheta coloanei.
        var columns = Array.prototype.map.call(headRow.cells, function (th, index) {
            if (!th.hasAttribute('data-col')) {
                th.setAttribute('data-col', String(index));
            }
            var labelEl = th.querySelector('.races-head-label') || th;
            return {
                col: th.getAttribute('data-col'),
                key: labelEl.textContent.replace(/\s+/g, ' ').trim(),
                required: index === 0
            };
        });
        Array.prototype.forEach.call(table.rows, function (row) {
            if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
                return;
            }
            Array.prototype.forEach.call(row.cells, function (cell, index) {
                if (!cell.hasAttribute('data-col')) {
                    cell.setAttribute('data-col', String(index));
                }
            });
        });
        var byKey = {};
        columns.forEach(function (column) {
            byKey[column.key] = column;
        });
        var defaultOrder = columns.map(function (column) { return column.key; });

        var normalize = function (raw) {
            var order = [];
            (raw && Array.isArray(raw.order) ? raw.order : []).forEach(function (key) {
                if (byKey[key] && order.indexOf(key) === -1) {
                    order.push(key);
                }
            });
            // Coloanele noi (sau nesalvate) intra langa vecinele lor din ordinea implicita.
            defaultOrder.forEach(function (key, defaultIndex) {
                if (order.indexOf(key) !== -1) {
                    return;
                }
                var insertAt = order.length;
                for (var next = defaultIndex + 1; next < defaultOrder.length; next++) {
                    var existing = order.indexOf(defaultOrder[next]);
                    if (existing !== -1) {
                        insertAt = existing;
                        break;
                    }
                }
                order.splice(insertAt, 0, key);
            });
            // Coloana obligatorie ramane prima.
            columns.filter(function (column) { return column.required; }).forEach(function (column) {
                order.splice(order.indexOf(column.key), 1);
                order.unshift(column.key);
            });
            var hidden = {};
            var rawHidden = raw && raw.hidden && typeof raw.hidden === 'object' ? raw.hidden : {};
            columns.forEach(function (column) {
                if (!column.required && rawHidden[column.key] === true) {
                    hidden[column.key] = true;
                }
            });
            return { order: order, hidden: hidden };
        };

        var state;
        try {
            state = normalize(JSON.parse(window.localStorage.getItem(storageKey) || 'null'));
        } catch (error) {
            state = normalize(null);
        }
        var save = function () {
            try {
                window.localStorage.setItem(storageKey, JSON.stringify(state));
            } catch (error) {
                // localStorage poate lipsi in modurile restrictive ale browserului.
            }
        };

        var isCustomized = function () {
            return Object.keys(state.hidden).length > 0 || state.order.some(function (key, index) {
                return key !== defaultOrder[index];
            });
        };

        // Interfata: aceleasi clase ca panoul din Desfasurator curse.
        var manager = document.createElement('div');
        manager.className = 'dispatcher-column-manager';
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn btn-sm btn-outline-primary dispatcher-column-toggle';
        toggle.setAttribute('aria-haspopup', 'dialog');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = '<i class="bi bi-layout-three-columns" aria-hidden="true"></i><span>Coloane</span><span class="driver-history-columns-count" hidden></span><i class="bi bi-chevron-down dispatcher-column-toggle-chevron" aria-hidden="true"></i>';
        var panel = document.createElement('div');
        panel.className = 'dispatcher-column-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Alege coloanele afisate si ordinea lor');
        panel.hidden = true;
        panel.innerHTML = '<div class="dispatcher-column-panel-header"><div><strong>Coloane tabel</strong><span>Vizibilitate si ordine</span></div></div>'
            + '<div class="dispatcher-column-list"></div>'
            + '<div class="dispatcher-column-panel-footer d-flex justify-content-between gap-2">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-columns-all>Afiseaza toate</button>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" data-columns-reset>Reseteaza</button></div>';
        manager.appendChild(toggle);
        manager.appendChild(panel);
        slot.appendChild(manager);
        var list = panel.querySelector('.dispatcher-column-list');
        var countBadge = toggle.querySelector('.driver-history-columns-count');

        var applyState = function () {
            var orderCols = state.order.map(function (key) { return byKey[key].col; });
            Array.prototype.forEach.call(table.rows, function (row) {
                if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
                    return;
                }
                var cells = {};
                Array.prototype.forEach.call(row.cells, function (cell) {
                    cells[cell.getAttribute('data-col')] = cell;
                });
                orderCols.forEach(function (col) {
                    if (cells[col]) {
                        row.appendChild(cells[col]);
                    }
                });
                state.order.forEach(function (key) {
                    var cell = cells[byKey[key].col];
                    if (cell) {
                        cell.style.display = state.hidden[key] ? 'none' : '';
                    }
                });
            });
            var hiddenCount = Object.keys(state.hidden).length;
            countBadge.hidden = hiddenCount === 0;
            countBadge.textContent = hiddenCount > 0 ? (columns.length - hiddenCount) + '/' + columns.length : '';
            toggle.classList.toggle('is-customized', isCustomized());
            renderList();
        };

        var move = function (key, direction) {
            var from = state.order.indexOf(key);
            var to = from + direction;
            if (from < 0 || to < 0 || to >= state.order.length || byKey[state.order[to]].required) {
                return;
            }
            state.order.splice(from, 1);
            state.order.splice(to, 0, key);
            save();
            applyState();
        };

        var renderList = function () {
            list.replaceChildren();
            state.order.forEach(function (key, index) {
                var column = byKey[key];
                var item = document.createElement('div');
                item.className = 'dispatcher-column-item';
                var label = document.createElement('label');
                label.className = 'dispatcher-column-check';
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = !state.hidden[key];
                checkbox.disabled = column.required;
                checkbox.setAttribute('aria-label', 'Afiseaza coloana ' + key);
                checkbox.addEventListener('change', function () {
                    if (checkbox.checked) {
                        delete state.hidden[key];
                    } else {
                        state.hidden[key] = true;
                        // Filtrul / sortarea pe o coloana ascunsa se sterg.
                        table.dispatchEvent(new CustomEvent('columnhidden', { detail: { column: parseInt(column.col, 10) } }));
                    }
                    save();
                    applyState();
                });
                var text = document.createElement('span');
                text.textContent = key;
                label.appendChild(checkbox);
                label.appendChild(text);

                var up = document.createElement('button');
                up.type = 'button';
                up.className = 'dispatcher-column-order-btn';
                up.disabled = column.required || index <= 1;
                up.setAttribute('aria-label', 'Muta coloana ' + key + ' mai sus');
                up.innerHTML = '<i class="bi bi-chevron-up" aria-hidden="true"></i>';
                up.addEventListener('click', function () { move(key, -1); });
                var down = document.createElement('button');
                down.type = 'button';
                down.className = 'dispatcher-column-order-btn';
                down.disabled = column.required || index === state.order.length - 1;
                down.setAttribute('aria-label', 'Muta coloana ' + key + ' mai jos');
                down.innerHTML = '<i class="bi bi-chevron-down" aria-hidden="true"></i>';
                down.addEventListener('click', function () { move(key, 1); });

                item.appendChild(label);
                item.appendChild(up);
                item.appendChild(down);
                list.appendChild(item);
            });
        };

        var setOpen = function (open) {
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.classList.toggle('is-open', open);
            var chevron = toggle.querySelector('.dispatcher-column-toggle-chevron');
            chevron.classList.toggle('bi-chevron-up', open);
            chevron.classList.toggle('bi-chevron-down', !open);
        };
        toggle.addEventListener('click', function () {
            setOpen(panel.hidden);
        });
        document.addEventListener('click', function (event) {
            // Butoanele sus/jos redeseneaza lista, deci tinta clickului poate fi deja scoasa din pagina.
            if (!panel.hidden && event.target.isConnected && !manager.contains(event.target)) {
                setOpen(false);
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panel.hidden) {
                setOpen(false);
                toggle.focus();
            }
        });
        panel.querySelector('[data-columns-all]').addEventListener('click', function () {
            state.hidden = {};
            save();
            applyState();
        });
        panel.querySelector('[data-columns-reset]').addEventListener('click', function () {
            state = normalize(null);
            try {
                window.localStorage.removeItem(storageKey);
            } catch (error) {
                // Resetarea vizuala se face oricum.
            }
            applyState();
        });

        applyState();
    });
}());


/*
 * Comparatie soferi: randul unui sofer se desfasoara in cursele lui (vehicul, tip
 * transport, beneficiar, km, tone, durata, diurne, valoare, cost). Cursele sunt deja
 * randate cu pagina, deci desfasurarea doar le arata.
 */
(function () {
    var toggles = document.querySelectorAll('[data-trips-toggle]');
    if (!toggles.length) {
        return;
    }

    // Randul desfasurat e lat cat tot tabelul: il tinem la latimea vizibila a zonei derulabile.
    var syncWidths = function () {
        document.querySelectorAll('.driver-history-compare-panel .driver-history-table-wrap').forEach(function (wrap) {
            wrap.style.setProperty('--dh-visible-width', wrap.clientWidth + 'px');
        });
    };
    syncWidths();
    window.addEventListener('resize', syncWidths);

    var toggleTrips = function (button) {
        if (!button) {
            return;
        }
        var detailRow = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!detailRow) {
            return;
        }
        var open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        detailRow.hidden = !open;
        var parentRow = button.closest('tr');
        if (parentRow) {
            parentRow.classList.toggle('is-expanded', open);
        }
        if (open) {
            syncWidths();
        }
    };

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var button = target.closest('[data-trips-toggle]');
        if (button) {
            toggleTrips(button);
            return;
        }
        // Click pe randul soferului (nu pe linkuri / butoane) deschide si el cursele.
        if (target.closest('button, a, input, select, textarea, label')) {
            return;
        }
        var driverRow = target.closest('[data-trips-row]');
        if (driverRow) {
            toggleTrips(driverRow.querySelector('[data-trips-toggle]'));
        }
    });
}());

/*
 * Coloanele de sumar din comparatie (Vehicul, Beneficiar): butonul "N detalii" deschide
 * lista, ca in Desfasurator curse. Popover-ul se construieste la prima deschidere din
 * data-summary-items si foloseste aceleasi clase (.dispatcher-summary-*) ca acolo.
 */
(function () {
    if (!document.querySelector('[data-summary-toggle]')) {
        return;
    }

    var active = null;

    var position = function (button, popover) {
        var margin = 12;
        var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        var viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        var width = Math.min(304, Math.max(220, viewportWidth - margin * 2));
        var maxHeight = Math.min(360, Math.max(180, viewportHeight - margin * 2));
        popover.style.width = width + 'px';
        popover.style.maxHeight = maxHeight + 'px';

        var rect = button.getBoundingClientRect();
        var height = Math.min(popover.getBoundingClientRect().height || popover.scrollHeight || maxHeight, maxHeight);
        var left = rect.left;
        if (left + width > viewportWidth - margin) {
            left = rect.right - width;
        }
        left = Math.max(margin, Math.min(left, viewportWidth - margin - width));

        var top = rect.bottom + 8;
        var above = rect.top - height - 8;
        if (top + height > viewportHeight - margin && above >= margin) {
            top = above;
        } else if (top + height > viewportHeight - margin) {
            top = Math.max(margin, viewportHeight - margin - height);
        }
        popover.style.left = Math.round(left) + 'px';
        popover.style.top = Math.round(top) + 'px';
    };

    var build = function (button) {
        var items;
        try {
            items = JSON.parse(String(button.getAttribute('data-summary-items') || '[]'));
        } catch (error) {
            return null;
        }
        if (!Array.isArray(items) || items.length === 0) {
            return null;
        }

        var popover = document.createElement('div');
        popover.className = 'dispatcher-summary-popover';
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-label', String(button.getAttribute('data-summary-label') || 'Detalii'));
        popover.tabIndex = -1;
        popover.hidden = true;

        var list = document.createElement('ul');
        list.className = 'dispatcher-summary-popover-list';
        list.setAttribute('role', 'list');
        items.forEach(function (item) {
            var entry = document.createElement('li');
            entry.className = 'dispatcher-summary-popover-item';
            entry.setAttribute('role', 'listitem');
            var label = document.createElement('strong');
            label.textContent = String(item && item.l != null ? item.l : '-');
            var value = document.createElement('span');
            value.className = 'dispatcher-summary-value';
            value.textContent = String(item && item.v != null ? item.v : '-');
            entry.appendChild(label);
            entry.appendChild(value);
            list.appendChild(entry);
        });

        var total = document.createElement('div');
        total.className = 'dispatcher-summary-popover-total';
        total.textContent = 'Total ' + (items.length === 1 ? '1 detaliu' : items.length + ' detalii');

        popover.appendChild(list);
        popover.appendChild(total);
        document.body.appendChild(popover);
        button.summaryPopover = popover;

        return popover;
    };

    var close = function () {
        if (!active) {
            return;
        }
        active.popover.hidden = true;
        active.button.setAttribute('aria-expanded', 'false');
        active.button.classList.remove('is-open');
        var icon = active.button.querySelector('i');
        if (icon) {
            icon.classList.add('bi-chevron-down');
            icon.classList.remove('bi-chevron-up');
        }
        active = null;
    };

    var open = function (button) {
        var popover = button.summaryPopover || build(button);
        if (!popover) {
            return;
        }
        close();
        active = { button: button, popover: popover };
        button.setAttribute('aria-expanded', 'true');
        button.classList.add('is-open');
        var icon = button.querySelector('i');
        if (icon) {
            icon.classList.remove('bi-chevron-down');
            icon.classList.add('bi-chevron-up');
        }
        popover.style.visibility = 'hidden';
        popover.hidden = false;
        position(button, popover);
        popover.style.visibility = '';
    };

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        var button = target.closest('[data-summary-toggle]');
        if (button) {
            event.preventDefault();
            event.stopPropagation();
            if (active && active.button === button) {
                close();
            } else {
                open(button);
            }
            return;
        }
        if (!target.closest('.dispatcher-summary-popover')) {
            close();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            close();
        }
    });
    // Popover-ul e pozitionat fix: la derulare / redimensionare se inchide.
    document.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
}());

/*
 * Comparatie soferi: comutatorul "Sumar soferi" / "Curse", ca in Istoric activitate.
 * Sumar = tabelul comparativ + graficele; Curse = lista tuturor curselor soferilor
 * alesi (tabul Curse), pe toata latimea. Alegerea se tine minte in browser.
 */
(function () {
    var shell = document.querySelector('.driver-history-compare-views');
    if (!shell) {
        return;
    }

    var storageKey = 'fleet.driverCompareView';
    // Tabul cu curse: comparatie sau pagina unui singur sofer.
    var tripsTab = document.querySelector('[data-bs-target="#driver-compare-trips"], [data-bs-target="#driver-history-trips"]');

    var setView = function (view, remember) {
        shell.setAttribute('data-compare-view', view === 'trips' ? 'trips' : 'summary');
        shell.querySelectorAll('[data-compare-view-button]').forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-compare-view-button') === view ? 'true' : 'false');
        });
        // In modul "Curse" lista trebuie sa fie chiar tabul cu curse.
        if (view === 'trips' && tripsTab && !tripsTab.classList.contains('active') && window.bootstrap && window.bootstrap.Tab) {
            window.bootstrap.Tab.getOrCreateInstance(tripsTab).show();
        }
        if (remember) {
            try {
                window.localStorage.setItem(storageKey, view);
            } catch (error) {
                // localStorage poate lipsi in modurile restrictive ale browserului.
            }
        }
    };

    shell.querySelectorAll('[data-compare-view-button]').forEach(function (button) {
        button.addEventListener('click', function () {
            setView(button.getAttribute('data-compare-view-button'), true);
        });
    });

    try {
        if (window.localStorage.getItem(storageKey) === 'trips') {
            setView('trips', false);
        }
    } catch (error) {
        // Fara localStorage ramane modul implicit (sumar).
    }
}());

/*
 * Tabelul de curse: randul intreg deschide formularul cursei in Dispecer curse.
 * Click pe un link / buton din rand (iconitele din Actiuni) isi pastreaza actiunea;
 * Ctrl / Cmd / click cu rotita deschid in tab nou, ca la un link obisnuit.
 */
(function () {
    'use strict';

    var openRow = function (row, newTab) {
        var href = row.getAttribute('data-row-href');
        if (!href) {
            return;
        }
        if (newTab) {
            window.open(href, '_blank', 'noopener');
        } else {
            window.location.href = href;
        }
    };

    document.addEventListener('click', function (event) {
        var row = event.target.closest('tr[data-row-href]');
        if (!row || event.target.closest('a, button, input, select, label')) {
            return;
        }
        // Textul selectat cu mouse-ul nu este un click de deschidere.
        if (window.getSelection && String(window.getSelection()).length > 0) {
            return;
        }
        openRow(row, event.ctrlKey || event.metaKey);
    });

    document.addEventListener('auxclick', function (event) {
        var row = event.button === 1 ? event.target.closest('tr[data-row-href]') : null;
        if (row && !event.target.closest('a, button')) {
            event.preventDefault();
            openRow(row, true);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.matches && event.target.matches('tr[data-row-href]')) {
            openRow(event.target, event.ctrlKey || event.metaKey);
        }
    });
}());
