-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 037
-- Usuwa tabele recenzji rozmow (CHAT-T-104, ADR-102).
-- Data: 2026-06-28
-- ============================================

DROP TABLE IF EXISTS divechat_conversation_review;
