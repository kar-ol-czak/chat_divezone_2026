# TASK-CHAT-012: Diagnoza i fix bug "SANTI search zwraca 0" (P0)

**Instancja:** backend (z opcjonalnym wsparciem embeddings)
**Powiązany:** ADR-053
**Priorytet:** P0 KRYTYCZNY — bestseller sklepu raportowany jako niedostępny
**Powiązane:** ADR-048 (live MySQL data), ADR-049 (search_debug stripping)

## Cel

Bot na zapytanie "Szukam suchego skafandra SANTI" odpowiedział: "Aktualnie nie mamy suchych skafandrów marki SANTI w ofercie." 

Tymczasem **SANTI jest najczęściej sprzedającym się skafandrem suchym w divezone.pl**. Sprawdzenie phpMyAdmin (14.05): zapytanie SQL JOIN-ujące pr_product + pr_manufacturer + pr_category_product + pr_category_lang z filtrami `manufacturer.name LIKE '%SANTI%' AND category_lang.name LIKE '%such%' AND product.active=1` zwróciło **128 wyników**.

Bug nie w danych — w pipeline wyszukiwania.

## Plan diagnozy (5 faz)

### FAZA 1: Logi wywołania search_products przy zapytaniu SANTI

1. Wywołaj chat.divezone.pl z zapytaniem "Szukam suchego skafandra SANTI"
2. Zaloguj pełny pipeline:
   - Co model wysłał jako `search_plan` (intent, category, query, exact_keywords, brand filter?)
   - Co `search_products()` dostał jako parametry
   - Co backend wywołał na PG (query embedding + warunki SQL)
   - Ile wyników zwróciło pgvector PRZED enrichWithMySQLData
   - Ile po enrichWithMySQLData
   - Ile po RRF fusion z FTS i trigram
   - Ile po stripping (ADR-049)

Wyniki zapisać w `_instances/backend/handoff/TASK-CHAT-012_diagnoza.md` z konkretnymi numerami per krok.

### FAZA 2: Sprawdzenie czy SANTI dry suits są w embeddings PG

Połącz z Railway PG (DATABASE_URL z .env):

```sql
-- Czy w ogóle są w divechat_products?
SELECT count(*) FROM divechat_products WHERE 
    product_data->>'brand' ILIKE '%SANTI%' 
    AND product_data->>'category' ILIKE '%such%';

-- Pokaż 3 przykłady
SELECT id, product_data->>'name' AS name, 
       product_data->>'brand' AS brand, 
       product_data->>'category' AS category,
       product_data->>'availability' AS availability
FROM divechat_products WHERE 
    product_data->>'brand' ILIKE '%SANTI%' 
    AND product_data->>'category' ILIKE '%such%'
LIMIT 5;

-- Sprawdź czy są embeddingi (nie tylko metadata)
SELECT id, octet_length(embedding::text) > 0 AS has_embedding 
FROM divechat_products WHERE 
    product_data->>'brand' ILIKE '%SANTI%' 
    AND product_data->>'category' ILIKE '%such%'
LIMIT 5;
```

**Możliwe znaleziska:**
- 0 wyników → bug w pipeline embeddings (produkty nie zostały zembeddowane). Solution: re-embed.
- >0 ale `has_embedding=false` → embedding column pusty. Re-embed.
- >0 wyników z OK embeddings → problem w warstwie search/query (Faza 3).

### FAZA 3: Test bezpośredni search_products

W lokalnym dev/REPL wywołaj bezpośrednio funkcję search:

```php
$results = $searchService->search([
    'query' => 'suchy skafander',
    'category' => 'Skafandry suche',
    'brand' => 'SANTI',
    'intent' => 'navigational',
    'exact_keywords' => ['SANTI'],
    'in_stock_only' => false,
]);

var_dump(count($results), $results);
```

Próbuj 4 warianty zapytań:
1. brand="SANTI" + category="Skafandry suche" (jak wyżej)
2. brand="SANTI" bez category
3. query="SANTI suchy skafander" bez brand filter
4. exact_keywords=["SANTI"] + query="suchy skafander"

Zapisz wyniki każdego wariantu.

**Możliwe znaleziska:**
- Wszystkie 4 zwracają 0 → bug w samym search engine
- Niektóre zwracają, niektóre nie → bug w konkretnym filtrze (np. brand filter dla SANTI ma case sensitivity)
- Wszystkie zwracają → problem w tym JAK model formułuje wywołanie (Faza 4)

### FAZA 4: Brand normalization

PrestaShop może mieć SANTI jako "SANTI", "Santi", "SANTI Diving", "Santi Diving" w `pr_manufacturer.name`. SystemPrompt mówi modelowi "SANTI" (uppercase). Embeddings mogą trzymać inną formę.

Sprawdź:

```sql
-- MySQL prod
SELECT DISTINCT name FROM pr_manufacturer WHERE name ILIKE '%santi%';
```

```sql
-- PG
SELECT DISTINCT product_data->>'brand' AS brand 
FROM divechat_products 
WHERE product_data->>'brand' ILIKE '%santi%';
```

Jeśli niezgodność (np. MySQL ma "Santi Diving", PG ma "SANTI"), to brand filter w search_products może nie matchować.

### FAZA 5: Kategoria

SystemPrompt instruuje category="Skafandry suche" (z NAZEWNICTWO SKLEPU). Sprawdź jaka jest faktyczna nazwa kategorii dla SANTI w PG:

```sql
SELECT DISTINCT product_data->>'category' AS category, count(*) 
FROM divechat_products 
WHERE product_data->>'brand' ILIKE '%santi%' 
    AND product_data->>'category' ILIKE '%such%'
GROUP BY category;
```

Możliwe: kategoria w PG to "SUCHE Neoprenowe" / "SUCHE Trylaminat Cordura" (z `pr_category`), a nie "Skafandry suche" (jak w SystemPrompt). Jeśli model szuka category="Skafandry suche" a w bazie są "SUCHE Trylaminat Cordura" — bingo.

## STOP point 1 (po Fazie 1-2)

Raport z diagnozy. Karol decyduje czy iść głębiej (Fazy 3-5) czy fix jest oczywisty.

## Możliwe rozwiązania (do wyboru po diagnozie)

### Solution A: Re-embed produktów SANTI

Jeśli Faza 2 pokazuje brak embeddingów → re-embed wszystkich produktów SANTI (i sprawdzić czy inne marki nie mają tego samego problemu).

### Solution B: Fix brand normalization

Jeśli Faza 4 pokazuje rozbieżność → normalizować brand w pipeline (UPPER lub case-insensitive search).

### Solution C: Fix mapowania kategorii

Jeśli Faza 5 pokazuje rozbieżność → albo zmienić SystemPrompt żeby używał faktycznych nazw kategorii ("SUCHE Trylaminat Cordura"), albo dodać alias mapping w search (category="Skafandry suche" → przeszukuje kategorie pochodne).

### Solution D: Fix logiki search engine

Jeśli faza 3 pokazuje że search jest broken niezależnie od inputs.

## Acceptance criteria

Po fix, zapytanie "Szukam suchego skafandra SANTI" zwraca w top 5 wyników co najmniej 3 produkty SANTI z kategorii skafandrów suchych. Bot informuje o dostępności poszczególnych modeli (in_stock / available_to_order) i NIE mówi że "nie mamy SANTI".

Regresja test: pozostałe marki (SCUBAPRO, MARES, BARE) nadal działają poprawnie.

## Deploy + Git

Standard scp + commit + push, konwencja `TASK-CHAT-012: ...`.

Jeśli fix wymaga re-embed → osobny commit per scope (np. `TASK-CHAT-012: re-embed SANTI dry suits`).

## Out of scope

- Refactor całego pipeline search
- Wprowadzanie nowych algorytmów rankingowych
- Editorial Picks (TASK-CHAT-009 — wstrzymane do zamknięcia hotfix)
