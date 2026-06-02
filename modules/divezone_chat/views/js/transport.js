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
 * 5 min na backendzie (HmacVerifier::maxAgeSec=300). Etap 1 emituje JEDEN token
 * na stronie — jesli sesja przekroczy 5 min, kolejne /stream zwroci 401.
 * Akceptowalne dla etapu 1 (po IP Karola, smoke). Refresh tokenu przez reload
 * strony lub iteracja w etapie 2/3.
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
          // Token HMAC zyje 5 min od zaladowania strony (BOOT). Etap 1 nie
          // refreshuje — instrukcja dla klienta.
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

  window.DivezoneChatTransport = {
    sendMessage: sendMessage,
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
