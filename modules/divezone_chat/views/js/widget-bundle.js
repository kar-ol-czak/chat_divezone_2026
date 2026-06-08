/*!
 * DiveZone Chat — widget bundle (okno czatu, render, stan).
 * CHAT-T-037 etap 1. Vanilla JS, montowany w shadow root z loadera.
 *
 * Eksponuje:
 *   window.DivezoneChatMount({ root, host, launcher, boot })
 *   window.DivezoneChatOpen()
 *   window.DivezoneChatClose()
 *
 * Streaming: poprzez window.DivezoneChatTransport.sendMessage (transport.js).
 * Streaming ETAP 1 jest UCZCIWY (decyzja 59a): status -> "Asystent pisze...",
 * done -> pelna odpowiedz pojawia sie raz. BEZ fake-typing token-by-token.
 */
(function () {
  'use strict';

  if (window.DivezoneChatMount) return; // double-load safety

  /* ───────────────────────── Constants ───────────────────────── */

  // CHAT-T-078: nowa tresc otwarcia + usunieto chip "Kompatybilność sprzętu".
  var WELCOME_HTML =
    '<p><strong>Cześć! Jestem doradcą nurkowym DIVEZONE.PL.</strong></p>' +
    '<p>Pomogę dobrać sprzęt do nurkowania: np. maskę, płetwy, automat, ' +
    'komputer i inne.</p>' +
    '<p>Mogę też sprawdzić rozmiar pianki lub suchego skafandra albo ' +
    'odpowiem na pytania o zamówienie.</p>' +
    '<p><span class="dz-callout">Od czego zaczynamy?</span></p>';

  var CHIPS_DESKTOP = [
    'Pomóż dobrać sprzęt',
    'Dobierz rozmiar',
    'Dostępność i wysyłka',
    'Status zamówienia',
    'Serwis sprzętu'
  ];
  var CHIPS_MOBILE_LIMIT = 4;
  var ORDER_CHIP_LABEL = 'Status zamówienia';

  var PRIVACY_NOTE_HTML =
    'Rozmawiasz z asystentem AI — nie podawaj danych wrażliwych. ' +
    '<a href="https://divezone.pl/polityka-prywatnosci" target="_blank" rel="noopener noreferrer">Polityka prywatności.</a>';

  var ORDER_MODAL_TITLE = 'Sprawdź status zamówienia';
  var ORDER_MODAL_INTRO = 'Podaj numer zamówienia i adres e-mail użyty przy zakupie — sprawdzimy status od razu.';
  var ORDER_MODAL_NOTE  = 'Dane służą wyłącznie do weryfikacji statusu Twojego zamówienia.';
  // Walidacja email — celowo prosta (typo-friendly, nie RFC-pełna):
  // znaki@znaki.kropka (PHP po stronie i tak waliduje autorytatywnie).
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  // CHAT-T-059: klucze localStorage / sessionStorage. Treningowo zero-treskove —
  // localStorage trzyma TYLKO {sessionId, ts}, NIE zawartosc rozmowy (ta zywie
  // na backendzie i pobiera sie przy mount przez fetchHistory).
  var PERSIST_KEY = 'dz_chat_session';   // {sessionId, ts} z TTL (BOOT.persist.ttl_days)
  var OPEN_KEY    = 'dz_chat_open';      // sessionStorage "1" jesli czat byl otwarty (per tab)
  var DEFAULT_PERSIST_TTL_DAYS = 30;

  /* ───────────────────────── Mount state ───────────────────────── */

  var state = {
    mounted: false,
    open: false,
    root: null,         // ShadowRoot
    host: null,         // host div
    launcher: null,     // <button> z loadera
    boot: null,
    win: null,          // .dz-window element
    messagesEl: null,
    chipsEl: null,
    inputEl: null,
    sendBtnEl: null,
    typingEl: null,
    srLiveEl: null,
    sessionId: null,
    isStreaming: false,
    abortCtl: null,
    lastFocus: null,
    // CHAT-T-043 modal statusu zamowienia
    orderModalEl: null,
    orderModalOpen: false,
    orderRefEl: null,
    orderEmailEl: null,
    orderSubmitEl: null,
    orderFormEl: null,
    orderResultEl: null,
    orderErrorEl: null,
    orderAbortCtl: null,
    orderLastFocus: null
  };

  /* ───────────────────────── Persystencja sesji (CHAT-T-059) ───────────────────────── */

  /**
   * Bezpieczne API localStorage — w trybach prywatnych / wylaczonych localStorage
   * (Safari ITP, Firefox prywatne) zwraca null/no-op zamiast rzucac wyjatkiem.
   */
  function lsGet(key) {
    try { return window.localStorage ? window.localStorage.getItem(key) : null; }
    catch (_) { return null; }
  }
  function lsSet(key, value) {
    try { if (window.localStorage) window.localStorage.setItem(key, value); }
    catch (_) {}
  }
  function lsRemove(key) {
    try { if (window.localStorage) window.localStorage.removeItem(key); }
    catch (_) {}
  }
  function ssGet(key) {
    try { return window.sessionStorage ? window.sessionStorage.getItem(key) : null; }
    catch (_) { return null; }
  }
  function ssSet(key, value) {
    try { if (window.sessionStorage) window.sessionStorage.setItem(key, value); }
    catch (_) {}
  }
  function ssRemove(key) {
    try { if (window.sessionStorage) window.sessionStorage.removeItem(key); }
    catch (_) {}
  }

  function persistTtlMs() {
    var days = (state.boot && state.boot.persist && state.boot.persist.ttl_days) || DEFAULT_PERSIST_TTL_DAYS;
    if (typeof days !== 'number' || days < 1 || days > 365) days = DEFAULT_PERSIST_TTL_DAYS;
    return days * 86400000;
  }

  function persistSession(sessionId) {
    if (!sessionId) return;
    var payload = JSON.stringify({ sessionId: sessionId, ts: Date.now() });
    lsSet(PERSIST_KEY, payload);
  }

  /**
   * Odczyt zapamietanego sessionId. Zwraca string lub null gdy brak / wygasl /
   * skorumpowany JSON. Wygasly wpis czysci od razu (samosprzatanie).
   */
  function loadPersistedSessionId() {
    var raw = lsGet(PERSIST_KEY);
    if (!raw) return null;
    var data;
    try { data = JSON.parse(raw); } catch (_) { lsRemove(PERSIST_KEY); return null; }
    if (!data || typeof data.sessionId !== 'string' || !data.sessionId || typeof data.ts !== 'number') {
      lsRemove(PERSIST_KEY);
      return null;
    }
    if (Date.now() - data.ts > persistTtlMs()) {
      lsRemove(PERSIST_KEY);
      return null;
    }
    return data.sessionId;
  }

  /**
   * Odtworzenie historii rozmowy w widoku z payloadu z backendu.
   * Format messages z backendu zgodny ze startOrResume — role: user/assistant/tool.
   * tool_result/system pomijamy (analogicznie do admin history.js).
   * Chipy ukrywamy bo rozmowa juz trwa.
   */
  function renderHistoryMessages(messages) {
    if (!Array.isArray(messages) || messages.length === 0) return false;
    var rendered = 0;
    for (var i = 0; i < messages.length; i++) {
      var m = messages[i];
      if (!m || typeof m.role !== 'string') continue;
      var content = (typeof m.content === 'string') ? m.content : '';
      if (m.role === 'user' && content) {
        appendUserMessage(content);
        rendered++;
      } else if (m.role === 'assistant' && content) {
        appendBotMessage(content);
        rendered++;
      }
      // tool / tool_result / system / inne — pomijamy (jak w admin history.js).
    }
    if (rendered > 0 && state.chipsEl) {
      state.chipsEl.style.display = 'none';
    }
    return rendered > 0;
  }

  function clearMessagesView() {
    if (!state.messagesEl) return;
    // Usuwamy wszystkie bable user/bot, zostawiamy welcome + chipy + sr-only.
    var rows = state.messagesEl.querySelectorAll('.dz-user-row, .dz-bot-row');
    // welcome-row jest pierwszym .dz-bot-row (welcome bubble). Zachowujemy go.
    var first = true;
    for (var i = 0; i < rows.length; i++) {
      if (first && rows[i].classList.contains('dz-bot-row')) { first = false; continue; }
      if (rows[i].parentNode) rows[i].parentNode.removeChild(rows[i]);
    }
    if (state.chipsEl) state.chipsEl.style.display = '';
  }

  /**
   * Akcja "Nowa rozmowa" (CHAT-T-059 C3): czysci sessionId + localStorage +
   * widok wiadomosci, pokazuje welcome + chipy. Rozmowa w backendzie zostaje
   * (closed_at = null), tylko front startuje swiezo.
   */
  function startNewConversation() {
    if (state.isStreaming && state.abortCtl) {
      try { state.abortCtl.abort(); } catch (_) {}
      state.abortCtl = null;
      state.isStreaming = false;
      hideTyping();
      if (state.sendBtnEl) state.sendBtnEl.disabled = state.inputEl && state.inputEl.value.trim() === '';
    }
    state.sessionId = null;
    lsRemove(PERSIST_KEY);
    clearMessagesView();
    if (state.srLiveEl) state.srLiveEl.textContent = '';
    if (state.inputEl) { state.inputEl.value = ''; state.inputEl.focus(); }
    scrollToBottom();
  }

  /**
   * Po mount (raz): jesli localStorage ma niewygasly sessionId, pobierz historie
   * z backendu i odtworz bable. Padl/cudza/wygasla -> swiezy czat (graceful).
   */
  function tryRestoreSession() {
    var sid = loadPersistedSessionId();
    if (!sid) return;
    var transport = window.DivezoneChatTransport;
    if (!transport || typeof transport.fetchHistory !== 'function') return;

    transport.fetchHistory(sid, {
      onResult: function (data) {
        if (data && data.exists && data.messages && data.messages.length) {
          state.sessionId = data.sessionId || sid;
          renderHistoryMessages(data.messages);
        } else {
          // {exists:false} = backend nie zna sesji (cudza / zamknieta / nieistniejaca).
          // Czyscimy localStorage — od teraz swieza rozmowa.
          lsRemove(PERSIST_KEY);
        }
      },
      onError: function () {
        // Padl fetch (siec / 401 / 5xx) — NIE bloku UI, gracefully zostawiamy
        // swiezy czat. localStorage zachowany; nastepny mount sprobuje znow.
      }
    });
  }

  /* ───────────────────────── SVG icons (z design handoff) ───────────────────────── */

  function maskIconSvg(size, color) {
    var s = String(size);
    var h = String(Math.round(size * 0.85));
    var c = color || '#ffffff';
    return '<svg width="' + s + '" height="' + h + '" viewBox="0 0 20 17" fill="none" aria-hidden="true">' +
      '<rect x="1.5" y="3.5" width="17" height="10" rx="4.5" stroke="' + c + '" stroke-width="1.4" fill="rgba(255,255,255,0.10)"/>' +
      '<path d="M1.5 7 L0 5.5" stroke="' + c + '" stroke-width="1.3" stroke-linecap="round"/>' +
      '<path d="M18.5 7 L20 5.5" stroke="' + c + '" stroke-width="1.3" stroke-linecap="round"/>' +
      '<path d="M7.5 13.5 Q10 16 12.5 13.5" stroke="' + c + '" stroke-width="1.1" stroke-linecap="round" fill="none"/>' +
      '</svg>';
  }
  function arrowUpSvg() {
    return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
      '<path d="M8 13V3M8 3 L4 7M8 3 L12 7" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
      '</svg>';
  }

  /* ───────────────────────── Markdown (minimalny: bold/list/link/paragrafy) ───────────────────────── */

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderMarkdown(text) {
    if (!text) return '';
    var safe = escapeHtml(text);

    // links: [label](url) — tylko http(s) i mailto
    safe = safe.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/g,
      function (_, label, href) {
        return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
      });

    // bold **...**
    safe = safe.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');

    // listy: linie zaczynajace sie od "- " lub "• " grupowane w <ul>
    var lines = safe.split(/\n/);
    var out = [];
    var listBuf = [];
    function flushList() {
      if (listBuf.length) {
        out.push('<ul>' + listBuf.map(function (it) { return '<li>' + it + '</li>'; }).join('') + '</ul>');
        listBuf = [];
      }
    }
    var paraBuf = [];
    function flushPara() {
      if (paraBuf.length) {
        out.push('<p>' + paraBuf.join('<br>') + '</p>');
        paraBuf = [];
      }
    }
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var m = line.match(/^\s*(?:-|•)\s+(.*)$/);
      if (m) {
        flushPara();
        listBuf.push(m[1]);
      } else if (line.trim() === '') {
        flushList();
        flushPara();
      } else {
        flushList();
        paraBuf.push(line);
      }
    }
    flushList();
    flushPara();
    return out.join('');
  }

  /* ───────────────────────── DOM ───────────────────────── */

  function createEl(tag, attrs, html) {
    var el = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (k === 'class') el.className = attrs[k];
        else if (k === 'html') el.innerHTML = attrs[k];
        else el.setAttribute(k, attrs[k]);
      }
    }
    if (html != null) el.innerHTML = html;
    return el;
  }

  function buildWindow() {
    var win = createEl('div', {
      class: 'dz-window',
      role: 'dialog',
      'aria-modal': 'true',
      'aria-label': 'Czat DIVEZONE.PL — Asystent AI',
      'data-open': 'false'
    });

    // Header
    var header = createEl('div', { class: 'dz-header' });
    var brand = createEl('div', { class: 'dz-brand' });
    brand.appendChild(createEl('div', { class: 'dz-brand__icon', html: maskIconSvg(16, '#ffffff') }));
    var label = createEl('span', { class: 'dz-brand__label' });
    label.appendChild(createEl('span', { class: 'dz-brand__name' }, 'DIVEZONE.PL'));
    label.appendChild(createEl('span', { class: 'dz-brand__tag' }, 'Chat doradca'));
    brand.appendChild(label);
    header.appendChild(brand);

    // CHAT-T-059: przycisk "Nowa rozmowa" w header — czysci sessionId +
    // localStorage + widok wiadomosci, pokazuje welcome + chipy. Backend
    // rozmowy nie zamyka — to tylko front start swiezej sesji.
    var newConv = createEl('button', {
      class: 'dz-newconv',
      type: 'button',
      title: 'Rozpocznij nową rozmowę',
      'aria-label': 'Rozpocznij nową rozmowę'
    }, 'Nowa');
    newConv.addEventListener('click', startNewConversation);
    header.appendChild(newConv);

    var x = createEl('button', {
      class: 'dz-x',
      type: 'button',
      'aria-label': 'Zamknij okno czatu'
    }, '✕');
    x.addEventListener('click', closeWindow);
    header.appendChild(x);
    win.appendChild(header);

    // Messages container (role=log dla a11y)
    var messages = createEl('div', {
      class: 'dz-messages',
      role: 'log',
      'aria-live': 'off' // a11y stream przez sr-only ponizej; tu wizualnie
    });

    // Wiadomosc powitalna (bot bubble z avatarem)
    var welcomeRow = createEl('div', { class: 'dz-bot-row' });
    welcomeRow.appendChild(createEl('div', { class: 'dz-avatar', html: maskIconSvg(15, '#ffffff') }));
    welcomeRow.appendChild(createEl('div', { class: 'dz-bubble--bot', html: WELCOME_HTML }));
    messages.appendChild(welcomeRow);

    // Chipy
    var chips = createEl('div', { class: 'dz-chips', role: 'group', 'aria-label': 'Szybkie odpowiedzi' });
    var isMobile = window.matchMedia && window.matchMedia('(max-width: 599.98px)').matches;
    var labels = CHIPS_DESKTOP.slice(0, isMobile ? CHIPS_MOBILE_LIMIT : CHIPS_DESKTOP.length);
    for (var i = 0; i < labels.length; i++) {
      (function (label) {
        var btn = createEl('button', { class: 'dz-chip', type: 'button' }, label);
        btn.addEventListener('click', function () {
          if (label === ORDER_CHIP_LABEL) {
            // CHAT-T-043: status zamowienia idzie poza LLM — modal + /api/order/status.
            openOrderModal();
          } else {
            sendUserMessage(label);
          }
        });
        chips.appendChild(btn);
      })(labels[i]);
    }
    messages.appendChild(chips);

    // SR-only region dla streamingu (ADR-063 a11y, etap 1 = uczciwy, czytnik
    // dostaje gotowa wiadomosc po event:done).
    var srLive = createEl('div', { class: 'dz-sr-only', 'aria-live': 'polite', 'aria-atomic': 'true' });
    messages.appendChild(srLive);

    win.appendChild(messages);

    // Nota RODO
    win.appendChild(createEl('div', { class: 'dz-note' }, PRIVACY_NOTE_HTML));

    // Input bar
    var inputbar = createEl('div', { class: 'dz-inputbar' });
    var input = createEl('input', {
      class: 'dz-input',
      type: 'text',
      placeholder: 'Napisz wiadomość…',
      'aria-label': 'Pole wiadomości',
      autocomplete: 'off',
      autocapitalize: 'sentences',
      enterkeyhint: 'send'
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        var v = input.value.trim();
        if (v && !state.isStreaming) sendUserMessage(v);
      }
    });
    input.addEventListener('input', function () {
      state.sendBtnEl.disabled = input.value.trim() === '' || state.isStreaming;
    });
    inputbar.appendChild(input);

    var sendBtn = createEl('button', {
      class: 'dz-send',
      type: 'button',
      'aria-label': 'Wyślij wiadomość',
      disabled: 'disabled'
    }, arrowUpSvg());
    sendBtn.addEventListener('click', function () {
      var v = input.value.trim();
      if (v && !state.isStreaming) sendUserMessage(v);
    });
    inputbar.appendChild(sendBtn);

    win.appendChild(inputbar);

    state.win = win;
    state.messagesEl = messages;
    state.chipsEl = chips;
    state.inputEl = input;
    state.sendBtnEl = sendBtn;
    state.srLiveEl = srLive;

    return win;
  }

  /* ───────────────────────── Visual Viewport (mobile) ───────────────────────── */

  function setupVisualViewport() {
    if (!window.visualViewport) return;
    var vv = window.visualViewport;
    function sync() {
      // --vvh trafia do dokumentu i shadow host (zmienne dziedzicza do shadow)
      var h = vv.height + 'px';
      state.host.style.setProperty('--vvh', h);
    }
    vv.addEventListener('resize', sync);
    vv.addEventListener('scroll', sync);
    sync();
  }

  /* ───────────────────────── Render wiadomosci ───────────────────────── */

  function appendUserMessage(text) {
    var row = createEl('div', { class: 'dz-user-row' });
    row.appendChild(createEl('div', { class: 'dz-bubble--user' }, escapeHtml(text)));
    state.messagesEl.appendChild(row);
    scrollToBottom();
  }

  function appendBotMessage(text) {
    var row = createEl('div', { class: 'dz-bot-row' });
    row.appendChild(createEl('div', { class: 'dz-avatar', html: maskIconSvg(15, '#ffffff') }));
    row.appendChild(createEl('div', { class: 'dz-bubble--bot', html: renderMarkdown(text) }));
    state.messagesEl.appendChild(row);
    scrollToBottom();
  }

  function showTyping(statusText) {
    if (!state.typingEl) {
      var row = createEl('div', { class: 'dz-bot-row' });
      row.appendChild(createEl('div', { class: 'dz-avatar', html: maskIconSvg(15, '#ffffff') }));
      var bubble = createEl('div', { class: 'dz-bubble--bot' });
      var typing = createEl('div', { class: 'dz-typing' });
      var labelEl = createEl('span', { class: 'dz-typing__label' }, 'Asystent pisze');
      var dots = createEl('span', { class: 'dz-typing__dots' });
      dots.appendChild(createEl('span'));
      dots.appendChild(createEl('span'));
      dots.appendChild(createEl('span'));
      typing.appendChild(labelEl);
      typing.appendChild(dots);
      bubble.appendChild(typing);
      row.appendChild(bubble);
      state.typingEl = row;
      state.messagesEl.appendChild(row);
    }
    if (statusText && typeof statusText === 'string') {
      var labelEl2 = state.typingEl.querySelector('.dz-typing__label');
      if (labelEl2) labelEl2.textContent = statusText;
    }
    scrollToBottom();
  }

  function hideTyping() {
    if (state.typingEl && state.typingEl.parentNode) {
      state.typingEl.parentNode.removeChild(state.typingEl);
    }
    state.typingEl = null;
  }

  function scrollToBottom() {
    // Nie uzywaj scrollIntoView (przewija viewport sklepu) — set scrollTop.
    var el = state.messagesEl;
    if (!el) return;
    requestAnimationFrame(function () {
      el.scrollTop = el.scrollHeight;
    });
  }

  /* ───────────────────────── Wysylka + streaming ───────────────────────── */

  function sendUserMessage(text) {
    if (state.isStreaming) return;
    var transport = window.DivezoneChatTransport;
    if (!transport) {
      appendBotMessage('**Blad konfiguracji:** transport czatu niedostepny. Odswiez strone.');
      return;
    }

    // Po pierwszej wiadomosci ukryj chipy (jak w briefie/Hi-Fi welcome flow).
    if (state.chipsEl && state.chipsEl.parentNode) {
      state.chipsEl.style.display = 'none';
    }

    appendUserMessage(text);
    state.inputEl.value = '';
    state.inputEl.dispatchEvent(new Event('input'));

    state.isStreaming = true;
    state.sendBtnEl.disabled = true;
    showTyping('Asystent pisze');

    state.abortCtl = transport.sendMessage(text, state.sessionId, {
      onStatus: function (statusText) {
        showTyping(statusText || 'Asystent pisze');
      },
      onDone: function (payload) {
        state.isStreaming = false;
        state.abortCtl = null;
        hideTyping();
        if (payload && payload.session_id) {
          state.sessionId = payload.session_id;
          // CHAT-T-059: zapamietaj sessionId + timestamp w localStorage.
          // Po nawigacji miedzy stronami sklepu front pobierze historie z backendu.
          persistSession(state.sessionId);
        }
        var responseText = (payload && payload.response) || '(brak odpowiedzi)';
        appendBotMessage(responseText);
        // a11y: ogloszenie gotowej wiadomosci czytnikom (ADR-063).
        state.srLiveEl.textContent = responseText;
        state.sendBtnEl.disabled = state.inputEl.value.trim() === '';
        state.inputEl.focus();
      },
      onError: function (msg) {
        state.isStreaming = false;
        state.abortCtl = null;
        hideTyping();
        appendBotMessage('**Nie udało się pobrać odpowiedzi.** ' + (msg || ''));
        state.sendBtnEl.disabled = state.inputEl.value.trim() === '';
      }
    });
  }

  /* ───────────────────────── Modal: status zamowienia (CHAT-T-043) ───────────────────────── */

  /**
   * Formatowanie ISO date / datetime -> PL czytelnie.
   * Tolerancyjne na rozne formaty z backendu: ISO ('2026-05-15'),
   * datetime ISO ('2026-05-15T10:23:00Z'), albo gotowy string PL.
   */
  function formatOrderDate(s) {
    if (!s) return '';
    try {
      var d = new Date(s);
      if (isNaN(d.getTime())) return String(s);
      return new Intl.DateTimeFormat('pl-PL', {
        day: 'numeric', month: 'long', year: 'numeric'
      }).format(d);
    } catch (_) {
      return String(s);
    }
  }

  function formatOrderTotal(t) {
    if (t == null || t === '') return '';
    var n = typeof t === 'number' ? t : parseFloat(String(t).replace(',', '.'));
    if (isNaN(n)) return String(t);
    try {
      return new Intl.NumberFormat('pl-PL', {
        style: 'currency', currency: 'PLN', minimumFractionDigits: 2
      }).format(n);
    } catch (_) {
      return n.toFixed(2) + ' zł';
    }
  }

  function buildOrderModal() {
    if (state.orderModalEl) return state.orderModalEl;

    var overlay = createEl('div', {
      class: 'dz-order-overlay',
      'data-open': 'false'
    });

    var dialog = createEl('div', {
      class: 'dz-order-dialog',
      role: 'dialog',
      'aria-modal': 'true',
      'aria-labelledby': 'dz-order-title',
      'aria-describedby': 'dz-order-intro'
    });

    // Header
    var header = createEl('div', { class: 'dz-order-header' });
    header.appendChild(createEl('h2', { class: 'dz-order-title', id: 'dz-order-title' }, ORDER_MODAL_TITLE));
    var closeBtn = createEl('button', {
      class: 'dz-order-close',
      type: 'button',
      'aria-label': 'Zamknij okno statusu zamówienia'
    }, '✕');
    closeBtn.addEventListener('click', closeOrderModal);
    header.appendChild(closeBtn);
    dialog.appendChild(header);

    // Intro
    dialog.appendChild(createEl('p', {
      class: 'dz-order-intro',
      id: 'dz-order-intro'
    }, ORDER_MODAL_INTRO));

    // Form
    var form = createEl('form', { class: 'dz-order-form', novalidate: 'novalidate' });

    var refField = createEl('div', { class: 'dz-field' });
    var refLabel = createEl('label', { class: 'dz-field__label', for: 'dz-order-ref' }, 'Numer / referencja zamówienia');
    var refInput = createEl('input', {
      class: 'dz-field__input',
      type: 'text',
      id: 'dz-order-ref',
      name: 'order_reference',
      placeholder: 'np. GBUQNGUCR',
      autocomplete: 'off',
      autocapitalize: 'characters',
      inputmode: 'text',
      maxlength: '40',
      required: 'required',
      'aria-required': 'true'
    });
    refField.appendChild(refLabel);
    refField.appendChild(refInput);
    form.appendChild(refField);

    var emailField = createEl('div', { class: 'dz-field' });
    var emailLabel = createEl('label', { class: 'dz-field__label', for: 'dz-order-email' }, 'Adres e-mail');
    var emailInput = createEl('input', {
      class: 'dz-field__input',
      type: 'email',
      id: 'dz-order-email',
      name: 'email',
      placeholder: 'twoj@email.pl',
      autocomplete: 'email',
      inputmode: 'email',
      maxlength: '120',
      required: 'required',
      'aria-required': 'true'
    });
    emailField.appendChild(emailLabel);
    emailField.appendChild(emailInput);
    form.appendChild(emailField);

    // Nota RODO przy formularzu (ADR-063 nota kontekstowa)
    form.appendChild(createEl('p', { class: 'dz-order-note' }, ORDER_MODAL_NOTE));

    // Komunikat błędu (aria-live)
    var errorEl = createEl('div', {
      class: 'dz-order-error',
      role: 'alert',
      'aria-live': 'assertive',
      hidden: 'hidden'
    });
    form.appendChild(errorEl);

    // Przycisk submit
    var submitBtn = createEl('button', {
      class: 'dz-order-submit',
      type: 'submit'
    }, 'Sprawdź status');
    form.appendChild(submitBtn);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitOrderForm();
    });

    dialog.appendChild(form);

    // Wynik (panel pojawia się po sukcesie)
    var resultEl = createEl('div', {
      class: 'dz-order-result',
      'aria-live': 'polite',
      hidden: 'hidden'
    });
    dialog.appendChild(resultEl);

    overlay.appendChild(dialog);

    // Klik w backdrop poza dialogiem zamyka (UX standard).
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeOrderModal();
    });

    state.orderModalEl  = overlay;
    state.orderFormEl   = form;
    state.orderRefEl    = refInput;
    state.orderEmailEl  = emailInput;
    state.orderSubmitEl = submitBtn;
    state.orderResultEl = resultEl;
    state.orderErrorEl  = errorEl;

    return overlay;
  }

  function showOrderError(message) {
    if (!state.orderErrorEl) return;
    state.orderErrorEl.textContent = message;
    state.orderErrorEl.removeAttribute('hidden');
  }

  function clearOrderError() {
    if (!state.orderErrorEl) return;
    state.orderErrorEl.textContent = '';
    state.orderErrorEl.setAttribute('hidden', 'hidden');
  }

  function showOrderResult(order) {
    if (!state.orderResultEl) return;
    var statusLabel = (order && order.status) ? String(order.status) : 'Status nieznany';
    var dateText    = formatOrderDate(order && order.date);
    var totalText   = formatOrderTotal(order && order.total);
    var reference   = (order && order.reference) ? String(order.reference) : '';
    var history     = (order && order.history) || [];
    var tracking    = order && order.tracking;

    // Czyscimy result
    while (state.orderResultEl.firstChild) {
      state.orderResultEl.removeChild(state.orderResultEl.firstChild);
    }

    var statusRow = createEl('div', { class: 'dz-order-status-row' });
    statusRow.appendChild(createEl('span', { class: 'dz-order-status-label' }, 'Status'));
    statusRow.appendChild(createEl('span', { class: 'dz-order-status-badge' }, statusLabel));
    state.orderResultEl.appendChild(statusRow);

    if (reference || dateText || totalText) {
      var meta = createEl('dl', { class: 'dz-order-meta' });
      if (reference) {
        meta.appendChild(createEl('dt', null, 'Numer'));
        meta.appendChild(createEl('dd', null, reference));
      }
      if (dateText) {
        meta.appendChild(createEl('dt', null, 'Data'));
        meta.appendChild(createEl('dd', null, dateText));
      }
      if (totalText) {
        meta.appendChild(createEl('dt', null, 'Kwota'));
        meta.appendChild(createEl('dd', null, totalText));
      }
      state.orderResultEl.appendChild(meta);
    }

    if (history && history.length) {
      state.orderResultEl.appendChild(createEl('h3', { class: 'dz-order-section' }, 'Historia statusów'));
      var ul = createEl('ul', { class: 'dz-order-history' });
      for (var i = 0; i < history.length; i++) {
        var h = history[i] || {};
        var li = createEl('li', { class: 'dz-order-history__item' });
        li.appendChild(createEl('span', { class: 'dz-order-history__status' }, String(h.status || '')));
        if (h.date) {
          li.appendChild(createEl('span', { class: 'dz-order-history__date' }, formatOrderDate(h.date)));
        }
        ul.appendChild(li);
      }
      state.orderResultEl.appendChild(ul);
    }

    if (tracking && tracking.url) {
      var trackP = createEl('p', { class: 'dz-order-tracking' });
      var carrier = tracking.carrier ? (String(tracking.carrier) + ' · ') : '';
      var num = tracking.number ? String(tracking.number) : 'Śledź przesyłkę';
      var link = createEl('a', {
        class: 'dz-order-tracking__link',
        href: String(tracking.url),
        target: '_blank',
        rel: 'noopener noreferrer'
      }, 'Śledź przesyłkę' + (tracking.number ? ' (' + num + ')' : ''));
      trackP.appendChild(document.createTextNode(carrier));
      trackP.appendChild(link);
      state.orderResultEl.appendChild(trackP);
    }

    state.orderResultEl.removeAttribute('hidden');
    // Po sukcesie chowamy formularz (czysciejsza prezentacja wyniku).
    if (state.orderFormEl) state.orderFormEl.setAttribute('hidden', 'hidden');
  }

  function clearOrderResult() {
    if (!state.orderResultEl) return;
    state.orderResultEl.setAttribute('hidden', 'hidden');
    while (state.orderResultEl.firstChild) {
      state.orderResultEl.removeChild(state.orderResultEl.firstChild);
    }
    if (state.orderFormEl) state.orderFormEl.removeAttribute('hidden');
  }

  function submitOrderForm() {
    var transport = window.DivezoneChatTransport;
    if (!transport || typeof transport.checkOrderStatus !== 'function') {
      showOrderError('Transport niedostępny. Odśwież stronę.');
      return;
    }
    // RODO: zmienne LOKALNE, nigdy do console/analytics.
    var reference = (state.orderRefEl.value || '').trim();
    var email     = (state.orderEmailEl.value || '').trim();

    // Walidacja klienta — niepuste oba + sensowny email pattern.
    if (!reference && !email) {
      showOrderError('Uzupełnij oba pola: numer zamówienia i e-mail.');
      state.orderRefEl.focus();
      return;
    }
    if (!reference) {
      showOrderError('Podaj numer zamówienia.');
      state.orderRefEl.focus();
      return;
    }
    if (!email || !EMAIL_RE.test(email)) {
      showOrderError('Podaj poprawny adres e-mail.');
      state.orderEmailEl.focus();
      return;
    }

    clearOrderError();
    state.orderSubmitEl.disabled = true;
    state.orderSubmitEl.textContent = 'Sprawdzam…';

    state.orderAbortCtl = transport.checkOrderStatus(reference, email, {
      onSuccess: function (order) {
        state.orderSubmitEl.disabled = false;
        state.orderSubmitEl.textContent = 'Sprawdź status';
        state.orderAbortCtl = null;
        showOrderResult(order);
      },
      onError: function (msg, httpStatus) {
        state.orderSubmitEl.disabled = false;
        state.orderSubmitEl.textContent = 'Sprawdź status';
        state.orderAbortCtl = null;
        showOrderError(msg || 'Spróbuj ponownie.');
        // 404 / 400 — zostaw fokus na polu, klient poprawia.
        if (httpStatus === 404 || httpStatus === 400) {
          state.orderRefEl.focus();
        }
      }
    });
  }

  function openOrderModal() {
    if (!state.mounted) return;
    if (!state.orderModalEl) {
      buildOrderModal();
      state.root.appendChild(state.orderModalEl);
    }
    state.orderLastFocus = (state.root.activeElement) || document.activeElement;
    clearOrderResult();
    clearOrderError();
    if (state.orderSubmitEl) {
      state.orderSubmitEl.disabled = false;
      state.orderSubmitEl.textContent = 'Sprawdź status';
    }
    state.orderModalEl.setAttribute('data-open', 'true');
    state.orderModalOpen = true;
    // Fokus na pierwszym polu po wyrenderowaniu.
    setTimeout(function () {
      if (state.orderRefEl) state.orderRefEl.focus();
    }, 60);
  }

  function closeOrderModal() {
    if (!state.orderModalOpen) return;
    if (state.orderAbortCtl) {
      try { state.orderAbortCtl.abort(); } catch (_) {}
      state.orderAbortCtl = null;
    }
    state.orderModalEl.setAttribute('data-open', 'false');
    state.orderModalOpen = false;
    // Czyscimy pola, zeby przy ponownym otwarciu nie wisialy stare wartosci (RODO).
    if (state.orderRefEl)   state.orderRefEl.value = '';
    if (state.orderEmailEl) state.orderEmailEl.value = '';
    clearOrderError();
    clearOrderResult();
    // Fokus z powrotem na element ktory otworzyl modal (chip lub launcher).
    if (state.orderLastFocus && typeof state.orderLastFocus.focus === 'function') {
      try { state.orderLastFocus.focus(); } catch (_) {}
    }
  }

  /* ───────────────────────── Open/Close ───────────────────────── */

  function openWindow() {
    if (!state.mounted || state.open) return;
    state.lastFocus = document.activeElement;
    state.win.setAttribute('data-open', 'true');
    state.launcher.style.display = 'none';
    state.open = true;
    // CHAT-T-059 (C4): zapamietaj stan otwarcia per-tab — po reload PS panel
    // wraca otwarty (ciaglosc UX kiedy ktos klika kategorie majac czat otwarty).
    ssSet(OPEN_KEY, '1');
    // Mobile: blokada scrolla tla
    var isMobile = window.matchMedia && window.matchMedia('(max-width: 599.98px)').matches;
    if (isMobile) {
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
    }
    // CHAT-T-053 (124a): autofocus tylko na desktopie — na mobile klawiatura
    // ekranowa zaslania chipy powitalne. Klawiatura otwiera sie dopiero gdy
    // user sam tapnie pole tekstowe.
    if (!isMobile) {
      setTimeout(function () {
        if (state.inputEl) state.inputEl.focus();
      }, 180);
    }
  }

  function closeWindow() {
    if (!state.open) return;
    state.win.setAttribute('data-open', 'false');
    state.launcher.style.display = '';
    state.open = false;
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
    // CHAT-T-059 (C4): user zamknal panel — usun flage otwarcia per-tab.
    ssRemove(OPEN_KEY);
    // Przerwij ewentualny streaming
    if (state.abortCtl) {
      try { state.abortCtl.abort(); } catch (_) {}
      state.abortCtl = null;
      state.isStreaming = false;
      hideTyping();
    }
    // Fokus z powrotem
    if (state.launcher && typeof state.launcher.focus === 'function') {
      state.launcher.focus();
    } else if (state.lastFocus && typeof state.lastFocus.focus === 'function') {
      state.lastFocus.focus();
    }
  }

  /* ───────────────────────── Global keyboard ───────────────────────── */

  function onKeydown(e) {
    if (e.key !== 'Escape') return;
    if (state.orderModalOpen) {
      e.preventDefault();
      closeOrderModal();
    } else if (state.open) {
      e.preventDefault();
      closeWindow();
    }
  }

  /* ───────────────────────── Public API ───────────────────────── */

  window.DivezoneChatMount = function (ctx) {
    if (state.mounted) return;
    state.root = ctx.root;
    state.host = ctx.host;
    state.launcher = ctx.launcher;
    state.boot = ctx.boot;

    // CHAT-T-083 (247a): jesli loader wygenerowal sid przy pokazaniu nudge
    // (BOOT.nudge.pendingSessionId), uzyj go jako state.sessionId. To ten sam
    // UUID, ktory poszedl w beaconie nudge_shown/nudge_cta_click — backend
    // SJOIN-uje ekspozycje z rozmowa po session_id. One-shot: po skopiowaniu
    // czyscimy pole w BOOT, zeby startNewConversation/druga rozmowa nie wracaly
    // do tego sid. tryRestoreSession ponizej moze go nadpisac jesli restore
    // wykryje istniejaca rozmowe (exists:true) — restore MA PIERWSZENSTWO.
    if (state.boot && state.boot.nudge && state.boot.nudge.pendingSessionId) {
      state.sessionId = state.boot.nudge.pendingSessionId;
      state.boot.nudge.pendingSessionId = null;
    }

    var win = buildWindow();
    state.root.appendChild(win);

    setupVisualViewport();
    document.addEventListener('keydown', onKeydown, true);

    state.mounted = true;

    // CHAT-T-059: po mount sprobuj odtworzyc rozmowe z localStorage (TTL).
    // Async fetch — UI dziala od razu z welcome+chipy; przyjdzie historia ->
    // chipy znikna, bable sie wyrenderuja. Pad gracefully (zero error UI).
    tryRestoreSession();

    // CHAT-T-059 (C4): jesli czat byl otwarty przed reload PS — auto-otworz.
    // Per-tab (sessionStorage), nie psuje pierwszego wejscia na sklep.
    if (ssGet(OPEN_KEY) === '1') {
      openWindow();
    }
  };

  window.DivezoneChatOpen = function () { openWindow(); };
  window.DivezoneChatClose = function () { closeWindow(); };
})();
