-- ============================================
-- ROLLBACK migracji 012
-- T-010 (ADR-057)
-- ============================================
-- Usuwa tabelę aliasów. NIE cofa zmian w divechat_product_embeddings.parent_category_name
-- (po rollbacku należy uruchomić sql/010_pseudocategory_mapping.sql żeby wrócić do D2-hybrid).
-- Rozszerzenie unaccent zostaje (używane w innych skryptach).

DROP INDEX IF EXISTS idx_category_aliases_normalized;
DROP TABLE IF EXISTS divechat_category_aliases;
