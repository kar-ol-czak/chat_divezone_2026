-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 030
-- Usuwa kolumnę label z divechat_chip_nodes (CHAT-T-088b)
-- Data: 2026-06-14
-- ADR: ADR-096
-- ============================================

ALTER TABLE divechat_chip_nodes
    DROP COLUMN IF EXISTS label;
