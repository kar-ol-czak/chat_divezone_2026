# CHAT-T-086 — FRONTEND+BACKEND: zdarzenie nudge_dismiss (kliknięcie X / zamknięcie zachęty)

**Instancja:** backend (migracja CHECK) + frontend (beacon w loaderze v1/v2)
**Powiązane:** ADR-090 faza 2, `_docs/22_spec_ab_ctr_nudge.md`, CHAT-T-082 (tabela + endpoint /api/widget/event), CHAT-T-083 (sendNudgeEvent, sticky bucket/sid). Decyzje 256b, 257a.
**Status:** DO ZROBIENIA (faza 2, dokładka pomiaru). Idzie RAZEM z CHAT-T-084 (panel) — jedno wdrożenie modułu PS na końcu obu.

---

## CEL
Dodać trzecie zdarzenie ekspozycji: `nudge_dismiss` (klik X / zamknięcie zachęty). Dziś klik X tylko chowa dymek + flaga sessionStorage, NIE wysyła beacona. Bez tego nie odróżniamy "świadomie odrzucił" (X) od "zignorował" (brak reakcji). Cała maszyneria istnieje (endpoint, tabela, sendNudgeEvent) — to tania dokładka, nie nowy projekt.

## KONTEKST (z analizy kodu — nie szukaj od nowa)
- `widget-loader.js`: klik X w v1 (~linia 527-532) i v2 (~linia 672-676) robi tylko `e.stopPropagation()` + `ssSet('dz_nudge_dismissed','1')` + `hideNudge()`. Brak beacona.
- `sendNudgeEvent(type)` (~linia 422) już istnieje (CHAT-T-083): wysyła Blob application/x-www-form-urlencoded do /api/widget/event, używa `nudgeShownSid`/`nudgeBucket`/`nudgeAbActive`. Guard: `if (!nudgeShownSid || !nudgeBucket) return;`.
- Tabela `divechat_nudge_events`: CHECK `divechat_nudge_events_event_type_chk` = `event_type IN ('nudge_shown','nudge_cta_click')`. UNIQUE `(session_id, event_type)`.
- Backend WidgetEventController (CHAT-T-082): waliduje event_type whitelist — TRZEBA dodać 'nudge_dismiss' do dozwolonych.

## ZAKRES

### CZĘŚĆ A — Migracja (Railway PG)
`sql/027_nudge_dismiss_event.sql` (+ rollback):
- DROP + ADD CHECK (nie da się ALTER CHECK in-place):
  ```sql
  ALTER TABLE divechat_nudge_events DROP CONSTRAINT IF EXISTS divechat_nudge_events_event_type_chk;
  ALTER TABLE divechat_nudge_events ADD CONSTRAINT divechat_nudge_events_event_type_chk
      CHECK (event_type IN ('nudge_shown', 'nudge_cta_click', 'nudge_dismiss'));
  ```
- UNIQUE `(session_id, event_type)` ZOSTAJE bez zmian — dismiss to trzeci typ, dedup "jeden dismiss per sesja" działa automatycznie.
- Rollback: przywróć CHECK do dwóch wartości (uwaga: jeśli są już wiersze nudge_dismiss, rollback CHECK się wywali — rollback ma najpierw DELETE WHERE event_type='nudge_dismiss', z komentarzem ostrzegawczym).

### CZĘŚĆ B — Backend (WidgetEventController, CHAT-T-082)
- Dodaj `'nudge_dismiss'` do whitelisty dozwolonych `event_type` (tam gdzie dziś walidacja nudge_shown/nudge_cta_click). Reszta logiki (RateLimiter, ON CONFLICT DO NOTHING, 204) bez zmian — dismiss przechodzi tą samą ścieżką.

### CZĘŚĆ C — Frontend (widget-loader.js, v1 + v2)
- W handlerze klik X dla OBU wariantów (v1 ~527, v2 ~672): PRZED `hideNudge()` dodaj `sendNudgeEvent('nudge_dismiss');`.
- KOLEJNOŚĆ: sendNudgeEvent przed hideNudge (jak nudge_cta_click w openChatFlow) — fire-and-forget, beacon nie blokuje zamknięcia.
- Guard w sendNudgeEvent (`!nudgeShownSid` → return) już chroni: jeśli X kliknięty bez wcześniejszego renderu (nie powinno się zdarzyć, ale defensywa) → no-op.
- NIE ruszaj reszty handlera X (stopPropagation, dz_nudge_dismissed flag, hideNudge zostają).

## KRYTERIA AKCEPTACJI
1. Migracja: CHECK akceptuje 3 wartości; insert nudge_dismiss przechodzi, śmieciowy event_type dalej odrzucany.
2. Klik X (v1 i v2) → POST /api/widget/event z event_type=nudge_dismiss, bucket + ab_active jak przy shown, ten sam nudgeShownSid (session_id). 204, wiersz w tabeli.
3. Dedup: drugi X w tej samej sesji (nie zdarzy się — dymek znika po pierwszym, ale defensywnie) → ON CONFLICT, brak duplikatu.
4. Klik X NADAL zamyka dymek i ustawia dz_nudge_dismissed (regresja zero — zachowanie UX bez zmian, tylko dochodzi beacon).
5. Klik CTA i ekspozycja działają jak dotąd (nudge_cta_click, nudge_shown nietknięte).
6. node --check + php -l clean.

## DEPLOY — SKOORDYNOWANY z CHAT-T-084
- Migracja 027 + backend whitelist (standalone): ADR-089, CC wdraża sam (backup, rsync, smoke, STOP).
- Frontend loader (widget-loader.js): moduł PS, 116b. **WAŻNE: to ten sam plik/wdrożenie co ewentualne zmiany z innych tasków — koordynuj. Jeśli CHAT-T-084 nie dotyka loadera (dotyka tylko AdminDivezoneChatController.php), to dwa różne pliki modułu — można wgrać razem jedną komendą rsync obejmującą oba.**
- Kolejność: backend (migracja+whitelist) PRZED frontem (loader wysyła nudge_dismiss). Gdyby front wyprzedził backend → event_type odrzucony whitelistą, beacon = no-op (bezpieczne, tylko gubi dane do czasu backendu).

## GIT
`git add` per ścieżka (migracja 027, WidgetEventController, widget-loader.js). Commit wg konwencji git log. Push origin main. Osobny commit docs: status.

## UWAGA — ADR
Ten task NIE tworzy ADR (mieści się w ADR-090 faza 2 — rozszerzenie pomiaru, nie nowa decyzja architektoniczna). Gdyby zaszła potrzeba — sprawdź ostatni wolny numer (konwencja CLAUDE.md; ostatni ADR-092).
