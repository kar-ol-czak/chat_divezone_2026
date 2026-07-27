-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 039
-- Usuwa kolumne chip_path + indeks (CHAT-T-122, ADR-110).
-- Data: 2026-07-01
-- ============================================

DROP INDEX IF EXISTS idx_conv_chip_path;

ALTER TABLE divechat_conversations
    DROP COLUMN IF EXISTS chip_path;
