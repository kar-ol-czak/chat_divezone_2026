-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 013
-- Cofa zmiany z 013_shipping_rates.sql
-- Data: 2026-05-26
-- TASK: T-014
-- ============================================

DROP TABLE IF EXISTS divechat_shipping_rates CASCADE;
DROP TABLE IF EXISTS divechat_shop_config CASCADE;
