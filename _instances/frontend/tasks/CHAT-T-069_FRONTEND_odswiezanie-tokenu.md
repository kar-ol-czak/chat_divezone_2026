# CHAT-T-069 — FRONTEND/PS: odświeżanie tokenu klienta (docelowy fix 401)

**Instancja:** frontend (moduł PS). Pliki: modules/divezone_chat/controllers/front/token.php (NOWY), modules/divezone_chat/views/js/transport.js.
**Powiązane:** ADR-084 (decyzje 188a/189a/190c/191c/192b), ADR-079 (TTL dług), ADR-069 (transport = wymienialna warstwa), CHAT-T-037/057/059.
**Deploy:** Karol wgrywa moduł RĘCZNIE (116b). CC NIE deployuje modułu. Backend NIE jest dotykany w tym tasku.

## Problem (u źródła)
Token klienta = hash_hmac('sha256', customerId:timestamp, CLIENT_SECRET) liczony RAZ w hookDisplayFooter (divezone_chat.php ~linia 517) i wstrzykiwany do window.DIVEZONE_CHAT_BOOT.{token,customerId,time}. transport.js czyta BOOT.* raz przy starcie. Po 1h (HmacVerifier maxAgeSec=3600) token wygasa → backend zwraca 401 {"error":"Nieprawidłowy token"}. T-057 (TTL 5min→1h) tylko zamaskował. Docelowy fix: front pobiera ŚWIEŻY token z endpointu modułu i ponawia request.

## Architektura (ADR-084 188a)
Endpoint tokenu = front-controller modułu PS (ten sam origin co sklep, divezone.pl). Tylko moduł zna realne customerId (kontekst PS) i ma CLIENT_SECRET (Configuration). Backend chat.divezone.pl NIETKNIĘTY. Endpoint wydaje token IDENTYCZNY formatowo z tym z hooka.

---

## KROK 1 — NOWY front-controller: controllers/front/token.php

Pierwszy front-controller modułu (dotąd tylko admin). PS 1.7.6 / PHP 7.2.

Klasa: `Divezone_ChatTokenModuleFrontController extends ModuleFrontController`.

Logika `initContent()` (lub `postProcess` + ręczne wyjście — patrz niżej):
1. Czyść bufory, ustaw nagłówki:
   - header('Content-Type: application/json; charset=utf-8');
   - nagłówki anty-cache: Cache-Control: no-store, no-cache, must-revalidate; Pragma: no-cache;
   - NIE renderować layoutu PS. Front-controller PS domyślnie ciągnie header/footer szablonu — MUSI zwrócić czysty JSON. Wzorzec: zbudować JSON, `echo json_encode(...)`, potem `exit;` (przerwać przed renderem szablonu). Alternatywnie ustawić `$this->ajax = true` i nadpisać display — ale exit po echo jest najpewniejszy w 1.7.6 dla czystego JSON.
2. Sekret + gating (KOPIA logiki z hookDisplayFooter):
   - $clientSecret = (string)Configuration::get('DIVEZONE_CHAT_CLIENT_SECRET'); jeśli '' → HTTP 500 JSON {error:'config'} + exit.
   - GATING: token wydać TYLKO gdy widget ma prawo się pokazać. shouldShowWidget() jest `private` w głównej klasie modułu — NIE jest dostępna z front-controllera. Rozwiązanie (wybrać prostsze, sprawdzić co działa w 1.7.6):
     a) zmienić `shouldShowWidget()` (i ewentualnie pomocnicze isLoggedCustomer/resolveVisitorIp/isFromPoland/isOnAllowedIpList/isBot/getUserAgent) z `private` na `public` w divezone_chat.php, wywołać `Module::getInstanceByName('divezone_chat')->shouldShowWidget()` z controllera.
     b) jeśli zmiana widoczności metod ryzykowna — w controllerze załadować instancję modułu i wywołać metodę przez dedykowany publiczny wrapper (np. dodać `public function canIssueToken()` delegujące do shouldShowWidget()).
   - REKOMENDACJA: wariant b (jeden publiczny wrapper `canIssueToken()` w divezone_chat.php), nie odsłaniać wszystkich helperów. Jeśli gating zwróci false → HTTP 403 JSON {error:'not_allowed'} + exit.
3. CustomerId — WYŁĄCZNIE z kontekstu PS, NIGDY z requestu (anti-podszycie, ADR-084 bezpieczeństwo):
   - $customerId = (isset($this->context->customer) && $this->context->customer->isLogged()) ? (int)$this->context->customer->id : 0;
   - NIE czytać customerId z GET/POST.
4. Token:
   - $timestamp = time();
   - $token = hash_hmac('sha256', $customerId . ':' . $timestamp, $clientSecret);
5. Wyjście JSON (czysty, exit):
   - {"token": $token, "customerId": (string)$customerId, "time": (string)$timestamp, "expires_in": 900}
   - Klucze IDENTYCZNE z BOOT (token/customerId/time jako stringi — transport czyta BOOT.customerId/time jako stringi w nagłówkach). expires_in informacyjnie (po 191c TTL=900; przed wdrożeniem TTL backend nadal 3600 — expires_in to tylko hint dla proaktywnego refreshu, patrz KROK 2).

URL endpointu (PS friendly URL / link modułu): `$this->context->link->getModuleLink('divezone_chat', 'token')`. CC ustala dokładną ścieżkę i zapisuje ją do BOOT (KROK 3).

## KROK 2 — transport.js: refreshToken() + retry-on-401 + proaktywny refresh

Plik views/js/transport.js. Zachować charakter ADR-069 (wymienialna warstwa, eksponuje window.DivezoneChatTransport).

### 2a. Funkcja refreshToken(callback)
- fetch(BOOT.tokenUrl) [URL z BOOT, patrz KROK 3], method GET, mode:'cors' jeśli inny origin / 'same-origin' jeśli ten sam — UWAGA: endpoint jest na divezone.pl (sklep), widget też → ten sam origin; ale transport dziś używa mode:'cors' bo gada z chat.divezone.pl. Dla tokenu użyć credentials:'include' (potrzebny kontekst sesji PS do rozpoznania zalogowanego klienta!) i mode wg origin. KRYTYCZNE: bez ciasteczka sesji PS endpoint zwróci customerId=0 nawet dla zalogowanego. Zweryfikować, że getModuleLink daje URL na tym samym origin co strona → credentials:'include' przekaże ciastko PS.
- Po 200: zaktualizować współdzielone BOOT: BOOT.token=resp.token; BOOT.time=resp.time; BOOT.customerId=resp.customerId; wywołać callback(true).
- Po błędzie/nie-200: callback(false) (NIE psować BOOT).
- Anti-burst: jeśli refresh już w locie, kolejne wywołania czekają na ten sam (prosty flag + kolejka callbacków, bez bibliotek).

### 2b. Proaktywny refresh (190c) — przed wysłaniem
- Helper tokenAgeSec() = Math.floor(Date.now()/1000) - parseInt(BOOT.time,10).
- Próg PROACTIVE_REFRESH_SEC (stała w pliku) = np. 600 (10 min — bezpiecznie poniżej 900/15min docelowego TTL; działa też przy 3600 zanim TTL skrócony).
- W sendMessage / checkOrderStatus / fetchHistory: PRZED fetchem, jeśli tokenAgeSec() > PROACTIVE_REFRESH_SEC → najpierw refreshToken(), po sukcesie kontynuuj z nowym BOOT.token; po porażce kontynuuj ze starym (reaktywny retry złapie 401).
- BEZ setInterval / timerów w tle (ADR-084 odrzuca 190a).

### 2c. Reaktywny retry-on-401 (190c rdzeń)
- Opakować 3 wywołania (sendMessage stream, checkOrderStatus, fetchHistory) tak, by przy odpowiedzi HTTP 401 (lub w SSE: stream zwraca 401 przed strumieniem):
  - jeśli to PIERWSZA próba tego requestu → refreshToken(); po sukcesie PONÓW request RAZ z nowym tokenem; po porażce refreshu → pokaż dotychczasowy komunikat błędu.
  - jeśli to już RETRY (drugi 401) → NIE pętlić; pokaż komunikat (sendMessage: onError; checkOrderStatus: dotychczasowy „Sesja wygasła…"; fetchHistory: traktuj jak brak historii).
  - Anti-pętla: licznik prób per wywołanie, max 1 retry.
- sendMessage to SSE (ReadableStream). 401 przychodzi jako `!response.ok` PRZED czytaniem strumienia (patrz obecny kod: `if (!response.ok)`). Tam wpiąć detekcję status===401 → refresh+retry. Po retrym, jeśli znów !ok → onError jak dziś.

### 2d. Aktualizacja komentarzy nagłówka pliku
- Usunąć/poprawić bloki mówiące „etap 1 emituje JEDEN token", „sesja > 1h zwróci 401", „5 min". Zastąpić krótkim opisem: token odświeżany reaktywnie (401) + proaktywnie (wiek > próg) przez BOOT.tokenUrl; TTL backendu skracany osobno (CHAT-T-069 krok backendowy).
- checkOrderStatus: komentarz przy 401 zaktualizować (retry najpierw, komunikat to fallback).

## KROK 3 — divezone_chat.php: dodać tokenUrl do BOOT + wrapper canIssueToken()

Plik divezone_chat.php, hookDisplayFooter (~linia 545, tablica $boot) + ciało klasy.
1. Dodać do $boot: `'tokenUrl' => $this->context->link->getModuleLink('divezone_chat', 'token', array(), true),` (true = SSL). Umieścić obok streamPath.
2. Dodać publiczną metodę-wrapper:
   ```
   public function canIssueToken()
   {
       return $this->shouldShowWidget();
   }
   ```
   (shouldShowWidget pozostaje private; wrapper to jedyny publiczny punkt — wariant b z KROK 1).
3. NIE zmieniać generowania tokenu w hooku (zostaje jako pierwszy/bootstrap token; refresh przejmuje dalej).

## Granice
- Moduł PS WYŁĄCZNIE: nowy controllers/front/token.php, transport.js, divezone_chat.php (BOOT.tokenUrl + canIssueToken). ZERO zmian w backendzie standalone.
- Endpoint NIE przyjmuje customerId z requestu (tylko kontekst PS).
- Endpoint wydaje token tylko gdy canIssueToken()==true (gating spójny z hookiem).
- Czysty JSON z front-controllera (exit po echo, bez layoutu PS).
- credentials:'include' na refreshu (ciastko sesji PS = rozpoznanie zalogowanego).
- Max 1 retry per request (anti-pętla). Bez timerów w tle.
- PHP 7.2 / PS 1.7.6 (unikać konstrukcji wywalonych w PS 9; bez arrow functions w PHP, bez typed properties).
- NIE skracać TTL w tym tasku (to osobny krok backendu PO weryfikacji na PROD — patrz KROK BACKEND niżej).

## Kryteria akceptacji
1. GET na URL modułu 'token' zwraca czysty JSON {token,customerId,time,expires_in}, bez HTML layoutu PS, z nagłówkami no-store.
2. Zalogowany klient (ciastko PS) → customerId = realne id; gość → 0. customerId z requestu IGNOROWANY.
3. canIssueToken()==false (widget ukryty wg drabiny ekspozycji) → 403, brak tokenu.
4. transport: po sztucznym wygaśnięciu tokenu (np. cofnięcie BOOT.time o >1h) pierwsza wiadomość → cichy refresh + retry → odpowiedź bez błędu dla użytkownika (jeden 401 w network, potem 200).
5. Drugi 401 z rzędu (refresh nieskuteczny) → komunikat błędu, BEZ pętli requestów.
6. Proaktywny: token starszy niż próg → refresh PRZED wysłaniem (w network widać GET token przed POST chat/stream).
7. refreshToken aktualizuje wspólne BOOT → kolejne checkOrderStatus/fetchHistory używają nowego tokenu.
8. php -l clean na token.php i divezone_chat.php; JS bez błędów konsoli; PHP 7.2/PS 1.7.6.
9. Komentarze nagłówka transport.js zaktualizowane (bez „5 min"/„jeden token").

## Wgranie modułu (RĘCZNE — Karol, 116b)
CC NIE wykonuje. Po akceptacji Karol wgrywa zmienione pliki modułu. Komenda rsync (port 5739, ~/public_html/newtmp2, exclude config_pl.xml, bez --delete) — CC wypełnia z .env, bez placeholderów, w raporcie końcowym:
```
rsync -avz -e "ssh -p 5739 -i /Users/karol/.ssh/id_ed25519" \
  --exclude config_pl.xml \
  /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/modules/divezone_chat/ \
  divezone@divezonededyk.smarthost.pl:~/public_html/newtmp2/modules/divezone_chat/
```
UWAGA: nowy front-controller (controllers/front/token.php) — po wgraniu PS może wymagać odświeżenia routingu modułu (czasem re-instalacja/„Skonfiguruj" lub wyczyszczenie cache PS). Odnotować w raporcie, że to pierwszy front-controller modułu i sprawdzić, czy getModuleLink zwraca działający URL (jeśli 404 — wyczyścić cache PS / przebudować routing).

## KROK FINALNY — raport + status
- Zapisać raport do _instances/frontend/handoff/CHAT-T-069_done.md (co zmienione, URL endpointu z getModuleLink, wynik testów 1-9, komenda rsync wypełniona z .env, ostrzeżenie o routingu front-controllera).
- Dopisać do _docs/21_STATUS_PROJEKTU.md wpis o CHAT-T-069.
- Git (standalone repo NIE dotknięty; moduł w repo głównym): git status; git add per ścieżka (modules/divezone_chat/controllers/front/token.php, modules/divezone_chat/views/js/transport.js, modules/divezone_chat/divezone_chat.php, _docs/10_decyzje_projektowe.md jeśli nie commitnięty, _instances/frontend/handoff/CHAT-T-069_done.md, _instances/frontend/tasks/CHAT-T-069_*.md); commit wg konwencji (sprawdzić git log); git push origin main. Pliki .gitignore pominąć. Osobny commit "docs:" dla 21_STATUS_PROJEKTU.md po wszystkim.

## KROK BACKEND (WARUNKOWY — NIE teraz, po weryfikacji refreshu na PROD; 191c/192b)
Po potwierdzeniu przez Karola, że refresh działa na żywo: skrócić TTL w standalone/src/Auth/HmacVerifier.php — default maxAgeSec 3600 → 900. Jedna liczba, dziedziczona przez 3 konsumentów (ChatController chat+stream, OrderStatusController). To zadanie instancji backend (CC deployuje sam), zlecane OSOBNYM promptem PO zielonym świetle z PROD. NIE wykonywać w ramach CHAT-T-069.


---

## ANEKS A (po review kodu + weryfikacji PROD) — profilaktyczny CORS na token.php

**Ustalenie (SSH PROD, 2026-06-04):** `PS_SHOP_DOMAIN_SSL = divezone.pl` (apex, BEZ www), `PS_SSL_ENABLED=1`. Czyli `getModuleLink('divezone_chat','token',array(),true)` zwraca `https://divezone.pl/...` = TEN SAM origin co strona sklepu. Refresh tokenu jest SAME-ORIGIN → ciastko sesji PS dochodzi, kod transport.js (credentials:'include') działa bez zmian. URL `www.divezone.pl` z raportu CC był błędnym założeniem opisowym — faktyczny URL liczy getModuleLink (apex). Bez poprawek w opisie (decyzja 195a).

**Decyzja 194a — dodać profilaktyczny CORS do controllers/front/token.php** (odporność na przyszłą zmianę domeny/migrację www, nie bieżąca konieczność). WYMÓG: neutralny dla same-origin (nie psuć stanu obecnego), aktywny tylko dla origin z whitelisty.

Zakres (token.php, początek initContent, PRZED emisją JSON):
1. Whitelista origin (stała w klasie): `array('https://divezone.pl', 'https://www.divezone.pl')`. (Tylko https — PS_SSL_ENABLED=1.)
2. Odczyt `$_SERVER['HTTP_ORIGIN']` (jeśli brak — same-origin GET często nie wysyła Origin; wtedy NIC nie robić, leci normalnie).
3. Jeśli Origin obecny I na whiteliście → ustawić:
   - `Access-Control-Allow-Origin: {echo dokładnie ten origin}` (NIE '*' — przy credentials '*' jest zakazane przez przeglądarkę),
   - `Access-Control-Allow-Credentials: true`,
   - `Vary: Origin` (poprawność cache przy zmiennym Allow-Origin).
4. Jeśli Origin obecny ale spoza whitelisty → NIE ustawiać nagłówków CORS (przeglądarka sama zablokuje; my nie autoryzujemy obcych).
5. Preflight: jeśli `$_SERVER['REQUEST_METHOD'] === 'OPTIONS'` → po ustawieniu nagłówków CORS dodać `Access-Control-Allow-Methods: GET, OPTIONS`, `Access-Control-Allow-Headers: Accept`, `Access-Control-Max-Age: 600`, `http_response_code(204)`, exit (bez body, bez generowania tokenu). UWAGA: front GET z credentials i nagłówkiem Accept nie zawsze wywołuje preflight, ale obsłużyć dla pewności.

Granice aneksu: tylko token.php, tylko nagłówki + obsługa OPTIONS. Zero zmian w transport.js (credentials:'include' już jest, działa dla obu trybów). Zero w backendzie. PHP 7.2.

Kryteria (dodatkowe do 1-9):
10. Same-origin GET (divezone.pl → divezone.pl): bez Origin lub Origin==strona → endpoint działa jak dotąd (JSON 200), brak regresji.
11. OPTIONS preflight z Origin z whitelisty → 204 + nagłówki CORS, BEZ tokenu w body.
12. Origin spoza whitelisty → brak nagłówków Allow-Origin (przeglądarka blokuje); endpoint nie wydaje tokenu dla preflightu obcego.
13. php -l clean.

(Reszta tasku — front-controller, transport.js, BOOT.tokenUrl, wgranie ręczne, krok backendu TTL — bez zmian.)
