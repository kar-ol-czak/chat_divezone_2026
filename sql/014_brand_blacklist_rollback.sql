-- ============================================
-- ROLLBACK migracji 014
-- T-015
-- ============================================
-- Usuwa tabelę blacklisty marek. ProductSearch po rollbacku przestanie
-- filtrować po blacklist (zachowanie sprzed T-015).

DROP TABLE IF EXISTS divechat_brand_blacklist;
