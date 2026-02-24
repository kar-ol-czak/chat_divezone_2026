-- TASK-012b: Multi-vector retrieval (3 osobne kolumny wektorowe)
-- embedding_name: product_name + brand_name (nawigacyjne)
-- embedding_desc: category + opis + cechy (eksploracyjne)
-- embedding_jargon: search_phrases z TASK-011b (vocabulary mismatch)

ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS embedding_name vector(1536),
    ADD COLUMN IF NOT EXISTS embedding_desc vector(1536),
    ADD COLUMN IF NOT EXISTS embedding_jargon vector(1536);

-- Indeksy HNSW (cosine similarity)
CREATE INDEX IF NOT EXISTS idx_emb_name_hnsw ON divechat_product_embeddings
    USING hnsw (embedding_name vector_cosine_ops);
CREATE INDEX IF NOT EXISTS idx_emb_desc_hnsw ON divechat_product_embeddings
    USING hnsw (embedding_desc vector_cosine_ops);
CREATE INDEX IF NOT EXISTS idx_emb_jargon_hnsw ON divechat_product_embeddings
    USING hnsw (embedding_jargon vector_cosine_ops);
