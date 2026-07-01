# CHAT-T-119 — INTEGRATION: przechwytywanie diagnostyki sieciowej w chwili epizodu (dowód dla Smarthost #167585)

**Instancja:** integration. Rozszerzenie ISTNIEJĄCEGO `railway_monitor.php` na serwerze (`~/_diag/`). FAZA 1 (zbieranie dowodu do pliku, BEZ wysyłki SMTP — SMTP to osobna faza 2 później).
**Powiązane:** CHAT-T-108 (monitor bazowy), CHAT-T-109 (anty-zawieszenie), spór z Smarthost helpdesk #167585 (egress serwer→Railway gubi pakiety 15:00-22:00).
**Status:** DONE / WGRANE na prod (2026-07-01).

---

## CEL
Gdy monitor wykryje epizod (>=3 FAIL z rzędu = istniejący trigger alertu), automatycznie uruchom diagnostykę sieciową i zapisz do pliku, ORAZ mierz pełne okno niedostępności (od 1. FAIL do powrotu OK). To dostarcza dowód, którego żąda Smarthost: traceroute + straty z CHWILI zdarzenia, nie po fakcie.

## KONTEKST DIAGNOSTYCZNY (ustalone, wpisać w komentarz skryptu)
- Baza Railway pgvector jest w **EU West (Amsterdam)**, NIE USA. Trasa serwer→Railway idzie przez twelve99/Arelion (Warszawa→Frankfurt→Amsterdam), RTT ~37ms, hop wejścia Railway = `railwaycorp-ic-390073.ip.twelve99-cust.net`.
- Cel: traceroute z epizodu pokaże, na którym hopie giną pakiety (hipoteza: przeciążony twelve99, nie Railway).
- Kontrola: inne cele europejskie (Leaseweb Amsterdam) idą INNĄ trasą (23ms, nie twelve99) i w dobrym oknie są czyste — pokazać kontrast.

## DANE (stałe w skrypcie)
- Cel docelowy: `66.33.22.230` (switchback.proxy.rlwy.net) — oczekiwane STRATY w epizodzie
- Kontrola globalna: `1.1.1.1`, `8.8.8.8` — oczekiwane CZYSTO
- Kontrola europejska (ten sam region, inna trasa): `mirror.nl.leaseweb.net` (5.79.108.33, Amsterdam) — oczekiwane CZYSTO
- Narzędzia na serwerze: `ping`, `traceroute`, `tracepath` (POTWIERDZONE dostępne). `mtr` BRAK — NIE używać.

---

## ZAKRES — ZMIANY W railway_monitor.php

### 1. Nowa funkcja `captureNetworkDiag(string $reason): string`
Odpala zestaw pomiarów, zwraca sformatowany tekst (i zapisuje do pliku — patrz 3). Każde polecenie z TWARDYM timeoutem (nie może zablokować monitora):
- `ping -c 15 -W 2 66.33.22.230` (Railway — łap straty)
- `ping -c 15 -W 2 5.79.108.33` (Leaseweb Amsterdam — kontrola europejska)
- `ping -c 15 -W 2 1.1.1.1` (Cloudflare — kontrola globalna)
- `ping -c 15 -W 2 8.8.8.8` (Google — kontrola globalna)
- `traceroute -w 2 -m 20 66.33.22.230` (Railway — łap hop gdzie giną pakiety)
Każde owinięte w `timeout <N> <cmd>` (np. `timeout 30 traceroute ...`) LUB proc_open z limitem. Ping 15 pakietów × ~2s worst-case + margines. traceroute max ~40s. Cały zrzut nie może przekroczyć ~90s (inaczej ryzyko że guard uzna monitor za martwy — patrz uwaga niżej).
Wykonanie przez `shell_exec`/`proc_open`; sanitizacja NIE potrzebna (brak inputu użytkownika, stałe IP).

### 2. Wpięcie w trigger epizodu (blok "--- alert / recovery ---")
W miejscu gdzie `$streak[$worst] >= $ALERT_STREAK && !$alertActive` (start epizodu): PO wysłaniu alertu mailowego (albo zamiast — mail zostaje bez zmian) wywołać `captureNetworkDiag("epizod-start {$worst}")`. To łapie diagnostykę na POCZĄTKU epizodu.
DODATKOWO przy recovery (`$alertActive && $okStreak >= $RECOVERY_OK`): drugi `captureNetworkDiag("epizod-koniec")` — trasa na końcu epizodu (decyzja 63a: traceroute na starcie i końcu, NIE co cykl).

### 3. Zapis diagnostyki do osobnego pliku
`~/_diag/incident_YYYYMMDD_HHMMSS.txt` — jeden plik per epizod (timestamp startu). Zawiera: nagłówek (powód, czas UTC+WAW, host), potem surowe wyniki wszystkich pingów i traceroute z etykietami. Format czytelny do wklejenia/analizy. NIE do logu głównego (za duże) — osobny plik.
Dodatkowo: jednolinijkowy wpis w logu głównym `### DIAG zapisano: incident_....txt` żeby było wiadomo że powstał.

### 4. Pomiar OKNA niedostępności (wzmocnienie istniejącej logiki)
Obecnie epizod ma tylko czas startu w `$episodeInfo`. Dodać: zapamiętać `$episodeStartW` (DateTime) przy starcie epizodu; przy recovery policzyć długość okna (recovery_time − start_time) i zapisać do logu ORAZ dopisać do pliku incydentu: `### OKNO NIEDOSTEPNOSCI: start HH:MM:SS, koniec HH:MM:SS, czas trwania Xm Ys`. To jest kluczowa liczba dla Smarthost (czas trwania, nie punkt).

### 5. Zapis celu Railway (region) do pliku incydentu
W nagłówku pliku dopisać stałą informacyjną: "Cel: switchback.proxy.rlwy.net (66.33.22.230), baza Railway region EU West/Amsterdam. Trasa przez twelve99/Arelion." — kontekst dla przyszłej analizy/decyzji o alternatywie.

## GRANICE / OSTROŻNOŚĆ
- NIE ruszać logiki pomiarowej pgProbe/tcp/alert/recovery/digest — tylko DOŁOŻYĆ diagnostykę i pomiar okna.
- NIE dodawać wysyłki SMTP do Smarthost (faza 2, osobny task). Faza 1 = tylko plik + istniejący mail do Karola.
- **UWAGA guard (CHAT-T-109)**: cron-guard wskrzesza monitor gdy log nie rośnie >60s. captureNetworkDiag może trwać ~90s i zamrozić pętlę → guard mógłby zabić monitor W TRAKCIE zrzutu. ROZWIĄZANIE: albo (a) uruchamiać diagnostykę w tle (`proc_open` bez czekania, zapis async do pliku) żeby pętla główna szła dalej i log rósł, albo (b) w trakcie diagnostyki dopisywać "keepalive" linie do głównego logu co ~20s. CC wybiera bezpieczniejsze — rekomendacja (a): odpal diag jako osobny proces w tle (`nohup ... &` lub proc_open bez blokowania), pętla monitora leci dalej. To NAJWAŻNIEJSZY punkt — źle zrobione, guard zabije monitor w środku epizodu.
- Timeout każdego polecenia OBOWIĄZKOWY — bez tego wiszący traceroute przy blackhole zawiesza wszystko.

## KRYTERIA AKCEPTACJI
- [ ] Sztuczny test: wymuś epizod (np. tymczasowo zły DSN/port w kopii skryptu LUB poczekaj na realny epizod wieczorem) → powstaje `incident_*.txt` z 4 pingami + traceroute + oknem niedostępności.
- [ ] Pętla monitora NIE zamiera podczas diagnostyki (log główny rośnie dalej / guard nie restartuje) — zweryfikować że diag idzie w tle.
- [ ] Plik incydentu ma czytelny format: nagłówek, ping Railway (straty), ping Leaseweb/1.1.1.1/8.8.8.8 (kontrola), traceroute Railway, okno niedostępności.
- [ ] `php -l` clean.
- [ ] Istniejące metryki/alert/digest działają bez zmian (regresja zero).

## DEPLOY
Skrypt działa na serwerze w `~/_diag/` (NIE w chat.divezone.pl, NIE przez rsync deploy ADR-089). To narzędzie diagnostyczne, nie kod aplikacji. Aktualizacja: podmiana pliku na serwerze + restart monitora (kill + nohup restart wg wzorca CHAT-T-108/109) + weryfikacja że guard go widzi. STOP przed restartem — Karol zatwierdza (monitor to żywe narzędzie w trakcie zbierania dowodu, nie chcemy luki).
Kopia skryptu do repo `_docs/scripts/railway_monitor.php` (źródło prawdy) + commit.

## RAPORT
KROK końcowy: status + raport. Commit: "CHAT-T-119 integration: przechwytywanie diagnostyki sieciowej w chwili epizodu Railway (dowod Smarthost #167585)". Osobny docs: commit ze statusem. ADR niepotrzebny (narzędzie diagnostyczne, nie decyzja architektoniczna) — chyba że CC uzna inaczej.

---

## WYNIK (2026-07-01)
Zrealizowane w `railway_monitor.php` na serwerze (`~/_diag/`) + kopia w repo `_docs/scripts/railway_monitor.php` (md5 identyczne). Zmiana czysto addytywna — logika pgProbe/tcp/alert/recovery/digest nietknięta (regresja zero).

**Dodane:**
- `captureNetworkDiag(incidentFile, reason, waw, utc, preface='')` — ping ×15 do 4 celów (Railway 66.33.22.230, Leaseweb AMS 5.79.108.33, 1.1.1.1, 8.8.8.8) + traceroute do Railway. Każde polecenie w `timeout`. Uruchamiane **w tle** (`nohup bash -c '...' &`) → pętla monitora nie zamiera, guard (CHAT-T-109, próg 60 s) nie ubija monitora podczas ~90 s zrzutu. **To był kluczowy punkt.**
- Wpięcie w START epizodu (`streak>=ALERT_STREAK && !alertActive`) i przy RECOVERY. Jeden plik per epizod: `~/_diag/incident_YYYYMMDD_HHMMSS.txt` (timestamp = start okna). W logu głównym jednolinijkowe `### DIAG zapisano: …`.
- Pomiar OKNA niedostępności od **1. FAIL serii** (`$firstFailW`, zamrożony przy alercie jako `$episodeStartW`) do powrotu OK — `### OKNO NIEDOSTEPNOSCI: start … koniec … Xm Ys` w pliku i logu, + w mailu recovery.
- Nagłówek pliku ze stałą informacją o regionie (Railway EU West/Amsterdam, trasa twelve99/Arelion).
- **Ponad task:** `flock` na `incident_*.txt.lock` serializuje diag-start i diag-koniec przy krótkim epizodzie (nagłówek + linia OKNA idą pod blokadą jako `printf`, nie synchronicznie z procesu głównego) — brak przeplotu wyjścia. Wykryte i naprawione podczas testu.

**Weryfikacja (sztuczny epizod, izolowana kopia — osobny katalog, mail off, self-stop):**
- Pętla NIE zamarła — wszystkie 15 cykli w logu, cykl zalogowany 2 s po odpaleniu diagu (dowód pracy w tle). ✅
- `incident_*.txt`: 4 pingi + traceroute na starcie i końcu, okno `0m 22s` (mierzone od 1. FAIL). ✅
- Format czytelny, bez przeplotu po fixie flock. ✅  |  `php -l` czysty. ✅  |  regresja zero. ✅
- Dowód: traceroute potwierdził trasę z taska — hop 10 = `railwaycorp-ic-390073.ip.twelve99-cust.net` (twelve99/Arelion); w dobrym oknie 0% strat na wszystkich celach. Przykład: `_instances/integration/handoff/CHAT-T-119_przyklad_incident.txt`.

**Deploy:** backup `railway_monitor.php.bak_pre_CHAT-T-119_20260701_133603`, podmiana pliku, restart wg wzorca CHAT-T-108/109 (pid 3967908 ALIVE, pidfile spójny, log świeży → guard widzi zdrowy monitor). FAZA 1 (tylko plik + istniejący mail do Karola); SMTP do Smarthost = FAZA 2 (osobny task).
