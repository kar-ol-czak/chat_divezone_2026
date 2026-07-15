# CHAT-T-143 (backend): `available_for_order` — produkt wycofany nie wypływa niepytany

**Instancja:** backend
**ADR:** ADR-123 (`_docs/10_decyzje_projektowe.md`, commit e41f128). ADR-120 nadal
zarezerwowany przez niewdrozony CHAT-T-138 — nie nadpisywac.
**Karta Trello:** 28
**Swiat wdrozeniowy:** BACKEND `chat.divezone.pl` (WYLACZNIE). Zero zmian w module PS/newtmp2.
**Zero re-embeddingu, zero migracji PG.** `afo` czytany na zywo z MySQL.

---

## KONTEKST — przyczyna ZWERYFIKOWANA, nie diagnozuj od nowa

Bot moze dzis polecac produkty **wycofane ze sprzedazy**. **520 aktywnych produktow ma
`available_for_order = 0`**, z czego **456 ma wektor** w indeksie — wszystkie moga wyplynac
w rekomendacjach.

`afo=0` to produkt z szarym przyciskiem „Dodaj do koszyka", formularzem „Powiadom mnie kiedy
bedzie dostepny" i banerem „Produkt wycofany ze sprzedazy" (zweryfikowane na 3920: strona
200, cena widoczna, promocja -15%, ale zamowic sie nie da).

**UWAGA — NIE uzywac `visibility` jako kryterium.** Architekt probowal i sie mylil (ADR-122
nota nr 3): wyszukiwarka sklepu to **Luigi's Box**, ktora IGNORUJE `visibility` z PrestaShop.
Produkt `vis='none'` jest normalnie znajdowany przez klientow. Do tego pola **nie pokrywaja sie**:

| `afo` | `visibility` | ile |
|---|---|---|
| 0 | none | 472 |
| 0 | both | **47** ← widoczne, ale NIE do zamowienia |
| 1 | none | **11** ← ukryte, ale DA SIE zamowic |

---

## DECYZJE KAROLA — czego NIE robic

- **87a:** produkt `afo=0` **NIE wyplywa niepytany** (zero rekomendacji, list, doboru zestawow).
  Gdy klient pyta **stricte o ten produkt** → „taki produkt byl, juz go nie ma, proponuje
  zamiast tego...". Obecny w indeksie, ale niekwalifikujacy sie do rekomendacji.
- **88:** **mechanizm JUZ ISTNIEJE — nie wymyslaj nowego.** `in_stock_only` (~89-93) to 1:1
  ta sama regula, tylko dla stanu magazynowego. Karol: „traktujemy dokladnie tak samo jak
  wyliczanie, kiedy klient pyta, czy macie na stanie produkt X".
- **91a:** **twardy filtr, nie flaga.** `exploratory` → `afo=0` wypada calkowicie.
  `navigational` + `exact_keywords` → wchodzi **z flaga**.
  ODRZUCONE: „zawsze w wynikach, model decyduje" (regula 87a jest twarda — model predzej czy
  pozniej go poleci); „reuzyc `in_stock_only`" (sklejenie dwoch roznych stanow: „chwilowo nie
  ma, bedzie" vs „wycofany na zawsze" — bot musi mowic o nich INACZEJ).
- **92a:** **Editorial Picks (ADR-058) NIE bypassuja `afo`.** Bypass ma sens dla stanu
  magazynowego, nie dla wycofania. Flagowy produkt, ktorego nie da sie kupic, to gorszy
  przypadek niz brak stanu.
- **NIE ruszac `visibility`** nigdzie w kodzie.
- **NIE deployowac `config/tools.php`** — dryf repo≠prod (niewdrozony T-129 → fatal 500).

---

## KROK 0 — pull + czytaj

```
git pull origin main
```
Przeczytaj **ADR-123** w `_docs/10_decyzje_projektowe.md` (commit e41f128) — cala regula
+ architektura + odrzucone warianty.
Otworz (nie greppuj):
- `standalone/src/Shop/MysqlProductEnrichmentService.php` — SELECT ~75-104 (`out_of_stock`,
  budowa `availability`), `$entry` ~131-152, docblock `@return` ~36-48
- `standalone/src/Tools/ProductSearch.php` — schema `in_stock_only` ~89-93, `buildFilters`
  ~454-460 (komentarz: „in_stock_only filtrowane post-hoc z real-time MySQL"), aplikacja
  filtra **~312** (glowny tor) i **~848** (`searchByPrice`), bypass Editorial Picks ~845
- `standalone/src/Chat/SystemPrompt.php` — sekcja o dostepnosci

## KROK 1 — `enrich()`: dolozyc `available_for_order`

`available_for_order` **nie istnieje nigdzie w kodzie** (sprawdzone: zero trafien w
`standalone/src/` i `embeddings/`). Dolozyc od zera.

W SELECT (~75-104) dolozyc kolumne `ps.available_for_order` — **ta sama tabela
`pr_product_shop`, z ktorej juz czytamy `out_of_stock`**. Zero dodatkowych round-tripow.

W `$entry` (~131-152) dolozyc klucz `'available_for_order' => (bool) $row[...]`.
Zaktualizowac docblock `@return`.

**WSTECZNA ZGODNOSC — twarde:** wylacznie NOWY klucz. Zero zmian w istniejacych
(`price`, `in_stock`, `availability`, `quantity`, `name`, `url`, `url_en`...).
`enrich()` ma 6 wywolujacych — zaden nie moze zauwazyc roznicy. Wzorzec: CHAT-T-139.

## KROK 2 — `ProductSearch`: filtr wzorem `in_stock_only`

Filtrowanie **post-hoc z MySQL**, jak `in_stock_only` (NIE w pgvector, NIE w `buildFilters`).

1. Schema: nowy parametr w `filters` obok `in_stock_only`, np. `include_discontinued`
   (`boolean`, DOMYSLNIE FALSE), opis wzorem istniejacego: *„Produkty wycofane ze sprzedazy
   (available_for_order=0). DOMYSLNIE FALSE — nie pokazuj. Ustaw TRUE TYLKO gdy klient pyta
   o konkretny model, ktory moze byc wycofany."*
2. Aplikacja w **obu** miejscach: ~312 (glowny tor) i ~848 (`searchByPrice`) — analogicznie
   do `$inStockOnly`, z wpisem do `$filteredOut` (`reason` = `available_for_order=0, discontinued`).
3. **Editorial Picks (~845): NIE bypassuja tego filtra** (decyzja 92a) — w odroznieniu od
   `in_stock_only`. Zaimplementuj jawnie, nie przez pominiecie.
4. Produkty `afo=0` przepuszczone przy `navigational` musza miec **flage w tool_result**
   (`available_for_order: false`), inaczej bot nie wie, ze ma powiedziec „byl, nie ma".

## KROK 3 — `CuratedRecommendations` + `PopularProducts`

Oba polecaja **niepytany** → `afo=0` ma **zawsze** wypadac, bez parametru.
Wzorzec staleness/skipped w Curated: dodac powod `discontinued` do pominietych.

## KROK 4 — SystemPrompt

Regula: produkt z `available_for_order: false` **nigdy** nie jest proponowany do zakupu ani
wymieniany w rekomendacjach. Gdy klient pyta wprost o taki produkt: powiedziec, ze **byl
w ofercie, ale zostal wycofany**, i zaproponowac alternatywe. **Nigdy** nie sugerowac zakupu
ani nie podawac linku jako oferty zakupowej.
Odroznic od braku stanu (`quantity=0`, `availability='available_to_order'`): tam bot mowi
„zamowimy", tu „juz go nie ma".

## KROK 5 — testy

`standalone/tests/` to samodzielne skrypty CLI (`php tests/.../XTest.php`), **NIE phpunit**
— w repo nie ma `vendor/bin`. Uruchom petla po `tests/**/*Test.php`.
Nowy test filtra (bez MySQL, wzor `ProductPriceTest`): `afo=0` + `exploratory` → odfiltrowany;
`afo=0` + `include_discontinued=true` → przechodzi z flaga; `afo=1` → bez zmian.

## KROK 6 — weryfikacja lokalna

`php -l` na zmienionych plikach. Cala suita ma przejsc.
**Znany stan zastany (NIE Twoja regresja):** `PricingServiceTest` 24/3 failed i `SantiSearchTest`
(fatal: konstruktor `ProductSearch` z 3 argumentami, klasa wymaga 4) — dryf sprzed tego taska.

## KROK 7 — STOP przed rsync (ADR-089)

**ZATRZYMAJ SIE. Zaraportuj i czekaj na jawne „deployuj" od Karola.**

## KROK 8 — deploy (dopiero po „deployuj")

Swiat BACKEND. backup `_deploy_bak/` → rsync `standalone/src/...` →
`~/public_html/chat.divezone.pl/src/...` (BEZ prefiksu `standalone/`) → `ea-php84 -l`
NA SERWERZE → md5 lokalnie==serwer (wypisz sumy) → smoke `/api/health`.
**BEZ `config/tools.php`.**

## KROK 9 — git

`git status` przed commitem. `git add` PER SCIEZKA (nigdy `git add .`):
```
git add standalone/src/Shop/MysqlProductEnrichmentService.php
git add standalone/src/Tools/ProductSearch.php
git add standalone/src/Tools/CuratedRecommendations.php
git add standalone/src/Tools/PopularProducts.php
git add standalone/src/Chat/SystemPrompt.php
git add standalone/tests/...
git add _instances/backend/tasks/CHAT-T-143_backend_available-for-order.md
```
**NIE commituj:** `standalone/config/routes.php` (cudza zmiana, CHAT-T-090), `_backups/`,
`_diag_local/`.
Commit: `CHAT-T-143 backend: filtr available_for_order — produkty wycofane nie w rekomendacjach (ADR-123)`
`git push origin main`. Po deployu osobny commit `docs:`.

## KROK 10 — status + raport

`_docs/21_STATUS_PROJEKTU.md` (najnowsze na gorze). Raport: md5, `php -l`, testy, smoke,
test PROD.

---

## KRYTERIA AKCEPTACJI

1. **Test PROD:** zapytanie ogolne („szukam torby na sprzet nurkowy") → **zaden produkt
   `afo=0` nie pojawia sie w odpowiedzi**. Sprawdz na 3920 (Torba MARES Cruise Backpack
   Mesh Deluxe, `afo=0`).
2. **Test PROD:** zapytanie wprost („macie torbe Mares Cruise Backpack Mesh Deluxe?") →
   bot mowi, ze produkt **byl, ale zostal wycofany**, i proponuje alternatywe. NIE proponuje
   zakupu.
3. `get_curated_recommendations` i `get_popular_products` **nigdy** nie zwracaja `afo=0`.
4. Editorial Picks z `afo=0` **nie** sa polecane (92a).
5. **Regresja:** `search_products` dla `afo=1` zwraca DOKLADNIE to co przed zmiana.
   Zaden istniejacy klucz `enrich()` nie zmienil nazwy, typu ani wartosci.
6. md5 prod == local. Zero zmian w `visibility`.
7. Rozmowy testowe oznaczyc `[test CHAT-T-143, nie klient]` w `note`, BEZ `verdict`,
   `updated_by=NULL` (decyzja 41b).

## POZA ZAKRESEM

- `visibility` — NIE jest kryterium (ADR-122 nota nr 3).
- Przywrocenie wektora 7602 — robi CHAT-T-142.
- 858 produktow usunietych z bazy — karta 29, odlozone.
- Guard w `ProductDetails` ~135 (ADR-121, znany dlug).
- Jakiekolwiek zmiany w newtmp2 / module PS / embeddingach.

---

## Wynik (2026-07-15, DONE, DEPLOYED)

**Status: DONE + DEPLOYED na chat.divezone.pl.** Wszystkie kryteria akceptacji spelnione.

**Zakres wykonany (KROK 1-4 + korekta 93a + domkniecie 91a):**
1. `enrich()`: kolumna `ps.available_for_order` w SELECT + klucz `'available_for_order' => (bool)` + docblock. Wylacznie NOWY klucz, 6 wywolujacych bez zmian.
2. `ProductSearch`: parametr `filters.include_discontinued` (DEFAULT FALSE); filtr post-hoc z MySQL w OBU torach (mergeRRF + searchByPrice), `$filteredOut` reason `available_for_order=0, discontinued`; Editorial Picks NIE bypassuja (92a, jawnie); afo=0 przepuszczony niesie flage `available_for_order: false` (klucz TYLKO dla afo=0). Wspolny predykat: `ProductSearch::isDiscontinued()` (public static, brak danych/klucza = dostepny).
3. **Odstepstwo od litery taska — decyzja 91a domknieta DETERMINISTYCZNIE:** test PROD v1 wykazal, ze model NIE ustawia `include_discontinued=true` przy pytaniu wprost (bot: "nie znalazlem... sprawdzimy czy mozemy sprowadzic"). Dodano `ProductSearch::shouldIncludeDiscontinued()`: navigational + exact_keywords => auto-include (dokladnie brzmienie 91a: "navigational + exact_keywords -> wchodzi z flaga"), jawny parametr nadal dziala. Wzorzec deterministyczny jak fallbacki T-017/T-020.
4. **Korekta 93a (nota do ADR-123):** filtr `visibility` USUNIETY z ProductSearch (searchByPrice + mergeRRF); kryterium post-hoc: active + afo. Pole `visible` w `enrich()` zostaje. Skutek uboczny potwierdzony: 11 produktow vis='none'+afo=1 wraca do wynikow (poprawne).
5. `CuratedRecommendations` + `PopularProducts`: afo=0 wypada ZAWSZE, reason `discontinued` (Curated dodatkowo error_log).
6. `SystemPrompt`: blok PRODUKT WYCOFANY ZE SPRZEDAZY (byl/wycofany + alternatywa; odroznienie od quantity=0; zakaz zakupu i surowej flagi).

**Testy:** nowy `standalone/tests/Tools/AvailableForOrderFilterTest.php` 14/14 (czysta logika, bez baz). Pelna suita bez regresji; zastane: PricingServiceTest 24/3 failed, SantiSearchTest fatal (konstruktor) — sprzed taska.

**Deploy (swiat BACKEND):** backup `_deploy_bak/20260715_202636_CHAT-T-143` (5 plikow pre-task) + `_deploy_bak/20260715_203219_CHAT-T-143_v1` (ProductSearch przed domknieciem 91a). rsync 5 plikow src/, `ea-php84 -l` 5/5 OK na serwerze, **md5 local==prod 5/5**:
- `ef2942051f01fab742863241c2067c21` Shop/MysqlProductEnrichmentService.php
- `6042f60dbca97255722a69007a1b8057` Tools/ProductSearch.php (final, po 91a)
- `24c74f7bf9843ac5f70ac121f1e067c0` Tools/CuratedRecommendations.php
- `cb812218a7e54f0d8a0a2c63bd76ed64` Tools/PopularProducts.php
- `46c9c8ce876c101238d48729d3b8c116` Chat/SystemPrompt.php
Smoke `/api/health`: 200, postgres+mysql true. BEZ `config/tools.php`, BEZ `routes.php`.

**Test PROD (kryteria 1-2): PASS.**
- "szukam torby na sprzet nurkowy" -> 3920 NIE pojawia sie (w debug: odfiltrowany; inne wycofane z reason `available_for_order=0, discontinued`).
- "macie torbe Mares Cruise Backpack Mesh Deluxe?" -> "zostala wycofana ze sprzedazy i nie mozna jej juz zamowic" + 2 alternatywy Mares, BEZ propozycji zakupu, BEZ surowej flagi. 3920 w tool_result z flaga `available_for_order: false`, debug `include_discontinued: true` (auto 91a).
Rozmowy 692, 693, 694, 695 oznaczone `[test CHAT-T-143, nie klient]`, verdict NULL, updated_by NULL (41b).

**Commit:** patrz git log (`CHAT-T-143 backend: filtr available_for_order ...`).
