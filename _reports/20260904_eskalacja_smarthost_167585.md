# Zgłoszenie #167585 — trasa do 66.33.22.230, stan po poprawce z 06.07.2026

**Do:** Smarthost, dział techniczny (do rąk p. Alberta Kruka)
**Serwer:** divezonededyk.smarthost.pl, 193.93.88.95
**Cel:** 66.33.22.230:14368 (switchback.proxy.rlwy.net, Railway, EU West / Amsterdam)
**Data:** 2026-09-04

---

Dzień dobry,

wracam do zgłoszenia #167585. Poprawka w routingu z 06.07.2026 **zadziałała** — mam na to
pomiar. Ale efekt zaczął się cofać w sierpniu i wczoraj wróciliśmy do stanu sprzed lipca.
Poniżej liczby, nie wrażenia.

Monitorujemy tę trasę w sposób ciągły od 28.06.2026: sonda co ~6 sekund, około 15 200 próbek
na dobę, TCP connect na 14368 plus cztery realne zapytania do bazy, plus kontrolne połączenie
do `api.github.com:443`. Przy każdym epizodzie automat zrzuca ping do czterech celów
i traceroute. Wszystkie liczby niżej pochodzą z tych logów.

## 1. Poprawka z 06.07 dała mierzalny efekt

Timeouty połączenia TCP na 1000 próbek:

| okres | FAIL / 1000 | alerty na dobę |
|---|---|---|
| 28.06 - 06.07 13:26 (przed poprawką) | 8,32 | 8,1 |
| 06.07 - 14.07 | 0,38 | 0,6 |
| 15.07 - 31.07 | 0,40 | 0,8 |
| sierpień | 2,39 | 1,6 |
| 01-03.09 | 2,19 | — |

Widać też skok jakościowy, ale **dziewięć dni po Waszej zmianie, nie tego samego dnia**.
Mediana czasu zapytania `SELECT 1` (połączenie plus zapytanie), doba po dobie:

```
29.06 - 14.07:  311-314 ms   (płasko)
15.07:          267 ms       <- skok, spadek o 46 ms
15.07 - 26.07:  266-268 ms
sierpień:       267 -> 284 ms (powolny wzrost)
01-04.09:       282 ms, dziś rano 267 ms
```

Nie wiem, czy to Wasza zmiana weszła z opóźnieniem, czy przestawiło się coś wyżej.
Odnotowuję fakt: 15.07 trasa się zmieniła i było lepiej.

## 2. Od sierpnia efekt się zużył

Sierpień jest **sześć razy gorszy niż lipiec po poprawce** (2,39 wobec 0,40 FAIL/1000).
Najgorsza doba w całej historii pomiarów to **02.08** (62,4 FAIL/1000) — 27 dni po poprawce.
Ostatni duży epizod: **02.09, 15:04-16:47 UTC** (17:04-18:47 czasu polskiego), 1 godz. 42 min,
84 timeouty połączenia z `errno=110` (ETIMEDOUT), 21 alertów mailowych.

Epizody mają wyraźny rytm dobowy. Rozkład timeoutów według godziny UTC, sierpień i wrzesień:

```
00-07 UTC:    8
08-15 UTC:  218
16 UTC:     154
17 UTC:     150
18 UTC:     198   <- szczyt
19 UTC:     148
20 UTC:     146
21 UTC:     138
22-23 UTC:   40
                       razem 1200
```

W przedziale 16-21 UTC (18-23 czasu polskiego) mieści się **934 z 1200 timeoutów, czyli 78%**.
Noce są praktycznie czyste: 8 zdarzeń na 1200 w godzinach 00-07 UTC. To wygląda na wysycenie
w godzinach szczytu, nie na awarię sprzętu.

## 3. Strata dotyczy WYŁĄCZNIE trasy do Railway

To jest najważniejsza liczba w tym piśmie. Przy każdym epizodzie automat pinguje cztery cele
w tej samej sekundzie. Epizod 02.09, **21 zrzutów, bez ani jednego wyjątku**:

| cel | straty pakietów |
|---|---|
| 66.33.22.230 (Railway) | 33,3% - 100% |
| 5.79.108.33 (Leaseweb Amsterdam, inna trasa) | **0%** we wszystkich 21 |
| 1.1.1.1 (Cloudflare) | **0%** we wszystkich 21 |
| 8.8.8.8 (Google) | **0%** we wszystkich 21 |

Rozkład: 8 zrzutów po 100% strat do Railway, 4 po 86,7%, 4 po 80%, 3 po 93,3%, 1 po 53,3%,
1 po 33,3%. Kontrole przez cały czas zero.

**Nie twierdzę, że to awaria Waszego łącza.** Łącze wyjściowe serwera w tych samych sekundach
działało bez zarzutu. Traci konkretna ścieżka do jednego celu.

## 4. Gdzie giną pakiety

Traceroute z epizodu 02.09 (i identyczny na żywo 03.09 oraz w zrzutach z 03.07 i 02.08 —
trasa nie zmieniła się przez cały ten czas):

```
 1  193.93.88.254   server-ba254.rev.smarthost.pl
 2  83.2.59.124     (Orange Polska)
 3  195.149.239.58
 4  195.149.239.57
 5  62.115.153.38   win-b2-link.ip.twelve99.net      (Arelion, Warszawa)
 6  62.115.114.182  win-bb2-link.ip.twelve99.net
 7  62.115.138.22   ffm-bb2-link.ip.twelve99.net     (Frankfurt)
 8  62.115.137.222  adm-bb2-link.ip.twelve99.net     (Amsterdam)
 9  62.115.137.189  adm-b12-link.ip.twelve99.net
10  62.115.196.223  railwaycorp-ic-390073.ip.twelve99-cust.net   <- styk Arelion / Railway
11+ brak odpowiedzi (Railway filtruje ICMP TTL także w dobach bez awarii)
```

W epizodzie 02.09 hop 10 odpowiadał w 6 z 21 zrzutów, przy 0% strat do wszystkich trzech
kontroli w tych samych sekundach. **Pakiety giną za tym hopem** — na styku Arelion z Railway
albo już wewnątrz Railway.

## 5. Co do trasowania asymetrycznego — ma Pan rację i to sprawdzamy

Zgadzam się z uwagą z 06.07: traceroute pokazuje wyłącznie ścieżkę w jedną stronę.
Dlatego dokładam pomiar, który obejmuje **obie** strony. RTT pingu to czas w obie strony
i na tym samym adresie IP ma kilka **stałych poziomów**, nie rozrzut (odchylenie wewnątrz
każdego poziomu 0,03-0,25 ms):

```
02-05.07:    poziom 23,0 ms i poziom 37,1 ms
od 16.07:    dochodzi poziom 31,5 ms
02.08:       22,8 / 31,3 / 60,9 / 65,2 / 71,4 ms — tego samego dnia
03-04.09:    32,8 ms
```

Jedno IP, kilka stałych poziomów opóźnienia w ciągu godzin. To albo ECMP, albo zmieniająca się
trasa powrotna. Ze swojej strony uruchamiamy pomiar z drugiego końca (traceroute z infrastruktury
Railway w kierunku 193.93.88.95) i wynik prześlemy do tego zgłoszenia.

## 6. Zastrzeżenie do naszych liczb: są zaniżone

Nasza sonda w trakcie epizodu sama się zawiesza na wiszących połączeniach i jest wskrzeszana
przez watchdoga z opóźnieniem do 60 sekund. 02.09 zdarzyło się to **39 razy** — to do około
39 minut czasu bez pomiaru w oknie 1 godz. 42 min. Podane wyżej 84 timeouty to dolna granica,
nie pełny obraz.

## 7. O co proszę

1. **Eskalację do Arelion (AS1299)** w sprawie ruchu do prefiksu Railway w oknie 16-21 UTC,
   z powołaniem na hop `62.115.196.223`. Chętnie dostarczymy komplet zrzutów: 21 plików
   z epizodu 02.09 i wcześniejsze z 03.07 oraz 02.08, każdy z pingami do czterech celów
   i traceroute z chwili zdarzenia.
2. **Odpowiedź na pytanie, czy zmiana z 06.07 była tymczasowa i wygasła.** W Waszej wiadomości
   pada słowo „tymczasowo". Nasze dane pokazują poprawę od 15.07 i jej stopniowy zanik od
   sierpnia. Jeżeli to była zmiana z terminem ważności — prosimy o wersję trwałą.
3. **Jeżeli to jest poza Waszym zasięgiem** — prosimy o jasną informację. Nie traktujemy tego
   jako uchylania się. Baza jest krytyczna dla czatu w sklepie, a my musimy wtedy podjąć własną
   decyzję o zmianie dostawcy bazy, i wolimy podjąć ją świadomie niż czekać.

Materiał dowodowy (logi ciągłe od 28.06, zrzuty epizodów, pomiary opóźnień doba po dobie)
mamy komplet i udostępnimy w dowolnej formie.

Z poważaniem,
Karol Susicki
DIVEZONE.PL sp. z o.o.

---

## Załącznik — skąd pochodzą liczby

| twierdzenie | źródło |
|---|---|
| FAIL/1000 w okresach | `~/_diag/railway_monitor_2026*.log`, linie pomiarowe `^#NNNNN`, pole `railway_tcp` |
| mediana `SELECT 1` doba po dobie | te same logi, pole `pg_select1`, tylko próbki OK |
| rozkład godzinowy | te same logi, sierpień i wrzesień, pole `railway_tcp FAIL` wg godziny UTC |
| straty pakietów, 21 zrzutów | `~/_diag/incident_20260902_*.txt`, sekcje `--- <IP> ping statistics ---` |
| traceroute | te same pliki, sekcja `=== traceroute Railway ===`, oraz pomiar na żywo 03.09 12:20 UTC |
| poziomy RTT | linie `rtt min/avg/max/mdev` we wszystkich plikach `incident_*.txt` od 02.07 |
| 39 zawieszeń sondy 02.09 | `~/_diag/guard.log`, wpisy `ZAMARCIE` z 2026-09-02 |
| epizod 02.09 | `railway_monitor_20260902.log`: 14331 próbek, 84 × `railway_tcp FAIL`, wszystkie `errno=110`, 21 × `### ALERT` |
