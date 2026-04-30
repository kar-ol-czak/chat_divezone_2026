-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 007
-- Cofa zmiany z 007_model_pricing_and_usage.sql
-- Data: 2026-04-30
-- TASK: TASK-052a
--
-- UWAGA: Rollback NIE cofa ALTER COLUMN estimated_cost TYPE
-- (DECIMAL(8,6) -> NUMERIC(10,6)). Cofniecie wymagaloby walidacji
-- ze nie ma wartosci > 99.999999. Poszerzenie typu jest bezpieczne
-- i nie blokuje istniejacego kodu czytajacego ta kolumne.
-- (Zgodnie z 052a_decisions.md, sekcja "Rollback".)
-- ============================================

-- DROP tabel w kolejnosci odwroconej do CREATE.
-- CASCADE usuwa zalezne indeksy i constraints (FK z message_usage do conversations).
DROP TABLE IF EXISTS divechat_message_usage CASCADE;
DROP TABLE IF EXISTS divechat_model_pricing CASCADE;
DROP TABLE IF EXISTS divechat_exchange_rates CASCADE;

-- Cofniecie kolumn dodanych do divechat_conversations
ALTER TABLE divechat_conversations
    DROP COLUMN IF EXISTS cache_read_tokens,
    DROP COLUMN IF EXISTS cache_creation_tokens;
