═══ CHAT-T-195 · INTEGRATION · DEPLOYED ═══

# CHAT-T-195 — czat i embeddingi do Railway przez tunel SSH na mydevil

**Instancja:** INTEGRATION. **Data:** 2026-11-05. **Karta:** Chat - 150. **Decyzja:** ADR-141 (Karol, 2b), zgoda na deploy z wariantami A, B, C (Karol, 2026-11-05).
**Zlecenie:** `_instances/integration/tasks/CHAT-T-195_INTEGRATION_tunel-railway-przez-mydevil.md`.
**Commity:** `4522631` fix (strażnik + monitor), `17c6951` fix (strażnik, wyścig `/proc` z KROKU 4h), commit docs(CHAT-T-195) z tym raportem, STATUS, CLAUDE.md i recenzją Codex.
**Czasy:** wszystkie godziny CET (serwer), o ile nie zaznaczono UTC.

---

## WERDYKT

Czat znowu ma bazę. Od 14:12:14 `DATABASE_URL` wskazuje tunel `127.0.0.1:14368` → `s81.mydevil.net` → `switchback.proxy.rlwy.net:14368`, a `/api/health` zwraca `postgres:true`.
Realna rozmowa przez API (rozmowa 1709) przeszła z odpowiedzią modelu i zapisem do `divechat_*`.
Przebieg embeddingów jak w cronie zakończył się `OK`, `last_success` = 2026-11-05 14:14:09.
Strażnik dwukrotnie odtworzył zabity tunel, w 28 s i 32 s.
Monitor dalej mierzy trasę bezpośrednią: `railway_tcp FAIL` do `66.33.22.230:14368`, czego oczekiwano.
Przebieg `cron_editorial_picks_expire.php` o 15:00 przeszedł bez błędu (`expired 0 pick(s)`). Tunel od restartu testowego o 14:16:06 stał bez przerwy, co najmniej do 15:02 (ten sam pid, zero wpisów strażnika).

---

## 1. KROK 0 i stan wejściowy

- Repo czatu, gałąź `main`, `git rev-list --count HEAD..origin/main` = 0. W trakcie zadania na origin pojawił się commit architekta `a648fdc` (`_docs/46`), niezwiązany z moimi plikami.
- `.sesja_CDK` ma prefiks CDK, a zadania w repo noszą prefiks CHAT-T. To ta sama sesja projektu.
- `_docs/scripts/railway_monitor_guard.sh` różni się od HEAD tylko trybem pliku (100755 → 100644). To cudza zmiana, nie ruszałem jej.
- Przeczytane: zlecenie, ADR-141, CHAT-T-193, `railway_monitor_guard.sh`, `railway_monitor.php` (okolica `$DBURL =`), `_docs/44` sekcja PUŁAPKI, CLAUDE.md „Mapa infrastruktury”, składnia `check_deploy.py` (z pliku: `--smoke-only`, mapuje tylko `standalone/` i `modules/`, więc md5 monitora z `_docs/scripts/` liczyłem ręcznie).

## 2. KROK 1 — rekonesans (pomiar 2026-11-05 ok. 13:48 CET)

| pytanie | wynik |
|---|---|
| `127.0.0.1:14368` na smarthost | wolny. Jedyne gniazdo z 14368 to SYN-SENT monitora `193.93.88.95 → 66.33.22.230:14368` |
| `ssh -V` smarthost | `OpenSSH_8.0p1, OpenSSL 1.1.1k FIPS` |
| `ssh -V` / sshd s81 | `OpenSSH_9.9p2` (klient i `strings /usr/sbin/sshd`), FreeBSD 14.3-RELEASE-p19. `restrict` obsługiwane od OpenSSH 7.2 |
| narzędzia smarthost | `autossh` BRAK, `flock`, `timeout`, `ss`, `setsid` obecne |
| s81 → Railway | `nc -z switchback.proxy.rlwy.net 14368` OK, nazwa → `66.33.22.230` |
| forwarding na s81 | działa: tymczasowy `-L` z Maca istniejącym kluczem, SSLRequest → odpowiedź PG `N` w 112 ms |
| `.env` per żądanie | TAK. `public/index.php` woła `Config::load()` przy każdym żądaniu, Dotenv `createImmutable` bez adaptera putenv. Zmiana `.env` działa bez restartu PHP, co potwierdziło `postgres:true` sekundę po zapisie |
| PDO trwałe (ADR-117) | pula kluczowana DSN-em. Nowy host:port daje nowy wpis w puli, stare uchwyty do `switchback` były i tak martwe od 2026-11-04. Workery `lsphp` żyją od kilku do kilkudziesięciu minut (etime 0:00 do 25:57), więc same się odnawiają. Po wdrożeniu 30 równoległych `/api/health` → 30× `postgres:true` przy 4 aktywnych workerach |
| kto czyta `DATABASE_URL` na serwerze | backend (`PostgresConnection.php`, `Tools/ResendOrderInfo.php`, `scripts/verify_nudge_correlation.php`), embeddingi (`batch_embed_multivector.py`, `generate_embeddings.py`), monitor |

## 3. Tunel

Polecenie (uruchamia je strażnik, `start_tunnel()`):

```
setsid /usr/bin/ssh -N -T -n -i /home/divezone/.ssh/id_ed25519_railway_tunnel \
  -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile=/home/divezone/.ssh/known_hosts -o ExitOnForwardFailure=yes \
  -o ServerAliveInterval=10 -o ServerAliveCountMax=2 -o ConnectTimeout=15 \
  -o ControlMaster=no -o ControlPath=none \
  -L 127.0.0.1:14368:switchback.proxy.rlwy.net:14368 karlsenconsultng@s81.mydevil.net \
  >> ~/_diag/railway_tunnel_ssh.out 2>&1 < /dev/null 9>&- &
```

Uzasadnienie opcji:
- `-L 127.0.0.1:…`: jawny adres bindowania, nasłuch tylko na loopback. Dowód: `ss -ltnp` → `127.0.0.1:14368 … users:(("ssh",pid=2507574,fd=4))`.
- `ServerAliveInterval=10`, `ServerAliveCountMax=2`: ssh po 10 s ciszy wysyła zapytanie i kończy się, gdy 2 zostaną bez odpowiedzi. Martwe łącze wykrywa więc po około 30 s, a strażnik restartuje je w najbliższej minucie crona. Przy 3 byłoby to ok. 40 s. Fałszywe zerwanie przy CountMax=2 kosztuje jeden restart (zmierzone ok. 1 s).
- `ExitOnForwardFailure=yes`: przy zajętym porcie ssh się kończy, zamiast działać bez przekierowania.
- `BatchMode=yes`: bez pytań o hasło lub hosta, pod cronem nikt nie odpowie.
- `StrictHostKeyChecking=yes`: nieznany lub zmieniony klucz s81 oznacza odmowę.
- `ConnectTimeout=15`: zawieszony connect nie blokuje strażnika dłużej niż `START_WAIT_S=20`.
- `IdentitiesOnly`, `ControlMaster=no`: wyłącznie klucz tunelu, bez współdzielonych gniazd.
- `-N -T -n`, `setsid`: bez komendy zdalnej, bez pty, stdin z `/dev/null`, proces poza grupą procesów crona.
- `9>&-` (wariant B): ssh nie dziedziczy deskryptora blokady `flock`. Dowód: `/proc/<pid>/fd` procesu ssh ma tylko 0, 1, 2 i dwa gniazda.

## 4. Klucz, `authorized_keys`, `known_hosts`

- Nowy klucz `~/.ssh/id_ed25519_railway_tunnel` (ed25519, bez hasła, 600), odcisk `SHA256:Jt1mv20GuD1dVnAeYvkZAUgoSDWSIvIWVIQFtDyKj4o`, komentarz `railway-tunnel CHAT-T-195 divezone@divezonededyk`.
- **Adres `from=` zmierzony** (warunek 1 Karola). Tymczasowy wpis `restrict,command="echo SSH_CLIENT=$SSH_CLIENT"` i połączenie ze smarthost dały `SSH_CLIENT=193.93.88.95 58624 22`. Adres jest zgodny z założonym, więc wpisałem `193.93.88.95`. Wpis tymczasowy zastąpiłem docelowym atomowo (`mv`).
- **Wpis docelowy na s81, dosłownie, bez klucza** (wariant A):
  ```
  restrict,port-forwarding,permitopen="switchback.proxy.rlwy.net:14368",command="/usr/bin/false",from="193.93.88.95" ssh-ed25519 <KLUCZ> railway-tunnel CHAT-T-195 divezone@divezonededyk
  ```
  Istniejący wpis (linia 1) jest nietknięty, porównany `cmp` z kopią. Odcisk `SHA256:U/pledNX…` należy do klucza Maca `~/.ssh/id_ed25519`, mimo komentarza `divezone@divezonededyk.smarthost.pl`. Nie ruszałem go.
- **Testy negatywne** (warunek 2 Karola), ze smarthost kluczem tunelu:

  | próba | wynik |
  |---|---|
  | exec `id; hostname` | rc=1, puste wyjście (wymuszone `/usr/bin/false`) |
  | PTY `-tt` | rc=255, `PTY allocation request failed on channel 0` |
  | `-L` na inny cel (`pgsql81.mydevil.net:5432`) | `channel 1: open failed: administratively prohibited`, sonda dostaje zerwanie |
  | `-R 127.0.0.1:15994` | rc=255, `Error: remote port forwarding failed for listen port 15994` |
  | kontrola dodatnia: dozwolony cel | tunel strażnika działa, `/api/health` `postgres:true` |

  **Zastrzeżenie do `-R`.** Kontrola dodatnia nieograniczonym kluczem Maca też dostaje `remote port forwarding failed`. Odmowę daje globalna konfiguracja sshd na s81, a nie nasz wpis, więc ten test nie rozróżnia wpisu od serwera. Warunek „jeśli -R przechodzi” nie zaszedł i `permitlisten` nie dopinałem. Gdyby mydevil kiedyś włączył zdalne przekierowanie, wpis go nie zablokuje (`port-forwarding` po `restrict` dopuszcza oba kierunki). Do decyzji: dopięcie `permitlisten` profilaktycznie.
- **`known_hosts` bez zmian.** smarthost ma wpis s81 ECDSA `SHA256:j1Tw2aIpOv3Yd/lNQ/Ryd32LwuPfGMx5GLCE4+onP8s`, identyczny z wpisem ECDSA na Macu. ED25519 z `ssh-keyscan` ze smarthost (`SHA256:rb2UWV0Y…`) jest identyczny z wpisem ED25519 na Macu. Połączenie z `StrictHostKeyChecking=yes` przeszło na istniejącym wpisie.

## 5. Strażnik `railway_tunnel_guard.sh`

Repo `_docs/scripts/railway_tunnel_guard.sh` (100755), produkcja `~/_diag/railway_tunnel_guard.sh` (`-rwxr-xr-x`), md5 lokalnie == prod `1259ea20890f95449ecce3e876ac0e4a`.
Cron: `* * * * * /home/divezone/_diag/railway_tunnel_guard.sh >> /home/divezone/_diag/railway_tunnel_guard.log 2>&1` (crontab 139 → 143 linii, początek identyczny z kopią).

Kryterium zdrowia to odpowiedź Postgresa przez tunel, a nie sam proces. Sonda wysyła SSLRequest (8 bajtów) na `127.0.0.1:14368` i czeka 5 s na 1 bajt.

| sonda | znaczenie | reakcja |
|---|---|---|
| `OK` (`N`/`S`) | cała ścieżka żyje | nic, wpis tylko przy powrocie z innego stanu |
| `ODMOWA` | nikt nie słucha na 127.0.0.1 | restart |
| brak pid / cudzy pid | ssh padł | restart |
| `EOF` | połączenie zamknięte bez bajtu, zwykle s81 odrzucił kanał | jeden restart próbny (wariant C), potem `CEL ODRZUCA` |
| `CISZA` | brak odpowiedzi w 5 s: zamarłe łącze albo milczący cel | restart, a jeśli świeży tunel też milczy, to `CEL` |

**Ssh i łącze żyją, padł cel (Railway z s81).** Zmierzone z Maca przez s81: cel odrzucający połączenie daje zerwanie gniazda po 23 ms, a ssh pisze `open failed: connect failed: Connection refused`. Cel milczący (blackhole) daje timeout, nieodróżnialny od zamarłego łącza. Strażnik robi wtedy jeden restart. Jeśli świeży tunel, czyli ssh, który przed chwilą się uwierzytelnił, też nie dostaje odpowiedzi, w logu pojawia się:
`… restart: ssh zestawiony pid=…, ale CEL nie odpowiada przez swiezy tunel (sonda=EOF|CISZA po Ns) -> problem po stronie s81->Railway, nie lacza smarthost->s81. ssh: <ostatnia linia ssh>`.
Kolejne minuty dają wpisy `CEL ODRZUCA` lub `CEL MILCZY` bez restartu. Następna próba restartu jest najwcześniej po 10 min (`CEL_RETRY_S`). Martwe łącze smarthost→s81 kończy sam ssh (ServerAlive, 30 s), a strażnik widzi wtedy brak pid albo `ODMOWA`.

Testy:
- `bash -n` i shellcheck (`-S warning`) czyste.
- Sonda na bashu 4.4 serwera pod `env -i PATH=/usr/bin:/bin` z emulatorem na 127.0.0.1: `OK` 3 ms, `EOF` 2 ms, `CISZA` 5003 ms, `ODMOWA` 2 ms, 4/4 PASS. Pierwsza próba na Macu dała fałszywe PASS (brak bash 4 i `timeout`, zsh nie rozbił zmiennej, więc porównywał puste z pustym). Odrzuciłem ją.
- Pierwsze uruchomienie (14:10:55): `restart OK po 1s pid=2507574`. Drugie przebiegło cicho z rc=0, więc blokada nie została przy ssh.
- KROK 4h, dwa razy: kill 14:14:38 → nasłuch wrócił po 28 s (wpis 14:15:06), kill 14:15:35 → po 32 s (wpis 14:16:06). W trakcie awarii `/api/health` `postgres:false`, po odtworzeniu `postgres:true`.
- **Błąd znaleziony w 4h i poprawiony:** w pierwszym teście do logu trafiły **64 linie** stderr typu `linia 81: /proc/2518221/cmdline: Nie ma takiego pliku` (wszystkie przed wpisem 14:15:06, `stop_tunnel` przechodzi `/proc` dwa razy). To wyścig: proces znikał między globem `/proc/[0-9]*` a odczytem `cmdline`, a błąd przekierowania szedł do powłoki mimo `2>/dev/null` przy `tr`. Poprawka `{ tr …; } 2>/dev/null` w `tunnel_pid` i `all_tunnel_pids`, commit `17c6951`, ponowny deploy atomowy (`mv`). Drugi test 4h dał już czysty wpis, a od 14:16 do 15:02 (kilkadziesiąt przebiegów crona) nie przybyła żadna linia stderr (pomiar: 64 linie `linia 81`, wszystkie przed 14:15:06).

## 6. `.env` i monitor (KROK 4e)

Kolejność w obrębie kroku zmieniłem: **najpierw monitor, potem `.env`**. Nowy monitor bez `DATABASE_URL_DIRECT` zachowuje się jak stary, a w odwrotnej kolejności wskrzeszony w międzyczasie stary monitor zacząłby mierzyć tunel. Stan końcowy jest ten sam co w zleceniu.

Monitor, diff (jedna linia):
```
-$DBURL = $_ENV['DATABASE_URL'] ?? '';
+$DBURL = trim((string)($_ENV['DATABASE_URL_DIRECT'] ?? '')) !== '' ? trim((string)$_ENV['DATABASE_URL_DIRECT']) : ($_ENV['DATABASE_URL'] ?? ''); // CHAT-T-195 (ADR-141): mierzymy trase BEZPOSREDNIA, nie tunel
```
- md5 lokalnie == prod `d6379ed4bb4709e6d9d20a9fb4a9eea6`, `ea-php84 -l` OK, zapis przez plik tymczasowy i `mv`.
- Wybór URL sprawdzony lokalnie: oba klucze → `switchback`, pusty DIRECT → `127.0.0.1`, bez DIRECT → `switchback`.
- Restart: kill 2713877 o 14:12:14, guard 14:13:01 `proces martwy (log 56s) -> restart pid=2511087 sapi=cli`. Nowy log: `# START … host switchback.proxy.rlwy.net:14368`, pierwszy cykl `railway_tcp FAIL 5005ms … errno=110`. Zgodnie z oczekiwaniem.

`.env` (`~/public_html/chat.divezone.pl/.env`), bez wartości:

| klucz | przed | po |
|---|---|---|
| `DATABASE_URL` (linia 8) | `switchback.proxy.rlwy.net:14368`, db `railway`, `sslmode=disable` | `127.0.0.1:14368`, reszta bez zmian |
| `DATABASE_URL_DIRECT` (linia 9, nowa) | brak | dotychczasowa wartość `DATABASE_URL` + komentarz `# CHAT-T-195 (ADR-141)` |

Zapis skryptem po stronie serwera: plik tymczasowy, prawa 644 zachowane, `os.replace`. Walidacja realną ścieżką aplikacji (`Config::load` + `parse_url`): oba URL-e mają niepuste user i hasło, db `railway`, `sslmode=disable`, różnią się wyłącznie host:port (uwaga Codex 6). `SMARTHOST_TICKET_*` i `sslmode` bez zmian.

## 7. Pomiary przez tunel (KROK 4c, przed zmianą `.env`, host:port nadpisany w pamięci skryptu)

Ze smarthost, ok. 14:11, PHP 8.4 CLI, 10 powtórzeń. Serwer odpowiada `railway`, PG 18.3.

| pomiar | p50 | p95 |
|---|---|---|
| connect + `SELECT 1` (świeże połączenie) | 281,3 ms | 317,4 ms |
| `SELECT 1` na otwartym połączeniu | 95,8 ms | 105,4 ms |
| wektor `ORDER BY embedding <=> ? LIMIT 10` na `encyclopedia_chunks` | 62,2 ms | 554,1 ms |

10 wierszy, top1 sim = 1,000 (wektor zapytania wzięty z pierwszego chunka). p95 wektora to pierwsze, zimne wykonanie. Trasy bezpośredniej nie dało się zmierzyć dla porównania, bo jest zablokowana.

## 8. Weryfikacja (KROK 4f do 4h)

- `/api/health` 3/3 `postgres:true` sekundę po zmianie `.env` (14:12:15). `check_deploy.py --smoke-only`: HTTP 200, `=== WSZYSTKO OK ===`.
- **Realna rozmowa:** POST `/api/chat` (HMAC, klient 0) „Czym różni się automat oddechowy z membraną od tłokowego? (test techniczny CHAT-T-195)”. HTTP 200 w 16 217 ms, `tools_used ["get_expert_knowledge"]`. Zapis:
  - `divechat_conversations` id=1709, started_at 2026-11-05 13:13:04 UTC, model `claude-sonnet-5`, `response_times {ai_ms 10472, tool_ms 2426.4, total_ms 14264.7}`
  - `divechat_messages` 16064 user, 16065 assistant (tool_call `get_expert_knowledge`), 16066 tool (7419 zn., `AUTOMAT_ODDECHOWY`), **16067 assistant, 903 zn.**: „Różnica dotyczy mechanizmu uszczelniającego w **pierwszym stopniu** automatu oddechowego: - **Membranowy** – komora pierwszego stopnia jest …”
  - Reguła E: `divechat_conversation_review` id=535, `note='[test CHAT-T-195, nie klient]'`, status `do_weryfikacji`, verdict i updated_by NULL.
- **`cron_editorial_picks_expire.php`:** najbliższy przebieg po przełączeniu, `[2026-11-05 14:00:01+00:00] cron_editorial_picks_expire: expired 0 pick(s)` (15:00 CET), bez błędu. Poprzednie przebiegi od 2026-11-04 07:00 UTC dawały `Railway PostgreSQL niedostepne (hard)`.
- **Embeddingi (4g):** `run_nightly.py --mode changed` uruchomiony 14:13:52 jak w cronie (`env -i PATH=/usr/bin:/bin`, `timeout 1800`, ten sam log). `Heartbeat OK — ostatni sukces 2158 min temu`, 2629 produktów z MySQL, w PG 2631, delta 0, `=== KONIEC przebiegu OK ===`, rc=0. `~/logs/divechat_embeddings.last_success` = `1793884449` = **2026-11-05 14:14:09 CET**. Delta 0 znaczy, że od ostatniego udanego przebiegu żaden produkt się nie zmienił. Zapis wektorów przez tunel nie był więc w tym przebiegu ćwiczony, odczyt tak.
- **Strażnik (4h):** sekcja 5. 30 równoległych `/api/health` po odtworzeniu: 30× `postgres:true` (uwaga Codex 8).

## 9. Recenzja Codex

Plik: `_docs/reviews/CODEX_REVIEW_20261105_chat-t-195-tunel.md` (gpt-5.6-sol, reasoning xhigh, tryb read-only).

Przyjęte decyzją Karola i wykonane:
- **1 (KRYTYCZNY)** `restrict` nie blokuje exec, a `port-forwarding` dopuszcza `-R`. Wariant A: `command="/usr/bin/false"` i `from=`. Testy negatywne w sekcji 4, z zastrzeżeniem do `-R`.
- **4 (WYSOKI)** dziedziczenie fd 9. Wariant B `9>&-`, dowód z `/proc/<pid>/fd`.
- **5 (WYSOKI)** EOF bez restartu. Wariant C, jeden restart próbny. Część o `INNY:<bajt>` (log bez restartu) **nie została zmieniona**.

Pokryte weryfikacją, bez zmian w kodzie:
- **6** walidacja URL-i parserem aplikacji przed zapisem `.env` (sekcja 6).
- **8** 30 równoległych żądań po odtworzeniu tunelu.

**NIEROZSTRZYGNIĘTE (zgodnie z decyzją Karola, nierealizowane):**
- **2 (WYSOKI)** brak ciągłego alertu o awarii trasy produkcyjnej (tunelu). Monitor mierzy trasę bezpośrednią, a strażnik pisze tylko do lokalnego logu. Po padnięciu tunelu, którego strażnik nie odtworzy, nikt nie dostanie powiadomienia.
- **3 (WYSOKI)** sonda SSLRequest nie dowodzi uwierzytelnienia ani właściwej bazy. Moje zdanie: strażnik pilnuje tunelu, a błędu hasła restart nie naprawi.
- **7 (ŚREDNI)** etykiety monitora („TRASA PRODUKCYJNA”, treści maili) stają się mylące. Zlecenie dopuszczało jedną zmianę.
- **9 (WYSOKI)** zgoda mydevil dotyczyła tunelu do ich PostgreSQL, a nie przekazania do hosta zewnętrznego (ADR-141, „Konsekwencje”).
- **10 (ŚREDNI)** kryterium powrotu (24 h `railway_tcp OK`) sprawdza tylko TCP, a monitor ma też metryki PG.

Otwarte, poza zakresem:
- **11 (NISKI)** brak rotacji `railway_tunnel_guard.log` i `railway_tunnel_ssh.out`. W stanie `CEL` strażnik pisze wpis co minutę.

## 10. Wycofanie (nie wykonywać bez decyzji Karola)

1. W `~/public_html/chat.divezone.pl/.env` ustawić `DATABASE_URL` na wartość `DATABASE_URL_DIRECT` (albo przywrócić `env.bak`, jeśli w międzyczasie nic innego się nie zmieniło).
2. Usunąć z crontaba linię strażnika i komentarz `# CHAT-T-195`.
3. `kill $(cat ~/_diag/railway_tunnel.pid)`, potem sprawdzić `ss -ltn | grep 14368` (pusto).
4. Na s81 usunąć z `~/.ssh/authorized_keys` linię `railway-tunnel CHAT-T-195`.
5. Monitor może zostać: bez `DATABASE_URL_DIRECT` czyta `DATABASE_URL`, a z nim mierzy to samo.

Kopie z 4a: `~/public_html/chat.divezone.pl/_deploy_bak/CHAT-T-195_20261105-140959/` (`env.bak` md5 `a1134d4f…`, `railway_monitor.php.bak` md5 `a2f7347b…`, `crontab.bak` 139 linii, `known_hosts.bak`), wszystkie sprawdzone `cmp` z oryginałem. Na s81: `~/_deploy_bak/authorized_keys.CHAT-T-195_20261105-140959`.

## 11. Deklaracja Sentinela

**Nie dotyczy, z zastrzeżeniem.** Jedyny wgrany plik PHP to `~/_diag/railway_monitor.php`. Według raportu CHAT-T-190 (sekcja 7) `~/_diag/` leży poza drzewami monitorowanymi (`chat.divezone.pl`, `prod`). Nie zweryfikowałem tego poleceniem, bo `~/security/` jest poza moim zakresem. `.env` leży w drzewie `chat.divezone.pl`, ale nie jest plikiem PHP. Jeśli o :07 pojawi się alert o `railway_monitor.php`, blok dla operatora wygląda tak (token drzewa trzeba potwierdzić):
```
printf '%s\n' /home/divezone/_diag/railway_monitor.php > /dev/shm/decl_diag.txt
~/security/rebaseline.sh declared <TOKEN_DRZEWA_DIAG> --files /dev/shm/decl_diag.txt
```

## 12. Czego NIE sprawdziłem

- Stabilności tunelu w czasie: 48 h obserwacji z CHAT-T-193 nie wykonano. Nie wiadomo, czy mydevil zrywa długie sesje.
- Zapisu wektorów embeddingów przez tunel (delta 0, sekcja 8).
- Że każdy worker `lsphp` z osobna odrzucił stare uchwyty. Widać 30/30 OK przy 4 workerach.
- Zgody mydevil na przekazanie do hosta zewnętrznego (Codex 9).
- Czy `~/_diag/` jest poza Sentinelem, poleceniem (sekcja 11).
- Konfiguracji sshd na s81 (`/etc/ssh/sshd_config` bez prawa odczytu). Wnioski o `-R` wynikają z zachowania, nie z pliku.

## 13. Uwagi poboczne

- **Sekrety w wyjściu sesji.** Mój pierwszy odczyt `crontab -l` w rekonesansie wypisał do okna sesji tokeny w URL-ach cudzych cronów (redakcja wzorcem nie złapała tokenów w query stringu). Nie trafiły do żadnego pliku ani tego raportu. Dalsze odczyty crona były filtrowane albo porównywane bez wypisywania. Do rozważenia przez Karola: czy tokeny tych cronów traktować jako ujawnione.
- **Mój błąd z `pgrep`.** Przed 4g `pgrep -f "run_nightly[.]py"` złapał własną komendę SSH (zawierała literał ścieżki) i zgłosił `JUZ_DZIALA`. To pułapka z `_docs/44`. Nic nie zostało uruchomione. Sprawdzenie i start rozdzieliłem na dwa wywołania.
- **Równoległa sesja na serwerze:** FIX-T-28 robiła kopie PROD w `~/_deploy_bak` o 13:48 i 14:08. Nie dotykałem jej plików.
- **Kontrola wywołań w czasie awarii:** przebiegi `cron_editorial_picks_expire` od 2026-11-04 07:00 UTC do 2026-11-05 13:00 UTC skończyły się błędem `Railway PostgreSQL niedostepne (hard)`. Nie ponawiałem ich ręcznie.

raport: _reports/CHAT-T-195_tunel-railway-przez-mydevil.md
═══ CHAT-T-195 · INTEGRATION · DEPLOYED ═══
