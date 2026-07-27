-- ============================================
-- DIVEZONE CHAT AI - Rollback seedu 029 (gałąź operacyjna drzewa chipów)
-- Data: 2026-06-13
-- ADR: ADR-096
--
-- Usuwa węzły operacyjne. Kolejność nieistotna — ON DELETE CASCADE skasuje
-- dzieci przy usunięciu roota, ale usuwamy jawnie po node_key dla czytelności.
-- NIE usuwa tabeli (to robi 029_chip_nodes_rollback.sql).
-- ============================================

DELETE FROM divechat_chip_nodes
WHERE node_key IN ('zwroty', 'serwis', 'wysylka', 'dobor', 'root');
