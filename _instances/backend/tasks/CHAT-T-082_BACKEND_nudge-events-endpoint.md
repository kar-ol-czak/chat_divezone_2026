# CHAT-T-082 — BACKEND: tabela divechat_nudge_events + endpoint /api/widget/event + akceptacja client-supplied sessionId

**Instancja:** backend
**Powiązane:** ADR-090 (faza 2), `_docs/22_spec_ab_ctr_nudge.md` (PEŁNY kontrakt — przeczytaj), ADR-089 (deploy rsync standalone), ADR-087 (cache-safe), decyzje 246a/247a/248a
**Status:** DO ZROBIENIA (faza 2, krok 1/3; przed CHAT-T-083 frontend i CHAT-T-084 panel)
**Warunek startu:** v2 zaakceptowany wizualnie na PROD.

---

## CEL
Backend telemetrii CTR: tabela zdarzeń w Railway PG + publiczny lekki endpoint przyjmujący beacony + akceptacja sessionId generowanego przez front (247a).

## ZAKRES
1. Migracja SQL: tabela `divechat_nudge_events` (Railway PG).
2. `src/Usage/NudgeEventStore.php` (lub `src/Chat/` — wybierz spójnie z istniejącą strukturą; wzór: lekki store na `PostgresConnection::getInstance()`).
3. `src/Controller/WidgetEventController.php` — handler `POST /api/widget/event`.
4. `config/routes.php` — rejestracja trasy.
5. Akceptacja client-supplied sessionId w `ConversationStore::resumeOrCreate` (247a) z zachowaniem modelu własności (punkt bezpieczeństwa, sekcja 3 spec).

## SZCZEGÓŁY

### Tabela (sekcja 4 spec)
Kolumny: `id BIGSERIAL PK`, `session_id TEXT NOT NULL`, `event_type TEXT NOT NULL CHECK (event_type IN ('nudge_shown','nudge_cta_click'))`, `bucket TEXT NOT NULL CHECK (bucket IN ('v1','v2'))`, `ab_active BOOLEAN NOT NULL`, `created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()`.
- UNIQUE `(session_id, event_type)` — dedup (jedno shown + jeden click per sesja).
- Index `(bucket, event_type, created_at)` + index `(session_id)`.
- Migracja w `sql/` z kolejnym numerem; wykonanie na Railway (NIE Aiven).

### Endpoint (sekcja 5 spec)
`POST /api/widget/event`, publiczny (BEZ admina, BEZ HMAC klienckiego, BEZ CostGuard).
- Walidacja whitelist: `event_type`∈{nudge_shown,nudge_cta_click}, `bucket`∈{v1,v2}, `session_id` = format UUID v4, `ab_active` = bool. Cokolwiek poza → 204 i cichy no-op (nie 4xx — beacon i tak ignoruje; nie chcemy logować szumu jako błędów).
- RateLimiter per IP (reuse istniejącego z `src/Usage/RateLimiter.php`, luźny limit — to nie LLM). Przekroczenie → 204 no-op.
- Zapis przez NudgeEventStore z `ON CONFLICT (session_id, event_type) DO NOTHING`.
- Odpowiedź zawsze `204 No Content`.
- CORS: endpoint woła front widgetu cross-origin (sklep→chat.divezone.pl) — upewnij się, że nagłówki CORS pozwalają na POST z origin sklepu (sprawdź jak robi to /api/chat/stream; sendBeacon to "simple request" gdy Content-Type text/plain lub application/x-www-form-urlencoded — uwzględnij że beacon może przyjść jako text/plain blob, parsuj body tolerancyjnie: JSON.parse z body niezależnie od Content-Type).

### Client-supplied sessionId (sekcja 3 spec — KRYTYCZNE)
Dziś `resumeOrCreate(sessionId)` przyjmuje sessionId, ale realnie front startuje z null i backend tworzy ID. Teraz front poda UUID z góry. Zasada własności:
- sessionId istnieje w `divechat_conversations` + należy do tego customerId (HMAC) → resume (jak dziś).
- sessionId NIE istnieje → INSERT z tym sessionId + customerId z HMAC (NOWE: akceptujemy podany ID, nie generujemy własnego).
- sessionId istnieje, ale należy do INNEGO customerId → NIE resume, NIE nadpisuj; potraktuj jak nową sesję (zachowanie spójne z {exists:false} w /api/chat/history). Zapobiega podszyciu.
- Walidacja formatu sessionId (UUID) po stronie backendu zanim trafi do SQL.
- To zmiana w ścieżce `/api/chat/stream` (ChatController) + ewentualnie ConversationStore. NIE psuj istniejącej persystencji (CHAT-T-059) ani weryfikacji właściciela (CHAT-T-046).

## KRYTERIA AKCEPTACJI
1. Migracja przechodzi na Railway; tabela + constrainty + indeksy istnieją.
2. `POST /api/widget/event` z poprawnym body → 204, wiersz w tabeli; powtórka tego samego (session_id, event_type) → 204, BEZ duplikatu.
3. Body niepoprawne / rate-limit → 204 no-op, zero wpisu, zero błędu w logu.
4. Beacon cross-origin z origin sklepu przechodzi (CORS OK).
5. Front podający nowy UUID jako sessionId → backend tworzy rozmowę z TYM ID; podanie cudzego sessionId → nowa sesja, brak dostępu do cudzej rozmowy.
6. Regresja zero: istniejący czat, persystencja sesji (CHAT-T-059), historia (/api/chat/history) działają jak dziś.
7. `php -l` clean; testy (jeśli dopisujesz) zielone.

## DEPLOY (ADR-089 — standalone, CC wdraża SAM po STOP-point)
1. KROK DEPLOY: backup zmienianych plików → `_deploy_bak/CHAT-T-082/`.
2. Rsync per ścieżka (port 5739, user divezone, klucz id_ed25519, BEZ --delete, BEZ .env/vendor).
3. Migracja na Railway (osobny, jawny krok — pokaż SQL Karolowi przed wykonaniem).
4. Weryfikacja: md5 match repo↔serwer, `php -l` ea-php84, smoke `curl /api/health` 200, smoke `POST /api/widget/event` → 204 + wiersz.
5. STOP-point: pokaż komendy rsync + SQL migracji Karolowi, czekaj na zgodę PRZED wykonaniem.

## GIT
`git add` per ścieżka (migracja SQL, NudgeEventStore, WidgetEventController, routes.php, ewentualnie ChatController/ConversationStore). Commit wg konwencji z `git log`. Push origin main. Po deploy osobny commit `docs:` ze statusem.
