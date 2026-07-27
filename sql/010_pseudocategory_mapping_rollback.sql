-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 010
-- Czyści parent_category_name dla wszystkich produktów.
-- Wymaga uprzedniego backupu (divechat_product_embeddings_backup_20260514)
-- jeśli chcesz przywrócić poprzedni stan (np. częściowy mapping).
-- ============================================

UPDATE divechat_product_embeddings
SET parent_category_name = NULL
WHERE parent_category_name IS NOT NULL;
