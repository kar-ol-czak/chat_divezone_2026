# CHAT-T-057 — BACKEND: wydłużenie TTL tokenu klienta (5 min → 1 h)

**Instancja:** backend (standalone, PHP 8.4)
**Powiązane:** CHAT-T-037 (widget/token), ADR-064 (ochrona publiczna — odświeżanie tokenu należy tam, NIE tu).
**Decyzje:** 138c (szybki fix TTL teraz; pełne odświeżanie tokenu = osobny temat ADR-064), 139a (TTL = 1 h / 3600 s).
**Backend standalone — CC WDRAŻA SAM.**

## Cel / kontekst
PROD bug: czat zwraca 401 „Nieprawidłowy token" gdy strona jest otwarta dłużej niż 5 min przed zadaniem pytania. Przyczyna (potwierdzona w kodzie): token klienta generowany RAZ przy renderze strony (hookDisplayFooter, $timestamp=time()), front (transport.js) NIE odświeża go (świadomy skrót etapu 1 — komentarz w transport.js). Backend HmacVerifier ma maxAgeSec=300 (5 min) → token wygasa 5 min po załadowaniu strony. Świeża strona działa, po dłuższym czasie 401.

Szybki fix: wydłużyć TTL do 1 h (139a). To MASKUJE problem (token nadal statyczny, tylko dłużej ważny), nie usuwa u źródła — usunięcie (odświeżanie tokenu) świadomie odłożone do ADR-064 (wymaga endpointu tokenów z rate-limitem; bez ochrony = otwarta fabryka tokenów).

## Zakres (jedna zmiana, jedno miejsce)
- standalone/src/Auth/HmacVerifier.php: zmienić DEFAULT `maxAgeSec` z 300 na 3600 (1 h) w konstruktorze.
  - POWÓD zmiany defaultu zamiast argumentu: 3 miejsca tworzą `new HmacVerifier($secret)` bez 2. argumentu — ChatController:44 (chat), ChatController:105 (stream), OrderStatusController:56 (status zamówienia). Wszystkie dziedziczą default. Zmiana defaultu = jedno miejsce, spójnie dla wszystkich, zero duplikacji/rozjazdu. NIE hardkodować 3600 w 3 miejscach.
  - Zaktualizować komentarz przy maxAgeSec: `// 1 h (CHAT-T-057; było 5 min — za krótkie dla realnych sesji, pełne odświeżanie tokenu w ADR-064)`.
- NIE zmieniać ChatController ani OrderStatusController (dziedziczą nowy default automatycznie).
- NIE ruszać modułu PS (token nadal generowany tak samo, tylko backend akceptuje go dłużej).
- NIE zmieniać logiki verify (HMAC, hash_equals) — tylko wartość TTL.

## Uwaga dot. transport.js (NIE w tym tasku, do odnotowania)
Komentarze w transport.js mówią o „5 min" (linie ~13, ~187-188). Po zmianie backendu są nieaktualne. NIE edytować transport.js w tym tasku (to moduł PS = ręczny deploy Karola, niepotrzebny dla samego fixu). Odnotować w raporcie, że komentarze transport.js warto zaktualizować przy najbliższej okazji dotykającej modułu (np. razem z innym frontowym taskiem), żeby nie robić osobnego wgrania tylko dla komentarza.

## Granice
- Tylko HmacVerifier.php (default maxAgeSec). Zero zmian w kontrolerach, module, logice HMAC.
- To nie jest rozwiązanie docelowe — odświeżanie tokenu = ADR-064.

## Kryteria akceptacji
1. HmacVerifier default maxAgeSec = 3600.
2. php -l HmacVerifier.php clean.
3. Test ręczny (CC w raporcie): wygenerować token z timestampem sprzed np. 10 min (ale < 1 h) i zweryfikować → przechodzi (200, nie 401). Token sprzed > 1 h → nadal 401 (TTL działa, nie wyłączony).
4. ChatController/OrderStatusController bez zmian (git diff pusty).
5. Po deploy: czat na stronie otwartej > 5 min nie zwraca już 401 (do 1 h).
