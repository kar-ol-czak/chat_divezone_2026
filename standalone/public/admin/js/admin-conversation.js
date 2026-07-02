/**
 * Modal podglądu rozmowy (TASK-055 sekcja "Modal podgląd rozmowy").
 * Klik na wiersz w "Top 10" otwiera modal z pełną historią z divechat_messages.
 */
(function () {
    'use strict';

    var DiveAdmin = window.DiveAdmin = window.DiveAdmin || {};

    var modal = null;
    var titleEl = null;
    var metaEl = null;
    var bodyEl = null;

    document.addEventListener('DOMContentLoaded', function () {
        modal = document.getElementById('convModal');
        titleEl = document.getElementById('convModalTitle');
        metaEl = document.getElementById('convModalMeta');
        bodyEl = document.getElementById('convModalBody');

        document.querySelectorAll('[data-close-modal]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
    });

    DiveAdmin.openConversationModal = function (conversationId) {
        if (!modal) return;
        titleEl.textContent = 'Rozmowa #' + conversationId;
        metaEl.textContent = 'Ładowanie…';
        bodyEl.innerHTML = '';
        modal.classList.remove('hidden');

        DiveAdmin.api('/api/admin/conversations/' + conversationId)
            .then(function (data) {
                renderHeader(data);
                renderMessages(data.messages || []);
                // CHAT-T-123 (ADR-110): ślad chipów, przez który klient wszedł w
                // rozmowę — wstawiany NAD wątkiem (renderMessages czyści bodyEl, więc
                // dokładamy po nim). Pusty/NULL chip_path => nic nie renderujemy.
                renderChipPath(data.chip_path);
            })
            .catch(function (err) {
                metaEl.textContent = 'Błąd: ' + err.message;
            });
    };

    function closeModal() {
        modal.classList.add('hidden');
    }

    function renderHeader(data) {
        var totals = data.totals || {};
        var session = data.session_id ? data.session_id.substring(0, 8) : '—';

        metaEl.innerHTML =
            'session: <code>' + DiveAdmin.escHtml(session) + '</code> · '
            + DiveAdmin.fmt.date(data.started_at) + ' · '
            + (data.model_used ? '<strong>' + DiveAdmin.escHtml(data.model_used) + '</strong> · ' : '')
            + 'koszt: <strong>' + DiveAdmin.fmt.pln(totals.cost_pln) + '</strong> ('
            + DiveAdmin.fmt.usd(totals.cost_usd) + ') · '
            + DiveAdmin.fmt.tokens(totals.input_tokens) + ' in / '
            + DiveAdmin.fmt.tokens(totals.output_tokens) + ' out'
            + (totals.cache_read_tokens ? ' · cache: ' + DiveAdmin.fmt.tokens(totals.cache_read_tokens) : '');
    }

    /**
     * Ślad chipów (breadcrumb) nad wątkiem: "Ścieżka: Dobór sprzętu › Komputer".
     * chip_path = [{node_key, label, level}] utrwalone przez CHAT-T-122. Render tylko
     * gdy niepusta lista z co najmniej jedną etykietą — rozmowy z wolnego pisania
     * (NULL/[]) nie dostają bloku. Etykiety escapowane (DiveAdmin.escHtml); separator
     * "›" to stały tekst UI, nie dane. CHAT-T-123 (ADR-110).
     */
    function renderChipPath(chipPath) {
        if (!Array.isArray(chipPath) || chipPath.length === 0) return;

        var labels = chipPath
            .map(function (node) { return (node && node.label != null) ? String(node.label) : ''; })
            .filter(function (s) { return s.trim() !== ''; });
        if (labels.length === 0) return;

        var parts = labels.map(function (l) { return DiveAdmin.escHtml(l); });
        var trail = document.createElement('div');
        trail.className = 'conv-chip-path';
        trail.innerHTML = '<span class="conv-chip-path__label">Ścieżka:</span> '
            + parts.join(' <span class="conv-chip-path__sep">›</span> ');

        bodyEl.insertBefore(trail, bodyEl.firstChild);
    }

    function renderMessages(messages) {
        bodyEl.innerHTML = '';
        if (messages.length === 0) {
            bodyEl.innerHTML = '<div style="text-align:center;color:#999;padding:20px">Brak wiadomości.</div>';
            return;
        }

        messages.forEach(function (m) {
            bodyEl.appendChild(renderMessage(m));
        });
    }

    function renderMessage(m) {
        var wrap = document.createElement('div');
        wrap.className = 'msg msg--' + m.role;

        var sidebar = document.createElement('div');
        sidebar.className = 'msg__sidebar';
        sidebar.innerHTML = renderSidebar(m);

        var bubble = document.createElement('div');
        bubble.className = 'msg__bubble';
        bubble.textContent = m.content || '';

        if (m.role === 'user') {
            // Sidebar po lewej (flex-direction row-reverse w CSS)
            wrap.appendChild(bubble);
            wrap.appendChild(sidebar);
        } else {
            wrap.appendChild(sidebar);
            wrap.appendChild(bubble);
        }

        return wrap;
    }

    function renderSidebar(m) {
        var html = '<span class="msg__tag">' + DiveAdmin.escHtml(m.role) + '</span><br>';
        html += DiveAdmin.fmt.date(m.created_at) + '<br>';

        if (m.usage) {
            html += '<strong>' + DiveAdmin.escHtml(m.usage.model_id) + '</strong><br>';
            html += DiveAdmin.fmt.tokens(m.usage.input_tokens) + ' / '
                + DiveAdmin.fmt.tokens(m.usage.output_tokens) + ' tok<br>';
            html += DiveAdmin.fmt.latency(m.usage.latency_ms) + '<br>';
            html += '<strong>' + DiveAdmin.fmt.pln(m.usage.cost_pln) + '</strong>';
        }

        if (m.tool_calls && Array.isArray(m.tool_calls) && m.tool_calls.length > 0) {
            var names = m.tool_calls.map(function (tc) { return tc.name || tc.tool_call_id; }).filter(Boolean);
            if (names.length > 0) {
                html += '<br>tools: ' + DiveAdmin.escHtml(names.join(', '));
            }
        }

        return html;
    }
})();
