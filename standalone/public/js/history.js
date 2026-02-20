/**
 * DiveChat — Historia czatow
 * Lista rozmow z paginacja, search, podglad z detalami.
 */
(function () {
    'use strict';

    var listEl = document.getElementById('historyList');
    var searchEl = document.getElementById('historySearch');
    var btnLoadMore = document.getElementById('btnLoadMore');
    var btnBack = document.getElementById('btnBackToChat');
    var chatMessages = document.getElementById('chatMessages');
    var chatHeaderLabel = document.getElementById('chatHeaderLabel');
    var btnNewChat = document.getElementById('btnNewChat');

    var currentPage = 1;
    var totalLoaded = 0;
    var totalAvailable = 0;
    var searchTimeout = null;
    var viewingHistory = false;
    var savedChatHtml = '';

    // Public API
    window.DiveChat = window.DiveChat || {};
    window.DiveChat.History = {
        load: loadConversations,
        isViewing: function () { return viewingHistory; },
        exitView: exitHistoryView,
    };

    // --- Init ---
    loadConversations();

    // --- Events ---
    searchEl.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function () {
            currentPage = 1;
            totalLoaded = 0;
            loadConversations();
        }, 300);
    });

    btnLoadMore.addEventListener('click', function () {
        currentPage++;
        loadConversations(true);
    });

    btnBack.addEventListener('click', function () {
        exitHistoryView();
    });

    // --- Funkcje ---

    function loadConversations(append) {
        var search = searchEl.value.trim();
        var url = '/api/conversations?page=' + currentPage + '&per_page=20';
        if (search) url += '&search=' + encodeURIComponent(search);

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    listEl.innerHTML = '<div class="history-empty">Blad: ' + escHtml(data.error) + '</div>';
                    return;
                }
                renderList(data, append);
            })
            .catch(function (err) {
                listEl.innerHTML = '<div class="history-empty">Blad ladowania</div>';
                console.error('Historia:', err);
            });
    }

    function renderList(data, append) {
        var conversations = data.conversations || [];
        totalAvailable = data.total || 0;

        if (!append) {
            listEl.innerHTML = '';
            totalLoaded = 0;
        }

        if (conversations.length === 0 && totalLoaded === 0) {
            listEl.innerHTML = '<div class="history-empty">Brak rozmow</div>';
            btnLoadMore.classList.add('hidden');
            return;
        }

        conversations.forEach(function (conv) {
            listEl.appendChild(createHistoryItem(conv));
            totalLoaded++;
        });

        // Pokaz/ukryj "Zaladuj wiecej"
        if (totalLoaded < totalAvailable) {
            btnLoadMore.classList.remove('hidden');
        } else {
            btnLoadMore.classList.add('hidden');
        }
    }

    function createHistoryItem(conv) {
        var el = document.createElement('div');
        el.className = 'history-item';

        var sessionShort = (conv.session_id || '').substring(0, 8);
        var date = conv.started_at ? formatDate(conv.started_at) : '';
        var model = conv.model_used || '';
        var msgs = conv.message_count || 0;
        var cost = conv.estimated_cost != null ? '$' + conv.estimated_cost.toFixed(3) : '';
        var tools = (conv.tools_used || []).join(', ');

        var badges = '';
        if (model) badges += '<span class="history-item__badge badge--model">' + escHtml(model) + '</span>';
        badges += '<span class="history-item__badge badge--msgs">' + msgs + ' msg</span>';
        if (cost) badges += '<span class="history-item__badge badge--cost">' + cost + '</span>';
        if (tools) badges += '<span class="history-item__badge badge--tools">' + escHtml(tools) + '</span>';
        if (conv.knowledge_gap) badges += '<span class="history-item__badge badge--gap">gap</span>';
        if (conv.admin_status && conv.admin_status !== 'new') {
            badges += '<span class="history-item__badge badge--status-' + conv.admin_status + '">' + conv.admin_status + '</span>';
        } else if (conv.admin_status === 'new') {
            badges += '<span class="history-item__badge badge--status-new">new</span>';
        }

        el.innerHTML =
            '<div class="history-item__top">' +
                '<span class="history-item__session">#' + escHtml(sessionShort) + '</span>' +
                '<span class="history-item__date">' + escHtml(date) + '</span>' +
            '</div>' +
            '<div class="history-item__bottom">' + badges + '</div>';

        el.addEventListener('click', function () {
            loadConversationDetail(conv.session_id);
        });

        return el;
    }

    function loadConversationDetail(sessionId) {
        fetch('/api/conversations/' + encodeURIComponent(sessionId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    alert('Blad: ' + data.error);
                    return;
                }
                showConversation(data, sessionId);
            })
            .catch(function (err) {
                console.error('Blad ladowania rozmowy:', err);
            });
    }

    function showConversation(data, sessionId) {
        // Zapisz aktualny czat
        if (!viewingHistory) {
            savedChatHtml = chatMessages.innerHTML;
        }
        viewingHistory = true;

        // Pokaz przycisk powrotu, ukryj "Nowa rozmowa"
        btnBack.classList.remove('hidden');
        btnNewChat.classList.add('hidden');
        chatHeaderLabel.textContent = 'Rozmowa #' + sessionId.substring(0, 8);

        // Renderuj wiadomosci
        chatMessages.innerHTML = '';

        var messages = data.messages || [];
        var searchDiagnostics = data.search_diagnostics || [];
        var responseTimes = data.response_times || {};

        messages.forEach(function (msg) {
            if (msg.role === 'user') {
                chatMessages.appendChild(createMessageEl(msg.content, 'user', true));
            } else if (msg.role === 'assistant' && msg.content) {
                var el = createMessageEl(msg.content, 'ai', true);
                // Dodaj przycisk "Pokaz szczegoly"
                var detailsData = {
                    model: data.model_used,
                    tokens_input: data.tokens_input,
                    tokens_output: data.tokens_output,
                    response_times: responseTimes,
                    search_diagnostics: searchDiagnostics,
                    tool_calls: msg.tool_calls || null,
                };
                appendDetailsToggle(el, detailsData);
                chatMessages.appendChild(el);
            } else if (msg.role === 'tool_result') {
                // Nie renderujemy tool_result jako wiadomosc
            }
        });

        chatMessages.scrollTop = 0;
    }

    function exitHistoryView() {
        viewingHistory = false;
        btnBack.classList.add('hidden');
        btnNewChat.classList.remove('hidden');
        chatHeaderLabel.textContent = 'Nowa rozmowa';
        chatMessages.innerHTML = savedChatHtml;
        savedChatHtml = '';
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function createMessageEl(content, role, readonly) {
        var el = document.createElement('div');
        el.className = 'message message--' + role + (readonly ? ' message--readonly' : '');

        var bubble = document.createElement('div');
        bubble.className = 'message__bubble';
        bubble.textContent = content;
        el.appendChild(bubble);

        return el;
    }

    function appendDetailsToggle(messageEl, diagData) {
        var btn = document.createElement('button');
        btn.className = 'message__details-toggle';
        btn.textContent = 'Pokaz szczegoly';

        var detailsEl = null;
        var visible = false;

        btn.addEventListener('click', function () {
            visible = !visible;
            if (visible) {
                if (!detailsEl) {
                    detailsEl = buildDetailsPanel(diagData);
                    messageEl.appendChild(detailsEl);
                }
                detailsEl.style.display = '';
                btn.textContent = 'Ukryj szczegoly';
            } else {
                if (detailsEl) detailsEl.style.display = 'none';
                btn.textContent = 'Pokaz szczegoly';
            }
        });

        messageEl.appendChild(btn);
    }

    function buildDetailsPanel(diag) {
        var el = document.createElement('div');
        el.className = 'message__details';

        // Model
        if (diag.model) {
            el.appendChild(detailRow('Model', diag.model));
        }

        // Tokeny
        if (diag.tokens_input || diag.tokens_output) {
            el.appendChild(detailRow('Tokeny', (diag.tokens_input || 0) + ' in / ' + (diag.tokens_output || 0) + ' out'));
        }

        // Czasy
        var times = diag.response_times || {};
        if (times.total_ms) {
            var parts = [];
            if (times.embedding_ms) parts.push('Embedding: ' + Math.round(times.embedding_ms) + 'ms');
            if (times.ai_ms) parts.push('AI: ' + Math.round(times.ai_ms) + 'ms');
            if (times.tool_ms) parts.push('Tools: ' + Math.round(times.tool_ms) + 'ms');
            parts.push('Total: ' + Math.round(times.total_ms) + 'ms');
            el.appendChild(detailRow('Czasy', parts.join(' | ')));
        }

        // Search diagnostics
        var searchDiags = diag.search_diagnostics || [];
        searchDiags.forEach(function (sd) {
            var simHtml = '';
            if (sd.max_similarity != null) {
                var cls = sd.max_similarity > 0.6 ? 'similarity-high' :
                          sd.max_similarity > 0.4 ? 'similarity-mid' : 'similarity-low';
                simHtml = ' | sim: <span class="' + cls + '">' +
                    sd.min_similarity.toFixed(3) + ' - ' + sd.max_similarity.toFixed(3) +
                    '</span>';
            }
            var gapHtml = sd.knowledge_gap ? ' | <span class="similarity-low">GAP</span>' : '';
            var row = detailRow(
                sd.tool,
                sd.result_count + ' wynikow' + simHtml + gapHtml
            );
            row.querySelector('.message__details-value').innerHTML =
                sd.result_count + ' wynikow' + simHtml + gapHtml;
            el.appendChild(row);
        });

        // Tool calls JSON
        if (diag.tool_calls) {
            var block = document.createElement('div');
            block.className = 'tool-call-block';
            block.textContent = JSON.stringify(diag.tool_calls, null, 2);
            el.appendChild(block);
        }

        return el;
    }

    function detailRow(label, value) {
        var row = document.createElement('div');
        row.className = 'message__details-row';
        row.innerHTML =
            '<span class="message__details-label">' + escHtml(label) + '</span>' +
            '<span class="message__details-value">' + escHtml(value) + '</span>';
        return row;
    }

    function formatDate(isoStr) {
        try {
            var d = new Date(isoStr);
            return d.toLocaleDateString('pl-PL') + ' ' +
                d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return isoStr;
        }
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }
})();
