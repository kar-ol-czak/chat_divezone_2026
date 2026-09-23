═══ CHAT-T-193 · INTEGRATION · tunel SSH do mydevil, pomiar i stabilność ═══

# CHAT-T-193 — tunel SSH serwer sklepu → mydevil: zmierzyć i obserwować

Autor zlecenia: architekt. Data: 2026-09-23.
Poprzednik: CHAT-T-192 (PRZESZŁO Z ZASTRZEŻENIAMI).
Charakter: **pomiar i obserwacja**. Aplikacji nie przepinamy, `.env` nie ruszamy.

## 1. Co się zmieniło od T-192

T-192 ustaliło, że PostgreSQL na mydevil jest nieosiągalny z zewnątrz
(`no pg_hba.conf entry for host "193.93.88.95"`, brak SSL) i dlatego czwarta
para liczb z KROKU 5 nie powstała.

Zapytaliśmy dostawcę. Odpowiedź z 2026-09-23: wpisu w `pg_hba` nie będzie,
ale **stały tunel SSH jest dozwolony wprost** — cytat: „Można pozostawić tunel ssh".

Metoda z ich dokumentacji (pomoc.mydevil.net/PostgreSQL, sekcja dostęp zdalny),
dosłownie:

```
ssh -f LOGIN@sX.mydevil.net -L 8543:pgsqlX.mydevil.net:5432 -N
psql -h localhost -p 8543 -U POSTGRESQL_USER -W
```

**Uwaga na topologię.** Baza NIE stoi na `s81`. `s81.mydevil.net` to host, przez
który przechodzi tunel; dane są na `pgsql81.mydevil.net` (alias `db81.mydevil.net`),
osobnej maszynie. Tunel jest dwuczłonowy: serwer sklepu → s81 → pgsql81.

## 2. Co to zadanie ma rozstrzygnąć

Dwie liczby i jedną obserwację:

1. **Czas zapytania przez tunel** — brakująca czwarta para z T-192 §5.
2. **Narzut samego tunelu** — różnica wobec 10 ms czystego TCP do `pgsql81`
   zmierzonych w T-192.
3. **Czy sesja SSH na współdzielonym hostingu przeżywa 48 h** — i jeśli nie,
   to po ilu godzinach ginie i w jaki sposób.

Punkt 3 jest ważniejszy od pozostałych. Jeżeli mydevil zrywa długie sesje
w sposób, którego nie da się wyprzedzić, cała konstrukcja odpada niezależnie
od tego, jak dobre wyjdą liczby.

## 3. Zgody i granice

Karol autoryzował założenie klucza SSH z serwera sklepu na konto mydevil
(pytanie 22, wariant a). To jedyna nowa ścieżka zaufania w tym zadaniu.

- Klucz **wyłącznie** dla tego zastosowania, osobna para, nie współdziel
  z żadnym istniejącym kluczem.
- W `authorized_keys` na mydevil ogranicz go: `no-agent-forwarding`,
  `no-X11-forwarding`, `no-pty`. Przekazywanie portu ma działać, reszta nie.
- Klucz bez hasła, bo ma wstawać z crona. To świadomy kompromis; ograniczenia
  wyżej są jego przeciwwagą.
- Nie dotykaj `.env` czatu ani `config/`. Aplikacja w tym zadaniu nie wie
  o istnieniu tunelu.

## 4. Do zbudowania

### 4.1. Tunel

Port lokalny na serwerze sklepu: **8543** (jak w dokumentacji dostawcy).
Nasłuch **wyłącznie na `127.0.0.1`**, nigdy na `0.0.0.0` — inaczej otwierasz
bazę mydevil na świat przez nasz serwer.

Opcje obowiązkowe: `ExitOnForwardFailure=yes` (bez tego ssh wstaje i udaje,
że działa, gdy port jest zajęty), `ServerAliveInterval` i `ServerAliveCountMax`
dobrane tak, żeby zerwanie było wykrywane w kilkadziesiąt sekund, nie w kilka minut.
Wartości dobierz i **uzasadnij w raporcie**, nie kopiuj z internetu bez wyjaśnienia.

### 4.2. Watchdog

Wzoruj się na `_docs/scripts/railway_monitor_guard.sh`, który jest sprawdzony
w produkcji. Ta sama logika: cron co minutę, wskrzeszenie gdy proces padł,
wykrycie ZAMARCIA po objawie, nie po samym istnieniu procesu.

Kryterium zdrowia tunelu to **nie** „proces ssh żyje", tylko „przez port 8543
da się nawiązać połączenie TCP". Proces ssh potrafi żyć z martwym przekierowaniem,
dokładnie jak monitor Railway potrafił żyć z martwym logiem.

Log własny, osobny plik, format jak w `guard.log`: znacznik czasu, przyczyna,
pid. Bez tego nie policzymy punktu 3 z §2.

### 4.3. Rotacja — parametr, DOMYŚLNIE WYŁĄCZONY

Przewiduj w skrypcie opcjonalny maksymalny czas życia tunelu, po którym watchdog
sam go zamyka i stawia od nowa. **Domyślnie wyłączony.**

Uzasadnienie, żeby nie było wątpliwości co do intencji: rotacja wyprzedza tylko
jeden tryb awarii, czyli ubicie sesji po czasie. Nie chroni przed restartem hosta
ani zanikiem sieci, więc nie zastępuje watchdoga. Nie wiemy też, czy mydevil
w ogóle zrywa długie sesje — ich odpowiedź sugeruje, że nie. Parametr ma istnieć
i być przetestowany, ale wartość ustalimy po obserwacji z §5, nie przed.

Koszt rotacji jest policzony i akceptowalny: czat trzyma połączenia trwałe
(`PostgresConnection.php:156`, `PDO::ATTR_PERSISTENT => true`, ADR-117), więc
zamknięcie tunelu zrywa uchwyty workerów. Te zerwania klasyfikują się jako SOFT
i T-191 obsługuje je rekonektem po backoffie 100 ms. Jedna nieudana próba
na worker, raz na rotację.

## 5. Pomiary

### 5.1. Liczby, po zbudowaniu tunelu

Wszystko **z serwera sklepu**, tą samą metodą co w T-192, żeby liczby dało się
zestawić. Po 30 powtórzeń każdego, podaj p50 i p95:

| pomiar | przez |
|---|---|
| `SELECT 1` | tunel `127.0.0.1:8543` |
| zapytanie wektorowe, `LIMIT 10` | tunel `127.0.0.1:8543` |
| `SELECT 1` | Railway, `switchback.proxy.rlwy.net:14368` |
| zapytanie wektorowe, `LIMIT 10` | Railway |

Dwie ostatnie pary powtórz teraz, nie przepisuj z T-192 — minął tydzień i od
tamtego czasu wdrożone jest T-191.

Baza po stronie mydevil: `p1137_divechat_t`, ta z T-192, dane w niej zostały.

### 5.2. Obserwacja 48 h

Tunel ma stać 48 godzin z watchdogiem i **wyłączoną rotacją**. To jest test,
czy dostawca zrywa sesje.

Raportuj z logu watchdoga:
- liczba zerwań i moment każdego, w godzinach od startu
- czy zerwania układają się w regularny wzorzec (to by znaczyło limit czasu),
  czy są rozrzucone (to by znaczyło niestabilność)
- czas od zerwania do odtworzenia tunelu przez watchdog
- czy `ExitOnForwardFailure` kiedykolwiek zadziałało

Co 5 minut, niezależnie od watchdoga, zapisuj do osobnego pliku wynik
`SELECT 1` przez tunel z czasem. To da ciągłość pomiaru dostępności porównywalną
z tym, co mamy dla Railway.

## 6. Dowody wymagane w raporcie

1. Polecenie budujące tunel, dosłownie, z uzasadnieniem każdej opcji.
2. `ss -ltnp` albo `netstat -ltnp` na serwerze sklepu pokazujące nasłuch
   **na 127.0.0.1**, nie na 0.0.0.0.
3. Zawartość wpisu w `authorized_keys` na mydevil, bez samego klucza,
   z widocznymi ograniczeniami.
4. Cztery pary liczb z §5.1.
5. Pełne zestawienie z §5.2 po 48 h.
6. Dowód, że watchdog naprawdę działa: ubij tunel ręcznie, pokaż wpis w logu
   i czas do odtworzenia.
7. Dowód, że rotacja działa, na jednym przebiegu z ustawionym krótkim czasem
   (na przykład 5 minut), po czym **przywróć wyłączenie**.

## 7. Czego NIE robić

- Nie przepinaj czatu na tunel. To osobna decyzja, po tym zadaniu.
- Nie ruszaj `.env`, `config/tools.php`, `config/routes.php`.
- Nie otwieraj nasłuchu na `0.0.0.0`.
- Nie używaj istniejącego klucza SSH, twórz nowy.
- Nie kasuj bazy `p1137_divechat_t` ani danych w niej.
- Standardowo: `_ops/newtmp2_root/purge_litespeed.php`, ADR-y, `newtmp2`.

## 8. Kryterium wyniku

- **TUNEL STABILNY** — 48 h bez zerwań albo zerwania rzadkie i odtwarzane przez
  watchdog w czasie porównywalnym z dzisiejszymi epizodami Railway.
- **TUNEL WYMAGA ROTACJI** — zerwania regularne, z widocznym limitem czasu.
  Podaj zmierzony limit i zaproponuj wartość rotacji z zapasem.
- **TUNEL NIESTABILNY** — zerwania częste i nieregularne. Wtedy zamykamy temat
  mydevil i zostajemy na Railway z łatą z T-191.

Werdykt wprost w raporcie. STOP po raporcie.

═══ CHAT-T-193 · INTEGRATION · tunel SSH do mydevil, pomiar i stabilność ═══
