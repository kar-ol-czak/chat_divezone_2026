═══ CHAT-T-192 · INTEGRATION · PRÓBA WYKONALNOŚCI · PRZESZŁO Z ZASTRZEŻENIAMI ═══

# CHAT-T-192 — czy baza czatu da się odtworzyć na mydevil

**Instancja:** INTEGRATION. **Data:** 2026-09-15. **Charakter:** próba wykonalności na kopii.
**Zlecenie:** `_instances/integration/tasks/CHAT-T-192_INTEGRATION_proba-wykonalnosci-mydevil.md`.
**Railway:** wyłącznie odczyt (`pg_dump`, `SELECT`, `EXPLAIN`). Produkcji czatu nie dotykałem.

---

## WERDYKT: PRZESZŁO Z ZASTRZEŻENIAMI

Baza odtwarza się na mydevil **w całości i bez straty wiersza**, a zejście 18.3 → 16.10 nie
okazało się przeszkodą. Zastrzeżenie, które waży najwięcej, nie dotyczy jednak zrzutu:

> **PostgreSQL na mydevil nie jest dostępny z internetu.** `pg_hba` odrzuca połączenia
> zarówno z Maca, jak i z serwera sklepu. Jedyna droga zdalna opisana przez dostawcę to
> **tunel SSH**. Dopóki to nie zostanie rozwiązane, czat nie ma jak się z tą bazą połączyć,
> niezależnie od tego, jak dobre są czasy.

Szczegóły w sekcji „Zastrzeżenia" na końcu.

---

## DOWÓD 1 — wersja `pg_dump` użytego do zrzutu

```
/opt/homebrew/opt/postgresql@18/bin/pg_dump --version  ->  pg_dump (PostgreSQL) 18.6 (Homebrew)
/opt/homebrew/opt/postgresql@18/bin/psql   --version  ->  psql    (PostgreSQL) 18.6 (Homebrew)
pg_dump --version (stary klient z PATH)               ->  pg_dump (PostgreSQL) 14.22 (Homebrew)
```

`brew install postgresql@18` przeszedł, ale formuła jest keg-only i **jest przesłonięta przez
postgresql@14** z PATH. Każde wywołanie szło pełną ścieżką; użycie samego `pg_dump` cofnęłoby
nas do czternastki i zadanie by nie ruszyło. Serwery: Railway **18.3**, mydevil **16.10**
(`amd64-portbld-freebsd14.3`).

## DOWÓD 2 — pełna lista błędów odtworzenia, z kwalifikacją

Zrzut odtwarzałem **bez żadnych modyfikacji**, żeby zobaczyć prawdę, a nie wersję wygładzoną.

### Próba 1 — przed włączeniem rozszerzeń (kolejność wg zlecenia)

43 linie stderr, ale tylko jedna przyczyna źródłowa:

```
psql:schema_railway.sql:13: ERROR:  unrecognized configuration parameter "transaction_timeout"
psql:schema_railway.sql:54: ERROR:  permission denied to create extension "vector"
HINT:  Must be superuser to create this extension.
psql:schema_railway.sql:61: ERROR:  extension "vector" does not exist
psql:schema_railway.sql:825: ERROR:  type "public.vector" does not exist
LINE 7:     embedding public.vector(1536),
```

Dalej kaskada: 3 × `type "X" does not exist`, 30 × `relation "X" does not exist`. Wszystkie
wtórne wobec braku `vector`. **Kwalifikacja: nie blokada, zła kolejność kroków** — rozszerzenie
musi istnieć, zanim powstaną kolumny typu `vector`.

### Próba 2 — po włączeniu rozszerzeń, na czystym schemacie

**Cztery linie, komplet:**

```
psql:schema_railway.sql:13: ERROR:  unrecognized configuration parameter "transaction_timeout"
psql:schema_railway.sql:33: ERROR:  must be owner of extension pg_trgm
psql:schema_railway.sql:47: ERROR:  must be owner of extension unaccent
psql:schema_railway.sql:61: ERROR:  must be owner of extension vector
```

| linia | błąd | kwalifikacja |
|---|---|---|
| 13 | `transaction_timeout` | **nagłówek zrzutu do odfiltrowania.** GUC wprowadzony w PostgreSQL 17, szesnastka go nie zna. `SET` nie powiódł się i nic z tego nie wynika: parametr ustawia limit czasu transakcji na 0, czyli i tak „bez limitu" — dokładnie to, co 16.10 robi domyślnie. Zero wpływu na schemat i dane. |
| 33, 47, 61 | `must be owner of extension` | **nagłówek zrzutu do odfiltrowania.** To wyłącznie `COMMENT ON EXTENSION`, czyli opis w katalogu systemowym. Rozszerzenia zakłada superużytkownik przez `devil`, więc ich właścicielem nie jesteśmy i komentarza nie zmienimy. Nie dotyczy ani jednego obiektu aplikacji. |

**Realnych blokad 16.10: ZERO.**

### Czego się spodziewałem, a nie wystąpiło

Linia 5 zrzutu to `\restrict <token>`, a ostatnia `\unrestrict <token>` — polecenia trybu
ograniczonego `psql`, naturalny kandydat na „osiemnastka mówi językiem, którego szesnastka
nie zna". **Nie wywołały błędu.** Sprawdziłem wprost, z próbą kontrolną dodatnią:

```
\restrict testtoken  +  SELECT 1;  +  \unrestrict testtoken   ->  1        (bez błędu)
\nieistniejace_polecenie                                      ->  invalid command \nieistniejace_polecenie
psql -c "\?" | grep restrict  ->  \restrict RESTRICT_KEY / \unrestrict RESTRICT_KEY
```

Czyli psql 16.10 **zna** te polecenia i nie ma tu czego filtrować. Odnotowuję, bo sam bym
obstawiał odwrotnie.

## DOWÓD 3 — rozszerzenia na `divechat_test`

```
 extname  | extversion
----------+------------
 pg_trgm  | 1.6
 plpgsql  | 1.0
 unaccent | 1.1
 vector   | 0.8.0
```

**Metoda ma znaczenie i różni się od zlecenia.** `CREATE EXTENSION` jest niedostępne (brak
superużytkownika, patrz błąd z linii 54 w próbie 1). Rozszerzenia włącza się poleceniem
dostawcy, którego składnię odczytałem z narzędzia, nie z pamięci:

```
devil pgsql extensions p1137_divechat_t vector      ->  [Ok] Rozszerzenie dodane prawidłowo
devil pgsql extensions p1137_divechat_t pg_trgm     ->  [Ok]
devil pgsql extensions p1137_divechat_t unaccent    ->  [Ok]
```

Wersje `pg_trgm` i `unaccent` są **identyczne** jak na Railway (1.6 i 1.1). `vector` różni się
na trzeciej pozycji: 0.8.0 wobec 0.8.1.

**Pułapka przy powtarzaniu próby:** `DROP SCHEMA public CASCADE` kasuje także rozszerzenia,
bo mieszkają w `public`. Po każdym czyszczeniu trzeba je włączyć ponownie przez `devil`.

## DOWÓD 4 — indeksy na `divechat_product_embeddings`

Po obu stronach **14 indeksów, definicja w definicję**, w tym cztery HNSW:

```
idx_emb_desc_hnsw         hnsw   USING hnsw (embedding_desc vector_cosine_ops)
idx_emb_jargon_hnsw       hnsw   USING hnsw (embedding_jargon vector_cosine_ops)
idx_emb_name_hnsw         hnsw   USING hnsw (embedding_name vector_cosine_ops)
idx_product_embedding_hnsw hnsw  USING hnsw (embedding vector_cosine_ops)
```

plus 3 × GIN (`gin_trgm_ops`, `fts_vector`) i 7 × btree. Metoda dostępu, kolumna i klasa
operatorów zgadzają się co do znaku — różnica pgvector 0.8.0 wobec 0.8.1 nie zmieniła niczego
w strukturze indeksów. Całościowo: **32 tabele, 98 indeksów, 155 funkcji po obu stronach.**

## DOWÓD 5 — czasy i rozmiary

| etap | wynik |
|---|---|
| zrzut schematu (Railway → Mac) | **2,5 s**, 80 KB, 2538 linii |
| zrzut pełny z danymi | **10 s**, **324 561 361 B** (309,5 MiB), 489 317 linii, **zero błędów** |
| kompresja `gzip -1` | 3 s → 126 901 169 B (121 MiB) |
| transfer na mydevil (`scp`) | **10 s** |
| rozpakowanie na mydevil | 1 s |
| **odtworzenie pełne** (`psql -f`) | **9 s**, 4 linie stderr (tabela wyżej) |

Cała droga Railway → mydevil zamyka się w **ok. 33 sekundach**. Dziewięć sekund na odtworzenie
309 MiB wydało mi się zbyt dobre, żeby w nie uwierzyć bez sprawdzenia — stąd DOWÓD 7.

## DOWÓD 6 — pomiar p50 i p95

**Zapytanie:** `SELECT ps_product_id FROM divechat_product_embeddings ORDER BY embedding <=> <wektor> LIMIT 10`,
30 powtórzeń w jednej sesji (`\timing`), ten sam wektor po obu stronach.

| | zapytanie wektorowe p50 | p95 | `SELECT 1` p50 | p95 |
|---|---|---|---|---|
| **Railway** — z serwera sklepu, trasa realna | **69,2 ms** | 78,7 ms | **34,2 ms** | 34,8 ms |
| **mydevil** — z powłoki obok bazy, trasa ~0 | **13,4 ms** | 13,8 ms | **0,0 ms** | 0,0 ms |

Wariant z literałem wektora (19 KB w treści zapytania, dokładnie jak robi aplikacja) daje to
samo: Railway p50 **70,3** / p95 77,8; mydevil p50 **9,5** / p95 12,5.

**Czwartej liczby nie ma i nie zmyślam jej.** Pomiar mydevil **z serwera sklepu** jest
niewykonalny, bo `pg_hba` odrzuca ten adres (sekcja „Zastrzeżenia"). Zamiast tego zmierzyłem
osobno obie składowe, żeby dało się je złożyć:

```
czas nawiazania TCP z serwera sklepu, 10 prob:
  switchback.proxy.rlwy.net:14368  ->  p50 = 37 ms   (min 37, max 38)
  pgsql81.mydevil.net:5432         ->  p50 = 10 ms   (min 10, max 11)
```

**Szacunek, nie pomiar:** mydevil z serwera sklepu to ≈ 9,5 ms pracy bazy + ~10 ms trasy
≈ **20 ms**, wobec zmierzonych 69,2 ms do Railway. Do tego doszedłby narzut tunelu SSH,
którego nie znam. Traktować jako rząd wielkości, nie jako wynik.

**Plany zapytań są identyczne**, więc porównanie nie jest jabłek z gruszkami:

```
RAILWAY 18.3:  Limit <- Sort (top-N heapsort) <- Seq Scan (2620 wierszy)   Execution Time: 42,9 ms
MYDEVIL 16.10: Limit <- Sort (top-N heapsort) <- Seq Scan (2620 wierszy)   Execution Time: 14,4 ms
```

**Ustalenie poboczne, warte osobnego zadania:** ani Railway, ani mydevil **nie używają indeksu
HNSW** w tym zapytaniu — przy 2620 wierszach planista wybiera przegląd sekwencyjny jako tańszy.
Sprawdziłem to również z literałem wektora, żeby wykluczyć wpływ podzapytania: ten sam `Seq Scan`.
Indeksy HNSW istnieją po obu stronach i są poprawne, tylko nie są w tej chwili do niczego
używane. To nie wpływa na werdykt (obie bazy liczą tak samo), ale znaczy, że `divechat_test`
przy tej wielkości tabeli nie testuje pgvectora tak, jak się wydaje.

## DOWÓD 7 — kontrola kompletności zrzutu

| tabela | Railway | mydevil |
|---|---|---|
| `divechat_product_embeddings` | **2620** | **2620** |
| `encyclopedia_chunks` | **530** | **530** |
| `divechat_conversations` | 1270 | 1270 |
| `divechat_messages` | 11902 | 11902 |
| `divechat_rate_limit` | 84958 | 84958 |
| `divechat_nudge_events` | 374200 | 374199 |

Jedna różnica, o jeden wiersz. **Nie przyjąłem, że „to pewnie ruch" — sprawdziłem w zrzucie:**

```
blok COPY public.divechat_nudge_events w pliku zrzutu  ->  374199 wierszy
```

Zrzut ma dokładnie tyle, ile ma mydevil, czyli **odtworzenie nie zgubiło niczego**. Railway
w międzyczasie urosło dalej — przy kolejnym odczycie było już 374 201, a `max(id)` 374 277
wobec 374 275 na kopii. To żywa tabela, rośnie w trakcie pomiaru.

Rozmiar bazy: Railway **452 MB**, mydevil **338 MB**. Przy identycznych liczbach wierszy
różnica to rozdęcie żywej bazy martwymi krotkami, nie brak danych — kopia jest świeżo
odtworzona i zwarta.

---

## Zastrzeżenia

**1. Brak dostępu sieciowego — to jest prawdziwa przeszkoda, nie nagłówek zrzutu.**

```
z Maca (188.47.44.175):          FATAL: no pg_hba.conf entry for host "188.47.44.175", ... no encryption
z serwera sklepu (193.93.88.95): FATAL: no pg_hba.conf entry for host "193.93.88.95", ... no encryption
z powłoki mydevil (s81):         PostgreSQL 16.10 ... (działa)
sslmode=require:                 server does not support SSL, but SSL was required
```

Dokumentacja dostawcy potwierdza, że tak ma być: „Aby zdalnie zalogować się do bazy PostgreSQL
należy wykorzystać `tunelowanie SSH`" (pomoc.mydevil.net/PostgreSQL). Nie ma polecenia `devil`
do dopisania adresu — moduły to 2fa, BinExec, DNS, FTP, Info, Lang, Mail, Mongo, MySQL, PgSQL,
Port, Repo, SSL, Vhost, WWW; żaden nie zarządza dostępem do bazy. **Konsekwencje dla migracji:**
czat musiałby chodzić przez stały tunel SSH z serwera sklepu, czyli nowy proces do pilnowania
i nowy pojedynczy punkt awarii — dokładnie ta klasa rzeczy, którą monitor Railway miał wykrywać.
Serwer sklepu nie ma dziś klucza do mydevil (`Permission denied (publickey)`), a założenie go
to decyzja architekta, nie krok mechaniczny, więc go nie założyłem.

**2. Serwer bazy nie obsługuje SSL.** Ruch w tunelu byłby szyfrowany przez SSH, ale połączenie
bezpośrednie jest jawne. Do odnotowania przy projektowaniu migracji.

**3. Rozszerzenia tylko przez `devil`.** Każde odtworzenie bazy od zera wymaga trzech wywołań
`devil pgsql extensions`; `CREATE EXTENSION` ze zrzutu zawsze poleci błędem uprawnień.

**4. Nazwa bazy została obcięta przez dostawcę.** `devil pgsql db add divechat_test` zwróciło
ostrzeżenie `database_too_long` i utworzyło **`p1137_divechat_t`** (użytkownik o tej samej
nazwie, host `pgsql81.mydevil.net`). Nazwy z zlecenia nie da się użyć dosłownie.

**5. `pg_dump` 14.22 przesłania osiemnastkę w PATH.** Formuła `postgresql@18` jest keg-only.
Każdy przyszły skrypt musi wołać pełną ścieżkę `/opt/homebrew/opt/postgresql@18/bin/`.

## Co zostało, co posprzątane

- **`p1137_divechat_t` na mydevil ZOSTAJE**, z pełnymi danymi i rozszerzeniami — zgodnie z §6.
- Zrzut z danymi **skasowany z Maca** (324 561 361 B) i z mydevil, razem z kopią `.gz`.
- Poświadczenia zapisane poza repo, w `~/Documents/3_DIVEZONE/.divezone_secrets/secrets.env`
  (0600, +8 linii, klucze `MYDEVIL_PG_*`, backup `.bak_20260915`). **Wartości nie padły ani
  w raporcie, ani w logu, ani w argv** — hasło szło przez stdin, połączenia przez `PGPASSFILE`.
- `~/.pgpass_t192` **usunięty z serwera sklepu** (poświadczenia mydevil nie mają tam czego
  szukać), zostawiony na mydevil przy bazie, która ma zostać.
- Na Railway nie wykonałem ani jednej operacji zapisu. `divechat_nudge_events`
  i `divechat_rate_limit` nietknięte.

## Czego NIE zrobiłem

- **Nie założyłem klucza SSH** serwer sklepu → mydevil i nie zbudowałem tunelu. To otwiera
  stałą ścieżkę między dwoma hostami produkcyjnymi i jest decyzją architekta.
- Nie dotykałem `.env` produkcji, `config/` czatu, `config/tools.php`, `config/routes.php`.
- Nie czyściłem `divechat_nudge_events` (374 tys. wierszy, 129 MB) ani `divechat_rate_limit` —
  osobna decyzja, zgodnie ze zleceniem.
- **Żadnego wniosku „to teraz migrujemy".** Werdykt wyżej i STOP.

═══ CHAT-T-192 · INTEGRATION · PRÓBA WYKONALNOŚCI · PRZESZŁO Z ZASTRZEŻENIAMI ═══
