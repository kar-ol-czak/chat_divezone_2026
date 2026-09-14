═══ CHAT-T-190 · INTEGRATION · DEPLOYED ═══

# CHAT-T-190 — raport: guard przypięty do binarki CLI + asercja SAPI

**Instancja:** INTEGRATION. **Data:** 2026-09-14. **Zlecenie:** `_instances/integration/tasks/CHAT-T-190_INTEGRATION_guard-przypiety-do-binarki-cli.md` (+ §9 KOREKTA ARCHITEKTA).
**Plik:** `_docs/scripts/railway_monitor_guard.sh` → produkcja `/home/divezone/_diag/railway_monitor_guard.sh`.
**Commity:** `4c70322` (przypięcie binarki + pułapka w `_docs/44`), `d383a4d` (korekta §9: asercja na ścieżkę restartu).

---

## 1. Problem i co dokładnie zmieniono

Linia 12 guarda wybierała binarkę przez PATH:

```bash
PHP=$(command -v ea-php84 || command -v php)
```

`command -v` rozstrzyga inaczej pod cronem niż w sesji SSH:

| binarka | cel symlinku | SAPI | max_execution_time |
|---|---|---|---|
| `/usr/bin/ea-php84` | `/opt/cpanel/ea-php84/root/usr/bin/php-cgi` | `cgi-fcgi` | 30 |
| `/usr/local/bin/ea-php84` | `/opt/cpanel/ea-php84/root/usr/bin/php` | `cli` | 0 |

Cron ma `PATH=/usr/bin:/bin`, więc guard od początku startował monitor pod **php-cgi**. Stąd 139 wpisów `Maximum execution time of 30 seconds exceeded` i 2-3 zgony monitora na dobę. CHAT-T-189 leczył skutek (`set_time_limit(0)` w monitorze), to zadanie usuwa przyczynę.

**Po zmianie:** binarka przypięta na sztywno (`PHP=/usr/local/bin/ea-php84`) z pętlą fallbacku `/opt/cpanel/ea-php84/root/usr/bin/php` → `/usr/local/bin/php` → `/usr/bin/php`; odczyt SAPI dopisywany do każdej linii restartu w `guard.log` (`sapi=… php=…`).

**Asercja świadomie NIE blokuje startu.** Gdyby SAPI wyszło inne niż `cli`, guard i tak wskrzesi monitor — po T-189 monitor działa też pod CGI, więc twarde `exit 1` zamieniłoby drobną degradację w całkowity brak monitoringu. SAPI widać w logu i to wystarczy.

## 2. Korekta §9 — asercja przeniesiona na ścieżkę restartu

W pierwotnym §4 odczyt SAPI stał na górze skryptu. Guard chodzi co minutę i w ogromnej większości tików kończy się jako „zdrowy" (`exit 0` kilka linii wyżej), więc każdy taki tik startował proces PHP bez powodu. Zgłosiłem to w raporcie KROK 1-5, architekt przyznał rację (§9) i linia trafiła za `rm -f "$PIDFILE"`, czyli na ścieżkę, na której wartość jest realnie używana.

Pomiar ścieżki zdrowej, piaskownica z własnym `DIAG`, `pkill`/`nohup` podmienione na no-opy (produkcja nietykalna):

```
--- wersja NOWA (d383a4d) ---          --- wersja STARA (4c70322) ---
exit=0 (sciezka zdrowa)                exit=0 (sciezka zdrowa)
uruchomien PHP w tracie bash -x: 0     uruchomien PHP w tracie bash -x: 1
20 przebiegow: 135 ms -> 6 ms/tik      20 przebiegow: 1157 ms -> 57 ms/tik
```

Trace `bash -x` jest dowodem wprost: w nowej wersji linia z `echo PHP_SAPI` nie wykonuje się wcale. Stara wersja w tej samej piaskownicy wykonuje ją raz — **próba kontrolna dodatnia**, więc test potrafi wykryć różnicę, a nie tylko potwierdza oczekiwanie. Znika ~1440 uruchomień PHP na dobę.

Pierwszy pomiar czasu wyszedł nieczytelnie (`time` pisze na stderr, wyniki przeplotły się przez SSH i nie dało się przypisać liczby do wersji). Nie zgadywałem — powtórzyłem na `date +%s%N` z wypisem na stdout; powyżej są liczby z powtórki.

## 3. Test wyboru binarki przed wdrożeniem (serwer, `/tmp`, poza `_diag`)

```
A) powloka interaktywna  ->  /usr/local/bin/ea-php84 cli
B) env -i PATH=/usr/bin:/bin (PATH CRONA)  ->  /usr/local/bin/ea-php84 cli
C) kontrola, STARA logika `command -v` pod tymi samymi PATH:
   interaktywnie: /usr/local/bin/ea-php84
   pod cronem:    /usr/bin/ea-php84          <- php-cgi, przyczyna 139 fatali
D) readlink -f /usr/bin/ea-php84        -> /opt/cpanel/ea-php84/root/usr/bin/php-cgi
   readlink -f /usr/local/bin/ea-php84  -> /opt/cpanel/ea-php84/root/usr/bin/php
```

Blok C to próba kontrolna dodatnia. Testowanie takich rzeczy z powłoki interaktywnej niczego nie dowodzi (tak zawiódł T-185) — stąd `env -i`.

## 4. Wdrożenie (ADR-089, autoryzacja Karola 2026-09-14)

- Stan wejściowy odczytany **z produkcji**, nie z listy w zleceniu: `f9bb20a5ff675882f7f57b8d2f95da1a` — zgodny, nikt pliku nie ruszał.
- Backup `~/_diag/railway_monitor_guard.sh.bak_20260914`, md5 identyczne z oryginałem.
- rsync **jednego** pliku, port 5739, bez `--delete`, bez katalogu, **bez `--perms`**.
- `bash -n` na pliku produkcyjnym czysty, 72 linie, bit wykonywalności zachowany.

**Pułapka wychwycona przed transferem:** repo trzyma guarda jako `644`, produkcja ma `755`, a cron woła skrypt bezpośrednio (`* * * * * /home/divezone/_diag/railway_monitor_guard.sh`). `rsync -a` (albo `-p`) zsynchronizowałby prawa z repo i **wyłączyłby guarda całkowicie** — monitor przestałby być wskrzeszany, a objaw pojawiłby się dopiero przy pierwszym zgonie. Dlatego `--no-perms`; po transferze `ls -la` potwierdza `-rwxr-xr-x`. Dopisane do `_docs/44` jako pułapka.

## 5. Sześć dowodów z §6 zlecenia

**(1) md5 — cztery osobne wartości**

| | przed | po |
|---|---|---|
| lokalny | `f9bb20a5ff675882f7f57b8d2f95da1a` | `412a8c51fe881c024cb0b87274f08689` |
| produkcyjny | `f9bb20a5ff675882f7f57b8d2f95da1a` | `412a8c51fe881c024cb0b87274f08689` |

Wartość „po" zgadza się z commitem `d383a4d`.

**(2) Nowa linia w `guard.log`**

```
[guard] 2026-09-14 21:26:02 proces martwy (log 12s) -> restart pid=3085045 sapi=cli php=/usr/local/bin/ea-php84
```

Restart wymuszony przez `pkill -9`, monitor wskrzeszony przez **crona** po 10 s — ręcznie nic nie startowałem, bo to właśnie testowana ścieżka.

**(3) Binarka żywego procesu**

```
readlink /proc/3085045/exe  ->  /opt/cpanel/ea-php84/root/usr/bin/php
```

Bez `-cgi`, bez `(deleted)`.

**(4) Marker CHAT-T-189 z żywego procesu**

```
# CHAT-T-189: SAPI cli | max_execution_time 0 -> 0 | po bootstrapie: 0 | limit zdjety
```

Wcześniej: `SAPI cgi-fcgi | max_execution_time 30 -> 0`. Zapis `0 -> 0` znaczy, że limitu **nie ma już czego zdejmować** — przyczyna usunięta, a `set_time_limit(0)` z T-189 zostaje jako pas bezpieczeństwa.

**Rozbieżność wobec zlecenia, do odnotowania:** §6.4 lokuje ten marker w `monitor_nohup.out`. Realnie pisze go `wlog()`, więc trafia do logu dobowego `railway_monitor_20260914.log`; `monitor_nohup.out` zbiera wyłącznie stderr i komunikaty typu `digest mail status=sent`. Dowód jest, tylko w innym pliku niż zapisano.

**(5) Nowy proces**

```
PID      ELAPSED   RSS
3085045    00:16   43692 kB
deskryptorow: 4
PATH procesu: PATH=/usr/bin:/bin
```

`PATH=/usr/bin:/bin` to sedno sprawy: to dokładnie środowisko, w którym stara logika wybierała CGI, a proces mimo to działa pod `php`. RSS 43 692 kB przy starcie to **nowa baza odniesienia** — CGI startował z 34 644 kB i po czterech dobach schodził do 26 256 kB, więc liczb nie porównujemy wprost między SAPI. Wartość do obserwacji, nie objaw.

**(6) Pierwszy cykl pomiarowy (obowiązkowy — pomiar, nie deklaracja)**

```
# START 2026-09-14 21:26:02 WAW | CIAGLY (bez stop) | interval 5s | host switchback.proxy.rlwy.net:14368
#00001 2026-09-14 19:26:03 UTC | railway_tcp OK 33ms | pg_select1 OK 371ms | pg_settings OK 109ms | pg_chiptree OK 105ms | pg_upsert OK 108ms | github OK 17ms | errno=0
#00002 2026-09-14 19:26:08 UTC | railway_tcp OK 33ms | pg_select1 OK 275ms | pg_settings OK 102ms | pg_chiptree OK 102ms | pg_upsert OK 103ms | github OK 17ms | errno=0
#00003 2026-09-14 19:26:14 UTC | railway_tcp OK 33ms | pg_select1 OK 284ms | pg_settings OK 104ms | pg_chiptree OK 103ms | pg_upsert OK 104ms | github OK 17ms | errno=0
```

`pg_select1` i `pg_upsert` to realne zapytania do Railway przez `pdo_pgsql` — rozszerzenie działa pod CLI faktycznie, nie tylko wg `extension_loaded`. Wszystkie sześć metryk OK.

**Licznik Fatal:** `Maximum execution time` w `monitor_nohup.out` stoi na **139**, zero nowych.

## 6. Czego NIE zrobiłem i co pozostaje otwarte

- **Recenzji `/codex` nie było** — zlecenie jej nie przewidywało (brak sekcji RECENZJA KRZYŻOWA), architekt uzasadnił to w §9: dziesięciolinijkowy diff w powłoce z próbą kontrolną dodatnią daje mocniejszy dowód niż opinia drugiego modelu.
- **Nie ruszałem** `railway_monitor.php`, `railway_summary_mail.php`, wpisu crona, `.env`, logów ani zrzutów w `~/_diag/`.
- **Restart zgasił passę 4 doby 03:41** bez `proces martwy` i bez `ZAMARCIE` (pid 1449046, ostatni restart 10.09 17:44). To był dowód trwałości CHAT-T-189; licznik rusza od zera, zgodnie z §9.
- **Repo trzyma guarda jako `644`, produkcja wymaga `755`.** Dziś rozwiązane przez `--no-perms`, ale rozjazd zostaje i przy kolejnym deployu ktoś może o nim nie wiedzieć. Propozycja do decyzji architekta: ustawić bit wykonywalności w repo (`git update-index --chmod=+x`), żeby źródło i produkcja się zgadzały. **Nie robię tego z własnej inicjatywy** — to zmiana w repo poza zakresem zlecenia.
- **Obserwacja z §8 zlecenia, poza zakresem:** symlinki `ea-php84` mają datę 9 wrz 01:25, czyli cPanel podmienia binarkę PHP przy aktualizacjach. Po każdej takiej aktualizacji monitor warto przeładować, inaczej trzyma usunięty i-node (`/proc/<pid>/exe` → `… (deleted)`).

## 7. Deklaracja Sentinela

**Nie dotyczy.** Deploy nie wgrał ani nie zmienił żadnego pliku PHP; `~/_diag/` leży poza drzewami monitorowanymi (`chat.divezone.pl`, `prod`), a zmieniony plik to skrypt powłoki. Bloku do wklejenia nie ma.

═══ CHAT-T-190 · INTEGRATION · DEPLOYED ═══
