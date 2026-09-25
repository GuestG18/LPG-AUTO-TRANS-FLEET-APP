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

    var createChart = function (canvasId, values, configFactory) {
        if (!setEmptyState(canvasId, values) || typeof Chart === 'undefined') {
            return;
        }

        var canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }

        new Chart(canvas, configFactory(canvas));
    };

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

    // Comparatie: grafice cu cate o culoare pe sofer.
    var compare = chartData.compare || null;
    if (compare) {
        var palette = ['#0d6efd', '#16a34a', '#f59e0b', '#7c3aed', '#e11d48', '#0891b2', '#ea580c', '#475569', '#db2777', '#65a30d'];
        var colorAt = function (index) { return palette[index % palette.length]; };
        var driverColors = (compare.drivers || []).map(function (name, index) { return colorAt(index); });
        var legend = { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10, color: '#334155', font: { size: 11, weight: '700' } } };
        var compareOptions = function (showLegend, stacked) {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: showLegend ? legend : { display: false } },
                scales: {
                    x: { stacked: !!stacked, grid: { display: false }, ticks: defaultTicks },
                    y: { stacked: !!stacked, beginAtZero: true, grid: defaultGrid, ticks: defaultTicks }
                }
            };
        };
        var perDriverBar = function (canvasId, label, values) {
            createChart(canvasId, (values || []).map(Number), function () {
                return {
                    type: 'bar',
                    data: { labels: compare.drivers, datasets: [{ label: label, data: values, backgroundColor: driverColors, borderRadius: 5, maxBarThickness: 46 }] },
                    options: compareOptions(false, false)
                };
            });
        };

        perDriverBar('driver_compare_km_chart', 'Km', compare.km);
        perDriverBar('driver_compare_consumption_chart', 'L/100 km', compare.consumption);

        createChart('driver_compare_cost_chart', (compare.fuel_cost || []).concat(compare.repair_cost || [], compare.trip_cost || [], compare.salary_cost || [], compare.diurne_cost || []), function () {
            return {
                type: 'bar',
                data: {
                    labels: compare.drivers,
                    datasets: [
                        { label: 'Carburant', data: compare.fuel_cost, backgroundColor: '#0d6efd', maxBarThickness: 46 },
                        { label: 'Reparatii', data: compare.repair_cost, backgroundColor: '#fb5f72', maxBarThickness: 46 },
                        { label: 'Costuri curse', data: compare.trip_cost, backgroundColor: '#f59e0b', maxBarThickness: 46 },
                        { label: 'Salariu', data: compare.salary_cost, backgroundColor: '#7c3aed', maxBarThickness: 46 },
                        { label: 'Diurne', data: compare.diurne_cost, backgroundColor: '#16a34a', maxBarThickness: 46 }
                    ].filter(function (dataset) {
                        // Salariul si diurnele lipsesc pentru cei fara dreptul „Date financiare”.
                        return Array.isArray(dataset.data);
                    })
                },
                options: compareOptions(true, true)
            };
        });

        var timeline = compare.timeline || {};
        var series = timeline.series || [];
        createChart('driver_compare_timeline_chart', [].concat.apply([], series.map(function (s) { return s.values || []; })), function () {
            // Bare grupate: zilele fara curse raman 0, fara curbe care coboara sub zero.
            return {
                type: 'bar',
                data: {
                    labels: timeline.labels || [],
                    datasets: series.map(function (s, index) {
                        return {
                            label: s.label,
                            data: s.values,
                            backgroundColor: colorAt(index),
                            borderRadius: 3,
                            maxBarThickness: 18
                        };
                    })
                },
                options: compareOptions(true, false)
            };
        });
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
