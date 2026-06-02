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

  var WELCOME_HTML =
    '<p><strong>Cześć! Jestem doradcą nurkowym DIVEZONE.PL.</strong></p>' +
    '<p>Pomogę dobrać sprzęt, sprawdzić rozmiar pianki lub suchego skafandra, ' +
    'zweryfikować kompatybilność automatu z komputerem — albo odpowiem na ' +
    'pytania o zamówienie.</p>' +
    '<p><span class="dz-callout">Od czego zaczynamy?</span></p>';

  var CHIPS_DESKTOP = [
    'Pomóż dobrać sprzęt',
    'Dobierz rozmiar',
    'Kompatybilność sprzętu',
    'Dostępność i wysyłka',
    'Status zamówienia',
    'Serwis sprzętu'
  ];
  var CHIPS_MOBILE_LIMIT = 4;

  var PRIVACY_NOTE_HTML =
    'Rozmawiasz z asystentem AI — nie podawaj danych wrażliwych. ' +
    '<a href="https://divezone.pl/content/3-polityka-prywatnosci" target="_blank" rel="noopener noreferrer">Polityka prywatności.</a>';

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
    lastFocus: null
  };

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
          sendUserMessage(label);
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

  /* ───────────────────────── Open/Close ───────────────────────── */

  function openWindow() {
    if (!state.mounted || state.open) return;
    state.lastFocus = document.activeElement;
    state.win.setAttribute('data-open', 'true');
    state.launcher.style.display = 'none';
    state.open = true;
    // Mobile: blokada scrolla tla
    var isMobile = window.matchMedia && window.matchMedia('(max-width: 599.98px)').matches;
    if (isMobile) {
      document.documentElement.style.overflow = 'hidden';
      document.body.style.overflow = 'hidden';
    }
    // Fokus na input (po animacji)
    setTimeout(function () {
      if (state.inputEl) state.inputEl.focus();
    }, 180);
  }

  function closeWindow() {
    if (!state.open) return;
    state.win.setAttribute('data-open', 'false');
    state.launcher.style.display = '';
    state.open = false;
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';
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
    if (e.key === 'Escape' && state.open) {
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

    var win = buildWindow();
    state.root.appendChild(win);

    setupVisualViewport();
    document.addEventListener('keydown', onKeydown, true);

    state.mounted = true;
  };

  window.DivezoneChatOpen = function () { openWindow(); };
  window.DivezoneChatClose = function () { closeWindow(); };
})();
