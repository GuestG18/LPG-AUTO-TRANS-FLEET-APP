(function () {
    'use strict';

    var root = document.querySelector('.course-expense-history-page');
    if (!root) {
        return;
    }

    function safeNumber(value) {
        var number = Number(value);
        return Number.isFinite(number) ? number : 0;
    }

    function formatMoney(value) {
        return new Intl.NumberFormat('ro-RO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(safeNumber(value)) + ' lei';
    }

    function formatAxisValue(value) {
        var number = safeNumber(value);
        var absolute = Math.abs(number);
        if (absolute >= 1000) {
            var thousands = number / 1000;
            var decimals = Number.isInteger(thousands) ? 0 : 1;
            return new Intl.NumberFormat('ro-RO', {
                maximumFractionDigits: decimals
            }).format(thousands) + 'k';
        }

        return new Intl.NumberFormat('ro-RO', {
            maximumFractionDigits: absolute < 10 && absolute !== Math.floor(absolute) ? 1 : 0
        }).format(number);
    }

    function parseCharts() {
        try {
            return JSON.parse(root.getAttribute('data-course-expense-charts') || '{}');
        } catch (error) {
            return {};
        }
    }

    function initAddExpenseModal() {
        var raceSelect = document.querySelector('[data-role="course-expense-race-select"]');
        var vehicleDisplay = document.querySelector('[data-role="course-expense-vehicle-display"]');
        var driverDisplay = document.querySelector('[data-role="course-expense-driver-display"]');

        if (!(raceSelect instanceof HTMLSelectElement) || !(vehicleDisplay instanceof HTMLInputElement) || !(driverDisplay instanceof HTMLInputElement)) {
            return;
        }

        var sync = function () {
            var option = raceSelect.options[raceSelect.selectedIndex] || null;
            if (!(option instanceof HTMLOptionElement) || option.value === '') {
                vehicleDisplay.value = '';
                driverDisplay.value = '';
                return;
            }

            vehicleDisplay.value = option.getAttribute('data-vehicle') || '';
            driverDisplay.value = option.getAttribute('data-driver') || '';
        };

        raceSelect.addEventListener('change', sync);
        sync();
    }

    function initCharts() {
        if (typeof Chart === 'undefined') {
            return;
        }

        var barValueLabelPlugin = {
            id: 'courseExpenseBarValueLabels',
            afterDatasetsDraw: function (chart) {
                var labelOptions = chart.options.plugins && chart.options.plugins.courseExpenseValueLabels;
                if (!labelOptions || !labelOptions.enabled) {
                    return;
                }

                var ctx = chart.ctx;
                var canvasRight = chart.width - 8;
                ctx.save();
                ctx.fillStyle = labelOptions.color || '#475569';
                ctx.font = (labelOptions.fontWeight || 550) + ' ' + (labelOptions.fontSize || 10.5) + 'px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';

                chart.data.datasets.forEach(function (dataset, datasetIndex) {
                    var meta = chart.getDatasetMeta(datasetIndex);
                    if (!meta || meta.hidden) {
                        return;
                    }

                    meta.data.forEach(function (bar, index) {
                        var rawValue = Array.isArray(dataset.data) ? dataset.data[index] : 0;
                        var label = formatMoney(rawValue);
                        var textWidth = ctx.measureText(label).width;
                        var x = Math.min(bar.x + 8, canvasRight - textWidth);
                        var y = bar.y;
                        ctx.fillText(label, x, y);
                    });
                });

                ctx.restore();
            }
        };
        Chart.register(barValueLabelPlugin);

        var charts = parseCharts();
        var categories = charts.categories || {};
        var topProfit = charts.top_profit || {};
        var dailyProfit = charts.daily_profit || {};
        var palette = ['#0d6efd', '#e91e63', '#6f42c1', '#16a34a', '#f59e0b', '#ec4899', '#0891b2'];

        var categoryLabels = Array.isArray(categories.labels) ? categories.labels : [];
        var categoryValues = Array.isArray(categories.values) ? categories.values : [];
        var categoryPercentages = Array.isArray(categories.percentages) ? categories.percentages : [];

        var donutEl = document.getElementById('courseExpenseCategoryDonut');
        if (donutEl) {
            new Chart(donutEl, {
                type: 'doughnut',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        data: categoryValues,
                        backgroundColor: palette,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    cutout: '62%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return context.label + ': ' + formatMoney(context.raw);
                                }
                            }
                        }
                    }
                }
            });
        }

        var legendEl = document.getElementById('courseExpenseCategoryLegend');
        if (legendEl) {
            legendEl.innerHTML = categoryLabels.map(function (label, index) {
                var color = palette[index % palette.length];
                return '' +
                    '<div class="course-expense-category-legend-row">' +
                    '<span style="background:' + color + '"></span>' +
                    '<strong>' + escapeHtml(label) + '</strong>' +
                    '<em>' + escapeHtml(formatMoney(categoryValues[index] || 0)) + ' (' + safeNumber(categoryPercentages[index]).toLocaleString('ro-RO', { maximumFractionDigits: 1 }) + '%)</em>' +
                    '</div>';
            }).join('');
        }

        var barEl = document.getElementById('courseExpenseCategoryBars');
        if (barEl) {
            new Chart(barEl, {
                type: 'bar',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        data: categoryValues,
                        backgroundColor: 'rgba(13, 110, 253, 0.82)',
                        borderRadius: 4,
                        barThickness: 9,
                        maxBarThickness: 9
                    }]
                },
                options: horizontalOptions('lei', true)
            });
        }

        var topProfitEl = document.getElementById('courseExpenseTopProfit');
        if (topProfitEl) {
            new Chart(topProfitEl, {
                type: 'bar',
                data: {
                    labels: Array.isArray(topProfit.labels) ? topProfit.labels : [],
                    datasets: [{
                        data: Array.isArray(topProfit.values) ? topProfit.values : [],
                        backgroundColor: 'rgba(22, 163, 74, 0.85)',
                        borderRadius: 4,
                        barThickness: 9,
                        maxBarThickness: 9
                    }]
                },
                options: horizontalOptions('lei', true)
            });
        }

        var dailyEl = document.getElementById('courseExpenseDailyProfit');
        if (dailyEl) {
            new Chart(dailyEl, {
                type: 'line',
                data: {
                    labels: Array.isArray(dailyProfit.labels) ? dailyProfit.labels : [],
                    datasets: [{
                        label: 'Profit (lei)',
                        data: Array.isArray(dailyProfit.values) ? dailyProfit.values : [],
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22, 163, 74, 0.14)',
                        borderWidth: 2,
                        pointBackgroundColor: '#16a34a',
                        pointBorderColor: '#16a34a',
                        pointRadius: 2.6,
                        pointHoverRadius: 3.2,
                        tension: 0.32,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 28,
                                boxHeight: 2,
                                color: '#475569',
                                font: { size: 11, weight: 650 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return 'Profit: ' + formatMoney(context.raw);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                maxTicksLimit: 6,
                                callback: formatAxisValue
                            }
                        },
                        x: {
                            ticks: {
                                color: '#64748b',
                                maxRotation: 0,
                                autoSkipPadding: 12
                            }
                        }
                    }
                }
            });
        }
    }

    function horizontalOptions(unit, showValueLabels) {
        return {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            layout: {
                padding: { left: 12, right: showValueLabels ? 92 : 0 }
            },
            plugins: {
                legend: { display: false },
                courseExpenseValueLabels: {
                    enabled: !!showValueLabels,
                    color: '#475569',
                    fontSize: 10.5,
                    fontWeight: 550
                },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return unit === 'lei' ? formatMoney(context.raw) : String(context.raw);
                        }
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        color: '#475569',
                        font: { size: 11 }
                    }
                },
                x: {
                    beginAtZero: true,
                    grace: '18%',
                    ticks: {
                        maxTicksLimit: 6,
                        callback: formatAxisValue
                    }
                }
            }
        };
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAddExpenseModal();
        initCharts();
    });
})();
