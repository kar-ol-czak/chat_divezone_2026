-- ============================================
-- DIVEZONE CHAT AI - Rollback Migracja 016
-- Kuratorowane rekomendacje produktowe (ADR-065 MVP)
-- ============================================

DROP TRIGGER IF EXISTS trg_curated_updated_at ON divechat_curated_recommendations;
DROP FUNCTION IF EXISTS divechat_curated_set_updated_at();
DROP INDEX IF EXISTS idx_curated_active_category;
DROP TABLE IF EXISTS divechat_curated_recommendations;
