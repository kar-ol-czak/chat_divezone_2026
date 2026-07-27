# Zgłoszenie do smarthost.pl — straty pakietów / timeouty połączeń wychodzących z serwera do hosta zewnętrznego

**Temat (do wpisania w tytuł zgłoszenia):**
Serwer divezonededyk.smarthost.pl (193.93.88.95) — ~80% strat pakietów i timeouty TCP do hosta zewnętrznego 66.33.22.230:14368 (z innej sieci ten sam host: 0% strat)

---

Dzień dobry,

na moim serwerze występują poważne, powtarzalne problemy z **połączeniami wychodzącymi (egress)** do zewnętrznego hosta — wysoki procent utraty pakietów i timeouty nawiązania połączenia TCP. Z innej sieci (zwykłe łącze, w tym samym czasie) ten sam host odpowiada bez zarzutu, więc problem nie leży po stronie hosta docelowego ani usługi zdalnej, lecz na ścieżce sieciowej z Państwa serwera.

## Dane identyfikacyjne

- **Serwer:** divezonededyk.smarthost.pl
- **IP publiczne serwera:** 193.93.88.95 (rev: server-ba254.rev.smarthost.pl)
- **Konto:** divezone
- **Host docelowy:** switchback.proxy.rlwy.net → **66.33.22.230**, **port TCP 14368**
  (to publiczny TCP-proxy bazy PostgreSQL usługi Railway; aplikacja na subdomenie chat.divezone.pl, PHP ea-php84, łączy się z tą bazą)
- **Data/godziny pomiarów:** 2026-06-29, ok. 18:58–21:01 CEST

## Objaw aplikacyjny

Aplikacja PHP (PDO/pgsql) z serwera regularnie zwraca błąd połączenia:
```
SQLSTATE[08006] [7] timeout expired
```
Żądania, które normalnie trwają < 1 s, trwają 30–120 s albo kończą się timeoutem. Skutkuje to błędami 500 w aplikacji.

## Dowody techniczne

### 1. ICMP ping — straty pakietów (Z SERWERA vs Z ZEWNĄTRZ, ten sam cel)

**Z serwera (193.93.88.95)** — dwie próby w odstępie kilku minut:
```
$ ping -c 20 switchback.proxy.rlwy.net   (66.33.22.230)
[próba 1] 20 packets transmitted,  4 received, 80% packet loss
[próba 2] 20 packets transmitted,  1 received, 95% packet loss
rtt (gdy dochodzi) = ~37 ms
```
→ **Powtarzalnie 80–95% utraty pakietów.** Pakiety, które docierają, mają prawidłowy czas (37 ms) — to wyklucza „daleki/wolny host", wskazuje na **gubienie pakietów na trasie**.

**Z zewnętrznej sieci (komputer, łącze w Polsce), ten sam IP, w tym samym czasie:**
```
$ ping -c 20 66.33.22.230
20 packets transmitted, 20 packets received, 0.0% packet loss
round-trip min/avg/max/stddev = 23.523/25.928/29.814/1.701 ms
```
→ **0% strat, ~26 ms.** Host docelowy jest w pełni sprawny i blisko — problem dotyczy wyłącznie ścieżki z Państwa serwera.

### 2. TCP connect na port 14368 — pomiar równoległy (te same sekundy)

Pomiar czasu nawiązania połączenia TCP (`fsockopen`), 12 prób co 3 s, równolegle z serwera i z komputera zewnętrznego:

```
KOMPUTER ZEWNĘTRZNY            SERWER (smarthost)
18:58:08  OK   35 ms           18:58:08  OK     38 ms
18:58:11  OK   37 ms           18:58:11  OK     37 ms
18:58:24  OK   29 ms           18:58:24  OK     38 ms
18:58:27  OK   38 ms           18:58:28  OK   1059 ms
18:58:30  OK   31 ms           18:58:32  OK   1061 ms
18:58:33  OK   36 ms           18:58:42  FAIL „Connection timed out" 7094 ms
                               18:58:46  OK   1088 ms
                               18:58:50  OK   1051 ms
                               18:59:00  FAIL „Connection timed out" 7094 ms
```
→ W tych samych momentach komputer zewnętrzny ma stabilne ~30 ms, a serwer skacze do 1 s i **timeoutuje (7 s)**.

### 3. Trasa z serwera (tracepath do 66.33.22.230)

```
 1:  server-ba254.rev.smarthost.pl              0.39 ms
 2:  83.2.59.124                                 5.34 ms
 3:  195.149.239.58                             28.72 ms
 4:  195.149.239.57                              9.78 ms
 5:  no reply
 6:  win-bb2-link.ip.twelve99.net               47.54 ms   (Telia/AS1299)
 7:  ffm-bb2-link.ip.twelve99.net               47.84 ms
 8:  adm-bb2-link.ip.twelve99.net               52.61 ms
 9:  adm-b12-link.ip.twelve99.net               37.24 ms
10:  railwaycorp-ic-390073.ip.twelve99-cust.net 37.15 ms   (przekazanie do sieci celu)
11–23: no reply
```
→ Ruch wychodzi przez Państwa sieć (83.2.59.124, 195.149.239.x) i dalej tranzytem Telia (twelve99) do sieci docelowej (hop 10, 37 ms). Gubienie pakietów występuje na tej ścieżce.

## Podsumowanie

Te same testy, ten sam cel, ta sama chwila: **z Państwa serwera ~80% strat i timeouty, z zewnątrz 0% strat i ~26 ms.** Host docelowy i usługa zdalna działają poprawnie. Problem jest na ścieżce/egressie z serwera 193.93.88.95.

## Prośba

Proszę o sprawdzenie po Państwa stronie:

1. Czy na łączu/egressie serwera **193.93.88.95** nie działa rate-limiting / policing / QoS / shaping gubiący ruch wychodzący do **66.33.22.230** (lub do sieci AS Telia/twelve99 / Railway)?
2. Czy mechanizmy ochrony (firewall, conntrack, anty-DDoS, limit liczby/tempa połączeń wychodzących) nie ograniczają połączeń TCP z tego serwera do tego hosta/portu (14368)?
3. Czy nie występuje problem z peeringiem/trasą smarthost → Telia (AS1299) → sieć docelowa, powodujący ~80% utraty pakietów?
4. Czy problem leży po Państwa stronie, czy u operatora tranzytowego — i czy w tym drugim przypadku mogą Państwo zgłosić to dalej?

Chętnie wykonam dodatkowe testy z serwera, jeśli będą Państwo potrzebować (mtr/traceroute z odpowiednimi uprawnieniami, pomiary w wskazanym oknie czasowym, itp.). Proszę o informację, czego potrzebują Państwo do diagnozy.

Z góry dziękuję za pomoc.

Pozdrawiam,
Karol
