# T-006: Fix availability logic w enrichWithMySQLData (backend)

**Instancja:** backend
**Plik:** `standalone/src/Tools/ProductSearch.php` (linie ~580-680)
**Powiązany:** ADR-056, smoke test T-003 (14.05), retroaktywnie T-002 follow-up #2 i #3
**Priorytet:** P0 KRYTYCZNY — całe SKU z `out_of_stock=2` błędnie oznaczane jako "unavailable"
**Czas estymowany:** ~45 min CC

## Cel

Naprawić logikę `availability` w `ProductSearch::enrichWithMySQLData()` żeby uwzględniała globalną wartość `PS_ORDER_OUT_OF_STOCK` z `pr_configuration` dla produktów z `out_of_stock=2` (PrestaShop "use default").

## Kontekst diagnozy

SQL CASE w `enrichWithMySQLData()` linia ~605 traktuje tylko `allow_oos=1` jako "allow", ignorując wartość `2` (PrestaShop "use default"). W divezone `PS_ORDER_OUT_OF_STOCK=1`, więc produkty z quantity=0 i out_of_stock=2 powinny być `available_to_order`, a są `unavailable`. Bug dotyka kilkuset SKU.

Pełne uzasadnienie + tabela diagnostyczna SANTI E.Lite Plus: ADR-056.

## KROK 0. Read

- `standalone/src/Tools/ProductSearch.php` linie 580-680 (cała `enrichWithMySQLData`)
- `_docs/10_decyzje_projektowe.md` sekcja ADR-056

## KROK 1. Pobranie globalnej wartości na początku enrichWithMySQLData

Po `$mysql = MysqlConnection::getInstance();` (przed `$placeholders = ...`):

```php
// Globalna konfiguracja: czy sklep pozwala zamawiać niedostępne produkty (PS_ORDER_OUT_OF_STOCK).
// Używana gdy produkt ma out_of_stock=2 (use default behavior — PrestaShop konwencja).
// Wartość pobierana per request — może się zmieniać w PS admin bez restartu.
$globalAllowOos = (int) (
    $mysql->fetchOne(
        "SELECT value FROM pr_configuration WHERE name = 'PS_ORDER_OUT_OF_STOCK' LIMIT 1"
    )['value'] ?? 0
);
```

## KROK 2. Rozszerzyć CASE w SQL (linia ~605)

Zastąp obecny CASE:

```sql
CASE
    WHEN COALESCE(sa.total_qty, 0) > 0 THEN 'in_stock'
    WHEN COALESCE(sa.allow_oos, 0) = 1 THEN 'available_to_order'
    ELSE 'unavailable'
END AS availability
```

Nowym:

```sql
CASE
    WHEN COALESCE(sa.total_qty, 0) > 0 THEN 'in_stock'
    WHEN sa.allow_oos = 1 THEN 'available_to_order'
    WHEN sa.allow_oos = 0 THEN 'unavailable'
    WHEN (sa.allow_oos IS NULL OR sa.allow_oos = 2) AND ? = 1 THEN 'available_to_order'
    ELSE 'unavailable'
END AS availability
```

Wywołanie `$mysql->fetchAll(...)`:
- Stary bindings: `$productIds`
- Nowy bindings: `array_merge([$globalAllowOos], $productIds)`

UWAGA: placeholder `?` dla $globalAllowOos jest PIERWSZY w SQL (przed `IN ({$placeholders})`). PDO wiąże w kolejności wystąpienia.

## KROK 3. Integration test (lokalnie, przed deploy)

Stwórz `scripts/test_availability_fix.php` wywołujący ProductSearch z:

```php
$params = [
    'query' => 'skafander suchy santi',
    'search_plan' => ['intent' => 'exploratory', 'reasoning' => 'T-006 verification'],
    'category' => 'Skafandry suche',
    'filters' => ['brand' => 'SANTI', 'in_stock_only' => false],
    'limit' => 10,
];
```

Oczekiwany wynik po fix:

| id | quantity (MySQL) | availability (oczekiwane) |
|---|---|---|
| 5508 | -4 | available_to_order |
| 5509 | 0 | available_to_order |
| 5846 | 0 | available_to_order |
| 6865 | 1 | in_stock |
| 7617 | 0 | available_to_order |

Wynik wpisz w raport T-006_done.md.

## KROK 4. Audyt zakresu (informacyjnie, do raportu)

Query MySQL żeby zobaczyć ile SKU "ożywa":

```sql
SELECT COUNT(DISTINCT p.id_product) AS sku_affected
FROM pr_product p
JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
JOIN (
    SELECT id_product, MAX(quantity) as q, MAX(out_of_stock) as oos
    FROM pr_stock_available GROUP BY id_product
) sa ON p.id_product = sa.id_product
WHERE sa.q <= 0 AND sa.oos = 2 AND ps.active = 1;
```

Liczbę wpisz w raport.

## KROK 5. Smoke test PHP

```bash
php -l standalone/src/Tools/ProductSearch.php
```

## KROK 6. STOP point — diff do review Karol

Status: "READY FOR REVIEW v1". Wklej diff (`git diff`). NIE deploy bez akceptacji.

## KROK 7. Deploy

Standard scp procedura:
- Backup md5 przed (lokalnie + remote)
- scp pliku na chat.divezone.pl
- md5 lokalny == remote
- `php -l` na zdalnym
- Smoke test na prod: curl/php-cli search_products z parametrami z KROK 3, weryfikacja że 5509 i 5846 zwracają `available_to_order`

## KROK 8. Git workflow

```bash
git status
git add standalone/src/Tools/ProductSearch.php
# scripts/test_availability_fix.php tylko jeśli scripts/ NIE jest w .gitignore (sprawdź):
# cat .gitignore | grep -E '^scripts' — jeśli nie ma, dorzuć skrypt; jeśli jest, pomiń
git commit -m "T-006: fix availability logic — respektuj out_of_stock=2 (use default)

PrestaShop konwencja out_of_stock 0/1/2. Wartość 2 (najczęstszy default)
oznacza 'use global PS_ORDER_OUT_OF_STOCK'. Kod traktował to jak unavailable.
Po fix: dla out_of_stock=2 czytamy globalną wartość raz per request.

Diagnoza: smoke T-003 wykrył E.Lite Plus SANTI mylnie jako niedostępne mimo
PS_ORDER_OUT_OF_STOCK=1 (allow orders globalnie). Bug retroaktywnie wyjaśnia
follow-up #2 i #3 po T-002.

Powiązany ADR: ADR-056"
git push origin main
```

## KROK 9. Smoke test produkcyjny dla Karola

Po deploy Karol powtarza:

1. "Szukam suchego skafandra Santi" — E.Lite Plus damski i Ladies First powinny być **"na zamówienie"**, NIE "aktualnie niedostępne". Linki dla available_to_order powinny być (patch G T-003 już deployed).
2. "Polec komputer SHEARWATER" — regression mapping T-002 nadal działa, ceny + statusy bold.
3. Jakikolwiek "deny orders" produkt jeśli istnieje — nadal "unavailable".

## KROK 10. Raport + status update

### Utworz `_instances/backend/handoff/T-006_done.md`:
- Backup md5 przed/po
- Wynik integration testu z KROK 3 (5 par id→availability)
- Wynik audytu z KROK 4 (liczba SKU "ożywa")
- Git commit hash
- Diff stat (+X/-Y linii)

### Update `_docs/21_STATUS_PROJEKTU.md`:
- Sekcja "Co działa na produkcji" → T-006 DEPLOYED + commit hash
- Sekcja "Aktywne instancje CC" → backend T-006 DONE
- Sekcja "Kolejka tasków" → usunąć T-006
- Update follow-up T-003: bug #1 linkowanie + bug #2 generalizacja "niedostępne" — CLOSED by T-006; bug #3 płeć dla SANTI — OPEN (osobny task)

### Osobny commit "docs:":

```bash
git add _docs/21_STATUS_PROJEKTU.md _docs/10_decyzje_projektowe.md
git commit -m "docs: T-006 DEPLOYED + ADR-056 (out_of_stock=2 fix)"
git push origin main
```

## Out of scope

- Patch F SystemPrompt (już deployed w T-003, działa retroaktywnie po fix PHP)
- Mini-patch v4 SystemPrompt — NIEPOTRZEBNY
- Per-customer-group order policy (ADR-056 out of scope)
- Combination-level out_of_stock (zachowane MAX GROUP BY)
- Bug płci dla SANTI (osobny task, prompt-side)
- EN strategia (T-XXX po T-006, decyzja 63b)
