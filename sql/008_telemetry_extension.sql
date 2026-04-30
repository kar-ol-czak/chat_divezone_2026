-- ============================================
-- DIVEZONE CHAT AI - Migracja 008
-- Rozszerzenie telemetrii: latency_ms, tool_calls, divechat_messages
-- Data: 2026-04-30
-- ADR: ADR-052
-- TASK: TASK-054
--
-- Idempotentna: ADD COLUMN IF NOT EXISTS, CREATE TABLE IF NOT EXISTS,
-- CREATE INDEX IF NOT EXISTS. ADD CONSTRAINT IF NOT EXISTS niedostępne
-- przed PG 9.6+ ale Railway ma 18.x – używamy DO/EXCEPTION wrapper.
-- ============================================

-- ============================================
-- 1.1 Rozszerzenie divechat_message_usage
-- ============================================
ALTER TABLE divechat_message_usage
    ADD COLUMN IF NOT EXISTS latency_ms INTEGER,
    ADD COLUMN IF NOT EXISTS tool_calls JSONB;

-- Indeks na latency dla raportów p95/p99 (faza 1 dashboard nie używa, ale tani).
CREATE INDEX IF NOT EXISTS idx_usage_latency
    ON divechat_message_usage(latency_ms)
    WHERE latency_ms IS NOT NULL;

-- ============================================
-- 1.2 Tabela divechat_messages (per-message audit do feedbacku/ratingów)
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_messages (
    id BIGSERIAL PRIMARY KEY,
    conversation_id INTEGER NOT NULL REFERENCES divechat_conversations(id) ON DELETE CASCADE,
    role VARCHAR(16) NOT NULL CHECK (role IN ('user', 'assistant', 'system', 'tool')),
    content TEXT NOT NULL,
    tool_calls JSONB,
    rating SMALLINT CHECK (rating IS NULL OR rating IN (-1, 0, 1)),
    rating_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_messages_conversation
    ON divechat_messages(conversation_id, created_at);

CREATE INDEX IF NOT EXISTS idx_messages_rating
    ON divechat_messages(rating, rating_at DESC)
    WHERE rating IS NOT NULL AND rating != 0;

-- ============================================
-- 1.3 Domknięcie FK z migracji 007 (zapowiedziane w komentarzu sql/007 linia 30)
-- divechat_message_usage.message_id -> divechat_messages.id
-- ============================================
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'fk_usage_message'
          AND conrelid = 'divechat_message_usage'::regclass
    ) THEN
        ALTER TABLE divechat_message_usage
            ADD CONSTRAINT fk_usage_message
            FOREIGN KEY (message_id) REFERENCES divechat_messages(id) ON DELETE SET NULL;
    END IF;
END$$;

-- ============================================
-- 1.4 Indeksy do queries dashboardu (TASK-055)
-- ============================================

-- Trendy daily/weekly/monthly: GROUP BY DATE_TRUNC, model_id breakdown.
CREATE INDEX IF NOT EXISTS idx_usage_created_model
    ON divechat_message_usage(created_at, model_id);

-- Top N najdroższych rozmów: ORDER BY estimated_cost DESC.
CREATE INDEX IF NOT EXISTS idx_conversations_cost
    ON divechat_conversations(estimated_cost DESC)
    WHERE estimated_cost > 0;
