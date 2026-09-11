═══ CHAT-T-190 · INTEGRATION · przypięcie guarda do binarki CLI ═══

# CHAT-T-190 — railway_monitor_guard.sh: przypięcie do binarki CLI + asercja SAPI

Autor zlecenia: architekt. Data: 2026-09-11.
Poprzednik: CHAT-T-189 (zamknięty, dowód 24 h zweryfikowany 11.09 15:40 CEST).

## 1. Problem

`railway_monitor_guard.sh` linia 12:

```
PHP=$(command -v ea-php84 || command -v php)
```

`command -v` rozstrzyga przez PATH. Pod cronem PATH jest inny niż interaktywnie:

| binarka | cel symlinku | SAPI | max_execution_time |
|---|---|---|---|
| `/usr/bin/ea-php84` | `/opt/cpanel/ea-php84/root/usr/bin/php-cgi` | cgi-fcgi | 30 |
| `/usr/local/bin/ea-php84` | `/opt/cpanel/ea-php84/root/usr/bin/php` | cli | 0 |

Pomiar 2026-09-11 na produkcji:

```
/usr/local/bin/ea-php84 -r 'echo PHP_SAPI," | maxexec=",ini_get("max_execution_time");'
cli | maxexec=0

/usr/bin/ea-php84 -r '...'
Error in argument 1, char 2: option not found r     <- php-cgi nie zna -r
```

Skutek: monitor od początku istnienia chodzi pod php-cgi. To była przyczyna
139 wpisów `Maximum execution time of 30 seconds exceeded` w monitor_nohup.out.
CHAT-T-189 wyleczył SKUTEK (`set_time_limit(0)` w monitorze). To zadanie usuwa
PRZYCZYNĘ: guard ma jawnie startować CLI.

## 2. Przesłanka zweryfikowana przed zleceniem

Rozszerzenia pod CLI, pomiar 2026-09-11:

```
/usr/local/bin/ea-php84 -r 'var_dump(extension_loaded("pdo_pgsql"),
    extension_loaded("pdo_mysql"), extension_loaded("curl"), extension_loaded("openssl"));'
bool(true) bool(true) bool(true) bool(true)
```

Wszystkie cztery rozszerzenia potrzebne monitorowi ładują się pod CLI.
Przełączenie jest bezpieczne.

md5 guarda: repo `_docs/scripts/railway_monitor_guard.sh` = produkcja
`~/_diag/railway_monitor_guard.sh` = `f9bb20a5ff675882f7f57b8d2f95da1a`. Brak dryfu.

## 3. Zakres

Jeden plik: `_docs/scripts/railway_monitor_guard.sh` (59 linii).
Nic poza nim. NIE dotykaj `railway_monitor.php` ani `railway_summary_mail.php`.

## 4. Zmiana

Zastąp linię 12 blokiem. Zachowaj `set -u` i resztę skryptu bez zmian.

```bash
# CHAT-T-190: binarka przypieta na sztywno. NIE wracac do `command -v ea-php84`:
# pod cronem PATH rozstrzyga na /usr/bin/ea-php84 = php-cgi (SAPI cgi-fcgi,
# max_execution_time 30, nie zna nawet -r). Patrz _docs/44, sekcja PULAPKI.
PHP=/usr/local/bin/ea-php84
if [ ! -x "$PHP" ]; then
    for cand in /opt/cpanel/ea-php84/root/usr/bin/php /usr/local/bin/php /usr/bin/php; do
        if [ -x "$cand" ]; then PHP="$cand"; break; fi
    done
fi
PHP_SAPI_SEEN=$("$PHP" -r 'echo PHP_SAPI;' 2>/dev/null || echo "nieznany")
```

W linii startowej guarda dopisz SAPI do komunikatu, żeby każdy restart zostawiał
dowód w guard.log. Ostatnia linia skryptu (obecnie `echo "[guard] ... pid=..."`)
ma brzmieć:

```bash
echo "[guard] $(date '+%Y-%m-%d %H:%M:%S') $reason pid=$(cat "$PIDFILE") sapi=$PHP_SAPI_SEEN php=$PHP"
```

### Świadoma decyzja: asercja NIE blokuje startu

Gdyby `$PHP_SAPI_SEEN` wyszło inne niż `cli`, guard i tak startuje monitor.
Uzasadnienie: po CHAT-T-189 monitor pracuje poprawnie także pod CGI, więc
twarde `exit 1` zamieniłoby drobną degradację w całkowity brak monitoringu,
czyli lekarstwo gorsze od choroby. SAPI trafia do guard.log i tam je widać.

## 5. Kroki

0. `git pull --rebase`. Przeczytaj `_docs/scripts/railway_monitor_guard.sh`
   w całości i `_docs/44_slownik_pol_i_metryk.md`, sekcja PUŁAPKI na końcu.
1. Zmiana w pliku lokalnym wg §4. `bash -n` na pliku (składnia).
2. Test lokalny logiki wyboru binarki: uruchom po SSH sam fragment wyboru
   (skopiuj blok do `/tmp/t190.sh` na serwerze, poza `_diag`) i pokaż wynik
   `echo "$PHP $PHP_SAPI_SEEN"`. Oczekiwane: `/usr/local/bin/ea-php84 cli`.
3. Dopisz pułapkę do `_docs/44_slownik_pol_i_metryk.md`, sekcja PUŁAPKI,
   jedna linijka z dowodem: `command -v ea-php84` daje php-cgi pod cronem
   i php pod interaktywnym shellem; CLI to `/usr/local/bin/ea-php84`.
4. Commit wg konwencji, `git add` per ścieżka (dwa pliki: guard + _docs/44).
   Push.
5. **STOP (ADR-089).** Czekaj na słowo „deployuj" od Karola.
6. Po autoryzacji: backup `~/_diag/railway_monitor_guard.sh.bak_20260911`,
   rsync JEDNEGO pliku na port 5739, md5 local↔prod, `bash -n` na produkcji.
7. Wymuś restart monitora, żeby guard wystartował nową binarką:
   `pkill -9 -f 'railway_monitor\.php'`, odczekaj do 90 s na cron.
8. Raport z DOWODAMI (patrz §6).

## 6. Dowody wymagane w raporcie

1. md5 przed i po, lokalny i produkcyjny, jako cztery osobne wartości.
2. Nowa linia w `guard.log` po restarcie z §5.7, zawierająca `sapi=cli`
   i `php=/usr/local/bin/ea-php84`.
3. Z ŻYWEGO procesu: `readlink /proc/<pid>/exe` ma wskazywać
   `/opt/cpanel/ea-php84/root/usr/bin/php`, BEZ `-cgi` i bez `(deleted)`.
4. Marker z CHAT-T-189 w `monitor_nohup.out` po restarcie ma brzmieć
   `SAPI cli | max_execution_time 0 -> 0 | po bootstrapie: 0`.
   Wcześniej brzmiał `SAPI cgi-fcgi | max_execution_time 30 -> 0`.
5. `ps -o pid,etime,rss` i liczba deskryptorów nowego procesu, jako baza
   do porównania.
6. Pierwszy cykl pomiarowy w logu `railway_monitor_YYYYMMDD.log` po restarcie,
   z widocznymi metrykami `pg_select1` i `pg_upsert` — dowód, że `pdo_pgsql`
   realnie działa pod CLI, nie tylko `extension_loaded` zwraca true.

Punkt 6 jest obowiązkowy. `extension_loaded` to deklaracja, zapytanie do bazy
to pomiar.

## 7. Czego NIE ruszać

- `railway_monitor.php`, `railway_summary_mail.php`
- `config/routes.php`, `config/tools.php` (dryf R-5)
- `_ops/newtmp2_root/purge_litespeed.php` (SEKRET)
- cudze niezacommitowane zmiany w drzewie roboczym (`git add` per ścieżka,
  nigdy `git add -A`)
- ADR-y (pisze je architekt)
- wpis crona guarda (`* * * * *`) — bez zmian

## 8. Ryzyko

Jeżeli `/usr/local/bin/ea-php84` zniknie po aktualizacji cPanel, pętla
fallbacku zejdzie na `/opt/cpanel/ea-php84/root/usr/bin/php`. Gdyby i to
zawiodło, guard wystartuje `/usr/bin/php` (systemowy, prawdopodobnie starsza
wersja) i zaloguje `sapi=cli php=/usr/bin/php`. Monitor może wtedy nie wstać
z powodu brakujących rozszerzeń — sygnałem będzie wzrost `proces martwy`
w guard.log. To akceptowalne: głośna awaria zamiast cichej.

Obserwacja na przyszłość, poza zakresem tego zadania: symlinki
`/usr/bin/ea-php84` i `/usr/local/bin/ea-php84` mają datę 9 wrz 01:25, czyli
cPanel podmienił binarkę PHP. Działający wtedy proces monitora trzymał stary,
usunięty i-node (`readlink /proc/1449046/exe` → `php-cgi (deleted)`).
Po każdej aktualizacji PHP monitor warto przeładować.

═══ CHAT-T-190 · INTEGRATION · przypięcie guarda do binarki CLI ═══
