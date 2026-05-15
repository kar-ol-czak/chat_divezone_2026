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

    // Wysyła POST/PUT/DELETE z body JSON. Zwraca promise z parsedJson lub rzuca Error
    // wzbogacony o pola .status oraz .body — żeby caller mógł rozróżnić 404 / 409 / inne.
    //
    // Method override: PUT i DELETE są wysyłane jako POST + X-HTTP-Method-Override.
    // Powód: Apache ModSecurity na chat.divezone.pl blokuje PUT/DELETE z 403
    // zanim trafią do PHP (smoke T-012 15.05). Backend rozpoznaje header i mapuje
    // method z powrotem na PUT/DELETE w Request::resolveMethod().
    DiveAdmin.send = function (method, url, body) {
        var useOverride = method === 'PUT' || method === 'DELETE';
        var headers = { 'Content-Type': 'application/json' };
        if (useOverride) {
            headers['X-HTTP-Method-Override'] = method;
        }
        var opts = {
            method: useOverride ? 'POST' : method,
            credentials: 'same-origin',
            headers: headers,
        };
        if (body !== undefined && body !== null) {
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(function (r) {
            return r.text().then(function (txt) {
                var data = null;
                if (txt) {
                    try { data = JSON.parse(txt); } catch (e) { data = { raw: txt }; }
                }
                if (!r.ok) {
                    var msg = (data && data.error) ? data.error : ('HTTP ' + r.status);
                    var err = new Error(msg);
                    err.status = r.status;
                    err.body = data;
                    throw err;
                }
                return data;
            });
        });
    };

    // T-011: prosty toast (auto-dismiss po 3s)
    DiveAdmin.toast = function (message, kind) {
        var container = document.getElementById('toastContainer');
        if (!container) return;
        var t = document.createElement('div');
        t.className = 'toast' + (kind ? ' toast--' + kind : '');
        t.textContent = message;
        container.appendChild(t);
        setTimeout(function () {
            t.classList.add('toast--leaving');
            setTimeout(function () { t.remove(); }, 200);
        }, 3000);
    };

    // T-011: hash-based router
    function parseHash() {
        var raw = (window.location.hash || '').replace(/^#\/?/, '');
        var qIdx = raw.indexOf('?');
        var path = qIdx >= 0 ? raw.substring(0, qIdx) : raw;
        var queryStr = qIdx >= 0 ? raw.substring(qIdx + 1) : '';
        var query = {};
        queryStr.split('&').forEach(function (kv) {
            if (!kv) return;
            var pair = kv.split('=');
            query[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || '');
        });
        return { tab: path || 'koszty', query: query };
    }

    DiveAdmin.router = {
        get: parseHash,
        listeners: [],
        onChange: function (cb) { this.listeners.push(cb); },
    };

    function applyRoute() {
        var route = parseHash();
        var tab = route.tab;
        // Pokaż tylko sekcje pasujące do tab; reszta hidden
        document.querySelectorAll('[data-tab]').forEach(function (el) {
            if (el.classList.contains('topbar__link')) return; // linki obsługujemy osobno
            if (el.dataset.tab === tab) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        });
        // Active link
        document.querySelectorAll('.topbar__link[data-tab]').forEach(function (link) {
            link.classList.toggle('topbar__link--active', link.dataset.tab === tab);
        });
        DiveAdmin.router.listeners.forEach(function (cb) {
            try { cb(route); } catch (e) { console.error('router listener error:', e); }
        });
    }

    window.addEventListener('hashchange', applyRoute);

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
        // Default na #/koszty jeśli pusty hash, żeby active link działał konsystentnie
        if (!window.location.hash) {
            window.location.replace('#/koszty');
        }
        applyRoute();
        DiveAdmin.loadKpi();
        // Inne sekcje ładują się przez własne moduły (admin-charts.js, admin-tables.js, admin-editorial.js)
    });
})();
