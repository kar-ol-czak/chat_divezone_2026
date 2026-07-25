# CHAT-T-169 — INTEGRATION — `replay.py`: replay rozmowy na produkcji przez /api/chat

**Instancja:** integration
**Plik do utworzenia:** `_diag_local/chat_verification/replay.py`
**Świat wdrożeniowy:** ŻADEN — narzędzie lokalne, nie idzie na serwer
**ADR:** brak nowego (narzędzie diagnostyczne, nie zmiana architektury)

---

## 1. Po co

Kryteria akceptacji tasków wymagają odtworzenia rozmowy na produkcji i sprawdzenia,
co bot faktycznie wywołał. Dziś nie ma jak tego zrobić bez udziału Karola klikającego
w widget. W jednej sesji (2026-07-24) weryfikacja zatrzymała się na tym trzykrotnie:
CHAT-T-167, CHAT-T-168 i domknięcie recenzji rozmów 817/829.

Zasada projektu: weryfikację robi architekt lub CC, nie Karol.

Po tym tasku `replay.py` staje się czwartym narzędziem obok `sql.py`, `mysql.py`
i `check_deploy.py`, i wchodzi do katalogu narzędzi w `_docs/46` (§1).

## 2. Kontrakt endpointu — ustalony z kodu, nie zgadywany

`standalone/config/routes.php:84` → `POST /api/chat` → `ChatController::handle()`

**Headery (ChatController.php:250-257):**
```
X-DiveChat-Token:    HMAC
X-DiveChat-Customer: customer_id (0 = niezalogowany)
X-DiveChat-Time:     unix timestamp
Content-Type:        application/json
```

**Wyliczenie tokenu (`Auth/HmacVerifier.php:27`):**
```
hash_hmac('sha256', $customerId . ':' . $timestamp, $secret)
```
gdzie `$secret` = `DIVECHAT_SECRET` (NIE `DIVECHAT_SERVER_SECRET` — to inny sekret,
kanał serwerowy panelu admina; pomylenie ich kosztowało już czas w tym projekcie).

**Okno czasowe:** 900 s (`maxAgeSec`). Timestamp starszy → 401.

**Body (ChatController.php:271-287):**
```json
{"message": "treść", "session_id": "UUID v4 lub null"}
```
`session_id` null → backend generuje własny UUID v4. Format inny niż UUID v4
lub legacy 32-hex jest odrzucany i zastępowany server-side (defensywa CHAT-T-082).

**Odpowiedź:** JSON z polami `response` i `session_id`.

## 3. Twarda zasada — sekrety

`DIVECHAT_SECRET` czytasz przez `Config::load()` → `$_ENV`, **NIGDY** przez
parsowanie `.env` regexem ani `grep`. Powód (ADR-088, wpis w `_docs/44`):
phpdotenv ucina wartość na `#`, więc ręczny odczyt daje inny sekret niż ten,
którego używa aplikacja. Ta pułapka wywaliła już produkcję (błąd 1045).

Ponieważ `replay.py` jest w Pythonie, a `Config::load()` w PHP — sekret pobierz
przez SSH, uruchamiając na serwerze jednolinijkowy PHP, który wypisze wartość
z `$_ENV` po `Config::load()`. Wzorzec połączenia SSH skopiuj z `mysql.py`
(base64 + `ea-php84`), nie wymyślaj własnego.

**Sekret NIE trafia do stdout, do logów, do plików tymczasowych ani do repo.**
Trzymaj w zmiennej w pamięci procesu.

## 4. Interfejs CLI

Wzorzec z `sql.py` (argparse, `-c` na treść):

```
replay.py -m "treść wiadomości"                    # nowa rozmowa
replay.py -m "..." --session <uuid>                # kontynuacja
replay.py -m "..." --customer 0                    # domyślnie 0
replay.py -m "..." --show-tools                    # od razu przebieg z tool_result
replay.py --from-conversation 829                  # powtórz 1. pytanie użytkownika z rozmowy 829
```

`--from-conversation N` czyta `divechat_conversations.messages` (Railway, przez
`_conn.py` jak `sql.py`), wyciąga **pierwszą** wiadomość roli `user` i wysyła ją
jako `-m`. To najczęstszy przypadek użycia: odtworzenie zgłoszonego błędu.

## 5. Wyjście

Po odpowiedzi wypisz:
```
session_id : <uuid>
conv_id    : <id z divechat_conversations>   (dociągnij po session_id)
narzedzia  : ["search_products", "get_product_combinations"]
zapytania  : search_products q="..." kw=["..."] cnt=N
odpowiedz  : <treść>
```

`--show-tools` → dołóż pełny przebieg, wołając istniejący `show_conversation.py`
z `--tools` (**nie duplikuj jego logiki**, uruchom go jako podproces lub zaimportuj).

## 6. Bezpieczniki

1. **Read-only wobec produkcji poza samą rozmową.** Narzędzie tworzy rozmowę
   w bazie i to jest jego jedyny efekt uboczny. Żadnych zapisów gdzie indziej.
2. **Oznaczenie rozmów testowych.** Rozmowy z replayu wpadają do tej samej tabeli
   co klienckie i zaśmiecą panel recenzji. Domyślnie doklej do treści niewidoczny
   dla oceny marker (np. prefiks `[REPLAY]` w wiadomości) LUB — lepiej, jeśli
   backend na to pozwala bez zmian — ustaw `X-DiveChat-Customer` na wartość
   zarezerwowaną. **Sprawdź, co jest możliwe BEZ modyfikacji backendu i zgłoś
   w raporcie, którą drogę wybrałeś i dlaczego.** Nie zmieniaj backendu w tym tasku.
3. **Limit.** Maksymalnie jedno wywołanie na uruchomienie. Bez pętli, bez batcha —
   każde wywołanie kosztuje tokeny modelu produkcyjnego.

---

## KROK 0 — pull i lektura

```
git pull --rebase
```

Przeczytaj (nie przepisuj — użyj istniejących wzorców):
- `_diag_local/chat_verification/sql.py` — argparse, `_conn.py`, styl wyjścia
- `_diag_local/chat_verification/mysql.py` — wzorzec SSH + base64 + `ea-php84`
- `_diag_local/chat_verification/show_conversation.py` — czego NIE duplikować
- `standalone/src/Controller/ChatController.php` linie 248-300
- `standalone/src/Auth/HmacVerifier.php` — całość (30 linii)

**NIE RUSZAJ:** niczego w `standalone/`, `config/`, ADR-ów. Ten task nie dotyka backendu.

## KROK 1 — pobranie sekretu

Funkcja zwracająca `DIVECHAT_SECRET` przez SSH + `Config::load()`.
Wzorzec połączenia z `mysql.py`. Sekret tylko w pamięci.

## KROK 2 — klient HMAC

Wyliczenie tokenu wg wzoru z sekcji 2. Timestamp = `int(time.time())`.
Nagłówki, POST na `https://chat.divezone.pl/api/chat`, timeout 120 s
(model potrafi myśleć długo).

## KROK 3 — CLI i `--from-conversation`

Wg sekcji 4. Odczyt Railway przez `_conn.py`.

## KROK 4 — wyjście i `--show-tools`

Wg sekcji 5. Po odpowiedzi dociągnij `conv_id`, `tools_used`
i `search_diagnostics` z Railway po `session_id`.

## KROK 5 — test na żywo

Uruchom dwa razy:
1. `replay.py --from-conversation 829 --show-tools`
2. `replay.py -m "Jakie rozmiary są dostępne w Scubapro OneFlex Overal 5mm?" --show-tools`

W raporcie wklej **pełne wyjście obu uruchomień**. To jest jednocześnie test
narzędzia i materiał do domknięcia recenzji rozmów 817/829 przez architekta.

## KROK 6 — dokumentacja

1. `_diag_local/chat_verification/README.md` — dopisz `replay.py` do listy narzędzi,
   z przykładami użycia.
2. **Nie ruszaj `_docs/46`** — wpięcie do runbooka architekta zrobi architekt.
   Zgłoś w raporcie, że czeka.

## KROK 7 — git

```
git status
git add _diag_local/chat_verification/replay.py
git add _diag_local/chat_verification/README.md
git commit -m "CHAT-T-169 integration: replay.py - odtwarzanie rozmowy na prod przez /api/chat"
git push origin main
```

---

## Kryterium akceptacji (weryfikuje architekt)

1. `replay.py --from-conversation 829` tworzy rozmowę i zwraca `conv_id`
2. Wyjście pokazuje `tools_used` i zapytania z `search_diagnostics`
3. Sekret nie pojawia się w żadnym wyjściu, pliku ani logu
4. Powtórne uruchomienie po 15 minutach nadal działa (świeży timestamp, nie cache)
