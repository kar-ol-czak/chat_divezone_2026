# CHAT-T-189 — INTEGRATION: monitor pada 2-3 razy dziennie na `max_execution_time` + zła data w README sondy

**Instancja:** integration. `_docs/scripts/railway_monitor.php` oraz
`_diag_local/railway_reverse_probe/README.md`.
**Powiązane:** CHAT-T-182/183 (raport dobowy liczy pokrycie), CHAT-T-186 (cronowy PATH),
CHAT-T-187 (sonda na Railway), karta Trello **Chat - 88**.
**Status:** DO ZROBIENIA.

---

## CZĘŚĆ 1 — `Maximum execution time of 30 seconds exceeded`

### Objaw, zmierzony

`~/_diag/monitor_nohup.out` zawierał 130 wpisów (pomiar 2026-09-09), a przy wdrożeniu
CHAT-T-188 baseline wynosił **139 Fatal, 0 Parse**:

```
Fatal error: Maximum execution time of 30 seconds exceeded in /home/divezone/_diag/railway_monitor.php on line 147
Fatal error: ... on line 101
Fatal error: ... on line 180
Fatal error: ... on line 158
```

Numery linii są różne, czyli limit wyczerpuje się stopniowo i zabija proces w losowym miejscu,
a nie na jednym konkretnym wywołaniu.

`guard.log`, rozkład przyczyn restartu na dobę (pomiar 2026-09-04 i 2026-09-10):

```
doby czyste (08-22..09-01, 09-03, 09-04):  ZAMARCIE=0   "proces martwy"=2-3
doby z epizodem (08-27, 09-02):            ZAMARCIE=20-39  "proces martwy"=1
```

`ZAMARCIE` to objaw sieciowy i występuje wyłącznie w dobach z epizodem.
**`proces martwy` to ten fatal i zdarza się 2-3 razy KAŻDEJ doby, niezależnie od stanu łącza.**

### Dlaczego to teraz przeszkadza

Każdy taki zgon kosztuje do 60 sekund pomiaru, bo tyle trwa cykl wskrzeszenia przez guarda.
To poniżej 0,2% doby i przez trzy miesiące nikomu nie wadziło. Ale od CHAT-T-183 raport dobowy
liczy **pokrycie doby** i pokazuje **największą przerwę**, a te liczby idą do dostawcy jako
materiał dowodowy. Nie chcemy tłumaczyć, dlaczego nasz własny monitor pada trzy razy dziennie,
w piśmie o niestabilności ich trasy.

### Zakres

Monitor to skrypt CLI z nieskończoną pętlą, więc limit czasu jest tu bez sensu.
Ustaw go jawnie w skrypcie, na samej górze, przed pętlą.

**Najpierw zweryfikuj, że to w ogóle zadziała na tym hoście**, zanim wpiszesz na stałe:

- odczytaj realną wartość `max_execution_time` dla `ea-php84` w trybie CLI
- sprawdź, czy `set_time_limit` nie jest w `disable_functions`
- jeżeli funkcja jest wyłączona, **zatrzymaj się i zgłoś** — wtedy rozwiązaniem jest wpis
  w `.user.ini` albo w konfiguracji PHP, a to jest zmiana poza naszym zakresem i decyduje
  architekt

Zmiana ma dotyczyć wyłącznie procesu monitora. Nie ruszaj limitów globalnie i nie dokładaj
niczego do konfiguracji serwera.

### Uwaga na podprocesy

`captureNetworkDiag()` i dobowy baseline odpalają się w tle jako osobne procesy powłoki,
więc limit PHP ich nie dotyczy i nie wolno przy nich niczego zmieniać. Potwierdź to w raporcie,
żeby nie było wątpliwości, że zmiana ma wąski zasięg.

---

## CZĘŚĆ 2 — data wyłączenia sondy w README jest z przeszłości

`_diag_local/railway_reverse_probe/README.md`, linie 85 i 159, mówią:

```
Data wyłączenia: 2026-09-08 (po trzech pełnych wieczorach: 04, 05, 06 i 07.09)
Usunięcie po zakończeniu (do 2026-09-08)
```

Data była pisana 2026-09-04 przy założeniu, że sonda ruszy tego samego dnia.
**Sonda wystartowała dopiero 2026-09-10 o 08:41 UTC** (pierwszy cykl `#00001`, wdrożenie
`005030d0`). Data z README jest o dwa dni w tyle i ktoś, kto się nią kieruje, wyłączy pomiar,
zanim ten cokolwiek zmierzy.

Popraw na: trzy pełne wieczory to **10, 11, 12 i 13.09**, usunięcie serwisu **14.09**.
Dopisz jedno zdanie, że data liczy się od realnego startu sondy, nie od napisania dokumentu.

Przy okazji dopisz do README fakt z pierwszego uruchomienia, bo jest istotny dla pisma:
sonda wykryła rozjazd DNS. `chat.divezone.pl` rozwiązuje się na adresy Cloudflare
(`104.26.8.54`, `104.26.9.54`, `172.67.75.232` — whois: CLOUDFLARENET, sprawdzone 2026-09-10),
a sonda celowo idzie na `193.93.88.95` bezpośrednio. Gdyby szła za DNS-em, mierzyłaby
Cloudflare zamiast naszego serwera.

---

## TESTY AKCEPTACYJNE

1. `ea-php84 -l` czysty.
2. Odczytana wartość `max_execution_time` w CLI oraz wynik sprawdzenia `disable_functions`
   — obie liczby do raportu.
3. Po wdrożeniu i restarcie: w nowym procesie potwierdź, że limit jest zdjęty
   (odczyt `ini_get('max_execution_time')` z działającego procesu albo równoważny dowód).
4. **Dowód końcowy przychodzi po dobie i nie da się go przyspieszyć:** przez 24 godziny
   od wdrożenia w `monitor_nohup.out` ma NIE przybyć ani jeden wpis `Maximum execution time`,
   a w `guard.log` ma nie być ani jednego `proces martwy`. Wpisy `ZAMARCIE` mogą się pojawić,
   jeżeli wystąpi epizod, i to nie jest regresja. Zapisz liczbę bazową Fatal PRZED wdrożeniem
   i porównaj po dobie.
5. README: pokaż poprawione linie 85 i 159 oraz dopisany akapit o DNS.

## RECENZJA KRZYŻOWA
`/codex` na diffie. Niezgody NIEROZSTRZYGNIĘTE.

## WDROŻENIE
Jeden plik na serwer: `~/_diag/railway_monitor.php`. Backup `.bak_YYYYMMDD`.
Stan przed zmianą odczytaj Z PRODUKCJI, nie z pamięci (przy pisaniu zlecenia było
`1215c89d60cf38d734c7caac3b5e63e6`). **STOP przed rsync, czekaj na „deployuj" (ADR-089).**
Po wdrożeniu restart monitora i cztery dowody jak w CHAT-T-183.
README nie idzie na serwer — to zmiana wyłącznie w repo.

## GIT
`git add` per ścieżka. Commit `fix(CHAT-T-189): ...` na monitor, osobny
`docs(CHAT-T-189): ...` na README i status. `git push origin main`.

## NIE RUSZAĆ
`.env`, `railway_summary_mail.php`, `railway_monitor_guard.sh`, crontab, konfiguracja PHP
serwera, logi i zrzuty w `~/_diag/`, `probe.py`, `railpack.json`, `requirements.txt`,
produkcyjna baza Railway, `_ops/newtmp2_root/purge_litespeed.php`,
`standalone/config/routes.php`, `standalone/config/tools.php`, pliki ADR.

## RAPORT KOŃCOWY
Wartość `max_execution_time` w CLI, wynik sprawdzenia `disable_functions`, dowód że limit
jest zdjęty w nowym procesie, liczba bazowa Fatal przed wdrożeniem, poprawione linie README,
wynik `/codex`, md5 przed i po, cztery dowody restartu. Zadania nie zamykaj przed upływem
doby od wdrożenia i sprawdzeniem punktu 4.
