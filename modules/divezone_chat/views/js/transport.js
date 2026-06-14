/*!
 * DiveZone Chat — transport (auth + streaming) — WYMIENIALNA WARSTWA
 * CHAT-T-037 (etap 1, HMAC) + CHAT-T-069 (odswiezanie tokenu).
 * ADR-069: wymiana warstwy = podmiana TEGO JEDNEGO pliku (np. JWT w etapie 2/3),
 *          reszta widgetu bez zmian.
 *
 * Eksponuje globalnie: window.DivezoneChatTransport
 *
 * sendMessage(message, sessionId, nudgeSid, chipContext, callbacks) -> AbortController
 *   callbacks: { onStatus(text), onDone(payload), onError(msg) }
 *   nudgeSid (CHAT-T-085 / ADR-091): opcjonalna atrybucja ekspozycji nudge.
 *     null gdy klient nie wszedl przez nudge (launcher / second message).
 *   chipContext (CHAT-T-088e / ADR-097, decyzja 65b): opcjonalny kontekst sciezki
 *     chipow ("Dobór rozmiaru › Kaptur" + ai_prompt). OSOBNY od message — backend
 *     wstrzykuje go do promptu tury, NIE do historii. null dla wolnego pisania.
 *
 * Token + customerId + time + backendUrl + tokenUrl czyta z window.DIVEZONE_CHAT_BOOT
 * (ustawione przez shim PHP w hookDisplayFooter).
 *
 * Odswiezanie tokenu (ADR-084):
 *  - PROAKTYWNE: przed kazdym requestem sprawdzamy wiek BOOT.time; jesli starszy
 *    niz PROACTIVE_REFRESH_SEC, pobieramy nowy z BOOT.tokenUrl (endpoint modulu PS
 *    na tym samym originie, credentials:'include' = ciastko sesji PS). Bez timerow w tle.
 *  - REAKTYWNE: kazda z 3 funkcji (sendMessage, checkOrderStatus, fetchHistory)
 *    przy odpowiedzi HTTP 401 wola refreshToken() i ponawia request RAZ
 *    (anti-petla: max 1 retry per wywolanie). Drugi 401 -> normalny komunikat bledu.
 *  - Refresh aktualizuje wspoldzielony BOOT.token/time/customerId, wiec kolejne
 *    wywolania zaraz po refreshu korzystaja z nowego tokenu.
 *
 * TTL backendu: HmacVerifier::maxAgeSec (skracany osobnym krokiem backendu po
 * weryfikacji refreshu na PROD — CHAT-T-069 krok warunkowy).
 */
(function () {
  'use strict';

  var BOOT = window.DIVEZONE_CHAT_BOOT;
  if (!BOOT) return;

  // Prog wieku tokenu, powyzej ktorego refreshujemy PROAKTYWNIE przed requestem.
  // 600s = bezpiecznie ponizej docelowego TTL backendu (900s, ADR-084 191c) i
  // dziala rowniez przy aktualnym TTL 3600s (ADR-079) zanim zostanie skrocony.
  var PROACTIVE_REFRESH_SEC = 600;

  // Anti-burst: pojedynczy in-flight refresh; rownolegle wywolania czekaja na ten sam fetch.
  var refreshInFlight = false;
  var refreshQueue = [];

  function tokenAgeSec() {
    var t = parseInt(BOOT.time, 10);
    if (!t) return 0;
    return Math.floor(Date.now() / 1000) - t;
  }

  function refreshToken(callback) {
    callback = callback || function () {};
    if (!BOOT.tokenUrl) { callback(false); return; }

    refreshQueue.push(callback);
    if (refreshInFlight) return;
    refreshInFlight = true;

    function settle(ok) {
      refreshInFlight = false;
      var queue = refreshQueue;
      refreshQueue = [];
      for (var i = 0; i < queue.length; i++) {
        try { queue[i](ok); } catch (e) { /* nie psujemy reszty kolejki */ }
      }
    }

    fetch(BOOT.tokenUrl, {
      method: 'GET',
      // Ten sam origin co strona sklepu (divezone.pl). credentials:'include'
      // zapewnia, ze ciastko sesji PS idzie z requestem -> isLogged() rozpoznaje
      // zalogowanego klienta, customerId = realne id (a nie 0 jak dla goscia).
      credentials: 'include',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) { settle(false); return; }
      return response.json().then(function (payload) {
        if (!payload || !payload.token) { settle(false); return; }
        BOOT.token      = payload.token;
        BOOT.time       = String(payload.time);
        BOOT.customerId = String(payload.customerId);
        settle(true);
      }).catch(function () { settle(false); });
    }).catch(function () { settle(false); });
  }

  function ensureFreshToken(callback) {
    if (!BOOT.tokenUrl || tokenAgeSec() <= PROACTIVE_REFRESH_SEC) {
      callback();
      return;
    }
    // Niezaleznie od wyniku proaktywnego refreshu — kontynuuj (reaktywny retry zlapie 401).
    refreshToken(function () { callback(); });
  }

  /**
   * Parser SSE strumienia z ReadableStream.
   * Format: bloki rozdzielone "\n\n", linia "event: X" + "data: JSON".
   * Mozliwosc multilinii data: konkatenowane z "\n" (kanonicznie spec SSE).
   */
  function parseSseChunks(buffer) {
    var blocks = buffer.split('\n\n');
    var rest = blocks.pop(); // niedokonczony blok zostaje w buforze
    var events = [];
    for (var i = 0; i < blocks.length; i++) {
      var raw = blocks[i];
      if (!raw.trim()) continue;
      var lines = raw.split('\n');
      var ev = 'message';
      var data = [];
      for (var j = 0; j < lines.length; j++) {
        var line = lines[j];
        if (line.indexOf('event:') === 0) {
          ev = line.slice(6).trim();
        } else if (line.indexOf('data:') === 0) {
          data.push(line.slice(5).trim());
        }
      }
      events.push({ event: ev, data: data.join('\n') });
    }
    return { events: events, rest: rest };
  }

  function sendMessage(message, sessionId, nudgeSid, chipContext, callbacks) {
    callbacks = callbacks || {};
    var onStatus = callbacks.onStatus || function () {};
    var onDone   = callbacks.onDone   || function () {};
    var onError  = callbacks.onError  || function () {};

    var controller = new AbortController();
    var url = BOOT.backendUrl + (BOOT.streamPath || '/api/chat/stream');

    var body = { message: message };
    if (sessionId) body.session_id = sessionId;
    // CHAT-T-085 (ADR-091): dosylamy osobna atrybucje nudge (jesli byla
    // ekspozycja w tej sesji przegladania). Backend zapisze w
    // divechat_conversations.nudge_sid przy INSERT nowej rozmowy.
    if (nudgeSid) body.nudge_sid = nudgeSid;
    // CHAT-T-088e (ADR-097, decyzja 65b): kontekst sciezki chipow jako osobne pole.
    // Backend (ChatController::resolveChipContext) trimuje + capuje; tu tylko gdy niepuste.
    if (chipContext) body.chip_context = chipContext;
    var bodyJson = JSON.stringify(body);

    function makeRequest() {
      return fetch(url, {
        method: 'POST',
        mode: 'cors',
        credentials: 'omit',
        cache: 'no-cache',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'text/event-stream',
          'X-DiveChat-Token': BOOT.token,
          'X-DiveChat-Customer': BOOT.customerId,
          'X-DiveChat-Time': BOOT.time
        },
        body: bodyJson
      });
    }

    function streamResponse(response) {
      if (!response.body || !response.body.getReader) {
        onError('Twoja przegladarka nie wspiera streamingu.');
        return;
      }
      var reader = response.body.getReader();
      var decoder = new TextDecoder('utf-8');
      var buffer = '';
      var done = false;
      var sawDone = false;

      function pump() {
        return reader.read().then(function (chunk) {
          if (chunk.done) {
            if (!sawDone) {
              onError('Polaczenie zostalo zamkniete przed odpowiedzia.');
            }
            done = true;
            return;
          }
          buffer += decoder.decode(chunk.value, { stream: true });
          var parsed = parseSseChunks(buffer);
          buffer = parsed.rest;
          for (var i = 0; i < parsed.events.length; i++) {
            var ev = parsed.events[i];
            var payload = null;
            try { payload = JSON.parse(ev.data); } catch (e) { payload = null; }

            if (ev.event === 'status' && payload && typeof payload.text === 'string') {
              onStatus(payload.text);
            } else if (ev.event === 'done' && payload) {
              sawDone = true;
              onDone(payload);
            } else if (ev.event === 'error') {
              sawDone = true; // unikamy podwojnego error po close
              var msg = payload && payload.error ? payload.error : 'Blad serwera.';
              onError(msg);
            }
          }
          if (!done) return pump();
        });
      }
      return pump();
    }

    function emitHttpError(response) {
      return response.text().then(function (txt) {
        onError('HTTP ' + response.status + ': ' + (txt || response.statusText));
      });
    }

    function handle(response, alreadyRetried) {
      // Reaktywny retry-on-401: refresh + jedno ponowienie. SSE: 401 przychodzi
      // jako !response.ok PRZED otwarciem strumienia, wiec mozemy spokojnie ponowic.
      if (response.status === 401 && !alreadyRetried) {
        return new Promise(function (resolve) {
          refreshToken(function (ok) {
            if (!ok) { emitHttpError(response).then(resolve, resolve); return; }
            makeRequest().then(function (resp2) {
              handle(resp2, true).then(resolve, resolve);
            }, function (err) {
              if (err && err.name === 'AbortError') { resolve(); return; }
              onError((err && err.message) || 'Blad sieci.');
              resolve();
            });
          });
        });
      }
      if (!response.ok) {
        return emitHttpError(response);
      }
      return streamResponse(response);
    }

    ensureFreshToken(function () {
      makeRequest().then(function (response) {
        return handle(response, false);
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return; // zaplanowane przerwanie
        onError((err && err.message) || 'Blad sieci.');
      });
    });

    return controller;
  }

  /**
   * Sprawdzenie statusu zamowienia (CHAT-T-043).
   * POST /api/order/status — zwykly fetch JSON (NIE SSE), HMAC identyczny
   * jak czat. Strukturalny input z pominieciem LLM (ADR-063).
   *
   * @param {string} reference   numer / referencja zamowienia
   * @param {string} email       email klienta uzyty przy zakupie
   * @param {object} callbacks   { onSuccess(order), onError(message, httpStatus) }
   * @returns {AbortController}  do przerwania requestu
   *
   * RODO: nie loguje reference/email do konsoli. Body idzie tylko do
   * skonfigurowanego backendUrl, nigdzie indziej.
   */
  function checkOrderStatus(reference, email, callbacks) {
    callbacks = callbacks || {};
    var onSuccess = callbacks.onSuccess || function () {};
    var onError   = callbacks.onError   || function () {};

    var controller = new AbortController();
    var url = BOOT.backendUrl + '/api/order/status';
    var bodyJson = JSON.stringify({ order_reference: reference, email: email });

    function makeRequest() {
      return fetch(url, {
        method: 'POST',
        mode: 'cors',
        credentials: 'omit',
        cache: 'no-cache',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-DiveChat-Token': BOOT.token,
          'X-DiveChat-Customer': BOOT.customerId,
          'X-DiveChat-Time': BOOT.time
        },
        body: bodyJson
      });
    }

    function mapAndCallback(response) {
      var status = response.status;
      return response.json().catch(function () { return null; }).then(function (payload) {
        if (status === 200 && payload && payload.success && payload.order) {
          onSuccess(payload.order);
          return;
        }
        // Mapowanie kodow na przyjazne komunikaty (PII-free).
        var msg;
        if (status === 400) {
          msg = (payload && payload.error) || 'Uzupelnij oba pola.';
        } else if (status === 401) {
          // Token nie odswiezyl sie nawet po retry (lub refresh zwrocil 403/500).
          // Fallback dla uzytkownika: pojedyncza informacja zamiast pustego ekranu.
          msg = 'Sesja wygasla. Odswiez strone i sprobuj ponownie.';
        } else if (status === 404) {
          msg = (payload && payload.error) || 'Nie znaleziono zamowienia. Sprawdz dane.';
        } else if (status >= 500) {
          msg = 'Wystapil blad serwera. Sprobuj za chwile.';
        } else {
          msg = (payload && payload.error) || ('Blad ' + status + '.');
        }
        onError(msg, status);
      });
    }

    function handle(response, alreadyRetried) {
      if (response.status === 401 && !alreadyRetried) {
        return new Promise(function (resolve) {
          refreshToken(function (ok) {
            if (!ok) { mapAndCallback(response).then(resolve, resolve); return; }
            makeRequest().then(function (resp2) {
              handle(resp2, true).then(resolve, resolve);
            }, function (err) {
              if (err && err.name === 'AbortError') { resolve(); return; }
              onError('Brak polaczenia. Sprawdz internet i sprobuj ponownie.', 0);
              resolve();
            });
          });
        });
      }
      return mapAndCallback(response);
    }

    ensureFreshToken(function () {
      makeRequest().then(function (response) {
        return handle(response, false);
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        onError('Brak polaczenia. Sprawdz internet i sprobuj ponownie.', 0);
      });
    });

    return controller;
  }

  /**
   * Pobranie historii aktywnej rozmowy (CHAT-T-059).
   * GET /api/chat/history?sid={sessionId} — HMAC identyczny jak czat.
   *
   * Backend zwraca {exists:true, session_id, messages:[]} dla aktywnej rozmowy
   * nalezacej do customera (weryfikacja ps_customer_id == HMAC customerId).
   * Cudza/nieistniejaca/zamknieta -> {exists:false, messages:[]} (NIE blad —
   * front gracefully startuje swiezy czat).
   *
   * UWAGA: query param `sid` (NIE `session_id`) — LiteSpeed/ModSecurity na
   * hostingu blokuje query stringi z `session_id=` (regula PHPSESSID-like, 403).
   *
   * @param {string} sessionId   identyfikator sesji z localStorage
   * @param {object} callbacks   { onResult({exists, messages}), onError(msg) }
   * @returns {AbortController}
   */
  function fetchHistory(sessionId, callbacks) {
    callbacks = callbacks || {};
    var onResult = callbacks.onResult || function () {};
    var onError  = callbacks.onError  || function () {};

    var controller = new AbortController();
    var path = (BOOT.persist && BOOT.persist.historyPath) || '/api/chat/history';
    var url = BOOT.backendUrl + path + '?sid=' + encodeURIComponent(sessionId);

    function makeRequest() {
      return fetch(url, {
        method: 'GET',
        mode: 'cors',
        credentials: 'omit',
        cache: 'no-cache',
        signal: controller.signal,
        headers: {
          'Accept': 'application/json',
          'X-DiveChat-Token': BOOT.token,
          'X-DiveChat-Customer': BOOT.customerId,
          'X-DiveChat-Time': BOOT.time
        }
      });
    }

    function consume(response) {
      var status = response.status;
      return response.json().catch(function () { return null; }).then(function (payload) {
        if (status === 200 && payload && typeof payload.exists === 'boolean') {
          onResult({
            exists: payload.exists,
            sessionId: payload.session_id || sessionId,
            messages: payload.messages || []
          });
          return;
        }
        // 401 po retry / inny blad — gracefully traktuj jako "brak historii",
        // wolajacy startuje swiezo (zachowanie z CHAT-T-059).
        onError('HTTP ' + status);
      });
    }

    function handle(response, alreadyRetried) {
      if (response.status === 401 && !alreadyRetried) {
        return new Promise(function (resolve) {
          refreshToken(function (ok) {
            if (!ok) { consume(response).then(resolve, resolve); return; }
            makeRequest().then(function (resp2) {
              handle(resp2, true).then(resolve, resolve);
            }, function (err) {
              if (err && err.name === 'AbortError') { resolve(); return; }
              onError((err && err.message) || 'Blad sieci.');
              resolve();
            });
          });
        });
      }
      return consume(response);
    }

    ensureFreshToken(function () {
      makeRequest().then(function (response) {
        return handle(response, false);
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        onError((err && err.message) || 'Blad sieci.');
      });
    });

    return controller;
  }

  window.DivezoneChatTransport = {
    sendMessage: sendMessage,
    fetchHistory: fetchHistory,
    checkOrderStatus: checkOrderStatus,
    /* getBootSnapshot: tylko do diagnostyki (NIE zwraca tokenu). */
    getBootSnapshot: function () {
      return {
        backendUrl: BOOT.backendUrl,
        customerId: BOOT.customerId,
        version: BOOT.version
      };
    }
  };
})();
