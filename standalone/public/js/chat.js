/**
 * DiveChat — Panel czatu
 * Wysylanie wiadomosci, renderowanie odpowiedzi, karty produktow, sesje.
 */
(function () {
    'use strict';

    var chatMessages = document.getElementById('chatMessages');
    var chatInput = document.getElementById('chatInput');
    var btnSend = document.getElementById('btnSend');
    var btnNewChat = document.getElementById('btnNewChat');
    var chatHeaderLabel = document.getElementById('chatHeaderLabel');
    var statusDot = document.querySelector('.topbar__dot');
    var statusText = document.getElementById('statusText');

    var sessionId = generateSessionId();
    var sending = false;
    var authCache = null;

    // --- Init ---
    checkHealth();
    chatInput.focus();
    updateSendButton();

    // --- Events ---
    chatInput.addEventListener('input', function () {
        autoResize();
        updateSendButton();
    });

    chatInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    btnSend.addEventListener('click', function () {
        sendMessage();
    });

    btnNewChat.addEventListener('click', function () {
        // Wyjdz z historii jesli aktywna
        if (window.DiveChat.History && window.DiveChat.History.isViewing()) {
            window.DiveChat.History.exitView();
        }
        sessionId = generateSessionId();
        chatMessages.innerHTML =
            '<div class="chat-welcome">' +
                '<div class="chat-welcome__icon">&#x1F3A3;</div>' +
                '<h2>Witaj w DiveChat</h2>' +
                '<p>Zapytaj o produkty nurkowe, dostawe, zamowienia i wiele wiecej.</p>' +
            '</div>';
        chatHeaderLabel.textContent = 'Nowa rozmowa';
        chatInput.value = '';
        chatInput.focus();
        updateSendButton();
        // Wyczyść widget kosztu
        if (window.DiveChat.Settings) {
            window.DiveChat.Settings.clearCostWidget();
        }
    });

    // --- Funkcje ---

    function sendMessage() {
        var text = chatInput.value.trim();
        if (!text || sending) return;

        // Wyjdz z historii jesli aktywna
        if (window.DiveChat.History && window.DiveChat.History.isViewing()) {
            window.DiveChat.History.exitView();
        }

        // Usun ekran powitalny
        var welcome = chatMessages.querySelector('.chat-welcome');
        if (welcome) welcome.remove();

        // Renderuj wiadomosc uzytkownika
        appendMessage(text, 'user');
        chatInput.value = '';
        autoResize();
        updateSendButton();

        // Loguj w konsoli
        if (window.DiveChat.Console) {
            window.DiveChat.Console.logRequest(text, sessionId);
        }

        // Pokaz typing indicator
        var typingEl = showTyping();

        // Zablokuj input
        setSending(true);

        // Pobierz token i wyslij przez SSE stream
        getAuthToken()
            .then(function (auth) {
                return sendWithStream(auth, text, typingEl);
            })
            .catch(function (err) {
                removeTyping(typingEl);
                appendMessage('Blad polaczenia. Sprobuj ponownie.', 'ai');
                if (window.DiveChat.Console) {
                    window.DiveChat.Console.log('Fetch error: ' + err.message, 'error');
                }
            })
            .finally(function () {
                setSending(false);
                chatInput.focus();
            });
    }

    /**
     * Wysyla wiadomosc przez SSE stream (POST /api/chat/stream).
     * Parsuje eventy status/done/error z ReadableStream.
     * Przy bledzie streamu fallback na klasyczny fetch.
     */
    function sendWithStream(auth, text, typingEl) {
        var headers = {
            'Content-Type': 'application/json',
            'X-DiveChat-Token': auth.token,
            'X-DiveChat-Customer': String(auth.customer_id),
            'X-DiveChat-Time': String(auth.timestamp),
        };
        var body = JSON.stringify({ message: text, session_id: sessionId });

        return fetch('/api/chat/stream', {
            method: 'POST',
            headers: headers,
            body: body,
        }).then(function (response) {
            // Jesli nie SSE — fallback na klasyczny endpoint
            var ct = response.headers.get('content-type') || '';
            if (!ct.includes('text/event-stream')) {
                return response.json().then(function (data) {
                    handleChatResponse(data, typingEl);
                });
            }

            return readSSEStream(response.body, typingEl);
        }).catch(function () {
            // Fallback: klasyczny fetch
            return sendClassic(auth, text, typingEl);
        });
    }

    /**
     * Czyta SSE stream z ReadableStream i obsluguje eventy.
     */
    function readSSEStream(body, typingEl) {
        var reader = body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';
        var statusEl = typingEl ? typingEl.querySelector('.typing-indicator__status') : null;

        function processChunk(result) {
            if (result.done) return;

            buffer += decoder.decode(result.value, { stream: true });

            // Parsuj kompletne eventy SSE (rozdzielone \n\n)
            var parts = buffer.split('\n\n');
            buffer = parts.pop(); // ostatni fragment moze byc niekompletny

            parts.forEach(function (block) {
                if (!block.trim()) return;

                var eventType = 'message';
                var dataLine = '';

                block.split('\n').forEach(function (line) {
                    if (line.indexOf('event: ') === 0) {
                        eventType = line.substring(7).trim();
                    } else if (line.indexOf('data: ') === 0) {
                        dataLine = line.substring(6);
                    }
                });

                if (!dataLine) return;

                try {
                    var data = JSON.parse(dataLine);
                } catch (e) {
                    return;
                }

                if (eventType === 'status') {
                    // Zatrzymaj timer fake statusow przy pierwszym SSE
                    if (typingEl && typingEl._statusInterval) {
                        clearInterval(typingEl._statusInterval);
                        typingEl._statusInterval = null;
                    }
                    // Aktualizuj tekst statusu w typing indicator
                    if (statusEl) {
                        statusEl.textContent = data.text || '';
                    }
                } else if (eventType === 'done') {
                    handleChatResponse(data, typingEl);
                } else if (eventType === 'error') {
                    removeTyping(typingEl);
                    appendMessage('Blad: ' + (data.error || 'Nieznany blad'), 'ai');
                    if (window.DiveChat.Console) {
                        window.DiveChat.Console.log('SSE error: ' + (data.error || ''), 'error');
                    }
                }
            });

            return reader.read().then(processChunk);
        }

        return reader.read().then(processChunk);
    }

    /**
     * Fallback: klasyczny fetch na /api/chat (bez streamu).
     */
    function sendClassic(auth, text, typingEl) {
        return fetch('/api/chat', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-DiveChat-Token': auth.token,
                'X-DiveChat-Customer': String(auth.customer_id),
                'X-DiveChat-Time': String(auth.timestamp),
            },
            body: JSON.stringify({ message: text, session_id: sessionId }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            handleChatResponse(data, typingEl);
        });
    }

    /**
     * Obsluguje odpowiedz czatu (wspolna dla SSE i klasycznego fetch).
     */
    function handleChatResponse(data, typingEl) {
        removeTyping(typingEl);

        if (data.error) {
            appendMessage('Blad: ' + data.error, 'ai');
            if (window.DiveChat.Console) {
                window.DiveChat.Console.log('Blad API: ' + data.error, 'error');
            }
            return;
        }

        // Aktualizuj session_id z odpowiedzi
        if (data.session_id) {
            sessionId = data.session_id;
            chatHeaderLabel.textContent = 'Rozmowa #' + sessionId.substring(0, 8);
        }

        // Renderuj odpowiedz AI (z produktami do linkowania)
        appendMessage(data.response, 'ai', data.products);

        // Karty produktow
        if (data.products && data.products.length > 0) {
            appendProductCards(data.products);
        }

        // Aktualizuj widget kosztu rozmowy (TASK-052c)
        if (data.conversation_cost && window.DiveChat.Settings) {
            window.DiveChat.Settings.updateCostWidget(data.conversation_cost);
        }

        // Loguj diagnostyke
        if (window.DiveChat.Console) {
            window.DiveChat.Console.logResponse(data);
        }
    }

    function appendMessage(content, role, products) {
        var el = document.createElement('div');
        el.className = 'message message--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'message__bubble';

        if (role === 'ai') {
            bubble.innerHTML = formatAiResponse(content, products);
        } else {
            bubble.textContent = content;
        }
        el.appendChild(bubble);

        var meta = document.createElement('div');
        meta.className = 'message__meta';
        var now = new Date();
        meta.textContent = now.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
        el.appendChild(meta);

        chatMessages.appendChild(el);
        scrollToBottom();
    }

    /**
     * Formatuje odpowiedź AI: bold, separatory, linkuje produkty.
     */
    function formatAiResponse(text, products) {
        var html = escHtml(text);

        // Markdown bold **text** i __text__
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');

        // Separatory ---
        html = html.replace(/^-{3,}$/gm, '<hr class="ai-separator">');

        // Linkuj nazwy produktów jeśli mamy dane
        if (products && products.length > 0) {
            products.forEach(function (p) {
                if (!p.name || !p.url) return;
                var url = p.url || p.product_url;
                if (!url) return;

                // Próbuj dopasować pełną nazwę lub skróconą (3+ słów od początku)
                var nameEsc = escHtml(p.name);
                var matched = tryLinkProduct(html, nameEsc, url);
                if (matched) {
                    html = matched;
                    return;
                }

                // Fallback: szukaj po kluczowych słowach (marka + model, min 3 słowa)
                var words = p.name.split(/\s+/);
                for (var len = Math.min(words.length, 5); len >= 3; len--) {
                    var partial = escHtml(words.slice(0, len).join(' '));
                    matched = tryLinkProduct(html, partial, url);
                    if (matched) {
                        html = matched;
                        return;
                    }
                }
            });
        }

        return html;
    }

    function escRegex(s) {
        return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Próbuje znaleźć fragment w HTML i zamienić na link.
     * Zwraca zmieniony HTML lub null jeśli nie znaleziono.
     * Pomija fragmenty już wewnątrz tagów <a>.
     */
    function tryLinkProduct(html, needle, url) {
        var regex = new RegExp('(?![^<]*<\\/a>)' + escRegex(needle), 'i');
        if (!regex.test(html)) return null;
        return html.replace(regex,
            '<a href="' + escAttr(url) + '" target="_blank" rel="noopener" class="product-link">$&</a>'
        );
    }

    function appendProductCards(products) {
        var container = document.createElement('div');
        container.className = 'product-cards';

        products.forEach(function (p) {
            var card = document.createElement('a');
            card.className = 'product-card';
            card.href = p.url || p.product_url || '#';
            card.target = '_blank';
            card.rel = 'noopener';

            var imgSrc = p.image_url || '';
            var stockClass = p.in_stock ? 'product-card__stock--available' : 'product-card__stock--order';
            var stockText = p.in_stock ? 'Od reki' : 'Na zamowienie';
            var price = typeof p.price === 'number' ? p.price.toFixed(2) + ' zl' : (p.price || '');

            card.innerHTML =
                (imgSrc ? '<img class="product-card__img" src="' + escAttr(imgSrc) + '" alt="" loading="lazy">' : '') +
                '<div class="product-card__info">' +
                    '<div class="product-card__name">' + escHtml(p.name || '') + '</div>' +
                    '<div class="product-card__brand">' + escHtml(p.brand || '') + '</div>' +
                    '<div class="product-card__price">' + escHtml(price) + '</div>' +
                    '<div class="product-card__stock ' + stockClass + '">' + stockText + '</div>' +
                '</div>';

            container.appendChild(card);
        });

        chatMessages.appendChild(container);
        scrollToBottom();
    }

    function showTyping() {
        var el = document.createElement('div');
        el.className = 'typing-indicator';
        el.innerHTML =
            '<span class="typing-indicator__dot"></span>' +
            '<span class="typing-indicator__dot"></span>' +
            '<span class="typing-indicator__dot"></span>' +
            '<span class="typing-indicator__status"></span>';

        var statusEl = el.querySelector('.typing-indicator__status');
        var messages = [
            'Analizuję pytanie...',
            'Przeszukuję ofertę...',
            'Dobieram produkty...',
            'Przygotowuję odpowiedź...',
        ];
        var idx = 0;
        statusEl.textContent = messages[0];

        el._statusInterval = setInterval(function () {
            idx++;
            if (idx < messages.length) {
                statusEl.textContent = messages[idx];
            }
        }, 4000);

        chatMessages.appendChild(el);
        scrollToBottom();
        return el;
    }

    function removeTyping(el) {
        if (el) {
            clearInterval(el._statusInterval);
            if (el.parentNode) el.parentNode.removeChild(el);
        }
    }

    function setSending(val) {
        sending = val;
        chatInput.disabled = val;
        btnSend.disabled = val;
    }

    function updateSendButton() {
        btnSend.disabled = sending || !chatInput.value.trim();
    }

    function autoResize() {
        chatInput.style.height = 'auto';
        var maxH = 120; // 4 linie
        chatInput.style.height = Math.min(chatInput.scrollHeight, maxH) + 'px';
    }

    function scrollToBottom() {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    function generateSessionId() {
        var arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        return Array.from(arr, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }

    /**
     * Pobiera token HMAC z /api/test-token.
     * Cachuje token na 4 minuty (wygasa po 5).
     */
    function getAuthToken() {
        if (authCache && (Date.now() / 1000) < authCache.timestamp + 240) {
            return Promise.resolve(authCache);
        }

        return fetch('/api/test-token')
            .then(function (r) {
                if (!r.ok) throw new Error('Token endpoint: ' + r.status);
                return r.json();
            })
            .then(function (data) {
                authCache = data;
                return data;
            });
    }

    /**
     * Sprawdza dostepnosc API.
     */
    function checkHealth() {
        fetch('/api/health')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'ok') {
                    statusDot.className = 'topbar__dot topbar__dot--ok';
                    statusText.textContent = 'API OK';
                } else {
                    statusDot.className = 'topbar__dot topbar__dot--error';
                    statusText.textContent = 'API degraded';
                }
            })
            .catch(function () {
                statusDot.className = 'topbar__dot topbar__dot--error';
                statusText.textContent = 'API offline';
            });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Udostępnij formatowanie globalnie (dla history.js)
    window.DiveChat = window.DiveChat || {};
    window.DiveChat.formatAiResponse = formatAiResponse;
})();
