# Schemat bazy danych PostgreSQL (Railway + pgvector)
# Wersja: 1.2 | Data: 2026-02-20
# Zmiana 1.2: Migracja Aiven → Railway (ADR-019). PG 18.2, pgvector 0.8.1.
# Zmiana 1.1: vector(3072) -> vector(1536), model text-embedding-3-large@1536 (ADR-011, ADR-012)

## Połączenie

```
Host: switchback.proxy.rlwy.net
Port: 14368
Database: railway
User: postgres
Password: (w .env)
pgvector: 0.8.1
PostgreSQL: 18.2
Provider: Railway Hobby ($5/mies z $5 kredytów)
```

## Tabele

### divechat_product_embeddings
Embeddingi produktów z PrestaShop. Aktualizowane cronem (pipeline Python).

```sql
CREATE TABLE divechat_product_embeddings (
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

CREATE INDEX idx_product_embedding_hnsw 
    ON divechat_product_embeddings 
    USING hnsw (embedding vector_cosine_ops);

CREATE INDEX idx_product_active 
    ON divechat_product_embeddings (is_active, in_stock);

CREATE INDEX idx_product_ps_id 
    ON divechat_product_embeddings (ps_product_id);

CREATE INDEX idx_product_category 
    ON divechat_product_embeddings (category_name);

CREATE INDEX idx_product_price 
    ON divechat_product_embeddings (price);
```

Uwaga: vector(1536) wymuszony limitem HNSW na pgvector 0.8.1 (max 2000 dim).
Model: Google Gemini embedding-001 z output_dimensionality=1536.
Task types: RETRIEVAL_DOCUMENT (dokumenty), RETRIEVAL_QUERY (zapytania).

### divechat_knowledge
Baza wiedzy eksperckiej (Q&A, artykuły, FAQ).

```sql
CREATE TABLE divechat_knowledge (
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

CREATE INDEX idx_knowledge_embedding_hnsw 
    ON divechat_knowledge 
    USING hnsw (embedding vector_cosine_ops);

CREATE INDEX idx_knowledge_active 
    ON divechat_knowledge (active);

CREATE INDEX idx_knowledge_category 
    ON divechat_knowledge (category);

CREATE INDEX idx_knowledge_type 
    ON divechat_knowledge (chunk_type);
```

chunk_type: 'qa', 'article', 'faq', 'expert_note'

### divechat_conversations
Historia rozmów z klientami.

```sql
CREATE TABLE divechat_conversations (
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

CREATE INDEX idx_conversations_session 
    ON divechat_conversations (session_id);

CREATE INDEX idx_conversations_customer 
    ON divechat_conversations (ps_customer_id);

CREATE INDEX idx_conversations_started 
    ON divechat_conversations (started_at);
```

## Przykładowe zapytanie hybrydowe (wektorowe + filtry SQL)

```sql
SELECT 
    ps_product_id,
    product_name,
    price,
    category_name,
    1 - (embedding <=> $1::vector) AS similarity
FROM divechat_product_embeddings
WHERE is_active = true
  AND in_stock = true
  AND ($2::decimal IS NULL OR price >= $2)
  AND ($3::decimal IS NULL OR price <= $3)
  AND ($4::text IS NULL OR category_name = $4)
ORDER BY embedding <=> $1::vector
LIMIT 10;
```

$1 = wektor zapytania klienta (z OpenAI Embeddings API)
$2 = min_price (opcjonalny)
$3 = max_price (opcjonalny)
$4 = kategoria (opcjonalna)
