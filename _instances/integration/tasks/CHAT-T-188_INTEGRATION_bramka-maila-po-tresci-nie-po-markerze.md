# CHAT-T-188 — INTEGRATION: bramka maila do smarthosta ma sprawdzać TREŚĆ, nie marker końca

**Instancja:** integration. `_docs/scripts/railway_monitor.php`.
**Powiązane:** CHAT-T-185 (mail do smarthosta), CHAT-T-186 (cronowy PATH), karta **Chat - 88**,
zgłoszenie smarthost **#167585**.
**Status:** DO ZROBIENIA. **BLOKUJE włączenie `SMARTHOST_TICKET_MAIL` w `.env`.**

---

## DLACZEGO TO JEST PILNE

Karol ma adres (`hosting@smarthost.pl`) i jest o jedną linijkę w `.env` od włączenia
automatycznej wysyłki do dostawcy. Zanim ta linijka powstanie, bramka musi umieć odróżnić
zrzut z danymi od zrzutu z komunikatem błędu.

CC zgłosił to jako nierozstrzygnięte w raporcie CHAT-T-186 i ma rację.

## OBJAW, KTÓRY JUŻ WYSTĄPIŁ

Zrzut z 2026-09-09 18:30:02 UTC zawierał wyłącznie:

```
=== mtr ICMP 66.33.22.230 (strata per hop) ===
/usr/sbin/mtr: Failure to start mtr-packet: Invalid argument
```

Marker `=== DIAG koniec` zapisuje się **bezwarunkowo**, na końcu payloadu, niezależnie od tego,
czy którekolwiek polecenie coś zmierzyło. Bramka wysyłki uznaje obecność markerów za dowód
kompletności. Gdyby `SMARTHOST_TICKET_MAIL` był wtedy ustawiony, do dostawcy poszedłby mail
z pustymi sekcjami mtr i zdaniem, że załączamy pomiar. To najgorszy możliwy wynik dla
wiarygodności całego zgłoszenia — sami byśmy im dali argument.

CHAT-T-186 usuwa przyczynę tego konkretnego błędu, ale nie usuwa klasy: każde przyszłe
niepowodzenie narzędzia (timeout, brak uprawnień, zmiana wersji) da ten sam efekt.

## ZAKRES

### 1. Bramka po treści

Warunkiem wysłania maila do smarthosta ma być obecność **realnych danych pomiarowych**
w zrzucie, nie obecność markera końca. Minimum:

- w obu sekcjach mtr wiersz nagłówkowy raportu (`HOST:`) oraz co najmniej jeden wiersz hopa
- w sekcjach ping wiersz `packet loss` dla Railway i dla co najmniej jednej kontroli

Kryterium wyprowadź z realnego pliku zrzutu, nie z pamięci — weź `incident_20260902_*.txt`
jako wzorzec kompletnego i `mtr_baseline_20260909.txt` jako wzorzec pustego.

Gdy warunek nie jest spełniony: **nie wysyłaj**, zapisz w logu głównym linię
`### MAIL-SMARTHOST pominiety | powod=niekompletny zrzut | plik=...` i zostaw zdarzenie
w kolejce zgodnie z obecną logiką, żeby nie zgubić epizodu.

### 2. Alert do Karola, gdy zrzut jest pusty

Jeżeli zrzut nie przechodzi bramki, Karol ma się o tym dowiedzieć **tego samego dnia**,
a nie przy okazji następnej sesji. Dołóż tę informację do istniejącego maila alertowego
albo do raportu dobowego (T-182/183) — wybierz to, co nie mnoży kanałów, i uzasadnij wybór
w raporcie. Cicha degradacja jest tu zakazana: to dokładnie ta klasa awarii, która ukryła
raport dobowy na 67 dni.

### 3. Ponowienie nieudanego baseline'u

Nieudany baseline tworzy plik, a mechanizm nadrabiania sprawdza tylko `file_exists()`,
więc tego dnia nie ma drugiej próby. Zmień warunek nadrabiania na ten sam test treści
co w punkcie 1. Jedno ponowienie w ciągu doby wystarczy, nie buduj pętli.

### 4. Poza zakresem

`MTR_PACKET` jako alternatywa dla `PATH` — CC zmierzył, że działa, i to jest węższe
rozwiązanie, ale wariant z `PATH` jest wdrożony i przetestowany. Nie przepisujemy tego teraz.
Odnotuj w raporcie jako opcję na przyszłość.

---

## TESTY AKCEPTACYJNE

1. Zrzut kompletny (`incident_20260902_*.txt` skopiowany do `/tmp`) przechodzi bramkę.
2. Zrzut pusty (`mtr_baseline_20260909.txt`) bramki NIE przechodzi i zostawia wpis w logu.
3. Zrzut częściowy: ping kompletny, obie sekcje mtr z samym komunikatem błędu — NIE przechodzi.
4. Zrzut częściowy odwrotnie: mtr kompletny, ping ucięty w połowie — udokumentuj, jak
   zachowuje się bramka, i uzasadnij, czy to jest zachowanie właściwe.
5. Ponowienie baseline'u: podłóż pusty plik z dzisiejszą datą i pokaż, że mechanizm
   nadrabiania go powtarza.
6. Wszystkie testy w `/tmp`, `~/_diag` nietknięty.

## RECENZJA KRZYŻOWA
`/codex` na diffie, szczególnie na fałszywe negatywy bramki (zrzut poprawny uznany za pusty —
to gorsze niż fałszywy pozytyw, bo cicho gubi dowód). Niezgody NIEROZSTRZYGNIĘTE.

## WDROŻENIE
Jeden plik do `~/_diag/railway_monitor.php`. Backup `.bak_YYYYMMDD`.
Stan przed zmianą: md5 z wdrożenia CHAT-T-186 (podaj w raporcie odczytany z produkcji,
nie z pamięci). **STOP przed rsync, czekaj na „deployuj" (ADR-089).**
Po wdrożeniu restart monitora i cztery dowody jak w CHAT-T-183.

## GIT
`git add` per ścieżka. Commit `fix(CHAT-T-188): ...`, osobny `docs(CHAT-T-188): ...`.
`git push origin main`.

## NIE RUSZAĆ
`railway_summary_mail.php` poza punktem 2, jeśli wybierzesz raport dobowy jako kanał —
wtedy zmiana ma być minimalna i opisana osobno. Dalej: `railway_monitor_guard.sh`, crontab,
logi i zrzuty w `~/_diag/`, `probe.py`, produkcyjna baza Railway,
`_ops/newtmp2_root/purge_litespeed.php`, `standalone/config/routes.php`,
`standalone/config/tools.php`, pliki ADR.

## RAPORT KOŃCOWY
Kryterium bramki wyprowadzone z realnych plików (zacytuj wiersze, na których się opierasz),
wynik pięciu testów, wybór kanału powiadomienia z uzasadnieniem, wynik `/codex`,
md5 przed i po, cztery dowody restartu.
