-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 035 (CHAT-T-099)
-- Usuwa tabele rozmiarow skafandrow: divechat_product_size_chart,
-- divechat_size_chart_rows, divechat_size_charts.
-- Data: 2026-06-18
-- ADR: ADR-099
--
-- Idempotentny: DROP TABLE IF EXISTS (kasuje tez indeksy + dane + seed).
-- Kolejnosc: najpierw zaleznosci (rows/product_size_chart via FK), potem charts.
-- ============================================

DROP TABLE IF EXISTS divechat_product_size_chart;
DROP TABLE IF EXISTS divechat_size_chart_rows;
DROP TABLE IF EXISTS divechat_size_charts;
