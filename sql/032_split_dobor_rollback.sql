-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 032 (seed)
-- Cofa rozdzielenie doboru: usuwa dobor_rozmiar + przywraca bot_text dobor (CHAT-T-088d)
-- Data: 2026-06-14
-- ADR: ADR-096
--
-- Idempotentny: DELETE no-op gdy węzła brak, UPDATE deterministyczny.
-- dobor wraca do treści baseline z seedu 029 (jak rollback 031).
-- ============================================

-- 1. Usuń nowy węzeł dobor_rozmiar
DELETE FROM divechat_chip_nodes
WHERE node_key = 'dobor_rozmiar';

-- 2. dobor: przywróć poprzedni bot_text (baseline seed 029)
UPDATE divechat_chip_nodes
SET bot_text = 'Pomogę dobrać sprzęt. Co Cię interesuje?',
    updated_at = NOW()
WHERE node_key = 'dobor';
