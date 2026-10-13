═══ CHAT-T-194 · INTEGRATION · czytniki paramiko na macOS ═══

# CHAT-T-194 — mysql_dev.py, mysql_pmk.py, ssh_pmk.py: ścieżki SSH, weryfikacja hosta, commit

Autor zlecenia: architekt. Data: 2026-10-13.
Karta: Chat - 120 (zgłoszenie z projektu Konkurs na recenzję, KNR-T-004).
Decyzja Karola: 1c (2026-10-13).
Zlecenie ma 7 kroków (KROK 0..6). Prompt kończący się wcześniej jest niekompletny: STOP.

## 1. Problem (pomiar architekta 2026-10-13, HEAD 4375d8d, Mac Karola)

1. W trzech skryptach w `_diag_local/chat_verification/` stałe `SSH_KEY` i `KNOWN_HOSTS`
   mają postać `os.path.expanduser(r"~\.ssh\...")`. Na macOS:
   `python3 -c 'import os;p=os.path.expanduser(r"~\.ssh\id_ed25519");print(repr(p),os.path.exists(p))'`
   → `'~\\.ssh\\id_ed25519' False`. Skrypt nie łączy się z serwerem.
   Pliki: `mysql_dev.py` (SSH_KEY, KNOWN_HOSTS), `mysql_pmk.py` (SSH_KEY, KNOWN_HOSTS),
   `ssh_pmk.py` (SSH_KEY, KNOWN_HOSTS).
2. We wszystkich trzech: gdy `KNOWN_HOSTS` nie istnieje, `load_host_keys` jest pomijane,
   a polityka to `paramiko.AutoAddPolicy()`. Czyli brak pliku = cichy brak weryfikacji
   hosta. Po złej ścieżce tak właśnie działało na Windows od lipca (wywnioskowane).
3. Docstring `mysql_dev.py` (sekcja BEZPIECZENSTWO) mówi „nic nie zostaje na serwerze:
   kod PHP idzie przez STDIN, zero plikow". Kod w `_run_remote` zapisuje
   `/tmp/_hades_ro.php` i kasuje go po wykonaniu. Docstring kłamie.
4. Wszystkie trzy pliki są NIEŚLEDZONE w git (`git status` → `??`). Projekt KNR prosi
   o hash commita, którego nie ma.
5. Pliki należą do `root` (`-rw-r--r-- root staff`), katalog do `karol` (zapisywalny).
   Edycja w miejscu skończy się EACCES. Nie używaj sudo. Podmiana: kopia → edycja
   kopii → `mv -f` kopii na oryginał (rename w katalogu karol jest dozwolony).

Środowisko sprawdzone: `~/.ssh/id_ed25519` istnieje, paramiko 3.5.1,
`ssh-keygen -F '[divezonededyk.smarthost.pl]:5739'` znajduje wpis w `~/.ssh/known_hosts`.

## 2. Zakres

Trzy pliki: `_diag_local/chat_verification/{mysql_dev.py,mysql_pmk.py,ssh_pmk.py}`.

Zmiany (zasady, implementacja Twoja):
- ścieżki klucza i known_hosts budowane przenośnie (działają na macOS i Windows),
  bez odwrotnych ukośników w literale,
- brak pliku klucza albo known_hosts = głośny błąd z nazwą brakującej ścieżki,
  przed próbą połączenia,
- polityka nieznanego hosta: odrzucenie (`RejectPolicy`), nie `AutoAddPolicy`,
- docstring `mysql_dev.py` zgodny z kodem w sprawie `/tmp/_hades_ro.php`
  (opisz, co realnie trafia na serwer i kiedy znika),
- docstringi `mysql_pmk.py` i `ssh_pmk.py` mówią „dla Windows": dopisz, że działają
  też na macOS, nic więcej.

Bez zmian: bramki BRAMKA 1 i BRAMKA 2, regex `ALLOWED`, brak flagi `--write`,
zdalny PHP, format wyjścia, argumenty CLI.

## 3. Czego NIE ruszać

- `mysql.py`, `_conn.py`, `sql.py`, `trello.py`, `replay.py`, `check_deploy.py`,
  wszystkie `_tmp_*` i `_open_problems_*` w tym katalogu,
- niezacommitowane zmiany innych sesji: `_docs/scripts/railway_monitor_guard.sh`,
  `_ops/newtmp2_root/purge_litespeed.php` (SEKRET), `standalone/config/routes.php`,
- `_docs/46` (wpis robi architekt po weryfikacji), `_docs/10` i ADR-y,
- serwer: zero zmian. Wolno wyłącznie zapytania read-only przez te skrypty.

## 4. Kroki

KROK 0. Odczyt i synchronizacja.
```
cd /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026   # albo /Volumes/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026
git fetch && git log --oneline HEAD..origin/main
```
Pusto: dalej. Niepusto: `git pull --rebase --autostash`, przy konflikcie STOP.
Przeczytaj w całości trzy pliki z zakresu i ten task.

KROK 1. Kopie wersji sprzed zmiany (potrzebne do mutantów).
```
cd _diag_local/chat_verification
for f in mysql_dev mysql_pmk ssh_pmk; do cp $f.py /tmp/t194_$f.orig.py; md5 -q $f.py; done
```
md5 trzech plików do raportu.

KROK 2. Zmiany wg §2, przez kopię i `mv -f` (§1 pkt 5).

KROK 3. Testy. Każdy wynik dosłownie do raportu.
- A1 klasa błędu: `grep -nF '~\' *.py` w katalogu → pusto. Próba kontrolna: przed
  zmianą ta sama komenda daje 6 trafień (pomiar architekta 2026-10-13), powtórz ją w KROKU 1.
- A2 DEV działa: `python3 mysql_dev.py -c "SELECT DATABASE()"` → `divezone_dev`.
- A3 MUTANT ścieżki: `python3 /tmp/t194_mysql_dev.orig.py -c "SELECT DATABASE()"` → MA
  paść (brak pliku klucza). Bez tego A2 nic nie dowodzi.
- A4 read-only: `python3 mysql_dev.py -c "UPDATE pr_configuration SET value=value WHERE 1=0"`
  → odmowa lokalna, bez połączenia. Próba dodatnia: A2.
- A5 PROD: `python3 mysql_pmk.py -c "SELECT DATABASE()"` → `divezone_2025`.
  `python3 ssh_pmk.py -c "hostname"` → rc 0. Mutanty: oba `.orig.py` z /tmp → MAJĄ paść.
- A6 MUTANT known_hosts brak: kopia `mysql_dev.py` do /tmp z KNOWN_HOSTS podmienionym
  na nieistniejącą ścieżkę → MA paść z komunikatem o brakującym pliku, przed połączeniem.
- A7 MUTANT host nieznany: kopia z KNOWN_HOSTS wskazującym pusty plik w /tmp → MA paść
  na odrzuceniu klucza hosta. Próba dodatnia: A2.
- A8 Windows: nie testujesz (brak maszyny). Napisz w raporcie wprost „nie testowane na
  Windows" i na czym opierasz przenośność.
Sprzątnij `/tmp/t194_*` po testach.

KROK 4. `/codex` na diff trzech plików. Wynik i Twoje niezgody do raportu
NIEROZSTRZYGNIĘTE.

KROK 5. Commit (pliki są nowe w git, więc diff = całe pliki).
```
git status --short
git add _diag_local/chat_verification/mysql_dev.py
git add _diag_local/chat_verification/mysql_pmk.py
git add _diag_local/chat_verification/ssh_pmk.py
git diff --cached --stat        # dokładnie 3 pliki
git commit -m "fix(CHAT-T-194): czytniki paramiko na macOS, RejectPolicy, wersjonowanie (Chat-120)"
git push origin main
```
Nigdy `git add .`.

KROK 6. Raport `_reports/CHAT-T-194_czytniki-paramiko-macos.md` (md5 przed i po, wyniki
A1..A8, recenzja, hash commita) + wpis NA GÓRZE `_docs/21_STATUS_PROJEKTU.md`, osobny
commit `docs(CHAT-T-194): ...`, `git pull --rebase` przed push. Karty Trello nie ruszasz.

Ostatnia linia raportu: ścieżka raportu.

═══ CHAT-T-194 · INTEGRATION · czytniki paramiko na macOS ═══
