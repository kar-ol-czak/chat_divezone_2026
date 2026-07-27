-- TASK-013: Synonimy produktowe (PL + EN)
-- synonyms_pl: polskie synonimy nazwy produktu/kategorii
-- synonyms_en: angielskie odpowiedniki

ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS synonyms_pl JSONB DEFAULT '[]',
    ADD COLUMN IF NOT EXISTS synonyms_en JSONB DEFAULT '[]';
