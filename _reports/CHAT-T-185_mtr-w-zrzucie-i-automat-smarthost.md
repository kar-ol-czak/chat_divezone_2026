═══ CHAT-T-185 · INTEGRATION · DEPLOYED ═══

# CHAT-T-185 — mtr w zrzucie epizodu + automatyczne powiadomienie smarthosta

> **AKTUALIZACJA 2026-09-09 15:45 UTC — poprawka liczby cykli mtr TCP.**
> Na podstawie pomiaru architekta (5 przebiegow) tryb TCP zszedl z 20 na **10 cykli**
> (`MTR_CYCLES_TCP`), tryb ICMP **zostaje na 20**. Szczegoly i nowy pomiar czasu: sekcja 8.


**Stan:** WDROŻONE na produkcji 2026-09-09 18:08-18:09 CEST (autoryzacja Karola, ADR-089).
Wdrożony commit `74eeeb4` — `git diff HEAD -- _docs/scripts/` pusty przed `scp`. Dowody: sekcja 9.
**KROK 0:** `git pull --rebase` odmówił (niezacommitowane zmiany innych sesji: `purge_litespeed.php`, `routes.php` — nietknięte); `git fetch` + `git rev-list --left-right --count origin/main...HEAD` = `0 0`.

---

## 1. KOREKTA FAKTU — potwierdzona, ale z jednym zastrzeżeniem

Zweryfikowałem sam, bo od tego zależy całe zadanie (pomiar 2026-09-09 13:09 UTC):

```
/usr/sbin/mtr          mtr 0.92
/usr/sbin/mtr-packet   getcap -> cap_net_raw=ep
```

`mtr` **jest** i **działa z konta `divezone` bez roota** — potwierdzone realnymi przebiegami,
które dochodzą do **hopa 11, czyli do samego `66.33.22.230`**, gdzie traceroute nigdy nie docierał.

**Zastrzeżenie, które muszę zgłosić: przyczyna pomyłki podana w zleceniu NIE odtwarza się.**
Zlecenie mówi, że `command -v mtr` zwracało pusto, bo `/usr/sbin` nie jest w PATH.
Zmierzone dziś:

```
PATH konta divezone:              ... :/usr/local/sbin:/usr/sbin
PATH widziany przez PHP shell_exec: ... :/usr/local/sbin:/usr/sbin
command -v mtr (SSH):             /usr/sbin/mtr
command -v mtr (z PHP shell_exec): /usr/sbin/mtr
```

Czyli **fakt** („mtr jest i działa") jest zmierzony, a **wyjaśnienie** („bo PATH") — nie.
Albo PATH zmienił się od lipca, albo pierwotne sprawdzenie poszło inną drogą. Zapisałem to
tak w obu dokumentach: nie powielam niezweryfikowanej przyczyny. Pełnej ścieżki `/usr/sbin/mtr`
używam mimo to — cron i `at` bywają na minimalnym PATH.

## 2. CO ZMIENIONE — `_docs/scripts/railway_monitor.php` (+395 linii, −4)

| Element | Opis |
|---|---|
| `mtrCommands()` | dwa przebiegi mtr (ICMP + TCP na porcie bazy), pełna ścieżka, `timeout 90` każdy |
| `captureNetworkDiag()` | oba mtr **pod tym samym `flock`** co reszta zrzutu; dodatkowo `flock -w 600` (bez limitu zawieszony holder zostawiał proces w tle na zawsze) |
| `captureMtrBaseline()` | dobowy mtr odniesienia 18:30 UTC → `~/_diag/mtr_baseline_YYYYMMDD.txt`, w tle, bez maila i bez alertu, z nadrobieniem po restarcie |
| `smarthostReserve()` / `smarthostRelease()` | **atomowa rezerwacja** slotu: limit dobowy + klucz epizodu w jednej operacji pod jedną blokadą; nieudany `mail()` zwraca slot |
| `smarthostSavePending()` / `smarthostLoadPending()` | kolejka zaległych maili **przeżywa restart monitora** (a ten restartuje się kilka razy dziennie) |
| `parseIncidentForTicket()` | czyta zrzut **blokami** `### DIAG:` + `### czas:`, nie całym plikiem |
| `sendSmarthostTicket()` | treść maila budowana z **jednego** bloku dowodowego, temat zależny od tego, co pokazał pomiar |
| pętla | harmonogram baseline, obsługa kolejki, log przed wysyłką, `### MAIL-SMARTHOST` w logu głównym |

**Domyślnie po wdrożeniu funkcja mailowa jest WYŁĄCZONA** — bez `SMARTHOST_TICKET_MAIL` w `.env`
monitor tylko zapisuje w logu, że pominął wysyłkę.

## 3. POMIARY CZASU (wymóg punktu 1 zlecenia)

Wszystko zmierzone na produkcji 2026-09-09:

| co | czas |
|---|---|
| mtr ICMP, 20 cykli | **25 s** (do blackhole, czyli przy 100% strat: 26 s — czas jest ograniczony) |
| mtr TCP, 20 cykli | **11 s** |
| zrzut BEZ mtr (4 pingi + traceroute), stan zdrowy | **60 s** |
| **zrzut PO zmianie, wymuszony na żywo** | **100 s (1,7 min)** — i tak samo 100 s po wszystkich poprawkach z recenzji |

100 s < ~4 min, więc **20 cykli zostaje** — ale UWAGA: dotyczy to już tylko wariantu ICMP.
Wariant TCP zszedł na 10 cykli po weryfikacji architekta, patrz **sekcja 8** (czas zrzutu bez zmian, 100 s).
Margines na epizod: pingi przy 100%
strat kończą się po ~15 s każdy (dowód: `incident_20260902_172907.txt` — `time 14323ms` przy 100% strat),
traceroute ma sufit 50 s, oba mtr są ograniczone `timeout 90`.

## 4. TESTY AKCEPTACYJNE 1-6

**1. `ea-php84 -l`** — czysty (na serwerze, na pliku który pójdzie na produkcję).

**2. Wymuszony zrzut na żywo** (`/tmp`, nie `~/_diag`) — pełna struktura pliku:

```
### DIAG: TEST CHAT-T-185 po poprawkach
### czas: 2026-09-09 13:48:35 UTC / 15:48:35 WAW
=== ping Railway 66.33.22.230 ===        === ping Leaseweb AMS 5.79.108.33 ===
=== ping Cloudflare 1.1.1.1 ===          === ping Google 8.8.8.8 ===
=== traceroute Railway 66.33.22.230 ===
=== mtr ICMP 66.33.22.230 (strata per hop) ===
=== mtr TCP 66.33.22.230:14368 (ta sama sciezka na porcie, ktory realnie pada) ===
=== DIAG koniec (13:50:11 UTC) ===          [czas calkowity: 100 s]
```

mtr ICMP dochodzi do hopa 11 (`66.33.22.230`, 0,0% strat, 32,8 ms), pełna ścieżka
Orange Polska → Arelion → Railway.

**3. Baseline** — `captureMtrBaseline` wywołany ręcznie (do `/tmp`): plik `mtr_baseline_20260909.txt`,
38 linii, gotowy po **40 s**, obie sekcje mtr, nagłówek `### MTR BASELINE (stan odniesienia, brak epizodu)`.

**4. Powiadomienie — oba warianty, na REALNYCH bajtach kodu** (blok decyzyjny wycięty z pliku i wykonany
z podstawionym `wlog`, żeby nie dotknąć `~/_diag`):

```
BEZ SMARTHOST_TICKET_MAIL:  ### MAIL-SMARTHOST pominiety | okno 13:11-13:18 UTC | brak SMARTHOST_TICKET_MAIL w .env (funkcja wylaczona)
                            pendingTicket ustawiony? NIE (poprawnie)
Z adresem Karola:           ### MAIL-SMARTHOST zakolejkowany | okno 13:11-13:18 UTC | wysylka za 180s (czekam na mtr w zrzucie)
Realna wysylka:             ### MAIL-SMARTHOST ... | do k.susicki@gmail.com | slot 1/3 | temat: [DIVEZONE #TEST-CHAT-T-185] ... | mail=sent
```

**Do smarthosta nie poszedł żaden test** — wszystkie wysyłki na `k.susicki@gmail.com`,
z numerem zgłoszenia podmienionym na `TEST-CHAT-T-185`, żeby maila nie dało się pomylić z prawdziwym.

**5. Limit dobowy** — pięć prób pod rząd przy limicie 3:

```
proba 1: ok=true  slot 1/3      proba 4: ok=false limit dobowy 3/3
proba 2: ok=true  slot 2/3      proba 5: ok=false limit dobowy 3/3
proba 3: ok=true  slot 3/3      licznik na dysku: 3
```
Dodatkowo: ten sam epizod drugi raz → `NIE wyslano: mail dla tego epizodu juz poszedl`.

**6. Restart monitora** — do wykonania po autoryzacji (KROK 9).

**Testy ponad wymagane, wymuszone recenzją:**

| test | wynik |
|---|---|
| parser na zrzucie DWUBLOKOWYM (realny kształt epizodu) | 2 bloki rozdzielone: `epizod-start` Railway 100%/kontrole 0%, `epizod-koniec` wszystko 0% |
| czy mail bierze blok Z AWARII, a nie maksimum z pliku | **tak** — wariant ze stratami tylko w bloku powrotu daje temat „Epizod niedostepnosci bazy (ping bez strat)", nie „Utrata pakietow" |
| kolejka po restarcie | zapis i odczyt z dysku, wpis wraca po starcie |
| uszkodzony licznik (`abc`) | liczba wysłanych liczona z listy kluczy epizodów, limit nadal działa |
| cel mtr vs `DATABASE_URL` | `gethostbyname(switchback.proxy.rlwy.net)` = `66.33.22.230` = stała → brak driftu; przy rozjeździe monitor loguje ostrzeżenie i mierzy adres BIEŻĄCY |
| czy nowe linie logu psują raport dobowy T-183 | **nie** — raport na logu 20260908 z 7 dopisanymi liniami daje wynik identyczny co bez nich |

## 5. RECENZJA KRZYŻOWA `/codex` (gpt-5.6-sol, xhigh) — „do not approve unchanged", 2× CRITICAL, 5× HIGH

Recenzja szła na wersję sprzed moich poprawek. Z dziesięciu uwag **przyjąłem osiem**:

| # | Uwaga | Co zrobione |
|---|---|---|
| 1 CRITICAL | limit dobowy nieatomowy (odczyt → wysyłka → dopis): dwie instancje mogły przekroczyć limit; brak identyfikatora epizodu; fail-**open** przy nieczytelnym liczniku | jedna atomowa rezerwacja pod jedną blokadą, klucz epizodu = nazwa pliku incydentu (idempotencja), uszkodzony licznik liczy się z listy wysłanych, nieudany `mail()` zwraca slot |
| 2 CRITICAL | jeden slot na pending → drugi epizod kasował pierwszy; restart gubił maila | kolejka + zapis na dysk, wczytywana przy starcie |
| 3 HIGH | „plik przestał rosnąć" nie dowodzi kompletności; brak `clearstatcache` | warunkiem są **markery `=== DIAG koniec` (2 z 2)**, dopiero potem cisza 60 s, na końcu twardy termin; `clearstatcache()` przed odczytem; mail pisze wprost, ile bloków było domkniętych |
| 4 HIGH | `flock` bez limitu czekania | `flock -w 600` + wpis `### DIAG POMINIETY` gdy blokada zajęta |
| 6 HIGH | mail mógł twierdzić rzeczy niepoparte pomiarem (maksimum strat z całego pliku, temat zawsze o utracie pakietów, „kontrole czyste" bez kompletu kontroli, zła kadencja próbkowania) | straty **z jednego bloku**, temat zależny od pomiaru, „kontrole czyste" tylko przy trzech zmierzonych zerach, kadencja opisana jako „co ok. 6 s (interwał 5 s + czas sond)", blok powrotu pokazany osobno i podpisany |
| 7 HIGH | harmonogram 18:30 gubił dobę przy restarcie o złej porze | nadrobienie po starcie, gdy termin minął, a pliku na dziś nie ma |
| 8 MEDIUM | sztywne IP/port mtr vs `DATABASE_URL` | cel mtr z `gethostbyname($RHOST)` i port z `$RPORT`, ostrzeżenie w logu przy rozjeździe ze stałą |
| 9 MEDIUM (część) | nie widać, czy zrzut się udał | temat maila i liczba domkniętych bloków lądują w logu głównym |

### NIEROZSTRZYGNIĘTE — decyduje architekt

- **`mail()` nie ma timeoutu i stoi na ścieżce pętli pomiarowej** (codex #5). Złagodzone: wpis do logu
  leci **tuż przed** wysyłką, więc guard ma pełne 60 s zapasu, a przy dłuższej blokadzie zrobi to,
  do czego służy — zrestartuje monitor. Pełne rozwiązanie (wysyłka w osobnym procesie) to osobne zadanie.
- **„Epizod" znaczy „epizod, który przebił próg alertu"** (codex #2). Awarie krótsze niż 3 cykle
  albo mieszczące się w 15-minutowym cooldownie nie dają zgłoszenia. To zastane zachowanie alertów
  z CHAT-T-119, nie zmieniam go w tym zadaniu — ale trzeba wiedzieć, że smarthost dostanie mail
  o podzbiorze zdarzeń.
- **Kod wyjścia mtr nie jest zapisywany** (codex #9). Mail mówi, ile bloków diagnostycznych się domknęło,
  ale nie odróżnia „mtr zwrócił błąd" od „mtr się nie zmieścił w timeoucie".
- **Sam pomiar dokłada ruchu do mierzonego łącza** (codex #10): dwa mtr po ~36 s w tle na epizod plus
  jeden dobowy. Nie mam A/B z produkcji, żeby wykazać, że nie wpływa to na własne metryki.
- **`mtr` w trybie TCP kończy się komunikatem `Unexpected mtr-packet error`** i wysyła 10-13 sond
  zamiast 20 (odtworzone 3/3 razy, mtr 0.92). Ścieżkę pokazuje pełną, do hopa 11, ale ten komunikat
  **pójdzie w mailu do smarthosta**. Do decyzji: zostawić (uczciwe surowe wyjście) czy dopisać zdanie
  wyjaśniające. Sam bym zostawił i wyjaśnił w piśmie.
- Propozycje recenzenta dotyczące singletona monitora, testów crash-injection i replayu logów przez
  parser raportu w wariancie A/B — poza zakresem tego zlecenia.

## 6. CZEGO NIE SPRAWDZIŁEM

- Zachowania na **realnym** epizodzie (od 04.09 do dziś były 3 alerty; żaden nie wypadł w oknie prac).
  Wszystkie testy szły na zrzutach wymuszonych i spreparowanych.
- Wysyłki na prawdziwy adres smarthosta — **celowo**, zgodnie ze zleceniem.
- Kolejki po realnym ubiciu procesu w trakcie okna 180 s (test był na zapisie i odczycie pliku,
  nie na faktycznym `kill -9` monitora).
- Zachowania przy dwóch równoległych instancjach monitora — rezerwacja jest chroniona `flock`,
  ale testu z dwoma procesami naraz nie robiłem.

## 7. WDROŻENIE — CZEKA NA AUTORYZACJĘ (KROK 8, ADR-089)

Jeden plik: `/home/divezone/_diag/railway_monitor.php`. Backup `.bak_20260909`.
Stan przed: md5 `f88ef04234b622bdc94275928fa207a6`.
Po wdrożeniu: `ea-php84 -l`, md5 local==prod, `pkill -9 -f "railway_monitor[.]php"`, cztery dowody
restartu (guard.log, nowy nagłówek, rosnący log, `monitor_nohup.out` bez nowych fatali wobec baseline
zdjętego PRZED wdrożeniem).

**Po wdrożeniu funkcja mailowa pozostaje wyłączona**, dopóki Karol nie doda `SMARTHOST_TICKET_MAIL`
do `.env` (`/home/divezone/public_html/chat.divezone.pl/.env`). Opcjonalnie `SMARTHOST_TICKET_ID`
(domyślnie `167585`).

## 8. POPRAWKA PO WERYFIKACJI ARCHITEKTA — mtr TCP na 10 cykli (2026-09-09)

**Zmiana:** w wariancie TCP `--report-cycles` z 20 na 10 (nowa stala `MTR_CYCLES_TCP`).
Wariant ICMP **bez zmian, 20 cykli** — zweryfikowane: Snt=20 na kazdym hopie, zero bledow.
Komunikatu bledu **nie filtruje**, idzie surowy do zrzutu i do maila, z komentarzem przy wywolaniu.

**Nowy pomiar lacznego czasu zrzutu: 100 s (1,7 min)** — tyle samo co przy 20 cyklach TCP,
bo tryb TCP i tak konczyl sie wczesniej niz zadano. Struktura zrzutu bez zmian, obie sekcje mtr obecne.

**Co potwierdzilem wlasnym pomiarem (6 przebiegow po zmianie):**

| przebieg | wynik |
|---|---|
| 4 przebiegi kontrolne (2 pod rzad + 2 w odstepie 100 s) | hopow 11, `Snt(hop 11)=10`, ogon `Unexpected mtr-packet error` |
| w zrzucie epizodu | hopy posrednie `Snt=10`, hop koncowy `Snt=9` |

Czyli przy 10 cyklach wynik jest powtarzalny i pelny na hopach posrednich, a hop koncowy
bywa o jedna sonde krotszy (9 zamiast 10). Zapisalem to w komentarzu dokladnie tak — nie jako
„Snt zawsze rowna sie zadanym cyklom".

**ZNALEZISKO, KTORE MUSZE ZGLOSIC (nie wynika ze zmiany, ale dotyczy tej samej komendy).**
Miedzy 15:30 a 15:33 UTC `mtr -T -P 14368` **czterokrotnie** (raz wewnatrz zrzutu, trzy razy
samodzielnie) konczyl sie natychmiast bledem `/usr/sbin/mtr: Address in use`, dajac sekcje
szczatkowa: `Snt=1`, sciezka urwana na 5 hopie, brak celu. W szesciu pozniejszych przebiegach
(w tym dwoch pod rzad) blad **nie wystapil**. Sprawdzone i **odrzucone** jako wyjasnienie:
liczba gniazd TIME-WAIT do `66.33.22.230:14368` (28 podczas awarii, **42** gdy komenda dziala).
**Przyczyny nie ustalilem.**

Przy okazji odrzucilem falszywy trop: `mtr --port 33434` „naprawialo" problem, ale `--port`
to dluga forma `-P`, czyli **port DOCELOWY** — taki pomiar szedlby na inny port niz baza.
`-L/--localport` dziala w mtr 0.92 tylko dla UDP. Zadnego obejscia po stronie flag nie ma.

**Konsekwencja dla wdrozenia:** sekcja TCP w zrzucie moze sporadycznie wyjsc szczatkowa,
i wtedy taka trafi do maila do smarthosta — widoczna po samym komunikacie bledu.
Sekcja ICMP (20 cykli, pelna sciezka do hopa 11) jest tym nietknieta i to ona niesie glowny dowod.
**Do decyzji architekta:** zostawic tak (surowe wyjscie, uczciwe) czy dolozyc jedno powtorzenie
mtr TCP, gdy pierwszy przebieg zwroci `Address in use`. Sam bym zostawil — powtorka wydluza zrzut,
a dowodem glownym jest ICMP.

---

## 9. WDROŻENIE — WYKONANE 2026-09-09 (KROK 9)

Jeden plik do `/home/divezone/_diag/railway_monitor.php`, `scp`, port 5739, bez rsync katalogu.

### Stan przed (serwer, 2026-09-09 16:08:23 UTC)

```
md5 PRZED:  f88ef04234b622bdc94275928fa207a6   (zgodne ze zleceniem)
monitor:    pid 4139636, log doby 11652 linii, 2 naglowki "# metryki:"
BASELINE monitor_nohup.out: 760 linii | 138 x "Fatal error" | 0 x "Parse error"
SMARTHOST_TICKET_MAIL w .env: BRAK  ->  funkcja mailowa domyslnie WYLACZONA
```

Baseline `nohup.out` zdjety PRZED wgraniem — plik od dawna zawiera fatale
`max_execution_time` (znalezisko z CHAT-T-183), wiec bez punktu odniesienia nie dalo by sie
odroznic starego bledu od nowego.

### Backup, transfer, weryfikacja

```
backup:  railway_monitor.php.bak_20260909   md5 f88ef04234b622bdc94275928fa207a6 (zgodny ze stanem prod)
ea-php84 -l /home/divezone/_diag/railway_monitor.php  ->  No syntax errors detected
md5 PO (prod):  0c778965584c0220b070d968414ce613
md5 lokalny:    0c778965584c0220b070d968414ce613     ZGODNE
```

### Restart monitora — cztery dowody

```
(a) guard.log:
    [guard] 2026-09-09 18:09:01 proces martwy (log 21s) -> restart pid=384859
    monitor wstal po ~20 s

(b) nowy naglowek w logu doby (naglowkow "# metryki:" 2 -> 3):
    # START 2026-09-09 18:09:01 WAW | CIAGLY (bez stop) | interval 5s | ...
    # CHAT-T-185: mtr 20 cykli do 66.33.22.230:14368 | baseline mtr @ 18:30 UTC
                | mail do smarthosta: WYLACZONY (brak SMARTHOST_TICKET_MAIL w .env)

(c) log ROSNIE:
    11659 linii (18:09:03)  ->  11665 (18:09:38)  = +6 w 35 s
    kontrola po minucie: 11669 linii, probka #00011 z 16:09:58 UTC
    licznik cyklu zresetowany = nowy proces; jeden proces monitora (384859), zgodny z pidfile

(d) monitor_nohup.out PO restarcie:
    760 linii / 138 Fatal / 0 Parse  ==  baseline 760/138/0
    => ZERO nowych bledow po restarcie. Rollback niepotrzebny.
```

Linia `(b)` jest jednoczesnie dowodem, ze **powiadomienie smarthosta wstalo w stanie WYLACZONYM** —
tak jak mialo. Potwierdza to tez brak plikow stanu: `smarthost_mail_*.count`, `smarthost_sent_*.list`
i `smarthost_pending.json` nie istnieja.

### Co jeszcze sie wydarzy samo

- **Dobowy mtr odniesienia o 18:30 UTC** (20:30 czasu warszawskiego) — pierwszy przebieg dzis.
  Do sprawdzenia jutro: `ls -la ~/_diag/mtr_baseline_20260909.txt` (~38 linii, obie sekcje mtr)
  oraz wpis `# MTR-BASELINE zlecony:` w logu doby.
- **mtr w zrzucie epizodu** — uruchomi sie przy najblizszym epizodzie. Kod zweryfikowany
  wymuszonym zrzutem w `/tmp` (100 s, obie sekcje), ale na realnym epizodzie jeszcze nie chodzil.

### Zeby wlaczyc maile do smarthosta (decyzja Karola, nie moja)

W `/home/divezone/public_html/chat.divezone.pl/.env` dopisac:
```
SMARTHOST_TICKET_MAIL=<adres kolejki zgloszen>
SMARTHOST_TICKET_ID=167585        # opcjonalnie, taka jest wartosc domyslna
```
Monitor czyta `.env` przy starcie, wiec po dopisaniu trzeba go zrestartowac
(`pkill -9 -f "railway_monitor[.]php"`, guard wskrzesi w ~60 s). Do czasu dopisania zmiennej
monitor tylko odnotowuje w logu, ze pominal wysylke.

### Rollback

```
cp ~/_diag/railway_monitor.php.bak_20260909 ~/_diag/railway_monitor.php
pkill -9 -f "railway_monitor[.]php"     # guard wskrzesi w ~60 s
```

═══ CHAT-T-185 · INTEGRATION · DEPLOYED ═══
