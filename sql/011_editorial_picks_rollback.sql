-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 011
-- ============================================

DROP INDEX IF EXISTS idx_editorial_picks_product;
DROP INDEX IF EXISTS idx_editorial_picks_active_expires;
DROP TABLE IF EXISTS divechat_editorial_picks;
