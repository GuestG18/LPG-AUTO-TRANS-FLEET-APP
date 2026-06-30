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
        (tons.loaded || []).concat(tons.delivered || []),
        function () {
            return {
                type: 'bar',
                data: {
                    labels: tons.labels || [],
                    datasets: [
                        {
                            label: 'Incarcat',
                            data: tons.loaded || [],
                            backgroundColor: '#0d6efd',
                            borderRadius: 5,
                            maxBarThickness: 28
                        },
                        {
                            label: 'Livrat',
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
        '#f59e0b'
    ]);

    createDoughnut('driver_history_transport_chart', chartData.transport_distribution || {}, [
        '#0d6efd',
        '#22c55e',
        '#f59e0b',
        '#7c3aed'
    ]);

    var dateFocusButton = document.querySelector('[data-driver-history-focus-date]');
    if (dateFocusButton) {
        dateFocusButton.addEventListener('click', function () {
            var input = document.getElementById('driver_history_date_range');
            if (input) {
                input.focus();
                input.select();
            }
        });
    }
}());
