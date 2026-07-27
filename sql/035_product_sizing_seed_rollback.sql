-- ============================================
-- DIVEZONE CHAT AI - Rollback seedu migracji 035 (CHAT-T-099)
-- Usuwa charty Scubapro/Bare i (kaskadowo) ich wiersze progow.
-- Data: 2026-06-18 | ADR: ADR-099
--
-- Kasuje TYLKO seed (charty source='dev_ocr_2026-06'), NIE struktury tabel.
-- ON DELETE CASCADE na divechat_size_chart_rows usuwa powiazane wiersze.
-- NIE rusza divechat_product_size_chart (mapowanie produktow) — to osobny krok.
-- ============================================

DELETE FROM divechat_size_charts
WHERE source = 'dev_ocr_2026-06'
  AND brand IN ('Scubapro', 'Bare');
