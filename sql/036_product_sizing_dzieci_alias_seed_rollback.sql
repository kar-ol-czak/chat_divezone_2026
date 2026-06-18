-- ============================================
-- DIVEZONE CHAT AI - Rollback seedu migracji 036 (CHAT-T-099b)
-- Usuwa: aliasy + chart Scubapro/DZIECI (kaskadowo jego wiersze height).
-- Data: 2026-06-18 | ADR: ADR-099
--
-- Kasuje TYLKO seed 036, nie struktury (tabela aliasow + CHECK zostaja — to migracja).
-- Wykonac PRZED rollbackiem migracji 036 (binarny CHECK nie wroci, gdy istnieje DZIECI).
-- NIE rusza mapowan w divechat_product_size_chart (osobny krok).
-- ============================================

DELETE FROM divechat_size_label_alias
WHERE chart_id IN (SELECT id FROM divechat_size_charts WHERE brand='Scubapro' AND gender IN ('M','K'))
  AND alias_label IN ('M tall', 'XXL', 'L short', 'L tall');

DELETE FROM divechat_size_charts
WHERE brand = 'Scubapro' AND gender = 'DZIECI' AND source = 'rebel_product_page_2026-06';
