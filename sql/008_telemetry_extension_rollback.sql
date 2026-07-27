-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 008
-- ============================================

ALTER TABLE divechat_message_usage
    DROP CONSTRAINT IF EXISTS fk_usage_message;

DROP TABLE IF EXISTS divechat_messages CASCADE;

ALTER TABLE divechat_message_usage
    DROP COLUMN IF EXISTS latency_ms,
    DROP COLUMN IF EXISTS tool_calls;

DROP INDEX IF EXISTS idx_usage_latency;
DROP INDEX IF EXISTS idx_usage_created_model;
DROP INDEX IF EXISTS idx_conversations_cost;
