═══ CHAT-T-195 · INTEGRATION · tunel smarthost → mydevil → Railway, przywrócenie czatu ═══

# CHAT-T-195 — czat i embeddingi do Railway przez tunel SSH na mydevil

Autor zlecenia: architekt. Data: 2026-11-05. Karta: Chat - 150. Decyzja: ADR-141 (Karol, 2b).
Charakter: **AWARIA PRODUKCJI.** Czat od 2026-11-04 ok. 08:00 CET działa bez bazy (`/api/health` → `postgres:false`).

## 1. Stan (pomiary architekta, 2026-11-05)

- smarthost → `66.33.22.230:14368`, `66.33.22.230:443`, `69.46.46.46:443`: FAIL (blokada IP Railway na publicznej liście reputacji, smarthost nie odblokuje).
- `s81.mydevil.net` → `66.33.22.230:14368`: OK. smarthost → `s81.mydevil.net:22`: OK.
- Na smarthost nie ma `autossh` ani klucza do mydevil (`~/.ssh`: `authorized_keys`, `known_hosts`, `putty/`).
- Jedno źródło adresu bazy na smarthost: `~/public_html/chat.divezone.pl/.env`, klucz `DATABASE_URL`, `sslmode=disable`. Czyta je backend, `scripts/embeddings/*.py` (`_resolve_env_file`) i `~/_diag/railway_monitor.php` (`$DBURL = $_ENV['DATABASE_URL'] ?? ''`).
- `.env` ma ustawione `SMARTHOST_TICKET_MAIL`, więc powiadomienia monitora do smarthost są aktywne. Dlatego monitor NIE może zacząć mierzyć tunelu.
- `_docs/scripts/railway_monitor.php` lokalnie == prod (md5 `a2f7347b046cc1710dabd9b6903341cb`).
- Wzorzec konstrukcji tunelu i zgoda mydevil na stały tunel: `CHAT-T-193` §1, §3, §4 (zadanie NIEWYKONANE, inny cel: PG mydevil). Bierzesz z niego zasady, nie numer portu.

## 2. Docelowo

```
127.0.0.1:14368 (smarthost) ──ssh──▶ s81.mydevil.net ──tcp──▶ switchback.proxy.rlwy.net:14368
```

- `DATABASE_URL` → host `127.0.0.1`, port `14368`, reszta bez zmian. Stara wartość w nowym kluczu `DATABASE_URL_DIRECT`.
- `railway_monitor.php`: źródło URL = `DATABASE_URL_DIRECT`, gdy niepusty, inaczej `DATABASE_URL`. Jedna zmiana, nic więcej w tym pliku.
- Nowy `_docs/scripts/railway_tunnel_guard.sh` → `~/_diag/railway_tunnel_guard.sh`, cron `* * * * *`, log `~/_diag/railway_tunnel_guard.log`.

## 3. Wymagania tunelu

- Nasłuch **wyłącznie** `127.0.0.1`. `ExitOnForwardFailure=yes`, `BatchMode=yes`, `ServerAliveInterval`/`ServerAliveCountMax` tak, żeby martwe łącze wykrywać w około 30 s. Wartości uzasadnij w raporcie.
- Nowa para kluczy ed25519, tylko dla tunelu, bez hasła. `authorized_keys` na mydevil: `restrict,port-forwarding,permitopen="switchback.proxy.rlwy.net:14368"`. Najpierw sprawdź, czy sshd na s81 zna `restrict`. Jeśli nie, użyj odpowiednika z `no-pty,no-agent-forwarding,no-X11-forwarding,no-user-rc,command="/usr/bin/false"` i to zgłoś.
- `known_hosts` dla s81 na smarthost: odcisk porównany z wpisem s81 w `~/.ssh/known_hosts` na Macu. Rozbieżność = STOP.
- Strażnik: `flock`, wskrzeszenie po padnięciu procesu, wykrycie zamarcia po objawie (port 127.0.0.1:14368 nie przyjmuje TCP), wpis w logu: znacznik czasu, przyczyna, pid. Wzór: `railway_monitor_guard.sh` (binarka i PATH pod cronem, patrz komentarz CHAT-T-190 w tym pliku).
- Opisz w raporcie, co strażnik widzi, gdy żyje ssh i łącze, ale padł cel (Railway z mydevil). Ten przypadek ma być w logu rozpoznawalny, nawet jeśli restart tunelu nie pomoże.

## 4. KROKI

KROK 0. `git fetch` i `git rev-list --count HEAD..origin/main`. Gdy > 0: STOP i pytaj (w drzewie są cudze niezacommitowane zmiany, nie stashuj ich). Przeczytaj: ADR-141, CHAT-T-193, `_docs/scripts/railway_monitor_guard.sh`, `_docs/scripts/railway_monitor.php` (okolica `$DBURL =`), `_docs/44` sekcja PUŁAPKI, CLAUDE.md „Mapa infrastruktury i wdrożeń”. Składnię `check_deploy.py` odczytaj z pliku.

KROK 1. Rekonesans read-only, wyniki do raportu: czy `127.0.0.1:14368` jest wolny na smarthost, `ssh -V` po obu stronach, czy backend wczytuje `.env` przy każdym żądaniu (czy zmiana działa bez restartu PHP), jak zachowają się połączenia trwałe PDO (ADR-117) po zmianie DSN.

KROK 2. Lokalnie: strażnik i zmiana jednej linii w `railway_monitor.php`. `bash -n`, `php -l`. Recenzja `/codex` diffu. Uwagi Codex wpisz do raportu bez rozstrzygania.

KROK 3. **STOP (ADR-089).** Pokaż: polecenie tunelu, wpis `authorized_keys` bez samego klucza, linię crona, diff `.env` (same NAZWY kluczy i host:port, żadnych haseł), diff monitora, plan wycofania. Czekaj na „deployuj”.

KROK 4. Po „deployuj”, w tej kolejności:
 a) Kopie do `_deploy_bak/` z datą: `.env`, `~/_diag/railway_monitor.php`, `crontab -l`, na mydevil `~/.ssh/authorized_keys`.
 b) Klucz, wpis na mydevil, `known_hosts`.
 c) Wgraj strażnika, uruchom go raz ręcznie. Dowód: `ss -ltnp` (albo `netstat`) z `127.0.0.1:14368`. Przez tunel, bez ruszania `.env`: `SELECT 1` oraz jedno zapytanie wektorowe `LIMIT 10` na `encyclopedia_chunks`, po 10 powtórzeń, p50/p95. **Gdy nie przechodzi: nie idziesz dalej, raport.**
 d) Linia crona strażnika.
 e) `.env`: dopisz `DATABASE_URL_DIRECT`, zmień host:port w `DATABASE_URL`. Bez wypisywania wartości. Wgraj `railway_monitor.php`, potwierdź md5 local == prod i `ea-php84 -l`. Zrestartuj monitor (zabij pid, guard wskrzesi) i pokaż, że nowy log dalej sonduje `66.33.22.230:14368` (FAIL jest tu oczekiwany).
 f) Weryfikacja: `/api/health` → `postgres:true`, smoke z `check_deploy.py`, jedna realna rozmowa przez API czatu z odpowiedzią modelu i zapisem do `divechat_*` (pokaż wiersz), najbliższy przebieg `cron_editorial_picks_expire.php` bez błędu.
 g) Embeddingi: jeden przebieg `run_nightly.py --mode changed` tak jak w cronie (`timeout 1800`, ten sam log). Pokaż nowy `divechat_embeddings.last_success`.
 h) Test strażnika: ubij ssh tunelu, pokaż wpis w logu, czas do odtworzenia i `postgres:true` po nim.

KROK 5. Dokumentacja, osobny commit `docs:`: wpis NA GÓRZE `_docs/21_STATUS_PROJEKTU.md`, CLAUDE.md sekcja „Mapa infrastruktury i wdrożeń”: tunel, `DATABASE_URL` vs `DATABASE_URL_DIRECT`, strażnik, procedura wycofania (ADR-141 „Wyjście”).

KROK 6. Raport `_reports/CHAT-T-195_tunel-railway-przez-mydevil.md` + git. Status karty zostawiasz architektowi.

## 5. Git

`git status` przed commitem, `git add` per ścieżka, nigdy `git add .`. Konwencja z `git log`: `fix(CHAT-T-195): …` dla kodu, `docs(CHAT-T-195): …` dla dokumentacji. `git push origin main`. Nie `--amend`.

## 6. NIE ruszać

`config/tools.php`, `config/routes.php`, `_ops/newtmp2_root/purge_litespeed.php` (SEKRET), `_docs/scripts/railway_monitor_guard.sh` (cudza niezacommitowana zmiana), ADR-y, `_docs/44`, `sslmode` (Chat - 90), `SMARTHOST_TICKET_*`, `newtmp2`, baza `p1137_divechat_t` na mydevil, `CHAT-T-193`, istniejące klucze SSH. Żadnego blanket-rsync. Żadnego nasłuchu na `0.0.0.0`.

## 7. Wycofanie (do raportu, nie wykonywać)

`DATABASE_URL` := `DATABASE_URL_DIRECT`, usunięcie linii crona strażnika, zabicie tunelu, usunięcie wpisu z `authorized_keys` na mydevil. Kopie z KROKU 4a.

═══ CHAT-T-195 · INTEGRATION · tunel smarthost → mydevil → Railway, przywrócenie czatu ═══
