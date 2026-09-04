═══ CHAT-T-183 · INTEGRATION · KROK 6 / STOP przed deployem ═══

# CHAT-T-183 — werdykt okna ze strat pingu, bramka kompletnosci doby, polnoc w monitorze

**Stan:** kod gotowy, przetestowany na realnych logach i zrzutach z produkcji, zacommitowany i wypchniety.
**Czeka na:** slowo „deployuj" od Karola (ADR-089). Na serwerze NIC nie zmienione — CHAT-T-182 dziala dalej (md5 `7df010f467be2e424f98154044cbb83c`).
**Commit:** `84dc597 fix(CHAT-T-183): werdykt okna ze strat pingu, bramka kompletnosci doby, polnoc w monitorze`
**Recenzja krzyzowa:** `_docs/reviews/CODEX_REVIEW_20260904_CHAT-T-183_werdykt-okna-i-bramka-kompletnosci.md`
**KROK 0:** `git pull --rebase` odmowil (niezacommitowane zmiany innych sesji: `purge_litespeed.php`, `routes.php` — nietkniete). `git fetch` + `git rev-list --left-right --count origin/main...HEAD` = `0 0`, wiec nie bylo czego przestawiac.

---

## 1. PUNKT 1 ZLECENIA — werdykt okna ze zrzutu, `github` schodzi do przeslanki

**Bylo:** `railway_summary_mail.php:300` etykietowalo okno wartoscia pola `github`:
`OK (Railway winny)` / `TEZ FAIL (szerszy problem)`. Za dobe 2026-09-02 druga etykiete
dostawalo 10 z 13 okien — przy jednoczesnym „Railway 100% strat | Leaseweb 0% strat"
dwie sekcje nizej. Mail przeczyl sam sobie.

**Jest:** werdykt liczony ze STRAT PINGU w blokach diagnostycznych pokrywajacych okno:

| Werdykt | Warunek |
|---|---|
| `TRASA DO RAILWAY (kontrole czyste)` | Railway traci, zadna kontrola nie przekracza progu |
| `SZERSZY PROBLEM LACZA (kontrole tez traca)` | ktorakolwiek kontrola >= progu (z tego samego bloku co Railway) |
| `ZRZUT BEZ STRAT PINGU (ICMP czysty)` | blok jest, wszedzie 0% strat |
| `ZRZUT NIEPELNY — bez werdyktu` | blok pokrywa okno, ale brak znacznika czasu / Railway / kontroli |
| `BRAK ZRZUTU — bez werdyktu` | zaden blok nie pokrywa okna w czasie |

`github` przy oknach jest tylko liczba z dopiskiem „przeslanka, nie dowod: koreluje
z wlasnymi zrzutami diagnostycznymi"; jako osobny wskaznik zostaje bez zmian.
Korelacja (13 par „start zrzutu → github FAIL", doba 2026-09-02, 0-190 s po starcie zrzutu,
kontrole 0% strat we wszystkich 21 zrzutach) jest wpisana w docblock
`railway_summary_window_verdict()` z data i podstawa.

**Wynik na dobie 2026-09-02:** 12 okien `TRASA DO RAILWAY (kontrole czyste)`, 1 `BRAK ZRZUTU`.
**Zero okien z etykieta sugerujaca szerszy problem lacza** — wymog testu akceptacyjnego spelniony.

## 2. PUNKT 2 — bramka kompletnosci doby

Progi jako nazwane stale u gory pliku (`define`, guard `defined`, bo plik bywa `require`'owany):

| Stala | Wartosc | Znaczenie |
|---|---|---|
| `RAILWAY_SUMMARY_COVERAGE_MIN_PCT` | 95.0 | ponizej tego pokrycia zakaz slowa CZYSTA |
| `RAILWAY_SUMMARY_MAX_GAP_S` | 900 | przerwa > 15 min = doba niepelna |
| `RAILWAY_SUMMARY_MAX_MEDIAN_GAP_S` | 60 | kadencja pomiaru (dodane po recenzji, patrz sekcja 5) |
| `RAILWAY_SUMMARY_DIAG_PING_RUN_S` | 160 | jak dlugo blok DIAG realnie mierzy siec (4 x ping pod `timeout 40`) |
| `RAILWAY_SUMMARY_CONTROL_LOSS_PCT` | 20.0 | strata kontroli uznana za realna (3 z 15 pakietow) |

Doba niepelna dostaje `OCENA DOBY: NIEPELNA (pokrycie X%, najwieksza przerwa Y, linii nieparsowalnych Z)`
i **nigdzie w mailu** nie pada slowo CZYSTA (asercja w testach: zero wystapien).

## 3. PUNKT 3 — wyscig o polnocy w `railway_monitor.php`

Poprawka 18 linii (12 usuniec), format linii / interwal / logika alertow **bez zmian**:
`logPath()` i `wlog()` przyjmuja opcjonalny `?DateTime $atW`, a wszystkie zapisy w petli
przekazuja `$nowW` — ten sam znacznik, ktory idzie w pole WAW linii.

**Dowod, nie deklaracja** (test na REALNYCH bajtach obu funkcji wycietych z pliku monitora):

```
pinned:   /tmp/x/railway_monitor_20260131.log      (cykl 2026-01-31 23:59:58 WAW)
unpinned: /tmp/x/railway_monitor_20260904.log      (bez pinu = doba biezaca, jak dotad)
TEST 1 (cykl przez polnoc trafia do pliku swojej doby): PASS
TEST 2 (bez pinu nadal doba biezaca):                   PASS
```

Kontrola statyczna na pelnych instrukcjach: **8 z 8** zapisow w petli (`$line`, `### ALERT`,
`### DIAG` x2, `### RECOVERY`, okno niedostepnosci, `# alive`, sciezka logu w mailu alertowym)
przekazuje `$nowW`. Naglowki startowe przed petla celowo zostaja na domyslnym „teraz".

---

## 4. TESTY AKCEPTACYJNE — liczby pomiarowe BEZ ZMIAN

Metoda jak w CHAT-T-182: logi i zrzuty skopiowane z serwera (SSH READ-ONLY), parser
uruchamiany lokalnie, kazda liczba dublowana `grepem` na tym samym pliku.

### `--dry-run 20260902`

| Pozycja | Wymagane | Wynik | grep |
|---|---|---|---|
| probki | 14331 | **14331** | 14331 |
| railway_tcp FAIL | 84 | **84** | 84 |
| pg_select1 FAIL | 164 | **164** | 164 |
| pg_settings FAIL | 168 | **168** | 168 |
| pg_chiptree FAIL | 171 | **171** | 171 |
| pg_upsert FAIL | 173 | **173** | 173 |
| github FAIL | 42 | **42** | 42 |
| errno=110 | 84 | **84** | 84 |
| alertow | 21 | **21** | 21 |
| zrzutow | 21 | **21** | 21 |
| okien | 13 (17:04-18:46 WAW) | **13**, 17:04:33-18:46:44 | — |
| najdluzsze okno | 18m 30s | **18m 30s** | — |

Werdykty: **12 x TRASA DO RAILWAY (kontrole czyste), 1 x BRAK ZRZUTU. Zero „szerszy problem".**

### `--dry-run 20260903`

| Pozycja | Wymagane | Wynik | grep |
|---|---|---|---|
| probki | 15251 | **15251** | 15251 |
| railway_tcp FAIL | 1 | **1** | 1 |
| pg_select1 FAIL | 1 | **1** | 1 |
| github FAIL | 0 | **0** | 0 |
| errno=110 | 1 | **1** | 1 |
| alertow | 0 | **0** | 0 |
| zrzutow | 0 | **0** | 0 |
| okno | 1 x 15:38:16 WAW | **1 x 15:38:16** | — |

`OCENA DOBY: CZYSTA` (pokrycie doby 100,0%, najwieksza przerwa 1m 3s, linii nieparsowalnych 0),
jedyne okno: `BRAK ZRZUTU — bez werdyktu`. Zgodnie z wymogiem.

### Testy bramki kompletnosci (kopie w /tmp, NIE w `~/_diag/`)

| Test | Co symuluje | Wynik |
|---|---|---|
| A (wymagany zleceniem) | log przyciety do 7 h | `NIEPELNA (pokrycie 29.2%)`, slowo CZYSTA: **0 wystapien** |
| B | dziura 30 min w srodku doby | `NIEPELNA (najwieksza przerwa 30m 7s)` przy pokryciu 100% |
| C | jedna linia w starym formacie | `NIEPELNA (linii nieparsowalnych 1)` + ostrzezenie o zmianie formatu |
| D (po recenzji) | kadencja 1 probka / 899 s | `NIEPELNA` + „monitor mierzyl ZA RZADKO" (bez tego progu wyszlo by 100%) |
| E (po recenzji) | CALY log w starym formacie (0 sparsowanych, 100 odrzuconych) | mail **ALARM** zamiast `noop:no-data` |
| F (po recenzji) | zrzut bez pomiarow kontrolnych | `ZRZUT NIEPELNY — bez werdyktu` (nie „kontrole czyste") |
| G (po recenzji) | dwa sprzeczne bloki DIAG | werdykt z JEDNEGO bloku: „incident_...173030.txt: Railway 0% strat, kontrola Leaseweb 100%" |

Dodatkowo: doba ze zmiana czasu (25 h) — bez wywrotki, `dlugosc doby 25h`; log z jedna probka —
brak dzielenia przez zero, `NIEPELNA`; zrzut o nazwie niepasujacej do wzorca — pomijany
w dopasowaniu, nie wywraca raportu.

### Kontrakt `railway_summary_send()` — bez zmian

`noop:no-log`, `noop:no-data`, `skip:flag-exists` (klucz = doba raportowana), `sent` — wszystkie
cztery odtworzone w harnessie na katalogu tymczasowym. Sygnatura i zbior statusow nietkniete.

---

## 5. RECENZJA KRZYZOWA `/codex` (gpt-5.6-sol, xhigh) — 9 uwag, 6 przyjetych

Werdykt recenzenta: „do not approve unchanged" — 6 uwag HIGH. Przeczytalem i **zgadzam sie
z szescioma**; poprawki sa w tym samym commicie.

| # | Uwaga | Co zrobione |
|---|---|---|
| 1 HIGH | sekcja okien pisala „Brak. Doba czysta" nawet gdy naglowek mowil NIEPELNA | tekst zalezny od bramki; test: zero wystapien slowa CZYSTA w niepelnym raporcie |
| 2 HIGH | zero sparsowanych linii + niezerowy licznik odrzuconych => cichy `noop:no-data`, czyli **dokladnie awaria z T-182** | mail ALARM z liczba odrzuconych linii (status `sent`, kontrakt bez zmian) |
| 3 HIGH | dopasowanie okna do zrzutu po nazwie pliku i heurystyce 120 s; nazwa to 1. FAIL, a ping startuje pozniej; blok „epizod-koniec" bywa z innej godziny | bloki DIAG czytane osobno, kazdy z wlasnym `### czas`, dopasowanie po PRZECIECIU W CZASIE (UTC) z realnym oknem pomiaru 160 s; do dopasowania dokladane zrzuty doby D-1 (epizod przez polnoc) |
| 4 HIGH | maksima Railway i kontroli brane niezaleznie => werdykt z pomiarow, ktore nigdy nie wystapily razem | obie liczby zawsze z TEGO SAMEGO bloku (dowod: test G) |
| 5 HIGH | brak danych kontrolnych opisywany jako „kontrole czyste" | blok bez Railway albo bez kontroli nie jest dowodem => `ZRZUT NIEPELNY`; straty ponizej progu opisane jako „ponizej progu", nie „czyste" |
| 6 HIGH | pokrycie liczone wzgledem WLASNEJ mediany samo normalizuje monitor mierzacy raz na kwadrans | niezalezny sufit kadencji `RAILWAY_SUMMARY_MAX_MEDIAN_GAP_S` = 60 s (dowod: test D) |
| 7 MED | pokrycie liczone na godzinach WAW bez daty (DST), brak procentu stosunku probek, nieznane pokrycie nie blokowalo | pokrycie liczone na znacznikach UTC, procent stosunku probek dopisany, `fail closed` gdy pokrycia nie da sie policzyc |

### NIEROZSTRZYGNIETE — decyduje architekt

- **Uwaga 8 (MED): prog 20% dla strat kontroli to polityka, nie zmierzony dyskryminator.**
  Zgadzam sie, ze to zalozenie, nie dowod, i zostawiam je jawnie jako nazwana stala z komentarzem
  (15 pakietow w probie, wiec 20% = 3 stracone; pojedynczy drop ICMP to szum). Recenzent chce, zeby
  parser trzymal tez liczby pakietow — mozliwe, ale to zmiana kontraktu danych dla jednej liczby;
  **nie realizowalem**, do decyzji.
- **Uwaga 9 (LOW): pinowanie przesuwa przez polnoc takze linie ALERT/RECOVERY/alive.**
  To jest zamierzone — cala relacja z jednego cyklu ma lezec w jednym pliku. Skutek uboczny:
  licznik alertow za dobe graniczna moze sie roznic o jeden wobec starego podzialu. Zglaszam,
  nie cofam.
- **Naglowki startowe monitora** (`# START`, `# metryki:`) nadal ida po czasie zapisu — moglyby
  rozjechac sie z pierwsza probka, gdyby start monitora wypadl dokladnie na polnoc.
  Stan zastany, nie regresja; poza „poprawka kilkulinijkowa" ze zlecenia.
- **Kroki koncowe recenzenta 6-7** (fixtures na wszystkie kombinacje + harness z wstrzykiwanym
  zegarem i stubem maila) — zlecenie wprost odklada harness poza zakres. Osiem przypadkow
  z sekcji 4 pokrywa to, co dalo sie sprawdzic bez harnessu.
- **Uwaga recenzenta o niesprawdzalnosci liczb produkcyjnych** („exact production counts were not
  verifiable") dotyczy jego srodowiska — ja mam oba logi i 21 zrzutow lokalnie i wszystkie liczby
  sprawdzilem dwiema niezaleznymi metodami.

---

## 6. CZEGO NIE SPRAWDZILEM

- Zachowania na **realnej** dobie ze zmiana czasu (25.10.2026) — test byl na logu syntetycznym.
- Zachowania przy zrzucie z DWOMA blokami DIAG na realnych danych — wszystkie 21 zrzutow
  z 2026-09-02 ma po jednym bloku; przypadek dwublokowy sprawdzony na spreparowanej parze (test G).
- Epizodu przez polnoc na realnych danych — logika dopasowania zrzutow z doby D-1 jest przetestowana
  tylko przez to, ze nie psuje dob 09-02 i 09-03 (zaden zrzut z D-1 nie pokrywa ich okien).
- Realnej wysylki maila po zmianie — dopiero po deployu.

---

## 7. WDROZENIE — CZEKA NA AUTORYZACJE (KROK 7, ADR-089)

**DWA pliki**, oba do `/home/divezone/_diag/`, osobno przez `scp`, port 5739, bez rsync katalogu:

1. `railway_summary_mail.php` (md5 lokalny po zmianie: sprawdzic tuz przed wyslaniem)
2. `railway_monitor.php`

Kroki: backup obu z sufiksem `.bak_YYYYMMDD` → `ea-php84 -l` na obu → md5 local == prod na obu →
`pkill -9 -f "railway_monitor[.]php"` (restart konieczny **podwojnie**: zmienia sie sam monitor
ORAZ jego `require` raportu w linii 50) → dowod restartu (guard.log, nowy `# metryki:`, rosnaca
liczba linii w dwoch odczytach, zywy pid) → `--dry-run` na obu dobach z serwera → **sciezki bez
`--dry-run` NIE uruchamiac**.

Rollback: `cp ~/_diag/<plik>.bak_YYYYMMDD ~/_diag/<plik>` dla obu + `pkill -9` monitora.

**Sentinel:** deklaracja nie jest potrzebna — `TREES_ROOT_RE="^$HOME/public_html[^/]*/[^/]+"`,
a `/home/divezone/_diag/` lezy poza `public_html` (ustalone w CHAT-T-182 odczytem konfiguracji).

═══ CHAT-T-183 · INTEGRATION · KROK 6 / STOP przed deployem ═══
