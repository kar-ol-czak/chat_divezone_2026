# CHAT-T-182 — INTEGRATION: naprawa raportu dobowego monitora Railway (martwy od 2026-06-29)

**Instancja:** integration. Zmiana w `_docs/scripts/railway_summary_mail.php` (repo) i wdrożenie do `~/_diag/` na serwerze.
**Powiązane:** CHAT-T-108 (monitor bazowy, rozbił metryki PG), CHAT-T-109 (cron-guard), CHAT-T-119 (zrzuty incydentów), karta Trello **Chat - 88**.
**Status:** DO ZROBIENIA.

---

## KONTEKST — CO JEST ZEPSUTE I SKĄD TO WIADOMO

Raport dobowy o stanie trasy serwer → Railway PG nie został wysłany ANI RAZU od 2026-06-29.
Alerty epizodowe działają, raport dobowy milczy. Skutek: nie ma narzędzia, które odpowiada na
pytanie „czy trasa jest zdrowa", więc ocena stanu opiera się na wrażeniach.

**Dowód 1 — parser nie dopasowuje ani jednej linii.**
`_docs/scripts/railway_summary_mail.php:34`:
```
preg_match('/^(\S+ \S+) UTC \| (\S+) WAW \| railway_tcp (\w+).*?railway_pg (\w+).*?github (\w+)/', $ln, $m)
```
Pole `railway_pg` zniknęło z logu 2026-06-29, gdy CHAT-T-108 rozbił je na cztery metryki:
`pg_select1`, `pg_settings`, `pg_chiptree`, `pg_upsert`.
Ostatni log zawierający `railway_pg`: `railway_monitor_20260628.log` (36 wystąpień).
Od `railway_monitor_20260629.log` — zero wystąpień.
Efekt: `$N === 0` → `return 'noop:no-data'` (linia 56).

**Dowód 2 — status w logu crona, codziennie od dwóch miesięcy.**
`/home/divezone/_diag/cron_fallback.log`:
```
[railway_summary_mail] 2026-09-03 07:05:01 status=noop:no-data
```
Ten sam wpis każdego dnia. Zero plików `mail_sent_*.flag` w `/home/divezone/_diag/`.

**Dowód 3 — RAPORT PATRZY NA ZŁE OKNO DOBY (to jest większy błąd niż regex).**
`railway_summary_mail.php:18` bierze `now` w strefie WAW i czyta log O TEJ SAMEJ DACIE.
Cron chodzi o 07:05 WAW (crontab: `5 7 * * *`), a monitor wysyła in-process o 07:00
(`railway_monitor.php:219`). Log rotuje o północy WAW = 22:00 UTC, więc plik dnia D zawiera
22:00 UTC (D-1) → 21:59 UTC (D).
O 07:05 dnia D raport pokrywa więc **22:00 UTC (D-1) → 05:05 UTC (D)**, czyli 00:00-07:05 WAW.
Awarie są wieczorem. Rozkład `railway_tcp FAIL` wg godziny UTC, sierpień + wrzesień 2026:
```
godziny 00-07 UTC:   7 FAIL
godziny 16-21 UTC: 934 FAIL
```
Raport pokrywa okno z 7 awariami i pomija okno z 934. Sama naprawa regexa dałaby raport,
który prawie zawsze pisze „Brak. Lacze stabilne".

**Dowód 4 — interwał w treści maila jest 5x zawyżony.**
`railway_monitor.php:67`: `$INTERVAL = 5;`
Realny odstęp próbek zmierzony na `railway_monitor_20260903.log`: 22:09:24, :30, :36, :41, :47,
:53, :58 — około 5,7 s.
`railway_summary_mail.php:63` pisze „interwal 25s", a linia 80 liczy `$dur = $w[2] * 25`.
Każda długość okna w mailu byłaby 5x za duża.

---

## CEL

Raport dobowy ma codziennie o 07:05 WAW opisywać **pełną poprzednią dobę WAW**, z podziałem na
cztery metryki PG, i podawać długości okien awarii liczone z realnych znaczników czasu.

---

## ZAKRES ZMIAN — `_docs/scripts/railway_summary_mail.php`

### 1. Okno raportu: poprzednia pełna doba WAW
`$date` liczone jako `(new DateTime('now', $waw))->modify('-1 day')->format('Ymd')`.
Flaga dedup `mail_sent_<data_raportowana>.flag` (klucz = raportowana doba, nie dzień wysyłki),
żeby ścieżka in-process (monitor, `fallback=false`) i ścieżka cron (`fallback=true`) dalej się
deduplikowały tak jak dziś.
Zachować `noop:no-log`, gdy log poprzedniej doby nie istnieje.

### 2. Nowy regex pod aktualny format linii
Format produkcyjny (zweryfikowany na `railway_monitor_20260902.log`):
```
#05211 2026-09-02 13:17:33 UTC | 15:17:30 WAW | railway_tcp OK       33ms | pg_select1 OK      281ms | pg_settings OK      104ms | pg_chiptree OK      104ms | pg_upsert OK      105ms | github FAIL   2116ms | errno=0
```
Parsować: znacznik UTC, znacznik WAW, `railway_tcp`, cztery metryki PG osobno, `github`, `errno`.
Nie dopasowywać linii `# metryki:` ani `### ALERT` — te idą osobnymi licznikami (punkt 5).
Numer cyklu `#NNNNN` resetuje się przy każdym restarcie monitora przez cron-guard
(`railway_monitor_20260902.log` ma 33 nagłówki `# metryki:`), więc **nie wolno** używać numeru
cyklu do liczenia czegokolwiek. Sekwencję buduj wyłącznie na znacznikach czasu.

### 3. Wskaźniki per metryka
Zamiast jednej linii `Railway PG` — pięć: `railway_tcp`, `pg_select1`, `pg_settings`,
`pg_chiptree`, `pg_upsert`, plus `github` jako kontrola wyjścia hostingu.
Zachować sekcję „DOWOD KORELACYJNY": FAIL metryki PG przy `github OK` (to jest argument
w rozmowie z hostingiem, że problem jest specyficzny dla Railway).
Dodać licznik `errno=110` (ETIMEDOUT) — to najczystszy sygnał timeoutu połączenia.

### 4. Okna awarii liczone z czasu, nie z liczby cykli
Okno = ciąg kolejnych próbek z `pg_select1 FAIL` (metryka wiodąca: jeden connect + SELECT 1).
Długość okna = różnica znaczników WAW pierwszej i ostatniej próbki okna, sformatowana `Xm Ys`.
Usunąć mnożnik `* 25` i tekst „interwal 25s". Jeżeli interwał ma się pojawić w treści maila,
policz medianę odstępów między próbkami z parsowanego logu — nie wpisuj stałej.

### 5. Kontekst epizodów
Doliczyć z tego samego logu liczbę linii `### ALERT` (ile maili alertowych poszło danej doby)
oraz wypisać nazwy plików `incident_<data>_*.txt` z `$diagDir` dla raportowanej doby.
Dla każdego pliku incydentu wyciągnąć procent strat pingu do `66.33.22.230` i do kontroli
`5.79.108.33` — jedna linia na plik, format:
```
  incident_20260902_172907.txt | Railway 100% strat | Leaseweb 0% strat
```
To jest gotowy materiał do eskalacji, bez ręcznego grzebania w zrzutach.
Parsowanie tylko odczytem, bez modyfikacji plików incydentów.

### 6. Czego NIE zmieniać
- Nagłówek `From:`, adresaci, `Content-Type` — bez zmian.
- Kontrakt funkcji `railway_summary_send(string $diagDir, DateTimeZone $waw, string $to, bool $fallback): string`
  — sygnatura i zbiór statusów zwrotnych zostają (`sent | skip:flag-exists | noop:no-log | mail-failed | noop:no-data`).
  Monitor woła ją w `railway_monitor.php:244`, nie ruszaj tego wywołania.
- Guard `railway_monitor_guard.sh` — poza zakresem.
- `railway_monitor.php` — poza zakresem tego taska. Jedyny wyjątek: jeżeli okaże się, że
  in-process wysyłka o 07:00 (`railway_monitor.php:219-249`) po zmianie okna wysyła raport
  o innej dobie niż cron, ZGŁOŚ to w raporcie, nie naprawiaj sam.

---

## TESTY AKCEPTACYJNE (liczby zmierzone 2026-09-03, muszą się zgodzić)

Uruchom parser na dwóch logach z serwera, bez wysyłki maila (dorób tryb `--dry-run <YYYYMMDD>`
wypisujący treść na stdout, albo osobny skrypt testowy — nie zostawiaj hacka w ścieżce cronowej).

**Log `railway_monitor_20260902.log` (doba z dużym epizodem):**
```
próbki (linie pomiarowe):  14331
railway_tcp FAIL:             84
pg_select1  FAIL:            183
github      FAIL:             42
errno=110:                    84
linie ### ALERT:              21
plików incident_20260902_*.txt: 21
```
Pierwszy alert w logu: `15:08:42 UTC / 17:08:32 WAW`. Ostatni: `16:44:48 UTC / 18:44:39 WAW`.
Raport MUSI pokazać co najmniej jedno okno awarii w godzinach wieczornych WAW.

**Log `railway_monitor_20260901.log` (doba czysta):**
```
próbki:                    15238
railway_tcp FAIL:              1
pg_select1  FAIL:              1
github      FAIL:              0
errno=110:                     1
linie ### ALERT:               0
```
Raport MUSI napisać, że doba była czysta, i NIE MOŻE zwrócić `noop:no-data`.

Rozbieżność choćby o jedną próbkę zgłoś, nie zaokrąglaj i nie dopasowuj testu do wyniku.

---

## WDROŻENIE — UWAGA, TO JEST TRZECI ŚWIAT

Ten plik nie należy ani do backendu `chat.divezone.pl`, ani do modułu w `newtmp2`.
Miejsce docelowe: **`/home/divezone/_diag/railway_summary_mail.php`**, jeden plik.

Stan przed zmianą (zweryfikowany 2026-09-03): repo i produkcja są zgodne,
md5 `fbafb8bd0239dd5d59def6f5b27d5af7` po obu stronach.

Kroki wdrożenia:
1. Backup: `cp ~/_diag/railway_summary_mail.php ~/_diag/railway_summary_mail.php.bak_YYYYMMDD`
2. **STOP — czekaj na słowo „deployuj" od Karola (ADR-089).**
3. `rsync` / `scp` JEDNEGO pliku, port 5739. Nie synchronizuj katalogu `_docs/scripts/`.
4. `ea-php84 -l /home/divezone/_diag/railway_summary_mail.php`
5. md5 local == prod.
6. **PUŁAPKA:** `railway_monitor.php:50` robi `require` tego pliku PRZY STARCIE. Działający
   proces monitora ma w pamięci STARY kod i będzie go miał do restartu. Po wdrożeniu:
   `pkill -9 -f "railway_monitor\.php"` — cron-guard wskrzesi monitor w ciągu 60 s
   (`railway_monitor_guard.sh`, próg świeżości logu 60 s). Potwierdź restart wpisem
   w `~/_diag/guard.log` i świeżym nagłówkiem `# metryki:` w logu dnia.
7. Test na żywo: uruchom `ea-php84 /home/divezone/_diag/railway_summary_mail.php` ręcznie,
   sprawdź `status=` na stdout. Jeżeli flaga poprzedniej doby już istnieje, usuń ją PRZED
   testem, żeby nie dostać `skip:flag-exists`.

## RECENZJA KRZYŻOWA
Po napisaniu diffa, przed commitem: `/codex` na diffie. Wynik recenzji i swoje niezgody
z recenzentem wpisz do raportu NIEROZSTRZYGNIĘTE — decyduje architekt.

## GIT
- `git pull --rebase` przed commitem.
- `git add` per ścieżka. Nigdy `git add .` ani `git add -A`.
- Commit: `fix(CHAT-T-182): raport dobowy Railway — nowy format metryk, okno poprzedniej doby`
- Osobny commit `docs(CHAT-T-182): ...` na aktualizację `_docs/21_STATUS_PROJEKTU.md` (dopisz NA GÓRZE).
- `git push origin main`.

## NIE RUSZAĆ
`_ops/newtmp2_root/purge_litespeed.php` (SEKRET), `standalone/config/routes.php`
(niezacommitowana zmiana innej sesji), `standalone/config/tools.php`, pliki ADR,
`railway_monitor.php`, `railway_monitor_guard.sh`, logi i pliki incydentów w `~/_diag/`.

## RAPORT KOŃCOWY
Wypisz: co zmieniłeś (plik + linie), wynik obu testów akceptacyjnych liczba po liczbie,
wynik `/codex`, md5 po wdrożeniu, potwierdzenie restartu monitora, status ręcznego uruchomienia.
Jeżeli którakolwiek liczba akceptacyjna się nie zgadza — nie zamykaj taska, zgłoś rozbieżność.
