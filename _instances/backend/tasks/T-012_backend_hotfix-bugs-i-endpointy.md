# T-012: Backend hotfix — 3 bugi (HTTP 403, boost-vs-filters, prompt patch v6) + 3 endpointy follow-up

**Instancja:** backend
**Powiązane:** smoke T-011 (15.05), ADR-058 (boost hybryda), T-008/T-011 follow-up
**Priorytet:** P0 (bugi blokują core Editorial Picks UX) + P1 (endpointy odblokowują T-013 frontend polish)
**Czas estymowany:** ~4-5h CC

## Cel

Jeden monolitowy task domykający Editorial Picks na backend:

**Bugi (P0):**
1. HTTP 403 przy PUT `/api/admin/editorial-picks/{id}` (edit modal w UI)
2. Boost vs filters logic (ADR-058 hybryda: respect price_max, ignore in_stock_only)
3. Prompt Patch v6: NIE dopytuj o doprecyzowanie gdy klient już to powiedział

**Endpointy follow-up (P1, odblokowują T-013):**
4. `GET /api/admin/editorial-picks/pending-reviews` (banner data)
5. `GET /api/admin/products/search?q=` (autocomplete UI)
6. Whitelist `last_review_at` w `EditorialPicksService::list()` sort

## KROK 0. Read

- `_docs/10_decyzje_projektowe.md` sekcja ADR-058 (źródło prawdy dla #2)
- `standalone/src/Tools/ProductSearch.php` linie 340-470 (RRF + enrichWithMySQLData — gdzie wpiąć force_include)
- `standalone/src/Editorial/EditorialPicksService.php` (sort whitelist + add metoda dla pending-reviews)
- `standalone/src/Controller/AdminEditorialPicksController.php` (gdzie wpiąć 2 nowe endpointy)
- `standalone/src/Chat/SystemPrompt.php` sekcja PYTANIA DOPRECYZOWUJĄCE (po patchach E/H z T-003/T-007)
- `standalone/public/admin/.htaccess` + `standalone/public/.htaccess` (kontekst dla diagnozy #1)

## KROK 1. Bug #1 — HTTP 403 przy PUT

### Diagnoza (KROK 1a, na początku)

```bash
# Test PUT z curl bezpośrednio na prod:
curl -X PUT -u admin:HASLO https://chat.divezone.pl/api/admin/editorial-picks/9 \
  -H "Content-Type: application/json" \
  -d '{"boost_factor": 2.0}' \
  -v 2>&1 | grep -E "^< (HTTP|Server)"

# Test z innym method (POST z body PUT):
curl -X POST -u admin:HASLO https://chat.divezone.pl/api/admin/editorial-picks/9 \
  -H "Content-Type: application/json" \
  -H "X-HTTP-Method-Override: PUT" \
  -d '{"boost_factor": 2.0}' \
  -v 2>&1 | grep -E "^< (HTTP|Server)"
```

Możliwe wyniki:
- a) PUT zwraca 403 z `Server: Apache` — Apache `.htaccess` (LimitExcept) lub cPanel ModSecurity blokuje PUT
- b) PUT zwraca 403 z `Server: cloudflare` lub innym proxy — WAF
- c) X-HTTP-Method-Override (POST z header) zwraca 200 → fix przez method override
- d) Oba zwracają 403 → mod_security rule, wymaga adminstratorka cPanel

### Fix (KROK 1b, dependent na diagnozie)

**Opcja A — Apache `.htaccess` exception** (jeśli LimitExcept blokuje):

Dodaj w `standalone/public/.htaccess` ZANIM RewriteEngine:

```apache
<LimitExcept GET POST PUT DELETE PATCH OPTIONS>
    Require all denied
</LimitExcept>
```

**Opcja B — X-HTTP-Method-Override** (jeśli ModSecurity / Cloudflare blokuje PUT):

W `standalone/src/Http/Request.php` (lub odpowiedniku) dodać method override fallback:

```php
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
    $override = strtoupper(trim($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
    if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
        $method = $override;
    }
}
```

Następnie w `standalone/public/admin/js/admin.js` w `send()`:

```javascript
// Method override fallback dla shared hosting blokujących PUT/DELETE
var useOverride = method === 'PUT' || method === 'DELETE';
opts.method = useOverride ? 'POST' : method;
if (useOverride) {
    opts.headers['X-HTTP-Method-Override'] = method;
}
```

**Opcja C — kontakt z cPanel admin** (mod_security WAF) jeśli A i B nie działają. Wtedy CC pisze raport co wymaga whitelistingu i pasuje Karol dalej.

Zaaplikuj opcję wg wyniku diagnozy z KROK 1a. Default jeśli niepewne: **opcja B** (method override) — najbardziej portable, działa niezależnie od konfiguracji serwera.

### Smoke test #1

```bash
curl -X PUT -u admin:HASLO https://chat.divezone.pl/api/admin/editorial-picks/9 \
  -H "Content-Type: application/json" \
  -d '{"boost_factor": 2.1}'
# Oczekiwane: {"success":true,"id":9}
```

## KROK 2. Bug #2 — Boost vs filters logic (ADR-058)

W `standalone/src/Tools/ProductSearch.php` w `execute()` LUB `mergeRRF()` (CC wybiera lepsze miejsce):

1. Pobrać `pickedProductIds` z `EditorialPicksService::getActiveBoosts(...)` ZANIM stosujemy `in_stock_only` filter
2. Przekazać `$pickedProductIds` do `enrichWithMySQLData()` jako param `$bypassStockFilter`
3. W `enrichWithMySQLData` przy WHERE clause: jeśli produkt ma `in_stock_only=true` i jego availability != 'in_stock', nadal go zachowaj **JEŚLI** ID jest w `$pickedProductIds`

Pseudokod:

```php
// W execute(), przed enrichWithMySQLData:
$pickedIds = $this->editorialPicks?->getActiveBoosts(array_keys($scores), $category) ?? [];
$bypassStock = array_keys($pickedIds);

// Pass do enrichWithMySQLData:
$mysqlData = $this->enrichWithMySQLData($candidateIds, $bypassStock);

// W enrichWithMySQLData, po WHERE in_stock_only filter:
// SELECT ... WHERE (
//   (in_stock_only_flag = false OR availability = 'in_stock')
//   OR id IN ({bypassPlaceholders})
// )
```

**WAŻNE:** `price_max` filter pozostaje bezwarunkowy. Tylko `in_stock_only` jest bypassowane dla picków.

### Smoke test #2

Po deploy:
1. Sprawdź czy Suunto Ocean Steel Black (7318) jest dostępny (in_stock vs available_to_order):
   ```sql
   SELECT availability FROM divechat_product_embeddings WHERE ps_product_id = 7318;
   -- lub przez search_products tool z in_stock_only=false
   ```
2. Jeśli available_to_order: pick powinien teraz pojawić się w wynikach gdy `in_stock_only=true` w query
3. Jeśli cena > 3000 zł i klient daje budget 3000 zł: pick NIE powinien się pojawić (price_max szanowany)

Test scenariusze:
- Pick Suunto + query "komputer zegarkowy" + budżet 3000 zł → jeśli cena > 3000, pick NIE w wynikach (price_max szanowany ✓)
- Pick Suunto + query "komputer zegarkowy" + budżet 8000 zł → pick W wynikach z boost mnożeniem ×2.0 (najwyżej w rankingu, jeśli base score jest większy od konkurencji × tylko 2x)
- Pick Suunto + query "komputer zegarkowy" + budżet 8000 zł + in_stock_only=true + Suunto Ocean ma available_to_order → pick W wynikach (bypass stock filter ✓)

## KROK 3. Bug #3 — Prompt Patch v6 (NIE dopytuj o już doprecyzowane)

W `standalone/src/Chat/SystemPrompt.php` sekcja PYTANIA DOPRECYZOWUJĄCE (po Patch H z T-007), dodaj NOWY podblok:

```
PATCH v6 — NIE DOPYTUJ O JUŻ DOPRECYZOWANE — KRYTYCZNE:

Przed wysłaniem pytania doprecyzowującego, sprawdź czy klient JUŻ podał tę informację w pytaniu (explicit lub implicit przez słowa kluczowe). Jeśli tak, NIE pytaj.

Słowa kluczowe identyfikujące JUŻ podaną informację:

- Forma komputera nurkowego (smartwatch vs duży na butelce):
  - smartwatch-style: "zegarkowy", "smartwatch", "zegarek", "na rękę", "do codziennego"
  - large dive computer: "duży", "na butelce", "klasyczny komputer", "konsola", "na pasku do automatu"
  - NIE pytaj o ten aspekt jeśli pojawiło się którekolwiek z powyższych

- Płeć:
  - damski: "damski", "damska", "dla żony", "dla siostry", "dla córki" (bez "ojca/męża/syna" w kontekście)
  - męski: "męski", "męska", "dla męża", "dla mnie" gdy klient ID jako mężczyzna (z kontekstu wcześniejszego)
  - NIE pytaj o płeć jeśli klient jasno wskazał

- Budżet:
  - "do X zł", "około X", "X tysięcy", "do tysiąca", "budżet to X"
  - NIE pytaj o budżet jeśli klient go podał

- Doświadczenie nurkowe:
  - "początkujący", "świeżo po kursie", "OWD", "AOWD", "Rescue", "Divemaster", "instruktor", "tech"
  - NIE pytaj o stopień jeśli klient wymienia certyfikat

ZASADA: pytaj TYLKO o informacje krytyczne których faktycznie BRAKUJE. Maksymalnie 2 pytania doprecyzowujące w jednej turze.

Bug do uniknięcia (smoke T-011 15.05): klient napisał "Szukam komputera zegarkowego, jaki byś polecił". Bot zapytał "Wolisz komputer w formie zegarka (smartwatch-style, np. Garmin/Suunto) czy raczej duży, czytelny komputer nurkowy noszony głównie na butelce/pasku?". Klient JUŻ powiedział "zegarkowego" — pytanie redundantne. PRAWIDŁOWO: "Świetnie, komputer zegarkowy. Jaki budżet i czy potrzebujesz transmitera do odczytu ciśnienia z butli?" (tylko brakujące informacje).
```

### Smoke test #3

UI prod, kilka prompts:
1. "Szukam komputera zegarkowego" — bot NIE pyta smartwatch vs duży, pyta o budżet + transmiter
2. "Szukam damskiej pianki" — bot NIE pyta o płeć, pyta o budżet/temperature wody
3. "Szukam komputera za 3000 zł" — bot NIE pyta o budżet
4. "Polec automat dla początkującego po OWD" — bot NIE pyta o doświadczenie
5. Regression: "Szukam suchego skafandra" — bot NADAL pyta o płeć (klient nie podał)

## KROK 4. Endpoint #4 — GET /api/admin/editorial-picks/pending-reviews

W `EditorialPicksService.php` dodaj metodę:

```php
/**
 * Liczba picków wymagających uwagi:
 * - expired_this_week: aktywne ale expires_at minął w ostatnich 7 dniach
 * - long_unreviewed: bezterminowe (expires_at IS NULL) bez review > 30 dni
 *
 * @return array{expired_this_week: int, long_unreviewed: int, total: int}
 */
public function pendingReviews(): array
```

W `AdminEditorialPicksController.php` dodaj metodę `pendingReviews(Request $request)` zwracającą JSON.

W `routes.php`:

```php
$router->get('/api/admin/editorial-picks/pending-reviews', $editorialPicksController->pendingReviews(...));
```

UWAGA: route z parametrem dynamicznym `{id}` w PUT może kolidować z statycznym `/pending-reviews`. Routing musi sprawdzać statyczne przed parametrycznym, lub dać `pending-reviews` w hierarchii powyżej `{id}` — sprawdź matchPath.

### Smoke test #4

```bash
curl -u admin:HASLO https://chat.divezone.pl/api/admin/editorial-picks/pending-reviews
# Oczekiwane: {"expired_this_week":0,"long_unreviewed":0,"total":0} (lub liczby)
```

Po fix banner w UI zacznie odsłaniać się gdy total > 0.

## KROK 5. Endpoint #5 — GET /api/admin/products/search?q=

W `AdminEditorialPicksController.php` LUB osobnym `AdminProductsController.php`:

Endpoint przyjmuje `?q=` (min 2 znaki) i zwraca top 20 produktów matching nazwa LUB id_product LUB barcode w MySQL PrestaShop.

Query (MySQL):

```sql
SELECT
    p.id_product,
    pl.name AS product_name,
    p.price,
    sa.quantity AS stock_qty
FROM pr_product p
JOIN pr_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
LEFT JOIN pr_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1 AND ps.active = 1
WHERE (pl.name LIKE :q OR p.id_product = :exact_id OR p.reference LIKE :q)
ORDER BY (CASE WHEN p.id_product = :exact_id THEN 0 ELSE 1 END), pl.name
LIMIT 20;
```

Gdzie `:q = '%searchterm%'` i `:exact_id = (int)searchterm` (jeśli numeric, inaczej 0).

Response:

```json
{
  "products": [
    {"id": 6865, "name": "SANTI E.Lite Plus Ladies First...", "price": 10920, "in_stock": true},
    ...
  ]
}
```

### Smoke test #5

```bash
curl -u admin:HASLO "https://chat.divezone.pl/api/admin/products/search?q=santi%20suchy" | head
# Oczekiwane: lista 5-20 produktów SANTI
curl -u admin:HASLO "https://chat.divezone.pl/api/admin/products/search?q=7318"
# Oczekiwane: Suunto Ocean Steel Black jako pierwszy
```

## KROK 6. Endpoint #6 — last_review_at sort whitelist

W `EditorialPicksService::list()` linia 79:

```php
$allowedOrder = ['added_at', 'expires_at', 'boost_factor', 'product_name'];
```

Zmień na:

```php
$allowedOrder = ['added_at', 'expires_at', 'boost_factor', 'product_name', 'last_review_at'];
```

Plus w SQL query w `list()` upewnić się że `ORDER BY last_review_at` traktuje NULL jako "najstarsze" (NULLS FIRST gdy ASC, NULLS LAST gdy DESC — sprawdź PostgreSQL convention).

### Smoke test #6

```bash
curl -u admin:HASLO "https://chat.divezone.pl/api/admin/editorial-picks?order_by=last_review_at" | head
```

## KROK 7. PHP lint + integration tests

```bash
php -l standalone/src/Tools/ProductSearch.php
php -l standalone/src/Editorial/EditorialPicksService.php
php -l standalone/src/Controller/AdminEditorialPicksController.php
php -l standalone/src/Chat/SystemPrompt.php
php -l standalone/src/Http/Request.php
```

Integration tests w `tests/integration/EditorialPicksTest.php`:
- Test bypass stock filter: pick z available_to_order produktem + in_stock_only=true → produkt zwrócony
- Test price_max szanowany: pick + price_max < price → produkt NIE zwrócony
- Test pendingReviews zwraca {0,0,0} dla empty table

## KROK 8. STOP point — review przez Karol

Status: "READY FOR REVIEW v1". Wklej:

- Wynik diagnozy KROK 1a (jaki Server header, czy ModSec, jaki fix wybrany A/B/C)
- Diff stat per plik
- Smoke testy #1-#6 wyniki (curl outputs)
- Integration test results

NIE deploy bez akceptacji.

## KROK 9. Deploy

Standard scp procedura. Pliki do scp:

- standalone/src/Tools/ProductSearch.php
- standalone/src/Editorial/EditorialPicksService.php
- standalone/src/Controller/AdminEditorialPicksController.php
- standalone/src/Chat/SystemPrompt.php
- standalone/src/Http/Request.php (jeśli zmieniony dla method override)
- standalone/public/admin/js/admin.js (jeśli dodany method override)
- standalone/public/.htaccess (jeśli opcja A)
- standalone/config/routes.php (jeśli nowe routes)

md5 weryfikacja per plik. Backup hashes przed.

Smoke prod (curl) per KROK 1-6. Plus UI smoke (Karol w przeglądarce po deploy).

## KROK 10. Git workflow

```bash
git status
# Konkretne ścieżki:
git add standalone/src/Tools/ProductSearch.php
git add standalone/src/Editorial/EditorialPicksService.php
git add standalone/src/Controller/AdminEditorialPicksController.php
git add standalone/src/Chat/SystemPrompt.php
# Warunkowo (zależnie od wybranego fixa #1):
# git add standalone/src/Http/Request.php
# git add standalone/public/admin/js/admin.js
# git add standalone/public/.htaccess
git add standalone/config/routes.php
git add tests/integration/EditorialPicksTest.php
git commit -m "T-012: hotfix Editorial Picks bugs + 3 follow-up endpoints

Bugi:
- #1 HTTP 403 przy PUT — fix przez {wybrana_opcja: A.htaccess / B method override / C cPanel kontakt}
- #2 Boost vs filters (ADR-058): bypass in_stock_only, respect price_max
- #3 Prompt Patch v6: NIE dopytuj o już doprecyzowane (smartwatch/płeć/budżet/cert)

Endpointy follow-up dla T-013:
- #4 GET /api/admin/editorial-picks/pending-reviews
- #5 GET /api/admin/products/search?q= (autocomplete)
- #6 last_review_at w sort whitelist

Powiązany ADR: ADR-058"
git push origin main
```

## KROK 11. Smoke test produkcyjny dla Karola

UI prod, scenariusze testowe:

### Bug #1 (HTTP 403 fix):
1. Otwórz Editorial Picks w panelu admin
2. Kliknij Edit na picku Suunto Ocean Steel Black (id 9 lub innym)
3. Zmień boost factor (np. 2.1)
4. Kliknij Zapisz → powinien być toast "Pick zaktualizowany" + brak 403

### Bug #2 (boost respektuje budżet, ignoruje stock):
5. Sprawdź `category_hint` picka Suunto Ocean Steel Black: edit → upewnij się że "Komputery Nurkowe" (lub puste)
6. Chat: "Szukam komputera zegarkowego, budżet około 5000 zł" — Suunto Ocean Steel Black W wynikach z boost
7. Chat: "Szukam komputera zegarkowego, budżet około 2000 zł" — Suunto Ocean Steel Black NIE w wynikach (cena > 2000)

### Bug #3 (NIE dopytuje):
8. Chat: "Szukam komputera zegarkowego" — bot NIE pyta smartwatch vs duży
9. Chat: "Szukam damskiej pianki" — bot NIE pyta o płeć
10. Chat: "Szukam komputera za 3000 zł" — bot NIE pyta o budżet
11. Regression: "Szukam suchego skafandra" — bot NADAL pyta o płeć (klient nie podał)

### Endpointy:
12. curl `/pending-reviews` zwraca JSON
13. curl `/products/search?q=santi` zwraca listę

## KROK 12. Raport + status update

### Utworz `_instances/backend/handoff/T-012_done.md`:
- Diagnoza bug #1 i wybrany fix (A/B/C)
- Diff stat per plik
- Smoke results: 11 scenariuszy UI + 2 curl endpoint
- Git commit hash

### Update `_docs/21_STATUS_PROJEKTU.md`:
- "Co działa na produkcji" → T-012 DEPLOYED (bugi + endpointy)
- "Aktywne instancje CC" → backend T-012 DONE
- "Kolejka tasków" → usunąć T-012 + 3 backend follow-up, dodać T-013 frontend UI polish (gotowy do startu po T-012 deploy)

### Osobny commit "docs:"

```bash
git add _docs/21_STATUS_PROJEKTU.md _docs/10_decyzje_projektowe.md
git commit -m "docs: T-012 DEPLOYED + ADR-058"
git push origin main
```

## Out of scope

- T-013 frontend UI polish (autocomplete, layout kolumn, sort header, ikony, tooltipy) — osobny task po T-012 deploy
- Per-customer-segment różne polityki boost
- ML auto-tuning boost factors
- Analytics konwersji picków
- Weekly notifications cron (email/banner backend trigger — wymaga decyzji email vs banner-only)
- D1 ETL leaf cat alias (Karabinki/Retraktory → Bezpieczeństwo) — niski priorytet, P3
