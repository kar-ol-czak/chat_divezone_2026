-- ============================================
-- DIVEZONE CHAT AI - Migracja 030
-- Kolumna label dla węzłów drzewa chipów (CHAT-T-088b)
-- Data: 2026-06-14
-- ADR: ADR-096 (model węzła), decyzja 42a (osobna etykieta nawigacyjna)
--
-- Krótka etykieta nawigacyjna węzła — OSOBNA od bot_text. Chip wyświetla label
-- (nazwę przycisku), bot_text to pełna treść inline. Silnik widgetu (CHAT-T-089)
-- potrzebuje label, by nie renderować surowego node_key.
--
-- Re-seed wartości label = OSOBNY plik 030_chip_nodes_label_seed.sql.
-- Idempotentna: ADD COLUMN IF NOT EXISTS.
-- ============================================

ALTER TABLE divechat_chip_nodes
    ADD COLUMN IF NOT EXISTS label TEXT NULL;

COMMENT ON COLUMN divechat_chip_nodes.label IS
    'Krótka etykieta nawigacyjna chipa (nazwa przycisku) — osobna od bot_text (pełna treść inline). NULL dozwolone dla węzłów czysto nawigacyjnych. CHAT-T-088b / decyzja 42a.';
