# CHAT-T-150 — EMBEDDINGS: Automatyzacja pipeline'u produktów (delta po hashu + cron 02:15)

**Status:** DO WYKONANIA
**Instancja:** embeddings (Python)
**Powiązane:** ADR-128 (+ nota nr 1 — CZYTAJ, koryguje rekomendację), ADR-088 (`.env`), ADR-089 (STOP-gate), TASK-ENC-013 (wzorzec hash-delty, decyzja 252a), ADR-123 nota 93a
**Karta Trello:** Chat - 23

---

## ŚWIAT WDROŻENIOWY — CZYTAJ ZANIM COKOLWIEK WYŚLESZ

Ten task **NIE dotyczy żadnego z dwóch zwykłych światów wdrożeniowych.**

- **NIE** `~/public_html/chat.divezone.pl/` (backend standalone)
- **NIE** `~/public_html/newtmp2/` (sklep + moduł)
- **TAK:** `/home/divezone/scripts/embeddings/` — nowy katalog, poza docrootem

**ZERO migracji PG. ZERO rsync `standalone/`.** Repo ma dryf wobec produkcji (`config/tools.php` = fatal 500) — nawet nie zbliżaj się do `standalone/`.

`.env` czytany **ścieżką bezwzględną**: `/home/divezone/public_html/chat.divezone.pl/.env`. **NIE kopiuj go. NIE twórz drugiego. NIE wypisuj wartości kluczy do logu ani do raportu.**

---

## KONTEKST

Pipeline embeddingów produktów żyje wyłącznie na laptopie Karola. Nowy produkt jest niewidoczny dla bota, aż ktoś ręcznie odpali skrypt. Nie ma crona, nie ma kodu na serwerze, nie ma alertu — awaria jest cicha i taka cisza trwała już raz miesiącami.

**Stan zweryfikowany przez architekta na produkcji 2026-07-17 (pomiar, nie karta):**
- `crontab -l` — 48 wpisów, zero embeddingów
- `~/public_html/chat.divezone.pl/embeddings/` — nie istnieje
- `/usr/bin/python3.12` = 3.12.13, pip 23.2.1, venv OK. `/usr/bin/python3` = 3.6.8 (za stary — NIE używaj)
- `divechat_product_embeddings`: 2606 rekordów, `max(updated_at)` = 2026-07-16 18:40 UTC, zero NULL w `embedding`. Cztery wektory na produkt, wszystkie komplet.
- MySQL `pr_product` `active=1`: 2664 wiersze, 43 zmiany/7 dni, 1/dobę, 4 nowe/30 dni

**Architektura pipeline'u jest dwuetapowa (potwierdzone w kodzie):**
1. `batch_embed_products.py` — MySQL → PG, zapisuje `document_text` + `embedding`
2. `batch_embed_multivector.py` — czyta z PG (w tym `search_phrases`), liczy `embedding_name`, `embedding_desc`, `embedding_jargon` (`:192-194`)

Etap 2 zależy od etapu 1. Cron musi odpalić **oba, w tej kolejności, na tym samym zbiorze delty.**

---

## USTALENIA WERYFIKACJI SERWERA (architekt, 2026-07-18) — czytaj, oszczędza rundę pytań

Pierwsza tura prac CC (przerwana blokadą macOS TCC) zgłosiła cztery rozbieżności task↔kod. Architekt sprawdził każdą na serwerze. Wyniki:

1. **`DIVECHAT_COST_ALERT_EMAIL` JEST w serwerowym `.env`** (potwierdzone `grep` na `~/public_html/chat.divezone.pl/.env`). W repo go nie ma — to normalne, sekret żyje tylko na serwerze. Alerty (KROK 4) mają na czym stanąć. `/usr/sbin/sendmail` obecny (setgid mailtrap).
2. **Ścieżka `.env`: loader MUSI być mode-aware.** `parent.parent/.env` na serwerze wskazuje `/home/divezone/scripts/.env` = nie istnieje. W trybie `server` czytaj **bezwzględnie** `/home/divezone/public_html/chat.divezone.pl/.env` (ADR-128 dec. 145a). CC w pierwszej turze dodał to poprawnie — zachowaj.
3. **`requirements.txt` już istnieje** i ma komplet zależności. W KROKU 5 to **deploy istniejącego pliku, nie tworzenie nowego.**
4. **`DB_HOST=localhost`, NIE `127.0.0.1`.** Zweryfikowane: port 3306 otwarty, socket `/var/lib/mysql/mysql.sock` żyje — więc tryb `server` zadziała. **UWAGA dla PyMySQL: `localhost` bywa tłumaczone na socket unixowy, `127.0.0.1` wymusza TCP — to NIE są synonimy.** W trybie `server` użyj wartości z `DB_HOST`/`DB_PORT` z `.env` (czyli `localhost:3306`); jeśli PyMySQL z `host='localhost'` pójdzie w socket i to zadziała — dobrze, socket istnieje. Nie podmieniaj na sztywno `127.0.0.1`, chyba że test połączenia w KROKU 6 pokaże problem z socketem. Backend czatu łączy się z tym MySQL na tych samych parametrach z `.env`, więc są sprawdzone w boju.

---

## KROK 0 — PULL / READ

1. `git pull --rebase origin main`, `git status`, sprawdź gałąź.
2. Przeczytaj **ADR-128 wraz z notą nr 1** w `_docs/10_decyzje_projektowe.md` (na końcu pliku). Nota koryguje pierwotną rekomendację architekta — obowiązuje wersja z noty: **bez migracji, bez nowej kolumny**.
3. Przeczytaj `_instances/embeddings/tasks/TASK-ENC-013_embeddings_auto-update-encyklopedii.md`, KROK 1 — tam jest wzorzec kanonicznego hasha. **Stosuj ten sam wzorzec, nie wymyślaj drugiego.**
4. Przeczytaj w całości: `embeddings/extract_products.py` (szczególnie `open_ssh_tunnel()` `:217`, `close_ssh_tunnel()` `:240`, `get_mysql_connection()` `:246`, `build_document_text()` `:270`), `embeddings/batch_embed_products.py`, `embeddings/batch_embed_multivector.py`.
5. `git log --oneline -5 -- embeddings/` — konwencja commitów.

---

## KROK 1 — Refaktor połączenia MySQL (tryb serwerowy bez tunelu)

Dziś `get_mysql_connection()` (`extract_products.py:246-256`) ma na sztywno `host="127.0.0.1"` i `port=LOCAL_MYSQL_PORT`. User, hasło i baza idą już ze zmiennych — **tego nie ruszaj**.

- Wprowadź przełącznik trybu ze zmiennej środowiskowej, np. `EMBEDDINGS_ENV=server|local` (domyślnie `local` — **brak zmiennej = zachowanie jak dziś**).
- `server`: `host` i `port` z `DB_HOST`/`DB_PORT`, **bez** wołania `open_ssh_tunnel()`.
- `local`: tunel jak dziś, zero zmian w zachowaniu.
- `open_ssh_tunnel()` / `close_ssh_tunnel()` **zostają w kodzie**, wołane warunkowo. Nie usuwaj ich.
- To samo dotyczy pozostałych plików wołających tunel: `audit_d1_pr_category.py`, `coverage_report.py`, `diagnose_size_attributes.py`, `etl_d1_parent_category.py`, `inventory_size_charts.py`, `map_size_products.py` — **w tym tasku ich NIE ruszasz**, chyba że importują `open_ssh_tunnel` z `extract_products` i refaktor by je wywrócił. Jeśli tak: zachowaj sygnaturę funkcji, żeby nic się nie zepsuło.

**Kryterium: tryb lokalny z laptopa musi działać dokładnie jak dziś.** To jedyna ścieżka debugowania.

## KROK 2 — Delta po hashu (`--mode changed`)

Nowy tryb w `batch_embed_products.py`, obok istniejących `--test N` / `--full`.

- Extract z MySQL **zawsze pełny** (2664 wiersze, jedno zapytanie, zero kosztu API).
- Dla każdego produktu zbuduj `document_text` istniejącą funkcją `build_document_text()` — **nie pisz drugiej**.
- Policz `sha256` z **znormalizowanego** `document_text` (normalizacja jak w ENC-013: odporna na białe znaki i formatowanie — inaczej dostaniesz fałszywe rozjazdy i re-embed całości).
- Pobierz z PG `ps_product_id, document_text` dla wszystkich rekordów, policz hash **tą samą funkcją** po stronie PG-owej treści.
- Do embeddingu kwalifikuj wyłącznie: **hash różny** albo **produkt nieobecny w PG**.
- **NIE dodawaj kolumny na hash. NIE pisz migracji.** Hash liczony w locie po obu stronach (ADR-128 nota nr 1).
- Produkty zniknięte z MySQL / `active=0`: **nie usuwaj wierszy w tym tasku** — pipeline ma `is_active`, zmiana tej flagi zmienia `document_text`, więc złapie ją delta. Kasowanie danych to osobna decyzja, nie rób jej sam.

## KROK 3 — Multivector na tym samym zbiorze

- `batch_embed_multivector.py` — tryb przyjmujący **listę `ps_product_id`** do przeliczenia (dziś leci po wszystkim).
- Wołany po KROKU 2, na dokładnie tym zbiorze, który KROK 2 zaktualizował. Zbiór pusty → nic nie robi i kończy się sukcesem.
- `EMBEDDING_DIM` i modele: **NIE zmieniaj**.

## KROK 4 — Runner + log + alert

Jeden punkt wejścia dla crona (np. `run_nightly.py` albo `run_nightly.sh` — Twój wybór, uzasadnij w raporcie).

- Kolejność: KROK 2 → KROK 3. Błąd etapu 1 → **nie odpalaj etapu 2**.
- Log: `/home/divezone/logs/divechat_embeddings.log` (append, z timestampem; katalog istnieje, używają go inne crony czatu).
- Log ma zawierać: ile wyekstrahowano, ile zakwalifikowano do delty, ile embeddingów, czas, koszt/liczba wywołań API. **Zero sekretów w logu.**
- **Alert mailem** na adres z `DIVECHAT_COST_ALERT_EMAIL` (ADR-128, decyzja 144a):
  1. przebieg padł (niezerowy exit / wyjątek / błąd API)
  2. **heartbeat: brak udanego przebiegu przez 48 h** — zapisuj znacznik ostatniego sukcesu (plik `last_success` obok logu, `touch` po udanym przebiegu) i sprawdzaj go na starcie. **Cisza nie jest dowodem sukcesu.**
- Zabezpieczenie przed nakładaniem przebiegów (lock file) — przebieg nie może wystartować, gdy poprzedni żyje.

**Heartbeat w runnerze NIE wystarcza (decyzja 148b).** Łapie „przebiegi lecą, ale padają". NIE łapie najgorszego przypadku: **cron w ogóle nie wystartował** (runner się nie odpali, więc nikt nie sprawdzi heartbeatu). Ten przypadek już raz zaszedł — pipeline stał 2 miesiące w ciszy. Dlatego dochodzi **osobny cron-strażnik (dead-man)**, niezależny od runnera:

- Mały skrypt `watchdog.sh` (kilka linii): sprawdza wiek pliku `last_success`; jeśli starszy niż próg (26 h — doba + margines), wysyła mail alertowy przez `/usr/sbin/sendmail` na adres z `.env` i kończy. Jeśli świeży — cisza, exit 0.
- **Adres i sekret czyta z tego samego `.env`** ścieżką bezwzględną (nie hardkoduj maila w skrypcie).
- Strażnik jest osobną linią w crontabie (KROK 7), o innej godzinie niż główny przebieg. Działa, **nawet gdy główny wpis crona zniknie lub runner się nie odpali** — bo to on obserwuje `last_success`, nie runner samego siebie.
- **Świadome ograniczenie do zapisania w raporcie:** jeśli padnie CAŁY cron demona (nie pojedynczy wpis, lecz `crond`), strażnik też nie ruszy. Pełne domknięcie wymagałoby monitoringu spoza serwera — poza zakresem tej karty. Strażnik zamyka najczęstszy realny scenariusz (zniknięty/zepsuty wpis, runner rzucający wyjątkiem przed zapisem heartbeatu), nie wszystkie.

## KROK 5 — STOP. Deploy na serwer

**STOP-gate (ADR-089). Nie wykonuj bez słowa „deployuj" od Karola.**

Przygotuj i przedstaw do zatwierdzenia:
- `mkdir -p /home/divezone/scripts/embeddings`
- venv: `/usr/bin/python3.12 -m venv /home/divezone/scripts/embeddings/.venv`, potem `pip install -r requirements.txt`. **`requirements.txt` już istnieje w repo z kompletem zależności** (openai, psycopg2-binary, python-dotenv, numpy, pymysql, beautifulsoup4) — deployujesz istniejący plik, nie tworzysz nowego.
- **Wypisz KONKRETNE pliki do wysłania** (nie katalog, nie `rsync` całości). Minimum: `extract_products.py`, `batch_embed_products.py`, `batch_embed_multivector.py`, `generate_embeddings.py`, runner (`run_nightly.py`), `watchdog.sh`, `requirements.txt`. Sprawdź importy — jeśli ciągną coś jeszcze, dopisz.
- **NIE wysyłaj** `__pycache__`, plików diagnostycznych ani skryptów jednorazowych.

## KROK 6 — Weryfikacja na serwerze (przed cronem)

1. `--mode changed` **na sucho / dry-run**: ma wypisać, ile produktów zakwalifikowałby, **bez żadnego wywołania API**. Oczekiwanie: **liczba bliska zeru** (baza odświeżona 2026-07-16, MySQL od tego czasu 1 zmiana). **Jeśli wyjdzie ~2606 — hash jest źle liczony, STOP, zgłoś. Nie „napraw" tego pełnym re-embedem.**
2. Dopiero po zgodzie: realny przebieg delty.
3. Sprawdź `max(updated_at)` w PG i log.

**DOWÓD, ŻE DELTA=0 JEST POPRAWNA (architekt, weryfikacja niezależna 2026-07-18).** Lokalny dry-run CC dał delta 0/2606. To NIE jest „nic nie znalazł" — to potwierdzenie mechanizmu na realnym przypadku brzegowym. W MySQL produkt **4290** (Kompas TECLINE) ma `date_upd = 2026-07-17 12:03`, czyli PO ostatnim embeddingu (2026-07-16 20:40 CEST). Kryterium `date_upd > last_run` (odrzucone 141a) wysłałoby go na zbędny re-embed 4 wektorów. Ale jego cena BRUTTO się nie zmieniła (MySQL `price=202.097561` netto × 1.23 = **248.58** = dokładnie to, co siedzi w `document_text` w PG), więc treść dokumentu jest identyczna → hash się zgadza → delta słusznie go pomija. **`date_upd` skłamał, hash nie.** Dokładnie o to chodziło w 141b. Dry-run na serwerze (KROK 6) ma powtórzyć delta ≈ 0 — jeśli tak, ten sam mechanizm potwierdzony też z serwerowej ścieżki.

## KROK 7 — STOP. Wpis do crona

**STOP. Crontab to wspólny zasób — inne projekty mają tam swoje wpisy. Nie edytuj bez zgody.**

Proponowane wpisy — **DWA** (ADR-128 dec. 142c + 146c; strażnik dec. 148b):
```
# 1) główny przebieg delty, 02:15
15 2 * * * /usr/bin/timeout 1800 /home/divezone/scripts/embeddings/.venv/bin/python /home/divezone/scripts/embeddings/run_nightly.py --mode changed >> /home/divezone/logs/divechat_embeddings.log 2>&1
# 2) cron-strażnik (dead-man): sprawdza wiek last_success, alert gdy > 26 h
30 8 * * * /home/divezone/scripts/embeddings/watchdog.sh >> /home/divezone/logs/divechat_embeddings.log 2>&1
```
Główny wpis o **02:15** — wybór z pomiaru zajętości crona: blok 03:00-05:30 gęsty (indeksery 03:20/03:30, `sec_scan COLD` 03:30, klaviyo 03:40, `sentinel --full` 04:30 z timeoutem 3600 → do 05:30). Wolne okno 01:40-03:00, 02:15 daje ~45 min zapasu z obu stron. **Nie przesuwaj bez ponownego pomiaru.**

Strażnik o **08:30** — z rana, po nocnym przebiegu, w porze gdy Karol widzi maila; niezależny od godziny głównego wpisu. Próg 26 h = doba + margines (przebieg spóźniony o godzinę nie odpala fałszywego alarmu, ale zniknięty przebieg łapie następnego ranka).

Przed edycją: `crontab -l > ~/crontab.bak-embeddings-$(date +%Y%m%d-%H%M%S)` (wzorzec z istniejących backupów w `~/`).

## KROK 8 — Status + raport

1. `_docs/21_STATUS_PROJEKTU.md` — dopisz **NA GÓRZE**, nie nadpisuj.
2. Kroki git: `git status`, `git add` **per ścieżka** (nigdy `git add .`), commit wg konwencji `CHAT-T-150 embeddings: opis (ADR-128)`, `git push origin main`. Po deployu **osobny commit `docs:`**.
3. Raport: co zrobione, co zweryfikowane **czym** (polecenie + wynik), co pominięte i dlaczego, oraz **każda rozbieżność między tym taskiem a realnym kodem** — architekt czyta raport jak recenzję, nie sprawozdanie.

---

## CZEGO NIE RUSZAĆ

- `standalone/` — **cokolwiek**. Szczególnie `config/tools.php` (dryf R-5, fatal 500) i `config/routes.php` (niezacommitowana zmiana innej sesji)
- `~/public_html/newtmp2/` — cały świat sklepu
- `_ops/newtmp2_root/purge_litespeed.php` — SEKRET
- ADR-y w `_docs/10` — **pisze je architekt**. Nie dopisuj, nie edytuj, nie commituj ADR-ów
- `EMBEDDING_DIM`, nazwy modeli OpenAI, schemat `divechat_product_embeddings`
- Cudze wpisy w crontabie
- Skrypty encyklopedii (`scripts/embed_encyclopedia.py`) — inna karta, inny wykonawca
- `.env` — czytaj, nie zapisuj, nie kopiuj, nie loguj wartości

---

**Instancja: EMBEDDINGS (Python)**
