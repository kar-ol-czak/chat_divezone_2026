-- ============================================
-- ROLLBACK migracji 015
-- T-022 (decyzja 101b)
-- ============================================
-- Cofamy:
-- 1. usuwamy wpis gpt-5.5 (nie był używany w produkcji — bezpieczne)
-- 2. przywracamy gpt-5.4 output 15.0 → 14.0 (stan z migracji 007)
-- 3. zerujemy (NULL) cache_read_price_per_million dla OpenAI (stan z 007)
--
-- UWAGA: rollback NIE cofa historycznych zapisów w divechat_message_usage —
-- one są forward-only. Migracja 015 nigdy ich nie modyfikowała.

DELETE FROM divechat_model_pricing WHERE model_id = 'gpt-5.5';

UPDATE divechat_model_pricing
SET output_price_per_million = 14.0000,
    updated_at = NOW()
WHERE model_id = 'gpt-5.4' AND output_price_per_million = 15.0000;

UPDATE divechat_model_pricing
SET cache_read_price_per_million = NULL,
    updated_at = NOW()
WHERE model_id IN ('gpt-5.4', 'gpt-4.1', 'gpt-5.4-mini', 'o3-mini', 'gpt-5-mini')
  AND cache_read_price_per_million IS NOT NULL;
