# TASK-012: Hybrid Search 3-torowy z RRF
# Instancje: integration (SQL) + backend (PHP)
# Zależności: TASK-011a (synonimy w tabeli)
# Priorytet: WYSOKI

## CEL
Zamienić obecny single-vector search na 3-torowy hybrid search z RRF.

## TOR 1: Embedding similarity (pgvector, BEZ ZMIAN)
Obecne zapytanie: embedding <=> $1::vector
Zostaje jak jest. Później TASK-012b doda multi-vector.

## TOR 2: Full-Text Search (NOWY)

### Konfiguracja FTS w PostgreSQL

```sql
-- Wymagane rozszerzenia
CREATE EXTENSION IF NOT EXISTS unaccent;

-- Konfiguracja FTS: simple + unaccent
-- (NIE używamy dict_xsyn, synonimy przez query expansion w aplikacji)
CREATE TEXT SEARCH CONFIGURATION diving_simple (COPY = pg_catalog.simple);
ALTER TEXT SEARCH CONFIGURATION diving_simple
    ALTER MAPPING FOR asciiword, asciihword, hword_asciipart, word, hword, hword_part
    WITH unaccent, simple;

-- Kolumna tsvector (precomputed, szybsze wyszukiwanie)
ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS fts_vector tsvector;

-- Wypełnienie kolumny
UPDATE divechat_product_embeddings
SET fts_vector = to_tsvector('diving_simple',
    COALESCE(product_name, '') || ' ' ||
    COALESCE(brand_name, '') || ' ' ||
    COALESCE(category_name, '') || ' ' ||
    COALESCE(document_text, '')
);

-- Indeks GIN na precomputed tsvector
CREATE INDEX idx_product_fts ON divechat_product_embeddings USING gin (fts_vector);

-- Trigger do automatycznej aktualizacji
CREATE OR REPLACE FUNCTION update_fts_vector() RETURNS trigger AS $$
BEGIN
    NEW.fts_vector := to_tsvector('diving_simple',
        COALESCE(NEW.product_name, '') || ' ' ||
        COALESCE(NEW.brand_name, '') || ' ' ||
        COALESCE(NEW.category_name, '') || ' ' ||
        COALESCE(NEW.document_text, '')
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_update_fts
    BEFORE INSERT OR UPDATE OF product_name, brand_name, category_name, document_text
    ON divechat_product_embeddings
    FOR EACH ROW EXECUTE FUNCTION update_fts_vector();
```

### Query Expansion (synonimy z tabeli)

W PHP/Python, PRZED wysłaniem do FTS:

```php
// ProductSearch.php
private function expandQuery(string $query): string
{
    // 1. Tokenizuj zapytanie
    $words = explode(' ', mb_strtolower($query));

    // 2. Dla każdego słowa sprawdź synonimy
    $expanded = [];
    foreach ($words as $word) {
        $synonyms = $this->getSynonyms($word); // z divechat_synonyms
        if ($synonyms) {
            // pianka → (pianka | skafander | wetsuit | neopren)
            $expanded[] = '(' . implode(' | ', array_merge([$word], $synonyms)) . ')';
        } else {
            $expanded[] = $word;
        }
    }

    // 3. Łącz operatorem AND
    return implode(' & ', $expanded);
}

// Wynik: "pianka 7mm" → "(pianka | skafander | wetsuit) & 7mm"
```

## TOR 3: Trigram fuzzy (pg_trgm, wzmocniony)

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX IF NOT EXISTS idx_product_name_trgm
    ON divechat_product_embeddings USING gin (product_name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_brand_name_trgm
    ON divechat_product_embeddings USING gin (brand_name gin_trgm_ops);
```

Trigram TYLKO na product_name i brand_name (nie na document_text).
Cel: literówki, nazwy własne, kody modeli.

## FUZJA: Reciprocal Rank Fusion (RRF)

```sql
WITH params AS (
    SELECT
        $1::vector AS query_embedding,
        $2::text AS query_text,
        $3::text AS expanded_query,  -- po query expansion
        $4::text AS category_filter,
        $5::numeric AS price_min,
        $6::numeric AS price_max,
        $7::text AS brand_filter,
        $8::boolean AS in_stock_only
),
-- Tor 1: Semantic
semantic AS (
    SELECT ps_product_id,
           ROW_NUMBER() OVER (ORDER BY embedding <=> (SELECT query_embedding FROM params)) AS rank
    FROM divechat_product_embeddings
    WHERE is_active = true
      AND (SELECT category_filter FROM params) IS NULL
          OR category_name ILIKE '%' || (SELECT category_filter FROM params) || '%')
      AND ((SELECT price_min FROM params) IS NULL OR price >= (SELECT price_min FROM params))
      AND ((SELECT price_max FROM params) IS NULL OR price <= (SELECT price_max FROM params))
      AND ((SELECT brand_filter FROM params) IS NULL OR brand_name ILIKE (SELECT brand_filter FROM params))
      AND ((SELECT in_stock_only FROM params) = false OR in_stock = true)
    ORDER BY embedding <=> (SELECT query_embedding FROM params)
    LIMIT 30
),
-- Tor 2: Full-Text Search
fulltext AS (
    SELECT ps_product_id,
           ROW_NUMBER() OVER (ORDER BY ts_rank_cd(fts_vector,
               to_tsquery('diving_simple', (SELECT expanded_query FROM params))) DESC) AS rank
    FROM divechat_product_embeddings
    WHERE is_active = true
      AND fts_vector @@ to_tsquery('diving_simple', (SELECT expanded_query FROM params))
      -- te same filtry co wyżej
    LIMIT 30
),
-- Tor 3: Trigram (nazwa + marka)
trgm AS (
    SELECT ps_product_id,
           ROW_NUMBER() OVER (ORDER BY
               GREATEST(
                   similarity(product_name, (SELECT query_text FROM params)),
                   similarity(COALESCE(brand_name,''), (SELECT query_text FROM params))
               ) DESC) AS rank
    FROM divechat_product_embeddings
    WHERE is_active = true
      AND (
          similarity(product_name, (SELECT query_text FROM params)) > 0.2
          OR similarity(COALESCE(brand_name,''), (SELECT query_text FROM params)) > 0.3
      )
    LIMIT 30
),
-- RRF Fusion (k=60)
fused AS (
    SELECT
        COALESCE(s.ps_product_id, f.ps_product_id, t.ps_product_id) AS ps_product_id,
        COALESCE(1.0 / (60 + s.rank), 0) AS semantic_rrf,
        COALESCE(1.0 / (60 + f.rank), 0) AS fulltext_rrf,
        COALESCE(1.0 / (60 + t.rank), 0) AS trigram_rrf,
        COALESCE(1.0 / (60 + s.rank), 0) +
        COALESCE(1.0 / (60 + f.rank), 0) +
        COALESCE(1.0 / (60 + t.rank), 0) AS rrf_score
    FROM semantic s
    FULL OUTER JOIN fulltext f ON f.ps_product_id = s.ps_product_id
    FULL OUTER JOIN trgm t ON t.ps_product_id = COALESCE(s.ps_product_id, f.ps_product_id)
)
SELECT f.rrf_score, f.semantic_rrf, f.fulltext_rrf, f.trigram_rrf,
       p.ps_product_id, p.product_name, p.brand_name, p.category_name,
       p.price, p.in_stock, p.product_url, p.image_url
FROM fused f
JOIN divechat_product_embeddings p ON p.ps_product_id = f.ps_product_id
WHERE f.rrf_score > 0
ORDER BY f.rrf_score DESC
LIMIT $9;
```

## ZMIANY W PHP (ProductSearch.php)

1. Nowa metoda expandQuery() (query expansion z synonimami)
2. Nowa metoda searchHybridRRF() (zastępuje obecny search)
3. Wynik zawiera debug info: semantic_rrf, fulltext_rrf, trigram_rrf
4. Logowanie do search_diagnostics (JSONB w conversations)

## PLIKI WYJŚCIOWE
- sql/004_hybrid_search_setup.sql (FTS config, indeksy, trigger)
- standalone/src/Tools/ProductSearch.php (zmieniony)
- standalone/src/Tools/SynonymExpander.php (nowa klasa)

## TESTY
| Zapytanie | Oczekiwany tor dominujący | Oczekiwany wynik |
|---|---|---|
| "pianka" | FTS (po expansion: pianka\|skafander\|wetsuit) | Skafandry |
| "BARE 7mm" | Trigram + FTS | Produkty BARE |
| "coś na zimną wodę do nurkowania" | Semantic | Skafandry/pianki 7mm |
| "Shearwater Teric" | Trigram | Dokładny produkt |
| "komputer na trimix" | Semantic + FTS | Komputery wielogazowe |

## KRYTERIA AKCEPTACJI
- [ ] 3 tory działają niezależnie
- [ ] RRF łączy wyniki poprawnie
- [ ] Query expansion rozszerza znane synonimy
- [ ] Filtry (category, price, brand, in_stock) działają
- [ ] Debug info w wynikach (który tor znalazł co)
- [ ] Testy z tabeli powyżej przechodzą
