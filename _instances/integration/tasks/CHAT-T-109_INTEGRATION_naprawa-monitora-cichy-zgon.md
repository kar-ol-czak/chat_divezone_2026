# CHAT-T-109 — INTEGRATION: naprawa monitora Railway (cichy zgon + wiszące połączenie)

**Instancja:** integration (diagnostyka, `~/_diag/` na serwerze + `_diag_local/` lokalnie).
**Powiązane:** CHAT-T-108 (monitoring), incydent 2026-06-29 ~00:00 — monitor ZAMARŁ o 23:33 (28-06), nie logował 46 min, guard go NIE wskrzesił (proces żył). Decyzja P42a.
**Status:** DONE (2026-06-29). Guard wykrywa zamarcie po świeżości logu (>60s) — TEST SIGSTOP PASSED (proces żywy+zamarły → kill -9 + restart). Monitor: statement_timeout=6000 + connect_timeout 8→5 + budżet ~7s, log zawsze się zapisuje. 1 instancja na serwerze (pid 2419000), cron-guard aktywny. Raport: `_instances/integration/handoff/T-109_done.md`.

## PROBLEM (zdiagnozowany na żywo)
1. **Cichy zgon monitora:** o 23:33 (28-06) pg latencja skoczyła (pg_select1 277→564→5610 ms), monitor przestał logować, ale proces 2340898 ZOSTAŁ w `ps` (zawieszony na wiszącym połączeniu, nie martwy). Plik logu na 29-06 nie powstał. Ręcznie ubity (kill -9) + wskrzeszony (nowy pid 2413981, loguje od 00:30).
2. **Guard ślepy na zamarcie:** `railway_monitor_guard.sh` sprawdza TYLKO `kill -0 $PID` + `pgrep -f railway_monitor.php`. Proces zawieszony spełnia oba → guard robi `exit 0`, nic nie wskrzesza. Guard nie wie nic o ŚWIEŻOŚCI logu.
3. **Słaby timeout w monitorze:** `PDO::ATTR_TIMEOUT=8` + `connect_timeout=8` — ATTR_TIMEOUT w PDO_pgsql bywa zawodny dla wiszącego `query()` (działa na connect, nie zawsze na zapytanie). Wiszące połączenie zamraża pętlę (`while(true)` l.150) między `query` a `file_put_contents`, więc cykl się nie domyka i log staje.

## CEL
Monitor, który (A) sam nie zawiesza się na wiszącym połączeniu, (B) jest wskrzeszany przez guard gdy ZAMARŁ (nie tylko gdy proces zniknął). Ta sama klasa błędu co w backendzie (wiszące połączenie kładzie proces) — leczymy u źródła.

## KROK 0 — pull + rozpoznanie
1. `git pull`.
2. Przeczytaj `_diag/railway_monitor.php` (pętla l.150, PDO l.80/122, log l.59, connect_timeout l.41) i `_diag/railway_monitor_guard.sh`. Kopie referencyjne w repo: `_docs/scripts/railway_monitor.php`, `railway_monitor_guard.sh`.

## KROK 1 — guard wykrywa ZAMARCIE po świeżości logu (nie tylko istnienie procesu)
W `railway_monitor_guard.sh`, OPRÓCZ obecnego sprawdzenia pidfile/pgrep, dodaj warunek świeżości:
- Ustal najnowszy plik `railway_monitor_$(date +%Y%m%d).log` (a jeśli go nie ma — to też sygnał zamarcia: monitor nie wszedł w nowy dzień).
- Sprawdź wiek ostatniej modyfikacji logu: jeśli starszy niż PRÓG (np. 60s — interwał to 5s, więc 60s = 12 brakujących cykli, pewny sygnał) → monitor ZAMARŁ mimo żywego procesu.
- Wtedy: `kill -9` zawieszonego procesu (z pidfile/pgrep), wyczyść pidfile, wskrześ na nowo (jak teraz). Zaloguj `[guard] ZAMARCIE wykryte (log stary Xs), restart pid=...`.
- Zachowaj idempotencję: jeśli proces żyje I log świeży (<60s) → `exit 0`.
- Uważaj na granicę północy: porównuj wiek pliku po mtime (sekundy od teraz), nie po nazwie. Jeśli najnowszy log to wczorajszy i ma >60s → zamarcie (nie wszedł w nowy dzień).

## KROK 2 — twardy timeout w monitorze (żeby się nie zawieszał)
W `railway_monitor.php`:
- Dodaj `PDO::ATTR_TIMEOUT` zostaw, ALE dodatkowo otocz każde zapytanie twardym limitem czasu na poziomie procesu. Opcje (wybierz najpewniejszą dla PHP CLI):
  a) `pcntl_alarm()` + handler rzucający wyjątek wokół `query()` (jeśli pcntl dostępny na ea-php84 CLI — sprawdź `function_exists('pcntl_alarm')`), albo
  b) `statement_timeout` na sesji PG (`SET statement_timeout = 6000`) — ogranicza zapytanie po stronie serwera PG, ale NIE chroni przed wiszącym CONNECT, więc łącz z connect_timeout krótszym (np. 5s).
- Cel: pojedynczy cykl pomiaru ma się domknąć w max ~6-7s NIEZALEŻNIE od stanu Railway. Jeśli zapytanie przekroczy limit → zaloguj FAIL z czasem (to PRAWIDŁOWY pomiar degradacji, nie zawieszenie) i idź dalej w pętli.
- WAŻNE: log MUSI się zapisać nawet gdy zapytanie failuje/timeoutuje — owijaj pomiar w try/finally tak, by `file_put_contents` linii zawsze się wykonał. Skok latencji ma być WIDOCZNY w logu jako FAIL/wysokie ms, nie kończyć się ciszą.
- Skróć `connect_timeout` 8→5 (spójnie z CHAT-T-107 backend).

## KROK 3 — to samo w monitorze lokalnym
`_diag_local/railway_monitor_local_v2.py` JUŻ ma wątek z twardym limitem (`th.join(tmo+2)`) — zweryfikuj, że limit jest dość krótki (~7s) i że timeout loguje FAIL, nie zawiesza. Jeśli OK, tylko potwierdź. Jeśli nie — wyrównaj do zachowania serwerowego.

## KROK 4 — restart + weryfikacja
- Po poprawce guard+monitor: zrestartuj monitor serwerowy (kill stary, uruchom nowy), potwierdź świeży log + guard nie tworzy duplikatu.
- TEST zamarcia (symulacja): tymczasowo wymuś zawieszenie (np. SIGSTOP na proces monitora: `kill -STOP <pid>`), odczekaj >60s, sprawdź że guard wykrył stary log i wskrzesił (po czym usuń zatrzymany proces). To dowód, że guard łapie cichy zgon.
- Potwierdź jedną instancję na końcu.

## KROK 5 — raport + repo
- `git add` per ścieżka: `_docs/scripts/railway_monitor.php`, `_docs/scripts/railway_monitor_guard.sh` (kopie referencyjne), `_diag_local/*` jeśli zmieniane. (Serwerowe `~/_diag/` nie jest repo — aktualizuj kopie referencyjne.)
- Commit `fix(diag): guard wykrywa zamarcie po świeżości logu + twardy timeout w monitorze (CHAT-T-109)`. Push.
- Raport: jak guard wykrywa zamarcie (próg 60s), jaki mechanizm timeoutu wybrany (pcntl/statement_timeout), wynik testu SIGSTOP, potwierdzenie 1 instancji.

## KRYTERIA AKCEPTACJI
- [ ] Guard wskrzesza monitor gdy log starszy niż 60s, NAWET gdy proces żyje (test SIGSTOP przechodzi).
- [ ] Guard radzi sobie z granicą północy (brak pliku na dziś = sygnał zamarcia).
- [ ] Monitor: pojedynczy cykl domyka się w ~6-7s niezależnie od Railway; skok/timeout → FAIL w logu, nie cisza.
- [ ] Log zapisywany zawsze (try/finally), nawet przy failu zapytania.
- [ ] connect_timeout 8→5. Monitor lokalny zweryfikowany. Jedna instancja po restarcie.

## KONTEKST
Luka w danych 23:33–00:30 (28/29-06) jest bezpowrotna (monitor stał). Od 00:30 znów mierzy. Zegar 72h liczymy od momentu, gdy monitor działa STABILNIE z tą poprawką — ustali Karol po wdrożeniu.
