═══ CHAT-T-189 · INTEGRATION · DEPLOYED (zadanie OTWARTE do dowodu po dobie) ═══

# CHAT-T-189 — monitor padał 2-3× dziennie na `max_execution_time` + zła data w README sondy

**Stan:** WDROŻONE 2026-09-10 15:33 CEST (autoryzacja Karola, ADR-089), commit `ff75071`.
**ZADANIE OTWARTE** do sprawdzenia po dobie — sekcja 9.
**KROK 0:** `git pull --rebase` odmówił (cudze niezacommitowane zmiany: `purge_litespeed.php`,
`routes.php` — nietknięte); `git fetch` + `git rev-list --left-right --count origin/main...HEAD` = `0 0`.

---

## 1. KROK 1 — pomiar PRZED kodem. Odpowiedź: fix zadziała, nie ma powodu się zatrzymywać

Zmierzone 2026-09-10 na produkcji, **w tym samym trybie, w jakim guard uruchamia monitor**:

| co | wartość |
|---|---|
| `max_execution_time` w trybie monitora | **30** |
| SAPI / binarka w trybie monitora | `cgi-fcgi` / `/opt/cpanel/ea-php84/root/usr/bin/php-cgi` |
| `max_execution_time` w powłoce interaktywnej | **0** (SAPI `cli`, `/usr/local/bin/ea-php84`) |
| `disable_functions` | **puste** |
| `set_time_limit` istnieje | **TAK** |
| `set_time_limit(0)` w trybie monitora | zwraca `true` i **realnie zmienia 30 → 0** |

**Przyczyna jest węższa i inna, niż mówiło zlecenie** — i to jest ustalenie, nie domysł.
Nie chodzi o to, że „CLI ma limit 30 s". Guard woła `command -v ea-php84`, a pod cronowym
`PATH=/usr/bin:/bin` ta nazwa wskazuje na `/usr/bin/ea-php84`, czyli **binarkę CGI**
(`php-cgi`, SAPI `cgi-fcgi`) z domyślnym `max_execution_time=30`. W powłoce interaktywnej ta
sama nazwa wskazuje na `/usr/local/bin/ea-php84` (SAPI `cli`) z limitem **0** — dlatego problem
był niewidoczny przy każdej ręcznej próbie. **To ta sama rodzina pułapki co cronowy PATH
z CHAT-T-186:** to samo polecenie znaczy co innego w zależności od tego, kto je uruchamia.

Potwierdza to żywy proces: `1156137 /usr/bin/ea-php84 /home/divezone/_diag/railway_monitor.php`.

## 2. KROK 2 — zmiana i jej zasięg

Na samej górze skryptu, **przed** `require` i `Config::load()`:

```php
$MAX_EXEC_BEFORE = (string)ini_get('max_execution_time');
$MAX_EXEC_CALL   = function_exists('set_time_limit') ? @set_time_limit(0) : false;
$MAX_EXEC_AFTER  = (string)ini_get('max_execution_time');
$MAX_EXEC_SET_OK = ($MAX_EXEC_CALL !== false) && ($MAX_EXEC_AFTER === '0');
```

Plus linia startowa w logu z dowodem i **głośna awaria**, gdy limit nie zejdzie (sekcja 4).

**Zasięg jest wąski — podprocesy w tle limit NIGDY nie obejmował** (wymagane potwierdzenie):
`captureNetworkDiag()` i `captureMtrBaseline()` odpalają `nohup bash -c '...' &`, czyli **osobne
procesy powłoki**; `set_time_limit` dotyczy wyłącznie skryptu PHP, który go wywołał. Każde
polecenie w środku ma zresztą własny `timeout` (pingi 40 s, traceroute 50 s, mtr 90 s).
**Dowód empiryczny, nie tylko z dokumentacji:** zrzut trwa ~100 s i domyka się poprawnie,
mimo że proces, który go zlecił, miał limit 30 s.

## 3. KROK 4 — liczba bazowa PRZED wdrożeniem (bez niej test 4 jest nieweryfikowalny)

Odczyt 2026-09-10 09:03:08 UTC:

```
monitor_nohup.out:  766 linii
Fatal error:        139   <- BAZA
  w tym "Maximum execution time": 139   (czyli WSZYSTKIE fatale to ten jeden problem)
Parse error:        0
```

`guard.log`, rozkład restartów na dobę:

```
2026-09-05  proces_martwy=1  ZAMARCIE=1        2026-09-08  proces_martwy=2  ZAMARCIE=0
2026-09-06  proces_martwy=1  ZAMARCIE=2        2026-09-09  proces_martwy=3  ZAMARCIE=0
2026-09-07  proces_martwy=3  ZAMARCIE=1        2026-09-10  proces_martwy=3  ZAMARCIE=0
```

**Zastrzeżenie do porównania po dobie, żeby nie liczyć własnego ogona:** wpisy `proces martwy`
z 09-10 obejmują **także moje własne restarty przy wdrożeniach** T-186 (08:15) i T-188 (10:22) —
sam ubijam proces, więc guard notuje to tak samo. Przy sprawdzaniu po dobie trzeba odjąć
restarty wywołane deployem.

## 4. RECENZJA `/codex` (gpt-5.6-sol, xhigh) — mechanizm zatwierdzony, jedna uwaga MEDIUM przyjęta

Werdykt: „the fix addresses the measured failure and is placed correctly". Potwierdził też
niezależnie dwie rzeczy, które sam sprawdzałem: że **umieszczenie jest wystarczająco wcześnie**
(pierwsza instrukcja wykonywalna, przed autoloadem i `Config::load()`) oraz że **nowa linia
startowa nie może zostać wzięta za pomiarową** przez parser raportu dobowego (parser wymaga
`#` + cyfra; „przepuściłem obie regexy przez dokładnie tę linię, żadna nie pasuje").
Ja sprawdziłem to samo empirycznie: raport na logu z 7 dopisanymi liniami daje wynik
identyczny co bez nich.

**Przyjęte (MEDIUM #1): wykrycie awarii było ciche i mierzyło nie to, co trzeba.**
- sukces brany był ze zwrotu `set_time_limit`, a nie ze **zmierzonej** wartości → teraz
  `SET_OK` wymaga `$MAX_EXEC_AFTER === '0'`;
- „dowód" czytany był przed `require`, więc nie wykryłby zależności przywracającej limit →
  teraz wartość czytana jest **ponownie po bootstrapie** i to ona idzie do logu;
- porażka była tylko komentarzem w logu → teraz idzie **mail alertowy do Karola** (ten sam
  kanał co alerty epizodowe) z SAPI, binarką, trzema wartościami limitu i `disable_functions`,
  plus wpis do `error_log`.

**Przyjęte poza literą zlecenia (LOW #2, mówię wprost):** `captureMtrBaseline()` czekał na
`flock` **bez limitu**, w odróżnieniu od zrzutu epizodu, który od T-185 ma `-w 600`.
Sam wprowadziłem tę asymetrię i dopiero teraz stała się istotna: skoro proces ma chodzić
tygodniami zamiast godzin, zablokowany na stałe holder zbierałby odczepione procesy baseline
z kolejnych dób. Dołożone `-w 600` + wpis `### BASELINE POMINIETY`. To dwa znaki w tym samym
pliku, ale odnotowuję jako rozszerzenie zakresu.

### NIEROZSTRZYGNIĘTE — decyduje architekt

- **Guard rozwiązuje PHP dynamicznie przez cronowy PATH** (codex #6). Właściwym domknięciem
  byłoby przypięcie guarda do binarki **CLI** i asercja SAPI na starcie — wtedy cała
  dwuznaczność PATH/SAPI znika. `railway_monitor_guard.sh` jest jednak na liście NIE RUSZAĆ,
  więc tego nie tykam. **Rekomenduję osobne zadanie.**
- **Co realnie staje się nieograniczone** (codex #4): skumulowany czas wykonania PHP, czyli
  m.in. zamierzona pętla `while (true)`. Wszystkie operacje sieciowe mają własne limity
  (TCP 5 s, `connect_timeout=5`, `statement_timeout=6000`), a scenariusz „proces żyje i milczy"
  łapie guard po 60 s ciszy w logu — i to działa, co widać po wpisach `ZAMARCIE` w dobach
  z epizodem. Stary limit nie był tu żadnym zabezpieczeniem: zabijał zdrowy proces po
  skumulowanym czasie, w losowym miejscu (139 fatali, różne numery linii).
- **Stabilność RSS/FD przy tygodniach uptime'u** (codex #7): dotąd proces „odświeżał się" sam,
  bo padał 2-3× dziennie. Nie mam pomiaru z tygodnia. Codex proponuje zapis `etime`, RSS
  i liczby deskryptorów po restarcie, po dobie i po tygodniu — sensowne, zapiszę wartości
  startowe przy wdrożeniu.
- **`mail()`, `flock` i DNS w procesie głównym nadal nie mają lokalnych limitów** — stan
  zastany, nie zmieniony tą poprawką.

## 5. KROK 3 — README sondy: daty i fakt o DNS

**Było (linia 85):** `Data wyłączenia: 2026-09-08 (po trzech pełnych wieczorach: 04, 05, 06 i 07.09)`
**Jest:**

> - **Data wyłączenia: 2026-09-14** (po trzech pełnych wieczorach: 10, 11, 12 i 13.09).
>   **Data liczy się od REALNEGO startu sondy, nie od napisania tego dokumentu.** Sonda
>   wystartowała `2026-09-10 08:41 UTC` (pierwszy cykl `#00001`, wdrożenie `005030d0`) —
>   poprzednia wersja README mówiła 08.09, czyli datę z przeszłości, bo pisano ją 04.09
>   przy założeniu, że sonda ruszy tego samego dnia. Gdyby ktoś się nią kierował,
>   wyłączyłby pomiar, zanim ten cokolwiek zmierzył.

**Było (linia 159):** `## 🧹 Usunięcie po zakończeniu (do 2026-09-08)` → **jest:** `(do 2026-09-14)`.

**Dopisany akapit o DNS — sprawdziłem sam, zanim wpisałem:**

> **`chat.divezone.pl` nie wskazuje już na nasz serwer.** Nazwa rozwiązuje się dziś na adresy
> Cloudflare (`104.26.8.54`, `104.26.9.54`, `172.67.75.232` — whois: **CLOUDFLARENET**,
> sprawdzone 2026-09-10; jeszcze 04.09 ta sama nazwa dawała `193.93.88.95`). Sonda **celowo**
> łączy się z `193.93.88.95` bezpośrednio, a nazwę podaje tylko w SNI — gdyby szła za DNS-em,
> mierzyłaby łącze do Cloudflare zamiast do naszego serwera i cały pomiar trasy powrotnej
> byłby bezwartościowy. Linia `# dns: ... ROZJAZD! sonda i tak uzywa 193.93.88.95` w logu jest
> więc **oczekiwana i poprawna**, a nie usterką.

Whois potwierdziłem osobno dla wszystkich trzech adresów: `NetName: CLOUDFLARENET`.
Zmiana DNS nastąpiła między 04.09 (gdy mierzyłem `193.93.88.95`) a dziś.

## 6. TESTY

1. `ea-php84 -l` — czysty.
2. Wartości z KROKU 1 — sekcja 1.
3. Dowód, że limit schodzi, **na realnych bajtach poprawki**, w obu trybach:
   `cgi-fcgi | PRZED=30 | PO=0 | call=true | SET_OK=true` oraz `cli | PRZED=0 | PO=0 | SET_OK=true`.
   Po wdrożeniu ten sam dowód będzie **w logu** monitora (linia `# CHAT-T-189: ...`).
4. Baza Fatal — sekcja 3. Dowód końcowy po dobie, nie da się przyspieszyć.
5. README — sekcja 5.

## 7. WDROŻENIE — CZEKA NA AUTORYZACJĘ

Jeden plik `/home/divezone/_diag/railway_monitor.php`, backup `.bak_YYYYMMDD`,
md5 przed **odczytane z produkcji** (przy pisaniu zlecenia: `1215c89d60cf38d734c7caac3b5e63e6`),
`ea-php84 -l`, md5 local==prod, restart + cztery dowody, plus **linia `# CHAT-T-189` w logu
nowego procesu jako dowód zdjętego limitu**. **README nie idzie na serwer.**

**Zadania nie zamykamy po wdrożeniu.** Dowód końcowy: przez 24 h zero nowych wpisów
`Maximum execution time` w `monitor_nohup.out` (baza **139**) i zero `proces martwy`
w `guard.log` **poza restartem deployowym**. Wpisy `ZAMARCIE` mogą się pojawić przy epizodzie
i nie są regresją.

---

## 8. WDROŻENIE — WYKONANE 2026-09-10 15:33 CEST

```
Stan przed (13:32:31 UTC, odczyt Z PRODUKCJI):
  md5 monitora:  1215c89d60cf38d734c7caac3b5e63e6
  nohup.out:     766 linii | 139 Fatal | 139 Maximum execution time | 0 Parse
  stary proces:  pid 1156137, /proc/exe -> php-cgi, uptime 5h 10m

Backup:  railway_monitor.php.bak_20260910c   md5 1215c89d... (zgodny)
Lint:    ea-php84 -l  ->  No syntax errors detected
md5 PO:  a2f7347b046cc1710dabd9b6903341cb  ==  md5 lokalny  ==  commit ff75071
Kontrola tresci: set_time_limit(0) obecny w pliku produkcyjnym
README na serwer NIE poszedl (zmiana wylacznie w repo)
```

**Cztery dowody restartu + dowod zdjetego limitu:**

```
(a) guard.log:  [guard] 2026-09-10 15:33:01 proces martwy (log 11s) -> restart pid=1363578
(b) nowy START 2026-09-10 15:33:01 WAW; naglowkow "# metryki:" 3 -> 4
(c) log rosnie: 9949 linii (15:33:05) -> 9955 (15:33:40), przyrost 6
(d) monitor_nohup.out: 766 / 139 Fatal / 0 Parse  ==  baseline  =>  zero nowych bledow

(e) DOWOD Z ZYWEGO PROCESU:
    # CHAT-T-189: SAPI cgi-fcgi | max_execution_time 30 -> 0 | po bootstrapie: 0 | limit zdjety
```

Punkt (e) jest pomiarem, nie deklaracja: wartosc `po bootstrapie` czytana jest PONOWNIE,
juz po `require` i `Config::load()`. Sciezka awaryjna (mail o niezdjetym limicie) NIE odpalila.

**Nowy proces:** `pid 1363578`, `/proc/1363578/exe -> /opt/cpanel/ea-php84/root/usr/bin/php-cgi`
— **nadal CGI i tak ma byc**, bo guarda nie ruszamy; poprawka leczy skutek, nie wybor binarki.

**Baza do obserwacji dlugiego uptime'u** (dotad proces odswiezal sie sam, bo padal 2-3x dziennie,
wiec stabilnosc przy tygodniach pracy jest zalozeniem, nie zmierzonym faktem):

```
tuz po starcie:  RSS 34 644 kB | 5 deskryptorow
```

## 9. ZADANIE OTWARTE — dowod koncowy po dobie

Od **2026-09-10 15:33 WAW** przez 24 h:

- `monitor_nohup.out`: **zero nowych** wpisow `Maximum execution time` (baza **139**),
- `guard.log`: **zero** `proces martwy` **poza restartem deployowym z 15:33**,
- wpisy `ZAMARCIE` moga sie pojawic przy epizodzie i **nie sa regresja**.

Komenda do sprawdzenia:

```
ssh -p 5739 divezone@divezonededyk.smarthost.pl \
  'grep -c "Maximum execution time" ~/_diag/monitor_nohup.out; grep "2026-09-11.*proces martwy" ~/_diag/guard.log'
```

**Uwaga do liczenia:** dzisiejsze wpisy `proces martwy` sprzed 15:33 to w wiekszosci **moje
wlasne restarty** przy wdrozeniach T-186, T-188 i T-189 — nie mylic ich z fatalem.

Warto tez porownac wtedy RSS i liczbe deskryptorow z liczbami z sekcji 8.

═══ CHAT-T-189 · INTEGRATION · DEPLOYED (zadanie OTWARTE do dowodu po dobie) ═══
