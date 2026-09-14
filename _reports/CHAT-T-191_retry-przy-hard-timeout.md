═══ CHAT-T-191 · BACKEND · KROK 5 (zacommitowane, STOP przed deployem) ═══

# CHAT-T-191 — retry przy nieosiągalnej trasie. Raport z implementacji i dowodów

Data: 2026-09-14. Instancja: BACKEND. Świat wdrożeniowy: **chat.divezone.pl** (ŚWIAT 1).
Do `newtmp2` nie idzie nic.

---

## 0. Streszczenie w trzech zdaniach

Rozdzieliłem klasyfikację błędów połączenia na trzy kategorie wewnętrzne (SOFT,
HARD_RETRY, HARD_FINAL), flaga publiczna SOFT/HARD bez zmian, wszystkie ścieżki
zmierzone. **Przy okazji obaliłem premisę §4.3 zlecenia**: `connect_timeout=2`
w DSN był martwy od CHAT-T-107 — pdo_pgsql dokleja własny `connect_timeout` na
końcu conninfo i to on wygrywa, więc bez dodatkowej poprawki budżet retry
wyniósłby nie 10 s, tylko **94 s**. Naprawa mieści się w tym samym pliku
(`PDO::ATTR_TIMEOUT => 2`), po niej zmierzony budżet na produkcji to **10 006 ms**.

---

## 1. Zakres zmian

| plik | charakter |
|---|---|
| `standalone/src/Database/PostgresConnection.php` | implementacja §4 zlecenia + naprawa martwego `connect_timeout` |
| `standalone/tests/Database/DbResilienceTest.php` | test kodował STARY kontrakt (3 asercje czerwone po zmianie); zaktualizowany i rozszerzony |
| `_docs/reviews/CODEX_REVIEW_20260914_CHAT-T-191_retry-hard-timeout.md` | recenzja krzyżowa |
| `_reports/CHAT-T-191_retry-przy-hard-timeout.md` | ten raport |

Nie tknięte, zgodnie z §7 zlecenia: `ChatController.php`, `ConversationStore.php`,
`DbUnavailableException.php`, `config/tools.php`, `config/routes.php`,
`_ops/newtmp2_root/purge_litespeed.php`, ADR-y, cokolwiek w `newtmp2`.

---

## 2. Co zrobiłem (§4 zlecenia, punkt po punkcie)

**§4.1 — trzy kategorie wewnętrzne.** `CAT_SOFT` / `CAT_HARD_RETRY` /
`CAT_HARD_FINAL` (stałe prywatne, linie 55-57). `classifyError()` zwraca teraz
kategorię wewnętrzną, `toPublicKind()` mapuje ją na dotychczasową flagę:
SOFT→`soft`, obie HARD_*→`hard`. `DbUnavailableException` nie tknięty, reason-key
na froncie i komunikaty `DB_SOFT_MESSAGE`/`DB_HARD_MESSAGE` działają jak dotąd
(potwierdzone testem: 6 asercji `ChatController::dbDegradePayload` zielonych).

**Jedna decyzja projektowa, której zlecenie nie rozstrzygało — kolejność needli.**
HARD_FINAL sprawdzam **przed** HARD_RETRY, bo libpq 13 (ten z produkcji) zagnieżdża
przyczynę w komunikacie ogólnym: `could not connect to server: Connection refused`.
Przy kolejności z §2 zlecenia (przejściowe najpierw) prefiks `could not connect`
przejąłby każdy zamknięty port i **każdy refused kosztowałby klienta pełne 10 s**.
Osobna asercja pilnuje tej pułapki.

**§4.2 — pętla.** SOFT bez zmian (3 próby, 100/300 ms). HARD_RETRY 3 próby,
1000/3000 ms. HARD_FINAL przerywa po pierwszej. Tablica backoffu wybierana przez
`backoffMs()` wg kategorii.

**§4.3 — budżet w docblocku.** Wyliczony w docblocku klasy (linie 32-35) i przy
`buildDsn()`. **Ale patrz §4 tego raportu — premisa była fałszywa i wymagała naprawy.**

**§4.4 — bezpiecznik jednorazowości.** `$this->unavailable` bez zmian w działaniu;
zweryfikowany testem, nie założeniem (§5.3).

**§4.5 — mierzalność.** Sukces po ponowieniu: `$this->delayed = true` (jak dotąd)
**plus** nowy wpis `[PostgresConnection] OK po retry (<kategoria>): proby=N, czas=M ms`.
Porażka: `Railway niedostepne (<kategoria> → <flaga>): proby=N, czas=M ms: <komunikat>`.
Po miesiącu da się policzyć, ile razy retry uratował zapytanie — i sprawdzić, czy
prognoza 20 % się potwierdziła.

**§4.6 — docblock klasy przepisany.** Stary opisywał dwie kategorie i twierdził
„retry i tak nie pomoze"; nowy podaje trzy kategorie, podstawę pomiarową z §1
zlecenia i rozliczenie budżetu.

---

## 3. Dowody: trzy ścieżki, czas w milisekundach (§6.2)

### 3.1 Pętla — czas to sam narzut backoffu (operacja podstawiona, bez sieci)

Skrypt dowodowy: `executeWithRetry()` wołane przez refleksję, `$op` rzuca
spreparowane `PDOException` w formacie, jaki realnie zwraca PDO pgsql.

| ścieżka | prób | czas | flaga publiczna | `wasDelayed()` |
|---|---|---|---|---|
| HARD_RETRY (`timeout expired`) | **3** | **4007,20 ms** | `hard` | false |
| ↳ breaker: 2. operacja w żądaniu | **0** | **0,00 ms** | `hard` | false |
| HARD_FINAL (`Connection refused`) | **1** | **0,00 ms** | `hard` | false |
| HARD_FINAL (stary format zagnieżdżony) | **1** | **0,00 ms** | `hard` | false |
| SOFT (`server closed`) | **3** | **410,07 ms** | `soft` | false |
| HARD_RETRY, sukces w 2. próbie | **2** | **1005,05 ms** | — (OK) | **true** |
| SOFT, sukces w 2. próbie | **2** | **102,96 ms** | — (OK) | **true** |
| błąd składni (nie-połączeniowy) | **1** | **0,02 ms** | rethrow `PDOException` | false |
| ↳ kolejna operacja po błędzie składni | **1** | **0,00 ms** | OK — breaker NIE zatrzaśnięty | false |

4007 ms = 1000 + 3000 ms backoffu. 410 ms = 100 + 300 ms. Zgodne z §4.2 zlecenia.

### 3.2 Ścieżka realna sieciowo (z limitem connectu)

Adres bez trasy `192.0.2.1:14368` (HARD_RETRY) i zamknięty port `127.0.0.1:1`
(HARD_FINAL), wywołanie przez publiczne `fetchAll('SELECT 1')`:

| środowisko | HARD_RETRY, 1. operacja | HARD_FINAL, 1. operacja |
|---|---|---|
| lokalnie (PHP 8.5.7, libpq 18.4) | **10 032,37 ms** | **1,07 ms** |
| **produkcja** (PHP 8.4.24, libpq 13.23) | **10 006,07 ms** | **0,17 ms** |

Wpisy w `error_log` z tego przebiegu:
```
[PostgresConnection] Railway niedostepne (hard_retry → hard): proby=3, czas=10021 ms: SQLSTATE[08006] [7] connection to server at "192.0.2.1", port 14368 failed: timeout expired
[PostgresConnection] Railway niedostepne (hard_final → hard): proby=1, czas=1 ms: SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 1 failed: Connection refused
```

### 3.3 Kontrola dodatnia — realne Railway z produkcji, nowa klasa

Żeby dowód nie opierał się wyłącznie na przypadkach negatywnych:

```
PHP 8.4.24  SAPI cli   cel: switchback.proxy.rlwy.net:14368 (host z .env)
  proba 1:  257,18 ms  wierszy=1  delayed=false
  proba 2:  101,94 ms  wierszy=1  delayed=false
  proba 3:  102,71 ms  wierszy=1  delayed=false
```

257 ms na pierwszy connect wobec limitu 2000 ms to zapas ×7,8. Nowy limit nie
zrywa zdrowych połączeń.

**Jak to zmierzyłem bez wgrywania czegokolwiek na serwer:** klasy sklejone
w jeden plik z blokami `namespace {}` i podane przez **stdin** do
`ssh divezone-dz '/usr/local/bin/ea-php84'`. Na produkcji nie powstał żaden plik.

---

## 4. ⚠️ Premisa §4.3 zlecenia jest fałszywa. `connect_timeout=2` w DSN był martwy

To najważniejsza rzecz w tym raporcie i wykracza poza to, co zakładało zlecenie.

**Twierdzenie zlecenia (§4.3):** „`connect_timeout=2` w `buildDsn()` zostaje.
Budżet najgorszego przypadku: 2 + 1 + 2 + 3 + 2 = 10 s."

**Pomiar (produkcja, 2026-09-14, PHP 8.4.24, libpq 13.23, cel bez trasy):**

| wariant | czas |
|---|---|
| A) DSN `connect_timeout=2` — **dokładnie to, co robi kod na produkcji** | **7108 ms** |
| B) `PDO::ATTR_TIMEOUT => 2` | **2002 ms** |
| C) `pg_connect` z tym samym conninfo (kontrola dodatnia) | **2002 ms** |

Lokalnie (libpq 18.4, cel milcząco odrzucający pakiety) wariant A dał **30 021 ms**,
czyli dokładnie domyślne 30 s libpq.

**Mechanizm.** pdo_pgsql dokleja własne `connect_timeout=<PDO::ATTR_TIMEOUT,
domyślnie 30>` na **końcu** conninfo, a libpq przy powtórzonym słowie kluczowym
honoruje **ostatnie** wystąpienie. Nasze `connect_timeout=2` z DSN stało wcześniej
i było nadpisywane. Kontrola dodatnia: `pg_connect` z tym samym ciągiem (bez
doklejki PDO) uciął po 2002 ms, a wstrzyknięty `bogus_keyword=1` dał
`invalid connection option "bogus_keyword"`, co dowodzi, że DSN **jest**
przekazywany do libpq — parametr nie jest ignorowany, tylko **nadpisywany**.

**Dlaczego to blokowało zadanie.** Budżet 3 prób przy nieskutecznym limicie:
30 + 1 + 30 + 3 + 30 = **94 s**. Zamiast poprawy klient dostałby regres —
dziś czeka do 30 s (jedna próba), po wdrożeniu czekałby do 94 s.

**Co zrobiłem.** Dodałem `PDO::ATTR_TIMEOUT => 2` do opcji w `getPdo()`
(ten sam plik, jedna linia, komentarz z pomiarem). `connect_timeout=2` w DSN
zostaje zgodnie z §4.3, ale docblock `buildDsn()` mówi teraz wprost, że sam
z siebie nic nie robi. Obie wartości trzymam równe 2, żeby się nie rozjechały.

**Skutki uboczne, świadome:**
- `PDO::ATTR_TIMEOUT` w pdo_pgsql dotyczy **wyłącznie nawiązania połączenia**,
  nie czasu wykonania zapytania — długie `SELECT`y nie są zagrożone.
- `isConnected()` (sonda `/api/health`, poza breakerem) degraduje teraz w ~2 s
  zamiast do 30 s. To poprawa, ale nie było celem zadania.
- To był **cichy dług od CHAT-T-107**: zapis „degradacja w ~2s, nie 30s" w docblocku
  był nieprawdziwy od dnia wdrożenia. Realna degradacja przy awarii Railway trwała
  do 30 s na pierwszą operację, nie 2 s.

**Decyzja dla architekta:** to wykracza poza literalny zakres §3 („jeden plik"),
ale mieści się w tym samym pliku i bez tego §4.3 jest arytmetyką na fałszywej
przesłance. Jeśli mimo to ma tego nie być — jedna linia do usunięcia, ale wtedy
retry trzeba wycofać w całości, bo 94 s przy czacie jest nie do przyjęcia.

---

## 5. Test budżetu (§5.3 zlecenia, §6.3 dowodów)

Pytanie: czy żądanie robiące 10 operacji DB przy niedostępnej bazie kosztuje
~10 s czy ~100 s.

**Realna ścieżka sieciowa, produkcja:**
```
op  1   10006,07 ms  kind=hard
op  2       0,01 ms  kind=hard
op  3..10   0,00 ms  kind=hard
RAZEM 10 operacji: 10006,10 ms   (op1 10006,07 ms, op2..10 razem 0,04 ms)
bez bezpiecznika byloby ~100060,65 ms
```
Lokalnie: **10 032,38 ms** dla 10 operacji.

**To samo w pakiecie testowym** (bez sieci, budżet syntetyczny 4 s): 10 operacji
→ `$op` wołane **3 razy łącznie**, nie 30; czas < 4,5 s; wszystkie 10 operacji
zwracają flagę `hard`.

Bezpiecznik `$this->unavailable` działa **dla operacji, które przegrywają**.

⚠️ **Ale to nie wystarcza na całe żądanie** — recenzja krzyżowa wykazała ścieżkę,
której ten test nie pokrywał, i ja ją odtworzyłem pomiarem: operacja, która
UDA SIĘ po ponowieniu, breakera nie zatrzaskuje, więc kolejne dostają świeży
budżet. Szczegóły i liczby: §7 ustalenie 1. Odpowiedź na pytanie z §5.4 zlecenia
(„czy pętla ma ścieżkę wydającą budżet wielokrotnie") brzmi zatem: **TAK, ma**.

---

## 6. Regresja: istniejący pakiet testowy

`standalone/tests/Database/DbResilienceTest.php` (z CHAT-T-107) kodował stary
kontrakt: `classifyError()` zwracało flagę publiczną. Po zmianie **3 asercje
poszły na czerwono** (32 passed, 3 failed) — wyłącznie te porównujące wynik
`classifyError()` z `DbUnavailableException::HARD`. Żadna asercja zachowania nie
pękła; w szczególności `HARD (nieosiagalne) → TYLKO 1 proba` przeszedł bez zmian,
bo testuje `could not connect to server: Connection refused`, czyli dokładnie tę
pułapkę kolejności needli z §2 tego raportu.

Test zaktualizowany do nowego kontraktu i rozszerzony o: pięć needli HARD_RETRY,
trzy HARD_FINAL, dopełnienie po SQLSTATE (57P01 / 08004 / 08001 / 08006),
zmierzony backoff HARD_RETRY, `HARD_RETRY-then-ok` z `wasDelayed()` oraz test
budżetu 10 operacji.

**Wynik: `=== 55 passed, 0 failed ===`** (przed zadaniem: 35 asercji).

---

## 7. Recenzja krzyżowa `/codex` (§5.4) — WYNIK NIEROZSTRZYGNIĘTY

Pełny tekst: `_docs/reviews/CODEX_REVIEW_20260914_CHAT-T-191_retry-hard-timeout.md`

Model: `gpt-5.6-sol`, `model_reasoning_effort=xhigh`, tryb read-only, czas ~25 min.
Recenzent samodzielnie pobrał źródła libpq 13 i pdo_pgsql z php-src, żeby zweryfikować
moje twierdzenie o `connect_timeout`. Dziewięć ustaleń: 6 × HIGH, 3 × MEDIUM.

**Uwaga o numeracji linii:** recenzja cytuje wersję pliku sprzed moich poprawek
komentarzy (ustalenie 3 i 1), więc numery są przesunięte o kilka linii.

Poniżej każde ustalenie z moim werdyktem. **Nic z tego nie zostało naprawione** —
zgodnie z poleceniem wracają nierozstrzygnięte.

### Ustalenia, które POTWIERDZIŁEM własnym pomiarem

**1. HIGH — „budżet raz na żądanie" nie obowiązuje dla operacji, które się UDAŁY po
ponowieniu. POTWIERDZONE.** Breaker zatrzaskuje się tylko po wyczerpaniu prób.
Operacja, która odzyska się w 2. próbie, wraca wcześniej i zostawia breaker otwarty,
więc następna dostaje świeży pełny budżet. Zmierzyłem u siebie (syntetycznie, sam
backoff): 5 operacji, z czego 4 odzyskane w 2. próbie i 1 przegrana →
**8037 ms** (1006 + 1001 + 1006 + 1010 + 4014). Przy realnych connectach operacja
odzyskana w 2. próbie kosztuje ~3,2 s, w 3. próbie ~8,3 s, a żądanie czatu robi
kilkanaście zapytań. **To jest realna dziura w §4.4 zlecenia** i mój test budżetu
jej nie łapał, bo pokrywał tylko wariant „pierwsza operacja przegrywa".
Minimalne domknięcie: skumulowany budżet czasu ponowień na instancję (np. 10 s),
sprawdzany przed każdym `usleep`. **Nie wprowadziłem — to zmiana polityki retry,
decyzja architekta.** Docblock klasy mówi teraz wprost, że gwarancja dotyczy
operacji przegranych, nie całego żądania.

**3. HIGH — błędy uwierzytelnienia lądują w SOFT. POTWIERDZONE pomiarem na
produkcji.** Sonda z celowo złym hasłem (jedna próba, 2026-09-14):
```
getCode()  = 7  (typ integer, NIE string SQLSTATE)
errorInfo  = ["08006", 7, "FATAL:  password authentication failed for user \"postgres\""]
```
Żadna needle nie pasuje → `(string) 7` = `'7'` → lista `['08006','08000','08003','7']`
→ **SOFT**, czyli 3 próby i komunikat „spróbuj ponownie za moment" zamiast twardego.
Konsekwencja dla mojego kodu: gałęzie `08001`/`08004`, które dodałem, **przy błędach
connectu praktycznie nie mają szans zadziałać** — PDO wstawia SQLSTATE do kodu
wyjątku dopiero w fazie zapytania. Komentarz, w którym napisałem „08004 = auth",
był nieprawdziwy — **poprawiłem go na stan zmierzony**, zachowania nie ruszałem.
Ważne: **to nie jest regres tego zadania** — stara wersja miała tę samą listę
i to samo mapowanie `'7'` → SOFT. Propozycja domknięcia (nie wykonana, wykracza
poza listy z §2 zlecenia): needle `password authentication failed`,
`no pg_hba.conf entry`, `does not exist` → HARD_FINAL.

**4 (część) i 9. MEDIUM — braki w macierzy i w teście. PRZYJĘTE.**
`could not translate host` łapie także `Temporary failure in name resolution`
(EAI_AGAIN), czyli błąd DNS **przejściowy**, i wrzuca go do HARD_FINAL. Realne,
choć u nas rzadkie. Test budżetu rzeczywiście pokrywał tylko najłatwiejszy wariant
— brakujący wariant odtworzyłem w ustaleniu 1 powyżej.

### Ustalenia, z którymi się NIE ZGADZAM albo które obaliłem pomiarem

**4 i 6 (część) — „jeden hostname z wieloma adresami może przekroczyć 2 s na próbę"
i „jeden RST przesłoni timeout innego adresu". OBALONE dla naszego hosta.**
Pomiar na produkcji: `switchback.proxy.rlwy.net` rozwiązuje się na **dokładnie
jeden adres** (66.33.22.230), rekordów AAAA **zero**. Mnożenie budżetu przez liczbę
adresów dziś nie zachodzi. Zastrzeżenie uczciwe: to stan na 2026-09-14, Railway
może dodać adres i wtedy zastrzeżenie wraca.

**2. HIGH — „SSL connection has been closed unexpectedly wypada do null".
NIEISTOTNE U NAS, i tak zaklasyfikowane zbyt wysoko.** Sama luka w listach jest
prawdziwa (tego tekstu nie ma w żadnej needli). Ale produkcja łączy się z Railway
przez `DATABASE_URL` z **`sslmode=disable`** (zmierzone) — połączenie w ogóle nie
ma warstwy TLS, więc ten komunikat libpq na naszej ścieżce nie powstanie.
Nie zweryfikowałem też twierdzenia o `HY000`; nie udało mi się odtworzyć czystego
zamknięcia TLS w trakcie zapytania i **mówię to wprost, zamiast przyjąć na wiarę**.

**6. HIGH — „10 s nie jest twardym limitem czasu żądania". ZGODA CO DO FAKTU,
SPRZECIW CO DO KWALIFIKACJI.** `PDO::ATTR_TIMEOUT` w pdo_pgsql obejmuje wyłącznie
nawiązanie połączenia — napisałem to w komentarzu przy tej linii, zanim recenzja to
podniosła. Czas wykonania zapytania nie był ograniczony ani przed tym zadaniem, ani
po nim (brak `statement_timeout`), więc to nie jest wada wprowadzanej zmiany, tylko
osobny, wcześniejszy dług. Budżet 10 s dotyczy ścieżki nieudanego connectu i tak
jest opisany w docblocku.

**8. HIGH — „retry ma semantykę at-least-once dla zapisów nieidempotentnych".
ZGODA CO DO ISTNIENIA, SPRZECIW CO DO PRZYPISANIA TEMU ZADANIU.** Ryzyko duplikatu
dotyczy awarii **w trakcie zapytania**, a to jest dokładnie kategoria SOFT, która
ponawiała 3 razy **od CHAT-T-107**. Needle HARD_RETRY to w przeważającej części
błędy fazy connectu (`could not connect`, `no route to host`, `network is
unreachable`) — tam nic jeszcze nie poszło do serwera, więc duplikat nie powstanie.
Uczciwe ustępstwo: `connection timed out` / `timeout expired` **może** wystąpić
też w trakcie zapytania i dla tego jednego przypadku ryzyko duplikatu jest **nowe**
(wcześniej 1 próba, teraz 3). Dotyczy liczników `RateLimiter`, `UsageLogger`
i dopisywania wiadomości. To realne zawężone ryzyko i zostawiam je do decyzji.

**7. HIGH — stare workery FPM mogą trzymać timeout 30 s. PRAWDOPODOBNE, NIE
ZWERYFIKOWANE PRZEZE MNIE.** Mechanizm opisany przez recenzenta jest spójny z tym,
co wiem o php-src: klucz puli persistent nie zawiera `ATTR_TIMEOUT`, a martwy
uchwyt z puli jest wskrzeszany przez `PQreset()` z **pierwotnymi** parametrami.
Skutek: w każdym workerze sprzed deployu **pierwsza** nieudana operacja może
kosztować do 30 s (PQreset), zanim PDO uzna uchwyt za martwy i pójdzie przez
fabrykę z nowym limitem 2 s. Efekt jednorazowy per worker, wygasa po recyklingu.
**Moje pomiary tego nie obejmują** — biegły pod CLI, w świeżym procesie, więc
puli persistent sprzed deployu nie dotknęły. To realna uwaga na bramkę deployu.

**5. MEDIUM — niekompletny fallback SQLSTATE (57P02/57P03/57P05/08007/08P01).
PRZYJĘTE Z ZASTRZEŻENIEM.** Braki są prawdziwe i przedwczesne dla fazy connectu:
pomiar z ustalenia 3 pokazuje, że tam `getCode()` to natywne `7`, więc żadna gałąź
SQLSTATE i tak nie strzela. Dla błędów fazy zapytania (gdzie SQLSTATE trafia do
kodu) luka jest realna: `57P03` (`cannot_connect_now`) zwraca dziś `null`, czyli
surowy `PDOException` leci wyżej i **nie zamienia się w komunikat degradacji**.
To stan sprzed tego zadania, nie regres.

### Czego recenzja nie zakwestionowała

Nie podważyła ani podziału needli HARD_FINAL-przed-HARD_RETRY (pułapka kolejności),
ani samego pomiaru o martwym `connect_timeout` — sprawdziła go u źródła w php-src
i potwierdziła mechanizm doklejania `connect_timeout` na końcu conninfo.

---

## 8. Czego NIE sprawdziłem

- **Nie wdrożyłem** — STOP z ADR-089, czekam na „deployuj".
- **Nie sprawdziłem zachowania pod FPM**, tylko pod CLI. Breaker jest polem
  instancji singletona, a stan statyczny PHP i tak jest kasowany między żądaniami,
  więc nie spodziewam się różnicy — ale **nie zmierzyłem tego**. Dotyczy to także
  puli połączeń trwałych (ustalenie 7 recenzji): moje pomiary biegły w świeżych
  procesach CLI, więc **nie dotknęły uchwytów persistent sprzed deployu**.
- **Nie odtworzyłem czystego zamknięcia TLS w trakcie zapytania** ani wyjątku
  z SQLSTATE `HY000` (ustalenie 2 recenzji) — mówię to wprost zamiast przyjmować
  cudze twierdzenie na wiarę.
- **Nie zmierzyłem ścieżki „połączenie zestawione, trasa umiera w trakcie"** —
  tam wskrzeszenie uchwytu idzie przez `PQreset()`, a nie przez fabrykę PDO,
  i limit 2 s nie jest tam dowiedziony.
- **Nie zmierzyłem realnego HARD_RETRY na trasie do Railway w chwili awarii**,
  bo w czasie pracy trasa była zdrowa (257 / 102 / 103 ms). Ścieżka HARD_RETRY
  dowiedziona na adresie bez trasy, nie na prawdziwym epizodzie.
- **Nie ruszałem `ConversationStore`** (§9 zlecenia: to on jest pierwszym
  blokerem). Retry ratuje ~20 % zapytań trafiających w awarię; pozostałe 80 %
  nadal zobaczy komunikat degradacji. To świadoma łata, nie rozwiązanie.
- **Nie weryfikowałem prognozy 20 %** — to pomiar na miesiąc do przodu, z logów,
  które to zadanie dopiero wprowadza.

---

## 9. Znaleziska poboczne (do osobnych zadań, NIE realizowane tutaj)

1. **Produkcja łączy się z Railway bez TLS.** `DATABASE_URL` ma `?sslmode=disable`
   (zmierzone 2026-09-14), więc hasło do bazy i cała treść rozmów idą przez
   publiczny internet do proxy Railway **nieszyfrowane**. To decyzja
   infrastrukturalna sprzed tego zadania (`CLAUDE.md`: „SSL: nie wymagane (TCP
   proxy)"), ale warta świadomego potwierdzenia albo osobnego zadania.
2. **`isConnected()` (sonda `/api/health`) degraduje teraz w ~2 s zamiast do 30 s** —
   uboczny, dodatni skutek naprawy limitu connectu. Nie było to celem zadania.
3. **Cichy dług z CHAT-T-107**: docblock twierdził „degradacja w ~2s, nie 30s" od
   dnia wdrożenia, a realnie pierwsza operacja przy awarii mogła blokować do 30 s.
   Wszystkie wcześniejsze oceny czasu degradacji czatu opierały się na tej liczbie.

---

## 10. Deploy (KROK 7) — przygotowane, czeka na autoryzację

- md5 lokalnie przed zmianą (= HEAD): `481f67be9f76889823b11fcec6a7785a`
- md5 na produkcji przed deployem: `481f67be9f76889823b11fcec6a7785a` — **zero dryfu**
- md5 lokalnie po zmianie: `c7354ec21b97cc7eb41a86c7a5687e96`

Jeden plik: `standalone/src/Database/PostgresConnection.php` →
`~/public_html/chat.divezone.pl/src/Database/PostgresConnection.php`
(na serwerze **bez** prefiksu `standalone/`). Backup do `_deploy_bak/`, po rsync
md5 lokalne↔produkcyjne, `ea-php84 -l` na pliku produkcyjnym, smoke na realnym
endpoincie czatu, status na górze `_docs/21_STATUS_PROJEKTU.md`, osobny commit `docs:`.

Po deployu blok deklaracji Sentinela do wklejenia przez operatora (jedno drzewo,
`chat.divezone.pl`) — bez nowych klas, więc bez plików vendora.

═══ CHAT-T-191 · BACKEND · KROK 5 (zacommitowane, STOP przed deployem) ═══
