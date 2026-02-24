-- 006: Dodanie parent_category_name (ADR-027)
-- Hierarchia kategorii PrestaShop: filtr po parent lub child category

ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS parent_category_name VARCHAR(255);

-- Index na parent_category_name dla filtrów ILIKE
CREATE INDEX IF NOT EXISTS idx_product_embeddings_parent_category
    ON divechat_product_embeddings (parent_category_name);
