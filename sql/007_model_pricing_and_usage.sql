-- ============================================
-- DIVEZONE CHAT AI - Migracja 007
-- Cennik modeli AI + logowanie zuzycia tokenow + kursy walut
-- Data: 2026-04-30
-- ADR: ADR-051
-- TASK: TASK-052a
--
-- Idempotentna: kolejne wywolania nie blada (CREATE IF NOT EXISTS,
-- ADD COLUMN IF NOT EXISTS, INSERT ... ON CONFLICT DO UPDATE).
-- ============================================

-- ============================================
-- 1.1 Tabela: divechat_model_pricing
-- Cennik modeli AI (USD per milion tokenow)
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_model_pricing (
    model_id VARCHAR(64) PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    input_price_per_million NUMERIC(10,4) NOT NULL,
    output_price_per_million NUMERIC(10,4) NOT NULL,
    cache_read_price_per_million NUMERIC(10,4),
    cache_creation_price_per_million NUMERIC(10,4),
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_escalation BOOLEAN NOT NULL DEFAULT FALSE,
    supports_temperature BOOLEAN NOT NULL DEFAULT FALSE,
    supports_reasoning_effort BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pricing_provider
    ON divechat_model_pricing(provider) WHERE is_active = TRUE;

-- ============================================
-- 1.2 Tabela: divechat_message_usage
-- Logowanie zuzycia tokenow per wiadomosc
-- conversation_id: INTEGER + FK (decyzja architekta, 052a_decisions.md, pytanie 1 -> A)
-- message_id: BIGINT bez FK – tabela divechat_messages jeszcze nie istnieje,
--             dopisac ALTER gdy powstanie (decyzja, pytanie 3 -> A)
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_message_usage (
    id BIGSERIAL PRIMARY KEY,
    conversation_id INTEGER NOT NULL REFERENCES divechat_conversations(id) ON DELETE CASCADE,
    message_id BIGINT,
    model_id VARCHAR(64) NOT NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    cache_read_tokens INTEGER NOT NULL DEFAULT 0,
    cache_creation_tokens INTEGER NOT NULL DEFAULT 0,
    cost_input_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    cost_output_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    cost_cache_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    cost_total_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_usage_conversation ON divechat_message_usage(conversation_id);
CREATE INDEX IF NOT EXISTS idx_usage_created ON divechat_message_usage(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_usage_model ON divechat_message_usage(model_id);

-- ============================================
-- 1.3 Tabela: divechat_exchange_rates
-- Kursy walut do PLN (NBP daily fetcher w TASK-052b)
-- UWAGA: tej migracji NIE seedujemy kursow – robi to backend.
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_exchange_rates (
    rate_date DATE NOT NULL,
    currency VARCHAR(3) NOT NULL,
    rate_to_pln NUMERIC(10,4) NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'NBP',
    fetched_at TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (rate_date, currency)
);

-- ============================================
-- 1.4 Rozszerzenie divechat_conversations
-- Decyzja architekta (052a_decisions.md, pytanie 2 -> D-modified):
--   - ZACHOWAC istniejace tokens_input, tokens_output, estimated_cost
--   - DODAC tylko cache_read_tokens, cache_creation_tokens
--   - ROZSZERZYC precyzje estimated_cost: DECIMAL(8,6) -> NUMERIC(10,6)
-- ============================================
ALTER TABLE divechat_conversations
    ADD COLUMN IF NOT EXISTS cache_read_tokens INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS cache_creation_tokens INTEGER NOT NULL DEFAULT 0;

ALTER TABLE divechat_conversations
    ALTER COLUMN estimated_cost TYPE NUMERIC(10,6);

-- ============================================
-- 2. Seed cennika (ADR-051)
-- INSERT ... ON CONFLICT DO UPDATE – idempotentny
-- ============================================
INSERT INTO divechat_model_pricing (
    model_id, provider, label,
    input_price_per_million, output_price_per_million,
    cache_read_price_per_million, cache_creation_price_per_million,
    currency, is_active, is_escalation,
    supports_temperature, supports_reasoning_effort,
    updated_at
) VALUES
    ('claude-opus-4-7',   'claude', 'Claude Opus 4.7',   5.0000, 25.0000, 0.5000, 6.2500, 'USD', TRUE, TRUE,  FALSE, TRUE,  NOW()),
    ('claude-sonnet-4-6', 'claude', 'Claude Sonnet 4.6', 3.0000, 15.0000, 0.3000, 3.7500, 'USD', TRUE, FALSE, FALSE, TRUE,  NOW()),
    ('claude-haiku-4-5',  'claude', 'Claude Haiku 4.5',  1.0000,  5.0000, 0.1000, 1.2500, 'USD', TRUE, FALSE, FALSE, TRUE,  NOW()),
    ('gpt-5.4',           'openai', 'GPT-5.4',           2.5000, 14.0000, NULL,   NULL,   'USD', TRUE, TRUE,  FALSE, TRUE,  NOW()),
    ('gpt-4.1',           'openai', 'GPT-4.1',           2.0000,  8.0000, NULL,   NULL,   'USD', TRUE, FALSE, TRUE,  FALSE, NOW()),
    ('gpt-5.4-mini',      'openai', 'GPT-5.4 Mini',      0.7500,  4.0000, NULL,   NULL,   'USD', TRUE, FALSE, FALSE, TRUE,  NOW()),
    ('o3-mini',           'openai', 'o3-mini',           1.1000,  4.4000, NULL,   NULL,   'USD', TRUE, FALSE, FALSE, TRUE,  NOW()),
    ('gpt-5-mini',        'openai', 'GPT-5 Mini',        0.2500,  2.0000, NULL,   NULL,   'USD', TRUE, FALSE, FALSE, TRUE,  NOW())
ON CONFLICT (model_id) DO UPDATE SET
    provider                          = EXCLUDED.provider,
    label                             = EXCLUDED.label,
    input_price_per_million           = EXCLUDED.input_price_per_million,
    output_price_per_million          = EXCLUDED.output_price_per_million,
    cache_read_price_per_million      = EXCLUDED.cache_read_price_per_million,
    cache_creation_price_per_million  = EXCLUDED.cache_creation_price_per_million,
    currency                          = EXCLUDED.currency,
    is_active                         = EXCLUDED.is_active,
    is_escalation                     = EXCLUDED.is_escalation,
    supports_temperature              = EXCLUDED.supports_temperature,
    supports_reasoning_effort         = EXCLUDED.supports_reasoning_effort,
    updated_at                        = NOW();
