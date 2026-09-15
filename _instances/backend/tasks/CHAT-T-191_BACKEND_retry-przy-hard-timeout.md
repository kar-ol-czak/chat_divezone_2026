═══ CHAT-T-191 · BACKEND · retry przy HARD-timeout ═══

# CHAT-T-191 — PostgresConnection: retry przy nieosiągalnej trasie, nie tylko przy zerwaniu

Autor zlecenia: architekt. Data: 2026-09-14.
Świat wdrożeniowy: **backend czatu, chat.divezone.pl** (na serwerze BEZ prefiksu
`standalone/`). To NIE jest moduł PS, do `newtmp2` nic nie idzie.

## 1. Przesłanka, zmierzona

Monitor trasy serwer→Railway, okno 2026-08-15 .. 2026-09-14 (30,8 doby,
465 841 próbek co 5 s):

- 149 serii niedostępności, łącznie 6 633 s = 110,5 min = **0,249 % czasu**
- mediana serii 19 s, p75 46 s, p90 115 s, maksimum 345 s
- 69 % całego czasu niedostępności przypada na godziny 16, 17 i 18 WAW

Ile zapytań uratowałby budżet retry o długości B, liczone **ważone ekspozycją**
(zapytania trafiają w awarię proporcjonalnie do jej długości, nie do liczby serii):

| B | uratowane |
|---|---|
| 10 s | 20,0 % |
| 15 s | 27,1 % |
| 20 s | 33,1 % |
| 30 s | 42,0 % |

Przyjmujemy **B ≈ 10 s**. Wyżej nie idziemy, bo to czat i człowiek czeka na
odpowiedź; 20 % za 10 s opóźnienia w najgorszym razie jest opłacalne, 42 % za
30 s już nie.

## 2. Problem w kodzie

`standalone/src/Database/PostgresConnection.php`, linie 142-145:

```php
// HARD = host nieosiagalny → retry nie pomoze, przerwij po 1. probie.
if ($kind === self::KIND_HARD) {
    break;
}
```

Założenie „retry nie pomoże" jest prawdziwe dla leżącego hosta i **fałszywe dla
trasy z 85-95 % straty pakietów**, gdzie host żyje, a pakiety w większości nie
docierają. Pomiar z §1 to rozstrzyga: połowa serii kończy się w 19 s.

Dodatkowo lista `classifyError()` (linie 178-192) wrzuca do jednego worka
przypadki zupełnie różne:

- **przejściowe**, warte ponowienia: `could not connect`, `connection timed out`,
  `timeout expired`, `no route to host`, `network is unreachable`
- **trwałe**, gdzie ponawianie to marnowanie sekund klienta: `connection refused`
  (host żyje, port zamknięty), `could not translate host`,
  `name or service not known` (DNS)

## 3. Zakres

Jeden plik: `standalone/src/Database/PostgresConnection.php` (270 linii).
Nic więcej. NIE ruszaj `ChatController`, `ConversationStore`, `DbUnavailableException`.

**Flaga publiczna SOFT/HARD w `DbUnavailableException` zostaje bez zmian**, żeby
komunikaty dla klienta (`DB_SOFT_MESSAGE` / `DB_HARD_MESSAGE` w `ChatController`
linie 110 i 112) i reason-key na froncie działały tak jak dziś. Rozróżnienie
wprowadzasz WEWNĄTRZ klasy, jako trzecią kategorię wewnętrzną.

## 4. Do zrobienia

1. Rozdziel klasyfikację na trzy kategorie wewnętrzne: `SOFT`, `HARD_RETRY`
   (przejściowe z §2) i `HARD_FINAL` (trwałe z §2). Na zewnątrz obie HARD-owe
   mapują się na dotychczasowy `KIND_HARD`.
2. Pętla `executeWithRetry()`:
   - `SOFT` — bez zmian, 3 próby, backoff 100/300 ms
   - `HARD_RETRY` — do 3 prób, backoff **1000/3000 ms**
   - `HARD_FINAL` — przerwij po pierwszej próbie, jak dziś
3. `connect_timeout=2` w `buildDsn()` (linia 255) **zostaje**. Budżet najgorszego
   przypadku: 2 + 1 + 2 + 3 + 2 = **10 s**. Wylicz to w docblocku, nie w głowie.
4. Bezpiecznik jednorazowości: pole `$this->unavailable` (linia 120) ma nadal
   powodować fail-fast przy kolejnych operacjach w tym samym żądaniu. To ono
   pilnuje, żeby budżet 10 s wydał się RAZ na żądanie, a nie przy każdym
   zapytaniu do bazy. **Zweryfikuj to testem**, nie założeniem — jedno żądanie
   czatu robi kilkanaście zapytań do PG.
5. Mierzalność: przy sukcesie po retry typu `HARD_RETRY` ustaw istniejące pole
   `$this->delayed` (linia 132) oraz dopisz do `error_log` liczbę prób i łączny
   czas. Bez tego nie sprawdzimy po miesiącu, czy prognoza 20 % się potwierdziła.
6. Zaktualizuj docblock klasy (linie 18-23) — dziś opisuje stan sprzed zmiany
   i po wdrożeniu zacząłby kłamać.

## 5. Kroki

0. `git pull --rebase`. Przeczytaj cały `PostgresConnection.php` oraz
   `_docs/44_slownik_pol_i_metryk.md`, sekcja PUŁAPKI.
1. Implementacja wg §4. `ea-php84 -l`.
2. Test jednostkowy albo skrypt dowodowy pokazujący trzy ścieżki:
   `HARD_RETRY` ponawia i mierzy czas, `HARD_FINAL` przerywa po pierwszej próbie,
   `SOFT` bez zmian. Dowodem ma być zmierzony czas, nie sam przebieg kodu.
3. Test budżetu z §4.4: symuluj żądanie wykonujące 10 operacji DB przy
   niedostępnej bazie i pokaż, że łączny czas to około 10 s, nie 100 s.
4. **Recenzja krzyżowa `/codex`** na gotowym diffie. Interesuje mnie przede
   wszystkim, czy podział needli w `classifyError()` jest kompletny i czy pętla
   nie ma ścieżki, w której budżet wydaje się wielokrotnie. Wynik recenzji
   i ewentualne niezgody wracają do mnie NIEROZSTRZYGNIĘTE.
5. Commit wg konwencji, `git add` per ścieżka, push.
6. **STOP (ADR-089).** Czekaj na „deployuj".
7. Po autoryzacji: backup, rsync **jednego pliku** na `chat.divezone.pl`
   (`~/public_html/chat.divezone.pl/src/Database/PostgresConnection.php`),
   md5 local↔prod, `ea-php84 -l` na produkcji, smoke na realnym endpoincie czatu.

## 6. Dowody wymagane w raporcie

1. md5 przed i po, lokalny i produkcyjny.
2. Zmierzony czas trzech ścieżek z §5.2, w milisekundach.
3. Wynik testu budżetu z §5.3.
4. Pełny wynik `/codex` wraz z Twoimi niezgodami, bez wygładzania.
5. Smoke: realne zapytanie do czatu po wdrożeniu kończy się odpowiedzią,
   nie komunikatem degradacji.
6. `ea-php84 -l` na pliku produkcyjnym.

## 7. Czego NIE ruszać

- `ChatController.php`, `ConversationStore.php`, `DbUnavailableException.php`
- `config/tools.php` (rozjazd R-5, wypchnięcie = fatal 500)
- `config/routes.php` (niezacommitowana zmiana innej sesji)
- `_ops/newtmp2_root/purge_litespeed.php` (SEKRET)
- ADR-y (pisze architekt)
- cokolwiek w `newtmp2` — to inny świat wdrożeniowy

## 8. Czego to zadanie NIE naprawia

Retry ratuje 20 % zapytań trafiających w awarię. Pozostałe 80 % nadal zobaczy
komunikat degradacji, bo pierwszy twardy blokada leży gdzie indziej — patrz
audyt w §9. To świadoma łata, nie rozwiązanie.

## 9. Audyt ścieżki krytycznej (kontekst, nie zakres zadania)

Kolejność zależności od Railway w pojedynczym żądaniu czatu:

| krok | plik, linia | zachowanie przy awarii |
|---|---|---|
| RateLimiter | `Usage/RateLimiter.php:70` | łapie `\Throwable`, nie blokuje |
| CostGuard | `Usage/CostGuard.php:68` | łapie `\Throwable`, nie blokuje |
| **ConversationStore::startOrResume** | `Chat/ChatService.php:85` | **BRAK fallbacku, rzuca wyżej** |
| **appendMessage(user)** | `Chat/ChatService.php:141` | **BRAK fallbacku** |
| SettingsStore::getAll | `Chat/ChatService.php:438` | degraduje przez cache plikowy |
| ChipTreeService | `Chip/ChipTreeService.php:59` | degraduje |
| ProductSearch, ExpertKnowledge | narzędzia | **nigdy nie osiągane przy awarii** |

Wniosek: pierwszym blokerem jest tożsamość i historia rozmowy, nie wyszukiwanie
semantyczne. Lokalna kopia wektorów nie pomogłaby, bo żądanie umiera zanim
do niej dojdzie. To materiał na osobne zadanie, nie na to.

## 10. KOREKTA ARCHITEKTA — po raporcie KROK 5 (2026-09-15)

### 10.1. Premisa §4.3 była fałszywa. Moja wina

Napisałem: „`connect_timeout=2` w `buildDsn()` zostaje. Budżet najgorszego
przypadku: 2 + 1 + 2 + 3 + 2 = 10 s." Odczytałem tę wartość z pliku i uznałem
za obowiązującą. Jest w pliku i nie działa.

Sprawdziłem sam na produkcji, cel `192.0.2.1` (TEST-NET-1, czarna dziura,
Railway nietknięte):

```
A) sam DSN connect_timeout=2             ->  7088 ms
B) DSN + PDO::ATTR_TIMEOUT=2             ->  2002 ms
C) kontrola pg_connect connect_timeout=2 ->  2002 ms
```

Para B i C pokazuje, że dwa sekundy są osiągalne. A pokazuje, że wartość z DSN
nie jest honorowana. CC ma rację i naprawa przez `PDO::ATTR_TIMEOUT` jest
właściwa. Zastrzeżenie do raportu CC: liczba 94 s pochodzi z innego trybu awarii
niż mój pomiar (7 s to wyczerpanie retransmisji TCP wobec TEST-NET-1, nie limit
libpq) i jej nie odtwarzałem. Kierunek wniosku bez zmian.

Skutek uboczny, który trzeba odnotować w `_docs/44`: deklarowana od CHAT-T-107
„szybka degradacja w ~2 s" nigdy nie działała. Realna degradacja przy awarii
Railway trwała tyle, ile zajął timeout TCP.

### 10.2. Blokada wdrożenia: budżet wydaje się wielokrotnie

`/codex` ma rację i to jest powód, dla którego **nie wdrażamy tej wersji**.

Breaker `$this->unavailable` zatrzaskuje się dopiero po wyczerpaniu prób.
Operacja, która uda się w drugiej próbie, zostawia go otwartym, więc następna
operacja dostaje pełny budżet od nowa. Repro CC: 5 operacji = 8037 ms.

To nie jest przypadek brzegowy, tylko dokładnie nasz scenariusz. Epizody mają
85-95 % straty, nie 100 %, więc część połączeń udaje się w drugiej lub trzeciej
próbie. Żądanie czatu robi kilkanaście zapytań do PG. Przy 3,2 s na odzyskaną
operację daje to kilkadziesiąt sekund oczekiwania klienta, czyli stan **gorszy
niż dzisiejszy**, gdzie żądanie szybko kończy się komunikatem degradacji.
Wdrożenie łaty, która w scenariuszu docelowym pogarsza doświadczenie klienta,
nie ma sensu.

### 10.3. Do zrobienia przed wdrożeniem

1. Skumulowany budżet retry **na żądanie**, nie na operację. Stała rzędu
   `RETRY_BUDGET_MS = 10000`, pole instancji, naliczane przy KAŻDEJ nieudanej
   próbie i KAŻDYM backoffie, niezależnie od tego, czy operacja ostatecznie
   się powiodła. To ostatnie jest sednem: dziura, którą znalazł `/codex`,
   polega właśnie na tym, że odzyskane operacje nic nie kosztują licznika.
2. Gdy budżet wyczerpany: zachowuj się jak dziś, czyli jedna próba i porażka.
   Bez wyjątków i bez „jeszcze tylko raz".
3. Singleton nie wymaga dodatkowego resetu. PHP burzy statyki na koniec żądania
   (model shared-nothing), więc pole instancji jest z natury per żądanie;
   `reset()` z linii 404 jest używane wyłącznie w testach i tak ma zostać.
   Trwałe jest tylko uchwyt PDO przy `ATTR_PERSISTENT`, nie statyka PHP.
4. Docblock ma opisywać gwarancję, która realnie obowiązuje: „budżet N ms
   na żądanie", a nie „na operację przegraną".

### 10.4. Dowód wymagany dodatkowo

Test: **15 operacji, każda udana dopiero w drugiej próbie**, przy sztucznie
niedostępnej bazie w pierwszej próbie. Łączny narzut ma nie przekroczyć
`RETRY_BUDGET_MS` z tolerancją na jeden backoff. Dotychczasowy test budżetu
z §5.3 tego nie łapał, bo używał operacji przegranych.

### 10.5. Pozostałe ustalenia `/codex` — moje rozstrzygnięcie

- **Błędy uwierzytelnienia w SOFT** — stan sprzed zadania, nie regres. Zostaje,
  ale dopisz jedną linijkę do `_docs/44`, bo to pułapka: `getCode()` przy
  błędzie connectu zwraca `int 7`, nie SQLSTATE, więc gałęzie `08001/08004`
  nie strzelają nigdy.
- **SSL clean-close** — zgadzam się z CC, że u nas nieistotny, ale z powodu,
  który jest osobnym problemem: produkcja łączy się `sslmode=disable`.
  Patrz §10.6.
- **Brak limitu na czas zapytania** — dług sprzed zadania, poza zakresem.
- **Ryzyko duplikatu zapisu** — istniało dla SOFT od CHAT-T-107, poza zakresem.
- **Stare workery FPM i `PQreset` do 30 s** — uwaga realna, nikt jej nie
  zmierzył. Nie blokuje wdrożenia, ale wpisz ją do raportu jako znane ryzyko
  pierwszego żądania po deployu.
- **Wiele adresów IP** — obalone pomiarem CC, jeden rekord A, zero AAAA.

### 10.6. Znalezisko poboczne, poza zakresem tego zadania

`sslmode=disable` w produkcyjnym `DATABASE_URL`. Zweryfikowane niezależnie:
`grep -oh "sslmode=[A-Za-z-]*"` na `~/public_html/chat.divezone.pl/.env`
zwraca jedno trafienie, `sslmode=disable`. Domyślna wartość w kodzie
(`PostgresConnection.php:390`) to `require`, więc to świadome ustawienie
w konfiguracji, nie przeoczenie w kodzie.

Skutek: hasło do bazy i pełna treść rozmów klientów przechodzą przez Orange
Polska i Arelion bez szyfrowania. Osobna karta, nie ruszaj tego w T-191.

═══ CHAT-T-191 · BACKEND · retry przy HARD-timeout ═══
