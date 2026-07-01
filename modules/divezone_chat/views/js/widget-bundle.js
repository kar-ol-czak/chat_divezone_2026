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
  // CHAT-T-088e (58a): Level 1 ma 5 chipow po rozdzieleniu doboru (088d) —
  // limit podniesiony 4→6, zeby wszystkie zmiescily sie na mobile.
  var CHIPS_MOBILE_LIMIT = 6;
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
    // CHAT-T-085 (ADR-091): osobna atrybucja nudge — sid z ekspozycji nudge.
    // ZWLASZCZA: NIE czyszczone przez tryRestoreSession (restore zmienia tylko
    // sessionId rozmowy). nudgeSid przezywa restore, dosylany przy pierwszej
    // wiadomosci, backend zapisuje w divechat_conversations.nudge_sid przy
    // INSERT. Konwersja w panelu = JOIN events.session_id ↔ conversations.nudge_sid.
    nudgeSid: null,
    isStreaming: false,
    abortCtl: null,
    lastFocus: null,
    // CHAT-T-089 (ADR-096): drzewo chipow pobrane z /api/chip-tree przy mount.
    // chipTree = wezel root (z .children = Level 1). chipStack = sciezka zejscia
    // w glab (Wariant A nawigacji wstecz, decyzja 44a) — szczyt stosu to biezacy
    // poziom chipow. Pusty/NULL => fallback do statycznych CHIPS_DESKTOP.
    chipTree: null,
    chipStack: [],
    // CHAT-T-089b (51a): tryb nastepczy — po pierwszym bot_text chipy poziomu
    // doklejane POD bablem w messagesEl (inline), startowe menu (chipsEl) znika.
    chipsInline: false,
    // CHAT-T-121 (ADR-110, decyzja 41a): klik przycisku target:ai NIE wysyla
    // wiadomosci — odslania pole pisania. Tu zapamietujemy kontekst chipow do
    // zuzycia przy PIERWSZEJ realnej wiadomosci klienta: { context, path }.
    // context = string dla LLM (efemeryczny, ADR-097), path = strukturalna
    // sciezka [{node_key,label,level}] do utrwalenia (kontrakt chip_path).
    // One-shot: czyszczone po dolaczeniu do wysylki.
    pendingChip: null,
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
    // CHAT-T-089b: usun rowniez inline'owe zestawy chipow doklejone pod bablami.
    var inlineChips = state.messagesEl.querySelectorAll('.dz-chips--inline');
    for (var k = 0; k < inlineChips.length; k++) {
      if (inlineChips[k].parentNode) inlineChips[k].parentNode.removeChild(inlineChips[k]);
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
    // CHAT-T-085: nowa rozmowa = brak atrybucji nudge (klient kliknal "Nowa
    // rozmowa", nie wszedl przez ekspozycje). Czyscimy nudgeSid zeby kolejny
    // INSERT w bazie poszedl z NULL.
    state.nudgeSid = null;
    lsRemove(PERSIST_KEY);
    clearMessagesView();
    // CHAT-T-089: przywroc Level 1 z drzewa (zamiast statycznych chipow). No-op
    // gdy drzewo niezaladowane — wtedy clearMessagesView pokazal statyczne.
    resetChipsToLevel1();
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

  /* ───────────────────────── Silnik drzewa chipow (CHAT-T-089/089b, ADR-096) ─────────────────────────
   *
   * Zastepuje statyczne CHIPS_DESKTOP dynamicznym drzewem z GET /api/chip-tree
   * (publiczny, bez tokenu). Render Level 1 z root.children; klik wezla z bot_text
   * renderuje tekst LOKALNIE (ZERO LLM — Q231a, decyzja 38a), klik liscia ai →
   * sendUserMessage. Nawigacja w glab: chip "← Wróć" (Wariant A, decyzja 44a) —
   * bable rozmowy ZOSTAJA, wraca tylko ZESTAW CHIPOW.
   *
   * CHAT-T-089b (51a): DWA tryby renderu poziomu (wspolna logika renderChipLevelInto):
   *  - STARTOWY (przed rozmowa) — w stalym state.chipsEl u gory (pod welcome).
   *  - NASTEPCZY (po pierwszym bot_text) — inline blok doklejony POD bablem w
   *    messagesEl; startowe menu znika. "← Wróć" dokleja kolejny inline blok
   *    (bable ZOSTAJA). Tylko najnowszy inline zestaw jest interaktywny.
   */

  var CHIP_TREE_PATH = '/api/chip-tree';

  // Etykieta nawigacyjna chipa = label z wezla (42a). Fallback: node_key.
  function deriveChipLabel(node) {
    if (node && typeof node.label === 'string' && node.label.trim() !== '') {
      return node.label.trim();
    }
    return (node && node.node_key) ? String(node.node_key) : 'Opcja';
  }

  // Hook beacona klikow chipow (decyzja 39a) — celowo pusty, podpiecie w CHAT-T-090.
  function onChipClick(nodeKey) { /* no-op do CHAT-T-090 */ }

  /**
   * CHAT-T-088e (ADR-097, decyzja 65b): "dwa swiaty" — co klient klika (label) ≠
   * co dostaje AI. Serializujemy sciezke zejscia z chipStack na czytelny string
   * ("Dobór rozmiaru › Kaptur") + opcjonalny ai_prompt liscia/wezla. Wysylany
   * OSOBNYM parametrem chipContext (NIE w tresci user message — historia czysta).
   *
   * extraLabel: etykieta liscia jeszcze NIE bedacego na stosie (routeChipNode
   *   wola sendUserMessage BEZ pushu liscia) — doklejana na koniec sciezki.
   *   null dla przyciskow akcji (wezel-zrodlo jest juz na stosie).
   * aiPrompt: instrukcja od obslugi z wezla (kolumna ai_prompt) — gdy ustawiona.
   * Zwraca null gdy brak i sciezki, i ai_prompt (np. wpis z wolnego pola).
   */
  function buildChipContext(extraLabel, aiPrompt) {
    var labels = [];
    // chipStack[0] = root ("W czym mogę pomóc?") — pomijamy, nie niesie intencji.
    for (var i = 1; i < state.chipStack.length; i++) {
      labels.push(deriveChipLabel(state.chipStack[i]));
    }
    if (extraLabel && String(extraLabel).trim() !== '') {
      labels.push(String(extraLabel).trim());
    }
    var path = labels.join(' › ');
    var prompt = (typeof aiPrompt === 'string' && aiPrompt.trim() !== '') ? aiPrompt.trim() : '';
    if (path === '' && prompt === '') return null;
    var ctx = path;
    if (prompt !== '') {
      ctx += (path !== '' ? '. ' : '') + 'Instrukcja od obsługi: ' + prompt;
    }
    return ctx;
  }

  /**
   * CHAT-T-121 (ADR-110, decyzja 8b): strukturalna, rozlaczna reprezentacja
   * sciezki chipow do UTRWALENIA (kontrakt chip_path — handoff frontend→backend).
   * Analogicznie do buildChipContext serializujemy zejscie z chipStack, pomijajac
   * chipStack[0] = root (Level 1, nie niesie intencji). Kazdy element:
   *   { node_key, label, level }.
   *
   * level: drzewo z /api/chip-tree NIE eksportuje kolumny `level` (ChipTreeService
   *   celowo pomija id/parent_id/level). Wyprowadzamy go z GLEBOKOSCI stosu: root
   *   to Level 1 na indeksie 0, kazdy push schodzi dokladnie o jeden poziom
   *   (routeChipNode pcha tylko dzieci biezacego wezla), wiec chipStack[i] ma
   *   level = i + 1. Niezmiennik trzyma sie tez po "← Wróć" (pop utrzymuje
   *   ciagle indeksowanie od root).
   *
   * Zwraca [] gdy brak sciezki (klient nie zszedl ponizej root).
   */
  function buildChipPath() {
    var path = [];
    for (var i = 1; i < state.chipStack.length; i++) {
      var node = state.chipStack[i];
      if (!node) continue;
      path.push({
        node_key: (node.node_key != null) ? String(node.node_key) : '',
        label: deriveChipLabel(node),
        level: i + 1
      });
    }
    return path;
  }

  /**
   * Wspolna logika renderu jednego poziomu chipow do dowolnego kontenera
   * (CHAT-T-089b refaktor): "← Wróć" (gdy nie Level 1) + dzieci (nawigacja,
   * limit mobilny) + przyciski (akcje). Uzywane DWOJAKO: startowy chipsEl (gora)
   * oraz inline'owy blok pod bablem (tryb nastepczy, 51a). backHandler rozni sie
   * per tryb (re-render startowy vs doklejenie nowego inline bloku).
   */
  function renderChipLevelInto(containerEl, node, backHandler) {
    if (!containerEl || !node) return;
    while (containerEl.firstChild) containerEl.removeChild(containerEl.firstChild);

    var isMobile = window.matchMedia && window.matchMedia('(max-width: 599.98px)').matches;

    // "← Wróć" tylko ponizej Level 1 (Wariant A, 44a). Na Level 1 brak.
    if (state.chipStack.length > 1) {
      containerEl.appendChild(makeChipButton('← Wróć', 'dz-chip dz-chip--back', backHandler));
    }

    // Nawigacja: dzieci wezla. Limit mobilny (CHIPS_MOBILE_LIMIT) wg sort_order
    // (backend sortuje). Buttony NIE sa limitowane — to akcje, nie nawigacja.
    var children = Array.isArray(node.children) ? node.children : [];
    if (isMobile && children.length > CHIPS_MOBILE_LIMIT) {
      children = children.slice(0, CHIPS_MOBILE_LIMIT);
    }
    children.forEach(function (child) {
      containerEl.appendChild(
        makeChipButton(deriveChipLabel(child), 'dz-chip', function () { routeChipNode(child); })
      );
    });

    // Akcje: przyciski wezla (link / ai / modal).
    var buttons = Array.isArray(node.buttons) ? node.buttons : [];
    buttons.forEach(function (btn) {
      containerEl.appendChild(
        makeChipButton((btn && btn.label) || 'Otwórz', 'dz-chip', function () { routeChipButton(btn); })
      );
    });
  }

  /**
   * Wejscie w tryb nastepczy (51a): rozmowa sie zaczela, startowe menu (chipsEl)
   * u gory znika — od teraz chipy poziomu doklejane sa POD bablem w messagesEl.
   */
  function enterInlineMode() {
    state.chipsInline = true;
    if (state.chipsEl) state.chipsEl.style.display = 'none';
  }

  /**
   * Wygas wszystkie wczesniejsze inline'owe zestawy chipow — interaktywny zostaje
   * tylko najnowszy. Chroni przed mieszaniem wspoldzielonego chipStack przez klik
   * w nieaktualny "← Wróć". Link do tresci pozostaje klikalny w samym bablu (50a).
   */
  function spendPriorInlineChips() {
    if (!state.messagesEl) return;
    var prior = state.messagesEl.querySelectorAll('.dz-chips--inline:not(.dz-chips--spent)');
    for (var i = 0; i < prior.length; i++) {
      prior[i].classList.add('dz-chips--spent');
      var btns = prior[i].querySelectorAll('button');
      for (var j = 0; j < btns.length; j++) btns[j].disabled = true;
    }
  }

  /**
   * Doklejenie zestawu chipow biezacego poziomu jako nowy inline blok POD ostatnim
   * bablem (51a). Bable rozmowy ZOSTAJA (44a). Starsze inline bloki wygaszane.
   */
  function appendInlineChips(node) {
    if (!state.messagesEl || !node) return;
    spendPriorInlineChips();
    var inline = createEl('div', {
      class: 'dz-chips dz-chips--inline',
      role: 'group',
      'aria-label': 'Szybkie odpowiedzi'
    });
    renderChipLevelInto(inline, node, goBackInline);
    state.messagesEl.appendChild(inline);
    scrollToBottom();
  }

  /**
   * "← Wróć" w trybie inline: poziom wyzej. Bable ZOSTAJA — doklejamy nowy inline
   * blok poziomu wyzej pod ostatnia pozycja (NIE kasujemy historii, 44a).
   */
  function goBackInline() {
    if (state.chipStack.length > 1) state.chipStack.pop();
    appendInlineChips(state.chipStack[state.chipStack.length - 1]);
  }

  function makeChipButton(label, cssClass, onClick) {
    var btn = createEl('button', { class: cssClass, type: 'button' }, escapeHtml(label));
    btn.addEventListener('click', onClick);
    return btn;
  }

  /**
   * Pobranie drzewa przy mount. Public endpoint — prosty fetch, bez naglowkow
   * X-DiveChat-*. ETag/max-age=300 obsluguje przegladarka (cache:'default').
   * Graceful fallback: blad/puste => statyczne chipy z buildWindow zostaja.
   */
  function fetchChipTree() {
    var backend = state.boot && state.boot.backendUrl;
    if (!backend) return; // brak backendUrl => zostaja statyczne chipy
    fetch(backend + CHIP_TREE_PATH, {
      method: 'GET',
      mode: 'cors',
      credentials: 'omit',
      cache: 'default',
      headers: { 'Accept': 'application/json' }
    }).then(function (resp) {
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      return resp.json();
    }).then(function (data) {
      var roots = (data && Array.isArray(data.tree)) ? data.tree : null;
      if (!roots || !roots.length) throw new Error('puste drzewo');
      var root = roots[0];
      if (!root || !Array.isArray(root.children) || !root.children.length) {
        throw new Error('root bez dzieci');
      }
      state.chipTree = root;
      state.chipStack = [root];
      // Render Level 1 TYLKO gdy chipy sa widoczne (rozmowa sie nie zaczela
      // / nie przywrocono historii). Jesli tryRestoreSession schowal chipy —
      // nie pokazuj ich z powrotem.
      if (state.chipsEl && state.chipsEl.style.display !== 'none') {
        renderCurrentChipLevel();
      }
    }).catch(function () {
      // Graceful: statyczne CHIPS_DESKTOP (buildWindow) zostaja jako fallback.
    });
  }

  /**
   * Render biezacego poziomu w STARTOWYM chipsEl (gora, pod welcome). Tryb sprzed
   * rozmowy: startowe menu Level 1 + ewentualne zejscie w wezel BEZ bot_text.
   * "← Wróć" tu re-renderuje startowy chipsEl w miejscu.
   */
  function renderCurrentChipLevel() {
    if (!state.chipsEl || !state.chipTree || !state.chipStack.length) return;
    var node = state.chipStack[state.chipStack.length - 1];
    if (!node) return;
    state.chipsEl.style.display = '';
    renderChipLevelInto(state.chipsEl, node, popChipLevelStartowe);
    scrollToBottom();
  }

  function popChipLevelStartowe() {
    if (state.chipStack.length > 1) state.chipStack.pop();
    renderCurrentChipLevel();
  }

  /** Reset do Level 1 z drzewa (np. po "Nowa rozmowa"). No-op gdy brak drzewa. */
  function resetChipsToLevel1() {
    state.chipsInline = false; // powrot do startowego menu u gory
    if (!state.chipTree) return; // statyczne chipy zostaja (juz widoczne)
    state.chipStack = [state.chipTree];
    renderCurrentChipLevel();
  }

  /**
   * Routing klika chipa nawigacyjnego (sedno, decyzja 38a + 51a):
   *  - wezel z bot_text  → render LOKALNIE (ZERO LLM); chipy poziomu doklejone
   *    INLINE POD bablem (tryb nastepczy), startowe menu znika.
   *  - czysto nawigacyjny (bez tekstu, ma dzieci/przyciski) → zejscie bez babla:
   *    inline pod ostatnim bablem gdy rozmowa juz trwa, inaczej w startowym chipsEl.
   *  - lisc ai (bez tekstu, bez dzieci) → sendUserMessage(label) (114a).
   */
  function routeChipNode(node) {
    if (!node) return;
    onChipClick(node.node_key); // beacon hook (CHAT-T-090)

    var hasText = typeof node.bot_text === 'string' && node.bot_text.trim() !== '';
    var hasChildren = Array.isArray(node.children) && node.children.length > 0;
    var hasButtons = Array.isArray(node.buttons) && node.buttons.length > 0;

    if (hasText) {
      appendBotMessage(node.bot_text);
      // Chipy poziomu doklejone POD bablem (51a) — naturalne przedluzenie
      // odpowiedzi. Tylko gdy jest co pokazac; inaczej bot_text zostaje sam,
      // startowe menu (jesli jeszcze widoczne) tez zostaje.
      if (hasChildren || hasButtons) {
        enterInlineMode();
        state.chipStack.push(node);
        appendInlineChips(node);
      } else {
        // CHAT-T-121 (ADR-110, decyzja 8a): lisc z bot_text, bez dzieci i bez
        // przyciskow — po usunieciu zbednego "Napisz czego szukasz" (seed 040)
        // bot_text SAM zaprasza do pisania. Wchodzimy w tryb pisania: lisc na
        // stos (zeby sciezka + ai_prompt objely ten lisc), potem enterWriteMode
        // ustawia pendingChip i fokusuje input. Zachowanie zbiezne z dawnym
        // klikiem przycisku target:ai na tym lisciu.
        enterInlineMode();
        state.chipStack.push(node);
        enterWriteMode(buildChipContext(null, node.ai_prompt), buildChipPath());
      }
      return;
    }

    if (hasChildren || hasButtons) {
      // Wezel czysto nawigacyjny (bez tekstu). W trybie inline (rozmowa trwa)
      // doklej chipy pod ostatnim bablem; przed rozmowa — zejscie w startowym
      // chipsEl u gory (zachowanie 089, np. przyszle podchipy doboru).
      state.chipStack.push(node);
      if (state.chipsInline) {
        appendInlineChips(node);
      } else {
        renderCurrentChipLevel();
      }
      return;
    }

    // Lisc ai: wstrzykniecie czytelnego content do LLM (114a) — NIE node_key.
    // CHAT-T-088e: user message = label liscia; sciezka + ai_prompt liscia ida
    // OSOBNYM parametrem chipContext (decyzja 65b). Lisc NIE jest na stosie —
    // doklejamy jego label jako ostatni segment sciezki (extraLabel).
    var leafLabel = deriveChipLabel(node);
    sendUserMessage(leafLabel, buildChipContext(leafLabel, node.ai_prompt));
  }

  /**
   * CHAT-T-121 (ADR-110, decyzja 41a): wejscie w pisanie z chipa target:ai.
   * Ukrywa chipy (startowe + wygasza inline), ustawia one-shot pendingChip i
   * daje fokus na input. Bez wysylania wiadomosci — bot_text liscia juz zaprasza
   * do pisania. Fokus przez state.inputEl.focus() (ten sam wzorzec co "Nowa
   * rozmowa" / restore — mobile: przegladarka sama pokaze klawiature).
   */
  function enterWriteMode(context, path) {
    if (state.chipsEl && state.chipsEl.parentNode) {
      state.chipsEl.style.display = 'none';
    }
    spendPriorInlineChips();
    state.pendingChip = {
      context: context || null,
      path: Array.isArray(path) ? path : []
    };
    if (state.inputEl) {
      try { state.inputEl.focus(); } catch (_) { /* mobile/edge: no-op */ }
    }
  }

  /**
   * Mapowanie target przycisku na akcje:
   *  - link (+url)    → otworz URL w nowej karcie (_blank, noopener)
   *  - ai             → wejscie w pisanie (ADR-110) — NIE wysyla wiadomosci
   *  - modal:order    → openOrderModal(); inne modal:<typ> → fallback ai
   *  - curated:<kat> / inne → fallback ai (hook na przyszlosc, poza zakresem)
   */
  function routeChipButton(btn) {
    if (!btn) return;
    var target = String(btn.target || '');
    if (target === 'link' && btn.url) {
      window.open(String(btn.url), '_blank', 'noopener');
      return;
    }
    // CHAT-T-088e: przycisk akcji ai siedzi na wezle-zrodle (juz na chipStack),
    // wiec sciezka = caly stos; ai_prompt bierzemy z tego wezla (extraLabel=null).
    var srcNode = state.chipStack.length ? state.chipStack[state.chipStack.length - 1] : null;
    var ctx = buildChipContext(null, srcNode && srcNode.ai_prompt);
    if (target === 'ai') {
      // CHAT-T-121 (ADR-110, decyzja 41a): NIE wysylamy wiadomosci. Etykieta
      // przycisku ("Napisz czego szukasz") to instrukcja UI, nie tresc — nigdy
      // nie trafia do historii ani do backendu. Odslaniamy pole pisania i
      // zapamietujemy kontekst chipow do zuzycia przy pierwszej realnej
      // wiadomosci klienta (context dla LLM + strukturalna sciezka chip_path).
      enterWriteMode(ctx, buildChipPath());
      return;
    }
    if (target.indexOf('modal:') === 0) {
      if (target === 'modal:order') { openOrderModal(); return; }
      sendUserMessage(btn.label || '', ctx); // inne modale jeszcze nieobslugiwane
      return;
    }
    // curated:<kat> i nieznane — fallback do LLM (nie budujemy sciezki kuratora).
    sendUserMessage(btn.label || '', ctx);
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

    // Chipy — render poczatkowy ze statycznych CHIPS_DESKTOP (FALLBACK). Po mount
    // fetchChipTree() podmienia je na dynamiczny Level 1 z drzewa (CHAT-T-089).
    // Gdy fetch padnie/puste — te statyczne zostaja (graceful degradation).
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

  function sendUserMessage(text, chipContext) {
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
    // CHAT-T-089b: wejscie w swobodna rozmowe — wygas aktywne inline'owe chipy
    // (klient zadal pytanie do LLM, nie nawiguje juz drzewem). Link w tresci
    // poprzednich babli zostaje klikalny.
    spendPriorInlineChips();

    // CHAT-T-121 (ADR-110, decyzje 41a/9a): konsumpcja pendingChip. Jesli klient
    // wszedl w pisanie przez przycisk target:ai, pierwsza realna wiadomosc niesie
    // zapamietany chip_context (string dla LLM tej tury) ORAZ strukturalna sciezke
    // chip_path (do utrwalenia w rozmowie). One-shot: kolejne wiadomosci tury juz
    // bez tego. Jawny chipContext (lisc-ai z routeChipNode) ma pierwszenstwo nad
    // pendingChip; chip_path pochodzi WYLACZNIE z pendingChip (target:ai).
    var chipPath = null;
    if (state.pendingChip) {
      if (!chipContext) chipContext = state.pendingChip.context;
      if (Array.isArray(state.pendingChip.path) && state.pendingChip.path.length) {
        chipPath = state.pendingChip.path;
      }
      state.pendingChip = null;
    }

    appendUserMessage(text);
    state.inputEl.value = '';
    state.inputEl.dispatchEvent(new Event('input'));

    state.isStreaming = true;
    state.sendBtnEl.disabled = true;
    showTyping('Asystent pisze');

    // CHAT-T-085 fix race (smoke 2026-06-08): widget-loader robi prefetch
    // bundla na requestIdleCallback (linia ~747 widget-loader.js), wczesniej
    // niz setupNudge.setTimeout ktory ustawia BOOT.nudge.pendingSessionId.
    // Mount wykonal sie ZANIM ekspozycja nudge → blok if{} przy mount nie
    // zlapał pending (bylo undefined). Konsumujemy lazily TUTAJ, tuz przed
    // pierwsza wiadomoscia: jesli BOOT.nudge.pendingSessionId pojawil sie
    // PO mount (typowy przypadek), przenosimy go do state. One-shot — czyscimy
    // pole po konsumpcji zeby kolejne wiadomosci nie wracaly do tego sid.
    // Sprawdzenie pole-po-polu: nudgeSid (atrybucja) zawsze konsumuje pending,
    // sessionId TYLKO gdy null (zeby NIE nadpisac restored sid z tryRestoreSession).
    if (state.boot && state.boot.nudge && state.boot.nudge.pendingSessionId) {
      var pendingNudge = state.boot.nudge.pendingSessionId;
      if (!state.nudgeSid) state.nudgeSid = pendingNudge;
      if (state.sessionId === null) state.sessionId = pendingNudge;
      state.boot.nudge.pendingSessionId = null;
    }

    // CHAT-T-085 (ADR-091): dosyłamy nudgeSid jako OSOBNY parametr — backend
    // zapisze w divechat_conversations.nudge_sid przy INSERT nowej rozmowy.
    // Null gdy klient nie wszedl przez ekspozycje nudge (launcher) lub po
    // startNewConversation. Wartosc PRZEZYWA tryRestoreSession (tylko sessionId
    // jest nadpisywane przez restore — to rozdzielenie ról jest sednem fixu).
    // CHAT-T-088e (ADR-097, decyzja 65b): chipContext (sciezka chipow + ai_prompt)
    // jako OSOBNY parametr — backend wstrzykuje go do system promptu tej tury, NIE
    // do tresci user message. null dla wolnego pisania (czysta rozmowa).
    // CHAT-T-121 (ADR-110): chipPath — strukturalna sciezka do utrwalenia (jsonb),
    // rozlaczna z chipContext. null gdy klient nie wszedl przez chip target:ai.
    state.abortCtl = transport.sendMessage(text, state.sessionId, state.nudgeSid, chipContext || null, chipPath, {
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
        // CHAT-T-085: po pierwszej wiadomosci atrybucja jest juz zapisana w
        // bazie (INSERT z nudge_sid). Czyscimy state, zeby kolejne wiadomosci
        // tej rozmowy nie wysylaly redundantnego pola — rozmowa juz ma swoja
        // atrybucje w PG, dosylanie nie zmieni nic (resume nie nadpisuje).
        state.nudgeSid = null;
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

    // CHAT-T-083 (247a) + CHAT-T-085 (ADR-091): jesli loader wygenerowal sid
    // przy pokazaniu nudge (BOOT.nudge.pendingSessionId), uzyj go DWOJAKO:
    //  (a) state.sessionId — proba startu rozmowy z tym sid (moze byc nadpisany
    //      przez tryRestoreSession gdy backend zna stara rozmowe; restore wygrywa
    //      dla UX zachowania historii — decyzja swiadoma, ADR-091).
    //  (b) state.nudgeSid — OSOBNA, TRWALA atrybucja. NIE czyszczona przez
    //      restore. Dosylana w body pierwszej wiadomosci. Backend zapisze w
    //      divechat_conversations.nudge_sid przy INSERT nowej rozmowy. Konwersja
    //      w panelu CHAT-T-084 = JOIN events.session_id ↔ conversations.nudge_sid.
    // One-shot: po skopiowaniu czyscimy pendingSessionId w BOOT, zeby
    // startNewConversation/druga rozmowa nie wracaly do tego sid.
    if (state.boot && state.boot.nudge && state.boot.nudge.pendingSessionId) {
      state.sessionId = state.boot.nudge.pendingSessionId;
      state.nudgeSid = state.boot.nudge.pendingSessionId;
      state.boot.nudge.pendingSessionId = null;
    }

    var win = buildWindow();
    state.root.appendChild(win);

    setupVisualViewport();
    document.addEventListener('keydown', onKeydown, true);

    state.mounted = true;

    // CHAT-T-089: pobierz drzewo chipow i podmien Level 1 (jesli chipy widoczne).
    // Async — UI startuje od razu ze statycznymi chipami (fallback), drzewo je
    // zastapi gdy przyjdzie. Pad => statyczne zostaja.
    fetchChipTree();

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
