# CHAT-T-085 — BACKEND+FRONTEND: fix korelacji konwersji nudge (nudge_sid jako osobna atrybucja)

**Instancja:** backend (migracja + 3 warstwy PHP) + frontend (moduł PS, osobne state.nudgeSid)
**Powiązane:** ADR-091 (decyzja + pełna diagnoza), ADR-090 faza 2, CHAT-T-083 (bug), CHAT-T-059 (persystencja = źródło konfliktu), CHAT-T-082 (client-supplied sid), `_docs/22_spec_ab_ctr_nudge.md`. Decyzje 253a/254a.
**Status:** DO ZROBIENIA (blocker dla kolumny konwersji w CHAT-T-084)

---

## DIAGNOZA (już wykonana — NIE odkrywaj od nowa)

Korelacja konwersji jest zepsuta. Root cause (potwierdzony empirycznie, smoke 2026-06-08): przy mount okna czatu w widget-bundle.js front ustawia `state.sessionId = BOOT.nudge.pendingSessionId` (sid z nudge), a ZARAZ POTEM `tryRestoreSession()` (CHAT-T-059) nadpisuje go starym sid z localStorage gdy backend zna tę rozmowę (`exists:true`). Pierwsza wiadomość leci pod stary sid rozmowy, beacony nudge mają nowy sid → rozjazd. Każda warstwa z osobna jest poprawna — to konflikt persystencji (CHAT-T-059) z pomiarem (CHAT-T-083).

**Rozwiązanie (ADR-091, 253a/254a):** rozdzielić `session_id` (rozmowa, zmienny) od `nudge_sid` (atrybucja, stały). Front trzyma nudge_sid w OSOBNYM polu state, dosyła w body pierwszej wiadomości. Backend zapisuje nudge_sid w `divechat_conversations.nudge_sid` przy tworzeniu rozmowy. Konwersja = JOIN events.session_id ↔ conversations.nudge_sid.

## ZAKRES

### CZĘŚĆ A — Migracja (Railway PG)
`sql/026_conversations_nudge_sid.sql` (+ rollback):
- `ALTER TABLE divechat_conversations ADD COLUMN nudge_sid VARCHAR(64) DEFAULT NULL;`
- Index `CREATE INDEX IF NOT EXISTS idx_conversations_nudge_sid ON divechat_conversations (nudge_sid) WHERE nudge_sid IS NOT NULL;` (partial — większość rozmów nie ma atrybucji).
- Komentarz kolumny: atrybucja źródła (sid ekspozycji nudge, JOIN z divechat_nudge_events). Stare rozmowy = NULL (brak atrybucji wstecz, świadome ADR-091).

### CZĘŚĆ B — Backend (przepływ nudge_sid przez 3 warstwy)
1. **ChatController** (`stream()` ~linia 314-318 ORAZ `handle()` ~linia 229-233): odczytaj `$nudgeSid = $body['nudge_sid'] ?? null;`. Waliduj formatem TAK SAMO jak session_id (`isValidSessionIdFormat` — UUID v4 lub legacy 32-hex); niepoprawny → null (nie generuj, atrybucja jest opcjonalna). Przekaż do ChatService::handle.
2. **ChatService::handle** (~linia 42, sygnatura): dodaj opcjonalny param `?string $nudgeSid = null`. Przekaż do `startOrResume`.
3. **ConversationStore::startOrResume** (~linia 47, sygnatura): dodaj opcjonalny param `?string $nudgeSid = null`. Zapisz TYLKO przy INSERT nowej rozmowy (gałąź "sessionId nie istnieje"). Przy resume istniejącej NIE nadpisuj (atrybucja należy do momentu powstania rozmowy). Przy ownership mismatch (nowy effectiveSessionId) — to też nowa rozmowa, więc nudge_sid zapisz.
   - UWAGA: INSERT rozmowy jest w startOrResume (gałąź bez wiersza) — dorzuć kolumnę nudge_sid do tego INSERT. Znajdź dokładny INSERT (czytaj resztę metody, ~linia 75+).

### CZĘŚĆ C — Frontend (moduł PS — osobne state.nudgeSid)
1. **widget-bundle.js** (~linia 1016-1019, mount): obok skopiowania pending do state.sessionId, ZAPISZ nudge_sid w OSOBNYM, trwałym polu:
   ```
   if (state.boot && state.boot.nudge && state.boot.nudge.pendingSessionId) {
     state.sessionId = state.boot.nudge.pendingSessionId;
     state.nudgeSid  = state.boot.nudge.pendingSessionId;  // NOWE: osobna atrybucja, NIE czyszczona przez restore
     state.boot.nudge.pendingSessionId = null;
   }
   ```
   - `state.nudgeSid` dodaj do inicjalizacji state (obok sessionId, ~linia 74). Default null.
   - KRYTYCZNE: tryRestoreSession (~linia 220+) nadpisuje state.sessionId — NIE może dotykać state.nudgeSid. nudge_sid przeżywa restore.
2. **widget-bundle.js** (sendUserMessage, ~linia 563): przekaż nudge_sid do transportu. `transport.sendMessage(text, state.sessionId, state.nudgeSid, {...})` (rozszerz sygnaturę) LUB przekaż przez obiekt opcji — wybierz spójnie, ale nudge_sid musi dotrzeć do body requestu.
3. **transport.js** (sendMessage, ~linia 122-133): przyjmij nudge_sid, dołóż do body: `if (nudgeSid) body.nudge_sid = nudgeSid;` obok istniejącego `if (sessionId) body.session_id = sessionId;`.
4. **onDone** (~linia 572): backend zwraca session_id (efektywny, może być inny). NIE ruszaj state.nudgeSid w onDone — atrybucja jest jednorazowa, wysłana przy pierwszej wiadomości. Po pierwszej wiadomości nudge_sid można wyzerować (opcjonalnie — kolejne wiadomości tej rozmowy nie potrzebują atrybucji; rozmowa już ma nudge_sid w bazie).

## KRYTERIA AKCEPTACJI
1. Migracja przechodzi; kolumna `nudge_sid` + partial index istnieją na Railway.
2. Smoke korelacji: pokaż nudge → kliknij → wyślij wiadomość. Potem `verify_nudge_correlation.php`: klik z dopasowaną rozmową ≥1/1 (sekcja 2 ma_rozmowe=TAK) — ALE uwaga: skrypt JOIN-uje po session_id; PO tym fixie konwersję liczy się po nudge_sid. ZAKTUALIZUJ skrypt: sekcja 2 i 3 mają JOIN-ować events.session_id ↔ conversations.nudge_sid (nie c.session_id). Po zmianie: klik z dopasowaną rozmową = TAK dla świeżego smoke'u.
3. Powracający użytkownik z rozmową w localStorage: wchodzi przez nudge, klika, pisze → historia starej rozmowy zachowana (CHAT-T-059 działa, restore wygrywa dla session_id), JEDNOCZEŚNIE nudge_sid zapisany w nowej/wznowionej rozmowie → konwersja policzalna. Oba wymagania spełnione naraz.
4. Klient BEZ ekspozycji nudge (wszedł launcherem): nudge_sid = null w body, rozmowa.nudge_sid = NULL. Zero regresji.
5. Stare rozmowy: nudge_sid = NULL (brak atrybucji wstecz — OK).
6. Regresja zero: czat, persystencja, ownership check, rate-limit, cost guard — bez zmian.
7. php -l + node --check clean. Testy ConversationStore (jeśli są) zielone.

## DEPLOY
- Backend (standalone) + migracja: ADR-089 (backup → _deploy_bak/CHAT-T-085/, rsync per ścieżka, migracja osobnym krokiem z SQL pokazanym Karolowi, smoke /api/health + verify_nudge_correlation.php, STOP-point).
- Moduł PS (widget-bundle.js, transport.js): 116b — Karol wgrywa ręcznie, CC podaje rsync (port 5739, ~/public_html/newtmp2, --exclude config_pl.xml, bez --delete).
- DWA STOP-pointy (standalone deploy osobno, moduł PS osobno).

## GIT
`git add` per ścieżka (migracja, ChatController, ChatService, ConversationStore, widget-bundle.js, transport.js, zaktualizowany verify_nudge_correlation.php). Commit wg konwencji `git log`. Push origin main. Po deploy osobny commit `docs:` status.
