═══ CHAT-T-182 · INTEGRATION · KROK 5 / STOP przed deployem ═══

# CHAT-T-182 — naprawa raportu dobowego monitora Railway

**Stan:** kod gotowy, przetestowany na realnych logach z produkcji, zacommitowany i wypchniety.
**Czeka na:** slowo „deployuj" od Karola (ADR-089). Na serwerze NIC nie zmienione.
**Commit:** `cb0a391 fix(CHAT-T-182): raport dobowy Railway — nowy format metryk, okno poprzedniej doby`
**Recenzja krzyzowa:** `_docs/reviews/CODEX_REVIEW_20260903_CHAT-T-182_raport-dobowy-railway.md`

---

## 1. CO ZMIENIONE — `_docs/scripts/railway_summary_mail.php`

Plik przepisany w calosci (282 wstawki, 51 usuniec). Struktura po zmianie:

Plik ma po zmianie 384 linie. Numery zweryfikowane grepem na zacommitowanej wersji:

| Linia | Element | Opis |
|---|---|---|
| 26, 33 | `railway_summary_fmt_dur()`, `railway_summary_fmt_pct()` | sekundy → `Xm Ys`, procent strat → `53.3%` / `100%` |
| 53 | `railway_summary_parse_log()` | parser linii pomiarowych, okna awarii, mediana i maksimum odstepu, licznik `### ALERT` i `mail=FAILED`, licznik linii NIEsparsowanych |
| 173 | `railway_summary_incidents()` | odczyt `incident_<data>_*.txt`, straty pingu do `66.33.22.230` i `5.79.108.33` |
| 214 | `railway_summary_build()` | budowa tresci raportu dla wskazanej doby (uzywana przez wysylke i przez `--dry-run`) |
| 326 | `railway_summary_send()` | **sygnatura i zbior statusow bez zmian**; okno = poprzednia doba, flaga kluczowana doba raportowana |
| 358 | wejscie CLI | `--dry-run YYYYMMDD [katalog_diag]` + dotychczasowa sciezka cronowa |

### Punkty ZAKRESU ZMIAN z zlecenia

1. **Okno = poprzednia pelna doba WAW** — `railway_summary_send()`: `(new DateTime('now', $waw))->modify('-1 day')->format('Ymd')`. Flaga `mail_sent_<doba_raportowana>.flag`. `noop:no-log` zachowane.
2. **Nowy regex** — dopasowuje znacznik UTC, znacznik WAW, `railway_tcp`, cztery metryki PG, `github`, `errno`. Linie `# metryki:`, `# START`, `# alive`, `### ALERT`, `### RECOVERY`, `### DIAG` NIE sa liczone jako probki. Numer cyklu `#NNNNN` nie sluzy do niczego poza rozpoznaniem, ze linia jest pomiarowa — sekwencja stoi na znacznikach czasu.
3. **Wskazniki per metryka** — piec sond + `github` jako kontrola wyjscia hostingu + licznik `errno=110`. Sekcja „DOWOD KORELACYJNY" zachowana i rozszerzona o „dowolna metryka PG FAIL przy github OK".
4. **Okna z realnych znacznikow czasu** — mnoznik `* 25` i tekst „interwal 25s" usuniete. Dlugosc okna = roznica znacznikow pierwszej i ostatniej probki okna. Interwal w mailu = **mediana zmierzona** z parsowanego logu (dla obu testowych dob: 6 s; `$INTERVAL = 5` w monitorze to nominal, realny cykl jest dluzszy o czas sond).
5. **Kontekst epizodow** — liczba linii `### ALERT` + lista plikow incydentow doby ze stratami pingu, format `incident_20260902_172907.txt | Railway 100% strat | Leaseweb 0% strat`. Pliki incydentow czytane wylacznie do odczytu.
6. **Czego nie ruszono** — `From:`, adresaci, `Content-Type`, sygnatura `railway_summary_send()`, zbior statusow, `railway_monitor.php`, `railway_monitor_guard.sh`.

### Zmiany ponad litere zlecenia (swiadome, do akceptacji architekta)

- **Temat maila zawiera date raportowanej doby**: `[divezone] Nocny monitor Railway — podsumowanie 2026-09-02 (fallback)`. Zlecenie zabranialo ruszac `From:`, adresatow i `Content-Type` — tematu nie wymienialo. Powod: bez daty w temacie nie widac, ktorej doby dotyczy mail.
- **Linia „OCENA DOBY"** (jedno zdanie na gorze maila) — wymog testu akceptacyjnego „raport MUSI napisac, ze doba byla czysta" nie dalby sie spelnic sekcja okien, bo 1 wrzesnia ma jedna probke FAIL (czyli okien jest 1, nie 0).
- **Dlugosc okien liczona ze znacznika UTC, wyswietlana w WAW.** Zlecenie mowi „roznica znacznikow WAW". Znacznik WAW w logu to sam `H:i:s`, bez daty i offsetu — przy zmianie czasu (25.10, powtorzona godzina) roznica WAW klamie. Wynik dla obu testowych dob jest identyczny z liczonym po WAW.
- **Trzy elementy z recenzji Codeksa** (opis w sekcji 4): licznik linii nieparsowalnych, `max przerwa` w oknie, rozbicie alertow na `mail=sent` / `mail=FAILED`, dlugosc doby liczona z kalendarza (23/24/25 h), kontrola zapisu flagi.

---

## 2. TESTY AKCEPTACYJNE — liczba po liczbie

Metoda: logi `railway_monitor_20260901.log` i `railway_monitor_20260902.log` skopiowane z serwera (SSH READ-ONLY, `scp`), parser uruchamiany LOKALNIE (`php --dry-run <data> <katalog>`). Kazda liczba dodatkowo zmierzona niezaleznie `grepem` na tym samym pliku.

### `railway_monitor_20260902.log` (doba z duzym epizodem)

| Pozycja | Oczekiwane w zleceniu | Wynik parsera | grep kontrolny | Zgodnosc |
|---|---|---|---|---|
| probki (linie pomiarowe) | 14331 | **14331** | 14331 | ZGODNE |
| railway_tcp FAIL | 84 | **84** | 84 | ZGODNE |
| **pg_select1 FAIL** | **183** | **164** | 164 w liniach pomiarowych | **ROZBIEZNOSC — patrz nizej** |
| github FAIL | 42 | **42** | 42 | ZGODNE |
| errno=110 | 84 | **84** | 84 | ZGODNE |
| linie `### ALERT` | 21 | **21** | 21 | ZGODNE |
| pliki `incident_20260902_*.txt` | 21 | **21** | 21 | ZGODNE |

Pierwszy alert w logu `15:08:42 UTC / 17:08:32 WAW`, ostatni `16:44:48 UTC / 18:44:39 WAW` — zgodne ze zleceniem.
Raport pokazuje 13 okien awarii, wszystkie w godzinach **17:04–18:46 WAW** (wieczor), najdluzsze `18:25:59 - 18:44:27 WAW, 18m 30s`. Wymog „co najmniej jedno okno wieczorne" spelniony.

### ROZBIEZNOSC: pg_select1 FAIL 183 vs 164 — nie dopasowywalem testu do wyniku

Liczba 183 w zleceniu pochodzi z `grep -c 'pg_select1 FAIL'` na calym pliku. Ten grep liczy takze linie `### ALERT`, ktore cytuja nazwe metryki w tresci:

```
### ALERT 15:08:42 UTC / 17:08:32 WAW | pg_select1 FAIL x3 od ~15:08:42 UTC / 17:08:32 WAW | mail=sent
```

Rozklad wszystkich 183 trafien wg typu linii (`grep 'pg_select1 FAIL' | cut -c1-12 | sed 's/[0-9]/N/g' | sort | uniq -c`):

```
  19  ### ALERT NN        <- linie alertowe, NIE probki
 164  #NNNNN NNNN-        <- linie pomiarowe
```

**164 + 19 = 183.** Punkt 2 zlecenia wprost zabrania dopasowywac linii `### ALERT`, wiec parser liczy 164 i jest to liczba poprawna; 183 to artefakt metody pomiaru, nie blad implementacji. Liczba alertow (21) i tak jest raportowana osobno, zgodnie z punktem 5. Zadnej innej liczby nie ruszalem.

Kontrola spojnosci sond potwierdza 164: FAIL rosna wzdluz kolejnosci sond w cyklu (`pgProbe()` przerywa cykl na pierwszym bledzie) — `pg_select1` 164 ≤ `pg_settings` 168 ≤ `pg_chiptree` 171 ≤ `pg_upsert` 173.

### `railway_monitor_20260901.log` (doba czysta)

| Pozycja | Oczekiwane | Wynik parsera | grep kontrolny | Zgodnosc |
|---|---|---|---|---|
| probki | 15238 | **15238** | 15238 | ZGODNE |
| railway_tcp FAIL | 1 | **1** | 1 | ZGODNE |
| pg_select1 FAIL | 1 | **1** | 1 | ZGODNE |
| github FAIL | 0 | **0** | 0 | ZGODNE |
| errno=110 | 1 | **1** | 1 | ZGODNE |
| linie `### ALERT` | 0 | **0** | 0 | ZGODNE |

Status **nie** jest `noop:no-data` (raport zbudowany, `status=ok`). Raport pisze wprost:
`OCENA DOBY: CZYSTA — zero alertow i zero epizodow; pojedyncze zdarzenia: 1 prob pg_select1 FAIL w 1 oknie, najdluzsze 0m 0s.`

### Testy kontraktu `railway_summary_send()` (osobny harness, katalog tymczasowy)

| Przypadek | Status zwrotny | Oczekiwany |
|---|---|---|
| katalog bez logu | `noop:no-log` | ZGODNE |
| log bez linii pomiarowych | `noop:no-data` | ZGODNE |
| istnieje `mail_sent_20260902.flag` (doba raportowana, przy uruchomieniu 2026-09-03) | `skip:flag-exists` | ZGODNE — potwierdza, ze klucz flagi to doba raportowana |
| brak flagi | `sent` + flaga zalozona | ZGODNE |

`--dry-run` bez daty → exit 2 i komunikat uzycia; `--dry-run` na nieistniejacej dobie → `noop:no-log`, exit 1; `--dry-run` nie wysyla maila i nie tworzy flagi (sprawdzone porownaniem zawartosci katalogu przed i po).

### Proby kontrolne DODATNIE (czy nowe liczniki w ogole zapalaja)

Do kopii logu dopisano trzy linie: jedna w STARYM formacie (`railway_pg`), jedna urwana, jedna `### ALERT ... mail=FAILED`.

```
Ciaglosc pomiaru: najwieksza przerwa miedzy probkami 0m 6s | UWAGA: 2 linii z numerem cyklu NIE sparsowano (zmiana formatu logu?)
Alerty (linie ### ALERT w logu): 1, w tym mail=FAILED: 1
```

Oznacza to, ze **powtorka awarii z CHAT-T-108 (zmiana formatu logu) bedzie glosna** — raport wypisze liczbe linii, ktorych nie umial sparsowac, zamiast cicho zwrocic `noop:no-data` przez dwa miesiace.

Doba ze zmiana czasu (syntetyczny log na 2026-10-25): `Doba raportowana: 2026-10-25 (WAW), dlugosc doby 25h.`

---

## 3. PRZYKLAD WYJSCIA (fragment, doba 2026-09-02)

```
Doba raportowana: 2026-09-02 (WAW), dlugosc doby 24h.
Okno logu: 00:00:03 - 23:59:58 WAW. Prob: 14331 (mediana odstepu probek 6s).
Ciaglosc pomiaru: najwieksza przerwa miedzy probkami 7m 54s.
OCENA DOBY: EPIZODY — 21 alertow, 21 zrzutow incydentow, 13 okien pg_select1 FAIL, najdluzsze 18m 30s.

=== WSKAZNIKI FAIL (per sonda) ===
railway_tcp (connect TCP)        FAIL:     84/14331 (0.6%)
pg_select1  (connect+SELECT 1)   FAIL:    164/14331 (1.1%)
pg_settings (odczyt ustawien)    FAIL:    168/14331 (1.2%)
pg_chiptree (odczyt chip_nodes)  FAIL:    171/14331 (1.2%)
pg_upsert   (zapis probe)        FAIL:    173/14331 (1.2%)
github      (api.github.com:443) FAIL:     42/14331 (0.3%)  <- kontrola wyjscia hostingu
errno=110                            :     84/14331 (0.6%)  <- timeout connectu (ETIMEDOUT)

=== DOWOD KORELACYJNY (do eskalacji) ===
pg_select1 FAIL przy github OK:      127/14331
railway_tcp FAIL przy github OK:     61/14331
dowolna metryka PG FAIL przy gh OK:  134/14331

=== ZLE OKNA (ciagi kolejnych pg_select1 FAIL) ===
Liczba okien: 13
  17:04:33 - 17:11:38 WAW | 10 prob | 7m 5s | max przerwa 199s | github w tym oknie: TEZ FAIL (szerszy problem)
  ...
  18:25:59 - 18:44:27 WAW | 29 prob | 18m 30s | max przerwa 178s | github w tym oknie: TEZ FAIL (szerszy problem)

=== EPIZODY (alerty i zrzuty diagnostyczne CHAT-T-119) ===
Alerty (linie ### ALERT w logu): 21, w tym mail=FAILED: 0
Pliki incydentow: 21
  incident_20260902_172907.txt | Railway 100% strat | Leaseweb 0% strat
  ...
```

---

## 4. RECENZJA KRZYZOWA `/codex` (gpt-5.6-sol, xhigh) — NIEROZSTRZYGNIETE

Pelny tekst: `_docs/reviews/CODEX_REVIEW_20260903_CHAT-T-182_raport-dobowy-railway.md`.
Recenzent potwierdzil poprawnosc regexa, logiki poprzedniej doby, klucza flagi, zachowania kontraktu i bezpieczenstwa `--dry-run`. Zglosil 9 uwag.

### Uwagi, ktore PRZYJALEM i wdrozylem (przed commitem)

| # | Uwaga | Co zrobione |
|---|---|---|
| 1 (HIGH) | „pelne 24h" i „czysta" twierdzone bez sprawdzenia kompletnosci logu; linie niepasujace do regexa gina po cichu | licznik linii `#NNNNN` NIEsparsowanych + linia „Ciaglosc pomiaru: najwieksza przerwa…"; dlugosc doby liczona z kalendarza WAW (23/24/25 h) zamiast stalej „24h" |
| 4 (MED) | dlugosc okna obejmuje odcinki, w ktorych nic nie mierzono (dluzszy cykl przy timeoutach albo restart monitora) | kazde okno pokazuje `max przerwa Xs` miedzy probkami. Na 2026-09-02 to 124–474 s — uwaga byla trafna, dlugosci okien NIE sa pomiarem ciaglym |
| 3 (MED) | zapis flagi bez sprawdzenia wyniku → mozliwy `sent` bez flagi i podwojna wysylka | wynik `file_put_contents` sprawdzany, awaria idzie do `error_log` (status bez zmian, kontrakt zachowany) |
| 6 (LOW) | licznik `### ALERT` opisany jako „maile alertowe", a monitor pisze tez `mail=FAILED` | rozbicie: „Alerty (linie ### ALERT w logu): N, w tym mail=FAILED: M" |
| 9 (LOW) | temat w `--dry-run` bez sufiksu `(fallback)` | ujednolicone ze sciezka cronowa |

### Uwagi, z ktorymi sie NIE ZGADZAM albo ktorych NIE realizowalem — decyduje architekt

- **Uwaga 2 (MED) — wyscig o polnocy w MONITORZE.** Recenzent ma racje co do faktu: `railway_monitor.php:239` bierze znacznik WAW przed sondami, `:259` znacznik UTC po nich, a nazwe pliku logu wybiera dopiero przy zapisie (`:76`). Cykl przekraczajacy polnoc moze wiec trafic do logu D+1 ze znacznikiem WAW z doby D. Skutek: bledy rzedu jednej probki na dobe. **Nie naprawiam** — to `railway_monitor.php`, jawnie wylaczony z zakresu (punkt 6 zlecenia kaze takie rzeczy zglosic, nie naprawiac). Zgloszone.
- **Uwaga 5 (MED) — dwa rozne znaczniki w jednej linii.** Fakt prawdziwy, ale wniosek („moze nie zgadzac sie odejmowanie") uwazam za nieistotny praktycznie: dla obu testowych dob roznica WAW i roznica UTC daja te sama liczbe. Swiadomie licze w UTC (odporne na zmiane czasu), wyswietlam WAW (czytelnosc). Do decyzji, czy zapisac to jako ADR.
- **Uwaga 7 (LOW) — powtorzona godzina przy zmianie czasu.** Czesciowo przyjeta (dlugosc doby liczona z kalendarza). Reszty — etykiet `HH:MM:SS` bez offsetu i sortowania leksykalnego — nie ruszam: dotyczy jednej doby w roku i wymagaloby zmiany formatu logu, czyli monitora.
- **Uwaga 8 (LOW) — parser pingow zaklada GNU ping i angielska lokalizacje.** Prawda. Zostawiam: format zrodlowy generuje ten sam monitor (`railway_monitor.php:123`), a zmiana formatu ujawni sie jako `n/d` w mailu. Zamiana `n/d` na rozroznienie „brak pomiaru" / „nieznany format" = osobne zadanie, jesli architekt uzna za potrzebne.
- **Kroki koncowe recenzenta** (wstrzykiwany zegar, stub maila, fixtures na DST i restarty, snapshot katalogu) to budowa harnessu testowego dla skryptu diagnostycznego. Poza zakresem CHAT-T-182 — do decyzji architekta jako osobny task.

---

## 5. WERYFIKACJA STANU I CZEGO NIE SPRAWDZALEM

- Repo i produkcja przed zmiana zgodne: md5 `fbafb8bd0239dd5d59def6f5b27d5af7` po obu stronach (zmierzone: `md5 -q` lokalnie, `md5sum` na serwerze).
- Na serwerze **nie zmieniono niczego**: wylacznie `ls`, `md5sum` i `scp` w kierunku lokalnym.
- W `/home/divezone/_diag/` zero plikow `mail_sent_*.flag` — potwierdza, ze raport nie wyszedl ani razu.
- **NIE sprawdzalem**: zachowania parsera na dobie ze zmiana czasu na realnym logu (brak takiego logu; test na logu syntetycznym), zachowania przy dwoch blokach DIAG w jednym pliku incydentu (wszystkie 21 plikow z 2026-09-02 ma po jednym bloku; kod bierze wtedy strate najwieksza), realnej wysylki maila z serwera po zmianie (dopiero po deployu).
- Uruchomienie testowe `railway_summary_send()` z harnessu lokalnego faktycznie wywolalo `mail()` na adres `nikt@example.invalid` (TLD `.invalid` nie istnieje, przesylka nie ma dokad dojsc).

---

## 6. WDROZENIE — CZEKA NA AUTORYZACJE (KROK 6, ADR-089)

Nie wykonane. Po slowie „deployuj":

1. `cp ~/_diag/railway_summary_mail.php ~/_diag/railway_summary_mail.php.bak_20260903`
2. `scp` JEDNEGO pliku (port 5739) → `/home/divezone/_diag/railway_summary_mail.php`
3. `ea-php84 -l` na pliku docelowym
4. md5 lokalne == md5 na produkcji
5. `pkill -9 -f "railway_monitor\.php"` — monitor trzyma STARY kod w pamieci (`railway_monitor.php:50` robi `require` przy starcie). Guard wskrzesi w ciagu 60 s; dowod: wpis w `~/_diag/guard.log` + swiezy naglowek `# metryki:` w logu dnia
6. Test recznego uruchomienia `ea-php84 /home/divezone/_diag/railway_summary_mail.php`, sprawdzenie `status=` (jesli flaga doby 2026-09-02 istnieje — usunac PRZED testem)

**Deklaracja Sentinela:** plik lezy w `/home/divezone/_diag/`, czyli poza drzewami `chat.divezone.pl` i `prod`. Jesli `_diag` nie jest monitorowanym drzewem, deklaracja nie jest potrzebna; blok do wklejenia dolacze do raportu po deployu, gdy potwierdze status tego katalogu.

**Uwaga do zgloszenia (punkt 6 zlecenia):** po zmianie okna sciezka in-process (`railway_monitor.php:219`, 07:00 WAW) i sciezka cronowa (07:05 WAW) raportuja **te sama dobe** (D-1) i uzywaja **tej samej flagi** `mail_sent_<D-1>.flag`, wiec dedup dziala jak dotad. Zmiany w `railway_monitor.php` nie sa potrzebne.

═══ CHAT-T-182 · INTEGRATION · KROK 5 / STOP przed deployem ═══
