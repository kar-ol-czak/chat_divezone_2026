# Zgłoszenie #167585 — odpowiedź. Adresatem eskalacji nie jest Arelion, tylko Orange Polska

**Do:** Smarthost, dział techniczny
**Serwer:** divezonededyk.smarthost.pl, 193.93.88.95
**Data:** 2026-09-04

---

Dzień dobry,

dziękuję za szybką odpowiedź. Trzy sprawy, po kolei.

## 1. Mają Państwo rację co do Arelion. Ale eskalacja nie idzie do Arelion

Zgadzam się, że nie mają Państwo relacji handlowej z AS1299. Dlatego prosiłem
o niewłaściwą rzecz i to koryguję.

Sprawdziłem, do kogo należą kolejne hopy naszej trasy wyjściowej (whois RIPE, 2026-09-04):

| hop | adres | właściciel wg whois |
|---|---|---|
| 1 | 193.93.88.254 | **Smarthost sp. z o.o.** |
| 2 | 83.2.59.124 | netname TPIX, mnt-by TPNET / AS5617-MNT — **Orange Polska** |
| 3 | 195.149.239.58 | org-name **Orange Polska Spolka Akcyjna**, netname TPIX |
| 4 | 195.149.239.57 | org-name **Orange Polska Spolka Akcyjna**, netname TPIX |
| 5 | 62.115.153.38 | **Arelion Sweden AB**, AS1299 |

Ruch z naszego serwera do Railway trafia do Orange Polska **już na drugim hopie** i to
Orange Polska przekazuje go do Arelion na hopie piątym. Arelion nie jest naszym dostawcą
ani Państwa dostawcą. Jest siecią, do której Państwa dostawca oddaje nasz ruch.

**Właściwy adresat pierwszej eskalacji to Orange Polska**, nie Arelion. To jest sieć oddalona
o jeden hop od Państwa routera brzegowego, z którą relację ma Smarthost, nie DIVEZONE.
My do Orange Polska nie mamy jak się zgłosić — nie jesteśmy ich klientem.

Proszę zatem o przekazanie sprawy do Orange Polska: ruch z 193.93.88.0/22 do prefiksu
Railway 66.33.22.0/23 w oknie 16-21 UTC, z pytaniem, czy przekazanie tego ruchu do AS1299
jest optymalne i czy istnieje alternatywa. Jeżeli Orange odeśle nas do Arelion, pójdziemy
do Arelion i wtedy skorzystamy z Państwa deklaracji o pomocy w potwierdzeniu.

## 2. To nie jest problem aplikacji i mam na to dowód, którego aplikacja nie może wytworzyć

Sugestia, żeby pytać dostawcę aplikacji, nie da się pogodzić z pomiarem. Trzy fakty:

**Metryka, która pada, nie dotyka aplikacji.** `railway_tcp` to goły `connect()` na porcie
14368. Nie ma tam zapytania, sesji, ani żadnego kodu poza jądrem systemu. 84 razy w dobie
02.09 zwrócił `errno=110`, czyli ETIMEDOUT — pakiet SYN wyszedł i nie wrócił nic.

**Traci też ICMP, a echo nie przechodzi przez żadną aplikację.** W epizodzie 02.09 automat
21 razy pingował cztery cele w tej samej sekundzie. Wynik, bez ani jednego wyjątku:

```
66.33.22.230  (Railway)              33,3% - 100% strat
5.79.108.33   (Leaseweb Amsterdam)   0% we wszystkich 21
1.1.1.1       (Cloudflare)           0% we wszystkich 21
8.8.8.8       (Google)               0% we wszystkich 21
```

Żadna aplikacja nie potrafi zgubić pakietu ICMP echo do jednego adresu, zostawiając trzy inne
nietknięte w tej samej sekundzie.

**Dostawca aplikacji nie zgłasza awarii.** Status Railway dla regionu EU West / Amsterdam
za ten okres pokazuje 100% dostępności, bez incydentów (sprawdzone 2026-09-03).

Co do argumentu, że „host jest osiągalny, a komunikacja w określonych momentach działa
prawidłowo" — to jest opis problemu, nie kontrargument. Przerywana strata pakietów w oknie
szczytu wygląda dokładnie tak: przez większość doby czysto, wieczorem 33-100% strat przez
półtorej godziny. Gdyby host był trwale nieosiągalny, mielibyśmy do czynienia z awarią, a nie
z wysyceniem.

## 3. Dziękuję za potwierdzenie, że zmiana z lipca jest trwała

To jest użyteczna informacja i przyjmuję ją. Skoro Państwa zmiana działa nieprzerwanie
i nie ona odpowiada za poprawę, to znaczy, że **15.07 zmieniło się coś poza Państwa
infrastrukturą** — bo mediana czasu połączenia spadła tego dnia z 313 ms na 267 ms i utrzymała
się na tym poziomie. To dodatkowy argument, żeby zapytać Orange Polska: czy w połowie lipca
zmieniali coś w polityce przekazywania ruchu, i czy to samo cofnęło się w sierpniu, gdy
awaryjność wzrosła sześciokrotnie.

## Podsumowanie prośby

1. Przekazanie sprawy do **Orange Polska** (Państwa sieć sąsiednia, hopy 2-4 naszej trasy),
   z pytaniem o ruch 193.93.88.0/22 → 66.33.22.0/23 w oknie 16-21 UTC.
2. Jeżeli Orange skieruje nas dalej do Arelion — poproszę o obiecane potwierdzenie z Państwa
   strony, że pomiar pochodzi z serwera w Państwa sieci.

Komplet materiału dowodowego (logi ciągłe od 28.06, 21 zrzutów z epizodu 02.09 z pingami
do czterech celów i traceroute, pomiary opóźnień doba po dobie) przekażę w dowolnej formie.

Z poważaniem,
Karol Susicki
DIVEZONE.PL sp. z o.o.

---

## Notatka wewnętrzna, nie do wysyłki

**Co zweryfikowane samodzielnie:** przynależność hopów 1-5 (whois RIPE, 2026-09-04, komenda
`whois <ip>` na hopach z naszych traceroute'ów). Liczby z epizodu 02.09 i rozdzielność strat
21/21 — pomiar własny z `~/_diag/`. Status Railway — odczyt status.railway.com 2026-09-03.

**Czego NIE zweryfikowałem samodzielnie:** spisu ścieżek BGP z RIPE RIS (382 ścieżki dla
naszego prefiksu, 330 dla Railway, udział AS1299 5,5% wobec 38,2%) — to pomiar Claude Code
z CHAT-T-184, którego nie powtórzyłem. Dlatego świadomie **nie umieściłem tych liczb w piśmie**.
Argument o asymetrii stoi na whois hopów, który sprawdziłem sam, i jest wystarczający.

**Ryzyko:** smarthost może mieć rację, że to poza ich zasięgiem, a Orange i Arelion mogą nie
podjąć tematu klienta końcowego cudzego klienta. Wtedy zostaje decyzja architektoniczna:
przeniesienie bazy poza publiczny proxy TCP Railway albo zmiana dostawcy bazy. Warto zacząć
o tym myśleć równolegle, nie po kolejnych trzech tygodniach korespondencji.
