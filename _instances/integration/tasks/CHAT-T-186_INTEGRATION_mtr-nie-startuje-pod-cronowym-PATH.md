# CHAT-T-186 — INTEGRATION: mtr wdrożony w T-185 NIE DZIAŁA na produkcji (cronowy PATH)

**Instancja:** integration. `_docs/scripts/railway_monitor.php`, funkcja `mtrCommands()`.
**Powiązane:** CHAT-T-185 (wdrożony 2026-09-09, md5 prod `0c778965584c0220b070d968414ce613`),
karta Trello **Chat - 88**, zgłoszenie smarthost **#167585**.
**Status:** DO ZROBIENIA. **Priorytet: funkcja jest martwa na produkcji od wdrożenia.**

---

## OBJAW — PIERWSZY BASELINE Z PRODUKCJI JEST PUSTY

`~/_diag/mtr_baseline_20260909.txt`, wygenerowany automatem o 18:30:02 UTC:

```
=== mtr ICMP 66.33.22.230 (strata per hop) ===
/usr/sbin/mtr: Failure to start mtr-packet: Invalid argument

=== mtr TCP 66.33.22.230:14368 (ta sama sciezka na porcie, ktory realnie pada) ===
/usr/sbin/mtr: Failure to start mtr-packet: Invalid argument
```

Oba przebiegi padły natychmiast, cały blok zajął 0 sekund. Test wymuszony w `/tmp` z KROKU 5
przeszedł, bo był uruchamiany z powłoki interaktywnej. **Ścieżka produkcyjna nie przeszła.**

## PRZYCZYNA — ZMIERZONA, NIE ZGADNIĘTA

Środowisko żywego procesu monitora, odczytane z `/proc/<pid>/environ` 2026-09-10:

```
PATH=/usr/bin:/bin
```

Monitor jest wskrzeszany przez `railway_monitor_guard.sh` z **crona**, więc dziedziczy minimalny
PATH crona. `/usr/sbin` w nim nie ma.

Odtworzenie i weryfikacja poprawki, ten sam host, ta sama komenda:

```
env -i PATH=/usr/bin:/bin           /usr/sbin/mtr --report ... -> Failure to start mtr-packet: Invalid argument
env -i PATH=/usr/sbin:/usr/bin:/bin /usr/sbin/mtr --report ... -> pelny raport, hop 11, 0.0% strat
```

**Mechanizm:** `mtr` uruchamia pomocniczy proces `mtr-packet` **po samej nazwie**, przez PATH.
Podanie pełnej ścieżki do `mtr` nie pomaga, bo to nie `mtr` się gubi, tylko jego dziecko.
Dlatego objaw pojawia się wyłącznie tam, gdzie PATH jest okrojony: cron, guard, monitor.

Konteksty, w których mtr DZIAŁA (sprawdzone, żeby wykluczyć fałszywe tropy): powłoka
interaktywna, `stdin < /dev/null`, `nohup` w tle, `shell_exec()` z PHP, `flock` plus `nohup`.
Żaden z nich nie był przyczyną — decyduje wyłącznie PATH procesu wołającego.

## NOTA DO WCZEŚNIEJSZYCH USTALEŃ

CHAT-T-185 podawał jako przyczynę lipcowej pomyłki „`/usr/sbin` poza PATH użytkownika".
CC to odrzucił i miał rację: PATH konta `divezone` oraz PATH w `shell_exec()` z powłoki
interaktywnej zawierają `/usr/sbin`, a `command -v mtr` zwraca tam pełną ścieżkę.
Prawdziwy mechanizm jest sąsiedni i węższy: chodzi o PATH **procesu cronowego** i o proces
**`mtr-packet`**, nie o `mtr`. Obie wcześniejsze wersje były niepełne.

---

## ZAKRES — JEDNA ZMIANA

W `mtrCommands()` (`railway_monitor.php`, ok. linii 143-157) poprzedź oba wywołania ustawieniem
PATH, tak żeby `mtr` znalazł `mtr-packet` niezależnie od środowiska wołającego. Wariant do
zastosowania w budowanym łańcuchu poleceń:

```
PATH=/usr/sbin:/usr/bin:/bin timeout 90 /usr/sbin/mtr --report --report-wide ...
```

Pełną ścieżkę do samego `mtr` **zostaw** — jest poprawna i nic nie kosztuje.
W komentarzu nad funkcją zastąp obecne wyjaśnienie tym zmierzonym: `mtr-packet` szukany
przez PATH, monitor dziedziczy `PATH=/usr/bin:/bin` z crona, dowód z `/proc/<pid>/environ`
i z pary `env -i`, data 2026-09-10.

Sprawdź, czy w pliku nie ma **innych** wywołań poleceń zewnętrznych zależnych od `/usr/sbin`
(przejrzyj wszystkie `shell_exec`, `timeout`, `nohup` w tym pliku) i zgłoś je w raporcie —
nie naprawiaj tego, czego nie potwierdzisz pomiarem.

---

## TESTY AKCEPTACYJNE

1. `ea-php84 -l` czysty.
2. **Test w warunkach cronowych, nie interaktywnych.** Uruchom wymuszony zrzut oraz baseline
   przez `env -i PATH=/usr/bin:/bin ...`, czyli w tym samym okrojonym środowisku, jakie ma
   monitor. Oba mtr mają dać pełne raporty do hopa 11. Ten test jest sednem zadania —
   test z powłoki interaktywnej niczego nie dowodzi i już raz nas zmylił.
3. Po wdrożeniu i restarcie monitora odczytaj `/proc/<pid>/environ` nowego procesu
   i wpisz PATH do raportu.
4. **Dowód końcowy przychodzi sam:** kolejny baseline o 18:30 UTC ma zawierać dwa pełne
   raporty mtr. Sprawdź plik `mtr_baseline_<data>.txt` następnego dnia i dopisz wynik
   do raportu. Do tego czasu zadania nie uznajemy za zamknięte.
5. Cztery dowody restartu jak w CHAT-T-183 i CHAT-T-185.

## RECENZJA KRZYŻOWA
`/codex` na diffie. Niezgody NIEROZSTRZYGNIĘTE.

## WDROŻENIE
Jeden plik do `~/_diag/railway_monitor.php`. Backup `.bak_20260910`.
Stan przed zmianą: md5 `0c778965584c0220b070d968414ce613`.
**STOP przed rsync, czekaj na „deployuj" (ADR-089).**

## GIT
`git add` per ścieżka. Commit `fix(CHAT-T-186): ...`, osobny `docs(CHAT-T-186): ...`.
Do `_docs/44`, sekcja PUŁAPKI, dopisz linijkę o `mtr-packet` i cronowym PATH, z dowodem.
`git push origin main`.

## NIE RUSZAĆ
`railway_summary_mail.php`, `railway_monitor_guard.sh`, crontab, logi i zrzuty w `~/_diag/`,
`probe.py` z CHAT-T-184, produkcyjna baza Railway, `_ops/newtmp2_root/purge_litespeed.php`,
`standalone/config/routes.php`, `standalone/config/tools.php`, pliki ADR.

## RAPORT KOŃCOWY
Wynik testu w środowisku `env -i PATH=/usr/bin:/bin`, PATH nowego procesu monitora
z `/proc/<pid>/environ`, lista innych wywołań zależnych od `/usr/sbin` w tym pliku
(albo jawne „brak"), md5 przed i po, cztery dowody restartu, wynik `/codex`.
