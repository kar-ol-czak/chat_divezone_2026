# CHAT-T-083 — FRONTEND: bucket A/B, sessionId-at-shown, beacony zdarzeń nudge

**Instancja:** frontend
**Powiązane:** ADR-090 (faza 2), `_docs/22_spec_ab_ctr_nudge.md` (PEŁNY kontrakt), CHAT-T-081 (variant DONE), CHAT-T-082 (endpoint + tabela — MUSI być wdrożony przed tym taskiem), decyzje 241a/247a/248a, ADR-087 (cache-safe)
**Status:** DO ZROBIENIA (faza 2, krok 2/3; PO CHAT-T-082)
**Warunek startu:** CHAT-T-082 na PROD (endpoint /api/widget/event odpowiada 204, akceptuje client-supplied sessionId).

---

## CEL
Front: przydział bucketa A/B (sticky client-side), generowanie sessionId w momencie pokazania nudge, wysyłka zdarzeń `nudge_shown`/`nudge_cta_click` beaconem. Zawsze (oba warianty, niezależnie od A/B — 248a).

## ZAKRES
1. `modules/divezone_chat/views/js/widget-loader.js` — bucket, sessionId-at-shown, `sendNudgeEvent()`, wpięcie w render + openChatFlow.
2. `modules/divezone_chat/views/js/widget-bundle.js` — użyj sessionId przekazanego z loadera zamiast czekać na backend (graceful fallback).
3. `modules/divezone_chat/divezone_chat.php` — klucz `DIVEZONE_CHAT_NUDGE_AB` (lazy default OFF), checkbox w panelu sekcja 5, pola `ab` + `eventPath` w `$boot['nudge']`.

## SZCZEGÓŁY (sekcja 6 spec)

### Bucket A/B (241a)
- W `setupNudge()`: `var ab = !!(BOOT.nudge && BOOT.nudge.ab);`
- Jeśli `ab`: odczytaj `dz_ab_bucket` z localStorage; brak → wylosuj 50/50 (`Math.random() < 0.5 ? 'v1' : 'v2'`), zapisz. Bucket NADPISUJE variant użyty do renderu.
- Jeśli `!ab`: bucket = aktualny variant z panelu (`BOOT.nudge.variant`).
- Bucket wybiera funkcję renderu: `v2` → `renderNudgeCard`, inaczej `renderNudge` (rozgałęzienie z CHAT-T-081, teraz sterowane bucketem nie samym variantem).

### sessionId-at-shown (247a — KRYTYCZNE)
- W momencie FAKTYCZNEGO renderu nudge (w `renderNudge`/`renderNudgeCard`, po przejściu guardów): wygeneruj `var sid = (crypto && crypto.randomUUID) ? crypto.randomUUID() : <fallback UUID v4 ręczny>;`
- Zapisz sid w miejscu dostępnym dla bundla. Rekomendacja: `BOOT.nudge.pendingSessionId = sid` (bundle czyta BOOT). NIE koliduje z cache (to runtime mutacja obiektu JS, nie HTML).
- To samo sid trafia do `nudge_shown` i (jeśli klik) do `nudge_cta_click`.

### Beacony
- Funkcja `sendNudgeEvent(type)`:
  - body = `JSON.stringify({session_id: sid, event_type: type, bucket: bucket, ab_active: ab})`
  - `navigator.sendBeacon(BOOT.backendUrl + (BOOT.nudge.eventPath || '/api/widget/event'), new Blob([body], {type:'text/plain'}))`
  - fallback gdy brak sendBeacon: `fetch(url, {method:'POST', body: body, keepalive:true, mode:'cors', headers:{'Content-Type':'text/plain'}})` (text/plain = simple request, omija preflight; backend parsuje JSON tolerancyjnie — CHAT-T-082).
  - try/catch, fire-and-forget, ZERO wpływu na UX (błąd beacona nie może blokować otwarcia czatu).
- `nudge_shown`: wywołaj raz, w renderze (po wygenerowaniu sid).
- `nudge_cta_click`: wywołaj w `openChatFlow()` ZANIM załaduje bundle (lub równolegle), tylko jeśli nudge był pokazany (jest pending sid). Klik launchera bez pokazanego nudge NIE wysyła click (to nie jest klik w zachętę).
- Guard: nie wysyłaj `nudge_shown` dwa razy (ten sam mechanizm raz-na-sesję co dziś).

### Bundle — użycie sessionId z loadera
- Przy starcie rozmowy: jeśli `BOOT.nudge.pendingSessionId` istnieje i `state.sessionId` jest null → użyj pending jako `state.sessionId` (i persystuj jak dziś po pierwszej odpowiedzi).
- Graceful: brak pending → stare zachowanie (backend tworzy ID). NIE psuj tryRestoreSession (CHAT-T-059) — restore z localStorage ma pierwszeństwo nad pending (istniejąca rozmowa > nowa ekspozycja).

### Panel (php)
- `const KEY_NUDGE_AB = 'DIVEZONE_CHAT_NUDGE_AB';` lazy default '0'.
- Checkbox w sekcji 5: "Test A/B v1 vs v2 (50/50)" + `<small>`: gdy włączony, wariant losowany per przeglądarka, przełącznik wyglądu wyżej jest ignorowany; pomiar CTR działa zawsze.
- Zapis w submit. W hooku: `'ab' => (bool), 'eventPath' => '/api/widget/event'` w `$boot['nudge']`.

## KRYTERIA AKCEPTACJI
1. AB=OFF: zachowanie jak CHAT-T-081 (variant z panelu), ale dodatkowo lecą beacony shown/click z bucket=variant.
2. AB=ON: bucket losowany raz, sticky (reload → ten sam wariant); ~50/50 rozkład; beacony z poprawnym bucket.
3. `nudge_shown` leci raz przy pokazaniu; `nudge_cta_click` tylko przy kliknięciu zachęty (nie przy samym launcherze bez nudge).
4. sessionId z nudge = sessionId rozmowy po otwarciu czatu (sprawdzalne: wiersz w divechat_nudge_events.session_id == session_id rozmowy w divechat_conversations).
5. Błąd/blokada beacona nie wpływa na otwarcie czatu (fire-and-forget).
6. Regresja zero: czat, persystencja (CHAT-T-059), v1/v2 render, gating — bez zmian. Restore istniejącej rozmowy > pending sid.
7. F12 bez błędów; Shadow DOM izolacja; mobile OK.

## WDROŻENIE MODUŁU PS (116b)
CC NIE wgrywa modułu. Po implementacji: odczytaj .env, podaj KOMPLETNĄ komendę rsync (port 5739, ~/public_html/newtmp2, --exclude config_pl.xml, bez --delete), multi-line z `\`. STOP — Karol wgrywa ręcznie.

## GIT
`git add` per ścieżka (3 pliki modułu). Commit wg konwencji z `git log`. Push origin main. Po zatwierdzeniu deployu osobny commit `docs:` ze statusem.
