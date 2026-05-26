# T-015: Search logic — blacklista marek, price floor, boolean recall

**Instancja:** backend
**Powiązane:** testy pracowników 15 (Aquazone), 8+26 (price floor), 23 (child+pink)
**Priorytet:** P1 (blacklista) + P2 (price floor, boolean)
**Czas estymowany:** ~3h CC
**Plik główny:** standalone/src/Tools/ProductSearch.php (NIE dotyka SystemPrompt — równoległy z T-014)

## Cel

Trzy usprawnienia logiki wyszukiwania:

1. **Blacklista marek** (test 15): Aquazone wykluczony z wyników (firma zniknęła, wadliwe sztuki)
2. **Price floor / tolerancja budżetu** (testy 8, 26): przy budżecie X nie pokazuj produktów < pewnego % wartości (ładowarka 60 zł przy budżecie 700 zł)
3. **Boolean recall** (test 23 "pink mask for child"): poprawa precyzji gdy zapytanie ma wiele atrybutów (child AND pink, nie OR)

## KROK 0. Read

- `standalone/src/Tools/ProductSearch.php` linie 150-230 (buildFilters + filtry) oraz 340-470 (RRF fusion + scoring)
- `standalone/src/Tools/SynonymExpander.php` (expandForFts — buduje tsquery, child & pink jest poprawne)
- `sql/004_hybrid_search_setup.sql` (struktura FTS + trigram)
- `_docs/22_analiza_testow_pracownikow.md` (kontekst testów 8/15/23/26)

## KROK 1. Blacklista marek (test 15) — P1

### 1a. Tabela divechat_brand_blacklist (PG, edytowalna online)

Migracja `sql/014_brand_blacklist.sql` + rollback:

```sql
CREATE TABLE IF NOT EXISTS divechat_brand_blacklist (
    id SERIAL PRIMARY KEY,
    brand_name TEXT UNIQUE NOT NULL,
    reason TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO divechat_brand_blacklist (brand_name, reason) VALUES
('Aquazone', 'Firma zniknęła z rynku, wyprzedaż ostatnich wadliwych sztuk — nie polecać')
ON CONFLICT (brand_name) DO NOTHING;
```

### 1b. Filtr w buildFilters()

W `ProductSearch::buildFilters()` dodać warunek wykluczający blacklistowane marki:

```sql
AND brand_name NOT IN (SELECT brand_name FROM divechat_brand_blacklist)
```

Lub pobrać listę raz na request (cache property) i dodać `brand_name NOT ILIKE` per marka. Wybór: subquery w SQL (prostsze, 1 query). Blacklista jest mała (1-5 marek), subquery tani.

UWAGA: blacklista działa na poziomie filtra WHERE we wszystkich 5 torach search. Produkt blacklistowanej marki NIE pojawia się w żadnym torze → nie wejdzie do RRF.

WYJĄTEK: jeśli klient pyta WPROST o markę blacklistowaną ("macie coś Aquazone?"), bot powinien móc odpowiedzieć że nie polecamy. To obsłuży SystemPrompt (T-016) — tu tylko filtr search. Filtr można pominąć gdy `filters.brand` zawiera blacklistowaną markę (klient explicite jej szuka) — wtedy zwróć wyniki ale flag `brand_blacklisted: true` w search_debug żeby T-016 prompt wiedział że ma ostrzec. Decyzja CC: prościej zawsze filtrować, ostrzeżenie w prompt. Jeśli łatwo — dodaj bypass dla explicit brand query.

## KROK 2. Price floor / tolerancja budżetu (testy 8, 26) — P2

### Problem

Klient: "latarki do 700 zł" → bot pokazuje ładowarkę 60 zł, uchwyt. `price_max=700` filtruje górę, ale dół jest otwarty → drobnica wpada.

### Rozwiązanie

Gdy `price_max` jest ustawiony I `price_min` NIE jest podany przez klienta, zastosuj automatyczny price floor = `price_max * FLOOR_RATIO`.

```php
const PRICE_FLOOR_RATIO = 0.25; // produkty poniżej 25% budżetu max są pomijane jako "drobnica"
```

W buildFilters(), po ustaleniu max_price:

```php
if (!empty($params['max_price']) && empty($params['min_price'])) {
    $impliedFloor = (float) $params['max_price'] * self::PRICE_FLOOR_RATIO;
    $sqlParams[] = $impliedFloor;
    $conditions[] = "price >= ?";
}
```

UWAGA: ratio 0.25 to wartość startowa. Dla budżetu 700 zł floor = 175 zł (odcina ładowarki 60 zł, uchwyty). Dla budżetu 200 zł (prezent) floor = 50 zł (rozsądne). Karol może chcieć tunować — rozważ czy ratio ma być w divechat_shop_config (edytowalne) zamiast const. REKOMENDACJA CC: zacznij od const 0.25, jeśli łatwo dodaj do shop_config jako 'price_floor_ratio'.

WYJĄTEK: gdy klient pyta o "prezent do 200 zł" — niektóre gadżety (brelok 24 zł) są OK jako prezent. To napięcie. Decyzja: price floor stosuj TYLKO gdy intent != prezent. Jeśli ProductSearch dostaje sygnał że to prezent (np. category zawiera "Prezenty" lub search_plan intent), pomiń floor. Inaczej drobne prezenty znikną. CC sprawdza czy jest taki sygnał w params; jeśli nie ma łatwego sygnału — zostaw floor zawsze, Karol oceni w smoke.

## KROK 3. Boolean recall (test 23 "pink mask for child") — P2

### Diagnoza

"I need a pink mask for a child" → bot pokazał maski które są ALBO różowe ALBO dziecięce (nie AND). Przyczyna: hybrydowy search z 5 torów. FTS robi `child & pink` (AND, poprawnie), ale 3 tory semantyczne (embeddingi) dają wysokie podobieństwo produktom pasującym do KTÓREGOKOLWIEK atrybutu. RRF fusion sumuje → produkt "różowa maska dorosła" i "dziecięca maska niebieska" oba dostają punkty.

### Rozwiązanie — re-ranking boost dla multi-attribute match

Nie zmieniamy torów (semantic recall jest cenny). Zamiast tego: w RRF fusion, PO złączeniu torów, dodaj boost dla produktów które matchują WSZYSTKIE znaczące tokeny zapytania w product_name/description.

Pseudokod (w mergeRRF lub po nim):

```php
// Po RRF fusion, przed sortowaniem:
// Wyciągnij znaczące tokeny zapytania (rzeczowniki/przymiotniki, pomiń stopwords)
$queryTokens = $this->extractSignificantTokens($query); // ['pink', 'mask', 'child'] -> normalize
foreach ($scores as $pid => $score) {
    $matchedTokens = $this->countTokenMatches($pid, $queryTokens, $mysqlData);
    // boost proporcjonalny do % dopasowanych tokenów
    $matchRatio = $matchedTokens / max(1, count($queryTokens));
    $scores[$pid] *= (1 + 0.5 * $matchRatio); // do +50% za pełne dopasowanie
}
```

To NIE jest twardy filtr (nie wyrzuca produktów), tylko re-ranking — produkty pasujące do większej liczby atrybutów idą wyżej. Bezpieczne dla recall.

UWAGA: to delikatna zmiana. Jeśli CC oceni że re-ranking jest zbyt złożony/ryzykowny dla tego taska — ALTERNATYWA: zostaw search bez zmian, problem rozwiąż w T-016 SystemPrompt (instrukcja: "gdy klient podaje wiele atrybutów koloru+wiek+typ, w odpowiedzi wskaż które produkty pasują do WSZYSTKICH kryteriów, a które tylko częściowo"). Decyzja CC: jeśli re-ranking ProductSearch jest > 1h, przesuń do T-016 prompt i zaznacz w raporcie.

## KROK 4. PHP lint + smoke

```bash
php -l standalone/src/Tools/ProductSearch.php
```

## KROK 5. STOP point — diff do review Karol

Status: "READY FOR REVIEW v1". Wklej:
- Migracje 014 (blacklist) treść
- Diff ProductSearch.php (3 zmiany: blacklist filter, price floor, boolean re-rank LUB notatka że przesunięte do T-016)
- Decyzje: czy price_floor_ratio w const czy config; czy boolean re-rank zrobiony czy przesunięty
- Plan smoke prod

NIE deploy bez akceptacji.

## KROK 6. Deploy

- Apply migracja 014 na Railway
- scp ProductSearch.php
- php -l prod
- Smoke prod (curl/chat)

## KROK 7. Git workflow

```bash
git status
git add sql/014_brand_blacklist.sql sql/014_brand_blacklist_rollback.sql
git add standalone/src/Tools/ProductSearch.php
git commit -m "T-015: search logic — blacklista marek + price floor + boolean recall

- Blacklista marek (divechat_brand_blacklist): Aquazone wykluczony z wyników
  (firma zniknęła, wadliwe sztuki). Edytowalna online.
- Price floor: gdy price_max ustawiony bez price_min, auto floor = max*0.25
  (odcina drobnicę typu ładowarka 60 zł przy budżecie 700 zł)
- Boolean recall: re-ranking boost dla multi-attribute match (pink AND child
  AND mask wyżej niż produkty z jednym atrybutem) {LUB: przesunięte do T-016}

Testy pracowników: 15 (Aquazone), 8+26 (price floor), 23 (child+pink)"
git push origin main
```

## KROK 8. Smoke test produkcyjny dla Karola

1. Chat: "Szukam skafandra mokrego dla początkującego" → BRAK Aquazone w wynikach (blacklista)
2. Chat: "macie coś Aquazone?" → wyniki LUB bot ostrzega (zależnie od implementacji 1b)
3. Chat: "latarki nurkowe do 700 zł" → BRAK ładowarek/uchwytów za 60 zł (price floor odciął)
4. Chat: "prezent dla nurka do 200 zł" → drobne gadżety NADAL widoczne (jeśli floor wyłączony dla prezentów) lub odcięte (jeśli floor zawsze — Karol oceni)
5. Chat (EN): "pink mask for child" → maski dziecięce różowe wyżej niż osobne atrybuty (jeśli boolean re-rank zrobiony)

## KROK 9. Raport + status update

### `_instances/backend/handoff/T-015_done.md`:
- Migracja 014 applied
- Diff ProductSearch.php
- Decyzje implementacyjne (price floor ratio location, boolean re-rank done/deferred)
- Smoke prod 5 scenariuszy

### Update `_docs/21_STATUS_PROJEKTU.md`:
- T-015 DEPLOYED, search logic improvements
- Backlog jeśli boolean re-rank przesunięty → do T-016

### Osobny commit "docs:":

```bash
git add _docs/21_STATUS_PROJEKTU.md
git commit -m "docs: T-015 DEPLOYED — search logic"
git push origin main
```

## Out of scope

- Combination stock (wariant koloru/rozmiaru, test 102) — osobny task, wymaga MAX(quantity) GROUP BY na pr_product_attribute
- UI do edycji blacklisty (na razie SQL manual)
- Tuning price_floor_ratio per kategoria
- Encyklopedia gaps (Peregrine TX, Ammonite) — osobny task
- Wszystkie zmiany SystemPrompt → T-016 (równoległy task NIE dotyka tego pliku)
