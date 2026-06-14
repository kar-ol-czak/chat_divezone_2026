-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 033 (CHAT-T-088e)
-- Usuwa kolumnę ai_prompt z divechat_chip_nodes.
-- Data: 2026-06-14
-- ADR: ADR-096 / ADR-097
--
-- Idempotentny: DROP COLUMN IF EXISTS. Usuwa też dane ai_prompt (jak każdy DROP).
-- ============================================

ALTER TABLE divechat_chip_nodes
    DROP COLUMN IF EXISTS ai_prompt;
