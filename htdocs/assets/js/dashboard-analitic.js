(function () {
    'use strict';

    var root = document.getElementById('dashboard-analytic-page');
    if (!root) {
        return;
    }

    var filtersForm = document.getElementById('dashboard-analytic-filters');
    var loadingEl = document.getElementById('dashboard-analytic-loading');
    var emptyEl = document.getElementById('dashboard-analytic-empty');
    var contentEl = document.getElementById('dashboard-analytic-content');
    var lastRefreshEl = document.getElementById('dashboard-analytic-last-refresh');
    var vehicleBody = document.getElementById('vehicle-profitability-body');
    var driverBody = document.getElementById('driver-performance-body');
    var alertsList = document.getElementById('vehicle-risk-alerts');

    var endpointBase = root.getAttribute('data-endpoint') || '';
    var refreshMs = parseInt(root.getAttribute('data-refresh-ms') || '30000', 10);
    if (!Number.isFinite(refreshMs) || refreshMs < 5000) {
        refreshMs = 30000;
    }

    var chartRegistry = {};
    var refreshTimer = null;
    var activeRequestId = 0;

    function safeNumber(value) {
        var num = Number(value);
        return Number.isFinite(num) ? num : 0;
    }

    function formatMoney(value) {
        return new Intl.NumberFormat('ro-RO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(safeNumber(value)) + ' lei';
    }

    function formatKm(value) {
        return new Intl.NumberFormat('ro-RO', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 1
        }).format(safeNumber(value)) + ' km';
    }

    function formatTons(value) {
        return new Intl.NumberFormat('ro-RO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(safeNumber(value)) + ' t';
    }

    function formatPercent(value) {
        return new Intl.NumberFormat('ro-RO', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
        }).format(safeNumber(value)) + '%';
    }

    function formatRatio(value) {
        return new Intl.NumberFormat('ro-RO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(safeNumber(value));
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    }

    function setLoadingState(isLoading) {
        if (!loadingEl) {
            return;
        }

        if (isLoading) {
            loadingEl.classList.remove('d-none');
            root.classList.add('is-loading');
            return;
        }

        loadingEl.classList.add('d-none');
        root.classList.remove('is-loading');
    }

    function setContentVisibility(hasData) {
        if (hasData) {
            emptyEl.classList.add('d-none');
            contentEl.classList.remove('d-none');
            return;
        }

        contentEl.classList.add('d-none');
        emptyEl.classList.remove('d-none');
    }

    function destroyChart(key) {
        if (chartRegistry[key]) {
            chartRegistry[key].destroy();
            delete chartRegistry[key];
        }
    }

    function renderChart(key, config) {
        var canvas = document.getElementById(key);
        if (!canvas) {
            return;
        }

        destroyChart(key);
        chartRegistry[key] = new Chart(canvas, config);
    }

    function buildApiUrl() {
        var baseUrl = new URL(endpointBase, window.location.origin);
        var params = new URLSearchParams();
        params.set('page', 'dashboard_analytic_data');

        var formData = new FormData(filtersForm);
        var dateStart = (formData.get('date_start') || '').toString().trim();
        var dateEnd = (formData.get('date_end') || '').toString().trim();
        var vehicleId = (formData.get('vehicle_id') || '').toString().trim();
        var driverId = (formData.get('driver_id') || '').toString().trim();
        var beneficiaryId = (formData.get('beneficiary_id') || '').toString().trim();
        var transportType = (formData.get('tip_transport') || '').toString().trim();
        var status = (formData.get('status') || '').toString().trim();

        if (dateStart !== '') {
            params.set('date_start', dateStart);
        }
        if (dateEnd !== '') {
            params.set('date_end', dateEnd);
        }
        if (vehicleId !== '') {
            params.set('vehicle_id', vehicleId);
        }
        if (driverId !== '') {
            params.set('driver_id', driverId);
        }
        if (beneficiaryId !== '') {
            params.set('beneficiary_id', beneficiaryId);
        }
        if (transportType !== '') {
            params.set('tip_transport', transportType);
        }
        if (status !== '') {
            params.set('status', status);
        }

        baseUrl.search = params.toString();
        return baseUrl.toString();
    }

    function syncBrowserUrl() {
        var url = new URL(window.location.href);
        url.searchParams.set('page', 'dashboard_analitic');

        var formData = new FormData(filtersForm);
        var keys = ['date_start', 'date_end', 'vehicle_id', 'driver_id', 'beneficiary_id', 'tip_transport', 'status'];

        keys.forEach(function (key) {
            var value = (formData.get(key) || '').toString().trim();
            if (value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, value);
            }
        });

        history.replaceState({}, '', url.toString());
    }

    function renderFleetKpis(fleet) {
        setText('kpi_total_curse', String(Math.round(safeNumber(fleet.total_curse))));
        setText('kpi_total_facturare', formatMoney(fleet.total_facturare));
        setText('kpi_total_cheltuieli', formatMoney(fleet.total_cheltuieli));
        setText('kpi_profit_total', formatMoney(fleet.profit_total));
        setText('kpi_total_km', formatKm(fleet.total_km));
        setText('kpi_tone_livrate', formatTons(fleet.tone_livrate));
        setText('kpi_profit_km', formatRatio(fleet.profit_km) + ' lei');
        setText('kpi_grad_mediu', formatPercent(fleet.grad_incarcare_mediu));
    }

    function renderVehicleTable(vehicles) {
        if (!vehicleBody) {
            return;
        }

        if (!Array.isArray(vehicles) || vehicles.length === 0) {
            vehicleBody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">Nu exista date.</td></tr>';
            return;
        }

        var html = vehicles.map(function (row) {
            return '' +
                '<tr>' +
                '<td><strong>' + escapeHtml(row.nr_inmatriculare || '-') + '</strong></td>' +
                '<td class="text-end">' + Math.round(safeNumber(row.curse)) + '</td>' +
                '<td class="text-end">' + formatKmCell(row.km_totali) + '</td>' +
                '<td class="text-end">' + formatTonsCell(row.tone_livrate) + '</td>' +
                '<td class="text-end">' + formatMoneyCell(row.facturare) + '</td>' +
                '<td class="text-end">' + formatMoneyCell(row.cheltuieli) + '</td>' +
                '<td class="text-end ' + (safeNumber(row.profit) < 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold') + '">' + formatMoneyCell(row.profit) + '</td>' +
                '<td class="text-end">' + formatRatioCell(row.venit_km) + '</td>' +
                '<td class="text-end">' + formatRatioCell(row.cost_km) + '</td>' +
                '<td class="text-end">' + formatRatioCell(row.profit_km) + '</td>' +
                '<td class="text-end">' + formatPercentCell(row.km_nefacturati_percent) + '</td>' +
                '<td class="text-end">' + formatPercentCell(row.grad_incarcare_mediu) + '</td>' +
                '</tr>';
        }).join('');

        vehicleBody.innerHTML = html;
    }

    function renderDriverTable(drivers) {
        if (!driverBody) {
            return;
        }

        if (!Array.isArray(drivers) || drivers.length === 0) {
            driverBody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Nu exista date.</td></tr>';
            return;
        }

        var html = drivers.map(function (row) {
            return '' +
                '<tr>' +
                '<td><strong>' + escapeHtml(row.sofer || '-') + '</strong></td>' +
                '<td class="text-end">' + Math.round(safeNumber(row.curse)) + '</td>' +
                '<td class="text-end">' + formatKmCell(row.km_totali) + '</td>' +
                '<td class="text-end">' + formatTonsCell(row.tone_livrate) + '</td>' +
                '<td class="text-end">' + formatMoneyCell(row.facturare_generata) + '</td>' +
                '<td class="text-end ' + (safeNumber(row.profit_generat) < 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold') + '">' + formatMoneyCell(row.profit_generat) + '</td>' +
                '<td class="text-end">' + formatTonsCell(row.tone_per_cursa) + '</td>' +
                '<td class="text-end">' + formatKmCell(row.km_per_cursa) + '</td>' +
                '<td class="text-end">' + formatPercentCell(row.grad_incarcare_mediu) + '</td>' +
                '</tr>';
        }).join('');

        driverBody.innerHTML = html;
    }

    function renderAlerts(alerts) {
        if (!alertsList) {
            return;
        }

        if (!Array.isArray(alerts) || alerts.length === 0) {
            alertsList.innerHTML = '<li class="text-muted">Nu exista alerte pentru filtrele selectate.</li>';
            return;
        }

        alertsList.innerHTML = alerts.map(function (alertItem) {
            var severity = alertItem.severity === 'danger' ? 'danger' : 'warning';
            var badgeClass = severity === 'danger' ? 'text-bg-danger' : 'text-bg-warning text-dark';
            var value = alertItem.value !== undefined && alertItem.value !== null ? ' (' + escapeHtml(String(alertItem.value)) + ')' : '';
            return '' +
                '<li class="dashboard-analytic-alert-item">' +
                '<span class="badge ' + badgeClass + '">' + (severity === 'danger' ? 'Critic' : 'Atentie') + '</span>' +
                '<span><strong>' + escapeHtml(alertItem.target || '-') + '</strong>: ' + escapeHtml(alertItem.message || '-') + value + '</span>' +
                '</li>';
        }).join('');
    }

    function renderCharts(charts) {
        var palette = {
            blue: '#2563eb',
            green: '#16a34a',
            red: '#dc2626',
            amber: '#f59e0b',
            cyan: '#0891b2',
            violet: '#7c3aed'
        };

        var profitEvolution = charts.profit_evolution || {};
        renderChart('chart_profit_evolution', {
            type: 'line',
            data: {
                labels: profitEvolution.labels || [],
                datasets: [
                    {
                        label: 'Facturare',
                        data: profitEvolution.facturare || [],
                        borderColor: palette.blue,
                        backgroundColor: 'rgba(37,99,235,0.15)',
                        tension: 0.3
                    },
                    {
                        label: 'Cheltuieli',
                        data: profitEvolution.cheltuieli || [],
                        borderColor: palette.red,
                        backgroundColor: 'rgba(220,38,38,0.12)',
                        tension: 0.3
                    },
                    {
                        label: 'Profit',
                        data: profitEvolution.profit || [],
                        borderColor: palette.green,
                        backgroundColor: 'rgba(22,163,74,0.12)',
                        tension: 0.3
                    }
                ]
            },
            options: commonChartOptions()
        });

        var kmChart = charts.km_billed_vs_unbilled || {};
        renderChart('chart_km_billed_unbilled', {
            type: 'bar',
            data: {
                labels: kmChart.labels || ['Km'],
                datasets: [
                    {
                        label: 'Km facturati',
                        data: kmChart.km_facturati || [0],
                        backgroundColor: 'rgba(37,99,235,0.85)'
                    },
                    {
                        label: 'Km nefacturati',
                        data: kmChart.km_nefacturati || [0],
                        backgroundColor: 'rgba(220,38,38,0.80)'
                    }
                ]
            },
            options: Object.assign(commonChartOptions(), {
                plugins: {
                    legend: { position: 'bottom' }
                },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true }
                }
            })
        });

        var transportDistribution = charts.transport_distribution || {};
        renderChart('chart_transport_distribution', {
            type: 'doughnut',
            data: {
                labels: transportDistribution.labels || [],
                datasets: [{
                    data: transportDistribution.values || [],
                    backgroundColor: ['#2563eb', '#16a34a', '#f59e0b', '#7c3aed', '#0891b2', '#ef4444']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });

        var topProfit = charts.top_vehicle_profit || {};
        renderChart('chart_vehicle_top_profit', {
            type: 'bar',
            data: {
                labels: topProfit.labels || [],
                datasets: [{
                    label: 'Profit',
                    data: topProfit.values || [],
                    backgroundColor: 'rgba(22,163,74,0.85)'
                }]
            },
            options: horizontalBarOptions()
        });

        var profitPerKm = charts.vehicle_profit_per_km || {};
        renderChart('chart_vehicle_profit_km', {
            type: 'bar',
            data: {
                labels: profitPerKm.labels || [],
                datasets: [{
                    label: 'Profit/Km',
                    data: profitPerKm.values || [],
                    backgroundColor: 'rgba(37,99,235,0.85)'
                }]
            },
            options: horizontalBarOptions()
        });

        var ridesPerDriver = charts.rides_per_driver || {};
        renderChart('chart_driver_rides', {
            type: 'bar',
            data: {
                labels: ridesPerDriver.labels || [],
                datasets: [{
                    label: 'Curse',
                    data: ridesPerDriver.values || [],
                    backgroundColor: 'rgba(37,99,235,0.85)'
                }]
            },
            options: horizontalBarOptions()
        });

        var tonsPerDriver = charts.tons_per_driver || {};
        renderChart('chart_driver_tons', {
            type: 'bar',
            data: {
                labels: tonsPerDriver.labels || [],
                datasets: [{
                    label: 'Tone livrate',
                    data: tonsPerDriver.values || [],
                    backgroundColor: 'rgba(245,158,11,0.85)'
                }]
            },
            options: horizontalBarOptions()
        });

        var driverMatrix = charts.driver_activity_matrix || {};
        renderChart('chart_driver_matrix', {
            type: 'bubble',
            data: {
                datasets: (driverMatrix.points || []).map(function (point, index) {
                    var colors = ['#2563eb', '#16a34a', '#f59e0b', '#7c3aed', '#0891b2', '#ef4444'];
                    return {
                        label: point.label || ('Sofer ' + (index + 1)),
                        data: [{ x: safeNumber(point.x), y: safeNumber(point.y), r: safeNumber(point.r) }],
                        backgroundColor: colors[index % colors.length]
                    };
                })
            },
            options: Object.assign(commonChartOptions(), {
                scales: {
                    x: { title: { display: true, text: 'Numar curse' }, beginAtZero: true },
                    y: { title: { display: true, text: 'Tone livrate' }, beginAtZero: true }
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var label = context.dataset.label || '';
                                var point = context.raw || {};
                                return label + ': curse ' + safeNumber(point.x) + ', tone ' + safeNumber(point.y);
                            }
                        }
                    }
                }
            })
        });
    }

    function commonChartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        };
    }

    function horizontalBarOptions() {
        return {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: { beginAtZero: true }
            }
        };
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function formatMoneyCell(value) {
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(safeNumber(value)) + ' lei';
    }

    function formatKmCell(value) {
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 0, maximumFractionDigits: 1 }).format(safeNumber(value));
    }

    function formatTonsCell(value) {
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(safeNumber(value));
    }

    function formatPercentCell(value) {
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(safeNumber(value)) + '%';
    }

    function formatRatioCell(value) {
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(safeNumber(value));
    }

    function renderPayload(payload) {
        var fleet = payload.fleet || {};
        var vehicles = Array.isArray(payload.vehicles) ? payload.vehicles : [];
        var drivers = Array.isArray(payload.drivers) ? payload.drivers : [];
        var charts = payload.charts || {};
        var alerts = Array.isArray(payload.alerts) ? payload.alerts : [];

        renderFleetKpis(fleet);
        renderVehicleTable(vehicles);
        renderDriverTable(drivers);
        renderAlerts(alerts);
        renderCharts(charts);

        setContentVisibility(safeNumber(fleet.total_curse) > 0);
    }

    function updateLastRefresh() {
        if (!lastRefreshEl) {
            return;
        }

        var now = new Date();
        var timeLabel = now.toLocaleTimeString('ro-RO', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        lastRefreshEl.textContent = 'Ultima actualizare: ' + timeLabel;
    }

    async function fetchAndRender(options) {
        options = options || {};
        var silent = !!options.silent;
        var requestId = ++activeRequestId;

        if (!silent) {
            setLoadingState(true);
        }

        try {
            var response = await fetch(buildApiUrl(), {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            var payload = await response.json();
            if (requestId !== activeRequestId) {
                return;
            }

            renderPayload(payload);
            updateLastRefresh();
        } catch (error) {
            console.error('[dashboard-analitic] fetch error:', error);
            setContentVisibility(false);
        } finally {
            if (!silent) {
                setLoadingState(false);
            }
        }
    }

    function restartRefreshTimer() {
        if (refreshTimer !== null) {
            clearInterval(refreshTimer);
        }

        refreshTimer = window.setInterval(function () {
            fetchAndRender({ silent: true });
        }, refreshMs);
    }

    filtersForm.addEventListener('submit', function (event) {
        event.preventDefault();
        syncBrowserUrl();
        fetchAndRender();
        restartRefreshTimer();
    });

    filtersForm.querySelectorAll('select,input[type="date"]').forEach(function (element) {
        element.addEventListener('change', function () {
            syncBrowserUrl();
            fetchAndRender({ silent: true });
            restartRefreshTimer();
        });
    });

    fetchAndRender();
    restartRefreshTimer();
})();
