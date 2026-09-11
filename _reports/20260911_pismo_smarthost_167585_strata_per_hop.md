# Zgłoszenie #167585 — pismo z 11.09.2026: strata rozpisana na hopy

Wysłane przez Karola 11.09.2026 na hosting@smarthost.pl.

## Kontekst

Pierwszy epizod po wdrożeniu CHAT-T-186 (mtr w zrzutach) i CHAT-T-188 (bramka
treści przed wysyłką). Epizod 10.09.2026, 16:37:07–16:40:10 WAW (3 m 3 s),
w ramach szerszego okna 16:05–17:44 WAW z 37 wpisami ZAMARCIE w guard.log.

Monitor wygenerował 14 zrzutów incydentowych. Bramka T-188 przepuściła jeden:
`incident_20260910_163707.txt` (15 296 B wobec ~7 000 B pozostałych — jedyny
z kompletnymi tabelami mtr). Wysłany automatycznie 10.09 o 16:43 WAW,
`smarthost_mail_20260910.count` = 1, `smarthost_sent_20260910.list` zawiera
nazwę pliku. To rekord monitora o wysyłce, nie potwierdzenie doręczenia SMTP.

Pismo poniżej jest komentarzem do tego surowego zrzutu: bez niego tabela mtr
czyta się jako strata na hopie 5 (Arelion Warszawa), co skierowałoby sprawę
w złą stronę.

## Dowód

Źródło: `~/_diag/incident_20260910_163707.txt` na divezonededyk.smarthost.pl.

Pingi z sekcji epizod-start (te same sekundy, ten sam interfejs):

| cel | wynik |
|---|---|
| Railway 66.33.22.230 | 15 wysłanych, 0 odebranych, **100 % straty** |
| Leaseweb AMS 5.79.108.33 | 15 / 15, 0 % straty, 22,9 ms |
| Cloudflare 1.1.1.1 | 15 / 15, 0 % straty |
| Google 8.8.8.8 | 15 / 15, 0 % straty |

mtr ICMP, 16:38:58 WAW, 20 pakietów na hop (linie 105–119 zrzutu):

```
  1.|-- 193.93.88.254      0.0%   20    smarthost
  2.|-- 83.2.59.124        0.0%   20    Orange Polska, TPIX
  3.|-- 195.149.239.58     0.0%   20    Orange Polska
  4.|-- 195.149.239.57     0.0%   20    Orange Polska
  5.|-- 62.115.153.38     90.0%   20    Arelion, Warszawa
  6.|-- 62.115.114.182     0.0%   20
  7.|-- 62.115.138.22      0.0%   20    Frankfurt
  8.|-- 62.115.137.222     0.0%   20    Amsterdam
  9.|-- 62.115.137.191    95.0%   20
 10.|-- 62.115.196.223    95.0%   20    styk Arelion / Railway
 11.|-- 66.33.22.230      95.0%   20    cel
```

mtr TCP :14368, 16:39:23 WAW (linie 120–135): hopy 1–4 zero straty,
hop 5 83,3 %, hopy 6–8 zero, hop 9 66,7 %, hop 11 (cel) 85,7 % przy Last 3082 ms.

## Odczyt

Strata na hopie 5 przy zerowej stracie na hopach 6–8 **nie jest stratą trasy**:
strata, która nie propaguje się na kolejne hopy, to rate limit generowania
odpowiedzi ICMP na routerze. Stratą realną jest wyłącznie ta utrzymująca się
do celu — tu od hopa 9 (62.115.137.191, Amsterdam) do hopa 11.

**Odcinek Orange Polska (hopy 2–4) jest w trakcie epizodu czysty.**
Odcinek Arelion Warszawa → Frankfurt → Amsterdam (hopy 6–8) również.
Pakiety giną w Amsterdamie, między hopem 8 a 9, przed stykiem z Railway.

Doprecyzowanie wobec pisma z 04.09: napisałem tam „pakiety giną za hopem 10".
Pomiar per hop pokazuje początek straty o jeden hop wcześniej, między 8 a 9.
Kierunek eskalacji bez zmian — Orange Polska pozostaje jedyną siecią na trasie,
do której smarthost ma jak dotrzeć.

Trailer `/usr/sbin/mtr: Unexpected mtr-packet error` po tabeli TCP jest
artefaktem zamykania mtr-packet w trybie TCP (decyzja z CHAT-T-185:
nie filtrujemy, wyjaśniamy). Tabela nad tą linią jest kompletna.

## Treść wysłanego pisma

Temat: `[DIVEZONE #167585] Epizod 10.09.2026 16:37 WAW, strata rozpisana na hopy`

Prośba operacyjna: przekazanie sprawy do Orange Polska z pytaniem, czy
przekazywanie ruchu 193.93.88.0/22 → 66.33.22.0/23 do AS1299 jest optymalne
i czy istnieje alternatywna ścieżka. Zapowiedź, że monitor wysyła komplet
dowodów automatycznie przy każdym epizodzie, maks. 3 razy na dobę,
z ofertą zmiany adresu lub częstotliwości.

Pełna treść: patrz przebieg rozmowy architekta z 11.09.2026.
