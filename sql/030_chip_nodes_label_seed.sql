-- ============================================
-- DIVEZONE CHAT AI - Seed 030 (etykiety label węzłów operacyjnych)
-- Data: 2026-06-14
-- ADR: ADR-096, decyzja 42a. Wymaga migracji 030 (kolumna label).
--
-- Idempotentny: UPDATE ... WHERE node_key=... (bezpieczny wielokrotnie).
-- Tylko węzły z seedu 029 (root + 4 operacyjne).
-- ============================================

UPDATE divechat_chip_nodes SET label = 'W czym mogę pomóc?',  updated_at = NOW() WHERE node_key = 'root';
UPDATE divechat_chip_nodes SET label = 'Zwroty i wymiana',     updated_at = NOW() WHERE node_key = 'zwroty';
UPDATE divechat_chip_nodes SET label = 'Serwis automatu',      updated_at = NOW() WHERE node_key = 'serwis';
UPDATE divechat_chip_nodes SET label = 'Dostępność i wysyłka', updated_at = NOW() WHERE node_key = 'wysylka';
UPDATE divechat_chip_nodes SET label = 'Dobór sprzętu',        updated_at = NOW() WHERE node_key = 'dobor';
