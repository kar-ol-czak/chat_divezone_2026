/**
 * Trend wydatków - Chart.js (TASK-055 A1).
 * Toggle daily/weekly/monthly. PLN na osi Y (główna metryka), tooltip dorzuca USD.
 */
(function () {
    'use strict';

    var DiveAdmin = window.DiveAdmin = window.DiveAdmin || {};

    var chartInstance = null;
    var currentPeriod = 'daily';
    var DAYS = 30;

    document.addEventListener('DOMContentLoaded', function () {
        var btns = document.querySelectorAll('.btn--toggle[data-period]');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                btns.forEach(function (b) { b.classList.remove('btn--active'); });
                btn.classList.add('btn--active');
                currentPeriod = btn.dataset.period;
                loadTrend();
            });
        });

        // Czekamy aż Chart.js się załaduje (defer + CDN może być po DOMContentLoaded).
        if (typeof Chart === 'undefined') {
            window.addEventListener('load', loadTrend);
        } else {
            loadTrend();
        }
    });

    function loadTrend() {
        var canvas = document.getElementById('trendChart');
        var emptyEl = document.getElementById('trendEmpty');

        DiveAdmin.api('/api/admin/cost/trend?period=' + currentPeriod + '&days=' + DAYS)
            .then(function (resp) {
                var data = resp.data || [];
                if (data.length === 0) {
                    emptyEl.classList.remove('hidden');
                    canvas.classList.add('hidden');
                    if (chartInstance) { chartInstance.destroy(); chartInstance = null; }
                    return;
                }
                emptyEl.classList.add('hidden');
                canvas.classList.remove('hidden');
                renderChart(data, resp.currency_pln_rate);
            })
            .catch(function (err) {
                emptyEl.textContent = 'Błąd: ' + err.message;
                emptyEl.classList.remove('hidden');
                canvas.classList.add('hidden');
            });
    }

    function renderChart(data, rate) {
        var labels = data.map(function (d) { return DiveAdmin.fmt.dateOnly(d.date); });
        var plnValues = data.map(function (d) { return d.cost_pln; });
        var usdValues = data.map(function (d) { return d.cost_usd; });
        var conversations = data.map(function (d) { return d.conversations; });

        var ctx = document.getElementById('trendChart').getContext('2d');

        if (chartInstance) { chartInstance.destroy(); }

        chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Koszt (PLN)',
                    data: plnValues,
                    borderColor: '#0066cc',
                    backgroundColor: 'rgba(0, 102, 204, 0.08)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var i = ctx.dataIndex;
                                return [
                                    DiveAdmin.fmt.pln(plnValues[i]),
                                    DiveAdmin.fmt.usd(usdValues[i]),
                                    conversations[i] + ' rozm.',
                                ];
                            },
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (v) { return DiveAdmin.fmt.pln(v); },
                        },
                    },
                    x: {
                        ticks: { maxRotation: 45, minRotation: 0 },
                    },
                },
            },
        });
    }
})();
