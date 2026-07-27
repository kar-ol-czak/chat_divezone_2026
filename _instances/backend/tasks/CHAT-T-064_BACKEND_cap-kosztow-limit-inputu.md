# CHAT-T-064 — BACKEND: dzienny cap kosztów + alert + limit długości inputu (ochrona publiczna)

**Instancja:** backend (standalone, PHP 8.4). CC WDRAŻA SAM.
**Powiązane:** ADR-064 (ochrona kosztów — rate limiting i koszty, kwestia J), CostAnalytics, ChatService, ChatController.
**Decyzje:** 159a (najpierw cap+input, rate-limit/Turnstile osobno), 160 (twardy stop + alert), 161 (cap 10$/dobę, alert 5$/dobę), 163a/164a (tylko backend; ukrywanie launchera = osobny CHAT-T-065), 165b (alert k.susicki@divezone.pl), 166 (php mail()).

## Kontekst / PILNE
Czat jest PUBLICZNIE dostępny, a backend NIE ma ŻADNEJ ochrony kosztów (zero rate-limit, zero capa, zero limitu inputu — zweryfikowane). Anonimowy endpoint LLM = otwarte ryzyko palenia budżetu API. Ten task to PIERWSZA TAMA: twardy dzienny cap kosztów + limit długości inputu. Czysto serwerowe, zero zależności zewnętrznych (bez Cloudflare/Turnstile — to późniejsze warstwy).

## Źródło danych (zweryfikowane)
- Koszty: tabela `divechat_message_usage`, pole `cost_total_usd`, indeks idx_usage_created_model. Koszt dnia: `SELECT COALESCE(SUM(cost_total_usd),0) FROM divechat_message_usage WHERE created_at >= CURRENT_DATE`. Ten sam wzorzec co CostAnalytics::aggregateRange — JEDNO źródło prawdy o kosztach.
- Punkt wpięcia: ChatController::handle (linia ~50, PO weryfikacji HMAC) i ChatController::stream (analogicznie ~108) — PRZED wywołaniem chatService->handle().

## ZAKRES

### 1. Dzienny cap kosztów (160, 161)
- Próg twardy: 10.0 USD/dobę (stała/config — patrz niżej). Próg alertu: 5.0 USD/dobę.
- Nowa lekka klasa, np. CostGuard (standalone/src/Usage/ lub Chat/), DI: PostgresConnection.
- Metoda dailyCostUsd(): float — suma cost_total_usd od CURRENT_DATE (1 zapytanie, indeks pokrywa).
- W ChatController (handle + stream), PO HMAC, PRZED chatService:
  - $spent = costGuard->dailyCostUsd();
  - Jeśli $spent >= 10.0 (HARD_CAP) → NIE wołaj LLM. Zwróć komunikat (NIE błąd 500): np. 200/503 z {success:false, response:"Czat jest chwilowo niedostępny. Napisz na dive@divezone.pl lub zadzwoń 56 307 03 03 — chętnie pomożemy."} — uzgodnić kod HTTP tak, by FRONT pokazał to jako wiadomość bota, nie jako błąd transportu (sprawdź jak transport.js traktuje onError vs onDone; cel: user widzi grzeczny komunikat, nie "błąd sieci"). Dla stream: wyemituj event 'done' z taką odpowiedzią ALBO 'status'+'done', bez wołania modelu.
  - Cap sprawdzany PRZED wołaniem LLM (koszt bieżącej rozmowy jeszcze niezaksięgowany — akceptowalne, drobne przekroczenie o 1 rozmowę przy progu 10$ nieszkodliwe).

### 2. Alert e-mail przy 5 USD (161, 165b, 166)
- Gdy $spent przekroczy 5.0 USD (ALERT_THRESHOLD) — wyślij alert na k.susicki@divezone.pl przez php mail().
- ANTI-SPAM: alert MAX RAZ na dobę (nie przy każdym requeście po przekroczeniu). Mechanizm: flaga w tabeli (np. divechat_cost_alerts: date PK, sent_at) lub prosta tabela/wiersz "ostatni alert" — sprawdź czy alert dla CURRENT_DATE już wysłany; jeśli nie → wyślij + zapisz. Idempotentne.
- Treść maila: temat "[DiveChat] Dzienny koszt przekroczył 5 USD", body: aktualny koszt dnia, próg twardy 10$, data, link do panelu analityki. Plain text wystarczy.
- mail() może nie być skonfigurowany na hostingu — jeśli mail() zwróci false, NIE wywalaj requestu: zaloguj (error_log) że alert się nie wysłał, czat działa dalej. Alert to dodatek, nie bloker.

### 3. Limit długości inputu
- W ChatController (handle + stream), walidacja body['message']: jeśli mb_strlen > MAX_INPUT_CHARS (2000, ADR-064 sugeruje 2-4k — start 2000, do strojenia) → Response::error('Wiadomość jest za długa (max 2000 znaków). Skróć pytanie lub napisz na dive@divezone.pl.', 400). Przed wołaniem LLM.

### Konfiguracja progów
- Progi (HARD_CAP=10, ALERT=5, MAX_INPUT=2000, ALERT_EMAIL=k.susicki@divezone.pl) jako stałe w klasie LUB Config::get z .env (preferowane .env, by zmieniać bez deployu: DIVECHAT_DAILY_CAP_USD, DIVECHAT_COST_ALERT_USD, DIVECHAT_MAX_INPUT_CHARS, DIVECHAT_COST_ALERT_EMAIL). Jeśli .env — z sensownymi defaultami gdy brak klucza.

## Granice
- Tylko backend standalone. Bez modułu PS (ukrywanie launchera = CHAT-T-065). Bez rate-limit per-visitor (osobny task). Bez Turnstile.
- Cap czyta divechat_message_usage (to samo co CostAnalytics) — NIE twórz drugiego licznika kosztów.
- mail() fail → graceful (log), nie blokuj czatu.
- Cap/limit PRZED wołaniem LLM (oszczędność to cały sens).

## Kryteria akceptacji
1. Gdy dzienny koszt >= 10 USD: /api/chat i /api/chat/stream NIE wołają LLM, zwracają grzeczny komunikat z kontaktem (front pokazuje jako wiadomość, nie błąd sieci).
2. Gdy dzienny koszt przekroczy 5 USD: alert e-mail na k.susicki@divezone.pl, MAX raz/dobę (idempotentne).
3. mail() niedostępny → log, czat działa dalej (alert nie blokuje).
4. message > 2000 znaków → 400 z komunikatem, bez wołania LLM.
5. Cap czyta divechat_message_usage (CURRENT_DATE), spójnie z CostAnalytics. Jedno źródło.
6. Progi konfigurowalne (.env z defaultami) — zmiana capa bez deployu.
7. php -l clean; test PROD: symuluj/zweryfikuj cap (np. tymczasowo niski próg → potwierdź blokadę + komunikat), input >2000 → 400. Opisz w raporcie.
