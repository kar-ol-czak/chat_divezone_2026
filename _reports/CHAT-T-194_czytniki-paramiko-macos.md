═══ CHAT-T-194 · INTEGRATION · DONE ═══

# CHAT-T-194 — czytniki paramiko na macOS, RejectPolicy, wersjonowanie

**Instancja:** INTEGRATION. **Data:** 2026-10-13. **Karta:** Chat - 120 (zgłoszenie KNR-T-004), decyzja Karola 1c.
**Zlecenie:** `_instances/integration/tasks/CHAT-T-194_INTEGRATION_czytniki-paramiko-macos.md`.
**Commit kodu:** `477973c` — `fix(CHAT-T-194): czytniki paramiko na macOS, RejectPolicy, wersjonowanie (Chat-120)`, wypchnięty na `origin/main`.
**Serwer:** wyłącznie odczyt przez te trzy skrypty (`SELECT DATABASE()`, `hostname`, `ls /tmp/_hades_ro.php`). Zero zmian.
**Środowisko testów:** Mac Karola, Python 3.14.7, paramiko 3.5.1.

---

## WERDYKT

Trzy skrypty łączą się z macOS: DEV zwraca `divezone_dev`, PROD zwraca `divezone_2025`, `ssh_pmk` daje rc 0.
Wersje sprzed zmiany padają na tej samej maszynie na `FileNotFoundError: '~\\.ssh\\id_ed25519'`.
Brak klucza albo known_hosts daje teraz błąd z nazwą ścieżki, zanim zostanie wywołane `connect`.
Nieznany host i niezgodny klucz hosta są odrzucane. Pliki są w gicie: hash dla KNR to `477973c`.

---

## KROK 0 — synchronizacja

`git fetch && git log --oneline HEAD..origin/main` → pusto. HEAD na starcie `d7767a3`.
`.sesja_CDK` w korzeniu repo, zlecenie dotyczy tego repo.

## KROK 1 / KROK 2 — md5 i właściciel plików

| plik | md5 przed | md5 po (HEAD `477973c`) |
|---|---|---|
| `mysql_dev.py` | `8bf187a7b108c8d90bdb7a4e86e523db` | `3d97335d884d9eee1b68f633ea00cfc5` |
| `mysql_pmk.py` | `d123dc180e30b9f5bc843aedfa5ba6fd` | `373ec966f00d260f5513381b31686081` |
| `ssh_pmk.py` | `e0dcfd00a8f4589f9438faf092038cf5` | `c0eb52aca43a13369f5f1419aca09935` |

md5 „po” policzone z `git show HEAD:<plik> | md5 -q` i zgodne z plikami na dysku.
Właściciel przed: `root staff`. Podmiana: kopia w tym samym katalogu (`.<plik>.t194`), edycja kopii,
`mv -f` na oryginał, bez sudo. Właściciel po: `karol staff`.

Pośrednia wersja `mysql_dev.py` (`0bfda563ad52ac2cd79adb7fdc565e7d`) różniła się od końcowej
wyłącznie akapitem docstringu o `/tmp/_hades_ro.php`, przeredagowanym po recenzji Codexa (KROK 4).
Testy A3 do A7 szły na wersji pośredniej. Na wersji końcowej powtórzone A2 oraz B1 i B3.

## Zmiany (wg §2 zlecenia)

We wszystkich trzech plikach:
- `SSH_KEY` / `KNOWN_HOSTS` = `os.path.join(os.path.expanduser("~"), ".ssh", "<plik>")`, bez odwrotnego ukośnika w literale,
- nowa funkcja `_require_file(path, label)`: `os.path.isfile` albo `sys.exit("BRAK PLIKU <label>: <ścieżka>")`,
  wołana dla klucza i known_hosts przed utworzeniem `SSHClient`,
- `load_host_keys(KNOWN_HOSTS)` bezwarunkowo, polityka `paramiko.RejectPolicy()` zamiast `AutoAddPolicy()`.

Docstringi:
- `mysql_dev.py`, sekcja BEZPIECZENSTWO: zdanie „nic nie zostaje na serwerze: kod PHP idzie przez STDIN, zero plikow”
  zastąpione opisem stanu faktycznego: na czas wykonania powstaje `/tmp/_hades_ro.php` (sam kod PHP, bez hasła i bez SQL),
  SQL idzie w komendzie zdalnej jako base64 do STDIN PHP, `rm -f` w tej samej komendzie po `;`, więc także po błędzie PHP.
  Sprzątanie jest best effort, plik zostaje przy zerwaniu sesji przed `rm` albo przy błędzie `rm`, nazwa jest stała.
  Dopisana linia o weryfikacji hosta (RejectPolicy, błąd przy braku klucza albo known_hosts).
- `mysql_pmk.py`, `ssh_pmk.py`: dopisane „Dziala tez na macOS.”, nic więcej.

Bez zmian: BRAMKA 1, BRAMKA 2, `ALLOWED`, brak `--write`, zdalny PHP, format wyjścia, argumenty CLI.

## KROK 3 — testy (wyniki dosłowne)

**A1 klasa błędu.** Próba kontrolna PRZED zmianą, `grep -nF '~\' *.py`:
```
mysql_dev.py:40:SSH_KEY = os.path.expanduser(r"~\.ssh\id_ed25519")
mysql_dev.py:41:KNOWN_HOSTS = os.path.expanduser(r"~\.ssh\known_hosts")
mysql_pmk.py:31:SSH_KEY = os.path.expanduser(r"~\.ssh\id_ed25519")
mysql_pmk.py:32:KNOWN_HOSTS = os.path.expanduser(r"~\.ssh\known_hosts")
ssh_pmk.py:22:SSH_KEY = os.path.expanduser(r"~\.ssh\id_ed25519")
ssh_pmk.py:23:KNOWN_HOSTS = os.path.expanduser(r"~\.ssh\known_hosts")
count: 6
```
PO zmianie: `rc=1 count=0` (pusto). Zgodne z pomiarem architekta (6 trafień).

**A2 DEV działa.** `python3 mysql_dev.py -c "SELECT DATABASE()"`:
```
[baza: divezone_dev]
DATABASE()
------------
divezone_dev

(1 wierszy)
rc=0
```
Powtórzone na wersji końcowej: identyczny wynik, rc=0.

**A3 mutant ścieżki.** `python3 /tmp/t194_mysql_dev.orig.py -c "SELECT DATABASE()"`:
```
FileNotFoundError: [Errno 2] No such file or directory: '~\\.ssh\\id_ed25519'
rc=1
```

**A4 read-only.** `python3 mysql_dev.py -c "UPDATE pr_configuration SET value=value WHERE 1=0"`:
```
READ-ONLY: dozwolone tylko SELECT / SHOW / DESCRIBE / EXPLAIN / WITH.
rc=1
```
Dowód „bez połączenia”: ten sam przebieg pod sondą podmieniającą `paramiko.SSHClient.connect` na wersję
wypisującą `SONDA: SSHClient.connect WYWOLANE` → linii sondy brak. Próba dodatnia sondy: A2 pod sondą wypisało
`SONDA: SSHClient.connect WYWOLANE` i `divezone_dev`, rc=0.

**A5 PROD.**
```
$ python3 mysql_pmk.py -c "SELECT DATABASE()"
DATABASE()
-------------
divezone_2025

(1 wierszy)
rc=0
$ python3 ssh_pmk.py -c "hostname"
divezonededyk.smarthost.pl
rc=0
```
Mutanty `.orig.py`:
```
mysql_pmk.orig: FileNotFoundError: [Errno 2] No such file or directory: '~\\.ssh\\id_ed25519'   rc=1
ssh_pmk.orig:   FileNotFoundError: [Errno 2] No such file or directory: '~\\.ssh\\id_ed25519'   rc=1
```

**A6 mutant: brak known_hosts.** Kopia `mysql_dev.py` z `KNOWN_HOSTS = "/tmp/t194_nie_istnieje_known_hosts"`
(plik potwierdzony jako nieistniejący), pod sondą:
```
BRAK PLIKU known_hosts: /tmp/t194_nie_istnieje_known_hosts
rc=1
```
Linii sondy brak, czyli błąd przed połączeniem.

**A7 mutant: host nieznany.** Kopia z `KNOWN_HOSTS` na pusty plik (0 B):
```
paramiko.ssh_exception.SSHException: Server '[divezonededyk.smarthost.pl]:5739' not found in known_hosts
rc=1
```
Próba dodatnia: A2.

**Dodatkowe mutanty po recenzji Codexa (pkt 1 recenzji), na wersji końcowej, pod sondą:**
- B1 brak klucza (`SSH_KEY` na nieistniejący plik), wszystkie trzy skrypty: `BRAK PLIKU klucza SSH: <ścieżka>`, rc=1, linii sondy brak.
- B2 brak known_hosts w `mysql_pmk.py` i `ssh_pmk.py`: `BRAK PLIKU known_hosts: <ścieżka>`, rc=1, linii sondy brak.
- B3 niezgodny klucz hosta (known_hosts z wpisem `[divezonededyk.smarthost.pl]:5739` i obcym kluczem ed25519 wygenerowanym jednorazowo), `mysql_dev.py`:
  ```
  paramiko.ssh_exception.BadHostKeyException: Host key for server 'divezonededyk.smarthost.pl' does not match: got 'AAAAC3NzaC1lZDI1NTE5AAAAIPtWZshwP0Zq7ttPE1AJGtm1GUAFKZvabkbpyLVQzCsY', expected 'AAAAC3NzaC1lZDI1NTE5AAAAINfL2YF4QRS9SfbZYaAby18USU2x6kHsXj2bYb5T8nZT'
  rc=1
  ```
  Próba dodatnia: A2.

**Docstring a serwer.** Po A2: `python3 ssh_pmk.py -c "ls -la /tmp/_hades_ro.php"` →
`nie ma dostępu do '/tmp/_hades_ro.php': Nie ma takiego pliku ani katalogu`. Plik po przebiegu nie został.

**A8 Windows: nie testowane na Windows** (brak maszyny). Przenośność opieram na:
1. `os.path` na Windows to `ntpath`. Emulacja na Macu z `USERPROFILE='C:\Users\karol'`:
   ```
   nowa  ntpath : C:\Users\karol\.ssh\id_ed25519
   stara ntpath : C:\Users\karol\.ssh\id_ed25519
   nowa  posix  : /Users/karol/.ssh/id_ed25519
   stara posix  : '~\\.ssh\\id_ed25519'
   ```
   Nowa postać daje na Windows tę samą ścieżkę co stara.
2. `RejectPolicy`, `load_host_keys` i `isfile` nie zależą od systemu.

Emulacja nie jest testem na Windows: nie sprawdza Desktop Commandera, profilu użytkownika na VM ani zawartości tamtejszego known_hosts.

**Sprzątanie.** `/tmp/t194_*` przeniesione `mv` do scratchpadu sesji (kwarantanna zamiast `rm`), `ls /tmp/t194_*` → brak dopasowań.

## Teza zlecenia, której nie potwierdzam

Zlecenie, §1 pkt 2: „Po złej ścieżce tak właśnie działało na Windows od lipca (wywnioskowane)”.
Emulacja `ntpath` (A8 pkt 1) pokazuje, że na Windows `r"~\.ssh\known_hosts"` rozwija się poprawnie.
Zła ścieżka dotyczyła tylko macOS. Czy na Windows host był weryfikowany, zależało od tego, czy
tamtejszy known_hosts istniał i miał wpis `[divezonededyk.smarthost.pl]:5739`. Tego nie sprawdziłem.

**Skutek dla VM Windows:** jeżeli tamtejszy `%USERPROFILE%\.ssh\known_hosts` nie istnieje albo nie ma wpisu
dla `[divezonededyk.smarthost.pl]:5739`, skrypty po tej zmianie odmówią połączenia (`BRAK PLIKU known_hosts`
albo `not found in known_hosts`). To zamierzone. Przed pierwszym użyciem na VM trzeba sprawdzić wpis.

## KROK 4 — recenzja Codex (gpt-5.6-sol, reasoning xhigh)

Pełny tekst: `_docs/reviews/CODEX_REVIEW_20261013_CHAT-T-194_czytniki-paramiko.md`. Brak znalezisk HIGH.

| # | waga | znalezisko | moja ocena |
|---|---|---|---|
| 1 | MEDIUM | ścieżka „brak klucza” w wersji końcowej nietestowana, negatywne ścieżki pmk/ssh nietestowane | **przyjęte**: dodane B1, B2, B3 |
| 2 | MEDIUM | docstring „zostaje tylko wtedy, gdy sesja zerwana” mocniejszy niż kod (wynik `rm` niesprawdzany) | **przyjęte**: docstring przeredagowany na best effort |
| 3 | LOW | stała nazwa `/tmp/_hades_ro.php`, wyścig przy równoległych wywołaniach | zgoda co do faktu, poza zakresem (zdalna komenda bez zmian wg §2). Odnotowane w docstringu. Do osobnego zadania |
| 4 | LOW | `connect()` przed `try/finally`, przy odrzuceniu hosta brak `cli.close()` | sprzed zmiany, poza zakresem. Dla jednorazowego CLI bez skutku (proces się kończy). Nie poprawiam |
| 5 | MEDIUM | Windows nietestowany, założenia: profil, klucz bez hasła, wpis w known_hosts | zgoda, opisane w A8 i w sekcji o tezie zlecenia. Klucz bez hasła: założenie sprzed zmiany |

**NIEROZSTRZYGNIĘTE (niezgody z Codexem):**
- pkt 4: Codex proponuje przenieść `connect()` do `try/finally`. Moim zdaniem to wykracza poza zakres
  (§2 nie obejmuje struktury połączenia), a dla procesu CLI zamykanego zaraz po błędzie nie ma skutku.
  Decyzja dla architekta.
- pkt 5, „Document the required key type/passphrase expectation”: nie dopisałem, bo §2 ogranicza docstringi
  pmk i ssh do „działają też na macOS”. Decyzja dla architekta.

## KROK 5 — commit

```
git add _diag_local/chat_verification/mysql_dev.py
git add _diag_local/chat_verification/mysql_pmk.py
git add _diag_local/chat_verification/ssh_pmk.py
git diff --cached --stat → 3 files changed, 406 insertions(+)
before=d7767a3 after=477973c  HEAD_CHANGED
git ls-tree -r HEAD | wc -l: 838 -> 841
git push origin main: d7767a3..477973c  main -> main
```
Przed `git add` indeks był pusty (brak cudzych zastage'owanych plików).

## Czego NIE sprawdziłem / NIE ruszałem

- Windows (A8): brak testu na realnej maszynie.
- Mutantów A6/A7 dla pmk i ssh w wariancie „pusty known_hosts” i „zły klucz hosta” (B2 i B3 obejmują brak pliku w pmk/ssh i zły klucz tylko w dev). Kod ścieżki jest identyczny we wszystkich trzech plikach.
- Nie ruszałem: `mysql.py`, `_conn.py`, `sql.py`, `trello.py`, `replay.py`, `check_deploy.py`, `_tmp_*`, `_open_problems_*`,
  `railway_monitor_guard.sh`, `purge_litespeed.php`, `standalone/config/routes.php`, `_docs/46`, `_docs/10`, ADR-ów, karty Trello.
- Znaleziska poboczne do osobnego zadania: wyścig na stałej nazwie `/tmp/_hades_ro.php` (Codex pkt 3), `connect()` poza `try/finally` (Codex pkt 4).

raport: _reports/CHAT-T-194_czytniki-paramiko-macos.md
═══ CHAT-T-194 · INTEGRATION · DONE ═══
