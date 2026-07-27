-- ============================================
-- DIVEZONE CHAT AI - Migracja 004
-- Hybrid search: FTS (unaccent) + trigram
-- Data: 2026-02-23
-- Źródło: TASK-012 Hybrid Search 3-torowy z RRF
-- ============================================

-- Rozszerzenia
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ============================================
-- Konfiguracja FTS: diving_simple
-- simple tokenizer (bez stemmera) + unaccent
-- ============================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_ts_config WHERE cfgname = 'diving_simple'
    ) THEN
        CREATE TEXT SEARCH CONFIGURATION diving_simple (COPY = simple);
        ALTER TEXT SEARCH CONFIGURATION diving_simple
            ALTER MAPPING FOR word, asciiword, numword, asciihword, hword, hword_asciipart, hword_numpart, hword_part, numhword
            WITH unaccent, simple;
    END IF;
END
$$;

-- ============================================
-- Kolumna fts_vector na divechat_product_embeddings
-- ============================================
ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS fts_vector tsvector;

-- Wypełnienie fts_vector z pól tekstowych
UPDATE divechat_product_embeddings
SET fts_vector = to_tsvector('diving_simple',
    COALESCE(product_name, '') || ' ' ||
    COALESCE(brand_name, '') || ' ' ||
    COALESCE(category_name, '') || ' ' ||
    COALESCE(document_text, '')
)
WHERE fts_vector IS NULL;

-- Indeks GIN na fts_vector
CREATE INDEX IF NOT EXISTS idx_product_fts
    ON divechat_product_embeddings USING gin (fts_vector);

-- ============================================
-- Trigger: automatyczna aktualizacja fts_vector
-- ============================================
CREATE OR REPLACE FUNCTION trg_update_fts_vector()
RETURNS trigger AS $$
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

DROP TRIGGER IF EXISTS trg_update_fts ON divechat_product_embeddings;
CREATE TRIGGER trg_update_fts
    BEFORE INSERT OR UPDATE OF product_name, brand_name, category_name, document_text
    ON divechat_product_embeddings
    FOR EACH ROW EXECUTE FUNCTION trg_update_fts_vector();

-- ============================================
-- Indeksy trigram na product_name i brand_name
-- ============================================
CREATE INDEX IF NOT EXISTS idx_product_name_trgm
    ON divechat_product_embeddings USING gin (product_name gin_trgm_ops);

CREATE INDEX IF NOT EXISTS idx_brand_name_trgm
    ON divechat_product_embeddings USING gin (brand_name gin_trgm_ops);
