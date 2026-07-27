# CHAT-T-108 — INTEGRATION: monitoring stabilności Railway (realne zapytania + alert + ciągłe działanie)

**Instancja:** integration (diagnostyka, skrypty w `~/_diag/` na serwerze + `_diag_local/` lokalnie).
**Powiązane:** incydent 2026-06-28 (seria błędów 16:23–17:15 UTC, nawroty od 10-06), istniejący `~/_diag/railway_monitor.php` (serwer) + `_diag_local/railway_monitor_local_v2.py` (lokalny). Decyzja P35c.
**Status:** DONE (2026-06-28). Serwer: monitor ciągły 5s + cron-guard DZIAŁA (pid 2336958). Lokalny: rozszerzony, komenda startu dla Karola w raporcie `_instances/integration/handoff/T-108_done.md`. Warunek odblokowania czata: ≥48-72h ciągłego monitoringu BEZ nawrotu godzinnej serii.

## PROBLEM
Istniejący monitor mierzy tylko `SELECT 1` co 25 s. Awaria 28-06 pokazała, że: (1) padają REALNE zapytania pod obciążeniem (chip-tree, settings, upserty), nie „SELECT 1" — monitor robiący tylko SELECT 1 pokazał OK 11 min po awarii i fałszywie sugerował „brak problemu"; (2) 25 s to za rzadko przy 5–17 błędach/min; (3) brak alertu = awarię widać dopiero po fakcie w logu.

## CEL
Monitor, który mierzy TO, CO REALNIE PADA (te same zapytania co backend), gęsto (co 5 s), ciągle (dni, nie noc), z alertem mailowym przy serii FAIL. Dwie trasy równolegle: serwer→Railway (produkcyjna) i lokalny→Railway (kontrolna, inne IP).

## KROK 0 — pull + rozpoznanie
1. `git pull`.
2. Przeczytaj istniejący `~/_diag/railway_monitor.php` (serwer) i `_diag_local/railway_monitor_local_v2.py` (lokalny, już ma: output na konsolę+plik, watek z twardym limitem, czyta DATABASE_URL z .env). Rozszerzasz oba, nie piszesz od zera.
3. Ustal realne zapytania backendu do odwzorowania: `SELECT value FROM divechat_settings WHERE key=?` (SettingsStore), budowa drzewa z `divechat_chip_nodes` (ChipTreeService), przykładowy upsert rate-limit (bez psucia danych — użyj klucza testowego `__monitor_probe__`). NIE modyfikuj danych produkcyjnych poza własnym kluczem probe.

## KROK 1 — rozszerz pomiar o realne zapytania (P35c)
Każdy cykl mierzy (z czasem ms i OK/FAIL osobno):
1. `railway_tcp` — TCP connect (jak teraz).
2. `pg_select1` — SELECT 1 (baseline, jak teraz).
3. `pg_settings` — `SELECT value FROM divechat_settings WHERE key='model_primary'` (realny odczyt jak ChatController).
4. `pg_chiptree` — `SELECT count(*) FROM divechat_chip_nodes WHERE active` (proxy dla budowy drzewa; pełne drzewo opcjonalnie).
5. `pg_upsert` — upsert do `divechat_rate_limit` (lub innej tabeli z upsertem) na kluczu `__monitor_probe__` (test ścieżki ZAPISU, która też padała — NudgeEventStore/RateLimiter). READ-ONLY na danych realnych; probe-key czyszczony okresowo.
6. `github` — kontrola łącza (jak teraz).
Log: jedna linia/cykl z wszystkimi metrykami. Format zachowaj kompatybilny (parsowalny).

## KROK 2 — zagęszczenie + okno ciągłe
- INTERVAL 25 → 5 s (oba monitory).
- Usuń twarde okno „stop o 07:15" — monitor ma działać CIĄGLE (dni). Rotacja logu dzienna (plik per YYYYMMDD już jest). Dodaj lekki self-heartbeat (np. co 100 cykli linia `# alive Nxxx`).

## KROK 3 — alert mailowy przy serii FAIL
- Reużyj wzorca z `railway_summary_mail.php` (już istnieje, wysyła mail). 
- Trigger: ≥3 FAIL z rzędu na DOWOLNEJ metryce PG (nie tcp/github) → mail „[DIVECHAT MONITOR] Railway degradacja: <metryka> FAIL xN od <czas>". 
- Dedup: jeden mail na epizod (flaga/cooldown np. 15 min), nie spam co 5 s. Mail „recovery" gdy wróci OK po serii.
- Adresy jak w istniejącym: k.susicki@divezone.pl + gmail.

## KROK 4 — uruchomienie obu tras (instrukcja dla Karola, NIE auto)
- Serwer: zaktualizowany `railway_monitor.php` pod nohup/screen + cron-guard (restart jeśli padł). Komenda w raporcie.
- Lokalny: `railway_monitor_local_v2.py` (rozszerzony) — Karol odpala w Terminalu VM: `caffeinate -i nohup python3 railway_monitor_local_v2.py >> monitor_local_v2.out 2>&1 &` (po sprawdzeniu na pierwszym planie, że gada). Tylko JEDNA instancja — najpierw `pkill -f railway_monitor_local`.

## KROK 5 — raport + porządek repo
- `git add` per ścieżka: skrypty `_diag_local/*` (lokalne wersjonujemy w repo; serwerowy `~/_diag/` NIE jest w repo — dołącz jego treść jako `_docs/scripts/railway_monitor.php` kopia referencyjna, jak sentinel.sh). 
- Commit `feat(diag): monitoring stabilności Railway — realne zapytania + alert + ciągłe (CHAT-T-108)`. Push. 
- Raport: jakie metryki, próg alertu, komendy uruchomienia obu tras, gdzie logi.

## KRYTERIA AKCEPTACJI
- [ ] Monitor mierzy 5 metryk PG (select1, settings, chiptree, upsert-probe) + tcp + github, nie tylko SELECT 1.
- [ ] Interwał 5 s, działanie ciągłe (bez okna stop), heartbeat.
- [ ] Alert mailowy przy ≥3 FAIL z rzędu, z dedup i recovery.
- [ ] Obie trasy (serwer + lokalna) zaktualizowane, instrukcje uruchomienia w raporcie.
- [ ] Probe zapisu nie psuje danych produkcyjnych (klucz `__monitor_probe__`).

## ZASADA ODBLOKOWANIA CZATA (do decyzji Karola)
Czat wraca na stronę dopiero gdy: (1) CHAT-T-107 (odporność) wdrożone, ORAZ (2) monitor pokazuje ≥48–72h ciągłego działania BEZ serii FAIL na metrykach PG (jedna spokojna noc nie wystarcza — problem nawraca od 10-06). Jeśli seria wróci — dane z monitora idą do zgłoszenia Railway + decyzja o alternatywnym hostingu bazy.
