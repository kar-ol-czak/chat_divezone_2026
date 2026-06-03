# CHAT-T-066 — BACKEND: rate-limit per sessionId + per IP (token-bucket, PostgreSQL)

**Instancja:** backend (standalone, PHP 8.4). CC WDRAŻA SAM.
**Powiązane:** ADR-064 (rate limiting, kwestia J — token-bucket per visitor_id + wyższy próg per IP), ADR-082 (warstwa 3 ochrony publicznej), CHAT-T-064 (cap kosztów — warstwa 1, już PROD).
**Decyzje:** 167a (rate-limit przed ukrywaniem launchera), 168a (sessionId główny + IP bezpiecznik), 169a (liczniki w PostgreSQL), 170 (CF Rate Limiting niedostępne — plan ma 2 reguły, zajęte; całość w backendzie).

## Kontekst
Czat publiczny. Cap kosztów (T-064) chroni budżet GLOBALNIE, ale jeden napastnik może zżreć cały dzienny cap (10$) w kilka minut, blokując czat legalnym klientom do północy. Rate-limit per źródło rozkłada ochronę: łagodny limit per rozmowa (sessionId) + bezpiecznik per IP (łapie napastnika rotującego sessionId).

## Stan (zweryfikowane)
- sessionId = bin2hex(random_bytes(16)) — losowy, stabilny w rozmowie (front wysyła w body). Słabość: napastnik generuje nowe dowolnie → sam nie chroni przed zalewem.
- customerId = 0 dla wszystkich anonimów → bezużyteczny jako klucz.
- Request NIE ma odczytu IP. Backend za Apache/shared hosting (komentarz w Request.php о ModSecurity) → REMOTE_ADDR może być proxy. IP klienta z nagłówka, ale OSTROŻNIE (anty-spoofing).
- PostgreSQL (Railway) dostępny — liczniki tam.

## ZAKRES

### 1. Odczyt IP klienta (Request::getClientIp) — anty-spoofing
- Dodać metodę getClientIp(): ?string do Request.
- Źródło IP: hosting jest za proxy/Apache. Ustalić zaufane źródło:
  - Jeśli backend za Cloudflare (sprawdź czy przychodzi nagłówek CF-Connecting-IP w realnym requeście — CC zweryfikuje na PROD): użyć CF-Connecting-IP (CF go ustawia, nie da się sfałszować zza CF).
  - Jeśli NIE za CF: REMOTE_ADDR ($_SERVER['REMOTE_ADDR']) jako jedyne zaufane (X-Forwarded-For/X-Real-IP można sfałszować — NIE ufać bezkrytycznie nagłówkom od klienta).
  - KRYTYCZNE: NIE ufać X-Forwarded-For od klienta jako pierwszemu hopowi (spoofing → ominięcie limitu per IP). Użyć REMOTE_ADDR lub CF-Connecting-IP (jeśli potwierdzone że za CF). CC zdiagnozuje na PROD które IP jest realne i NIEspoofowalne, opisze w raporcie.
- Walidacja: filter_var($ip, FILTER_VALIDATE_IP). Brak/niepoprawne → null (wtedy limit per IP pomijany, zostaje per sessionId).

### 2. Token-bucket w PostgreSQL
- Migracja: tabela divechat_rate_limit (key TEXT, window_start TIMESTAMPTZ, count INT, PRIMARY KEY(key)). key = 'sess:{sessionId}' lub 'ip:{ip}'. Indeks na window_start (cleanup).
- Klasa RateLimiter (standalone/src/Usage/ obok CostGuard), DI PostgresConnection.
- Algorytm (sliding window licznikowy lub token-bucket — wybierz prostszy pewny; sliding window count wystarcza):
  - check(key, limit, windowSec): bool — atomowo: jeśli window_start starszy niż windowSec → reset (count=1, window_start=now); else count++; zwróć count <= limit.
  - Użyć UPSERT (INSERT ON CONFLICT DO UPDATE) atomowo, żeby równoległe requesty nie zgubiły inkrementu (jak race-safe alert w CostGuard T-064).
- Cleanup starych wpisów: opcjonalnie przy okazji (DELETE WHERE window_start < now - interval), albo zostawić (mała tabela). Nie blokować requestu cleanupem.

### 3. Wpięcie w ChatController (handle + stream), PO HMAC + PO cap (T-064), PRZED LLM
- Progi (ADR-064, .env konfigurowalne z defaultami):
  - per sessionId: DIVECHAT_RL_SESSION_MAX=10 wiad / DIVECHAT_RL_SESSION_WINDOW=300 s (5 min).
  - per IP: DIVECHAT_RL_IP_MAX=40 wiad / DIVECHAT_RL_IP_WINDOW=300 s (wyższy — łapie rotację sessionId, nie uderza w pojedynczego usera; ADR-064 mówi 25-50/dobę dla anonima ale to za mało dla aktywnej rozmowy — 40/5min rozsądniejszy start, do strojenia).
  - Sprawdź OBA: jeśli któryś przekroczony → odmowa.
- Reakcja po przekroczeniu (soft limit, ADR-064): NIE błąd 500. Zwróć grzeczny komunikat jako wiadomość bota (jak cap w T-064): "Wysłałeś wiele wiadomości w krótkim czasie. Odczekaj chwilę albo napisz na dive@divezone.pl / 56 307 03 03." handle: 200 {success:false, response:...}; stream: event done. Front pokaże jako wiadomość (transport onDone), nie błąd sieci.
- Kolejność w handle/stream: HMAC → cap kosztów (T-064) → limit inputu (T-064) → RATE-LIMIT (ten task) → chatService. Rate-limit PRZED LLM (cel: nie płacić za odrzucone).
- Liczyć request do limitu PRZED wywołaniem LLM (inkrement przy wejściu). History endpoint (GET /api/chat/history) — rozważyć osobno/pominąć (nie woła LLM, tani); skupić rate-limit na /api/chat + /api/chat/stream.

## Granice
- Tylko backend standalone. Bez modułu PS, bez Turnstile, bez zmian w cap kosztów (T-064 zostaje).
- IP: NIE ufać spoofowalnym nagłówkom (anty-spoofing krytyczny).
- Reakcja = grzeczny komunikat bota, nie błąd transportu.
- UPSERT atomowy (równoległe requesty).
- Progi w .env z defaultami.

## Kryteria akceptacji
1. Request::getClientIp() zwraca realne, NIEspoofowalne IP (CC potwierdza źródło na PROD: CF-Connecting-IP lub REMOTE_ADDR); niepoprawne → null, limit per IP pomijany.
2. >10 wiad/5min z jednego sessionId → grzeczny komunikat (nie błąd), bez wołania LLM.
3. >40 wiad/5min z jednego IP (np. rotacja sessionId) → odmowa per IP.
4. Liczniki atomowe (równoległe requesty nie gubią inkrementu — test współbieżny).
5. Reakcja jako wiadomość bota (front: onDone), nie błąd sieci. Stream: event done.
6. Kolejność: HMAC → cap → input → rate-limit → LLM. Rate-limit przed LLM.
7. Progi w .env (DIVECHAT_RL_SESSION_MAX/WINDOW, DIVECHAT_RL_IP_MAX/WINDOW) z defaultami 10/300, 40/300.
8. php -l clean; test PROD opisany (przekroczenie sessionId i IP). Anty-spoofing potwierdzony (X-Forwarded-For od klienta NIE omija limitu).
