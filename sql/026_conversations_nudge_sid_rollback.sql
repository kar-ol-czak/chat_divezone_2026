-- Rollback migracji 026 (CHAT-T-085): kolumna nudge_sid + partial index.
DROP INDEX IF EXISTS idx_conversations_nudge_sid;
ALTER TABLE divechat_conversations DROP COLUMN IF EXISTS nudge_sid;
