/*!
 * DiveZone Chat — transport (auth + streaming) — WYMIENIALNA WARSTWA
 * CHAT-T-037 etap 1, ADR-069 (etap 1 = HMAC, wymiana na JWT = etap 2/3
 * przez podmiane tego JEDNEGO pliku, reszta widgetu bez zmian).
 *
 * Eksponuje globalnie: window.DivezoneChatTransport
 *
 * sendMessage(message, sessionId, callbacks) -> AbortController
 *   callbacks: { onStatus(text), onDone(payload), onError(msg) }
 *
 * Token + customerId + time + backendUrl czyta z window.DIVEZONE_CHAT_BOOT
 * (ustawione przez shim PHP w hookDisplayFooter). NOTA: token ma anti-replay
 * 1 h na backendzie (HmacVerifier::maxAgeSec=3600, CHAT-T-057 — wczesniej 5 min).
 * Etap 1 emituje JEDEN token na stronie — sesja > 1 h zwroci 401. Persystencja
 * miedzy stronami (CHAT-T-059) odswieza token przy kazdym reload PS, wiec
 * problem dotyczy tylko sesji bez nawigacji powyzej godziny.
 * Pelne odswiezanie tokenu z transportu = ADR-064 (przyszle).
 */
(function () {
  'use strict';

  var BOOT = window.DIVEZONE_CHAT_BOOT;
  if (!BOOT) return;

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

  function sendMessage(message, sessionId, callbacks) {
    callbacks = callbacks || {};
    var onStatus = callbacks.onStatus || function () {};
    var onDone   = callbacks.onDone   || function () {};
    var onError  = callbacks.onError  || function () {};

    var controller = new AbortController();
    var url = BOOT.backendUrl + (BOOT.streamPath || '/api/chat/stream');

    var body = { message: message };
    if (sessionId) body.session_id = sessionId;

    fetch(url, {
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
      body: JSON.stringify(body)
    }).then(function (response) {
      if (!response.ok) {
        return response.text().then(function (txt) {
          onError('HTTP ' + response.status + ': ' + (txt || response.statusText));
        });
      }
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
            // Strumien zamkniety. Jesli nie bylo "done", to error/timeout.
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
    }).catch(function (err) {
      if (err && err.name === 'AbortError') return; // zaplanowane przerwanie
      onError((err && err.message) || 'Blad sieci.');
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

    fetch(url, {
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
      body: JSON.stringify({
        order_reference: reference,
        email: email
      })
    }).then(function (response) {
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
          // Token HMAC zyje 1 h od zaladowania strony (BOOT, CHAT-T-057).
          // Etap 1 nie refreshuje — instrukcja dla klienta.
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
    }).catch(function (err) {
      if (err && err.name === 'AbortError') return;
      onError('Brak polaczenia. Sprawdz internet i sprobuj ponownie.', 0);
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

    fetch(url, {
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
    }).then(function (response) {
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
        // 401/500/etc — gracefully traktuj jako "brak historii", front startuje swiezo.
        onError('HTTP ' + status);
      });
    }).catch(function (err) {
      if (err && err.name === 'AbortError') return;
      onError((err && err.message) || 'Blad sieci.');
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
