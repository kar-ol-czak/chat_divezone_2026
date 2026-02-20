-- ============================================
-- DIVEZONE CHAT AI - Migracja 001
-- Tabele PostgreSQL + pgvector
-- Data: 2026-02-19
-- ============================================

-- Upewnij się, że rozszerzenie pgvector jest włączone
CREATE EXTENSION IF NOT EXISTS vector;

-- UWAGA: Schemat w _docs/02_schemat_bazy.md zakłada vector(3072) dla text-embedding-3-large.
-- Aiven pgvector 0.8.1 ma limit 2000 wymiarów dla indeksu HNSW.
-- Używamy vector(1536) z modelem text-embedding-3-small.
-- Jeśli potrzebny large (3072), trzeba użyć indeksu IVFFlat zamiast HNSW.

-- ============================================
-- Tabela: divechat_product_embeddings
-- Embeddingi produktów z PrestaShop
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_product_embeddings (
    id SERIAL PRIMARY KEY,
    ps_product_id INTEGER NOT NULL UNIQUE,
    product_name TEXT NOT NULL,
    product_description TEXT,
    category_name TEXT,
    brand_name TEXT,
    features JSONB,
    price DECIMAL(10,2),
    is_active BOOLEAN DEFAULT true,
    in_stock BOOLEAN DEFAULT true,
    product_url TEXT,
    image_url TEXT,
    document_text TEXT NOT NULL,
    embedding vector(1536),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_product_embedding_hnsw
    ON divechat_product_embeddings
    USING hnsw (embedding vector_cosine_ops);

CREATE INDEX IF NOT EXISTS idx_product_active
    ON divechat_product_embeddings (is_active, in_stock);

CREATE INDEX IF NOT EXISTS idx_product_ps_id
    ON divechat_product_embeddings (ps_product_id);

CREATE INDEX IF NOT EXISTS idx_product_category
    ON divechat_product_embeddings (category_name);

CREATE INDEX IF NOT EXISTS idx_product_price
    ON divechat_product_embeddings (price);

-- ============================================
-- Tabela: divechat_knowledge
-- Baza wiedzy eksperckiej (Q&A, artykuły, FAQ)
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_knowledge (
    id SERIAL PRIMARY KEY,
    chunk_type VARCHAR(20) NOT NULL,
    question TEXT,
    content TEXT NOT NULL,
    category VARCHAR(100),
    embedding vector(1536),
    is_direct_answer BOOLEAN DEFAULT false,
    source_url TEXT,
    active BOOLEAN DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_knowledge_embedding_hnsw
    ON divechat_knowledge
    USING hnsw (embedding vector_cosine_ops);

CREATE INDEX IF NOT EXISTS idx_knowledge_active
    ON divechat_knowledge (active);

CREATE INDEX IF NOT EXISTS idx_knowledge_category
    ON divechat_knowledge (category);

CREATE INDEX IF NOT EXISTS idx_knowledge_type
    ON divechat_knowledge (chunk_type);

-- ============================================
-- Tabela: divechat_conversations
-- Historia rozmów z klientami
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_conversations (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    ps_customer_id INTEGER,
    messages JSONB NOT NULL DEFAULT '[]',
    tools_used JSONB NOT NULL DEFAULT '[]',
    tokens_input INTEGER DEFAULT 0,
    tokens_output INTEGER DEFAULT 0,
    estimated_cost DECIMAL(8,6) DEFAULT 0,
    started_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    closed_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_conversations_session
    ON divechat_conversations (session_id);

CREATE INDEX IF NOT EXISTS idx_conversations_customer
    ON divechat_conversations (ps_customer_id);

CREATE INDEX IF NOT EXISTS idx_conversations_started
    ON divechat_conversations (started_at);
