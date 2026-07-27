# CHAT-T-139 (backend): `url` + `name` w get_curated_recommendations

**Instancja:** backend
**ADR:** ADR-121 (ostatni ISTNIEJACY w pliku decyzji = ADR-119; ADR-120 zarezerwowany przez
niewdrozony CHAT-T-138 — nie nadpisywac, luka domknie sie przy wdrozeniu T-138)
**Karta Trello:** 18 `6a569425617902a01f23e895` (Gole linki https://divezone.pl zamiast URL produktu)
**Swiat wdrozeniowy:** BACKEND `chat.divezone.pl` (WYLACZNIE). Zero zmian w module PS / newtmp2.

---

## KONTEKST — przyczyna ZWERYFIKOWANA w kodzie, nie diagnozuj od nowa

Bot w doborze ZESTAWOW podaje gole `https://divezone.pl` zamiast URL produktu.
Skala: 4 rozmowy / 30 dni (conv 668, 657, 626, 598) vs 130 z pelnym `.html`.
Wszystkie 4 to sciezka `get_curated_recommendations`.

**Przyczyna (zrodlo: `standalone/src/Tools/CuratedRecommendations.php`, tablica `$available[]`
~146-157):** narzedzie zwraca botowi TYLKO `product_id, priority, rationale_pl, price,
price_eur, price_before_discount(+_eur), availability, verified_at`.
**BRAK `name` i BRAK `url`.** Bot dostaje samo id + cene → link WYMYSLA (gola domena) = fabrykacja.

**`name` jest rownie wazne jak `url`, nie kosmetyka.** Dzis bot bierze nazwe z wlasnego
kontekstu (wczesniejszy `search_products` w tej samej rozmowie). Gdy curated bedzie
PIERWSZYM narzedziem w rozmowie — bot moze nazwe rowniez zmyslic. Nie zaobserwowane w
rozmowach, ale mechanizm identyczny jak przy linku (ta sama klasa bledu: fabrykacja z braku
danych). Naprawiamy oba naraz.

**KOREKTA wzgledem starszych notatek (zweryfikowane przez otwarcie pliku):**
`MysqlProductEnrichmentService::enrich()` **NIE MA** dzis danych PL.
SELECT (`MysqlProductEnrichmentService.php` ~75-104) joinuje WYLACZNIE:
`LEFT JOIN pr_product_lang plen ON ... AND plen.id_lang = 3 AND plen.id_shop = 1`
i zwraca tylko `plen.link_rewrite AS link_rewrite_en` → `url_en` (~126-129).
**Brak `name`, brak PL `link_rewrite`.** Trzeba je DOLOZYC, nie "sprawdzic czy sa".

`PopularProducts` ma `name`/`url` poprawnie, bo bierze je z **wlasnego** zapytania
(`fetchBestsellers`, `pl.id_lang = 1` ~314), NIE z enrichment.

---

## DECYZJA (Karol 52a + 52a-i) — czego NIE robic

- **52a:** rozszerzyc `enrich()` o JOIN `id_lang=1` i dodac klucze `name`, `url`.
  Uzasadnienie: enrichment JUZ joinuje `pr_product_lang` i JUZ buduje URL (`url_en`) — to,
  ze zwraca `url_en` a nie `url` (PL), wyglada na przeoczenie, nie decyzje projektowa.
  Wariant "wlasne zapytanie w Curated" ODRZUCONY: dolozylby trzecia kopie budowania URL
  (ProductDetails, PopularProducts, Curated) — a karta 18 istnieje wlasnie dlatego, ze
  budowanie URL jest rozproszone. Kazda kopia to miejsce na kolejny wariant tego samego buga.
  Dodatkowo: drugi round-trip do MySQL w sciezce, ktora ADR-117 wlasnie odchudzal.
- **52a-i:** `PopularProducts` **NIE TYKAC**. Dziala poprawnie (~285, guard obecny).
  Przejscie wywolujacych na wspolne `name`/`url` = OSOBNA karta refaktoru. Nie mieszamy
  fixu z przebudowa (rosnie powierzchnia regresji).
- **`ProductDetails` (~135) NIE NAPRAWIAC w tym tasku.** Nie ma guarda na pusty slug
  (zbudowalby `divezone.pl/.html`). Znany dlug, odnotowany w ADR-121.
- **NIE deployowac `config/tools.php`** — dryf repo≠prod (niewdrozony T-129 → fatal 500).
  Ten task NIE wymaga zmian w tools.php (narzedzie juz zarejestrowane).

---

## KROK 0 — pull + czytaj

```
git pull origin main
```
Przeczytaj (nie zakladaj, otworz):
- `standalone/src/Shop/MysqlProductEnrichmentService.php` — SELECT ~75-104, budowa `$urlEn` ~126-129, docblock kontraktu `@return` ~36-48
- `standalone/src/Tools/CuratedRecommendations.php` — `$available[]` ~146-157
- `standalone/src/Tools/PopularProducts.php` ~285 — WZOR guarda na pusty slug
- `_docs/10_decyzje_projektowe.md` — ADR-121 (dopisany przez architekta)

---

## KROK 1 — `MysqlProductEnrichmentService::enrich()`: dolozyc PL name + url

W SELECT (~75-104) dolozyc DRUGI join do `pr_product_lang` dla PL, obok istniejacego EN:

```sql
LEFT JOIN pr_product_lang plpl ON p.id_product = plpl.id_product
    AND plpl.id_lang = 1 AND plpl.id_shop = 1
```
i do listy kolumn: `plpl.name AS name_pl`, `plpl.link_rewrite AS link_rewrite_pl`.

W budowie `$entry` (~131-143), obok istniejacego `url_en`, dolozyc:

- `'name'` = `$row['name_pl']` rzutowane na string; pusty/NULL → `null`.
- `'url'` = **guard OBOWIAZKOWY, wzor 1:1 z `PopularProducts` ~285:**
  slug niepusty → `"https://divezone.pl/{$slug}.html"`, w przeciwnym razie **`null`**.
  **NIGDY gola domena.** Bot ma nie dostac linku wcale, zamiast dostac zly link —
  to jest sedno karty 18.

Zaktualizowac docblock `@return` (~36-48) o `name: string|null`, `url: string|null`.

**WSTECZNA ZGODNOSC — twarde:** wylacznie NOWE klucze. Zero zmian w istniejacych
(`price`, `price_eur`, `in_stock`, `availability`, `quantity`, `active`, `visible`,
`url_en`, `price_before_discount(+_eur)`). Szesciu wywolujacych `enrich()`
(ProductSearch ×2, CuratedRecommendations, PopularProducts, ProductDetails,
AdminRecommendationsController) nie moze zauwazyc roznicy.

## KROK 2 — `CuratedRecommendations`: przekazac do bota

W `$available[]` (~146-157) dolozyc `'name' => $data['name'] ?? null` i
`'url' => $data['url'] ?? null`. Kolejnosc kluczy jak w PopularProducts
(product_id, name, ... price ..., url) dla spojnosci kontraktu.
Reszta metody bez zmian — staleness/skipped/statusy nietkniete.

## KROK 3 — test jednostkowy budowy URL

Nowy `standalone/tests/Shop/ProductUrlTest.php` (wzor: `standalone/tests/Tools/PopularProductsTest.php`,
`standalone/tests/Shop/ProductPriceTest.php` — bez MySQL, czysta logika).
Jesli budowa URL jest dzis inline w petli — wydzielic **prywatna statyczna** metode
`buildProductUrl(?string $slug): ?string` (wzor: `computeBruttoPrice` jest `public static`
wlasnie po to, by byla testowalna bez MySQL) i testowac ja.
Asercje minimum: slug normalny → pelny `.html`; slug `''` → `null`; slug `null` → `null`.
**Zaden przypadek nie moze zwrocic `https://divezone.pl` ani `https://divezone.pl/.html`.**

## KROK 4 — weryfikacja lokalna

```
cd standalone && ea-php84 vendor/bin/phpunit tests/
ea-php84 -l src/Shop/MysqlProductEnrichmentService.php
ea-php84 -l src/Tools/CuratedRecommendations.php
```
Cala suita ma przejsc (regresja pozostalych narzedzi — patrz kryteria akceptacji).

## KROK 5 — STOP przed rsync (ADR-089)

**ZATRZYMAJ SIE. Zaraportuj i czekaj na jawne "deployuj" od Karola.**
Bez tego slowa NIE dotykasz produkcji.

## KROK 6 — deploy (dopiero po "deployuj")

Swiat BACKEND. Pliki: `src/Shop/MysqlProductEnrichmentService.php`,
`src/Tools/CuratedRecommendations.php`. **BEZ `config/tools.php`.**
1. backup do `_deploy_bak/`
2. rsync `standalone/src/...` → `~/public_html/chat.divezone.pl/src/...` (BEZ prefiksu `standalone/`)
3. `ea-php84 -l` na obu plikach NA SERWERZE
4. md5 lokalnie == md5 na serwerze (oba pliki, wypisz sumy)
5. smoke `/api/health`

## KROK 7 — git

`git status` przed commitem. `git add` PER SCIEZKA (nigdy `git add .`):
```
git add standalone/src/Shop/MysqlProductEnrichmentService.php
git add standalone/src/Tools/CuratedRecommendations.php
git add standalone/tests/Shop/ProductUrlTest.php
```
Commit wg konwencji z `git log`:
`CHAT-T-139 backend: url + name w get_curated_recommendations (ADR-121)`
`git push origin main`. Po deployu OSOBNY commit `docs:` ze statusem.

## KROK 8 — status update + raport

Aktualizacja `_docs/21_STATUS_PROJEKTU.md` (najnowsze na gorze).
Raport: md5 lokalnie/serwer, wynik `php -l`, wynik testow, wynik smoke, wynik testu PROD (nizej).

---

## KRYTERIA AKCEPTACJI

1. **Test PROD na realnym przypadku z karty 18 (obowiazkowy):** dobor budzetowy automatu —
   sciezka jak conv 668/657 (np. "automat do 2000 zl", trafiajacy w `get_curated_recommendations`).
   Bot ma podac **pelny URL produktu konczacy sie `.html`**, NIE `https://divezone.pl`.
   Rozmowe testowa oznaczyc w `note` markerem `[test CHAT-T-139, nie klient]`,
   **BEZ nadawania verdict** (ocene robi Karol), `updated_by=NULL` (decyzja 41b).
2. `get_curated_recommendations` zwraca `name` i `url` dla kazdego dostepnego produktu.
3. Produkt z pustym/NULL `link_rewrite` PL → `url = null` (NIE gola domena, NIE `/.html`).
4. **Regresja wsteczna:** `search_products`, `get_product_details`, `get_popular_products`
   zwracaja DOKLADNIE to co przed zmiana — zaden istniejacy klucz nie zmienil nazwy,
   typu ani wartosci. Wykazac w raporcie (test/porownanie odpowiedzi przed-po).
5. Suita `phpunit` przechodzi w calosci.
6. md5 prod == md5 local dla obu wdrozonych plikow.

## POZA ZAKRESEM (nie robic)

- Guard w `ProductDetails` ~135 (dlug, ADR-121).
- Refaktor `PopularProducts`/`ProductSearch` na wspolne `name`/`url` (osobna karta).
- Zmiany w SystemPrompt — narzedzie ma ODDAC `url`, prompt juz opisuje `url`/`url_en`
  (`SystemPrompt.php` ~137-138). Gdyby test PROD pokazal, ze bot mimo poprawnego `url`
  dalej gubi link — zglosic, to osobna diagnoza.
- Jakiekolwiek zmiany w newtmp2 / module PS.

---

## WYNIK (CC, 2026-07-15) — DONE, wdrozone na PROD

**Zmiany:**
- `MysqlProductEnrichmentService::enrich()`: drugi `LEFT JOIN pr_product_lang plpl` (id_lang=1, id_shop=1),
  kolumny `name_pl` + `link_rewrite_pl`, nowe klucze `name` i `url` w zwracanej tablicy. Docblock zaktualizowany.
  Budowa URL wydzielona do `buildProductUrl(?string $slug): ?string` — **public static** (nie private, jak
  w jednym miejscu taska): wzor `computeBruttoPrice` jest public static wlasnie dla testowalnosci bez MySQL,
  private nie daloby sie testowac bez refleksji.
- `CuratedRecommendations::execute()`: `$available[]` dostaje `name` (po product_id) i `url` (po availability).
  Staleness/skipped/statusy nietkniete.
- Nowy test `standalone/tests/Shop/ProductUrlTest.php` — 6/6 pass (slug → .html; ''/null → null; nigdy gola
  domena ani /.html).

**Weryfikacja lokalna:** w repo NIE MA phpunit (brak vendor/bin; testy = samodzielne skrypty PHP) — suita
uruchomiona petla po tests/**/*Test.php: 17/19 plikow pass. 2 fail to STAN ZASTANY zweryfikowany stashem na
czystym HEAD (PricingServiceTest 24/3, SantiSearchTest — test wola konstruktor ProductSearch z 3 argumentami,
klasa wymaga 4). Regresja wsteczna: diff wylacznie dodaje (zero modyfikacji istniejacych kluczy/linii),
PopularProductsTest 20/20 i ProductPriceTest 10/10 bez zmian.

**Deploy PROD (swiat BACKEND):** backup `_deploy_bak/20260715_120534/`; przed rsync md5 prod==HEAD na obu
plikach (zero dryfu); rsync obu src; `ea-php84 -l` na serwerze OK; md5 local==prod:
- `MysqlProductEnrichmentService.php` = `ceaa1f3324eda349d74e84f3a39a6927`
- `CuratedRecommendations.php` = `924293942db32ae7417ebfe0648147ee`
Smoke `/api/health` = 200, postgres+mysql true. `config/tools.php` NIE dotykany.

**Test PROD (kryterium 1) — PASS:** "Szukam automatu oddechowego do 2000 zl, co polecacie?" →
tools_used zawiera `get_curated_recommendations`, bot podal pelny URL
`https://divezone.pl/apeks-atx40-ds4-octopus-atx40.html` (zero golych domen). Rozmowa conv **675**
(session 79bc976a-b542-4239-917e-a798760ef4d6) oznaczona w `divechat_conversation_review`:
note=`[test CHAT-T-139, nie klient]`, verdict=NULL, updated_by=NULL, status=do_weryfikacji (ocena: Karol).
