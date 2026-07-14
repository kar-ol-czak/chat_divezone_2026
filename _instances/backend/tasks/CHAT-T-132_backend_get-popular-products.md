# CHAT-T-132 BACKEND — narzedzie get_popular_products (dynamiczna popularnosc)

**Instancja:** backend
**Swiat:** BACKEND standalone (chat.divezone.pl). Nowe narzedzie + rejestracja w
ToolRegistry + wpiecie w SystemPrompt. ZERO zmian w module PS.
**ADR:** ADR-115 (po ADR-114 z CHAT-T-131; sprawdz ostatni numer w pliku przed zapisem).
**Karta Trello:** utworz "Chat - Dynamiczna rekomendacja popularnych (get_popular_products)"
albo powiaz z istniejaca "Porownania/dobor". Na start "W trakcie".

## Cel

Bot ma polecac produkty na podstawie REALNEJ, BIEZACEJ sprzedazy + zywej dostepnosci,
bez recznych list i bez recznego importu. Zastepuje pomysl statycznego seeda curated
dla pletw (odrzucony — "stale listy rozjezdzaja sie cicho", zasada projektu).

Przyklad uzycia przez bota (narracja bota, nie narzedzia):
"Najpopularniejsze pletwy paskowe rekreacyjne do 600 zl to X, Y, Z. Warto tez zwrocic
uwage na nowosc w tej kategorii: W."

## Decyzje Karola (zweryfikowane na danych)

- **19a — zrodlo: PrestaShop na zywo.** Popularnosc liczona z `pr_orders` +
  `pr_order_detail` (MySQL read-only, ten sam dostep co enrichment). NIE z
  `divechat_product_sales_popularity` (to martwy import CSV Subiekta, zamrozony na
  2026-06-01). Sprzedaz online wystarcza; Subiekt jako drugie zrodlo dopiero gdyby
  sprzedaz stacjonarna okazala sie istotna (przyszlosc, nie teraz).
- **20a — na zywo, bez materializacji.** Zmierzony koszt zapytania na PROD: ~9 ms
  (kategoria 473, 12 mcy, z JOIN + GROUP BY). Materializacja/cron NIE oplaca sie —
  dokladalaby ruchoma czesc, ktora cicho sie rozjezdza. Liczymy przy wywolaniu.
- **Okno domyslne: 6 miesiecy** (nie 12) — lepiej oddaje biezacy popyt (sezonowosc).
  Parametr z mozliwoscia zmiany.
- **17b — klucze kategorii semantyczne.** Narzedzie przyjmuje category_key mapowany
  wewnatrz na id_category PrestaShop. Start (mapa w kodzie/tabeli):
  `fins_recreational` → 473 (Pletwy Paskowe na Buta),
  `fins_jet` → 415 (Pletwy Gumowe JET),
  `fins_snorkel` → 472 (Pletwy Kaloszowe na Stope).
  Architektura OGOLNA (18a) — dokladanie kolejnych kategorii = jeden wpis w mapie,
  bez przepisywania narzedzia. Bot wybiera klucz z enum, NIE zgaduje kategorii z nazwy
  (to zamyka pomylke JET vs paskowe, conv 605/606).

## Zimny start nowego produktu (21b + 22a)

Ranking czysto po sprzedazy krzywdzi nowosci (np. nowy komputer Suunto ma 0 historii).
Rozwiazanie zweryfikowane na danych (kat. 473 najswiezszy produkt: Tecline RecTec,
dodany 2026-04-23, tylko 7 szt./6mcy — wypadlby z rankingu):

- **21b — okno laski.** Produkt z `pr_product.date_add` w ostatnich **90 dniach**,
  aktywny i dostepny, moze wejsc do rekomendacji MIMO niskiej sprzedazy, oznaczony
  jako nowosc.
- **22a — dwie sekcje w wyniku.** Narzedzie zwraca ODDZIELNIE:
  - `bestsellers`: top wg sprzedazy w oknie (6 mcy), dostepne;
  - `new_arrivals`: dodane < 90 dni, dostepne, niezaleznie od sprzedazy.
  Bot sam sklada narracje ("najpopularniejsze to X, z nowosci warto Y"). NIE mieszaj
  w jedna liste — nowosc z 1 sztuka nie moze udawac bestsellera.

Zalozenie (odnotuj w ADR): "nowosc" = `pr_product.date_add` < 90 dni. Pole zweryfikowane
jako realne i wypelnione. Ryzyko: re-dodanie/migracja produktu moze zafalszowac date_add
— na dzis wiarygodne.

## Kontrakt narzedzia

Wzoruj sie na `standalone/src/Tools/CuratedRecommendations.php` (ten sam ToolInterface,
konstruktor z PostgresConnection + MysqlProductEnrichmentService, enum kategorii w
schema, dostepnosc real-time z enrich, twarda zasada active=false → pomijamy).

- `getName()`: `get_popular_products`
- parametry (schema):
  - `category` (string, enum = klucze z mapy: fins_recreational/fins_jet/fins_snorkel; wymagany)
  - `max_price` (number, opcjonalny) — gorna granica budzetu klienta (brutto)
  - `months` (int, opcjonalny, default 6) — okno sprzedazy
- zwraca (obie listy przefiltrowane przez enrich → dostepne, z cena/linkiem brutto):
  - `bestsellers`: [{product_id, name, brand, price, url, sold_qty}] posort. malejaco po sold_qty
  - `new_arrivals`: [{product_id, name, brand, price, url, added_date}] dodane < 90 dni
  - limit np. 3-5 na sekcje
- opis narzedzia (getDescription): jasno rozgranicz od search_products (konkretny model)
  i get_curated_recommendations (reczny osad ekspercki). To narzedzie = "co sie
  NAJCZESCIEJ KUPUJE w kategorii + nowosci", gdy klient pyta ogolnie "jakie pletwy
  polecacie", "co najlepsze", "najpopularniejsze".

## Zapytanie SQL (MySQL, na zywo)

Bestsellery (wzor, zweryfikowany ~9 ms na PROD):
```sql
SELECT od.product_id, SUM(od.product_quantity) AS sold_qty
FROM pr_order_detail od
JOIN pr_orders o ON o.id_order = od.id_order
  AND o.valid = 1
  AND o.date_add >= DATE_SUB(NOW(), INTERVAL :months MONTH)
JOIN pr_category_product cp ON cp.id_product = od.product_id
  AND cp.id_category = :id_category
JOIN pr_product_shop ps ON ps.id_product = od.product_id
  AND ps.id_shop = 1 AND ps.active = 1
GROUP BY od.product_id
ORDER BY sold_qty DESC
LIMIT 15;   -- nadmiar, bo enrich odetnie niedostepne
```
Nowosci: analogicznie, ale bez JOIN sprzedazy — `pr_product.date_add >= DATE_SUB(NOW(),
INTERVAL 90 DAY)`, active=1, kategoria. Sortuj po date_add DESC.
Po SQL: przepusc oba zestawy przez `enrich()` (cena brutto + dostepnosc); wywal
niedostepne; utnij do limitu. Mapa category_key→id_category w kodzie narzedzia
(albo lekka tabela `divechat_popular_categories` gdyby zespol mial dokladac klucze
bez deployu — do decyzji CC, prostsze na start = staly array w klasie).

## SystemPrompt

Dodaj krotka regule: kiedy uzywac get_popular_products (pytania ogolne "co polecacie/
najpopularniejsze pletwy") vs search_products (konkretny model) vs
get_curated_recommendations (ekspercki dobor). Przyklad narracji z dwiema sekcjami
(bestsellery + nowosci). Rozroznienie JET/paskowe/kaloszowe: bot wybiera category_key,
nie zgaduje.

## Kryteria akceptacji
1. `get_popular_products(fins_recreational)` zwraca bestsellery ~ Mares Avanti Quattro+,
   Scubapro GO Sport, Tusa Liberator (zweryfikowane top w 473), tylko DOSTEPNE.
2. `fins_recreational` z `max_price=600` odcina drozsze niz 600 zl brutto.
3. new_arrivals zawiera produkty < 90 dni (jesli sa dostepne), nawet przy niskiej sprzedazy.
4. Nieprawidlowy/niedostepny produkt NIE trafia do wyniku (enrich odsiewa active=false).
5. `php -l` ea-php84 clean; test jednostkowy mapowania category_key.

## Deploy (ADR-089 — STOP przed rsync, jawne "deployuj")
Backend: rsync nowy plik Tools/PopularProducts.php + zmieniony ToolRegistry.php +
SystemPrompt.php → chat.divezone.pl/src/... + backup + md5 + php -l + smoke /api/health.
Test PROD: `get_popular_products(fins_recreational)` przez realny czat/endpoint.

## Git
`git add` per sciezka (nowy Tool + ToolRegistry + SystemPrompt + ADR); commit
`CHAT-T-132 backend: narzedzie get_popular_products — dynamiczna popularnosc z PrestaShop (ADR-115)`;
push. Po deployu osobny `docs:` commit (status + karta Trello).

## Domkniecie
Po zweryfikowanym deployu: karta → "Zrobione"; rozmowy 605/606 (dobor pletw, zla marka)
→ problem_rozwiazany jesli narzedzie je adresuje, wg procedury 42.
