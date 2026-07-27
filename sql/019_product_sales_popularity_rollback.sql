-- ============================================
-- DIVEZONE CHAT AI - Rollback Migracja 019
-- Popularnosc sprzedazy produktow (T-035 CZESC A)
-- ============================================

DROP INDEX IF EXISTS idx_popularity_qty;
DROP TABLE IF EXISTS divechat_product_sales_popularity;
