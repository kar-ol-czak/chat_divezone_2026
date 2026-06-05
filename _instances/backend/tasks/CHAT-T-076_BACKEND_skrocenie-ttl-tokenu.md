# CHAT-T-076 — BACKEND: skrócenie TTL tokenu klienta 3600→900 (domknięcie ADR-084 191c)

**Status:** DONE (2026-06-05). Pełny raport: `_instances/backend/handoff/CHAT-T-076_done.md`. 6/6 statycznie + smoke 2 PROD PASS (K7 ad hoc gdy Karol kliknie widget po >15 min bezruchu — T-069 refresh-on-401 przejmuje ścieżkę). Lokalny test 6 timestampów: 0/600/900s ACCEPT, 901/1800/3600s REJECT. PROD: fresh token z modułu PS → /api/chat/stream HTTP 200 + SSE pong + conversation #356. Negatyw (time -1000s) → 401. ServerHmacVerifier nietknięty (maxAgeSec=300). Problem 401 ZAMKNIĘTY (T-069 refresh + T-076 TTL 15min).

**Instancja:** backend (standalone chat.divezone.pl). CC deployuje SAM. ZERO modułu PS.
**Powiązany ADR:** ADR-084 (191c — zaplanowane PO potwierdzeniu refreshu na PROD). **Powiązane:** CHAT-T-069 (DONE, smoke PROD potwierdził refresh), ADR-079 (okno replay).
**Warunek spełniony:** refresh tokenu działa na PROD (T-069 smoke: endpoint tokenu 200, token akceptowany przez /api/chat/stream). Można domknąć TTL.

## Cel
Skrócić okno ważności tokenu klienta z 1h do 15 min. Po wdrożeniu odświeżania (T-069) długie okno straciło uzasadnienie; 15 min domyka okno replay z ADR-079 i ujednolica z `expires_in:900`, które endpoint tokenu już deklaruje (obecnie rozjazd: front myśli 15 min, backend akceptuje 60 min).

## Zmiana (jedna liczba)
Plik: standalone/src/Auth/HmacVerifier.php, linia 14.
- BYŁO: `private int $maxAgeSec = 3600, // 1 h (CHAT-T-057; ...)`
- NA: `private int $maxAgeSec = 900, // 15 min (CHAT-T-076; po wdrozeniu odswiezania tokenu T-069 dlugie okno zbedne; domyka okno replay ADR-079; spojne z expires_in:900 endpointu)`

Propagacja: wszystkie 4 instancjonowania (ChatController:220/303/427, OrderStatusController:56) wołają `new HmacVerifier($secret)` BEZ drugiego argumentu → dziedziczą default. Zmiana defaultu wystarcza, ZERO zmian w kontrolerach.

## Granice
- TYLKO HmacVerifier.php (jedna stała + komentarz).
- NIE ruszać ServerHmacVerifier (osobny kanał serwerowy panel/mobile, maxAgeSec=300 — inny trust, nie dotyczy).
- ZERO modułu PS. ZERO frontu.

## Kryteria akceptacji
1. HmacVerifier default maxAgeSec = 900.
2. Token świeży (wiek < 15 min) → akceptowany (/api/chat/stream 200). Smoke jak w T-069: pobierz token z https://divezone.pl/module/divezone_chat/token, użyj na /api/chat/stream → 200.
3. Token starszy niż 15 min → odrzucony (401). (Symulacja: token z time przesuniętym o >900s wstecz → 401. Potwierdza zacieśnione okno.)
4. Token w wieku 16-60 min, który PRZED zmianą był ważny, teraz → 401 (okno faktycznie skrócone, nie tylko deklaratywnie).
5. ServerHmacVerifier nietknięty (panel PS /api/conversations, mobile /m/api/* działają — kanał serwerowy bez regresji).
6. php -l clean.
7. Widget na sklepie: po skróceniu TTL refresh (T-069) nadal pokrywa wygaśnięcia — wyślij wiadomość po >15 min bezruchu → reaktywny refresh łapie 401, pobiera świeży token, ponawia → 200, użytkownik bez błędu. (To potwierdza, że skrócenie nie psuje UX, bo refresh działa.)

## KROK FINALNY — deploy + raport + status + git
- Deploy standalone (CC sam, wzorzec T-070/T-071). STOP przed: raport php -l + kryteria 1-6 (4 wymaga symulacji starego tokenu), deploy, smoke 2+7 na PROD.
- Adnotacja do ADR-084 w _docs/10_decyzje_projektowe.md: dopisać przy decyzji 191c krótkie "ZREALIZOWANE CHAT-T-076 (2026-06-05): maxAgeSec 3600→900 po potwierdzeniu refreshu na PROD."
- Raport: _instances/backend/handoff/CHAT-T-076_done.md.
- Status: dopisać CHAT-T-076 do _docs/21_STATUS_PROJEKTU.md (+ odnotować: problem 401 ZAMKNIĘTY — odświeżanie T-069 + TTL 15min T-076).
- Git: git status; git add per ścieżka (standalone/src/Auth/HmacVerifier.php, _docs/10_decyzje_projektowe.md, task); commit wg konwencji; push origin main. Osobny "docs:" dla statusu.
