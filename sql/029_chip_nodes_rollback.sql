-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 029
-- Usuwa tabelę drzewa chipów (CHAT-T-088)
-- Data: 2026-06-13
-- ADR: ADR-096
--
-- DROP TABLE kasuje też dane seed (029_chip_nodes_seed.sql) — ON DELETE CASCADE
-- nieistotne przy DROP całej tabeli. Indeksy i CHECK znikają z tabelą.
-- ============================================

DROP TABLE IF EXISTS divechat_chip_nodes;
