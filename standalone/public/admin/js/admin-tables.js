/**
 * Tabele Top 10 (A3) i Breakdown per model (A4).
 */
(function () {
    'use strict';

    var DiveAdmin = window.DiveAdmin = window.DiveAdmin || {};

    var DAYS = 30;
    var TOP_LIMIT = 10;

    document.addEventListener('DOMContentLoaded', function () {
        loadTop();
        loadByModel();
    });

    function loadTop() {
        var tbody = document.getElementById('topConversationsBody');
        DiveAdmin.api('/api/admin/conversations/top?limit=' + TOP_LIMIT + '&days=' + DAYS)
            .then(function (resp) {
                var convs = resp.conversations || [];
                if (convs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="data-table__empty">'
                        + 'Brak rozmów w ostatnich ' + DAYS + ' dniach.</td></tr>';
                    return;
                }
                tbody.innerHTML = '';
                convs.forEach(function (c) {
                    tbody.appendChild(renderTopRow(c));
                });
            })
            .catch(function (err) {
                tbody.innerHTML = '<tr><td colspan="5" class="data-table__empty">Błąd: '
                    + DiveAdmin.escHtml(err.message) + '</td></tr>';
            });
    }

    function renderTopRow(c) {
        var tr = document.createElement('tr');
        tr.classList.add('clickable');
        tr.dataset.conversationId = c.id;

        var firstMsg = (c.first_user_message || '').substring(0, 80);
        if ((c.first_user_message || '').length > 80) firstMsg += '…';

        tr.innerHTML =
            '<td>' + DiveAdmin.fmt.date(c.started_at) + '</td>' +
            '<td>' + (c.model_used
                ? '<span class="badge--model">' + DiveAdmin.escHtml(c.model_used) + '</span>'
                : '—') + '</td>' +
            '<td class="num">' + c.messages_count + '</td>' +
            '<td class="num"><strong>' + DiveAdmin.fmt.pln(c.cost_pln) + '</strong>'
                + '<br><small style="color:#888">' + DiveAdmin.fmt.usd(c.cost_usd) + '</small></td>' +
            '<td class="ellipsis" title="' + DiveAdmin.escHtml(c.first_user_message || '') + '">'
                + DiveAdmin.escHtml(firstMsg) + '</td>';

        tr.addEventListener('click', function () {
            if (DiveAdmin.openConversationModal) {
                DiveAdmin.openConversationModal(c.id);
            }
        });

        return tr;
    }

    function loadByModel() {
        var tbody = document.getElementById('byModelBody');
        DiveAdmin.api('/api/admin/cost/by-model?days=' + DAYS)
            .then(function (resp) {
                var models = resp.models || [];
                if (models.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="data-table__empty">'
                        + 'Brak danych w ostatnich ' + DAYS + ' dniach.</td></tr>';
                    return;
                }
                tbody.innerHTML = '';
                models.forEach(function (m) {
                    tbody.appendChild(renderModelRow(m));
                });
            })
            .catch(function (err) {
                tbody.innerHTML = '<tr><td colspan="7" class="data-table__empty">Błąd: '
                    + DiveAdmin.escHtml(err.message) + '</td></tr>';
            });
    }

    function renderModelRow(m) {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><strong>' + DiveAdmin.escHtml(m.label) + '</strong>'
                + '<br><small style="color:#888">' + DiveAdmin.escHtml(m.provider) + '</small></td>' +
            '<td class="num">' + m.uses + '</td>' +
            '<td class="num">' + DiveAdmin.fmt.tokens(m.input_tokens)
                + ' / ' + DiveAdmin.fmt.tokens(m.output_tokens) + '</td>' +
            '<td class="num">' + DiveAdmin.fmt.tokens(m.cache_read_tokens) + '</td>' +
            '<td class="num">' + DiveAdmin.fmt.latency(m.avg_latency_ms) + '</td>' +
            '<td class="num"><strong>' + DiveAdmin.fmt.pln(m.cost_pln) + '</strong>'
                + '<br><small style="color:#888">' + DiveAdmin.fmt.usd(m.cost_usd) + '</small></td>' +
            '<td class="num">' + DiveAdmin.fmt.usd(m.avg_cost_per_use_usd) + '</td>';
        return tr;
    }
})();
