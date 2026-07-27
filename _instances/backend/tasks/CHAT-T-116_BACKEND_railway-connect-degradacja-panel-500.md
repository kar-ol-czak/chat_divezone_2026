---
number: CHAT-T-116
title: Degradacja połączenia standalone→Railway — panel recenzji 500 (timeout 08006), cała apka wolna
status: READY-FOR-CC
architect: Claude Opus 4.8 (1M) — instancja frontend (eskalacja)
executor: instancja backend
date: 2026-06-29
adr: ADR-104 (odporność na zrywanie Railway), ADR-088 (.env/Config), ADR-089 (deploy STOP)
preconditions: [CHAT-T-107 DEPLOYED (circuit-breaker PostgresConnection)]
unlocks: [panel recenzji CHAT-T-105/113 stabilny, cała apka stabilna pod obciążeniem Railway]
---

## Cel
Usunąć (lub złagodzić) timeouty połączenia standalone (chat.divezone.pl) → Railway PostgreSQL, które wywalają `/api/*` na 500 i czynią panel recenzji nieużywalnym. Frontend (CHAT-T-113) zrobił już mitygację po stronie panelu (czytelny komunikat + mniej round-tripów) — to NIE wystarcza, bo problem jest na warstwie połączenia DB.

## Objaw (zgłoszenie Karola 2026-06-29)
Panel recenzji (zakładka Rozmowy, domyślny widok `nowy`): „Blad pobrania listy recenzji: Niepoprawna odpowiedz JSON". Źródło: backend zwraca **puste 500**, moduł nie umie sparsować pustego body.

## Diagnoza (frontend, real-path 2026-06-29)
- Błąd w logu standalone (`public/error_log`):
  `[PostgresConnection] Railway niedostepne (hard): SQLSTATE[08006] [7] timeout expired`
  → `makeUnavailable('hard')` → `DbUnavailableException` → puste 500. Wywala się **już na trywialnym `SELECT role FROM divechat_admin_roles`** w `requireAnyRole()`, PRZED logiką recenzji.
- **TCP connect z serwera do `switchback.proxy.rlwy.net:14368`: 1044–3116 ms** (3 próby; `fsockopen`). Z lokalnej maszyny ten sam endpoint: ~50 ms.
- Czasy żądań HTTP z serwera: `/api/health` 2.5–25 s (mimo 200); `/api/admin/review?status=nowy` → 500 po 30 s / 94 s / 120 s, sporadycznie 200 po 12–20 s. **Przerywane, ale silnie zdegradowane.**
- **Dotyczy całej aplikacji**, nie tylko recenzji (health też wolny). To nie jest bug konkretnego endpointu.
- **Endpoint Railway identyczny** w `.env` lokalnym i serwerowym (`switchback.proxy.rlwy.net:14368`) — to NIE stary/martwy adres.
- **Repo recenzji poprawne**: `ConversationReviewRepositoryTest` 35/35 + `ConversationReviewCountsTest` 13/13 real-path z lokalnej maszyny (Railway szybkie z mojej sieci). Deployed md5 == lokalny.

**Wniosek:** degradacja ścieżki sieciowej hosting (smarthost.pl) → Railway TCP proxy. Railway żyje (lokalnie szybkie), ale połączenie z serwera jest wolne/zrywane. CHAT-T-107 (circuit-breaker) działa zgodnie z projektem (fail-fast), ale underlying connect ciągle pada.

### POTWIERDZENIE: test RÓWNOLEGŁY local vs server (2026-06-29 18:58–18:59) — to HOSTING, NIE Railway
TCP connect do `switchback.proxy.rlwy.net:14368`, te same sekundy:
- **LOCAL (komp Karola):** 12/12 OK, 29–38 ms, ZERO degradacji w całym oknie.
- **SERVER (smarthost):** OK ~38 ms → potem 1059/1061/1088 ms → **2× FAIL „Connection timed out" 7094 ms**.
- W tych samych znacznikach czasu LOCAL=30 ms gdy SERVER=1000 ms+/timeout.

→ Railway sprawne i szybkie z innej sieci w tym samym momencie. **Problem jest po stronie hostingu smarthost.pl (egress/routing/throttling do Railway), NIE Railway, NIE kod aplikacji, NIE connection-pooling.** `08006 timeout expired` to skutek tego, że serwer nie potrafi otworzyć socketu TCP (warstwa PG nie może być szybsza niż TCP).

## Co już zrobione (frontend, CHAT-T-113, NIE ruszać — to nie fix infra)
- Moduł PS: czytelny komunikat „Baza chwilowo niedostępna (Railway) — odśwież panel" zamiast surowego błędu JSON (5xx/timeout/puste). Commit `cd4c152`, na PROD.
- Repo: mniej round-tripów do Railway — `COUNT(*) OVER()` w listach (2→1 zapytanie) + `countsByStatus` w 1 zapytaniu (2→1). Widok `nowy` z ~5 do ~3 zapytań/request. Pomaga gdy połączenie wolne, NIE gdy zrywa na connectcie.

## Kierunki do rozważenia

### PRIORYTET 0 — HOSTING (to jest root cause, poza kodem)
0. **Zgłoszenie do smarthost.pl** z dowodem z testu równoległego: „serwer nie potrafi stabilnie otworzyć TCP do `switchback.proxy.rlwy.net:14368` (1–7 s, timeouty), podczas gdy z innej sieci ten sam endpoint odpowiada w 30 ms w tym samym czasie". Pytania: throttling/limit połączeń wychodzących? routing/MTU do regionu Railway? firewall/conntrack? Możliwy traceroute/mtr z serwera do endpointu Railway w załączniku. **To naprawia problem u źródła — reszta poniżej to tylko łagodzenie skutków.**

### Łagodzenie w kodzie (band-aid, NIE usuwa przyczyny)
1. **Reuse połączenia / persistent.** Zweryfikować, czy `PostgresConnection` (singleton per-request) otwiera 1 połączenie i reużywa je dla wszystkich zapytań requestu — czy `executeWithRetry` przy zerwaniu NIE reconnectuje wielokrotnie (1–3 s connect każdorazowo → stackuje do 30–120 s). Rozważyć `PDO::ATTR_PERSISTENT` (UWAGA: limity połączeń Railway) lub świadome ograniczenie reconnectów.
2. **connect_timeout / statement_timeout.** Sprawdzić aktualne wartości w DSN/PDO. 30 s connect timeout × retry = 90–120 s (widziane). Krótszy connect_timeout + szybszy fail + czytelny 503 zamiast wiszenia.
3. **Backoff/retry w `executeWithRetry`.** Czy retry nie pogarsza (każda próba = nowy wolny connect)? Może mniej prób, szybszy hard-fail → 503 z JSON `{error:...}` (NIE puste body — moduł i tak teraz to obsłuży, ale czysty JSON 503 lepszy).
4. **503 z poprawnym JSON zamiast pustego 500.** `DbUnavailableException` powinien dawać `Response::json(['error'=>'DB unavailable'], 503)`, nie pusty fatal. Wtedy każdy klient (nie tylko panel) dostaje sensowną odpowiedź.
5. **Railway endpoint/transport.** Czy jest stabilniejszy endpoint (private networking / inny proxy / IPv4 vs IPv6)? Czy Railway nie throttluje IP hostingu? Ewentualnie monitoring connect-latency (CHAT-T-108 monitor — pending).
6. **CHAT-T-108 (monitor)** — domknąć: alert gdy connect-latency serwer→Railway > próg, żeby wyłapać degradację zanim zgłosi Karol.

## Acceptance (proponowane)
- [ ] `/api/admin/review?status=nowy` i `/api/health` stabilne (<2 s, brak 500) w 10/10 próbach z serwera, albo przy degradacji Railway → **503 z JSON** (nie puste 500, nie 30–120 s wiszenia).
- [ ] Round-trip/connect handling udokumentowany (ile connectów na request, timeouty).
- [ ] Deploy wg ADR-089 (STOP przed rsync). Real-path wg ADR-088.

## Notatki
- Pełny materiał diagnostyczny + komendy: sesja frontend CHAT-T-113 (2026-06-29). Token HMAC do ręcznego curla: `hash_hmac('sha256', "<empId>:<ts>", DIVECHAT_SERVER_SECRET)` + nagłówki `X-DiveChat-Server-Token/Employee/Time`; employee z rolą np. 2 (admin).
- To NIE blokuje innych prac, ale panel recenzji jest praktycznie nieużywalny dopóki Railway-connect jest zdegradowany.
