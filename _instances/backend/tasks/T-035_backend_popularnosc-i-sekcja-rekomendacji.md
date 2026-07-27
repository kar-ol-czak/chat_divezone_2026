# T-035 — BACKEND: Popularnosc Subiekt (tabela+seed) + sekcja read-only rekomendacji w panelu

**Data:** 2026-06-01
**Instancja:** backend
**Wejscie:** T-031 (rekomendacje zaseedowane, mapowanie SKU->product_id gotowe), T-032/033/034 (kregoslup panelu dziala end-to-end), ADR-065 uzup.4 (panel pokazuje popularnosc Subiekta obok produktu), ADR-068 (kanal serwerowy).
**Cel:** druga walidacja kregoslupa na REALNYM odczycie danych. Sekcja read-only kuratorowanych rekomendacji w panelu PS + wskaznik popularnosci.

---

## DECYZJE (zatwierdzone)
- 35a: panel pokazuje WSZYSTKIE wpisy rekomendacji (takze nieaktywne/niedostepne — odwrotnie niz narzedzie bota, ktore je filtruje). Pracownik MUSI widziec martwe wpisy (sygnal do maczowania).
- 36a: endpoint dostepny dla DOWOLNEJ roli (operator lub admin).
- 37a: grupowanie po kategorii (3 sekcje), w kazdej wpisy wg priority.
- 38b: tabela trzyma qty_12m + net_value_12m, ale panel NIGDY nie pokazuje liczb sprzedazowych — tylko wskaznik wzgledny (malo/srednio/duzo lub pasek). Liczby NIE opuszczaja backendu.
- 39a: granularnosc per product_id (agregacja wszystkich wariantow/SKU danego produktu, jak ranking T-031).
- 41a: wskaznik liczony W OBREBIE KATEGORII rekomendacji (rozklad wartosci w tej kategorii), NIE globalnie.
- 42a: jednorazowy seed danych z T-031 TERAZ. BEZ budowy skryptu importu (mechanizm zasilania — skrypt upload vs pomost Subiekt — decyzja odlozona na pozniej, decyzja 173).

---

## CZESC A — tabela popularnosci + jednorazowy seed (BRAMKA przed seedem)

### A0 — rozpoznanie
- git pull. Przeczytaj T-031 handoff/skrypt rankingu (standalone/scripts/reseed_curated_candidates.php — logika parse CSV + mapowanie SKU->product_id przez pr_product.reference + fallback pr_product_attribute.reference). Przeczytaj sql/011 (wzorzec migracji).
- Zrodlo danych: reports/sales_subiekt_12mcy.csv (gitignored, kolumny Nazwa, Symbol, Grupa, Ilosc, J.M., Netto).

### A1 — migracja (sql/019_product_sales_popularity.sql + rollback)
Schemat:
- product_id INTEGER PRIMARY KEY (FK logiczna do pr_product, walidacja aplikacyjna)
- qty_12m INTEGER NOT NULL (suma sztuk 12mc, agregacja per product_id)
- net_value_12m NUMERIC(12,2) (wartosc netto 12mc z CSV; WRAZLIWE — nie pokazywac pracownikom)
- period_label VARCHAR(32) (np. "2025-06..2026-05" lub etykieta eksportu)
- source VARCHAR(32) NOT NULL DEFAULT 'subiekt_csv'
- updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
- COMMENT na tabeli: docelowo zasilana pomostem Subiekt->czat (decyzja 173); teraz jednorazowy seed z CSV (T-035). net_value_12m wrazliwe — panel pokazuje tylko wskaznik wzgledny (38b).
Wzorzec jak 011: header ADR/TASK, IF NOT EXISTS, COMMENT-y.

### A2 — seed (BRAMKA: pokaz wynik, NIE laduj bez akceptacji)
- Odtworz parsing z T-031 dla CALEGO CSV (nie tylko automaty/komputery — tabela popularnosci jest ogolna, przyda sie pod przyszle kategorie). Mapuj SKU->product_id, agreguj qty + net_value per product_id.
- Pokaz: ile pozycji CSV, ile zmapowanych na product_id, ile unikalnych product_id, ile niezmapowanych (te pomijamy w tej tabeli). Pokaz TOP 20 product_id wg qty jako kontrola zdrowia.
- STOP. Po akceptacji Karola: UPSERT do tabeli. Pokaz liczbe wierszy po seedzie.

## CZESC B — endpoint panelu + sekcja read-only

### B0 — rozpoznanie
- Kontrakt MysqlProductEnrichmentService::enrich(list<int>): zwraca per product_id {price, in_stock, availability, quantity, active, visible, price_before_discount?}. UWAGA: enrich NIE zwraca NAZWY produktu. Ustal skad wziac nazwe do panelu (pgvector divechat_product_embeddings? MySQL pr_product_lang?) — wybierz zrodlo, zaznacz w raporcie.
- Wzorzec endpointu admina: AdminWhoamiController (T-032, kanal serwerowy ServerHmacVerifier) + AdminEditorialPicksController (CRUD pattern). Reuzyj ServerHmacVerifier + lookup roli.

### B1 — endpoint GET /api/admin/recommendations (kanal serwerowy)
- Auth: kanal serwerowy (ServerHmacVerifier, headery X-DiveChat-Server-*), lookup roli; DOWOLNA rola (operator/admin) -> 200; brak roli -> 403; zly podpis -> 401. NIE reuzuj logiki filtrujacej z CuratedRecommendations (ta pomija niedostepne — panel ma pokazac WSZYSTKO).
- Zwraca wszystkie wpisy z divechat_curated_recommendations (active i nieaktywne), pogrupowane po category_key (+ category_label_pl), w kazdej wg priority. Per wpis: product_id, priority, rationale_pl, verified_at, active(wpisu) + live z MySQL (nazwa, price, price_before_discount, availability, active, visible) + flaga "pominiety przez bota" gdy !active||!visible||availability=unavailable (zeby pracownik widzial co bot ukrywa).
- WSKAZNIK popularnosci (38b/41a): backend liczy bucket per kategoria z qty_12m wpisow danej kategorii (np. tercyle -> malo/srednio/duzo, lub wzgledny % do max w kategorii dla paska). Zwraca TYLKO bucket/percent, NIGDY qty ani net_value. Produkt bez wiersza w popularnosci -> bucket "brak danych".

### B2 — sekcja read-only w module PS
- W AdminDivezoneChatController (lub nowa sekcja) dolozyc widok: woła GET /api/admin/recommendations kanalem serwerowym, renderuje natywnie 3 sekcje (kategorie), w kazdej wpisy wg priority: nazwa, cena (+ przed promocja jesli jest), dostepnosc, wskaznik popularnosci (pasek/etykieta — NIE liczby), znacznik "ukryty przed botem" dla pominietych. READ-ONLY (zero edycji). PHP 7.2, unikac konstrukcji wywalonych w PS 9.

### B3 — smoke
- Endpoint curl-em (podpis serwerowy): admin id=5 -> 200 z 3 kategoriami/9 wpisami; operator id=14 -> 200 (dowolna rola); id=999 -> 403; bez podpisu -> 401.
- Sprawdz: wpisy niedostepne SA w odpowiedzi z flaga (nie odfiltrowane); wskaznik popularnosci to bucket/percent, BRAK qty/net_value w JSON.
- Panel: wymaga rak Karola (LIVE PS) — przygotuj instrukcje.

## GIT
- A: git add sql/019_*.sql (+ ewentualny jednorazowy seed jako sql/019b lub inline — NIE skrypt importu reuzywalny). Commit "T-035: tabela popularnosci Subiekt + seed 12mc (jednorazowy)".
- B: git add standalone/src/Controller/<recommendations controller>.php, standalone/config/routes.php, modules/divezone_chat/... Commit "T-035: endpoint + sekcja read-only rekomendacji w panelu (wskaznik popularnosci)".
- Przy okazji (drobiazg z T-032): dodaj komentarz rozrozniajacy DIVECHAT_SECRET (klient HMAC) vs DIVECHAT_SERVER_SECRET (kanal serwerowy) w .env.example — Karol pomylil je przy konfiguracji modulu.
- Po deploy: osobny `docs:` commit (status). Handoff LOKALNY — NIE commituj.

## RAPORT
A: statystyki mapowania + TOP 20 -> STOP -> po seedzie liczba wierszy.
B: kontrakt endpointu, zrodlo nazwy produktu, wynik smoke (role + flaga niedostepnych + brak liczb sprzedazowych w JSON), instrukcja testu panelu dla Karola.
