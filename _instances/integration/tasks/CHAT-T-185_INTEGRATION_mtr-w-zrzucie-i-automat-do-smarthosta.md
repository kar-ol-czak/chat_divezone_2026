# CHAT-T-185 — INTEGRATION: mtr w zrzucie epizodu + automatyczne powiadomienie smarthosta

**Instancja:** integration. `_docs/scripts/railway_monitor.php`, wdrożenie do `~/_diag/`.
**Powiązane:** karta Trello **Chat - 88**, zgłoszenie smarthost **#167585**, CHAT-T-119
(zrzuty epizodowe), CHAT-T-184 (strona Railway, NIE dubluj jej tutaj).
**Status:** DO ZROBIENIA.

---

## KONTEKST — SMARTHOST PROSI O DOKŁADNIE TO, CO MOŻEMY DAĆ AUTOMATEM

Odpowiedź Smarthost z 2026-09-09 10:37 (Tomasz Kielan): proszą o `mtr` z obu stron sieci
w chwili wystąpienia strat oraz o mailowe powiadomienie, gdy strata zostanie odnotowana.

## KOREKTA FAKTU, KTÓRY BLOKOWAŁ NAS OD LIPCA

`_instances/integration/tasks/CHAT-T-119_INTEGRATION_diagnostyka-sieciowa-epizod.md:21`
mówi: *„Narzędzia na serwerze: ping, traceroute, tracepath (POTWIERDZONE dostępne).
`mtr` BRAK — NIE używać."*

**To jest nieprawda.** Pomiar 2026-09-09 14:42 na produkcji:

```
/usr/sbin/mtr           -rwxr-xr-x root root 113584 Dec 15  2019
/usr/sbin/mtr-packet    -rwxr-xr-x root root  59704 Dec 15  2019
getcap /usr/sbin/mtr-packet  ->  cap_net_raw=ep
```

`mtr` jest zainstalowany i **działa jako użytkownik `divezone` bez roota**, bo `mtr-packet`
ma `cap_net_raw`. Powód pomyłki z lipca: `/usr/sbin` nie jest w PATH tego użytkownika, więc
`command -v mtr` zwracało pusto. **Zawsze wołaj pełną ścieżką `/usr/sbin/mtr`.**

Co więcej, mtr daje więcej niż nasz traceroute: dochodzi do **hopa 11, czyli do samego
66.33.22.230**, którego traceroute nigdy nie pokazywał (Railway filtruje ICMP TTL-exceeded,
ale odpowiada na echo i na TCP). Zweryfikowane wyjście z 2026-09-09 14:42, stan zdrowy:

```
  1.|-- 193.93.88.254    0.0%   10    0.1 ms
  2.|-- 83.2.59.124      0.0%   10    4.6 ms      Orange Polska
  3.|-- 195.149.239.58   0.0%   10   10.0 ms      Orange Polska
  4.|-- 195.149.239.57   0.0%   10    9.5 ms      Orange Polska
  5.|-- 62.115.153.38    0.0%   10   29.6 ms      Arelion, wejscie w AS1299
  6.|-- 62.115.114.182   0.0%   10   41.6 ms
  7.|-- 62.115.138.22    0.0%   10   41.5 ms
  8.|-- 62.115.137.222   0.0%   10   46.5 ms
  9.|-- 62.115.137.191   0.0%   10   32.9 ms
 10.|-- 62.115.196.223   0.0%   10   33.0 ms      styk Arelion / Railway
 11.|-- 66.33.22.230     0.0%   10   32.9 ms      CEL — traceroute tu nie dochodzil
```

Wariant TCP (`-T -P 14368`) też działa i mierzy tę samą ścieżkę na porcie, który realnie pada.

---

## ZAKRES

### 1. mtr w `captureNetworkDiag()` (`railway_monitor.php`)

Dołóż do zrzutu epizodu, obok istniejących pingów i traceroute, dwa przebiegi mtr.
Oba przez `/usr/sbin/mtr` (pełna ścieżka), oba owinięte w `timeout`, oba w tle jak reszta:

```
timeout 90 /usr/sbin/mtr --report --report-wide --report-cycles 20 -n 66.33.22.230
timeout 90 /usr/sbin/mtr --report --report-wide --report-cycles 20 -n -T -P 14368 66.33.22.230
```

Etykiety w zrzucie po polsku, w konwencji istniejących sekcji, np.
`=== mtr ICMP 66.33.22.230 (strata per hop) ===`.

**Budżet czasu.** Zrzut już teraz trwa do ~90 s (4 pingi + traceroute), a guard uznaje monitor
za martwy po 60 s ciszy w logu — chroni nas to, że zrzut leci w tle (`nohup ... &`).
Dwa mtr po 20 cykli to kolejne ~40-50 s każdy. **Sprawdź empirycznie łączny czas zrzutu
po zmianie** i wpisz pomiar do raportu. Jeśli wyjdzie ponad ~4 minuty, zejdź do 10 cykli
i to odnotuj — lepiej krótszy mtr niż zrzut, który nie zdąży się domknąć przed kolejnym
epizodem.

Zachowaj `flock` na pliku incydentu — mtr ma iść pod tą samą blokadą co reszta.

### 2. Dobowy mtr odniesienia (kontrola stanu zdrowego)

Epizody są teraz rzadkie (04-09.09: 3 alerty przez 6 dób). Smarthost dostanie mtr z awarii,
ale bez porównania ze stanem zdrowym z tej samej godziny nie ma czego z czym zestawić.

Raz na dobę, o **18:30 UTC** (środek okna ryzyka), zrób ten sam zestaw dwóch mtr i dopisz
do `~/_diag/mtr_baseline_YYYYMMDD.txt`. Bez maila, bez alertu. Ma być tanie i ciche.

### 3. Automatyczne powiadomienie smarthosta — DOMYŚLNIE WYŁĄCZONE

Smarthost prosi o maila przy stracie. Sam alert epizodowy idzie dziś do Karola przy
**starcie** epizodu, po 3 FAIL z rzędu.

**Do smarthosta wysyłamy JEDEN mail na epizod, przy RECOVERY, nie przy starcie.** Powód:
02.09 wysłalibyśmy 21 maili w półtorej godziny, co u helpdesku kończy się filtrem. Przy
recovery zrzut zawiera już oba mtr, okno niedostępności i pełne pingi.

- adres celu w `.env` jako `SMARTHOST_TICKET_MAIL`; **brak zmiennej = funkcja nieaktywna**
  i to jest stan domyślny po wdrożeniu
- temat musi wpiąć się w wątek zgłoszenia, np.
  `[DIVEZONE #167585] Utrata pakietow serwer->Railway, okno HH:MM-HH:MM UTC`
- treść: okno niedostępności, tabela strat do czterech celów, oba mtr, jedno zdanie po polsku
  co to znaczy. Bez załączników binarnych, wszystko inline
- kopia zawsze do Karola
- twardy limit: **maksymalnie 3 maile do smarthosta na dobę**, licznik w pliku
  `~/_diag/smarthost_mail_YYYYMMDD.count`. Po przekroczeniu tylko wpis w logu
- każdy taki mail odnotuj w logu głównym linią `### MAIL-SMARTHOST ... | mail=sent|FAILED`,
  żeby raport dobowy mógł to policzyć

### 4. Poprawka stałego faktu w dokumentacji

- w `CHAT-T-119...md:21` dopisz notę: fakt o braku `mtr` jest nieaktualny, korekta CHAT-T-185,
  z datą i dowodem (`getcap`). Nie kasuj oryginalnej linii, dopisz notę pod nią
- w `_docs/44_slownik_pol_i_metryk.md`, sekcja PUŁAPKI, dopisz jedną linijkę: `mtr` jest
  pod `/usr/sbin/mtr`, poza PATH użytkownika, działa dzięki `cap_net_raw` na `mtr-packet`,
  `command -v mtr` kłamie. Z dowodem i datą

### 5. Poza zakresem

mtr od strony Railway to **CHAT-T-184 Faza B**. Nie dubluj, nie ruszaj `probe.py`.
Jeżeli przy okazji zauważysz, że `probe.py` dałoby się wzbogacić o mtr — zgłoś w raporcie,
nie implementuj.

---

## TESTY AKCEPTACYJNE

1. `ea-php84 -l` na `railway_monitor.php`.
2. **Wymuś zrzut na żywo** bez czekania na epizod: uruchom `captureNetworkDiag` w izolacji
   (mały skrypt testowy w `/tmp`, nie w `~/_diag/`) i pokaż pełny plik wynikowy z obiema
   sekcjami mtr. Zmierz i podaj **łączny czas trwania zrzutu**.
3. Baseline: wywołaj ścieżkę dobową ręcznie, pokaż `mtr_baseline_<data>.txt`.
4. Powiadomienie smarthosta: przetestuj **bez** `SMARTHOST_TICKET_MAIL` (ma być no-op,
   z wpisem w logu) oraz z adresem ustawionym na skrzynkę Karola, nie na smarthosta.
   **Pod żadnym pozorem nie wysyłaj testowego maila do smarthosta.**
5. Limit dobowy: pokaż, że czwarta próba w dobie nie wysyła i zostawia wpis w logu.
6. Monitor po restarcie chodzi: guard.log, nowy nagłówek, rosnący log, `monitor_nohup.out`
   bez nowych fatali wobec baseline zdjętego przed wdrożeniem.

## RECENZJA KRZYŻOWA
`/codex` na diffie. Szczególnie: czy zrzut w tle nie urośnie tak, że nachodzi na kolejny
epizod, i czy licznik maili jest odporny na równoległe wywołania. Niezgody NIEROZSTRZYGNIĘTE.

## WDROŻENIE
Jeden plik: `/home/divezone/_diag/railway_monitor.php`. Backup `.bak_YYYYMMDD`.
**STOP przed rsync, czekaj na „deployuj" (ADR-089).** Po wdrożeniu restart monitora
(`pkill -9 -f "railway_monitor[.]php"`, guard wskrzesi w ~60 s) i cztery dowody restartu
jak w CHAT-T-183. Stan przed zmianą: md5 `f88ef04234b622bdc94275928fa207a6`.

## GIT
`git add` per ścieżka, nigdy `git add .`. Commit `feat(CHAT-T-185): ...`, osobny
`docs(CHAT-T-185): ...` na poprawki dokumentacji i status. `git push origin main`.

## NIE RUSZAĆ
`railway_summary_mail.php`, `railway_monitor_guard.sh`, crontab, logi i zrzuty w `~/_diag/`,
`probe.py` z CHAT-T-184, produkcyjna baza Railway, `_ops/newtmp2_root/purge_litespeed.php`,
`standalone/config/routes.php`, `standalone/config/tools.php`, pliki ADR.

## RAPORT KOŃCOWY
Pełny plik zrzutu testowego z obiema sekcjami mtr, zmierzony czas zrzutu, plik baseline,
wynik obu wariantów testu powiadomienia, dowód limitu dobowego, cztery dowody restartu,
wynik `/codex`, md5 przed i po.
