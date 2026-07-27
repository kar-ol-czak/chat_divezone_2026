# CHAT-T-107 — BACKEND: odporność na zrywanie połączeń z Railway (retry + reconnect + fallback)

**Instancja:** backend (PHP, standalone `chat.divezone.pl`).
**Powiązane:** ADR-088 (.env), ADR-089 (deploy rsync + STOP), incydent 2026-06-28 (godzinna seria błędów połączenia Railway 16:23–17:15 UTC, nawroty od 10-06). Decyzja P34a.
**Status:** DO WYKONANIA. PILNE — czat NIE wraca na stronę, dopóki to nie wejdzie + monitor (CHAT-T-108) nie potwierdzi stabilności.

## PROBLEM (z logu produkcyjnego)
`~/public_html/chat.divezone.pl/public/error_log`: gdy Railway zrywa połączenia (`could not connect`, `server closed the connection unexpectedly`, `no connection to the server`), backend NIE degraduje — wywala `PHP Fatal error: Uncaught PDOException` w `PostgresConnection.php:51` i kładzie całe żądanie klienta. RateLimiter jest fail-open (dobrze), ale `chip-tree`, `SettingsStore` i ChatController NIE mają fallbacku. Efekt: czat na stronie nie działał ~godzinę.

## CEL
Zerwane/timeoutujące połączenie z Railway ma DEGRADOWAĆ czat (działa dalej z ostatnią znaną konfiguracją / pustym wynikiem), nie KŁAŚĆ. Trzy warstwy: (1) retry+reconnect w połączeniu, (2) fallback-cache dla odczytów krytycznych (settings, chip-tree), (3) żaden odczyt konfiguracji nie rzuca fatalem do użytkownika.

## KROK 0 — pull + rozpoznanie
1. `git pull`.
2. Przeczytaj `src/Database/PostgresConnection.php` (singleton, lazy PDO, `query/fetchAll/fetchOne`, brak retry), `src/Chat/SettingsStore.php` (`get/getAll` — czyste odczyty), `src/Controller/ChatController.php` (l.48 `SettingsStore->get`, l.152 `readNumericThreshold`, l.364 `rateLimitExceeded`), `src/Chat/ChipTreeService.php` (chip-tree). Zmapuj wszystkie miejsca, gdzie odczyt z PG może rzucić wyjątkiem do użytkownika.
3. Sprawdź dostępność APCu (`apcu_enabled()`) na ea-php84 — jeśli jest, użyj jako cache; jeśli nie, fallback plikowy w `var/cache/` (utwórz katalog, zabezpiecz .htaccess deny).

## KROK 1 — retry + reconnect w PostgresConnection (REWIZJA P36c — rozróżnienie typu błędu)

Kluczowa zmiana względem pierwszej implementacji: NIE traktuj wszystkich błędów połączenia jednakowo. Rozróżnij dwa przypadki, bo mają różny optymalny czas reakcji dla klienta:

1. **„zerwane w trakcie" (połączenie żyło, padło mid-query):** `server closed the connection unexpectedly`, `57P01`, `no connection to the server`, `08006` po wcześniej udanym połączeniu. → retry MA SENS: max 3 próby, backoff 100/300 ms, zeruj `$this->pdo=null` i ponów. Reconnect realnie ratuje żądanie.
2. **„nieosiągalne" (Railway down):** `could not connect`, `Connection timed out`, `Connection refused`, timeout connect. → retry NIE pomoże (host leży): TYLKO 1 próba z krótkim `connect_timeout=2s`, potem od razu breaker. Nie marnuj 15s klienta na 3×5s, skoro i tak skończy się degradacją.

3. Po wyczerpaniu (3 próby zerwania / 1 próba nieosiągalności): rzuć `DbUnavailableException` z **flagą rodzaju degradacji**: `DbUnavailableException::SOFT` (zerwanie, retry nie pomógł) vs `DbUnavailableException::HARD` (nieosiągalne). Ta flaga zasila komunikat dla klienta (KROK 1b, P37c).
4. Circuit-breaker per-request (jak w pierwszej impl.): pierwsze zapytanie płaci pełny koszt, kolejne w tym żądaniu fail-fast (rzucają od razu DbUnavailableException z zapamiętaną flagą). Bez tego N odczytów configu × timeout = dziesiątki sekund.
5. `connect_timeout` w DSN: 2s (nie 5s — szybsza degradacja przy awarii, główny scenariusz). Błędy nie-połączeniowe (składnia/constraint) → rzuć od razu, bez retry.
6. Docblock „Aiven" → „Railway".

## KROK 1b — komunikaty dla klienta wg stanu (P37c — trzy stany)

ChatController, przy łapaniu degradacji, dobiera komunikat wg sytuacji (NIE jeden uniwersalny):

- **Retry SIĘ UDAŁ po opóźnieniu** (połączenie wróciło w 2-3 próbie): odpowiedź normalna; OPCJONALNIE dolep krótkie „Chwilę to zajęło, dziękujemy za cierpliwość." Nie blokuj funkcji — wszystko działa, był tylko lag.
- **DbUnavailableException::SOFT** (zerwanie, retry nie pomógł, ale to przejściowe): bot zwraca (HTTP 200 / SSE done) „Mamy chwilowy problem z połączeniem. Spróbuj wysłać wiadomość ponownie za moment." Podstawowe dane z cache działają.
- **DbUnavailableException::HARD** (Railway nieosiągalne, twarda awaria): bot zwraca uczciwie + KONTAKT: „Mamy przejściowe problemy techniczne i czat może teraz nie odpowiadać w pełni. Jeśli sprawa jest pilna, napisz na dive@divezone.pl lub zadzwoń 56 307 03 03 — chętnie pomożemy." (dane kontaktowe — żeby klient trafił do człowieka, nie utknął w martwym czacie). Numer/mail z konfiguracji jeśli dostępne z cache, inaczej zahardkodowane stałe fallback.

Komunikaty trzymaj jako stałe/konfiguracja w jednym miejscu (łatwa edycja tekstu bez grzebania w logice). Ton zgodny z resztą bota (ciepły, zwięzły, polski).

## KROK 2 — fallback-cache dla odczytów krytycznych
1. `SettingsStore::get/getAll`: po udanym odczycie zapisz wynik do cache (APCu/plik, TTL np. 300 s, plus „last known good" bez TTL jako ostatnia deska). Przy `DbUnavailableException`: zwróć wartość z cache (najpierw świeży TTL, potem last-known-good); jeśli cache pusty → zwróć `$default`. NIGDY nie propaguj wyjątku z odczytu settings do użytkownika.
2. `ChipTreeService` (endpoint `/api/chip-tree`): analogicznie. Po udanym zbudowaniu drzewa cache'uj cały wynik (to dane rzadko zmienne — idealne do cache). Przy `DbUnavailableException`: zwróć ostatnie znane drzewo z cache. Jeśli cache pusty → zwróć minimalną, statyczną strukturę root (żeby widget się nie wywalił), z nagłówkiem/flagą degradacji.
3. ChatController l.48/152: `readNumericThreshold` i odczyt progów — przy braku DB użyj wartości domyślnych z `.env`/stałych (już są jako fallback w sygnaturze `readNumericThreshold(...,$default,...)`), nie wywalaj żądania.

## KROK 3 — twardy warunek: żaden odczyt konfiguracji nie kładzie czatu
Przejdź ścieżkę `public/index.php → Router → ChatController->stream`: każdy odczyt z PG na tej ścieżce musi mieć fallback. Zapisy (nudge, rate-limit) zostają fail-open jak są (logują, nie przerywają). Cel: nawet przy 100% niedostępności Railway, żądanie czata zwraca sensowną odpowiedź (degradacja: bez personalizacji z settings, z cache'owanym drzewem), a nie 500/fatal.

## KROK 4 — testy
- Unit: `executeWithRetry` ponawia na błędzie ZERWANIA (3 próby, zeruje PDO), NIE ponawia na błędzie składni.
- Unit (P36c): błąd „nieosiągalne" (could not connect/timeout/refused) → TYLKO 1 próba, potem DbUnavailableException::HARD (nie 3 próby). Błąd „zerwane" → DbUnavailableException::SOFT po 3 próbach.
- Unit (P37c): ChatController dobiera właściwy komunikat per flaga (SOFT vs HARD vs retry-success). HARD zawiera dane kontaktowe.
- Symulacja niedostępności: zły DSN → `SettingsStore::get` zwraca cache/default (nie wyjątek), `/api/chip-tree` zwraca cache/minimal, `/api/chat` zwraca komunikat HARD z kontaktem (nie 500/fatal).
- Regresja: przy DZIAŁAJĄCYM Railway wszystko jak dotąd (TTL 300s nie psuje świeżości). CHAT-T-106 regresja musi przejść.

## KROK 5 — STOP przed deploy (ADR-089)
NIE rób rsync bez zgody. Przedstaw: diff plików, `php -l` 6/6, wynik testów. Deploy: backup `_deploy_bak/CHAT-T-107/` → rsync per ścieżka (pełne `/home/divezone/...`, nie `~/`) → md5 → `php -l` ea-php84 → smoke `/api/health` 200.
- **Smoke chip-tree z rozróżnieniem źródła (Sprawa 3):** `/api/chip-tree` musi zwrócić dane ORAZ wskazać, czy przyszły z DB czy z cache (flaga degradacji w odpowiedzi/nagłówku). „Smoke OK" znaczy „z DB OK", nie „cache zamaskował leżącą bazę". Jeśli flaga = cache/degradacja podczas deployu → ZGŁOŚ (możliwe trafienie w okno zrywania Railway), nie raportuj jako pełny sukces.
- **Test degradacji na żywo (Sprawa 2, izolacja TWARDA):** OPCJONALNY, wyłącznie osobnym skryptem `test_degradacja.php` z **zahardkodowanym złym DSN w zmiennej skryptu** (np. host nieistniejący), uruchamianym ad-hoc i SKASOWANYM po teście. ZAKAZ: podmiany produkcyjnego `.env`, ZAKAZ zmiennej środowiskowej DATABASE_URL mogącej przeciec do realnego procesu php-fpm. Skrypt tylko instancjonuje klasy z własnym DSN, nie dotyka globalnego configu.
- CZEKAJ na zielone światło Karola po smoke.

## KROK 6 — porządek repo + raport
- `git status`; `git add` per ścieżka (src/Database/*, src/Chat/SettingsStore.php, src/Chat/ChipTreeService.php, src/Controller/ChatController.php, src/Exception/* jeśli nowy, tests/*). NIE `git add .`.
- Commit wg konwencji (`git log`): np. `feat(chat): odporność na zrywanie połączeń Railway — retry+reconnect+fallback (CHAT-T-107)`.
- `git push origin <branch>`. Osobny commit `docs:` ze statusem + ADR (patrz niżej).
- ADR w `_docs/10`: „Odporność backendu na niedostępność Railway" — retry/backoff, DbUnavailableException, fallback-cache settings+chip-tree, zasada „odczyt konfiguracji nigdy nie kładzie czata".

## KRYTERIA AKCEPTACJI
- [ ] PostgresConnection: rozróżnia ZERWANIE (3 próby retry) vs NIEOSIĄGALNE (1 próba, connect_timeout 2s); reconnect (zerowanie PDO) na zerwaniu.
- [ ] DbUnavailableException z flagą SOFT/HARD zamiast gołego PDOException. Circuit-breaker per-request.
- [ ] ChatController: 3 komunikaty (retry-success / SOFT / HARD-z-kontaktem) wg flagi (P37c). HARD zawiera dive@divezone.pl + 56 307 03 03.
- [ ] SettingsStore i ChipTreeService: fallback-cache (świeży TTL → last-known-good → default/minimal).
- [ ] Ścieżka /api/chat (stream) nie rzuca fatala przy niedostępności Railway — degraduje z właściwym komunikatem.
- [ ] Degradacja przy awarii w ~2-3s (nie 15s) dla przypadku „nieosiągalne".
- [ ] /api/chip-tree sygnalizuje źródło (DB vs cache) dla smoke.
- [ ] Zapisy zostają fail-open. Testy unit (retry, rozróżnienie, komunikaty) + symulacja niedostępności. php -l 6/6. CHAT-T-106 regresja OK.
- [ ] Test degradacji izolowany (zahardkodowany DSN, skasowany po), zero dotykania prod .env. Docblock Aiven→Railway.

## POZA ZAKRESEM (osobno)
Lokalny bufor zapisów nudge/rate-limit (P34b — odrzucone teraz), warstwa lokalnej bazy (P34c). Monitoring = CHAT-T-108.
