/**
 * DiveChat Admin Dashboard - Główny init.
 *
 * Boot: ładowanie KPI + delegacja do innych modułów (charts, tables, conversation).
 * Format: vanilla JS, fetch z basic auth (przeglądarka dodaje Authorization
 * header automatycznie po basic auth login).
 */
(function () {
    'use strict';

    var DiveAdmin = window.DiveAdmin = window.DiveAdmin || {};

    // Wspólne formatery liczb (TASK-055 sekcja "Format liczb")
    DiveAdmin.fmt = {
        usd: function (n) {
            n = Number(n) || 0;
            if (n === 0) return '$0.00';
            if (Math.abs(n) < 0.01) return '$' + n.toFixed(4);
            return '$' + n.toFixed(2);
        },
        pln: function (n) {
            n = Number(n) || 0;
            return n.toFixed(2) + ' zł';
        },
        tokens: function (n) {
            n = Number(n) || 0;
            return String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        },
        latency: function (ms) {
            if (ms == null) return '—';
            ms = Number(ms);
            if (ms < 1000) return ms + ' ms';
            return (ms / 1000).toFixed(1) + ' s';
        },
        date: function (iso) {
            if (!iso) return '—';
            var d = new Date(iso);
            if (isNaN(d.getTime())) return iso;
            var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        },
        dateOnly: function (iso) {
            if (!iso) return '—';
            return iso.substring(0, 10);
        },
    };

    // Generic fetch wrapper - error handling, JSON
    DiveAdmin.api = function (url) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 401) {
                    // Basic auth wymagane; przeglądarka pokaże prompt po pierwszym 401.
                    throw new Error('Unauthorized – odśwież stronę i zaloguj się.');
                }
                if (!r.ok) {
                    throw new Error('HTTP ' + r.status);
                }
                return r.json();
            });
    };

    // KPI section - render z /api/admin/cost/kpi
    DiveAdmin.loadKpi = function () {
        var section = document.getElementById('kpiSection');
        DiveAdmin.api('/api/admin/cost/kpi')
            .then(function (kpi) {
                section.innerHTML = '';
                section.appendChild(renderKpiCard('Dziś', kpi.today, kpi.currency_pln_rate));
                section.appendChild(renderKpiCard('Tydzień', kpi.this_week, kpi.currency_pln_rate));
                section.appendChild(renderKpiCard('Miesiąc', kpi.this_month, kpi.currency_pln_rate));
                section.appendChild(renderCprCard(kpi.cost_per_resolution));
            })
            .catch(function (err) {
                section.innerHTML = '<div class="kpi__card"><span class="kpi__label">Błąd ładowania KPI</span>'
                    + '<span class="kpi__value">—</span><span class="kpi__sub">' + escHtml(err.message) + '</span></div>';
            });
    };

    function renderKpiCard(label, bucket, rate) {
        var card = document.createElement('div');
        card.className = 'kpi__card';
        var pln = bucket.cost_pln != null ? bucket.cost_pln : (bucket.cost_usd * rate);
        card.innerHTML =
            '<span class="kpi__label">' + escHtml(label) + '</span>' +
            '<span class="kpi__value">' + DiveAdmin.fmt.pln(pln) + '</span>' +
            '<span class="kpi__sub">'
                + DiveAdmin.fmt.usd(bucket.cost_usd) + ' · '
                + bucket.conversations + ' rozm. · '
                + bucket.messages + ' wiad.'
            + '</span>';
        return card;
    }

    function renderCprCard(cpr) {
        var card = document.createElement('div');
        card.className = 'kpi__card kpi__card--cpr';
        var benchmark = cpr.industry_benchmark_usd || '—';
        var humanRange = cpr.vs_human_agent_usd || '—';
        card.innerHTML =
            '<span class="kpi__label">CPR (vs human agent)</span>' +
            '<span class="kpi__value">' + DiveAdmin.fmt.pln(cpr.this_month_pln) + '</span>' +
            '<span class="kpi__sub">'
                + DiveAdmin.fmt.usd(cpr.this_month_usd)
                + ' · branża: ' + escHtml(benchmark) + ' $'
                + ' · człowiek: ' + escHtml(humanRange) + ' $'
            + '</span>';
        return card;
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    DiveAdmin.escHtml = escHtml;

    // --- Boot ---
    document.addEventListener('DOMContentLoaded', function () {
        DiveAdmin.loadKpi();
        // Inne sekcje ładują się przez własne moduły (admin-charts.js, admin-tables.js)
    });
})();
